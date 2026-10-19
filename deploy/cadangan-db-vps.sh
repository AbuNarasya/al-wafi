#!/usr/bin/env bash
# Cadangan harian SELURUH database instans ERP di VPS (dipasang di /usr/local/bin, dijalankan cron root 01:30).
#
#   /var/backups/alwafi/harian/<db>-YYYYmmdd-HHMM.dump   14 terakhir per database
#   /var/backups/alwafi/mingguan/…                       hari Minggu, 8 terakhir
#   /var/backups/alwafi/bulanan/…                        tanggal 1, 12 terakhir
#
# Format custom pg_dump (-Fc): dipulihkan dengan `pg_restore -d <db> --clean <berkas>`. Berkas dibaca kelompok
# `deploy` supaya bisa ditarik ke laptop & diunggah ke Google Drive tanpa root. Database sistem tidak ikut.
set -euo pipefail
AKAR=/var/backups/alwafi
STEMPEL=$(date +%Y%m%d-%H%M)
install -d -m 750 -o root -g deploy "$AKAR" "$AKAR/harian" "$AKAR/mingguan" "$AKAR/bulanan"

DBS=$(sudo -u postgres psql -tAc "select datname from pg_database where not datistemplate and datname <> 'postgres' order by 1")
for DB in $DBS; do
  BERKAS="$AKAR/harian/$DB-$STEMPEL.dump"
  sudo -u postgres pg_dump -Fc "$DB" > "$BERKAS.tmp"
  # Uji baca: berkas yang tak bisa didaftar isinya tak boleh menggantikan cadangan lama.
  pg_restore --list "$BERKAS.tmp" > /dev/null
  mv "$BERKAS.tmp" "$BERKAS"
  chown root:deploy "$BERKAS"; chmod 640 "$BERKAS"
  [ "$(date +%u)" = 7 ] && cp -p "$BERKAS" "$AKAR/mingguan/"
  [ "$(date +%d)" = 01 ] && cp -p "$BERKAS" "$AKAR/bulanan/"

  # Rotasi per database: simpan N terbaru.
  for pasang in harian:14 mingguan:8 bulanan:12; do
    DIR="$AKAR/${pasang%%:*}"; N="${pasang##*:}"
    # find, bukan ls: folder yang masih kosong membuat `ls` gagal & menghentikan skrip (set -e + pipefail).
    find "$DIR" -maxdepth 1 -name "$DB-*.dump" -printf '%T@ %p\n' | sort -rn | tail -n +$((N + 1)) | cut -d' ' -f2- | xargs -r rm -f
  done
  echo "$(date '+%F %T') $DB $(du -h "$BERKAS" | cut -f1)"
done
