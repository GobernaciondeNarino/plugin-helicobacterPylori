/* Descarga las librerías de terceros que usan las pruebas de navegador.

   Se guardan en tests/vendor (fuera del control de versiones) para que las
   pruebas no dependan de que la CDN responda en cada ejecución. Si ya están,
   no se vuelven a bajar. */
'use strict';

const fs = require('fs');
const path = require('path');
const https = require('https');

const DESTINO = path.join(__dirname, 'vendor');

const THREE = 'https://cdn.jsdelivr.net/npm/three@0.180.0';
const JSM = THREE + '/examples/jsm';

const ARCHIVOS = [
  {
    nombre: 'd3plus.min.js',
    url: 'https://cdn.jsdelivr.net/npm/d3plus@2.0.0/build/d3plus.full.min.js'
  },
  {
    // d3 suelto, el que usa el tablero. No basta con d3plus: su bundle
    // expone window.d3plus, no window.d3, y el tablero llama a
    // d3.geoMercator, d3.zoom y d3.interpolateRgbBasis directamente.
    nombre: 'd3.min.js',
    url: 'https://cdn.jsdelivr.net/npm/d3@7/dist/d3.min.js'
  },
  {
    nombre: 'leaflet.js',
    url: 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'
  },
  {
    nombre: 'leaflet.css',
    url: 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css'
  },
  /* Espejo de Three.js y sus complementos. El servidor de pruebas los
     sirve en local, incluida la ruta absoluta /npm/three@…/+esm que los
     complementos importan internamente: así la suite no depende de la
     red y se comprueba de paso que una sola instancia de la librería
     basta para todos ellos. */
  { nombre: 'three-core.js', url: THREE + '/+esm' },
  { nombre: 'three-orbit.js', url: JSM + '/controls/OrbitControls.js/+esm' },
  { nombre: 'three-composer.js', url: JSM + '/postprocessing/EffectComposer.js/+esm' },
  { nombre: 'three-renderpass.js', url: JSM + '/postprocessing/RenderPass.js/+esm' },
  { nombre: 'three-bloom.js', url: JSM + '/postprocessing/UnrealBloomPass.js/+esm' },
  { nombre: 'three-bokeh.js', url: JSM + '/postprocessing/BokehPass.js/+esm' },
  { nombre: 'three-output.js', url: JSM + '/postprocessing/OutputPass.js/+esm' },
  { nombre: 'three-room.js', url: JSM + '/environments/RoomEnvironment.js/+esm' }
];

function descargar(url, destino) {
  return new Promise((resolve, reject) => {
    https.get(url, (res) => {
      if (res.statusCode >= 300 && res.statusCode < 400 && res.headers.location) {
        descargar(res.headers.location, destino).then(resolve, reject);
        return;
      }
      if (res.statusCode !== 200) {
        reject(new Error('HTTP ' + res.statusCode + ' al pedir ' + url));
        return;
      }
      const salida = fs.createWriteStream(destino);
      res.pipe(salida);
      salida.on('finish', () => salida.close(resolve));
      salida.on('error', reject);
    }).on('error', reject);
  });
}

async function principal() {
  if (!fs.existsSync(DESTINO)) {
    fs.mkdirSync(DESTINO, { recursive: true });
  }

  for (const archivo of ARCHIVOS) {
    const destino = path.join(DESTINO, archivo.nombre);
    if (fs.existsSync(destino) && fs.statSync(destino).size > 1000) {
      console.log('  ya está  ' + archivo.nombre);
      continue;
    }
    process.stdout.write('  bajando  ' + archivo.nombre + ' … ');
    await descargar(archivo.url, destino);
    console.log(Math.round(fs.statSync(destino).size / 1024) + ' KB');
  }
}

principal().catch((err) => {
  console.error('No se pudieron descargar las librerías de prueba: ' + err.message);
  process.exit(1);
});
