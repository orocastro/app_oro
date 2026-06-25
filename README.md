# **Sistema de Trazabilidad de Joyería**

## **1. Descripción del Proyecto**

Este proyecto es un sistema de trazabilidad web desarrollado con PHP puro en el backend y HTML/CSS/JavaScript en el frontend. Permite a los usuarios registrar los movimientos de inventario de joyería (Entregas y Recibidos) a través de diferentes procesos y responsables, calculando automáticamente la merma y asociando fotografías a cada registro.

El diseño del frontend se basa en las plantillas proporcionadas, utilizando un esquema de color en tonos verdes (Teal) para el branding.

## **2. Arquitectura y Stack Tecnológico**

| Componente        | Tecnología                                     | Descripción                                                                                 |
| ----------------- | ---------------------------------------------- | ------------------------------------------------------------------------------------------- |
| **Frontend**      | HTML5, CSS (Tailwind CSS classes), JavaScript. | Interfaz de usuario, formularios CRUD, lógica de cálculo de merma y llamadas a la API.      |
| **Backend**       | PHP puro (>= 7.4), PDO.                        | Lógica de negocio, controladores RESTful, manejo de sesiones y gestión de archivos (fotos). |
| **Base de Datos** | MySQL.                                         | Almacenamiento de datos de catálogos y registros de trazabilidad.                           |


## **3. Estructura de Directorios**

La estructura del proyecto sigue una organización clara para separar el frontend, el backend y los recursos.

```text
/  
├── backend/  
│   ├── config/  
|   |   ├── app.php                     # Switch debug para apagar en producción
│   │   └── db.php                      # Clase de conexión a la base de datos (PDO)  
│   ├── controllers/  
│   │   ├── AuthController.php          # Lógica de Login/Logout  
│   │   ├── ProcesoController.php       # CRUD Procesos  
│   │   ├── ProductoController.php      # CRUD Productos
│   │   ├── ResponsableController.php   # CRUD Responsables
│   │   └── TrazabilidadController.php  # Lógica de Entrega/Recibido/Listado  
│   └── core/  
│       ├── Logger.php                  # generador de logs
│       └── Router.php                  # Enrutador básico del API REST  
├── frontend/  
│   ├── assets/
│   │   ├── css/
│   │   │   └── styles.css   
│   │   ├── js/
│   │   │   └── scripts.js 
│   │   ├── css/
│   │   │   └── atyles.css 
│   │   └── img/                        # Directorio de subida de archivos (creado en runtime)
│   │       └── {CONSECUTIVO}/          # Subcarpetas creadas automáticamente
│   ├── login.html                      # Vista de Login y Recuperación  
│   ├── home.html                       # Vista de navegación principal  
│   ├── responsables.html               # CRUD de Responsables  
│   ├── procesos.html                   # CRUD de Procesos  
│   ├── productos.html                  # CRUD de Productos  
│   ├── trazabilidad_form.html          # Formulario de Entrega/Recibido  
│   └── trazabilidad_list.html          # Tabla general de trazabilidad  
├── logs/
│   └── {logs}                          # archivos de errores creados automaticamente
├── sql/  
│   ├── schema.sql                      # Script de creación de tablas  
│   └── seed.sql                        # Script de datos iniciales  
├── .htaccess
├── README.md
└── index.php                           # Front Controller (Maneja el enrutamiento API y de Vistas)
```

## **4. Configuración y Despliegue**

### **4.1 Base de Datos**

1. **Crear Base de Datos:** Cree una base de datos MySQL (por ejemplo, trazabilidad_db).  
2. **Ejecutar Schema:** Ejecute el script sql/schema.sql para crear todas las tablas.  
3. **Cargar Datos Iniciales:** Ejecute el script sql/seed.sql para poblar las tablas de catálogo (Responsables, Procesos, Productos) y la tabla Usuarios.

**Nota:** Las claves de usuario en seed.sql están hasheadas (simulación).

### **4.2 Conexión PHP**

Asegúrese de que el archivo backend/config/db.php contenga las credenciales correctas para su base de datos local o de producción.

### **4.3 Usuarios Iniciales**

Los usuarios para acceder al sistema son:

| Nombre                          | Usuario (Email)        | Clave (Original) |
| ------------------------------- | ---------------------- | ---------------- |
| ALBEIRO ANDRES CUBIDES GUERRERO | andressud@hotmail.com  | 74348010         |
| VICTOR HUGO BOTERO CUARTAS      | ganoderico@hotmail.com | 16224901         |

## **5. Funcionalidades Clave**

* **Autenticación:** Login seguro y funcionalidad de recuperación de contraseña simulada.  
* **Catálogos (CRUD):** Gestión completa de Responsables, Procesos y Productos.  
* **Formulario Condicional:** Una sola vista (trazabilidad_form.html) maneja la lógica de **Entrega** y **Recibido**.  
* **Generación Automática:** El sistema genera automáticamente el CONSECUTIVO (para Entrega) y registra la FECHA DE ENTREGA/RECIBIDO.  
* **Búsqueda Rápida:** En el modo "Recibido", permite buscar un registro de Entrega pendiente usando el Consecutivo.  
* **Cálculo de Merma:** La MERMA se calcula automáticamente: PESO ENTREGADO - PESO RECIBIDO.  
* **Gestión de Archivos:** Las fotos de Entrega y Recibido se suben al directorio /frontend/assets/img/{CONSECUTIVO}.  
* **Vista General:** Tabla principal con todos los registros de trazabilidad, indicando estado (PENDIENTE/COMPLETADO) y enlaces para ver las fotos.