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
# SEMENTARA (21 Okt 2026): kotak surat di paket Premium Web Hosting erp.al-wafi.sch.id (zona DNS `erp` bisa dikelola
# kolaborator; domain utama belum dibagikan pemilik akun). Paket itu berakhir 19 Nov 2026. Kelak, setelah
# noreply@al-wafi.sch.id ada:  PENGIRIM=noreply@al-wafi.sch.id bash deploy/pasang-smtp.sh
PENGIRIM="${PENGIRIM:-noreply@erp.al-wafi.sch.id}"
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
MAIL_HOST=smtp.hostinger.com
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
kirim() { # $1 = tujuan ssh (beserta opsi), $2 = folder, $3 = nama pengirim
    local perintah
    perintah="IFS= read -r SANDI; export SANDI; exec bash -s -- $(printf '%q ' "$2" "$3" "$UJI")"
    { printf '%s\n' "$SANDI"; printf '%s\n' "$SKRIP_SERVER"; } | ssh $1 "$perintah"
}

for s in "$@"; do
    case "$s" in
        erp)      kirim "-i $KUNCI -o BatchMode=yes $VPS" /var/www/erp "Al Wafi" ;;
        wafimart) kirim "-i $KUNCI -o BatchMode=yes $VPS" /var/www/wafimart "Wafi Mart" ;;
        dev)      kirim "-o BatchMode=yes $DEV_HOST" domains/dev.erp.al-wafi.sch.id/app-laravel "Al Wafi (dev)" ;;
        *) echo "Instans tak dikenal: $s (erp | wafimart | dev)" >&2 ;;
    esac
done
unset SANDI
