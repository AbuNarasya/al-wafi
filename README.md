---
title: Al Wafi ERP — Akuntansi & Kesantrian
emoji: 🏫
colorFrom: indigo
colorTo: green
sdk: docker
app_port: 7860
pinned: false
---

# Al Wafi ERP — Sistem Akuntansi & Kesantrian

Aplikasi internal pesantren: akuntansi (COA, jurnal, kas, hutang, anggaran,
pengajuan pembayaran) dan kesantrian/PPSB (calon santri, registrasi, uang
pangkal & angsuran, SPP, dompet santri, dashboard PPSB).

Laravel 13 · PHP 8.4 · PostgreSQL · Tailwind 4 · Alpine.js

## Menjalankan di komputer sendiri

```bash
composer install && npm install && npm run build
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Login awal: `admin` / `admin123`.

## Cara deploy

| Berkas | Isi |
| ------ | --- |
| [HUGGINGFACE.md](HUGGINGFACE.md) | Hugging Face Spaces + Neon — gratis, tanpa kartu |
| [DEPLOY-RENDER.md](DEPLOY-RENDER.md) | Render — gratis, tetapi akun perlu verifikasi kartu |
| [CODESPACES.md](CODESPACES.md) | GitHub Codespaces — untuk mencoba sendiri, bukan agar diakses orang lain |

Blok metadata di paling atas berkas ini dibaca Hugging Face Spaces (jenis SDK
dan port aplikasi). Jangan dihapus selama Space masih dipakai; ia tidak
mengganggu tampilan halaman repo di GitHub.
