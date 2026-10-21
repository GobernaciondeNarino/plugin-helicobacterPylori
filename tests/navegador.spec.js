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

/* Tesela de un píxel con la que se responde a los servidores de capa base.

   La suite NO debe salir a la red —lo dice la guía— y los describe que
   dibujan con Leaflet o con teselas ya lo tenían resuelto cada uno por su
   cuenta. Los dos que faltaban salían de verdad a tile.openstreetmap.org y
   pasaban o fallaban según el entorno: con un proxy que intercepta el TLS,
   Chromium rechaza el certificado y esos fallos acababan contados como
   errores del plugin. Lo que estas pruebas comprueban es que el componente
   PIDE la capa base y la coloca, no cómo se ve una tesela. */
const TESELA_1PX = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mM8w8DwHwAExAIsF7hMWQAAAABJRU5ErkJggg==',
  'base64'
);

/** Responde a las teselas sin salir a la red. */
async function sinTeselas(page) {
  await page.route(/basemaps\.cartocdn\.com|tile\.openstreetmap\.org/, (route) =>
    route.fulfill({ status: 200, contentType: 'image/png', body: TESELA_1PX })
  );
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

  test('avanzar la línea de tiempo no se lleva el scroll de la página', async ({ page }) => {
    await page.goto(BASE + '/paginas/objeto-3d-desplazamiento.html');
    await expect(page.locator('#quieto .uhp3d__carga')).toHaveClass(/is-oculto/, { timeout: 45000 });

    // La escena está muy por debajo del pliegue y nada ha movido la página.
    expect(await page.evaluate(() => window.scrollY)).toBe(0);

    // El paso se activa por código a propósito: si lo pulsara Playwright,
    // sería él quien desplazase la página hasta el botón y la prueba no
    // mediría nada. Así, lo único que puede mover el scroll es el plugin.
    await page.evaluate(() => document.querySelectorAll('#quieto .uhp3d__paso')[7].click());
    await expect(page.locator('#quieto .uhp3d__paso').nth(7)).toHaveAttribute('aria-current', 'true');

    await page.waitForTimeout(800); // margen para un scroll suave, si lo hubiera.
    expect(await page.evaluate(() => window.scrollY)).toBe(0);

    // Y sin embargo el paso activo sí queda a la vista dentro de su carril:
    // lo que se movió fue el carril, no el documento.
    await expect.poll(
      () => page.evaluate(() => {
        const b = document.querySelectorAll('#quieto .uhp3d__paso')[7];
        const c = document.querySelector('#quieto [data-uhp3d="pasos"]');
        const rb = b.getBoundingClientRect(), rc = c.getBoundingClientRect();
        return rb.left >= rc.left - 1 && rb.right <= rc.right + 1;
      }),
      { timeout: 5000 }
    ).toBe(true);
  });

  test('desplazar="si" sí lleva la página hasta el objeto', async ({ page }) => {
    await page.goto(BASE + '/paginas/objeto-3d-desplazamiento.html');
    await expect(page.locator('#arrastra .uhp3d__carga')).toHaveClass(/is-oculto/, { timeout: 45000 });

    expect(await page.evaluate(() => window.scrollY)).toBe(0);

    await page.evaluate(() => document.querySelectorAll('#arrastra .uhp3d__paso')[7].click());

    await expect.poll(
      () => page.evaluate(() => window.scrollY),
      { timeout: 5000 }
    ).toBeGreaterThan(0);
  });
});

/* ================================================================== */
test.describe('Asistente de voz', () => {

  /* Doble de las DOS rutas del asistente.

     Ya no hay SDK que doblar: el navegador no habla con ningún proveedor.
     Le pregunta a /voz/chat —que devuelve lo que el modelo dijo y las
     herramientas que quiere usar— y a /voz/hablar —que devuelve el audio
     ya sintetizado—, las dos de este mismo sitio. El doble intercepta
     fetch antes de que corra nada de la página, guarda lo que se mandó y
     responde con el guion que ponga cada prueba.

     Eso hace que estas pruebas ejerciten el camino de verdad: la vuelta
     de herramientas, el tablero moviéndose y el turno pintándose. Lo
     único fingido es lo que diría el modelo, que es justo lo que no se
     puede comprobar en una suite. */
  async function conDoble(page, ajustes) {
    const cfg = Object.assign({ microfono: true, dictado: true }, ajustes || {});
    await page.addInitScript((cfg) => {
      window.__UHP_STUB = {
        // Peticiones a cada ruta, y lo que iba en ellas.
        chat: 0, hablar: 0, cuerpos: [], textos: [],
        // Lo que el doble va a contestar, en orden.
        guion: [], contador: 0,
        /* Para forzar un fallo: { ruta, codigo, code }. `code` es el del
           WP_Error que WordPress serializa en el cuerpo, y es lo que
           distingue dos 503 que significan cosas distintas. */
        fallo: null,
        // El micrófono. Cuántas veces se pidió y cuántas pistas quedaron
        // abiertas: en el canal escrito las dos tienen que ser cero.
        microfono: 0, pistasVivas: 0,
        // El dictado del navegador: cuántas veces arrancó y la instancia
        // viva, para que la prueba le dispare un resultado.
        dictados: 0, viva: null, abortados: 0
      };

      /* El micrófono. Un Chromium sin tarjeta de sonido no se comporta
         como el equipo de un visitante, así que se sustituye por un doble
         que CUENTA. */
      const falso = {
        getUserMedia: function () {
          window.__UHP_STUB.microfono++;
          if (!cfg.microfono) {
            const e = new Error('Permission denied');
            e.name = 'NotAllowedError';
            return Promise.reject(e);
          }
          window.__UHP_STUB.pistasVivas++;
          return Promise.resolve({
            getTracks: function () {
              return [{ stop: function () { window.__UHP_STUB.pistasVivas--; } }];
            }
          });
        }
      };
      try {
        Object.defineProperty(navigator, 'mediaDevices', { value: falso, configurable: true });
      } catch (e) { window.navigator.mediaDevices = falso; }

      /* El dictado. Lo pone el navegador, no el plugin, y en un Chromium
         de pruebas no existe: sin doble, todas las pruebas de voz caerían
         por la rama de «su navegador no sabe dictar», que es precisamente
         la que hay que poder distinguir de las demás. */
      if (cfg.dictado) {
        window.SpeechRecognition = function () {
          const yo = this;
          this.lang = ''; this.continuous = null; this.interimResults = null;
          this.start = function () {
            window.__UHP_STUB.dictados++;
            window.__UHP_STUB.viva = yo;
            if (cfg.dictadoFalla) {
              setTimeout(function () {
                if (yo.onerror) { yo.onerror({ error: cfg.dictadoFalla }); }
                if (yo.onend) { yo.onend({}); }
              }, 0);
            }
          };
          this.stop = function () {
            window.__UHP_STUB.abortados++;
            if (yo.onend) { yo.onend({}); }
          };
          this.abort = this.stop;
        };
        window.webkitSpeechRecognition = window.SpeechRecognition;
      } else {
        try { delete window.SpeechRecognition; } catch (e) { window.SpeechRecognition = undefined; }
        try { delete window.webkitSpeechRecognition; } catch (e) { window.webkitSpeechRecognition = undefined; }
      }

      /* Las dos rutas. Se responde con un objeto mínimo y no con un
         Response de verdad: el módulo solo mira `ok`, `status` y `json()`,
         y un doble que imite más de lo que se usa esconde lo que se usa. */
      const original = window.fetch;
      function responder(datos, codigo) {
        const c = codigo || 200;
        return Promise.resolve({
          ok: c >= 200 && c < 300,
          status: c,
          json: function () { return Promise.resolve(datos); }
        });
      }

      window.fetch = function (url, opciones) {
        const u = String(url);
        const S = window.__UHP_STUB;
        let cuerpo = null;
        try { cuerpo = JSON.parse((opciones && opciones.body) || 'null'); } catch (e) { cuerpo = null; }

        if (u.indexOf('/voz/chat') !== -1) {
          S.chat++;
          S.cuerpos.push(cuerpo);
          if (S.fallo && S.fallo.ruta === 'chat') {
            return responder({ code: S.fallo.code || '', message: 'x' }, S.fallo.codigo);
          }
          const siguiente = S.guion.length ? S.guion.shift() : { texto: 'No tengo guion.', llamadas: [] };
          return responder(siguiente);
        }

        if (u.indexOf('/voz/hablar') !== -1) {
          S.hablar++;
          S.textos.push(cuerpo && cuerpo.texto);
          if (S.fallo && S.fallo.ruta === 'hablar') {
            return responder({ code: S.fallo.code || '', message: 'x' }, S.fallo.codigo);
          }
          /* Un MP3 de verdad, aunque mudo: el módulo lo decodifica a
             bytes y se lo da a un <audio>. Devolver basura haría fallar
             la decodificación y la prueba miraría la rama equivocada. */
          return responder({ tipo: 'audio/mpeg', audio: '//uQZ' });
        }

        return original.apply(this, arguments);
      };
    }, cfg);
  }

  /** Lo que el doble contestará a la siguiente pregunta, en orden. */
  async function responde(page, guion) {
    await page.evaluate((g) => { window.__UHP_STUB.guion = g.slice(); }, guion);
  }

  /** Escribe una pregunta y la envía. */
  async function enviar(page, texto) {
    await page.locator('.uhp-voz__campo').fill(texto);
    await page.locator('.uhp-voz__forma').getByRole('button', { name: 'Enviar' }).click();
  }

  /** Una pregunta completa: se guionan las respuestas y se espera a que
   *  el asistente termine de contestar. */
  async function preguntar(page, texto, guion) {
    const antes = await page.evaluate(() => window.__UHP_STUB.chat);
    await responde(page, guion || [{ texto: 'Listo.', llamadas: [] }]);
    await enviar(page, texto);
    await expect.poll(() => page.evaluate(() => window.__UHP_STUB.chat))
      .toBe(antes + (guion ? guion.length : 1));
  }

  /** Llama a una herramienta como la llamaría el modelo, y devuelve lo
   *  que el navegador le contestó: eso es lo que el modelo leerá. */
  async function herramienta(page, nombre, args) {
    const antes = await page.evaluate(() => window.__UHP_STUB.chat);
    await page.evaluate(([n, a]) => {
      const S = window.__UHP_STUB;
      S.contador++;
      S.guion = [
        { texto: '', llamadas: [{ id: 'tu_' + S.contador, nombre: n, args: a || {} }] },
        { texto: 'Listo.', llamadas: [] }
      ];
    }, [nombre, args || {}]);
    await enviar(page, 'una pregunta cualquiera');
    await expect.poll(() => page.evaluate(() => window.__UHP_STUB.chat)).toBe(antes + 2);
    return page.evaluate(() => {
      const S = window.__UHP_STUB;
      const ultimo = S.cuerpos[S.cuerpos.length - 1];
      const mensajes = ultimo.mensajes;
      const fin = mensajes[mensajes.length - 1];
      return fin.resultados[0].salida;
    });
  }

  /** Las herramientas que el navegador acaba de ejecutar en esa vuelta. */
  async function llamadasHechas(page) {
    return page.evaluate(() => {
      const S = window.__UHP_STUB;
      const ultimo = S.cuerpos[S.cuerpos.length - 1];
      const fin = ultimo.mensajes[ultimo.mensajes.length - 1];
      return fin.resultados.map(function (r) { return r.nombre; });
    });
  }

  /** El tablero ya terminó de cargar sus datos. */
  async function listo(page) {
    await expect(page.locator('.uhp-db__muni')).toHaveCount(64, { timeout: 25000 });
    await expect(page.locator('.uhp-db__mrow')).toHaveCount(55);
  }

  /** Abre el panel por el canal HABLADO y acepta su aviso si lo hay. Se
   *  nombra el canal porque en una página con los dos, «el lanzador» son
   *  dos. */
  async function conversar(page) {
    await page.locator('[data-uhp-voz-canal="voz"]').click();
    const acepta = page.getByRole('button', { name: 'Acepto y quiero hablar' });
    if (await acepta.count()) { await acepta.click(); }
    await expect(page.locator('[data-uhp-voz-zona="panel"]')).toBeVisible();
    await expect(page.locator('.uhp-voz__turno')).not.toHaveCount(0);
  }

  /** Lo mismo por el canal escrito, que tiene su propio aviso y su propio
   *  botón de aceptación: aceptar uno no acepta el otro. */
  async function conversarEscribiendo(page) {
    await page.locator('[data-uhp-voz-canal="texto"]').click();
    const acepta = page.getByRole('button', { name: 'Acepto y quiero escribir' });
    if (await acepta.count()) { await acepta.click(); }
    await expect(page.locator('[data-uhp-voz-zona="panel"]')).toBeVisible();
    await expect(page.locator('.uhp-voz__turno')).not.toHaveCount(0);
  }

  test('el tablero sin asistente configurado sale exactamente como antes', async ({ page }) => {
    await page.goto(BASE + '/paginas/tablero.html');
    await listo(page);
    // Nace apagado: sin la clave del modelo de lenguaje no hay con qué
    // responder, y el botón no se imprime.
    await expect(page.locator('[data-uhp-voz]')).toHaveCount(0);
  });

  test('el aviso corto viaja en el HTML, sin depender del JavaScript', async ({ page }) => {
    await page.context().route('**/*.js', (r) => r.abort());
    await page.goto(BASE + '/paginas/voz.html');

    // Lo primero que debe saber quien se plantea pulsar está servido por
    // PHP: que su voz sale a un tercero y que el tablero funciona sin esto.
    const aviso = page.locator('.uhp-voz__aviso-corto');
    await expect(aviso).toContainText('proveedor en el exterior');
    await expect(aviso).toContainText('funciona igual sin el asistente');
  });

  test('pulsar abre el aviso completo y no conversa hasta que se acepta', async ({ page }) => {
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz.html');
    await listo(page);

    const abrir = page.locator('[data-uhp-voz-accion="abrir"]');
    await expect(abrir).toHaveAttribute('aria-expanded', 'false');
    await abrir.click();
    await expect(abrir).toHaveAttribute('aria-expanded', 'true');

    const panel = page.locator('[data-uhp-voz-zona="panel"]');
    await expect(panel).toHaveAttribute('role', 'dialog');
    await expect(panel).toHaveAttribute('aria-modal', 'true');

    // Los puntos que la Ley 1581 obliga a informar antes de recoger la
    // autorización. Si alguno desaparece del aviso, esto falla.
    for (const texto of [
      'Quién responde por sus datos',
      'transferencia internacional',
      'artículo 9 de la Ley 1581',
      'No dicte datos personales',
      'Sus derechos',
      'Sin micrófono también funciona'
    ]) {
      await expect(panel).toContainText(texto);
    }

    // Nada se ha abierto todavía: ni transcripción, ni campo.
    await expect(page.locator('.uhp-voz__turno')).toHaveCount(0);
    expect(await page.evaluate(() => window.__UHP_STUB.chat)).toBe(0);

    await page.getByRole('button', { name: 'Acepto y quiero hablar' }).click();
    await expect(page.locator('.uhp-voz__turno')).not.toHaveCount(0);

    /* Y el saludo lo pone el navegador, no el proveedor: abrir el panel no
       puede costar una pregunta. Quien lo abra por curiosidad y lo cierre
       no debe haber gastado nada de la entidad. */
    expect(await page.evaluate(() => window.__UHP_STUB.chat)).toBe(0);
  });

  test('sin aviso configurado, pulsar abre la conversación directamente', async ({ page }) => {
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz-sin-aviso.html');
    await listo(page);

    /* El aviso previo es un ajuste y no una puerta: apagado —que es como
       nace— no hay nada que aceptar. Con esta arquitectura el asistente no
       graba ni guarda la voz de nadie: el dictado lo convierte en texto el
       navegador y lo que sale de aquí es texto. */
    await page.locator('[data-uhp-voz-canal="voz"]').click();
    await expect(page.locator('[data-uhp-voz-zona="panel"]')).toBeVisible();
    await expect(page.locator('.uhp-voz__turno')).not.toHaveCount(0);
    await expect(page.getByRole('button', { name: /^Acepto/ })).toHaveCount(0);
    await expect(page.locator('.uhp-voz__aviso-corto')).toHaveCount(0);
  });

  test('cancelar el aviso no abre nada y devuelve el foco', async ({ page }) => {
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz.html');
    await listo(page);

    await page.locator('[data-uhp-voz-accion="abrir"]').click();
    await page.getByRole('button', { name: 'Cancelar' }).click();

    await expect(page.locator('.uhp-voz__turno')).toHaveCount(0);
    await expect(page.locator('[data-uhp-voz-accion="abrir"]')).toHaveAttribute('aria-expanded', 'false');
    await expect(page.locator('[data-uhp-voz-zona="panel"]')).toBeHidden();
  });

  test('el navegador sabe ejecutar las diez herramientas', async ({ page }) => {
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz.html');
    await listo(page);
    await conversar(page);

    /* Las definiciones las manda el SERVIDOR al modelo; lo que se puede
       comprobar aquí es lo otro, que es lo que de verdad se rompe: que el
       navegador sepa ejecutar las diez por su nombre. Una herramienta que
       el servidor declara y el navegador no reconoce devuelve «no existe»
       y el modelo se queda sin poder hacer lo que acaba de anunciar. */
    const nombres = [
      'uhp_cambiar_indicador',
      'uhp_comparar_subregiones',
      'uhp_consultar_municipio',
      'uhp_consultar_perfil',
      'uhp_filtrar_subregion',
      'uhp_filtrar_zona_riesgo',
      'uhp_leer_estado_tablero',
      'uhp_limpiar_tablero',
      'uhp_listar_municipios',
      'uhp_seleccionar_municipio'
    ];
    for (const n of nombres) {
      const r = await herramienta(page, n, n === 'uhp_cambiar_indicador' ? { indicador: 'hp' } : {});
      expect(r, n).not.toContain('No existe una herramienta');
      expect(r, n).not.toContain('La herramienta falló');
    }
  });

  test('la primera pregunta ya lleva el estado de la pantalla', async ({ page }) => {
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz.html');
    await listo(page);
    await conversar(page);

    /* El modelo tiene que arrancar sabiendo qué hay en pantalla. Antes se
       interpolaba en el prompt del agente y una variable que faltara
       tumbaba la sesión entera; ahora va como un turno más de la
       conversación, de modo que si falta, falta un dato y no la
       conversación. */
    await preguntar(page, '¿Cuántos municipios hay?');
    const cuerpo = await page.evaluate(() => window.__UHP_STUB.cuerpos[0]);
    const todo = JSON.stringify(cuerpo);
    expect(cuerpo.canal).toBe('voz');
    expect(todo).toContain('55');
    expect(todo).toContain('lesión precursora');
  });

  test('las herramientas mueven el tablero de verdad', async ({ page }) => {
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz.html');
    await listo(page);
    await conversar(page);

    // Indicador. Pasa por la misma función que el clic, de modo que el
    // aria-pressed de las pestañas se mueve con él.
    const r1 = await herramienta(page, 'uhp_cambiar_indicador', { indicador: 'hp' });
    expect(r1).toContain('H. pylori');
    await expect(page.locator('[data-uhp-ind="hp"]')).toHaveAttribute('aria-pressed', 'true');

    await page.waitForTimeout(1600); // el presupuesto de cambios: 1500 ms

    const r2 = await herramienta(page, 'uhp_filtrar_zona_riesgo', { zona: 'amarilla' });
    expect(r2).toMatch(/10 municipios/);
    await expect(page.locator('.uhp-db__mrow')).toHaveCount(10);

    await page.waitForTimeout(1600);

    const r3 = await herramienta(page, 'uhp_seleccionar_municipio', { municipio: 'Pasto' });
    expect(r3).toContain('52001');
    await expect(page.locator('[data-uhp-zona="detalle"] .big')).toHaveText('Pasto');

    // Y el cambio se anuncia atribuyéndolo al asistente.
    await expect(page.locator('[data-uhp-zona="estado"]')).toContainText('El asistente');

    await page.waitForTimeout(1600);
    await herramienta(page, 'uhp_limpiar_tablero', {});
    await expect(page.locator('.uhp-db__mrow')).toHaveCount(55);
  });

  test('un municipio que no cruza exacto NO se selecciona: se confirma a mano', async ({ page }) => {
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz.html');
    await listo(page);
    await conversar(page);

    // Albán y Aldana están a dos cambios de distancia y los dos existen.
    // Dejar que el modelo acierte por parecido significa leer en voz alta
    // la cifra de cáncer gástrico de otro pueblo.
    const r = await herramienta(page, 'uhp_seleccionar_municipio', { municipio: 'Albano' });
    expect(r).toMatch(/¿Quiso decir/);
    expect(r).toContain('botón');

    // El tablero NO se movió.
    await expect(page.locator('.uhp-db__muni.sel')).toHaveCount(0);

    // La confirmación es una pulsación, no una frase del modelo.
    const confirmar = page.locator('.uhp-voz__confirmar');
    await expect(confirmar).toBeVisible();
    await confirmar.getByRole('button', { name: /^Sí, / }).click();
    await expect(page.locator('.uhp-db__muni.sel')).toHaveCount(1);
  });

  test('el modelo no puede autoconfirmar su propia sugerencia', async ({ page }) => {
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz.html');
    await listo(page);
    await conversar(page);

    const pregunta = await herramienta(page, 'uhp_seleccionar_municipio', { municipio: 'Albano' });
    const sugerido = /¿Quiso decir ([^?]+)\?/.exec(pregunta)[1];
    await page.waitForTimeout(1600);

    // Nada impide al modelo llamar acto seguido con el nombre que él mismo
    // sugirió, y entonces ya sería una coincidencia exacta. La salvaguarda
    // tiene que vivir en el plugin, no en la obediencia del modelo.
    const r = await herramienta(page, 'uhp_seleccionar_municipio', { municipio: sugerido });
    expect(r).toMatch(/Necesito que confirme/i);
    await expect(page.locator('.uhp-db__muni.sel')).toHaveCount(0);

    // Con la pulsación sí.
    await page.locator('.uhp-voz__confirmar').getByRole('button', { name: /^Sí, / }).click();
    await expect(page.locator('.uhp-db__muni.sel')).toHaveCount(1);
  });

  test('un municipio fuera del estudio se dice, no se inventa', async ({ page }) => {
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz.html');
    await listo(page);
    await conversar(page);

    // Tumaco es de Nariño y no entró en el estudio. No falta el dato:
    // faltó el tamizaje, y esa diferencia es la regla del conjunto.
    const r = await herramienta(page, 'uhp_seleccionar_municipio', { municipio: 'Tumaco' });
    expect(r).toContain('no entró en el estudio');
    expect(r).toContain('faltó el estudio');
    await expect(page.locator('.uhp-db__muni.sel')).toHaveCount(0);
  });

  test('una ráfaga de llamadas no repinta el tablero cien veces', async ({ page }) => {
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz.html');
    await listo(page);
    await conversar(page);

    /* Los tres proveedores pueden pedir varias herramientas en una sola
       respuesta, y un modelo atascado pide doce. Se ejecutan todas, pero
       el tablero no puede repintarse doce veces seguidas: quien usa un
       lector de pantalla recibiría doce anuncios. */
    const llamadas = [];
    for (let i = 0; i < 12; i++) {
      llamadas.push({ id: 'r' + i, nombre: 'uhp_cambiar_indicador', args: { indicador: i % 2 ? 'hp' : 'lpm' } });
    }
    await preguntar(page, 'cambia el indicador muchas veces', [
      { texto: '', llamadas: llamadas },
      { texto: 'Listo.', llamadas: [] }
    ]);

    const respuestas = await page.evaluate(() => {
      const S = window.__UHP_STUB;
      const ultimo = S.cuerpos[S.cuerpos.length - 1];
      const fin = ultimo.mensajes[ultimo.mensajes.length - 1];
      return fin.resultados.map(function (r) { return r.salida; });
    });

    expect(respuestas).toHaveLength(12);
    const frenadas = respuestas.filter((r) => /demasiados cambios/i.test(r));
    expect(frenadas.length).toBeGreaterThan(8);
  });

  test('lo que dice el modelo se pinta como texto, nunca como HTML', async ({ page }) => {
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz.html');
    await listo(page);
    await conversar(page);

    /* Lo que devuelve un proveedor es texto ajeno, por mucho que la
       cuenta sea de la entidad. Y el camino entero —petición, respuesta,
       pintado— tiene que tratarlo como tal. */
    await preguntar(page, 'dime algo', [
      { texto: '<img src=x onerror="window.__INYECTADO=1"> y <b>negrita</b>', llamadas: [] }
    ]);

    const turno = page.locator('.uhp-voz__turno').last();
    await expect(turno).toContainText('<img src=x');
    await expect(turno).toContainText('<b>negrita</b>');
    expect(await turno.locator('img, b').count()).toBe(0);
    expect(await page.evaluate(() => window.__INYECTADO)).toBeUndefined();
  });

  test('escribir en vez de hablar llega al modelo', async ({ page }) => {
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz.html');
    await listo(page);
    await conversar(page);

    // La alternativa equivalente que exige la Resolución 1519: quien no
    // puede o no quiere hablar tiene que poder preguntar igual.
    await expect(page.locator('.uhp-voz__campo')).toBeVisible();
    await preguntar(page, '¿Cuántos municipios hay?', [
      { texto: 'Son 55 municipios priorizados.', llamadas: [] }
    ]);

    const enviado = await page.evaluate(() => {
      const m = window.__UHP_STUB.cuerpos[0].mensajes;
      return m[m.length - 1];
    });
    expect(enviado.rol).toBe('usuario');
    expect(enviado.texto).toBe('¿Cuántos municipios hay?');
    await expect(page.locator('.uhp-voz__turno--usted')).toContainText('¿Cuántos municipios hay?');
    await expect(page.locator('.uhp-voz__turno').last()).toContainText('Son 55 municipios');
  });

  test('retirar la autorización cierra la conversación y borra la aceptación', async ({ page }) => {
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz.html');
    await listo(page);
    await conversar(page);

    // El artículo 8(e) de la Ley 1581 da derecho a revocar la
    // autorización, y revocarla tiene que notarse en el acto.
    await page.getByRole('button', { name: 'Retirar autorización' }).click();
    await expect(page.locator('[data-uhp-voz-zona="panel"]')).toBeHidden();
    expect(await page.evaluate(() => {
      try { return window.sessionStorage.getItem('uhp_voz_consentimiento_v1'); }
      catch (e) { return null; }
    })).toBeNull();

    // Y al volver a pulsar, el aviso aparece otra vez.
    await page.locator('[data-uhp-voz-accion="abrir"]').click();
    await expect(page.locator('[data-uhp-voz-zona="panel"]')).toContainText('artículo 9 de la Ley 1581');
  });

  test('un clic del visitante le llega al modelo con la siguiente pregunta', async ({ page }) => {
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz.html');
    await listo(page);
    await conversar(page);

    // Si el visitante mueve el tablero a mano y el modelo no se entera,
    // seguirá hablando de una pantalla que ya no existe y le leerá a
    // alguien la cifra de otro municipio.
    await page.locator('.uhp-db__mrow', { hasText: 'Pasto' }).first().click();
    await expect(page.locator('[data-uhp-zona="detalle"] .big')).toHaveText('Pasto');

    await preguntar(page, '¿y aquí qué tal?');
    const mensajes = await page.evaluate(() => window.__UHP_STUB.cuerpos[0].mensajes);
    const texto = mensajes.map((m) => m.texto || '').join(' ');
    expect(texto).toContain('En pantalla ahora mismo');
    expect(texto).toContain('Pasto');
  });

  test('el estado de la pantalla no se repite si no cambió', async ({ page }) => {
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz.html');
    await listo(page);
    await conversar(page);

    /* Va en cada pregunta solo cuando cambió. Repetirlo intacto engorda
       el historial, que viaja ENTERO en cada vuelta de herramientas: se
       paga tres o cuatro veces por pregunta. */
    await preguntar(page, 'primera');
    await preguntar(page, 'segunda');

    const cuantas = await page.evaluate(() => {
      const m = window.__UHP_STUB.cuerpos[window.__UHP_STUB.cuerpos.length - 1].mensajes;
      return m.filter((x) => (x.texto || '').indexOf('En pantalla ahora mismo') === 0).length;
    });
    expect(cuantas).toBe(1);
  });

  test('el asistente no pide nada a un servidor externo', async ({ page }) => {
    const fuera = [];
    page.on('request', (r) => {
      const u = r.url();
      if (!u.startsWith(BASE) && !u.startsWith('data:') && !u.startsWith('blob:')) { fuera.push(u); }
    });

    await conDoble(page);
    await page.goto(BASE + '/paginas/voz.html');
    await listo(page);
    await conversar(page);
    await herramienta(page, 'uhp_leer_estado_tablero', {});

    /* Es la propiedad central de la arquitectura: el navegador habla con
       ESTE sitio y con nadie más. Ni CDN del que bajar un SDK, ni
       WebSocket contra el proveedor, ni dominios de LiveKit. Quien tenga
       que escribir la política de seguridad de contenido de este sitio
       necesita que esto siga siendo verdad. */
    expect(fuera, 'el asistente salió a la red: ' + fuera.join(', ')).toEqual([]);
  });

  test('el botón cierra la conversación en vez de abrir una segunda', async ({ page }) => {
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz.html');
    await listo(page);
    await conversar(page);

    // La primera versión comparaba contra una fase llamada «activa» que
    // no existía en ninguna parte: volver a pulsar abría una segunda
    // conversación encima de la primera, y la primera no se cerraba.
    await page.locator('[data-uhp-voz-accion="abrir"]').click();

    await expect(page.locator('.uhp-voz__turno')).toHaveCount(0);
    await expect(page.locator('[data-uhp-voz-accion="abrir"]')).toHaveAttribute('aria-expanded', 'false');
    await expect(page.locator('[data-uhp-voz-zona="panel"]')).toBeHidden();
  });

  test('cerrar mientras el modelo piensa descarta la respuesta que llegue tarde', async ({ page }) => {
    /* Hay una ventana real entre preguntar y que llegue la respuesta, y
       es justo cuando el visitante puede arrepentirse. Sin el testigo de
       generación, la respuesta llegaba después, se pintaba en un panel
       cerrado y —en el canal hablado— se ponía a sonar sola. */
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz.html');
    await listo(page);
    await conversar(page);

    await page.evaluate(() => {
      const original = window.fetch;
      window.fetch = function (url) {
        if (String(url).indexOf('/voz/chat') !== -1) {
          const args = arguments;
          const yo = this;
          return new Promise((res) => setTimeout(() => res(original.apply(yo, args)), 800));
        }
        return original.apply(this, arguments);
      };
      window.__UHP_STUB.guion = [{ texto: 'Una respuesta que llega tarde.', llamadas: [] }];
    });

    await enviar(page, '¿cuántos municipios hay?');
    await page.locator('[data-uhp-voz-canal="voz"]').click();
    await expect(page.locator('[data-uhp-voz-zona="panel"]')).toBeHidden();

    await page.waitForTimeout(1500);

    // Ni se pintó, ni se mandó a sintetizar.
    await expect(page.locator('.uhp-voz__turno')).toHaveCount(0);
    expect(await page.evaluate(() => window.__UHP_STUB.hablar)).toBe(0);
  });

  test('una respuesta del modelo no borra lo que el visitante está escribiendo', async ({ page }) => {
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz.html');
    await listo(page);
    await conversar(page);

    // La primera versión repintaba el panel entero con cada turno: el
    // <input> se destruía, el texto a medias se perdía y el foco caía al
    // <body>, con lo que la tecla Escape dejaba de llegar.
    /* El modelo contesta en dos tiempos: lo que dice ANTES de pedir una
       herramienta se pinta en cuanto llega, y la respuesta final después.
       Entre medias el visitante sigue escribiendo, y ahí es donde la
       primera versión le borraba lo escrito. */
    await page.evaluate(() => {
      const original = window.fetch;
      window.fetch = function (url) {
        if (String(url).indexOf('/voz/chat') !== -1) {
          const args = arguments;
          const yo = this;
          return new Promise((res) => setTimeout(() => res(original.apply(yo, args)), 700));
        }
        return original.apply(this, arguments);
      };
    });
    await responde(page, [
      { texto: 'Un momento.', llamadas: [{ id: 'a1', nombre: 'uhp_leer_estado_tablero', args: {} }] },
      { texto: 'Son 55 municipios.', llamadas: [] }
    ]);

    const campo = page.locator('.uhp-voz__campo');
    await enviar(page, 'primera pregunta');

    // Mientras el modelo piensa, se escribe lo siguiente.
    await campo.click();
    await campo.fill('¿cuántos municipios tiene la zona');

    await expect(page.locator('.uhp-voz__turno').last()).toContainText('Son 55 municipios');
    await expect(campo).toHaveValue('¿cuántos municipios tiene la zona');
    expect(await page.evaluate(() => document.activeElement.className)).toContain('uhp-voz__campo');
  });

  test('la confirmación de un municipio no sobrevive a la conversación', async ({ page }) => {
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz.html');
    await listo(page);
    await conversar(page);

    const pregunta = await herramienta(page, 'uhp_seleccionar_municipio', { municipio: 'Albano' });
    const sugerido = /¿Quiso decir ([^?]+)\?/.exec(pregunta)[1];
    await page.locator('.uhp-voz__confirmar').getByRole('button', { name: /^Sí, / }).click();
    await expect(page.locator('.uhp-db__muni.sel')).toHaveCount(1);

    // Se cierra y se vuelve a abrir: la confirmación anterior no puede
    // valer, o la salvaguarda se sortea en la conversación siguiente.
    await page.locator('[data-uhp-voz-accion="abrir"]').click();
    await expect(page.locator('[data-uhp-voz-zona="panel"]')).toBeHidden();
    await page.locator('[data-uhp-voz-accion="abrir"]').click();
    await expect(page.locator('[data-uhp-voz-zona="panel"]')).toBeVisible();

    /* Y el historial arranca de cero: arrastrar la conversación anterior
       le daría al modelo una confirmación vieja con la que saltarse la
       pregunta de una sugerencia nueva. */
    const r = await herramienta(page, 'uhp_seleccionar_municipio', { municipio: sugerido });
    expect(r).toContain(sugerido);
    const mensajes = await page.evaluate(() => window.__UHP_STUB.cuerpos[window.__UHP_STUB.cuerpos.length - 1].mensajes);
    expect(JSON.stringify(mensajes)).not.toContain('Albano');
  });

  test('la transcripción se puede recorrer con el teclado', async ({ page }) => {
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz.html');
    await listo(page);
    await conversar(page);

    // Una caja con overflow que no es alcanzable con el teclado deja la
    // transcripción fuera del alcance de quien no usa ratón; y un <div>
    // a secas descarta su propio aria-label.
    const hilo = page.locator('.uhp-voz__hilo');
    await expect(hilo).toHaveAttribute('tabindex', '0');
    await expect(hilo).toHaveAttribute('role', 'log');
    await expect(hilo).toHaveAttribute('aria-label', /transcripci/i);
  });

  test('sin JavaScript el botón del micrófono no promete nada', async ({ page }) => {
    await page.context().route('**/*.js', (r) => r.abort());
    await page.goto(BASE + '/paginas/voz.html');

    // Un botón que se puede pulsar y no hace nada es peor que no tenerlo.
    await expect(page.locator('[data-uhp-voz-accion="abrir"]')).toBeDisabled();
  });

  test('las herramientas rechazan lo que no conocen en vez de adivinar', async ({ page }) => {
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz.html');
    await listo(page);
    await conversar(page);

    // Un indicador inventado NO puede caer en «lpm» y decir que obedeció.
    const r1 = await herramienta(page, 'uhp_cambiar_indicador', { indicador: 'mortalidad' });
    expect(r1).toContain('Solo puedo mostrar dos indicadores');
    await expect(page.locator('[data-uhp-ind="lpm"]')).toHaveAttribute('aria-pressed', 'true');

    // Un filtro que no cruza NO se descarta en silencio: hacerlo sería
    // contestar otra pregunta sin avisar.
    const r2 = await herramienta(page, 'uhp_listar_municipios', { subregion: 'Cundinamarca' });
    expect(r2).toContain('No encuentro esa subregión');

    const r3 = await herramienta(page, 'uhp_listar_municipios', { zona: 'azul' });
    expect(r3).toContain('Las zonas son roja, amarilla y verde');

    const r4 = await herramienta(page, 'uhp_filtrar_zona_riesgo', { zona: 'azul' });
    expect(r4).toContain('No conozco esa zona');
  });

  test('los pares tinta/fondo del asistente cumplen la norma de contraste', async ({ page }) => {
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz.html');
    await listo(page);

    const medir = (selectores) => page.evaluate((sel) => {
      function lum(c) {
        const m = c.match(/\d+(\.\d+)?/g).map(Number);
        const f = m.slice(0, 3).map((v) => {
          const s = v / 255;
          return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
        });
        return 0.2126 * f[0] + 0.7152 * f[1] + 0.0722 * f[2];
      }
      function fondoDe(n) {
        let e = n;
        while (e && e !== document.documentElement) {
          const c = getComputedStyle(e).backgroundColor;
          if (c && !/rgba\(0, 0, 0, 0\)|transparent/.test(c)) { return c; }
          e = e.parentElement;
        }
        return 'rgb(255,255,255)';
      }
      const faltan = [];
      const medidos = [];
      sel.forEach((s) => {
        const n = document.querySelector(s);
        // Un selector que no encuentra nodo NO puede saltarse en
        // silencio: la prueba pasaría en verde sin medir nada.
        if (!n) { faltan.push(s); return; }
        const e = getComputedStyle(n);
        const a = lum(e.color);
        const b = lum(fondoDe(n));
        const r = (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
        medidos.push({ sel: s, ratio: Math.round(r * 100) / 100 });
      });
      return { faltan, medidos };
    }, selectores);

    function comprobar(pares) {
      // Un selector que no encuentra nodo NO puede saltarse en silencio:
      // la prueba pasaría en verde sin medir nada.
      expect(pares.faltan, 'selectores que no existen en la pantalla medida').toEqual([]);
      pares.medidos.forEach((m) => {
        expect(m.ratio, m.sel + ' da ' + m.ratio + ':1').toBeGreaterThanOrEqual(4.5);
      });
    }

    // Estado 1: el lanzador y el aviso corto, antes de tocar nada.
    comprobar(await medir(['.uhp-voz__abrir', '.uhp-voz__aviso-corto']));

    // Estado 2: la compuerta del aviso, con su botón principal.
    await page.locator('[data-uhp-voz-accion="abrir"]').click();
    comprobar(await medir([
      '.uhp-voz__bloque h4', '.uhp-voz__bloque p',
      '.uhp-voz__btn', '.uhp-voz__btn--principal'
    ]));

    // Estado 3: la conversación, con un turno de cada parte.
    await page.getByRole('button', { name: 'Acepto y quiero hablar' }).click();
    await expect(page.locator('.uhp-voz__turno')).not.toHaveCount(0);
    await preguntar(page, '¿cuántos municipios hay?', [
      { texto: 'Hay 55 municipios.', llamadas: [] }
    ]);
    comprobar(await medir([
      '.uhp-voz__quien', '.uhp-voz__turno', '.uhp-voz__estado-txt', '.uhp-voz__cab h3'
    ]));
  });

  /* ---------------------------------------------------------------- */
  /* Canal escrito                                                    */
  /* ---------------------------------------------------------------- */

  test('en modo solo escrito no hay botón de micrófono ni aviso de voz', async ({ page }) => {
    await page.goto(BASE + '/paginas/voz-texto.html');

    // Un solo lanzador, y es el del canal escrito.
    await expect(page.locator('[data-uhp-voz-accion="abrir"]')).toHaveCount(1);
    await expect(page.locator('[data-uhp-voz-canal="texto"]')).toHaveCount(1);
    await expect(page.locator('[data-uhp-voz-canal="voz"]')).toHaveCount(0);

    // Y el aviso de la voz no viaja: describe algo que en esta página no
    // puede ocurrir, y pedir autorización para tratar una muestra de voz
    // que nunca se captura es recoger una autorización que sobra.
    await expect(page.locator('template[data-uhp-voz-zona="aviso"]')).toHaveCount(0);
    await expect(page.locator('template[data-uhp-voz-zona="aviso-texto"]')).toHaveCount(1);

    const corto = page.locator('.uhp-voz__aviso-corto');
    await expect(corto).toContainText('Lo que escriba');
    await expect(corto).not.toContainText('Su voz');
  });

  test('el canal escrito no pide el micrófono ni una vez', async ({ page }) => {
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz-texto.html');
    await listo(page);
    await conversarEscribiendo(page);

    await preguntar(page, '¿cuántos municipios hay?', [
      { texto: 'Son 55 municipios priorizados.', llamadas: [] }
    ]);

    const st = await page.evaluate(() => ({
      microfono: window.__UHP_STUB.microfono,
      dictados: window.__UHP_STUB.dictados,
      hablar: window.__UHP_STUB.hablar,
      canal: window.__UHP_STUB.cuerpos[0].canal
    }));

    // La diferencia entre «no hay audio» y «no se pide permiso para el
    // audio»: lo segundo es lo que convierte esto en una alternativa de
    // verdad para quien no puede o no quiere hablar.
    expect(st.microfono).toBe(0);
    expect(st.dictados).toBe(0);

    /* Y no se manda a sintetizar NADA, aunque el sitio tenga la voz
       configurada: ElevenLabs cobra por carácter leído, y un audio que
       nadie va a oír se factura igual que uno que sí. */
    expect(st.hablar).toBe(0);

    // El servidor sabe por qué canal va, que es lo que cambia el prompt.
    expect(st.canal).toBe('texto');
  });

  test('el canal escrito dice «escriba», no «escuchando»', async ({ page }) => {
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz-texto.html');
    await listo(page);
    await conversarEscribiendo(page);

    // Quien no ve la pantalla depende de este rótulo: decirle
    // «escuchando» a quien está teclando le describe algo que no existe.
    const estado = page.locator('.uhp-voz__estado-txt');
    await expect(estado).toContainText('Escriba su pregunta');
    await expect(estado).not.toContainText('Escuchando');
    await expect(page.locator('.uhp-voz__cab h3')).toContainText('escrito');

    // El campo está desde el primer momento: es la única entrada.
    await expect(page.locator('.uhp-voz__campo')).toBeVisible();
  });

  test('aceptar el aviso escrito NO autoriza el micrófono', async ({ page }) => {
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz-dos.html');
    await listo(page);

    await conversarEscribiendo(page);
    await page.keyboard.press('Escape');

    // El aviso del canal hablado incluye el tratamiento de una muestra de
    // voz, que es un dato biométrico. Si aceptar el pequeño abriera el
    // grande, la autorización del artículo 9 dejaría de estar informada.
    await page.locator('[data-uhp-voz-canal="voz"]').click();
    await expect(page.getByRole('button', { name: 'Acepto y quiero hablar' })).toBeVisible();
    await expect(page.locator('.uhp-voz__turno')).toHaveCount(0);
  });

  test('aceptar el aviso de voz sí cubre el canal escrito', async ({ page }) => {
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz-dos.html');
    await listo(page);

    await conversar(page);
    await page.keyboard.press('Escape');

    // En este sentido sí: el aviso de la voz dice con esas palabras que
    // «puede escribir sus preguntas en lugar de hablarlas». Volver a
    // pedirlo sería fricción por algo más estrecho de lo ya autorizado.
    await page.locator('[data-uhp-voz-canal="texto"]').click();
    await expect(page.locator('[data-uhp-voz-zona="panel"]')).toBeVisible();
    await expect(page.locator('.uhp-voz__turno')).not.toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Acepto y quiero escribir' })).toHaveCount(0);
  });

  test('los dos lanzadores declaran el mismo estado del panel', async ({ page }) => {
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz-dos.html');
    await listo(page);

    const voz = page.locator('[data-uhp-voz-canal="voz"]');
    const txt = page.locator('[data-uhp-voz-canal="texto"]');
    await expect(voz).toHaveAttribute('aria-expanded', 'false');
    await expect(txt).toHaveAttribute('aria-expanded', 'false');

    await txt.click();
    // Los dos gobiernan el MISMO panel: quien llegue tabulando al otro
    // botón tiene que enterarse de que está abierto, no creer que lo abre.
    await expect(txt).toHaveAttribute('aria-expanded', 'true');
    await expect(voz).toHaveAttribute('aria-expanded', 'true');

    await page.keyboard.press('Escape');
    await expect(voz).toHaveAttribute('aria-expanded', 'false');
    await expect(txt).toHaveAttribute('aria-expanded', 'false');
  });

  test('la respuesta hablada se manda a sintetizar, la escrita no', async ({ page }) => {
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz-dos.html');
    await listo(page);
    await conversar(page);

    await preguntar(page, '¿cuántos municipios hay?', [
      { texto: 'Son 55 municipios priorizados.', llamadas: [] }
    ]);

    /* Una sola vez, y con el texto que de verdad se pintó. ElevenLabs
       cobra por carácter: mandar dos veces lo mismo se paga dos veces. */
    await expect.poll(() => page.evaluate(() => window.__UHP_STUB.hablar)).toBe(1);
    expect(await page.evaluate(() => window.__UHP_STUB.textos)).toEqual(['Son 55 municipios priorizados.']);

    // Y la clave de ElevenLabs no la ve el navegador en ningún momento.
    const cuerpo = await page.content();
    expect(cuerpo).not.toContain('sk-el-que-no-debe-salir-nunca');
    expect(cuerpo).not.toContain('sk-ant-que-no-debe-salir-nunca');
  });

  test('lo que el modelo dice antes de una herramienta no se lee en alto', async ({ page }) => {
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz-dos.html');
    await listo(page);
    await conversar(page);

    /* «Déjeme mirar» se pinta, porque callarlo deja la pantalla muda
       mientras el tablero se mueve solo. Pero no se sintetiza: se pagaría
       un audio de relleno en cada vuelta de herramientas, y en una
       pregunta normal hay tres o cuatro. */
    await preguntar(page, 'compara Ipiales con Túquerres', [
      { texto: 'Déjeme mirar.', llamadas: [{ id: 'a1', nombre: 'uhp_consultar_municipio', args: { municipio: 'Ipiales' } }] },
      { texto: 'Ipiales está en 37,2 %.', llamadas: [] }
    ]);

    await expect.poll(() => page.evaluate(() => window.__UHP_STUB.hablar)).toBe(1);
    expect(await page.evaluate(() => window.__UHP_STUB.textos)).toEqual(['Ipiales está en 37,2 %.']);
  });

  test('que no suene no rompe la conversación', async ({ page }) => {
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz-dos.html');
    await listo(page);
    await conversar(page);

    /* La respuesta ya está escrita en el panel. Pintarle un error a quien
       tiene delante lo que preguntó sería decirle que algo salió mal
       cuando lo que pidió está ahí. */
    await page.evaluate(() => { window.__UHP_STUB.fallo = { ruta: 'hablar', codigo: 500 }; });
    await preguntar(page, '¿cuántos municipios hay?', [
      { texto: 'Son 55 municipios priorizados.', llamadas: [] }
    ]);

    await expect(page.locator('.uhp-voz__turno').last()).toContainText('Son 55 municipios');
    await expect(page.locator('.uhp-voz__estado-txt')).not.toContainText(/error/i);
    await expect(page.locator('.uhp-voz__campo')).toBeVisible();
  });

  test('el dictado no arranca solo: lo arranca el visitante', async ({ page }) => {
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz.html');
    await listo(page);
    await conversar(page);

    /* Un micrófono siempre abierto en la página de una entidad pública
       es algo que habría que justificar. Aquí es pulsar para hablar, y
       hasta que se pulsa no se ha abierto nada. */
    expect(await page.evaluate(() => window.__UHP_STUB.dictados)).toBe(0);

    /* `exact` porque getByRole casa el nombre por SUBCADENA: sin él,
       «Escribir en vez de hablar» cuenta como «Hablar» y la prueba
       aprueba un botón que no existe. */
    const mic = page.getByRole('button', { name: 'Hablar', exact: true });
    await expect(mic).toHaveAttribute('aria-pressed', 'false');
    await mic.click();
    await expect.poll(() => page.evaluate(() => window.__UHP_STUB.dictados)).toBe(1);
    await expect(page.getByRole('button', { name: 'Dejar de hablar', exact: true })).toHaveAttribute('aria-pressed', 'true');

    // Y lo dictado entra como una pregunta más.
    await page.evaluate(() => {
      const r = window.__UHP_STUB.viva;
      r.onresult({ results: [[{ transcript: '¿cuántos municipios hay?' }]] });
      r.onend({});
    });
    await expect(page.locator('.uhp-voz__turno--usted')).toContainText('¿cuántos municipios hay?');
  });

  test('el asistente se calla antes de abrir el micrófono', async ({ page }) => {
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz.html');
    await listo(page);
    await conversar(page);

    /* Si no, su propia voz entra por el micrófono, se transcribe como si
       la hubiera dicho el ciudadano, y el asistente se pone a conversar
       consigo mismo a costa de la entidad. */
    await preguntar(page, '¿cuántos municipios hay?', [
      { texto: 'Son 55 municipios priorizados.', llamadas: [] }
    ]);
    await expect.poll(() => page.evaluate(() => window.__UHP_STUB.hablar)).toBe(1);

    await page.getByRole('button', { name: 'Hablar', exact: true }).click();
    const sonando = await page.evaluate(() => {
      const a = document.querySelectorAll('audio');
      for (let i = 0; i < a.length; i++) { if (!a[i].paused) { return true; } }
      return false;
    });
    expect(sonando).toBe(false);
  });

  test('un navegador que no sabe dictar lo dice y deja escribir', async ({ page }) => {
    await conDoble(page, { dictado: false });
    await page.goto(BASE + '/paginas/voz-dos.html');
    await listo(page);
    await conversar(page);

    /* Firefox, Opera, Brave y Vivaldi no reconocen voz. No es un caso
       raro: es una parte del público. Prometerles un botón de micrófono
       que no funciona es peor que no ofrecerlo. */
    await expect(page.getByRole('button', { name: 'Hablar', exact: true })).toHaveCount(0);
    await expect(page.locator('.uhp-voz__campo')).toBeVisible();

    await preguntar(page, '¿cuántos municipios hay?', [
      { texto: 'Son 55 municipios priorizados.', llamadas: [] }
    ]);
    await expect(page.locator('.uhp-voz__turno').last()).toContainText('Son 55 municipios');
  });

  test('denegar el micrófono no gasta una pregunta y deja por dónde seguir', async ({ page }) => {
    await conDoble(page, { dictadoFalla: 'not-allowed' });
    await page.goto(BASE + '/paginas/voz-dos.html');
    await listo(page);
    await conversar(page);

    const antes = await page.evaluate(() => window.__UHP_STUB.chat);
    await page.getByRole('button', { name: 'Hablar', exact: true }).click();
    await expect(page.locator('.uhp-voz__estado-txt')).toContainText('micrófono');

    /* LO QUE IMPORTA: no se gasta una pregunta. Una negativa del
       navegador no puede llevarse una ranura del cupo diario, que es
       facturable. */
    expect(await page.evaluate(() => window.__UHP_STUB.chat)).toBe(antes);

    // Y queda un camino, no solo un mensaje: la conversación sigue viva y
    // el campo de escritura tiene que estar delante.
    const campo = page.locator('.uhp-voz__campo');
    await expect(campo).toBeVisible();
    await preguntar(page, '¿cuántos municipios hay?', [
      { texto: 'Son 55 municipios priorizados.', llamadas: [] }
    ]);
    await expect(page.locator('.uhp-voz__turno').last()).toContainText('Son 55 municipios');
  });

  test('un navegador que falla al transcribir no deja un botón roto', async ({ page }) => {
    await conDoble(page, { dictadoFalla: 'service-not-allowed' });
    await page.goto(BASE + '/paginas/voz-dos.html');
    await listo(page);
    await conversar(page);

    /* En Brave, Vivaldi y Opera el objeto existe y falla DESPUÉS, en
       tiempo de ejecución, porque el motor de transcripción es un
       servicio de Google que esos navegadores no tienen. Reintentar ahí
       es pedirle al visitante que pulse un botón roto. */
    await page.getByRole('button', { name: 'Hablar', exact: true }).click();
    await expect(page.locator('.uhp-voz__estado-txt')).toContainText('no reconoce la voz');
    await expect(page.getByRole('button', { name: 'Hablar', exact: true })).toHaveCount(0);
    await expect(page.locator('.uhp-voz__campo')).toBeVisible();
  });

  test('el canal viaja en cada pregunta, porque cambia las instrucciones', async ({ page }) => {
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz-texto.html');
    await listo(page);
    await conversarEscribiendo(page);

    /* Antes esto eran ocho variables interpoladas en el prompt del
       agente, y si faltaba una sola el proveedor rechazaba la sesión
       entera. Ahora el prompt es estático y lo único que el navegador
       tiene que decir es por dónde va la conversación: de eso depende que
       el modelo lea una lista de once subregiones o la resuma. */
    await preguntar(page, '¿cuántos municipios hay?');
    expect(await page.evaluate(() => window.__UHP_STUB.cuerpos[0].canal)).toBe('texto');
  });

  /* ---------------------------------------------------------------- */
  /* El gráfico de barras                                             */
  /* ---------------------------------------------------------------- */

  test('el asistente lee el gráfico de barras y nombra lo que no sale en él', async ({ page }) => {
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz.html');
    await listo(page);
    await conversar(page);

    const r = await herramienta(page, 'uhp_comparar_subregiones', {});

    // La cifra de una barra es SUBREGIONAL y publicada como tal. No es el
    // promedio de los municipios intervenidos, porque la subregión
    // incluye municipios donde el proyecto no estuvo.
    expect(r).toContain('cifras de la subregión');
    expect(r).toContain('no de los municipios');
    expect(r).toContain('NO es el promedio');
    expect(r).toContain('uhp_consultar_municipio');

    // Río Mayo es la más alta de lesión precursora, 44,3 %.
    expect(r).toContain('La más alta');
    expect(r).toContain('Río Mayo');

    /* Y LO QUE IMPORTA: Nariño tiene trece subregiones y el informe
       publica once. Las dos de la costa no se dibujan porque no hay barra
       que dibujar; un asistente que leyera solo lo que se ve diría «las
       subregiones de Nariño» habiendo borrado dos del departamento. */
    expect(r).toContain('13 subregiones');
    expect(r).toContain('Pacífico Sur');
    expect(r).toContain('Sanquianga');
    expect(r).toContain('NO filtrables');
  });

  test('por voz el ranking no enumera once subregiones seguidas', async ({ page }) => {
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz.html');
    await listo(page);
    await conversar(page);

    const r = await herramienta(page, 'uhp_comparar_subregiones', {});
    // Una enumeración hablada de once elementos no se retiene: por voz van
    // los extremos y un par más, y quien quiera la lista entera la pide.
    const nombradas = ['Río Mayo', 'La Sabana', 'Occidente', 'La Cordillera',
      'Los Abades', 'Juanambú', 'Guambuyaco', 'Obando', 'Telembí', 'Centro',
      'Piedemonte Costero'].filter((n) => r.includes(n));
    expect(nombradas.length).toBeLessThanOrEqual(5);
    expect(r).toContain('Piedemonte Costero');

    const todas = await herramienta(page, 'uhp_comparar_subregiones', { cuantas: 11 });
    expect(todas).toContain('Guambuyaco');
  });

  test('por escrito el rótulo viaja pegado a cada cifra', async ({ page }) => {
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz-texto.html');
    await listo(page);
    await conversarEscribiendo(page);

    const r = await herramienta(page, 'uhp_comparar_subregiones', {});

    /* Lo que se copia de una conversación escrita sale sin el resto del
       párrafo. Una cifra suelta sin su territorio se convierte en un dato
       falso en cuanto alguien la pega en otra parte, así que el rótulo va
       en CADA línea y no solo en la cabecera. */
    expect(r.match(/\(cifra de la subregión\)/g).length).toBeGreaterThanOrEqual(11);

    // Y el denominador de la cobertura, que es el que falta en la barra:
    // Telembí encabeza H. pylori con una cifra de subregión mientras el
    // proyecto intervino uno de sus tres municipios.
    expect(r).toContain('municipios intervenidos');

    // Por escrito se puede releer: van las once.
    expect(r).toContain('Río Mayo');
    expect(r).toContain('Piedemonte Costero');
  });

  test('dos subregiones con la misma cifra comparten puesto', async ({ page }) => {
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz-texto.html');
    await listo(page);
    await conversarEscribiendo(page);

    const r = await herramienta(page, 'uhp_comparar_subregiones', {});

    /* Occidente y La Cordillera están las dos en 37,7 % de lesión
       precursora. Numerarlas «3.» y «4.» inventa una diferencia que el
       dato no tiene. */
    expect(r).toContain('empatada en este puesto');
    const puestoDe = (n) => Number((r.match(new RegExp('(\\d+)\\. ' + n)) || [])[1]);
    expect(puestoDe('Occidente')).toBe(puestoDe('La Cordillera'));
    // Y el siguiente salta: tras dos empatados en el 3, el 5.
    expect(puestoDe('Los Abades')).toBe(5);
  });

  test('el asistente lee el perfil de los 5.000 y da el denominador', async ({ page }) => {
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz-texto.html');
    await listo(page);
    await conversarEscribiendo(page);

    const r = await herramienta(page, 'uhp_consultar_perfil', {});

    // El perfil está publicado para el CONJUNTO del estudio. Con el
    // tablero filtrado por una subregión no cambia, y presentarlo como el
    // perfil de la selección sería inventar un dato.
    expect(r).toContain('DEPARTAMENTAL');
    expect(r).toContain('no es el perfil de los municipios seleccionados');

    expect(r).toContain('Mujeres');
    expect(r).toContain('3204 personas');

    /* El denominador. En género son 4.994 de los 5.000 los que tienen el
       campo: reparted los porcentajes sobre 5.000 convertiría seis
       ausencias en seis personas. */
    expect(r).toContain('4994');
    expect(r).toContain('no se les registró');

    // Y las dos categorías que el bloque de la pantalla se había dejado
    // fuera: tres barras que sumaban 99,6 sin decir que faltaba una.
    expect(r).toContain('Delgadez');
    expect(r).toContain('Otro / no reportado');
  });

  test('se puede pedir solo una parte del perfil', async ({ page }) => {
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz-texto.html');
    await listo(page);
    await conversarEscribiendo(page);

    const r = await herramienta(page, 'uhp_consultar_perfil', { aspecto: 'genero' });
    expect(r).toContain('Mujeres');
    expect(r).not.toContain('Sobrepeso');

    const mal = await herramienta(page, 'uhp_consultar_perfil', { aspecto: 'horóscopo' });
    // Lo que no conoce se rechaza enumerando lo que sí, en vez de adivinar.
    expect(mal).toContain('No tengo ese aspecto');
    expect(mal).toContain('Estado nutricional');
  });

  test('ninguna clave aparece en la página, por ninguna vía', async ({ page }) => {
    for (const pagina of ['voz', 'voz-dos', 'voz-texto', 'voz-sin-aviso']) {
      await page.goto(BASE + '/paginas/' + pagina + '.html');
      const html = await page.content();
      expect(html, pagina).not.toContain('sk-ant-que-no-debe-salir-nunca');
      expect(html, pagina).not.toContain('sk-el-que-no-debe-salir-nunca');
      expect(html.toLowerCase(), pagina).not.toContain('xi-api-key');
      expect(html.toLowerCase(), pagina).not.toContain('x-api-key');
      // Ni qué proveedor hay detrás: no le hace falta al navegador y es
      // una pista gratis para quien quiera molestar.
      expect(html, pagina).not.toContain('api.anthropic.com');
      expect(html, pagina).not.toContain('api.openai.com');
      expect(html, pagina).not.toContain('generativelanguage');
    }
  });

  test('sin clave de ElevenLabs no se pinta un botón de micrófono', async ({ page }) => {
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz-muda.html');
    await listo(page);

    /* La voz es opcional, pero a medias no: con la clave del modelo y sin
       la de ElevenLabs el asistente responde entero por escrito. Pintar
       un lanzador de voz prometería algo que el servidor no puede hacer,
       y el fallo sería mudo —nunca mejor dicho— en producción. */
    await expect(page.locator('[data-uhp-voz-canal="voz"]')).toHaveCount(0);
    await expect(page.locator('[data-uhp-voz-canal="texto"]')).toHaveCount(1);

    await conversarEscribiendo(page);
    await preguntar(page, '¿cuántos municipios hay?', [
      { texto: 'Son 55 municipios priorizados.', llamadas: [] }
    ]);
    await expect(page.locator('.uhp-voz__turno').last()).toContainText('Son 55 municipios');
    expect(await page.evaluate(() => window.__UHP_STUB.hablar)).toBe(0);
  });

  test('cuando el servidor dice que no, se dice por qué', async ({ page }) => {
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz-dos.html');
    await listo(page);
    await conversarEscribiendo(page);

    /* Las puertas del servidor —cupo diario, límite por IP, pregunta
       demasiado larga— tienen que llegar al visitante como algo que
       entiende, no como «algo salió mal». */
    const reanudar = page.getByRole('button', { name: 'Volver a preguntar' });
    const puertas = [
      [503, 'uhp_voz_cuota', /hoy/i],
      [429, 'uhp_voz_ritmo', /espere|demasiadas/i],
      [413, 'uhp_voz_larga', /larga/i],
      /* Y el que importa: un 503 que NO es el cupo. La ruta devuelve uno
         cuando la voz no está configurada, y se anunciaba como «alcanzó
         su límite de consultas de hoy» —una cifra que no se había
         gastado y un límite que no existía—. El estado HTTP no basta
         para clasificar: hace falta el código del servidor. */
      [503, 'uhp_voz_sin_voz', /no pude consultar/i],
    ];
    for (const [codigo, code, aguja] of puertas) {
      if (await reanudar.count()) { await reanudar.click(); }
      await page.evaluate((f) => { window.__UHP_STUB.fallo = { ruta: 'chat', codigo: f[0], code: f[1] }; }, [codigo, code]);
      await enviar(page, 'una pregunta');
      await expect(page.locator('.uhp-voz__estado-txt')).toContainText(aguja);

      /* Y nunca se queda sin salida: un mensaje que dice «inténtelo de
         nuevo» con nada que pulsar es peor que no decir nada. */
      await expect(reanudar).toBeVisible();
    }

    // Y volver a preguntar funciona de verdad, no solo aparece el botón.
    await reanudar.click();
    await page.evaluate(() => { window.__UHP_STUB.fallo = null; });
    await preguntar(page, '¿cuántos municipios hay?', [
      { texto: 'Son 55 municipios priorizados.', llamadas: [] }
    ]);
    await expect(page.locator('.uhp-voz__turno').last()).toContainText('Son 55 municipios');
  });

  test('lo que falla en silencio para el visitante deja rastro en la consola', async ({ page }) => {
    const consola = [];
    page.on('console', (m) => { if (m.text().includes('[URKUNINA 5000]')) { consola.push(m.text()); } });

    await conDoble(page);
    await page.goto(BASE + '/paginas/voz-dos.html');
    await listo(page);
    await conversar(page);

    /* Que no suene NO se le pinta al visitante: la respuesta ya está
       escrita delante. Pero desde fuera, «no suena» por una clave mal
       configurada y «no suena» porque el navegador bloqueó la
       reproducción se ven exactamente igual, y se arreglan distinto.
       Quien tenga que diagnosticarlo solo tiene el equipo del visitante,
       y hasta ahora ahí no había absolutamente nada. */
    await page.evaluate(() => { window.__UHP_STUB.fallo = { ruta: 'hablar', codigo: 502, code: 'uhp_voz_proveedor' }; });
    await preguntar(page, '¿cuántos municipios hay?', [
      { texto: 'Son 55 municipios priorizados.', llamadas: [] }
    ]);

    await expect(page.locator('.uhp-voz__turno').last()).toContainText('Son 55 municipios');
    await expect(page.locator('.uhp-voz__estado-txt')).not.toContainText(/error/i);

    await expect.poll(() => consola.filter((l) => l.includes('voz:')).length).toBeGreaterThan(0);
    expect(consola.join(' ')).toContain('HTTP 502');
    expect(consola.join(' ')).toContain('uhp_voz_proveedor');
  });

  test('el motivo que da el navegador al fallar el micrófono llega a la consola', async ({ page }) => {
    const consola = [];
    page.on('console', (m) => { if (m.text().includes('[URKUNINA 5000]')) { consola.push(m.text()); } });

    await conDoble(page, { dictadoFalla: 'service-not-allowed' });
    await page.goto(BASE + '/paginas/voz-dos.html');
    await listo(page);
    await conversar(page);

    /* El navegador dice 'not-allowed', 'network', 'service-not-allowed' o
       'audio-capture', el plugin usa ese motivo para decidir la frase y
       para apagar el micrófono… y no lo imprimía en ninguna parte. Son
       cuatro arreglos distintos y el visitante ve el mismo cartel. */
    await page.getByRole('button', { name: 'Hablar', exact: true }).click();
    await expect(page.locator('.uhp-voz__estado-txt')).toContainText('no reconoce la voz');

    await expect.poll(() => consola.filter((l) => l.includes('micrófono')).length).toBeGreaterThan(0);
    expect(consola.join(' ')).toContain('service-not-allowed');
  });

  test('un navegador sin reconocimiento lo dice en la consola, no solo en pantalla', async ({ page }) => {
    const consola = [];
    page.on('console', (m) => { if (m.text().includes('[URKUNINA 5000]')) { consola.push(m.text()); } });

    await conDoble(page, { dictado: false });
    await page.goto(BASE + '/paginas/voz-dos.html');
    await listo(page);
    await conversar(page);

    // Sin botón que pulsar, el único camino a la consola es preguntar
    // por escrito y que el panel no prometa un micrófono que no hay.
    await expect(page.getByRole('button', { name: 'Hablar', exact: true })).toHaveCount(0);
    await preguntar(page, '¿cuántos municipios hay?', [
      { texto: 'Son 55 municipios priorizados.', llamadas: [] }
    ]);
    await expect(page.locator('.uhp-voz__turno').last()).toContainText('Son 55 municipios');
  });

  test('un modelo que se enreda no da vueltas indefinidamente', async ({ page }) => {
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz-dos.html');
    await listo(page);
    await conversarEscribiendo(page);

    /* Cada vuelta cuesta: el historial entero viaja otra vez. Un modelo
       atascado gastaría la conversación dando vueltas mientras el
       visitante ve el tablero moverse sin recibir respuesta. El tope lo
       pone el plugin y no la obediencia del modelo. */
    const guion = [];
    for (let i = 0; i < 20; i++) {
      guion.push({ texto: '', llamadas: [{ id: 'v' + i, nombre: 'uhp_leer_estado_tablero', args: {} }] });
    }
    await responde(page, guion);
    await enviar(page, 'da vueltas');

    await expect(page.locator('.uhp-voz__turno').last()).toContainText(/me enred/i, { timeout: 15000 });
    // El tope de la página de pruebas es 8.
    expect(await page.evaluate(() => window.__UHP_STUB.chat)).toBeLessThanOrEqual(9);
  });

  test('un participante publicado no se sustituye por uno prorrateado', async ({ page }) => {
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz.html');
    await listo(page);

    /* Sin filtro, el total es el que publica el informe —5.000— y el pie
       nombra las dos partes: los 940 que el informe desglosa en doce
       municipios y el prorrateo de los 4.060 restantes entre los otros
       cuarenta y tres. Prorratear 5.000 entre 55 y sumar aparte los doce
       publicados daría 4.849, porque esos doce están por debajo de la
       media. Dos datos publicados restringen el estimado. */
    const kpi = page.locator('.uhp-db__kpi', { hasText: 'Participantes' }).first();
    await expect(kpi.locator('.val')).toHaveText('5.000');
    await expect(kpi).toContainText('940 publicados');
    await expect(kpi).toContainText('4.060 prorrateados');

    await conversar(page);

    /* Con un filtro puesto, lo publicado se suma y solo se prorratea el
       resto. La Cordillera tiene cinco municipios intervenidos y el
       informe desglosa tres: 240 publicados más 189 prorrateados. Antes,
       los cinco se prorrateaban por igual y daban 455. */
    const f = await herramienta(page, 'uhp_filtrar_subregion', { subregion: 'La Cordillera' });
    expect(f).toContain('Cordillera');
    await expect(kpi).toContainText('240 publicados');
    await expect(kpi.locator('.val')).toHaveText('429');

    /* Y el asistente puede decir la cifra de un municipio, que antes no
       viajaba al navegador: a «¿a cuántas personas examinaron en
       Policarpa?» solo podía contestar que no lo sabía. */
    const ficha = await herramienta(page, 'uhp_consultar_municipio', { municipio: 'Policarpa' });
    expect(ficha).toContain('90 participantes examinados');
    expect(ficha).toContain('65 positivos');
    expect(ficha).toContain('dato publicado');

    // Donde no se publica, no se inventa: ni cifra ni cero. Dar el
    // prorrateo del conjunto como la cifra de un municipio sería mentir.
    const sinDato = await herramienta(page, 'uhp_consultar_municipio', { municipio: 'Túquerres' });
    expect(sinDato).toContain('Túquerres');
    expect(sinDato).not.toContain('participantes examinados');
  });

  test('el perfil de la pantalla no se deja fuera ninguna categoría publicada', async ({ page }) => {
    await page.goto(BASE + '/paginas/tablero.html');
    const tarjeta = page.locator('.uhp-db__card', { hasText: 'Perfil de los 5.000' });

    /* Tres barras que sumaban 99,6 y nada que dijera que faltaba una
       cuarta. Dieciocho personas no son un redondeo. */
    await expect(tarjeta).toContainText('Delgadez');
    await expect(tarjeta).toContainText('Otro o no reportado');
  });

  test('una subregión que no se puede filtrar se explica, no se niega', async ({ page }) => {
    await conDoble(page);
    await page.goto(BASE + '/paginas/voz.html');
    await listo(page);
    await conversar(page);

    const r = await herramienta(page, 'uhp_filtrar_subregion', { subregion: 'Sanquianga' });
    // «No encuentro esa subregión» a secas sería falso: existe. Lo que no
    // hay es cifra publicada ni municipio intervenido.
    expect(r).toContain('Sanquianga');
    expect(r).toContain('no se pueden filtrar');
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

  test('la serie de los 55 colorea 55 municipios y deja nueve sin dato', async ({ page }) => {
    const errores = vigilar(page);
    await sinRed(page);
    await page.goto(BASE + '/paginas/geomapa.html');

    const fig = page.locator('[data-caso="serie-55"] [data-uhp-geomapa]');
    await expect(fig.locator('g.d3plus-geomap-paths path')).toHaveCount(64, { timeout: 25000 });

    // Es la vista que trajo la socialización ante el IDSN. Frente a la de
    // arriba, que solo tiene los diez extremos, aquí el mapa se llena: los
    // 55 municipios intervenidos con su cifra y los nueve restantes sin
    // ella, porque el estudio no llegó allí.
    const relleno = await fig.evaluate((nodo) => {
      const fills = Array.from(nodo.querySelectorAll('g.d3plus-geomap-paths path'))
        .map((p) => p.getAttribute('fill'));
      return {
        sinDato: fills.filter((f) => f === '#EDF1F5').length,
        conColor: fills.filter((f) => f !== '#EDF1F5').length
      };
    });
    expect(relleno.sinDato).toBe(9);
    expect(relleno.conColor).toBe(55);

    expect(errores).toEqual([]);
  });

  test('una vista sin un solo valor lo dice, en vez de una rampa de 0 a 0', async ({ page }) => {
    await sinRed(page);

    // Se simula una instalación cuyos archivos de datos se han quedado
    // atrás respecto del código: la vista existe y el mapa se dibuja, pero
    // la respuesta no trae valores. Ocurrió en el sitio real cuando un
    // despliegue copió includes/ y se saltó data/, y el mapa salía entero
    // en gris rotulado «0,0 %» a los dos lados, que no es lo que pasa.
    await page.route(/\/geomapa\?/, async (route) => {
      const resp = await route.fetch();
      const cuerpo = await resp.json();
      cuerpo.valores = {};
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(cuerpo) });
    });

    await page.goto(BASE + '/paginas/geomapa.html');

    const fig = page.locator('[data-caso="sin-teselas"] [data-uhp-geomapa]');
    const trazados = fig.locator('g.d3plus-geomap-paths path');
    await expect(trazados).toHaveCount(64, { timeout: 25000 });

    // El departamento se dibuja igual —el territorio sigue diciendo algo—
    // pero la leyenda avisa y no inventa un rango.
    const leyenda = fig.locator('.uhp-geo__leyenda');
    await expect(leyenda).toContainText('no trae ningún valor publicado');
    await expect(fig.locator('.uhp-geo__rango')).toHaveCount(0);
    await expect(fig.locator('.uhp-geo__escala')).toHaveCount(0);
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
    // La página trae también el caso con teselas: sin interceptarlas, sus
    // peticiones a la red acaban contadas como errores de esta prueba.
    await sinRed(page);
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
    await sinTeselas(page);
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

  /** Espera a que el tablero haya pintado el mapa y las tarjetas. */
  async function listo(page) {
    await expect(page.locator('.uhp-db__muni')).toHaveCount(64, { timeout: 25000 });
    await expect(page.locator('.uhp-db__mrow')).toHaveCount(55);
  }

  test('ocupa el 100 % de ancho y 100vh de alto, sin desbordar', async ({ page }) => {
    const errores = vigilar(page);
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(BASE + '/paginas/tablero.html');
    await listo(page);

    const m = await page.locator('.uhp-db').evaluate((n) => {
      const r = n.getBoundingClientRect();
      return {
        w: Math.round(r.width),
        h: Math.round(r.height),
        vw: window.innerWidth,
        vh: window.innerHeight,
        desborde: document.documentElement.scrollWidth - document.documentElement.clientWidth
      };
    });
    expect(m.w).toBe(m.vw);
    expect(m.h).toBe(m.vh);
    // El contenedor lleva relleno propio: sin border-box sumaría 28 px al
    // 100 % y empujaría la página a desbordarse en horizontal.
    expect(m.desborde).toBeLessThanOrEqual(1);

    expect(errores).toEqual([]);
  });

  test('el mapa dibuja los 64 municipios, cada uno con su propia extensión', async ({ page }) => {
    await page.goto(BASE + '/paginas/tablero.html');
    await listo(page);

    // La comprobación que importa: el sentido de giro de los anillos. D3
    // decide el interior del polígono por él, con el criterio INVERSO al
    // del RFC 7946. Un anillo al revés no se ve mal, se ve como el mundo
    // entero menos el municipio, y entonces TODOS los bbox miden lo mismo
    // y ocupan el lienzo completo. Por eso se comprueban las medidas y no
    // solo que existan los paths.
    const cajas = await page.locator('.uhp-db__muni').evaluateAll((ns) =>
      ns.slice(0, 12).map((n) => {
        const b = n.getBBox();
        return Math.round(b.width) + 'x' + Math.round(b.height);
      })
    );
    const distintas = new Set(cajas);
    expect(distintas.size).toBeGreaterThan(6);

    const lienzo = await page.locator('.uhp-db__mapa').evaluate((n) => {
      const r = n.getBoundingClientRect();
      return { w: r.width, h: r.height };
    });
    const mayor = await page.locator('.uhp-db__muni').evaluateAll((ns) =>
      Math.max(...ns.map((n) => n.getBBox().width))
    );
    // Ningún municipio puede ocupar casi todo el mapa del departamento.
    expect(mayor).toBeLessThan(lienzo.w * 0.75);
  });

  test('los siete municipios con casos llevan su círculo', async ({ page }) => {
    await page.goto(BASE + '/paginas/tablero.html');
    await listo(page);
    await expect(page.locator('.uhp-db__mapa circle')).toHaveCount(7);
  });

  test('filtrar por zona recorta la lista, las cifras y el título del mapa', async ({ page }) => {
    const errores = vigilar(page);
    await page.goto(BASE + '/paginas/tablero.html');
    await listo(page);

    await expect(page.locator('.uhp-db__kpi .val').first()).toHaveText('55');

    const amarilla = page.locator('.uhp-db__zbtn').nth(1);
    await amarilla.click();

    await expect(amarilla).toHaveAttribute('aria-pressed', 'true');
    await expect(page.locator('.uhp-db__zbtn[aria-pressed="true"]')).toHaveCount(1);
    await expect(page.locator('.uhp-db__kpi .val').first()).toHaveText('10');
    await expect(page.locator('.uhp-db__mrow')).toHaveCount(10);
    await expect(page.locator('[data-uhp-zona="mapatitulo"]')).toContainText('Zona amarilla');

    // El mismo botón otra vez deshace el filtro.
    await amarilla.click();
    await expect(page.locator('.uhp-db__mrow')).toHaveCount(55);

    expect(errores).toEqual([]);
  });

  test('elegir un municipio abre su ficha y lo resalta en el mapa', async ({ page }) => {
    const errores = vigilar(page);
    await page.goto(BASE + '/paginas/tablero.html');
    await listo(page);

    const belen = page.locator('.uhp-db__mrow', { hasText: 'Belén' }).first();
    await belen.click();

    await expect(belen).toHaveAttribute('aria-pressed', 'true');
    await expect(page.locator('[data-uhp-zona="detalle-titulo"]')).toHaveText('Municipio seleccionado');
    await expect(page.locator('[data-uhp-zona="mapatitulo"]')).toHaveText('Belén');

    const ficha = page.locator('[data-uhp-zona="detalle"]');
    await expect(ficha).toContainText('Belén');
    await expect(ficha).toContainText('DANE 52083');
    await expect(ficha).toContainText('Río Mayo');
    // Belén concentra 2 de los 8 casos detectados.
    await expect(ficha.locator('.uhp-db__dgrid .v').nth(2)).toHaveText('2');

    await expect(page.locator('.uhp-db__muni.sel')).toHaveCount(1);

    expect(errores).toEqual([]);
  });

  test('los 55 municipios intervenidos llevan ya su propia cifra', async ({ page }) => {
    await page.goto(BASE + '/paginas/tablero.html');
    await listo(page);

    // Cuando solo se conocían los extremos de la distribución, la mayoría de
    // los 55 se pintaba con la cifra de su subregión: atenuada al 0,72 y
    // diciéndolo en la ficha. La serie completa llegó con la socialización
    // ante el IDSN y ese respaldo ya no hace falta para ninguno de ellos.
    const opacidades = await page.locator('.uhp-db__muni').evaluateAll((ns) =>
      ns.filter((n) => n.getAttribute('stroke-dasharray') === null)
        .map((n) => n.getAttribute('fill-opacity'))
    );
    expect(opacidades.length).toBe(55);
    expect(opacidades.filter((o) => o === '0.72')).toEqual([]);

    // Tangua no estaba en ninguno de los dos extremos publicados: si su
    // ficha declara cifra propia, la serie completa llegó hasta el mapa.
    await page.locator('.uhp-db__mrow', { hasText: 'Tangua' }).first().click();
    const nota = await page.locator('[data-uhp-zona="detalle"] .uhp-db__nota').textContent();
    expect(nota).toContain('Cifras propias del municipio');
    expect(nota).not.toContain('Sin cifra municipal');

    // Y la cifra que enseña es la suya, no la de Juanambú, su subregión.
    const lpm = await page.locator('[data-uhp-zona="detalle"] .uhp-db__dgrid .v').first().textContent();
    expect(lpm.trim()).toBe('28,6%');
  });

  test('los nueve municipios fuera del estudio siguen sin colorear', async ({ page }) => {
    await page.goto(BASE + '/paginas/tablero.html');
    await listo(page);

    // La serie completa cubre a los 55 que el proyecto intervino. Los otros
    // nueve no entraron en el estudio: ahí no falta un dato, faltó el
    // tamizaje, y el mapa tiene que seguir distinguiéndolo.
    const fuera = await page.locator('.uhp-db__muni').evaluateAll((ns) =>
      ns.filter((n) => n.getAttribute('stroke-dasharray') !== null).length
    );
    expect(fuera).toBe(9);
  });

  test('cambiar de indicador repinta leyenda, barras y cifras', async ({ page }) => {
    const errores = vigilar(page);
    await page.goto(BASE + '/paginas/tablero.html');
    await listo(page);

    await expect(page.locator('[data-uhp-zona="leyenda-titulo"]')).toContainText('LPM');
    await expect(page.locator('.uhp-db__fill.hp')).toHaveCount(0);

    await page.locator('[data-uhp-ind="hp"]').click();

    await expect(page.locator('[data-uhp-zona="leyenda-titulo"]')).toContainText('H. pylori');
    await expect(page.locator('[data-uhp-ind="hp"]')).toHaveAttribute('aria-pressed', 'true');
    await expect(page.locator('[data-uhp-ind="lpm"]')).toHaveAttribute('aria-pressed', 'false');
    // Las once subregiones documentadas, ahora en la rampa azul.
    await expect(page.locator('.uhp-db__fill.hp')).toHaveCount(11);
    await expect(page.locator('.uhp-db__kpi').nth(2)).toContainText('H. pylori');

    expect(errores).toEqual([]);
  });

  test('la cifra de cada barra se lee sobre el fondo que le toca', async ({ page }) => {
    await page.goto(BASE + '/paginas/tablero.html');
    await listo(page);

    // El diseño fijaba la cifra al borde del track y elegía tinta por un
    // umbral. Con la barra a media asta eso deja el número medio sobre el
    // relleno y medio sobre el hueco, y la tinta oscura sobre el hueco da
    // 1,17:1. Anclada al relleno solo hay un fondo debajo: se comprueba
    // que la tinta oscura aparece únicamente en las barras largas.
    const filas = await page.locator('.uhp-db__bar').evaluateAll((ns) =>
      ns.map((n) => {
        const t = n.querySelector('.uhp-db__track');
        const f = n.querySelector('.uhp-db__fill');
        const s = n.querySelector('span');
        return {
          p: parseFloat(f.style.width),
          dentro: s.classList.contains('dentro')
        };
      })
    );
    expect(filas.length).toBeGreaterThan(5);
    for (const f of filas) {
      // Tinta oscura solo cuando el relleno pasa de la mitad del track.
      expect(f.dentro).toBe(f.p >= 50);
    }
  });

  test('elegir una subregión en las barras mueve también el mapa y la zona', async ({ page }) => {
    const errores = vigilar(page);
    await page.goto(BASE + '/paginas/tablero.html');
    await listo(page);

    const barra = page.locator('.uhp-db__bar').first();
    const nombre = await barra.locator('.nm').textContent();
    await barra.click();

    await expect(barra).toHaveAttribute('aria-pressed', 'true');
    await expect(page.locator('[data-uhp-zona="mapatitulo"]')).toContainText('Subregión ' + nombre);
    // La subregión arrastra su zona: sin eso, filtrar por una subregión de
    // la costa con la zona roja activa daría una selección vacía.
    await expect(page.locator('.uhp-db__zbtn[aria-pressed="true"]')).toHaveCount(1);
    const filas = await page.locator('.uhp-db__mrow').count();
    expect(filas).toBeGreaterThan(0);
    expect(filas).toBeLessThan(55);

    expect(errores).toEqual([]);
  });

  test('«Limpiar» devuelve el tablero a su estado inicial', async ({ page }) => {
    await page.goto(BASE + '/paginas/tablero.html');
    await listo(page);

    await page.locator('.uhp-db__zbtn').first().click();
    await page.locator('.uhp-db__mrow').first().click();
    await expect(page.locator('[data-uhp-zona="detalle-titulo"]')).toHaveText('Municipio seleccionado');

    await page.locator('[data-uhp-accion="limpiar"]').click();

    await expect(page.locator('.uhp-db__mrow')).toHaveCount(55);
    await expect(page.locator('.uhp-db__zbtn[aria-pressed="true"]')).toHaveCount(0);
    await expect(page.locator('[data-uhp-zona="detalle-titulo"]')).toHaveText('Casos de cáncer gástrico detectados');
    await expect(page.locator('[data-uhp-zona="mapatitulo"]')).toContainText('55 municipios priorizados');
  });

  test('los botones de zoom mueven el mapa', async ({ page }) => {
    await page.goto(BASE + '/paginas/tablero.html');
    await listo(page);

    const transform = () => page.locator('.uhp-db__mapa svg > g').getAttribute('transform');
    const inicio = await transform();

    await page.locator('[data-uhp-accion="zoom-mas"]').click();
    await page.waitForTimeout(600);
    const acercado = await transform();
    expect(acercado).not.toBe(inicio);

    await page.locator('[data-uhp-accion="zoom-menos"]').click();
    await page.waitForTimeout(600);
    expect(await transform()).not.toBe(acercado);
  });

  test('la lista de casos lleva a su municipio', async ({ page }) => {
    await page.goto(BASE + '/paginas/tablero.html');
    await listo(page);

    // Siete municipios con caso, ordenados de más a menos.
    const lineas = page.locator('.uhp-db__cline');
    await expect(lineas).toHaveCount(7);
    await expect(lineas.first()).toContainText('Belén');

    await lineas.first().click();
    await expect(page.locator('[data-uhp-zona="mapatitulo"]')).toHaveText('Belén');
  });

  test('el perfil de los participantes NO responde a los filtros', async ({ page }) => {
    await page.goto(BASE + '/paginas/tablero.html');
    await listo(page);

    // El perfil está publicado para el conjunto de los 5.000, no municipio
    // a municipio. Si se redibujara con la selección parecería responder a
    // ella, que es justo lo que el conjunto de datos se niega a afirmar.
    const antes = await page.locator('.uhp-db__perfil').textContent();
    await page.locator('.uhp-db__zbtn').first().click();
    await page.locator('.uhp-db__mrow').first().click();
    expect(await page.locator('.uhp-db__perfil').textContent()).toBe(antes);
  });

  test('el cambio de selección se anuncia a los lectores de pantalla', async ({ page }) => {
    await page.goto(BASE + '/paginas/tablero.html');
    await listo(page);

    const estado = page.locator('[data-uhp-zona="estado"]');
    await expect(estado).toHaveAttribute('aria-live', 'polite');
    await expect(estado).toContainText('55 municipios');

    // Elegir un municipio se anuncia en una LÍNEA BREVE. La ficha de la
    // derecha llevaba aria-live y con ella un lector de pantalla leía sus
    // veinte líneas enteras con cada clic; es un bloque de contenido, no
    // un estado, y con el asistente de voz moviendo filtros eso pasaba de
    // molesto a secuestro.
    await page.locator('.uhp-db__mrow', { hasText: 'Pasto' }).first().click();
    await expect(estado).toContainText('Pasto');
    await expect(estado).toContainText('a la vista');
    await expect(page.locator('[data-uhp-zona="detalle"]')).not.toHaveAttribute('aria-live', 'polite');

    // Y filtrar por zona también, con el recuento que deja.
    await page.locator('.uhp-db__zbtn').nth(1).click();
    await expect(estado).toContainText('10 municipios a la vista');
  });

  test('todos los pares tinta/fondo cumplen la norma de contraste', async ({ page }) => {
    await page.goto(BASE + '/paginas/tablero.html');
    await listo(page);

    // WCAG 2.1 AA, que la Resolución 1519 de 2020 adopta: 4,5:1 para texto
    // y 3:1 para la información visual que identifica un control. Se mide
    // sobre los colores YA COMPUTADOS por el navegador, no sobre los
    // tokens: es lo que de verdad ve quien entra.
    const medidas = await page.evaluate(() => {
      const lum = (css) => {
        const [r, g, b] = css.match(/[\d.]+/g).slice(0, 3).map(Number);
        const f = (v) => {
          const x = v / 255;
          return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4);
        };
        return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
      };
      const razon = (a, b) => {
        const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p);
        return (x + 0.05) / (y + 0.05);
      };
      // Sube por los ancestros hasta dar con un fondo opaco de verdad.
      const fondoDe = (n) => {
        let c = n;
        while (c && c !== document.documentElement) {
          const bg = getComputedStyle(c).backgroundColor;
          if (bg && bg !== 'transparent' && !bg.startsWith('rgba(0, 0, 0, 0')) { return bg; }
          c = c.parentElement;
        }
        return 'rgb(255, 255, 255)';
      };

      const texto = [
        '.uhp-db__card h2', '.uhp-db__kpi .lab', '.uhp-db__kpi .val',
        '.uhp-db__kpi .sub', '.uhp-db__zbtn', '.uhp-db__mrow',
        '.uhp-db__mrow .sr', '.uhp-db__bar .nm', '.uhp-db__perfil .lab',
        '.uhp-db__perfil .leg', '.uhp-db__dgrid .l', '.uhp-db__dgrid .v',
        '.uhp-db__nota', '.uhp-db__pill', '.uhp-db__lema'
      ];
      const bordes = ['.uhp-db__zbtn', '.uhp-db__tab', '.uhp-db__limpiar', '.uhp-db__zoomctl button'];

      const flojos = [];
      texto.forEach((sel) => {
        const n = document.querySelector(sel);
        if (!n) { return; }
        const v = razon(getComputedStyle(n).color, fondoDe(n));
        if (v < 4.5) { flojos.push(sel + ' texto ' + v.toFixed(2) + ':1'); }
      });
      bordes.forEach((sel) => {
        const n = document.querySelector(sel);
        if (!n) { return; }
        const v = razon(getComputedStyle(n).borderTopColor, fondoDe(n.parentElement));
        if (v < 3) { flojos.push(sel + ' borde ' + v.toFixed(2) + ':1'); }
      });
      return flojos;
    });

    expect(medidas, 'pares por debajo del mínimo').toEqual([]);
  });

  test('la fachada de mando mueve el tablero por el mismo camino que un clic', async ({ page }) => {
    await page.goto(BASE + '/paginas/tablero.html');
    await listo(page);

    // El asistente de voz no toca el estado del tablero: pasa por esta
    // fachada, que es la que usan también los manejadores de clic. Si
    // divergieran, hablar y pulsar darían resultados distintos.
    const hay = await page.evaluate(() => !!(window.UHPTablero && window.UHPTablero.mando()));
    expect(hay, 'el tablero no expone su mando').toBe(true);

    // Indicador: repinta Y mueve el aria-pressed de las pestañas, que es
    // lo que antes hacía solo el manejador de clic.
    await page.evaluate(() => window.UHPTablero.mando().setIndicador('hp'));
    await expect(page.locator('[data-uhp-zona="leyenda-titulo"]')).toContainText('H. pylori');
    await expect(page.locator('[data-uhp-ind="hp"]')).toHaveAttribute('aria-pressed', 'true');
    await expect(page.locator('[data-uhp-ind="lpm"]')).toHaveAttribute('aria-pressed', 'false');

    // Zona: recorta la lista igual que el botón.
    await page.evaluate(() => window.UHPTablero.mando().setZona('amarilla'));
    await expect(page.locator('.uhp-db__mrow')).toHaveCount(10);
    await expect(page.locator('.uhp-db__kpi .val').first()).toHaveText('10');

    // Municipio: abre su ficha y lo resalta en el mapa.
    await page.evaluate(() => window.UHPTablero.mando().setMunicipio('Pasto'));
    await expect(page.locator('[data-uhp-zona="detalle"] .big')).toHaveText('Pasto');
    await expect(page.locator('.uhp-db__muni.sel')).toHaveCount(1);

    await page.evaluate(() => window.UHPTablero.mando().limpiar());
    await expect(page.locator('.uhp-db__mrow')).toHaveCount(55);
  });

  test('un cambio del asistente se anuncia diciendo que no fue el visitante', async ({ page }) => {
    await page.goto(BASE + '/paginas/tablero.html');
    await listo(page);

    // Quien no ve la pantalla tiene que poder distinguir lo que hizo él
    // de lo que le hicieron: media pantalla cambia sin que haya tocado
    // nada y sin que el foco se mueva.
    await page.evaluate(() => window.UHPTablero.mando().setZona('roja', 'agente'));
    await expect(page.locator('[data-uhp-zona="estado"]')).toContainText('El asistente');

    // El mismo cambio hecho a mano NO se atribuye a nadie.
    await page.locator('[data-uhp-accion="limpiar"]').click();
    await expect(page.locator('[data-uhp-zona="estado"]')).not.toContainText('El asistente');
  });

  test('resolver un municipio dictado no acierta por aproximación', async ({ page }) => {
    await page.goto(BASE + '/paginas/tablero.html');
    await listo(page);

    const r = await page.evaluate(() => {
      const m = window.UHPTablero.mando();
      return {
        exacto: m.resolver('túquerres'),
        sinTilde: m.resolver('tuquerres'),
        alias: m.resolver('Génova'),
        codigo: m.resolver('52001'),
        // Pupiales e Ipiales, Albán y Aldana, Iles e Imués son los tres
        // pares a dos cambios de distancia entre los 55 intervenidos.
        // Confundirlos es leer en voz alta la cifra de otro pueblo.
        vecino: m.resolver('Albán'),
        casi: m.resolver('Sandona del Sur'),
        fuera: m.resolver('Tumaco'),
        nada: m.resolver('Barranquilla')
      };
    });

    expect(r.exacto.estado).toBe('exacto');
    expect(r.exacto.municipio.n).toBe('Túquerres');
    expect(r.sinTilde.estado).toBe('exacto');
    expect(r.alias.municipio.n).toBe('Colón (Génova)');
    expect(r.codigo.municipio.n).toBe('Pasto');

    // Con un vecino a dos cambios, la resolución lo dice para poder avisar.
    expect(r.vecino.estado).toBe('exacto');
    expect(r.vecino.vecino).toBe('Aldana');

    // Lo que no cruza exacto NO selecciona: sugiere.
    expect(['sugerencia', 'ninguno']).toContain(r.casi.estado);
    expect(r.casi.municipio).toBeUndefined();

    // Un municipio de Nariño que el proyecto no intervino no es una
    // errata: es que allí no hubo tamizaje.
    expect(r.fuera.estado).toBe('fuera');
    expect(r.nada.estado).toBe('ninguno');
  });

  test('la ficha por voz dice si la cifra es del municipio o de su subregión', async ({ page }) => {
    await page.goto(BASE + '/paginas/tablero.html');
    await listo(page);

    const f = await page.evaluate(() => window.UHPTablero.mando().ficha('Túquerres'));
    expect(f.c).toBe('52838');
    expect(f.sub.length).toBeGreaterThan(0);
    // Los 55 intervenidos traen cifra propia desde la serie completa.
    expect(f.lpm.origen).toBe('municipal');
    expect(f.hp.origen).toBe('municipal');
    expect(typeof f.lpm.valor).toBe('number');

    // El resumen que lee el asistente sale del mismo cálculo que los KPI.
    const e = await page.evaluate(() => window.UHPTablero.mando().estado());
    expect(e.visibles).toBe(55);
    expect(e.indicador).toBe('lpm');
    expect(Math.abs(e.promedio - 35.1)).toBeLessThan(0.6);
  });

  test('en móvil las columnas se apilan sin desbordar', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(BASE + '/paginas/tablero.html');
    await listo(page);

    const desborde = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth
    );
    expect(desborde).toBeLessThanOrEqual(1);

    // Una sola columna: el mapa deja de compartir fila con los paneles.
    const cols = await page.locator('.uhp-db__main').evaluate(
      (n) => getComputedStyle(n).gridTemplateColumns.split(' ').length
    );
    expect(cols).toBe(1);
  });

  test('su hoja llega en el <head>, no después del marcado', async ({ page }) => {
    await page.goto(BASE + '/paginas/tablero.html');

    // EL FALLO QUE ESTO IMPIDE. Los shortcodes encolan sus hojas cuando se
    // renderizan, y eso ocurre cuando wp_head ya pasó: WordPress las saca
    // entonces en el pie. El marcado se pinta en crudo hasta que llegan, y
    // en la página real eso eran 223 KB de HTML sin estilo. Con la página
    // grande y la conexión lenta el parpadeo dura lo suficiente para que
    // parezca que el tablero no funciona.
    const posicion = await page.evaluate(() => {
      const link = document.querySelector('link[data-handle="uhp-dashboard"]');
      const db = document.querySelector('.uhp-db');
      if (!link || !db) { return { falta: true }; }
      return {
        enHead: link.closest('head') !== null,
        // Y antes que el contenedor en el orden del documento.
        antesDelMarcado: !!(link.compareDocumentPosition(db) & Node.DOCUMENT_POSITION_FOLLOWING)
      };
    });

    expect(posicion.falta, 'no se encontró la hoja o el contenedor').toBeFalsy();
    expect(posicion.enHead, 'la hoja del tablero salió fuera del <head>').toBe(true);
    expect(posicion.antesDelMarcado).toBe(true);

    // Y el estilo está realmente aplicado, no solo enlazado.
    await expect(page.locator('.uhp-db')).toHaveCSS('display', 'grid');
  });

  test('el tablero no se apodera de la página que lo contiene', async ({ page }) => {
    await page.goto(BASE + '/paginas/tablero-embebido.html');
    await listo(page);

    // Define su propia retícula, tipografía y fondo. Nada de eso puede
    // alcanzar al contenido de alrededor.
    const fuera = await page.locator('h1').first().evaluate((n) => {
      const s = getComputedStyle(n);
      return { familia: s.fontFamily, color: s.color, tamano: s.fontSize };
    });
    expect(fuera.familia.toLowerCase()).not.toContain('ibm plex');

    const dentro = await page.locator('.uhp-db__marca h1').evaluate((n) => getComputedStyle(n).fontSize);
    expect(dentro).toBe('19px');

    // Y con alto propio deja de estar atado a la ventana.
    const alto = await page.locator('.uhp-db').evaluate((n) => Math.round(n.getBoundingClientRect().height));
    expect(alto).toBe(640);
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
    await sinTeselas(page);
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
    // recarga, sigue a la misma lista. La vista nueva tiene territorio, de
    // modo que abre en el mapa con sus trece subregiones.
    await expect(fig).toHaveAttribute('data-view', 'prev_subregion_hp');
    await expect(fig).toHaveAttribute('data-type', 'mapa', { timeout: 25000 });
    await expect(fig.locator('g.d3plus-geomap-paths path')).toHaveCount(13, { timeout: 25000 });

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
    //
    // Se prueban las DOS formas de panel: el <div> envolvente que lleva la
    // clase .uhp-panel y el <p> de la descripción dentro del propio
    // selector, que no la lleva. Lo que define a un panel es el atributo,
    // y la defensa del CSS tiene que ir contra el atributo.
    await expect(page.locator('[data-zona="titulo"] [data-uhp-panel][hidden]').first()).toBeHidden();
    await expect(page.locator('[data-zona="selector"] p[data-uhp-panel][hidden]').first()).toBeHidden();

    // Y exactamente una descripción visible dentro del selector.
    await expect(page.locator('[data-zona="selector"] p[data-uhp-panel]:not([hidden])')).toHaveCount(1);
  });

  test('un canal de solo selector y gráfico funciona con el selector delante', async ({ page }) => {
    const errores = vigilar(page);
    await page.goto(BASE + '/paginas/selector.html');

    const zona = page.locator('[data-zona="solo-grafico"]');
    const fig = zona.locator('[data-uhp-grafico]');
    await expect(fig.locator('.uhp-skeleton')).toHaveCount(0, { timeout: 25000 });
    await expect(fig).toHaveAttribute('data-view', 'cancer_municipios');

    // Con el selector delante del gráfico, su script se imprime antes: un
    // aviso inmediato al arrancar se perdería porque la figura todavía no
    // tendría puesto su oyente. Aquí se comprueba el camino normal, que es
    // que el cambio del usuario sí llega.
    await zona.locator('.uhp-sel__select').selectOption('cancer_desenlace');
    await expect(fig).toHaveAttribute('data-view', 'cancer_desenlace');
    await expect(fig.locator('.uhp-g__lienzo svg')).toHaveCount(1, { timeout: 25000 });

    expect(errores).toEqual([]);
  });

  test('al cambiar de vista, la que tiene territorio abre en el mapa', async ({ page }) => {
    const errores = vigilar(page);
    await sinTeselas(page);
    await page.goto(BASE + '/paginas/selector.html');

    const fig = page.locator('[data-zona="grafico"] [data-uhp-grafico]');
    await expect(fig.locator('.uhp-skeleton')).toHaveCount(0, { timeout: 25000 });

    // «Municipios con mayor infección» tiene por defecto las barras. Al
    // llegar desde el selector abre igualmente en el mapa: es la lectura
    // que pidió la Secretaría para cualquier vista con territorio.
    await page.locator('[data-zona="selector"] .uhp-sel__select').selectOption('prev_hp_municipios');
    await expect(fig).toHaveAttribute('data-type', 'mapa', { timeout: 25000 });
    await expect(fig.locator('g.d3plus-geomap-paths path')).toHaveCount(64, { timeout: 25000 });

    // Y la barra de herramientas lo refleja, para poder salir del mapa.
    await expect(fig.locator('.uhp-g__select')).toHaveValue('mapa');

    expect(errores).toEqual([]);
  });

  test('una vista sin territorio conserva su propio tipo por defecto', async ({ page }) => {
    await sinTeselas(page);
    await page.goto(BASE + '/paginas/selector.html');

    const fig = page.locator('[data-zona="solo-grafico"] [data-uhp-grafico]');
    await expect(fig.locator('.uhp-skeleton')).toHaveCount(0, { timeout: 25000 });

    const sel = page.locator('[data-zona="solo-grafico"] .uhp-sel__select');

    // El desenlace no tiene territorio: se dibuja con su propia dona, no
    // con un mapa vacío.
    await sel.selectOption('cancer_desenlace');
    await expect(fig).toHaveAttribute('data-type', 'donut', { timeout: 25000 });
    await expect(fig.locator('g.d3plus-geomap-paths path')).toHaveCount(0);

    // Los casos por municipio sí lo tienen: al volver, mapa.
    await sel.selectOption('cancer_municipios');
    await expect(fig).toHaveAttribute('data-type', 'mapa', { timeout: 25000 });
    await expect(fig.locator('g.d3plus-geomap-paths path')).toHaveCount(64, { timeout: 25000 });
  });

  test('cambiar de tipo a mano no devuelve al mapa en el siguiente dibujo', async ({ page }) => {
    await sinTeselas(page);
    await page.goto(BASE + '/paginas/selector.html');

    const fig = page.locator('[data-zona="grafico"] [data-uhp-grafico]');
    await expect(fig.locator('.uhp-skeleton')).toHaveCount(0, { timeout: 25000 });

    await page.locator('[data-zona="selector"] .uhp-sel__select').selectOption('prev_hp_55');
    await expect(fig).toHaveAttribute('data-type', 'mapa', { timeout: 25000 });

    // La preferencia se gasta al aplicarla: quien elige barras se queda en
    // barras hasta que vuelva a cambiar de vista.
    await fig.locator('.uhp-g__select').selectOption('bar');
    await expect(fig).toHaveAttribute('data-type', 'bar', { timeout: 25000 });
    await expect(fig.locator('.uhp-g__lienzo svg')).toHaveCount(1);
    await expect(fig.locator('g.d3plus-geomap-paths path')).toHaveCount(0);
  });

  test('el borde del control se distingue de su fondo, como exige la norma', async ({ page }) => {
    await page.goto(BASE + '/paginas/selector.html');

    // WCAG 2.1 §1.4.11, que la Resolución 1519 de 2020 adopta: la línea
    // que separa un control de su fondo es información visual necesaria
    // para identificarlo y pide 3:1. El borde decorativo de una tarjeta
    // no tiene esa obligación; el de un <select>, sí.
    const medida = await page.locator('[data-zona="selector"] .uhp-sel__select').evaluate((n) => {
      const s = getComputedStyle(n);
      return { borde: s.borderTopColor, fondo: s.backgroundColor };
    });

    const lum = (css) => {
      const [r, g, b] = css.match(/[\d.]+/g).slice(0, 3).map(Number);
      const f = (v) => {
        const x = v / 255;
        return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4);
      };
      return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
    };
    const a = lum(medida.borde);
    const b = lum(medida.fondo);
    const razon = (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);

    expect(razon, `borde ${medida.borde} sobre ${medida.fondo}`).toBeGreaterThanOrEqual(3);
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
