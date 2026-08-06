@echo off
chcp 65001 >nul
REM ============================================================
REM Script de instalación para XAMPP - Trazabilidad de Joyería
REM ============================================================
REM Ejecutar como Administrador
REM
REM Este script copia los archivos del proyecto a XAMPP
REM y configura la base de datos automáticamente
REM ============================================================

echo.
echo ==========================================
echo  INSTALADOR XAMPP - Trazabilidad Oro
echo ==========================================
echo.

REM --- Configuración ---
set "SOURCE_DIR=C:\Users\orlandocastrol\OneDrive\Documentos\apporo\app_oro\app_oro"
set "DEST_DIR=C:\xampp\htdocs\app_oro"
set "XAMPP_MYSQL=C:\xampp\mysql\bin"

REM --- Verificar XAMPP existe ---
if not exist "C:\xampp" (
    echo [ERROR] XAMPP no encontrado en C:\xampp
    echo Por favor instala XAMPP desde: https://www.apachefriends.org/
    pause
    exit /b 1
)

REM --- Verificar MySQL existe ---
if not exist "%XAMPP_MYSQL%\mysql.exe" (
    echo [ERROR] MySQL no encontrado en %XAMPP_MYSQL%
    echo Verifica que XAMPP esté instalado correctamente.
    pause
    exit /b 1
)

REM --- Crear directorio destino ---
echo [1/4] Creando directorio en htdocs...
if not exist "%DEST_DIR%" mkdir "%DEST_DIR%"

REM --- Copiar archivos ---
echo [2/4] Copiando archivos del proyecto...
echo     Desde: %SOURCE_DIR%
echo     Hasta:  %DEST_DIR%

xcopy /E /I /Y "%SOURCE_DIR%\backend" "%DEST_DIR%\backend" >nul
xcopy /E /I /Y "%SOURCE_DIR%\frontend" "%DEST_DIR%\frontend" >nul
xcopy /E /I /Y "%SOURCE_DIR%\sql" "%DEST_DIR%\sql" >nul
xcopy /E /I /Y "%SOURCE_DIR%\uploads" "%DEST_DIR%\uploads" >nul 2>nul

REM Copiar archivos sueltos
for %%f in ("%SOURCE_DIR%\*.html" "%SOURCE_DIR%\*.md" "%SOURCE_DIR%\*.txt" "%SOURCE_DIR%\*.json") do (
    copy /Y "%%f" "%DEST_DIR%\" >nul 2>nul
)

echo [OK] Archivos copiados.

REM --- Backup de db.php si existe ---
echo [3/4] Configurando base de datos...
if exist "%DEST_DIR%\backend\config\db.php" (
    echo     Backup de db.php existente...
    copy /Y "%DEST_DIR%\backend\config\db.php" "%DEST_DIR%\backend\config\db_hostinger.php" >nul
)

REM --- Copiar configuración local ---
if exist "%SOURCE_DIR%\backend\config\db_local.php" (
    copy /Y "%SOURCE_DIR%\backend\config\db_local.php" "%DEST_DIR%\backend\config\db.php" >nul
    echo [OK] Configuración local aplicada (root / sin password).
) else (
    echo [ADVERTENCIA] No se encontró db_local.php
    echo     Debes configurar db.php manualmente.
)

REM --- Ejecutar SQL ---
echo [4/4] Creando base de datos y tablas...
if exist "%DEST_DIR%\sql\setup_xampp.sql" (
    "%XAMPP_MYSQL%\mysql.exe" -u root -e "source %DEST_DIR%\sql\setup_xampp.sql" 2>nul
    if %errorlevel% == 0 (
        echo [OK] Base de datos creada con datos de prueba.
    ) else (
        echo [ADVERTENCIA] No se pudo ejecutar SQL automáticamente.
        echo     Ejecuta manualmente en phpMyAdmin: http://localhost/phpmyadmin
        echo     Archivo SQL: %DEST_DIR%\sql\setup_xampp.sql
    )
) else (
    echo [ERROR] No se encontró setup_xampp.sql
)

echo.
echo ==========================================
echo  INSTALACION COMPLETADA
echo ==========================================
echo.
echo Pasos finales:
echo 1. Abre el Panel de Control de XAMPP
echo 2. Inicia Apache y MySQL
echo 3. Abre: http://localhost/app_oro/frontend/login.html
echo.
echo Credenciales de prueba:
echo   Usuario: admin
echo   Password: admin123
echo.

REM --- Preguntar si abrir navegador ---
set /p open_browser="¿Abrir la app en el navegador? (s/n): "
if /i "%open_browser%"=="s" (
    start http://localhost/app_oro/frontend/login.html
)

pause
