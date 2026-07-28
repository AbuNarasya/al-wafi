#!/usr/bin/env bash
#
# Menyalakan server aplikasi di latar belakang. Dijalankan tiap codespace mulai.
#
# Sengaja berupa skrip terpisah, bukan satu baris di devcontainer.json: proses
# yang di-background langsung dari postAttachCommand gampang ikut mati saat
# shell pemanggilnya selesai — itu yang membuat port 8000 menjawab 502.

mkdir -p storage/logs

# Bersihkan server lama agar port tak bentrok saat codespace dinyalakan ulang.
pkill -f "artisan serve" 2>/dev/null || true

setsid nohup php artisan serve --host=0.0.0.0 --port=8000 \
    >> storage/logs/serve.log 2>&1 < /dev/null &

sleep 2
if pgrep -f "artisan serve" > /dev/null; then
    echo "Server siap di port 8000. Buka tab PORTS lalu klik ikon globe."
else
    echo "Server GAGAL menyala. Lihat storage/logs/serve.log, atau jalankan"
    echo "manual:  php artisan serve --host=0.0.0.0 --port=8000"
fi
