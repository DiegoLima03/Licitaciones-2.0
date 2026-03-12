@echo off
cd /d "%~dp0"

echo ========================================
echo   Veraleza - Arrancar Backend + Frontend
echo ========================================
echo.

REM Iniciar backend en nueva ventana
start "Veraleza Backend" cmd /k "cd /d "%~dp0" && arrancar-backend.bat"

REM Esperar a que el backend inicie
echo Esperando 5 segundos a que arranque el backend...
timeout /t 5 /nobreak >nul

REM Iniciar frontend en nueva ventana
echo Iniciando frontend...
start "Veraleza Frontend" cmd /k "cd /d "%~dp0\frontend" && npm run dev"

echo.
echo ========================================
echo   Abre http://localhost:3000 en el navegador
echo   (o http://localhost:3001 si Next.js usa ese puerto)
echo ========================================
echo.
pause
