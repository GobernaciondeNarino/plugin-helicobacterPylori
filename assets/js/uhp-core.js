/* Núcleo compartido por los componentes del front.
   Helpers de fetch a la REST interna, esqueletos de carga, errores con
   reintento, escapado y formato en convención colombiana.
   Expone window.UHPcore. */
(function () {
  'use strict';

  var CFG = window.UHP || { rest: '', pluginUrl: '', locale: 'es-CO' };

  /** Milisegundos antes de abortar una petición que no responde. */
  var TIMEOUT = 15000;

  /* ------------------------------------------------------------------ */
  /* Red                                                                */
  /* ------------------------------------------------------------------ */

  /** fetch con límite de tiempo: sin él, un backend colgado deja el
      esqueleto girando y los reintentos apilan peticiones vivas. */
  function pedir(url, ms) {
    var limite = ms || TIMEOUT;
    if (typeof AbortController === 'undefined') {
      return fetch(url).then(comprobar);
    }
    var ctl = new AbortController();
    var reloj = setTimeout(function () { ctl.abort(); }, limite);
    return fetch(url, { signal: ctl.signal, credentials: 'same-origin' })
      .then(function (r) { clearTimeout(reloj); return comprobar(r); })
      .catch(function (e) {
        clearTimeout(reloj);
        if (e && e.name === 'AbortError') {
          throw new Error('La fuente de datos tardó demasiado en responder.');
        }
        throw e;
      });
  }

  function comprobar(r) {
    if (!r.ok) { throw new Error('HTTP ' + r.status); }
    return r.json();
  }

  /** GET a la REST interna del plugin.
      No se envía X-WP-Nonce a propósito: todos los endpoints son públicos
      de solo lectura y un nonce caducado servido desde la caché de página
      devolvería 403 a los visitantes. */
  function rest(ruta, params) {
    var url = CFG.rest + ruta;
    if (params) {
      var q = Object.keys(params)
        .filter(function (k) {
          return params[k] !== undefined && params[k] !== null && params[k] !== '';
        })
        .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); })
        .join('&');
      if (q) { url += (url.indexOf('?') >= 0 ? '&' : '?') + q; }
    }
    return pedir(url);
  }

  /** Memoriza en sesión el resultado de una ruta REST.
      El GeoJSON pesa cientos de kilobytes y varios componentes del tablero
      lo necesitan: pedirlo una sola vez evita descargarlo por duplicado. */
  var memo = {};
  function restCache(ruta, params) {
    var clave = ruta + '|' + JSON.stringify(params || {});
    if (!memo[clave]) {
      memo[clave] = rest(ruta, params).catch(function (e) {
        delete memo[clave];
        throw e;
      });
    }
    return memo[clave];
  }

  /* ------------------------------------------------------------------ */
  /* DOM                                                                */
  /* ------------------------------------------------------------------ */

  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  /** Crea un elemento con clase y texto. El texto va por textContent, de
      modo que nada de lo que devuelva la REST puede inyectar markup. */
  function el(tag, clase, texto) {
    var n = document.createElement(tag);
    if (clase) { n.className = clase; }
    if (texto !== undefined && texto !== null) { n.textContent = String(texto); }
    return n;
  }

  /** Escapa una cadena para insertarla en HTML.
      Solo se usa donde hace falta componer markup; la vía preferente es
      textContent. */
  function esc(s) {
    return String(s === undefined || s === null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  /** Esqueleto de carga accesible. */
  function skeleton(mensaje) {
    var d = el('div', 'uhp-skeleton');
    d.setAttribute('role', 'status');
    d.setAttribute('aria-live', 'polite');
    d.appendChild(el('span', 'uhp-skeleton__giro'));
    d.appendChild(el('span', 'uhp-skeleton__txt', mensaje || 'Cargando…'));
    return d;
  }

  /** Estado de error con botón de reintento. */
  function error(nodo, mensaje, reintentar) {
    if (!nodo) { return; }
    nodo.innerHTML = '';
    var caja = el('div', 'uhp-error');
    caja.setAttribute('role', 'alert');
    caja.appendChild(el('p', 'uhp-error__txt', mensaje || 'No se pudieron cargar los datos.'));
    if (typeof reintentar === 'function') {
      var b = el('button', 'uhp-error__btn', 'Reintentar');
      b.type = 'button';
      b.addEventListener('click', function () {
        nodo.innerHTML = '';
        nodo.appendChild(skeleton('Reintentando…'));
        reintentar();
      });
      caja.appendChild(b);
    }
    nodo.appendChild(caja);
  }

  /** Quita el esqueleto de un contenedor si lo tiene. */
  function quitarSkeleton(nodo) {
    if (!nodo) { return; }
    var s = nodo.querySelector('.uhp-skeleton');
    if (s && s.parentNode) { s.parentNode.removeChild(s); }
  }

  /* ------------------------------------------------------------------ */
  /* Formato                                                            */
  /* ------------------------------------------------------------------ */

  /** Número en convención colombiana (punto de miles, coma decimal). */
  function num(v, decimales) {
    if (v === null || v === undefined || v === '') { return ''; }
    var n = Number(v);
    if (isNaN(n)) { return String(v); }
    var d = (decimales === undefined)
      ? (Math.abs(n - Math.round(n)) < 0.001 ? 0 : (Math.abs(n) < 10 ? 2 : 1))
      : decimales;
    try {
      return n.toLocaleString(CFG.locale || 'es-CO', {
        minimumFractionDigits: d,
        maximumFractionDigits: d
      });
    } catch (e) {
      return n.toFixed(d);
    }
  }

  function pct(v, decimales) {
    return num(v, decimales === undefined ? 1 : decimales) + ' %';
  }

  /** Formatea un valor según el tipo declarado por el backend. */
  function formato(v, tipo) {
    if (tipo === 'porcentaje') { return pct(v); }
    if (tipo === 'entero') { return num(v, 0); }
    return num(v);
  }

  /* ------------------------------------------------------------------ */
  /* Color                                                              */
  /* ------------------------------------------------------------------ */

  /** Interpola una rampa de colores hex y devuelve rgb(). */
  function rampa(colores, t) {
    if (!colores || !colores.length) { return '#cccccc'; }
    if (colores.length === 1) { return colores[0]; }
    t = Math.max(0, Math.min(1, Number(t) || 0));
    var n = colores.length - 1;
    var i = Math.min(n - 1, Math.floor(t * n));
    return mezclar(colores[i], colores[i + 1], (t * n) - i);
  }

  function mezclar(c1, c2, t) {
    var a = hex(c1), b = hex(c2);
    return 'rgb(' +
      Math.round(a[0] + (b[0] - a[0]) * t) + ',' +
      Math.round(a[1] + (b[1] - a[1]) * t) + ',' +
      Math.round(a[2] + (b[2] - a[2]) * t) + ')';
  }

  function hex(c) {
    var s = String(c).replace('#', '');
    if (s.length === 3) { s = s[0] + s[0] + s[1] + s[1] + s[2] + s[2]; }
    return [
      parseInt(s.slice(0, 2), 16) || 0,
      parseInt(s.slice(2, 4), 16) || 0,
      parseInt(s.slice(4, 6), 16) || 0
    ];
  }

  window.UHPcore = {
    cfg: CFG,
    rest: rest,
    restCache: restCache,
    externo: pedir,
    ready: ready,
    el: el,
    esc: esc,
    skeleton: skeleton,
    quitarSkeleton: quitarSkeleton,
    error: error,
    num: num,
    pct: pct,
    formato: formato,
    rampa: rampa
  };
})();
