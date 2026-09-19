# Deploy ke Hostinger — panduan lengkap

Menggantikan `DEPLOY-RENDER.md`, yang kini usang. Berkas Docker (`Dockerfile`, `docker/`,
`render.yaml`) **tidak dipakai di sini** — shared hosting tak menjalankan container.

> **BELUM PERNAH DIJALANKAN.** Disusun dari fakta panel & mesin lokal yang sudah diperiksa,
> bukan dari deploy yang berhasil. Simpan keluaran tiap perintah, dan catat yang meleset.

---

## Bagian 0 — Peta mental: apa sebenarnya "deploy" itu

Deploy Laravel itu memindahkan **tiga hal yang terpisah**, dan kebanyakan kekacauan deploy
berasal dari menyangka ketiganya satu paket:

| Yang dipindah | Dari mana | Cara |
|---|---|---|
| **Kode** — `app/`, `routes/`, `resources/`, `vendor/`, `public/build/` | repo git + hasil build | `git clone` di server + `composer install` di server + `scp` untuk `public/build` |
| **Database** — struktur tabel | migrasi di `database/migrations/` | `php artisan migrate` **di server** |
| **Konfigurasi & rahasia** — `.env` | tidak dari mana-mana | **diketik langsung di server**, sekali |

Yang terakhir itu kuncinya. `.env` **tidak pernah** ikut git dan **tidak pernah** disalin
dari lokal — sebab `.env` lokal menunjuk ke database kerja di mesin Anda. Kalau ia terbawa,
`migrate` di server akan menghantam database yang salah.

Tiga folder yang perlu Anda bedakan di server:

```
/home/u607494788/                              ← rumah Anda; ADA APLIKASI ORANG LAIN di sini
└── domains/
    ├── erp.al-wafi.sch.id/                    ← wilayah ERP — hanya di sini Anda bekerja
    │   ├── public_html/                       ← yang dilihat browser
    │   └── app-laravel/                       ← aplikasi (akan kita buat)
    └── <domain ISE>/                          ← JANGAN DISENTUH
```

⚠️ **Akun SSH ini tingkat-akun, bukan per-website.** Dari sana berkas aplikasi **ISE** milik
pemilik akun ikut terjangkau dan ikut bisa terhapus. Karena itu tiap perintah di bawah
menyebut path penuh. Jangan pernah menjalankan `rm -rf` atau `chmod -R` dari `~`.

---

## Bagian 1 — Siapkan di mesin sendiri

### 1.1 Pastikan `main` sudah bersih dan ter-push

Server akan mengambil kode lewat `git clone`. Apa pun yang belum di-push, tidak akan sampai.

```bash
git status
```

Harus `working tree clean`. Lalu:

```bash
git log --oneline origin/main..main
```

Kalau keluar daftar commit, berarti ada yang belum di-push — `git push` dulu.
Kalau kosong, server akan mendapat kode yang sama persis dengan mesin ini.

### 1.2 Bangun aset front-end

```bash
npm run build
```

Hasilnya masuk `public/build/`. Folder itu ada di `.gitignore`, jadi **tidak ikut git** dan
harus dikirim terpisah. Ini sumber kebingungan nomor satu bagi yang baru deploy Laravel:
aplikasinya jalan, tapi halamannya tampil polos tanpa gaya sama sekali — penyebabnya selalu
`public/build` yang tak ikut terkirim.

Node **tidak ada** di shared hosting, jadi build memang harus di sini, tiap kali `app.js`
atau `app.css` berubah.

### 1.3 Kenali alat yang Anda punya

Di mesin ini tersedia `ssh`, `scp`, `sftp` (OpenSSH 10.3). **`rsync` TIDAK ada** — Git Bash
tak membawanya. Karena itu panduan ini memakai `scp`, dan memindahkan kode lewat git di
sisi server, bukan menyalin ribuan berkas dari sini.

⚠️ **`ssh` memakai `-p` kecil, `scp` memakai `-P` besar.** Tertukar → pesan
"connection refused" yang menyesatkan, karena port 22 di server ini memang tertutup.

### 1.4 Pintasan agar tak mengetik alamat panjang

Buat/sunting `~/.ssh/config`:

```
Host alwafi
    HostName 153.92.13.252
    Port 65002
    User u607494788
```

Sesudah itu `ssh alwafi` sudah cukup, dan `scp -P 65002 … u607494788@153.92.13.252:…`
menjadi `scp … alwafi:…`.

---

## Bagian 2 — Masuk ke server untuk pertama kali

```bash
ssh -p 65002 u607494788@153.92.13.252
```

**Yang akan terjadi:**

1. Pertama kali saja, muncul
   `The authenticity of host … can't be established … Are you sure you want to continue connecting?`
   Ketik **`yes`**. Ini SSH meminta Anda mengakui sidik jari server; sesudah itu ia disimpan
   di `~/.ssh/known_hosts` dan tak ditanya lagi.
2. `u607494788@153.92.13.252's password:` → ketik sandi. **Kursor tidak bergerak dan tak ada
   bintang** saat mengetik. Itu normal, bukan keyboard rusak.
3. Berhasil → muncul prompt seperti `u607494788@srvXXXX:~$`.

Kalau ditolak terus, periksa: port **65002** (bukan 22), dan sandi yang dipakai adalah
**sandi SSH/hosting**, bukan sandi akun hPanel.

### 2.1 Orientasi

```bash
pwd; ls -la; ls domains/
```

`pwd` menjawab `/home/u607494788`. `ls domains/` memperlihatkan domain apa saja yang ada —
di situlah Anda melihat ERP dan ISE berdampingan. Pastikan Anda mengenali mana yang mana
**sebelum** menjalankan perintah apa pun yang menulis.

```bash
ls -la domains/erp.al-wafi.sch.id/
```

Biasanya sudah ada `public_html/` berisi halaman bawaan Hostinger. Itu yang akan kita ganti.

---

## Bagian 3 — Periksa lingkungan sebelum menyalin apa pun

Ini langkah yang paling sering dilewati, dan yang paling mahal kalau dilewati: Anda bisa
menghabiskan satu jam menyalin berkas, baru tahu ekstensi database-nya belum aktif.

```bash
php -v
```

Harus **8.5.x**. Aplikasi ini menuntut PHP ≥ 8.4.1 karena 16 paket Symfony di
`composer.lock`. (`composer.json` menulis `^8.3`, tapi yang menentukan adalah LOCK.)

```bash
php -m | grep -i pgsql
```

Harus keluar **`pdo_pgsql`** dan **`pgsql`**. Kalau kosong → lanjut ke Bagian 4, aktifkan
dulu di panel, baru kembali ke sini.

```bash
which composer git
```

- Ada `composer` → `vendor/` dibangun di server (lebih ringan).
- Ada `git` → kode diambil lewat `git clone` (jauh lebih enak untuk deploy berikutnya).
- Tak ada salah satunya → lihat Bagian 5b.

> **Jebakan yang mudah terlewat:** `php -v` di SSH menjawab **PHP CLI**. Yang melayani
> browser diatur terpisah di hPanel. Keduanya bisa berbeda versi — dan gejalanya jahat:
> `migrate` sukses di 8.5, lalu aplikasi mati karena disajikan PHP 8.2. Periksa keduanya.

> **Pelajaran lama yang sudah pernah termakan:** `php -m` melaporkan apa yang **sedang
> aktif**, bukan apa yang **tersedia**. Kalau `pdo_pgsql` tak muncul, jangan simpulkan
> Hostinger tak punya — buka tab Ekstensi PHP di panel.

---

## Bagian 4 — Setel PHP di hPanel

Lewat browser, bukan SSH.

1. hPanel → pilih website **erp.al-wafi.sch.id** (⚠️ bukan website ISE).
2. **Tingkat lanjut → Konfigurasi PHP**.
3. Tab **Versi PHP** → pilih **8.5** → simpan.
4. Tab **Ekstensi PHP** → centang **`pdo_pgsql`** dan **`pgsql`** → simpan.

Tanpa langkah 4, aplikasi mati dengan `could not find driver` — pesan yang tak menyebut
PostgreSQL sama sekali, jadi mudah disalahartikan.

Kembali ke SSH, ulangi `php -m | grep -i pgsql` untuk memastikan perubahannya sampai ke CLI.

---

## Bagian 5 — Taruh kode di server

### 5a. Lewat git (kalau `git` ada — cara yang disarankan)

```bash
cd /home/u607494788/domains/erp.al-wafi.sch.id
git clone <url-repo> app-laravel
```

Repo privat akan meminta kredensial. Yang aman: buat **Personal Access Token** di GitHub
(Settings → Developer settings → Tokens), pakai token itu sebagai pengganti sandi.

```bash
cd app-laravel && composer install --no-dev --optimize-autoloader
```

**Apa yang dilakukan perintah ini:** membaca `composer.lock` dan mengunduh `vendor/`.
`--no-dev` membuang paket yang hanya dipakai untuk test (PHPUnit dsb) — di produksi ia
cuma beban. `--optimize-autoloader` membuat peta kelas statis, jadi PHP tak perlu meraba
berkas tiap request.

Butuh 1–3 menit. Kalau berhenti karena kehabisan memori, jalankan
`php -d memory_limit=-1 $(which composer) install --no-dev --optimize-autoloader`.

Lalu kirim aset dari **mesin lokal** (buka terminal baru di lokal, jangan tutup SSH):

```bash
scp -P 65002 -r public/build u607494788@153.92.13.252:/home/u607494788/domains/erp.al-wafi.sch.id/app-laravel/public/
```

### 5b. Tanpa git atau tanpa composer di server

Bangun `vendor/` di lokal lebih dulu:

```bash
composer install --no-dev --optimize-autoloader
```

Lalu bungkus jadi **satu arsip**. Jangan `scp -r` seluruh proyek — itu berarti puluhan ribu
berkas dikirim satu per satu lewat SSH, dan makan berjam-jam.

```bash
tar --exclude=.git --exclude=node_modules --exclude=tests --exclude=.env --exclude='storage/logs/*' --exclude='storage/framework/views/*' --exclude='*.zip' -czf ../alwafi.tar.gz .
```

```bash
scp -P 65002 ../alwafi.tar.gz u607494788@153.92.13.252:/home/u607494788/domains/erp.al-wafi.sch.id/
```

Di server:

```bash
cd /home/u607494788/domains/erp.al-wafi.sch.id && mkdir -p app-laravel && tar -xzf alwafi.tar.gz -C app-laravel && rm alwafi.tar.gz
```

⚠️ **`.env` sengaja dikecualikan** — alasannya di Bagian 0.

Sesudah selesai, di lokal kembalikan paket dev supaya bisa menjalankan test lagi:

```bash
composer install
```

---

## Bagian 6 — Menyambungkan `public_html` ke aplikasi

Laravel menaruh satu-satunya berkas yang **boleh** diakses browser di `public/`. Sisanya —
`.env`, `app/`, `config/` — harus tak terjangkau. Kalau seluruh proyek ditaruh di
`public_html`, siapa pun bisa mengunduh `.env` berisi sandi database Anda.

### 6a. Jalan A — symlink (coba ini dulu)

```bash
cd /home/u607494788/domains/erp.al-wafi.sch.id
mv public_html public_html.lama
ln -s app-laravel/public public_html
```

`ln -s` membuat penunjuk, bukan salinan. Browser membuka `public_html`, yang sebenarnya
`app-laravel/public`. Keuntungannya: `index.php` tak perlu disunting sama sekali, dan tiap
`npm run build` tak menuntut penyalinan ulang.

Simpan `public_html.lama` sampai aplikasi terbukti hidup, baru hapus.

Kalau hasilnya **403 atau 404**, Hostinger menolak symlink sebagai document root
(dokumentasi mereka menyatakan document root paket Web/Cloud tak bisa dipindah). Kembalikan
dan pakai Jalan B:

```bash
rm public_html && mv public_html.lama public_html
```

### 6b. Jalan B — salin isi `public/`

```bash
cd /home/u607494788/domains/erp.al-wafi.sch.id
cp -r app-laravel/public/. public_html/
```

Lalu sunting `public_html/index.php` — **hanya salinan ini**, jangan `app-laravel/public/index.php`
(itu berkas repo; kalau ikut disunting, lokal dan test ikut rusak):

```bash
nano public_html/index.php
```

Tiga baris yang diubah:

| Baris asli | Jadi |
|---|---|
| `__DIR__.'/../storage/framework/maintenance.php'` | `__DIR__.'/../app-laravel/storage/framework/maintenance.php'` |
| `require __DIR__.'/../vendor/autoload.php';` | `require __DIR__.'/../app-laravel/vendor/autoload.php';` |
| `require_once __DIR__.'/../bootstrap/app.php';` | `require_once __DIR__.'/../app-laravel/bootstrap/app.php';` |

Sebabnya: dari `public_html`, `..` menunjuk ke folder domain, bukan ke akar Laravel.
Tiga baris itu yang perlu tahu di mana aplikasinya sekarang tinggal.

Konsekuensi melekat Jalan B: tiap `npm run build`, `public/build/` harus **disalin dua kali**
— ke `app-laravel/public/build/` dan ke `public_html/build/`.

⚠️ Pada kedua jalan: **`app-laravel/` tidak boleh berada di dalam `public_html/`.**

---

## Bagian 7 — `.env` produksi

```bash
cd /home/u607494788/domains/erp.al-wafi.sch.id/app-laravel
nano .env
```

(`nano`: sunting biasa, `Ctrl+O` lalu Enter untuk simpan, `Ctrl+X` untuk keluar.)

```
APP_NAME="Al Wafi ERP"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://erp.al-wafi.sch.id
APP_TIMEZONE=Asia/Jakarta

LOG_CHANNEL=stack
LOG_LEVEL=warning

DB_CONNECTION=pgsql
DB_HOST=ep-polished-star-az0669lt-pooler.c-3.ap-southeast-1.aws.neon.tech
DB_PORT=5432
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=
DB_SSLMODE=require

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local
LAMPIRAN_DISK=local
```

Isi `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` dari dashboard Neon (proyek **ERP**,
region Singapura). `APP_KEY` dibiarkan kosong — diisi perintah di Bagian 8.

**Kenapa tiap baris penting:**

- `APP_DEBUG=false` — dengan `true`, halaman galat Laravel menampilkan isi `.env`,
  **termasuk sandi database**, kepada siapa pun yang memicu error.
- `DB_SSLMODE=require` — Neon menolak koneksi tanpa TLS. Tanpa ini: `SSL required`.
- `SESSION_DRIVER=database` — sesi disimpan di tabel, bukan berkas. Jadi tak ada yang hilang.
- `LAMPIRAN_DISK=local` — lampiran dokumen keuangan disajikan lewat `LampiranController`
  (disk `local`, bukan disk `public`), jadi **`storage:link` tidak diperlukan**. Dan berbeda
  dengan Render, shared hosting punya disk permanen: lampiran tak lagi hilang tiap restart.

Amankan berkasnya:

```bash
chmod 600 .env
```

---

## Bagian 8 — Menyalakan aplikasi

```bash
cd /home/u607494788/domains/erp.al-wafi.sch.id/app-laravel
chmod -R 755 storage bootstrap/cache
```

Dua folder inilah satu-satunya yang ditulisi aplikasi: cache, log, sesi, lampiran. Kalau
`755` tak cukup (galat `failed to open stream: Permission denied`), naikkan ke `775`.
**Jangan ke `777`** — itu berarti proses lain di server bersama ini pun boleh menulis.

```bash
php artisan key:generate --force
```

Mengisi `APP_KEY`. Kunci ini mengenkripsi sesi & cookie. **Harus berbeda dari kunci lokal**,
dan sekali diganti sesudah aplikasi dipakai, semua sesi & data terenkripsi jadi tak terbaca.

```bash
php artisan migrate --force
```

Membangun ±100 tabel di Neon. `--force` diperlukan karena `APP_ENV=production`; Laravel
sengaja bertanya dulu di produksi, dan cron/skrip tak bisa menjawab.

Harus berakhir tanpa galat merah. Kalau berhenti di tengah, **jangan langsung ulangi** —
baca pesannya. Migrasi yang gagal separuh jalan perlu diperiksa, bukan ditabrak.

```bash
php artisan db:seed --force
```

Mengisi data awal: 4 level otorisasi, 4 level pengajuan, grup COA induk & akun kontrol,
unit U006, bagian contoh, rantai persetujuan BUDGET-STD & BAYAR-STD, `company_settings`,
1 tahun ajaran, jalur pendaftaran, dan **pengguna `admin` dengan sandi `admin123`**.

⚠️ **SEKALI SAJA, di deploy pertama.** Seeder ini idempotent (`updateOrCreate`), jadi kalau
dijalankan lagi ia **mengembalikan sandi admin ke `admin123`** — termasuk setelah Anda
menggantinya.

```bash
php artisan config:cache
php artisan view:cache
```

`config:cache` menggabung seluruh `config/*.php` jadi satu berkas; `view:cache` mengompilasi
Blade di muka. Keduanya mempercepat, tak mengubah perilaku.

⚠️ **`config:cache` MEMBEKUKAN isi `.env`.** Tiap kali `.env` diubah, ia **wajib** dijalankan
ulang — kalau tidak, perubahannya tak terbaca sama sekali dan Anda akan mengejar hantu.
Kalau bingung, `php artisan config:clear` mengembalikan pembacaan langsung.

⚠️ **JANGAN menjalankan `route:cache`.** `routes/web.php` memakai closure (rute `/` yang
mengalihkan ke dashboard), dan Laravel menolak men-serialisasi closure. Perintahnya gagal
dan bisa meninggalkan cache rute yang rusak.

---

## Bagian 9 — Cron terjadwal

`routes/console.php` sudah memuat dua perintah terjadwal: `reminder:tagihan` (harian, jamnya
mengikuti pengaturan) dan `santri:terapkan-jadwal` (00:30). Laravel tak menjalankannya
sendiri — ia butuh satu cron yang memanggil penjadwalnya tiap menit.

hPanel → **Tingkat lanjut → Cron Job** → jadwal **tiap menit**:

```bash
cd /home/u607494788/domains/erp.al-wafi.sch.id/app-laravel && php artisan schedule:run >> /dev/null 2>&1
```

Cron ini **tetap** — jangan dihapus. Satu cron sudah melayani semua perintah terjadwal,
sekarang dan nanti: `schedule:run` memeriksa mana yang jatuh tempo menit ini.

Inilah yang membuka jalan bagi **butir 8** (penerbitan tagihan terjadwal). Di Render ia
ditunda karena cron di sana tak bisa dipercaya — servernya tidur 15 menit.

---

## Bagian 10 — Verifikasi

1. Buka `https://erp.al-wafi.sch.id` → harus mengalihkan ke halaman masuk.
2. Masuk **admin / admin123**.
3. **SEGERA ganti sandi lewat `/profil`.** Sandi bawaan ini sudah pernah menggantung
   berminggu-minggu di produksi lama — jangan diulang.
4. hPanel → SSL: pastikan HTTPS aktif. PWA & service worker menuntutnya.
5. Buka laporan berat (Neraca Saldo) — mengukur apakah Neon Singapura cukup cepat dari sini.

Saat menguji, biarkan log terbuka di jendela SSH kedua:

```bash
tail -f /home/u607494788/domains/erp.al-wafi.sch.id/app-laravel/storage/logs/laravel.log
```

Dengan `APP_DEBUG=false`, galat tak tampil di layar — log inilah satu-satunya tempat
melihatnya. (`Ctrl+C` untuk berhenti.)

---

## Bagian 11 — Kalau rusak: gejala → sebab

| Yang terlihat | Hampir selalu berarti |
|---|---|
| Halaman putih kosong | Galat PHP fatal. Baca `storage/logs/laravel.log` |
| `500` seketika, log kosong | `storage/` atau `bootstrap/cache/` tak bisa ditulis → `chmod -R 775` |
| `could not find driver` | `pdo_pgsql` belum dicentang, atau dicentang di website yang salah |
| `SSL required` / koneksi DB ditolak | `DB_SSLMODE=require` belum ada di `.env` |
| Halaman tampil **tanpa gaya sama sekali** | `public/build` tak ikut terkirim (Bagian 1.2 / 5a) |
| Perubahan `.env` tak berpengaruh | `config:cache` masih memegang versi lama → jalankan ulang |
| `No application encryption key` | `key:generate` belum dijalankan |
| Isi direktori tampil, bukan aplikasi | document root salah — `index.php` tak di akar `public_html` |
| `403 Forbidden` sesudah symlink | Jalan A ditolak → pakai Jalan B (Bagian 6b) |
| Sandi admin kembali `admin123` sendiri | `db:seed` terjalan lagi — periksa daftar cron |

Perintah pertolongan pertama:

```bash
php artisan config:clear && php artisan cache:clear && php artisan view:clear
```

---

## Bagian 12 — Deploy kedua dan seterusnya

```bash
ssh alwafi
cd /home/u607494788/domains/erp.al-wafi.sch.id/app-laravel
php artisan down
git pull
composer install --no-dev --optimize-autoloader    # hanya bila composer.lock berubah
php artisan migrate --force                        # hanya bila ada migrasi baru
php artisan config:cache && php artisan view:cache
php artisan up
```

`php artisan down` memasang halaman "sedang dirawat" supaya tak ada yang menyimpan data di
tengah migrasi. `php artisan up` membukanya lagi. Kalau `up` terlupa, aplikasi tetap
tertutup — dan itu gejala yang membingungkan kalau tak tahu.

Kalau `app.js`/`app.css` berubah: dari lokal `npm run build`, lalu `scp -r public/build`
(Bagian 5a). Pada **Jalan B**, salin juga ke `public_html/build/`.

`scp -r` menimpa yang bernama sama tapi **tidak menghapus** yang sudah tak dipakai. Nama
berkas Vite ber-hash, jadi `public/build/assets` menumpuk sampah. Sesekali:
`rm -rf .../public/build` lebih dulu, baru kirim ulang.

**Tanpa `db:seed`.**

---

## Bagian 13 — Pekerjaan manual sesudah hidup

Database **mulai benar-benar kosong** — tak ada pemindahan data dari Neon lama. Yang harus
diketik ulang lewat layar:

- jenjang · tipe & jenis biaya · **grid tarif** (dulu 171 sel) · COA lengkap (dulu 109 akun)
- potongan gelombang · rekening bank · karyawan · vendor
- seluruh santri & wali (dulu 201/198), lewat modul Impor Data Awal. `max_execution_time`
  360 detik & `memory_limit` 1024M di sini jauh lebih longgar dari Render, jadi impor yang
  dulu 502 kemungkinan besar lancar sekarang
- **hak akses per pengguna** (dulu 245 baris), termasuk tiga modul baru yang belum dipegang
  siapa pun: `buka-periode`, `dana`, `kebijakan-khusus`. Ingat `buka-periode` **tidak boleh**
  `buat` + `ubah` pada orang yang sama — sistem menolak permohonan yang diputuskan pemohonnya
  sendiri, dan permohonannya akan mentok selamanya
- akun pengurang dana bebas (7 baris)
- **`klasifikasi_arus_kas`** untuk 40 akun neraca yang sengaja dikosongkan migrasi:
  Piutang Santri, Persediaan, Hutang Usaha, Titipan Dompet/Tabungan → `operasi`;
  Aset Tetap → `investasi`; Pinjaman Bank → `pendanaan`
- penandaan akun terikat (dana wakaf/donasi/beasiswa)

---

## Bagian 14 — Yang JANGAN dilakukan

- **Jangan `migrate:fresh` di server.** Sekali salah sebut koneksi, seluruh database
  terhapus — sudah pernah terjadi di lokal 7 Agustus 2026, dan Neon `archive_mode=off`
  berarti tak ada PITR. Tak ada jalan pulang.
- **Jangan `db:seed` dua kali.**
- **Jangan menimpa `storage/`** pada deploy kedua dan seterusnya — lampiran dokumen
  keuangan ada di sana.
- **Jangan mengirim `.env` lokal ke server.**
- **Jangan menyentuh folder atau setelan PHP website ISE.** Akun SSH ini menjangkaunya.
- **Jangan hapus proyek/akun Neon lama**, dan biarkan Render suspended — keduanya
  satu-satunya jalan meninjau ulang keputusan pindah ini.

---

## Lampiran — tanpa mengetik sandi berulang

Sandi SSH diketik Anda sendiri di terminal dan tak pernah lewat Claude. Kalau ingin
menghilangkan pengetikan berulang:

```bash
ssh-keygen -t ed25519 -C "alwafi-deploy" -f ~/.ssh/alwafi
```

Tempel isi `~/.ssh/alwafi.pub` ke hPanel → SSH Access → **Kunci SSH**, lalu tambahkan
`IdentityFile ~/.ssh/alwafi` pada blok `Host alwafi` di `~/.ssh/config`.
