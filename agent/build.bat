@echo off
REM =====================================================================
REM  build.bat - compile biometric_agent.py into a distributable bundle.
REM
REM  Run this ONCE on a build machine (any Windows PC with Python 3.9+).
REM  The output folder is then copied to every client PC - the clients
REM  never need Python installed.
REM
REM  Output: dist\biometric_agent\   (exe + _internal\ + config.ini)
REM =====================================================================

setlocal EnableExtensions

echo(
echo  ============================================================
echo   Building biometric_agent.exe
echo  ============================================================
echo(

REM ---- 1. Check Python is present -------------------------------------
python --version >nul 2>&1
if errorlevel 1 (
    echo  [!] Python not found on PATH.
    echo      Install Python 3.9+ from python.org and tick "Add to PATH".
    pause
    exit /b 1
)
python --version

REM ---- 2. Dependencies -------------------------------------------------
echo(
echo  [1/3] Installing dependencies...
python -m pip install --quiet --upgrade pip
python -m pip install --quiet -r requirements.txt
if errorlevel 1 (
    echo  [!] pip install failed - check your network connection.
    pause
    exit /b 1
)

REM ---- 3. Build --------------------------------------------------------
REM  --onedir    : folder bundle. Starts faster than --onefile and does not
REM                unpack to %TEMP% on every launch (which some corporate
REM                AV products flag).
REM  --windowed  : no console window. All output goes to logs\.
REM  --hidden-import: PyInstaller's static analysis misses drivers that are
REM                imported lazily inside functions, so name them explicitly.
echo(
echo  [2/3] Running PyInstaller...

pyinstaller --noconfirm --onedir --windowed ^
    --name biometric_agent ^
    --hidden-import pymysql ^
    --hidden-import pymysql.cursors ^
    --hidden-import serial ^
    --hidden-import serial.tools.list_ports ^
    --collect-submodules serial ^
    biometric_agent.py

if errorlevel 1 (
    echo  [!] Build failed - see the PyInstaller output above.
    pause
    exit /b 1
)

REM ---- 4. Stage the runtime files next to the exe ----------------------
echo(
echo  [3/3] Staging config and certificate...

if exist "config.ini" copy /y "config.ini" "dist\biometric_agent\config.ini" >nul
if exist "ca.pem"     copy /y "ca.pem"     "dist\biometric_agent\ca.pem"     >nul
if not exist "ca.pem" if exist "..\ca.pem" copy /y "..\ca.pem" "dist\biometric_agent\ca.pem" >nul

REM Ship the installer and the driver inside the bundle, so one folder is
REM all you need to carry to a new PC.
if exist "install_service.bat"   copy /y "install_service.bat"   "dist\" >nul
if exist "uninstall_service.bat" copy /y "uninstall_service.bat" "dist\" >nul
if exist "CH341SER.EXE"          copy /y "CH341SER.EXE"          "dist\" >nul

echo(
echo  ============================================================
echo   Build complete.
echo(
echo   Bundle : dist\biometric_agent\
echo   Deploy : copy the whole  dist\  folder to the client PC,
echo            then run  install_service.bat  there as Admin.
echo  ============================================================
echo(

REM NOTE: if you prefer a genuinely single file you can copy anywhere, use
REM   pyinstaller --noconfirm --onefile --windowed biometric_agent.py
REM Remember that --onefile still reads config.ini from the folder the exe
REM sits in, so the config must travel with it either way.

pause
endlocal
