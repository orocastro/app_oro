# Solución Rápida: `/materiales` en Producción

## **Diagnóstico Confirmado**

✅ Tu código **local** (`index.php`) SÍ tiene el mapeo `'materiales' => 'materiales.html',` (línea 168).

❌ Tu código **en producción** NO tiene este mapeo → por eso devuelve login como fallback.

---

## **Causa Inmediata**

El último `git push` / deploy de archivos **no incluyó** los cambios recientes a `index.php` y `frontend/materiales.html`.

---

## **Solución en 3 Pasos**

### **Paso 1: Subir `index.php` actualizado a producción**

**Opción A (Git):**
```bash
git add index.php
git commit -m "Agregar ruta materiales en view_map"
git push origin main  # o tu rama
```

**Opción B (SFTP/Manualmente):**
- Descarga `c:/...xampp/htdocs/app_oro/index.php` (versión local).
- Súbelo a `/home/tu_usuario/public_html/materiales/index.php` en el servidor.
- Asegúrate de que la línea 168 es: `'materiales' => 'materiales.html',`

---

### **Paso 2: Subir `frontend/materiales.html` a producción**

**Opción A (Git):**
```bash
git add frontend/materiales.html
git commit -m "Agregar vista de gestión de materiales"
git push origin main
```

**Opción B (SFTP):**
- Descarga `c:/...xampp/htdocs/app_oro/frontend/materiales.html` (versión local).
- Súbelo a `/home/tu_usuario/public_html/materiales/frontend/materiales.html`.

---

### **Paso 3: Reiniciar PHP en producción**

**SSH en el servidor:**
```bash
# Si es PHP-FPM (Nginx):
sudo systemctl restart php-fpm

# O PHP específico (ej: 8.0):
sudo systemctl restart php8.0-fpm

# Si es Apache + mod_php:
sudo systemctl restart apache2

# O limpiar OPcache desde terminal:
php -r 'opcache_reset();'
```

---

## **Validación Inmediata**

Después de los 3 pasos:

1. **Abre el navegador**: `https://app.tutorempresa.com/materiales`
2. **Espera 5 segundos** (por si hay caché).
3. **Resultado:**
   - ✅ **Correcto**: Ves tabla de materiales (CRUD).
   - ❌ **Incorrecto**: Sigue apareciendo login → repite **Paso 3** (reinicio PHP).

---

## **Si Usas Git (Recomendado)**

```bash
# En tu máquina local
cd c:\Users\USUARIO\OneDrive\xampp\htdocs\app_oro

# Ver cambios pendientes
git status

# Agregar cambios recientes
git add -A

# Confirmar
git commit -m "Implementar materiales: ruta, vista, CRUD y bloqueo de peso"

# Subir a producción
git push origin main

# En el servidor (SSH)
cd /home/tu_usuario/public_html/materiales
git pull origin main
sudo systemctl restart php-fpm
```

---

## **Checklist Final**

- [ ] `index.php` actualizado en producción (línea 168: `'materiales' => 'materiales.html',`).
- [ ] `frontend/materiales.html` existe en producción.
- [ ] PHP reiniciado tras cambios.
- [ ] `/materiales` abre la tabla CRUD, no login.
- [ ] Botón "Gestión de Materiales" en `/home` funciona.

---

**¿Necesitas que verifique algo más o que suba los cambios por ti?**
