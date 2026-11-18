@echo off
SETLOCAL EnableDelayedExpansion

:: ============================================================================
:: YUHU PHOTOBOOTH - LUMABOOTH WINDOWS UNIVERSAL SYNC & FAST RECOVERY
:: VERSI: YUHU v12.bat (SMART NON-BLOCKING ANIMATED + MULTI-PC AUTO-RESET)
:: ============================================================================
:: Mendukung 2 Cara Kerja Sekaligus (Di Komputer Mana Saja):
:: 1. Dipanggil Otomatis oleh LumaBooth Trigger (Settings -> Triggers -> Application)
:: 2. Diklik 2x Langsung oleh Operator (Otomatis jalan di belakang layar tanpa
::    jendela mengganggu, memantau & mengupload seluruh foto lama & baru!)
::
:: PEMBARUAN & PERBAIKAN DI v12:
:: 1. VIDEO ANIMATED BEBAS MACET (NON-BLOCKING):
::    - Menunggu rendering ffmpeg LumaBooth selesai secara halus sebelum di-upload.
::    - Video MP4/GIF/MOV dikirim 100% original tanpa kompresi CPU ulang.
:: 2. TIMEOUT TEGAS 35 DETIK:
::    - Tidak ada lagi proses gantung 5-30 menit jika internet lambat.
:: 3. MULTI-PC AUTO RESET SAAT DICOPY:
::    - Jika folder script dicopy ke laptop/komputer lain, script otomatis
::      mereset cache & file lock lama sehingga langsung aktif mengupload.
:: 4. 0% GANGGUAN PRINTER & KOMPUTER:
::    - Berjalan pada prioritas BELOWNORMAL di latar belakang tanpa jendela mengganggu.
:: 5. ANTI-DUPLIKAT STRIP:
::    - Foto strip _2x otomatis dilewati jika _1x sudah ada (hemat bandwidth 50%).
:: ============================================================================

SET "INPUT_EVENT=%~1"
SET "INPUT_FILE=%~2"

SET "API_URL_PRIMARY=https://files.yuhu.co.id/client/api_upload.php"
SET "API_URL_FALLBACK=https://files.yuhu.co.id/client/api_upload.php"
SET "API_TOKEN=YuhuSecretToken2026"

SET "CURL=%SystemRoot%\System32\curl.exe"
IF NOT EXIST "%CURL%" SET "CURL=curl.exe"

SET "WSCRIPT=%SystemRoot%\System32\wscript.exe"
IF NOT EXIST "%WSCRIPT%" SET "WSCRIPT=wscript.exe"

:: Deteksi otomatis ffmpeg.exe bawaan instalasi LumaBooth / dslrBooth di komputer ini
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
SET "QUEUE_FILE=%WORK_DIR%upload_queue.txt"
SET "BATCH_FILE=%WORK_DIR%active_batch.txt"
SET "SENT_LOG=%WORK_DIR%sent_history.txt"
SET "ACTIVE_PROJ_FILE=%WORK_DIR%active_project.txt"
SET "LOCK_FILE=%WORK_DIR%worker.lock"
SET "SWEEP_LOCK=%WORK_DIR%sweep.lock"
SET "DAEMON_LOCK=%WORK_DIR%daemon.lock"
SET "LOG_FILE=%WORK_DIR%last_upload.log"

:: ============================================================================
:: MULTI-PC AUTO-RESET: Jika folder script dipindahkan/dicopy ke komputer lain
:: ============================================================================
SET "SAVED_PC="
IF EXIST "%PC_MARKER%" SET /P SAVED_PC=<"%PC_MARKER%"
IF /I NOT "!SAVED_PC!"=="%COMPUTERNAME%" (
    > "%PC_MARKER%" ECHO %COMPUTERNAME%
    DEL /F /Q "%BATCH_FILE%" "%QUEUE_FILE%" "%PRIORITY_QUEUE%" "%ACTIVE_PROJ_FILE%" "%WORK_DIR%last_batch_project.txt" "%WORK_DIR%*.lock" "%WORK_DIR%*.tmp" >nul 2>&1
    IF EXIST "%SENT_LOG%" (
        MOVE /Y "%SENT_LOG%" "%SENT_LOG%.bak" >nul 2>&1
    )
    TYPE nul > "%SENT_LOG%"
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
IF /I "!INPUT_EVENT!"=="WORKER"     GOTO :worker_entry
IF /I "!INPUT_EVENT!"=="BG_SWEEP"   GOTO :bg_sweep_entry
IF /I "!INPUT_EVENT!"=="BG_DAEMON"  GOTO :bg_daemon_entry
IF "!INPUT_EVENT!"==""              GOTO :manual_click_entry

:: ============================================================================
:: FILTER EVENT TRIGGER LUMABOOTH (RINGAN & 0% OVERHEAD SAAT JEPRA-JEPRET)
:: ============================================================================
IF /I "!INPUT_EVENT!"=="countdown"        EXIT /B 0
IF /I "!INPUT_EVENT!"=="countdown_start"  EXIT /B 0
IF /I "!INPUT_EVENT!"=="processing_start" EXIT /B 0
IF /I "!INPUT_EVENT!"=="capture_start"    EXIT /B 0
IF /I "!INPUT_EVENT!"=="file_download"    EXIT /B 0

IF /I "!INPUT_EVENT!"=="file_upload" IF NOT "!INPUT_FILE!"=="" GOTO :handle_fullpath_event
IF /I "!INPUT_EVENT!"=="printing"    IF NOT "!INPUT_FILE!"=="" GOTO :handle_fullpath_event

:: Untuk event non-file (Launch Event, session_start, sharing_screen, session_end):
CALL :launch_detached BG_SWEEP "!LB_ACTIVE_DIR!"
CALL :launch_detached WORKER ""
CALL :launch_detached BG_DAEMON ""
EXIT /B 0


:handle_fullpath_event
IF NOT EXIST "!INPUT_FILE!" (
    CALL :launch_detached BG_SWEEP "!LB_ACTIVE_DIR!"
    CALL :launch_detached WORKER ""
    CALL :launch_detached BG_DAEMON ""
    EXIT /B 0
)

FOR %%X IN ("!INPUT_FILE!") DO (
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
IF /I NOT "!INPUT_FILE:\thumb\=!"=="!INPUT_FILE!" EXIT /B 0
IF /I NOT "!INPUT_FILE:\Singles\=!"=="!INPUT_FILE!" EXIT /B 0
IF /I NOT "!INPUT_FILE:\Single\=!"=="!INPUT_FILE!" EXIT /B 0
IF /I NOT "!INPUT_FILE:\Originals\=!"=="!INPUT_FILE!" EXIT /B 0
IF /I NOT "!INPUT_FILE:\Original\=!"=="!INPUT_FILE!" EXIT /B 0

:: Cegah foto ganda & hemat kuota: Jika _2x.jpg dan file _1x.jpg ada, lewati _2x
IF /I NOT "!FNAME:_2x.jpg=!"=="!FNAME!" (
    SET "PAIR_1X_FULL=!INPUT_FILE:_2x.jpg=_1x.jpg!"
    IF EXIST "!PAIR_1X_FULL!" EXIT /B 0
)

:: Catat folder project yang sedang aktif dari path file ini
SET "P_MEDIA_DIR=!P_MEDIA_DIR:~0,-1!"
FOR %%P IN ("!P_MEDIA_DIR!") DO SET "P_EVENT_DIR=%%~dpP"
SET "P_EVENT_DIR=!P_EVENT_DIR:~0,-1!"
IF EXIST "!P_EVENT_DIR!" (
    > "%ACTIVE_PROJ_FILE%" ECHO !P_EVENT_DIR!
)

:: Masukkan file terbaru ini ke PRIORITY_QUEUE (Urutan #1)
>> "%PRIORITY_QUEUE%" ECHO !INPUT_FILE!
CALL :launch_detached WORKER ""
CALL :launch_detached BG_SWEEP "!P_EVENT_DIR!"
CALL :launch_detached BG_DAEMON ""
EXIT /B 0


:: ============================================================================
:: JIKA DIKLIK 2X LANGSUNG OLEH OPERATOR (MANUAL RUN)
:: ============================================================================
:manual_click_entry
ECHO =====================================================================
ECHO   YUHU PHOTOBOOTH v12 - FAST SYNC ^& RECOVERY ENGINE AKTIF
ECHO =====================================================================
ECHO   Folder Data LumaBooth : "!DSLRBOOTH_DIR!"
ECHO   Project Aktif Saat Ini: "!LB_ACTIVE_DIR!"
ECHO   Target Cloud Server   : !API_URL_PRIMARY!
ECHO   Filter Media          : Hanya Prints ^& Animated (Singles/Originals DIABAIKAN)
ECHO   Mode Kerja            : Non-Blocking Background Daemon
ECHO =====================================================================
ECHO.
ECHO [*] Membersihkan antrean lama...
DEL /F /Q "%LOCK_FILE%" "%SWEEP_LOCK%" "%DAEMON_LOCK%" "%BATCH_FILE%" "%QUEUE_FILE%" "%PRIORITY_QUEUE%" "%WORK_DIR%active_priority.txt" "%WORK_DIR%raw_scan.tmp" >nul 2>&1

ECHO [*] Memulai sinkronisasi seluruh media di latar belakang...
CALL :launch_detached BG_SWEEP "!LB_ACTIVE_DIR!"
CALL :launch_detached WORKER ""
CALL :launch_detached BG_DAEMON ""
ECHO [V] Berhasil aktif! Seluruh foto hasil cetak ^& animasi di dalam project
ECHO     sedang di-upload otomatis di latar belakang sampai 100%% tuntas.
ECHO     Jendela ini akan menutup otomatis dalam 3 detik...
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
PUSHD "%WORK_DIR%"
IF "!EXTRA_ARG!"=="" (
    START "" /B /BELOWNORMAL cmd.exe /d /c """%SELF%"" !MODE_ARG!" >nul 2>&1
) ELSE (
    START "" /B /BELOWNORMAL cmd.exe /d /c """%SELF%"" !MODE_ARG! ""!EXTRA_ARG!""" >nul 2>&1
)
POPD
EXIT /B 0


:: ============================================================================
:: SUB-MODE: BG_DAEMON (Berjalan Non-Stop di Background)
:: ============================================================================
:bg_daemon_entry
CALL :locked_daemon 8>>"%DAEMON_LOCK%"
EXIT /B 0

:locked_daemon
:daemon_loop
CALL :locked_sweep_entry "!LB_ACTIVE_DIR!"
CALL :worker_entry
ping 127.0.0.1 -n 6 >nul 2>&1
GOTO :daemon_loop


:: ============================================================================
:: SUB-MODE: BG_SWEEP (Memindai File di Project Aktif & Semua Project)
:: ============================================================================
:bg_sweep_entry
SET "EXPLICIT_PROJECT=%~2"
CALL :locked_sweep_entry "!EXPLICIT_PROJECT!"
CALL :launch_detached WORKER ""
EXIT /B 0

:locked_sweep_entry
CALL :do_sweep_scan "%~1" 7>>"%SWEEP_LOCK%"
EXIT /B 0

:do_sweep_scan
IF NOT EXIST "%SENT_LOG%" TYPE nul > "%SENT_LOG%"
IF NOT EXIST "%DSLRBOOTH_DIR%" EXIT /B 0

SET "PRIORITY_DIR=%~1"
IF "!PRIORITY_DIR!"=="" (
    IF NOT "!LB_ACTIVE_DIR!"=="" (
        SET "PRIORITY_DIR=!LB_ACTIVE_DIR!"
    ) ELSE IF EXIST "%ACTIVE_PROJ_FILE%" (
        SET /P PRIORITY_DIR=<"%ACTIVE_PROJ_FILE%"
    )
)

SET "LAST_BATCH_PROJ="
IF EXIST "%WORK_DIR%last_batch_project.txt" (
    SET /P LAST_BATCH_PROJ=<"%WORK_DIR%last_batch_project.txt"
)

IF NOT "!PRIORITY_DIR!"=="" (
    IF /I NOT "!PRIORITY_DIR!"=="!LAST_BATCH_PROJ!" (
        > "%WORK_DIR%last_batch_project.txt" ECHO !PRIORITY_DIR!
        DEL /F /Q "%BATCH_FILE%" "%QUEUE_FILE%" >nul 2>&1
    )
)

SET "RAW_LIST=%WORK_DIR%raw_scan.tmp"
DEL /F /Q "%RAW_LIST%" >nul 2>&1

:: 1. Urutan Pertama: Pindai foto/video di project aktif
IF NOT "!PRIORITY_DIR!"=="" (
    IF EXIST "!PRIORITY_DIR!" (
        CALL :append_project_files "!PRIORITY_DIR!"
    )
)

:: 2. Urutan Kedua: Pindai project dari AppData LumaBooth jika berbeda
IF NOT "!LB_ACTIVE_DIR!"=="" (
    IF /I NOT "!LB_ACTIVE_DIR!"=="!PRIORITY_DIR!" (
        IF EXIST "!LB_ACTIVE_DIR!" (
            CALL :append_project_files "!LB_ACTIVE_DIR!"
        )
    )
)

:: 3. Urutan Ketiga: Pindai seluruh folder project lain di dalam DSLRBOOTH_DIR
FOR /F "delims=" %%D IN ('DIR "%DSLRBOOTH_DIR%" /AD /B /O-D 2^>nul') DO (
    IF /I NOT "%%D"=="Settings" IF /I NOT "%%D"=="Templates" (
        SET "CAND_PROJ=%DSLRBOOTH_DIR%\%%D"
        IF /I NOT "!CAND_PROJ!"=="!PRIORITY_DIR!" (
            IF /I NOT "!CAND_PROJ!"=="!LB_ACTIVE_DIR!" (
                CALL :append_project_files "!CAND_PROJ!"
            )
        )
    )
)

IF NOT EXIST "%RAW_LIST%" EXIT /B 0

:: Filter thumbnail internal _mp4.jpg dan masukkan ke antrean QUEUE_FILE
FINDSTR /V /I "_mp4.jpg" "%RAW_LIST%" >> "%QUEUE_FILE%" 2>nul
DEL /F /Q "%RAW_LIST%" >nul 2>&1
EXIT /B 0

:append_project_files
SET "TARGET_PROJ=%~1"
IF "!TARGET_PROJ!"=="" EXIT /B 0
IF NOT EXIST "!TARGET_PROJ!" EXIT /B 0

(
    IF EXIST "!TARGET_PROJ!\Prints" (
        FOR /F "delims=" %%F IN ('DIR "!TARGET_PROJ!\Prints" /A-D /B /O-D 2^>nul') DO (
            ECHO !TARGET_PROJ!\Prints\%%F
        )
    )
    IF EXIST "!TARGET_PROJ!\Animated" (
        FOR /F "delims=" %%F IN ('DIR "!TARGET_PROJ!\Animated" /A-D /B /O-D 2^>nul') DO (
            ECHO !TARGET_PROJ!\Animated\%%F
        )
    )
    IF EXIST "!TARGET_PROJ!\Videos" (
        FOR /F "delims=" %%F IN ('DIR "!TARGET_PROJ!\Videos" /A-D /B /O-D 2^>nul') DO (
            ECHO !TARGET_PROJ!\Videos\%%F
        )
    )
    IF EXIST "!TARGET_PROJ!\GIFs" (
        FOR /F "delims=" %%F IN ('DIR "!TARGET_PROJ!\GIFs" /A-D /B /O-D 2^>nul') DO (
            ECHO !TARGET_PROJ!\GIFs\%%F
        )
    )
) >> "%RAW_LIST%" 2>nul
EXIT /B 0


:: ============================================================================
:: PROSES WORKER SINGLE-INSTANCE
:: ============================================================================
:worker_entry
CALL :locked_worker 9>>"%LOCK_FILE%"
EXIT /B 0

:locked_worker
IF NOT EXIST "%SENT_LOG%" TYPE nul > "%SENT_LOG%"
IF NOT EXIST "%STAGE_DIR%" MKDIR "%STAGE_DIR%" >nul 2>&1

:worker_main_loop
IF EXIST "%PRIORITY_QUEUE%" (
    CALL :drain_priority_queue
)

IF NOT EXIST "%BATCH_FILE%" (
    IF EXIST "%QUEUE_FILE%" (
        MOVE /Y "%QUEUE_FILE%" "%BATCH_FILE%" >nul 2>&1
    )
)

IF NOT EXIST "%BATCH_FILE%" (
    IF EXIST "%PRIORITY_QUEUE%" GOTO :worker_main_loop
    EXIT /B 0
)

FOR /F "usebackq delims=" %%A IN ("%BATCH_FILE%") DO (
    IF EXIST "%PRIORITY_QUEUE%" (
        CALL :drain_priority_queue
    )
    CALL :upload_single_file "%%A" 0
)

DEL /F /Q "%BATCH_FILE%" >nul 2>&1
IF EXIST "%QUEUE_FILE%" GOTO :worker_main_loop
IF EXIST "%PRIORITY_QUEUE%" GOTO :worker_main_loop
EXIT /B 0

:drain_priority_queue
MOVE /Y "%PRIORITY_QUEUE%" "%WORK_DIR%active_priority.txt" >nul 2>&1
IF NOT EXIST "%WORK_DIR%active_priority.txt" EXIT /B 0
FOR /F "usebackq delims=" %%P IN ("%WORK_DIR%active_priority.txt") DO (
    CALL :upload_single_file "%%P" 1
)
DEL /F /Q "%WORK_DIR%active_priority.txt" >nul 2>&1
IF EXIST "%PRIORITY_QUEUE%" GOTO :drain_priority_queue
EXIT /B 0

:upload_single_file
SET "CURRENT_FILE=%~1"
SET "IS_NEW_CAPTURE=%~2"
FOR /L %%i IN (1,1,5) DO IF "!CURRENT_FILE:~-1!"==" " SET "CURRENT_FILE=!CURRENT_FILE:~0,-1!"
IF "!CURRENT_FILE!"=="" EXIT /B 0
IF NOT EXIST "!CURRENT_FILE!" EXIT /B 0

:: Lewati jika sudah ada di sent_history.txt
FINDSTR /I /L /C:"!CURRENT_FILE!" "%SENT_LOG%" >nul 2>&1
IF NOT ERRORLEVEL 1 EXIT /B 0

:: Tunggu file selesai ditulis jika sedang di-render/di-lock oleh LumaBooth
SET "F_READY=0"
FOR /L %%R IN (1,1,6) DO (
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

:: Lewati strip _2x jika _1x ada (cegah duplikat & hemat 50% kuota)
IF /I NOT "!FNAME_ONLY:_2x.jpg=!"=="!FNAME_ONLY!" (
    SET "PAIR_1X_CHECK=!CURRENT_FILE:_2x.jpg=_1x.jpg!"
    IF EXIST "!PAIR_1X_CHECK!" (
        >> "%SENT_LOG%" ECHO !CURRENT_FILE!
        EXIT /B 0
    )
)

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

:: Video Animated 100% original (tanpa re-encode CPU)
IF "!IS_VIDEO!"=="1" (
    COPY /Y "!CURRENT_FILE!" "!STAGE_FILE!" >nul 2>&1
    IF NOT EXIST "!STAGE_FILE!" (
        ping 127.0.0.1 -n 2 >nul 2>&1
        COPY /Y "!CURRENT_FILE!" "!STAGE_FILE!" >nul 2>&1
    )
    GOTO :ready_to_upload
)

:: Hanya Foto Cetak (Prints) yang >= 2MB yang dioptimalkan jika diperlukan
IF !FILE_SIZE! GEQ 2000000 (
    CALL :compress_to_stage "!CURRENT_FILE!" "!STAGE_FILE!" "!FILE_EXT!"
)

IF NOT EXIST "!STAGE_FILE!" (
    COPY /Y "!CURRENT_FILE!" "!STAGE_FILE!" >nul 2>&1
)

:ready_to_upload
IF NOT EXIST "!STAGE_FILE!" EXIT /B 0

SET "RETRY_COUNT=0"
:retry_upload
IF NOT EXIST "!CURRENT_FILE!" (
    DEL /F /Q "!STAGE_FILE!" >nul 2>&1
    EXIT /B 0
)

:: Kirim ke Server Utama (files.yuhu.co.id) dengan timeout 35 detik
"%CURL%" -4 --ssl-no-revoke --http1.1 -H "Expect:" --fail --max-time 35 --connect-timeout 10 -s -k -X POST "!API_URL_PRIMARY!" -F "token=!API_TOKEN!" -F "client_event=!EVENT_NAME!" -F "folder_target=!FOLDER_TARGET!" -F "file=@!STAGE_FILE!" >nul 2>&1
SET "CURL_ERR=!ERRORLEVEL!"

IF "!CURL_ERR!"=="0" (
    DEL /F /Q "!STAGE_FILE!" >nul 2>&1
    >> "%SENT_LOG%" ECHO !CURRENT_FILE!
    > "%LOG_FILE%" ECHO [%DATE% %TIME%] SUKSES: !EVENT_NAME!/!FOLDER_TARGET! - !FNAME_ONLY!
    EXIT /B 0
)

:: Jika HTTP 413 / error ukuran besar pada video: kirim via Raw Binary Stream
IF "!CURL_ERR!"=="22" (
    IF "!IS_VIDEO!"=="1" (
        "%CURL%" -4 --ssl-no-revoke --http1.1 -H "Expect:" --fail --max-time 45 --connect-timeout 10 -s -k -X POST "!API_URL_PRIMARY!?token=!API_TOKEN!" -H "X-Client-Event: !EVENT_NAME!" -H "X-Folder-Target: !FOLDER_TARGET!" -H "X-File-Name: !FNAME_ONLY!" -H "X-File-Size: !FILE_SIZE!" --data-binary "@!STAGE_FILE!" >nul 2>&1
        IF !ERRORLEVEL! EQU 0 (
            DEL /F /Q "!STAGE_FILE!" >nul 2>&1
            >> "%SENT_LOG%" ECHO !CURRENT_FILE!
            > "%LOG_FILE%" ECHO [%DATE% %TIME%] SUKSES VIDEO RAW STREAM: !EVENT_NAME!/!FOLDER_TARGET! - !FNAME_ONLY!
            EXIT /B 0
        )
    ) ELSE (
        CALL :force_recompress "!CURRENT_FILE!" "!STAGE_FILE!" "!FILE_EXT!"
        "%CURL%" -4 --ssl-no-revoke --http1.1 -H "Expect:" --fail --max-time 35 --connect-timeout 10 -s -k -X POST "!API_URL_PRIMARY!" -F "token=!API_TOKEN!" -F "client_event=!EVENT_NAME!" -F "folder_target=!FOLDER_TARGET!" -F "file=@!STAGE_FILE!" >nul 2>&1
        IF !ERRORLEVEL! EQU 0 (
            DEL /F /Q "!STAGE_FILE!" >nul 2>&1
            >> "%SENT_LOG%" ECHO !CURRENT_FILE!
            > "%LOG_FILE%" ECHO [%DATE% %TIME%] SUKSES OPTIMIZED: !EVENT_NAME!/!FOLDER_TARGET! - !FNAME_ONLY!
            EXIT /B 0
        )
    )
)

SET /A RETRY_COUNT+=1
IF !RETRY_COUNT! GEQ 2 (
    DEL /F /Q "!STAGE_FILE!" >nul 2>&1
    > "%LOG_FILE%" ECHO [%DATE% %TIME%] LEWATI SEMENTARA (ERR !CURL_ERR!): !FNAME_ONLY!
    EXIT /B 0
)

> "%LOG_FILE%" ECHO [%DATE% %TIME%] RETRY !RETRY_COUNT!/2 (CODE !CURL_ERR!): !FNAME_ONLY!
ping 127.0.0.1 -n 3 >nul 2>&1
GOTO :retry_upload


:: ============================================================================
:: HELPER: KOMPRESI CERDAS (< 1.8 MB) UNTUK FOTO CETAK
:: ============================================================================
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

:force_recompress
SET "F_SRC=%~1"
SET "F_DST=%~2"
SET "F_EXT=%~3"
IF /I "!F_EXT!"==".mp4" (
    COPY /Y "!F_SRC!" "!F_DST!" >nul 2>&1
    EXIT /B 0
)
IF /I "!F_EXT!"==".mov" (
    COPY /Y "!F_SRC!" "!F_DST!" >nul 2>&1
    EXIT /B 0
)
IF /I "!F_EXT!"==".webm" (
    COPY /Y "!F_SRC!" "!F_DST!" >nul 2>&1
    EXIT /B 0
)
CALL :compress_to_stage "!F_SRC!" "!F_DST!" "!F_EXT!"
EXIT /B 0
