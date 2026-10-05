@echo off
REM =====================================================================
REM  install_service.bat - one-shot setup for a new client PC
REM
REM  Run this on ANY PC the biometric terminal gets moved to. It:
REM     1. installs the CH341SER USB-to-serial driver silently
REM     2. copies the agent to a stable per-user folder
REM     3. registers it to start automatically at every Windows logon
REM     4. starts it immediately, so you do not have to reboot
REM
REM  Re-running it is safe: it upgrades in place and keeps your config.ini.
REM
REM  Run as Administrator the FIRST time on a PC (the driver needs it).
REM  Later re-runs do not need admin.
REM =====================================================================

setlocal EnableExtensions EnableDelayedExpansion

set "APPNAME=BiometricAgent"
set "SRC=%~dp0"
set "TARGET=%LOCALAPPDATA%\%APPNAME%"
set "STARTUP=%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup"
set "EXENAME=biometric_agent.exe"

echo(
echo  ============================================================
echo   Biometric Agent installer
echo  ============================================================
echo(

REM ---------------------------------------------------------------------
REM  1. Locate the compiled agent.
REM
REM  PyInstaller --onedir produces a FOLDER, not a lone .exe: the exe needs
REM  the _internal\ DLLs beside it. So we copy the whole folder rather than
REM  dropping a single file into Startup (which would fail to launch).
REM ---------------------------------------------------------------------
set "PAYLOAD="
if exist "%SRC%dist\biometric_agent\%EXENAME%"  set "PAYLOAD=%SRC%dist\biometric_agent"
if exist "%SRC%biometric_agent\%EXENAME%"       set "PAYLOAD=%SRC%biometric_agent"
if exist "%SRC%%EXENAME%"                       set "PAYLOAD=%SRC%"

if not defined PAYLOAD (
    echo  [!] Could not find %EXENAME%.
    echo(
    echo      Build it first:   build.bat
    echo      Expected at:      %SRC%dist\biometric_agent\%EXENAME%
    echo(
    pause
    exit /b 1
)
echo  [1/5] Found agent at: !PAYLOAD!

REM ---------------------------------------------------------------------
REM  2. Install the CH340/CH341 driver silently.
REM     /S is the WCH installer's silent switch. Skipped without complaint
REM     if the EXE is not shipped alongside - the file-watcher transport
REM     does not need a driver at all.
REM ---------------------------------------------------------------------
if exist "%SRC%CH341SER.EXE" (
    echo  [2/5] Installing CH341SER USB-serial driver ^(silent^)...
    start /wait "" "%SRC%CH341SER.EXE" /S
    if errorlevel 1 (
        echo        [!] Driver installer returned an error - continuing anyway.
        echo            Re-run this script as Administrator if the COM port
        echo            never appears.
    ) else (
        echo        Driver OK.
    )
) else (
    echo  [2/5] CH341SER.EXE not present - skipping driver install.
)

REM ---------------------------------------------------------------------
REM  3. Copy the agent into place, preserving any existing config.
REM ---------------------------------------------------------------------
echo  [3/5] Installing to: %TARGET%

if exist "%TARGET%\config.ini" (
    echo        Existing config.ini found - preserving it.
    copy /y "%TARGET%\config.ini" "%TEMP%\%APPNAME%_config.bak" >nul 2>&1
)

REM Stop a running copy so the files are not locked.
taskkill /f /im "%EXENAME%" >nul 2>&1

if not exist "%TARGET%" mkdir "%TARGET%" >nul 2>&1
robocopy "!PAYLOAD!" "%TARGET%" /E /NFL /NDL /NJH /NJS /NP >nul
REM robocopy uses 0-7 for success; 8+ is a real failure.
if errorlevel 8 (
    echo  [!] Copy failed. Is the agent still running, or the disk full?
    pause
    exit /b 1
)

REM Ship config.ini next to the exe if the build did not include it.
if not exist "%TARGET%\config.ini" (
    if exist "%SRC%config.ini" copy /y "%SRC%config.ini" "%TARGET%\config.ini" >nul
)
REM Restore the PC's own settings over whatever the build shipped.
if exist "%TEMP%\%APPNAME%_config.bak" (
    copy /y "%TEMP%\%APPNAME%_config.bak" "%TARGET%\config.ini" >nul
    del "%TEMP%\%APPNAME%_config.bak" >nul 2>&1
)

REM The Aiven CA cert, if this PC syncs straight to the database.
if exist "%SRC%ca.pem" copy /y "%SRC%ca.pem" "%TARGET%\ca.pem" >nul 2>&1

echo        Copied.

REM ---------------------------------------------------------------------
REM  4. Register for auto-start at logon.
REM
REM  A .lnk in the Startup folder is used rather than a copied .exe, because
REM  the exe must stay beside its _internal\ folder. PowerShell creates the
REM  shortcut - no third-party tools needed.
REM ---------------------------------------------------------------------
echo  [4/5] Registering auto-start...

powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "$s = (New-Object -ComObject WScript.Shell).CreateShortcut('%STARTUP%\%APPNAME%.lnk');" ^
  "$s.TargetPath = '%TARGET%\%EXENAME%';" ^
  "$s.WorkingDirectory = '%TARGET%';" ^
  "$s.WindowStyle = 7;" ^
  "$s.Description = 'Biometric attendance sync agent';" ^
  "$s.Save()" >nul 2>&1

if exist "%STARTUP%\%APPNAME%.lnk" (
    echo        Startup shortcut created.
) else (
    echo        [!] Shortcut failed - falling back to a Run registry key.
    reg add "HKCU\Software\Microsoft\Windows\CurrentVersion\Run" ^
        /v "%APPNAME%" /t REG_SZ /d "\"%TARGET%\%EXENAME%\"" /f >nul
)

REM ---------------------------------------------------------------------
REM  5. Start it now.
REM ---------------------------------------------------------------------
echo  [5/5] Starting agent...
start "" /d "%TARGET%" "%TARGET%\%EXENAME%"

echo(
echo  ============================================================
echo   Done. The agent is running and will start on every logon.
echo(
echo   Installed at : %TARGET%
echo   Config       : %TARGET%\config.ini
echo   Log          : %TARGET%\logs\biometric_agent.log
echo(
echo   EDIT config.ini NOW and set sync.api_url and sync.api_key.
echo  ============================================================
echo(

REM Open the config so the operator cannot forget this step.
start "" notepad "%TARGET%\config.ini"

pause
endlocal
