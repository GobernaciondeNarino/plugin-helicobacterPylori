/* Asistente de voz del tablero — [urkunina_dashboard].

   El visitante pulsa, concede el micrófono y pregunta en voz alta por las
   cifras del proyecto. El agente de ElevenLabs responde y, cuando se lo
   piden, mueve los filtros del tablero.

   CUATRO REGLAS QUE SOSTIENEN ESTE ARCHIVO:

     · NADA DEL MODELO ENTRA COMO HTML. Todo lo que dice el agente se
       pinta con textContent o con nodos creados a mano. Un modelo que
       devuelve «<img onerror=…>» tiene que verse como ese texto y no
       ejecutarse. Es la misma regla que ya cumplen el tablero y el mapa.

     · EL ASISTENTE NO PUEDE HACER NADA QUE EL TECLADO NO HAGA. Habla con
       el tablero por window.UHPTablero.mando(), que es la misma puerta
       que usan los clics. Si apareciera aquí una capacidad que no está en
       la pantalla, sería una función escondida detrás de un micrófono.

     · EL MICRÓFONO NO SE ENCIENDE SIN AUTORIZACIÓN INFORMADA. La compuerta
       con el aviso de habeas data es obligatoria y previa; el aviso lo
       imprime PHP y viaja en el HTML, no lo compone este archivo.

     · NO SE SELECCIONA UN MUNICIPIO POR PARECIDO. Albán y Aldana, Iles e
       Imués, Linares y Leiva están a dos cambios de distancia y los seis
       son municipios intervenidos. Equivocarse significa leer en voz alta
       la cifra de cáncer gástrico de otro pueblo, de modo que lo que no
       cruza exacto se pregunta y lo confirma una pulsación.          */
(function () {
  'use strict';

  var C = window.UHPcore;
  var DATOS = window.UHPVoz || null;
  var T = (DATOS && DATOS.textos) || {};
  var CFG = (DATOS && DATOS.cfg) || null;

  /* Presupuesto de cambios. Las cinco herramientas que mueven el tablero
     no esperan respuesta, de modo que el agente puede encadenarlas sin
     frenar. Cien seguidas repintan la lista de 55 municipios cien veces y
     dejan al lector de pantalla leyendo un anuncio por segundo. */
  var MUT_MINIMO_MS = 700;
  var MUT_POR_MINUTO = 20;

  if (!C || !DATOS || !CFG) { return; }

  C.ready(function () {
    var nodos = document.querySelectorAll('[data-uhp-voz]');
    for (var i = 0; i < nodos.length; i++) { iniciar(nodos[i]); }
  });

  /* ------------------------------------------------------------------ */
  /* Arranque                                                           */
  /* ------------------------------------------------------------------ */

  function iniciar(raiz) {
    var v = {
      raiz: raiz,
      nodos: zonas(raiz),
      tablero: raiz.closest ? raiz.closest('[data-uhp-tablero]') : null,
      mando: null,
      conv: null,
      fase: 'inactivo',
      hilo: [],
      mutaciones: [],
      sugerido: null,
      confirmado: null,
      desuscribir: null,
      temporizadorCtx: 0,
      focoPrevio: null
    };

    var abrir = raiz.querySelector('[data-uhp-voz-accion="abrir"]');
    if (abrir) {
      abrir.addEventListener('click', function () {
        if (v.fase === 'activa' || v.fase === 'conectando') { cerrarPanel(v); return; }
        comenzar(v);
      });
    }

    // Una pestaña que se oculta o se cierra con la conversación abierta
    // dejaría el micrófono encendido y el punto rojo del navegador puesto.
    window.addEventListener('pagehide', function () { terminar(v, 'pagehide'); });

    raiz.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && v.fase !== 'inactivo') {
        e.stopPropagation();
        terminar(v, 'escape');
        cerrarPanel(v);
      }
    });
  }

  function zonas(raiz) {
    var n = {};
    var nodos = raiz.querySelectorAll('[data-uhp-voz-zona]');
    for (var i = 0; i < nodos.length; i++) {
      n[nodos[i].getAttribute('data-uhp-voz-zona')] = nodos[i];
    }
    return n;
  }

  /* El mando del tablero puede no existir todavía —el tablero pide sus
     datos por red— o no existir nunca, si el tablero falló. El asistente
     sigue sirviendo para preguntar; lo que no podrá es mover filtros. */
  function mandoDe(v) {
    if (!v.mando && window.UHPTablero && window.UHPTablero.mando) {
      v.mando = window.UHPTablero.mando(v.tablero);
    }
    return v.mando;
  }

  /* ------------------------------------------------------------------ */
  /* Compuerta de consentimiento                                        */
  /* ------------------------------------------------------------------ */

  function claveConsentimiento() {
    return 'uhp_voz_consentimiento_v' + CFG.avisoVersion;
  }

  /* En sessionStorage y no en localStorage: la autorización vale para la
     visita, no para siempre. Y entre try/catch porque en una ventana
     privada el solo hecho de leerlo lanza. */
  function consentido() {
    if (!CFG.recordar) { return false; }
    try {
      return window.sessionStorage.getItem(claveConsentimiento()) === '1';
    } catch (e) { return false; }
  }

  function guardarConsentimiento(si) {
    if (!CFG.recordar) { return; }
    try {
      if (si) { window.sessionStorage.setItem(claveConsentimiento(), '1'); }
      else { window.sessionStorage.removeItem(claveConsentimiento()); }
    } catch (e) { /* sin almacenamiento, la autorización dura la sesión */ }
  }

  function comenzar(v) {
    abrirPanel(v);
    if (consentido()) { arrancarSesion(v); return; }
    pintarCompuerta(v);
  }

  function pintarCompuerta(v) {
    var panel = v.nodos.panel;
    if (!panel) { return; }
    panel.innerHTML = '';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');
    panel.setAttribute('aria-label', T.titulo || 'Asistente de voz');

    panel.appendChild(cabecera(v, T.titulo));

    var aviso = C.el('div', 'uhp-voz__aviso');
    aviso.setAttribute('tabindex', '0');
    var plantilla = v.nodos.aviso;
    if (plantilla && plantilla.content) {
      aviso.appendChild(plantilla.content.cloneNode(true));
    }
    panel.appendChild(aviso);

    var pie = C.el('div', 'uhp-voz__pie');
    var si = boton(T.aceptar, 'uhp-voz__btn uhp-voz__btn--principal', function () {
      guardarConsentimiento(true);
      arrancarSesion(v);
    });
    var no = boton(T.cancelar, 'uhp-voz__btn', function () { cerrarPanel(v); });
    pie.appendChild(si);
    pie.appendChild(no);
    panel.appendChild(pie);

    atraparFoco(v, panel);
    si.focus();
  }

  /* ------------------------------------------------------------------ */
  /* Sesión                                                             */
  /* ------------------------------------------------------------------ */

  function arrancarSesion(v) {
    fase(v, 'conectando');
    pintarConversacion(v);

    cargarSdk()
      .then(function () { return credenciales(); })
      .then(function (cred) { return abrir(v, cred); })
      .catch(function (err) { fallar(v, err); });
  }

  /* El SDK pesa más de un megabyte: se baja al pulsar y no al abrir la
     página. Quien no use el asistente no paga por él. */
  var sdkPrometido = null;
  function cargarSdk() {
    if (window.ElevenLabsClient) { return Promise.resolve(); }
    if (sdkPrometido) { return sdkPrometido; }

    sdkPrometido = new Promise(function (resolver, rechazar) {
      var s = document.createElement('script');
      s.src = CFG.sdk.sdk;
      s.async = true;
      s.crossOrigin = 'anonymous';
      s.onload = function () {
        if (window.ElevenLabsClient) { resolver(); }
        else { rechazar(new Error('sin-sdk')); }
      };
      s.onerror = function () { sdkPrometido = null; rechazar(new Error('sin-sdk')); };
      document.head.appendChild(s);
    });
    return sdkPrometido;
  }

  /* Con un agente privado la URL firmada la pide el servidor, que es el
     único que tiene la clave. Con uno público basta el identificador: el
     propio SDK se saca su credencial. */
  function credenciales() {
    if (CFG.modo !== 'privado') {
      return Promise.resolve({ agentId: CFG.agente, connectionType: CFG.transporte });
    }
    return fetch(window.UHP.rest + CFG.ruta, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' }
    }).then(function (r) {
      if (!r.ok) { throw new Error(r.status === 503 ? 'sin-cuota' : 'sin-sesion'); }
      return r.json();
    }).then(function (d) {
      if (!d || !d.url) { throw new Error('sin-sesion'); }
      return { signedUrl: d.url, connectionType: 'websocket' };
    });
  }

  function abrir(v, cred) {
    var mando = mandoDe(v);
    var opciones = {
      clientTools: herramientas(v),
      onConnect: function () { fase(v, 'escucha'); anunciar(v, T.escuchando); },
      onDisconnect: function () { if (v.fase !== 'inactivo') { fase(v, 'cerrada'); anunciar(v, T.cerrada); } },
      onError: function (e) { fallar(v, e instanceof Error ? e : new Error('sin-sesion')); },
      onModeChange: function (m) {
        if (v.fase === 'cerrada' || v.fase === 'error') { return; }
        fase(v, m && m.mode === 'speaking' ? 'habla' : 'escucha');
      },
      onMessage: function (m) {
        if (!m || !m.message) { return; }
        turno(v, m.source === 'user' ? 'usted' : 'asistente', String(m.message));
      }
    };
    if (mando && mando.disponible()) {
      // Lo que ya está en pantalla cuando empieza la conversación. Sin
      // esto, la primera pregunta «¿qué estoy viendo?» obliga a una
      // llamada a herramienta antes de poder responder.
      opciones.dynamicVariables = variables(mando.estado());
    }
    for (var k in cred) { if (Object.prototype.hasOwnProperty.call(cred, k)) { opciones[k] = cred[k]; } }

    return window.ElevenLabsClient.Conversation.startSession(opciones).then(function (conv) {
      v.conv = conv;
      fase(v, 'escucha');
      pintarConversacion(v);
      escucharTablero(v);
    });
  }

  function variables(e) {
    return {
      indicador: e.indicadorEtiqueta,
      zona: e.zonaEtiqueta || 'todas',
      subregion: e.subregion || 'todas',
      municipio: e.municipio || 'ninguno',
      municipios_visibles: String(e.visibles),
      casos_visibles: String(e.casos),
      promedio_visible: e.promedio === null ? 'sin dato' : String(e.promedio)
    };
  }

  /* Cuando el visitante mueve el tablero con el ratón, el agente tiene
     que enterarse o hablará de una pantalla que ya no existe. */
  function escucharTablero(v) {
    var mando = mandoDe(v);
    if (!mando) { return; }
    v.desuscribir = mando.alCambiar(function (estado, origen) {
      if (origen === 'agente' || !v.conv) { return; }
      window.clearTimeout(v.temporizadorCtx);
      v.temporizadorCtx = window.setTimeout(function () {
        if (!v.conv) { return; }
        try {
          v.conv.sendContextualUpdate(
            'El visitante cambió el tablero: ' + resumenHablado(estado)
          );
        } catch (e) { /* la sesión pudo cerrarse entre medias */ }
      }, 800);
    });
  }

  function terminar(v, motivo) {
    window.clearTimeout(v.temporizadorCtx);
    if (v.desuscribir) { v.desuscribir(); v.desuscribir = null; }
    if (v.conv) {
      try { v.conv.endSession(); } catch (e) { /* ya estaba cerrada */ }
      v.conv = null;
    }
    if (motivo !== 'pagehide') {
      fase(v, 'cerrada');
      if (v.raiz.isConnected) { pintarConversacion(v); }
    }
  }

  function fallar(v, err) {
    var clave = (err && err.message) || '';
    var mensaje = T.sin_sesion;
    if (clave === 'sin-sdk') { mensaje = T.sin_sdk; }
    else if (clave === 'sin-cuota') { mensaje = T.sin_cuota; }
    else if (/notallowed|permission/i.test(clave)) { mensaje = T.sin_micro; }
    else if (/notfound|devices/i.test(clave)) { mensaje = T.sin_micro_no; }
    else if (/notreadable|inuse/i.test(clave)) { mensaje = T.ocupado; }
    else if (!window.isSecureContext) { mensaje = T.sin_seguro; }

    fase(v, 'error');
    v.error = mensaje;
    anunciar(v, mensaje);
    pintarConversacion(v);
    if (window.console && console.warn) {
      console.warn('[URKUNINA 5000] asistente de voz: ' + clave);
    }
  }

  /* ------------------------------------------------------------------ */
  /* Herramientas que el agente puede llamar                            */
  /* ------------------------------------------------------------------ */

  function herramientas(v) {
    return {
      uhp_cambiar_indicador: function (p) {
        return mutar(v, function (m) {
          var val = (p && p.indicador) === 'hp' ? 'hp' : 'lpm';
          if (!m.setIndicador(val)) { return 'No pude cambiar el indicador.'; }
          var e = m.estado();
          return 'Indicador cambiado a ' + e.indicadorEtiqueta + '. ' +
            'Promedio de la selección: ' + cifra(e.promedio) + '.';
        });
      },

      uhp_filtrar_zona_riesgo: function (p) {
        return mutar(v, function (m) {
          var z = p && p.zona;
          var destino = (!z || z === 'todas') ? null : String(z).toLowerCase();
          if (destino && ['roja', 'amarilla', 'verde'].indexOf(destino) < 0) {
            return 'No conozco esa zona. Las zonas son roja, amarilla y verde.';
          }
          if (!m.setZona(destino)) { return 'No pude aplicar ese filtro.'; }
          var e = m.estado();
          return destino
            ? (e.zonaEtiqueta + ': ' + e.visibles + ' municipios de los 55 intervenidos.')
            : 'Filtro de zona retirado: los 55 municipios priorizados.';
        });
      },

      uhp_filtrar_subregion: function (p) {
        return mutar(v, function (m) {
          var texto = p && p.subregion;
          if (!texto || String(texto).toLowerCase() === 'todas') {
            m.setSubregion(null);
            return 'Filtro de subregión retirado: ' + m.estado().visibles + ' municipios.';
          }
          var nombre = cruzarSubregion(m, texto);
          if (!nombre) {
            return 'No encuentro esa subregión. Las documentadas son: ' +
              m.catalogo().subregiones.join(', ') + '.';
          }
          if (!m.setSubregion(nombre)) { return 'No pude aplicar ese filtro.'; }
          var e = m.estado();
          return 'Subregión ' + nombre + ': ' + e.visibles + ' municipios. ' +
            e.indicadorEtiqueta + ' promedio ' + cifra(e.promedio) + '.';
        });
      },

      uhp_seleccionar_municipio: function (p) {
        return mutar(v, function (m) {
          var r = m.resolver(p && p.municipio);

          if (r.estado === 'fuera') {
            return r.municipio.n + ' no entró en el estudio: el proyecto intervino ' +
              '55 de los 64 municipios de Nariño y allí no hubo tamizaje. ' +
              'No falta el dato, faltó el estudio.';
          }
          if (r.estado === 'sugerencia') {
            // NO se selecciona. Se pregunta y lo confirma una pulsación.
            v.sugerido = r.sugerencia;
            pedirConfirmacion(v, r.sugerencia);
            return 'No encontré ese municipio. ¿Quiso decir ' + r.sugerencia +
              '? Le he puesto un botón en pantalla para confirmarlo.';
          }
          if (r.estado !== 'exacto') {
            return 'No encuentro ese municipio entre los 55 que intervino el proyecto.';
          }
          // Lo que llegó por sugerencia necesita la pulsación aunque ahora
          // cruce exacto: si no, el modelo se autoconfirma en el turno
          // siguiente y la salvaguarda no sirve de nada.
          if (v.sugerido === r.municipio.n && v.confirmado !== r.municipio.n) {
            pedirConfirmacion(v, r.municipio.n);
            return 'Necesito que confirme ' + r.municipio.n + ' con el botón de la pantalla.';
          }
          if (!m.setMunicipio(r.municipio.n)) { return 'No pude seleccionarlo.'; }
          v.sugerido = null;
          return describir(r.municipio, r.vecino);
        });
      },

      uhp_limpiar_tablero: function () {
        return mutar(v, function (m) {
          m.limpiar();
          return 'Filtros quitados: los 55 municipios priorizados a la vista.';
        });
      },

      /* Las tres que siguen SOLO LEEN. No gastan presupuesto de cambios y
         no mueven la pantalla delante de quien está leyendo. */

      uhp_leer_estado_tablero: function () {
        var m = mandoDe(v);
        if (!m || !m.disponible()) { return 'El tablero todavía no terminó de cargar.'; }
        return resumenHablado(m.estado());
      },

      uhp_consultar_municipio: function (p) {
        var m = mandoDe(v);
        if (!m || !m.disponible()) { return 'El tablero todavía no terminó de cargar.'; }
        var r = m.resolver(p && p.municipio);
        if (r.estado === 'fuera') {
          return r.municipio.n + ' no entró en el estudio: allí no hubo tamizaje.';
        }
        if (r.estado === 'sugerencia') {
          return 'No encontré ese municipio. ¿Quiso decir ' + r.sugerencia + '?';
        }
        if (r.estado !== 'exacto') { return 'No encuentro ese municipio entre los 55.'; }
        return describir(r.municipio, r.vecino);
      },

      uhp_listar_municipios: function (p) {
        var m = mandoDe(v);
        if (!m || !m.disponible()) { return 'El tablero todavía no terminó de cargar.'; }
        var filtro = {};
        if (p && p.zona) { filtro.zona = String(p.zona).toLowerCase(); }
        if (p && p.subregion) { filtro.subregion = cruzarSubregion(m, p.subregion); }
        var lista = m.listar(filtro);
        if (!lista.length) { return 'No hay municipios con ese filtro.'; }
        return lista.length + ' municipios: ' + lista.join(', ') + '.';
      }
    };
  }

  /* Aplica un cambio al tablero respetando el presupuesto. */
  function mutar(v, fn) {
    var m = mandoDe(v);
    if (!m || !m.disponible()) { return 'El tablero todavía no terminó de cargar.'; }

    var ahora = Date.now();
    v.mutaciones = v.mutaciones.filter(function (t) { return ahora - t < 60000; });
    var ultima = v.mutaciones.length ? v.mutaciones[v.mutaciones.length - 1] : 0;
    if (v.mutaciones.length >= MUT_POR_MINUTO || (ahora - ultima) < MUT_MINIMO_MS) {
      return T.demasiado;
    }
    v.mutaciones.push(ahora);

    try {
      return fn(m);
    } catch (e) {
      if (window.console && console.error) { console.error('[URKUNINA 5000] herramienta de voz', e); }
      return 'No pude hacer ese cambio.';
    }
  }

  function describir(f, vecino) {
    var partes = [f.n, 'código DANE ' + f.c, 'subregión ' + f.sub, 'zona ' + f.zonaEtiqueta];
    var txt = partes.join(', ') + '. ';
    txt += 'Lesión precursora ' + valorCon(f.lpm) + '. ';
    txt += 'H. pylori ' + valorCon(f.hp) + '. ';
    txt += f.casos
      ? (f.casos === 1 ? 'Un caso de cáncer gástrico detectado.' : f.casos + ' casos de cáncer gástrico detectados.')
      : 'Ningún caso de cáncer gástrico detectado.';
    if (vecino) {
      txt += ' Aviso: hay otro municipio de nombre parecido, ' + vecino +
        '. Confirme con el visitante cuál quería antes de seguir.';
    }
    return txt;
  }

  function valorCon(d) {
    if (d.valor === null) { return 'sin cifra publicada'; }
    return cifra(d.valor) + (d.origen === 'subregional' ? ' (cifra de la subregión, no del municipio)' : '');
  }

  function cifra(n) {
    return n === null || n === undefined ? 'sin dato' : C.num(n, 1) + ' %';
  }

  function resumenHablado(e) {
    var p = [];
    p.push('indicador: ' + e.indicadorEtiqueta);
    p.push('zona: ' + (e.zonaEtiqueta || 'todas'));
    p.push('subregión: ' + (e.subregion || 'todas'));
    p.push('municipio: ' + (e.municipio || 'ninguno'));
    p.push('municipios a la vista: ' + e.visibles);
    p.push('casos de cáncer en la selección: ' + e.casos);
    p.push('promedio: ' + cifra(e.promedio));
    return p.join('; ') + '.';
  }

  /* El reconocimiento de voz escribirá «la sabana» o «rio mayo». */
  function cruzarSubregion(m, texto) {
    var buscado = normalizar(texto);
    var subs = m.catalogo().subregiones;
    for (var i = 0; i < subs.length; i++) {
      if (normalizar(subs[i]) === buscado) { return subs[i]; }
    }
    return null;
  }

  function normalizar(txt) {
    var t = String(txt == null ? '' : txt).toLowerCase();
    t = t.normalize ? t.normalize('NFD').replace(/[̀-ͯ]/g, '') : t;
    t = t.replace(/^(el|la|los|las)\s+/, '');
    return t.replace(/[^a-z0-9]+/g, '');
  }

  /* ------------------------------------------------------------------ */
  /* Pintado                                                            */
  /* ------------------------------------------------------------------ */

  function abrirPanel(v) {
    var b = v.raiz.querySelector('[data-uhp-voz-accion="abrir"]');
    if (b) { b.setAttribute('aria-expanded', 'true'); }
    if (v.nodos.panel) { v.nodos.panel.hidden = false; }
    v.focoPrevio = document.activeElement;
  }

  function cerrarPanel(v) {
    terminar(v, 'cerrar');
    var b = v.raiz.querySelector('[data-uhp-voz-accion="abrir"]');
    if (b) { b.setAttribute('aria-expanded', 'false'); }
    if (v.nodos.panel) {
      v.nodos.panel.hidden = true;
      v.nodos.panel.innerHTML = '';
      v.nodos.panel.removeAttribute('role');
      v.nodos.panel.removeAttribute('aria-modal');
    }
    fase(v, 'inactivo');
    if (v.focoPrevio && v.focoPrevio.focus) { v.focoPrevio.focus(); }
    else if (b) { b.focus(); }
  }

  function pintarConversacion(v) {
    var panel = v.nodos.panel;
    if (!panel || !panel.isConnected) { return; }
    panel.innerHTML = '';
    panel.removeAttribute('role');
    panel.removeAttribute('aria-modal');

    panel.appendChild(cabecera(v, T.titulo));

    var hilo = C.el('div', 'uhp-voz__hilo');
    // El hilo NO es una región viva: con la conversación abierta llegan
    // fragmentos cada pocas décimas y un lector de pantalla quedaría
    // secuestrado leyendo. Los estados breves van a la región de estado.
    hilo.setAttribute('aria-label', T.transcripcion);
    if (!v.hilo.length) {
      hilo.appendChild(C.el('p', 'uhp-voz__vacio', textoDeFase(v)));
    } else {
      v.hilo.forEach(function (t) { hilo.appendChild(pintarTurno(t)); });
    }
    if (v.confirmaPendiente) { hilo.appendChild(v.confirmaPendiente); }
    panel.appendChild(hilo);

    panel.appendChild(pie(v));

    hilo.scrollTop = hilo.scrollHeight;
  }

  function pintarTurno(t) {
    var caja = C.el('div', 'uhp-voz__turno' + (t.quien === 'usted' ? ' uhp-voz__turno--usted' : ''));
    caja.appendChild(C.el('span', 'uhp-voz__quien', t.quien === 'usted' ? T.usted : T.asistente));
    // textContent: lo que devuelve el modelo es texto y solo texto.
    caja.appendChild(C.el('span', '', t.texto));
    return caja;
  }

  function cabecera(v, titulo) {
    var cab = C.el('div', 'uhp-voz__cab');
    cab.appendChild(C.el('h3', '', titulo || 'Asistente de voz'));
    var estado = C.el('div', 'uhp-voz__estado-txt');
    estado.appendChild(C.el('i', 'uhp-voz__pulso'));
    estado.appendChild(C.el('span', '', textoDeFase(v)));
    cab.appendChild(estado);
    return cab;
  }

  function pie(v) {
    var caja = C.el('div', 'uhp-voz__pie');

    if (v.fase === 'escucha' || v.fase === 'habla' || v.fase === 'conectando') {
      caja.appendChild(boton(T.terminar, 'uhp-voz__btn', function () {
        terminar(v, 'usuario');
      }));
    } else {
      caja.appendChild(boton(T.abrir, 'uhp-voz__btn uhp-voz__btn--principal', function () {
        v.hilo = [];
        comenzar(v);
      }));
    }

    if (CFG.texto && (v.fase === 'escucha' || v.fase === 'habla')) {
      var forma = C.el('form', 'uhp-voz__forma');
      var campo = document.createElement('input');
      campo.type = 'text';
      campo.className = 'uhp-voz__campo';
      campo.setAttribute('aria-label', T.escribir);
      campo.placeholder = T.escribir;
      forma.appendChild(campo);
      forma.appendChild(boton(T.enviar, 'uhp-voz__btn', null, 'submit'));
      forma.addEventListener('submit', function (e) {
        e.preventDefault();
        var texto = campo.value.trim();
        if (!texto || !v.conv) { return; }
        campo.value = '';
        turno(v, 'usted', texto);
        try { v.conv.sendUserMessage(texto); } catch (err) { /* sesión cerrada */ }
      });
      caja.appendChild(forma);
    }

    caja.appendChild(boton(T.retirar, 'uhp-voz__btn uhp-voz__btn--fino', function () {
      guardarConsentimiento(false);
      terminar(v, 'retirada');
      anunciar(v, T.retirada);
      v.hilo = [];
      cerrarPanel(v);
    }));

    return caja;
  }

  /* La confirmación de un municipio dudoso es una PULSACIÓN y no una
     frase: el modelo no puede confirmarse a sí mismo. */
  function pedirConfirmacion(v, nombre) {
    var caja = C.el('div', 'uhp-voz__confirmar');
    caja.appendChild(C.el('span', '', '¿Quiso decir ' + nombre + '?'));
    caja.appendChild(boton('Sí, ' + nombre, 'uhp-voz__btn', function () {
      v.confirmado = nombre;
      v.confirmaPendiente = null;
      var m = mandoDe(v);
      if (m) { m.setMunicipio(nombre); }
      pintarConversacion(v);
      if (v.conv) {
        try { v.conv.sendContextualUpdate('El visitante confirmó ' + nombre + '.'); } catch (e) { /* cerrada */ }
      }
    }));
    caja.appendChild(boton('No', 'uhp-voz__btn uhp-voz__btn--fino', function () {
      v.sugerido = null;
      v.confirmaPendiente = null;
      pintarConversacion(v);
    }));
    v.confirmaPendiente = caja;
    pintarConversacion(v);
  }

  function boton(texto, clase, fn, tipo) {
    var b = C.el('button', clase, texto);
    b.type = tipo || 'button';
    if (fn) { b.addEventListener('click', fn); }
    return b;
  }

  function turno(v, quien, texto) {
    v.hilo.push({ quien: quien, texto: texto });
    // Un hilo sin tope crece sin fin en una conversación larga.
    if (v.hilo.length > 40) { v.hilo = v.hilo.slice(-40); }
    pintarConversacion(v);
  }

  function fase(v, f) {
    v.fase = f;
    v.raiz.classList.remove('uhp-voz--escucha', 'uhp-voz--habla', 'uhp-voz--error');
    if (f === 'escucha') { v.raiz.classList.add('uhp-voz--escucha'); }
    else if (f === 'habla') { v.raiz.classList.add('uhp-voz--habla'); }
    else if (f === 'error') { v.raiz.classList.add('uhp-voz--error'); }
  }

  function textoDeFase(v) {
    if (v.fase === 'conectando') { return T.conectando; }
    if (v.fase === 'escucha') { return T.escuchando; }
    if (v.fase === 'habla') { return T.hablando; }
    if (v.fase === 'error') { return v.error || T.sin_sesion; }
    return T.cerrada;
  }

  function anunciar(v, texto) {
    if (v.nodos.estado) { v.nodos.estado.textContent = texto; }
  }

  /* Un diálogo modal que deja escapar el foco al fondo no es modal: el
     lector de pantalla sigue leyendo el tablero de debajo como si el
     aviso no estuviera. */
  function atraparFoco(v, panel) {
    panel.addEventListener('keydown', function (e) {
      if (e.key !== 'Tab') { return; }
      var focos = panel.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
      if (!focos.length) { return; }
      var primero = focos[0];
      var ultimo = focos[focos.length - 1];
      if (e.shiftKey && document.activeElement === primero) {
        e.preventDefault();
        ultimo.focus();
      } else if (!e.shiftKey && document.activeElement === ultimo) {
        e.preventDefault();
        primero.focus();
      }
    });
  }

  window.UHPVozAsistente = { iniciar: iniciar };
}());
