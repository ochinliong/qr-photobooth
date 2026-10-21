@echo off
title YUHU Photobooth - LumaBooth Windows 2026 Cloud Sync Pro
cd /d "%~dp0"

echo =====================================================================
echo   YUHU PHOTOBOOTH - LUMABOOTH WINDOWS (OKTOBER 2026) CLOUD SYNC PRO
echo =====================================================================
echo Memulai aplikasi sinkronisasi LumaBooth ke Portal files.yuhu.co.id...
echo.

python lumabooth_sync_pro.py
if %ERRORLEVEL% NEQ 0 (
    echo.
    echo [!] Gagal menjalankan Python. Mencoba menggunakan launcher py...
    py lumabooth_sync_pro.py
)

pause
