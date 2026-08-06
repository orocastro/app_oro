const http = require('http');
const fs = require('fs');
const path = require('path');
const url = require('url');

const PORT = 8767;
const BASE_DIR = path.resolve(__dirname);
const DEMO_DIR = path.join(BASE_DIR, 'demo');

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
};

const server = http.createServer((req, res) => {
  const parsed = url.parse(req.url, true);
  let pathname = parsed.pathname;

  if (req.method === 'OPTIONS') {
    res.writeHead(200, { 'Access-Control-Allow-Origin': '*', 'Access-Control-Allow-Methods': 'GET, POST, PUT, DELETE, OPTIONS', 'Access-Control-Allow-Headers': 'Content-Type' });
    res.end();
    return;
  }

  if (pathname === '/' || pathname === '/home') {
    pathname = '/trazabilidad_list.html';
  }

  const filePath = pathname;
  const fullPath = path.join(DEMO_DIR, filePath);
  const safePath = path.resolve(fullPath);
  const safeBase = path.resolve(DEMO_DIR);
  
  if (!safePath.startsWith(safeBase)) {
    res.writeHead(403, { 'Content-Type': 'text/html' });
    res.end('<html><body><h1>403 Forbidden</h1></body></html>');
    return;
  }

  fs.readFile(safePath, (err, data) => {
    if (err) {
      if (err.code === 'ENOENT') {
        res.writeHead(404, { 'Content-Type': 'text/html' });
        res.end('<html><body><h1>404 Not Found</h1></body></html>'); 
        return;
      }
      res.writeHead(500, { 'Content-Type': 'text/html' });
      res.end('<html><body><h1>500 Error</h1></body></html>');
      return;
    }
    const ext = path.extname(safePath).toLowerCase();
    res.writeHead(200, { 'Content-Type': MIME[ext] || 'application/octet-stream' });
    res.end(data);
  });
});

server.listen(PORT, () => {
  console.log('========================================');
  console.log('Demo server running!');
  console.log('Open your browser to:');
  console.log('  http://localhost:' + PORT + '/trazabilidad_list.html');
  console.log('  http://localhost:' + PORT + '/trazabilidad_form.html');
  console.log('========================================');
});
