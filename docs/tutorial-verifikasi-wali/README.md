# Panduan Verifikasi Data Keluarga (untuk wali)

- `Panduan-Verifikasi-Data-Keluarga.pdf` — berkas yang dibagikan ke wali (7 halaman A4).
- `tutorial-verifikasi-wali.html` — sumbernya. Gambar di `gambar/` = tangkapan layar data contoh (Ahmad Fauzi / MUHAMMAD RIZKI), bukan data asli.

Cetak ulang setelah menyunting HTML:

```bash
"/c/Program Files/Google/Chrome/Application/chrome.exe" --headless=new --disable-gpu --no-pdf-header-footer --print-to-pdf="D:\Works\AL WAFI Reborn\Project Aplikasi\Project Aplikasi Al Wafi Konversi PHP\docs\tutorial-verifikasi-wali\Panduan-Verifikasi-Data-Keluarga.pdf" "file:///D:/Works/AL WAFI Reborn/Project Aplikasi/Project Aplikasi Al Wafi Konversi PHP/docs/tutorial-verifikasi-wali/tutorial-verifikasi-wali.html"
```

Tiap `<section class="halaman">` = satu halaman; jaga tingginya ≤ 269 mm (A4 dikurangi margin 14 mm), kalau lebih ia meluber ke halaman baru.
Bila layar verifikasi berubah (label tombol, langkah), perbarui gambar & teksnya.
