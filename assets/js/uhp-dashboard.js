/* [urkunina_dashboard] — tablero completo del proyecto URKUNINA 5000.

   Un solo contenedor a 100 % de ancho y 100vh de alto con:
     · cintillo de indicadores clave,
     · panel de controles y filtros a la izquierda,
     · mapa de OpenStreetMap al centro,
     · panel de gráficos D3plus a la derecha,
     · ficha del territorio seleccionado.

   TODO GIRA ALREDEDOR DE UNA SELECCIÓN TERRITORIAL. El tablero mantiene un
   único territorio seleccionado —el departamento entero, una de sus 13
   subregiones o uno de sus 64 municipios— y cada pieza se recoloca a su
   alrededor: el cintillo pasa a las cifras de ese territorio, el mapa lo
   resalta, el gráfico atenúa lo demás y la ficha lo describe. Se selecciona
   pulsando en el mapa, en una barra del gráfico, en el selector de los
   controles o navegando por la ficha hacia su subregión o sus municipios.

   Y LO QUE NO SE PUEDE FILTRAR SE DICE. De las 24 vistas del catálogo, 4
   nombran municipios y 3 subregiones: las otras 17 solo existen para el
   conjunto del departamento. De los 6 indicadores del cintillo, 2 no están
   desagregados en ninguna fuente. Cuando una pieza no puede responder por
   el territorio elegido, lo anuncia en vez de enseñar la cifra
   departamental como si fuera local, que sería afirmar algo que el proyecto
   no ha medido. El estado de cada cifra —publicada, sin publicar, agregada
   o departamental— lo decide UHP_Territorios en el servidor y viaja con
   ella hasta aquí.

   Toda la carga inicial cabe en tres peticiones (/dashboard, /geo y /mapa)
   y el cambio de indicador solo repide la tabla de valores, nunca la
   geometría. */
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
    var tema = raiz.getAttribute('data-tema') === 'claro' ? 'claro' : 'oscuro';

    var st = {
      tema: tema,
      indicador: raiz.getAttribute('data-indicador') || 'lpm',
      // La capa base por defecto sigue al tema: el servidor ya la resuelve,
      // pero si el atributo llegara vacío se decide aquí igual.
      teselas: raiz.getAttribute('data-teselas') || tema,
      // Capa territorial que dibuja el mapa.
      nivel: raiz.getAttribute('data-nivel') || 'municipio',
      lat: raiz.getAttribute('data-lat'),
      lon: raiz.getAttribute('data-lon'),
      zoom: raiz.getAttribute('data-zoom'),
      grafico: GRAFICOS_PANEL[0].view,
      tipo: GRAFICOS_PANEL[0].type,
      // Nivel territorial de cada vista del catálogo, tal como lo declara
      // el servidor. Se rellena al poblar el selector de gráficos.
      nivelDeVista: {},
      mapa: null,
      datos: null,
      // El departamento es la selección de partida: sin filtro, pero es un
      // territorio como los demás y no un caso especial.
      seleccion: { nivel: 'departamento', id: '52', nombre: 'Nariño', ficha: null }
    };

    var nodos = {
      kpi: raiz.querySelector('[data-uhp-zona="kpi"]'),
      mapa: raiz.querySelector('[data-uhp-zona="mapa"]'),
      controles: raiz.querySelector('[data-uhp-zona="controles"]'),
      grafico: raiz.querySelector('[data-uhp-zona="grafico"]'),
      graficoTitulo: raiz.querySelector('[data-uhp-zona="grafico-titulo"]'),
      analisis: raiz.querySelector('[data-uhp-zona="analisis"]'),
      ficha: raiz.querySelector('[data-uhp-zona="ficha"]'),
      contexto: raiz.querySelector('[data-uhp-zona="contexto"]'),
      estado: raiz.querySelector('[data-uhp-zona="estado"]')
    };

    C.rest('/dashboard')
      .then(function (d) {
        st.datos = d;
        pintarKpi(nodos, st);
        pintarCabecera(raiz, d.proyecto);
        pintarContexto(nodos, st);
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

  /* Cómo se anuncia cada estado de una cifra. El texto corto va en la
     tarjeta y el largo en su título, para que se lea al pasar por encima y
     lo recoja un lector de pantalla. */
  var ESTADOS = {
    publicado: { clase: '', marca: '' },
    agregado: {
      clase: 'is-agregado',
      marca: 'suma',
      titulo: 'Cifra sumada a partir de sus municipios, no publicada para este territorio.'
    },
    sin_dato: {
      clase: 'is-sin-dato',
      marca: 'sin dato',
      titulo: 'El proyecto no publica esta cifra para este territorio.'
    },
    departamental: {
      clase: 'is-departamental',
      marca: 'departamental',
      titulo: 'Cifra del departamento: el proyecto no la desagrega por territorio.'
    }
  };

  /* Qué indicador del mapa corresponde a cada cifra del cintillo. Las que
     no aparecen aquí no tienen mapa, y su tarjeta no es pulsable. */
  var KPI_A_INDICADOR = {
    hpylori: 'hpylori',
    lpm: 'lpm',
    cancer: 'cancer',
    municipios: 'intervencion'
  };

  function pintarKpi(nodos, st) {
    var caja = nodos.kpi;
    if (!caja) { return; }
    caja.innerHTML = '';

    // Con un territorio seleccionado manda su ficha; sin ella, las cifras
    // del departamento que trajo /dashboard.
    var ficha = st.seleccion && st.seleccion.ficha;
    var lista = ficha ? ficha.indicadores : ((st.datos && st.datos.kpi) || []);

    lista.forEach(function (k) {
      var estado = ESTADOS[k.estado] || ESTADOS.publicado;
      var indicador = KPI_A_INDICADOR[k.clave] || '';

      // Pulsable solo si lleva a algún sitio: cambiar el indicador del mapa.
      var t = C.el(indicador ? 'button' : 'div', 'uhp-db__kpi ' + estado.clase);
      if (indicador) {
        t.type = 'button';
        t.setAttribute('data-uhp-kpi', k.clave);
        if (st.indicador === indicador) { t.classList.add('is-activo'); }
        t.addEventListener('click', function () { cambiarIndicador(nodos, st, indicador); });
      }
      if (estado.titulo) { t.title = estado.titulo; }

      var valor = (null === k.valor || undefined === k.valor)
        ? '—'
        : C.formato(k.valor, k.formato);
      t.appendChild(C.el('b', 'uhp-db__kpi-val', valor));

      var etq = C.el('span', 'uhp-db__kpi-etq', k.etiqueta);
      if (estado.marca) {
        etq.appendChild(C.el('i', 'uhp-db__kpi-marca', estado.marca));
      }
      t.appendChild(etq);

      if (k.nota) { t.appendChild(C.el('span', 'uhp-db__kpi-nota', k.nota)); }
      caja.appendChild(t);
    });
  }

  /* ------------------------------------------------------------------ */
  /* Barra de contexto: qué territorio manda ahora mismo                */
  /* ------------------------------------------------------------------ */

  var NOMBRE_NIVEL = {
    departamento: 'Departamento',
    subregion: 'Subregión',
    municipio: 'Municipio'
  };

  function pintarContexto(nodos, st) {
    var caja = nodos.contexto;
    if (!caja) { return; }
    caja.innerHTML = '';

    var sel = st.seleccion;
    var enDepartamento = 'departamento' === sel.nivel;

    caja.appendChild(C.el('span', 'uhp-db__ctx-etq', 'Viendo'));

    // Miga de pan: departamento › subregión › municipio. Cada eslabón sube
    // un nivel, que es la forma natural de deshacer un filtro.
    var ficha = sel.ficha;
    var padres = [];
    if (ficha && ficha.padre) { padres.push(ficha.padre); }
    if (!enDepartamento && (!ficha || !ficha.padre || 'departamento' !== ficha.padre.nivel)) {
      padres.unshift({ nivel: 'departamento', id: '52', nombre: 'Nariño' });
    }

    padres.forEach(function (p) {
      var b = C.el('button', 'uhp-db__ctx-salto', p.nombre);
      b.type = 'button';
      b.title = 'Volver a ' + (NOMBRE_NIVEL[p.nivel] || '').toLowerCase() + ' ' + p.nombre;
      b.addEventListener('click', function () {
        seleccionarTerritorio(nodos, st, p.nivel, p.id, p.nombre);
      });
      caja.appendChild(b);
      caja.appendChild(C.el('span', 'uhp-db__ctx-sep', '›'));
    });

    var actual = C.el('b', 'uhp-db__ctx-actual', sel.nombre || 'Nariño');
    actual.setAttribute('data-uhp-ctx-nivel', sel.nivel);
    caja.appendChild(actual);
    caja.appendChild(C.el('span', 'uhp-db__ctx-nivel', NOMBRE_NIVEL[sel.nivel] || ''));

    if (!enDepartamento) {
      var limpiar = C.el('button', 'uhp-db__ctx-limpiar', 'Ver todo el departamento');
      limpiar.type = 'button';
      limpiar.setAttribute('data-uhp-limpiar', '');
      limpiar.addEventListener('click', function () {
        seleccionarTerritorio(nodos, st, 'departamento', '52', 'Nariño');
      });
      caja.appendChild(limpiar);
    }
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
  /* Selección territorial: el eje del que cuelga todo lo demás         */
  /* ------------------------------------------------------------------ */

  /**
   * Selecciona un territorio y recoloca el tablero a su alrededor.
   *
   * Una sola petición —la ficha— y a partir de ella se repintan el
   * cintillo, la barra de contexto, la ficha lateral y el gráfico. El mapa
   * no se recarga: solo resalta, porque su geometría no depende de la
   * selección sino de la capa.
   *
   * @param {Object} nodos  Nodos del tablero.
   * @param {Object} st     Estado.
   * @param {string} nivel  'departamento' | 'subregion' | 'municipio'.
   * @param {string} id     Identificador del territorio.
   * @param {string} nombre Nombre, para poder pintarlo antes de la respuesta.
   */
  function seleccionarTerritorio(nodos, st, nivel, id, nombre) {
    st.seleccion = { nivel: nivel, id: id, nombre: nombre || '', ficha: null };

    // Lo que no depende de la respuesta se pinta ya: el tablero responde al
    // instante y la ficha llega después.
    pintarContexto(nodos, st);
    resaltarEnMapa(st);
    anunciar(nodos.estado, (NOMBRE_NIVEL[nivel] || 'Territorio') + ' seleccionado: ' + (nombre || id));

    C.rest('/territorio', { nivel: nivel, id: id })
      .then(function (f) {
        // Una respuesta que llega tarde no debe pisar una selección más
        // reciente: sin esta comprobación, pulsar rápido dos municipios
        // deja el tablero mostrando el primero.
        if (st.seleccion.nivel !== nivel || st.seleccion.id !== id) { return; }

        st.seleccion.ficha = f;
        st.seleccion.nombre = f.nombre || nombre;
        pintarContexto(nodos, st);
        pintarKpi(nodos, st);
        pintarFicha(nodos, st);
        cargarGrafico(nodos, st);
      })
      .catch(function () {
        // Sin ficha el tablero sigue siendo usable: se vuelve a las cifras
        // del departamento y se dice por qué.
        if (st.seleccion.nivel !== nivel || st.seleccion.id !== id) { return; }
        pintarKpi(nodos, st);
        if (nodos.ficha) {
          nodos.ficha.classList.add('is-activa');
          nodos.ficha.innerHTML = '';
          C.error(nodos.ficha, 'No se pudo cargar la ficha de ' + (nombre || id) + '.', function () {
            seleccionarTerritorio(nodos, st, nivel, id, nombre);
          });
        }
      });
  }

  /** Lleva la selección al mapa, si la capa activa puede representarla. */
  function resaltarEnMapa(st) {
    if (!st.mapa) { return; }
    // Un municipio no se puede resaltar en la capa de subregiones: en ese
    // caso se resalta su subregión, que sí está dibujada.
    if (st.seleccion.nivel === st.nivel) {
      st.mapa.seleccionar(st.seleccion.id);
      return;
    }
    var ficha = st.seleccion.ficha;
    if (ficha && ficha.padre && ficha.padre.nivel === st.nivel) {
      st.mapa.seleccionar(ficha.padre.id);
      return;
    }
    st.mapa.seleccionar('');
  }

  /** Cambia el indicador del mapa desde donde sea (cintillo o selector). */
  function cambiarIndicador(nodos, st, indicador) {
    st.indicador = indicador;
    var sel = nodos.controles && nodos.controles.querySelector('[data-uhp-indicador]');
    if (sel && sel.value !== indicador) { sel.value = indicador; }
    pintarKpi(nodos, st);
    if (st.mapa) {
      st.mapa.cambiarIndicador(indicador).then(function () {
        resaltarEnMapa(st);
      }).catch(function () { /* se conserva el anterior */ });
    }
  }

  /**
   * Lleva el mapa a la capa que corresponde a la vista elegida.
   *
   * Si el gráfico pasa a hablar de subregiones, el mapa se dibuja por
   * subregiones; si habla de municipios, por municipios. Es lo que hace que
   * las dos mitades del tablero cuenten lo mismo sin que nadie tenga que
   * acordarse de cambiar la capa a mano.
   *
   * Las vistas departamentales no mueven nada: no tienen un nivel al que
   * llevar el mapa.
   *
   * @param {Object}            nodos   Nodos del tablero.
   * @param {Object}            st      Estado.
   * @param {HTMLSelectElement} selCapa Selector de capa, para reflejarlo.
   */
  function sincronizarCapa(nodos, st, selCapa) {
    var nivel = st.nivelDeVista[st.grafico];
    if (!nivel || nivel === st.nivel) { return; }
    if (selCapa) { selCapa.value = nivel; }
    cambiarNivel(nodos, st, nivel);
  }

  /** Cambia la capa territorial del mapa. */
  function cambiarNivel(nodos, st, nivel) {
    st.nivel = nivel;
    if (!st.mapa) { return; }
    st.mapa.cambiarNivel(nivel).then(function () {
      resaltarEnMapa(st);
    }).catch(function () {
      C.error(nodos.mapa, 'No se pudo cambiar la capa del mapa.', function () {
        cambiarNivel(nodos, st, nivel);
      });
    });
  }

  /* ------------------------------------------------------------------ */
  /* Controles y filtros                                                */
  /* ------------------------------------------------------------------ */

  function montarControles(caja, datos, st, nodos) {
    if (!caja) { return; }
    caja.innerHTML = '';

    /* --- Capa territorial y territorio --- */
    var grTerr = grupo(
      'Territorio',
      'Elige con qué división se dibuja el mapa y qué territorio manda en todo el tablero.'
    );

    var selCapa = C.el('select', 'uhp-db__select');
    selCapa.id = uid('capa');
    selCapa.setAttribute('data-uhp-capa', '');
    [
      ['municipio', 'Municipios (64)'],
      ['subregion', 'Subregiones (13)'],
      ['departamento', 'Departamento']
    ].forEach(function (par) {
      var o = C.el('option', '', par[1]);
      o.value = par[0];
      if (par[0] === st.nivel) { o.selected = true; }
      selCapa.appendChild(o);
    });
    selCapa.addEventListener('change', function () {
      cambiarNivel(nodos, st, selCapa.value);
      poblarTerritorios(selTerr, st);
    });
    etiquetarPara(grTerr, selCapa, 'Capa del mapa');
    grTerr.appendChild(selCapa);

    var selTerr = C.el('select', 'uhp-db__select');
    selTerr.id = uid('terr');
    selTerr.setAttribute('data-uhp-territorio', '');
    selTerr.addEventListener('change', function () {
      var op = selTerr.options[selTerr.selectedIndex];
      if (!selTerr.value) {
        seleccionarTerritorio(nodos, st, 'departamento', '52', 'Nariño');
        return;
      }
      seleccionarTerritorio(nodos, st, st.nivel, selTerr.value, op ? op.textContent : '');
    });
    etiquetarPara(grTerr, selTerr, 'Territorio seleccionado');
    grTerr.appendChild(selTerr);
    poblarTerritorios(selTerr, st);

    grTerr.appendChild(C.el('p', 'uhp-db__nota',
      'Al seleccionar un territorio, el cintillo, el gráfico y la ficha pasan a hablar de él. Lo que el proyecto no publica por territorio queda marcado como departamental.'));
    caja.appendChild(grTerr);

    /* --- Indicador del mapa --- */
    var grIndicador = grupo('Indicador del mapa',
      'Determina el color de cada municipio. Los indicadores de prevalencia solo se publican para los diez municipios con mayor valor.');
    var selInd = C.el('select', 'uhp-db__select');
    selInd.id = uid('ind');
    selInd.setAttribute('data-uhp-indicador', '');
    Object.keys(datos.indicadores || {}).forEach(function (k) {
      var o = C.el('option', '', datos.indicadores[k].etiqueta);
      o.value = k;
      if (k === st.indicador) { o.selected = true; }
      selInd.appendChild(o);
    });
    selInd.addEventListener('change', function () {
      // Mismo camino que al pulsar una tarjeta del cintillo: si cada uno
      // hiciera lo suyo, uno de los dos acabaría olvidándose de repintar
      // algo.
      cambiarIndicador(nodos, st, selInd.value);
      if (st.mapa) {
        notaIndicador(grIndicador, st.mapa.estado.meta);
        anunciar(nodos.estado, 'Mapa actualizado: ' + (st.mapa.estado.meta ? st.mapa.estado.meta.etiqueta : st.indicador));
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
      ['oscuro', 'Tono oscuro' + (st.tema === 'oscuro' ? ' (la del tema)' : '')],
      ['claro', 'Tono claro' + (st.tema === 'claro' ? ' (la del tema)' : '')],
      ['osm', 'OpenStreetMap estándar'],
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
        // Nivel territorial de cada vista, para poder llevar el mapa a la
        // capa que le corresponde al cambiar de gráfico.
        if (vista.geo && vista.geo.nivel) { st.nivelDeVista[vista.id] = vista.geo.nivel; }
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
      sincronizarCapa(nodos, st, selCapa);
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
        sincronizarCapa(nodos, st, selCapa);
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

  /**
   * Rellena el selector de territorios con los de la capa activa.
   *
   * La lista sale de /territorio del departamento —sus hijos son las
   * subregiones— o de la ficha de cada subregión. Para los 64 municipios se
   * pide una sola vez y se memoriza.
   *
   * @param {HTMLSelectElement} sel Selector.
   * @param {Object}            st  Estado.
   */
  function poblarTerritorios(sel, st) {
    sel.innerHTML = '';
    var vacia = C.el('option', '', 'Todo el departamento');
    vacia.value = '';
    sel.appendChild(vacia);

    if ('departamento' === st.nivel) {
      sel.disabled = true;
      return;
    }
    sel.disabled = false;

    C.restCache('/geo', { nivel: st.nivel }).then(function (geo) {
      var filas = (geo.features || []).map(function (f) {
        return { id: f.properties.id, nombre: f.properties.nombre };
      });
      filas.sort(function (a, b) { return String(a.nombre).localeCompare(String(b.nombre), 'es'); });
      filas.forEach(function (t) {
        var o = C.el('option', '', t.nombre);
        o.value = t.id;
        if (st.seleccion && st.seleccion.id === t.id) { o.selected = true; }
        sel.appendChild(o);
      });
    }).catch(function () {
      // Sin lista, el selector queda con la opción de departamento: el
      // mapa sigue siendo la vía principal para seleccionar.
      sel.disabled = true;
    });
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

    // Los dos motivos posibles se distinguen: el mensaje que decía siempre
    // «Leaflet no está disponible» apuntaba al culpable equivocado cuando
    // lo que faltaba era el módulo de mapa del propio plugin.
    if (!window.UHPMapa) {
      C.quitarSkeleton(nodos.mapa);
      C.error(nodos.mapa, 'No se pudo iniciar el mapa: falta el módulo uhp-mapa.js del plugin.');
      return;
    }
    if (typeof window.L === 'undefined') {
      C.quitarSkeleton(nodos.mapa);
      C.error(nodos.mapa, 'No se pudo iniciar el mapa: la librería Leaflet no está disponible.');
      return;
    }

    var ctrl = window.UHPMapa.crear(lienzo, {
      lat: st.lat,
      lon: st.lon,
      zoom: st.zoom,
      teselas: st.teselas,
      // El mapa se tiñe con el tema del tablero: el borde de los
      // municipios, el relleno de «sin dato» y el resalte cambian con él.
      tema: st.tema,
      indicador: st.indicador,
      nivel: st.nivel,
      // Contorno del departamento por debajo: da marco a las subregiones y
      // sitúa los municipios sin dato.
      contorno: true,
      alSeleccionar: function (id, nombre, valor, nivel) {
        seleccionarTerritorio(nodos, st, nivel || st.nivel, id, nombre);
      },
      alDeseleccionar: function () {
        seleccionarTerritorio(nodos, st, 'departamento', '52', 'Nariño');
      }
    });

    if (!ctrl) {
      C.quitarSkeleton(nodos.mapa);
      C.error(nodos.mapa, 'No se pudo construir el mapa.', function () {
        montarMapa(nodos, st);
      });
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

        var alcance = alcanceDeVista(p.view, st);

        window.UHPRenderer.render(nodos.grafico, p, {
          legendPos: 'bottom',
          // Sin esto, D3plus pinta los ejes y la leyenda en tonos para
          // fondo claro y el gráfico del panel se vuelve ilegible en cuanto
          // el tablero es oscuro. Vale en los dos sentidos.
          tema: st.tema,
          // Con un territorio seleccionado y una vista que lo nombra, sus
          // marcas quedan a plena opacidad y las demás se atenúan.
          resaltar: alcance.resalta
            ? { campo: alcance.campo, valor: st.seleccion.nombre }
            : null,
          // Pulsar una marca selecciona ese territorio: el gráfico manda
          // sobre el mapa igual que el mapa manda sobre el gráfico.
          alPulsar: alcance.campo
            ? function (valor) { desdeGrafico(nodos, st, alcance.nivel, valor); }
            : null,
          reducirMovimiento: reducirMovimiento
        });

        if (nodos.analisis) {
          nodos.analisis.innerHTML = '';

          // Si hay territorio seleccionado y la vista no puede hablar de
          // él, se dice. Callarlo dejaría al visitante creyendo que el
          // gráfico está filtrado cuando no lo está.
          if (alcance.aviso) {
            nodos.analisis.appendChild(
              C.el('p', 'uhp-db__aviso', alcance.aviso)
            );
          }

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

  /**
   * Qué puede decir una vista sobre el territorio seleccionado.
   *
   * Devuelve el campo con el que se cruza, si procede resaltar, y el aviso
   * que hay que enseñar cuando la vista no llega a ese nivel.
   *
   * @param {Object} vista Vista devuelta por /render.
   * @param {Object} st    Estado del tablero.
   * @return {{campo:string,nivel:string,resalta:boolean,aviso:string}}
   */
  function alcanceDeVista(vista, st) {
    var dims = (vista && vista.dimensions) || [];
    var campo = dims.indexOf('municipio') >= 0 ? 'municipio'
      : (dims.indexOf('subregion') >= 0 ? 'subregion' : '');
    var nivel = campo === 'municipio' ? 'municipio' : (campo === 'subregion' ? 'subregion' : '');
    var sel = st.seleccion;
    var enDepartamento = 'departamento' === sel.nivel;

    if (enDepartamento) {
      return { campo: campo, nivel: nivel, resalta: false, aviso: '' };
    }

    if (campo && nivel === sel.nivel) {
      return { campo: campo, nivel: nivel, resalta: true, aviso: '' };
    }

    // La vista habla de territorio, pero de otro nivel que el elegido.
    if (campo) {
      return {
        campo: campo,
        nivel: nivel,
        resalta: false,
        aviso: 'Esta vista se publica por ' + (nivel === 'municipio' ? 'municipio' : 'subregión') +
          ', de modo que no se puede filtrar por ' +
          (sel.nivel === 'municipio' ? 'un municipio' : 'una subregión') + '. Se muestra completa.'
      };
    }

    return {
      campo: '',
      nivel: '',
      resalta: false,
      aviso: 'El proyecto solo publica esta vista para el conjunto del departamento: la cifra que se ve NO corresponde a ' +
        (sel.nombre || 'el territorio seleccionado') + '.'
    };
  }

  /** Selección hecha desde una marca del gráfico. */
  function desdeGrafico(nodos, st, nivel, valor) {
    if (!nivel || !valor) { return; }

    // El gráfico da el NOMBRE del territorio; el tablero necesita su
    // identificador. Se resuelve contra la geometría de esa capa, que ya
    // está memorizada.
    C.restCache('/geo', { nivel: nivel }).then(function (geo) {
      var buscado = normalizarNombre(valor);
      var hallado = null;
      (geo.features || []).forEach(function (f) {
        if (!hallado && normalizarNombre(f.properties.nombre) === buscado) {
          hallado = f.properties;
        }
      });
      if (!hallado) {
        anunciar(nodos.estado, 'No se encontró «' + valor + '» en el mapa.');
        return;
      }
      // Cambiar de capa si hace falta: pulsar un municipio en el gráfico
      // con la capa de subregiones activa debe llevar a ese municipio.
      if (st.nivel !== nivel) {
        st.nivel = nivel;
        var selCapa = nodos.controles && nodos.controles.querySelector('[data-uhp-capa]');
        if (selCapa) { selCapa.value = nivel; }
        if (st.mapa) {
          st.mapa.cambiarNivel(nivel).then(function () {
            seleccionarTerritorio(nodos, st, nivel, hallado.id, hallado.nombre);
          }).catch(function () { /* el mapa conserva su capa */ });
          return;
        }
      }
      seleccionarTerritorio(nodos, st, nivel, hallado.id, hallado.nombre);
    }).catch(function () { /* sin geometría no hay a dónde ir */ });
  }

  /** Misma regla de comparación que UHP_Municipios en el servidor. */
  function normalizarNombre(v) {
    var t = String(v == null ? '' : v);
    if (t.normalize) { t = t.normalize('NFD').replace(/[\u0300-\u036f]/g, ''); }
    return t.toLowerCase().replace(/\(.*?\)/g, '').replace(/[^a-z0-9]/g, '');
  }

  /* ------------------------------------------------------------------ */
  /* Ficha del territorio                                               */
  /* ------------------------------------------------------------------ */

  /**
   * Pinta la ficha del territorio seleccionado.
   *
   * Además de sus cifras, la ficha es el camino para moverse por el
   * territorio: el enlace de arriba sube a su subregión o al departamento
   * y los de abajo bajan a sus municipios. Es lo que convierte el tablero
   * en algo que se recorre y no solo se mira.
   *
   * @param {Object} nodos Nodos del tablero.
   * @param {Object} st    Estado.
   */
  function pintarFicha(nodos, st) {
    if (!nodos.ficha) { return; }
    var f = st.seleccion && st.seleccion.ficha;

    // En el departamento no hay nada que fichar: es el estado sin filtro.
    if (!f || 'departamento' === f.nivel) {
      nodos.ficha.classList.remove('is-activa');
      nodos.ficha.innerHTML = '';
      return;
    }

    nodos.ficha.innerHTML = '';
    nodos.ficha.classList.add('is-activa');

    /* --- Cabecera --- */
    var cab = C.el('div', 'uhp-db__ficha-cab');
    var titulo = C.el('h3', 'uhp-db__ficha-t', f.nombre || '');
    cab.appendChild(titulo);

    var cerrar = C.el('button', 'uhp-db__ficha-x', '✕');
    cerrar.type = 'button';
    cerrar.setAttribute('aria-label', 'Quitar el filtro y volver al departamento');
    cerrar.addEventListener('click', function () {
      seleccionarTerritorio(nodos, st, 'departamento', '52', 'Nariño');
    });
    cab.appendChild(cerrar);
    nodos.ficha.appendChild(cab);

    var cuerpo = C.el('div', 'uhp-db__ficha-cuerpo');

    /* --- Nivel y pertenencia --- */
    var linea = C.el('p', 'uhp-db__ficha-nivel');
    linea.appendChild(C.el('span', '', NOMBRE_NIVEL[f.nivel] || ''));
    if (f.padre) {
      linea.appendChild(document.createTextNode(' · '));
      var arriba = C.el('button', 'uhp-db__ficha-salto', f.padre.nombre);
      arriba.type = 'button';
      arriba.addEventListener('click', function () {
        seleccionarTerritorio(nodos, st, f.padre.nivel, f.padre.id, f.padre.nombre);
      });
      linea.appendChild(arriba);
    }
    cuerpo.appendChild(linea);

    /* --- Indicadores, con su condición --- */
    (f.indicadores || []).forEach(function (ind) {
      var estado = ESTADOS[ind.estado] || ESTADOS.publicado;
      var d = C.el('div', 'uhp-db__ficha-dato ' + estado.clase);
      if (estado.titulo) { d.title = estado.titulo; }

      var valor = (null === ind.valor || undefined === ind.valor)
        ? '—'
        : C.formato(ind.valor, ind.formato);
      d.appendChild(C.el('b', '', valor));

      var etq = C.el('span', '', ind.etiqueta);
      if (estado.marca) { etq.appendChild(C.el('i', 'uhp-db__kpi-marca', estado.marca)); }
      d.appendChild(etq);

      if (ind.nota) { d.appendChild(C.el('small', 'uhp-db__ficha-nota', ind.nota)); }
      cuerpo.appendChild(d);
    });

    /* --- Municipios de la subregión --- */
    if (f.hijos && f.hijos.length) {
      cuerpo.appendChild(C.el('h4', 'uhp-db__ficha-h', 'Municipios (' + f.hijos.length + ')'));
      var lista = C.el('div', 'uhp-db__chips');
      f.hijos.forEach(function (h) {
        var b = C.el('button', 'uhp-db__chip', h.nombre);
        b.type = 'button';
        b.setAttribute('data-uhp-hijo', h.id);
        b.addEventListener('click', function () {
          // Bajar a un municipio lleva también la capa del mapa: si no, se
          // seleccionaría algo que no está dibujado.
          if ('municipio' !== st.nivel) {
            st.nivel = 'municipio';
            var selCapa = nodos.controles && nodos.controles.querySelector('[data-uhp-capa]');
            if (selCapa) { selCapa.value = 'municipio'; }
            if (st.mapa) {
              st.mapa.cambiarNivel('municipio').then(function () {
                seleccionarTerritorio(nodos, st, 'municipio', h.id, h.nombre);
              }).catch(function () { /* el mapa conserva su capa */ });
              return;
            }
          }
          seleccionarTerritorio(nodos, st, 'municipio', h.id, h.nombre);
        });
        lista.appendChild(b);
      });
      cuerpo.appendChild(lista);
    }

    if (f.resumen) { cuerpo.appendChild(C.el('p', 'uhp-db__ficha-nota', f.resumen)); }
    if ('municipio' === f.nivel) {
      cuerpo.appendChild(C.el('p', 'uhp-db__ficha-cod', 'Código DIVIPOLA: ' + f.id));
    }

    nodos.ficha.appendChild(cuerpo);
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
