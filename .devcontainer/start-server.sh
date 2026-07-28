#!/usr/bin/env bash
#
# Menyalakan server aplikasi di latar belakang. Dijalankan tiap codespace mulai.
#
# Sengaja berupa skrip terpisah, bukan satu baris di devcontainer.json: proses
# yang di-background langsung dari postAttachCommand gampang ikut mati saat
# shell pemanggilnya selesai — itu yang membuat port 8000 menjawab 502.

mkdir -p storage/logs
LOG=storage/logs/serve.log

# Bersihkan server lama agar port tak bentrok saat codespace dinyalakan ulang.
pkill -f "artisan serve" 2>/dev/null || true
sleep 1

# setsid melepas proses dari terminal pemanggil; bila tak tersedia, nohup saja
# sudah cukup untuk membuatnya bertahan.
: > "$LOG"
if command -v setsid >/dev/null 2>&1; then
    setsid nohup php artisan serve --host=0.0.0.0 --port=8000 >> "$LOG" 2>&1 < /dev/null &
else
    nohup php artisan serve --host=0.0.0.0 --port=8000 >> "$LOG" 2>&1 < /dev/null &
fi

sleep 3
if pgrep -f "artisan serve" > /dev/null; then
    echo "Server siap di port 8000. Buka tab PORTS lalu klik ikon globe."
    exit 0
fi

# Gagal — tampilkan sebabnya langsung, jangan menyuruh mencari sendiri.
echo "Server GAGAL menyala. Pesan terakhirnya:"
echo "------------------------------------------------------------"
tail -n 30 "$LOG" 2>/dev/null || echo "(log kosong — artisan tak sempat menulis apa pun)"
echo "------------------------------------------------------------"
echo "Coba jalankan di depan mata untuk melihat error lengkapnya:"
echo "  php artisan serve --host=0.0.0.0 --port=8000"
exit 1
