/* Asistente del tablero — [urkunina_dashboard].

   El visitante pregunta por las cifras del proyecto y el agente de
   ElevenLabs responde y, cuando se lo piden, mueve los filtros del tablero.

   DOS CANALES, Y NO SON EL MISMO:

     · VOZ. Se concede el micrófono, se habla y se escucha. El SDK abre un
       VoiceConversation.

     · ESCRITO. No se pide el micrófono NUNCA. El SDK abre un
       TextConversation, que no toca navigator.mediaDevices ni crea un
       AudioContext, y la sesión se declara `text_only` también al
       servidor para que no sintetice un audio que nadie va a oír y que
       se factura igual.

   No es la misma conversación con otra piel: el aviso de habeas data es
   distinto —en el canal escrito no se trata ninguna muestra de voz, que es
   un dato biométrico—, la autorización se guarda por separado, los
   rótulos que lee un lector de pantalla son otros, y la receta de CSP que
   necesita el sitio se encoge. Cada vez que este archivo decida algo
   mirando `v.canal`, es por una de esas razones y no por estética.

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

  /* Registro de instancias. Hace falta por la visibilidad «oculto», donde
     el plugin no pinta ningún botón y el de la entidad lo pone su
     plantilla: sin una puerta pública, «se abre por programa» era una
     promesa del panel de administración que este archivo no cumplía. */
  var INSTANCIAS = [];

  C.ready(function () {
    var nodos = document.querySelectorAll('[data-uhp-voz]');
    for (var i = 0; i < nodos.length; i++) { iniciar(nodos[i]); }

    /* Puerta declarativa: cualquier elemento de la plantilla con
       data-uhp-voz-abrir abre el asistente, sin escribir JavaScript. El
       valor del atributo elige canal («voz» o «texto»); vacío, el que la
       página ofrezca. */
    if (INSTANCIAS.length) {
      document.addEventListener('click', function (e) {
        var b = (e.target && e.target.closest) ? e.target.closest('[data-uhp-voz-abrir]') : null;
        if (!b) { return; }
        e.preventDefault();
        abrirPorPrograma(b, b.getAttribute('data-uhp-voz-abrir'));
      });
    }
  });

  /* A qué asistente se refiere quien llama. Con un destino, el que lo
     contiene; sin destino, el primero. Y si el destino no está dentro de
     ninguno —el caso del botón que la plantilla pone en su cabecera— vale
     el único que haya: con dos tableros en la página haría falta decirlo,
     y entonces se devuelve nada en vez de adivinar. */
  function instanciaDe(destino) {
    if (!INSTANCIAS.length) { return null; }
    if (!destino) { return INSTANCIAS[0]; }

    var nodo = (typeof destino === 'string')
      ? document.getElementById(String(destino).replace(/^#/, ''))
      : destino;
    if (!nodo) { return null; }

    for (var i = 0; i < INSTANCIAS.length; i++) {
      var v = INSTANCIAS[i];
      if (v.raiz === nodo || v.raiz.contains(nodo)) { return v; }
      if (v.tablero && (v.tablero === nodo || v.tablero.contains(nodo))) { return v; }
    }
    return (INSTANCIAS.length === 1) ? INSTANCIAS[0] : null;
  }

  function abrirPorPrograma(destino, canal) {
    var v = instanciaDe(destino);
    if (!v) { return false; }

    // Un canal que esta página no ofrece NO se abre desde aquí: el ajuste
    // del sitio y el atributo del shortcode mandan sobre quien llame.
    var c = ('texto' === canal || 'voz' === canal) ? canal : null;
    if ('voz' === c && v.entrada === 'texto') { return false; }
    if ('texto' === c && v.entrada === 'voz') { return false; }

    if (v.fase !== 'inactivo') { return true; }
    v.lanzadorActivo = null;
    comenzar(v, c || (v.entrada === 'texto' ? 'texto' : 'voz'));
    return true;
  }

  function cerrarPorPrograma(destino) {
    var v = instanciaDe(destino);
    if (!v) { return false; }
    if (v.fase !== 'inactivo') { cerrarPanel(v); }
    return true;
  }

  /* ------------------------------------------------------------------ */
  /* Arranque                                                           */
  /* ------------------------------------------------------------------ */

  function iniciar(raiz) {
    var entrada = raiz.getAttribute('data-uhp-voz-entrada') || 'ambos';
    var v = {
      raiz: raiz,
      nodos: zonas(raiz),
      tablero: raiz.closest ? raiz.closest('[data-uhp-tablero]') : null,
      mando: null,
      conv: null,
      fase: 'inactivo',
      error: '',
      // Qué canales ofrece ESTA página, y por cuál va la conversación de
      // ahora. La página puede estrechar lo que permite el sitio.
      entrada: entrada,
      canal: 'texto' === entrada ? 'texto' : 'voz',
      lanzadores: [],
      lanzadorActivo: null,
      // Una pista que solo se usa si la sesión se cae al arrancar y hay
      // sustituciones configuradas: es la causa más probable y la más
      // difícil de adivinar desde el panel de administración.
      conSustituciones: false,
      errorMicro: false,
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

    INSTANCIAS.push(v);

    var lanzadores = raiz.querySelectorAll('[data-uhp-voz-accion="abrir"]');
    for (var j = 0; j < lanzadores.length; j++) {
      cablearLanzador(v, lanzadores[j]);
    }

    /* Los botones que ponga la plantilla. Su rótulo es suyo y no se toca
       —no sabemos qué dice—, pero si declararon aria-expanded se les
       mantiene al día: un botón que dice «cerrado» con el panel abierto
       le miente a quien no ve la pantalla. */
    var externos = document.querySelectorAll('[data-uhp-voz-abrir]');
    for (var k = 0; k < externos.length; k++) {
      if (instanciaDe(externos[k]) === v && externos[k].hasAttribute('aria-expanded')) {
        v.lanzadores.push({ nodo: externos[k], txt: null, canal: 'voz', reposo: '', externo: true });
      }
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

  /* Puede haber dos: uno por canal. Los dos gobiernan el MISMO panel, de
     modo que los dos llevan aria-controls contra él y los dos tienen que
     declarar el mismo aria-expanded: quien llegue tabulando al segundo
     botón con el panel ya abierto tiene que enterarse de que está abierto,
     no creer que lo va a abrir. */
  function cablearLanzador(v, boton) {
    var canal = boton.getAttribute('data-uhp-voz-canal') === 'texto' ? 'texto' : 'voz';
    var ficha = {
      nodo: boton,
      txt: boton.querySelector('span:not(.uhp-voz__icono)'),
      canal: canal,
      // El rótulo de reposo es el que trae del servidor, ya traducido.
      reposo: ''
    };
    ficha.reposo = ficha.txt ? ficha.txt.textContent : '';
    v.lanzadores.push(ficha);

    boton.addEventListener('click', function () {
      // Cualquier estado distinto de «inactivo» significa que el panel
      // está abierto: el botón cierra. Antes comparaba contra una fase
      // llamada 'activa' que no existe en ninguna parte, de modo que
      // volver a pulsarlo abría una segunda sesión encima de la primera
      // y la primera no se cerraba nunca.
      if (v.fase !== 'inactivo') { cerrarPanel(v); return; }
      v.lanzadorActivo = boton;
      comenzar(v, canal);
    });

    // Sin JavaScript el botón no hace nada: sale deshabilitado del
    // servidor y se habilita aquí, que es cuando de verdad funciona.
    boton.disabled = false;
  }

  function pintarLanzadores(v, abierto) {
    for (var i = 0; i < v.lanzadores.length; i++) {
      var l = v.lanzadores[i];
      l.nodo.setAttribute('aria-expanded', abierto ? 'true' : 'false');
      // El rótulo solo en los nuestros: el de la plantilla lo escribió la
      // entidad y no nos toca reescribirlo.
      if (l.txt && !l.externo) { l.txt.textContent = abierto ? T.cerrar : l.reposo; }
    }
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

  /* UNA CLAVE POR CANAL. Lo que autoriza quien lee el aviso escrito no es
     lo que autoriza quien lee el de voz: el segundo incluye el tratamiento
     de una muestra de su voz, que es un dato biométrico. Guardarlo en la
     misma casilla haría que aceptar lo pequeño abriera lo grande. */
  function claveConsentimiento(canal) {
    return 'uhp_voz_consentimiento_' + canal + '_v' + CFG.avisoVersion;
  }

  function leido(clave) {
    try {
      return window.sessionStorage.getItem(clave) === '1';
    } catch (e) { return false; }
  }

  function consentido(canal) {
    if (!CFG.recordar) { return false; }
    if (leido(claveConsentimiento(canal))) { return true; }
    // En un sentido SÍ, y solo en uno: quien autorizó la voz ya leyó un
    // aviso que incluye el canal escrito y lo dice con esas palabras
    // («puede escribir sus preguntas en lugar de hablarlas»). Al revés no:
    // el aviso escrito no menciona el micrófono porque no se enciende.
    return canal === 'texto' && leido(claveConsentimiento('voz'));
  }

  function guardarConsentimiento(v, si) {
    if (!CFG.recordar) { return; }
    try {
      if (si) {
        window.sessionStorage.setItem(claveConsentimiento(v.canal), '1');
      } else {
        // Retirar la autorización retira LAS DOS. Quien pulsa «Retirar
        // autorización» está diciendo que no quiere que se trate nada
        // suyo, no que prefiere el otro canal.
        window.sessionStorage.removeItem(claveConsentimiento('voz'));
        window.sessionStorage.removeItem(claveConsentimiento('texto'));
      }
    } catch (e) { /* sin almacenamiento, la autorización dura la sesión */ }
  }

  function comenzar(v, canal) {
    /* Sin canal explícito se CONSERVA el de ahora. Lo llaman también el
       botón «Volver a preguntar» y la salida de un fallo de micrófono, y
       con un valor por defecto fijo una conversación escrita reanudaba
       pidiendo el micrófono: el visitante pulsaba «volver a preguntar» en
       un panel escrito y le aparecía un diálogo de permiso. */
    if ('voz' === canal || 'texto' === canal) { v.canal = canal; }
    abrirPanel(v);
    if (consentido(v.canal)) { arrancarSesion(v); return; }
    pintarCompuerta(v);
  }

  function pintarCompuerta(v) {
    var panel = v.nodos.panel;
    if (!panel) { return; }

    /* Sin la plantilla del canal no hay aviso que leer, y un aviso VACÍO
       con un botón de aceptar es lo peor que puede ocurrir en este
       archivo: recogería una autorización sobre un texto que no existe.
       No debería pasar —la página solo imprime los avisos de los canales
       que ofrece, y abrirPorPrograma() rechaza los demás—, pero si pasa
       se para aquí. */
    var plantilla = v.canal === 'texto' ? v.nodos['aviso-texto'] : v.nodos.aviso;
    if (!plantilla || !plantilla.content) {
      montarConversacion(v);
      v.error = T.sin_sesion;
      fase(v, 'error');
      actualizarPie(v);
      return;
    }

    v.p = null;
    panel.innerHTML = '';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');
    var titulo = v.canal === 'texto' ? (T.titulo_txt || T.titulo) : T.titulo;
    panel.setAttribute('aria-label', titulo || 'Asistente');
    fase(v, 'aviso');

    var cab = C.el('div', 'uhp-voz__cab');
    cab.appendChild(C.el('h3', '', titulo));
    panel.appendChild(cab);

    var aviso = C.el('div', 'uhp-voz__aviso');
    aviso.setAttribute('tabindex', '0');
    aviso.setAttribute('role', 'document');
    // La plantilla del canal, no «la» plantilla: el aviso del canal
    // escrito no pide autorización para tratar una voz que no se captura.
    // Que existe ya se comprobó arriba.
    aviso.appendChild(plantilla.content.cloneNode(true));
    panel.appendChild(aviso);

    var pie = C.el('div', 'uhp-voz__pie');
    var rotulo = v.canal === 'texto' ? (T.aceptar_txt || T.aceptar) : T.aceptar;
    var si = boton(rotulo, 'uhp-voz__btn uhp-voz__btn--principal', function () {
      guardarConsentimiento(v, true);
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

    /* El permiso del micrófono se pide ANTES de pedirle credenciales al
       servidor, no después. Al revés se gastaba una ranura del cupo
       diario —que es facturable— y una URL firmada cada vez que alguien
       decía «no» al micrófono: el SDK pide el permiso por su cuenta antes
       de abrir la conexión, de modo que la sesión no llegaba a existir y
       la ranura se perdía igual, sin que nadie hablara con nadie.

       Las dos esperas van a la vez porque son independientes: descargar
       un megabyte de SDK no tiene por qué retrasar un diálogo del
       navegador. */
    /* Cada fallo en su propia casilla, en vez de devolverlo por la
       promesa. Devolviéndolo había que distinguir después «un error» de
       «un valor» con `instanceof Error`, y lo que rechaza getUserMedia es
       un DOMException: que su cadena de prototipos pase por Error es
       cierto en los motores de hoy, pero es un detalle del que no tiene
       por qué depender que una denegación de micrófono no se trague en
       silencio —si se tragara, el código seguiría a pedir credenciales,
       que es justo lo que este orden evita—. Con casillas, además, la
       prioridad entre los dos fallos es fija y no un orden de llegada. */
    var fallos = [null, null];
    Promise.all([
      permisoMicrofono(v).catch(function (e) { fallos[0] = e || new Error('sin-micro'); }),
      cargarSdk().catch(function (e) { fallos[1] = e || new Error('sin-sdk'); })
    ])
      .then(function () {
        // El del micrófono va primero a propósito: de los dos es el único
        // que el visitante puede resolver.
        for (var i = 0; i < fallos.length; i++) {
          if (fallos[i]) { throw fallos[i]; }
        }
        return credenciales(v);
      })
      .then(function (cred) { return abrir(v, cred, mia); })
      .catch(function (err) {
        // Un error de una sesión que el visitante ya abandonó no puede
        // pintar nada: el panel estaría mostrando el fallo de algo que
        // nadie está esperando.
        if (mia !== v.gen) { return; }
        fallar(v, err);
      });
  }

  /* En el canal escrito no se pide NADA: el SDK abre un TextConversation,
     que no toca navigator.mediaDevices ni crea un AudioContext.

     En el de voz se pide aquí, con la autorización ya informada por la
     compuerta. Pedirlo dos veces —aquí y dentro del SDK— no produce dos
     diálogos: concedido una vez, el navegador no vuelve a preguntar en el
     mismo origen. Las pistas se paran en cuanto llega la respuesta, para
     no dejar el indicador de grabación encendido mientras todavía se
     descarga el resto. */
  function permisoMicrofono(v) {
    if (v.canal === 'texto') { return Promise.resolve(); }

    var md = navigator.mediaDevices;
    if (!md || !md.getUserMedia) {
      // Se le pone el nombre que usa el navegador para que la
      // clasificación de fallar() no tenga que adivinar por el mensaje.
      var e = new Error('sin-micro-no');
      e.name = 'NotFoundError';
      return Promise.reject(e);
    }

    return md.getUserMedia({ audio: true }).then(function (flujo) {
      var vias = flujo.getTracks ? flujo.getTracks() : [];
      for (var i = 0; i < vias.length; i++) {
        try { vias[i].stop(); } catch (err) { /* ya estaba parada */ }
      }
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

  function credenciales(v) {
    if (CFG.modo !== 'privado') {
      // Una sesión de solo texto va siempre por WebSocket: es lo que
      // decide el propio SDK, y WebRTC arrastraría LiveKit para no
      // transportar ni un byte de audio.
      var transporte = v.canal === 'texto' ? 'websocket' : CFG.transporte;
      return Promise.resolve({ agentId: CFG.agente, connectionType: transporte });
    }
    // La base la pone el núcleo, y se lee con la misma tolerancia que él:
    // `window.UHP.rest` a secas lanzaba un TypeError en cualquier página
    // donde el bloque del núcleo no hubiera llegado, y lo que el visitante
    // habría visto es «no se pudo abrir la conversación».
    var base = (window.UHP && window.UHP.rest) || '';
    return fetch(base + CFG.ruta, {
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
    var escrito = v.canal === 'texto';
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
    // Siempre, aunque el tablero no haya cargado: si falta una sola de
    // las claves que el prompt interpola, el proveedor rechaza la sesión.
    opciones.dynamicVariables = variables(v, (mando && mando.disponible()) ? mando.estado() : null);

    if (escrito) {
      /* LAS DOS MITADES, y hacen falta las dos.

         `textOnly` es solo del cliente: impide que el SDK abra el
         micrófono y que reproduzca audio. Lo que hace que el servidor no
         SINTETICE ese audio es la sustitución, que es lo único que viaja
         en el evento de inicio. Con una sola de las dos, el proveedor
         seguiría generando voz —y cobrándola— para que el navegador la
         tirase a la basura. */
      opciones.textOnly = true;
      /* La sustitución solo si el servidor confirmó que el agente la
         admite. Enviarla sin confirmar no degrada nada: tumba la
         conversación entera con «Override is not allowed for this AI
         agent», y entonces el canal accesible —el único que le sirve a
         quien no puede hablar— sería el único que puede no abrir. Sin
         ella el canal escrito funciona igual; lo que pasa es que el
         proveedor sintetiza un audio que nadie oye y lo factura, y eso lo
         advierte el panel de administración. */
      if (CFG.textoServidor) {
        opciones.overrides = { conversation: { textOnly: true } };
      }
    } else {
      /* Explícito y no heredado: el SDK pide un bloqueo de pantalla por
         omisión, y aquí se deja puesto a conciencia. Durante una
         conversación hablada el visitante no toca el teléfono, y una
         pantalla que se apaga a media respuesta corta la conversación. */
      opciones.useWakeLock = true;

      /* Sustituciones de voz, SOLO si hay algo que sustituir. Un evento
         de sustitución vacío contra un agente que no las admite es una
         sesión rechazada sin que nadie haya pedido nada. */
      var tts = {};
      if (CFG.voz && CFG.voz.id) { tts.voiceId = CFG.voz.id; }
      if (CFG.voz && CFG.voz.velocidad) { tts.speed = CFG.voz.velocidad; }
      if (tts.voiceId || tts.speed) { opciones.overrides = { tts: tts }; }
    }
    // Se recuerda para que, si la sesión se cae al arrancar, el mensaje
    // pueda nombrar la causa más probable: un agente que no tiene
    // habilitadas esas sustituciones. Es la que nadie adivina.
    v.conSustituciones = !!opciones.overrides;

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

  /* Las variables dinámicas de ElevenLabs sustituyen los marcadores
     {{nombre}} del prompt del agente. Dos consecuencias que conviene no
     olvidar:

       · Hay que mandarlas SIEMPRE. Un prompt que escribe {{canal}} y una
         sesión que no manda «canal» es una sesión que el proveedor
         rechaza. Por eso aquí no hay ninguna rama que devuelva menos
         claves: cuando el tablero todavía no ha cargado sus datos, las
         claves van con un valor que lo dice.

       · Son el estado INICIAL y nada más. No se actualizan solas durante
         la conversación; lo que mantiene al agente al día es
         sendContextualUpdate y volver a llamar a las herramientas. El
         prompt del agente lo dice con esas palabras. */
  function variables(v, e) {
    var hay = !!e;
    return {
      // El agente necesita saber si le escuchan o le leen: cómo se dice
      // una cifra no es lo mismo en los dos canales, y lo que se lee se
      // puede copiar sin el resto de la conversación.
      canal: v.canal === 'texto' ? 'texto' : 'voz',
      indicador: hay ? e.indicadorEtiqueta : 'todavía sin cargar',
      zona: hay ? (e.zonaEtiqueta || 'todas') : 'todavía sin cargar',
      subregion: hay ? (e.subregion || 'todas') : 'todavía sin cargar',
      municipio: hay ? (e.municipio || 'ninguno') : 'todavía sin cargar',
      municipios_visibles: hay ? String(e.visibles) : 'todavía sin cargar',
      casos_visibles: hay ? String(e.casos) : 'todavía sin cargar',
      promedio_visible: hay ? (e.promedio === null ? 'sin dato' : String(e.promedio)) : 'todavía sin cargar'
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

    /* Una sesión que se cae en cuanto arranca, con sustituciones puestas,
       casi siempre es un agente que no las tiene habilitadas. Es la causa
       que nadie adivina —el panel de ElevenLabs las desactiva por
       omisión— y dejar al administrador delante de un «no se pudo abrir
       la conversación» le hace buscar donde no está. */
    if (v.conSustituciones && mensaje === T.sin_sesion && T.pista_sust) {
      mensaje += ' ' + T.pista_sust;
    }

    // Se guarda para que el pie pueda ofrecer el otro canal cuando lo que
    // falló fue el micrófono y no la conversación.
    v.errorMicro = (mensaje === T.sin_micro || mensaje === T.sin_micro_no
      || mensaje === T.ocupado || mensaje === T.sin_seguro);

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

      /* Lee el GRÁFICO DE BARRAS. Hace falta porque las otras ocho
         herramientas leen municipios o mueven filtros, y un ciudadano que
         pregunta «¿qué subregión está peor?» está preguntando por lo que
         ese gráfico dibuja.

         Dos cuidados que no son adorno:

           · La cifra de una barra es SUBREGIONAL y está publicada como
             tal. No es el promedio de sus municipios intervenidos, porque
             la subregión incluye municipios donde el proyecto no estuvo.
             Presentarla como «el promedio de la subregión» sería inventar
             un dato que nadie calculó.

           · El gráfico solo dibuja las subregiones CON cifra. Aquí se
             nombran también las que no la tienen. Un asistente que leyera
             nada más lo que se ve diría «trece subregiones» cuando son
             once, y dos territorios desaparecerían del mapa sin que nadie
             se enterase. */
      uhp_comparar_subregiones: function (p) {
        return conMando(v, function (m) {
          if (!m.rankingSubregiones) {
            return 'Este tablero no puede darme el gráfico de barras.';
          }
          var r = m.rankingSubregiones();
          var con = [];
          // Dos ausencias distintas, y confundirlas sería el error:
          // «sinDato» es una subregión que el tablero SÍ filtra y que no
          // tiene cifra de este indicador; «fuera» es una subregión del
          // departamento que el informe no publica y que el tablero no
          // puede filtrar porque el proyecto no intervino en ella.
          var sinDato = [];
          var fuera = [];
          r.filas.forEach(function (f) {
            if (f.valor !== null) { con.push(f); }
            else if (f.filtrable) { sinDato.push(f); }
            else { fuera.push(f); }
          });

          if (!con.length) {
            return 'No hay ninguna cifra subregional publicada para ' +
              r.indicadorEtiqueta + ', de modo que el gráfico de barras está vacío. ' +
              cola(r, con, sinDato, fuera);
          }

          var escrito = v.canal === 'texto';
          var tope = parseInt(p && p.cuantas, 10);
          if (!(tope > 0)) {
            // Por voz, tres. Una enumeración hablada de once elementos no
            // se retiene, y el agente acabaría leyendo una lista que nadie
            // puede seguir. Por escrito se puede releer: van todas.
            tope = escrito ? con.length : 3;
          }
          if (tope > con.length) { tope = con.length; }

          return escrito
            ? comoTexto(r, con, sinDato, fuera, tope)
            : comoVoz(r, con, sinDato, fuera, tope);
        });
      },

      /* El perfil de los 5.000 participantes. Está a la vista en el
         tablero —cuatro filas de barras— y el agente no podía leerlo, de
         modo que a «¿cuántas mujeres participaron?» solo podía contestar
         que no lo sabía teniendo el dato en pantalla.

         Dos rótulos viajan siempre con la respuesta:

           · DEPARTAMENTAL. El perfil está publicado para el conjunto de
             los 5.000 y no municipio a municipio. Con el tablero filtrado
             por una subregión estas cifras no cambian, y presentarlas como
             el perfil de la selección sería inventar un dato.

           · EL DENOMINADOR, cuando el informe lo da. En género son 4.994
             de los 5.000 los que tienen el campo, y en estado nutricional
             4.830. Repartir los porcentajes sobre 5.000 convertiría unas
             ausencias en personas. */
      uhp_consultar_perfil: function (p) {
        return conMando(v, function (m) {
          if (!m.perfil) { return 'Este tablero no puede darme el perfil de los participantes.'; }
          var perfil = m.perfil();
          if (!perfil || !perfil.bloques || !perfil.bloques.length) {
            return 'El perfil de los participantes todavía no ha cargado.';
          }

          var pedido = normalizar(String((p && p.aspecto) || ''));
          var bloques = perfil.bloques;
          if (pedido && pedido !== 'todo') {
            var filtrados = bloques.filter(function (b) {
              return normalizar(b.clave).indexOf(pedido) >= 0 ||
                normalizar(b.rotulo).indexOf(pedido) >= 0;
            });
            if (!filtrados.length) {
              return 'No tengo ese aspecto del perfil. Tengo: ' +
                bloques.map(function (b) { return b.rotulo; }).join(', ') + '.';
            }
            bloques = filtrados;
          }

          var txt = 'Perfil de los ' + perfil.participantes + ' participantes. ' +
            'Es una cifra DEPARTAMENTAL, del conjunto del estudio: no cambia con el ' +
            'filtro del tablero y no es el perfil de los municipios seleccionados. ';
          if (perfil.edad && perfil.edad.minimo) {
            txt += 'Edades entre ' + perfil.edad.minimo + ' y ' + perfil.edad.maximo + ' años. ';
          }

          bloques.forEach(function (b) {
            txt += b.rotulo + ': ' + b.filas.map(function (f) {
              var t = f.categoria + ' ' + cifra(f.porcentaje);
              if (f.personas !== null && f.personas !== undefined) {
                t += ' (' + f.personas + ' personas)';
              }
              return t;
            }).join('; ');
            if (b.n_con_dato && b.n_con_dato < perfil.participantes) {
              txt += '. Ojo: estos porcentajes son sobre ' + b.n_con_dato +
                ' participantes con el dato, no sobre ' + perfil.participantes +
                '; a ' + (perfil.participantes - b.n_con_dato) + ' no se les registró';
            }
            txt += '. ';
          });

          return txt;
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
    var c = m.catalogo();
    var txt = 'No encuentro esa subregión. Las que este tablero puede filtrar son: ' +
      c.subregiones.join(', ') + '.';
    // Y las que existen y no se pueden filtrar, con el motivo. Llamar
    // «las documentadas» a las once filtrables —como decía este mensaje—
    // borraba dos subregiones del departamento.
    if (c.subregionesSinCifra && c.subregionesSinCifra.length) {
      txt += ' ' + c.subregionesSinCifra.join(' y ') +
        (c.subregionesSinCifra.length > 1 ? ' también son' : ' también es') +
        ' de Nariño, pero el informe no publica su cifra y el proyecto no intervino ' +
        'ningún municipio suyo: no se pueden filtrar.';
    }
    return txt;
  }

  function describir(f, vecino) {
    var txt = [f.n, 'código DANE ' + f.c, 'subregión ' + f.sub, 'zona ' + f.zonaEtiqueta].join(', ') + '. ';
    txt += 'Lesión precursora ' + valorCon(f.lpm) + '. ';
    txt += 'H. pylori ' + valorCon(f.hp) + '. ';
    txt += f.casos
      ? (f.casos === 1 ? 'Un caso de cáncer gástrico detectado.' : f.casos + ' casos de cáncer gástrico detectados.')
      : 'Ningún caso de cáncer gástrico detectado.';
    /* Los participantes examinados, cuando el informe los publica para
       este municipio. Si no los publica, no se dice nada: el prorrateo de
       la tarjeta es una estimación del conjunto y darla como la cifra de un
       municipio sería inventarla. */
    if (f.participantes !== null && f.participantes !== undefined) {
      txt += ' ' + f.participantes + ' participantes examinados';
      if (f.positivosHp !== null && f.positivosHp !== undefined) {
        txt += ', ' + f.positivosHp + ' positivos a H. pylori';
      }
      txt += ' (dato publicado para este municipio).';
    }
    if (vecino) {
      txt += ' Aviso: hay otro municipio de nombre parecido, ' + vecino +
        '. Confirme con el visitante cuál quería antes de seguir.';
    }
    return txt;
  }

  /* El gráfico de barras, en palabras. Dos formatos, porque el canal
     cambia lo que se puede decir sin perder al que escucha o sin engañar
     al que copia. */

  function cobertura(f) {
    if (!f.municipios) { return ''; }
    return f.intervenidos + ' de sus ' + f.municipios + ' municipios intervenidos';
  }

  function cola(r, con, sinDato, fuera) {
    var txt = con.length + ' de las ' + r.total + ' subregiones de Nariño tienen cifra de este indicador. ';
    if (sinDato.length) {
      txt += 'Filtrables pero sin cifra de este indicador: ' +
        sinDato.map(function (f) { return f.subregion; }).join(', ') + '. ';
    }
    if (fuera.length) {
      txt += 'Sin cifra publicada y NO filtrables, porque el proyecto no intervino ningún municipio suyo: ' +
        fuera.map(function (f) {
          var c = cobertura(f);
          return f.subregion + (c ? ' (' + c + ')' : '');
        }).join(', ') + '. Nómbralas si enumeras subregiones. ';
    }
    /* Las tres cifras que se confunden, dichas una vez y juntas. Las
       subregionales publicadas coinciden con la media simple de sus
       municipios hasta la primera decimal en buena parte de los casos:
       nadie las distingue mirando, solo las separa el rótulo. */
    txt += 'Esta cifra NO es el promedio de los municipios intervenidos ni el promedio ' +
      'de la selección que da uhp_leer_estado_tablero: son tres números distintos. ' +
      'Para la cifra de un municipio, usa uhp_consultar_municipio.';
    return txt;
  }

  function comoTexto(r, con, sinDato, fuera, tope) {
    var lineas = con.slice(0, tope).map(function (f) {
      var trozos = [
        f.puesto + '. ' + f.subregion,
        // El rótulo va PEGADO a la cifra y no solo en la cabecera: lo que
        // el visitante copia de una conversación escrita sale sin el resto
        // del párrafo, y una cifra suelta sin su territorio se convierte
        // en un dato falso en cuanto alguien la pega en otra parte.
        cifra(f.valor) + ' (cifra de la subregión)'
      ];
      var c = cobertura(f);
      if (c) { trozos.push(c); }
      if (f.empatada) { trozos.push('empatada en este puesto'); }
      return trozos.join(' · ');
    });

    var txt = 'Gráfico de barras · ' + r.indicadorEtiqueta + ' por subregión · ' +
      'de mayor a menor · cada cifra es SUBREGIONAL publicada.\n' + lineas.join('\n') + '\n';
    if (tope < con.length) {
      txt += '(Son las ' + tope + ' más altas de las ' + con.length + ' que tienen cifra.)\n';
    }
    return txt + cola(r, con, sinDato, fuera);
  }

  function comoVoz(r, con, sinDato, fuera, tope) {
    // Sin ordinales y con los extremos: es lo que se retiene escuchando.
    var alto = con[0];
    var bajo = con[con.length - 1];

    var txt = 'Gráfico de barras de ' + r.indicadorEtiqueta +
      ' por subregión. Son cifras de la subregión y no de los municipios que el ' +
      'proyecto intervino. La más alta, ' + alto.subregion + ' con ' + cifra(alto.valor);
    var ca = cobertura(alto);
    if (ca && alto.intervenidos < alto.municipios) {
      txt += ', aunque allí el proyecto intervino ' + ca;
    }
    txt += '. ';

    var medias = con.slice(1, tope);
    if (medias.length) {
      txt += 'Después ' + medias.map(function (f) {
        return f.subregion + ' con ' + cifra(f.valor) + (f.empatada ? ', empatada' : '');
      }).join(', ') + '. ';
    }
    if (bajo !== alto && con.length > tope) {
      txt += 'La más baja, ' + bajo.subregion + ' con ' + cifra(bajo.valor) + '. ';
    }
    return txt + cola(r, con, sinDato, fuera);
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
      'promedio: ' + cifra(e.promedio),
      // Con su procedencia. El informe publica cuántos examinó en doce de
      // los 55 municipios y no en los demás: decir «participantes» a
      // secas mezclaría un dato con una estimación.
      (e.participantesPublicados === e.visibles && e.visibles > 0)
        ? ('participantes examinados, dato publicado: ' + e.participantes)
        : (e.participantesPublicados
          ? ('participantes examinados con cifra publicada: ' + e.participantes +
            ', en ' + e.participantesPublicados + ' de los ' + e.visibles +
            ' municipios a la vista; de los demás el informe no publica el desglose')
          : 'de estos municipios el informe no publica cuántas personas examinó en cada uno')
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
    pintarLanzadores(v, true);
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
    pintarLanzadores(v, false);
    if (v.nodos.panel) {
      v.nodos.panel.hidden = true;
      v.nodos.panel.innerHTML = '';
      v.nodos.panel.removeAttribute('role');
      v.nodos.panel.removeAttribute('aria-modal');
    }
    fase(v, 'inactivo');
    // Donde estaba el foco antes de abrir; y si eso ya no existe, el
    // botón que lo abrió, que es el que el visitante recuerda haber
    // pulsado. Dejarlo en el <body> deja a quien navega con teclado
    // teniendo que recorrer la página otra vez.
    var respaldo = v.lanzadorActivo || (v.lanzadores[0] && v.lanzadores[0].nodo) || null;
    var destino = (v.focoPrevio && v.focoPrevio.isConnected) ? v.focoPrevio : respaldo;
    v.focoPrevio = null;
    v.lanzadorActivo = null;
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
    cab.appendChild(C.el('h3', '', v.canal === 'texto' ? (T.titulo_txt || T.titulo) : T.titulo));
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

    /* Cuando lo que falló fue el micrófono y esta página también ofrece
       el canal escrito, se ofrece ese camino en vez de dejar al visitante
       con un mensaje y nada que pulsar. Abre una conversación NUEVA —el
       canal se fija al abrir la sesión y no se puede cambiar a mitad— y su
       propia compuerta, porque el aviso del canal escrito es otro. */
    if (v.fase === 'error' && v.errorMicro && v.canal === 'voz' && v.entrada !== 'voz') {
      pie.appendChild(boton(T.pasar_texto, 'uhp-voz__btn uhp-voz__btn--principal', function () {
        comenzar(v, 'texto');
      }));
    }

    if (CFG.texto && (v.fase === 'escucha' || v.fase === 'habla')) {
      if (!v.p.forma) { crearForma(v); }
      pie.appendChild(v.p.forma);
    }

    pie.appendChild(boton(T.retirar, 'uhp-voz__btn uhp-voz__btn--fino', function () {
      guardarConsentimiento(v, false);
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

    // Los lanzadores dicen lo que hacen AHORA, no lo que hacían al
    // cargar, y los dos a la vez: con el panel abierto, el botón del otro
    // canal también cierra.
    pintarLanzadores(v, f !== 'inactivo');

    /* Se anuncia lo que importa. En el canal hablado, «escucha» y «habla»
       se alternan muchas veces por frase y anunciarlas sería ruido
       continuo —además, ahí el propio audio dice quién tiene el turno—.
       En el escrito no hay audio ninguno: quien no ve la pantalla no tiene
       cómo saber que el asistente está redactando, y el cambio ocurre una
       vez por turno, no veinte. WCAG 2.1 §4.1.3. */
    var relevante = (f === 'cerrada' || f === 'error' || f === 'conectando');
    if (v.canal === 'texto' && (f === 'habla' || f === 'escucha')) { relevante = true; }
    if (relevante) { anunciar(v, texto); }
  }

  function textoDeFase(v) {
    var escrito = v.canal === 'texto';
    if (v.fase === 'aviso') { return T.aviso_fase; }
    if (v.fase === 'conectando') { return T.conectando; }
    // «Escuchando» a quien está teclando describe una pantalla que no
    // tiene delante, y es justo quien no la ve el que depende de esto.
    if (v.fase === 'escucha') { return escrito ? T.escribiendo : T.escuchando; }
    if (v.fase === 'habla') { return escrito ? T.redactando : T.hablando; }
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

  window.UHPVozAsistente = {
    iniciar: iniciar,
    abrir: abrirPorPrograma,
    cerrar: cerrarPorPrograma
  };
}());
