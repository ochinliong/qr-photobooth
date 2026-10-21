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
SET "LOCK_FILE=%WORK_DIR%worker.lock"

IF /I "%~1"=="WORKER" GOTO :worker_entry

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

SET "EVENT_TYPE=%~1"
IF "%EVENT_TYPE%"=="" (
    START "" /B /BELOWNORMAL cmd /c ""!SELF!" WORKER" >nul 2>&1
    EXIT /B 0
)

IF /I "%EVENT_TYPE%"=="countdown"        EXIT /B 0
IF /I "%EVENT_TYPE%"=="countdown_start"  EXIT /B 0
IF /I "%EVENT_TYPE%"=="session_start"    EXIT /B 0
IF /I "%EVENT_TYPE%"=="processing_start" EXIT /B 0
IF /I "%EVENT_TYPE%"=="sharing_screen"   EXIT /B 0
IF /I "%EVENT_TYPE%"=="capture_start"    EXIT /B 0
IF /I "%EVENT_TYPE%"=="printing"         EXIT /B 0

IF /I "%EVENT_TYPE%"=="session_end" (
    START "" /B /BELOWNORMAL cmd /c ""!SELF!" WORKER" >nul 2>&1
    EXIT /B 0
)

SET "FILE_PARAM=%~2"
IF "!FILE_PARAM!"=="" EXIT /B 0

IF /I "%EVENT_TYPE%"=="file_upload" (
    IF NOT EXIST "!FILE_PARAM!" EXIT /B 0

    ECHO "!FILE_PARAM!" | findstr /I "\.jpg\" \.jpeg\" \.png\" \.gif\" \.mp4\" \.mov\" \.webm\"" >nul
    IF ERRORLEVEL 1 EXIT /B 0

    ECHO "!FILE_PARAM!" | findstr /I "_mp4\.jpg\" \\thumb\\" >nul
    IF NOT ERRORLEVEL 1 EXIT /B 0

    >> "%QUEUE_FILE%" ECHO !FILE_PARAM!
    START "" /B /BELOWNORMAL cmd /c ""!SELF!" WORKER" >nul 2>&1
    EXIT /B 0
)

IF /I "%EVENT_TYPE%"=="file_download" (
    ECHO "!FILE_PARAM!" | findstr /I "\.jpg\" \.jpeg\" \.png\"" >nul
    IF ERRORLEVEL 1 EXIT /B 0

    START "" /B /BELOWNORMAL cmd /c ""!SELF!" BG_SINGLES "!FILE_PARAM!"" >nul 2>&1
    EXIT /B 0
)

EXIT /B 0

:worker_entry
CALL :locked_worker 9>>"%LOCK_FILE%"
EXIT /B 0

:locked_worker
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

ping 127.0.0.1 -n 3 >nul 2>&1

SET "HTTP_CODE=000"
FOR /F "delims=" %%H IN ('%CURL% --max-time 60 --connect-timeout 10 -s -k -o nul -w "%%{http_code}" -X POST "!API_URL_PRIMARY!" -F "token=!API_TOKEN!" -F "client_event=!EVENT_NAME!" -F "folder_target=!FOLDER_TARGET!" -F "file_size=!FILE_SIZE!" -F "file=@!CURRENT_FILE!" 2^>nul') DO SET "HTTP_CODE=%%H"

IF "!HTTP_CODE!"=="200" (
    %CURL% --max-time 45 --connect-timeout 8 -s -k -X POST "!API_URL_FALLBACK!" -F "token=!API_TOKEN!" -F "client_event=!EVENT_NAME!" -F "folder_target=!FOLDER_TARGET!" -F "file=@!CURRENT_FILE!" >nul 2>&1
    GOTO :pop_queue
)

FOR /F "delims=" %%H IN ('%CURL% --max-time 60 --connect-timeout 10 -s -k -o nul -w "%%{http_code}" -X POST "!API_URL_FALLBACK!" -F "token=!API_TOKEN!" -F "client_event=!EVENT_NAME!" -F "folder_target=!FOLDER_TARGET!" -F "file_size=!FILE_SIZE!" -F "file=@!CURRENT_FILE!" 2^>nul') DO SET "HTTP_CODE=%%H"

IF "!HTTP_CODE!"=="200" GOTO :pop_queue

ping 127.0.0.1 -n 7 >nul 2>&1
GOTO :queue_loop

:pop_queue
more +1 "%QUEUE_FILE%" > "%WORK_DIR%temp_queue.txt" 2>nul
MOVE /Y "%WORK_DIR%temp_queue.txt" "%QUEUE_FILE%" >nul 2>&1
GOTO :queue_loop
