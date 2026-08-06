/**
 * Servidor Local para Trazabilidad de Joyería
 * Ejecutar: node server_local.js
 * Abrir: http://localhost:8767
 * 
 * Este servidor:
 * 1. Sirve los archivos del frontend real (no la carpeta demo)
 * 2. Rutea URLs como /home, /trazabilidad, etc.
 * 3. Elimina código PHP de los HTML (ya que no hay PHP instalado)
 * 4. Inyecta window.BASE_URL para que los assets funcionen
 * 5. Simula las APIs con datos en memoria
 */

const http = require('http');
const fs = require('fs');
const path = require('path');
const url = require('url');

const PORT = 8767;
const BASE_DIR = path.resolve(__dirname);
const FRONTEND_DIR = path.join(BASE_DIR, 'frontend');
const BACKEND_DIR = path.join(BASE_DIR, 'backend');
const ASSETS_DIR = path.join(FRONTEND_DIR, 'assets');

const MIME = {
  '.html': 'text/html',
  '.css': 'text/css',
  '.js': 'application/javascript',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.gif': 'image/gif',
  '.svg': 'image/svg+xml',
  '.json': 'application/json',
  '.woff2': 'font/woff2',
  '.woff': 'font/woff',
  '.ttf': 'font/ttf',
  '.ico': 'image/x-icon',
};

// Rutas de vista → archivo HTML
const VIEW_MAP = {
  '/': 'login.html',
  '/login': 'login.html',
  '/home': 'home.html',
  '/responsables': 'responsables.html',
  '/procesos': 'procesos.html',
  '/productos': 'productos.html',
  '/usuarios': 'usuarios.html',
  '/trazabilidad': 'trazabilidad_form.html',
  '/trazabilidad_list': 'trazabilidad_list.html',
  '/trazabilidad_edit': 'trazabilidad_edit.html',
  '/materiales': 'materiales.html',
};

// Datos simulados para las APIs
const MOCK_DATA = {
  procesos: [
    {id:1,nombre:'SOLDAR'},{id:2,nombre:'BOMBA'},{id:3,nombre:'ENGASTAR'},
    {id:4,nombre:'PULIR'},{id:5,nombre:'LASER'},{id:6,nombre:'SALE DEL TALLER'},
    {id:7,nombre:'ARMAR'},{id:8,nombre:'BARRIL'},{id:9,nombre:'BLANQUEAR'},
    {id:10,nombre:'CORTAR'},{id:11,nombre:'DIAMANTAR'},{id:12,nombre:'FELPA'},
    {id:13,nombre:'FUNDIR'},{id:14,nombre:'LAMINAR'},{id:15,nombre:'RECOCER'},
    {id:16,nombre:'SOLDAR CADENA'},{id:17,nombre:'TROQUELAR'},{id:18,nombre:'VACIAR'}
  ],
  productos: [
    {id:1,nombre:'Cadena Van Cleef'},{id:2,nombre:'Pulsera Van Cleef'},
    {id:3,nombre:'Anillo Van Cleef'},{id:4,nombre:'Collar Perlas'},
    {id:5,nombre:'Aretes Diamante'},{id:6,nombre:'Balines'},
    {id:7,nombre:'Gucci Cadena'},{id:8,nombre:'Gucci Pulsera'},
    {id:9,nombre:'Medalla'}
  ],
  responsables: [
    {id:1,nombre:'Adriana'},{id:2,nombre:'Kike'},{id:3,nombre:'Kate'},
    {id:4,nombre:'Juan'},{id:5,nombre:'Pedro'},
    {id:6,nombre:'Cristian Correa'},{id:7,nombre:'Diego Andrés'},
    {id:8,nombre:'Henry Puentes'},{id:9,nombre:'Fernanda Peña'}
  ],
  materiales: [
    {id:1,nombre:'Piedras Esmeralda'},{id:2,nombre:'Piedras Rubí'},
    {id:3,nombre:'Piedras Zafiro'},{id:4,nombre:'Soldadura'},
    {id:5,nombre:'Baño de Oro'},{id:6,nombre:'Baño de Rodio'},
    {id:7,nombre:'Pulimento'},{id:8,nombre:'Balin'},{id:9,nombre:'Cierre'}
  ]
};

// Función para eliminar bloques PHP de un HTML
function stripPhp(html) {
  // Eliminar bloques <?php ... ?>
  html = html.replace(/<[?%]=?[\s\S]*?[?%]>/g, '');
  // Eliminar require_once, etc. que queden sueltos
  html = html.replace(/require_once[\s\S]*?;/g, '');
  return html;
}

// Función para inyectar variables JS necesarias
function injectBaseUrl(html) {
  const inject = `<script>window.APP_DEBUG=false;window.BASE_URL='/';</script>`;
  // Insertar después de <head> o al inicio del body
  if (html.includes('<head>')) {
    html = html.replace('<head>', '<head>\n' + inject);
  } else {
    html = inject + '\n' + html;
  }
  return html;
}

// Servir archivo estático
function serveStatic(res, filePath) {
  const ext = path.extname(filePath).toLowerCase();
  fs.readFile(filePath, (err, data) => {
    if (err) {
      if (err.code === 'ENOENT') {
        res.writeHead(404, { 'Content-Type': 'text/html' });
        res.end('<h1>404 Not Found</h1>');
      } else {
        res.writeHead(500, { 'Content-Type': 'text/html' });
        res.end('<h1>500 Error</h1>');
      }
      return;
    }
    res.writeHead(200, { 
      'Content-Type': MIME[ext] || 'application/octet-stream',
      'Access-Control-Allow-Origin': '*'
    });
    res.end(data);
  });
}

// Servir HTML (strip PHP + inject BASE_URL)
function serveHtml(res, filePath) {
  fs.readFile(filePath, 'utf8', (err, data) => {
    if (err) {
      res.writeHead(404, { 'Content-Type': 'text/html' });
      res.end('<h1>404 Not Found</h1>');
      return;
    }
    let html = stripPhp(data);
    html = injectBaseUrl(html);
    res.writeHead(200, { 
      'Content-Type': 'text/html',
      'Access-Control-Allow-Origin': '*'
    });
    res.end(html);
  });
}

// Respuesta JSON
function sendJson(res, obj) {
  res.writeHead(200, { 
    'Content-Type': 'application/json',
    'Access-Control-Allow-Origin': '*'
  });
  res.end(JSON.stringify(obj));
}

const server = http.createServer((req, res) => {
  const parsed = url.parse(req.url, true);
  let pathname = parsed.pathname;

  // CORS preflight
  if (req.method === 'OPTIONS') {
    res.writeHead(200, { 
      'Access-Control-Allow-Origin': '*',
      'Access-Control-Allow-Methods': 'GET, POST, PUT, DELETE, OPTIONS',
      'Access-Control-Allow-Headers': 'Content-Type, Authorization'
    });
    res.end();
    return;
  }

  // === API MOCK ===
  if (pathname.startsWith('/api/')) {
    const apiPath = pathname.replace('/api/', '');
    
    // Auth mock
    if (apiPath === 'login' && req.method === 'POST') {
      sendJson(res, { success: true, message: 'Login OK', data: { id: 1, nombre: 'Orlando', is_admin: true } });
      return;
    }
    if (apiPath === 'logout') {
      sendJson(res, { success: true, message: 'Logout OK' });
      return;
    }
    if (apiPath === 'usuarios' && req.method === 'GET') {
      // Para que el home muestre las tarjetas de admin
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify([{id:1}]));
      return;
    }

    // Catálogos
    if (apiPath === 'procesos') {
      // Filtrar procesos excluidos
      const filtrados = MOCK_DATA.procesos.filter(p => 
        !['MERMA A FAVOR','INGRESA PURO','INGRESAN PIEDRAS','Inventario','Facturacion','Control de Calidad'].includes(p.nombre)
      );
      sendJson(res, { success: true, data: filtrados });
      return;
    }
    if (apiPath === 'productos') {
      sendJson(res, { success: true, data: MOCK_DATA.productos });
      return;
    }
    if (apiPath === 'responsables') {
      sendJson(res, { success: true, data: MOCK_DATA.responsables });
      return;
    }
    if (apiPath === 'materiales') {
      sendJson(res, { success: true, data: MOCK_DATA.materiales });
      return;
    }

    // Ordenes mock (las órdenes se manejan con localStorage en el frontend)
    if (apiPath === 'ordenes' || apiPath === 'trazabilidad') {
      sendJson(res, { success: true, data: [], pagination: { total: 0 } });
      return;
    }
    if (apiPath.startsWith('ordenes/')) {
      sendJson(res, { success: false, message: 'Orden no encontrada' });
      return;
    }
    if (apiPath.startsWith('trazabilidad/')) {
      sendJson(res, { success: false, message: 'Not found' });
      return;
    }

    // Default API
    sendJson(res, { success: true, message: 'API mock OK' });
    return;
  }

  // === ARCHIVOS ESTÁTICOS (assets) ===
  if (pathname.startsWith('/frontend/assets/')) {
    const assetPath = pathname.replace('/frontend/assets/', '');
    const fullPath = path.join(ASSETS_DIR, assetPath);
    serveStatic(res, fullPath);
    return;
  }

  // === VISTAS HTML ===
  const viewFile = VIEW_MAP[pathname];
  if (viewFile) {
    const fullPath = path.join(FRONTEND_DIR, viewFile);
    serveHtml(res, fullPath);
    return;
  }

  // Si no coincide ninguna ruta, ir al login
  const defaultPath = path.join(FRONTEND_DIR, 'login.html');
  serveHtml(res, defaultPath);
});

server.listen(PORT, () => {
  console.log('========================================');
  console.log('  🚀 Servidor Local Trazabilidad');
  console.log('========================================');
  console.log('');
  console.log('  Abre tu navegador en:');
  console.log('');
  console.log('    http://localhost:' + PORT + '/home');
  console.log('    http://localhost:' + PORT + '/trazabilidad');
  console.log('    http://localhost:' + PORT + '/trazabilidad_list');
  console.log('');
  console.log('  Para detener: presiona Ctrl+C');
  console.log('========================================');
});
