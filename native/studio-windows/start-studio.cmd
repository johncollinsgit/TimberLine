@echo off
setlocal
where py >nul 2>nul
if errorlevel 1 goto python
py -3 -B "%~dp0studio.py" %*
goto done
:python
python -B "%~dp0studio.py" %*
:done
if not errorlevel 1 exit /b 0
if "%~1"=="" pause
exit /b 1
