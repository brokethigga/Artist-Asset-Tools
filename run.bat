@echo off
cd /d "%~dp0"

echo Installing Python dependencies...
pip install -r backend\requirements.txt > NUL 2>&1

set SCRIPTS=C:\Users\PC\AppData\Local\Python\pythoncore-3.14-64\Scripts
set PATH=%SCRIPTS%;%PATH%

start /min "Choreo Server" cmd /c "cd /d backend && python -m uvicorn main:app --host 0.0.0.0 --port 8000"

echo Choreography Manager started!
echo Open http://localhost:8000/app in your browser.
timeout /t 3 > NUL
start http://localhost:8000/app
