#!/usr/bin/env bash
# Pasang satu instans ERP di VPS (dijalankan sebagai `deploy`).
#   bash pasang-instans.sh <nama> <cabang> "<APP_NAME>" <server_name...>
# Aman diulang: yang sudah ada (clone, DB, .env, kata sandi admin) tidak ditimpa.
set -euo pipefail
umask 002

NAMA="$1"; CABANG="$2"; APPNAME="$3"; shift 3; SERVER_NAMES="$*"
# Jati diri instans (config/instans.php) — WAJIB ada di .env SEBELUM db:seed: seeder memilih data awalnya
# dari sini (wafimart → COA ritel & toko). Bawaan dari nama folder; timpa lewat variabel INSTANS=… bila perlu.
INSTANS_KODE="${INSTANS:-$([ "$NAMA" = wafimart ] && echo wafimart || echo alwafi)}"
DOMAIN="${SERVER_NAMES%% *}"
DIR="/var/www/$NAMA"
REPO="git@github.com:AbuNarasya/al-wafi.git"
KREDENSIAL="$HOME/kredensial-$NAMA.txt"
langkah() { echo; echo "=== $* ($(date +%H:%M:%S))"; }

langkah "1. Kode ($CABANG)"
if [ ! -d "$DIR/.git" ]; then
  sudo install -d -o deploy -g www-data -m 2775 "$DIR"
  git clone --quiet --branch "$CABANG" "$REPO" "$DIR"
fi
cd "$DIR"
git fetch --quiet origin
git checkout --quiet "$CABANG"
git reset --quiet --hard "origin/$CABANG"
git config core.fileMode false
echo "HEAD: $(git log --oneline -1)"

langkah "2. Database"
if ! sudo -u postgres psql -tAc "select 1 from pg_roles where rolname='$NAMA'" | grep -q 1; then
  DBPASS="$(openssl rand -hex 24)"
  sudo -u postgres psql -q -c "create role $NAMA login password '$DBPASS'"
  sudo -u postgres createdb -O "$NAMA" "$NAMA"
  echo "role & database $NAMA dibuat"
else
  DBPASS=""
  echo "role $NAMA sudah ada"
fi

langkah "3. .env"
if [ ! -f .env ]; then
  [ -n "$DBPASS" ] || { echo "GALAT: .env tak ada tapi role sudah ada — sandi DB tak diketahui"; exit 1; }
  cat > .env <<EOF
APP_NAME="$APPNAME"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=http://$DOMAIN
INSTANS=$INSTANS_KODE
APP_LOCALE=id
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=id_ID
APP_TIMEZONE=Asia/Jakarta
APP_MAINTENANCE_DRIVER=file
BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=warning

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=$NAMA
DB_USERNAME=$NAMA
DB_PASSWORD=$DBPASS

SESSION_DRIVER=file
SESSION_LIFETIME=120
CACHE_STORE=file
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local

MAIL_MAILER=log
OTP_TAMPIL_DI_LAYAR=false
VERIFIKASI_WALI_AKTIF=false

VITE_APP_NAME="\${APP_NAME}"
EOF
  chmod 640 .env
  echo ".env ditulis"
else
  echo ".env sudah ada — dibiarkan"
fi

langkah "4. Composer"
composer install --no-dev --optimize-autoloader --no-interaction --quiet
grep -q '^APP_KEY=base64' .env || php artisan key:generate --force --no-interaction

langkah "5. Aset (npm)"
npm ci --no-audit --no-fund --loglevel=error
npm run build --silent >/dev/null
ls public/build/manifest.json

langkah "6. Migrasi & seed"
php artisan migrate --force --no-interaction | tail -3
if [ ! -f "$KREDENSIAL" ]; then
  php artisan db:seed --force --no-interaction | tail -2
  ADMINPASS="$(openssl rand -base64 18 | tr -d '/+=' | cut -c1-16)"
  PW="$ADMINPASS" php artisan tinker --execute='App\Models\User::where("username","admin")->update(["password_hash"=>Illuminate\Support\Facades\Hash::make(getenv("PW"))]);' >/dev/null
  printf 'Instans: %s\nLogin  : admin\nSandi  : %s\n(Ganti lewat menu Profil sesudah masuk, lalu hapus berkas ini.)\n' "$NAMA" "$ADMINPASS" > "$KREDENSIAL"
  chmod 600 "$KREDENSIAL"
  echo "sandi admin acak disimpan di $KREDENSIAL"
fi

langkah "7. Izin & cache"
php artisan storage:link --force >/dev/null 2>&1 || true
sudo chgrp -R www-data storage bootstrap/cache
sudo chmod -R g+rwX storage bootstrap/cache
find storage bootstrap/cache -type d -exec sudo chmod g+s {} +
php artisan optimize --no-interaction >/dev/null
echo ok

langkah "8. Nginx"
sudo tee "/etc/nginx/sites-available/$NAMA" >/dev/null <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name $SERVER_NAMES;
    root $DIR/public;
    index index.php;
    client_max_body_size 20M;
    charset utf-8;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }
    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }
    location ~ \.php\$ {
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }
    location ~ /\.(?!well-known).* { deny all; }
}
EOF
sudo ln -sf "/etc/nginx/sites-available/$NAMA" "/etc/nginx/sites-enabled/$NAMA"
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t 2>&1 | tail -1
sudo systemctl reload nginx

langkah "9. Cron (schedule:run tiap menit, sebagai www-data)"
BARIS="* * * * * cd $DIR && php artisan schedule:run >> /dev/null 2>&1"
# `crontab -l` gagal bila belum ada crontab & `grep -v` gagal bila tak ada sisa baris — keduanya wajar.
( { sudo crontab -u www-data -l 2>/dev/null || true; } | { grep -vF "$DIR " || true; } ; echo "$BARIS" ) | sudo crontab -u www-data -
sudo crontab -u www-data -l | grep -F "$DIR"

langkah "Selesai"
curl -s -o /dev/null -w "GET /login → %{http_code}\n" -H "Host: ${SERVER_NAMES%% *}" http://127.0.0.1/login
