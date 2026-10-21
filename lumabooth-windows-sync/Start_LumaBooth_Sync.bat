@echo off
cd /d "%~dp0"

:: ============================================================================
:: YUHU PHOTOBOOTH - LUMABOOTH WINDOWS 2026 SYNC LAUNCHER (ECO / LOW PRIORITY)
:: ============================================================================
:: - Dijalankan dengan flag /BELOWNORMAL agar Windows 100% mengutamakan
::   LumaBooth (Kamera, Live View, Render Video) dan Print Spooler (Printer).
:: - Menggunakan pythonw.exe (Windowless Background Process) sehingga jendela
::   hitam CMD langsung tertutup otomatis dan tidak memakan resource di taskbar.
:: ============================================================================

where pythonw >nul 2>nul
if %ERRORLEVEL% EQU 0 (
    start "YUHU LumaBooth Sync" /BELOWNORMAL pythonw lumabooth_sync_pro.py
    exit /b 0
)

where pyw >nul 2>nul
if %ERRORLEVEL% EQU 0 (
    start "YUHU LumaBooth Sync" /BELOWNORMAL pyw lumabooth_sync_pro.py
    exit /b 0
)

start "YUHU LumaBooth Sync" /BELOWNORMAL python lumabooth_sync_pro.py
exit /b 0
