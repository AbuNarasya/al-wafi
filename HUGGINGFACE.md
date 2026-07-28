# Deploy gratis tanpa kartu — Hugging Face Spaces + Neon

Hasil akhirnya: satu alamat publik yang hidup terus, bisa dibuka siapa pun,
**tanpa komputer Anda menyala**. Dua layanan, keduanya gratis dan tak meminta
kartu:

| Layanan | Perannya |
| ------- | -------- |
| **Neon** | PostgreSQL — tempat data disimpan |
| **Hugging Face Spaces** | menjalankan aplikasinya dari `Dockerfile` |

## 1. Database di Neon

1. Daftar di [neon.com](https://neon.com) (bisa lewat akun GitHub).
2. **Create project** — nama bebas, versi Postgres samakan dengan yang dipakai
   di komputer (18), **Neon Auth dibiarkan mati**.
3. **Region: pilih AWS US East**, bukan Singapore.

   Ini kontra-intuitif tetapi penting. Yang menentukan kecepatan bukan jarak
   Anda ke database, melainkan jarak **aplikasi** ke database: satu halaman
   Laravel memanggil database belasan kali, sedangkan pengguna hanya satu kali
   bolak-balik per halaman. Karena Space berjalan di server Hugging Face di
   Amerika Serikat, database yang diletakkan di Singapura membuat tiap halaman
   terasa lambat meski aplikasinya sehat.

4. Buka **Connection Details**, salin nilai-nilai ini sebelum menutup halaman
   (password hanya ditampilkan sekali): `Host`, `Database`, `User`, `Password`.

Host Neon berbentuk seperti `ep-xxx-123456.ap-southeast-1.aws.neon.tech`.

## 2. Aplikasi di Hugging Face

1. Daftar di [huggingface.co](https://huggingface.co) (email saja, tanpa kartu).
2. Klik foto profil → **New Space**.
3. Isi: **Space name** `al-wafi`, **License** bebas, **SDK** pilih **Docker** →
   template **Blank**, visibilitas **Public**.
4. Klik **Create Space**. Halaman berikutnya menampilkan alamat git Space Anda,
   berbentuk `https://huggingface.co/spaces/<akun>/al-wafi`.

## 3. Kirim kode ke Space

Space adalah repo git juga, jadi cukup ditambahkan sebagai tujuan kedua dari
repo yang sudah ada:

```bash
git remote add space https://huggingface.co/spaces/<akun>/al-wafi
```

```bash
git push space main
```

Saat diminta login: username = akun Hugging Face Anda, password = **Access
Token** (buat di Settings → Access Tokens → New token, pilih peran **Write**).
Kata sandi biasa tidak diterima.

## 4. Isi kredensial database

Di halaman Space → **Settings** → **Variables and secrets**, tambahkan:

| Nama | Isi | Jenis |
| ---- | --- | ----- |
| `APP_KEY` | hasil `php artisan key:generate --show` | Secret |
| `DB_CONNECTION` | `pgsql` | Variable |
| `DB_HOST` | host dari Neon | Secret |
| `DB_PORT` | `5432` | Variable |
| `DB_DATABASE` | nama database Neon | Secret |
| `DB_USERNAME` | user Neon | Secret |
| `DB_PASSWORD` | password Neon | Secret |
| `DB_SSLMODE` | `require` | Variable |
| `SESSION_DRIVER` | `database` | Variable |
| `CACHE_STORE` | `database` | Variable |
| `QUEUE_CONNECTION` | `database` | Variable |
| `LOG_CHANNEL` | `stderr` | Variable |
| `APP_ENV` | `production` | Variable |
| `APP_DEBUG` | `false` | Variable |
| `APP_URL` | `https://<akun>-al-wafi.hf.space` | Variable |

Neon **mewajibkan SSL**, karena itu `DB_SSLMODE=require` tidak boleh dilewat.

Setelah disimpan, Space membangun ulang sendiri. Ikuti prosesnya di tab
**Logs** — cari baris `→ Menjalankan migrasi…`, `→ Mengisi data awal…`, lalu
`→ Menyalakan server di port 7860…`.

## 5. Masuk

Buka `https://<akun>-al-wafi.hf.space`, login `admin` / `admin123`, lalu
**segera ganti passwordnya**. Setelah itu ubah variabel `SEED_ON_DEPLOY`
menjadi `false` agar password tak dikembalikan ke bawaannya tiap kali Space
menyala ulang.

## Yang perlu diterima

- **Berkas unggahan tidak permanen.** Bukti pembayaran & dokumen santri hilang
  setiap kali Space dibangun ulang. Data di database aman karena tersimpan di
  Neon.
- **Tidak ada penjadwal**, jadi reminder tagihan harian tak berjalan sendiri.
  Tombol "Kirim Reminder Sekarang" tetap berfungsi.
- **Space tidur** setelah lama tak dikunjungi, lalu menyala lagi saat dibuka
  (butuh sekitar satu menit).
- **Space bersifat publik**: siapa pun yang tahu alamatnya sampai ke halaman
  login. Jangan isi dengan data santri sungguhan selama masih tahap uji coba.
