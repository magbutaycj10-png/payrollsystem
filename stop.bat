@echo off
title Stopping Payroll System...

set PORT=8765

echo  Stopping L^&N Pharmacy Payroll System on port %PORT%...

:: Find and kill the PHP process listening on our port
for /f "tokens=5" %%a in ('netstat -ano ^| findstr ":%PORT% " ^| findstr "LISTENING"') do (
    taskkill /PID %%a /F >nul 2>&1
)

echo  Done. The system has been stopped.
timeout /t 2 /nobreak >nul
exit /b 0
