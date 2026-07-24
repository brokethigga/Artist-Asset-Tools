' Silent uvicorn launcher — no console window
Dim shell
Set shell = CreateObject("WScript.Shell")
shell.CurrentDirectory = "D:\Programs\Adam\Artist Asset Tools\backend"
shell.Run "python -m uvicorn main:app --host 0.0.0.0 --port 8000", 0, False
