#!/usr/bin/env bash
# Kirim kode terbaru ke satu instans di VPS (dijalankan dari laptop, SESUDAH deploy/ke-dev.sh mem-push cabangnya).
#   bash deploy/ke-vps.sh wafimart            # cabang bawaan: dev-coba
#   bash deploy/ke-vps.sh wafimart dev-coba
#
# Di server: samakan kode ke origin/<cabang>, composer, bangun aset (Node ada di VPS — tak perlu kirim
# public/build seperti ke hosting lama), migrate, cache. Instans dipasang sekali lewat pasang-instans-vps.sh.
set -euo pipefail

NAMA="${1:?Pakai: bash deploy/ke-vps.sh <instans> [cabang]}"
CABANG="${2:-dev-coba}"
VPS="${VPS:-deploy@31.97.108.145}"
KUNCI="${KUNCI:-$HOME/.ssh/alwafi_ed25519}"

echo "[$(date +%H:%M:%S)] Mengirim $CABANG ke instans $NAMA di $VPS"
# Heredoc berkutip: isinya dijalankan apa adanya di server; nama & cabang lewat variabel lingkungan.
ssh -i "$KUNCI" -o BatchMode=yes "$VPS" "NAMA='$NAMA' CABANG='$CABANG' bash -s" <<'EOF'
set -euo pipefail
umask 002
cd "/var/www/$NAMA"
SEBELUM=$(git rev-parse --short HEAD)
git fetch --quiet origin
git reset --quiet --hard "origin/$CABANG"
composer install --no-dev --optimize-autoloader --no-interaction --quiet
npm ci --no-audit --no-fund --loglevel=error >/dev/null
npm run build --silent >/dev/null 2>&1
php artisan migrate --force --no-interaction | grep -E "DONE|Nothing to migrate" || true
php artisan optimize --no-interaction >/dev/null
echo "Server: $SEBELUM -> $(git log -1 --format='%h %s' | cut -c1-90)"
EOF
echo "[$(date +%H:%M:%S)] Selesai."
