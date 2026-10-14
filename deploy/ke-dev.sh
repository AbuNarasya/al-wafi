#!/bin/bash
#
# KIRIM KE DEV — commit yang sedang dipegang laptop → dev.erp.al-wafi.sch.id.
# Dijalankan DI LAPTOP (Git Bash), dari folder proyek:
#
#     bash deploy/ke-dev.sh
#
# Keputusan user 14 Okt 2026: dev.erp adalah tempat coba-coba sebelum ERP
# sungguhan, dan setiap "commit" langsung dikirim ke sana. Satu perintah ini
# menggantikan urutan push → bangun aset → scp → merge → migrate → cache.
#
# ── CABANG ──
#  • Commit tetap rapi di cabang kerja, dan cabang itu ikut di-push sebagai cadangan.
#  • Server dev mengikuti cabang `dev-coba`, yang DITIMPA PAKSA tiap kiriman —
#    ia cermin "apa yang sedang dicoba", bukan riwayat.
#  • `main` TIDAK disentuh: ia disisihkan untuk rilis ke ERP sungguhan
#    (deploy/hostinger.sh, dijalankan di server produksi).
#
# ── YANG DIJAGA ──
#  1. Hanya commit yang dikirim. Perubahan yang belum di-commit → batal; dev tak
#     pernah menjalankan kode yang tak tercatat di Git (pelajaran suntingan ISE
#     di server produksi — lihat DEPLOY-HOSTINGER.md).
#  2. Server yang disunting langsung → batal, tak ditimpa diam-diam.
#  3. Aset Vite dibangun ulang tiap kiriman dan manifest-nya dicocokkan di server:
#     `npm`/`node` tak ada di Hostinger, dan Tailwind hanya memuat kelas yang
#     sudah dibangun.
#  4. Migrasi yang tercatat di database dev tetapi berkasnya tak ada di commit
#     ini (sisa percobaan yang dibuang) DILAPORKAN — rollback-nya keputusan manusia.
#
# Composer TIDAK dijalankan: `proc_open` sering mati di server. Bila composer.lock
# berubah, skrip berhenti dan menyebutkannya.

set -euo pipefail

SSH_HOST="${SSH_HOST:-alwafi}"
APP_DIR="${APP_DIR:-domains/dev.erp.al-wafi.sch.id/app-laravel}"   # relatif dari $HOME server
CABANG_DEV="${CABANG_DEV:-dev-coba}"
URL_DEV="${URL_DEV:-https://dev.erp.al-wafi.sch.id}"

# LogLevel=ERROR membungkam peringatan "post-quantum key exchange" OpenSSH baru.
SSH_OPSI=(-o BatchMode=yes -o LogLevel=ERROR -o ConnectTimeout=30)

catat() { echo "[$(date '+%H:%M:%S')] $*"; }
gagal() { echo "[$(date '+%H:%M:%S')] GAGAL: $*" >&2; exit 1; }

main() {
    cd "$(git rev-parse --show-toplevel)" || gagal "Bukan di dalam repo Git."

    # ── 1. Hanya yang sudah di-commit ─────────────────────────────────────
    if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
        git status --short --untracked-files=no
        gagal "Ada perubahan yang belum di-commit. Commit dulu, baru kirim ke dev."
    fi
    local commit pendek cabang
    commit="$(git rev-parse HEAD)"
    pendek="$(git rev-parse --short HEAD)"
    cabang="$(git rev-parse --abbrev-ref HEAD)"
    catat "Mengirim $pendek ($(git log -1 --format=%s))"

    # ── 2. Aset ──────────────────────────────────────────────────────────
    catat "Membangun aset (npm run build)…"
    npm run build >/dev/null 2>&1 || gagal "npm run build gagal — jalankan sendiri untuk melihat galatnya."
    local manifest
    manifest="$(md5sum public/build/manifest.json | cut -c1-32)"

    # ── 3. Push ──────────────────────────────────────────────────────────
    if [ "$cabang" != "HEAD" ]; then
        git push -q origin "HEAD:refs/heads/$cabang" || gagal "Push cabang $cabang gagal (periksa koneksi internet)."
    fi
    git push -q --force origin "$commit:refs/heads/$CABANG_DEV" || gagal "Push ke $CABANG_DEV gagal."
    catat "Ter-push: $cabang & $CABANG_DEV → $pendek"

    # ── 4. Kirim aset ────────────────────────────────────────────────────
    scp -q -r "${SSH_OPSI[@]}" public/build "$SSH_HOST:$APP_DIR/public/" || gagal "Mengirim aset gagal."
    catat "Aset terkirim."

    # ── 5. Di server ─────────────────────────────────────────────────────
    # Heredoc berkutip: tak ada yang diekspansi di laptop; nilai dikirim sebagai argumen.
    ssh "${SSH_OPSI[@]}" "$SSH_HOST" bash -s -- "$APP_DIR" "$CABANG_DEV" "$commit" "$manifest" <<'SERVER' \
        || gagal "Langkah di server berhenti — lihat pesan di atas."
set -euo pipefail
dir="$1"; cabang="$2"; commit="$3"; manifest="$4"
cd "$HOME/$dir"

[ "$(md5sum public/build/manifest.json | cut -c1-32)" = "$manifest" ] \
    || { echo "GAGAL: manifest aset di server tak sama dengan yang dibangun."; exit 1; }

if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    git status --short --untracked-files=no
    echo "GAGAL: ada berkas yang disunting langsung di server. Simpan/buang dulu, lalu kirim lagi."
    exit 1
fi

git fetch -q origin "$cabang"
[ "$(git rev-parse FETCH_HEAD)" = "$commit" ] \
    || { echo "GAGAL: $cabang di GitHub bukan commit yang baru dikirim."; exit 1; }

lama="$(git rev-parse HEAD)"
if ! git diff --quiet "$lama" "$commit" -- composer.lock; then
    echo "GAGAL: composer.lock berubah — paket composer perlu dipasang di server (proc_open sering mati)."
    exit 1
fi

git checkout -q -B "$cabang" "$commit"
php artisan migrate --force --no-interaction
php artisan route:clear >/dev/null
php artisan config:cache >/dev/null
php artisan view:cache >/dev/null

yatim="$(php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    foreach (Illuminate\Support\Facades\DB::table("migrations")->pluck("migration") as $m) {
        if (! is_file("database/migrations/$m.php")) echo "  $m\n";
    }' 2>/dev/null || true)"
if [ -n "$yatim" ]; then
    echo "PERINGATAN: migrasi ini tercatat di database dev tetapi berkasnya tak ada di commit ini:"
    echo "$yatim"
    echo "  (sisa percobaan yang dibuang? rollback-nya perlu diputuskan)"
fi

echo "Server: $(git rev-parse --short "$lama") → $(git rev-parse --short HEAD)"
SERVER

    # ── 6. Masih hidup? ──────────────────────────────────────────────────
    local kode
    kode="$(curl -s -o /dev/null -w '%{http_code}' --max-time 60 "$URL_DEV/login" || echo 000)"
    [ "$kode" = "200" ] || gagal "$URL_DEV/login membalas $kode — periksa storage/logs/laravel.log di server."
    catat "Selesai: $URL_DEV menjalankan $pendek."
}

main "$@"
