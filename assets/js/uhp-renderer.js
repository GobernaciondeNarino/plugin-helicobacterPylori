/* Renderer genérico D3plus (capa 3 del motor de gráficos).

   Recibe el payload {chart, view, data, compatible} que devuelve
   /wp-json/urkunina/v1/render y dibuja un SVG interactivo siguiendo las
   reglas de calidad del ecosistema:

     · color POR SERIE (nunca por índice de punto: colorear por índice
       crea una serie falsa por cada punto y rompe la leyenda),
     · o color POR VALOR en las vistas de magnitud marcadas `heatmap`,
       donde el color codifica la cifra y no la categoría,
     · ejes siempre con título,
     · tooltip con title + tbody explícitos (sin tbody se ve vacío),
     · detectResize para redibujar al cambiar el contenedor,
     · detectVisible apagado (ver la nota en renderD3plus).

   Expone window.UHPRenderer.render(nodo, payload, opts). */
(function () {
  'use strict';

  var C = window.UHPcore;

  /* Paleta categórica institucional. Arranca con el verde y el azul de la
     Gobernación de Nariño y sigue con tonos de contraste suficiente entre
     sí y sobre fondo claro (WCAG 2.1 AA para elementos gráficos). */
  var PALETA = [
    '#10A13B', '#003366', '#F08A00', '#8C4A8E', '#0080C3', '#C0392B',
    '#1ABC9C', '#B8860B', '#5D6D7E', '#7D3C98', '#2E86C1', '#AF601A'
  ];

  /* Rampa de magnitud, de frío a cálido, para los rankings. */
  var CALOR = ['#EAF4FF', '#69A8D6', '#3FD26E', '#FFD500', '#F08A00', '#C0392B'];

  /* Paleta categórica para fondo oscuro: los mismos matices de la clara
     pero aclarados, porque sobre el fondo #0C1116 del objeto 3D el verde
     institucional y el azul de encabezados no llegan al contraste mínimo
     que exige el Anexo 1 de la Resolución 1519 de 2020. */
  var PALETA_OSCURA = [
    '#3FD26E', '#5FA8E0', '#FFB74D', '#C58BC7', '#4DD0C4', '#FF8A80',
    '#A5D6A7', '#FFD54F', '#90A4AE', '#CE93D8', '#81D4FA', '#FFAB91'
  ];

  /* Tinta de los ejes, las etiquetas y la leyenda en cada tema. Los tonos
     del tema oscuro son los del objeto 3D ([urkunina_3d]). */
  var TINTAS = {
    claro: {
      titulo: '#003366',
      etiqueta: '#5B6773',
      leyenda: '#0F172A',
      rejilla: '#E2E8F0'
    },
    oscuro: {
      titulo: '#FFD500',
      etiqueta: '#A9B7C1',
      leyenda: '#E7EDF1',
      rejilla: 'rgba(255,255,255,.10)'
    }
  };

  /* Etiquetas legibles de los campos, para ejes y tooltip. */
  var ETIQUETAS = {
    zona: 'Zona de riesgo',
    incidencia: 'Incidencia (casos por 100.000)',
    ambito: 'Ámbito',
    tasa: 'Tasa (casos por 100.000)',
    municipio: 'Municipio',
    mortalidad: 'Mortalidad (por 100.000)',
    resultado: 'Resultado',
    personas: 'Personas',
    porcentaje: 'Porcentaje',
    indicador: 'Indicador',
    positivos: 'Positivos',
    negativos: 'Negativos',
    prevalencia: 'Prevalencia (%)',
    subregion: 'Subregión',
    valor: 'Valor (%)',
    categoria: 'Categoría',
    tipo: 'Tipo',
    cantidad: 'Muestras',
    casos: 'Casos',
    estado: 'Estado',
    fuente: 'Fuente de financiación',
    producto: 'Producto',
    avance: 'Avance (%)',
    meta: 'Meta',
    ejecutado: 'Ejecutado',
    anio: 'Año',
    publicaciones: 'Publicaciones',
    entidades: 'Entidades',
    posicion: 'Posición',
    territorio: 'Territorio',
    _value: 'Valor',
    _metric: 'Serie'
  };

  function etiqueta(campo, respaldo) {
    if (ETIQUETAS[campo]) { return ETIQUETAS[campo]; }
    if (!campo) { return respaldo || ''; }
    return String(campo).charAt(0).toUpperCase() +
      String(campo).slice(1).replace(/_/g, ' ');
  }

  /* ------------------------------------------------------------------ */
  /* Preparación de datos                                               */
  /* ------------------------------------------------------------------ */

  /* Descarta las filas cuya medida principal es 0/null/NaN: una barra de
     altura cero solo ocupa espacio del eje. */
  function filtrarUtiles(datos, medidas) {
    if (!medidas || !medidas.length) { return datos; }
    var m = medidas[0];
    return datos.filter(function (r) {
      var v = r[m];
      if (v === null || v === undefined) { return false; }
      if (typeof v === 'number' && isNaN(v)) { return false; }
      return v !== 0;
    });
  }

  /* Series derivadas que no deben apilarse: doblarían el total. */
  function esDerivada(nombre) {
    return /^(total|pct_|participacion_|cobertura_)/i.test(String(nombre));
  }

  /* Formato ancho → largo: cada medida pasa a ser una fila con _metric. */
  function anchoALargo(datos, dims, medidas) {
    var mantener = (medidas || []).filter(function (m) { return !esDerivada(m); });
    var salida = [];
    datos.forEach(function (r) {
      mantener.forEach(function (m) {
        var fila = { _metric: etiqueta(m), _value: Number(r[m]) || 0 };
        (dims || []).forEach(function (d) { fila[d] = r[d]; });
        salida.push(fila);
      });
    });
    return salida;
  }

  /* ------------------------------------------------------------------ */
  /* Color                                                              */
  /* ------------------------------------------------------------------ */

  /* Mapa estable grupo → color. Esta es la regla de oro: el color debe
     depender del campo de serie, no de la posición de la fila. */
  function colorPorGrupo(datos, campo, paleta) {
    paleta = paleta || PALETA;
    var grupos = [];
    datos.forEach(function (r) {
      var g = r[campo];
      if (grupos.indexOf(g) < 0) { grupos.push(g); }
    });
    var mapa = {};
    grupos.forEach(function (g, i) { mapa[g] = paleta[i % paleta.length]; });
    return function (d) { return mapa[d[campo]] || paleta[0]; };
  }

  /* Color por valor sobre el rango de la medida (mapa de calor). */
  function colorPorValor(datos, campo) {
    var min = Infinity, max = -Infinity;
    datos.forEach(function (r) {
      var v = Number(r[campo]);
      if (!isNaN(v)) {
        if (v < min) { min = v; }
        if (v > max) { max = v; }
      }
    });
    var rango = (max - min) || 1;
    return function (d) {
      var v = Number(d[campo]);
      return isNaN(v) ? CALOR[0] : C.rampa(CALOR, (v - min) / rango);
    };
  }

  /* ------------------------------------------------------------------ */
  /* Render                                                             */
  /* ------------------------------------------------------------------ */

  function llamar(viz, metodo, arg) {
    if (viz && typeof viz[metodo] === 'function') { viz[metodo](arg); }
    return viz;
  }

  /* Configuración de un eje con su título y su tinta.
     No se fija `fontFamily`: D3plus mide el texto con su propia fuente
     para ajustarlo dentro de cada forma, y pisarla rompe el ajuste. */
  function configEje(titulo, tinta) {
    return {
      title: titulo,
      titleConfig: { fontColor: tinta.titulo },
      shapeConfig: {
        labelConfig: { fontColor: tinta.etiqueta },
        stroke: tinta.rejilla
      },
      gridConfig: { stroke: tinta.rejilla }
    };
  }

  function render(nodo, payload, opts) {
    opts = opts || {};
    var datos = (payload && payload.data) || [];
    if (nodo && !datos.length) { return vacio(nodo, payload); }
    try {
      if (!window.d3plus) { throw new Error('D3plus no está disponible.'); }
      return renderD3plus(nodo, payload, opts);
    } catch (e) {
      // El respaldo tampoco puede tumbar a quien llamó: si falla, se cae
      // al estado «sin datos», que no depende de nada más.
      try {
        return respaldoSVG(nodo, payload);
      } catch (e2) {
        return vacio(nodo, payload);
      }
    }
  }

  function renderD3plus(nodo, payload, opts) {
    var chart = payload.chart || {};
    var vista = payload.view || {};
    var datos = payload.data || [];
    var dims = vista.dimensions || [];
    var medidas = vista.measures || [];
    var clave = chart.key;

    var Clase = window.d3plus[chart.class];
    if (typeof Clase !== 'function') {
      throw new Error('Clase d3plus desconocida: ' + chart.class);
    }
    if (nodo) { nodo.innerHTML = ''; }

    /* 1) Datos y resolución de campos (eje X, eje Y y campo de serie). */
    var base = filtrarUtiles(datos, medidas);
    var plot = base;
    var grupo = dims[0];
    var campoX = dims[0];
    var campoY = medidas[0];

    if (clave === 'stacked_bar' || clave === 'stacked_area') {
      plot = anchoALargo(base, dims, medidas);
      grupo = '_metric';
      campoY = '_value';
    } else if (clave === 'line' || clave === 'area') {
      if (dims.length > 1 && dims[1]) {
        grupo = dims[1];
      } else {
        /* Con una sola dimensión, TODOS los puntos forman UNA serie. Si se
           agrupara por X, cada punto sería su propio grupo y la línea no
           llegaría a dibujarse. */
        grupo = '_serie';
        var nombreSerie = vista.name || etiqueta(campoY);
        plot = base.map(function (r) {
          var o = {};
          for (var k in r) {
            if (Object.prototype.hasOwnProperty.call(r, k)) { o[k] = r[k]; }
          }
          o._serie = nombreSerie;
          return o;
        });
      }
    } else if (dims.length > 1 && dims[1] && clave === 'bar') {
      /* Vista comparativa en formato largo (p. ej. prevalencia por
         subregión con dos indicadores): la serie es la segunda dimensión. */
      grupo = dims[1];
    }

    /* 2) Instancia y configuración común. */
    var viz = new Clase().select(nodo).data(plot);
    llamar(viz, 'detectResize', true);
    /* D3plus trae su propia detección de visibilidad: aplaza el dibujo
       hasta que el contenedor entra en el viewport y lo comprueba por
       sondeo periódico. En una página con varios gráficos eso deja en
       blanco los que quedan bajo el pliegue y los pinta de forma
       impredecible al desplazarse —verificado en navegador: de ocho
       gráficos solo se dibujaba el primero, y tras recorrer la página
       seguían faltando tres—. Se apaga: cada gráfico se dibuja cuando
       llegan sus datos, que es también lo que necesitan la impresión,
       la búsqueda en página y los lectores de pantalla. */
    llamar(viz, 'detectVisible', false);
    llamar(viz, 'legend', opts.legend !== false);
    llamar(viz, 'legendPosition', opts.legendPos || 'bottom');

    /* En las vistas de magnitud el color codifica la cifra: la leyenda de
       categorías sobra y se apaga. No se fija labelConfig.fontFamily:
       d3plus mide el texto con su propia fuente para ajustarlo dentro de
       cada forma, y pisarla lo rompe. */
    var oscuro = 'oscuro' === opts.tema;
    var tinta = oscuro ? TINTAS.oscuro : TINTAS.claro;

    var esMagnitud = ['bar', 'treemap', 'box_whisker'].indexOf(clave) >= 0;
    var unaSerie = grupo === dims[0];
    if (vista.heatmap && esMagnitud && unaSerie) {
      llamar(viz, 'color', colorPorValor(plot, campoY));
      llamar(viz, 'legend', false);
    } else {
      llamar(viz, 'color', colorPorGrupo(plot, grupo, oscuro ? PALETA_OSCURA : PALETA));
    }
    if (opts.reducirMovimiento) { llamar(viz, 'duration', 0); }

    /* 3) Configuración por tipo. */
    switch (clave) {
      case 'bar':
        viz.groupBy(grupo).x(campoX).y(campoY);
        llamar(viz, 'discrete', 'x');
        break;
      case 'stacked_bar':
        viz.groupBy(['_metric', dims[0]]).x(dims[0]).y('_value');
        llamar(viz, 'stacked', true);
        llamar(viz, 'discrete', 'x');
        break;
      case 'line':
      case 'area':
        viz.groupBy(grupo).x(campoX).y(campoY);
        break;
      case 'stacked_area':
        viz.groupBy('_metric').x(dims[0]).y('_value');
        break;
      case 'pie':
      case 'donut':
        viz.groupBy(dims[0]).value(medidas[0]);
        break;
      case 'treemap':
        viz.groupBy([dims[0]]).sum(medidas[0]);
        break;
      case 'box_whisker':
        viz.groupBy(dims[0]).value(medidas[0]);
        break;
      default:
        viz.groupBy(grupo).x(campoX).y(campoY);
    }

    /* 4) Ejes con título y tooltip enriquecido. */
    var dimX = dims[0];
    var cartesiano = ['bar', 'stacked_bar', 'line', 'area', 'stacked_area', 'box_whisker'].indexOf(clave) >= 0;

    function cuerpoTooltip() {
      var t = [];
      if (dimX) {
        t.push([etiqueta(dimX), function (r) { return r[dimX] != null ? String(r[dimX]) : ''; }]);
      }
      if (grupo && grupo !== dimX && grupo !== '_metric' && grupo !== '_serie') {
        t.push([etiqueta(grupo), function (r) { return r[grupo] != null ? String(r[grupo]) : ''; }]);
      }
      if (grupo === '_metric') {
        t.push(['Serie', function (r) { return r._metric != null ? String(r._metric) : ''; }]);
      }
      var ms = (campoY === '_value') ? ['_value'] : medidas;
      ms.forEach(function (m) {
        t.push([etiqueta(m), function (r) { return C.num(r[m]); }]);
      });
      /* Campos de contexto que enriquecen la lectura sin ser medidas. */
      ['porcentaje', 'personas', 'territorio', 'posicion', 'meta', 'ejecutado'].forEach(function (extra) {
        if (ms.indexOf(extra) >= 0) { return; }
        if (plot.length && plot[0] && plot[0][extra] !== undefined) {
          t.push([etiqueta(extra), function (r) {
            return typeof r[extra] === 'number' ? C.num(r[extra]) : String(r[extra] == null ? '' : r[extra]);
          }]);
        }
      });
      return t;
    }

    if (cartesiano) {
      /* Los ejes se tiñen explícitamente. D3plus los pinta en tonos
         pensados para fondo claro, y sobre el fondo oscuro del objeto 3D
         las etiquetas quedarían ilegibles. */
      llamar(viz, 'xConfig', configEje( etiqueta(dims[0]), tinta ));
      llamar(viz, 'yConfig', configEje( etiqueta(campoY === '_value' ? '_value' : campoY), tinta ));
    }
    var cfgLeyenda = { shapeConfig: { labelConfig: { fontColor: tinta.leyenda } } };
    if ('icons' === opts.legendStyle) { cfgLeyenda.label = false; }
    llamar(viz, 'legendConfig', cfgLeyenda);
    llamar(viz, 'tooltipConfig', {
      title: function (d) {
        var v = cartesiano ? (d[grupo] != null ? d[grupo] : d[dimX]) : d[dims[0]];
        return String(v == null ? '' : v);
      },
      tbody: cuerpoTooltip()
    });

    viz.render();
    return viz;
  }

  /* ------------------------------------------------------------------ */
  /* Estados degradados                                                 */
  /* ------------------------------------------------------------------ */

  function vacio(nodo, payload) {
    if (!nodo) { return null; }
    nodo.innerHTML = '';
    var v = (payload && payload.view) || {};
    var p = C.el('p', 'uhp-g__vacio',
      'Aún no hay datos para «' + (v.name || 'esta vista') +
      '». Revise el archivo de origen en URKUNINA 5000 → Datos.');
    nodo.appendChild(p);
    return null;
  }

  /* Gráfico mínimo en SVG por si d3plus no carga (CDN bloqueada por una
     CSP estricta, por ejemplo). Mejor una lectura sencilla que un hueco. */
  var SVGNS = 'http://www.w3.org/2000/svg';
  function svgEl(nombre, attrs) {
    var e = document.createElementNS(SVGNS, nombre);
    for (var k in attrs) {
      if (Object.prototype.hasOwnProperty.call(attrs, k)) { e.setAttribute(k, attrs[k]); }
    }
    return e;
  }

  function respaldoSVG(nodo, payload) {
    if (!nodo) { return null; }
    var vista = (payload && payload.view) || {};
    var chart = (payload && payload.chart) || {};
    var datos = (payload && payload.data) || [];
    var dim = (vista.dimensions || [])[0];
    var med = (vista.measures || [])[0];
    if (!datos.length || !med) { return vacio(nodo, payload); }
    nodo.innerHTML = '';

    var esLinea = ['line', 'area', 'stacked_area'].indexOf(chart.key) >= 0;
    var W = 720, H = 340, m = { t: 16, r: 16, b: 46, l: 52 };
    var iw = W - m.l - m.r, ih = H - m.t - m.b;

    var svg = svgEl('svg', {
      viewBox: '0 0 ' + W + ' ' + H,
      'class': 'uhp-g__svg',
      preserveAspectRatio: 'xMidYMid meet',
      role: 'img',
      'aria-label': vista.name || 'Gráfico'
    });

    var vals = datos.map(function (r) { return Number(r[med]) || 0; });
    var maxv = Math.max.apply(null, vals.concat([0]));
    var minv = Math.min.apply(null, vals.concat([0]));
    if (maxv === minv) { maxv = minv + 1; }
    function yy(v) { return m.t + ih - ((v - minv) / (maxv - minv)) * ih; }

    var base = (minv <= 0 && maxv >= 0) ? 0 : minv;
    svg.appendChild(svgEl('line', {
      x1: m.l, y1: yy(base), x2: m.l + iw, y2: yy(base),
      stroke: '#d7dee6', 'stroke-width': 1
    }));

    var n = datos.length, color = PALETA[0];
    function px(i) {
      return esLinea
        ? (m.l + (n > 1 ? (i / (n - 1)) * iw : iw / 2))
        : (m.l + (iw / n) * (i + 0.5));
    }

    if (esLinea) {
      var pts = datos.map(function (r, i) { return px(i) + ',' + yy(Number(r[med]) || 0); });
      svg.appendChild(svgEl('polyline', {
        points: pts.join(' '), fill: 'none', stroke: color,
        'stroke-width': 2.4, 'stroke-linejoin': 'round', 'stroke-linecap': 'round'
      }));
      datos.forEach(function (r, i) {
        var c = svgEl('circle', { cx: px(i), cy: yy(Number(r[med]) || 0), r: 2.8, fill: color });
        var ttl = svgEl('title');
        ttl.textContent = (r[dim] != null ? r[dim] + ': ' : '') + C.num(r[med]);
        c.appendChild(ttl);
        svg.appendChild(c);
      });
    } else {
      var bw = (iw / n) * 0.7;
      datos.forEach(function (r, i) {
        var v = Number(r[med]) || 0;
        var x0 = m.l + (iw / n) * (i + 0.15);
        var ya = yy(Math.max(0, v)), yb = yy(Math.min(0, v));
        var rect = svgEl('rect', {
          x: x0, y: Math.min(ya, yb), width: bw,
          height: Math.max(1, Math.abs(yb - ya)), fill: color, rx: 2
        });
        var ttl = svgEl('title');
        ttl.textContent = (r[dim] != null ? r[dim] + ': ' : '') + C.num(v);
        rect.appendChild(ttl);
        svg.appendChild(rect);
      });
    }

    var paso = Math.max(1, Math.ceil(n / 8));
    datos.forEach(function (r, i) {
      if (i % paso !== 0) { return; }
      var t = svgEl('text', {
        x: px(i), y: H - 26, 'text-anchor': 'middle', 'font-size': 10, fill: '#5B6773'
      });
      t.textContent = String(r[dim] != null ? r[dim] : '');
      svg.appendChild(t);
    });

    var nota = svgEl('text', { x: m.l, y: H - 8, 'font-size': 10, fill: '#8894a0' });
    nota.textContent = (vista.name || '') + ' · vista simple (D3plus no disponible)';
    svg.appendChild(nota);

    nodo.appendChild(svg);
    return null;
  }

  window.UHPRenderer = {
    render: render,
    PALETA: PALETA,
    CALOR: CALOR,
    etiqueta: etiqueta
  };
})();
