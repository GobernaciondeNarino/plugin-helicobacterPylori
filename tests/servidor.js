/* Servidor estático de pruebas.

   Sirve tres cosas para que Playwright ejercite el plugin en un navegador
   real sin necesidad de instalar WordPress:

     /assets/…            los CSS y JS reales del plugin,
     /wp-json/urkunina/…  las respuestas de la API, desde tests/fixtures,
     /paginas/…           las páginas con el marcado real de los shortcodes.

   Los alias vendor-* apuntan a las librerías descargadas en tests/vendor,
   de modo que las pruebas no dependen de una CDN. */
'use strict';

const http = require('http');
const fs = require('fs');
const path = require('path');
const url = require('url');

const RAIZ = path.resolve(__dirname, '..');
const FIXTURES = path.join(__dirname, 'fixtures');
const PAGINAS = path.join(__dirname, 'paginas');
const VENDOR = path.join(__dirname, 'vendor');

const TIPOS = {
  '.html': 'text/html; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.geojson': 'application/json; charset=utf-8',
  '.png': 'image/png',
  '.svg': 'image/svg+xml'
};

/* Alias de las librerías de terceros usadas por las páginas de prueba. */
const VENDOR_ALIAS = {
  '/assets/js/vendor-d3plus.js': path.join(VENDOR, 'd3plus.min.js'),
  '/assets/js/vendor-leaflet.js': path.join(VENDOR, 'leaflet.js'),
  '/assets/css/leaflet-vendor.css': path.join(VENDOR, 'leaflet.css')
};

/**
 * Traduce una ruta de la API al archivo de fixture correspondiente.
 *
 * /render?view=X&type=Y  →  render--X--Y.json
 * /mapa?indicador=X      →  mapa--X.json
 */
function fixtureDe(ruta, query) {
  const base = ruta.replace(/^\/wp-json\/urkunina\/v1/, '') || '/';

  if (base === '/render') {
    const vista = (query.view || '').replace(/[^a-z0-9_-]/gi, '');
    const tipo = (query.type || '').replace(/[^a-z0-9_-]/gi, '');
    if (!vista) { return null; }
    const conTipo = path.join(FIXTURES, `render--${vista}--${tipo}.json`);
    if (tipo && fs.existsSync(conTipo)) { return conTipo; }
    return path.join(FIXTURES, `render--${vista}.json`);
  }

  if (base === '/mapa') {
    const ind = (query.indicador || '').replace(/[^a-z0-9_-]/gi, '');
    const conInd = path.join(FIXTURES, `mapa--${ind}.json`);
    if (ind && fs.existsSync(conInd)) { return conInd; }
    return path.join(FIXTURES, 'mapa.json');
  }

  const nombre = base.replace(/^\//, '').replace(/\/$/, '') || 'index';
  return path.join(FIXTURES, `${nombre.replace(/\//g, '--')}.json`);
}

function servir(res, archivo, estado) {
  fs.readFile(archivo, (err, datos) => {
    if (err) {
      res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
      res.end('No encontrado: ' + archivo);
      return;
    }
    res.writeHead(estado || 200, {
      'Content-Type': TIPOS[path.extname(archivo)] || 'application/octet-stream',
      'Cache-Control': 'no-store'
    });
    res.end(datos);
  });
}

const servidor = http.createServer((req, res) => {
  const partes = url.parse(req.url, true);
  let ruta = decodeURIComponent(partes.pathname);

  // Ninguna ruta puede salir del árbol del proyecto.
  if (ruta.includes('..')) {
    res.writeHead(400);
    res.end('Ruta no válida');
    return;
  }

  if (VENDOR_ALIAS[ruta]) {
    servir(res, VENDOR_ALIAS[ruta]);
    return;
  }

  /* Espejo de Three.js. Los complementos importan internamente la ruta
     absoluta /npm/three@VERSION/+esm; se sirve desde el mismo archivo que
     el módulo principal, igual que hace jsDelivr, de modo que el navegador
     comparte una sola instancia de la librería. */
  if (ruta === '/npm/three@0.180.0/+esm') {
    servir(res, path.join(VENDOR, 'three-core.js'));
    return;
  }
  if (ruta.startsWith('/vendor/')) {
    servir(res, path.join(VENDOR, path.basename(ruta)));
    return;
  }

  if (ruta.startsWith('/wp-json/')) {
    const archivo = fixtureDe(ruta, partes.query);
    if (!archivo) {
      res.writeHead(400, { 'Content-Type': 'application/json' });
      res.end('{"code":"uhp_parametro","message":"Falta el parámetro view."}');
      return;
    }
    servir(res, archivo);
    return;
  }

  if (ruta === '/' || ruta === '') { ruta = '/paginas/tablero.html'; }
  if (ruta.startsWith('/paginas/')) {
    servir(res, path.join(PAGINAS, path.basename(ruta)));
    return;
  }
  if (ruta.startsWith('/assets/') || ruta.startsWith('/data/')) {
    servir(res, path.join(RAIZ, ruta));
    return;
  }

  res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
  res.end('No encontrado');
});

const PUERTO = Number(process.env.UHP_PUERTO || 8787);
servidor.listen(PUERTO, '127.0.0.1', () => {
  console.log('Servidor de pruebas en http://127.0.0.1:' + PUERTO);
});
