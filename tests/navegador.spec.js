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
      // El gráfico NO pinta textos: descripción, análisis y fuente son
      // shortcodes aparte para poder maquetarlos por libre.
      await expect(fig.locator('.uhp-texto')).toHaveCount(0);
      await expect(fig).not.toContainText('Fuente:');
    }

    expect(errores).toEqual([]);
  });

  test('el shortcode del gráfico no emite ningún texto de análisis', async ({ page }) => {
    // El enunciado es explícito: «todos los gráficos shortcode sin texto
    // adicional». Aquí se comprueba pieza a pieza, para que nadie vuelva a
    // colgar prosa dentro de la tarjeta.
    await page.goto(BASE + '/paginas/graficos.html');

    const fig = page.locator('[data-uhp-grafico]').first();
    await expect(fig.locator('.uhp-g__lienzo svg')).toHaveCount(1, { timeout: 20000 });
    // El esqueleto se retira cuando el hidratado termina: hasta entonces
    // la figura aún no tiene su contenido definitivo.
    await expect(fig.locator('.uhp-skeleton')).toHaveCount(0, { timeout: 20000 });

    // Los únicos hijos directos admitidos son el título, la barra y el
    // lienzo. Cualquier párrafo dentro de la figura sería texto colado.
    const dentro = await fig.evaluate((nodo) => ({
      parrafos: nodo.querySelectorAll('p').length,
      hijos: Array.from(nodo.children).map((n) => n.className),
      // Todo lo que no sea el título, la barra de herramientas ni el
      // dibujo: si el shortcode colara prosa, aparecería aquí.
      textoAjeno: Array.from(nodo.children)
        .filter((n) => !n.matches('.uhp-g__titulo, .uhp-g__barra, .uhp-g__lienzo'))
        .map((n) => n.textContent.trim())
        .join(' ')
    }));

    expect(dentro.parrafos).toBe(0);
    dentro.hijos.forEach((c) => {
      expect(
        ['uhp-g__titulo', 'uhp-g__barra', 'uhp-g__lienzo'].some((k) => c.includes(k)),
        'hijo inesperado en la tarjeta del gráfico: ' + c
      ).toBe(true);
    });
    expect(dentro.textoAjeno).toBe('');
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
test.describe('Textos de una vista', () => {

  test('llegan en el HTML y no dependen de JavaScript', async ({ page }) => {
    // Se renderizan en servidor: sin JavaScript el texto tiene que estar.
    await page.route('**/*.js', (route) => route.abort());
    await page.goto(BASE + '/paginas/maqueta.html');

    const columna = page.locator('[data-columna="texto"]');
    await expect(columna.locator('.uhp-texto--descripcion')).toHaveCount(1);
    await expect(columna.locator('.uhp-texto--interpretacion')).toHaveCount(1);
    await expect(columna.locator('.uhp-texto--resumen')).toHaveCount(1);
    await expect(columna.locator('.uhp-texto--cifras')).toHaveCount(1);
    await expect(columna.locator('.uhp-texto--fuente')).toContainText('Fuente:');

    // Los textos redactados son largos por norma del proyecto.
    const descripcion = await columna.locator('.uhp-texto--descripcion').textContent();
    expect(descripcion.trim().length).toBeGreaterThan(200);

    // El título es su propio shortcode y respeta la etiqueta pedida.
    const titulo = page.locator('.uhp-titulo');
    await expect(titulo).toHaveCount(1);
    expect(await titulo.evaluate((n) => n.tagName)).toBe('H2');
    await expect(titulo).not.toHaveText('');

    // El atajo agrupado pinta las cuatro piezas de una vez.
    await expect(page.locator('[data-columna="grupo"] .uhp-texto')).toHaveCount(4);
  });

  test('el gráfico y sus textos se maquetan en columnas independientes', async ({ page }) => {
    const errores = vigilar(page);
    await page.goto(BASE + '/paginas/maqueta.html');

    const fig = page.locator('[data-uhp-grafico]');
    await expect(fig.locator('.uhp-g__lienzo svg')).toHaveCount(1, { timeout: 20000 });

    // El gráfico vive en su columna y los textos en la suya: ni uno solo
    // de los textos cuelga de la figura.
    await expect(page.locator('[data-columna="grafico"] .uhp-texto')).toHaveCount(0);
    await expect(page.locator('[data-columna="texto"] .uhp-texto')).toHaveCount(5);

    // Con titulo="no" la tarjeta ni siquiera lleva su cabecera.
    await expect(fig.locator('.uhp-g__titulo')).toHaveCount(0);

    // Están de verdad lado a lado, no apilados.
    const g = await page.locator('[data-columna="grafico"]').boundingBox();
    const t = await page.locator('[data-columna="texto"]').boundingBox();
    expect(t.x).toBeGreaterThan(g.x + g.width - 2);

    expect(errores).toEqual([]);
  });
});

/* ================================================================== */
test.describe('Geomapa (D3plus)', () => {

  /* Un PNG de un píxel en lugar de las teselas reales: la suite no debe
     depender de la red, y lo que se comprueba es que el componente PIDE la
     capa base y la coloca, no cómo se ve una tesela de CARTO. */
  const TESELA = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mM8w8DwHwAExAIsF7hMWQAAAABJRU5ErkJggg==',
    'base64'
  );

  async function sinRed(page) {
    const pedidas = [];
    // Expresión regular y no glob: las teselas salen de subdominios
    // (a|b|c).basemaps.cartocdn.com y un patrón «**/host/**» no casa,
    // porque exige una barra donde solo hay un punto. Con el glob mal
    // puesto la interceptación no ocurría y la suite salía a la red sin
    // que nadie lo notara.
    await page.route(/basemaps\.cartocdn\.com|tile\.openstreetmap\.org/, (route) => {
      pedidas.push(route.request().url());
      return route.fulfill({ status: 200, contentType: 'image/png', body: TESELA });
    });
    return pedidas;
  }

  test('pinta los 64 municipios y colorea solo los que traen cifra', async ({ page }) => {
    const errores = vigilar(page);
    await sinRed(page);
    await page.goto(BASE + '/paginas/geomapa.html');

    const fig = page.locator('[data-caso="sin-teselas"] [data-uhp-geomapa]');
    const trazados = fig.locator('g.d3plus-geomap-paths path');
    await expect(trazados).toHaveCount(64, { timeout: 25000 });

    // prev_lpm_municipios documenta diez municipios: diez con color de la
    // rampa y cincuenta y cuatro con el relleno de «sin dato». Que el resto
    // NO caiga en el extremo bajo de la escala es la diferencia entre un
    // mapa correcto y uno que inventa un mínimo donde no hay dato.
    const relleno = await fig.evaluate((nodo) => {
      const fills = Array.from(nodo.querySelectorAll('g.d3plus-geomap-paths path'))
        .map((p) => p.getAttribute('fill'));
      return {
        sinDato: fills.filter((f) => f === '#EDF1F5').length,
        conColor: fills.filter((f) => f !== '#EDF1F5').length
      };
    });
    expect(relleno.sinDato).toBe(54);
    expect(relleno.conColor).toBe(10);

    expect(errores).toEqual([]);
  });

  test('la geometría no se invierte: ningún municipio ocupa el mapa entero', async ({ page }) => {
    // D3 decide el interior de un polígono por el sentido de giro de su
    // anillo, con el criterio contrario al del RFC 7946. Un anillo al revés
    // no se ve mal: se ve como el mundo entero menos el municipio, y basta
    // uno para que el departamento se reduzca a un punto. Esta prueba
    // vigila el sentido que fija UHP_Topojson.
    await page.goto(BASE + '/paginas/geomapa.html');

    const fig = page.locator('[data-caso="sin-teselas"] [data-uhp-geomapa]');
    await expect(fig.locator('g.d3plus-geomap-paths path')).toHaveCount(64, { timeout: 25000 });

    const medidas = await fig.evaluate((nodo) => {
      const lienzo = nodo.querySelector('.uhp-geo__lienzo').getBoundingClientRect();
      const areas = [];
      nodo.querySelectorAll('g.d3plus-geomap-paths path').forEach((p) => {
        const c = p.getBBox();
        areas.push((c.width * c.height) / (lienzo.width * lienzo.height));
      });
      areas.sort((a, b) => b - a);
      return { mayor: areas[0], menor: areas[areas.length - 1], total: areas.length };
    });

    // Ningún municipio puede ocupar ni la mitad del lienzo: el mayor de
    // Nariño no llega a esa proporción ni de lejos.
    expect(medidas.mayor).toBeLessThan(0.5);
    expect(medidas.menor).toBeGreaterThan(0);
    expect(medidas.total).toBe(64);
  });

  test('la capa base se enciende y se apaga desde el shortcode', async ({ page }) => {
    const pedidas = await sinRed(page);
    await page.goto(BASE + '/paginas/geomapa.html');

    const con = page.locator('[data-caso="con-teselas"] [data-uhp-geomapa]');
    const sin = page.locator('[data-caso="sin-teselas"] [data-uhp-geomapa]');
    await expect(con.locator('g.d3plus-geomap-paths path')).toHaveCount(64, { timeout: 25000 });
    await expect(sin.locator('g.d3plus-geomap-paths path')).toHaveCount(64, { timeout: 25000 });

    // Con teselas="si" el mapa las pide y las coloca.
    await expect(con.locator('image.d3plus-geomap-tile')).not.toHaveCount(0, { timeout: 15000 });
    expect(pedidas.length).toBeGreaterThan(0);

    // Con teselas="no" no se pide ni una.
    await expect(sin.locator('image.d3plus-geomap-tile')).toHaveCount(0);

    // La atribución del proveedor es obligatoria cuando hay capa base, y
    // no debe aparecer cuando no la hay.
    await expect(con.locator('.d3plus-attribution')).toContainText('OpenStreetMap');
    await expect(sin.locator('.d3plus-attribution')).toHaveCount(0);
  });

  test('cada municipio se anuncia con su nombre y su cifra', async ({ page }) => {
    await page.goto(BASE + '/paginas/geomapa.html');
    const fig = page.locator('[data-caso="sin-teselas"] [data-uhp-geomapa]');
    await expect(fig.locator('g.d3plus-geomap-paths path')).toHaveCount(64, { timeout: 25000 });

    const etiquetas = await fig.evaluate((nodo) =>
      Array.from(nodo.querySelectorAll('g.d3plus-geomap-paths path'))
        .map((p) => p.getAttribute('aria-label') || '')
    );

    // Ninguna puede quedar vacía ni con el «false» que produce D3plus
    // cuando no se le da un accesor de etiqueta.
    etiquetas.forEach((e) => {
      expect(e.length, 'trazado sin texto accesible').toBeGreaterThan(3);
      expect(e).not.toContain('false');
    });
    expect(etiquetas.filter((e) => /sin dato publicado/i.test(e)).length).toBe(54);
  });

  test('la leyenda muestra la rampa, el rango y el aviso de sin dato', async ({ page }) => {
    await page.goto(BASE + '/paginas/geomapa.html');
    const fig = page.locator('[data-caso="sin-teselas"] [data-uhp-geomapa]');
    await expect(fig.locator('g.d3plus-geomap-paths path')).toHaveCount(64, { timeout: 25000 });

    await expect(fig.locator('.uhp-geo__escala i')).toHaveCount(5);
    await expect(fig.locator('.uhp-geo__rango span')).toHaveCount(2);
    await expect(fig.locator('.uhp-geo__sindato')).toContainText('Sin dato');
    await expect(fig.locator('.uhp-geo__leyenda strong')).not.toHaveText('');

    // leyenda="no" no la pinta.
    const oscuro = page.locator('[data-caso="oscuro"] [data-uhp-geomapa]');
    await expect(oscuro.locator('.uhp-geo__leyenda')).toHaveCount(0);
  });

  test('una vista subregional se dibuja sobre las 13 subregiones', async ({ page }) => {
    const errores = vigilar(page);
    await page.goto(BASE + '/paginas/geomapa.html');

    const fig = page.locator('[data-caso="subregiones"] [data-uhp-geomapa]');
    await expect(fig).toHaveAttribute('data-nivel', 'subregion');

    // Trece subregiones, no sesenta y cuatro municipios: el nivel lo
    // decide la vista y arrastra consigo topología y claves.
    const trazados = fig.locator('g.d3plus-geomap-paths path');
    await expect(trazados).toHaveCount(13, { timeout: 25000 });

    // Los informes documentan once de las trece.
    const relleno = await fig.evaluate((nodo) => {
      const fills = Array.from(nodo.querySelectorAll('g.d3plus-geomap-paths path'))
        .map((p) => p.getAttribute('fill'));
      return {
        sinDato: fills.filter((f) => f === '#EDF1F5').length,
        conColor: fills.filter((f) => f !== '#EDF1F5').length
      };
    });
    expect(relleno.conColor).toBe(11);
    expect(relleno.sinDato).toBe(2);

    // El nombre cartográfico llega al texto accesible.
    const etiquetas = await fig.evaluate((nodo) =>
      Array.from(nodo.querySelectorAll('g.d3plus-geomap-paths path'))
        .map((p) => p.getAttribute('aria-label') || '').join(' | ')
    );
    expect(etiquetas).toContain('Centro');
    expect(etiquetas).toContain('Telembí');

    expect(errores).toEqual([]);
  });

  test('las subregiones llegan disueltas, sin las fronteras municipales', async ({ page }) => {
    // La geometría subregional se reconstruye uniendo los municipios de
    // cada subregión por cancelación de aristas. Si esa unión dejara de
    // funcionar, en vez de trece polígonos se verían los sesenta y cuatro
    // municipios sueltos, cada uno con su borde.
    await page.goto(BASE + '/paginas/geomapa.html');

    const fig = page.locator('[data-caso="subregiones"] [data-uhp-geomapa]');
    await expect(fig.locator('g.d3plus-geomap-paths path')).toHaveCount(13, { timeout: 25000 });

    const contornos = await fig.evaluate((nodo) =>
      Array.from(nodo.querySelectorAll('g.d3plus-geomap-paths path'))
        .map((p) => (p.getAttribute('d') || '').split('M').length - 1)
    );

    // Un subtrazado por polígono: doce subregiones continuas y Pacífico
    // Sur, que suma sus islas. Ninguna puede traer un subtrazado por
    // municipio.
    contornos.forEach((n) => {
      expect(n, 'subregión con ' + n + ' contornos: no se disolvió').toBeLessThanOrEqual(4);
      expect(n).toBeGreaterThan(0);
    });
  });

  test('una vista sin territorio avisa en vez de romperse', async ({ page }) => {
    await page.goto(BASE + '/paginas/geomapa.html');
    const caso = page.locator('[data-caso="no-territorial"]');
    await expect(caso.locator('[data-uhp-geomapa]')).toHaveCount(0);
    await expect(caso.locator('.uhp-error')).toContainText('no nombra un territorio');
    // Y dice cuáles sí valen, que es lo que necesita quien maqueta.
    await expect(caso.locator('.uhp-error')).toContainText('prev_lpm_municipios');
  });

  test('cada topología se descarga una sola vez para toda la página', async ({ page }) => {
    const niveles = [];
    page.on('request', (r) => {
      const u = r.url();
      if (u.includes('/wp-json/urkunina/v1/topojson')) {
        niveles.push(/nivel=subregion/.test(u) ? 'subregion' : 'municipio');
      }
    });
    await sinRed(page);
    await page.goto(BASE + '/paginas/geomapa.html');
    await expect(page.locator('[data-caso="subregiones"] g.d3plus-geomap-paths path'))
      .toHaveCount(13, { timeout: 25000 });
    await page.waitForTimeout(1500);

    // Tres geomapas municipales y uno subregional: dos descargas en total,
    // una por nivel. Es para lo que está restCache.
    expect(niveles.filter((n) => n === 'municipio').length).toBe(1);
    expect(niveles.filter((n) => n === 'subregion').length).toBe(1);
  });

  test('el tema oscuro tiñe el territorio sin dato con la paleta de la escena', async ({ page }) => {
    await page.goto(BASE + '/paginas/geomapa.html');
    const fig = page.locator('[data-caso="oscuro"] [data-uhp-geomapa]');
    await expect(fig.locator('g.d3plus-geomap-paths path')).toHaveCount(64, { timeout: 25000 });

    const relleno = await fig.evaluate((nodo) => {
      const cuenta = {};
      nodo.querySelectorAll('g.d3plus-geomap-paths path').forEach((p) => {
        const f = p.getAttribute('fill');
        cuenta[f] = (cuenta[f] || 0) + 1;
      });
      return cuenta;
    });
    // cancer_municipios documenta siete municipios; el resto va con el
    // relleno translúcido del tema oscuro, no con el gris del claro.
    expect(relleno['rgba(255,255,255,.08)']).toBe(57);
    expect(relleno['#EDF1F5']).toBeUndefined();
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

  test('carga el módulo de mapa del plugin, no solo Leaflet', async ({ page }) => {
    // El tablero construye su mapa a través de UHPMapa. Que Leaflet esté
    // cargado no basta: si uhp-mapa.js no llega, el mapa no se dibuja.
    // Esta comprobación existe porque esa dependencia faltaba y la suite
    // no lo detectaba: la página de prueba lo cargaba por su cuenta.
    await page.goto(BASE + '/paginas/tablero.html');

    const cargado = await page.evaluate(() => ({
      uhpMapa: typeof window.UHPMapa,
      leaflet: typeof window.L,
      renderer: typeof window.UHPRenderer,
      d3plus: typeof window.d3plus
    }));
    expect(cargado.uhpMapa).toBe('object');
    expect(cargado.leaflet).toBe('object');
    expect(cargado.renderer).toBe('object');
    expect(cargado.d3plus).toBe('object');

    // Y no queda ningún mensaje de error en el hueco del mapa.
    await expect(page.locator('.uhp-db__mapa .uhp-error')).toHaveCount(0);
  });

  test('viste la paleta del objeto 3D', async ({ page }) => {
    // Los tokens del tablero tienen que ser los mismos que los del
    // objeto 3D: si alguien retoca la paleta de la escena, esta prueba
    // avisa de que el tablero se quedó atrás.
    await page.goto(BASE + '/paginas/objeto-3d.html');
    const escena = await page.evaluate(() => {
      const cs = getComputedStyle(document.querySelector('.uhp3d'));
      return {
        verde: cs.getPropertyValue('--uhp3d-verde').trim(),
        verdeClaro: cs.getPropertyValue('--uhp3d-verde-claro').trim(),
        amarillo: cs.getPropertyValue('--uhp3d-amarillo').trim(),
        fondo: cs.getPropertyValue('--uhp3d-fondo').trim(),
        panel: cs.getPropertyValue('--uhp3d-panel').trim(),
        panelBorde: cs.getPropertyValue('--uhp3d-panel-borde').trim(),
        texto: cs.getPropertyValue('--uhp3d-texto').trim(),
        textoMedio: cs.getPropertyValue('--uhp3d-texto-medio').trim(),
        textoTenue: cs.getPropertyValue('--uhp3d-texto-tenue').trim()
      };
    });

    await page.goto(BASE + '/paginas/tablero.html');
    const tablero = await page.evaluate(() => {
      const cs = getComputedStyle(document.querySelector('.uhp-db'));
      return {
        verde: cs.getPropertyValue('--uhp-db-verde').trim(),
        verdeClaro: cs.getPropertyValue('--uhp-db-verde-claro').trim(),
        amarillo: cs.getPropertyValue('--uhp-db-amarillo').trim(),
        fondo: cs.getPropertyValue('--uhp-db-fondo').trim(),
        panel: cs.getPropertyValue('--uhp-db-panel').trim(),
        panelBorde: cs.getPropertyValue('--uhp-db-panel-borde').trim(),
        texto: cs.getPropertyValue('--uhp-db-texto').trim(),
        textoMedio: cs.getPropertyValue('--uhp-db-texto-medio').trim(),
        textoTenue: cs.getPropertyValue('--uhp-db-texto-tenue').trim()
      };
    });

    expect(tablero).toEqual(escena);
  });

  test('los gráficos del panel se tiñen para fondo oscuro', async ({ page }) => {
    await page.goto(BASE + '/paginas/tablero.html');
    await expect(page.locator('[data-uhp-zona="grafico"] svg')).toHaveCount(1, { timeout: 20000 });
    await expect(page.locator('[data-uhp-zona="grafico"] svg text')).not.toHaveCount(0, { timeout: 20000 });

    const tintas = await page.evaluate(() => {
      const textos = document.querySelectorAll('[data-uhp-zona="grafico"] svg text');
      const set = new Set();
      textos.forEach((t) => set.add(t.getAttribute('fill') || getComputedStyle(t).fill));
      return Array.from(set);
    });

    // D3plus pinta los ejes en tonos para fondo claro si no se le dice lo
    // contrario. Aquí toda la tinta tiene que ser la del tema oscuro.
    const esperadas = ['#A9B7C1', '#FFD500', '#E7EDF1'];
    tintas.forEach((t) => {
      expect(esperadas, 'tinta inesperada en el gráfico del tablero: ' + t).toContain(t);
    });
    expect(tintas.length).toBeGreaterThan(0);
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
