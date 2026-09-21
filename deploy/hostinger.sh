#!/bin/bash
#
# DEPLOY KE HOSTINGER — pengganti delapan perintah manual di DEPLOY-HOSTINGER.md
# Bagian 12. Dijalankan DI SERVER:
#
#     ssh alwafi 'bash ~/domains/erp.al-wafi.sch.id/app-laravel/deploy/hostinger.sh'
#
# ── KENAPA SKRIP, BUKAN PIPELINE YANG TERPICU PUSH ──
# Bahaya pada cara manual bukan "lupa deploy", melainkan deploy yang MELESET:
# lupa `artisan up`, lupa mengirim aset, urutan tertukar, migrasi gagal di
# tengah. Keempatnya kesalahan pelaksanaan, dan keempatnya disembuhkan skrip.
# Pemicu otomatis menyembuhkan penyakit yang tak diderita, lalu menambah satu
# yang baru: push keliru langsung jadi produksi tanpa ada yang sempat berpikir.
#
# ── LIMA JAMINAN ──
#  1. Urutan tak mungkin tertukar.
#  2. Aplikasi tak pernah tertinggal tertutup KARENA LUPA — `artisan up` dijamin
#     trap. Satu-satunya keadaan ia dibiarkan tertutup adalah kegagalan sesudah
#     kode berubah, dan itu memang disengaja (lihat bukaLagi).
#  3. Menolak berangkat bila aset Vite belum dikirim — lebih baik batal daripada
#     galat 500 keras karena manifest hilang.
#  4. Berhenti pada kegagalan pertama; tak pernah melanjutkan di atas skema
#     yang setengah termigrasi.
#  5. Tiap deploy tercatat di storage/logs/deploy.log.
#
# ── DUA HAL YANG MUDAH TERLEWAT SAAT MEMBACA SKRIP INI ──
#  • Seluruh isinya dibungkus fungsi `main` dan baru dipanggil di baris terakhir.
#    Itu WAJIB: skrip ini ikut ter-`git pull`, dan bash membaca berkasnya sambil
#    berjalan — tanpa pembungkus, skrip yang berubah di tengah eksekusi akan
#    melompat ke tempat acak.
#  • Aplikasi baru ditutup SESUDAH semua pemeriksaan lolos. Tak ada gunanya
#    memasang halaman "sedang dirawat" hanya untuk membatalkan deploy.

set -euo pipefail

APP_DIR="${APP_DIR:-/home/u607494788/domains/erp.al-wafi.sch.id/app-laravel}"
CABANG="${CABANG:-main}"
PHP="${PHP:-/usr/bin/php}"
COMPOSER="${COMPOSER:-$(command -v composer || echo /usr/local/bin/composer)}"
LOG=""

# Dinaikkan jadi 1 begitu kode di server BERUBAH. Sesudah titik itu, kegagalan
# apa pun TIDAK boleh membuka aplikasi kembali — lihat bukaLagi().
SUDAH_UBAH=0

catat() {
    local pesan="[$(date '+%Y-%m-%d %H:%M:%S')] $*"
    echo "$pesan"
    [ -n "$LOG" ] && echo "$pesan" >>"$LOG" || true
}

gagal() {
    catat "GAGAL: $*"
    exit 1
}

# Dipasang sebagai trap begitu aplikasi ditutup. Tanpa ini, skrip yang mati di
# tengah meninggalkan aplikasi tertutup untuk SEMUA orang — dan gejalanya
# membingungkan siapa pun yang tak tahu sebabnya.
#
# ⚠️ SATU PENGECUALIAN, DAN INI DISENGAJA. Kalau kegagalan terjadi SESUDAH kode
# di server berubah, aplikasi DIBIARKAN TERTUTUP. Membukanya berarti menyajikan
# kode baru di atas skema yang mungkin setengah termigrasi — pada aplikasi yang
# memegang uang, itu bukan gangguan layanan melainkan risiko data rusak. Ditutup
# itu berisik dan segera ketahuan; setengah jadi itu senyap.
bukaLagi() {
    local kode=$?
    [ -f "$APP_DIR/storage/framework/down" ] || return $kode

    if [ "$kode" -ne 0 ] && [ "$SUDAH_UBAH" -eq 1 ]; then
        catat "APLIKASI SENGAJA DIBIARKAN TERTUTUP — kode sudah berubah tetapi deploy gagal."
        catat "Periksa dulu keadaannya, baru buka dengan:  cd $APP_DIR && $PHP artisan up"
        return $kode
    fi

    catat "Membuka kembali aplikasi."
    "$PHP" artisan up >/dev/null 2>&1 || catat "PERINGATAN: artisan up gagal — jalankan manual."
    [ "$kode" -ne 0 ] && catat "Deploy berhenti dengan kode $kode." || true
    return $kode
}

main() {
    cd "$APP_DIR" || gagal "Folder aplikasi tak ditemukan: $APP_DIR"
    LOG="$APP_DIR/storage/logs/deploy.log"

    # ── Satu deploy pada satu waktu ────────────────────────────────────────
    # Dua deploy yang berjalan berbarengan akan saling menimpa di tengah
    # composer maupun migrasi. flock memastikan yang kedua menyerah, bukan ikut.
    exec 9>"$APP_DIR/storage/framework/deploy.lock"
    flock -n 9 || gagal "Deploy lain sedang berjalan."

    catat "──────── Deploy dimulai ────────"

    # ── Pemeriksaan, SEBELUM aplikasi ditutup ──────────────────────────────

    local cabangKini
    cabangKini="$(git rev-parse --abbrev-ref HEAD)"
    [ "$cabangKini" = "$CABANG" ] || gagal "Server berada di cabang '$cabangKini', bukan '$CABANG'."

    # Berkas yang disunting langsung di server akan membuat `git pull` berhenti
    # di tengah. Lebih baik ketahuan sekarang, sebelum apa pun disentuh.
    if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
        git status --short
        gagal "Ada perubahan belum tersimpan di server. Bereskan dulu."
    fi

    catat "Mengambil perubahan dari origin/$CABANG…"
    git fetch --quiet origin "$CABANG" || gagal "Tak bisa menghubungi GitHub."

    local lama baru
    lama="$(git rev-parse HEAD)"
    baru="$(git rev-parse "origin/$CABANG")"

    if [ "$lama" = "$baru" ]; then
        catat "Sudah mutakhir ($(git rev-parse --short HEAD)). Tak ada yang perlu dikerjakan."
        catat "──────── Selesai, tanpa gangguan layanan ────────"
        return 0
    fi

    catat "Akan naik dari $(git rev-parse --short "$lama") ke $(git rev-parse --short "$baru")."

    periksaAset "$baru"

    # ── Mulai dari sini aplikasi ditutup ───────────────────────────────────

    trap bukaLagi EXIT
    catat "Menutup aplikasi sementara."
    "$PHP" artisan down --retry=60 >/dev/null

    catat "Menarik kode."
    git merge --ff-only "origin/$CABANG" >/dev/null || gagal "Tak bisa maju lurus — riwayat server menyimpang dari origin."
    # Sejak baris ini, kegagalan tak lagi boleh membuka aplikasi sendiri.
    SUDAH_UBAH=1

    catat "Memasang paket PHP."
    "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction --quiet \
        || gagal "composer install gagal."

    catat "Menjalankan migrasi."
    "$PHP" artisan migrate --force --no-interaction \
        || gagal "MIGRASI GAGAL — skema database mungkin setengah jadi. Periksa sebelum membuka aplikasi."

    catat "Menyegarkan cache."
    # SENGAJA hanya config & view, mengikuti prosedur yang sudah terbukti.
    # `route:cache` belum pernah diuji di aplikasi ini; menambahkannya di sini
    # berarti mencobanya pertama kali justru saat produksi sedang tertutup.
    "$PHP" artisan config:cache >/dev/null || gagal "config:cache gagal."
    "$PHP" artisan view:cache >/dev/null || gagal "view:cache gagal."

    catat "Berhasil: sekarang di $(git rev-parse --short HEAD)."
    catat "──────── Selesai ────────"
}

# Menolak berangkat bila commit yang masuk menyentuh sumber tampilan sedangkan
# hasil perakitannya belum dikirim.
#
# `public/build` ada di .gitignore, jadi ia TAK PERNAH ikut `git pull` — dan
# `npm` tak terpasang di server ini (hanya composer & git). Manifest yang
# tertinggal bukan menghasilkan tampilan jelek, melainkan GALAT 500 KERAS.
#
# Caranya: bandingkan waktu commit terakhir yang menyentuh sumber tampilan
# dengan waktu berkas manifest ditulis. Manifest yang lebih tua = aset lama.
periksaAset() {
    local sasaran="$1"
    local manifest="$APP_DIR/public/build/manifest.json"
    local sumber=(resources/js resources/css vite.config.js package-lock.json package.json)

    [ -f "$manifest" ] || gagal "Aset tampilan belum pernah dikirim ($manifest tak ada).
  Di laptop:  npm run build
              scp -r public/build alwafi:$APP_DIR/public/"

    local commitAset
    commitAset="$(git log -1 --format=%ct "$sasaran" -- "${sumber[@]}" 2>/dev/null || echo 0)"
    [ -n "$commitAset" ] || commitAset=0

    if [ "$commitAset" -eq 0 ]; then
        catat "Tampilan tak tersentuh commit ini — aset lama tetap berlaku."
        return 0
    fi

    local waktuManifest
    waktuManifest="$(stat -c %Y "$manifest")"

    if [ "$waktuManifest" -lt "$commitAset" ]; then
        gagal "Sumber tampilan berubah pada commit ini, tetapi aset di server masih yang lama.
  Manifest ditulis : $(date -d "@$waktuManifest" '+%Y-%m-%d %H:%M')
  Perubahan terakhir: $(date -d "@$commitAset" '+%Y-%m-%d %H:%M')

  Di laptop, jalankan dulu:
      npm run build
      scp -r public/build alwafi:$APP_DIR/public/

  Lalu ulangi deploy. (Deploy DIBATALKAN — aplikasi tidak disentuh sama sekali.)"
    fi

    catat "Aset tampilan mutakhir."
}

main "$@"
