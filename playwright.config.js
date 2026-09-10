/* Configuración de Playwright para las pruebas de navegador del plugin.

   El navegador va a través del proxy del entorno cuando lo hay, porque la
   escena 3D importa Three.js de una CDN. El servidor estático de pruebas
   (tests/servidor.js) se levanta y se apaga solo. */
'use strict';

const { defineConfig, devices } = require('@playwright/test');

const PUERTO = Number(process.env.UHP_PUERTO || 8787);
const BASE = 'http://127.0.0.1:' + PUERTO;

/* El proxy del entorno, si existe, con 127.0.0.1 excluido para que el
   servidor local no salga por él. */
const proxyUrl = process.env.HTTPS_PROXY || process.env.https_proxy || '';
const proxy = proxyUrl
  ? { server: proxyUrl, bypass: '127.0.0.1,localhost' }
  : undefined;

/* Navegador ya instalado en la imagen, si lo hay. Evita que Playwright
   intente descargar una compilación propia cuando la del entorno sirve. */
const fs = require('fs');
const CHROMIUM_LOCAL = process.env.UHP_CHROMIUM || '/opt/pw-browsers/chromium';
const executablePath = fs.existsSync(CHROMIUM_LOCAL) ? CHROMIUM_LOCAL : undefined;

module.exports = defineConfig({
  testDir: './tests',
  testMatch: '**/*.spec.js',
  // La escena 3D descarga Three.js y construye la geometría: necesita aire.
  timeout: 90000,
  expect: { timeout: 15000 },
  fullyParallel: false,
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  reporter: [['list']],

  use: {
    baseURL: BASE,
    headless: true,
    viewport: { width: 1440, height: 900 },
    locale: 'es-CO',
    timezoneId: 'America/Bogota',
    proxy,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure'
  },

  projects: [
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        // WebGL por software: el contenedor no tiene GPU.
        launchOptions: {
          executablePath,
          args: [
            '--use-gl=swiftshader',
            '--enable-unsafe-swiftshader',
            '--disable-gpu-sandbox',
            '--no-sandbox'
          ]
        }
      }
    }
  ],

  webServer: {
    command: 'node tests/servidor.js',
    url: BASE + '/paginas/tablero.html',
    reuseExistingServer: true,
    timeout: 30000,
    stdout: 'ignore',
    stderr: 'pipe'
  }
});
