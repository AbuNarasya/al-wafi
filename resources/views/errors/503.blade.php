{{--
  Halaman MODE PEMELIHARAAN (12 Nov 2026) — tampil selama rilis (deploy/ke-vps.sh: `php artisan down`), ±20 detik.
  SENGAJA berdiri sendiri (gaya inline, tanpa @vite): saat rilis, berkas CSS/JS aplikasi justru sedang dibangun ulang.
  `--refresh=15` membuat peramban memuat ulang sendiri; begitu rilis selesai, halaman asli kembali.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Sedang pemeliharaan sebentar</title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px;
               font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: #f1f5f9; color: #0f172a; }
        .kartu { max-width: 380px; width: 100%; background: #fff; border-radius: 24px; padding: 32px 24px; text-align: center;
                 box-shadow: 0 1px 3px rgba(15, 23, 42, .08); border: 1px solid #e2e8f0; }
        .putar { width: 44px; height: 44px; margin: 0 auto 18px; border-radius: 50%; border: 4px solid #dbe4f3; border-top-color: #164a9e;
                 animation: putar 1s linear infinite; }
        @keyframes putar { to { transform: rotate(360deg); } }
        h1 { font-size: 18px; margin: 0 0 8px; }
        p { font-size: 14px; line-height: 1.5; color: #475569; margin: 0 0 6px; }
        .kecil { font-size: 12px; color: #94a3b8; margin-top: 14px; }
        @media (prefers-color-scheme: dark) {
            body { background: #0b1220; color: #e2e8f0; }
            .kartu { background: #111a2e; border-color: #1e293b; }
            p { color: #94a3b8; }
        }
    </style>
</head>
<body>
    <div class="kartu">
        <div class="putar" aria-hidden="true"></div>
        <h1>Sedang pembaruan sebentar</h1>
        <p>Aplikasi sedang diperbarui dan akan kembali dalam beberapa detik.</p>
        <p>Halaman ini akan memuat ulang sendiri — tidak perlu melakukan apa pun.</p>
        <p class="kecil">The app is being updated and will be back in a few seconds.</p>
    </div>
</body>
</html>
