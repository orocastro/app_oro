@echo off
echo ==========================================
echo  Iniciando Demo - Trazabilidad Joyeria
echo ==========================================
echo.
cd /d "%~dp0"

:: Verificar si Node.js esta instalado
node -v >nul 2>&1
if %errorlevel% neq 0 (
    echo ERROR: Node.js no esta instalado.
    echo Por favor instala Node.js desde https://nodejs.org
    pause
    exit /b 1
)

echo Node.js detectado:
node -v
echo.

:: Iniciar servidor
echo Iniciando servidor en http://localhost:8767
echo.
start "" "http://localhost:8767/trazabilidad_list.html"
node server_demo.js

pause
