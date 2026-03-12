@echo off
cd /d "%~dp0"

echo ========================================
echo   Veraleza - Arrancando Backend
echo ========================================
echo.

REM Comprobar Python
python --version >nul 2>&1
if errorlevel 1 (
    echo ERROR: Python no encontrado.
    echo Instala Python o asegurate de que este en el PATH.
    echo.
    pause
    exit /b 1
)

REM Comprobar dependencias
python -c "import uvicorn" >nul 2>&1
if errorlevel 1 (
    echo ERROR: uvicorn no esta instalado.
    echo Ejecuta en esta carpeta: pip install -r requirements.txt
    echo.
    pause
    exit /b 1
)

echo Backend en http://localhost:8000
echo No cierres esta ventana mientras uses la app.
echo.
echo Si ves errores abajo, revisa el archivo .env
echo con SUPABASE_URL y SUPABASE_KEY correctos.
echo ========================================
echo.

python -m uvicorn backend.main:app --reload --host 0.0.0.0

echo.
echo El backend se ha detenido.
pause
