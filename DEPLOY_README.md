# 🚀 DESPLIEGUE RÁPIDO - Sistema de Trazabilidad de Joyería

## Opción 1: 000webhost (Gratuito, recomendado para pruebas)

### Paso 1: Crear cuenta
1. Ve a https://www.000webhost.com/
2. Regístrate con email o Google
3. Crea un nuevo sitio web (gratuito)

### Paso 2: Crear base de datos
1. En el panel de 000webhost, ve a **Tools → Database Manager**
2. Crea una nueva base de datos MySQL
3. Anota estos datos:
   - **Database Name**: `id1234567_trazabilidad`
   - **Database User**: `id1234567_user`
   - **Database Password**: (la que elijas)
   - **Host**: `localhost` (normalmente)

### Paso 3: Importar la base de datos
1. En Database Manager, selecciona tu base de datos
2. Ve a **phpMyAdmin**
3. Ve a la pestaña **Import**
4. Selecciona el archivo `database_full.sql`
5. Click en **Go**

### Paso 4: Configurar la aplicación
1. Descomprime `app_oro.zip`
2. Abre `backend/config/config.php`
3. Edita con los datos de tu base de datos:

```php
define('DB_HOST',     'localhost');
define('DB_NAME',     'id1234567_trazabilidad');  // Tu nombre de BD
define('DB_USER',     'id1234567_user');           // Tu usuario
define('DB_PASSWORD', 'TuPasswordAqui');           // Tu contraseña
```

### Paso 5: Subir archivos
1. En 000webhost, ve a **Files → Upload Files**
2. Sube TODO el contenido de la carpeta `app_oro/` (no la carpeta, sus archivos)
3. Asegúrate de que `index.php` quede en la raíz `public_html/`

### Paso 6: Probar
1. Ve a tu URL: `https://tusitio.000webhostapp.com/`
2. Login por defecto:
   - **Usuario**: `admin`
   - **Contraseña**: `admin123`

---

## Opción 2: InfinityFree (Gratuito)

Pasos similares a 000webhost. Ve a https://infinityfree.net/

---

## Opción 3: Cualquier hosting con cPanel

1. Accede a **cPanel → MySQL Databases**
2. Crea la base de datos y el usuario
3. Ve a **phpMyAdmin** e importa `database_full.sql`
4. Ve a **File Manager** → `public_html/`
5. Sube el ZIP y descomprímelo
6. Edita `backend/config/config.php`
7. Prueba la URL

---

## ⚠️ Notas importantes

- **Seguridad**: Cambia la contraseña del usuario `admin` después del primer login.
- **Fotos**: Las fotos subidas se guardan en `backend/uploads/`. Este directorio necesita permisos de escritura (755).
- **HTTPS**: Si tu hosting usa HTTPS, todo debería funcionar automáticamente.

---

## 📦 Contenido del paquete

```
app_oro/
├── index.php                 # Front controller
├── .htaccess                 # Reglas de Apache
├── database_full.sql         # Base de datos completa
├── backend/                  # API y lógica
│   ├── config/
│   │   ├── config.php       # ← EDITAR ESTO
│   │   └── db.php
│   ├── controllers/
│   ├── core/
│   └── uploads/             # Fotos (necesita permisos 755)
├── frontend/                 # Vistas HTML/CSS/JS
│   ├── assets/
│   └── *.html
└── sql/                      # Scripts adicionales
```
