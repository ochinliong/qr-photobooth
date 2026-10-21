@echo off
SETLOCAL EnableDelayedExpansion

:: ============================================================================
:: YUHU PHOTOBOOTH - LUMABOOTH WINDOWS (OKTOBER 2026) NATIVE TRIGGER v5
:: ============================================================================
:: Dipasang langsung pada LumaBooth -> Settings -> Triggers -> Application:
::   C:\YUHU\YUHU v5.bat
::   Data Directory: C:\dslrBooth
:: ============================================================================

SET "API_URL_PRIMARY=https://files.yuhu.co.id/client/api_upload.php"
SET "API_URL_FALLBACK=https://qr.yuhu.co.id/client/api_upload.php"

SET "API_TOKEN=YuhuSecretToken2026"
SET "DSLRBOOTH_DIR=C:\dslrBooth"
SET "CURL=%SystemRoot%\System32\curl.exe"
IF NOT EXIST "%CURL%" SET "CURL=curl.exe"
SET "SELF=%~f0"
SET "WORK_DIR=%~dp0"
SET "QUEUE_FILE=%WORK_DIR%upload_queue.txt"
SET "SENT_LOG=%WORK_DIR%sent_history.txt"
SET "LOCK_FILE=%WORK_DIR%worker.lock"
SET "LOG_FILE=%WORK_DIR%last_upload.log"

:: ============================================================================
:: MODE 1: BACKGROUND WORKER (Memproses Antrean Upload Satu per Satu)
:: ============================================================================
IF /I "%~1"=="WORKER" GOTO :worker_entry

:: ============================================================================
:: MODE 2: DELAYED SINGLES FINDER (Menunggu LumaBooth selesai menulis Singles)
:: ============================================================================
IF /I "%~1"=="BG_SINGLES" (
    SET "FILE_NAME=%~2"
    ping 127.0.0.1 -n 11 >nul 2>&1
    FOR /D %%E IN ("%DSLRBOOTH_DIR%\*") DO (
        SET "ENAME=%%~nxE"
        IF /I NOT "!ENAME!"=="Settings" IF /I NOT "!ENAME!"=="Templates" (
            IF EXIST "%%E\Singles\!FILE_NAME!" (
                >> "%QUEUE_FILE%" ECHO %%E\Singles\!FILE_NAME!
                START "" /B /BELOWNORMAL cmd /c ""!SELF!" WORKER" >nul 2>&1
                EXIT /B 0
            )
        )
    )
    EXIT /B 0
)

:: ============================================================================
:: MODE 3: SESSION SWEEPER (Memindai file sesi terbaru di C:\dslrBooth)
:: Berjalan otomatis saat session_end / sharing_screen walaupun Fotoshare OFF!
:: ============================================================================
IF /I "%~1"=="BG_SWEEP" (
    ping 127.0.0.1 -n 5 >nul 2>&1
    IF NOT EXIST "%SENT_LOG%" TYPE nul > "%SENT_LOG%"

    :: Cari folder event paling baru yang sedang aktif di C:\dslrBooth
    SET "LATEST_EVENT_DIR="
    FOR /F "delims=" %%D IN ('DIR "%DSLRBOOTH_DIR%" /AD /B /O-D 2^>nul') DO (
        IF /I NOT "%%D"=="Settings" IF /I NOT "%%D"=="Templates" (
            SET "LATEST_EVENT_DIR=%DSLRBOOTH_DIR%\%%D"
            GOTO :sweep_event_found
        )
    )
    EXIT /B 0

    :sweep_event_found
    :: Ambil 10 file terbaru dari Prints, Animated, Videos, GIFs, dan Singles
    FOR %%M IN (Prints Animated Videos GIFs Singles) DO (
        IF EXIST "!LATEST_EVENT_DIR!\%%M" (
            SET "COUNT_M=0"
            FOR /F "delims=" %%F IN ('DIR "!LATEST_EVENT_DIR!\%%M" /A-D /B /O-D 2^>nul') DO (
                SET /A COUNT_M+=1
                IF !COUNT_M! LEQ 10 (
                    SET "CANDIDATE=!LATEST_EVENT_DIR!\%%M\%%F"
                    :: Abaikan file _mp4.jpg
                    SET "FN=%%F"
                    IF /I "!FN:_mp4.jpg=!"=="!FN!" (
                        FINDSTR /I /C:"!CANDIDATE!" "%SENT_LOG%" >nul 2>&1
                        IF ERRORLEVEL 1 (
                            >> "%QUEUE_FILE%" ECHO !CANDIDATE!
                        )
                    )
                )
            )
        )
    )
    START "" /B /BELOWNORMAL cmd /c ""!SELF!" WORKER" >nul 2>&1
    EXIT /B 0
)

:: ============================================================================
:: MODE 4: STARTUP / MANUAL RUN (Jika diklik 2x langsung oleh operator)
:: ============================================================================
SET "EVENT_TYPE=%~1"
IF "%EVENT_TYPE%"=="" (
    START "" /B /BELOWNORMAL cmd /c ""!SELF!" BG_SWEEP" >nul 2>&1
    EXIT /B 0
)

:: ============================================================================
:: FILTER EVENT LUMABOOTH YANG TIDAK RELEVAN (Keluar Instan < 1ms)
:: ============================================================================
IF /I "%EVENT_TYPE%"=="countdown"        EXIT /B 0
IF /I "%EVENT_TYPE%"=="countdown_start"  EXIT /B 0
IF /I "%EVENT_TYPE%"=="session_start"    EXIT /B 0
IF /I "%EVENT_TYPE%"=="processing_start" EXIT /B 0
IF /I "%EVENT_TYPE%"=="capture_start"    EXIT /B 0

:: Saat sharing_screen atau session_end muncul, jalankan pemindai sesi terbaru (BG_SWEEP)
IF /I "%EVENT_TYPE%"=="sharing_screen" (
    START "" /B /BELOWNORMAL cmd /c ""!SELF!" BG_SWEEP" >nul 2>&1
    EXIT /B 0
)
IF /I "%EVENT_TYPE%"=="session_end" (
    START "" /B /BELOWNORMAL cmd /c ""!SELF!" BG_SWEEP" >nul 2>&1
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
)
SET "VALID_EXT=0"
FOR %%E IN (.jpg .jpeg .png .gif .mp4 .mov .webm) DO (
    IF /I "!EXT!"=="%%E" SET "VALID_EXT=1"
)
IF "!VALID_EXT!"=="0" EXIT /B 0

IF /I NOT "!FNAME:_mp4.jpg=!"=="!FNAME!" EXIT /B 0
IF /I NOT "!FILE_PARAM:\thumb\=!"=="!FILE_PARAM!" EXIT /B 0

>> "%QUEUE_FILE%" ECHO !FILE_PARAM!
START "" /B /BELOWNORMAL cmd /c ""!SELF!" WORKER" >nul 2>&1
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

    START "" /B /BELOWNORMAL cmd /c ""!SELF!" BG_SINGLES "!FILE_PARAM!"" >nul 2>&1
    EXIT /B 0
)

EXIT /B 0


:: ============================================================================
:: PROSES WORKER SINGLE-INSTANCE (Hanya 1 Worker yang boleh jalan di satu waktu)
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

:: Jika sudah tercatat di sent_history.txt, langsung lewati agar tidak upload ganda
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

:: Tunggu 2 detik sebelum upload agar proses Print Spooler / LumaBooth selesai 100%
ping 127.0.0.1 -n 3 >nul 2>&1

:: Kirim ke Portal Utama (files.yuhu.co.id) dan pastikan sukses (--fail)
%CURL% --fail --max-time 60 --connect-timeout 10 -s -k -X POST "!API_URL_PRIMARY!" -F "token=!API_TOKEN!" -F "client_event=!EVENT_NAME!" -F "folder_target=!FOLDER_TARGET!" -F "file_size=!FILE_SIZE!" -F "file=@!CURRENT_FILE!" >nul 2>&1
IF !ERRORLEVEL! EQU 0 (
    >> "%SENT_LOG%" ECHO !CURRENT_FILE!
    > "%LOG_FILE%" ECHO [%DATE% %TIME%] SUKSES UPLOAD KE FILES.YUHU.CO.ID: !EVENT_NAME!/!FOLDER_TARGET! - !CURRENT_FILE!
    %CURL% --max-time 45 --connect-timeout 8 -s -k -X POST "!API_URL_FALLBACK!" -F "token=!API_TOKEN!" -F "client_event=!EVENT_NAME!" -F "folder_target=!FOLDER_TARGET!" -F "file=@!CURRENT_FILE!" >nul 2>&1
    GOTO :pop_queue
)

:: Jika server utama belum merespons 200, coba server fallback (qr.yuhu.co.id)
%CURL% --fail --max-time 60 --connect-timeout 10 -s -k -X POST "!API_URL_FALLBACK!" -F "token=!API_TOKEN!" -F "client_event=!EVENT_NAME!" -F "folder_target=!FOLDER_TARGET!" -F "file_size=!FILE_SIZE!" -F "file=@!CURRENT_FILE!" >nul 2>&1
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
