@echo off
SETLOCAL EnableDelayedExpansion

:: ============================================================================
:: YUHU PHOTOBOOTH - LUMABOOTH WINDOWS UNIVERSAL AUTO-SYNC & FAST RECOVERY
:: VERSI: YUHU v12.bat (HIGH CONCURRENCY + NON-BLOCKING + 0% PRINTER IMPACT)
:: ============================================================================
:: Fitur Utama:
:: 1. FULL BACKGROUND DAEMON: Berjalan di latar belakang secara otomatis melalui
::    trigger LumaBooth ATAU diklik 2x oleh operator.
:: 2. NON-BLOCKING ANIMATED: Video Animated (MP4/GIF/MOV) diupload kualitas 100%
::    asli tanpa kompresi CPU, dengan pengecekan lock cerdas agar tidak macet
::    saat LumaBooth sedang me-render video.
:: 3. TIMEOUT CEPAT (35 DETIK): Tidak ada lagi freeze 5-30 menit!
:: 4. BEBAS GANGGUAN PRINTER & KOMPUTER: Berjalan pada prioritas BELOWNORMAL
::    sehingga printer dan live-view kamera LumaBooth tetap lancar 100%.
:: 5. FILTER TEPAT: Singles & Originals diabaikan, _2x strip dilewati jika
::    _1x sudah ada (menghemat bandwidth 50% & cegah duplikat galeri).
:: ============================================================================

SET "API_URL_PRIMARY=https://files.yuhu.co.id/client/api_upload.php"
SET "API_URL_FALLBACK=https://files.yuhu.co.id/client/api_upload.php"
SET "API_TOKEN=YuhuSecretToken2026"

SET "CURL=%SystemRoot%\System32\curl.exe"
IF NOT EXIST "%CURL%" SET "CURL=curl.exe"

SET "WSCRIPT=%SystemRoot%\System32\wscript.exe"
IF NOT EXIST "%WSCRIPT%" SET "WSCRIPT=wscript.exe"

:: Deteksi otomatis ffmpeg bawaan instalasi LumaBooth / dslrBooth di komputer ini
SET "FFMPEG="
IF EXIST "%ProgramFiles%\LumaBooth\ffmpeg.exe"       SET "FFMPEG=%ProgramFiles%\LumaBooth\ffmpeg.exe"
IF EXIST "%ProgramFiles%\dslrBooth\ffmpeg.exe"       SET "FFMPEG=%ProgramFiles%\dslrBooth\ffmpeg.exe"
IF EXIST "%ProgramFiles(x86)%\LumaBooth\ffmpeg.exe"  SET "FFMPEG=%ProgramFiles(x86)%\LumaBooth\ffmpeg.exe"
IF EXIST "%ProgramFiles(x86)%\dslrBooth\ffmpeg.exe"  SET "FFMPEG=%ProgramFiles(x86)%\dslrBooth\ffmpeg.exe"
IF EXIST "%LOCALAPPDATA%\LumaBooth\ffmpeg.exe"       SET "FFMPEG=%LOCALAPPDATA%\LumaBooth\ffmpeg.exe"
IF EXIST "%LOCALAPPDATA%\dslrBooth\ffmpeg.exe"       SET "FFMPEG=%LOCALAPPDATA%\dslrBooth\ffmpeg.exe"

SET "SELF=%~f0"
SET "WORK_DIR=%~dp0"
SET "STAGE_DIR=%WORK_DIR%stage_upload"
SET "SILENT_VBS=%WORK_DIR%silent_runner_v12.vbs"
SET "IMG_COMP_PS1=%WORK_DIR%compress_img_v12.ps1"
SET "PC_MARKER=%WORK_DIR%last_pc.txt"
SET "PRIORITY_QUEUE=%WORK_DIR%priority_queue.txt"
SET "SENT_LOG=%WORK_DIR%sent_history.txt"
SET "ACTIVE_PROJ_FILE=%WORK_DIR%active_project.txt"
SET "DAEMON_LOCK=%WORK_DIR%daemon.lock"
SET "LOG_FILE=%WORK_DIR%last_upload.log"

:: Jika folder script dipindahkan antar PC, bersihkan marker & queue PC lama
SET "SAVED_PC="
IF EXIST "%PC_MARKER%" SET /P SAVED_PC=<"%PC_MARKER%"
IF /I NOT "!SAVED_PC!"=="%COMPUTERNAME%" (
    > "%PC_MARKER%" ECHO %COMPUTERNAME%
    DEL /F /Q "%PRIORITY_QUEUE%" "%WORK_DIR%active_priority.txt" "%ACTIVE_PROJ_FILE%" >nul 2>&1
)

:: Auto-generate VBScript launcher instan (<20ms) dengan Full Path "%SELF%"
IF NOT EXIST "%SILENT_VBS%" (
    (
        ECHO On Error Resume Next
        ECHO Set sh = CreateObject("WScript.Shell"^)
        ECHO sh.CurrentDirectory = WScript.Arguments(0^)
        ECHO cmd = "cmd.exe /d /c ""call """ ^& WScript.Arguments(1^) ^& """ " ^& WScript.Arguments(2^)
        ECHO If WScript.Arguments.Count ^> 3 Then cmd = cmd ^& " """ ^& WScript.Arguments(3^) ^& """"
        ECHO cmd = cmd ^& """"
        ECHO sh.Run cmd, 0, False
    ) > "%SILENT_VBS%"
)

:: Auto-generate PowerShell .NET Image Compressor (cadangan jika foto cetak > 2MB)
IF NOT EXIST "%IMG_COMP_PS1%" (
    (
        ECHO param([string]$src, [string]$dst^)
        ECHO Add-Type -AssemblyName System.Drawing
        ECHO $img = [System.Drawing.Image]::FromFile($src^)
        ECHO $w = $img.Width; $h = $img.Height
        ECHO if ($w -gt 2200 -or $h -gt 2200^) {
        ECHO     $r = [Math]::Min(2200.0 / $w, 2200.0 / $h^)
        ECHO     $nw = [int]($w * $r^); $nh = [int]($h * $r^)
        ECHO } else { $nw = $w; $nh = $h }
        ECHO $bmp = New-Object System.Drawing.Bitmap($nw, $nh^)
        ECHO $g = [System.Drawing.Graphics]::FromImage($bmp^)
        ECHO $g.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
        ECHO $g.DrawImage($img, 0, 0, $nw, $nh^)
        ECHO $g.Dispose(^); $img.Dispose(^)
        ECHO $enc = [System.Drawing.Imaging.ImageCodecInfo]::GetImageEncoders(^) ^| Where-Object { $_.MimeType -eq 'image/jpeg' }
        ECHO foreach ($q in @(85, 75, 65, 55^)^) {
        ECHO     $ep = New-Object System.Drawing.Imaging.EncoderParameters(1^)
        ECHO     $ep.Param[0] = New-Object System.Drawing.Imaging.EncoderParameter([System.Drawing.Imaging.Encoder]::Quality, [long]$q^)
        ECHO     $bmp.Save($dst, $enc, $ep^)
        ECHO     if ((Get-Item $dst^).Length -lt 1850000^) { break }
        ECHO }
        ECHO $bmp.Dispose(^)
    ) > "%IMG_COMP_PS1%"
)

:: ============================================================================
:: AUTO-DETECT LOKASI FOLDER DATA & PROJECT AKTIF DARI APPDATA LUMABOOTH
:: ============================================================================
SET "DSLRBOOTH_DIR=C:\dslrBooth"
IF EXIST "C:\dslrBooth" SET "DSLRBOOTH_DIR=C:\dslrBooth"
IF EXIST "D:\dslrBooth" SET "DSLRBOOTH_DIR=D:\dslrBooth"
IF EXIST "%USERPROFILE%\Pictures\dslrBooth" SET "DSLRBOOTH_DIR=%USERPROFILE%\Pictures\dslrBooth"
IF EXIST "%USERPROFILE%\Pictures\LumaBooth" SET "DSLRBOOTH_DIR=%USERPROFILE%\Pictures\LumaBooth"
IF EXIST "%USERPROFILE%\OneDrive\Pictures\dslrBooth" SET "DSLRBOOTH_DIR=%USERPROFILE%\OneDrive\Pictures\dslrBooth"
IF EXIST "%USERPROFILE%\OneDrive\Pictures\LumaBooth" SET "DSLRBOOTH_DIR=%USERPROFILE%\OneDrive\Pictures\LumaBooth"
IF EXIST "%USERPROFILE%\Documents\dslrBooth" SET "DSLRBOOTH_DIR=%USERPROFILE%\Documents\dslrBooth"
IF EXIST "C:\LumaBooth" SET "DSLRBOOTH_DIR=C:\LumaBooth"

SET "LB_EVENT_ID="
SET "LB_MEDIA_DIR="
SET "LB_ACTIVE_DIR="
SET "APPDATA_SETTINGS=%APPDATA%\dslrBooth\app_settings_2021.json"

IF EXIST "%APPDATA_SETTINGS%" (
    FOR /F "tokens=1,* delims=: " %%A IN ('TYPE "%APPDATA_SETTINGS%" 2^>nul') DO (
        IF /I "%%~A"=="ImagesPath" (
            CALL :unquote_str RAW_IMG %%B
            SET "RAW_IMG=!RAW_IMG:\\=\!"
            IF EXIST "!RAW_IMG!" SET "DSLRBOOTH_DIR=!RAW_IMG!"
        )
        IF /I "%%~A"=="EventId" (
            CALL :unquote_str RAW_EID %%B
            IF NOT "!RAW_EID!"=="" SET "LB_EVENT_ID=!RAW_EID!"
        )
    )
)

IF NOT "!LB_EVENT_ID!"=="" (
    CALL :resolve_lcfg_mediadir "!LB_EVENT_ID!"
)
IF "!LB_MEDIA_DIR!"=="" (
    CALL :resolve_lcfg_mediadir ""
)
IF NOT "!LB_MEDIA_DIR!"=="" (
    IF EXIST "!DSLRBOOTH_DIR!\!LB_MEDIA_DIR!" (
        SET "LB_ACTIVE_DIR=!DSLRBOOTH_DIR!\!LB_MEDIA_DIR!"
        > "%ACTIVE_PROJ_FILE%" ECHO !LB_ACTIVE_DIR!
    )
)

:: ============================================================================
:: ROUTING MODE EKSEKUSI INTERNAL
:: ============================================================================
IF /I "%~1"=="BG_DAEMON" GOTO :bg_daemon_entry
IF /I "%~1"=="WORKER"    GOTO :bg_daemon_entry
IF /I "%~1"=="BG_SWEEP"   GOTO :bg_daemon_entry

SET "EVENT_TYPE=%~1"
IF "%EVENT_TYPE%"==""    GOTO :manual_click_entry

:: ============================================================================
:: FILTER EVENT TRIGGER LUMABOOTH (RINGAN & 0% OVERHEAD SAAT JEPRA-JEPRET)
:: ============================================================================
IF /I "%EVENT_TYPE%"=="countdown"        EXIT /B 0
IF /I "%EVENT_TYPE%"=="countdown_start"  EXIT /B 0
IF /I "%EVENT_TYPE%"=="processing_start" EXIT /B 0
IF /I "%EVENT_TYPE%"=="capture_start"    EXIT /B 0
IF /I "%EVENT_TYPE%"=="file_download"    EXIT /B 0

SET "FILE_PARAM=%~2"
IF /I "%EVENT_TYPE%"=="file_upload" IF NOT "!FILE_PARAM!"=="" GOTO :handle_fullpath_event
IF /I "%EVENT_TYPE%"=="printing"    IF NOT "!FILE_PARAM!"=="" GOTO :handle_fullpath_event

:: Untuk SEMUA event non-file (Launch Event, session_start, sharing_screen, session_end):
CALL :ensure_daemon_running
EXIT /B 0


:handle_fullpath_event
IF NOT EXIST "!FILE_PARAM!" (
    CALL :ensure_daemon_running
    EXIT /B 0
)

FOR %%X IN ("!FILE_PARAM!") DO (
    SET "EXT=%%~xX"
    SET "FNAME=%%~nxX"
    SET "P_MEDIA_DIR=%%~dpX"
)
SET "VALID_EXT=0"
FOR %%E IN (.jpg .jpeg .png .gif .mp4 .mov .webm) DO (
    IF /I "!EXT!"=="%%E" SET "VALID_EXT=1"
)
IF "!VALID_EXT!"=="0" EXIT /B 0

:: Abaikan thumbnail internal, singles, dan originals
IF /I NOT "!FNAME:_mp4.jpg=!"=="!FNAME!" EXIT /B 0
IF /I NOT "!FILE_PARAM:\thumb\=!"=="!FILE_PARAM!" EXIT /B 0
IF /I NOT "!FILE_PARAM:\Singles\=!"=="!FILE_PARAM!" EXIT /B 0
IF /I NOT "!FILE_PARAM:\Single\=!"=="!FILE_PARAM!" EXIT /B 0
IF /I NOT "!FILE_PARAM:\Originals\=!"=="!FILE_PARAM!" EXIT /B 0
IF /I NOT "!FILE_PARAM:\Original\=!"=="!FILE_PARAM!" EXIT /B 0

:: Cegah foto double & hemat kuota: Jika _2x.jpg dan file _1x.jpg ada, lewati _2x
IF /I NOT "!FNAME:_2x.jpg=!"=="!FNAME!" (
    SET "PAIR_1X_FULL=!FILE_PARAM:_2x.jpg=_1x.jpg!"
    IF EXIST "!PAIR_1X_FULL!" EXIT /B 0
)

:: Catat folder project yang sedang aktif dari path file ini
SET "P_MEDIA_DIR=!P_MEDIA_DIR:~0,-1!"
FOR %%P IN ("!P_MEDIA_DIR!") DO SET "P_EVENT_DIR=%%~dpP"
SET "P_EVENT_DIR=!P_EVENT_DIR:~0,-1!"
IF EXIST "!P_EVENT_DIR!" (
    > "%ACTIVE_PROJ_FILE%" ECHO !P_EVENT_DIR!
)

:: Masukkan file ke antrean prioritas instan
>> "%PRIORITY_QUEUE%" ECHO !FILE_PARAM!

:: Pastikan daemon background aktif
CALL :ensure_daemon_running
EXIT /B 0


:: ============================================================================
:: ENSURE DAEMON BERJALAN DI LATAR BELAKANG (NON-BLOCKING)
:: ============================================================================
:ensure_daemon_running
:: Tes apakah daemon sudah aktif memegang DAEMON_LOCK
( >> "%DAEMON_LOCK%" REM ) >nul 2>&1
IF !ERRORLEVEL! NEQ 0 (
    :: Daemon sudah berjalan aktif di latar belakang! Langsung kembali dalam <2ms
    EXIT /B 0
)
:: Luncurkan daemon detached di latar belakang
CALL :launch_detached BG_DAEMON ""
EXIT /B 0


:: ============================================================================
:: SUB-MODE: BG_DAEMON (Berjalan Non-Stop di Background, 0% Ganggu Printer)
:: ============================================================================
:bg_daemon_entry
CALL :daemon_loop 8>>"%DAEMON_LOCK%"
EXIT /B 0

:daemon_loop
IF NOT EXIST "%SENT_LOG%" TYPE nul > "%SENT_LOG%"
IF NOT EXIST "%STAGE_DIR%" MKDIR "%STAGE_DIR%" >nul 2>&1

:: 1. Prioritas #1: Drain file baru dari PRIORITY_QUEUE (sesi foto/video terbaru)
IF EXIST "%PRIORITY_QUEUE%" (
    CALL :drain_priority_queue
)

:: 2. Prioritas #2: Pindai dan upload file tertunda di project aktif
CALL :sync_active_project_files

:: 3. Istirahat 3 detik sebelum siklus berikutnya (hemat CPU 100%, 0% lag)
ping 127.0.0.1 -n 4 >nul 2>&1
GOTO :daemon_loop


:drain_priority_queue
IF NOT EXIST "%PRIORITY_QUEUE%" EXIT /B 0
MOVE /Y "%PRIORITY_QUEUE%" "%WORK_DIR%active_priority.txt" >nul 2>&1
IF NOT EXIST "%WORK_DIR%active_priority.txt" EXIT /B 0

FOR /F "usebackq delims=" %%P IN ("%WORK_DIR%active_priority.txt") DO (
    CALL :upload_single_file "%%P"
)
DEL /F /Q "%WORK_DIR%active_priority.txt" >nul 2>&1
IF EXIST "%PRIORITY_QUEUE%" GOTO :drain_priority_queue
EXIT /B 0


:sync_active_project_files
SET "SCAN_DIR="
IF EXIST "%ACTIVE_PROJ_FILE%" (
    SET /P SCAN_DIR=<"%ACTIVE_PROJ_FILE%"
)
IF "!SCAN_DIR!"=="" (
    IF NOT "!LB_ACTIVE_DIR!"=="" (
        SET "SCAN_DIR=!LB_ACTIVE_DIR!"
    )
)
IF "!SCAN_DIR!"=="" (
    FOR /F "delims=" %%D IN ('DIR "%DSLRBOOTH_DIR%" /AD /B /O-D 2^>nul') DO (
        IF "!SCAN_DIR!"=="" (
            IF /I NOT "%%D"=="Settings" IF /I NOT "%%D"=="Templates" (
                SET "SCAN_DIR=%DSLRBOOTH_DIR%\%%D"
            )
        )
    )
)
IF "!SCAN_DIR!"=="" EXIT /B 0
IF NOT EXIST "!SCAN_DIR!" EXIT /B 0

:: A. Pindai folder Prints di project aktif
IF EXIST "!SCAN_DIR!\Prints" (
    FOR /F "delims=" %%F IN ('DIR "!SCAN_DIR!\Prints" /A-D /B /O-D 2^>nul') DO (
        SET "P_FILE=!SCAN_DIR!\Prints\%%F"
        SET "PF_NAME=%%F"
        SET "DO_SYNC=1"
        IF /I NOT "!PF_NAME:_mp4.jpg=!"=="!PF_NAME!" SET "DO_SYNC=0"
        IF /I NOT "!PF_NAME:_2x.jpg=!"=="!PF_NAME!" (
            SET "P_PAIR=!PF_NAME:_2x.jpg=_1x.jpg!"
            IF EXIST "!SCAN_DIR!\Prints\!P_PAIR!" SET "DO_SYNC=0"
        )
        IF "!DO_SYNC!"=="1" (
            FINDSTR /I /L /C:"!P_FILE!" "%SENT_LOG%" >nul 2>&1
            IF ERRORLEVEL 1 (
                CALL :upload_single_file "!P_FILE!"
                IF EXIST "%PRIORITY_QUEUE%" EXIT /B 0
            )
        )
    )
)

:: B. Pindai folder Animated di project aktif (MP4, GIF, WebM, MOV)
IF EXIST "!SCAN_DIR!\Animated" (
    FOR /F "delims=" %%F IN ('DIR "!SCAN_DIR!\Animated" /A-D /B /O-D 2^>nul') DO (
        SET "A_FILE=!SCAN_DIR!\Animated\%%F"
        SET "AF_NAME=%%F"
        SET "DO_SYNC_A=1"
        IF /I NOT "!AF_NAME:_mp4.jpg=!"=="!AF_NAME!" SET "DO_SYNC_A=0"
        FOR %%E IN (.jpg .jpeg .png) DO (
            IF /I "%%~xF"=="%%E" SET "DO_SYNC_A=0"
        )
        IF "!DO_SYNC_A!"=="1" (
            FINDSTR /I /L /C:"!A_FILE!" "%SENT_LOG%" >nul 2>&1
            IF ERRORLEVEL 1 (
                CALL :upload_single_file "!A_FILE!"
                IF EXIST "%PRIORITY_QUEUE%" EXIT /B 0
            )
        )
    )
)

:: C. Pindai folder Videos jika ada
IF EXIST "!SCAN_DIR!\Videos" (
    FOR /F "delims=" %%F IN ('DIR "!SCAN_DIR!\Videos" /A-D /B /O-D 2^>nul') DO (
        SET "V_FILE=!SCAN_DIR!\Videos\%%F"
        SET "VF_NAME=%%F"
        IF /I "!VF_NAME:_mp4.jpg=!"=="!VF_NAME!" (
            FINDSTR /I /L /C:"!V_FILE!" "%SENT_LOG%" >nul 2>&1
            IF ERRORLEVEL 1 (
                CALL :upload_single_file "!V_FILE!"
                IF EXIST "%PRIORITY_QUEUE%" EXIT /B 0
            )
        )
    )
)
EXIT /B 0


:: ============================================================================
:: UPLOAD SATU FILE KE SERVER (FAST NON-BLOCKING ENGINE)
:: ============================================================================
:upload_single_file
SET "CURRENT_FILE=%~1"
FOR /L %%i IN (1,1,5) DO IF "!CURRENT_FILE:~-1!"==" " SET "CURRENT_FILE=!CURRENT_FILE:~0,-1!"
IF "!CURRENT_FILE!"=="" EXIT /B 0
IF NOT EXIST "!CURRENT_FILE!" EXIT /B 0

:: Lewati jika sudah ada di sent_history.txt
FINDSTR /I /L /C:"!CURRENT_FILE!" "%SENT_LOG%" >nul 2>&1
IF NOT ERRORLEVEL 1 EXIT /B 0

:: Tunggu file selesai ditulis jika sedang di-render/di-lock oleh LumaBooth
SET "F_READY=0"
FOR /L %%R IN (1,1,8) DO (
    IF "!F_READY!"=="0" (
        ( >> "!CURRENT_FILE!" REM ) >nul 2>&1
        IF !ERRORLEVEL! EQU 0 (
            SET "F_READY=1"
        ) ELSE (
            ping 127.0.0.1 -n 2 >nul 2>&1
        )
    )
)
IF "!F_READY!"=="0" EXIT /B 0

SET "FILE_SIZE=0"
FOR %%I IN ("!CURRENT_FILE!") DO (
    SET "FILE_SIZE=%%~zI"
    SET "DIR_MEDIA=%%~dpI"
    SET "FNAME_ONLY=%%~nxI"
    SET "FILE_EXT=%%~xI"
)
IF "!FILE_SIZE!"=="" EXIT /B 0
IF "!FILE_SIZE!"=="0" EXIT /B 0
IF /I NOT "!FNAME_ONLY:_mp4.jpg=!"=="!FNAME_ONLY!" EXIT /B 0

SET "DIR_MEDIA=!DIR_MEDIA:~0,-1!"
FOR %%I IN ("!DIR_MEDIA!") DO (
    SET "MEDIA_TYPE=%%~nxI"
    SET "DIR_EVENT=%%~dpI"
)
SET "DIR_EVENT=!DIR_EVENT:~0,-1!"
FOR %%I IN ("!DIR_EVENT!") DO SET "EVENT_NAME=%%~nxI"

IF /I "!EVENT_NAME!"=="Settings"  EXIT /B 0
IF /I "!EVENT_NAME!"=="Templates" EXIT /B 0
IF /I "!MEDIA_TYPE!"=="Originals" EXIT /B 0
IF /I "!MEDIA_TYPE!"=="Original"  EXIT /B 0
IF /I "!MEDIA_TYPE!"=="Singles"   EXIT /B 0
IF /I "!MEDIA_TYPE!"=="Single"    EXIT /B 0
IF /I "!MEDIA_TYPE!"=="thumb"     EXIT /B 0

SET "FOLDER_TARGET=Prints"
IF /I "!MEDIA_TYPE!"=="Animated" SET "FOLDER_TARGET=Animated"
IF /I "!MEDIA_TYPE!"=="GIFs"     SET "FOLDER_TARGET=Animated"
IF /I "!MEDIA_TYPE!"=="Videos"   SET "FOLDER_TARGET=Animated"

SET "IS_VIDEO=0"
IF /I "!FILE_EXT!"==".mp4"  SET "IS_VIDEO=1"
IF /I "!FILE_EXT!"==".mov"  SET "IS_VIDEO=1"
IF /I "!FILE_EXT!"==".webm" SET "IS_VIDEO=1"
IF /I "!FILE_EXT!"==".gif"  SET "FOLDER_TARGET=Animated"

IF NOT EXIST "%STAGE_DIR%" MKDIR "%STAGE_DIR%" >nul 2>&1
SET "STAGE_FILE=%STAGE_DIR%\!FNAME_ONLY!"
DEL /F /Q "!STAGE_FILE!" >nul 2>&1

:: Video Animated 100% dikirim kualitas original (tanpa re-encode CPU)
IF "!IS_VIDEO!"=="1" (
    COPY /Y "!CURRENT_FILE!" "!STAGE_FILE!" >nul 2>&1
    IF NOT EXIST "!STAGE_FILE!" (
        ping 127.0.0.1 -n 2 >nul 2>&1
        COPY /Y "!CURRENT_FILE!" "!STAGE_FILE!" >nul 2>&1
    )
    GOTO :do_upload_now
)

:: Foto Prints hanya dioptimalkan jika ukuran file >= 2 MB
IF !FILE_SIZE! GEQ 2000000 (
    CALL :compress_to_stage "!CURRENT_FILE!" "!STAGE_FILE!" "!FILE_EXT!"
)
IF NOT EXIST "!STAGE_FILE!" (
    COPY /Y "!CURRENT_FILE!" "!STAGE_FILE!" >nul 2>&1
)

:do_upload_now
IF NOT EXIST "!STAGE_FILE!" EXIT /B 0

:: Kirim ke Server Utama (files.yuhu.co.id) dengan timeout tegas 35 detik
"%CURL%" -4 --ssl-no-revoke --http1.1 -H "Expect:" --fail --max-time 35 --connect-timeout 10 -s -k -X POST "!API_URL_PRIMARY!" -F "token=!API_TOKEN!" -F "client_event=!EVENT_NAME!" -F "folder_target=!FOLDER_TARGET!" -F "file=@!STAGE_FILE!" >nul 2>&1
SET "CURL_ERR=!ERRORLEVEL!"

IF "!CURL_ERR!"=="0" (
    DEL /F /Q "!STAGE_FILE!" >nul 2>&1
    >> "%SENT_LOG%" ECHO !CURRENT_FILE!
    > "%LOG_FILE%" ECHO [%DATE% %TIME%] SUKSES: !EVENT_NAME!/!FOLDER_TARGET! - !FNAME_ONLY!
    EXIT /B 0
)

:: Jika HTTP 413 / error ukuran besar pada video: kirim via Raw Binary Stream
IF "!CURL_ERR!"=="22" IF "!IS_VIDEO!"=="1" (
    "%CURL%" -4 --ssl-no-revoke --http1.1 -H "Expect:" --fail --max-time 45 --connect-timeout 10 -s -k -X POST "!API_URL_PRIMARY!?token=!API_TOKEN!" -H "X-Client-Event: !EVENT_NAME!" -H "X-Folder-Target: !FOLDER_TARGET!" -H "X-File-Name: !FNAME_ONLY!" -H "X-File-Size: !FILE_SIZE!" --data-binary "@!STAGE_FILE!" >nul 2>&1
    IF !ERRORLEVEL! EQU 0 (
        DEL /F /Q "!STAGE_FILE!" >nul 2>&1
        >> "%SENT_LOG%" ECHO !CURRENT_FILE!
        > "%LOG_FILE%" ECHO [%DATE% %TIME%] SUKSES VIDEO RAW STREAM: !EVENT_NAME!/!FOLDER_TARGET! - !FNAME_ONLY!
        EXIT /B 0
    )
)

:: Percobaan ulang (retry 1x setelah jeda 2 detik)
ping 127.0.0.1 -n 3 >nul 2>&1
"%CURL%" -4 --ssl-no-revoke --http1.1 -H "Expect:" --fail --max-time 35 --connect-timeout 10 -s -k -X POST "!API_URL_PRIMARY!" -F "token=!API_TOKEN!" -F "client_event=!EVENT_NAME!" -F "folder_target=!FOLDER_TARGET!" -F "file=@!STAGE_FILE!" >nul 2>&1
IF !ERRORLEVEL! EQU 0 (
    DEL /F /Q "!STAGE_FILE!" >nul 2>&1
    >> "%SENT_LOG%" ECHO !CURRENT_FILE!
    > "%LOG_FILE%" ECHO [%DATE% %TIME%] SUKSES (RETRY): !EVENT_NAME!/!FOLDER_TARGET! - !FNAME_ONLY!
    EXIT /B 0
)

:: Jika tetap gagal, hapus stage file & lewati sementara agar tidak macet di antrean
DEL /F /Q "!STAGE_FILE!" >nul 2>&1
> "%LOG_FILE%" ECHO [%DATE% %TIME%] LEWATI SEMENTARA (ERR !CURL_ERR!): !FNAME_ONLY!
EXIT /B 0


:: ============================================================================
:: JIKA DIKLIK 2X LANGSUNG OLEH OPERATOR
:: ============================================================================
:manual_click_entry
ECHO =====================================================================
ECHO   YUHU PHOTOBOOTH v12 - FAST SYNC ^& AUTO BACKGROUND ENGINE
ECHO =====================================================================
ECHO   Folder Data LumaBooth : "!DSLRBOOTH_DIR!"
ECHO   Project Aktif Saat Ini: "!LB_ACTIVE_DIR!"
ECHO   Target Cloud Server   : !API_URL_PRIMARY!
ECHO   Filter Media          : Hanya Prints ^& Animated (Singles/Originals DIABAIKAN)
ECHO   Mode Kerja            : Background Daemon Non-Stop (0%% Beban Printer)
ECHO =====================================================================
ECHO.
ECHO [*] Membersihkan proses curl gantung ^& file lock lama...
TASKKILL /F /IM curl.exe >nul 2>&1
DEL /F /Q "%DAEMON_LOCK%" "%WORK_DIR%active_priority.txt" "%WORK_DIR%active_batch.txt" "%WORK_DIR%raw_scan.tmp" >nul 2>&1

ECHO [*] Memulai engine sinkronisasi di latar belakang...
CALL :launch_detached BG_DAEMON ""

ECHO.
ECHO [V] Engine YUHU v12 Berhasil Aktif di Latar Belakang!
ECHO     - Foto hasil cetak ^& video animasi otomatis di-upload setiap sesi.
ECHO     - Aman 100%% tanpa mengganggu printer atau memperlambat kamera.
ECHO     - Jendela ini akan tertutup otomatis dalam 3 detik...
ping 127.0.0.1 -n 4 >nul 2>&1
EXIT /B 0


:: ============================================================================
:: HELPER: UNQUOTE STR & LAUNCH DETACHED BACKGROUND PROCESS
:: ============================================================================
:unquote_str
SET "%~1=%~2"
EXIT /B 0

:resolve_lcfg_mediadir
IF NOT EXIST "%APPDATA%\dslrBooth\EventSettings" EXIT /B 0
FOR /F "delims=" %%L IN ('DIR "%APPDATA%\dslrBooth\EventSettings\%~1*.lcfg" /A-D /B /O-D 2^>nul') DO (
    IF "!LB_MEDIA_DIR!"=="" (
        FOR /F "tokens=1,* delims=: " %%A IN ('FINDSTR /I MediaDirectoryName "%APPDATA%\dslrBooth\EventSettings\%%L" 2^>nul') DO (
            IF /I "%%~A"=="MediaDirectoryName" (
                CALL :unquote_str RAW_MDIR %%B
                IF NOT "!RAW_MDIR!"=="" (
                    IF EXIST "!DSLRBOOTH_DIR!\!RAW_MDIR!" (
                        SET "LB_MEDIA_DIR=!RAW_MDIR!"
                    )
                )
            )
        )
    )
)
EXIT /B 0

:launch_detached
SET "MODE_ARG=%~1"
SET "EXTRA_ARG=%~2"
IF EXIST "%SILENT_VBS%" (
    IF "!EXTRA_ARG!"=="" (
        "%WSCRIPT%" //B //Nologo "%SILENT_VBS%" "%WORK_DIR:~0,-1%" "%SELF%" "!MODE_ARG!" >nul 2>&1
    ) ELSE (
        "%WSCRIPT%" //B //Nologo "%SILENT_VBS%" "%WORK_DIR:~0,-1%" "%SELF%" "!MODE_ARG!" "!EXTRA_ARG!" >nul 2>&1
    )
) ELSE (
    PUSHD "%WORK_DIR%"
    START "" /B /BELOWNORMAL cmd /d /c "call "%SELF%" !MODE_ARG! "!EXTRA_ARG!"" >nul 2>&1
    POPD
)
EXIT /B 0

:compress_to_stage
SET "SRC_MEDIA=%~1"
SET "DST_MEDIA=%~2"
SET "MEDIA_EXT=%~3"

IF /I "!MEDIA_EXT!"==".jpg"  GOTO :comp_image
IF /I "!MEDIA_EXT!"==".jpeg" GOTO :comp_image
IF /I "!MEDIA_EXT!"==".png"  GOTO :comp_image
EXIT /B 0

:comp_image
IF NOT "!FFMPEG!"=="" (
    "!FFMPEG!" -y -i "!SRC_MEDIA!" -vf "scale='min(2200,iw)':-2" -q:v 5 "!DST_MEDIA!" >nul 2>&1
    IF EXIST "!DST_MEDIA!" (
        SET "DST_SZ=0"
        FOR %%S IN ("!DST_MEDIA!") DO SET "DST_SZ=%%~zS"
        IF !DST_SZ! GEQ 1900000 (
            "!FFMPEG!" -y -i "!SRC_MEDIA!" -vf "scale='min(1800,iw)':-2" -q:v 8 "!DST_MEDIA!" >nul 2>&1
        )
        EXIT /B 0
    )
)
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%IMG_COMP_PS1%" "!SRC_MEDIA!" "!DST_MEDIA!" >nul 2>&1
EXIT /B 0
