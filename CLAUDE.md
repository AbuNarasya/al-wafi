# Al Wafi — catatan kerja

Aplikasi keuangan & kesantrian pesantren. Laravel 12 + Blade + Alpine + Tailwind, PostgreSQL.
Bahasa kode & antarmuka: **Indonesia** (nama kelas/method/variabel ikut bahasa Indonesia — teruskan gaya itu).

**Sebelum coding, sampaikan dulu konfirmasi pemahaman** atas permintaannya — selalu, tanpa kecuali.

- **Perubahan kecil** (label, menyembunyikan isian, salah ketik, satu-dua berkas tanpa akibat
  ke data): cukup pemahamannya saja, lalu kerjakan.
- **Perubahan berdampak** (skema database, jurnal & akuntansi, hak akses, alur uang, hal yang
  menyentuh banyak berkas, atau yang sulit dibalik): tambahkan pro & kontra berikut kelebihan
  & kekurangannya, dan tunggu persetujuan sebelum menulis kode.

Riwayat panjang & keputusan bisnis ada di memori sesi (`handoff-resume.md`). Berkas ini hanya
rujukan cepat: perintah, konvensi, dan jebakan yang sudah pernah termakan.

## Perintah

```bash
C:/php/php.exe artisan test --filter=NamaTest   # ~13 s — PAKAI INI saat iterasi
C:/php/php.exe artisan test --parallel          # ~1 mnt 56 s (309 test) — sekali sebelum melapor
C:/php/php.exe -l <berkas.php>                  # wajib setelah menyunting PHP
C:/php/php.exe artisan view:cache               # wajib setelah menyunting Blade
npm run build                                   # wajib setelah menyunting app.js / app.css
serve.bat                                       # jalankan server dev (port 8123)
```

Angka terukur di mesin ini (4 core), supaya tak salah pilih:

| | biasa | `--parallel` |
|---|---:|---:|
| seluruh suite (309 test) | 2 mnt 42 s | **1 mnt 56 s** |
| satu berkas (14 test) | **12,6 s** | 16,2 s |

`--parallel` (paratest) hanya menguntungkan untuk suite penuh — untuk satu berkas ia malah lebih
lambat karena menyiapkan 4 database pekerja (`al_wafi_php_test_test_1` … `_4`). Lari paralel PERTAMA
setelah migrasi baru lebih lambat (±2 mnt 15 s) karena keempat database dimigrasi ulang.
**Jalankan `composer` & `artisan` dari folder proyek** — dijalankan dari home, composer akan membuat
proyek baru di sana alih-alih memasang ke sini.

- Login dev: **admin / admin123**, buka di **localhost:8123** (BUKAN 127.0.0.1 — sesi terikat host).
- DB kerja `al_wafi_php`, DB test `al_wafi_php_test` (lihat `phpunit.xml`), user `postgres`.
- `migrate:fresh` = 5 s untuk 96 migrasi, jadi menyetel ulang DB test itu murah.
- **`ExampleTest::test_the_application_returns_a_successful_response` SELALU gagal** (`/` → 302
  karena butuh login). Bawaan Laravel, tak relevan — jangan dikejar, jangan dihitung sebagai regresi.

## Aturan menyunting (mahal dipelajari)

- **JANGAN pakai `sed`/`perl`/skrip regex untuk menyunting kode.** Pernah mengosongkan
  `cash-out/index.blade.php` jadi 0 byte, dan pernah membuat sebuah trait test memanggil dirinya
  sendiri karena sed ikut mengenai berkasnya sendiri. Pakai Edit tool. Kalau terpaksa massal:
  `git diff` sesudahnya, tanpa kecuali.
- **JANGAN menulis tag `<x-komponen>` di dalam komentar `//` atau `/* */` pada Blade** — Blade tetap
  mengompilasinya → "Undefined variable $component" di semua halaman pemakai. Komentar `{{-- --}}` aman.
- **JANGAN menempelkan dua direktif Blade tanpa pemisah**: `@endif@if ($x)` membuat `@if` kedua TIDAK
  terkompilasi (regex Blade memakai `\B@`, dan posisi sesudah huruf `f` bukan non-word boundary),
  sehingga `@endif` pasangannya jadi yatim → "syntax error, unexpected token endif". Beri spasi,
  komentar `{{-- --}}`, atau baris baru di antaranya. `}}@if(...)` aman (didahului non-huruf).
- **Nama kelas Tailwind harus muncul di Blade**, bukan dirakit di controller/service. Tailwind
  memindai `resources/views` + `storage/framework/views`, TIDAK memindai `app/**`.
- `artisan tinker --execute` dengan kutip bersarang sering gagal escaping. Untuk pemeriksaan lebih
  dari satu baris: tulis skrip PHP ke scratchpad lewat heredoc, jalankan dengan `C:/php/php.exe`.
- Berkas pratinjau sementara di `public/_uji*.html` **wajib dihapus** setelah dipakai.

## PostgreSQL — jebakan nyata

- `UPDATE t SET … FROM a JOIN b ON b.x = t.y` **ILEGAL**: tabel target tak bisa dirujuk dari klausa
  JOIN. Pakai koma di `FROM`, syaratnya di `WHERE`.
- **Dua NULL dianggap BERBEDA** oleh unique index. Untuk kolom nullable pakai
  `CREATE UNIQUE INDEX … (COALESCE(kol,'-'), …)`.
- Pencocokan yang harus menganggap NULL = NULL: `WHERE kol IS NOT DISTINCT FROM ?`.
- Enum Laravel = varchar + CHECK. Menambah nilai berarti **DROP lalu ADD CONSTRAINT** ulang
  (lihat migrasi `tambah_perilaku_perlengkapan`).
- Kunci asing di sini `ON UPDATE NO ACTION`, jadi **mengganti nilai primary key ditolak**. Caranya:
  sisipkan baris baru → alihkan semua perujuk → hapus baris lama (lihat `kode_jenjang_berformat_j001`).
- Urutan dalam satu migrasi penting: sisipkan baris baru **sebelum** kolom NOT NULL-nya dibuang,
  tapi susun rencananya **selagi** kolom lama masih ada.

## Primary key yang bukan `id`

Kebanyakan master ber-PK **string** bernama `kode`/`kode_*`, bukan `id` auto-increment. Yang paling
sering menjebak:

| Model | PK |
|---|---|
| `TahunAjaran` | **`id`** (integer!) — `find('2026/2027')` melempar galat tipe; pakai `where('kode', …)` |
| `User` | `id_pengguna` |
| `Jenjang`, `JalurPendaftaran`, `TipeBiaya`, `JenisBiaya`, `SumberInformasi`, `Karyawan` | `kode` (string) |
| `CoaDetail`, `BankAccount` | `kode_coa` (string) |
| `BusinessUnit` | `kode_unit` · `Bagian` → `kode_bagian` · `Level` → `kode_level` |

`jenis_biaya.tipe` ber-kunci asing ke `tipe_biaya.kode` — baris tipenya harus ada dulu sebelum
jenis biaya dibuat (fixture test pun).

## Arsitektur yang perlu diketahui sebelum menyentuh biaya

- **`jenis_biaya` = identitas akuntansi saja**: nama, perilaku (lewat `tipe`), jenjang, akun COA,
  unit bisnis. Satu baris per (jenjang, perilaku). **Tanpa nominal, tanpa tahun ajaran, tanpa jalur.**
- **`tarif_biaya` = besarannya**, per (T.A, jenjang, jalur, perilaku). Tiga keadaan sel yang WAJIB
  tetap berbeda: nominal terisi = berlaku · `bebas` = sengaja tak dipungut, tagihan tak terbit ·
  tak ada barisnya = belum diisi, penagihan BERHENTI dengan pesan. Nol adalah angka sah.
- Pencarian tarif: T.A & jenjang **cocok persis**; satu-satunya cadangan adalah jalur → baris Umum.
  Hasilnya selalu membawa `asal` yang ditampilkan di layar.
- `daftar_ulang` & `spp` tak mengenal jalur (`TarifService::TANPA_JALUR`).
- **`tagihan_santri` menyimpan `perilaku`, `kode_jenjang`, `tahun_ajaran`** sebagai snapshot, dijaga
  indeks unik parsial `tagihan_santri_sekali_per_ta`. `tahun_ajaran` di sini = **tahun TAGIHAN**,
  sedangkan `santri.tahun_ajaran` = tahun MASUK/angkatan yang tak pernah maju — jangan disamakan.
- Perilaku yang dikenal: `registrasi`, `uang_pangkal`, `perlengkapan`, `daftar_ulang`, `spp`, `lain`.
  Program **selalu menyaring lewat perilaku**, bukan kode tipe (kode tipe dibuat sendiri tiap pesantren).
- Menambah perilaku berarti menyentuh juga: `PembayaranSantriService::TIPE_LINGKUP`,
  `PembayaranSantriController::tipe()`, `TugasSaya`, `JenisBiayaService::PERILAKU_TUNGGAL`, label index
  jenis biaya. Terlewat satu → tagihannya jadi tak bisa dibayar dari mana pun.

## Test

- Fixture jenis biaya + tarif lewat trait **`Tests\Concerns\MembuatTarif`** (`buatBiaya()`,
  `pasangTarif()`). Jangan membangun `jenis_biaya` langsung di test baru — perubahan skema jadi
  menyentuh puluhan berkas.
- Kalau memeriksa isi dropdown, **periksa markup `<option>`-nya**, bukan sekadar "namanya muncul di
  halaman": blob JSON Alpine memuat nama itu juga walau dropdown-nya kosong.
- Kewajiban isian ditegakkan di **CONTROLLER**, bukan service — menaruh `tingkat` wajib di
  `SantriService::create` pernah memecahkan 96 test sekaligus.
- Seeder **wajib ada testnya** (`DatabaseSeederTest`): 259 test pernah lulus sementara seeder rusak,
  dan itu baru ketahuan saat deploy mati di produksi.

## Jangan diulang

- **Docker TIDAK terpasang** di mesin ini (Windows 10 build 18362, terlalu tua untuk Docker Desktop).
  Semua `Dockerfile`/devcontainer di repo **belum pernah dibangun lokal** — jangan menulis skrip
  lingkungan lalu menganggapnya jalan. Kalau menyentuh berkas deploy: sebutkan bahwa itu belum
  teruji, dan minta lognya.
- Codespaces & Hugging Face **sudah dicoba dan gugur**. Jangan diarahkan ke sana lagi.
- Produksi = Render paket gratis + Neon. Tidur 15 menit, cold start ±1 menit, **tanpa disk permanen**
  (berkas unggahan hilang tiap restart). Jangan push tanpa diminta.
