# 🚀 GUÍA DE INSTALACIÓN EN XAMPP - Trazabilidad de Joyería

> **Objetivo**: Tener la app funcionando en `http://localhost/app_oro` para hacer pruebas antes de subir a Hostinger.

---

## ✅ REQUISITOS PREVIOS

- [XAMPP](https://www.apachefriends.org/) instalado (Apache + MySQL + PHP)
- Proyecto descargado en tu PC

---

## 📂 PASO 1: Copiar archivos a XAMPP

### Opción A: Script automático (recomendado)

1. Abre **Git Bash** o **CMD** como Administrador
2. Navega a la carpeta del proyecto:
   ```bash
   cd "C:\Users\orlandocastrol\OneDrive\Documentos\apporo\app_oro\app_oro"
   ```
3. Ejecuta el script:
   ```bash
   ./install_xampp.bat
   ```

### Opción B: Manual

1. Copia **toda** la carpeta del proyecto:
   ```
   C:\Users\orlandocastrol\OneDrive\Documentos\apporo\app_oro\app_oro
   ```
   
2. Pégala en la carpeta `htdocs` de XAMPP:
   ```
   C:\xampp\htdocs\app_oro
   ```

---

## 🗄️ PASO 2: Crear la Base de Datos

1. Abre tu navegador y ve a:
   ```
   http://localhost/phpmyadmin
   ```

2. Haz clic en la pestaña **SQL** (arriba)

3. Copia y pega todo el contenido del archivo:
   ```
   C:\xampp\htdocs\app_oro\sql\setup_xampp.sql
   ```

4. Haz clic en **Continuar** o **Go**

5. Debería decir: "Base de datos lista" con 3 órdenes de ejemplo

---

## ⚙️ PASO 3: Configurar la conexión a la BD

1. Ve a la carpeta:
   ```
   C:\xampp\htdocs\app_oro\backend\config\
   ```

2. **Haz una copia de seguridad** de `db.php`:
   ```
   Renombrar: db.php → db_hostinger.php
   ```

3. Copia el archivo de configuración local:
   ```
   Copiar: db_local.php → db.php
   ```

   O si prefieres, edita `db.php` y cambia las credenciales:
   ```php
   private $host = 'localhost';
   private $db_name = 'trazabilidad';
   private $username = 'root';
   private $password = '';  // Sin password en XAMPP
   ```

---

## ▶️ PASO 4: Iniciar XAMPP

1. Abre el **Panel de Control de XAMPP**
   ```
   C:\xampp\xampp-control.exe
   ```

2. Haz clic en **Start** en:
   - ✅ **Apache**
   - ✅ **MySQL**

3. Ambos deben ponerse en verde 💚

---

## 🌐 PASO 5: Abrir la aplicación

1. Abre tu navegador y ve a:
   ```
   http://localhost/app_oro/frontend/login.html
   ```

2. **Credenciales de prueba**:
   - **Usuario**: `admin`
   - **Contraseña**: `admin123`

3. ¡Deberías ver el login! 🎉

---

## 📋 DATOS DE PRUEBA CARGADOS

La base de datos incluye 3 órdenes de ejemplo:

| # | Estado | Responsable | Producto | Oro | Materiales | Total | Merma |
|---|--------|-------------|----------|-----|------------|-------|-------|
| 100 | **MERMA A FAVOR** | Juan Pérez | Cadena Oro 18k | 100gr | 30gr | 130gr | **-5gr** (favor) |
| 101 | Completado | María García | Anillo Solitario | 50gr | 0gr | 50gr | +2gr (pérdida) |
| 102 | Pendiente | Carlos López | Pulsera Eslabones | 75gr | 0gr | 75gr | Pendiente |

---

## 🔧 PASO 6: Probar la Fase 2 (Movimientos Parciales)

### Flujo de prueba recomendado:

1. **Crear una nueva orden**:
   - Ir a: `Trazabilidad → Nueva Orden`
   - Seleccionar: Proceso = "Eslabonado", Responsable = "Juan Pérez"
   - Peso Oro: `100`
   - Agregar materiales: Piedras Preciosas = `30`
   - Peso Total: `130` (se calcula automático)
   - Guardar

2. **Verificar en la lista**:
   - Ir a: `Trazabilidad → Lista`
   - Debe aparecer con estado **PENDIENTE**
   - Columnas: ORO = 100, MAT = 30, TOTAL = 130

3. **Agregar devolución parcial**:
   - Ir a: `Trazabilidad → Nueva Orden → Agregar Devolución`
   - Consecutivo: `101` (o el que acabas de crear)
   - Peso Oro: `40`
   - Piedras Preciosas: `15`
   - Peso Total: `55`
   - Guardar

4. **Verificar estado**:
   - Estado debe cambiar a **PARCIAL**
   - Saldo: 60gr Oro + 15gr Piedras = 75gr pendientes

5. **Agregar devolución final con merma a favor**:
   - Consecutivo: `101`
   - Peso Oro: `65` (¡más de los 60gr pendientes!)
   - Piedras Preciosas: `5`
   - Peso Total: `70`
   - Guardar

6. **Verificar resultado**:
   - Estado: **MERMA A FAVOR** 💜
   - Oro recibido: 105gr vs 100gr entregados = **+5gr**
   - En la lista: Merma = **-5gr** (verde con +)

---

## ❓ SOLUCIÓN DE PROBLEMAS

### "Error de conexión a la base de datos"
- Verifica que MySQL esté corriendo en el panel de XAMPP
- Verifica que la base de datos `trazabilidad` exista en phpMyAdmin
- Verifica que `db.php` tenga las credenciales correctas (root, sin password)

### "404 Not Found"
- Verifica que la carpeta esté en `C:\xampp\htdocs\app_oro`
- Verifica la URL: `http://localhost/app_oro/frontend/login.html`

### "Acceso denegado"
- Verifica que el archivo `db.php` apunte a `localhost`, no a Hostinger

### La página se ve mal (sin CSS)
- Presiona `Ctrl + F5` para recargar sin caché
- Verifica que Apache esté corriendo

---

## 🔄 PARA VOLVER A HOSTINGER

Cuando quieras subir a producción:

1. Restaura el archivo original:
   ```
   Copiar: db_hostinger.php → db.php
   ```

2. Sube los archivos por FTP a Hostinger

3. Aplica el SQL de migración en Hostinger:
   ```
   sql/migration_fase2.sql
   ```

---

## 📁 ESTRUCTURA EN XAMPP

```
C:\xampp\htdocs\app_oro\
├── backend\
│   ├── config\
│   │   ├── db.php          ← Configuración local (XAMPP)
│   │   └── db_hostinger.php ← Backup de producción
│   ├── controllers\
│   ├── models\
│   └── utils\
├── frontend\
│   ├── login.html
│   ├── home.html
│   ├── trazabilidad_form.html
│   ├── trazabilidad_list.html
│   └── ...
├── sql\
│   ├── setup_xampp.sql     ← Script completo para XAMPP
│   └── migration_fase2.sql ← Migración para Hostinger
├── uploads\
└── INSTALAR_XAMPP.md       ← Esta guía
```

---

## ✅ CHECKLIST FINAL

- [ ] Archivos copiados a `C:\xampp\htdocs\app_oro`
- [ ] Base de datos `trazabilidad` creada en phpMyAdmin
- [ ] `db.php` configurado con credenciales locales (root / sin password)
- [ ] Apache iniciado en XAMPP
- [ ] MySQL iniciado en XAMPP
- [ ] Login funciona en `http://localhost/app_oro/frontend/login.html`
- [ ] Puedo crear órdenes con peso Oro + Materiales
- [ ] Puedo agregar devoluciones parciales
- [ ] La merma se calcula automáticamente
- [ ] El estado "Merma a Favor" aparece correctamente

---

**¡Listo para presentar a los socios! 🎉**

Si tienes algún problema, dime el mensaje de error exacto.
