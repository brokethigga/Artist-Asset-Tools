@echo off
cd /d "%~dp0"

:: Find Python — try common locations, fallback to PATH
set PYTHON=
for %%p in (
  "%LOCALAPPDATA%\Programs\Python\Python313\python.exe"
  "%LOCALAPPDATA%\Programs\Python\Python312\python.exe"
  "%LOCALAPPDATA%\Programs\Python\Python311\python.exe"
  "%ProgramFiles%\Python313\python.exe"
  "%ProgramFiles%\Python312\python.exe"
  "%ProgramFiles%\Python311\python.exe"
  "%LOCALAPPDATA%\Microsoft\WindowsApps\python3.exe"
) do if exist %%p set PYTHON=%%p

if not defined PYTHON set PYTHON=python

echo Installing Python dependencies...
%PYTHON% -m pip install -r backend\requirements.txt > NUL 2>&1

:: Run seed if it exists
if exist backend\seed.py %PYTHON% backend\seed.py

echo Starting Choreography Manager...
start /min "Choreo Server" cmd /c "cd /d backend && %PYTHON% -m uvicorn main:app --host 0.0.0.0 --port 8000"

echo Open http://localhost:8000/app in your browser.
timeout /t 3 > NUL
start http://localhost:8000/app