@echo off
cd /d "%~dp0"

echo Installing Python dependencies...
pip install -r backend\requirements.txt > NUL 2>&1

set PYTHON=C:\Users\ngung\AppData\Local\Programs\Python\Python311\python.exe

echo Seeding database...
%PYTHON% backend/seed.py

echo Starting server...
start /min "Choreo Server" cmd /c "cd /d backend && %PYTHON% -m uvicorn main:app --host 0.0.0.0 --port 8000"

echo Choreography Manager started!
echo Open http://localhost:8000/app in your browser.
timeout /t 3 > NUL
start http://localhost:8000/app
