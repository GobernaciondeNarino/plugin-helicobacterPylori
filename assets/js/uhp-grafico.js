/* [urkunina_grafico] — hidratador.

   Lee los data-* del contenedor, pide /render a la REST, llama a
   UHPRenderer y cablea la barra de herramientas: Detalle, Datos,
   Compartir, Imagen PNG, Descarga JSON y cambio de tipo en vivo.

   El shortcode dibuja SOLO el gráfico. La descripción, la
   interpretación, el resumen, las cifras y la fuente son shortcodes
   aparte que se renderizan en servidor, para poder maquetarlos por libre
   en la página; aquí no queda nada que pintar de ellos.

   Toda la salida se compone con textContent o con nodos creados a mano:
   nada de lo que devuelve la REST se inserta como HTML. */
(function () {
  'use strict';

  var C = window.UHPcore;

  var ACCIONES = ['detalle', 'datos', 'compartir', 'imagen', 'descarga', 'cambiar'];
  var ETIQUETA_ACCION = {
    detalle: 'Detalle',
    datos: 'Datos',
    compartir: 'Compartir',
    imagen: 'Imagen',
    descarga: 'Descarga',
    cambiar: 'Cambiar'
  };
  var TITULO_ACCION = {
    detalle: 'Ver los metadatos del gráfico',
    datos: 'Ver la tabla completa de datos',
    compartir: 'Copiar el enlace a este gráfico',
    imagen: 'Descargar el gráfico como imagen PNG',
    descarga: 'Descargar los datos en formato JSON',
    cambiar: 'Cambiar el tipo de gráfico'
  };

  var reducirMovimiento = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  C.ready(function () {
    Array.prototype.forEach.call(document.querySelectorAll('[data-uhp-grafico]'), iniciarGrafico);
  });

  /* ================================================================== */
  /* [urkunina_grafico]                                                 */
  /* ================================================================== */

  function iniciarGrafico(fig) {
    var lienzo = fig.querySelector('.uhp-g__lienzo');
    var titulo = fig.querySelector('.uhp-g__titulo');
    if (!lienzo) { return; }

    var st = {
      view: fig.getAttribute('data-view') || '',
      type: fig.getAttribute('data-type') || '',
      legend: fig.getAttribute('data-legend') !== '0',
      legendStyle: fig.getAttribute('data-legend-style') || 'text',
      legendPos: fig.getAttribute('data-legend-pos') || 'bottom',
      acciones: parseAcciones(fig.getAttribute('data-acciones')),
      // Opciones del tipo «mapa». Viajan siempre, aunque el gráfico
      // arranque en barras: el usuario puede cambiar de tipo en la barra
      // y el mapa debe salir ya configurado como pide el shortcode.
      tema: fig.getAttribute('data-tema') === 'oscuro' ? 'oscuro' : 'claro',
      teselas: fig.getAttribute('data-teselas') === '1',
      serie: fig.getAttribute('data-serie') || '',
      etiquetas: fig.getAttribute('data-etiquetas') === '1',
      // Se enciende solo al cambiar de vista desde el selector; ver el
      // manejador de `uhp:canal` y `preferenciaDeTipo()`.
      preferirMapa: false,
      payload: null,
      viz: null,
      geo: null
    };

    cargar(fig, lienzo, titulo, st);

    // Gráfico agrupado: obedece al selector de su canal. Se escucha en
    // `document` porque el selector puede estar en otra columna o incluso
    // antes en el documento, y la figura no tiene forma de encontrarlo.
    var canal = fig.getAttribute('data-canal');
    if (canal) {
      document.addEventListener('uhp:canal', function (ev) {
        var d = ev.detail || {};
        if (d.canal !== canal || !d.vista || d.vista === st.view) { return; }

        st.view = d.vista;
        fig.setAttribute('data-view', d.vista);

        // El tipo NO se arrastra entre vistas: «dona» no existe en un
        // ranking y «mapa» no existe en una vista sin geometría. Se deja
        // que el servidor elija el tipo por defecto de la vista nueva…
        st.type = '';
        fig.setAttribute('data-type', '');

        // …salvo que la vista nueva tenga territorio, y entonces abre en el
        // mapa. Una vista municipal dice mucho más repartida sobre Nariño
        // que en una lista de cincuenta y cinco barras, y el resto de tipos
        // sigue a un clic en la barra de herramientas.
        st.preferirMapa = true;

        if (st.geo) {
          window.UHPGeomapa.destruir(st.geo);
          st.geo = null;
        }
        lienzo.innerHTML = '';
        lienzo.classList.remove('uhp-g__lienzo--mapa');
        cargar(fig, lienzo, titulo, st);
      });
    }
  }

  function parseAcciones(s) {
    if (!s) { return ACCIONES.slice(); }
    var arr = String(s).split(',').map(function (x) { return x.trim(); })
      .filter(function (x) { return ACCIONES.indexOf(x) >= 0; });
    return arr.length ? arr : ACCIONES.slice();
  }

  function cargar(fig, lienzo, titulo, st) {
    // Con `then(exito, fallo)` en vez de `.then().catch()`, una excepción
    // al pintar NO cae en el manejador de red: un error de dibujo dejaría
    // de anunciarse como «no se pudo cargar» y apuntaría al culpable
    // equivocado, que es justo lo que ocultó un fallo de dependencias.
    C.rest('/render', { view: st.view, type: st.type })
      .then(function (p) {
        st.payload = p;
        st.type = preferenciaDeTipo(st, p);
        fig.setAttribute('data-type', st.type);

        var nombre = (p.view && p.view.name) || 'Gráfico';
        if (titulo) { titulo.textContent = nombre; }
        lienzo.setAttribute('role', 'img');
        lienzo.setAttribute('aria-label', nombre + '. ' +
          ((p.view && p.view.description) || '') + ' ' +
          ((p.view && p.view.analisis && p.view.analisis.cuantitativo) || ''));

        C.quitarSkeleton(fig);
        dibujar(fig, lienzo, st);

        pintarBarra(fig, lienzo, titulo, st);
      }, function () {
        C.error(lienzo, 'No se pudieron cargar los datos del gráfico.', function () {
          cargar(fig, lienzo, titulo, st);
        });
      })
      .catch(function (err) {
        // Llegar aquí significa que los datos sí llegaron y falló el
        // dibujo. Se deja traza en consola: sin ella, diagnosticarlo
        // obliga a instrumentar el navegador a mano.
        if (window.console && console.error) {
          console.error('[URKUNINA 5000] fallo al dibujar la vista ' + st.view, err);
        }
        C.error(lienzo, 'No se pudo dibujar el gráfico.', function () {
          cargar(fig, lienzo, titulo, st);
        });
      });
  }

  /**
   * Tipo con el que se dibuja la respuesta recién llegada.
   *
   * Normalmente manda el servidor, que aplica el tipo del shortcode o el
   * de la vista. La excepción es el cambio de vista desde un selector: ahí
   * la vista con territorio abre en el mapa. La preferencia se consume de
   * una vez, para que quien cambie de tipo a mano en la barra no se vea
   * devuelto al mapa en el siguiente redibujo.
   *
   * @param {object} st Estado de la figura.
   * @param {object} p  Respuesta de /render.
   * @return {string}
   */
  function preferenciaDeTipo(st, p) {
    var tipo = (p.chart && p.chart.key) || st.type;
    var prefiere = st.preferirMapa;
    st.preferirMapa = false;

    if (!prefiere || !p.geo) { return tipo; }
    var compatibles = p.compatible || [];
    return compatibles.indexOf('mapa') >= 0 ? 'mapa' : tipo;
  }

  /* ---------------- Dibujo ---------------- */

  /* Un solo punto de dibujo para los dos motores.

     «mapa» no es un tipo de D3plus como los demás: necesita la topología
     del departamento y los valores por territorio, que es justo lo que
     ya sabe hacer [urkunina_geomapa]. En vez de duplicarlo aquí, se monta
     el mismo componente sobre el lienzo del gráfico.

     Salir del mapa exige desmontarlo antes: los dos motores dibujan
     DENTRO del lienzo y no lo vacían al soltarlo, de modo que sin el
     desmontaje el nuevo gráfico quedaría encima del mapa anterior.       */
  function dibujar(fig, lienzo, st) {
    if (st.geo) {
      window.UHPGeomapa.destruir(st.geo);
      st.geo = null;
    }

    if (st.type === 'mapa') {
      if (!window.UHPGeomapa) {
        C.error(lienzo, 'No se pudo dibujar el mapa: falta el componente de geomapas.');
        return;
      }
      st.viz = null;
      lienzo.innerHTML = '';
      lienzo.classList.add('uhp-g__lienzo--mapa');

      var g = (st.payload && st.payload.geo) || {};
      st.geo = window.UHPGeomapa.montar({
        fig: fig,
        lienzo: lienzo,
        leyendaCaja: fig.querySelector('.uhp-g__leyenda'),
        view: st.view,
        nivel: g.nivel,
        // Con la vista partida en series el mapa solo puede pintar una.
        // Si el shortcode no eligió, se toma la primera que declara la
        // vista, que es la misma que elegiría el servidor.
        serie: st.serie || ((g.series && g.series[0]) || ''),
        tema: st.tema,
        teselas: st.teselas,
        leyenda: st.legend,
        etiquetas: st.etiquetas
      });
      return;
    }

    lienzo.classList.remove('uhp-g__lienzo--mapa');
    st.viz = window.UHPRenderer.render(lienzo, st.payload, {
      legend: st.legend,
      legendStyle: st.legendStyle,
      legendPos: st.legendPos,
      reducirMovimiento: reducirMovimiento
    });
  }

  /* ---------------- Barra de herramientas ---------------- */

  function pintarBarra(fig, lienzo, titulo, st) {
    var barra = fig.querySelector('.uhp-g__barra');
    if (!barra) { return; }
    barra.innerHTML = '';

    st.acciones.forEach(function (accion) {
      if (accion === 'cambiar') {
        var compat = (st.payload && st.payload.compatible) || [];
        if (compat.length < 2) { return; }
        barra.appendChild(selectorTipo(fig, lienzo, titulo, st, compat));
        return;
      }
      var b = C.el('button', 'uhp-g__btn', ETIQUETA_ACCION[accion]);
      b.type = 'button';
      b.title = TITULO_ACCION[accion];
      b.setAttribute('data-accion', accion);
      b.addEventListener('click', function () { ejecutar(accion, b, fig, lienzo, st); });
      barra.appendChild(b);
    });
  }

  function selectorTipo(fig, lienzo, titulo, st, compat) {
    var envoltura = C.el('span', 'uhp-g__cambiar');
    var etq = C.el('label', 'uhp-g__cambiar-etq', 'Tipo');
    var id = 'uhp-tipo-' + Math.random().toString(36).slice(2, 9);
    etq.setAttribute('for', id);

    var sel = C.el('select', 'uhp-g__select');
    sel.id = id;
    sel.title = TITULO_ACCION.cambiar;

    var tipos = (st.payload && st.payload.tipos) || null;
    compat.forEach(function (t) {
      var o = C.el('option', '', nombreTipo(t, tipos));
      o.value = t;
      if (t === st.type) { o.selected = true; }
      sel.appendChild(o);
    });

    sel.addEventListener('change', function () {
      var nuevo = sel.value;
      fig.classList.add('is-cargando');
      C.rest('/render', { view: st.view, type: nuevo })
        .then(function (p) {
          st.payload = p;
          st.type = (p.chart && p.chart.key) || nuevo;
          fig.setAttribute('data-type', st.type);
          dibujar(fig, lienzo, st);
        })
        .catch(function () {
          C.error(lienzo, 'No se pudo cambiar el tipo de gráfico.', function () {
            cargar(fig, lienzo, titulo, st);
          });
        })
        .then(function () { fig.classList.remove('is-cargando'); });
    });

    envoltura.appendChild(etq);
    envoltura.appendChild(sel);
    return envoltura;
  }

  var NOMBRE_TIPO = {
    bar: 'Barras', stacked_bar: 'Barras apiladas', line: 'Líneas', area: 'Área',
    stacked_area: 'Área apilada', pie: 'Pastel', donut: 'Dona',
    treemap: 'Treemap', box_whisker: 'Caja y bigotes', mapa: 'Mapa'
  };
  function nombreTipo(t) { return NOMBRE_TIPO[t] || t; }

  function ejecutar(accion, boton, fig, lienzo, st) {
    switch (accion) {
      case 'detalle': modalDetalle(st); break;
      case 'datos': modalDatos(st); break;
      case 'compartir': compartir(boton, fig); break;
      case 'imagen': exportarPNG(lienzo, st); break;
      case 'descarga': descargarJSON(st); break;
    }
  }

  /* ---------------- Modales ---------------- */

  function modal(titulo, construirCuerpo) {
    var fondo = C.el('div', 'uhp-modal');
    fondo.setAttribute('role', 'dialog');
    fondo.setAttribute('aria-modal', 'true');
    fondo.setAttribute('aria-label', titulo);

    var panel = C.el('div', 'uhp-modal__panel');
    var cab = C.el('div', 'uhp-modal__cab');
    cab.appendChild(C.el('strong', 'uhp-modal__titulo', titulo));

    var cerrar = C.el('button', 'uhp-modal__x', '✕');
    cerrar.type = 'button';
    cerrar.setAttribute('aria-label', 'Cerrar');
    cab.appendChild(cerrar);

    var cuerpo = C.el('div', 'uhp-modal__cuerpo');
    construirCuerpo(cuerpo);

    panel.appendChild(cab);
    panel.appendChild(cuerpo);
    fondo.appendChild(panel);
    document.body.appendChild(fondo);

    var previo = document.activeElement;
    function fuera() {
      if (fondo.parentNode) { fondo.parentNode.removeChild(fondo); }
      document.removeEventListener('keydown', alTeclado);
      if (previo && typeof previo.focus === 'function') { previo.focus(); }
    }
    function alTeclado(e) { if (e.key === 'Escape') { fuera(); } }

    cerrar.addEventListener('click', fuera);
    fondo.addEventListener('click', function (e) { if (e.target === fondo) { fuera(); } });
    document.addEventListener('keydown', alTeclado);
    cerrar.focus();
  }

  function modalDetalle(st) {
    var v = (st.payload && st.payload.view) || {};
    modal('Detalle del gráfico', function (cuerpo) {
      var dl = C.el('dl', 'uhp-modal__dl');
      var filas = [
        ['Vista', v.name || ''],
        ['Identificador', v.id || ''],
        ['Grupo temático', v.grupo || ''],
        ['Tipo de gráfico', nombreTipo(st.type)],
        ['Categoría', v.category || ''],
        ['Dimensiones', (v.dimensions || []).map(window.UHPRenderer.etiqueta).join(', ')],
        ['Medidas', (v.measures || []).map(window.UHPRenderer.etiqueta).join(', ')],
        ['Filas', String((st.payload && st.payload.data || []).length)],
        ['Fuente', v.fuente || '']
      ];
      filas.forEach(function (f) {
        if (!f[1]) { return; }
        dl.appendChild(C.el('dt', '', f[0]));
        dl.appendChild(C.el('dd', '', f[1]));
      });
      cuerpo.appendChild(dl);

      if (v.descripcion_larga) {
        cuerpo.appendChild(C.el('h4', 'uhp-modal__h', 'Qué muestra'));
        cuerpo.appendChild(C.el('p', 'uhp-modal__p', v.descripcion_larga));
      }
      if (v.analisis_largo) {
        cuerpo.appendChild(C.el('h4', 'uhp-modal__h', 'Cómo leerlo'));
        cuerpo.appendChild(C.el('p', 'uhp-modal__p', v.analisis_largo));
      }
    });
  }

  function modalDatos(st) {
    var v = (st.payload && st.payload.view) || {};
    var datos = (st.payload && st.payload.data) || [];
    modal('Datos de la vista', function (cuerpo) {
      if (!datos.length) {
        cuerpo.appendChild(C.el('p', 'uhp-modal__p', 'Esta vista no tiene datos.'));
        return;
      }
      var columnas = Object.keys(datos[0]);
      var envoltura = C.el('div', 'uhp-modal__tabla-caja');
      var tabla = C.el('table', 'uhp-modal__tabla');

      var thead = C.el('thead');
      var trh = C.el('tr');
      columnas.forEach(function (c) {
        var th = C.el('th', '', window.UHPRenderer.etiqueta(c));
        th.scope = 'col';
        trh.appendChild(th);
      });
      thead.appendChild(trh);
      tabla.appendChild(thead);

      var tbody = C.el('tbody');
      datos.forEach(function (fila) {
        var tr = C.el('tr');
        columnas.forEach(function (c) {
          var val = fila[c];
          var txt = (typeof val === 'number') ? C.num(val)
            : (typeof val === 'boolean') ? (val ? '✓' : '—')
              : String(val === null || val === undefined ? '' : val);
          var td = C.el('td', typeof val === 'number' ? 'uhp-num' : '', txt);
          tr.appendChild(td);
        });
        tbody.appendChild(tr);
      });
      tabla.appendChild(tbody);
      envoltura.appendChild(tabla);
      cuerpo.appendChild(envoltura);

      if (v.fuente) {
        cuerpo.appendChild(C.el('p', 'uhp-modal__fuente', 'Fuente: ' + v.fuente));
      }
    });
  }

  /* ---------------- Acciones ---------------- */

  function compartir(boton, fig) {
    var url = window.location.href.split('#')[0] + '#' + (fig.id || '');
    var titulo = fig.getAttribute('data-view') || 'Gráfico';

    function exito() {
      boton.classList.add('is-ok');
      var original = boton.textContent;
      boton.textContent = 'Enlace copiado';
      setTimeout(function () {
        boton.classList.remove('is-ok');
        boton.textContent = original;
      }, 1600);
    }

    if (navigator.share) {
      navigator.share({ title: titulo, url: url }).catch(function () { /* cancelado */ });
      return;
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(exito).catch(function () { /* sin permiso */ });
    }
  }

  function exportarPNG(lienzo, st) {
    var svg = lienzo.querySelector('svg');
    if (!svg) { return; }

    var clon = svg.cloneNode(true);
    var caja = svg.getBoundingClientRect();
    var w = Math.max(320, Math.round(caja.width));
    var h = Math.max(240, Math.round(caja.height));
    clon.setAttribute('width', w);
    clon.setAttribute('height', h);
    clon.setAttribute('xmlns', 'http://www.w3.org/2000/svg');

    var cadena = new XMLSerializer().serializeToString(clon);
    var blob = new Blob([cadena], { type: 'image/svg+xml;charset=utf-8' });
    var url = URL.createObjectURL(blob);
    var img = new Image();

    img.onload = function () {
      var escala = 2; // 2× para pantallas de alta densidad.
      var canvas = document.createElement('canvas');
      canvas.width = w * escala;
      canvas.height = h * escala;
      var ctx = canvas.getContext('2d');
      ctx.fillStyle = '#ffffff';
      ctx.fillRect(0, 0, canvas.width, canvas.height);
      ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
      URL.revokeObjectURL(url);
      canvas.toBlob(function (png) {
        if (png) { descargar(png, (st.view || 'grafico') + '-' + st.type + '.png'); }
      }, 'image/png');
    };
    img.onerror = function () {
      // Si el navegador no puede rasterizar el SVG, se entrega el vectorial.
      URL.revokeObjectURL(url);
      descargar(blob, (st.view || 'grafico') + '.svg');
    };
    img.src = url;
  }

  function descargarJSON(st) {
    var v = (st.payload && st.payload.view) || {};
    var carga = {
      vista: {
        id: v.id,
        nombre: v.name,
        descripcion: v.description,
        categoria: v.category,
        dimensiones: v.dimensions,
        medidas: v.measures,
        fuente: v.fuente
      },
      datos: (st.payload && st.payload.data) || [],
      proyecto: 'URKUNINA 5000 · BPIN 2015000100064',
      entidad: 'Gobernación de Nariño — Secretaría TIC, Innovación y Gobierno Abierto'
    };
    var blob = new Blob([JSON.stringify(carga, null, 2)], { type: 'application/json;charset=utf-8' });
    descargar(blob, (v.id || 'vista') + '.json');
  }

  function descargar(blob, nombre) {
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = nombre;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    setTimeout(function () { URL.revokeObjectURL(url); }, 2000);
  }

})();
