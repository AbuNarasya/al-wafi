# Menjalankan aplikasi di GitHub Codespaces (gratis, tanpa kartu)

Cara mencoba aplikasi ini "di cloud" tanpa memasang apa pun dan tanpa kartu
kredit. Akun GitHub gratis mendapat jatah bulanan yang lebih dari cukup untuk
uji coba.

## Langkah

1. Buka halaman repo: `https://github.com/<akun>/al-wafi`
2. Klik tombol hijau **Code** → tab **Codespaces** → **Create codespace on main**
3. Tunggu. Kali pertama sekitar 5–8 menit karena GitHub menyiapkan mesin,
   memasang PHP, PostgreSQL, dependensi, lalu membangun tampilan.
   Ikuti prosesnya di panel terminal — tahapannya bernomor 1/6 sampai 6/6.
4. Setelah muncul tulisan **Selesai**, buka tab **PORTS** di panel bawah, cari
   baris port **8000**, klik ikon globe (Open in Browser).
5. Login: **admin** / **admin123**

## Ingin menunjukkannya ke orang lain?

Alamat port bawaannya privat — hanya bisa dibuka oleh akun GitHub Anda sendiri.
Untuk membukanya: di tab **PORTS**, klik kanan baris port 8000 → **Port
Visibility** → **Public**. Alamatnya lalu bisa dibuka siapa pun yang memegang
tautannya. Kembalikan ke **Private** setelah selesai.

## Menghemat jatah

- Codespace berhenti sendiri setelah 30 menit menganggur.
- Hentikan manual lewat halaman [github.com/codespaces](https://github.com/codespaces)
  → titik tiga → **Stop codespace**.
- **Hapus** codespace bila sudah tak dipakai; penyimpanan tetap terhitung
  walau sedang berhenti.

## Kalau aplikasi tak terbuka

Server dijalankan otomatis di latar belakang. Bila port 8000 tak muncul, jalankan
sendiri di terminal codespace:

```bash
php artisan serve --host=0.0.0.0 --port=8000
```

Bila penyiapan gagal di tengah, ulangi tahapannya dengan:

```bash
bash .devcontainer/setup.sh
```

## Catatan

- Data di codespace terpisah dari komputer Anda — mulai dari data awal hasil
  seeder (level, COA, unit, bagian, rantai approval, pengguna admin).
- Berkas yang diunggah di codespace ikut terhapus saat codespace dihapus.
- Penjadwal reminder tak berjalan otomatis; tombol "Kirim Reminder Sekarang"
  tetap berfungsi.
