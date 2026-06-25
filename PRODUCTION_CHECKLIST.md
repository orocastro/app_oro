# Checklist de Diagnóstico: Ruta `/materiales` en Producción

## **Problema**
En producción (`https://app.tutorempresa.com/materiales`), aparece la pantalla de login en lugar de la lista de materiales, aunque estés logueado.

---

## **Causa Probable**
La ruta `materiales` no está mapeada en el archivo `index.php`, o el despliegue no incluyó los cambios recientes. El fallback devuelve `login.html`.

---

## **Checklist: 5 Pasos (10 minutos)**

### **PASO 1: Verificar que la ruta existe en el código fuente**
**Qué revisar:** Confirmar que `index.php` tiene el mapeo de la vista `materiales`.

**Comando en tu máquina local:**
```bash
grep -n "materiales" c:\Users\USUARIO\OneDrive\xampp\htdocs\app_oro\index.php
```

**Qué buscar:**
```php
'materiales' => 'materiales.html',
```

**Si aparece:** ✅ El código local tiene el mapeo. Ir a **PASO 2**.  
**Si NO aparece:** ❌ Agregar la línea (línea ~174 en el view_map):
```php
'materiales' => 'materiales.html',
```

---

### **PASO 2: Verificar que el archivo vista existe en producción**
**Qué revisar:** Conectarse por SSH/SFTP al servidor y confirmar que `frontend/materiales.html` existe.

**Comando SSH en producción:**
```bash
ls -la /ruta/a/app_oro/frontend/ | grep materiales
```

**Qué esperar:**
```
-rw-r--r-- ... materiales.html
```

**Si existe:** ✅ Ir a **PASO 3**.  
**Si NO existe:** ❌ Necesitas hacer deploy/subir el archivo.

---

### **PASO 3: Validar que `index.php` en producción tiene el mapeo actualizado**
**Qué revisar:** Conectarse por SSH al servidor y confirmar que la línea `'materiales'` está en el view_map.

**Comando SSH en producción:**
```bash
grep -n "materiales" /ruta/a/app_oro/index.php
```

**Qué esperar:**
```
174:'materiales' => 'materiales.html',
```

**Si aparece:** ✅ El código en prod está actualizado. Ir a **PASO 4**.  
**Si NO aparece:** ❌ El deploy fue incompleto. Vuelve a desplegar `index.php`.

---

### **PASO 4: Limpiar el OPcache de PHP**
**Qué revisar:** Si usas PHP con OPcache habilitado, el servidor podría seguir ejecutando la versión vieja de `index.php`.

**Opción A: Reiniciar PHP-FPM (si usas Nginx/PHP-FPM):**
```bash
sudo systemctl restart php-fpm
# o si es PHP 7.4, 8.0, etc.
sudo systemctl restart php8.0-fpm
```

**Opción B: Reiniciar Apache (si usas Apache + mod_php):**
```bash
sudo systemctl restart apache2
```

**Opción C: Limpiar OPcache manualmente (si tienes acceso al código):**
Crea un archivo temporal `clear_opcache.php`:
```php
<?php
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "OPcache limpiado";
} else {
    echo "OPcache no disponible";
}
?>
```
Accede por HTTP: `https://app.tutorempresa.com/clear_opcache.php` y luego **bórralo**.

**Si se reinició:** ✅ Continúa con **PASO 5**.

---

### **PASO 5: Probar la ruta en navegador**
**Qué revisar:** Accede a la URL desde el navegador y verifica que:

1. **Estés logueado:** Busca la cookie de sesión o intenta acceder a `/home` primero.
2. **La ruta funciona:** Abre `/materiales` directamente o usa el enlace desde el dashboard.

**Pasos exactos:**
1. Abre `https://app.tutorempresa.com/` → deberías ver login.
2. Ingresa credenciales (ej: `andressud@hotmail.com` / `74348010`).
3. Espera a que cargue `/home`.
4. Busca el botón/card "Gestión de Materiales" y haz clic.
5. **O** abre directamente `https://app.tutorempresa.com/materiales` en una nueva pestaña.

**Resultado esperado:**
- ✅ **Correcto:** Lista de materiales con tabla CRUD.
- ❌ **Incorrecto:** Sigue apareciendo login → vuelve al PASO 3 y revisa si el deploy fue real.

---

## **Troubleshooting Rápido**

| Síntoma | Causa | Solución |
|---------|-------|----------|
| Aparece login tras sesión activa | Ruta no mapeada en `index.php` | Revisar PASO 1 y 3 |
| Archivo `materiales.html` no existe | Deploy incompleto | Subir archivo del local al servidor |
| Se ve el contenido viejo tras cambios | OPcache en PHP | Reiniciar PHP-FPM o Apache (PASO 4) |
| La URL es correcta pero devuelve 404 | `.htaccess` o rewrite rules incorrectas | Revisar `.htaccess` para permitir rutas sin extensión .php |
| Sesión se pierde al entrar directo a `/materiales` | Cookie/session path mal configurado | Revisar `php.ini` → `session.cookie_path` = `/` |

---

## **Validaciones Rápidas (Command Line)**

Si tienes acceso SSH, puedes hacer esto directamente:

```bash
# 1. Verificar archivo existe
[ -f /ruta/a/app_oro/frontend/materiales.html ] && echo "✅ materiales.html existe" || echo "❌ No existe"

# 2. Verificar index.php tiene el mapeo
grep -q "'materiales'" /ruta/a/app_oro/index.php && echo "✅ Mapeo en index.php" || echo "❌ Mapeo no encontrado"

# 3. Verificar permisos de lectura
ls -l /ruta/a/app_oro/frontend/materiales.html | awk '{print $1}'
# Debe tener: -rw-r--r-- (permisos 644 o 755)

# 4. Reiniciar PHP
sudo systemctl restart php-fpm && echo "✅ PHP reiniciado"
```

---

## **Próximos Pasos**

Una vez que funcione `/materiales`:

1. **Prueba la funcionalidad completa:**
   - Crear un material.
   - Editar un material.
   - Eliminar un material (respetando protecciones).

2. **Verifica que se refleja en el formulario de Trazabilidad:**
   - Ve a `/trazabilidad`.
   - En "Materiales (Entrega)" deberían aparecer todos los que acabas de crear.

3. **Revisa los logs en producción:**
   - `tail -f /ruta/a/app_oro/logs/app-$(date +%Y-%m-%d).log`
   - Busca errores con "materiales" o "ERROR".

---

## **Contacto**

Si tras estos pasos sigue sin funcionar:
1. Comparte el output del **PASO 3** (el resultado de `grep`).
2. Comparte el output del **PASO 5** (qué ves exactamente en el navegador).
3. Comparte el último log de error de la app: `tail -50 /ruta/a/app_oro/logs/app-2026-02-*.log`.
