@echo off
rem ============================================================================
rem  Jalankan server development Al Wafi.
rem  Klik dua kali berkas ini, lalu biarkan jendelanya terbuka selama dipakai.
rem  Tutup jendela (atau Ctrl+C) untuk mematikan server.
rem
rem  CATATAN: buka lewat http://localhost:8123 -- BUKAN 127.0.0.1:8123.
rem  Sesi login disimpan per-host, jadi keduanya tidak saling mengenali.
rem ============================================================================
setlocal
chcp 65001 >nul
cd /d "%~dp0"

set "PORT=8123"
set "URL=http://localhost:%PORT%"

rem ---- Cari PHP: C:\php dulu (lokasi di mesin ini), lalu PATH -----------------
set "PHP="
if exist "C:\php\php.exe" set "PHP=C:\php\php.exe"
if not defined PHP (
    for /f "delims=" %%i in ('where php 2^>nul') do if not defined PHP set "PHP=%%i"
)
if not defined PHP (
    echo.
    echo  [X] PHP tidak ditemukan.
    echo      Dicari di C:\php\php.exe dan di PATH, keduanya kosong.
    echo.
    goto :selesai
)

rem ---- Syarat lain yang sering jadi biang error ------------------------------
if not exist "vendor\autoload.php" (
    echo.
    echo  [X] Folder vendor belum ada. Jalankan dulu:  composer install
    echo.
    goto :selesai
)
if not exist ".env" (
    echo.
    echo  [X] Berkas .env belum ada. Salin dari .env.example lalu isi koneksi database.
    echo.
    goto :selesai
)

rem ---- Sudah jalan? Buka browsernya saja, jangan bentrok di port yang sama ----
netstat -ano -p tcp | findstr ":%PORT% " | findstr /i "LISTENING" >nul
if not errorlevel 1 (
    echo.
    echo  Server sudah berjalan di %URL% -- membuka browser saja.
    echo.
    start %URL%
    goto :akhir
)

echo.
echo  Al Wafi -- server development
echo  PHP    : %PHP%
echo  Alamat : %URL%
echo.
echo  Pastikan PostgreSQL sudah menyala (database al_wafi_php).
echo  Tekan Ctrl+C untuk menghentikan server.
echo.

rem ---- Buka browser setelah server sempat menyala ----------------------------
start /b "" cmd /c "timeout /t 3 /nobreak >nul & start %URL%"

"%PHP%" artisan serve --port=%PORT%

:selesai
echo.
pause
:akhir
endlocal
