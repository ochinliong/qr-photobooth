@echo off
SETLOCAL EnableDelayedExpansion

:: ============================================================================
:: YUHU PHOTOBOOTH - LUMABOOTH WINDOWS (OKTOBER 2026) UNIVERSAL SYNC & TRIGGER
:: ============================================================================
:: Mendukung 2 Cara Kerja Sekaligus (Di Komputer Mana Saja):
:: 1. Dipanggil Otomatis oleh LumaBooth Trigger (Settings -> Triggers -> Application)
:: 2. Diklik 2x Langsung oleh Operator (Otomatis jalan di belakang layar tanpa
::    jendela mengganggu, memantau folder LumaBooth terus-menerus selama 6+ jam!)
::
:: KEUNGGULAN:
:: - Auto-Backfill Project Lama/Sebelumnya: Jika project sudah dibuat sebelumnya
::   dan sudah ada foto di dalamnya sebelum .bat dimasukkan ke Trigger, SELURUH
::   foto sebelumnya otomatis ikut terupload 100% ke project tersebut!
:: - Bebas Bug Spasi Nama File (Aman dinamai "YUHU v7.bat", "YUHU v5.bat", dll)
:: - Auto-Detect Lokasi Folder dslrBooth / LumaBooth di PC mana pun:
::   (C:\dslrBooth, D:\dslrBooth, Pictures\dslrBooth, Pictures\LumaBooth, Documents\dslrBooth)
:: - Prioritas CPU Rendah (/BELOWNORMAL) -> 100% Aman untuk Kamera & Printer
:: - Anti-Gagal Upload (Antrean Offline Otomatis saat Internet/Listrik Mati)
:: ============================================================================

SET "API_URL_PRIMARY=https://files.yuhu.co.id/client/api_upload.php"
SET "API_URL_FALLBACK=https://qr.yuhu.co.id/client/api_upload.php"
SET "API_TOKEN=YuhuSecretToken2026"

SET "CURL=%SystemRoot%\System32\curl.exe"
IF NOT EXIST "%CURL%" SET "CURL=curl.exe"

SET "SELF=%~f0"
SET "WORK_DIR=%~dp0"
SET "QUEUE_FILE=%WORK_DIR%upload_queue.txt"
SET "SENT_LOG=%WORK_DIR%sent_history.txt"
SET "ACTIVE_PROJ_FILE=%WORK_DIR%active_project.txt"
SET "LOCK_FILE=%WORK_DIR%worker.lock"
SET "SWEEP_LOCK=%WORK_DIR%sweep.lock"
SET "DAEMON_LOCK=%WORK_DIR%daemon.lock"
SET "LOG_FILE=%WORK_DIR%last_upload.log"

:: ============================================================================
:: AUTO-DETECT LOKASI FOLDER DATA LUMABOOTH / DSLRBOOTH DI PC INI
:: ============================================================================
SET "DSLRBOOTH_DIR=C:\dslrBooth"
IF EXIST "C:\dslrBooth" GOTO :dir_detected
IF EXIST "D:\dslrBooth" (SET "DSLRBOOTH_DIR=D:\dslrBooth" & GOTO :dir_detected)
IF EXIST "%USERPROFILE%\Pictures\dslrBooth" (SET "DSLRBOOTH_DIR=%USERPROFILE%\Pictures\dslrBooth" & GOTO :dir_detected)
IF EXIST "%USERPROFILE%\Pictures\LumaBooth" (SET "DSLRBOOTH_DIR=%USERPROFILE%\Pictures\LumaBooth" & GOTO :dir_detected)
IF EXIST "%USERPROFILE%\Documents\dslrBooth" (SET "DSLRBOOTH_DIR=%USERPROFILE%\Documents\dslrBooth" & GOTO :dir_detected)
IF EXIST "C:\LumaBooth" (SET "DSLRBOOTH_DIR=C:\LumaBooth" & GOTO :dir_detected)
:dir_detected

:: ============================================================================
:: ROUTING MODE EKSEKUSI INTERNAL
:: ============================================================================
IF /I "%~1"=="WORKER"     GOTO :worker_entry
IF /I "%~1"=="BG_SINGLES" GOTO :bg_singles_entry
IF /I "%~1"=="BG_SWEEP"   GOTO :bg_sweep_entry
IF /I "%~1"=="BG_DAEMON"  GOTO :bg_daemon_entry

:: ============================================================================
:: JIKA DIKLIK 2X LANGSUNG OLEH OPERATOR (TANPA ARGUMEN DARI LUMABOOTH)
:: ============================================================================
SET "EVENT_TYPE=%~1"
IF "%EVENT_TYPE%"=="" (
    ECHO =====================================================================
    ECHO   YUHU PHOTOBOOTH - LUMABOOTH CLOUD AUTO-UPLOADER AKTIF
    ECHO =====================================================================
    ECHO   Folder Data LumaBooth : "!DSLRBOOTH_DIR!"
    ECHO   Folder Kerja Script   : "!WORK_DIR!"
    ECHO   Target Cloud Server   : !API_URL_PRIMARY!
    ECHO =====================================================================
    ECHO.
    ECHO [*] Memulai pemantauan otomatis ^& sinkronisasi foto lama/baru...
    PUSHD "%WORK_DIR%"
    START "" /B /BELOWNORMAL cmd /d /c "call "%~nx0" BG_DAEMON" >nul 2>&1
    POPD
    ECHO [V] Berhasil aktif! Semua foto yang sudah ada maupun foto baru di
    ECHO     "!DSLRBOOTH_DIR!" akan otomatis di-upload ke Cloud Portal.
    ECHO     Jendela ini akan menutup otomatis dalam 3 detik...
    ping 127.0.0.1 -n 4 >nul 2>&1
    EXIT /B 0
)

:: ============================================================================
:: FILTER EVENT TRIGGER LUMABOOTH (Keluar Instan < 1ms saat kamera memotret)
:: ============================================================================
IF /I "%EVENT_TYPE%"=="countdown"        EXIT /B 0
IF /I "%EVENT_TYPE%"=="countdown_start"  EXIT /B 0
IF /I "%EVENT_TYPE%"=="processing_start" EXIT /B 0
IF /I "%EVENT_TYPE%"=="capture_start"    EXIT /B 0

:: Saat session_start, sharing_screen, atau session_end muncul di LumaBooth,
:: jalankan pemindaian penuh di background agar foto-foto sebelumnya di project
:: yang belum sempat terupload langsung ikut masuk antrean!
IF /I "%EVENT_TYPE%"=="session_start" (
    PUSHD "%WORK_DIR%"
    START "" /B /BELOWNORMAL cmd /d /c "call "%~nx0" BG_SWEEP" >nul 2>&1
    POPD
    EXIT /B 0
)
IF /I "%EVENT_TYPE%"=="sharing_screen" (
    PUSHD "%WORK_DIR%"
    START "" /B /BELOWNORMAL cmd /d /c "call "%~nx0" BG_SWEEP" >nul 2>&1
    POPD
    EXIT /B 0
)
IF /I "%EVENT_TYPE%"=="session_end" (
    PUSHD "%WORK_DIR%"
    START "" /B /BELOWNORMAL cmd /d /c "call "%~nx0" BG_SWEEP" >nul 2>&1
    POPD
    EXIT /B 0
)

SET "FILE_PARAM=%~2"
IF "!FILE_PARAM!"=="" EXIT /B 0

:: ============================================================================
:: EVENT: file_upload ATAU printing (LumaBooth mengirimkan Full Path)
:: ============================================================================
IF /I "%EVENT_TYPE%"=="file_upload" GOTO :handle_fullpath_event
IF /I "%EVENT_TYPE%"=="printing"    GOTO :handle_fullpath_event
GOTO :check_download_event

:handle_fullpath_event
IF NOT EXIST "!FILE_PARAM!" EXIT /B 0

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

IF /I NOT "!FNAME:_mp4.jpg=!"=="!FNAME!" EXIT /B 0
IF /I NOT "!FILE_PARAM:\thumb\=!"=="!FILE_PARAM!" EXIT /B 0

:: Catat folder project yang sedang aktif dari path file ini
SET "P_MEDIA_DIR=!P_MEDIA_DIR:~0,-1!"
FOR %%P IN ("!P_MEDIA_DIR!") DO SET "P_EVENT_DIR=%%~dpP"
SET "P_EVENT_DIR=!P_EVENT_DIR:~0,-1!"
IF EXIST "!P_EVENT_DIR!" (
    > "%ACTIVE_PROJ_FILE%" ECHO !P_EVENT_DIR!
)

:: Masukkan file terbaru ini ke antrean & langsung jalankan Worker + Sweep seluruh foto lama di project ini!
>> "%QUEUE_FILE%" ECHO !FILE_PARAM!
PUSHD "%WORK_DIR%"
START "" /B /BELOWNORMAL cmd /d /c "call "%~nx0" WORKER" >nul 2>&1
START "" /B /BELOWNORMAL cmd /d /c "call "%~nx0" BG_SWEEP "!P_EVENT_DIR!"" >nul 2>&1
POPD
EXIT /B 0

:check_download_event
:: ============================================================================
:: EVENT: file_download (Kamera mengambil foto satuan -> masuk ke Singles)
:: ============================================================================
IF /I "%EVENT_TYPE%"=="file_download" (
    FOR %%X IN ("!FILE_PARAM!") DO SET "EXT=%%~xX"
    SET "VALID_EXT=0"
    FOR %%E IN (.jpg .jpeg .png) DO (
        IF /I "!EXT!"=="%%E" SET "VALID_EXT=1"
    )
    IF "!VALID_EXT!"=="0" EXIT /B 0

    PUSHD "%WORK_DIR%"
    START "" /B /BELOWNORMAL cmd /d /c "call "%~nx0" BG_SINGLES "!FILE_PARAM!"" >nul 2>&1
    POPD
    EXIT /B 0
)

EXIT /B 0


:: ============================================================================
:: SUB-MODE: BG_SINGLES (Mencari foto Singles 10 detik setelah jepretan kamera)
:: ============================================================================
:bg_singles_entry
SET "FILE_NAME=%~2"
ping 127.0.0.1 -n 11 >nul 2>&1
FOR /D %%E IN ("%DSLRBOOTH_DIR%\*") DO (
    SET "ENAME=%%~nxE"
    IF /I NOT "!ENAME!"=="Settings" IF /I NOT "!ENAME!"=="Templates" (
        IF EXIST "%%E\Singles\!FILE_NAME!" (
            > "%ACTIVE_PROJ_FILE%" ECHO %%~fE
            >> "%QUEUE_FILE%" ECHO %%E\Singles\!FILE_NAME!
            PUSHD "%WORK_DIR%"
            START "" /B /BELOWNORMAL cmd /d /c "call "%~nx0" WORKER" >nul 2>&1
            START "" /B /BELOWNORMAL cmd /d /c "call "%~nx0" BG_SWEEP "%%~fE"" >nul 2>&1
            POPD
            EXIT /B 0
        )
    )
)
EXIT /B 0


:: ============================================================================
:: SUB-MODE: BG_DAEMON (Berjalan Non-Stop di Background jika diklik 2x)
:: ============================================================================
:bg_daemon_entry
CALL :locked_daemon 8>>"%DAEMON_LOCK%"
EXIT /B 0

:locked_daemon
:daemon_loop
CALL :locked_sweep_entry
CALL :worker_entry
ping 127.0.0.1 -n 6 >nul 2>&1
GOTO :daemon_loop


:: ============================================================================
:: SUB-MODE: BG_SWEEP (Memindai SELURUH file yang belum terupload di Project)
:: ============================================================================
:bg_sweep_entry
SET "EXPLICIT_PROJECT=%~2"
ping 127.0.0.1 -n 3 >nul 2>&1
CALL :locked_sweep_entry "!EXPLICIT_PROJECT!"
PUSHD "%WORK_DIR%"
START "" /B /BELOWNORMAL cmd /d /c "call "%~nx0" WORKER" >nul 2>&1
POPD
EXIT /B 0

:locked_sweep_entry
CALL :do_sweep_scan "%~1" 7>>"%SWEEP_LOCK%"
EXIT /B 0

:do_sweep_scan
IF NOT EXIST "%SENT_LOG%" TYPE nul > "%SENT_LOG%"
IF NOT EXIST "%DSLRBOOTH_DIR%" EXIT /B 0

SET "PRIORITY_DIR=%~1"
IF "!PRIORITY_DIR!"=="" (
    IF EXIST "%ACTIVE_PROJ_FILE%" (
        SET /P PRIORITY_DIR=<"%ACTIVE_PROJ_FILE%"
    )
)

:: Cari juga project yang memiliki foto terbaru (berdasarkan timestamp nama file YYYYMMDD_HHMMSS)
:: sehingga walaupun project sudah dibuat jauh hari sebelumnya dan sudah ada foto di dalamnya
:: sebelum .bat dimasukkan ke Trigger, project tersebut otomatis terdeteksi & diupload 100%!
SET "BEST_TS="
SET "BEST_PROJ="
FOR /D %%D IN ("%DSLRBOOTH_DIR%\*") DO (
    IF /I NOT "%%~nxD"=="Settings" IF /I NOT "%%~nxD"=="Templates" (
        FOR %%M IN (Prints Animated Singles) DO (
            IF EXIST "%%D\%%M" (
                SET "TOP_FN="
                FOR /F "delims=" %%F IN ('DIR "%%D\%%M" /A-D /B /O-D 2^>nul') DO (
                    IF "!TOP_FN!"=="" (
                        SET "TOP_FN=%%F"
                        IF "!TOP_FN!" GTR "!BEST_TS!" (
                            SET "BEST_TS=!TOP_FN!"
                            SET "BEST_PROJ=%%~fD"
                        )
                    )
                )
            )
        )
    )
)

:: Jika belum ada foto sama sekali, fallback ke folder project yang paling baru dibuat
IF "!BEST_PROJ!"=="" (
    FOR /F "delims=" %%D IN ('DIR "%DSLRBOOTH_DIR%" /AD /B /O-D 2^>nul') DO (
        IF /I NOT "%%D"=="Settings" IF /I NOT "%%D"=="Templates" (
            SET "BEST_PROJ=%DSLRBOOTH_DIR%\%%D"
            GOTO :best_proj_done
        )
    )
)
:best_proj_done

:: 1. Pindai 100% file (foto lama sebelum .bat dipasang + foto baru) pada project aktif dari Trigger
IF NOT "!PRIORITY_DIR!"=="" (
    IF EXIST "!PRIORITY_DIR!" (
        CALL :sweep_single_project "!PRIORITY_DIR!"
    )
)

:: 2. Pindai 100% file pada project dengan aktivitas foto terbaru (jika berbeda)
IF NOT "!BEST_PROJ!"=="" (
    IF /I NOT "!BEST_PROJ!"=="!PRIORITY_DIR!" (
        CALL :sweep_single_project "!BEST_PROJ!"
    )
)
EXIT /B 0

:sweep_single_project
SET "SCAN_EVENT_DIR=%~1"
IF "!SCAN_EVENT_DIR!"=="" EXIT /B 0
IF NOT EXIST "!SCAN_EVENT_DIR!" EXIT /B 0

:: Urutan prioritas subfolder: Prints & Animated lebih dulu (tampil di QR), lalu Videos, GIFs, Singles
:: Tanpa batas jumlah file (seluruh foto lama maupun baru dalam project dipastikan masuk antrean!)
FOR %%M IN (Prints Animated Videos GIFs Singles) DO (
    IF EXIST "!SCAN_EVENT_DIR!\%%M" (
        FOR /F "delims=" %%F IN ('DIR "!SCAN_EVENT_DIR!\%%M" /A-D /B /O-D 2^>nul') DO (
            SET "CANDIDATE=!SCAN_EVENT_DIR!\%%M\%%F"
            SET "FN=%%F"
            IF /I "!FN:_mp4.jpg=!"=="!FN!" (
                FINDSTR /I /C:"!CANDIDATE!" "%SENT_LOG%" >nul 2>&1
                IF ERRORLEVEL 1 (
                    IF EXIST "%QUEUE_FILE%" (
                        FINDSTR /I /C:"!CANDIDATE!" "%QUEUE_FILE%" >nul 2>&1
                        IF ERRORLEVEL 1 (
                            >> "%QUEUE_FILE%" ECHO !CANDIDATE!
                        )
                    ) ELSE (
                        >> "%QUEUE_FILE%" ECHO !CANDIDATE!
                    )
                )
            )
        )
    )
)
EXIT /B 0


:: ============================================================================
:: PROSES WORKER SINGLE-INSTANCE (Hanya 1 Worker yang boleh upload di satu waktu)
:: ============================================================================
:worker_entry
CALL :locked_worker 9>>"%LOCK_FILE%"
EXIT /B 0

:locked_worker
IF NOT EXIST "%SENT_LOG%" TYPE nul > "%SENT_LOG%"

:queue_loop
IF NOT EXIST "%QUEUE_FILE%" EXIT /B 0

SET "CURRENT_FILE="
FOR /F "usebackq delims=" %%A IN ("%QUEUE_FILE%") DO (
    SET "CURRENT_FILE=%%A"
    GOTO :got_item
)
DEL /F /Q "%QUEUE_FILE%" >nul 2>&1
EXIT /B 0

:got_item
FOR /L %%i IN (1,1,5) DO IF "!CURRENT_FILE:~-1!"==" " SET "CURRENT_FILE=!CURRENT_FILE:~0,-1!"
IF "!CURRENT_FILE!"=="" GOTO :pop_queue
IF NOT EXIST "!CURRENT_FILE!" GOTO :pop_queue

:: Jika sudah pernah sukses diupload, lewati agar tidak upload ganda
FINDSTR /I /C:"!CURRENT_FILE!" "%SENT_LOG%" >nul 2>&1
IF NOT ERRORLEVEL 1 GOTO :pop_queue

FOR %%I IN ("!CURRENT_FILE!") DO (
    SET "FILE_SIZE=%%~zI"
    SET "DIR_MEDIA=%%~dpI"
)
SET "DIR_MEDIA=!DIR_MEDIA:~0,-1!"
FOR %%I IN ("!DIR_MEDIA!") DO (
    SET "MEDIA_TYPE=%%~nxI"
    SET "DIR_EVENT=%%~dpI"
)
SET "DIR_EVENT=!DIR_EVENT:~0,-1!"
FOR %%I IN ("!DIR_EVENT!") DO SET "EVENT_NAME=%%~nxI"

IF /I "!EVENT_NAME!"=="Settings"  GOTO :pop_queue
IF /I "!EVENT_NAME!"=="Templates" GOTO :pop_queue
IF /I "!MEDIA_TYPE!"=="Originals" GOTO :pop_queue
IF /I "!MEDIA_TYPE!"=="thumb"     GOTO :pop_queue

SET "FOLDER_TARGET=Prints"
IF /I "!MEDIA_TYPE!"=="Prints"   SET "FOLDER_TARGET=Prints"
IF /I "!MEDIA_TYPE!"=="Singles"  SET "FOLDER_TARGET=Singles"
IF /I "!MEDIA_TYPE!"=="Animated" SET "FOLDER_TARGET=Animated"
IF /I "!MEDIA_TYPE!"=="GIFs"     SET "FOLDER_TARGET=Animated"
IF /I "!MEDIA_TYPE!"=="Videos"   SET "FOLDER_TARGET=Animated"

:: Tunggu 1 detik sebelum upload agar penulisan file ke disk selesai 100%
ping 127.0.0.1 -n 2 >nul 2>&1

:: Kirim ke Portal Utama (files.yuhu.co.id) dan pastikan sukses (--fail)
%CURL% --fail --max-time 90 --connect-timeout 10 -s -k -X POST "!API_URL_PRIMARY!" -F "token=!API_TOKEN!" -F "client_event=!EVENT_NAME!" -F "folder_target=!FOLDER_TARGET!" -F "file_size=!FILE_SIZE!" -F "file=@!CURRENT_FILE!" >nul 2>&1
IF !ERRORLEVEL! EQU 0 (
    >> "%SENT_LOG%" ECHO !CURRENT_FILE!
    > "%LOG_FILE%" ECHO [%DATE% %TIME%] SUKSES UPLOAD KE FILES.YUHU.CO.ID: !EVENT_NAME!/!FOLDER_TARGET! - !CURRENT_FILE!
    %CURL% --max-time 45 --connect-timeout 8 -s -k -X POST "!API_URL_FALLBACK!" -F "token=!API_TOKEN!" -F "client_event=!EVENT_NAME!" -F "folder_target=!FOLDER_TARGET!" -F "file=@!CURRENT_FILE!" >nul 2>&1
    GOTO :pop_queue
)

:: Jika server utama belum merespons 200, coba server fallback (qr.yuhu.co.id)
%CURL% --fail --max-time 90 --connect-timeout 10 -s -k -X POST "!API_URL_FALLBACK!" -F "token=!API_TOKEN!" -F "client_event=!EVENT_NAME!" -F "folder_target=!FOLDER_TARGET!" -F "file_size=!FILE_SIZE!" -F "file=@!CURRENT_FILE!" >nul 2>&1
IF !ERRORLEVEL! EQU 0 (
    >> "%SENT_LOG%" ECHO !CURRENT_FILE!
    > "%LOG_FILE%" ECHO [%DATE% %TIME%] SUKSES UPLOAD KE QR.YUHU.CO.ID: !EVENT_NAME!/!FOLDER_TARGET! - !CURRENT_FILE!
    GOTO :pop_queue
)

:: Jika internet sedang mati / gagal upload, JANGAN hapus dari antrean!
> "%LOG_FILE%" ECHO [%DATE% %TIME%] MENUNGGU INTERNET (RETRY OTOMATIS): !CURRENT_FILE!
ping 127.0.0.1 -n 7 >nul 2>&1
GOTO :queue_loop

:pop_queue
more +1 "%QUEUE_FILE%" > "%WORK_DIR%temp_queue.txt" 2>nul
MOVE /Y "%WORK_DIR%temp_queue.txt" "%QUEUE_FILE%" >nul 2>&1
GOTO :queue_loop
