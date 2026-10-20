/* [urkunina_dashboard] — tablero de resultados de URKUNINA 5000.

   Reproduce el tablero que diseñó la Secretaría TIC. Una sola petición a
   /tablero trae los 64 municipios con su geometría y sus cifras, y a
   partir de ahí todo ocurre en el cliente: filtrar por zona, por subregión
   o por municipio no vuelve a pedir nada.

   EL MAPA ES D3 PURO, NO LEAFLET. Proyección Mercator ajustada al
   departamento, trazado con d3.geoPath y desplazamiento con d3.zoom sobre
   un <g>. No hay teselas ni mapa base: el tablero habla de los 64
   municipios de Nariño y una capa de calles no aporta nada a esa lectura,
   mientras que sí añadiría una petición externa por cada celda.

   TRES REGLAS DE HONESTIDAD QUE EL DIBUJO SOSTIENE:

     · Un municipio SIN cifra propia se pinta con la de su subregión, pero
       atenuado y diciéndolo en el tooltip. Con la serie completa de los 55
       municipios ya no hace falta para ninguno de ellos, pero la regla se
       queda: si un día faltara una cifra, el mapa seguiría mostrando lo que
       sí se sabe en vez de dejar el municipio en gris.
     · Un municipio NO INTERVENIDO no se colorea en absoluto: va con trama
       discontinua. No es que falte el dato, es que el proyecto no estuvo
       allí.
     · Un municipio sin casos tiene CERO casos, no un dato que falte.

   Toda la salida se compone con textContent o con nodos creados a mano:
   nada de lo que devuelve la REST se inserta como HTML.                  */
(function () {
  'use strict';

  var C = window.UHPcore;

  /* Rampa de color y dominio de cada indicador. El dominio se fija a mano
     y no se deduce de los datos a propósito: si se recalculara con cada
     filtro, el mismo municipio cambiaría de color al cambiar la selección
     y dejaría de poder compararse consigo mismo. */
  var RAMPAS = {
    lpm: {
      etiqueta: 'Prevalencia de LPM (%)',
      dom: [11, 55],
      colores: ['#132a33', '#1d5f5a', '#2f9b74', '#5fe0a4']
    },
    hp: {
      etiqueta: 'Prevalencia de H. pylori (%)',
      dom: [55, 82],
      colores: ['#132330', '#23527f', '#3d86bd', '#86c9f2']
    }
  };

  var ZONA_ORDEN = ['roja', 'amarilla', 'verde'];

  var reducirMovimiento = window.matchMedia &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  C.ready(function () {
    var nodos = document.querySelectorAll('[data-uhp-tablero]');
    for (var i = 0; i < nodos.length; i++) { iniciar(nodos[i]); }
  });

  function iniciar(raiz) {
    if (!window.d3) {
      avisar(raiz, 'No se pudo iniciar el tablero: la librería D3 no está disponible.');
      return;
    }

    var st = {
      raiz: raiz,
      nodos: zonas(raiz),
      ind: raiz.getAttribute('data-indicador') === 'hp' ? 'hp' : 'lpm',
      zona: null,
      sub: null,
      muni: null,
      // Datos, una vez cargados.
      feats: [],
      muns: [],
      subs: {},
      fichasZona: {},
      // Quien quiera enterarse de los cambios; ver mando().alCambiar().
      oyentes: [],
      // Estado del dibujo.
      svg: null, root: null, gMun: null, gDept: null, gCaso: null,
      proyeccion: null, trazo: null, zoom: null,
      W: 0, H: 0, k: 1, ajuste: null
    };

    INSTANCIAS.push({ raiz: raiz, mando: mando(st) });

    montarMapa(st);
    cablear(st);
    cargar(st);
  }

  /* Índice de las zonas del marcado, para no consultar el DOM en cada
     redibujo: el tablero se repinta entero con cada clic. */
  function zonas(raiz) {
    var n = {};
    var nodos = raiz.querySelectorAll('[data-uhp-zona]');
    for (var i = 0; i < nodos.length; i++) {
      n[nodos[i].getAttribute('data-uhp-zona')] = nodos[i];
    }
    return n;
  }

  /* ------------------------------------------------------------------ */
  /* Datos                                                              */
  /* ------------------------------------------------------------------ */

  function cargar(st) {
    anunciar(st, 'Cargando los datos del tablero…');

    C.rest('/tablero').then(function (carga) {
      st.feats = (carga && carga.features) || [];
      var meta = (carga && carga.meta) || {};
      st.fichasZona = meta.zonas || {};

      // La zona de cada subregión se deduce de sus propios municipios: la
      // trae la geometría y no hace falta una segunda tabla en el cliente.
      var zonaDe = {};
      st.feats.forEach(function (f) {
        var p = f.properties || {};
        if (p.sub && p.zona) { zonaDe[p.sub] = p.zona; }
      });

      st.subs = {};
      Object.keys(meta.subregional || {}).forEach(function (nombre) {
        var v = meta.subregional[nombre];
        st.subs[nombre] = { lpm: v.lpm, hp: v.hp, zona: zonaDe[nombre] || '' };
      });

      st.muns = st.feats.filter(function (f) { return f.properties && f.properties.int; })
        .map(function (f) { return f.properties; });

      st.ajuste = {
        type: 'FeatureCollection',
        features: st.feats.filter(function (f) { return f.properties && f.properties.int; })
      };

      dimensionar(st);
      render(st);
      anunciar(st, st.muns.length + ' municipios intervenidos cargados.');
    }, function () {
      avisar(st.raiz, 'No se pudieron cargar los datos del tablero.', function () { cargar(st); });
    }).catch(function (err) {
      // Los datos llegaron y falló el dibujo: se separa del fallo de red
      // para no señalar al culpable equivocado.
      if (window.console && console.error) {
        console.error('[URKUNINA 5000] fallo al dibujar el tablero', err);
      }
      avisar(st.raiz, 'No se pudo dibujar el tablero.', function () { cargar(st); });
    });
  }

  /* ------------------------------------------------------------------ */
  /* Mapa                                                               */
  /* ------------------------------------------------------------------ */

  function montarMapa(st) {
    var caja = st.nodos.mapa;
    if (!caja) { return; }

    st.svg = window.d3.select(caja).append('svg')
      .attr('role', 'img')
      .attr('aria-label', 'Mapa de los 64 municipios de Nariño, coloreado por el indicador seleccionado');
    st.root = st.svg.append('g');
    st.gMun = st.root.append('g');
    st.gDept = st.root.append('g');
    st.gCaso = st.root.append('g');

    st.proyeccion = window.d3.geoMercator();
    st.trazo = window.d3.geoPath(st.proyeccion);

    st.zoom = window.d3.zoom().scaleExtent([1, 12]).on('zoom', function (e) {
      st.k = e.transform.k;
      st.root.attr('transform', e.transform);
      escalarTrazos(st);
    });
    st.svg.call(st.zoom);

    // El mapa ocupa todo su contenedor y este cambia con la ventana y con
    // el plegado de la página. Reajustar la proyección al observar el
    // contenedor evita que el departamento quede recortado o diminuto.
    if (window.ResizeObserver) {
      var ro = new ResizeObserver(function () {
        dimensionar(st);
        dibujarMapa(st);
      });
      ro.observe(caja);
    }
  }

  function dimensionar(st) {
    if (!st.svg || !st.nodos.mapa) { return; }
    var r = st.nodos.mapa.getBoundingClientRect();
    st.W = r.width || 900;
    st.H = r.height || 480;
    st.svg.attr('viewBox', '0 0 ' + st.W + ' ' + st.H);
    if (st.ajuste && st.ajuste.features.length) {
      st.proyeccion.fitExtent([[12, 40], [st.W - 12, st.H - 16]], st.ajuste);
    }
  }

  /* Color de un municipio según el indicador activo.

     Cae en la cifra de la subregión cuando no hay municipal. Devuelve el
     relleno «sin dato» solo cuando no hay ni una ni otra. */
  function colorDe(st, p) {
    var s = st.subs[p.sub] || {};
    var v = (p[st.ind] != null) ? p[st.ind] : s[st.ind];
    if (v == null) { return 'var(--uhp-db-panel-2)'; }
    var rp = RAMPAS[st.ind];
    var t = Math.max(0, Math.min(1, (v - rp.dom[0]) / (rp.dom[1] - rp.dom[0])));
    return window.d3.interpolateRgbBasis(rp.colores)(t);
  }

  /* Los trazos se dividen por la raíz del zoom y no por el zoom entero: a
     escala 12 un borde de 0,6 px dividido por 12 desaparecería, y sin
     dividir engordaría hasta tapar los municipios pequeños. */
  function escalarTrazos(st) {
    var raiz = Math.sqrt(st.k);
    st.gMun.selectAll('path').attr('stroke-width', function (d) {
      return (st.muni === d.properties.n ? 1.8 : 0.6) / raiz;
    });
    st.gDept.selectAll('path').attr('stroke-width', 1.4 / raiz);
    st.gCaso.selectAll('circle')
      .attr('r', function (d) { return (6 + d.properties.casos * 1.6) / raiz; })
      .attr('stroke-width', 2 / raiz);
  }

  function dibujarMapa(st) {
    if (!st.feats.length || !st.gMun) { return; }

    st.gMun.selectAll('path').data(st.feats, function (d) { return d.properties.c; })
      .join('path')
      .attr('class', function (d) {
        return 'uhp-db__muni' + (st.muni === d.properties.n ? ' sel' : '');
      })
      .attr('d', st.trazo)
      .attr('fill', function (d) {
        return d.properties.int ? colorDe(st, d.properties) : 'var(--uhp-db-mapa-hueco)';
      })
      .attr('fill-opacity', function (d) {
        var p = d.properties;
        if (!p.int) { return 1; }
        if (!pasa(st, p)) { return 0.14; }
        // Atenuado cuando la cifra es de la subregión y no del municipio:
        // se ve, pero se distingue de la que sí es suya.
        return (p[st.ind] != null) ? 1 : 0.72;
      })
      .attr('stroke', function (d) {
        if (st.muni === d.properties.n) { return 'var(--uhp-db-ink)'; }
        return d.properties.int ? 'var(--uhp-db-mapa-bg)' : 'var(--uhp-db-mapa-fuera)';
      })
      .attr('stroke-dasharray', function (d) { return d.properties.int ? null : '2 2'; })
      .attr('opacity', function (d) { return pasa(st, d.properties) ? 1 : 0.5; })
      .style('cursor', function (d) { return d.properties.int ? 'pointer' : 'default'; })
      .on('mousemove', function (e, d) { verTip(st, e, d.properties); })
      .on('mouseleave', function () { ocultarTip(st); })
      .on('click', function (e, d) {
        if (!d.properties.int) { return; }
        ocultarTip(st);
        // Desde el mapa no se reenfoca: el municipio ya está bajo el
        // cursor y mover la cámara bajo la mano desorienta.
        st.muni = (st.muni === d.properties.n) ? null : d.properties.n;
        render(st);
        notificar(st, 'usuario', st.muni || 'ningún municipio');
      });

    // Contorno del departamento: la unión de todas las geometrías, sin
    // relleno y sin eventos, para que no robe los clics a los municipios.
    st.gDept.selectAll('path')
      .data([{
        type: 'Feature',
        geometry: {
          type: 'GeometryCollection',
          geometries: st.feats.map(function (f) { return f.geometry; })
        }
      }])
      .join('path')
      .attr('d', st.trazo)
      .attr('fill', 'none')
      .attr('stroke', 'var(--uhp-db-mapa-linea)')
      .attr('pointer-events', 'none');

    st.gCaso.selectAll('circle')
      .data(st.feats.filter(function (f) { return f.properties.casos; }), function (d) { return d.properties.c; })
      .join('circle')
      .attr('cx', function (d) { return st.proyeccion([d.properties.lon, d.properties.lat])[0]; })
      .attr('cy', function (d) { return st.proyeccion([d.properties.lon, d.properties.lat])[1]; })
      .attr('fill', 'none')
      .attr('stroke', 'var(--uhp-db-roja)')
      .attr('pointer-events', 'none')
      .attr('opacity', function (d) { return pasa(st, d.properties) ? 1 : 0.2; });

    escalarTrazos(st);
  }

  function verTip(st, e, p) {
    var tip = st.nodos.tip;
    if (!tip) { return; }
    var s = st.subs[p.sub] || {};
    var r = st.nodos.mapa.getBoundingClientRect();

    tip.innerHTML = '';
    tip.appendChild(C.el('b', '', p.n));
    tip.appendChild(document.createElement('br'));
    tip.appendChild(C.el('span', 's', (p.sub || '—') + ' · ' + etiquetaZona(st, p.zona)));

    if (p.int) {
      tip.appendChild(document.createElement('br'));
      tip.appendChild(document.createTextNode(
        'LPM ' + cifra(p.lpm != null ? p.lpm : s.lpm) + '%' + (p.lpm == null ? ' (subregión)' : '') +
        ' · H. pylori ' + cifra(p.hp != null ? p.hp : s.hp) + '%' + (p.hp == null ? ' (subregión)' : '')
      ));
      if (p.casos) {
        tip.appendChild(document.createElement('br'));
        tip.appendChild(C.el('span', 'caso',
          p.casos + ' caso' + (p.casos > 1 ? 's' : '') +
          ' de cáncer detectado' + (p.casos > 1 ? 's' : '')));
      }
    } else {
      tip.appendChild(document.createElement('br'));
      tip.appendChild(C.el('span', 's', 'Municipio no intervenido por el proyecto'));
    }

    tip.style.opacity = 1;
    var x = e.clientX - r.left;
    var y = e.clientY - r.top;
    tip.style.left = Math.min(Math.max(8, x + 12), r.width - tip.offsetWidth - 8) + 'px';
    tip.style.top = Math.min(Math.max(8, y - 10), r.height - tip.offsetHeight - 8) + 'px';
  }

  function ocultarTip(st) {
    if (st.nodos.tip) { st.nodos.tip.style.opacity = 0; }
  }

  function verTodo(st) {
    var d = reducirMovimiento ? 0 : 400;
    st.svg.transition().duration(d).call(st.zoom.transform, window.d3.zoomIdentity);
  }

  function enfocar(st, feats) {
    if (!feats.length) { return verTodo(st); }
    var b = st.trazo.bounds({ type: 'FeatureCollection', features: feats });
    var pad = 26;
    var k = Math.max(1, Math.min(4.5, 0.92 / Math.max(
      (b[1][0] - b[0][0] + pad) / st.W,
      (b[1][1] - b[0][1] + pad) / st.H
    )));
    var cx = (b[0][0] + b[1][0]) / 2;
    var cy = (b[0][1] + b[1][1]) / 2;
    var d = reducirMovimiento ? 0 : 450;
    st.svg.transition().duration(d).call(st.zoom.transform,
      window.d3.zoomIdentity.translate(st.W / 2 - k * cx, st.H / 2 - k * cy).scale(k));
  }

  /* ------------------------------------------------------------------ */
  /* Filtros                                                            */
  /* ------------------------------------------------------------------ */

  function pasa(st, p) {
    if (!p.int) { return false; }
    if (st.zona && p.zona !== st.zona) { return false; }
    if (st.sub && p.sub !== st.sub) { return false; }
    return true;
  }

  function seleccion(st) {
    return st.muns.filter(function (m) { return pasa(st, m); });
  }

  function featDe(st, nombre) {
    for (var i = 0; i < st.feats.length; i++) {
      if (st.feats[i].properties.n === nombre) { return st.feats[i]; }
    }
    return null;
  }

  function etiquetaZona(st, zona) {
    var f = st.fichasZona[zona];
    return (f && f.etiqueta) || '—';
  }

  function colorZona(st, zona) {
    var f = st.fichasZona[zona];
    return (f && f.color) || 'var(--uhp-db-ink-3)';
  }

  function cifra(n) {
    return (n == null) ? '—' : C.num(n, 1);
  }

  /* ------------------------------------------------------------------ */
  /* Mutadores del estado

     Las reglas de coherencia entre los tres filtros vivían dentro de los
     manejadores de clic, repetidas. Aquí tienen un solo sitio, y los
     clics las invocan igual que las invoca el asistente de voz: por
     construcción, hablar y pulsar no pueden divergir.

     Todas reciben `origen` —'usuario' o 'agente'— y devuelven si el
     cambio se aplicó. Quien no ve la pantalla necesita saber que acaba
     de moverse algo que él no tocó, y de ahí que `notificar()` anteponga
     la autoría cuando el cambio no es suyo.                            */
  /* ------------------------------------------------------------------ */

  function aplicarIndicador(st, clave, origen) {
    var v = (clave === 'hp') ? 'hp' : 'lpm';
    if (!RAMPAS[v]) { return false; }
    st.ind = v;
    sincronizarPestanas(st);
    render(st);
    notificar(st, origen, RAMPAS[v].etiqueta);
    return true;
  }

  /* El `aria-pressed` de las pestañas de indicador lo movía el manejador
     de clic. Si solo lo moviera él, un cambio por voz dejaría el mapa
     repintado y los botones mintiendo. */
  function sincronizarPestanas(st) {
    var inds = st.raiz.querySelectorAll('[data-uhp-ind]');
    for (var i = 0; i < inds.length; i++) {
      var suyo = inds[i].getAttribute('data-uhp-ind') === 'hp' ? 'hp' : 'lpm';
      inds[i].setAttribute('aria-pressed', suyo === st.ind ? 'true' : 'false');
    }
  }

  function aplicarZona(st, clave, origen) {
    if (clave !== null && ZONA_ORDEN.indexOf(clave) < 0) { return false; }
    st.zona = clave;
    // Una subregión que ya no cae en la zona activa deja de filtrar: si
    // se quedara, la selección sería vacía sin explicar por qué.
    if (st.sub && st.subs[st.sub] && st.subs[st.sub].zona !== st.zona) { st.sub = null; }
    st.muni = null;
    render(st);
    enfocar(st, st.feats.filter(function (f) { return pasa(st, f.properties); }));
    notificar(st, origen, clave ? etiquetaZona(st, clave) : 'todas las zonas');
    return true;
  }

  function aplicarSubregion(st, nombre, origen) {
    if (nombre !== null && !st.subs[nombre]) { return false; }
    st.sub = nombre;
    // Elegir una subregión mueve también la zona: si no, filtrar por una
    // subregión de la zona verde con la roja activa no daría nada.
    st.zona = st.sub ? (st.subs[nombre].zona || null) : st.zona;
    st.muni = null;
    render(st);
    enfocar(st, st.feats.filter(function (f) { return pasa(st, f.properties); }));
    notificar(st, origen, nombre || 'todas las subregiones');
    return true;
  }

  /* `alternar` es lo que hace el clic: pulsar dos veces el mismo
     municipio lo suelta. Por voz NO se alterna, porque «muéstrame
     Túquerres» dicho dos veces no significa «quítalo». */
  function aplicarMunicipio(st, nombre, origen, alternar) {
    if (nombre === null) {
      st.muni = null;
      render(st);
      notificar(st, origen, 'ningún municipio');
      return true;
    }
    var m = municipioDe(st, nombre);
    if (!m) { return false; }
    st.muni = (alternar && st.muni === m.n) ? null : m.n;
    render(st);
    var f = st.muni ? featDe(st, st.muni) : null;
    if (f) { enfocar(st, [f]); }
    notificar(st, origen, st.muni || 'ningún municipio');
    return true;
  }

  function aplicarLimpiar(st, origen) {
    st.zona = null;
    st.sub = null;
    st.muni = null;
    verTodo(st);
    render(st);
    notificar(st, origen, 'los 55 municipios');
    return true;
  }

  function municipioDe(st, nombre) {
    for (var i = 0; i < st.muns.length; i++) {
      if (st.muns[i].n === nombre) { return st.muns[i]; }
    }
    return null;
  }

  function notificar(st, origen, que) {
    if (origen === 'agente') {
      anunciar(st, 'El asistente cambió la vista a ' + que + '.');
    } else if (st.nodos.estado && st.nodos.estado.textContent.indexOf('El asistente') === 0) {
      // El aviso anterior era del asistente y el visitante acaba de
      // actuar: dejarlo ahí haría que un lector de pantalla atribuyera
      // al asistente un cambio que hizo quien está leyendo.
      anunciar(st, '');
    }
    for (var i = 0; i < st.oyentes.length; i++) {
      try {
        st.oyentes[i](estadoResumido(st), origen);
      } catch (e) {
        // Un oyente roto no puede llevarse por delante el tablero.
        if (window.console && console.error) { console.error('[URKUNINA 5000] oyente del tablero', e); }
      }
    }
  }

  /* ------------------------------------------------------------------ */
  /* Render                                                             */
  /* ------------------------------------------------------------------ */

  function render(st) {
    var lista = seleccion(st);
    pintarKpis(st, lista);
    pintarZonas(st);
    pintarMunicipios(st, lista);
    pintarBarras(st, lista);
    pintarDetalle(st, lista);
    pintarLeyenda(st);
    pintarTituloMapa(st, lista);
    dibujarMapa(st);
  }

  function pintarKpis(st, lista) {
    var caja = st.nodos.kpis;
    if (!caja) { return; }

    var casos = lista.reduce(function (a, m) { return a + m.casos; }, 0);

    // El promedio se calcula SOLO sobre los municipios que tienen cifra,
    // propia o de su subregión. Contar a los que no la tienen como cero
    // —y dividir igual entre todos— arrastraría el promedio hacia abajo
    // sin que nada lo dijera. Hoy no hay ninguno así entre los 55
    // intervenidos, pero dos subregiones de la costa no tienen cifra
    // publicada: basta que el proyecto intervenga un municipio suyo para
    // que el descuido aparezca. Una prueba fija este comportamiento.
    var suma = 0;
    var conCifra = 0;
    lista.forEach(function (m) {
      var s = st.subs[m.sub] || {};
      var v = (m[st.ind] != null) ? m[st.ind] : s[st.ind];
      if (v == null) { return; }
      suma += v;
      conCifra++;
    });
    var promedio = conCifra ? (suma / conCifra) : null;
    // Prorrateo, y el rótulo lo dice. El proyecto NO publica cuántos
    // participantes aportó cada municipio: repartir los 5.000 a partes
    // iguales es una estimación, no un dato, y por eso la tarjeta se
    // titula «estimados» y su pie nombra el método.
    var partic = Math.round(5000 * lista.length / 55);

    caja.innerHTML = '';
    caja.appendChild(kpi('Municipios', String(lista.length), 'de 55 priorizados', 'acc'));
    caja.appendChild(kpi('Participantes est.', C.num(partic, 0), 'prorrateo sobre 5.000', ''));
    caja.appendChild(kpi(
      (st.ind === 'hp' ? 'H. pylori' : 'LPM') + ' promedio',
      cifra(promedio) + '%',
      // Cuando alguno de los seleccionados no tiene cifra, el pie deja de
      // ser la referencia departamental y pasa a decir sobre cuántos se
      // calculó: un promedio sin denominador no se puede leer.
      (conCifra < lista.length)
        ? 'sobre ' + conCifra + ' de ' + lista.length
        : (st.ind === 'hp' ? 'depto. 67,4%' : 'depto. 35,1%'),
      ''
    ));
    caja.appendChild(kpi('Casos de cáncer', String(casos), 'de 8 detectados', casos ? 'warn' : ''));
  }

  function kpi(lab, val, sub, clase) {
    var caja = C.el('div', 'uhp-db__kpi');
    caja.appendChild(C.el('div', 'lab', lab));
    caja.appendChild(C.el('div', 'val' + (clase ? ' ' + clase : ''), val));
    caja.appendChild(C.el('div', 'sub', sub));
    return caja;
  }

  function pintarZonas(st) {
    var caja = st.nodos.zonas;
    if (!caja) { return; }
    caja.innerHTML = '';

    ZONA_ORDEN.forEach(function (clave) {
      var f = st.fichasZona[clave];
      if (!f) { return; }
      var n = st.muns.filter(function (m) { return m.zona === clave; }).length;

      var b = C.el('button', 'uhp-db__zbtn');
      b.type = 'button';
      b.setAttribute('aria-pressed', st.zona === clave ? 'true' : 'false');
      b.title = f.territorio || '';

      var punto = C.el('i', 'uhp-db__dot');
      punto.style.background = f.color;
      b.appendChild(punto);

      var texto = C.el('span');
      texto.appendChild(document.createTextNode(f.etiqueta));
      texto.appendChild(document.createElement('br'));
      texto.appendChild(C.el('span', 'rate', C.num(f.incidencia, 0) + ' x 100.000'));
      b.appendChild(texto);

      b.appendChild(C.el('span', 'n', String(n)));

      b.addEventListener('click', function () {
        aplicarZona(st, (st.zona === clave) ? null : clave, 'usuario');
      });
      caja.appendChild(b);
    });
  }

  function pintarMunicipios(st, lista) {
    if (st.nodos.mcount) { st.nodos.mcount.textContent = String(lista.length); }
    var caja = st.nodos.mlista;
    if (!caja) { return; }
    caja.innerHTML = '';

    lista.slice().sort(function (a, b) { return a.n.localeCompare(b.n, 'es'); })
      .forEach(function (m) {
        var b = C.el('button', 'uhp-db__mrow');
        b.type = 'button';
        b.setAttribute('aria-pressed', st.muni === m.n ? 'true' : 'false');

        var punto = C.el('i', 'uhp-db__dot');
        punto.style.background = colorZona(st, m.zona);
        b.appendChild(punto);
        b.appendChild(C.el('span', '', m.n));
        if (m.casos) { b.appendChild(C.el('span', 'cs', m.casos + '★')); }
        b.appendChild(C.el('span', 'sr', m.sub));

        b.addEventListener('click', function () {
          aplicarMunicipio(st, m.n, 'usuario', true);
        });
        caja.appendChild(b);
      });
  }

  function pintarBarras(st, lista) {
    var caja = st.nodos.bars;
    if (!caja) { return; }
    caja.innerHTML = '';

    var pares = Object.keys(st.subs).map(function (k) { return [k, st.subs[k]]; })
      .filter(function (p) { return p[1][st.ind] != null; })
      .sort(function (a, b) { return b[1][st.ind] - a[1][st.ind]; });
    if (!pares.length) { return; }

    var max = pares[0][1][st.ind];
    var activas = {};
    lista.forEach(function (m) { activas[m.sub] = true; });

    pares.forEach(function (par) {
      var clave = par[0];
      var v = par[1][st.ind];
      var proporcion = v / max;

      var b = C.el('button', 'uhp-db__bar' + (activas[clave] ? '' : ' dim'));
      b.type = 'button';
      b.setAttribute('aria-pressed', st.sub === clave ? 'true' : 'false');

      var nm = C.el('div', 'nm', clave);
      nm.title = clave;
      b.appendChild(nm);

      var track = C.el('div', 'uhp-db__track');
      track.style.setProperty('--uhp-db-p', (proporcion * 100) + '%');
      var fill = C.el('div', 'uhp-db__fill' + (st.ind === 'hp' ? ' hp' : ''));
      fill.style.width = (proporcion * 100) + '%';
      track.appendChild(fill);
      // La cifra se ancla al final del RELLENO: dentro cuando la barra
      // pasa de la mitad —con el track más estrecho, 120 px, eso deja 60
      // px para una etiqueta de unos 40— y fuera cuando no. Así la cifra
      // tiene un solo fondo debajo y no queda medio ilegible.
      track.appendChild(C.el('span', proporcion >= 0.5 ? 'dentro' : '', C.num(v, 1) + '%'));
      b.appendChild(track);

      b.addEventListener('click', function () {
        aplicarSubregion(st, (st.sub === clave) ? null : clave, 'usuario');
      });
      caja.appendChild(b);
    });
  }

  function pintarDetalle(st, lista) {
    var titulo = st.nodos['detalle-titulo'];
    var caja = st.nodos.detalle;
    if (!caja) { return; }
    caja.innerHTML = '';

    if (st.muni) {
      var m = null;
      for (var i = 0; i < st.muns.length; i++) {
        if (st.muns[i].n === st.muni) { m = st.muns[i]; break; }
      }
      if (!m) { return; }
      var s = st.subs[m.sub] || {};

      if (titulo) { titulo.textContent = 'Municipio seleccionado'; }

      caja.appendChild(C.el('div', 'big', m.n));

      var meta = C.el('div', 'meta');
      var punto = C.el('i', 'uhp-db__dot');
      punto.style.background = colorZona(st, m.zona);
      meta.appendChild(punto);
      meta.appendChild(document.createTextNode(
        ' ' + etiquetaZona(st, m.zona) + ' · ' + m.sub + ' · DANE ' + m.c));
      caja.appendChild(meta);

      caja.appendChild(rejilla([
        ['LPM', cifra(m.lpm != null ? m.lpm : s.lpm) + '%', ''],
        ['H. pylori', cifra(m.hp != null ? m.hp : s.hp) + '%', ''],
        ['Casos', String(m.casos), m.casos ? 'roja' : 'apagado']
      ]));

      caja.appendChild(C.el('div', 'uhp-db__nota',
        (m.lpm != null || m.hp != null)
          ? 'Cifras propias del municipio, de la serie de los 55 intervenidos.'
          : 'Sin cifra municipal publicada: se muestra la referencia de la subregión ' + m.sub + '.'));
      return;
    }

    if (titulo) { titulo.textContent = 'Casos de cáncer gástrico detectados'; }

    caja.appendChild(rejilla([
      ['Detectados', '8', 'roja'],
      ['En atención EPS', '5', ''],
      ['Fallecieron', '3', '']
    ]));

    var conCasos = st.muns.filter(function (m) { return m.casos && pasa(st, m); })
      .sort(function (a, b) { return b.casos - a.casos; });

    var casos = C.el('div', 'uhp-db__casos');
    if (conCasos.length) {
      conCasos.forEach(function (m) {
        var b = C.el('button', 'uhp-db__cline');
        b.type = 'button';
        var punto = C.el('i', 'uhp-db__dot');
        punto.style.background = 'var(--uhp-db-roja)';
        b.appendChild(punto);
        b.appendChild(C.el('b', '', m.n));
        b.appendChild(C.el('span', 'cn', String(m.casos)));
        b.addEventListener('click', function () {
          aplicarMunicipio(st, m.n, 'usuario', false);
        });
        casos.appendChild(b);
      });
    } else {
      casos.appendChild(C.el('div', 'uhp-db__nota', 'Ningún caso detectado en la selección actual.'));
    }
    caja.appendChild(casos);

    caja.appendChild(C.el('div', 'uhp-db__nota',
      '8 casos en 7 municipios, todos en personas asintomáticas al momento del tamizaje.'));
  }

  function rejilla(filas) {
    var g = C.el('div', 'uhp-db__dgrid');
    filas.forEach(function (f) {
      var celda = C.el('div');
      celda.appendChild(C.el('div', 'l', f[0]));
      celda.appendChild(C.el('div', 'v' + (f[2] ? ' ' + f[2] : ''), f[1]));
      g.appendChild(celda);
    });
    return g;
  }

  function pintarLeyenda(st) {
    var rp = RAMPAS[st.ind];
    if (st.nodos['leyenda-titulo']) { st.nodos['leyenda-titulo'].textContent = rp.etiqueta; }
    if (st.nodos.rampa) {
      st.nodos.rampa.style.background = 'linear-gradient(90deg,' + rp.colores.join(',') + ')';
    }
    if (st.nodos['rampa-min']) { st.nodos['rampa-min'].textContent = rp.dom[0] + '%'; }
    if (st.nodos['rampa-max']) { st.nodos['rampa-max'].textContent = rp.dom[1] + '%'; }
    if (st.nodos['muestra-sub']) {
      // La muestra del «dato subregional» lleva el color del centro de la
      // rampa Y su misma atenuación, para que se reconozca en el mapa.
      st.nodos['muestra-sub'].style.background = window.d3.interpolateRgbBasis(rp.colores)(0.55);
      st.nodos['muestra-sub'].style.opacity = '.72';
    }
  }

  function pintarTituloMapa(st, lista) {
    var n = st.nodos.mapatitulo;
    if (!n) { return; }
    if (st.muni) {
      n.textContent = st.muni;
    } else if (st.sub) {
      n.textContent = 'Subregión ' + st.sub + ' · ' + lista.length + ' mun.';
    } else if (st.zona) {
      n.textContent = etiquetaZona(st, st.zona) + ' · ' + lista.length + ' mun.';
    } else {
      n.textContent = '55 municipios priorizados · área andina';
    }
  }

  /* ------------------------------------------------------------------ */
  /* Controles                                                          */
  /* ------------------------------------------------------------------ */

  function cablear(st) {
    var inds = st.raiz.querySelectorAll('[data-uhp-ind]');
    for (var i = 0; i < inds.length; i++) {
      (function (b) {
        b.addEventListener('click', function () {
          aplicarIndicador(st, b.getAttribute('data-uhp-ind'), 'usuario');
        });
      }(inds[i]));
    }

    accion(st, 'limpiar', function () {
      aplicarLimpiar(st, 'usuario');
    });
    accion(st, 'zoom-mas', function () {
      st.svg.transition().duration(reducirMovimiento ? 0 : 250).call(st.zoom.scaleBy, 1.6);
    });
    accion(st, 'zoom-menos', function () {
      st.svg.transition().duration(reducirMovimiento ? 0 : 250).call(st.zoom.scaleBy, 1 / 1.6);
    });
  }

  function accion(st, nombre, fn) {
    var b = st.raiz.querySelector('[data-uhp-accion="' + nombre + '"]');
    if (b) { b.addEventListener('click', fn); }
  }

  /* ------------------------------------------------------------------ */
  /* Estado y errores                                                   */
  /* ------------------------------------------------------------------ */

  /* El tablero cambia media pantalla con cada clic sin mover el foco, de
     modo que un lector de pantalla no se enteraría por su cuenta. */
  function anunciar(st, texto) {
    if (st.nodos && st.nodos.estado) { st.nodos.estado.textContent = texto; }
  }

  function avisar(raiz, mensaje, reintentar) {
    var main = raiz.querySelector('.uhp-db__main');
    if (!main) { return; }
    main.innerHTML = '';
    var caja = C.el('div', 'uhp-db__aviso');
    caja.setAttribute('role', 'alert');
    caja.appendChild(C.el('p', '', mensaje));
    if (reintentar) {
      var b = C.el('button', '', 'Reintentar');
      b.type = 'button';
      b.addEventListener('click', function () { window.location.reload(); });
      caja.appendChild(b);
    }
    main.appendChild(caja);
  }

  /* ------------------------------------------------------------------ */
  /* Lectura del tablero

     Todo lo que sigue LEE y no toca nada. Existe para que algo externo
     —hoy el asistente de voz— pueda describir lo que se está viendo sin
     volver a pedir datos ni, peor, inventárselos.                      */
  /* ------------------------------------------------------------------ */

  /* El mismo cálculo que pintarKpis: el promedio solo sobre los que
     tienen cifra. Si se duplicara aquí con otra regla, el tablero y el
     asistente dirían números distintos de la misma selección. */
  function estadoResumido(st) {
    var lista = seleccion(st);
    var suma = 0;
    var conCifra = 0;
    lista.forEach(function (m) {
      var sb = st.subs[m.sub] || {};
      var v = (m[st.ind] != null) ? m[st.ind] : sb[st.ind];
      if (v == null) { return; }
      suma += v;
      conCifra++;
    });
    return {
      disponible: st.feats.length > 0,
      indicador: st.ind,
      indicadorEtiqueta: st.ind === 'hp' ? 'infección por H. pylori' : 'lesión precursora de malignidad',
      zona: st.zona,
      zonaEtiqueta: st.zona ? etiquetaZona(st, st.zona) : null,
      subregion: st.sub,
      municipio: st.muni,
      visibles: lista.length,
      casos: lista.reduce(function (a, m) { return a + m.casos; }, 0),
      promedio: conCifra ? Math.round(suma / conCifra * 10) / 10 : null,
      conCifra: conCifra
    };
  }

  /* Normaliza un nombre para compararlo con lo que escribe el
     reconocimiento de voz: sin tildes, en minúsculas, sin artículo
     inicial y sin nada que no sea letra o número. «La Sabana», «la
     sabana» y «sabana» tienen que cruzar con la misma subregión. */
  function normalizar(txt) {
    var t = String(txt == null ? '' : txt).toLowerCase();
    t = t.normalize ? t.normalize('NFD').replace(/[̀-ͯ]/g, '') : t;
    t = t.replace(/^(el|la|los|las)\s+/, '');
    return t.replace(/[^a-z0-9]+/g, '');
  }

  /* Distancia de edición, acotada: solo interesa saber si dos nombres
     están a 1 o 2 cambios, no cuánto se parecen dos frases largas. */
  function distancia(a, b) {
    if (a === b) { return 0; }
    if (Math.abs(a.length - b.length) > 2) { return 99; }
    var fila = [];
    for (var j = 0; j <= b.length; j++) { fila[j] = j; }
    for (var i = 1; i <= a.length; i++) {
      var ant = fila[0];
      fila[0] = i;
      for (var k = 1; k <= b.length; k++) {
        var tmp = fila[k];
        fila[k] = Math.min(
          fila[k] + 1,
          fila[k - 1] + 1,
          ant + (a.charAt(i - 1) === b.charAt(k - 1) ? 0 : 1)
        );
        ant = tmp;
      }
    }
    return fila[b.length];
  }

  /* Los rótulos de los informes llevan el nombre popular entre
     paréntesis —«Colón (Génova)»— y por voz se dirá uno u otro. */
  function clavesDe(m) {
    var claves = [normalizar(m.n), m.c];
    var par = /^(.*?)\s*\(([^)]+)\)\s*$/.exec(m.n);
    if (par) {
      claves.push(normalizar(par[1]));
      claves.push(normalizar(par[2]));
    }
    return claves;
  }

  /* Resuelve un nombre dictado contra los municipios del tablero.

     NUNCA selecciona por aproximación. Hay tres pares de municipios
     intervenidos a dos cambios de distancia —Albán y Aldana, Iles e
     Imués, Linares y Leiva— y confundirlos significa leer en voz alta la
     cifra de cáncer gástrico de otro pueblo. Cuando no hay coincidencia
     exacta se devuelve la sugerencia para que alguien la confirme, y
     cuando sí la hay pero tiene un vecino cercano, se devuelve el vecino
     para poder advertirlo. */
  function resolver(st, texto) {
    var buscado = normalizar(texto);
    if (!buscado) { return { estado: 'ninguno' }; }

    var exacto = null;
    var cerca = null;
    var mejor = 99;
    st.muns.forEach(function (m) {
      var claves = clavesDe(m);
      for (var i = 0; i < claves.length; i++) {
        if (claves[i] === buscado) { exacto = m; return; }
      }
      var d = distancia(buscado, normalizar(m.n));
      if (d < mejor) { mejor = d; cerca = m; }
    });

    if (exacto) {
      return { estado: 'exacto', municipio: ficha(st, exacto), vecino: vecinoDe(st, exacto) };
    }
    // Un municipio de Nariño que el proyecto no intervino no es un error
    // de dicción: es que allí no hubo tamizaje, y hay que decirlo.
    var fuera = null;
    st.feats.forEach(function (f) {
      if (f.properties.int) { return; }
      var claves = clavesDe(f.properties);
      for (var i = 0; i < claves.length; i++) {
        if (claves[i] === buscado) { fuera = f.properties; }
      }
    });
    if (fuera) { return { estado: 'fuera', municipio: { n: fuera.n, c: fuera.c, sub: fuera.sub } }; }

    if (cerca && mejor <= 2) { return { estado: 'sugerencia', sugerencia: cerca.n }; }
    return { estado: 'ninguno' };
  }

  /* ¿Tiene este municipio otro a dos cambios de distancia? */
  function vecinoDe(st, m) {
    var yo = normalizar(m.n);
    var hallado = null;
    st.muns.forEach(function (o) {
      if (o.n === m.n || hallado) { return; }
      if (distancia(yo, normalizar(o.n)) <= 2) { hallado = o.n; }
    });
    return hallado;
  }

  /* Ficha de un municipio. `origen` dice si la cifra es suya o de su
     subregión: es la regla de honestidad del tablero, y por voz importa
     más todavía, porque nadie ve la atenuación del mapa. */
  function ficha(st, m) {
    var sb = st.subs[m.sub] || {};
    function dato(k) {
      if (m[k] != null) { return { valor: m[k], origen: 'municipal' }; }
      if (sb[k] != null) { return { valor: sb[k], origen: 'subregional' }; }
      return { valor: null, origen: 'sin dato' };
    }
    return {
      n: m.n,
      c: m.c,
      sub: m.sub,
      zona: m.zona,
      zonaEtiqueta: etiquetaZona(st, m.zona),
      lpm: dato('lpm'),
      hp: dato('hp'),
      casos: m.casos,
      intervenido: true
    };
  }

  /* ------------------------------------------------------------------ */
  /* Fachada

     El único canal por el que algo externo gobierna el tablero. No
     expone ni una operación que el teclado no alcance ya: si apareciera
     aquí algo que no se puede hacer con el ratón, sería una función
     oculta detrás del micrófono, y eso es justo lo que la norma de
     accesibilidad prohíbe.                                             */
  /* ------------------------------------------------------------------ */

  /* Las instancias se guardan en una lista y no en un WeakMap para no
     exigir ES6 a un archivo que no lo usa en ninguna otra línea. En una
     página caben una o dos: recorrerla es más barato que mantenerla. */
  var INSTANCIAS = [];

  function mando(st) {
    return {
      disponible: function () { return st.feats.length > 0; },
      estado: function () { return estadoResumido(st); },
      catalogo: function () {
        return {
          indicadores: ['lpm', 'hp'],
          zonas: ZONA_ORDEN.slice(),
          subregiones: Object.keys(st.subs).sort(),
          municipios: st.muns.map(function (m) { return m.n; }).sort()
        };
      },
      resolver: function (texto) { return resolver(st, texto); },
      ficha: function (nombre) {
        var m = municipioDe(st, nombre);
        return m ? ficha(st, m) : null;
      },
      listar: function (filtro) {
        var f = filtro || {};
        return st.muns.filter(function (m) {
          if (f.zona && m.zona !== f.zona) { return false; }
          if (f.subregion && m.sub !== f.subregion) { return false; }
          if (!f.zona && !f.subregion) { return pasa(st, m); }
          return true;
        }).map(function (m) { return m.n; });
      },
      setIndicador: function (v, origen) { return aplicarIndicador(st, v, origen || 'agente'); },
      setZona: function (v, origen) { return aplicarZona(st, v, origen || 'agente'); },
      setSubregion: function (v, origen) { return aplicarSubregion(st, v, origen || 'agente'); },
      setMunicipio: function (v, origen) { return aplicarMunicipio(st, v, origen || 'agente', false); },
      limpiar: function (origen) { return aplicarLimpiar(st, origen || 'agente'); },
      alCambiar: function (fn) {
        if (typeof fn !== 'function') { return function () {}; }
        st.oyentes.push(fn);
        return function () {
          var i = st.oyentes.indexOf(fn);
          if (i >= 0) { st.oyentes.splice(i, 1); }
        };
      }
    };
  }

  /* Sin argumento devuelve el primer tablero del documento, que es el
     caso de todas las páginas reales: dos tableros en una misma página
     no tendrían a quién obedecer. */
  function mandoDe(raiz) {
    for (var i = 0; i < INSTANCIAS.length; i++) {
      if (!raiz || INSTANCIAS[i].raiz === raiz) { return INSTANCIAS[i].mando; }
    }
    return null;
  }

  window.UHPTablero = { iniciar: iniciar, mando: mandoDe };
}());
