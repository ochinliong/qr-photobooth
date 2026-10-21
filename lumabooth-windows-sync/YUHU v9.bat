@echo off
SETLOCAL EnableDelayedExpansion

:: ============================================================================
:: YUHU PHOTOBOOTH - LUMABOOTH WINDOWS (OKTOBER 2026) UNIVERSAL SYNC & TRIGGER
:: VERSI: YUHU v9.bat (DETACHED SILENT ENGINE + TURBO BULK BACKFILL)
:: ============================================================================
:: Mendukung 2 Cara Kerja Sekaligus (Di Komputer Mana Saja):
:: 1. Dipanggil Otomatis oleh LumaBooth Trigger (Settings -> Triggers -> Application)
:: 2. Diklik 2x Langsung oleh Operator (Otomatis jalan di belakang layar tanpa
::    jendela mengganggu, memantau & mengupload seluruh foto lama & baru!)
::
:: PERBAIKAN PENTING DI v9:
:: - Detached Silent Engine (wscript SW_HIDE): 100% terlepas dari proses LumaBooth
::   sehingga worker TIDAK AKAN MATI di tengah jalan saat mengupload ratusan/ribuan
::   foto lama, dan kembali ke LumaBooth dalam < 0.05 detik tanpa layar hitam!
:: - Bebas macet "more +1" saat antrean > 40 file (Sanggup upload 5.000+ file!)
:: - Pemindaian ribuan foto lama < 0.1 detik (Tanpa bottleneck FINDSTR di awal)
:: - Sistem Dual-Queue (Priority Queue + Bulk Queue): Foto tamu yang baru foto
::   langsung diselipkan ke urutan #1 walaupun sedang upload ratusan foto lama!
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
SET "SILENT_VBS=%WORK_DIR%silent_runner.vbs"
SET "PRIORITY_QUEUE=%WORK_DIR%priority_queue.txt"
SET "QUEUE_FILE=%WORK_DIR%upload_queue.txt"
SET "BATCH_FILE=%WORK_DIR%active_batch.txt"
SET "SENT_LOG=%WORK_DIR%sent_history.txt"
SET "ACTIVE_PROJ_FILE=%WORK_DIR%active_project.txt"
SET "LOCK_FILE=%WORK_DIR%worker.lock"
SET "SWEEP_LOCK=%WORK_DIR%sweep.lock"
SET "DAEMON_LOCK=%WORK_DIR%daemon.lock"
SET "LOG_FILE=%WORK_DIR%last_upload.log"

:: Auto-generate VBScript launcher (WMI Win32_Process + Fallback WScript.Shell)
:: agar proses background 100% terlepas dari Job Object LumaBooth dan berjalan tanpa jendela
IF NOT EXIST "%SILENT_VBS%" (
    (
        ECHO On Error Resume Next
        ECHO Set wmi = GetObject("winmgmts:{impersonationLevel=impersonate}!\\.\root\cimv2"^)
        ECHO Set st = wmi.Get("Win32_ProcessStartup"^).SpawnInstance_
        ECHO st.ShowWindow = 0
        ECHO st.PriorityClass = 16384
        ECHO Set pr = wmi.Get("Win32_Process"^)
        ECHO cmd = "cmd.exe /d /c ""call """ ^& WScript.Arguments(1^) ^& """ " ^& WScript.Arguments(2^)
        ECHO If WScript.Arguments.Count ^> 3 Then cmd = cmd ^& " """ ^& WScript.Arguments(3^) ^& """"
        ECHO cmd = cmd ^& """"
        ECHO rc = pr.Create(cmd, WScript.Arguments(0^), st, pid^)
        ECHO If Err.Number ^<^> 0 Or rc ^<^> 0 Then
        ECHO     Set sh = CreateObject("WScript.Shell"^)
        ECHO     sh.CurrentDirectory = WScript.Arguments(0^)
        ECHO     sh.Run cmd, 0, False
        ECHO End If
    ) > "%SILENT_VBS%"
)

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
    ECHO   YUHU PHOTOBOOTH v9 - LUMABOOTH CLOUD AUTO-UPLOADER AKTIF
    ECHO =====================================================================
    ECHO   Folder Data LumaBooth : "!DSLRBOOTH_DIR!"
    ECHO   Folder Kerja Script   : "!WORK_DIR!"
    ECHO   Target Cloud Server   : !API_URL_PRIMARY!
    ECHO =====================================================================
    ECHO.
    ECHO [*] Memulai sinkronisasi seluruh foto yang sudah ada ^& foto baru...
    CALL :launch_detached BG_DAEMON ""
    ECHO [V] Berhasil aktif! Seluruh foto di project aktif "!DSLRBOOTH_DIR!"
    ECHO     sedang di-upload otomatis di latar belakang sampai 100%% tuntas.
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
:: pindai seluruh foto di project aktif agar foto lama langsung ikut terupload!
IF /I "%EVENT_TYPE%"=="session_start" (
    CALL :launch_detached BG_SWEEP ""
    EXIT /B 0
)
IF /I "%EVENT_TYPE%"=="sharing_screen" (
    CALL :launch_detached BG_SWEEP ""
    EXIT /B 0
)
IF /I "%EVENT_TYPE%"=="session_end" (
    CALL :launch_detached BG_SWEEP ""
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

:: Masukkan file terbaru ini ke PRIORITY_QUEUE (Urutan #1) + jalankan Worker & Sweep project ini!
>> "%PRIORITY_QUEUE%" ECHO !FILE_PARAM!
CALL :launch_detached WORKER ""
CALL :launch_detached BG_SWEEP "!P_EVENT_DIR!"
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

    CALL :launch_detached BG_SINGLES "!FILE_PARAM!"
    EXIT /B 0
)

EXIT /B 0


:: ============================================================================
:: HELPER: LAUNCH DETACHED BACKGROUND PROCESS (100% Terlepas dari LumaBooth)
:: ============================================================================
:launch_detached
SET "MODE_ARG=%~1"
SET "EXTRA_ARG=%~2"
IF EXIST "%SILENT_VBS%" (
    IF "!EXTRA_ARG!"=="" (
        "%WSCRIPT%" //B //Nologo "%SILENT_VBS%" "%WORK_DIR:~0,-1%" "%~nx0" "!MODE_ARG!" >nul 2>&1
    ) ELSE (
        "%WSCRIPT%" //B //Nologo "%SILENT_VBS%" "%WORK_DIR:~0,-1%" "%~nx0" "!MODE_ARG!" "!EXTRA_ARG!" >nul 2>&1
    )
) ELSE (
    PUSHD "%WORK_DIR%"
    START "" /B /BELOWNORMAL cmd /d /c "call "%~nx0" !MODE_ARG! "!EXTRA_ARG!"" >nul 2>&1
    POPD
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
            >> "%PRIORITY_QUEUE%" ECHO %%E\Singles\!FILE_NAME!
            CALL :launch_detached WORKER ""
            CALL :launch_detached BG_SWEEP "%%~fE"
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
CALL :locked_sweep_entry ""
CALL :worker_entry
ping 127.0.0.1 -n 6 >nul 2>&1
GOTO :daemon_loop


:: ============================================================================
:: SUB-MODE: BG_SWEEP (Memindai 100% File di Project secara Instan < 0.1 Detik)
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
    IF EXIST "%ACTIVE_PROJ_FILE%" (
        SET /P PRIORITY_DIR=<"%ACTIVE_PROJ_FILE%"
    )
)

:: Cari project yang memiliki foto terbaru (berdasarkan timestamp nama file YYYYMMDD_HHMMSS)
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

IF "!BEST_PROJ!"=="" (
    FOR /F "delims=" %%D IN ('DIR "%DSLRBOOTH_DIR%" /AD /B /O-D 2^>nul') DO (
        IF /I NOT "%%D"=="Settings" IF /I NOT "%%D"=="Templates" (
            SET "BEST_PROJ=%DSLRBOOTH_DIR%\%%D"
            GOTO :best_proj_done
        )
    )
)
:best_proj_done

IF NOT "!PRIORITY_DIR!"=="" (
    IF EXIST "!PRIORITY_DIR!" (
        CALL :sweep_single_project "!PRIORITY_DIR!"
    )
)

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

:: Jika Worker sedang aktif memproses BATCH_FILE, tidak perlu menulis ulang daftar ribuan file
IF EXIST "%BATCH_FILE%" EXIT /B 0

SET "RAW_LIST=%WORK_DIR%raw_scan.tmp"
DEL /F /Q "%RAW_LIST%" >nul 2>&1

:: Kumpulkan 100% file (Prints & Animated dulu agar langsung tampil di QR, lalu Videos, GIFs, Singles)
(
    IF EXIST "!SCAN_EVENT_DIR!\Prints" (
        FOR /F "delims=" %%F IN ('DIR "!SCAN_EVENT_DIR!\Prints" /A-D /B /O-D 2^>nul') DO (
            ECHO !SCAN_EVENT_DIR!\Prints\%%F
        )
    )
    IF EXIST "!SCAN_EVENT_DIR!\Animated" (
        FOR /F "delims=" %%F IN ('DIR "!SCAN_EVENT_DIR!\Animated" /A-D /B /O-D 2^>nul') DO (
            ECHO !SCAN_EVENT_DIR!\Animated\%%F
        )
    )
    IF EXIST "!SCAN_EVENT_DIR!\Videos" (
        FOR /F "delims=" %%F IN ('DIR "!SCAN_EVENT_DIR!\Videos" /A-D /B /O-D 2^>nul') DO (
            ECHO !SCAN_EVENT_DIR!\Videos\%%F
        )
    )
    IF EXIST "!SCAN_EVENT_DIR!\GIFs" (
        FOR /F "delims=" %%F IN ('DIR "!SCAN_EVENT_DIR!\GIFs" /A-D /B /O-D 2^>nul') DO (
            ECHO !SCAN_EVENT_DIR!\GIFs\%%F
        )
    )
    IF EXIST "!SCAN_EVENT_DIR!\Singles" (
        FOR /F "delims=" %%F IN ('DIR "!SCAN_EVENT_DIR!\Singles" /A-D /B /O-D 2^>nul') DO (
            ECHO !SCAN_EVENT_DIR!\Singles\%%F
        )
    )
) > "%RAW_LIST%" 2>nul

IF NOT EXIST "%RAW_LIST%" EXIT /B 0

:: Saring keluar file thumbnail _mp4.jpg dalam 1 perintah instan (< 0.02 detik)
FINDSTR /V /I "_mp4.jpg" "%RAW_LIST%" > "%QUEUE_FILE%" 2>nul
DEL /F /Q "%RAW_LIST%" >nul 2>&1
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

:retry_upload
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

:: Jika internet sedang terputus, tunggu 5 detik lalu ulangi sampai berhasil
> "%LOG_FILE%" ECHO [%DATE% %TIME%] MENUNGGU INTERNET (RETRY OTOMATIS): !CURRENT_FILE!
ping 127.0.0.1 -n 6 >nul 2>&1
GOTO :retry_upload
