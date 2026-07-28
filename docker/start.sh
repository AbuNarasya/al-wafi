#!/bin/sh
#
# Perintah start container di Render.
#
# Migrasi WAJIB berhasil — kalau gagal, lebih baik deploy berhenti daripada
# aplikasi menyala di atas skema yang tak lengkap.
set -e

echo "→ Menjalankan migrasi…"
php artisan migrate --force

# Data awal (level, COA, unit, bagian, rantai approval, pengguna admin, dst).
# Tanpa ini database benar-benar kosong dan TAK ADA yang bisa login.
# Seeder-nya idempotent (updateOrCreate), jadi aman dijalankan berulang — tetapi
# ia MENGEMBALIKAN password admin ke bawaannya setiap container menyala. Itu
# disengaja untuk deploy uji coba; setel SEED_ON_DEPLOY=false di Render bila
# Anda sudah mengganti password dan ingin perubahan itu bertahan.
if [ "${SEED_ON_DEPLOY:-true}" = "true" ]; then
    echo "→ Mengisi data awal…"
    php artisan db:seed --force
fi

# Cache di bawah ini hanya mempercepat; kalau gagal aplikasi tetap jalan (config
# & Blade dibaca langsung), jadi sengaja TIDAK mematikan container.
set +e
php artisan config:cache || echo "  (config:cache dilewati — ada closure di config?)"
php artisan view:cache   || echo "  (view:cache dilewati — Blade akan dikompilasi saat diakses)"
set -e

# CATATAN: `route:cache` sengaja TIDAK dijalankan. routes/web.php memakai closure
# (rute '/' yang mengalihkan ke dashboard), dan Laravel menolak men-serialisasi
# closure — route:cache akan gagal dan menggagalkan deploy.

echo "→ Menyalakan server di port ${PORT:-10000}…"
exec php artisan serve --host=0.0.0.0 --port="${PORT:-10000}"
