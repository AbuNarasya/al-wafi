@echo off
rem ============================================================================
rem  Jalankan WAFI MART LOKAL (instans terpisah dari ERP Al Wafi lokal).
rem  Klik dua kali berkas ini, lalu biarkan jendelanya terbuka selama dipakai.
rem
rem  Memakai .env.wafimart (APP_ENV=wafimart): INSTANS=wafimart, database
rem  wafimart_lokal (salinan Wafi Mart dev), cookie sesi sendiri -- sehingga bisa
rem  dibuka BERSAMAAN dengan ERP lokal (serve.bat, port 8123).
rem
rem  Buka lewat http://localhost:8124 -- BUKAN 127.0.0.1.
rem  Perintah artisan untuk instans ini:  php artisan <perintah> --env=wafimart
rem ============================================================================
setlocal
chcp 65001 >nul
cd /d "%~dp0"

set "PORT=8124"
set "URL=http://localhost:%PORT%"

set "PHP="
if exist "C:\php\php.exe" set "PHP=C:\php\php.exe"
if not defined PHP (
    for /f "delims=" %%i in ('where php 2^>nul') do if not defined PHP set "PHP=%%i"
)
if not defined PHP (
    echo.
    echo  [X] PHP tidak ditemukan.
    echo.
    goto :selesai
)
if not exist ".env.wafimart" (
    echo.
    echo  [X] Berkas .env.wafimart belum ada.
    echo.
    goto :selesai
)

netstat -ano -p tcp | findstr ":%PORT% " | findstr /i "LISTENING" >nul
if not errorlevel 1 (
    echo.
    echo  Wafi Mart lokal sudah berjalan di %URL% -- membuka browser saja.
    echo.
    start %URL%
    goto :akhir
)

echo.
echo  Wafi Mart -- server development lokal
echo  PHP    : %PHP%
echo  Alamat : %URL%
echo.
echo  Pastikan PostgreSQL sudah menyala (database wafimart_lokal).
echo  Tekan Ctrl+C untuk menghentikan server.
echo.

start /b "" cmd /c "timeout /t 3 /nobreak >nul & start %URL%"

rem APP_ENV=wafimart membuat Laravel memuat .env.wafimart, juga di proses server anaknya.
set "APP_ENV=wafimart"
"%PHP%" artisan serve --port=%PORT%

:selesai
echo.
pause
:akhir
endlocal
