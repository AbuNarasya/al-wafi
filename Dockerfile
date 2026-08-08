# syntax=docker/dockerfile:1
#
# Image untuk deploy UJI COBA di Render (paket gratis).
#
# Render tak punya runtime PHP bawaan (hanya Node/Python/Ruby/Go), jadi aplikasi
# dikemas sebagai container. Tiga tahap agar build ulang tetap cepat: aset Vite,
# dependensi Composer, lalu runtime PHP yang ramping.

# ---------- 1. Aset (Vite + Tailwind 4) ----------
# Seluruh sumber disalin, bukan cuma resources/: Tailwind 4 memindai berkas Blade
# & PHP untuk menentukan kelas yang dipakai. Kalau hanya resources/ yang ada,
# separuh kelas hilang dari CSS hasil build.
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

# ---------- 2. Dependensi PHP ----------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .

# Direktori kerja dibuat dulu: .dockerignore mengecualikan isinya, sedangkan
# `dump-autoload` memicu `artisan package:discover` yang menuntut folder cache
# Blade ada — tanpa ini build berhenti dengan "Please provide a valid cache path".
# --no-scripts dipakai sebagai pengaman kedua: manifes paket toh dibangun ulang
# sendiri saat aplikasi pertama kali jalan.
RUN mkdir -p bootstrap/cache \
             storage/framework/cache/data storage/framework/sessions storage/framework/views \
             storage/logs \
    && composer dump-autoload --optimize --no-dev --no-scripts

# ---------- 3. Runtime ----------
# PHP 8.4, BUKAN 8.3: composer.lock mengunci Symfony 8.1 yang menuntut
# PHP >= 8.4.1. composer.json memang menulis "^8.3", tetapi lock-lah yang
# menentukan saat `composer install` dijalankan.
#
# VARIAN APACHE, bukan `cli`. Sebelumnya container ini menyalakan aplikasinya
# dengan `php artisan serve` — server bawaan PHP untuk pengembangan, yang
# melayani SATU permintaan pada satu waktu. Akibatnya satu impor panjang
# membekukan seluruh aplikasi termasuk health check Render, yang lalu
# menyimpulkan service-nya mati dan merestart container di tengah pekerjaan:
# datanya tersimpan, tetapi penggunanya melihat 502/503.
#
# Apache dipilih ketimbang FrankenPHP/RoadRunner karena selisihnya paling
# kecil dari yang sudah berjalan: `public/.htaccess` bawaan Laravel dipakai apa
# adanya, ekstensinya dipasang dengan cara yang sama, dan tak ada semantik baru
# yang perlu dipelajari — pertimbangan yang berbobot justru karena berkas ini
# TIDAK BISA DIUJI di mesin pengembang (Docker tak terpasang di sana).
FROM php:8.4-apache

# pdo_pgsql (database), intl & zip (ekspor Excel/CSV), gd (gambar di PDF dompdf),
# bcmath (hitungan uang), opcache (percepat cold start yang jadi masalah di Render).
COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql intl zip gd bcmath opcache

# `rewrite` wajib: seluruh perutean Laravel lewat public/.htaccess, dan tanpa
# modul ini setiap alamat selain "/" menjadi 404 — gejala yang menyesatkan
# karena halaman depannya tetap terbuka.
#
# Situs bawaan Debian (000-default) DIMATIKAN, bukan ditimpa: ia menunjuk
# /var/www/html yang tak berisi apa-apa di image ini.
COPY docker/apache.conf /etc/apache2/sites-available/alwafi.conf
COPY docker/apache-mpm.conf /etc/apache2/conf-available/alwafi-mpm.conf
RUN a2enmod rewrite \
    && a2dissite 000-default \
    && a2ensite alwafi \
    && a2enconf alwafi-mpm

WORKDIR /app
COPY --from=vendor /app ./
COPY --from=assets /app/public/build ./public/build

# Direktori kerja Laravel dibuat ulang karena .dockerignore mengosongkan isinya.
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views \
             storage/logs storage/app/private bootstrap/cache \
    && chmod -R 777 storage bootstrap/cache

COPY docker/start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

# 7860 = port yang ditunggu Hugging Face Spaces (lihat app_port di README.md).
# Render menyuntikkan $PORT sendiri saat runtime, jadi nilai bawaan ini tak
# mengganggu — start.sh selalu memakai $PORT bila ada.
ENV PORT=7860
EXPOSE 7860

CMD ["/usr/local/bin/start.sh"]
