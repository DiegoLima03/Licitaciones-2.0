@echo off
cd /d "%~dp0"
echo Arrancando backend en http://localhost:8000 ...
echo No cierres esta ventana mientras uses el login.
echo.
uvicorn backend.main:app --reload --host 0.0.0.0
pause
