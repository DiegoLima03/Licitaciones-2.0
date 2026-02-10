# Arranca el backend FastAPI (puerto 8000)
# Ejecuta este script y deja la ventana abierta mientras uses el frontend.

Set-Location $PSScriptRoot
Write-Host "Arrancando backend en http://localhost:8000 ..." -ForegroundColor Green
Write-Host "No cierres esta ventana mientras uses el login." -ForegroundColor Yellow
Write-Host ""
uvicorn backend.main:app --reload --host 0.0.0.0
