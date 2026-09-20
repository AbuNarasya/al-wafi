# Deploy ke Hostinger — panduan untuk pemula

Panduan memindahkan aplikasi **Al Wafi ERP** dari komputer Anda ke server Hostinger,
ditulis untuk orang yang belum pernah memakai terminal.

Menggantikan `DEPLOY-RENDER.md`, yang kini usang. Berkas Docker (`Dockerfile`, `docker/`,
`render.yaml`) **tidak dipakai di sini** — shared hosting tak menjalankan container.

> **SUDAH DIJALANKAN & BERHASIL — 20 September 2026.** Aplikasi hidup di
> <https://erp.al-wafi.sch.id>. Seluruh langkah di bawah sudah dilalui sungguhan, dan tiga
> temuan lapangan yang tak terduga sudah dimasukkan ke tempatnya masing-masing:
> **Jalan A (pintasan) DITERIMA** Hostinger · **`proc_open` dimatikan** sehingga
> `composer install` selalu berakhir dengan satu galat yang harus diabaikan lalu
> ditambal manual · **Neon menolak sambungan** sampai endpoint ID dititipkan di depan sandi.

# Bagian 0 — Bekal sebelum mulai

## 0.1 Istilah yang akan terus muncul

| Kata | Artinya dalam panduan ini |
|---|---|
| **Terminal** | Jendela hitam tempat Anda mengetik perintah, bukan mengeklik tombol |
| **Prompt** | Tulisan di ujung kiri yang berakhir `$`. Kalau `$` ada, terminal siap menerima perintah |
| **Perintah** | Satu baris yang Anda ketik lalu tekan **Enter** |
| **Server** | Komputer Hostinger di internet, yang akan menjalankan aplikasi ini 24 jam |
| **SSH** | Cara masuk ke server lewat terminal, seolah Anda duduk di depannya |
| **hPanel** | Halaman pengaturan Hostinger yang dibuka lewat browser |
| **Lokal** | Komputer Anda sendiri |

## 0.2 Tiga hukum terminal yang harus dipegang

**Hukum 1 — Diam berarti berhasil.**
Kebanyakan perintah **tidak menampilkan apa-apa** kalau sukses; ia langsung kembali ke `$`.
Pesan justru muncul kalau ada yang salah. Jangan menyangka perintah gagal hanya karena
layar tak berubah.

**Hukum 2 — Kalau `$` belum muncul, tunggu.**
Selama perintah masih berjalan, `$` menghilang. Jangan mengetik apa pun, jangan menutup
jendela. Beberapa perintah butuh 1–3 menit.

**Hukum 3 — Salin-tempel, jangan mengetik ulang.**
Satu huruf salah pada alamat server atau nama folder sudah cukup membuat perintah gagal
dengan pesan yang membingungkan. Di terminal, **klik kanan** biasanya berarti tempel;
`Ctrl+V` sering tidak berfungsi.

## 0.3 Peta mental: apa sebenarnya "deploy" itu

Deploy memindahkan **tiga hal yang terpisah**. Kebanyakan kekacauan deploy berasal dari
menyangka ketiganya satu paket.

| Yang dipindah | Isinya apa | Caranya |
|---|---|---|
| **Kode** | `app/`, `routes/`, tampilan, `vendor/`, hasil build | diambil server dari GitHub, + dikirim dari lokal untuk hasil build |
| **Struktur database** | tabel-tabel kosong | dibuat perintah `migrate` **di server** |
| **Rahasia** (`.env`) | alamat & sandi database | **diketik langsung di server**, sekali, tak pernah disalin |

Yang ketiga itu kuncinya. `.env` di komputer Anda menunjuk ke database latihan di mesin
sendiri. Kalau berkas itu ikut terbawa ke server, perintah `migrate` akan menghantam
database yang salah.

## 0.4 Gambaran folder di server

```
/home/u607494788/                 ← "rumah" Anda di server
└── domains/
    ├── erp.al-wafi.sch.id/      ← WILAYAH ERP, hanya di sini Anda bekerja
    │   ├── public_html/         ← yang dilihat browser
    │   └── app-laravel/         ← aplikasi (akan kita buat)
    └── <domain ISE>/            ← MILIK ORANG LAIN, JANGAN DISENTUH
```

⚠️ **Akun server ini dipakai bersama.** Pemilik akun menjalankan aplikasi lain bernama
**ISE** di sana, dan dari terminal yang sama berkasnya ikut terjangkau — ikut bisa
terhapus. Karena itu setiap perintah di panduan ini menyebut alamat folder selengkapnya.
**Jangan pernah** menjalankan perintah penghapus dari folder rumah.

## 0.5 Yang perlu disiapkan sebelum mulai

- **Sandi SSH** dari pemilik akun (berbeda dari sandi login hPanel).
- **Akses hPanel** — untuk mengatur versi PHP.
- **Data database Neon**: nama database, nama pengguna, sandi (dari dashboard Neon,
  proyek **ERP**, region Singapura).
- **Token GitHub** — karena repo ini privat. Cara membuatnya ada di Bagian 5.

Siapkan keempatnya di satu tempat sebelum mulai. Berhenti di tengah jalan untuk mencari
sandi adalah cara paling umum kehilangan jejak sudah sampai mana.

# Bagian 1 — Menyiapkan komputer sendiri

## 1.1 Membuka terminal

**Cara termudah — lewat aplikasi Claude:**

1. Di bagian atas jendela Claude ada tab bertuliskan **Terminal**, di samping percakapan.
2. Klik tab itu.
3. Muncul area hitam dengan tulisan berakhir `$`.
4. Terminal ini **sudah berada di folder proyek** — tak perlu pindah folder.

**Cara lain — lewat File Explorer:**

1. Buka **File Explorer** (ikon map kuning di taskbar).
2. Masuk ke `D:\Works\AL WAFI Reborn\Project Aplikasi\Project Aplikasi Al Wafi Konversi PHP`
3. **Klik kanan** di area kosong di dalam folder itu.
4. Pilih **"Open Git Bash here"**. (Di Windows 11, klik **"Tampilkan opsi lainnya"** dulu.)
5. Jendela hitam terbuka, sudah berada di folder yang benar.

## 1.2 Memastikan posisi folder

**Ketik:**

```bash
pwd
```

**Yang harus muncul:**

```
/d/Works/AL WAFI Reborn/Project Aplikasi/Project Aplikasi Al Wafi Konversi PHP
```

**Kalau yang muncul lain** (misalnya `/c/Users/Abu Narasya`), Anda belum di folder proyek.
Ulangi cara membuka di atas. Jangan lanjut sebelum baris ini benar.

## 1.3 Memastikan kode sudah sampai ke GitHub

Server akan mengambil kode dari GitHub, bukan dari folder di komputer Anda. Apa pun yang
belum terkirim ke sana, bagi server memang tidak ada.

**Ketik:**

```bash
git status --short --branch
```

**Yang harus muncul:**

```
## main...origin/main
```

**Kalau ada tambahan `[ahead 3]`** di belakangnya, berarti ada 3 perubahan yang masih
tersimpan di komputer ini saja. Kirim dulu:

```bash
git push
```

Lalu ulangi pemeriksaannya sampai `[ahead …]` hilang.

## 1.4 Membangun tampilan (`npm run build`)

### Kenapa langkah ini tak boleh dilewati

Tampilan aplikasi (warna, jarak, bentuk tombol) tidak dikirim apa adanya. Ia **dirakit**
lebih dulu oleh sebuah alat, dan alat itu hanya merakit yang ia temukan saat dijalankan.

Halaman-halaman baru — Dana Terikat, Kebijakan Khusus, Neraca Saldo, Jejak Audit — dibuat
setelah perakitan terakhir. Kalau langkah ini dilewati, keempatnya akan **tampil rusak
tata letaknya** di server, sementara semua halaman lama terlihat baik-baik saja.

Gejalanya membingungkan justru karena aplikasinya jelas jalan. Tak ada pesan galat apa pun
yang menunjuk ke sini.

### Menjalankannya

**Ketik:**

```bash
npm run build
```

**Yang akan Anda lihat:** baris-baris berjalan sendiri selama **15–60 detik**. Ada tulisan
`vite`, lalu daftar nama berkas dengan angka ukuran di sebelahnya.

**Tanda berhasil:** muncul kata **`built in`** diikuti waktu (misal `built in 12.34s`),
lalu `$` kembali muncul.

Selama angka-angka masih berjalan: **jangan tutup jendelanya, jangan tekan apa pun.**

### Memeriksa hasilnya

**Ketik:**

```bash
grep -c "\.w-60" public/build/assets/app-*.css
```

**Yang harus muncul:** angka **lebih besar dari 0**.

Kalau jawabannya `0`, perakitan tidak jadi — ulangi `npm run build` dan perhatikan apakah
ada pesan merah yang terlewat.

### Kalau muncul galat

| Tulisan yang muncul | Artinya | Yang dilakukan |
|---|---|---|
| `npm: command not found` | Node.js tak terbaca | Tutup terminal, buka lagi. Masih juga? Node perlu dipasang ulang |
| `ENOENT ... package.json` | Anda tidak di folder proyek | Ulangi langkah 1.2 |
| `EPERM` / `permission denied` | Ada berkas yang sedang dikunci program lain | Tutup editor & browser yang membuka folder itu, ulangi |

## 1.5 Membuat pintasan ke server

### Apa yang sedang dibuat

Alamat server panjang: `ssh -p 65002 u607494788@153.92.13.252`. Kita buat **catatan kecil**
supaya cukup mengetik `ssh alwafi`.

Catatan itu berupa berkas bernama `config` di dalam folder tersembunyi `.ssh`, yang berada
di folder rumah Anda (`C:\Users\Abu Narasya`). Folder itu belum ada, jadi dibuat dulu.

Titik di depan nama (`.ssh`) membuat Windows menyembunyikannya. Itu normal.

### Langkah 1 — buat foldernya

**Ketik:**

```bash
mkdir -p ~/.ssh && chmod 700 ~/.ssh
```

Tanda `~` berarti "folder rumah saya". `chmod 700` berarti **hanya Anda** yang boleh
membukanya.

**Yang muncul: tidak ada apa-apa**, langsung kembali ke `$`. Ingat Hukum 1.

### Langkah 2 — isi catatannya

**Ketik sebagai satu baris utuh** (salin-tempel saja):

```bash
printf 'Host alwafi\n    HostName 153.92.13.252\n    Port 65002\n    User u607494788\n' > ~/.ssh/config
```

Lagi-lagi tak ada yang muncul. Itu benar.

### Langkah 3 — kunci berkasnya

**Ketik:**

```bash
chmod 600 ~/.ssh/config
```

⚠️ Ini **bukan formalitas**. Kalau berkas itu bisa dibaca pengguna lain di komputer, program
SSH **menolak jalan sama sekali** dan mengeluarkan peringatan panjang soal "unprotected
file". Ia memang sengaja rewel di sini.

### Langkah 4 — periksa isinya

**Ketik:**

```bash
cat ~/.ssh/config
```

**Yang harus muncul persis:**

```
Host alwafi
    HostName 153.92.13.252
    Port 65002
    User u607494788
```

**Kalau ada baris hilang, atau `\n` tercetak sebagai teks biasa**, hapus lalu ulangi
Langkah 2:

```bash
rm ~/.ssh/config
```

## 1.6 Catatan alat

Di komputer ini tersedia `ssh`, `scp`, dan `sftp`. **`rsync` tidak ada** — Git Bash tak
membawanya. Jadi kalau Anda menemukan tutorial deploy lain yang menyuruh
`rsync -avz --delete`, perintah itu tak akan jalan di sini. Panduan ini memakai `scp`.

⚠️ **`ssh` memakai `-p` kecil, `scp` memakai `-P` besar.** Tertukar → pesan
"connection refused" yang menyesatkan, karena port 22 di server ini memang tertutup.

# Bagian 2 — Masuk ke server

## 2.1 Menyambung

**Ketik:**

```bash
ssh alwafi
```

**Yang akan terjadi, berurutan:**

**1. Pertanyaan yang hanya muncul sekali seumur hidup:**

```
The authenticity of host '[153.92.13.252]:65002' can't be established.
ED25519 key fingerprint is SHA256:xxxxxxxxxxxx.
Are you sure you want to continue connecting (yes/no/[fingerprint])?
```

Ketik **`yes`** lengkap (bukan `y`), lalu Enter.

Artinya: "saya belum pernah bertemu server ini, Anda yakin?" Sesudah dijawab, SSH
mengingatnya dan tak bertanya lagi.

**2. Permintaan sandi:**

```
u607494788@153.92.13.252's password:
```

Ketik sandi SSH, lalu Enter.

⚠️ **Kursor tidak akan bergerak. Tidak ada bintang. Layar tampak membeku.** Itu normal dan
disengaja — SSH sengaja tak menampilkan apa pun saat sandi diketik. Ketik sampai selesai,
tekan Enter. **Jangan mengetik ulang** karena mengira tak masuk.

**3. Kalau berhasil**, muncul tulisan sambutan lalu prompt berubah menjadi seperti:

```
u607494788@srv1234:~$
```

**Perhatikan namanya berubah.** Itu tandanya Anda sekarang mengetik **di komputer server**,
bukan di komputer sendiri lagi. Semua perintah berikutnya dijalankan di sana.

**Untuk keluar** dan kembali ke komputer sendiri, ketik `exit`.

## 2.2 Kalau gagal masuk

| Tulisan | Artinya | Yang dilakukan |
|---|---|---|
| `Permission denied, please try again` | Sandi salah | Pastikan itu sandi **SSH/hosting**, bukan sandi login hPanel |
| `Connection refused` | Port salah | `cat ~/.ssh/config` — `Port` harus **65002** |
| `Connection timed out` | Tak sampai ke server | Periksa internet; bisa juga SSH dimatikan lagi dari panel |
| `Bad owner or permissions` | Langkah 1.5.3 terlewat | Jalankan `chmod 600 ~/.ssh/config` |

## 2.3 Melihat-lihat dulu sebelum menyentuh apa pun

**Ketik:**

```bash
pwd
```

**Yang muncul:** `/home/u607494788` — folder rumah Anda di server.

**Ketik:**

```bash
ls domains/
```

**Yang muncul:** daftar nama domain. Di sinilah Anda melihat ERP dan ISE berdampingan.
**Kenali mana yang mana sekarang**, sebelum menjalankan perintah apa pun yang menulis.

**Ketik:**

```bash
ls -la domains/erp.al-wafi.sch.id/
```

Biasanya sudah ada `public_html/` berisi halaman bawaan Hostinger. Itu yang nanti diganti.

# Bagian 3 — Memeriksa server sebelum menyalin apa pun

Ini langkah yang paling sering dilewati, dan paling mahal kalau dilewati: Anda bisa
menghabiskan satu jam menyalin berkas, baru tahu komponen database-nya belum dinyalakan.

Semua perintah di bagian ini **dijalankan di server** — pastikan prompt Anda masih
`u607494788@srv…:~$`.

## 3.1 Versi PHP

**Ketik:**

```bash
php -v
```

**Yang harus muncul:** baris pertama memuat **`PHP 8.5.`** sekian.

**Kalau angkanya 8.2, 8.3, atau 8.4** — lanjut ke Bagian 4 dulu untuk menggantinya, baru
kembali ke sini. Aplikasi ini menuntut minimal 8.4.1.

## 3.2 Komponen PostgreSQL

**Ketik:**

```bash
php -m | grep -i pgsql
```

**Yang harus muncul:**

```
pdo_pgsql
pgsql
```

**Kalau kosong** (langsung kembali ke `$` tanpa tulisan apa pun) — komponennya belum
dinyalakan. Lanjut ke Bagian 4, lalu kembali ke sini.

⚠️ Perintah ini melaporkan apa yang **sedang aktif**, bukan apa yang **tersedia**. Jangan
menyimpulkan Hostinger tak punya PostgreSQL — ia punya, tinggal dicentang di panel.

## 3.3 Alat bantu

**Ketik:**

```bash
which composer git
```

**Yang muncul:** dua baris alamat, misalnya `/usr/bin/composer` dan `/usr/bin/git`.

- **Keduanya ada** → pakai Bagian 5a. Ini jalan yang jauh lebih enak.
- **Salah satu tak ada** (barisnya hilang) → pakai Bagian 5b.

## 3.4 Jebakan yang mudah terlewat

`php -v` di terminal menjawab PHP untuk **terminal**. PHP yang melayani **browser** diatur
terpisah di hPanel, dan keduanya bisa berbeda versi.

Akibatnya jahat: perintah `migrate` sukses di 8.5, lalu aplikasi mati karena browser
dilayani 8.2. Periksa keduanya — terminal di sini, browser di Bagian 4.

# Bagian 4 — Mengatur PHP lewat hPanel

Bagian ini dikerjakan di **browser**, bukan terminal. Biarkan jendela SSH terbuka.

1. Buka **hpanel.hostinger.com**, masuk dengan akun Anda.
2. Pilih menu **Website**, lalu klik **erp.al-wafi.sch.id**.
   ⚠️ **Pastikan yang dipilih ERP, bukan website ISE.** Pengaturan ini berlaku per-website,
   dan mengubahnya pada website ISE bisa merusak aplikasi orang lain.
3. Di daftar menu kiri, cari **Tingkat lanjut** → klik **Konfigurasi PHP**.
4. Buka tab **Versi PHP**:
   - Pilih **8.5**
   - Klik **Simpan** / **Perbarui**
5. Buka tab **Ekstensi PHP**:
   - Cari daftar yang panjang, temukan **`pdo_pgsql`** — beri centang
   - Temukan juga **`pgsql`** — beri centang
   - Klik **Simpan**
6. Tunggu ±1 menit; perubahannya tidak langsung berlaku.

Tanpa langkah 5, aplikasi mati dengan pesan `could not find driver` — pesan yang tak
menyebut PostgreSQL sama sekali, sehingga mudah disalahartikan.

**Kembali ke jendela SSH**, ulangi pemeriksaan:

```bash
php -v
```

```bash
php -m | grep -i pgsql
```

Keduanya harus benar sekarang. Kalau masih belum, periksa lagi apakah centangnya kena
website yang benar.

# Bagian 5 — Menaruh kode di server

Pilih **5a** atau **5b** sesuai hasil pemeriksaan 3.3. Jangan kerjakan keduanya.

## 5a — Lewat git (kalau `composer` dan `git` ada)

### Siapkan token GitHub dulu

Repo ini privat, jadi server akan meminta izin. Sandi GitHub biasa **tidak lagi diterima**;
yang dipakai adalah token.

1. Di browser, buka **github.com**, masuk ke akun Anda.
2. Klik foto profil (pojok kanan atas) → **Settings**.
3. Gulir ke bawah, menu kiri paling bawah: **Developer settings**.
4. **Personal access tokens** → **Tokens (classic)** → **Generate new token (classic)**.
5. Isi:
   - **Note**: `deploy hostinger`
   - **Expiration**: 90 hari
   - **Centang kotak `repo`** (yang paling atas — itu saja cukup)
6. Klik **Generate token**.
7. **Salin tokennya sekarang juga** dan simpan di tempat aman.
   ⚠️ Token itu **hanya ditampilkan sekali**. Kalau halamannya tertutup, tak ada cara
   melihatnya lagi — Anda harus membuat yang baru.

### Ambil kodenya

Di jendela SSH, **ketik:**

```bash
cd /home/u607494788/domains/erp.al-wafi.sch.id
```

`cd` berarti "pindah folder". Tak ada yang muncul — itu benar.

**Ketik:**

```bash
git clone https://github.com/AbuNarasya/al-wafi.git app-laravel
```

**Yang akan terjadi:**

1. Muncul `Username for 'https://github.com':` → ketik **nama pengguna GitHub** Anda, Enter.
2. Muncul `Password for '…':` → **tempel TOKEN** tadi (bukan sandi GitHub), Enter.
   Sama seperti sandi SSH, layar tak menampilkan apa-apa saat ditempel.
3. Baris-baris `Receiving objects…` berjalan, lalu selesai.

**Ketik:**

```bash
cd app-laravel && composer install --no-dev --optimize-autoloader
```

**Apa yang dikerjakannya:** mengunduh ±100 pustaka pendukung yang tidak ikut disimpan di
GitHub. `--no-dev` membuang yang hanya dipakai untuk pengujian — di server ia cuma beban.

**Yang akan Anda lihat:** daftar nama paket berjalan selama **1–3 menit**, diakhiri
`Generating optimized autoload files`.

### ⚠️ Dua galat yang SELALU muncul di sini — dan memang harus diabaikan

Hostinger mematikan `proc_open`, fungsi PHP untuk menjalankan program lain. Composer
membutuhkannya, aplikasi ini **tidak** (sudah diperiksa: `app/`, `routes/`, dan `database/`
tak memakainya sama sekali). Akibatnya dua pesan ini muncul tiap kali:

**Peringatan kuning di awal:**

```
proc_open is disabled so 'unzip' and '7z' commands cannot be used,
zip files are being unpacked using the PHP zip extension.
... any UNIX permissions (e.g. executable) defined in the archives will be lost.
```

Tak berdampak. Berkas yang kehilangan tanda "boleh dijalankan" hanya ada di `vendor/bin/`
— alat pengujian yang tak pernah dipakai di server, dan `--no-dev` sudah membuang
sebagian besarnya.

**Galat merah di akhir:**

```
In Process.php line 147:
The Process class relies on proc_open, which is not available on your PHP installation.
```

Ini muncul **sesudah** `Generating optimized autoload files`, jadi pemasangannya sendiri
sudah tuntas. Yang gagal hanya langkah pendataan paket yang hendak dipanggil Composer.
**Kerjakan sendiri langkah itu:**

```bash
php artisan package:discover
```

Harus muncul beberapa nama paket bertanda `DONE`. Setelah itu tak ada sisa masalah.

**Ingat: setiap `composer install` di server akan mengulang galat ini, dan obatnya selalu
`php artisan package:discover`.**

**Kalau berhenti dengan kata `memory`**, ulangi dengan:

```bash
php -d memory_limit=-1 $(which composer) install --no-dev --optimize-autoloader
```

### Kirim hasil perakitan tampilan

Hasil `npm run build` tadi tidak ikut GitHub — harus dikirim dari komputer Anda.

**Buka terminal KEDUA di komputer sendiri** (tab Terminal baru, atau Git Bash baru —
jangan tutup jendela SSH).

Pastikan posisinya di folder proyek (`pwd`), lalu **ketik satu baris utuh:**

```bash
scp -P 65002 -r public/build u607494788@153.92.13.252:/home/u607494788/domains/erp.al-wafi.sch.id/app-laravel/public/
```

Diminta sandi SSH lagi — ketik seperti biasa.

**Yang muncul:** daftar nama berkas dengan angka persen berjalan sampai 100%.

## 5b — Tanpa git atau tanpa composer di server

### Siapkan di komputer sendiri

**Ketik** (di terminal komputer Anda, bukan SSH):

```bash
composer install --no-dev --optimize-autoloader
```

Tunggu sampai selesai (1–3 menit).

Lalu bungkus seluruh proyek jadi **satu berkas** — jangan mengirim berkas satu per satu,
itu berarti puluhan ribu kali kirim dan makan berjam-jam.

**Ketik satu baris utuh:**

```bash
tar --exclude=.git --exclude=node_modules --exclude=tests --exclude=.env --exclude='storage/logs/*' --exclude='storage/framework/views/*' --exclude='*.zip' -czf ../alwafi.tar.gz .
```

Butuh 1–2 menit, tak ada tulisan apa pun selama berjalan.

⚠️ `.env` **sengaja tidak diikutkan**. Kalau ia terbawa, pengaturan database latihan di
komputer Anda akan menimpa pengaturan server — dan perintah `migrate` nanti menghantam
database yang salah.

### Kirim

**Ketik:**

```bash
scp -P 65002 ../alwafi.tar.gz u607494788@153.92.13.252:/home/u607494788/domains/erp.al-wafi.sch.id/
```

Masukkan sandi SSH. Tunggu sampai 100%.

### Buka di server

**Pindah ke jendela SSH**, ketik satu baris utuh:

```bash
cd /home/u607494788/domains/erp.al-wafi.sch.id && mkdir -p app-laravel && tar -xzf alwafi.tar.gz -C app-laravel && rm alwafi.tar.gz
```

## 5c — Rapikan komputer sendiri

Perintah `composer install --no-dev` tadi membuang alat pengujian dari komputer Anda juga.
Kembalikan supaya bisa menjalankan test lagi — **di terminal komputer sendiri:**

```bash
composer install
```

# Bagian 6 — Menyambungkan alamat web ke aplikasi

## 6.1 Kenapa ini perlu

Aplikasi Laravel menaruh satu-satunya folder yang **boleh** dibuka browser di `public/`.
Sisanya — termasuk `.env` yang berisi sandi database — harus tak terjangkau.

Kalau seluruh aplikasi ditaruh di `public_html`, siapa pun di internet bisa mengetik
alamat `.env` di browser dan **mengunduh sandi database Anda**.

Ada dua cara menyambungkannya. Coba Jalan A dulu; kalau ditolak, pakai Jalan B.

## 6.2 Jalan A — pintasan (INI YANG BERHASIL, 20 Sep 2026)

> Dokumentasi Hostinger menyatakan document root paket Web/Cloud tak bisa dipindah, dan
> karena itu panduan ini semula menyiapkan Jalan B sebagai keharusan. **Ternyata keliru:
> pintasan diterima.** Jalan B di bawah disimpan hanya sebagai cadangan.

Di jendela SSH, **ketik satu per satu:**

```bash
cd /home/u607494788/domains/erp.al-wafi.sch.id
```

```bash
mv public_html public_html.lama
```

```bash
ln -s app-laravel/public public_html
```

`ln -s` membuat **pintasan**, bukan salinan — seperti shortcut di desktop Windows. Browser
membuka `public_html`, yang sebenarnya menunjuk ke `app-laravel/public`.

Keuntungannya besar: tak ada berkas yang perlu disunting, dan setiap kali tampilan dirakit
ulang, tak perlu menyalin apa pun.

**Simpan `public_html.lama`** sampai aplikasi terbukti hidup, baru dihapus.

**Sekarang buka `https://erp.al-wafi.sch.id` di browser.**

- Muncul halaman apa pun dari aplikasi (termasuk halaman galat Laravel) → **Jalan A
  berhasil**, lanjut ke Bagian 7.
- Muncul **403 Forbidden** atau **404** → Hostinger menolak pintasan. Kembalikan dan pakai
  Jalan B:

```bash
rm public_html && mv public_html.lama public_html
```

## 6.3 Jalan B — salin (kalau Jalan A ditolak)

**Ketik:**

```bash
cd /home/u607494788/domains/erp.al-wafi.sch.id
```

```bash
cp -r app-laravel/public/. public_html/
```

Sekarang satu berkas harus disunting. **Ketik:**

```bash
nano public_html/index.php
```

Terbuka penyunting teks sederhana di dalam terminal. **Cara memakainya:**

- Gerakkan kursor dengan **tombol panah** (mouse tidak berfungsi)
- Ketik seperti biasa untuk mengubah
- **`Ctrl+O`** lalu **Enter** → simpan
- **`Ctrl+X`** → keluar
- **`Ctrl+K`** → hapus satu baris penuh

Cari tiga baris ini dan ubah:

| Cari baris yang memuat | Ubah bagian `/../` menjadi |
|---|---|
| `storage/framework/maintenance.php` | `/../app-laravel/storage/framework/maintenance.php` |
| `vendor/autoload.php` | `/../app-laravel/vendor/autoload.php` |
| `bootstrap/app.php` | `/../app-laravel/bootstrap/app.php` |

Jadi misalnya baris

```
require __DIR__.'/../vendor/autoload.php';
```

menjadi

```
require __DIR__.'/../app-laravel/vendor/autoload.php';
```

**Sebabnya:** dari dalam `public_html`, tanda `..` menunjuk ke folder domain, bukan ke
folder aplikasi. Tiga baris itulah yang perlu diberi tahu di mana aplikasinya sekarang
tinggal.

Simpan (`Ctrl+O`, Enter) lalu keluar (`Ctrl+X`).

⚠️ **Sunting hanya `public_html/index.php`.** Jangan menyunting
`app-laravel/public/index.php` — itu berkas asli dari GitHub, dan mengubahnya membuat
aplikasi di komputer Anda ikut rusak.

⚠️ **Konsekuensi Jalan B:** setiap kali tampilan dirakit ulang di komputer, hasilnya harus
disalin **dua kali** — ke `app-laravel/public/build/` **dan** ke `public_html/build/`.

# Bagian 7 — Mengisi pengaturan rahasia (`.env`)

**Ketik:**

```bash
cd /home/u607494788/domains/erp.al-wafi.sch.id/app-laravel
```

```bash
nano .env
```

Terbuka penyunting kosong. **Tempel** isi berikut (klik kanan di terminal biasanya berarti
tempel):

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

Lalu **isi tiga baris yang kosong** dengan data dari dashboard Neon (proyek **ERP**):

- `DB_DATABASE=` → nama database
- `DB_USERNAME=` → nama pengguna
- `DB_PASSWORD=` → sandi database

**Tanpa spasi di kiri-kanan tanda `=`.** `DB_DATABASE = neondb` (pakai spasi) tidak terbaca.

### ⚠️ WAJIB: endpoint Neon dititipkan di depan sandi

Tanpa ini, sambungan **pasti ditolak**:

```
SQLSTATE[08006] ERROR: Endpoint ID is not specified. Either please upgrade the
postgres client library (libpq) for SNI support or pass the endpoint ID ...
```

Neon menaruh ribuan database di balik satu alamat, dan mengenali tujuan lewat teknik
bernama **SNI** — nama tujuan dikirim saat sambungan dibuka. Pustaka PostgreSQL di server
Hostinger terlalu tua untuk mengirimnya, jadi Neon tak tahu harus menyambung ke mana.

Laravel tak punya tempat untuk menitipkan keterangan itu: penyambung PostgreSQL-nya
(`PostgresConnector::getDsn`) hanya mengenal `host`, `dbname`, `port`, `client_encoding`,
`application_name`, dan empat pengaturan SSL — **tidak ada `options`**. Karena itu dipakai
jalur resmi Neon: endpoint ID ditempel **di depan sandi**, dipisah `$`.

Endpoint ID = bagian depan alamat, **tanpa** `-pooler`:

```
ep-polished-star-az0669lt-pooler.c-3.ap-southeast-1.aws.neon.tech
└──────── endpoint ID ────────┘
```

Maka baris sandinya ditulis begini:

```
DB_PASSWORD='endpoint=ep-polished-star-az0669lt$npg_SANDIASLI'
```

⚠️ **Kutip tunggal `'` di awal & akhir WAJIB.** Tanpa itu `$` bisa dibaca sebagai awal nama
variabel, dan sebagian sandi hilang diam-diam — gejalanya menyamar jadi
`password authentication failed`, sehingga orang mencari di tempat yang salah.

`APP_KEY=` dibiarkan kosong — akan diisi otomatis di Bagian 8.

Simpan: **`Ctrl+O`**, Enter. Keluar: **`Ctrl+X`**.

## 7.1 Kunci berkasnya

**Ketik:**

```bash
chmod 600 .env
```

## 7.2 Kenapa baris-baris itu penting

| Baris | Kalau salah |
|---|---|
| `APP_DEBUG=false` | Dengan `true`, halaman galat menampilkan **isi seluruh `.env` termasuk sandi database** kepada siapa pun yang memicu error |
| `DB_SSLMODE=require` | Neon menolak sambungan tanpa pengaman → aplikasi tak bisa membaca database sama sekali |
| `APP_ENV=production` | Aplikasi menganggap dirinya masih dalam pengembangan, dan menolak sebagian perintah |
| `LAMPIRAN_DISK=local` | Berkas lampiran (nota, bukti transfer) tak ketemu tempat simpan |

Kabar baik soal lampiran: di sini berkas unggahan disimpan di disk permanen, jadi **tidak
hilang** saat server dinyalakan ulang — berbeda dengan server lama.

# Bagian 8 — Menyalakan aplikasi

Semua perintah di bagian ini dijalankan **di server**, di dalam folder `app-laravel`.
Pastikan posisi Anda benar:

```bash
cd /home/u607494788/domains/erp.al-wafi.sch.id/app-laravel && pwd
```

Harus menjawab `/home/u607494788/domains/erp.al-wafi.sch.id/app-laravel`.

## 8.1 Izin menulis

```bash
chmod -R 755 storage bootstrap/cache
```

Dua folder inilah satu-satunya yang ditulisi aplikasi: catatan kejadian, berkas sementara,
dan lampiran dokumen.

**Kalau nanti muncul galat `Permission denied`**, naikkan ke 775:

```bash
chmod -R 775 storage bootstrap/cache
```

⚠️ **Jangan sekali-kali 777** — itu berarti aplikasi lain di server bersama ini pun boleh
menulisi berkas Anda.

## 8.2 Membuat kunci pengaman

```bash
php artisan key:generate --force
```

**Yang muncul:** `INFO  Application key set successfully.`

Kunci ini mengacak sesi login dan cookie. Ia **harus berbeda** dari kunci di komputer Anda.
Dan sekali diganti setelah aplikasi dipakai, semua yang sedang login akan terlempar keluar.

## 8.3 Membuat tabel database

```bash
php artisan migrate --force
```

**Yang akan Anda lihat:** ±100 baris, masing-masing nama tabel diikuti `DONE` berwarna
hijau. Butuh **1–3 menit** karena database berada di Singapura.

**Tanda berhasil:** semua baris `DONE`, lalu kembali ke `$`.

⚠️ **Kalau berhenti di tengah dengan tulisan merah — JANGAN langsung mengulangi.** Baca
pesannya, catat, dan tanyakan. Migrasi yang gagal separuh jalan perlu diperiksa, bukan
ditabrak ulang.

## 8.4 Mengisi data awal

```bash
php artisan db:seed --force
```

Mengisi: level otorisasi, kerangka akun, unit, rantai persetujuan, pengaturan lembaga,
satu tahun ajaran, jalur pendaftaran, dan **pengguna `admin` dengan sandi `admin123`**.

⚠️ **PERINTAH INI HANYA SEKALI, SELAMANYA.** Kalau dijalankan lagi nanti, ia
**mengembalikan sandi admin ke `admin123`** — termasuk sesudah Anda menggantinya dengan
sandi yang kuat. Jangan pernah memasukkannya ke tugas terjadwal.

## 8.5 Mempercepat

```bash
php artisan config:cache
```

```bash
php artisan view:cache
```

Keduanya hanya mempercepat, tak mengubah perilaku.

⚠️ **`config:cache` membekukan isi `.env`.** Setelah perintah ini, mengubah `.env` **tidak
berpengaruh apa pun** sampai `config:cache` dijalankan ulang. Ini penyebab paling umum
orang mengejar hantu berjam-jam: merasa sudah membetulkan sandi database, tapi aplikasi
tetap memakai yang lama.

Kalau bingung, kembalikan ke pembacaan langsung dengan `php artisan config:clear`.

⚠️ **JANGAN menjalankan `php artisan route:cache`.** Aplikasi ini memakai bentuk penulisan
yang tak bisa dibekukan; perintahnya gagal dan bisa meninggalkan berkas rusak yang
membuat seluruh aplikasi mati.

# Bagian 9 — Tugas terjadwal

Aplikasi punya dua pekerjaan otomatis: mengirim pengingat tagihan (harian) dan menerapkan
jadwal kenaikan tingkat santri (00:30). Keduanya tak jalan sendiri — perlu satu pengatur
waktu yang memanggilnya.

Di **browser**:

1. hPanel → website **erp.al-wafi.sch.id**
2. **Tingkat lanjut** → **Cron Job**
3. Klik **Buat Cron Job baru**
4. **Jadwal**: pilih **Setiap menit** (`* * * * *`)
5. **Perintah**: tempel satu baris ini:

```
cd /home/u607494788/domains/erp.al-wafi.sch.id/app-laravel && php artisan schedule:run >> /dev/null 2>&1
```

6. Simpan.

Cron ini **permanen — jangan dihapus**. Satu cron ini melayani semua pekerjaan terjadwal,
sekarang maupun yang ditambahkan nanti: tiap menit ia memeriksa mana yang jatuh tempo.

# Bagian 10 — Memeriksa hasilnya

1. Buka **`https://erp.al-wafi.sch.id`** di browser → harus muncul halaman masuk.
2. Masuk dengan **admin** / **admin123**.
3. ⚠️ **SEGERA ganti sandi** lewat menu **Profil**. Sandi bawaan ini sudah pernah
   menggantung berminggu-minggu di server lama — jangan diulang.
4. Periksa alamatnya berawalan **`https://`** (ada gembok). Kalau belum, hPanel → **SSL**.
5. Buka satu laporan berat (**Neraca Saldo**) untuk merasakan kecepatannya.

## 10.1 Melihat catatan galat

Karena `APP_DEBUG=false`, galat **tidak ditampilkan di layar** — demi keamanan. Untuk
melihatnya, buka jendela SSH dan **ketik:**

```bash
tail -f /home/u607494788/domains/erp.al-wafi.sch.id/app-laravel/storage/logs/laravel.log
```

Layar akan menampilkan catatan galat **saat itu juga** ketika Anda mengeklik di browser.
Biarkan terbuka sambil menguji. Tekan **`Ctrl+C`** untuk berhenti.

### Cara membaca yang jauh lebih berguna

`tail` biasa hampir selalu mengecewakan: satu galat Laravel menghasilkan **50–60 baris
jejak langkah** (daftar jalur yang dilalui galat), sedangkan **pesan sebenarnya ada di
baris paling ATAS** entri itu — dan justru itulah yang tergulung hilang.

Pakai ini untuk mengambil pesannya saja:

```bash
grep -a "production.ERROR" storage/logs/laravel.log | tail -n 3
```

Hasilnya tiga galat terakhir, masing-masing satu baris:

```
[2026-09-20 09:03:20] production.ERROR: Vite manifest not found at: ... {"exception":...
```

Bagian sesudah `production.ERROR:` sampai sebelum `{"exception"` — itulah sebabnya.
Sisanya jarang diperlukan.

# Bagian 11 — Kalau ada yang rusak

| Yang terlihat | Hampir selalu berarti | Yang dilakukan |
|---|---|---|
| Halaman putih kosong | Galat PHP berat | Baca `laravel.log` (10.1) |
| `500` seketika, log kosong | Folder tak bisa ditulis | `chmod -R 775 storage bootstrap/cache` |
| `could not find driver` | `pdo_pgsql` belum dicentang | Ulangi Bagian 4 |
| `SSL required` | `DB_SSLMODE=require` belum ada | Betulkan `.env`, lalu `config:cache` lagi |
| `500` di `/login`, log berkata **`Vite manifest not found`** | Hasil perakitan tampilan belum terkirim | Ulangi 1.4 lalu kirim ulang (5a) |
| `Endpoint ID is not specified` | Endpoint Neon belum dititipkan di sandi | Lihat Bagian 7 |
| `The Process class relies on proc_open` | Normal di sini — hanya Composer | `php artisan package:discover` |
| Perubahan `.env` tak berpengaruh | Pengaturan masih beku | `php artisan config:cache` |
| `No application encryption key` | Kunci belum dibuat | `php artisan key:generate --force` |
| Daftar berkas tampil, bukan aplikasi | Alamat web salah arah | Ulangi Bagian 6 |
| `403 Forbidden` setelah Jalan A | Pintasan ditolak | Pakai Jalan B (6.3) |
| Sandi admin kembali `admin123` sendiri | `db:seed` terjalan lagi | Periksa daftar Cron Job, hapus yang memuat `db:seed` |

**Perintah pertolongan pertama** (aman dijalankan kapan saja):

```bash
php artisan config:clear && php artisan cache:clear && php artisan view:clear
```

# Bagian 12 — Memperbarui aplikasi nanti

Kalau ada perbaikan baru yang ingin dinaikkan ke server:

**Di komputer sendiri**, kalau tampilan berubah:

```bash
npm run build
```

```bash
scp -P 65002 -r public/build u607494788@153.92.13.252:/home/u607494788/domains/erp.al-wafi.sch.id/app-laravel/public/
```

**Di server:**

```bash
cd /home/u607494788/domains/erp.al-wafi.sch.id/app-laravel
```

```bash
php artisan down
```

```bash
git pull
```

```bash
composer install --no-dev --optimize-autoloader
```

```bash
php artisan migrate --force
```

```bash
php artisan config:cache && php artisan view:cache
```

```bash
php artisan up
```

`php artisan down` memasang halaman "sedang dirawat" supaya tak ada yang menyimpan data di
tengah pembaruan. `php artisan up` membukanya kembali.

⚠️ **Kalau `up` terlupa, aplikasi tetap tertutup** untuk semua orang — dan itu gejala yang
membingungkan kalau tak tahu sebabnya.

⚠️ **Tanpa `db:seed`.** Selamanya.

Pada **Jalan B**, salin juga hasil perakitan ke `public_html/build/`.

# Bagian 13 — Pekerjaan setelah aplikasi hidup

Database **mulai benar-benar kosong** — tak ada data yang dipindahkan dari server lama.
Yang harus diisi ulang lewat layar aplikasi:

- jenjang · tipe & jenis biaya · **grid tarif** (dulu 171 sel) · kerangka akun (dulu 109 akun)
- potongan gelombang · rekening bank · karyawan · vendor
- seluruh santri & wali (dulu 201/198), lewat menu **Impor Data Awal**
- **hak akses tiap pengguna** (dulu 245 baris), termasuk tiga modul baru yang belum
  dipegang siapa pun: **Buka Periode**, **Dana Terikat**, **Kebijakan Khusus Santri**

  ⚠️ Pada **Buka Periode**, hak `buat` dan `ubah` **tidak boleh** diberikan ke orang yang
  sama — sistem menolak permohonan yang diputuskan oleh pemohonnya sendiri, dan
  permohonannya akan mentok selamanya.

- akun pengurang dana bebas (7 baris)
- **klasifikasi arus kas** untuk 40 akun: Piutang Santri, Persediaan, Hutang Usaha,
  Titipan Dompet → **operasi**; Aset Tetap → **investasi**; Pinjaman Bank → **pendanaan**
- penandaan akun dana terikat (wakaf, donasi, beasiswa)

# Bagian 14 — Yang tidak boleh dilakukan

- ❌ **Jangan `php artisan migrate:fresh` di server.** Perintah itu **menghapus seluruh
  database** lalu membuatnya ulang kosong. Sudah pernah terjadi di komputer lokal pada
  7 Agustus 2026 dan datanya hilang permanen. Di Neon tak ada pemulihan otomatis —
  tak ada jalan pulang.
- ❌ **Jangan `db:seed` dua kali.**
- ❌ **Jangan menimpa folder `storage/`** saat memperbarui — lampiran dokumen keuangan
  ada di sana.
- ❌ **Jangan mengirim `.env` dari komputer ke server.**
- ❌ **Jangan menyentuh folder atau pengaturan PHP website ISE.**
- ❌ **Jangan menghapus proyek Neon lama maupun layanan Render** — keduanya satu-satunya
  jalan kembali kalau pindahan ini bermasalah.

# Lampiran A — Supaya tak perlu mengetik sandi berulang

Setelah beberapa kali deploy, mengetik sandi SSH terus-menerus melelahkan. Sekali setup:

**Di komputer sendiri, ketik:**

```bash
ssh-keygen -t ed25519 -C "alwafi-deploy" -f ~/.ssh/alwafi
```

Ditanya `Enter passphrase` → cukup tekan **Enter** dua kali (dikosongkan).

**Ketik:**

```bash
cat ~/.ssh/alwafi.pub
```

Salin seluruh barisnya. Lalu di hPanel → **Tingkat lanjut** → **SSH Access** → **Kunci SSH**
→ tempel → simpan.

**Terakhir, ketik:**

```bash
printf '    IdentityFile ~/.ssh/alwafi\n' >> ~/.ssh/config
```

Sesudah ini `ssh alwafi` langsung masuk tanpa bertanya sandi.

# Lampiran B — Daftar perintah nano

| Tombol | Fungsi |
|---|---|
| Panah | Gerakkan kursor (mouse tak berfungsi) |
| `Ctrl+O` lalu Enter | Simpan |
| `Ctrl+X` | Keluar |
| `Ctrl+K` | Hapus satu baris penuh |
| `Ctrl+W` | Cari kata |
| `Ctrl+C` | Lihat posisi baris saat ini |
