/* Mapa coroplético de Nariño sobre OpenStreetMap (Leaflet).

   Se usa desde [urkunina_mapa] y desde el tablero. La fábrica
   UHPMapa.crear(nodo, opts) devuelve un controlador con el que el tablero
   cambia de indicador sin volver a descargar la geometría.

   Atribución: © colaboradores de OpenStreetMap (ODbL). Geometría municipal
   del marco geoestadístico del DANE. */
(function () {
  'use strict';

  var C = window.UHPcore;

  /* Capas base admitidas. Todas son de uso libre con atribución; la
     etiqueta se muestra en el control de atribución de Leaflet. */
  var TESELAS = {
    osm: {
      url: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">Colaboradores de OpenStreetMap</a>',
      maxZoom: 19
    },
    humanitario: {
      url: 'https://{s}.tile.openstreetmap.fr/hot/{z}/{x}/{y}.png',
      attribution: '&copy; Colaboradores de OpenStreetMap · Teselas: Humanitarian OSM Team',
      maxZoom: 19
    },
    claro: {
      url: 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png',
      attribution: '&copy; Colaboradores de OpenStreetMap &copy; CARTO',
      maxZoom: 20,
      subdomains: 'abcd'
    },
    // Capa oscura: es la que acompaña al tablero, cuya identidad visual
    // es la del objeto 3D ([urkunina_3d]).
    oscuro: {
      url: 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
      attribution: '&copy; Colaboradores de OpenStreetMap &copy; CARTO',
      maxZoom: 20,
      subdomains: 'abcd'
    }
  };

  /* Tinta de los polígonos según el tema. Sobre la capa oscura, un gris
     claro para «sin dato» resultaría más llamativo que los municipios que
     sí tienen valor, justo al revés de lo que debe comunicar. */
  var TEMAS = {
    claro: {
      sinDato: '#EDF1F5',
      borde: '#9AA7B2',
      priorizado: '#10A13B',
      resalte: '#003366'
    },
    oscuro: {
      sinDato: 'rgba(233,237,241,.16)',
      borde: 'rgba(255,255,255,.22)',
      // Verde apagado, no el institucional a plena intensidad: 55 de los
      // 64 municipios están priorizados, así que sobre fondo oscuro un
      // filete brillante en casi todos deja de distinguir nada y solo
      // añade ruido. Basta con que se note la diferencia.
      priorizado: 'rgba(63,210,110,.45)',
      resalte: '#FFD500'
    }
  };

  /**
   * Crea un mapa coroplético.
   *
   * @param {HTMLElement} nodo Contenedor del mapa.
   * @param {Object}      opts lat, lon, zoom, teselas, indicador, alSeleccionar.
   * @return {Object|null} Controlador o null si Leaflet no está disponible.
   */
  function crear(nodo, opts) {
    opts = opts || {};
    if (!nodo || typeof window.L === 'undefined') { return null; }
    var L = window.L;

    var mapa = L.map(nodo, {
      center: [
        opts.lat === undefined ? 1.30 : Number(opts.lat),
        opts.lon === undefined ? -77.60 : Number(opts.lon)
      ],
      zoom: opts.zoom === undefined ? 8 : Number(opts.zoom),
      scrollWheelZoom: false,   // no secuestra el desplazamiento de la página
      zoomControl: true,
      attributionControl: true
    });

    // El scroll se activa al hacer clic dentro del mapa y se desactiva al
    // salir: así el visitante puede recorrer la página sin quedar atrapado.
    mapa.on('focus click', function () { mapa.scrollWheelZoom.enable(); });
    mapa.on('blur mouseout', function () { mapa.scrollWheelZoom.disable(); });

    var tema = TEMAS[opts.tema] || TEMAS.claro;
    var base = TESELAS[opts.teselas] || TESELAS.osm;
    L.tileLayer(base.url, {
      attribution: base.attribution,
      maxZoom: base.maxZoom,
      subdomains: base.subdomains || 'abc'
    }).addTo(mapa);

    var estado = {
      geo: null,
      capa: null,
      valores: {},
      meta: null,
      min: 0,
      max: 1,
      seleccion: '',
      leyenda: null
    };

    /* ---------------- Estilo ---------------- */

    function colorDe(divipola) {
      var v = estado.valores[divipola];
      if (!v || !estado.meta) { return tema.sinDato; }
      var rango = (estado.max - estado.min) || 1;
      return C.rampa(estado.meta.escala, (v.valor - estado.min) / rango);
    }

    function estilo(feature) {
      var p = feature.properties || {};
      var tieneDato = !!estado.valores[p.divipola];
      return {
        fillColor: colorDe(p.divipola),
        fillOpacity: tieneDato ? 0.85 : 0.45,
        color: p.priorizado ? tema.priorizado : tema.borde,
        weight: p.priorizado ? 0.9 : 0.6,
        opacity: 0.9
      };
    }

    function estiloResaltado() {
      return { weight: 2.6, color: tema.resalte, fillOpacity: 0.95 };
    }

    /* ---------------- Interacción ---------------- */

    function porCadaMunicipio(feature, capa) {
      var p = feature.properties || {};

      capa.on({
        mouseover: function (e) {
          e.target.setStyle(estiloResaltado());
          if (e.target.bringToFront) { e.target.bringToFront(); }
        },
        mouseout: function (e) {
          if (estado.seleccion === p.divipola) { return; }
          e.target.setStyle(estilo(feature));
        },
        click: function () {
          seleccionar(p.divipola);
          if (typeof opts.alSeleccionar === 'function') {
            opts.alSeleccionar(p.divipola, p.nombre, estado.valores[p.divipola] || null);
          }
        }
      });

      capa.bindTooltip(textoTooltip(p), { sticky: true, direction: 'auto', className: 'uhp-mapa__tip' });

      // Accesible por teclado: cada municipio es un elemento enfocable.
      if (capa.getElement) {
        capa.once('add', function () {
          var el = capa.getElement();
          if (!el) { return; }
          el.setAttribute('tabindex', '0');
          el.setAttribute('role', 'button');
          el.setAttribute('aria-label', textoPlano(p));
          el.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' || ev.key === ' ') {
              ev.preventDefault();
              seleccionar(p.divipola);
              if (typeof opts.alSeleccionar === 'function') {
                opts.alSeleccionar(p.divipola, p.nombre, estado.valores[p.divipola] || null);
              }
            }
          });
        });
      }
    }

    function textoPlano(p) {
      var v = estado.valores[p.divipola];
      var partes = [p.nombre];
      if (v && estado.meta) {
        partes.push(estado.meta.etiqueta + ': ' + C.num(v.valor) +
          (estado.meta.unidad === '%' ? ' %' : ' ' + estado.meta.unidad));
      } else {
        partes.push('sin dato publicado para este indicador');
      }
      if (p.priorizado) { partes.push('municipio priorizado por el proyecto'); }
      return partes.join('. ');
    }

    function textoTooltip(p) {
      var v = estado.valores[p.divipola];
      var html = '<strong>' + C.esc(p.nombre) + '</strong>';
      if (v && estado.meta) {
        var unidad = estado.meta.unidad === '%' ? ' %' : ' ' + C.esc(estado.meta.unidad);
        html += '<br><span class="uhp-mapa__tip-val">' + C.esc(C.num(v.valor)) + unidad + '</span>';
        html += '<br><span class="uhp-mapa__tip-etq">' + C.esc(estado.meta.etiqueta) + '</span>';
      } else {
        html += '<br><span class="uhp-mapa__tip-etq">Sin dato publicado</span>';
      }
      if (p.priorizado) {
        html += '<br><span class="uhp-mapa__tip-etq">Municipio priorizado</span>';
      }
      return html;
    }

    function seleccionar(divipola) {
      estado.seleccion = divipola;
      if (!estado.capa) { return; }
      estado.capa.eachLayer(function (l) {
        var p = (l.feature && l.feature.properties) || {};
        l.setStyle(p.divipola === divipola ? estiloResaltado() : estilo(l.feature));
      });
    }

    /* ---------------- Leyenda ---------------- */

    function pintarLeyenda() {
      if (estado.leyenda) { mapa.removeControl(estado.leyenda); estado.leyenda = null; }
      if (!estado.meta) { return; }

      var control = L.control({ position: 'bottomright' });
      control.onAdd = function () {
        var div = L.DomUtil.create('div', 'uhp-mapa__leyenda');
        div.setAttribute('aria-hidden', 'true');

        var t = document.createElement('strong');
        t.textContent = estado.meta.corto || estado.meta.etiqueta;
        div.appendChild(t);

        var escala = document.createElement('div');
        escala.className = 'uhp-mapa__escala';
        for (var i = 0; i < 5; i++) {
          var s = document.createElement('i');
          s.style.background = C.rampa(estado.meta.escala, i / 4);
          escala.appendChild(s);
        }
        div.appendChild(escala);

        var rango = document.createElement('div');
        rango.className = 'uhp-mapa__rango';
        var unidad = estado.meta.unidad === '%' ? ' %' : '';
        rango.appendChild(Object.assign(document.createElement('span'), {
          textContent: C.num(estado.min) + unidad
        }));
        rango.appendChild(Object.assign(document.createElement('span'), {
          textContent: C.num(estado.max) + unidad
        }));
        div.appendChild(rango);

        var sin = document.createElement('div');
        sin.className = 'uhp-mapa__sindato';
        var caja = document.createElement('i');
        caja.style.background = tema.sinDato;
        sin.appendChild(caja);
        sin.appendChild(document.createTextNode('Sin dato publicado'));
        div.appendChild(sin);

        // Sin esto, arrastrar sobre la leyenda movería el mapa.
        L.DomEvent.disableClickPropagation(div);
        return div;
      };
      control.addTo(mapa);
      estado.leyenda = control;
    }

    /* ---------------- Carga ---------------- */

    function calcularRango() {
      var vals = Object.keys(estado.valores).map(function (k) {
        return Number(estado.valores[k].valor);
      }).filter(function (v) { return !isNaN(v); });

      if (!vals.length) { estado.min = 0; estado.max = 1; return; }
      estado.min = Math.min.apply(null, vals);
      estado.max = Math.max.apply(null, vals);
      if (estado.min === estado.max) { estado.max = estado.min + 1; }
    }

    function pintarGeometria() {
      if (estado.capa) { mapa.removeLayer(estado.capa); }
      estado.capa = L.geoJSON(estado.geo, {
        style: estilo,
        onEachFeature: porCadaMunicipio
      }).addTo(mapa);

      try {
        mapa.fitBounds(estado.capa.getBounds(), { padding: [16, 16] });
      } catch (e) { /* geometría vacía: se conserva el encuadre inicial */ }
    }

    function refrescarEstilos() {
      if (!estado.capa) { return; }
      estado.capa.eachLayer(function (l) {
        l.setStyle(estilo(l.feature));
        var p = (l.feature && l.feature.properties) || {};
        l.setTooltipContent(textoTooltip(p));
        if (l.getElement && l.getElement()) {
          l.getElement().setAttribute('aria-label', textoPlano(p));
        }
      });
      pintarLeyenda();
    }

    /**
     * Cambia el indicador que colorea el mapa sin recargar la geometría.
     *
     * @param {string} indicador Clave del indicador.
     * @return {Promise}
     */
    function cambiarIndicador(indicador) {
      return C.rest('/mapa', { indicador: indicador }).then(function (r) {
        estado.valores = r.valores || {};
        estado.meta = r.meta || null;
        calcularRango();
        refrescarEstilos();
        return r;
      });
    }

    function iniciar() {
      nodo.classList.add('is-cargando');
      return Promise.all([
        C.restCache('/geo'),
        C.rest('/mapa', { indicador: opts.indicador || 'lpm' })
      ]).then(function (res) {
        estado.geo = res[0];
        estado.valores = res[1].valores || {};
        estado.meta = res[1].meta || null;
        calcularRango();
        pintarGeometria();
        pintarLeyenda();
        nodo.classList.remove('is-cargando');
        // Leaflet mide mal si el contenedor cambió de tamaño mientras cargaba.
        setTimeout(function () { mapa.invalidateSize(); }, 60);
        return res[1];
      }).catch(function (e) {
        nodo.classList.remove('is-cargando');
        throw e;
      });
    }

    return {
      mapa: mapa,
      iniciar: iniciar,
      cambiarIndicador: cambiarIndicador,
      seleccionar: seleccionar,
      redimensionar: function () { mapa.invalidateSize(); },
      estado: estado
    };
  }

  window.UHPMapa = { crear: crear, TESELAS: TESELAS };

  /* ---------------- [urkunina_mapa] ---------------- */

  C.ready(function () {
    Array.prototype.forEach.call(document.querySelectorAll('[data-uhp-mapa]'), function (caja) {
      var lienzo = caja.querySelector('.uhp-mapa__lienzo');
      if (!lienzo) { return; }

      var ctrl = crear(lienzo, {
        lat: caja.getAttribute('data-lat'),
        lon: caja.getAttribute('data-lon'),
        zoom: caja.getAttribute('data-zoom'),
        teselas: caja.getAttribute('data-teselas') || 'osm',
        tema: caja.getAttribute('data-tema') || 'claro',
        indicador: caja.getAttribute('data-indicador') || 'lpm'
      });

      if (!ctrl) {
        C.error(caja, 'No se pudo iniciar el mapa: la librería Leaflet no está disponible.');
        return;
      }

      ctrl.iniciar().then(function () {
        C.quitarSkeleton(caja);
        cablearSelector(caja, ctrl);
      }).catch(function () {
        C.quitarSkeleton(caja);
        C.error(lienzo, 'No se pudieron cargar los datos del mapa.', function () {
          ctrl.iniciar();
        });
      });
    });
  });

  function cablearSelector(caja, ctrl) {
    var sel = caja.querySelector('[data-uhp-indicador]');
    if (!sel) { return; }
    sel.addEventListener('change', function () {
      ctrl.cambiarIndicador(sel.value).catch(function () { /* se conserva el anterior */ });
    });
  }
})();
