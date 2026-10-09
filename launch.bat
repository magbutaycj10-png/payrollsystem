@echo off
:: launch.bat - starts the PHP built-in server.
:: Uses %%~dp0 (the folder this .bat lives in) so paths work on any drive or folder.

:: %~dp0 always ends with \, strip it for clean concatenation
set BASE=%~dp0
if "%BASE:~-1%"=="\" set BASE=%BASE:~0,-1%

:: Local credentials live in secrets.bat, which is gitignored and never pushed.
if exist "%BASE%\secrets.bat" call "%BASE%\secrets.bat"

set PHP=%BASE%\php\php.exe
set INI=%BASE%\php\php.ini
set ROOT=%BASE%\payroll2
set PORT=8765

:: If server is already running on the port, just open the browser
netstat -ano | findstr ":%PORT% " | findstr "LISTENING" >nul 2>&1
if %errorlevel%==0 (
    start "" "http://localhost:%PORT%/index.php?logout=1"
    exit /b 0
)

:: Start PHP in the background.
:: -d extension_dir overrides php.ini so DLLs load from the correct folder
:: regardless of where the app is installed.
start "" /B "%PHP%" -c "%INI%" -d "extension_dir=%BASE%\php\ext" -S localhost:%PORT% -t "%ROOT%"
timeout /t 3 /nobreak >nul
start "" "http://localhost:%PORT%/index.php?logout=1"
exit /b 0
