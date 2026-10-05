@echo off
:: launch-lan.bat — same as launch.bat, but reachable from phones on the same Wi-Fi.
:: Binds to 0.0.0.0 instead of localhost, then prints the address to type on the phone.

set BASE=%~dp0
if "%BASE:~-1%"=="\" set BASE=%BASE:~0,-1%

:: Database connection details (gitignored) - see secrets.bat.example
if exist "%BASE%\secrets.bat" call "%BASE%\secrets.bat"

set PHP=%BASE%\php\php.exe
set INI=%BASE%\php\php.ini
set ROOT=%BASE%\payroll2
set PORT=8765

netstat -ano | findstr ":%PORT% " | findstr "LISTENING" >nul 2>&1
if %errorlevel%==0 (
    echo Server already running on port %PORT%.
    goto :show
)

start "" /B "%PHP%" -c "%INI%" -d "extension_dir=%BASE%\php\ext" -S 0.0.0.0:%PORT% -t "%ROOT%"
timeout /t 3 /nobreak >nul

:show
echo.
echo ============================================================
echo   Open this address on the phone (same Wi-Fi as this PC):
echo ============================================================
for /f "tokens=2 delims=:" %%a in ('ipconfig ^| findstr /c:"IPv4 Address"') do (
    for /f "tokens=1" %%b in ("%%a") do echo      http://%%b:%PORT%/employee/index.php
)
echo.
echo   Admin on this PC:  http://localhost:%PORT%/index.php
echo ============================================================
echo.
echo   If the phone cannot connect, allow php.exe through
echo   Windows Firewall on Private networks.
echo.
pause
