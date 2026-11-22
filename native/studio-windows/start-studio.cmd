@echo off
setlocal
where py >nul 2>nul
if errorlevel 1 goto python
py -3 "%~dp0studio.py"
goto done
:python
python "%~dp0studio.py"
:done
if errorlevel 1 pause
