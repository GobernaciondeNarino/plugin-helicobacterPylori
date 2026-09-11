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

  test('un valor CERO se dibuja: no se descarta como si faltara', async ({ page }) => {
    /* El renderer descartaba las filas cuyo valor era cero. En la serie de
       producción científica eso borraba cinco años —2020, 2021, 2023, 2024
       y 2025— y la línea saltaba de 2019 a 2022 como si no hubiera habido
       años de por medio, cuando lo que hubo fue un vacío de publicaciones,
       que es justamente lo que había que ver. */
    await page.goto(BASE + '/paginas/graficos.html');

    const fig = page.locator('[data-view="publicaciones_anio"]');
    await expect(fig.locator('.uhp-g__lienzo svg')).toHaveCount(1, { timeout: 20000 });
    await expect(fig.locator('.uhp-g__lienzo svg text')).not.toHaveCount(0, { timeout: 20000 });

    const anios = await fig.evaluate((nodo) =>
      Array.from(nodo.querySelectorAll('.uhp-g__lienzo svg text')).map((t) => t.textContent)
    );
    // Los nueve años del archivo, con publicaciones o sin ellas.
    ['2018', '2019', '2020', '2021', '2022', '2023', '2024', '2025', '2026'].forEach((a) => {
      expect(anios, 'falta el año ' + a + ' en la serie').toContain(a);
    });
  });

  test('cada gráfico dibuja una marca por fila: no se recorta nada', async ({ page }) => {
    /* La comprobación de fondo: lo que publica la API es lo que se ve.

       Se cuentan las MARCAS, no los rótulos. D3plus escribe la etiqueta
       dentro de cada barra y la acorta cuando no cabe —con 23 productos de
       nombre largo eso pasa—, pero acortar un rótulo no es perder un dato:
       la barra está, y su nombre completo sigue en el tooltip y en la
       tabla de datos de la barra de herramientas. Lo que sí sería perder
       un dato es que faltara la marca. */
    await page.goto(BASE + '/paginas/graficos.html');
    await expect(page.locator('[data-uhp-grafico] .uhp-g__lienzo svg')).toHaveCount(8, { timeout: 25000 });
    await expect(page.locator('[data-view="metas_mga"] g.d3plus-Bar-shape > *'))
      .not.toHaveCount(0, { timeout: 20000 });

    const informe = await page.evaluate(async () => {
      // Cuántas marcas dibuja cada tipo por fila de datos.
      const POR_FILA = { bar: 'Bar', treemap: 'Rect', donut: 'Path', pie: 'Path' };
      const salida = [];

      for (const fig of document.querySelectorAll('[data-uhp-grafico]')) {
        const vista = fig.dataset.view;
        const tipo = fig.dataset.type;
        const d = await fetch('/wp-json/urkunina/v1/render?view=' + vista + '&type=' + tipo)
          .then((x) => x.json());
        const filas = (d.data || []).length;

        if (POR_FILA[tipo]) {
          const g = fig.querySelector('g.d3plus-' + POR_FILA[tipo] + '-shape');
          salida.push({ vista, tipo, filas, marcas: g ? g.children.length : 0 });
        } else if (tipo === 'stacked_bar') {
          // Formato largo: una marca por fila y medida.
          const medidas = (d.view.measures || []).length;
          const g = fig.querySelector('g.d3plus-Bar-shape');
          salida.push({ vista, tipo, filas: filas * medidas, marcas: g ? g.children.length : 0 });
        } else {
          // Líneas: una forma por serie, no por punto. Se comprueba que
          // dibuje algo; los puntos los cubre la prueba del valor cero.
          const g = fig.querySelector('g.d3plus-Line-shape');
          salida.push({ vista, tipo, filas: 1, marcas: g && g.children.length ? 1 : 0 });
        }
      }
      return salida;
    });

    informe.forEach((v) => {
      expect(v.filas, 'la vista ' + v.vista + ' llegó sin filas').toBeGreaterThan(0);
      expect(v.marcas, 'la vista ' + v.vista + ' (' + v.tipo + ') dibuja ' +
        v.marcas + ' marcas para ' + v.filas + ' filas').toBe(v.filas);
    });
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

  test('un territorio con conteo cero se colorea: cero no es «sin dato»', async ({ page }) => {
    /* El geomapa subregional descartaba las subregiones cuyo conteo era
       cero y las pintaba como «sin dato publicado». Son cosas distintas:
       de esas subregiones SÍ se sabe, y lo que se sabe es que no tienen
       casos documentados. */
    await page.goto(BASE + '/paginas/geomapa.html');

    const fig = page.locator('[data-caso="con-teselas"] [data-uhp-geomapa]');
    await expect(fig.locator('g.d3plus-geomap-paths path')).toHaveCount(64, { timeout: 25000 });

    const etiquetas = await fig.evaluate((nodo) =>
      Array.from(nodo.querySelectorAll('g.d3plus-geomap-paths path'))
        .map((p) => p.getAttribute('aria-label') || '')
    );
    // Los 9 municipios no priorizados no tienen orden de intervención: esos
    // sí son «sin dato». Los 55 restantes llevan su cifra.
    expect(etiquetas.filter((e) => /sin dato publicado/i.test(e)).length).toBe(9);
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

  test('una vista con dos indicadores por subregión dibuja uno y lo dice', async ({ page }) => {
    /* `prev_subregion` trae dos indicadores por subregión. En un gráfico de
       barras se ven los dos; en un mapa coroplético hay que elegir, porque
       un territorio no puede tener dos colores. El shortcode obliga a
       elegir y el título dice cuál se está dibujando. */
    await page.goto(BASE + '/paginas/geomapa.html');

    const fig = page.locator('[data-caso="serie"] [data-uhp-geomapa]');
    await expect(fig).toHaveAttribute('data-nivel', 'subregion');
    await expect(fig).toHaveAttribute('data-serie', 'Infección por H. pylori');
    await expect(fig.locator('g.d3plus-geomap-paths path')).toHaveCount(13, { timeout: 25000 });

    // El rótulo nombra la serie: sin eso el mapa no se puede leer.
    await expect(fig.locator('.uhp-geo__titulo')).toContainText('Infección por H. pylori');

    // Y son las cifras de ESA serie, no las de la otra.
    const etiquetas = await fig.evaluate((nodo) =>
      Array.from(nodo.querySelectorAll('g.d3plus-geomap-paths path'))
        .map((p) => p.getAttribute('aria-label') || '').join(' | ')
    );
    // Piedemonte Costero: 55 % de infección frente a 19,6 % de lesión.
    expect(etiquetas).toContain('55,0');
    expect(etiquetas).not.toContain('19,6');
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
    await expect(page.locator('.uhp-db__mapa .leaflet-overlay-pane path:not(.uhp-mapa__contorno)')).toHaveCount(64, { timeout: 25000 });

    // Panel de gráficos con su análisis.
    await expect(page.locator('[data-uhp-zona="grafico"] svg')).toHaveCount(1, { timeout: 20000 });
    await expect(page.locator('[data-uhp-zona="grafico-titulo"]')).not.toHaveText('');
    await expect(page.locator('[data-uhp-zona="analisis"] .uhp-db__txt').first()).toBeVisible();
  });

  test('los filtros del mapa y del gráfico responden', async ({ page }) => {
    await page.goto(BASE + '/paginas/tablero.html');
    await expect(page.locator('.uhp-db__mapa .leaflet-overlay-pane path:not(.uhp-mapa__contorno)')).toHaveCount(64, { timeout: 25000 });

    // Cambiar el indicador del mapa. Se apunta al selector por su atributo
    // y no por su posición: el panel de controles ha ganado grupos por
    // encima y «el primero» dejó de ser este.
    const selInd = page.locator('[data-uhp-indicador]');
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
    await expect(page.locator('.uhp-db__mapa .leaflet-overlay-pane path:not(.uhp-mapa__contorno)')).toHaveCount(64, { timeout: 25000 });

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

  test('el tema claro viste todo el tablero, no solo el fondo', async ({ page }) => {
    const errores = vigilar(page);
    await page.goto(BASE + '/paginas/tablero-claro.html');

    const db = page.locator('[data-uhp-dashboard]');
    await expect(db).toHaveClass(/uhp-db--claro/);
    await expect(db).toHaveAttribute('data-tema', 'claro');

    await expect(page.locator('.uhp-db__kpi')).toHaveCount(6, { timeout: 20000 });
    await expect(page.locator('.uhp-db__mapa .leaflet-overlay-pane path:not(.uhp-mapa__contorno)')).toHaveCount(64, { timeout: 25000 });

    // Ni una superficie oscura debe quedar dentro del tablero claro: eso
    // es lo que delataría un color literal olvidado en la hoja.
    const oscuras = await page.evaluate(() => {
      function lum(c) {
        const m = String(c).match(/[\d.]+/g);
        if (!m || m.length < 3) { return null; }
        if (m.length > 3 && Number(m[3]) < 0.5) { return null; }   // casi transparente
        return (0.2126 * Number(m[0]) + 0.7152 * Number(m[1]) + 0.0722 * Number(m[2])) / 255;
      }
      const malas = [];
      document.querySelectorAll('.uhp-db, .uhp-db *').forEach((n) => {
        // Lo que trae color de DATO queda fuera: las teselas y los
        // polígonos del mapa, y las muestras de la rampa de la leyenda,
        // cuyo extremo alto es rojo oscuro por definición. Aquí se
        // vigilan las superficies de la interfaz, no la escala.
        if (n.closest('.leaflet-pane')) { return; }
        if (n.closest('.uhp-mapa__escala, .uhp-geo__escala')) { return; }
        if (n.closest('.uhp-db__zona-pt') || n.classList.contains('uhp-db__zona-pt')) { return; }
        const l = lum(getComputedStyle(n).backgroundColor);
        if (l !== null && l < 0.3) {
          malas.push((n.className.baseVal || n.className || n.tagName) + ' :: ' +
            getComputedStyle(n).backgroundColor);
        }
      });
      return Array.from(new Set(malas));
    });
    expect(oscuras, 'superficie oscura dentro del tablero claro').toEqual([]);

    expect(errores).toEqual([]);
  });

  test('el tema decide también la capa base y la tinta de los gráficos', async ({ page }) => {
    await page.goto(BASE + '/paginas/tablero-claro.html');
    await expect(page.locator('.uhp-db__mapa .leaflet-overlay-pane path:not(.uhp-mapa__contorno)')).toHaveCount(64, { timeout: 25000 });

    // La capa base sigue al tema: un tablero claro con teselas oscuras es
    // el descuido más fácil al cambiar solo el tema, y aquí se vigila.
    await expect(page.locator('[data-uhp-dashboard]')).toHaveAttribute('data-teselas', 'claro');

    await expect(page.locator('[data-uhp-zona="grafico"] svg text')).not.toHaveCount(0, { timeout: 20000 });
    const tintas = await page.evaluate(() => {
      const set = new Set();
      document.querySelectorAll('[data-uhp-zona="grafico"] svg text').forEach((t) => {
        set.add(t.getAttribute('fill') || getComputedStyle(t).fill);
      });
      return Array.from(set);
    });
    // Las del tema claro del renderer; ninguna de las del oscuro.
    const claras = ['#5B6773', '#003366', '#0F172A'];
    tintas.forEach((t) => {
      expect(claras, 'tinta inesperada en el gráfico del tablero claro: ' + t).toContain(t);
    });
    expect(tintas.length).toBeGreaterThan(0);
  });

  test('la ficha y la leyenda del mapa se tiñen con el tema', async ({ page }) => {
    // La leyenda, la ficha y el tooltip los pinta el módulo de mapa, no el
    // del tablero: son justo las piezas que se quedaban claras dentro del
    // tablero oscuro antes de tokenizar la hoja.
    for (const [pagina, oscuro] of [['tablero', true], ['tablero-claro', false]]) {
      await page.goto(BASE + '/paginas/' + pagina + '.html');
      await expect(page.locator('.uhp-db__mapa .leaflet-overlay-pane path:not(.uhp-mapa__contorno)'))
        .toHaveCount(64, { timeout: 25000 });

      await page.locator('.leaflet-overlay-pane path').nth(20).click({ force: true });
      await expect(page.locator('[data-uhp-zona="ficha"]')).toHaveClass(/is-activa/, { timeout: 10000 });

      const claro = await page.evaluate(() => {
        function lum(c) {
          const m = String(c).match(/[\d.]+/g);
          if (!m) { return 0; }
          return (0.2126 * Number(m[0]) + 0.7152 * Number(m[1]) + 0.0722 * Number(m[2])) / 255;
        }
        return {
          fichaTinta: lum(getComputedStyle(document.querySelector('.uhp-db__ficha-t')).color),
          leyendaTinta: lum(getComputedStyle(document.querySelector('.uhp-mapa__leyenda strong')).color),
          atribTinta: lum(getComputedStyle(document.querySelector('.leaflet-control-attribution')).color)
        };
      });

      // Sobre fondo oscuro las tintas son claras y al revés. Comprobar el
      // sentido basta y no ata la prueba a un hex concreto.
      Object.entries(claro).forEach(([donde, l]) => {
        if (oscuro) {
          expect(l, donde + ' debería ser tinta clara en el tablero oscuro').toBeGreaterThan(0.45);
        } else {
          expect(l, donde + ' debería ser tinta oscura en el tablero claro').toBeLessThan(0.45);
        }
      });
    }
  });

  test('el mapa cambia de capa territorial', async ({ page }) => {
    const errores = vigilar(page);
    await page.goto(BASE + '/paginas/tablero.html');

    const territorios = page.locator('.uhp-db__mapa .leaflet-overlay-pane path:not(.uhp-mapa__contorno)');
    await expect(territorios).toHaveCount(64, { timeout: 25000 });

    // El contorno del departamento va por debajo de las tres capas y no es
    // un territorio: no debe contarse ni responder al ratón.
    await expect(page.locator('.uhp-db__mapa .uhp-mapa__contorno')).not.toHaveCount(0);

    await page.locator('[data-uhp-capa]').selectOption('subregion');
    await expect(territorios).toHaveCount(13, { timeout: 20000 });

    await page.locator('[data-uhp-capa]').selectOption('departamento');
    await expect(territorios).toHaveCount(1, { timeout: 20000 });

    await page.locator('[data-uhp-capa]').selectOption('municipio');
    await expect(territorios).toHaveCount(64, { timeout: 20000 });

    expect(errores).toEqual([]);
  });

  test('seleccionar un territorio recoloca todo el tablero', async ({ page }) => {
    const errores = vigilar(page);
    await page.goto(BASE + '/paginas/tablero.html');
    await expect(page.locator('.uhp-db__mapa .leaflet-overlay-pane path:not(.uhp-mapa__contorno)'))
      .toHaveCount(64, { timeout: 25000 });

    // De partida manda el departamento y no hay nada que limpiar.
    const ctx = page.locator('[data-uhp-zona="contexto"]');
    await expect(ctx).toContainText('Nariño');
    await expect(ctx.locator('[data-uhp-limpiar]')).toHaveCount(0);

    await page.locator('.leaflet-overlay-pane path:not(.uhp-mapa__contorno)').nth(20).click({ force: true });

    // La barra de contexto pasa a ese municipio, con su subregión de camino.
    await expect(ctx.locator('[data-uhp-ctx-nivel="municipio"]')).toHaveCount(1, { timeout: 10000 });
    await expect(ctx.locator('[data-uhp-limpiar]')).toHaveCount(1);

    // La ficha lo describe y dice a qué subregión pertenece.
    const ficha = page.locator('[data-uhp-zona="ficha"]');
    await expect(ficha).toHaveClass(/is-activa/);
    await expect(ficha).toContainText('DIVIPOLA');
    await expect(ficha.locator('.uhp-db__ficha-salto')).toHaveCount(1);

    // Volver al departamento deja el tablero como estaba.
    await ctx.locator('[data-uhp-limpiar]').click();
    await expect(ctx.locator('[data-uhp-limpiar]')).toHaveCount(0, { timeout: 10000 });
    await expect(ficha).not.toHaveClass(/is-activa/);

    expect(errores).toEqual([]);
  });

  test('el cintillo dice qué cifras NO son del territorio elegido', async ({ page }) => {
    /* Es la regla de honestidad del tablero. De los seis indicadores, dos no
       están desagregados en ninguna fuente: enseñar la cifra departamental
       sin decirlo sería atribuir al municipio un dato que el proyecto no ha
       medido. */
    await page.goto(BASE + '/paginas/tablero.html');
    await expect(page.locator('.uhp-db__mapa .leaflet-overlay-pane path:not(.uhp-mapa__contorno)'))
      .toHaveCount(64, { timeout: 25000 });

    // Sin selección, ninguna cifra lleva marca: todas son del departamento.
    await expect(page.locator('.uhp-db__kpis .uhp-db__kpi-marca')).toHaveCount(0);

    await page.locator('.leaflet-overlay-pane path:not(.uhp-mapa__contorno)').nth(20).click({ force: true });
    await expect(page.locator('[data-uhp-zona="ficha"]')).toHaveClass(/is-activa/, { timeout: 10000 });

    const marcas = await page.locator('.uhp-db__kpis .uhp-db__kpi-marca').allTextContents();
    expect(marcas.filter((m) => m === 'departamental').length).toBe(2);

    // Y una cifra sin publicar se muestra como raya, no como cero.
    const sinDato = page.locator('.uhp-db__kpis .uhp-db__kpi.is-sin-dato .uhp-db__kpi-val').first();
    if (await sinDato.count()) { await expect(sinDato).toHaveText('—'); }
  });

  test('el panel avisa cuando la vista no puede hablar del territorio', async ({ page }) => {
    await page.goto(BASE + '/paginas/tablero.html');
    await expect(page.locator('.uhp-db__mapa .leaflet-overlay-pane path:not(.uhp-mapa__contorno)'))
      .toHaveCount(64, { timeout: 25000 });

    // Sin territorio elegido no hay nada que advertir.
    await expect(page.locator('.uhp-db__aviso')).toHaveCount(0);

    await page.locator('.leaflet-overlay-pane path:not(.uhp-mapa__contorno)').nth(20).click({ force: true });
    await expect(page.locator('[data-uhp-zona="ficha"]')).toHaveClass(/is-activa/, { timeout: 10000 });

    // El gráfico de partida es subregional y hay un municipio elegido: se
    // dice que no se puede filtrar, en vez de dejar creer que sí.
    await expect(page.locator('.uhp-db__aviso')).toContainText('no se puede filtrar', { timeout: 10000 });
  });

  test('la ficha de una subregión lleva a sus municipios', async ({ page }) => {
    await page.goto(BASE + '/paginas/tablero.html');
    await expect(page.locator('.uhp-db__mapa .leaflet-overlay-pane path:not(.uhp-mapa__contorno)'))
      .toHaveCount(64, { timeout: 25000 });

    await page.locator('[data-uhp-capa]').selectOption('subregion');
    await expect(page.locator('.uhp-db__mapa .leaflet-overlay-pane path:not(.uhp-mapa__contorno)'))
      .toHaveCount(13, { timeout: 20000 });

    await page.locator('.leaflet-overlay-pane path:not(.uhp-mapa__contorno)').nth(3).click({ force: true });
    const ficha = page.locator('[data-uhp-zona="ficha"]');
    await expect(ficha).toHaveClass(/is-activa/, { timeout: 10000 });

    // Sus municipios son botones: bajar de nivel se hace desde aquí.
    const hijos = ficha.locator('[data-uhp-hijo]');
    await expect(hijos).not.toHaveCount(0);
    const nombre = await hijos.first().textContent();

    await hijos.first().click();

    // Bajar a un municipio arrastra la capa del mapa: si no, se estaría
    // seleccionando algo que no está dibujado.
    await expect(page.locator('[data-uhp-capa]')).toHaveValue('municipio', { timeout: 20000 });
    await expect(page.locator('[data-uhp-zona="contexto"] [data-uhp-ctx-nivel="municipio"]'))
      .toHaveCount(1, { timeout: 15000 });
    await expect(page.locator('[data-uhp-zona="contexto"]')).toContainText(nombre.trim());
  });

  test('la capa del mapa sigue a la vista del gráfico', async ({ page }) => {
    await page.goto(BASE + '/paginas/tablero.html');
    await expect(page.locator('.uhp-db__mapa .leaflet-overlay-pane path:not(.uhp-mapa__contorno)'))
      .toHaveCount(64, { timeout: 25000 });
    await expect(page.locator('[data-uhp-capa]')).toHaveValue('municipio');

    // Un acceso rápido a una vista subregional lleva el mapa a subregiones.
    await page.locator('.uhp-db__chip', { hasText: 'Subregiones' }).first().click();
    await expect(page.locator('[data-uhp-capa]')).toHaveValue('subregion', { timeout: 20000 });
    await expect(page.locator('.uhp-db__mapa .leaflet-overlay-pane path:not(.uhp-mapa__contorno)'))
      .toHaveCount(13, { timeout: 20000 });
  });

  test('una cifra del cintillo cambia el indicador del mapa', async ({ page }) => {
    await page.goto(BASE + '/paginas/tablero.html');
    await expect(page.locator('.uhp-mapa__leyenda strong')).toContainText('LPM', { timeout: 25000 });

    await page.locator('[data-uhp-kpi="cancer"]').click();
    await expect(page.locator('.uhp-mapa__leyenda strong')).toContainText('Cáncer', { timeout: 15000 });
    // El selector de los controles refleja el cambio: los dos caminos
    // dejan el tablero en el mismo estado.
    await expect(page.locator('[data-uhp-indicador]')).toHaveValue('cancer');
    await expect(page.locator('[data-uhp-kpi="cancer"]')).toHaveClass(/is-activo/);
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

/* ================================================================== */
test.describe('El mapa como tipo de gráfico', () => {

  /* Un PNG de un píxel en lugar de las teselas reales: igual que en el
     bloque del geomapa, la suite no debe depender de la red. */
  const TESELA = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mM8w8DwHwAExAIsF7hMWQAAAABJRU5ErkJggg==',
    'base64'
  );
  async function sinRed(page) {
    await page.route(/basemaps\.cartocdn\.com|tile\.openstreetmap\.org/, (route) =>
      route.fulfill({ status: 200, contentType: 'image/png', body: TESELA })
    );
  }

  test('una vista territorial arranca en mapa cuando el shortcode lo pide', async ({ page }) => {
    const errores = vigilar(page);
    await sinRed(page);
    await page.goto(BASE + '/paginas/grafico-mapa.html');

    const fig = page.locator('[data-caso="arranca-en-mapa"] [data-uhp-grafico]');
    await expect(fig).toHaveAttribute('data-type', 'mapa');
    await expect(fig.locator('.uhp-skeleton')).toHaveCount(0, { timeout: 25000 });

    // Es un mapa de verdad: la geometría del departamento, no unas barras.
    const caminos = fig.locator('.uhp-g__lienzo path.d3plus-Path');
    await expect(caminos.first()).toBeVisible({ timeout: 25000 });
    expect(await caminos.count()).toBeGreaterThan(50);

    // Con su leyenda de rampa, que solo pinta el componente de geomapas.
    await expect(fig.locator('.uhp-g__leyenda .uhp-geo__escala')).toBeVisible();
    await expect(fig.locator('.uhp-g__leyenda')).toContainText('Sin dato publicado');

    expect(errores).toEqual([]);
  });

  test('el mapa está entre los tipos de una vista territorial y no de las demás', async ({ page }) => {
    await sinRed(page);
    await page.goto(BASE + '/paginas/grafico-mapa.html');

    const conGeo = page.locator('[data-caso="arranca-en-barras"] [data-uhp-grafico]');
    await expect(conGeo.locator('.uhp-skeleton')).toHaveCount(0, { timeout: 25000 });
    const tiposGeo = await conGeo.locator('.uhp-g__select option').allTextContents();
    expect(tiposGeo).toContain('Mapa');

    const sinGeo = page.locator('[data-caso="sin-geo"] [data-uhp-grafico]');
    await expect(sinGeo.locator('.uhp-skeleton')).toHaveCount(0, { timeout: 25000 });
    const tiposSinGeo = await sinGeo.locator('.uhp-g__select option').allTextContents();
    expect(tiposSinGeo.length).toBeGreaterThan(1);
    expect(tiposSinGeo).not.toContain('Mapa');
  });

  test('cambiar de barras a mapa y volver no deja restos del dibujo anterior', async ({ page }) => {
    const errores = vigilar(page);
    await sinRed(page);
    await page.goto(BASE + '/paginas/grafico-mapa.html');

    const fig = page.locator('[data-caso="arranca-en-barras"] [data-uhp-grafico]');
    await expect(fig.locator('.uhp-skeleton')).toHaveCount(0, { timeout: 25000 });

    const lienzo = fig.locator('.uhp-g__lienzo');
    await expect(lienzo.locator('rect.d3plus-Rect').first()).toBeVisible({ timeout: 25000 });

    // A mapa: aparece la geometría y desaparecen las barras.
    await fig.locator('.uhp-g__select').selectOption('mapa');
    await expect(lienzo.locator('path.d3plus-Path').first()).toBeVisible({ timeout: 25000 });
    await expect(lienzo).toHaveClass(/uhp-g__lienzo--mapa/);
    expect(await lienzo.locator('rect.d3plus-Rect').count()).toBe(0);

    // Y de vuelta a barras: el mapa no puede quedarse debajo. Es el fallo
    // que motiva el desmontaje explícito: los dos motores dibujan DENTRO
    // del lienzo y ninguno lo vacía al soltarlo.
    await fig.locator('.uhp-g__select').selectOption('bar');
    await expect(lienzo.locator('rect.d3plus-Rect').first()).toBeVisible({ timeout: 25000 });
    expect(await lienzo.locator('path.d3plus-Path').count()).toBe(0);
    await expect(lienzo).not.toHaveClass(/uhp-g__lienzo--mapa/);
    await expect(fig.locator('.uhp-g__leyenda')).toBeHidden();

    expect(errores).toEqual([]);
  });

  test('el shortcode elige qué serie pinta el mapa de una vista partida', async ({ page }) => {
    await sinRed(page);
    await page.goto(BASE + '/paginas/grafico-mapa.html');

    const fig = page.locator('[data-caso="serie"] [data-uhp-grafico]');
    await expect(fig).toHaveAttribute('data-serie', 'Infección por H. pylori');
    await expect(fig.locator('.uhp-skeleton')).toHaveCount(0, { timeout: 25000 });

    // El rótulo de la leyenda dice qué indicador se está viendo: un mapa
    // de «Prevalencia por subregión» sin decir de cuál de los dos no se
    // puede leer.
    await expect(fig.locator('.uhp-g__leyenda')).toContainText('Infección por H. pylori');
  });

  test('el mapa subregional con teselas las pide y dibuja sus subregiones', async ({ page }) => {
    const pedidas = [];
    await page.route(/basemaps\.cartocdn\.com|tile\.openstreetmap\.org/, (route) => {
      pedidas.push(route.request().url());
      return route.fulfill({ status: 200, contentType: 'image/png', body: TESELA });
    });
    await page.goto(BASE + '/paginas/grafico-mapa.html');

    const fig = page.locator('[data-caso="subregion-teselas"] [data-uhp-grafico]');
    await expect(fig.locator('.uhp-skeleton')).toHaveCount(0, { timeout: 25000 });

    // Trece subregiones, no sesenta y cuatro municipios.
    const caminos = fig.locator('.uhp-g__lienzo path.d3plus-Path');
    await expect(caminos.first()).toBeVisible({ timeout: 25000 });
    const n = await caminos.count();
    expect(n).toBeGreaterThan(5);
    expect(n).toBeLessThan(30);

    expect(pedidas.length).toBeGreaterThan(0);
  });
});

/* ================================================================== */
test.describe('Selector de vistas', () => {

  test('elegir en la lista cambia título, textos, tabla y gráfico a la vez', async ({ page }) => {
    const errores = vigilar(page);
    await page.goto(BASE + '/paginas/selector.html');

    const fig = page.locator('[data-zona="grafico"] [data-uhp-grafico]');
    await expect(fig.locator('.uhp-skeleton')).toHaveCount(0, { timeout: 25000 });

    // Estado inicial: la primera vista del grupo «Prevalencia».
    await expect(fig).toHaveAttribute('data-view', 'prev_lpm_municipios');
    const titulo = page.locator('[data-zona="titulo"] [data-uhp-panel]:not([hidden]) .uhp-titulo');
    await expect(titulo).toHaveCount(1);
    await expect(titulo).toContainText('Municipios con mayor lesión precursora');

    // Solo un panel visible por pieza, nunca dos a la vez.
    const visiblesTexto = page.locator('[data-zona="textos"] [data-uhp-panel]:not([hidden])');
    const antes = await visiblesTexto.count();
    expect(antes).toBe(5); // descripción, interpretación, resumen, cifras y fuente

    const textoAntes = await visiblesTexto.first().textContent();
    const tablaAntes = await page.locator('[data-zona="tabla"] [data-uhp-panel]:not([hidden]) table').textContent();

    // Se elige otra vista del mismo canal.
    await page.locator('[data-zona="selector"] .uhp-sel__select').selectOption('prev_subregion_hp');

    await expect(titulo).toContainText('Infección por H. pylori por subregión');
    await expect(visiblesTexto).toHaveCount(5);
    expect(await visiblesTexto.first().textContent()).not.toBe(textoAntes);
    expect(
      await page.locator('[data-zona="tabla"] [data-uhp-panel]:not([hidden]) table').textContent()
    ).not.toBe(tablaAntes);

    // Y el gráfico, que no es un panel escondido sino una figura que se
    // recarga, sigue a la misma lista.
    await expect(fig).toHaveAttribute('data-view', 'prev_subregion_hp');
    await expect(fig.locator('.uhp-g__lienzo svg')).toHaveCount(1, { timeout: 25000 });

    expect(errores).toEqual([]);
  });

  test('dos canales en la misma página no se pisan', async ({ page }) => {
    await page.goto(BASE + '/paginas/selector.html');

    const tituloPrev = page.locator('[data-zona="titulo"] [data-uhp-panel]:not([hidden]) .uhp-titulo');
    const tituloTam = page.locator('[data-zona="otro-canal"] [data-uhp-panel]:not([hidden]) .uhp-titulo');
    await expect(tituloPrev).toHaveCount(1);
    await expect(tituloTam).toHaveCount(1);

    const antesPrev = await tituloPrev.textContent();

    // Se cambia el canal «tamizaje»: el de «prevalencia» no debe moverse.
    await page.locator('[data-zona="otro-canal"] .uhp-sel__select').selectOption('tamizaje_lpm');
    await expect(tituloTam).toContainText('Lesión precursora de malignidad');
    expect(await tituloPrev.textContent()).toBe(antesPrev);
  });

  test('una lista explícita respeta su orden y su vista inicial', async ({ page }) => {
    await page.goto(BASE + '/paginas/selector.html');

    const sel = page.locator('[data-zona="lista"] .uhp-sel__select');
    const opciones = await sel.locator('option').evaluateAll((os) => os.map((o) => o.value));
    expect(opciones).toEqual(['mortalidad_anio', 'acceso_oncologico', 'zonas_riesgo']);

    // `view` elige cuál arranca, aunque no sea la primera de la lista.
    await expect(sel).toHaveValue('acceso_oncologico');
    await expect(
      page.locator('[data-zona="lista"] [data-uhp-panel]:not([hidden]) .uhp-titulo')
    ).toContainText('Municipios con oferta de servicios oncológicos');
  });

  test('el cambio se anuncia y el panel oculto sale del árbol de accesibilidad', async ({ page }) => {
    await page.goto(BASE + '/paginas/selector.html');

    const estado = page.locator('[data-zona="selector"] [data-uhp-canal-estado]');
    await expect(estado).toHaveAttribute('aria-live', 'polite');

    await page.locator('[data-zona="selector"] .uhp-sel__select').selectOption('prev_subregion_lpm');
    await expect(estado).toContainText('Lesión precursora por subregión');

    // Un panel con `hidden` no lo lee un lector de pantalla. Se comprueba
    // que realmente está oculto y no solo transparente: algunos temas
    // declaran `display` en selectores de elemento y ganarían al valor por
    // defecto del navegador.
    const oculto = page.locator('[data-zona="titulo"] [data-uhp-panel][hidden]').first();
    await expect(oculto).toBeHidden();
  });

  test('un selector sin grupo avisa en vez de romper la página', async ({ page }) => {
    const errores = vigilar(page);
    await page.goto(BASE + '/paginas/selector.html');

    const aviso = page.locator('[data-zona="sin-grupo"] .uhp-error');
    await expect(aviso).toContainText('necesita un grupo de vistas');
    // Es un aviso anunciado, no un párrafo mudo: quien use lector de
    // pantalla debe enterarse de que ese hueco no se va a llenar.
    await expect(aviso).toHaveAttribute('role', 'alert');
    // El resto de la página sigue viva.
    await expect(page.locator('[data-zona="selector"] .uhp-sel__select')).toBeVisible();
    expect(errores).toEqual([]);
  });
});
