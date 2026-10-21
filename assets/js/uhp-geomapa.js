/* [urkunina_geomapa] — mapa coroplético de Nariño con D3plus Geomap.

   Es la pieza que faltaba en el módulo de gráficos: hasta ahora el
   territorio solo se podía ver con Leaflet ([urkunina_mapa] y el tablero),
   que es un mapa de navegación. Este es un GRÁFICO de mapa, del mismo
   motor que el resto de vistas, con su leyenda, su tooltip y su análisis
   coherentes con los demás.

   Dos diferencias de fondo con [urkunina_mapa]:

     · La capa base de teselas se enciende y se apaga desde el shortcode
       (`teselas="si|no"`). Sin teselas queda una plancha limpia, que es lo
       que pide la identidad de la entidad para material impreso o para una
       ficha; con teselas se ve el relieve y las vías, que ayuda a situar
       los municipios a quien no conoce el departamento.
     · D3plus Geomap consume TopoJSON, no GeoJSON: la topología llega de
       /wp-json/urkunina/v1/topojson, construida en el servidor.

   Expone window.UHPGeomapa por si otro componente quiere montar uno.      */
(function () {
  'use strict';

  var C = window.UHPcore;

  /* Teselas por tema. Son las mismas de CARTO que usa el mapa de Leaflet,
     de modo que los dos componentes se ven como el mismo departamento.
     D3plus sustituye {s} por a|b|c y {z}/{x}/{y} por la tesela. */
  var TESELAS = {
    claro: 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}@2x.png',
    oscuro: 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}@2x.png',
    osm: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png'
  };

  /* La atribución de las teselas NO se escribe aquí: D3plus la deriva de
     la propia URL de la capa y la pinta cuando `tiles` está encendido,
     de modo que siempre cita al proveedor que se esté usando. Escribir
     otra al lado solo produciría la misma línea dos veces. Lo que sí
     decidimos es su aspecto, con `attributionStyle`. */

  /* Colores del territorio en cada tema. `sinDato` es el relleno de los
     municipios que la vista no nombra: se ven, pero no compiten con los
     que sí traen cifra. */
  var TEMAS = {
    claro: {
      sinDato: '#EDF1F5',
      borde: '#FFFFFF',
      oceano: 'transparent',
      tinta: '#0F172A',
      suave: '#5B6773',
      titulo: '#003366'
    },
    oscuro: {
      sinDato: 'rgba(255,255,255,.08)',
      borde: 'rgba(12,17,22,.85)',
      oceano: 'transparent',
      tinta: '#E7EDF1',
      suave: '#A9B7C1',
      titulo: '#FFD500'
    }
  };

  var reducirMovimiento = window.matchMedia &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ------------------------------------------------------------------ */
  /* Arranque                                                           */
  /* ------------------------------------------------------------------ */

  C.ready(function () {
    var nodos = document.querySelectorAll('[data-uhp-geomapa]');
    for (var i = 0; i < nodos.length; i++) { iniciar(nodos[i]); }
  });

  function iniciar(fig) {
    var lienzo = fig.querySelector('.uhp-geo__lienzo');
    if (!lienzo) { return; }

    return montar({
      fig: fig,
      lienzo: lienzo,
      titulo: fig.querySelector('.uhp-geo__titulo'),
      leyendaCaja: fig.querySelector('.uhp-geo__leyenda'),
      view: fig.getAttribute('data-view') || '',
      indicador: fig.getAttribute('data-indicador') || '',
      nivel: fig.getAttribute('data-nivel'),
      // Serie a dibujar cuando la vista trae más de una por territorio.
      serie: fig.getAttribute('data-serie') || '',
      tema: fig.getAttribute('data-tema'),
      teselas: fig.getAttribute('data-teselas') === '1',
      capa: fig.getAttribute('data-capa') || '',
      zoom: fig.getAttribute('data-zoom') !== '0',
      leyenda: fig.getAttribute('data-leyenda') !== '0',
      etiquetas: fig.getAttribute('data-etiquetas') === '1'
    });
  }

  /* Monta un geomapa sobre un lienzo cualquiera.

     [urkunina_geomapa] llega aquí desde sus data-*, y [urkunina_grafico]
     cuando el usuario elige el tipo «mapa» en la barra: es el mismo mapa,
     con la misma topología cacheada y la misma leyenda, montado en otro
     contenedor. Devuelve el estado para poder desmontarlo después.       */
  function montar(o) {
    if (!o || !o.lienzo) { return null; }

    var st = {
      fig: o.fig || o.lienzo,
      lienzo: o.lienzo,
      titulo: o.titulo || null,
      leyendaCaja: o.leyendaCaja || null,
      view: o.view || '',
      indicador: o.indicador || '',
      nivel: o.nivel === 'subregion' ? 'subregion' : 'municipio',
      serie: o.serie || '',
      tema: o.tema === 'oscuro' ? 'oscuro' : 'claro',
      teselas: o.teselas === true,
      capa: o.capa || '',
      zoom: o.zoom !== false,
      leyenda: o.leyenda !== false,
      etiquetas: o.etiquetas === true,
      topo: null,
      datos: null,
      viz: null
    };

    cargar(st);
    return st;
  }

  /* Desmonta un geomapa y deja el lienzo limpio para otro dibujo.

     Sin esto, cambiar de «mapa» a «barras» en la barra del gráfico
     dejaría el SVG del mapa debajo del nuevo: D3plus dibuja dentro del
     contenedor que le dan, no lo vacía al soltarlo.                      */
  function destruir(st) {
    if (!st) { return; }
    try {
      if (st.viz && typeof st.viz.delete === 'function') { st.viz.delete(); }
    } catch (e) {
      // Un fallo al soltar la instancia no debe impedir vaciar el lienzo:
      // lo que importa es que no quede el mapa anterior por debajo.
      if (window.console && console.warn) { console.warn('[URKUNINA 5000] no se pudo soltar el geomapa', e); }
    }
    st.viz = null;
    if (st.lienzo) { st.lienzo.innerHTML = ''; }
    if (st.leyendaCaja) { st.leyendaCaja.innerHTML = ''; }
  }

  /* ------------------------------------------------------------------ */
  /* Datos                                                              */
  /* ------------------------------------------------------------------ */

  function cargar(st) {
    // La topología va aparte de los valores y con restCache: pesa unas
    // decenas de kilobytes y no cambia nunca, así que varios geomapas en
    // la misma página la descargan una sola vez entre todos.
    Promise.all([
      C.restCache('/topojson', { nivel: st.nivel }),
      C.rest('/geomapa', { view: st.view, indicador: st.indicador, serie: st.serie })
    ]).then(function (r) {
      st.topo = r[0];
      st.datos = r[1];
      C.quitarSkeleton(st.fig);
      dibujar(st);
    }, function () {
      C.error(st.lienzo, 'No se pudo cargar el mapa del departamento.', function () {
        cargar(st);
      });
    }).catch(function (err) {
      // Los datos llegaron y falló el dibujo: se separa del fallo de red
      // para no señalar al culpable equivocado.
      if (window.console && console.error) {
        console.error('[URKUNINA 5000] fallo al dibujar el geomapa', err);
      }
      C.error(st.lienzo, 'No se pudo dibujar el mapa.', function () { cargar(st); });
    });
  }

  /* ------------------------------------------------------------------ */
  /* Dibujo                                                             */
  /* ------------------------------------------------------------------ */

  function dibujar(st) {
    if (!window.d3plus || !window.d3plus.Geomap) {
      C.error(st.lienzo, 'No se pudo iniciar el mapa: la librería D3plus no está disponible.');
      return;
    }

    var tema = TEMAS[st.tema];
    var meta = (st.datos && st.datos.meta) || {};
    var valores = (st.datos && st.datos.valores) || {};
    var objeto = (st.datos && st.datos.objeto) ||
      (st.nivel === 'subregion' ? 'subregiones' : 'municipios');

    if (st.titulo && !st.titulo.textContent.trim()) {
      st.titulo.textContent = meta.etiqueta || 'Mapa del departamento';
    }

    // Una fila por municipio CON dato. Los que no tienen quedan fuera de
    // `data` y D3plus los pinta con el relleno de `topojsonFill`, que es
    // exactamente la distinción que se quiere ver.
    // La subregión de cada territorio sale de la propia topología, para
    // poder decirla en el tooltip del mapa municipal sin otra petición.
    var subregionDe = {};
    var coleccion = st.topo && st.topo.objects && st.topo.objects[objeto];
    ((coleccion && coleccion.geometries) || []).forEach(function (g) {
      if (g && g.properties && g.properties.subregion) {
        subregionDe[g.id] = g.properties.subregion;
      }
    });

    var filas = [];
    var min = Infinity;
    var max = -Infinity;
    Object.keys(valores).forEach(function (clave) {
      var v = Number(valores[clave].valor);
      if (!isFinite(v)) { return; }
      min = Math.min(min, v);
      max = Math.max(max, v);
      filas.push({
        id: clave,
        territorio: valores[clave].nombre,
        subregion: subregionDe[clave] || '',
        valor: v
      });
    });
    /* Una vista puede no traer ni un valor. Ocurre cuando los archivos de
       datos instalados se han quedado atrás respecto del código —un
       despliegue que copia `includes/` y se salta `data/`, por ejemplo—:
       la vista existe, el mapa se dibuja y sale entero en gris con una
       rampa de 0 a 0, que es exactamente lo que NO debe leerse como «en
       ningún municipio hay dato publicado». Se marca el caso para que la
       leyenda lo diga y se deja traza para quien administre el sitio. */
    var vacio = !filas.length;
    if (vacio) {
      min = 0;
      max = 0;
      if (window.console && console.warn) {
        console.warn('[URKUNINA 5000] la vista «' + (st.view || st.indicador || '?') +
          '» no devolvió ningún valor: revise que los archivos de data/ estén al día.');
      }
    }

    var escala = meta.escala || ['#EAF4FF', '#69A8D6', '#3FD26E', '#FFD500', '#C0392B'];
    var rango = (max - min) || 1;
    var unidad = meta.unidad || '';

    /* Relleno de cada municipio.

       D3plus entrega a los accesores la fila de datos cuando el municipio
       tiene cifra, y el feature crudo de la topología cuando no la tiene.
       Distinguir los dos casos aquí es imprescindible: sin la comprobación,
       un municipio sin dato caería en el extremo bajo de la rampa y se
       leería como un mínimo real. */
    function color(d) {
      if (!d || typeof d.valor !== 'number' || !isFinite(d.valor)) {
        return tema.sinDato;
      }
      return C.rampa(escala, (d.valor - min) / rango);
    }

    /** Nombre del territorio, venga de la fila o del feature. */
    function nombre(d) {
      if (d && d.territorio) { return d.territorio; }
      if (d && d.properties && d.properties.nombre) { return d.properties.nombre; }
      return '';
    }

    /* Cómo se llama la unidad en los textos: el mismo componente sirve
       para municipios y para subregiones, y decir «Municipio» sobre un
       mapa de subregiones sería sencillamente falso. */
    var UNIDAD = (st.nivel === 'subregion') ? 'Subregión' : 'Municipio';
    var CLAVE = (st.nivel === 'subregion') ? 'Código' : 'DIVIPOLA';

    var viz = new window.d3plus.Geomap()
      .select(st.lienzo)
      .data(filas)
      .groupBy('id')
      .topojson(st.topo)
      .topojsonKey(objeto)
      .topojsonId(function (d) { return d.id; })
      // El filtro por defecto de D3plus descarta la Antártida por id; el
      // nuestro no descarta nada, porque todos los municipios se dibujan.
      .topojsonFilter(function () { return true; })
      .topojsonFill(tema.sinDato)
      .ocean(tema.oceano)
      .tiles(st.teselas)
      .tileUrl(TESELAS[st.capa] || TESELAS[st.tema])
      .attributionStyle({
        background: st.tema === 'oscuro' ? 'rgba(12,17,22,.82)' : 'rgba(255,255,255,.86)',
        border: '1px solid ' + (st.tema === 'oscuro' ? 'rgba(255,255,255,.14)' : 'rgba(15,23,42,.14)'),
        color: st.tema === 'oscuro' ? '#A9B7C1' : '#5B6773',
        font: "400 11px/1.4 var(--uhp-fuente, system-ui, sans-serif)",
        opacity: 1,
        padding: '3px 7px'
      })
      .zoom(st.zoom)
      // La rueda no hace zoom mientras el puntero solo pasa por encima:
      // secuestrar el desplazamiento de la página es de las cosas que más
      // estorban a quien está leyendo. Se navega arrastrando y con los
      // controles.
      .zoomScroll(false)
      // Los controles de D3plus vienen con el estilo de su propia hoja de
      // muestra; aquí se visten con los tokens de la tarjeta para que no
      // desentonen ni se pierdan sobre el tema oscuro.
      .zoomControlStyle(controlesZoom(tema, false))
      .zoomControlStyleHover(controlesZoom(tema, true))
      .zoomControlStyleActive(controlesZoom(tema, true))
      .shapeConfig({
        // El relleno va DENTRO de Path, no al lado: Geomap ya trae su
        // propio `shapeConfig.Path.fill` y la configuración de la forma
        // pisa a la general, de modo que un `fill` de primer nivel se
        // ignora en silencio y el mapa sale entero del color «sin dato».
        Path: {
          fill: color,
          stroke: tema.borde,
          strokeWidth: 0.6,
          fillOpacity: st.teselas ? 0.78 : 1,
          // Sin esto, con los rótulos apagados D3plus compone el texto
          // accesible a partir del accesor de etiqueta y cada municipio
          // acaba anunciándose como «false».
          ariaLabel: function (d) {
            var n = nombre(d) || UNIDAD;
            if (!d || typeof d.valor !== 'number') {
              return n + ': sin dato publicado.';
            }
            return n + ': ' + C.num(d.valor, 1) + (unidad ? ' ' + unidad : '') + '.';
          }
        }
      })
      // El nombre alimenta el tooltip y el aria-label de cada trazado; que
      // además se escriba sobre el mapa lo decide el shortcode, porque 64
      // rótulos a la vez son ilegibles salvo en un mapa a página completa.
      .label(st.etiquetas ? nombre : false)
      .tooltipConfig({
        title: function (d) { return nombre(d) || UNIDAD; },
        tbody: function (d) {
          if (!d || typeof d.valor !== 'number') {
            return [[meta.corto || 'Valor', 'Sin dato publicado']];
          }
          var filas = [
            [meta.corto || 'Valor', C.num(d.valor, 1) + (unidad ? ' ' + unidad : '')],
            [CLAVE, d.id]
          ];
          // En el mapa municipal se dice a qué subregión pertenece: es la
          // pieza que enlaza las dos lecturas del territorio.
          var sub = d.subregion || (d.properties && d.properties.subregion);
          if (sub) { filas.push(['Subregión', sub]); }
          return filas;
        }
      })
      .legend(false)          // la leyenda es una rampa, no una lista.
      .detectResize(true)
      // Igual que en el resto de gráficos: la detección de visibilidad de
      // D3plus deja en blanco lo que arranca por debajo del pliegue.
      .detectVisible(false)
      .duration(reducirMovimiento ? 0 : 600)
      .render();

    st.viz = viz;

    if (st.leyenda) { pintarLeyenda(st, escala, min, max, meta, vacio); }
  }

  /**
   * Estilo de los botones de zoom de D3plus, por tema.
   *
   * @param {object} tema     Entrada de TEMAS.
   * @param {boolean} resalte Estado de hover o pulsado.
   * @return {object}
   */
  function controlesZoom(tema, resalte) {
    var oscuro = tema === TEMAS.oscuro;
    return {
      background: resalte
        ? (oscuro ? 'rgba(255,255,255,.14)' : '#EEF3F8')
        : (oscuro ? 'rgba(255,255,255,.06)' : '#FFFFFF'),
      border: '1px solid ' + (oscuro ? 'rgba(255,255,255,.16)' : '#D7DEE6'),
      color: oscuro ? '#E7EDF1' : '#003366',
      'border-radius': '6px',
      margin: '3px',
      padding: '3px 7px'
    };
  }

  /* ------------------------------------------------------------------ */
  /* Cromo alrededor del mapa                                           */
  /* ------------------------------------------------------------------ */

  function pintarLeyenda(st, escala, min, max, meta, vacio) {
    var caja = st.leyendaCaja || st.fig.querySelector('.uhp-geo__leyenda');
    if (!caja) { return; }
    caja.innerHTML = '';

    var t = C.el('strong', '', meta.corto || meta.etiqueta || 'Indicador');
    caja.appendChild(t);

    // Sin un solo valor no hay rampa que enseñar: un degradado rotulado
    // «0,0 %» en los dos extremos afirma algo que nadie ha medido.
    if (vacio) {
      caja.appendChild(C.el('div', 'uhp-geo__vacio',
        'Esta vista no trae ningún valor publicado.'));
      return;
    }

    var rampa = C.el('div', 'uhp-geo__escala');
    for (var i = 0; i < 5; i++) {
      var s = C.el('i');
      s.style.background = C.rampa(escala, i / 4);
      rampa.appendChild(s);
    }
    caja.appendChild(rampa);

    var unidad = meta.unidad ? ' ' + meta.unidad : '';
    var rangos = C.el('div', 'uhp-geo__rango');
    rangos.appendChild(C.el('span', '', C.num(min, 1) + unidad));
    rangos.appendChild(C.el('span', '', C.num(max, 1) + unidad));
    caja.appendChild(rangos);

    var sin = C.el('div', 'uhp-geo__sindato');
    var muestra = C.el('i');
    muestra.style.background = TEMAS[st.tema].sinDato;
    sin.appendChild(muestra);
    sin.appendChild(C.el('span', '', 'Sin dato publicado'));
    caja.appendChild(sin);
  }

  window.UHPGeomapa = { iniciar: iniciar, montar: montar, destruir: destruir };
}());
