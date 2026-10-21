#!/usr/bin/env bash
# Pasang pengiriman email SMTP Email Hostinger ke .env instans (keputusan user 21 Okt 2026; alamat pengirim di bawah).
# Dijalankan DARI LAPTOP oleh user — sandinya diketik saat diminta (tak tampil, tak tersimpan di riwayat perintah),
# dikirim lewat stdin SSH (baris pertama), lalu ditulis ke .env di server. Claude tidak menulis sandi ke server.
#
#   bash deploy/pasang-smtp.sh              # erp (produksi) + wafimart di VPS + dev.erp
#   bash deploy/pasang-smtp.sh wafimart     # hanya instans tertentu: erp | wafimart | dev
#
# Tiap .env dicadangkan dulu ke .env.sebelum-smtp; baris MAIL_* lama diganti. Sesudahnya: config:cache + email:uji.
# Sandi TIDAK boleh mengandung tanda kutip tunggal (') — .env menulisnya di antara kutip tunggal supaya $ tak diurai.
set -euo pipefail

VPS="${VPS:-deploy@31.97.108.145}"
KUNCI="${KUNCI:-$HOME/.ssh/alwafi_ed25519}"
DEV_HOST="${DEV_HOST:-alwafi}"
# 21 Okt 2026: kotak surat noreply@al-wafi.sch.id (dibuat tim IT Al Wafi; MX & SPF Hostinger sudah ada). Alamat
# "noreply@mail.al-wafi.sch.id" di pesan awal ternyata tak bisa login.
# Ganti tanpa menyunting skrip:  PENGIRIM=… SMTP_HOST=… NAMA_ALWAFI=… bash deploy/pasang-smtp.sh
PENGIRIM="${PENGIRIM:-noreply@al-wafi.sch.id}"
SMTP_HOST="${SMTP_HOST:-smtp.hostinger.co.id}"
NAMA_ALWAFI="${NAMA_ALWAFI:-Yayasan Al Sudais Indonesia}"
NAMA_WAFIMART="${NAMA_WAFIMART:-Wafi Mart}"
[ $# -eq 0 ] && set -- erp wafimart dev

read -rsp "Sandi ${PENGIRIM}: " SANDI; echo
if [[ -z "$SANDI" || "$SANDI" == *"'"* ]]; then
    echo "Sandi kosong atau mengandung kutip tunggal (') — dibatalkan." >&2
    exit 1
fi
read -rp "Kirim email uji ke alamat (kosongkan bila tak perlu): " UJI

# Dijalankan di server oleh `bash -s`; SANDI sudah diekspor (dibaca dari baris pertama stdin).
# $1 = folder app, $2 = nama pengirim, $3 = alamat uji (boleh kosong).
SKRIP_SERVER='set -euo pipefail
cd "$1"
umask 077
cp -p .env .env.sebelum-smtp
grep -v "^MAIL_" .env.sebelum-smtp > .env.baru || true
cat >> .env.baru <<ENV

MAIL_MAILER=smtp
MAIL_SCHEME=smtps
MAIL_HOST='"$SMTP_HOST"'
MAIL_PORT=465
MAIL_USERNAME='"$PENGIRIM"'
MAIL_PASSWORD='"'"'${SANDI}'"'"'
MAIL_FROM_ADDRESS='"$PENGIRIM"'
MAIL_FROM_NAME="$2"
ENV
cat .env.baru > .env && rm -f .env.baru
php artisan config:cache >/dev/null
# email:uji baru ada sesudah kode ini sampai di instans itu (erp produksi: sesudah "rilis") — tak menggagalkan pemasangan.
if [ -n "$3" ]; then php artisan email:uji "$3" || echo "(email:uji gagal / belum tersedia di instans ini — lihat pesan di atas)"; fi
echo "Selesai: $1"'

# Baris pertama stdin = sandi (dibaca & diekspor), sisanya = skrip untuk bash -s.
# Opsi ssh lewat ARRAY: jalur kunci di folder home yang mengandung spasi ("C:/Users/Abu Narasya") tak boleh terpecah.
kirim() { # $1 = folder, $2 = nama pengirim, sisanya = argumen ssh (opsi + tujuan)
    local folder="$1" nama="$2" perintah
    shift 2
    perintah="IFS= read -r SANDI; export SANDI; exec bash -s -- $(printf '%q ' "$folder" "$nama" "$UJI")"
    { printf '%s\n' "$SANDI"; printf '%s\n' "$SKRIP_SERVER"; } | ssh "$@" "$perintah"
}

for s in "$@"; do
    case "$s" in
        erp)      kirim /var/www/erp "$NAMA_ALWAFI" -i "$KUNCI" -o BatchMode=yes "$VPS" ;;
        wafimart) kirim /var/www/wafimart "$NAMA_WAFIMART" -i "$KUNCI" -o BatchMode=yes "$VPS" ;;
        dev)      kirim domains/dev.erp.al-wafi.sch.id/app-laravel "$NAMA_ALWAFI (dev)" -o BatchMode=yes "$DEV_HOST" ;;
        *) echo "Instans tak dikenal: $s (erp | wafimart | dev)" >&2 ;;
    esac
done
unset SANDI
