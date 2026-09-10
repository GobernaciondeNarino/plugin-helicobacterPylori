/* [urkunina_dashboard] — tablero completo del proyecto URKUNINA 5000.

   Un solo contenedor a 100 % de ancho y 100vh de alto con:
     · cintillo de indicadores clave,
     · panel de controles y filtros a la izquierda,
     · mapa de OpenStreetMap al centro,
     · panel de gráficos D3plus a la derecha,
     · ficha del municipio seleccionado.

   Toda la carga inicial cabe en dos peticiones (/dashboard y /geo) y el
   cambio de indicador solo repide la tabla de valores, nunca la geometría. */
(function () {
  'use strict';

  var C = window.UHPcore;

  var GRAFICOS_PANEL = [
    { view: 'prev_subregion', type: 'bar' },
    { view: 'tamizaje_comparado', type: 'stacked_bar' },
    { view: 'zonas_riesgo', type: 'bar' },
    { view: 'perfil_etnia', type: 'donut' }
  ];

  var reducirMovimiento = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  C.ready(function () {
    Array.prototype.forEach.call(document.querySelectorAll('[data-uhp-dashboard]'), iniciar);
  });

  function iniciar(raiz) {
    var st = {
      indicador: raiz.getAttribute('data-indicador') || 'lpm',
      teselas: raiz.getAttribute('data-teselas') || 'osm',
      lat: raiz.getAttribute('data-lat'),
      lon: raiz.getAttribute('data-lon'),
      zoom: raiz.getAttribute('data-zoom'),
      grafico: GRAFICOS_PANEL[0].view,
      tipo: GRAFICOS_PANEL[0].type,
      mapa: null,
      datos: null
    };

    var nodos = {
      kpi: raiz.querySelector('[data-uhp-zona="kpi"]'),
      mapa: raiz.querySelector('[data-uhp-zona="mapa"]'),
      controles: raiz.querySelector('[data-uhp-zona="controles"]'),
      grafico: raiz.querySelector('[data-uhp-zona="grafico"]'),
      graficoTitulo: raiz.querySelector('[data-uhp-zona="grafico-titulo"]'),
      analisis: raiz.querySelector('[data-uhp-zona="analisis"]'),
      ficha: raiz.querySelector('[data-uhp-zona="ficha"]'),
      estado: raiz.querySelector('[data-uhp-zona="estado"]')
    };

    C.rest('/dashboard')
      .then(function (d) {
        st.datos = d;
        pintarKpi(nodos.kpi, d.kpi);
        pintarCabecera(raiz, d.proyecto);
        montarControles(nodos.controles, d, st, nodos);
        montarMapa(nodos, st);
        cargarGrafico(nodos, st);
        cablearPaneles(raiz, st);
      })
      .catch(function () {
        C.error(raiz, 'No se pudo cargar el tablero. Compruebe que el plugin tiene acceso a los archivos de /data.', function () {
          iniciar(raiz);
        });
      });
  }

  /* ------------------------------------------------------------------ */
  /* Cintillo de indicadores                                            */
  /* ------------------------------------------------------------------ */

  function pintarKpi(caja, kpis) {
    if (!caja) { return; }
    caja.innerHTML = '';
    (kpis || []).forEach(function (k) {
      var t = C.el('div', 'uhp-db__kpi');
      t.appendChild(C.el('b', 'uhp-db__kpi-val', C.formato(k.valor, k.formato)));
      t.appendChild(C.el('span', 'uhp-db__kpi-etq', k.etiqueta));
      if (k.nota) {
        var n = C.el('span', 'uhp-db__kpi-nota', k.nota);
        t.appendChild(n);
      }
      caja.appendChild(t);
    });
  }

  function pintarCabecera(raiz, proyecto) {
    if (!proyecto) { return; }
    var sub = raiz.querySelector('[data-uhp-zona="subtitulo"]');
    if (sub && proyecto.bpin) {
      sub.textContent = 'BPIN ' + proyecto.bpin +
        (proyecto.estado ? ' · ' + proyecto.estado : '');
    }
  }

  /* ------------------------------------------------------------------ */
  /* Controles y filtros                                                */
  /* ------------------------------------------------------------------ */

  function montarControles(caja, datos, st, nodos) {
    if (!caja) { return; }
    caja.innerHTML = '';

    /* --- Indicador del mapa --- */
    var grIndicador = grupo('Indicador del mapa',
      'Determina el color de cada municipio. Los indicadores de prevalencia solo se publican para los diez municipios con mayor valor.');
    var selInd = C.el('select', 'uhp-db__select');
    selInd.id = uid('ind');
    Object.keys(datos.indicadores || {}).forEach(function (k) {
      var o = C.el('option', '', datos.indicadores[k].etiqueta);
      o.value = k;
      if (k === st.indicador) { o.selected = true; }
      selInd.appendChild(o);
    });
    selInd.addEventListener('change', function () {
      st.indicador = selInd.value;
      if (st.mapa) {
        st.mapa.cambiarIndicador(st.indicador).then(function (r) {
          notaIndicador(grIndicador, r.meta);
          anunciar(nodos.estado, 'Mapa actualizado: ' + (r.meta ? r.meta.etiqueta : st.indicador));
        }).catch(function () {
          anunciar(nodos.estado, 'No se pudo cambiar el indicador del mapa.');
        });
      }
    });
    etiquetarPara(grIndicador, selInd, 'Indicador');
    grIndicador.appendChild(selInd);
    grIndicador.appendChild(C.el('p', 'uhp-db__nota', (datos.indicadores[st.indicador] || {}).nota || ''));
    caja.appendChild(grIndicador);

    /* --- Capa base --- */
    var grBase = grupo('Capa base del mapa', 'Cartografía de OpenStreetMap.');
    var selBase = C.el('select', 'uhp-db__select');
    selBase.id = uid('base');
    [
      ['osm', 'OpenStreetMap estándar'],
      ['claro', 'Tono claro (mejor contraste)'],
      ['humanitario', 'Humanitarian OSM']
    ].forEach(function (par) {
      var o = C.el('option', '', par[1]);
      o.value = par[0];
      if (par[0] === st.teselas) { o.selected = true; }
      selBase.appendChild(o);
    });
    selBase.addEventListener('change', function () {
      st.teselas = selBase.value;
      montarMapa(nodos, st);
    });
    etiquetarPara(grBase, selBase, 'Capa base');
    grBase.appendChild(selBase);
    caja.appendChild(grBase);

    /* --- Gráfico del panel derecho --- */
    var grGraf = grupo('Gráfico del panel', 'Elige qué vista se dibuja a la derecha.');
    var selGraf = C.el('select', 'uhp-db__select');
    selGraf.id = uid('graf');
    C.rest('/vistas').then(function (v) {
      (v.vistas || []).forEach(function (vista) {
        var o = C.el('option', '', vista.grupo + ' · ' + vista.name);
        o.value = vista.id;
        if (vista.id === st.grafico) { o.selected = true; }
        selGraf.appendChild(o);
      });
    }).catch(function () {
      GRAFICOS_PANEL.forEach(function (g) {
        var o = C.el('option', '', g.view);
        o.value = g.view;
        selGraf.appendChild(o);
      });
    });
    selGraf.addEventListener('change', function () {
      st.grafico = selGraf.value;
      st.tipo = '';
      cargarGrafico(nodos, st);
    });
    etiquetarPara(grGraf, selGraf, 'Vista');
    grGraf.appendChild(selGraf);
    caja.appendChild(grGraf);

    /* --- Accesos rápidos --- */
    var grRapido = grupo('Accesos rápidos', 'Vistas destacadas del proyecto.');
    var lista = C.el('div', 'uhp-db__chips');
    GRAFICOS_PANEL.forEach(function (g) {
      var b = C.el('button', 'uhp-db__chip', nombreCorto(g.view));
      b.type = 'button';
      b.addEventListener('click', function () {
        st.grafico = g.view;
        st.tipo = g.type;
        selGraf.value = g.view;
        cargarGrafico(nodos, st);
      });
      lista.appendChild(b);
    });
    grRapido.appendChild(lista);
    caja.appendChild(grRapido);

    /* --- Contexto de las zonas de riesgo --- */
    if (datos.zonas && datos.zonas.data) {
      var grZonas = grupo('Zonas de riesgo', 'Incidencia de cáncer gástrico por 100.000 habitantes.');
      datos.zonas.data.forEach(function (z) {
        var f = C.el('div', 'uhp-db__zona');
        f.appendChild(C.el('span', 'uhp-db__zona-pt uhp-db__zona-pt--' + claseZona(z.zona)));
        f.appendChild(C.el('span', 'uhp-db__zona-etq', z.zona));
        f.appendChild(C.el('b', 'uhp-db__zona-val', C.num(z.incidencia)));
        grZonas.appendChild(f);
      });
      caja.appendChild(grZonas);
    }
  }

  function claseZona(nombre) {
    var n = String(nombre || '').toLowerCase();
    if (n.indexOf('roja') >= 0) { return 'roja'; }
    if (n.indexOf('amarilla') >= 0) { return 'amarilla'; }
    return 'verde';
  }

  var NOMBRE_CORTO = {
    prev_subregion: 'Subregiones',
    tamizaje_comparado: 'Tamizaje',
    zonas_riesgo: 'Zonas de riesgo',
    perfil_etnia: 'Etnia'
  };
  function nombreCorto(id) { return NOMBRE_CORTO[id] || id; }

  function grupo(titulo, ayuda) {
    var g = C.el('div', 'uhp-db__grupo');
    g.appendChild(C.el('h3', 'uhp-db__grupo-t', titulo));
    if (ayuda) { g.appendChild(C.el('p', 'uhp-db__grupo-ayuda', ayuda)); }
    return g;
  }

  function etiquetarPara(contenedor, control, texto) {
    var l = C.el('label', 'uhp-db__label', texto);
    l.setAttribute('for', control.id);
    contenedor.appendChild(l);
  }

  function notaIndicador(grupoNodo, meta) {
    var p = grupoNodo.querySelector('.uhp-db__nota');
    if (p && meta) { p.textContent = meta.nota || ''; }
  }

  var contador = 0;
  function uid(p) { contador++; return 'uhp-db-' + p + '-' + contador; }

  /* ------------------------------------------------------------------ */
  /* Mapa                                                               */
  /* ------------------------------------------------------------------ */

  function montarMapa(nodos, st) {
    if (!nodos.mapa) { return; }

    // Cambiar de capa base implica reconstruir: Leaflet no admite dos
    // instancias sobre el mismo nodo, así que se limpia antes.
    if (st.mapa) {
      st.mapa.mapa.remove();
      st.mapa = null;
    }
    nodos.mapa.innerHTML = '';

    var lienzo = C.el('div', 'uhp-db__mapa-lienzo');
    nodos.mapa.appendChild(lienzo);
    nodos.mapa.appendChild(C.skeleton('Cargando el mapa de Nariño…'));

    var ctrl = window.UHPMapa && window.UHPMapa.crear(lienzo, {
      lat: st.lat,
      lon: st.lon,
      zoom: st.zoom,
      teselas: st.teselas,
      indicador: st.indicador,
      alSeleccionar: function (divipola, nombre, valor) {
        pintarFicha(nodos, st, divipola, nombre, valor);
      }
    });

    if (!ctrl) {
      C.quitarSkeleton(nodos.mapa);
      C.error(nodos.mapa, 'No se pudo iniciar el mapa: la librería Leaflet no está disponible.');
      return;
    }

    st.mapa = ctrl;
    ctrl.iniciar().then(function () {
      C.quitarSkeleton(nodos.mapa);
    }).catch(function () {
      C.quitarSkeleton(nodos.mapa);
      C.error(nodos.mapa, 'No se pudieron cargar los datos del mapa.', function () {
        montarMapa(nodos, st);
      });
    });
  }

  /* ------------------------------------------------------------------ */
  /* Panel de gráficos                                                  */
  /* ------------------------------------------------------------------ */

  function cargarGrafico(nodos, st) {
    if (!nodos.grafico) { return; }
    nodos.grafico.innerHTML = '';
    nodos.grafico.appendChild(C.skeleton('Cargando el gráfico…'));

    C.rest('/render', { view: st.grafico, type: st.tipo })
      .then(function (p) {
        nodos.grafico.innerHTML = '';
        if (nodos.graficoTitulo) {
          nodos.graficoTitulo.textContent = (p.view && p.view.name) || '';
        }
        nodos.grafico.setAttribute('role', 'img');
        nodos.grafico.setAttribute('aria-label',
          ((p.view && p.view.name) || '') + '. ' +
          ((p.view && p.view.analisis && p.view.analisis.cuantitativo) || ''));

        window.UHPRenderer.render(nodos.grafico, p, {
          legendPos: 'bottom',
          reducirMovimiento: reducirMovimiento
        });

        if (nodos.analisis) {
          nodos.analisis.innerHTML = '';
          var a = (p.view && p.view.analisis) || {};
          if (a.descriptivo) { nodos.analisis.appendChild(C.el('p', 'uhp-db__txt', a.descriptivo)); }
          if (a.cuantitativo) { nodos.analisis.appendChild(C.el('p', 'uhp-db__txt uhp-db__txt--num', a.cuantitativo)); }
          if (p.view && p.view.fuente) {
            nodos.analisis.appendChild(C.el('p', 'uhp-db__fuente', 'Fuente: ' + p.view.fuente));
          }
        }
      })
      .catch(function () {
        C.error(nodos.grafico, 'No se pudo cargar el gráfico.', function () {
          cargarGrafico(nodos, st);
        });
      });
  }

  /* ------------------------------------------------------------------ */
  /* Ficha del municipio                                                */
  /* ------------------------------------------------------------------ */

  function pintarFicha(nodos, st, divipola, nombre, valor) {
    if (!nodos.ficha) { return; }
    nodos.ficha.innerHTML = '';
    nodos.ficha.classList.add('is-activa');

    var cab = C.el('div', 'uhp-db__ficha-cab');
    cab.appendChild(C.el('h3', 'uhp-db__ficha-t', nombre || 'Municipio'));
    var cerrar = C.el('button', 'uhp-db__ficha-x', '✕');
    cerrar.type = 'button';
    cerrar.setAttribute('aria-label', 'Cerrar la ficha del municipio');
    cerrar.addEventListener('click', function () {
      nodos.ficha.classList.remove('is-activa');
      nodos.ficha.innerHTML = '';
    });
    cab.appendChild(cerrar);
    nodos.ficha.appendChild(cab);

    var meta = (st.datos && st.datos.indicadores && st.datos.indicadores[st.indicador]) || null;
    var cuerpo = C.el('div', 'uhp-db__ficha-cuerpo');

    if (valor && meta) {
      var d = C.el('div', 'uhp-db__ficha-dato');
      d.appendChild(C.el('b', '', C.num(valor.valor) + (meta.unidad === '%' ? ' %' : ' ' + meta.unidad)));
      d.appendChild(C.el('span', '', meta.etiqueta));
      cuerpo.appendChild(d);
    } else {
      cuerpo.appendChild(C.el('p', 'uhp-db__txt',
        'Este municipio no tiene valor publicado para el indicador seleccionado. Los informes del proyecto solo difunden los diez municipios con mayor prevalencia en cada indicador.'));
    }

    cuerpo.appendChild(C.el('p', 'uhp-db__ficha-cod', 'Código DIVIPOLA: ' + divipola));
    nodos.ficha.appendChild(cuerpo);
    anunciar(nodos.estado, 'Municipio seleccionado: ' + (nombre || divipola));
  }

  /* ------------------------------------------------------------------ */
  /* Utilidades                                                         */
  /* ------------------------------------------------------------------ */

  /** Colapsar y expandir los paneles laterales. */
  function cablearPaneles(raiz, st) {
    Array.prototype.forEach.call(raiz.querySelectorAll('[data-uhp-toggle]'), function (b) {
      b.addEventListener('click', function () {
        var destino = b.getAttribute('data-uhp-toggle');
        var panel = raiz.querySelector('[data-uhp-panel="' + destino + '"]');
        if (!panel) { return; }
        var plegado = panel.classList.toggle('is-plegado');
        b.setAttribute('aria-expanded', plegado ? 'false' : 'true');
        // El mapa ocupa el espacio restante: hay que remedirlo.
        setTimeout(function () { if (st.mapa) { st.mapa.redimensionar(); } }, 260);
      });
    });
  }

  /** Mensaje para lectores de pantalla. */
  function anunciar(nodo, texto) {
    if (nodo) { nodo.textContent = texto; }
  }
})();
