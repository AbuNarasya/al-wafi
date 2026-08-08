# Deploy uji coba ke Render (paket gratis)

Berkas pendukung: `Dockerfile`, `.dockerignore`, `docker/start.sh`, `render.yaml`.
Ditujukan untuk **mencoba**, bukan dipakai seterusnya — lihat batasannya di bawah.

## 1. Jadikan repo git & dorong ke GitHub

Folder ini belum berupa repo. `.env` sudah masuk `.gitignore`, jadi kredensial
lokal tidak ikut terkirim.

```bash
git init && git add -A && git commit -m "Siap deploy uji coba ke Render"
```

Buat repo kosong di GitHub (boleh privat — Render gratis tetap bisa membacanya),
lalu:

```bash
git remote add origin https://github.com/<akun>/<repo>.git && git branch -M main && git push -u origin main
```

## 2. Siapkan APP_KEY

```bash
php artisan key:generate --show
```

Salin hasilnya (berawalan `base64:`) — dipakai di langkah berikutnya.

## 3. Buat layanan di Render

Render → **New → Blueprint**, arahkan ke repo tadi. `render.yaml` membuat satu
web service Docker di region **Ohio**, dengan database mengarah ke **Neon**
(bukan PostgreSQL bawaan Render, yang berumur terbatas lalu dihapus).

Render akan menanyakan tiga nilai yang sengaja tidak disimpan di repo:

| Variabel      | Isi                                                        |
| ------------- | ---------------------------------------------------------- |
| `APP_KEY`     | hasil langkah 2                                             |
| `APP_URL`     | `https://<nama-service>.onrender.com` (isi setelah dibuat)  |
| `DB_PASSWORD` | password dari dashboard Neon                                |

Build pertama sekitar 3–5 menit. Migrasi dijalankan otomatis saat container
menyala (lihat `docker/start.sh`).

## 4. Masuk ke aplikasi

Data awal (level, COA, unit, bagian, rantai approval, dan **pengguna admin**)
diisi otomatis saat container menyala — lihat `docker/start.sh`. Login pertama:

| Username | Password   |
| -------- | ---------- |
| `admin`  | `admin123` |

**Segera ganti password itu** lewat menu Pengguna, lalu setel env `SEED_ON_DEPLOY`
menjadi `false` di Render — kalau tidak, password akan dikembalikan ke bawaannya
setiap kali container menyala ulang (dan di paket gratis itu sering, karena
instance tidur lalu bangun lagi).

Ingin membawa data lokal? `pg_dump` dari PostgreSQL lokal lalu `psql` ke koneksi
eksternal database Render.

## Batas paket gratis (terima atau pilih cara lain)

- **Tidur** setelah 15 menit menganggur; akses berikutnya menunggu ± 1 menit.
- **Tanpa disk permanen** — bukti pembayaran & berkas santri yang diunggah HILANG
  setiap restart/deploy. Jangan dipakai menyimpan berkas sungguhan.
- **Tanpa cron/worker** — `reminder:tagihan` harian tidak berjalan. Tombol
  "Kirim Reminder Sekarang" tetap berfungsi manual.
- **Database gratis berumur terbatas**, lalu dihapus Render.

Ketentuan paket gratis Render berubah dari waktu ke waktu; cek halaman harganya
sebelum mulai.

## Catatan teknis

- **Server: Apache (`php:8.4-apache`), bukan `php artisan serve`.** Server bawaan
  PHP melayani SATU permintaan pada satu waktu — satu impor panjang membekukan
  seluruh aplikasi termasuk health check, Render menyimpulkan service-nya mati,
  lalu merestart container di tengah pekerjaan. Gejalanya: datanya tersimpan,
  tetapi penggunanya melihat 502/503. Perutean memakai `public/.htaccess` bawaan
  Laravel (karena itu `AllowOverride All` di `docker/apache.conf`).
- **Pekerja Apache dibatasi 8** (`docker/apache-mpm.conf`). Bawaan Debian 150,
  dan tiap pekerja satu proses PHP utuh — pada 512 MB paket gratis itu membuat
  container dibunuh kernel tanpa pesan yang menjelaskan apa-apa.
- Port Apache ditulis ulang saat container menyala (`sed` di `docker/start.sh`),
  karena konfigurasi Apache tak mengenal variabel lingkungan sedangkan Render
  baru menyuntikkan `$PORT` saat runtime.
- `route:cache` sengaja tidak dijalankan: `routes/web.php` memakai closure pada
  rute `/`, dan Laravel menolak men-serialisasi closure.
- `trustProxies` ditambahkan di `bootstrap/app.php`. Tanpa itu Laravel mengira
  koneksinya HTTP di balik proxy Render, sehingga CSS/JS diblokir sebagai mixed
  content dan redirect login berputar. Ini juga yang membuat aplikasi aman
  diakses lewat Cloudflare Tunnel.
- Aset Vite dibangun di dalam image (`public/build` tidak ikut git). Tahap Node
  menyalin seluruh sumber karena Tailwind 4 memindai berkas Blade & PHP untuk
  menentukan kelas yang dipakai.
