/* Asistente de voz del tablero — [urkunina_dashboard].

   El visitante pulsa, concede el micrófono y pregunta en voz alta por las
   cifras del proyecto. El agente de ElevenLabs responde y, cuando se lo
   piden, mueve los filtros del tablero.

   CINCO REGLAS QUE SOSTIENEN ESTE ARCHIVO:

     · NADA DEL MODELO ENTRA COMO HTML. Todo lo que dice el agente se
       pinta con textContent. Un modelo que devuelve «<img onerror=…>»
       tiene que verse como ese texto y no ejecutarse.

     · EL ASISTENTE NO PUEDE HACER NADA QUE EL TECLADO NO HAGA. Habla con
       el tablero por window.UHPTablero.mando(), la misma puerta que usan
       los clics.

     · EL MICRÓFONO NO SE ENCIENDE SIN AUTORIZACIÓN INFORMADA. La compuerta
       con el aviso de habeas data es previa y obligatoria.

     · NO SE SELECCIONA UN MUNICIPIO POR PARECIDO. Entre los 55
       intervenidos hay tres pares a dos cambios de distancia —Pupiales e
       Ipiales, Albán y Aldana, Iles e Imués— y confundirlos significa
       leer en voz alta la cifra de otro pueblo: Pupiales tiene 21,3 % de
       lesión precursora e Ipiales 37,2 %. Lo que no cruza exacto se
       pregunta, y lo confirma una pulsación.

     · EL PANEL SE CONSTRUYE UNA VEZ Y SE ACTUALIZA. Repintarlo entero con
       cada frase del agente —como hacía la primera versión— destruye el
       campo donde el visitante está escribiendo, le roba el foco al
       <body> y con él se lleva la tecla Escape. Aquí la cabecera, el hilo
       y el pie se montan al abrir la conversación y después solo se les
       añade o se les cambia el texto.                                  */
(function () {
  'use strict';

  var C = window.UHPcore;
  var DATOS = window.UHPVoz || null;
  var T = (DATOS && DATOS.textos) || {};
  var CFG = (DATOS && DATOS.cfg) || null;

  /* Presupuesto de cambios. Las cinco herramientas que mueven el tablero
     no esperan respuesta, de modo que el agente puede encadenarlas sin
     frenar. Cada una repinta la lista de 55 municipios y reenfoca el
     mapa; una ráfaga deja además al lector de pantalla leyendo un aviso
     por segundo, que es peor que no avisar. */
  var MUT_MINIMO_MS = 1500;
  var MUT_POR_MINUTO = 8;

  /* Una descarga que se queda colgada no dispara onerror nunca. */
  var SDK_ESPERA_MS = 15000;

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
      error: '',
      // Testigo de generación: todo lo que vuelve de una promesa de una
      // sesión abandonada se descarta comparándolo con esto.
      gen: 0,
      // Nodos vivos del panel, montados una sola vez por conversación.
      p: null,
      mutaciones: [],
      sugerido: null,
      confirmado: null,
      confirmaCaja: null,
      desuscribir: null,
      temporizadorCtx: 0,
      trampa: null,
      focoPrevio: null
    };

    var abrir = raiz.querySelector('[data-uhp-voz-accion="abrir"]');
    if (abrir) {
      v.lanzador = abrir;
      v.lanzadorTxt = abrir.querySelector('span:not(.uhp-voz__icono)');
      abrir.addEventListener('click', function () {
        // Cualquier estado distinto de «inactivo» significa que el panel
        // está abierto: el botón cierra. Antes comparaba contra una fase
        // llamada 'activa' que no existe en ninguna parte, de modo que
        // volver a pulsarlo abría una segunda sesión encima de la
        // primera y la primera no se cerraba nunca.
        if (v.fase !== 'inactivo') { cerrarPanel(v); return; }
        comenzar(v);
      });
      // Sin JavaScript el botón no hace nada: sale deshabilitado del
      // servidor y se habilita aquí, que es cuando de verdad funciona.
      abrir.disabled = false;
    }

    window.addEventListener('pagehide', function () { soltar(v); });

    // Escape en el documento y no en la raíz: tras un repintado el foco
    // puede estar fuera del panel, y entonces la tecla no llegaría.
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape' || v.fase === 'inactivo') { return; }
      if (!v.raiz.isConnected) { return; }
      cerrarPanel(v);
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
    v.p = null;
    panel.innerHTML = '';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');
    panel.setAttribute('aria-label', T.titulo || 'Asistente de voz');
    fase(v, 'aviso');

    var cab = C.el('div', 'uhp-voz__cab');
    cab.appendChild(C.el('h3', '', T.titulo));
    panel.appendChild(cab);

    var aviso = C.el('div', 'uhp-voz__aviso');
    aviso.setAttribute('tabindex', '0');
    aviso.setAttribute('role', 'document');
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
    pie.appendChild(si);
    pie.appendChild(boton(T.cancelar, 'uhp-voz__btn', function () { cerrarPanel(v); }));
    panel.appendChild(pie);

    atraparFoco(v, panel);
    si.focus();
  }

  /* ------------------------------------------------------------------ */
  /* Sesión                                                             */
  /* ------------------------------------------------------------------ */

  function arrancarSesion(v) {
    soltarTrampa(v);
    v.gen++;
    var mia = v.gen;

    montarConversacion(v);
    fase(v, 'conectando');

    cargarSdk()
      .then(function () { return credenciales(); })
      .then(function (cred) { return abrir(v, cred, mia); })
      .catch(function (err) {
        // Un error de una sesión que el visitante ya abandonó no puede
        // pintar nada: el panel estaría mostrando el fallo de algo que
        // nadie está esperando.
        if (mia !== v.gen) { return; }
        fallar(v, err);
      });
  }

  var sdkPrometido = null;
  function cargarSdk() {
    if (window.ElevenLabsClient) { return Promise.resolve(); }
    if (sdkPrometido) { return sdkPrometido; }

    sdkPrometido = new Promise(function (resolver, rechazar) {
      var listo = false;
      var fallo = function () {
        if (listo) { return; }
        listo = true;
        // Se suelta la promesa: si quedara en caché rechazada, el
        // asistente estaría muerto para el resto de la página y un
        // reintento no volvería a pedir el archivo.
        sdkPrometido = null;
        rechazar(new Error('sin-sdk'));
      };
      var reloj = window.setTimeout(fallo, SDK_ESPERA_MS);

      var s = document.createElement('script');
      s.src = CFG.sdk.sdk;
      s.async = true;
      s.crossOrigin = 'anonymous';
      s.onload = function () {
        window.clearTimeout(reloj);
        if (listo) { return; }
        if (window.ElevenLabsClient) { listo = true; resolver(); }
        else { fallo(); }
      };
      s.onerror = function () { window.clearTimeout(reloj); fallo(); };
      document.head.appendChild(s);
    });
    return sdkPrometido;
  }

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

  function abrir(v, cred, mia) {
    var mando = mandoDe(v);
    var opciones = {
      clientTools: herramientas(v),
      onConnect: function () { if (mia === v.gen) { fase(v, 'escucha'); } },
      onDisconnect: function () {
        if (mia !== v.gen) { return; }
        soltar(v, true);
        fase(v, 'cerrada');
        actualizarPie(v);
      },
      onError: function (e) {
        if (mia !== v.gen) { return; }
        fallar(v, e instanceof Error ? e : new Error('sin-sesion'));
      },
      onModeChange: function (m) {
        if (mia !== v.gen || v.fase === 'cerrada' || v.fase === 'error') { return; }
        fase(v, m && m.mode === 'speaking' ? 'habla' : 'escucha');
      },
      onMessage: function (m) {
        if (mia !== v.gen || !m || !m.message) { return; }
        turno(v, m.source === 'user' ? 'usted' : 'asistente', String(m.message));
      }
    };
    if (mando && mando.disponible()) {
      opciones.dynamicVariables = variables(mando.estado());
    }
    for (var k in cred) { if (Object.prototype.hasOwnProperty.call(cred, k)) { opciones[k] = cred[k]; } }

    return window.ElevenLabsClient.Conversation.startSession(opciones).then(function (conv) {
      // El visitante pudo cerrar el panel mientras conectaba. Sin esta
      // comparación, la sesión quedaba abierta —y el micrófono encendido,
      // con su punto rojo en la pestaña— sin panel a la vista.
      if (mia !== v.gen) {
        try { conv.endSession(); } catch (e) { /* ya estaba cerrada */ }
        return;
      }
      v.conv = conv;
      fase(v, 'escucha');
      actualizarPie(v);
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

  function escucharTablero(v) {
    var mando = mandoDe(v);
    if (!mando) { return; }
    v.desuscribir = mando.alCambiar(function (estado, origen) {
      if (origen === 'agente' || !v.conv) { return; }
      window.clearTimeout(v.temporizadorCtx);
      v.temporizadorCtx = window.setTimeout(function () {
        if (!v.conv) { return; }
        try {
          v.conv.sendContextualUpdate('El visitante cambió el tablero: ' + resumenHablado(estado));
        } catch (e) { /* la sesión pudo cerrarse entre medias */ }
      }, 800);
    });
  }

  /* Suelta TODO lo que una conversación deja abierto. Lo llaman el cierre
     a mano, el corte del proveedor, el fallo y la salida de la página: si
     cada uno soltara lo suyo, alguno se dejaría algo. */
  function soltar(v, conservarEstadoUi) {
    window.clearTimeout(v.temporizadorCtx);
    if (v.desuscribir) { v.desuscribir(); v.desuscribir = null; }
    if (v.conv) {
      try { v.conv.endSession(); } catch (e) { /* ya estaba cerrada */ }
      v.conv = null;
    }
    // Lo sugerido y lo confirmado son de UNA conversación. Si sobreviven,
    // la confirmación por pulsación que protege de elegir el municipio
    // equivocado se sortea en la conversación siguiente.
    v.sugerido = null;
    v.confirmado = null;
    v.mutaciones = [];
    quitarConfirmacion(v);
    if (!conservarEstadoUi) { v.gen++; }
  }

  function fallar(v, err) {
    soltar(v, true);

    // La taxonomía del navegador vive en err.name, no en err.message: el
    // mensaje es libre y cambia entre navegadores y entre versiones.
    var nombre = (err && err.name) || '';
    var clave = (err && err.message) || '';
    var mensaje = T.sin_sesion;

    if (clave === 'sin-sdk') { mensaje = T.sin_sdk; }
    else if (clave === 'sin-cuota') { mensaje = T.sin_cuota; }
    else if (!window.isSecureContext) { mensaje = T.sin_seguro; }
    else if ('NotAllowedError' === nombre || 'SecurityError' === nombre || /permission|not ?allowed/i.test(clave)) { mensaje = T.sin_micro; }
    else if ('NotFoundError' === nombre || 'OverconstrainedError' === nombre || /not ?found|device/i.test(clave)) { mensaje = T.sin_micro_no; }
    else if ('NotReadableError' === nombre || 'AbortError' === nombre || /not ?readable|in ?use/i.test(clave)) { mensaje = T.ocupado; }

    v.error = mensaje;
    fase(v, 'error');
    actualizarPie(v);
    if (window.console && console.warn) {
      console.warn('[URKUNINA 5000] asistente de voz: ' + (nombre || clave));
    }
  }

  /* ------------------------------------------------------------------ */
  /* Herramientas que el agente puede llamar                            */
  /* ------------------------------------------------------------------ */

  function herramientas(v) {
    return {
      uhp_cambiar_indicador: function (p) {
        return conMando(v, function (m) {
          var val = p && p.indicador;
          if (val !== 'lpm' && val !== 'hp') {
            return 'Solo puedo mostrar dos indicadores: lesión precursora de malignidad e infección por H. pylori.';
          }
          if (!gastar(v)) { return T.demasiado; }
          if (!m.setIndicador(val)) { return 'No pude cambiar el indicador.'; }
          var e = m.estado();
          return 'Indicador cambiado a ' + e.indicadorEtiqueta + '. Promedio de la selección: ' + cifra(e.promedio) + '.';
        });
      },

      uhp_filtrar_zona_riesgo: function (p) {
        return conMando(v, function (m) {
          var z = p && p.zona;
          var destino = (!z || z === 'todas') ? null : String(z).toLowerCase();
          if (destino && m.catalogo().zonas.indexOf(destino) < 0) {
            return 'No conozco esa zona. Las zonas son roja, amarilla y verde.';
          }
          if (!gastar(v)) { return T.demasiado; }
          if (!m.setZona(destino)) { return 'No pude aplicar ese filtro.'; }
          var e = m.estado();
          return destino
            ? (e.zonaEtiqueta + ': ' + e.visibles + ' municipios de los 55 intervenidos.')
            : 'Filtro de zona retirado: los 55 municipios priorizados.';
        });
      },

      uhp_filtrar_subregion: function (p) {
        return conMando(v, function (m) {
          var texto = p && p.subregion;
          if (!texto || String(texto).toLowerCase() === 'todas') {
            if (!gastar(v)) { return T.demasiado; }
            m.setSubregion(null);
            return 'Filtro de subregión retirado: ' + m.estado().visibles + ' municipios.';
          }
          var nombre = cruzarSubregion(m, texto);
          if (!nombre) { return sinSubregion(m); }
          if (!gastar(v)) { return T.demasiado; }
          if (!m.setSubregion(nombre)) { return 'No pude aplicar ese filtro.'; }
          var e = m.estado();
          return 'Subregión ' + nombre + ': ' + e.visibles + ' municipios. ' +
            e.indicadorEtiqueta + ' promedio ' + cifra(e.promedio) + '.';
        });
      },

      uhp_seleccionar_municipio: function (p) {
        return conMando(v, function (m) {
          var r = m.resolver(p && p.municipio);

          if (r.estado === 'fuera') {
            return r.municipio.n + ' no entró en el estudio: el proyecto intervino ' +
              '55 de los 64 municipios de Nariño y allí no hubo tamizaje. ' +
              'No falta el dato, faltó el estudio.';
          }
          if (r.estado === 'ambiguo') {
            v.sugerido = r.sugerencias;
            pedirConfirmacion(v, r.sugerencias);
            return 'Hay varios municipios con ese nombre de cerca: ' + r.sugerencias.join(', ') +
              '. Le he puesto los botones en pantalla para que elija.';
          }
          if (r.estado === 'sugerencia') {
            v.sugerido = [r.sugerencia];
            pedirConfirmacion(v, [r.sugerencia]);
            return 'No encontré ese municipio. ¿Quiso decir ' + r.sugerencia +
              '? Le he puesto un botón en pantalla para confirmarlo.';
          }
          if (r.estado !== 'exacto') {
            return 'No encuentro ese municipio entre los 55 que intervino el proyecto.';
          }
          // Lo que llegó por sugerencia necesita la pulsación aunque ahora
          // cruce exacto: si no, el modelo se autoconfirma en el turno
          // siguiente y la salvaguarda no sirve de nada.
          if (v.sugerido && v.sugerido.indexOf(r.municipio.n) >= 0 && v.confirmado !== r.municipio.n) {
            pedirConfirmacion(v, v.sugerido);
            return 'Necesito que confirme ' + r.municipio.n + ' con el botón de la pantalla.';
          }
          if (!gastar(v)) { return T.demasiado; }
          if (!m.setMunicipio(r.municipio.n)) { return 'No pude seleccionarlo.'; }
          // De un solo uso: ni dentro de la misma conversación vale dos veces.
          v.sugerido = null;
          v.confirmado = null;
          return describir(r.municipio, r.vecino);
        });
      },

      uhp_limpiar_tablero: function () {
        return conMando(v, function (m) {
          if (!gastar(v)) { return T.demasiado; }
          m.limpiar();
          return 'Filtros quitados: los 55 municipios priorizados a la vista.';
        });
      },

      /* Las tres que siguen SOLO LEEN. No gastan presupuesto de cambios y
         no mueven la pantalla delante de quien está leyendo. */

      uhp_leer_estado_tablero: function () {
        return conMando(v, function (m) { return resumenHablado(m.estado()); });
      },

      uhp_consultar_municipio: function (p) {
        return conMando(v, function (m) {
        var r = m.resolver(p && p.municipio);
        if (r.estado === 'fuera') {
          return r.municipio.n + ' no entró en el estudio: allí no hubo tamizaje.';
        }
        if (r.estado === 'ambiguo') {
          return 'Hay varios municipios de nombre parecido: ' + r.sugerencias.join(', ') + '. ¿Cuál quería?';
        }
        if (r.estado === 'sugerencia') {
          return 'No encontré ese municipio. ¿Quiso decir ' + r.sugerencia + '?';
        }
        if (r.estado !== 'exacto') { return 'No encuentro ese municipio entre los 55.'; }
        return describir(r.municipio, r.vecino);
        });
      },

      uhp_listar_municipios: function (p) {
        return conMando(v, function (m) {
        var filtro = {};
        if (p && p.zona) {
          var z = String(p.zona).toLowerCase();
          // Un filtro que no cruza NO se descarta en silencio: hacerlo
          // significa contestar otra pregunta sin avisar, y el agente lo
          // leería como si fuera la respuesta a la que hizo.
          if (m.catalogo().zonas.indexOf(z) < 0) {
            return 'No conozco esa zona. Las zonas son roja, amarilla y verde.';
          }
          filtro.zona = z;
        }
        if (p && p.subregion) {
          var sr = cruzarSubregion(m, p.subregion);
          if (!sr) { return sinSubregion(m); }
          filtro.subregion = sr;
        }

        var lista = m.listar(filtro);
        if (!lista.length) { return 'No hay municipios con ese filtro.'; }
        return lista.length + ' municipios: ' + lista.join(', ') + '.';
        });
      }
    };
  }

  /* Acceso al tablero, sin gastar nada: lo usan también las tres
     herramientas que solo leen. */
  function conMando(v, fn) {
    var m = mandoDe(v);
    if (!m || !m.disponible()) { return 'El tablero todavía no terminó de cargar.'; }
    try {
      return fn(m);
    } catch (e) {
      if (window.console && console.error) { console.error('[URKUNINA 5000] herramienta de voz', e); }
      return 'No pude hacer ese cambio.';
    }
  }

  /* El presupuesto se cobra JUSTO ANTES de cambiar algo, no al entrar en
     la herramienta: una llamada con un argumento que no existe no repinta
     nada, y cobrársela dejaría al agente sin cupo por equivocarse de
     palabra. Devuelve false cuando ya no queda. */
  function gastar(v) {
    var ahora = Date.now();
    v.mutaciones = v.mutaciones.filter(function (t) { return ahora - t < 60000; });
    var ultima = v.mutaciones.length ? v.mutaciones[v.mutaciones.length - 1] : 0;
    if (v.mutaciones.length >= MUT_POR_MINUTO || (ahora - ultima) < MUT_MINIMO_MS) {
      return false;
    }
    v.mutaciones.push(ahora);
    return true;
  }

  function sinSubregion(m) {
    return 'No encuentro esa subregión. Las documentadas son: ' + m.catalogo().subregiones.join(', ') + '.';
  }

  function describir(f, vecino) {
    var txt = [f.n, 'código DANE ' + f.c, 'subregión ' + f.sub, 'zona ' + f.zonaEtiqueta].join(', ') + '. ';
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
    return [
      'indicador: ' + e.indicadorEtiqueta,
      'zona: ' + (e.zonaEtiqueta || 'todas'),
      'subregión: ' + (e.subregion || 'todas'),
      'municipio: ' + (e.municipio || 'ninguno'),
      'municipios a la vista: ' + e.visibles,
      'casos de cáncer en la selección: ' + e.casos,
      'promedio: ' + cifra(e.promedio)
    ].join('; ') + '.';
  }

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
  /* Panel: se monta una vez y se actualiza                             */
  /* ------------------------------------------------------------------ */

  function abrirPanel(v) {
    if (v.lanzador) { v.lanzador.setAttribute('aria-expanded', 'true'); }
    if (v.nodos.panel) { v.nodos.panel.hidden = false; }
    var previo = document.activeElement;
    // Solo se guarda si está FUERA del asistente: si no, al cerrar se
    // devolvería el foco a un botón que acaba de desaparecer.
    if (previo && previo.focus && !v.raiz.contains(previo)) { v.focoPrevio = previo; }
  }

  function cerrarPanel(v) {
    soltar(v);
    soltarTrampa(v);
    v.p = null;
    if (v.lanzador) { v.lanzador.setAttribute('aria-expanded', 'false'); }
    if (v.nodos.panel) {
      v.nodos.panel.hidden = true;
      v.nodos.panel.innerHTML = '';
      v.nodos.panel.removeAttribute('role');
      v.nodos.panel.removeAttribute('aria-modal');
    }
    fase(v, 'inactivo');
    var destino = (v.focoPrevio && v.focoPrevio.isConnected) ? v.focoPrevio : v.lanzador;
    v.focoPrevio = null;
    if (destino && destino.focus) { destino.focus(); }
  }

  /* Monta cabecera, hilo y pie UNA vez. Lo que cambia después cambia por
     textContent o añadiendo un nodo al hilo, nunca repintando: el campo
     de texto tiene que sobrevivir a que el agente hable. */
  function montarConversacion(v) {
    var panel = v.nodos.panel;
    if (!panel) { return; }
    panel.innerHTML = '';
    panel.removeAttribute('role');
    panel.removeAttribute('aria-modal');

    var cab = C.el('div', 'uhp-voz__cab');
    cab.appendChild(C.el('h3', '', T.titulo));
    var estado = C.el('div', 'uhp-voz__estado-txt');
    var pulso = C.el('i', 'uhp-voz__pulso');
    pulso.setAttribute('aria-hidden', 'true');
    var estadoTxt = C.el('span', '', '');
    estado.appendChild(pulso);
    estado.appendChild(estadoTxt);
    cab.appendChild(estado);
    panel.appendChild(cab);

    var hilo = C.el('div', 'uhp-voz__hilo');
    // role="log" y tabindex: una caja con overflow que no puede recorrerse
    // con el teclado deja la transcripción fuera del alcance de quien no
    // usa ratón, y un <div> a secas descarta su propio aria-label.
    hilo.setAttribute('role', 'log');
    hilo.setAttribute('tabindex', '0');
    hilo.setAttribute('aria-label', T.transcripcion);
    hilo.appendChild(C.el('p', 'uhp-voz__vacio', T.conectando));
    panel.appendChild(hilo);

    var pie = C.el('div', 'uhp-voz__pie');
    panel.appendChild(pie);

    v.p = { panel: panel, estadoTxt: estadoTxt, hilo: hilo, pie: pie, vacio: hilo.firstChild, forma: null, campo: null };
    actualizarPie(v);
  }

  /* El pie cambia de botones según la fase, pero el formulario se
     CONSERVA: recrearlo borraría lo que el visitante está escribiendo. */
  function actualizarPie(v) {
    if (!v.p) { return; }
    var pie = v.p.pie;
    var enSesion = v.fase === 'escucha' || v.fase === 'habla' || v.fase === 'conectando';

    // Se vacía el pie salvo el formulario, que se saca y se vuelve a poner.
    if (v.p.forma && v.p.forma.parentNode === pie) { pie.removeChild(v.p.forma); }
    pie.innerHTML = '';

    if (enSesion) {
      pie.appendChild(boton(T.terminar, 'uhp-voz__btn', function () {
        soltar(v);
        fase(v, 'cerrada');
        actualizarPie(v);
      }));
    } else {
      pie.appendChild(boton(T.reanudar, 'uhp-voz__btn uhp-voz__btn--principal', function () {
        comenzar(v);
      }));
    }

    if (CFG.texto && (v.fase === 'escucha' || v.fase === 'habla')) {
      if (!v.p.forma) { crearForma(v); }
      pie.appendChild(v.p.forma);
    }

    pie.appendChild(boton(T.retirar, 'uhp-voz__btn uhp-voz__btn--fino', function () {
      guardarConsentimiento(false);
      soltar(v);
      anunciar(v, T.retirada);
      cerrarPanel(v);
    }));
  }

  function crearForma(v) {
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
    v.p.forma = forma;
    v.p.campo = campo;
  }

  function turno(v, quien, texto) {
    if (!v.p || !v.p.hilo.isConnected) { return; }
    if (v.p.vacio && v.p.vacio.parentNode) {
      v.p.hilo.removeChild(v.p.vacio);
      v.p.vacio = null;
    }

    var caja = C.el('div', 'uhp-voz__turno' + (quien === 'usted' ? ' uhp-voz__turno--usted' : ''));
    caja.appendChild(C.el('span', 'uhp-voz__quien', quien === 'usted' ? T.usted : T.asistente));
    // textContent: lo que devuelve el modelo es texto y solo texto.
    caja.appendChild(C.el('span', '', texto));

    // Si hay una confirmación pendiente, los turnos nuevos van ANTES de
    // ella para que el botón siga siendo lo último.
    if (v.confirmaCaja && v.confirmaCaja.parentNode === v.p.hilo) {
      v.p.hilo.insertBefore(caja, v.confirmaCaja);
    } else {
      v.p.hilo.appendChild(caja);
    }

    // Solo se baja si el visitante ya estaba abajo: si había subido a
    // releer algo, arrastrarlo es quitarle el control de su propia lectura.
    var abajo = v.p.hilo.scrollHeight - v.p.hilo.scrollTop - v.p.hilo.clientHeight < 40;
    if (abajo) { v.p.hilo.scrollTop = v.p.hilo.scrollHeight; }

    // Un hilo sin tope crece sin fin en una conversación larga.
    while (v.p.hilo.querySelectorAll('.uhp-voz__turno').length > 40) {
      v.p.hilo.removeChild(v.p.hilo.querySelector('.uhp-voz__turno'));
    }
  }

  /* La confirmación de un municipio dudoso es una PULSACIÓN y no una
     frase: el modelo no puede confirmarse a sí mismo. */
  function pedirConfirmacion(v, nombres) {
    quitarConfirmacion(v);
    if (!v.p || !v.p.hilo.isConnected) { return; }

    var caja = C.el('div', 'uhp-voz__confirmar');
    caja.setAttribute('role', 'group');
    var pregunta = C.el('span', '', nombres.length > 1
      ? '¿Cuál de estos quería?'
      : ('¿Quiso decir ' + nombres[0] + '?'));
    var idp = 'uhp-voz-conf-' + (v.gen) + '-' + nombres.length;
    pregunta.id = idp;
    caja.setAttribute('aria-labelledby', idp);
    caja.appendChild(pregunta);

    var primero = null;
    nombres.forEach(function (nombre) {
      var b = boton('Sí, ' + nombre, 'uhp-voz__btn', function () {
        v.confirmado = nombre;
        quitarConfirmacion(v);
        var m = mandoDe(v);
        // La pulsación es del VISITANTE aunque la sugerencia la trajera
        // el agente: atribuírsela al asistente sería mentirle a quien no
        // ve la pantalla.
        if (m) { m.setMunicipio(nombre, 'usuario'); }
        if (v.conv) {
          try { v.conv.sendContextualUpdate('El visitante confirmó ' + nombre + '.'); } catch (e) { /* cerrada */ }
        }
      });
      if (!primero) { primero = b; }
      caja.appendChild(b);
    });

    caja.appendChild(boton(T.no, 'uhp-voz__btn uhp-voz__btn--fino', function () {
      v.sugerido = null;
      quitarConfirmacion(v);
    }));

    v.confirmaCaja = caja;
    v.p.hilo.appendChild(caja);
    v.p.hilo.scrollTop = v.p.hilo.scrollHeight;

    // Es un cambio de contexto que pidió el propio visitante al hablar:
    // mover el foco aquí es lo correcto, y además hay que decirlo.
    anunciar(v, T.confirmar);
    if (primero) { primero.focus(); }
  }

  function quitarConfirmacion(v) {
    if (v.confirmaCaja && v.confirmaCaja.parentNode) {
      v.confirmaCaja.parentNode.removeChild(v.confirmaCaja);
    }
    v.confirmaCaja = null;
  }

  function boton(texto, clase, fn, tipo) {
    var b = C.el('button', clase, texto);
    b.type = tipo || 'button';
    if (fn) { b.addEventListener('click', fn); }
    return b;
  }

  /* ------------------------------------------------------------------ */
  /* Estado                                                             */
  /* ------------------------------------------------------------------ */

  function fase(v, f) {
    if (v.fase === f) { return; }
    v.fase = f;

    v.raiz.classList.remove('uhp-voz--escucha', 'uhp-voz--habla', 'uhp-voz--error');
    if (f === 'escucha') { v.raiz.classList.add('uhp-voz--escucha'); }
    else if (f === 'habla') { v.raiz.classList.add('uhp-voz--habla'); }
    else if (f === 'error') { v.raiz.classList.add('uhp-voz--error'); }

    var texto = textoDeFase(v);
    // El rótulo se actualiza siempre: el color del punto no puede ser la
    // única señal de que el asistente pasó de escuchar a hablar.
    if (v.p && v.p.estadoTxt) { v.p.estadoTxt.textContent = texto; }

    // El lanzador dice lo que hace ahora, no lo que hacía al cargar.
    if (v.lanzadorTxt) {
      v.lanzadorTxt.textContent = (f === 'inactivo') ? T.abrir : T.cerrar;
    }

    // Se anuncia lo que importa; «escucha» y «habla» se alternan muchas
    // veces por conversación y anunciarlas sería ruido continuo.
    if (f === 'cerrada' || f === 'error' || f === 'conectando') { anunciar(v, texto); }
  }

  function textoDeFase(v) {
    if (v.fase === 'aviso') { return T.aviso_fase; }
    if (v.fase === 'conectando') { return T.conectando; }
    if (v.fase === 'escucha') { return T.escuchando; }
    if (v.fase === 'habla') { return T.hablando; }
    if (v.fase === 'error') { return v.error || T.sin_sesion; }
    return T.cerrada;
  }

  function anunciar(v, texto) {
    if (v.nodos.estado) { v.nodos.estado.textContent = texto; }
  }

  /* Un diálogo modal que deja escapar el foco al fondo no es modal. Pero
     la trampa tiene que irse CON el diálogo: si sobrevive, el Tab queda
     encerrado en un panel que ya es una conversación corriente. */
  function atraparFoco(v, panel) {
    soltarTrampa(v);
    var fn = function (e) {
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
    };
    panel.addEventListener('keydown', fn);
    v.trampa = { nodo: panel, fn: fn };
  }

  function soltarTrampa(v) {
    if (v.trampa) {
      v.trampa.nodo.removeEventListener('keydown', v.trampa.fn);
      v.trampa = null;
    }
  }

  window.UHPVozAsistente = { iniciar: iniciar };
}());
