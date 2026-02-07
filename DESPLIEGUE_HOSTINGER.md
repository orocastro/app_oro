# 🚀 GUÍA DE DESPLIEGUE EN HOSTINGER - App Oro

## 📋 Preparativos Antes del Despliegue

### 1. Archivos Modificados para Producción
- ✅ `backend/config/app.php` - Debug desactivado
- ✅ `.htaccess` - Configuración de seguridad y rendimiento
- ✅ `backend/config/db_production.php` - Plantilla para configuración de BD

## 🗂️ PASO 1: Subir Archivos

### Estructura a subir a Hostinger:
```
public_html/
├── index.php
├── .htaccess
├── README.md
├── backend/
│   ├── config/
│   ├── controllers/
│   └── core/
├── frontend/
│   ├── *.html
│   └── assets/
├── logs/ (crear vacío)
└── sql/
```

### Archivos que NO subir:
- ❌ `backend/config/db_production.php` (es solo plantilla)
- ❌ Archivos de configuración local

## 🗄️ PASO 2: Configurar Base de Datos

### 2.1 Crear Base de Datos en Hostinger
1. Ir al Panel de Control de Hostinger
2. Sección "Bases de Datos" → "Administrar"
3. Crear nueva base de datos:
   - Nombre: `u[usuario]_trazabilidad` (ejemplo)
   - Usuario: `u[usuario]_admin`
   - Contraseña: [generar segura]

### 2.2 Importar Esquema
1. Acceder a phpMyAdmin
2. Seleccionar la base de datos creada
3. Ir a "Importar"
4. Subir el archivo `sql/schema.sql`
5. Ejecutar importación
6. Subir el archivo `sql/seed.sql` (datos iniciales)

### 2.3 Actualizar Configuración de BD
Editar `backend/config/db.php` en el servidor con los datos reales:

```php
private $host = 'localhost';
private $db_name = 'u123456789_trazabilidad'; // Tu BD real
private $username = 'u123456789_admin';        // Tu usuario real  
private $password = 'TU_PASSWORD_REAL';        // Tu contraseña real
```

## ⚙️ PASO 3: Configuraciones Finales

### 3.1 Verificar .htaccess
**TU CONFIGURACIÓN ESPECÍFICA:**
Ruta del servidor: `/home/u942127396/domains/app.tutorempresa.com/public_html`

Editar el archivo `.htaccess` en el servidor y configurar:
```apache
RewriteBase /  # Para dominio principal app.tutorempresa.com
```

**Pasos para editar en Hostinger:**
1. File Manager → navegar a `public_html`
2. Localizar archivo `.htaccess`
3. Clic derecho → "Edit" o "Editar"
4. Verificar que la línea sea: `RewriteBase /`
5. Guardar cambios

**Si tu app estuviera en subdirectorio sería:**
```apache
RewriteBase /subdirectorio/  # Solo si no está en raíz del dominio
```

### 3.2 Permisos de Archivos

**IMPORTANTE**: Configurar vía Administrador de Archivos de Hostinger o FTP

| Elemento | Permiso | Explicación |
|----------|---------|-------------|
| **Carpetas principales** | `755` | backend/, frontend/, sql/ |
| **Archivos PHP** | `644` | *.php (lectura para servidor) |
| **Archivo .htaccess** | `644` | Configuración Apache |
| **Carpeta logs/** | `777` | Debe ser escribible por PHP |
| **Carpeta img/** | `755` | Upload de fotos (escribible) |
| **Archivos CSS/JS** | `644` | frontend/assets/* |
| **Imágenes subidas** | `644` | *.jpg, *.png dentro de img/ |

**Cómo configurar en Hostinger:**
1. Panel Control → "Administrador de archivos"
2. Clic derecho en archivo/carpeta → "Permisos" 
3. Establecer números según tabla
4. Para carpetas marcar: ☑️ Leer ☑️ Escribir ☑️ Ejecutar
5. Para archivos marcar: ☑️ Leer ☑️ Escribir ☐ Ejecutar

### 3.3 PHP.ini (OPCIONAL - Normalmente NO necesario)

**¿Cuándo crear este archivo?**
- Solo si VES errores de PHP visibles en tu web después del despliegue
- Si aparecen warnings o notices en pantalla

**¿Cómo crear?**
1. File Manager → navegar a `public_html`
2. Botón "New File" → nombrar `.user.ini`
3. Agregar contenido:

```ini
; Ocultar errores PHP del usuario final
error_reporting = 0
display_errors = Off
log_errors = On

; Límites (solo si tienes problemas)
memory_limit = 256M
max_execution_time = 60
upload_max_filesize = 10M
```

**IMPORTANTE**: En Hostinger normalmente NO necesitas esto porque:
- ✅ Ya configuramos `APP_DEBUG = false` en el código
- ✅ Hostinger tiene buena configuración por defecto
- ✅ Los errores se manejan en nuestro `config/app.php`

**OMITIR este paso** a menos que veas errores visibles después del despliegue.

## 🔐 PASO 4: Seguridad

### 4.1 Cambiar Credenciales por Defecto
En la base de datos, tabla `usuarios`, cambiar:
- Usuario admin por defecto
- Contraseñas (usar password_hash en PHP)

### 4.2 SSL/HTTPS
- Activar certificado SSL gratuito en Hostinger
- Descomentar líneas de forzar HTTPS en `.htaccess`

## 🧪 PASO 5: Pruebas Post-Despliegue

### Verificar Funcionalidades:
- [ ] Login funcional
- [ ] Gestión de usuarios (admin)
- [ ] Registro de procesos
- [ ] Trazabilidad completa
- [ ] Responsive design en móvil

### URLs a Probar:
- `tudominio.com/` → Página de login
- `tudominio.com/home` → Dashboard
- `tudominio.com/trazabilidad_list` → Lista de registros
- `tudominio.com/trazabilidad_edit/[id]` → Edición (admin)

## 🚨 Solución de Problemas Comunes

### Error 500 - Internal Server Error
- Revisar permisos de archivos
- Verificar sintaxis de `.htaccess`
- Revisar logs de error del servidor

### Error de Conexión DB
- Verificar credenciales en `db.php`
- Comprobar que la BD existe
- Revisar nombre de host (puede ser distinto a 'localhost')

### Rutas no Funcionan
- Verificar `RewriteBase` en `.htaccess`
- Comprobar que mod_rewrite esté habilitado

## 📞 Contacto y Soporte
- Documentación del código en cada archivo
- Logs en carpeta `logs/` para debugging
- Panel de control Hostinger para gestión de hosting

---
**IMPORTANTE**: Hacer backup completo antes de cualquier cambio en producción.

## 🔄 Comandos SQL Útiles para Mantenimiento

```sql
-- Ver usuarios registrados
SELECT id, nombre, usuario, rol FROM usuarios;

-- Verificar registros de trazabilidad
SELECT COUNT(*) as total_registros FROM trazabilidad;

-- Estadísticas básicas
SELECT 
    estado,
    COUNT(*) as cantidad
FROM trazabilidad 
GROUP BY estado;
```