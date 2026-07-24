@echo off
setlocal

set SCRIPTS=C:\Users\PC\AppData\Local\Python\pythoncore-3.14-64\Scripts
set PATH=%SCRIPTS%;%PATH%

echo Starting Headroom proxy...
start /B "" "%SCRIPTS%\headroom.exe" proxy > NUL 2>&1
timeout /T 3 /NOBREAK > NUL

echo Launching OpenCode with Headroom...
"%SCRIPTS%\headroom.exe" wrap opencode -- --workdir "D:\Programs\Adam\Artist Asset Tools"
