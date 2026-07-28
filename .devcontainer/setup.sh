#!/usr/bin/env bash
#
# Penyiapan sekali jalan saat codespace dibuat.
#
# URUTAN ITU PENTING: `php artisan` apa pun baru bisa dipanggil SETELAH
# `composer install`, karena artisan memuat vendor/autoload.php. Versi pertama
# skrip ini memanggil key:generate lebih dulu dan mati di situ — akibatnya
# dependensi tak pernah terpasang dan port 8000 menjawab 502.
set -e

echo "==> 1/7 Memastikan ekstensi PHP untuk PostgreSQL"
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

echo "==> 2/7 Menyiapkan berkas .env"
# .env tak ikut git (berisi rahasia), jadi disalin dari contoh. Alamat database
# TIDAK perlu ditulis di sini — sudah datang sebagai environment variable.
[ -f .env ] || cp .env.example .env

echo "==> 3/7 Memasang dependensi PHP (beberapa menit)"
composer install --no-interaction --prefer-dist

echo "==> 4/7 Membuat kunci aplikasi"
php artisan key:generate --force

echo "==> 5/7 Membangun tampilan (CSS & JavaScript)"
npm ci
npm run build

echo "==> 6/7 Menunggu database siap"
for i in $(seq 1 30); do
    if php -r 'new PDO("pgsql:host=db;port=5432;dbname=alwafi", "alwafi", "alwafi");' 2>/dev/null; then
        echo "    Database menjawab."
        break
    fi
    sleep 2
done

echo "==> 7/7 Membuat tabel & mengisi data awal"
php artisan migrate --force
php artisan db:seed --force

echo
echo "============================================================"
echo " Selesai. Jalankan server:"
echo "   bash .devcontainer/start-server.sh"
echo " lalu buka tab PORTS, klik ikon globe pada port 8000."
echo " Login:  admin  /  admin123"
echo "============================================================"
