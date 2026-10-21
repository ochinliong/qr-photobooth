@echo off
SETLOCAL EnableDelayedExpansion

:: ============================================================================
:: YUHU PHOTOBOOTH - LUMABOOTH WINDOWS (OKTOBER 2026) UNIVERSAL SYNC & TRIGGER
:: VERSI: YUHU v10.bat (APPDATA EVENT DETECTOR + INSTANT LAUNCH-EVENT BACKFILL)
:: ============================================================================
:: Mendukung 2 Cara Kerja Sekaligus (Di Komputer Mana Saja):
:: 1. Dipanggil Otomatis oleh LumaBooth Trigger (Settings -> Triggers -> Application)
:: 2. Diklik 2x Langsung oleh Operator (Otomatis jalan di belakang layar tanpa
::    jendela mengganggu, memantau & mengupload seluruh foto lama & baru!)
::
:: PERBAIKAN PENTING DI v10:
:: 1. Membaca langsung konfigurasi internal LumaBooth:
::    - %APPDATA%\dslrBooth\app_settings_2021.json ("ImagesPath" & "EventId")
::    - %APPDATA%\dslrBooth\EventSettings\*.lcfg ("MediaDirectoryName")
::    Sehingga di komputer mana pun LumaBooth diinstal, script 100% tahu persis
::    lokasi penyimpanan dan nama folder project yang sedang dibuka saat ini!
:: 2. Langsung jalan saat klik "Launch Event" (Start Screen):
::    Begitu .bat dimasukkan ke Trigger lalu klik Launch Event (bahkan TANPA
::    perlu memotret foto baru!), seluruh foto yang sudah ada di dalam project
::    tersebut langsung otomatis terupload ke Cloud!
:: 3. Perbaikan Subshell FOR /F (%~1), Multi-Project Append (>>), Anti-Stuck
::    Worker Lock, dan Instant Silent Launcher (< 20ms).
:: ============================================================================

SET "API_URL_PRIMARY=https://files.yuhu.co.id/client/api_upload.php"
SET "API_URL_FALLBACK=https://qr.yuhu.co.id/client/api_upload.php"
SET "API_TOKEN=YuhuSecretToken2026"

SET "CURL=%SystemRoot%\System32\curl.exe"
IF NOT EXIST "%CURL%" SET "CURL=curl.exe"

SET "WSCRIPT=%SystemRoot%\System32\wscript.exe"
IF NOT EXIST "%WSCRIPT%" SET "WSCRIPT=wscript.exe"

SET "SELF=%~f0"
SET "WORK_DIR=%~dp0"
SET "SILENT_VBS=%WORK_DIR%silent_runner_v10.vbs"
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

:: Jika folder script dipindahkan/dicopy dari komputer lain, otomatis bersihkan cache PC lama!
SET "SAVED_PC="
IF EXIST "%PC_MARKER%" SET /P SAVED_PC=<"%PC_MARKER%"
IF /I NOT "!SAVED_PC!"=="%COMPUTERNAME%" (
    > "%PC_MARKER%" ECHO %COMPUTERNAME%
    DEL /F /Q "%BATCH_FILE%" "%QUEUE_FILE%" "%PRIORITY_QUEUE%" "%ACTIVE_PROJ_FILE%" "%WORK_DIR%last_batch_project.txt" >nul 2>&1
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

:: Fallback jika EventId belum ter-update atau foldernya tidak ada: cek file .lcfg terbaru yang foldernya ada!
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
    ECHO   YUHU PHOTOBOOTH v10 - LUMABOOTH CLOUD AUTO-UPLOADER AKTIF
    ECHO =====================================================================
    ECHO   Folder Data LumaBooth : "!DSLRBOOTH_DIR!"
    ECHO   Project Aktif Saat Ini: "!LB_ACTIVE_DIR!"
    ECHO   Target Cloud Server   : !API_URL_PRIMARY!
    ECHO =====================================================================
    ECHO.
    ECHO [*] Memulai sinkronisasi seluruh foto yang sudah ada ^& foto baru...
    CALL :launch_detached BG_SWEEP "!LB_ACTIVE_DIR!"
    CALL :launch_detached BG_DAEMON ""
    ECHO [V] Berhasil aktif! Seluruh foto yang sudah ada di dalam project
    ECHO     sedang di-upload otomatis di latar belakang sampai 100%% tuntas.
    ECHO     Jendela ini akan menutup otomatis dalam 3 detik...
    ping 127.0.0.1 -n 4 >nul 2>&1
    EXIT /B 0
)

:: ============================================================================
:: FILTER EVENT TRIGGER LUMABOOTH
:: ============================================================================
:: Abaikan HANYA saat kamera sedang hitung mundur / memotret agar kamera 100% mulus
IF /I "%EVENT_TYPE%"=="countdown"        EXIT /B 0
IF /I "%EVENT_TYPE%"=="countdown_start"  EXIT /B 0
IF /I "%EVENT_TYPE%"=="processing_start" EXIT /B 0
IF /I "%EVENT_TYPE%"=="capture_start"    EXIT /B 0

SET "FILE_PARAM=%~2"

:: Jika event adalah file_upload atau printing (ada path file di %~2)
IF /I "%EVENT_TYPE%"=="file_upload" IF NOT "!FILE_PARAM!"=="" GOTO :handle_fullpath_event
IF /I "%EVENT_TYPE%"=="printing"    IF NOT "!FILE_PARAM!"=="" GOTO :handle_fullpath_event

:: Jika event adalah file_download (kamera mengambil foto satuan -> masuk ke Singles)
IF /I "%EVENT_TYPE%"=="file_download" IF NOT "!FILE_PARAM!"=="" GOTO :check_download_event

:: Untuk SEMUA event lainnya dari LumaBooth (termasuk saat klik "Launch Event" / Start Screen,
:: session_start, sharing_screen, session_end, cancel_session, dll):
:: Langsung jalankan BG_SWEEP + BG_DAEMON agar foto-foto yang sudah ada di project sebelumnya
:: langsung otomatis terupload walaupun belum memotret foto baru!
CALL :launch_detached BG_SWEEP "!LB_ACTIVE_DIR!"
CALL :launch_detached BG_DAEMON ""
EXIT /B 0


:handle_fullpath_event
IF NOT EXIST "!FILE_PARAM!" (
    CALL :launch_detached BG_SWEEP "!LB_ACTIVE_DIR!"
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

IF /I NOT "!FNAME:_mp4.jpg=!"=="!FNAME!" EXIT /B 0
IF /I NOT "!FILE_PARAM:\thumb\=!"=="!FILE_PARAM!" EXIT /B 0

:: Catat folder project yang sedang aktif dari path file ini
SET "P_MEDIA_DIR=!P_MEDIA_DIR:~0,-1!"
FOR %%P IN ("!P_MEDIA_DIR!") DO SET "P_EVENT_DIR=%%~dpP"
SET "P_EVENT_DIR=!P_EVENT_DIR:~0,-1!"
IF EXIST "!P_EVENT_DIR!" (
    > "%ACTIVE_PROJ_FILE%" ECHO !P_EVENT_DIR!
)

:: Masukkan file terbaru ini ke PRIORITY_QUEUE (Urutan #1) + jalankan Worker & Sweep project ini!
>> "%PRIORITY_QUEUE%" ECHO !FILE_PARAM!
CALL :launch_detached WORKER ""
CALL :launch_detached BG_SWEEP "!P_EVENT_DIR!"
CALL :launch_detached BG_DAEMON ""
EXIT /B 0

:check_download_event
FOR %%X IN ("!FILE_PARAM!") DO SET "EXT=%%~xX"
SET "VALID_EXT=0"
FOR %%E IN (.jpg .jpeg .png) DO (
    IF /I "!EXT!"=="%%E" SET "VALID_EXT=1"
)
IF "!VALID_EXT!"=="0" EXIT /B 0

CALL :launch_detached BG_SINGLES "!FILE_PARAM!"
CALL :launch_detached BG_DAEMON ""
EXIT /B 0


:: ============================================================================
:: HELPER: UNQUOTE JSON & LAUNCH DETACHED BACKGROUND PROCESS (Full Path %SELF%)
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


:: ============================================================================
:: SUB-MODE: BG_SINGLES (Mencari foto Singles 10 detik setelah jepretan kamera)
:: ============================================================================
:bg_singles_entry
SET "FILE_NAME=%~2"
ping 127.0.0.1 -n 11 >nul 2>&1
IF NOT "!LB_ACTIVE_DIR!"=="" (
    IF EXIST "!LB_ACTIVE_DIR!\Singles\!FILE_NAME!" (
        >> "%PRIORITY_QUEUE%" ECHO !LB_ACTIVE_DIR!\Singles\!FILE_NAME!
        CALL :launch_detached WORKER ""
        CALL :launch_detached BG_SWEEP "!LB_ACTIVE_DIR!"
        EXIT /B 0
    )
)
FOR /D %%E IN ("%DSLRBOOTH_DIR%\*") DO (
    SET "ENAME=%%~nxE"
    IF /I NOT "!ENAME!"=="Settings" IF /I NOT "!ENAME!"=="Templates" (
        IF EXIST "%%E\Singles\!FILE_NAME!" (
            > "%ACTIVE_PROJ_FILE%" ECHO %%~fE
            >> "%PRIORITY_QUEUE%" ECHO %%E\Singles\!FILE_NAME!
            CALL :launch_detached WORKER ""
            CALL :launch_detached BG_SWEEP "%%~fE"
            EXIT /B 0
        )
    )
)
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
:: SUB-MODE: BG_SWEEP (Memindai 100% File di Project Aktif & Semua Project)
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

:: Jika project aktif berubah (misal operator baru saja membuka project lain di LumaBooth),
:: prioritaskan project yang sedang dibuka sekarang ke urutan paling atas!
SET "LAST_BATCH_PROJ="
IF EXIST "%WORK_DIR%last_batch_project.txt" (
    SET /P LAST_BATCH_PROJ=<"%WORK_DIR%last_batch_project.txt"
)

IF NOT "!PRIORITY_DIR!"=="" (
    IF /I NOT "!PRIORITY_DIR!"=="!LAST_BATCH_PROJ!" (
        > "%WORK_DIR%last_batch_project.txt" ECHO !PRIORITY_DIR!
        DEL /F /Q "%BATCH_FILE%" >nul 2>&1
        DEL /F /Q "%QUEUE_FILE%" >nul 2>&1
    )
)

:: Jika BATCH_FILE masih sedang dikerjakan untuk project yang sama, biarkan Worker menyelesaikannya
IF EXIST "%BATCH_FILE%" EXIT /B 0

SET "RAW_LIST=%WORK_DIR%raw_scan.tmp"
DEL /F /Q "%RAW_LIST%" >nul 2>&1

:: 1. Urutan Pertama: Pindai 100% foto/video di project yang sedang aktif di LumaBooth (!PRIORITY_DIR!)
IF NOT "!PRIORITY_DIR!"=="" (
    IF EXIST "!PRIORITY_DIR!" (
        CALL :append_project_files "!PRIORITY_DIR!"
    )
)

:: 2. Urutan Kedua: Pindai juga project dari AppData LumaBooth (!LB_ACTIVE_DIR!) jika berbeda
IF NOT "!LB_ACTIVE_DIR!"=="" (
    IF /I NOT "!LB_ACTIVE_DIR!"=="!PRIORITY_DIR!" (
        IF EXIST "!LB_ACTIVE_DIR!" (
            CALL :append_project_files "!LB_ACTIVE_DIR!"
        )
    )
)

:: 3. Urutan Ketiga: Pindai seluruh folder project lain di dalam DSLRBOOTH_DIR
::    Sehingga project mana pun yang sudah ada fotonya dipastikan 100% terupload!
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

:: Saring keluar file thumbnail _mp4.jpg dalam 1 perintah instan
FINDSTR /V /I "_mp4.jpg" "%RAW_LIST%" > "%QUEUE_FILE%" 2>nul
DEL /F /Q "%RAW_LIST%" >nul 2>&1
EXIT /B 0

:append_project_files
IF "%~1"=="" EXIT /B 0
IF NOT EXIST "%~1" EXIT /B 0

:: PENTING: Gunakan %~1 (bukan !VAR!) di dalam FOR /F ('DIR ...') karena subshell cmd.exe /c
:: tidak mengaktifkan DelayedExpansion secara default!
:: Gunakan >> "%RAW_LIST%" (APPEND) agar semua project tergabung tanpa saling menimpa!
(
    IF EXIST "%~1\Prints" (
        FOR /F "delims=" %%F IN ('DIR "%~1\Prints" /A-D /B /O-D 2^>nul') DO (
            ECHO %~1\Prints\%%F
        )
    )
    IF EXIST "%~1\Animated" (
        FOR /F "delims=" %%F IN ('DIR "%~1\Animated" /A-D /B /O-D 2^>nul') DO (
            ECHO %~1\Animated\%%F
        )
    )
    IF EXIST "%~1\Videos" (
        FOR /F "delims=" %%F IN ('DIR "%~1\Videos" /A-D /B /O-D 2^>nul') DO (
            ECHO %~1\Videos\%%F
        )
    )
    IF EXIST "%~1\GIFs" (
        FOR /F "delims=" %%F IN ('DIR "%~1\GIFs" /A-D /B /O-D 2^>nul') DO (
            ECHO %~1\GIFs\%%F
        )
    )
    IF EXIST "%~1\Singles" (
        FOR /F "delims=" %%F IN ('DIR "%~1\Singles" /A-D /B /O-D 2^>nul') DO (
            ECHO %~1\Singles\%%F
        )
    )
) >> "%RAW_LIST%" 2>nul
EXIT /B 0


:: ============================================================================
:: PROSES WORKER SINGLE-INSTANCE (Tanpa "more +1" -> Anti Macet untuk 5.000+ File!)
:: ============================================================================
:worker_entry
CALL :locked_worker 9>>"%LOCK_FILE%"
EXIT /B 0

:locked_worker
IF NOT EXIST "%SENT_LOG%" TYPE nul > "%SENT_LOG%"

:worker_main_loop
:: 1. Utamakan foto terbaru di PRIORITY_QUEUE (Tamu yang baru selesai berfoto)
IF EXIST "%PRIORITY_QUEUE%" (
    CALL :drain_priority_queue
)

:: 2. Proses antrean utama (BATCH_FILE / QUEUE_FILE)
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
    :: Sebelum upload tiap file lama, cek apakah ada foto baru di PRIORITY_QUEUE!
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

FOR %%I IN ("!CURRENT_FILE!") DO (
    SET "FILE_SIZE=%%~zI"
    SET "DIR_MEDIA=%%~dpI"
    SET "FNAME_ONLY=%%~nxI"
)
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
IF /I "!MEDIA_TYPE!"=="thumb"     EXIT /B 0

SET "FOLDER_TARGET=Prints"
IF /I "!MEDIA_TYPE!"=="Prints"   SET "FOLDER_TARGET=Prints"
IF /I "!MEDIA_TYPE!"=="Singles"  SET "FOLDER_TARGET=Singles"
IF /I "!MEDIA_TYPE!"=="Animated" SET "FOLDER_TARGET=Animated"
IF /I "!MEDIA_TYPE!"=="GIFs"     SET "FOLDER_TARGET=Animated"
IF /I "!MEDIA_TYPE!"=="Videos"   SET "FOLDER_TARGET=Animated"

:: Hanya beri jeda 1 detik jika ini adalah jepretan baru (agar file selesai ditulis LumaBooth)
IF "!IS_NEW_CAPTURE!"=="1" (
    ping 127.0.0.1 -n 2 >nul 2>&1
)

SET "RETRY_COUNT=0"
:retry_upload
IF NOT EXIST "!CURRENT_FILE!" EXIT /B 0

%CURL% --fail --max-time 90 --connect-timeout 10 -s -k -X POST "!API_URL_PRIMARY!" -F "token=!API_TOKEN!" -F "client_event=!EVENT_NAME!" -F "folder_target=!FOLDER_TARGET!" -F "file_size=!FILE_SIZE!" -F "file=@!CURRENT_FILE!" >nul 2>&1
IF !ERRORLEVEL! EQU 0 (
    >> "%SENT_LOG%" ECHO !CURRENT_FILE!
    > "%LOG_FILE%" ECHO [%DATE% %TIME%] SUKSES UPLOAD KE FILES.YUHU.CO.ID: !EVENT_NAME!/!FOLDER_TARGET! - !CURRENT_FILE!
    EXIT /B 0
)

%CURL% --fail --max-time 90 --connect-timeout 10 -s -k -X POST "!API_URL_FALLBACK!" -F "token=!API_TOKEN!" -F "client_event=!EVENT_NAME!" -F "folder_target=!FOLDER_TARGET!" -F "file_size=!FILE_SIZE!" -F "file=@!CURRENT_FILE!" >nul 2>&1
IF !ERRORLEVEL! EQU 0 (
    >> "%SENT_LOG%" ECHO !CURRENT_FILE!
    > "%LOG_FILE%" ECHO [%DATE% %TIME%] SUKSES UPLOAD KE QR.YUHU.CO.ID: !EVENT_NAME!/!FOLDER_TARGET! - !CURRENT_FILE!
    EXIT /B 0
)

SET /A RETRY_COUNT+=1
IF !RETRY_COUNT! GEQ 3 (
    > "%LOG_FILE%" ECHO [%DATE% %TIME%] TUNDA KE SIKLUS BERIKUTNYA: !CURRENT_FILE!
    EXIT /B 0
)

:: Jika internet sedang terputus, tunggu 3 detik lalu ulangi
> "%LOG_FILE%" ECHO [%DATE% %TIME%] MENUNGGU INTERNET (RETRY !RETRY_COUNT!/3): !CURRENT_FILE!
ping 127.0.0.1 -n 4 >nul 2>&1
GOTO :retry_upload
