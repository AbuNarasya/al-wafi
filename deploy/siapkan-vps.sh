#!/usr/bin/env bash
# Menyiapkan VPS Ubuntu 26.04 KOSONG untuk ERP Al Wafi & Wafi Mart (dijalankan sebagai ROOT, sekali).
#   bash siapkan-vps.sh
#
# Yang dilakukan: pengguna kerja `deploy` (kunci SSH root disalin, sudo tanpa sandi), zona Asia/Jakarta,
# firewall (22/80/443), fail2ban, pembaruan keamanan otomatis, swap 2 GB, paket: Nginx, PHP 8.5-FPM,
# PostgreSQL 18 (hanya localhost), Composer, Node/npm, Git, Certbot, rclone; deploy key GitHub untuk `deploy`.
#
# SENGAJA TIDAK mematikan login root/kata sandi: lakukan sesudah login `deploy@<IP>` terbukti berhasil,
# lewat `bash siapkan-vps.sh kunci-ssh` — supaya tak terkunci di luar server.
# Aman diulang. Paket diambil dari repositori Ubuntu (tanpa PPA).
set -euo pipefail

if [ "${1:-}" = "kunci-ssh" ]; then
  cat > /etc/ssh/sshd_config.d/10-alwafi.conf <<EOF
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin no
EOF
  sshd -t && systemctl reload ssh
  sshd -T | grep -E "^(passwordauthentication|permitrootlogin) "
  exit 0
fi

langkah() { echo; echo "=== $* ($(date +%H:%M:%S))"; }
APT="apt-get -o DPkg::Lock::Timeout=900 -y -qq"
export DEBIAN_FRONTEND=noninteractive

langkah "1. Pengguna deploy & zona waktu"
id deploy >/dev/null 2>&1 || adduser --disabled-password --gecos "" deploy
usermod -aG sudo deploy
install -d -m 700 -o deploy -g deploy /home/deploy/.ssh
install -m 600 -o deploy -g deploy /root/.ssh/authorized_keys /home/deploy/.ssh/authorized_keys
echo "deploy ALL=(ALL) NOPASSWD:ALL" > /etc/sudoers.d/90-deploy && chmod 440 /etc/sudoers.d/90-deploy && visudo -cf /etc/sudoers.d/90-deploy
timedatectl set-timezone Asia/Jakarta

langkah "2. Paket (server baru biasanya sedang menjalankan pembaruan pertamanya — ditunggu)"
$APT update >/dev/null
$APT install ufw fail2ban unattended-upgrades nginx postgresql-18 git unzip curl certbot python3-certbot-nginx \
  composer nodejs npm rclone \
  php8.5-fpm php8.5-cli php8.5-pgsql php8.5-mbstring php8.5-xml php8.5-curl php8.5-zip php8.5-bcmath php8.5-intl php8.5-gd >/dev/null

langkah "3. Firewall, fail2ban, swap"
ufw allow OpenSSH >/dev/null; ufw allow 80/tcp >/dev/null; ufw allow 443/tcp >/dev/null; ufw --force enable >/dev/null
systemctl enable --now fail2ban >/dev/null 2>&1
if [ ! -f /swapfile ]; then
  fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile >/dev/null && swapon /swapfile
  echo "/swapfile none swap sw 0 0" >> /etc/fstab
fi
rm -f /etc/nginx/sites-enabled/default && systemctl reload nginx

langkah "4. Deploy key GitHub (milik pengguna deploy)"
sudo -u deploy bash -c '
  [ -f ~/.ssh/github_erp ] || ssh-keygen -q -t ed25519 -N "" -C "vps-alwafi-deploy" -f ~/.ssh/github_erp
  grep -q "Host github.com" ~/.ssh/config 2>/dev/null || printf "Host github.com\n  IdentityFile ~/.ssh/github_erp\n  IdentitiesOnly yes\n" >> ~/.ssh/config
  chmod 600 ~/.ssh/config
  grep -q "^github.com" ~/.ssh/known_hosts 2>/dev/null || ssh-keyscan -t ed25519 github.com 2>/dev/null >> ~/.ssh/known_hosts'

langkah "Ringkasan"
echo "PHP      : $(php -r 'echo PHP_VERSION;')"
echo "Postgres : $(sudo -u postgres psql -tAc 'show server_version')"
echo "Nginx    : $(nginx -v 2>&1 | cut -d/ -f2)"
echo "Node/npm : $(node -v) / $(npm -v)"
echo "Firewall : $(ufw status | head -1)"
echo "Swap     : $(swapon --show --noheadings | awk '{print $3}')"
echo
echo "Deploy key — tempel di GitHub (repo → Settings → Deploy keys, TANPA write access):"
cat /home/deploy/.ssh/github_erp.pub
