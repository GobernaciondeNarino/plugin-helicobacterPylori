/* Pruebas de navegador con Playwright.

   Abren en Chromium el marcado REAL que emiten los shortcodes y comprueban
   que cada componente llega a pintarse, que no hay errores de JavaScript y
   que las medidas del enunciado se cumplen (el tablero al 100 % de ancho y
   100vh de alto, la escena 3D anclada a su contenedor, los gráficos con
   color por serie y las leyendas correctas).

   Uso:  npx playwright test tests/navegador.spec.js */
'use strict';

const { test, expect } = require('@playwright/test');

const BASE = process.env.UHP_BASE || 'http://127.0.0.1:8787';

/* Ruido que no depende del plugin: el icono de pestaña que el servidor de
   pruebas no sirve y los recursos de terceros que puedan fallar según la
   red del entorno. Un error de JavaScript nunca entra aquí. */
const RUIDO = [/favicon/i, /net::ERR_CONNECTION_RESET/, /status of 404/];

/** Recoge los errores de consola y de página, descartando el ruido de red. */
function vigilar(page) {
  const errores = [];
  page.on('console', (msg) => {
    if (msg.type() !== 'error') { return; }
    const texto = msg.text();
    if (RUIDO.some((r) => r.test(texto))) { return; }
    errores.push('console: ' + texto);
  });
  // Un error de JavaScript sí es siempre un fallo del plugin.
  page.on('pageerror', (err) => { errores.push('pageerror: ' + err.message); });
  // Un recurso del propio plugin que no carga también lo es.
  page.on('requestfailed', (req) => {
    const url = req.url();
    if (url.includes('/assets/') || url.includes('/wp-json/')) {
      errores.push('recurso del plugin no cargó: ' + url);
    }
  });
  return errores;
}

/* ================================================================== */
test.describe('Objeto 3D', () => {

  test('la escena arranca, pinta la línea de tiempo y llena su contenedor', async ({ page }) => {
    const errores = vigilar(page);
    await page.goto(BASE + '/paginas/objeto-3d.html');

    const raiz = page.locator('[data-uhp3d-raiz]');
    await expect(raiz).toHaveCount(1);

    // La pantalla de carga se retira cuando la escena está lista.
    await expect(page.locator('.uhp3d__carga')).toHaveClass(/is-oculto/, { timeout: 45000 });

    // El riel de la línea de tiempo se pinta con sus diez momentos, del
    // contagio en la infancia al diagnóstico.
    const pasos = page.locator('.uhp3d__paso');
    await expect(pasos).toHaveCount(10);
    await expect(pasos.first()).toContainText('Contagio');
    await expect(pasos.last()).toContainText('Diagnóstico');
    await expect(pasos.first()).toHaveAttribute('aria-current', 'true');

    // La columna de lectura recibe el contenido del primer momento.
    await expect(page.locator('[data-uhp3d="titulo"]')).not.toHaveText('—');
    await expect(page.locator('[data-uhp3d="entradilla"]')).not.toHaveText('—');
    await expect(page.locator('[data-uhp3d="cuerpo"] .uhp3d__parrafo').first()).toBeVisible();
    await expect(page.locator('.uhp3d__cifra').first()).toBeVisible();

    // El lienzo tiene un contexto WebGL con dibujo real.
    const pintado = await page.evaluate(() => {
      const c = document.querySelector('.uhp3d__lienzo');
      if (!c || !c.width || !c.height) { return { ok: false, motivo: 'sin lienzo' }; }
      const gl = c.getContext('webgl2') || c.getContext('webgl');
      return { ok: !!gl, ancho: c.width, alto: c.height };
    });
    expect(pintado.ok).toBe(true);
    expect(pintado.ancho).toBeGreaterThan(300);

    // El contenedor ocupa el alto de la ventana, como pide `alto="100vh"`.
    const caja = await raiz.boundingBox();
    const ventana = page.viewportSize();
    expect(Math.abs(caja.height - ventana.height)).toBeLessThan(4);
    expect(caja.width).toBeGreaterThan(ventana.width * 0.95);

    expect(errores).toEqual([]);
  });

  test('los controles avanzan y pausan el recorrido', async ({ page }) => {
    await page.goto(BASE + '/paginas/objeto-3d.html');
    await expect(page.locator('.uhp3d__carga')).toHaveClass(/is-oculto/, { timeout: 45000 });

    const titulo = page.locator('[data-uhp3d="titulo"]');
    const primero = await titulo.textContent();

    await page.locator('[data-uhp3d="btn-siguiente"]').click();
    await expect(titulo).not.toHaveText(primero);
    await expect(page.locator('.uhp3d__paso').nth(1)).toHaveAttribute('aria-current', 'true');

    await page.locator('[data-uhp3d="btn-atras"]').click();
    await expect(titulo).toHaveText(primero);

    // Pausar cambia la etiqueta accesible del botón.
    const play = page.locator('[data-uhp3d="btn-play"]');
    await play.click();
    await expect(play).toHaveAttribute('aria-label', 'Reanudar recorrido');
  });

  test('embebida no se apropia del teclado de la página', async ({ page }) => {
    await page.goto(BASE + '/paginas/objeto-3d-embebido.html');
    await expect(page.locator('.uhp3d__carga')).toHaveClass(/is-oculto/, { timeout: 45000 });

    const titulo = page.locator('[data-uhp3d="titulo"]');
    const inicial = await titulo.textContent();

    // Con el foco fuera de la escena, la flecha derecha no debe moverla.
    await page.locator('h1').click();
    await page.keyboard.press('ArrowRight');
    await page.waitForTimeout(300);
    await expect(titulo).toHaveText(inicial);

    // Con el foco dentro, sí.
    await page.locator('[data-uhp3d-raiz]').focus();
    await page.keyboard.press('ArrowRight');
    await expect(titulo).not.toHaveText(inicial);
  });
});

/* ================================================================== */
test.describe('Gráficos', () => {

  test('las ocho vistas se dibujan con D3plus', async ({ page }) => {
    const errores = vigilar(page);
    await page.goto(BASE + '/paginas/graficos.html');

    const figuras = page.locator('[data-uhp-grafico]');
    await expect(figuras).toHaveCount(8);

    for (let i = 0; i < 8; i++) {
      const fig = figuras.nth(i);
      // Cada gráfico produce un SVG con formas dibujadas. D3plus crea el
      // SVG antes de pintar las marcas y las anima, de modo que la
      // comprobación tiene que reintentarse, no medirse una sola vez.
      const svg = fig.locator('.uhp-g__lienzo svg');
      await expect(svg).toHaveCount(1, { timeout: 20000 });

      const marcas = fig.locator('.uhp-g__lienzo svg rect, .uhp-g__lienzo svg path, .uhp-g__lienzo svg circle');
      await expect(marcas, 'la vista ' + (await fig.getAttribute('data-view')) + ' dibuja formas')
        .not.toHaveCount(0, { timeout: 20000 });

      // El título llega de la API y no queda con el valor de servidor.
      await expect(fig.locator('.uhp-g__titulo')).not.toHaveText('');
      // El análisis automático se pinta bajo el gráfico.
      await expect(fig.locator('.uhp-g__analisis p').first()).toBeVisible();
      // La atribución de fuente aparece.
      await expect(fig.locator('.uhp-g__fuente')).toContainText('Fuente:');
    }

    expect(errores).toEqual([]);
  });

  test('la barra de herramientas abre el detalle y la tabla de datos', async ({ page }) => {
    await page.goto(BASE + '/paginas/graficos.html');
    const fig = page.locator('[data-uhp-grafico]').first();
    await expect(fig.locator('.uhp-g__lienzo svg')).toHaveCount(1, { timeout: 20000 });

    await fig.locator('[data-accion="detalle"]').click();
    const modal = page.locator('.uhp-modal');
    await expect(modal).toBeVisible();
    await expect(modal).toContainText('Detalle del gráfico');
    await expect(modal).toContainText('Qué muestra');
    await page.keyboard.press('Escape');
    await expect(modal).toHaveCount(0);

    await fig.locator('[data-accion="datos"]').click();
    await expect(page.locator('.uhp-modal__tabla tbody tr')).not.toHaveCount(0);
    await page.locator('.uhp-modal__x').click();
    await expect(page.locator('.uhp-modal')).toHaveCount(0);
  });

  test('el selector cambia el tipo de gráfico en vivo', async ({ page }) => {
    await page.goto(BASE + '/paginas/graficos.html');
    const fig = page.locator('[data-uhp-grafico]').first();
    await expect(fig.locator('.uhp-g__lienzo svg')).toHaveCount(1, { timeout: 20000 });

    const select = fig.locator('.uhp-g__select');
    await expect(select).toBeVisible();

    const opciones = await select.locator('option').allTextContents();
    expect(opciones.length).toBeGreaterThan(1);

    await select.selectOption('bar');
    await expect(fig).toHaveAttribute('data-type', 'bar', { timeout: 15000 });
    await expect(fig.locator('.uhp-g__lienzo svg')).toHaveCount(1);
  });

  test('el color va por serie y no por punto', async ({ page }) => {
    await page.goto(BASE + '/paginas/graficos.html');

    // prev_subregion tiene dos series (LPM e infección) sobre once
    // subregiones: si el color fuera por punto habría 22 colores.
    const fig = page.locator('[data-view="prev_subregion"]');
    await expect(fig.locator('.uhp-g__lienzo svg')).toHaveCount(1, { timeout: 20000 });
    await expect(fig.locator('.uhp-g__lienzo svg rect')).not.toHaveCount(0, { timeout: 20000 });

    const colores = await fig.evaluate((nodo) => {
      const formas = nodo.querySelectorAll('.uhp-g__lienzo svg rect');
      const set = new Set();
      formas.forEach((f) => {
        const c = f.getAttribute('fill');
        if (c && c !== 'none' && c !== 'transparent') { set.add(c); }
      });
      return Array.from(set);
    });
    expect(colores.length).toBeGreaterThan(0);
    expect(colores.length).toBeLessThanOrEqual(4);
  });
});

/* ================================================================== */
test.describe('Mapa', () => {

  test('pinta los 64 municipios sobre OpenStreetMap con su leyenda', async ({ page }) => {
    const errores = vigilar(page);
    await page.goto(BASE + '/paginas/mapa.html');

    const lienzo = page.locator('.uhp-mapa__lienzo');
    await expect(lienzo.locator('.leaflet-container, .leaflet-pane')).not.toHaveCount(0, { timeout: 20000 });

    // Un camino SVG por municipio.
    const poligonos = lienzo.locator('.leaflet-overlay-pane path');
    await expect(poligonos).toHaveCount(64, { timeout: 20000 });

    // Leyenda con la escala de color y el aviso de «sin dato».
    await expect(page.locator('.uhp-mapa__leyenda')).toBeVisible();
    await expect(page.locator('.uhp-mapa__escala i')).toHaveCount(5);
    await expect(page.locator('.uhp-mapa__sindato')).toContainText('Sin dato');

    // Atribución obligatoria de OpenStreetMap.
    await expect(page.locator('.leaflet-control-attribution')).toContainText('OpenStreetMap');

    expect(errores).toEqual([]);
  });

  test('cambiar de indicador recolorea sin recargar la geometría', async ({ page }) => {
    let geoPeticiones = 0;
    page.on('request', (r) => {
      if (r.url().includes('/wp-json/urkunina/v1/geo')) { geoPeticiones++; }
    });

    await page.goto(BASE + '/paginas/mapa.html');
    await expect(page.locator('.leaflet-overlay-pane path')).toHaveCount(64, { timeout: 20000 });
    await expect(page.locator('.uhp-mapa__leyenda strong')).toContainText('LPM');

    await page.locator('[data-uhp-indicador]').selectOption('hpylori');
    await expect(page.locator('.uhp-mapa__leyenda strong')).toContainText('H. pylori', { timeout: 15000 });

    // La geometría se pide una sola vez en toda la sesión.
    expect(geoPeticiones).toBe(1);
  });

  test('los municipios son accesibles con teclado', async ({ page }) => {
    await page.goto(BASE + '/paginas/mapa.html');
    await expect(page.locator('.leaflet-overlay-pane path')).toHaveCount(64, { timeout: 20000 });

    const primero = page.locator('.leaflet-overlay-pane path').first();
    await expect(primero).toHaveAttribute('tabindex', '0');
    await expect(primero).toHaveAttribute('role', 'button');
    const etiqueta = await primero.getAttribute('aria-label');
    expect(etiqueta).toBeTruthy();
    expect(etiqueta.length).toBeGreaterThan(3);
  });
});

/* ================================================================== */
test.describe('Tablero', () => {

  test('ocupa el 100 % de ancho y 100vh de alto', async ({ page }) => {
    const errores = vigilar(page);
    await page.goto(BASE + '/paginas/tablero.html');

    const db = page.locator('[data-uhp-dashboard]');
    await expect(db).toHaveCount(1);

    const caja = await db.boundingBox();
    const ventana = page.viewportSize();
    expect(Math.abs(caja.height - ventana.height)).toBeLessThan(4);
    expect(Math.abs(caja.width - ventana.width)).toBeLessThan(4);

    expect(errores).toEqual([]);
  });

  test('pinta cifras, controles, mapa y gráfico', async ({ page }) => {
    await page.goto(BASE + '/paginas/tablero.html');

    // Cintillo de indicadores.
    await expect(page.locator('.uhp-db__kpi')).toHaveCount(6, { timeout: 20000 });
    await expect(page.locator('.uhp-db__kpi-val').first()).not.toHaveText('');

    // Controles y filtros.
    await expect(page.locator('.uhp-db__grupo')).not.toHaveCount(0);
    await expect(page.locator('.uhp-db__select')).not.toHaveCount(0);
    await expect(page.locator('.uhp-db__chip')).not.toHaveCount(0);
    await expect(page.locator('.uhp-db__zona')).toHaveCount(3);

    // Mapa central con los 64 municipios.
    await expect(page.locator('.uhp-db__mapa .leaflet-overlay-pane path')).toHaveCount(64, { timeout: 25000 });

    // Panel de gráficos con su análisis.
    await expect(page.locator('[data-uhp-zona="grafico"] svg')).toHaveCount(1, { timeout: 20000 });
    await expect(page.locator('[data-uhp-zona="grafico-titulo"]')).not.toHaveText('');
    await expect(page.locator('[data-uhp-zona="analisis"] .uhp-db__txt').first()).toBeVisible();
  });

  test('los filtros del mapa y del gráfico responden', async ({ page }) => {
    await page.goto(BASE + '/paginas/tablero.html');
    await expect(page.locator('.uhp-db__mapa .leaflet-overlay-pane path')).toHaveCount(64, { timeout: 25000 });

    // Cambiar el indicador del mapa.
    const selInd = page.locator('.uhp-db__select').first();
    await selInd.selectOption('cancer');
    await expect(page.locator('.uhp-mapa__leyenda strong')).toContainText('Cáncer', { timeout: 15000 });

    // Cambiar el gráfico con un acceso rápido.
    const titulo = page.locator('[data-uhp-zona="grafico-titulo"]');
    const antes = await titulo.textContent();
    await page.locator('.uhp-db__chip', { hasText: 'Tamizaje' }).click();
    await expect(titulo).not.toHaveText(antes, { timeout: 15000 });
    await expect(page.locator('[data-uhp-zona="grafico"] svg')).toHaveCount(1);
  });

  test('al pulsar un municipio se abre su ficha', async ({ page }) => {
    await page.goto(BASE + '/paginas/tablero.html');
    await expect(page.locator('.uhp-db__mapa .leaflet-overlay-pane path')).toHaveCount(64, { timeout: 25000 });

    const ficha = page.locator('[data-uhp-zona="ficha"]');
    await expect(ficha).not.toHaveClass(/is-activa/);

    // Se pulsa el municipio del centro del mapa.
    await page.locator('.leaflet-overlay-pane path').nth(20).click({ force: true });
    await expect(ficha).toHaveClass(/is-activa/, { timeout: 10000 });
    await expect(ficha.locator('.uhp-db__ficha-t')).not.toHaveText('');
    await expect(ficha).toContainText('DIVIPOLA');

    await ficha.locator('.uhp-db__ficha-x').click();
    await expect(ficha).not.toHaveClass(/is-activa/);
  });

  test('los paneles laterales se pliegan', async ({ page }) => {
    await page.goto(BASE + '/paginas/tablero.html');
    await expect(page.locator('.uhp-db__grupo')).not.toHaveCount(0, { timeout: 20000 });

    const panel = page.locator('[data-uhp-panel="controles"]');
    const anchoInicial = (await panel.boundingBox()).width;

    await page.locator('[data-uhp-toggle="controles"]').click();
    await expect(panel).toHaveClass(/is-plegado/);
    await page.waitForTimeout(400);
    expect((await panel.boundingBox()).width).toBeLessThan(anchoInicial);
  });

  test('en móvil las zonas se apilan sin desbordar', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 780 });
    await page.goto(BASE + '/paginas/tablero.html');
    await expect(page.locator('.uhp-db__kpi')).not.toHaveCount(0, { timeout: 20000 });

    const desborde = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth
    );
    expect(desborde).toBeLessThanOrEqual(1);
  });
});

/* ================================================================== */
test.describe('Componentes de servidor', () => {

  test('las cifras, la ficha y la tabla llegan en el HTML', async ({ page }) => {
    // Sin JavaScript: comprueba que estos componentes no dependen de él.
    await page.route('**/*.js', (route) => route.abort());
    await page.goto(BASE + '/paginas/servidor.html');

    await expect(page.locator('.uhp-kpi__tarjeta')).toHaveCount(6);
    await expect(page.locator('.uhp-kpi__valor').first()).not.toHaveText('');

    await expect(page.locator('.uhp-ficha__dl dt')).not.toHaveCount(0);
    await expect(page.locator('.uhp-ficha')).toContainText('2015000100064');

    await expect(page.locator('.uhp-tabla tbody tr')).toHaveCount(11);
    await expect(page.locator('.uhp-dato')).toContainText('%');
  });
});

/* ================================================================== */
test.describe('Convivencia', () => {

  test('mapa, gráfico y escena 3D funcionan juntos en una página', async ({ page }) => {
    const errores = vigilar(page);
    await page.goto(BASE + '/paginas/convivencia.html');

    // El gráfico se dibuja.
    await expect(page.locator('[data-uhp-grafico] .uhp-g__lienzo svg')).toHaveCount(1, { timeout: 25000 });
    // El mapa se dibuja.
    await expect(page.locator('.uhp-mapa__lienzo .leaflet-overlay-pane path')).toHaveCount(64, { timeout: 25000 });
    // La escena 3D arranca.
    await expect(page.locator('.uhp3d__carga')).toHaveClass(/is-oculto/, { timeout: 45000 });
    await expect(page.locator('.uhp3d__paso')).toHaveCount(10);

    // Una sola instancia de cada librería en la página.
    const conteo = await page.evaluate(() => ({
      d3plus: typeof window.d3plus,
      leaflet: typeof window.L,
      etiquetasD3plus: document.querySelectorAll('script[src*="d3plus"]').length,
      etiquetasLeaflet: document.querySelectorAll('script[src*="leaflet"]').length,
      globalesPropias: ['UHP', 'UHPcore', 'UHPRenderer', 'UHPMapa', 'UHP3D']
        .filter((k) => typeof window[k] !== 'undefined')
    }));
    expect(conteo.d3plus).toBe('object');
    expect(conteo.leaflet).toBe('object');
    expect(conteo.etiquetasD3plus).toBe(1);
    expect(conteo.etiquetasLeaflet).toBe(1);

    expect(errores).toEqual([]);
  });

  test('el plugin no declara ningún mapa de importaciones', async ({ page }) => {
    await page.goto(BASE + '/paginas/convivencia.html');
    // Un documento solo admite uno: si el plugin lo declarase, chocaría con
    // el de cualquier otro plugin que ya lo haga.
    const importmaps = await page.locator('script[type="importmap"]').count();
    expect(importmaps).toBe(0);
  });

  test('el CSS del plugin no alcanza al contenido de la página', async ({ page }) => {
    await page.goto(BASE + '/paginas/convivencia.html');
    await expect(page.locator('[data-uhp-grafico] .uhp-g__lienzo svg')).toHaveCount(1, { timeout: 25000 });

    // Un h1 fuera de los contenedores del plugin conserva su estilo propio.
    const h1 = await page.locator('h1').first().evaluate((n) => {
      const s = getComputedStyle(n);
      return { color: s.color, familia: s.fontFamily };
    });
    // El plugin usa Hind Madurai dentro de sus contenedores; fuera, no.
    expect(h1.familia.toLowerCase()).not.toContain('hind madurai');
  });
});
