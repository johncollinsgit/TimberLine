@echo off
setlocal
if "%~1"=="" (
    where pyw >nul 2>nul && (start "" pyw -3 -B "%~dp0editor_server.py" & exit /b 0)
)
where py >nul 2>nul
if errorlevel 1 goto python
py -3 -B "%~dp0editor_server.py" %*
goto done
:python
python -B "%~dp0editor_server.py" %*
:done
if not errorlevel 1 exit /b 0
if "%~1"=="" pause
exit /b 1
