#!/usr/bin/env bash
#
# Penyiapan sekali jalan saat codespace dibuat.
set -e

echo "==> 1/6 Memastikan ekstensi PHP untuk PostgreSQL"
# Image devcontainer PHP belum tentu membawa pdo_pgsql. Dua jalur pemasangan
# dicoba berurutan agar tak bergantung pada isi image.
if ! php -m | grep -qi '^pdo_pgsql$'; then
    if command -v install-php-extensions >/dev/null 2>&1; then
        sudo install-php-extensions pdo_pgsql
    else
        sudo apt-get update -y
        sudo apt-get install -y libpq-dev
        sudo docker-php-ext-install pdo_pgsql
    fi
fi
php -m | grep -qi '^pdo_pgsql$' && echo "    pdo_pgsql siap."

echo "==> 2/6 Menyiapkan berkas .env"
# .env tak ikut git (berisi rahasia), jadi dibuat dari contoh. Alamat database
# TIDAK perlu ditulis di sini — sudah datang sebagai environment variable.
[ -f .env ] || cp .env.example .env
php artisan key:generate --force

echo "==> 3/6 Memasang dependensi PHP"
composer install --no-interaction --prefer-dist

echo "==> 4/6 Membangun tampilan (CSS & JavaScript)"
npm ci
npm run build

echo "==> 5/6 Menunggu database siap"
for i in $(seq 1 30); do
    if php -r 'new PDO("pgsql:host=db;port=5432;dbname=alwafi", "alwafi", "alwafi");' 2>/dev/null; then
        echo "    Database menjawab."
        break
    fi
    sleep 2
done

echo "==> 6/6 Membuat tabel & mengisi data awal"
php artisan migrate --force
php artisan db:seed --force

echo
echo "============================================================"
echo " Selesai. Buka tab PORTS di bawah, klik alamat port 8000."
echo " Login:  admin  /  admin123"
echo "============================================================"
