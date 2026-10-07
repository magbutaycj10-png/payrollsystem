@echo off
:: Runs the payroll test suite against a private throw-away database (see tests\README.md).
::   run-tests.bat                  everything
::   run-tests.bat --suite=03       one suite       --filter=SSS   tests by name
::   run-tests.bat --strict         confirmed defects fail the run too
setlocal
set "HERE=%~dp0"
set "PHP=%HERE%..\php\php.exe"
if not exist "%PHP%" set "PHP=php"
set "QA_MAIN=%HERE%lib\Main.php"
"%PHP%" -r "require getenv('QA_MAIN');" -- %*
exit /b %errorlevel%
