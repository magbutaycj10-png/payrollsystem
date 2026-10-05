@echo off
REM =====================================================================
REM  uninstall_service.bat - remove the agent from this PC.
REM
REM  Use this when the biometric terminal is moved somewhere else, so the
REM  old PC stops polling for a device that is no longer attached.
REM  Leaves the CH341SER driver installed (harmless, and other devices
REM  may be using it).
REM =====================================================================

setlocal EnableExtensions

set "APPNAME=BiometricAgent"
set "TARGET=%LOCALAPPDATA%\%APPNAME%"
set "STARTUP=%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup"

echo(
echo  Removing Biometric Agent from this PC...
echo(

REM 1. Stop the running process.
taskkill /f /im "biometric_agent.exe" >nul 2>&1
if errorlevel 1 (
    echo   - agent was not running
) else (
    echo   - agent stopped
)

REM 2. Remove both possible auto-start registrations.
if exist "%STARTUP%\%APPNAME%.lnk" (
    del /f /q "%STARTUP%\%APPNAME%.lnk" >nul 2>&1
    echo   - startup shortcut removed
)
reg query "HKCU\Software\Microsoft\Windows\CurrentVersion\Run" /v "%APPNAME%" >nul 2>&1
if not errorlevel 1 (
    reg delete "HKCU\Software\Microsoft\Windows\CurrentVersion\Run" /v "%APPNAME%" /f >nul 2>&1
    echo   - run-key removed
)

REM 3. Keep the logs - they are the only record of what this PC synced.
if exist "%TARGET%" (
    if exist "%TARGET%\logs" (
        echo   - keeping logs at %USERPROFILE%\Desktop\%APPNAME%-logs
        robocopy "%TARGET%\logs" "%USERPROFILE%\Desktop\%APPNAME%-logs" /E /NFL /NDL /NJH /NJS /NP >nul
    )
    rmdir /s /q "%TARGET%" >nul 2>&1
    echo   - program files removed
)

echo(
echo  Done.
echo(
pause
endlocal
