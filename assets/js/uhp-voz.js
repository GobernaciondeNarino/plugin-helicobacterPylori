/* Asistente del tablero — [urkunina_dashboard].

   El visitante pregunta por las cifras del proyecto; un modelo de
   lenguaje responde, mueve los filtros del tablero y —si la entidad
   configuró una voz— la respuesta se lee en alto.

   UNA SOLA CONVERSACIÓN, Y TRES CONTROLES.

   Antes había dos canales, voz y escrito, con dos lanzadores, dos
   avisos y dos autorizaciones. Se unificaron, y el motivo no fue la
   estética: el reparto hacía que una conversación abierta por el
   lanzador escrito no sonara NUNCA, ni con la voz perfectamente
   configurada. Como el panel se abría casi siempre por ahí, el
   asistente mostraba texto y no conversaba.

   Lo que queda es un botón, «Asistente», que abre una ventana con la
   estructura que mandó la entidad: el hilo, con el nombre de quien habla
   ENCIMA de cada globo; la línea de estado; «Puedes preguntar:» con sus
   pastillas; y una barra con el micrófono, el campo en píldora con
   «enviar» dentro, «parar» y «cerrar». La barra se monta UNA vez y
   después solo cambian atributos; reconstruirla en cada cambio de fase
   desprendía el <input> con el foco dentro, y con él se iba la tecla
   Escape.

   ENTRAR Y SALIR SON COSAS DISTINTAS, y conviene no volver a juntarlas:

     · El MICRÓFONO es una forma de entrar. Lo pone el navegador del
       visitante con SpeechRecognition, no cuesta nada y no necesita
       ninguna clave. En Firefox, Opera, Brave y Vivaldi no existe o
       falla al usarse, y entonces no se pinta.

     · La VOZ es una forma de salir. La pone ElevenLabs desde el
       servidor y necesita clave e identificador de voz. Sin ella el
       asistente responde igual, por escrito.

   Una instalación puede tener micrófono sin voz, voz sin micrófono, las
   dos o ninguna, y las cuatro combinaciones son conversaciones
   completas.

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

  /* UHP-CFG-01. Sin núcleo o sin configuración no hay asistente, y antes
     eso pasaba EN SILENCIO: el botón se quedaba deshabilitado y nadie
     sabía por qué. Casi siempre es un optimizador de JavaScript que
     separó el bloque en línea de este archivo. Se escribe con console
     directo porque sin núcleo no hay avisar(), y solo si la página tiene
     un asistente: en las demás este archivo no tendría por qué estar. */
  if (!C || !DATOS || !CFG) {
    var sinNucleo = function () {
      if (!document.querySelector('[data-uhp-voz]') || !window.console || !console.error) { return; }
      console.error('[URKUNINA 5000] UHP-CFG-01 · el script del asistente corrió sin el núcleo o sin su configuración · ' +
        (!C ? 'falta window.UHPcore' : 'falta window.UHPVoz'));
    };
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', sinNucleo); } else { sinNucleo(); }
    return;
  }

  /* Registro de instancias. Hace falta por la visibilidad «oculto», donde
     el plugin no pinta ningún botón y el de la entidad lo pone su
     plantilla: sin una puerta pública, «se abre por programa» era una
     promesa del panel de administración que este archivo no cumplía. */
  var INSTANCIAS = [];

  C.ready(function () {
    var nodos = document.querySelectorAll('[data-uhp-voz]');
    for (var i = 0; i < nodos.length; i++) { iniciar(nodos[i]); }

    /* Puerta declarativa: cualquier elemento de la plantilla con
       data-uhp-voz-abrir abre el asistente, sin escribir JavaScript. */
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

  function abrirPorPrograma(destino) {
    var v = instanciaDe(destino);
    if (!v) {
      // Dos tableros en la página y un botón que no dice cuál: hay que
      // indicarlo con data-uhp-voz-abrir="#id".
      avisar(null, 'UHP-PAN-02', INSTANCIAS.length + ' asistentes en la página');
      return false;
    }
    if (v.fase !== 'inactivo') { return true; }
    v.lanzadorActivo = null;
    comenzar(v);
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
    var micro = raiz.getAttribute('data-uhp-voz-micro') !== 'no';
    var v = {
      raiz: raiz,
      nodos: zonas(raiz),
      tablero: raiz.closest ? raiz.closest('[data-uhp-tablero]') : null,
      mando: null,
      conv: null,
      fase: 'inactivo',
      error: '',
      /* ¿Esta página ofrece el micrófono? Lo decide el servidor y la
         página puede estrechar lo que el sitio permite. Ya no hay
         «canal»: una sola conversación, siempre con cuadro de
         escritura, y con micrófono si esta página lo ofrece y el
         navegador sabe dictar. */
      micro: micro,
      lanzadores: [],
      lanzadorActivo: null,
      // Una pista que solo se usa si la sesión se cae al arrancar y hay
      // sustituciones configuradas: es la causa más probable y la más
      // difícil de adivinar desde el panel de administración.
      errorMicro: false,
      // La conversación, en el formato neutro que entiende el servidor.
      // Vive aquí y no en el servidor: cada petición la lleva entera,
      // que es lo que hace que el servidor no tenga que recordar nada
      // de nadie.
      hist: [],
      audio: null,
      audioUrl: null,
      escucha: null,
      oyendo: false,
      // Lo enciende el primer fallo del servicio de reconocimiento, y no
      // se apaga en toda la visita.
      micMuerto: false,
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
      focoPrevio: null,
      // La petición en curso, para que «Parar» y el cierre la corten en
      // la red; y hasta dónde se deshace el historial si se para.
      abortar: null,
      marca: null,
      botonOir: null,
      errorCodigo: ''
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
        v.lanzadores.push({ nodo: externos[k], externo: true });
      }
    }

    cablearRevocacion(v);

    window.addEventListener('pagehide', function () { soltar(v); });

    // Escape en el documento y no en la raíz: tras un repintado el foco
    // puede estar fuera del panel, y entonces la tecla no llegaría.
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape' || v.fase === 'inactivo') { return; }
      // Otro control ya usó la tecla, o un método de entrada está
      // cancelando lo que compone.
      if (e.defaultPrevented || e.isComposing) { return; }
      if (!v.raiz.isConnected) { return; }
      if (!focoEsDelAsistente(v)) { return; }
      cerrarPanel(v);
    });
  }

  /* Escape cierra cuando la persona está EN el asistente: foco dentro, en
     el <body> o en un lanzador propio de la plantilla. Con el foco en el
     mapa o en el modal de un gráfico, la tecla es de ellos: antes se
     cerraban los dos a la vez. */
  function focoEsDelAsistente(v) {
    var f = document.activeElement;
    if (!f || f === document.body || f === document.documentElement) { return true; }
    if (v.raiz.contains(f)) { return true; }
    var externo = f.closest ? f.closest('[data-uhp-voz-abrir]') : null;
    return !!(externo && instanciaDe(externo) === v);
  }

  /* RETIRAR LA AUTORIZACIÓN vive FUERA del panel, junto al aviso corto.
     Perdió su sitio al reducir la fila a tres controles, y no podía
     perderse sin más: el artículo 8(e) de la Ley 1581 da derecho a
     revocar, y un derecho que solo se puede ejercer abriendo primero
     aquello de lo que uno se quiere ir no es un derecho ejercible.

     Aquí además está mejor que donde estaba: se ve con el panel cerrado,
     que es justo cuando alguien decide que no quiere seguir.

     Solo aparece si hay algo que revocar. Sin aviso configurado no se
     guarda autorización ninguna, y un botón para retirar algo que nadie
     dio es una pregunta sin respuesta. */
  function cablearRevocacion(v) {
    if (!CFG.aviso || !CFG.recordar) { return; }
    var corto = v.raiz.querySelector('.uhp-voz__aviso-corto');
    if (!corto) { return; }

    var b = C.el('button', 'uhp-voz__retirar', T.retirar);
    b.type = 'button';
    b.hidden = !consentido();
    b.addEventListener('click', function () {
      guardarConsentimiento(v, false);
      soltar(v);
      anunciar(v, T.retirada);
      if (v.fase !== 'inactivo') { cerrarPanel(v); }
      b.hidden = true;
    });
    corto.appendChild(b);
    v.botonRetirar = b;
  }

  /* Uno solo. Puede haber además lanzadores propios de la plantilla, y
     a esos se les mantiene el aria-expanded al día: un botón que dice
     «cerrado» con el panel abierto le miente a quien no ve la pantalla. */
  function cablearLanzador(v, boton) {
    v.lanzadores.push({ nodo: boton });

    boton.addEventListener('click', function () {
      // Cualquier estado distinto de «inactivo» significa que el panel
      // está abierto: el botón cierra. Antes comparaba contra una fase
      // llamada 'activa' que no existe en ninguna parte, de modo que
      // volver a pulsarlo abría una segunda sesión encima de la primera
      // y la primera no se cerraba nunca.
      if (v.fase !== 'inactivo') { cerrarPanel(v); return; }
      v.lanzadorActivo = boton;
      comenzar(v);
    });

    // Sin JavaScript el botón no hace nada: sale deshabilitado del
    // servidor y se habilita aquí, que es cuando de verdad funciona.
    boton.disabled = false;
  }

  /* El lanzador dice SIEMPRE «Asistente». El estado lo dicen
     aria-expanded y el fondo: un rótulo que cambia junto con aria-expanded
     da el estado dos veces y contradice el patrón de botón desplegable. */
  function pintarLanzadores(v, abierto) {
    for (var i = 0; i < v.lanzadores.length; i++) {
      v.lanzadores[i].nodo.setAttribute('aria-expanded', abierto ? 'true' : 'false');
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

  /* Una sola clave: hay una sola conversación y un solo aviso. La
     versión va dentro a propósito —subirla en el panel invalida lo
     autorizado con el texto anterior— y así una instalación que venía
     de la versión de dos canales no arrastra un consentimiento dado
     sobre un aviso que ya no es el que se muestra. */
  function claveConsentimiento() {
    return 'uhp_voz_consentimiento_v' + CFG.avisoVersion;
  }

  function leido(clave) {
    try {
      return window.sessionStorage.getItem(clave) === '1';
    } catch (e) {
      // Ventana privada o almacenamiento bloqueado: la autorización se
      // pedirá cada vez. No es un fallo del sitio.
      avisarUnaVez(null, 'UHP-AVI-03', (e && e.name) || '');
      return false;
    }
  }

  function consentido() {
    if (!CFG.recordar) { return false; }
    return leido(claveConsentimiento());
  }

  function guardarConsentimiento(v, si) {
    if (!CFG.recordar) { return; }
    try {
      if (si && v && v.botonRetirar) { v.botonRetirar.hidden = false; }
      if (si) {
        window.sessionStorage.setItem(claveConsentimiento(), '1');
      } else {
        window.sessionStorage.removeItem(claveConsentimiento());
      }
    } catch (e) {
      // Sin almacenamiento, la autorización dura lo que dure el panel.
      avisarUnaVez(v, 'UHP-AVI-03', (e && e.name) || '');
    }
  }

  function comenzar(v) {
    abrirPanel(v);
    /* La compuerta de autorización es ahora un ajuste del sitio, no un
       requisito. Si la entidad la apagó, se entra directo. */
    if (!CFG.aviso || consentido()) { arrancarSesion(v); return; }
    pintarCompuerta(v);
  }

  function pintarCompuerta(v) {
    var panel = v.nodos.panel;
    if (!panel) { avisar(v, 'UHP-PAN-01'); return; }

    /* Sin la plantilla no hay aviso que leer, y un aviso VACÍO
       con un botón de aceptar es lo peor que puede ocurrir en este
       archivo: recogería una autorización sobre un texto que no existe.
       No debería pasar —la página solo imprime los avisos de los canales
       que ofrece, y abrirPorPrograma() rechaza los demás—, pero si pasa
       se para aquí. */
    var plantilla = v.nodos.aviso;
    if (!plantilla || !plantilla.content) {
      /* Un filtro del tema o de kses que quita la etiqueta <template>, o
         una copia en caché de antes de encender el aviso. */
      avisar(v, 'UHP-AVI-01');
      v.errorCodigo = 'UHP-AVI-01';
      montarConversacion(v);
      v.error = T.sin_sesion;
      fase(v, 'error');
      actualizarPie(v);
      return;
    }

    v.p = null;
    panel.innerHTML = '';
    panel.classList.remove('uhp-voz__panel--conversacion');
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');
    var titulo = T.titulo_dos;
    panel.setAttribute('aria-label', titulo || 'Asistente');
    fase(v, 'aviso');

    var cab = C.el('div', 'uhp-voz__cab');
    cab.appendChild(C.el('h3', '', titulo));
    panel.appendChild(cab);

    var aviso = C.el('div', 'uhp-voz__aviso');
    aviso.setAttribute('tabindex', '0');
    aviso.setAttribute('role', 'document');
    // El aviso que corresponde a esta página: el de una sin micrófono no
    // pide autorización para tratar una voz que no se captura. Que
    // existe ya se comprobó arriba.
    aviso.appendChild(plantilla.content.cloneNode(true));
    panel.appendChild(aviso);

    var pie = C.el('div', 'uhp-voz__pie');
    var rotulo = T.aceptar;
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

  /* Ya no hay «sesión» que abrir contra nadie: no hay SDK que descargar,
     ni credencial que pedir, ni socket que mantener. Empezar a conversar
     es montar el panel. La primera llamada al proveedor ocurre con la
     primera pregunta, y ni un segundo antes: así, abrir el panel y
     cerrarlo sin preguntar nada no cuesta un céntimo. */
  function arrancarSesion(v) {
    soltarTrampa(v);
    v.gen++;
    v.hist = [];
    v.ultimoEstado = '';

    montarConversacion(v);
    fase(v, 'escucha');
    actualizarPie(v);

    /* El saludo lo dice el panel, no el modelo. Pedirle al proveedor que
       salude cuesta un turno entero —el prompt del sistema y las diez
       herramientas viajan igual— para decir «hola». */
    turno(v, 'asistente', T.saludo);
    enfocarAlAbrir(v);
  }

  /* En táctil el foco va al hilo y no al campo: enfocar el campo saca el
     teclado de golpe y tapa media pantalla a quien venía a dictar. */
  function enfocarAlAbrir(v) {
    if (!v.p) { return; }
    var tactil = false;
    try { tactil = !!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches); } catch (e) { tactil = false; }
    var destino = tactil ? v.p.hilo : v.p.campo;
    if (!destino) { return; }
    try { destino.focus({ preventScroll: true }); } catch (e) { destino.focus(); }
  }

  /* ------------------------------------------------------------------ */
  /* El motor de la conversación                                        */
  /* ------------------------------------------------------------------ */

  /* Todo lo que sale de este navegador va a ESTE sitio. Las claves —la
     del modelo de lenguaje y la de la voz— están en el servidor y no
     bajan nunca. El servidor es un proxy sin memoria: recibe la
     conversación, le pregunta al proveedor y devuelve lo que diga.

     El BUCLE DE HERRAMIENTAS vive aquí y no allí, y no es un capricho:
     las herramientas son las que mueven el mapa y los filtros, y eso solo
     se puede hacer en la página que los tiene delante. El servidor no
     tiene tablero que mover. */

  /* LOS CÓDIGOS QUE DETECTA EL NAVEGADOR.

     Cada fallo tiene un código UHP-ÁREA-NN que es el MISMO en la consola,
     en el registro de PHP, en el panel de administración y en la pantalla
     del visitante. El área dice dónde mirar y el número qué pasó. Los
     que detecta el servidor viven en includes/class-uhp-codigos.php y
     llegan aquí con su frase; la tabla de los dos lados está en
     docs/voz/codigos.md.

     El nivel es del código y no de quien lo emite: `error` solo cuando
     alguien de la entidad tiene que actuar. Un micrófono denegado o un
     autoplay que Safari bloquea no pueden salir en rojo: un sitio sano
     tiene que tener cero errores en la consola, y si los fallos que no
     tienen arreglo dieran error, los de verdad dejarían de verse. */
  var CODIGOS = {
    // Este se escribe antes de existir el catálogo (sin núcleo no hay
    // avisar()); está aquí para que la tabla de documentación lo tenga.
    'UHP-CFG-01': ['error', 'el script del asistente corrió sin el núcleo o sin su configuración'],
    'UHP-CFG-02': ['error', 'falta la base de la API del sitio (window.UHP.rest)'],
    'UHP-CFG-03': ['warn', 'la página es de otra versión del plugin que el servidor'],
    'UHP-ACC-03': ['warn', 'el nonce caducó o la sesión se cerró: hay que recargar la página'],
    'UHP-RED-01': ['warn', 'el equipo del visitante está sin conexión'],
    'UHP-RED-02': ['error', 'la petición se quedó sin respuesta del sitio'],
    'UHP-RED-03': ['error', 'el sitio contestó con algo que no es JSON'],
    'UHP-RED-04': ['error', 'la ruta del asistente no existe en este sitio'],
    'UHP-RED-05': ['error', 'otra pieza de WordPress rechazó la petición'],
    'UHP-RED-06': ['error', 'error fatal de PHP al atender la ruta'],
    'UHP-RED-07': ['error', 'la respuesta de error no la generó este plugin'],
    'UHP-RED-08': ['error', 'el sitio contestó con una forma inesperada'],
    'UHP-AUD-01': ['error', 'el audio no se pudo decodificar'],
    'UHP-AUD-02': ['error', 'el navegador rechazó el audio al cargarlo'],
    'UHP-AUD-03': ['warn', 'el navegador no dejó sonar sin un gesto'],
    'UHP-AUD-04': ['error', 'el navegador no dejó reproducir el audio'],
    'UHP-AUD-05': ['warn', 'tampoco sonó con el gesto del visitante'],
    'UHP-MIC-01': ['info', 'este navegador no tiene reconocimiento de voz'],
    'UHP-MIC-02': ['info', 'el micrófono se apagó para esta visita tras un fallo'],
    'UHP-MIC-03': ['warn', 'no se pudo crear el reconocedor de voz'],
    'UHP-MIC-04': ['warn', 'se denegó el permiso del micrófono'],
    'UHP-MIC-05': ['warn', 'el navegador no ofrece el servicio de transcripción'],
    'UHP-MIC-06': ['warn', 'el servicio de transcripción del navegador no respondió'],
    'UHP-MIC-07': ['warn', 'no hay micrófono o lo está usando otra aplicación'],
    'UHP-MIC-08': ['warn', 'el navegador no transcribe español de Colombia'],
    'UHP-MIC-09': ['info', 'no se oyó nada mientras se esperaba la pregunta'],
    'UHP-MIC-10': ['warn', 'el reconocedor de voz no arrancó'],
    'UHP-MIC-11': ['info', 'el dictado terminó sin texto'],
    'UHP-MIC-99': ['warn', 'el reconocedor dio un motivo no previsto'],
    'UHP-CNV-01': ['info', 'pregunta descartada: el asistente todavía respondía'],
    'UHP-CNV-02': ['warn', 'se alcanzó el tope de vueltas de herramientas'],
    'UHP-CNV-03': ['warn', 'la respuesta llegó vacía'],
    'UHP-CNV-04': ['error', 'excepción inesperada en la conversación'],
    'UHP-HER-01': ['warn', 'el modelo pidió una herramienta que este navegador no tiene'],
    'UHP-HER-02': ['error', 'una herramienta lanzó una excepción'],
    'UHP-HER-03': ['warn', 'el tablero rechazó el cambio'],
    'UHP-HER-04': ['info', 'se agotó el presupuesto de cambios del tablero'],
    'UHP-HER-05': ['info', 'el argumento de la herramienta no cruza con el tablero'],
    'UHP-HER-06': ['info', 'la selección espera la confirmación del visitante'],
    'UHP-HER-07': ['error', 'la confirmación no logró seleccionar el municipio'],
    'UHP-TAB-01': ['error', 'no hay mando del tablero'],
    'UHP-TAB-02': ['warn', 'el tablero todavía no tiene datos'],
    'UHP-TAB-03': ['error', 'al mando le falta una función que pide la herramienta'],
    'UHP-TAB-04': ['warn', 'el perfil de los participantes no ha cargado'],
    'UHP-TAB-05': ['warn', 'no se pudo leer el estado de la pantalla'],
    'UHP-AVI-01': ['error', 'el aviso está encendido pero la página no trae su plantilla'],
    'UHP-AVI-03': ['info', 'el navegador no deja guardar la autorización'],
    'UHP-PAN-01': ['error', 'falta el nodo del panel del asistente'],
    'UHP-PAN-02': ['warn', 'un botón no sabe qué asistente abrir'],
    'UHP-PAN-03': ['error', 'no se pudo dibujar un icono de la barra'],
    'UHP-PAN-04': ['info', 'la persona paró la consulta, la voz o el dictado'],
    'UHP-PAN-05': ['info', 'este navegador no puede cortar la petición en la red'],
    'UHP-PAN-06': ['info', 'no hay preguntas sugeridas: la fila no se pinta']
  };

  /* Las últimas veinte entradas, SOLO EN MEMORIA. Con
     copy(UHPVozAsistente.diagnostico()) el soporte recibe texto en vez de
     una captura. Nunca en localStorage: en bibliotecas y puntos de acceso
     público los equipos son compartidos, y un rastro que sobrevive a la
     visita no le pertenece a quien se sienta después. */
  var DIARIO = [];
  var YA_DICHO = {};

  /* TODO lo que va a la consola pasa por aquí, en UNA cadena por fallo:

       [URKUNINA 5000] UHP-MIC-04 · se denegó el permiso del micrófono · not-allowed · v2.1.0

     Una cadena y no objetos, porque en una captura un objeto sale como
     {…}. El detalle sale de una lista cerrada —nombres de error del
     navegador, estado HTTP, nombre de herramienta, ref, ruta— y NUNCA
     lleva la pregunta, la transcripción ni el texto del modelo.

     `extra.frase` y `extra.nivel` los trae el servidor para sus propios
     códigos: así la consola sigue siendo legible aunque esta página sea
     de una versión anterior y no conozca el código. `extra.err` es un
     Error de verdad y va como segundo argumento, para conservar su pila. */
  function avisar(v, codigo, detalle, extra) {
    extra = extra || {};
    var fila = CODIGOS[codigo];
    var nivel = extra.nivel || (fila ? fila[0] : 'error');
    var frase = extra.frase || (fila ? fila[1] : 'código sin catalogar');

    var trozos = ['[URKUNINA 5000] ' + codigo, frase];
    if (detalle) { trozos.push(String(detalle)); }
    if (extra.ref) { trozos.push('ref ' + extra.ref); }
    if (extra.ruta) { trozos.push(extra.ruta); }
    if (CFG.version) { trozos.push('v' + CFG.version); }
    var linea = trozos.join(' · ');

    DIARIO.push({ hora: new Date().toISOString(), codigo: codigo, nivel: nivel, detalle: detalle ? String(detalle) : '', ref: extra.ref || '' });
    if (DIARIO.length > 20) { DIARIO.shift(); }

    /* Para las pruebas y para quien inspecciona la página: el último
       código que no fue informativo. No se borra al recuperarse. */
    if (v && v.raiz && nivel !== 'info') {
      v.raiz.setAttribute('data-uhp-voz-error', codigo);
      if (extra.ref) { v.raiz.setAttribute('data-uhp-voz-ref', extra.ref); } else { v.raiz.removeAttribute('data-uhp-voz-ref'); }
    }

    if (!window.console) { return; }
    var fn = (nivel === 'info' ? console.info : (nivel === 'warn' ? console.warn : console.error)) || console.warn;
    try {
      if (extra.err) { fn.call(console, linea, extra.err); } else { fn.call(console, linea); }
    } catch (e) { /* una consola que falla no puede tumbar el panel */ }
  }

  // Para los que basta decir una vez por página: no tiene arreglo y
  // repetirlo en cada pulsación es ruido.
  function avisarUnaVez(v, codigo, detalle, extra) {
    if (YA_DICHO[codigo]) { return; }
    YA_DICHO[codigo] = true;
    avisar(v, codigo, detalle, extra);
  }

  // Un argumento libre, recortado: es lo que extrajo el modelo y solo va
  // a la consola de quien está en este equipo.
  function corto(x) {
    var t = String(x == null ? '' : x).replace(/\s+/g, ' ').trim();
    return t.length > 40 ? t.slice(0, 40) + '…' : t;
  }

  /* El code de WordPress de un servidor de antes de los códigos, o de una
     pieza que no es este plugin, traducido al código UHP que le toca. */
  function codigoDeWordPress(wp, http, ruta) {
    var porCode = {
      uhp_voz_inactivo: 'UHP-ACC-01',
      uhp_voz_origen: 'UHP-ACC-04',
      uhp_rate_limit: 'UHP-ACC-05',
      uhp_voz_cuota: 'UHP-ACC-06',
      uhp_voz_vacio: 'UHP-CNV-05',
      uhp_voz_larga: 'UHP-CNV-06',
      uhp_voz_sin_voz: 'UHP-CFG-04',
      uhp_voz_red: 'UHP-VOZ-01',
      rest_no_route: 'UHP-RED-04',
      rest_cookie_invalid_nonce: 'UHP-ACC-03'
    };
    if (porCode[wp]) { return porCode[wp]; }
    if (wp === 'uhp_voz_proveedor') { return ruta === CFG.rutaVoz ? 'UHP-VOZ-99' : 'UHP-LLM-99'; }
    if (wp && wp.indexOf('rest_') === 0 && (http === 401 || http === 403)) { return 'UHP-RED-05'; }
    if (http >= 500 && (!wp || wp === 'internal_server_error')) { return 'UHP-RED-06'; }
    return 'UHP-RED-07';
  }

  /* Qué frase ve el visitante, según el código. El mensaje del servidor
     NO se pinta: no le dice nada a quien pregunta por el cáncer gástrico
     en su municipio. */
  function claveVisible(codigo) {
    if (codigo === 'UHP-ACC-06' || codigo === 'UHP-ACC-07') { return 'sin-cuota'; }
    if (codigo === 'UHP-ACC-05') { return 'muchas'; }
    if (codigo === 'UHP-CNV-06') { return 'larga'; }
    if (codigo === 'UHP-LLM-11' || codigo === 'UHP-LLM-12') { return 'sin-respuesta'; }
    if (codigo === 'UHP-RED-01') { return 'sin-red'; }
    return 'sin-sesion';
  }

  /* LOS ICONOS. Constantes de este archivo: nada del modelo ni del
     servidor pasa nunca por aquí. Rejilla de 20, trazo 1,75, puntas
     redondas.

     El micrófono va DOS veces en su botón —tachado en reposo, entero
     escuchando— y el CSS enseña el que dice aria-pressed: el estado que
     se ve y el que se oye salen del mismo atributo y no pueden
     contradecirse. La diagonal del tachado va por y = x − 1 y no por el
     centro: centrada, el trozo de abajo quedaba pegado a la cápsula y a
     20 px se leía como un borrón. */
  var SVG_ABRE = '<svg class="uhp-voz__ico" viewBox="0 0 20 20" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">';
  var SVG_MICRO_REPOSO = SVG_ABRE.replace('uhp-voz__ico"', 'uhp-voz__ico uhp-voz__ico--reposo"')
    + '<path d="M12.5 7.25v-3a2.5 2.5 0 0 0-4.47-1.54"/><path d="M7.5 6.5v3.25a2.5 2.5 0 0 0 4.6 1.35"/>'
    + '<path d="M4.75 8.75v.75a5.25 5.25 0 0 0 9.2 3.45"/><path d="M15.14 10.59a5.25 5.25 0 0 0 .11-1.09v-.75"/>'
    + '<path d="M10 14.75v3M7.25 17.75h5.5"/><path d="M3.75 2.75l13.5 13.5"/></svg>';
  var SVG_MICRO_ACTIVO = SVG_ABRE.replace('uhp-voz__ico"', 'uhp-voz__ico uhp-voz__ico--activo"')
    + '<path d="M10 1.75a2.5 2.5 0 0 1 2.5 2.5v5.5a2.5 2.5 0 0 1-5 0v-5.5a2.5 2.5 0 0 1 2.5-2.5Z"/>'
    + '<path d="M4.75 8.75v.75a5.25 5.25 0 0 0 10.5 0v-.75"/><path d="M10 14.75v3M7.25 17.75h5.5"/></svg>';
  var SVG_ENVIAR = SVG_ABRE + '<path d="M10 15.75V4.5"/><path d="M5.25 9.25 10 4.5l4.75 4.75"/></svg>';
  var SVG_PARAR = SVG_ABRE + '<rect class="uhp-voz__ico-relleno" x="5.5" y="5.5" width="9" height="9" rx="1.5" fill="currentColor"/></svg>';
  var SVG_CERRAR = SVG_ABRE + '<path d="M5.5 5.5l9 9M14.5 5.5l-9 9"/></svg>';

  function pedir(v, ruta, cuerpo) {
    // La base la pone el núcleo, y se lee con la misma tolerancia que él:
    // `window.UHP.rest` a secas lanza un TypeError en una página donde el
    // bloque del núcleo no haya llegado.
    var base = (window.UHP && window.UHP.rest) || '';
    if (!base) { avisarUnaVez(v, 'UHP-CFG-02', 'se pide a una ruta relativa'); }

    /* El nonce solo viaja cuando el servidor lo mandó, y solo lo manda
       con la visibilidad en «editores». Sin él, WordPress trata la
       petición con cookie como anónima y las dos rutas contestan 403:
       el modo de ensayo era el único que no funcionaba. */
    var cab = { 'Content-Type': 'application/json' };
    if (CFG.nonce) { cab['X-WP-Nonce'] = CFG.nonce; }

    /* Cada fallo sale de aquí como un Error con su código UHP colgado, y
       quien lo recibe escribe UNA línea en la consola. Antes pedir()
       escribía una y quien la recibía otra, de modo que cada fallo
       aparecía dos veces con dos frases distintas. */
    function fallo(codigo, detalle, extra) {
      extra = extra || {};
      var e = new Error(claveVisible(codigo));
      e.uhp = codigo;
      e.detalle = detalle || '';
      e.frase = extra.frase || '';
      e.nivel = extra.nivel || '';
      e.ref = extra.ref || '';
      e.ruta = ruta;
      return e;
    }

    /* Cada petición lleva su interruptor, y el de la última queda en
       v.abortar para que «Parar» y el cierre la corten en la red. Cortar
       aquí NO devuelve lo gastado: si el servidor ya preguntó al
       proveedor, esa consulta cuenta en el cupo. */
    var ctl = null;
    if (typeof window.AbortController === 'function') {
      ctl = new window.AbortController();
      v.abortar = ctl;
    } else {
      avisarUnaVez(v, 'UHP-PAN-05', '«Parar» descarta la respuesta pero no corta la petición');
    }
    var op = { method: 'POST', credentials: 'same-origin', headers: cab, body: JSON.stringify(cuerpo) };
    if (ctl) { op.signal = ctl.signal; }
    var suelta = function () { if (v.abortar === ctl) { v.abortar = null; } };

    return fetch(base + ruta, op).then(function (r) {
      /* LA VERSIÓN. Si la página es de una y el servidor de otra, es una
         copia en caché sin purgar después de actualizar: la causa número
         uno de «no suena». Una vez por página. */
      var delServidor = r.headers && r.headers.get ? r.headers.get('X-UHP-Version') : '';
      if (delServidor && CFG.version && delServidor !== CFG.version) {
        avisarUnaVez(v, 'UHP-CFG-03', 'página ' + CFG.version + ' · servidor ' + delServidor);
      }

      if (r.ok) {
        return r.json().then(function (d) {
          if (!d || typeof d !== 'object') { throw fallo('UHP-RED-08', 'HTTP ' + r.status + ' sin objeto'); }
          // Lo que no corta la conversación pero conviene que se sepa: una
          // respuesta recortada, un historial podado. Con el nivel y la
          // frase del servidor.
          if (d.avisos && d.avisos.length) {
            d.avisos.forEach(function (a) {
              if (a && a.codigo) { avisar(v, String(a.codigo), '', { frase: a.frase, nivel: a.nivel || 'warn', ruta: ruta }); }
            });
          }
          return d;
        }, function (e) {
          if (e && (e.uhp || e.name === 'AbortError')) { throw e; }
          // Casi siempre son avisos de PHP impresos antes del JSON, o una
          // caché que sirve HTML con 200.
          throw fallo('UHP-RED-03', 'HTTP ' + r.status + (e && e.name ? ' · ' + e.name : ''));
        });
      }

      /* El cuerpo SÍ se lee. WordPress devuelve ahí el `code` del
         WP_Error —uhp_voz_inactivo, uhp_voz_origen, uhp_voz_cuota,
         uhp_voz_larga, uhp_voz_proveedor—, y sin él dos cosas muy
         distintas se confundían: los dos 403 de las puertas (asistente
         no disponible y origen no válido) caían los dos en «no pude
         consultar», y el 503 de una voz sin configurar se anunciaba como
         «alcanzó su límite de consultas de hoy», que es mentira.

         Se lee para DIAGNOSTICAR, no para enseñárselo al visitante: lo
         que se pinta en el panel sigue siendo la frase corta de siempre.
         Un mensaje del servidor en pantalla no le dice nada a quien está
         preguntando por el cáncer gástrico en su municipio. */
      return r.json().catch(function () { return null; }).then(function (d) {
        var wp = (d && typeof d.code === 'string') ? d.code : '';
        var uhp = (d && d.data && d.data.uhp && typeof d.data.uhp.codigo === 'string') ? d.data.uhp : null;
        var cabecera = r.headers && r.headers.get ? r.headers.get('X-UHP-Codigo') : '';

        /* El código, en este orden: el que trae el cuerpo, el de la
           cabecera, el que corresponde al code de WordPress y, si no hay
           nada, RED-07. Que falte el código ES el diagnóstico: la
           respuesta no la generó este plugin. */
        var codigo = uhp ? uhp.codigo : (cabecera || codigoDeWordPress(wp, r.status, ruta));
        var detalle = 'HTTP ' + r.status + ((!uhp && wp) ? ' · ' + wp : '');
        throw fallo(codigo, detalle, uhp ? { frase: uhp.frase, nivel: uhp.nivel, ref: uhp.ref } : {
          ref: r.headers && r.headers.get ? (r.headers.get('X-UHP-Ref') || '') : ''
        });
      });
    }, function (e) {
      if (e && e.uhp) { throw e; }
      // Una parada propia no es un fallo: la pidió «Parar» o el cierre.
      if (e && e.name === 'AbortError') { throw e; }
      /* fetch rechazado: no hubo respuesta. Sin conexión es cosa del
         equipo del visitante; con conexión, la línea roja del propio
         navegador justo encima dice qué fue (CSP, certificado, extensión). */
      var sinRed = (typeof navigator !== 'undefined' && navigator.onLine === false);
      throw fallo(sinRed ? 'UHP-RED-01' : 'UHP-RED-02', (e && e.name ? e.name : '') + (e && e.message ? ': ' + e.message : ''));
    }).then(function (d) { suelta(); return d; }, function (e) { suelta(); throw e; });
  }

  function abortarPeticion(v) {
    if (!v.abortar) { return; }
    try { v.abortar.abort(); } catch (e) { /* ya había terminado */ }
    v.abortar = null;
  }

  /* Un turno entero: entra la pregunta del visitante y sale la respuesta.
     En medio puede haber varias vueltas, porque el modelo pide
     herramientas, hay que ejecutarlas y devolvérselas para que siga. */
  function preguntar(v, texto) {
    if (!texto) { return Promise.resolve(); }

    /* LA GUARDIA VIVE AQUÍ, no en el formulario. Estaba solo en el
       submit, de modo que el dictado entraba igual mientras el asistente
       pensaba: dos cadenas de vueltas concurrentes sobre el MISMO
       historial, con el mismo testigo de generación, y ninguna
       descartaba a la otra. El historial quedaba intercalado —la
       pregunta B empujada entre la pregunta A y su respuesta— y las dos
       acababan pidiendo audio, con lo que la primera, parada a media
       carga, enmudecía a la segunda.

       Puesta aquí, deja de importar si la pregunta vino del teclado, del
       micrófono o de una confirmación. */
    if (v.fase === 'pensando' || v.fase === 'habla') {
      avisar(v, 'UHP-CNV-01');
      return Promise.resolve();
    }

    var mia = v.gen;
    // Hasta aquí se deshace el historial si se pulsa «Parar».
    v.marca = v.hist.length;
    v.errorCodigo = '';

    /* Qué hay en pantalla, antes de la pregunta y solo cuando cambió.
       Dos razones, y ninguna es de estilo:

       La primera es que sin esto, cada conversación empieza con una
       vuelta entera —el prompt del sistema y las diez herramientas viajan
       otra vez— para que el modelo averigüe lo que el navegador ya sabe.

       La segunda importa más: el visitante puede mover el tablero a mano
       mientras conversa. Si el modelo no se entera, sigue hablando de una
       pantalla que ya no existe y le lee cifras de otro municipio a quien
       no está mirando. */
    var ahora = estadoDePantalla(v);
    if (ahora && ahora !== v.ultimoEstado) {
      v.ultimoEstado = ahora;
      v.hist.push({ rol: 'usuario', texto: 'En pantalla ahora mismo: ' + ahora });
    }

    v.hist.push({ rol: 'usuario', texto: texto });
    turno(v, 'usted', texto);
    fase(v, 'pensando');

    var util = herramientas(v);
    var vueltas = 0;
    var tope = Math.max(1, CFG.turnos || 8);

    function vuelta() {
      if (mia !== v.gen) { return Promise.resolve(); }

      return pedir(v, CFG.rutaChat, { mensajes: v.hist }).then(function (d) {
        if (mia !== v.gen) { return; }

        var dicho = (d && typeof d.texto === 'string') ? d.texto : '';
        var llamadas = (d && d.llamadas && d.llamadas.length) ? d.llamadas : [];

        v.hist.push({ rol: 'asistente', texto: dicho, llamadas: llamadas });

        if (!llamadas.length) {
          if (dicho) {
            turno(v, 'asistente', dicho);
            hablar(v, dicho);
          } else {
            /* Desde la 2.1 el servidor convierte una respuesta vacía en
               UHP-LLM-12. Que llegue una aquí es la pista de un servidor
               de versión anterior. Y no se deja al visitante sin nada. */
            avisar(v, 'UHP-CNV-03');
            turno(v, 'asistente', T.sin_respuesta);
            hablar(v, T.sin_respuesta);
          }
          return;
        }

        /* Lo que dijo ANTES de pedir herramientas también se pinta. Suele
           ser «déjeme mirar»: callarlo deja la pantalla muda mientras el
           tablero se mueve solo, que es justo cuando el visitante necesita
           saber que alguien está haciendo algo. */
        if (dicho) { turno(v, 'asistente', dicho); }

        vueltas++;
        if (vueltas >= tope) {
          /* Un tope, y se para de verdad en vez de pedirle al modelo que
             se pare. Cada vuelta cuesta: el historial entero viaja otra
             vez. Un modelo que se enreda gastaría la conversación dando
             vueltas y el visitante vería el tablero moverse sin respuesta. */
          /* Y se dice en alto, como cualquier otra respuesta. Quedaba
             muda: el panel entregaba una frase y el canal de audio no la
             acompañaba, de modo que quien depende del oído no se
             enteraba de que había habido respuesta. */
          avisar(v, 'UHP-CNV-02', 'tope ' + tope);
          /* Y CADA LLAMADA CON SU RESULTADO, aunque no se ejecute. Los tres
             proveedores exigen que a una llamada le siga su resultado: sin
             esto, la pregunta siguiente de esta conversación daba 400 y el
             asistente se quedaba mudo hasta cerrar el panel. */
          v.hist.push({
            rol: 'herramienta',
            resultados: llamadas.map(function (l) {
              return { id: l.id, nombre: l.nombre, salida: 'No se ejecutó: se alcanzó el tope de vueltas.' };
            })
          });
          turno(v, 'asistente', T.vueltas);
          hablar(v, T.vueltas);
          return;
        }

        v.hist.push({ rol: 'herramienta', resultados: ejecutar(v, util, llamadas) });
        return vuelta();
      });
    }

    return vuelta().catch(function (err) {
      if (mia !== v.gen) { return; }
      if (err && err.name === 'AbortError') { return; }
      fallar(v, err);
    });
  }

  /* Ejecuta lo que el modelo pidió. Lo que no reconoce se le devuelve
     dicho, no en silencio: un resultado vacío le invita a repetir la
     llamada, y así se gastan las vueltas. */
  function ejecutar(v, util, llamadas) {
    var salida = [];
    for (var i = 0; i < llamadas.length; i++) {
      var l = llamadas[i] || {};
      var fn = util[l.nombre];
      var r;
      if (typeof fn !== 'function') {
        // Un JS en caché anterior a las herramientas del servidor, o un
        // nombre inventado por el modelo.
        avisar(v, 'UHP-HER-01', corto(l.nombre));
        r = 'No existe una herramienta con ese nombre.';
      } else {
        v.herramientaActual = l.nombre;
        try {
          r = fn(l.args || {});
        } catch (e) {
          r = 'La herramienta falló.';
          avisar(v, 'UHP-HER-02', l.nombre + ' · ' + ((e && e.name) || 'Error') + ': ' + ((e && e.message) || ''), { err: e });
        }
        v.herramientaActual = '';
      }
      salida.push({
        id: l.id,
        nombre: l.nombre,
        salida: String(r === null || r === undefined ? '' : r)
      });
    }
    return salida;
  }

  /* ------------------------------------------------------------------ */
  /* La voz: la pone el servidor y aquí solo suena                      */
  /* ------------------------------------------------------------------ */

  function hablar(v, texto) {
    /* AQUÍ ESTABA EL SILENCIO. La condición era
       `!CFG.voz || v.canal !== 'voz' || !texto`, y el término del medio
       significaba que una conversación abierta por el lanzador escrito
       no sonaba NUNCA, ni con la voz perfectamente configurada. Como el
       panel se abría casi siempre por ahí, el asistente mostraba texto y
       no conversaba. Ahora la voz depende solo de que haya voz. */
    if (!CFG.voz || !texto) {
      fase(v, 'escucha');
      return;
    }
    var mia = v.gen;
    fase(v, 'habla');

    pedir(v, CFG.rutaVoz, { texto: texto }).then(function (d) {
      if (mia !== v.gen) { return; }
      if (!d || !d.audio) {
        avisar(v, 'UHP-RED-08', 'la síntesis contestó sin audio', { ruta: CFG.rutaVoz });
        fase(v, 'escucha');
        return;
      }
      reproducir(v, d.tipo || 'audio/mpeg', d.audio);
    }).catch(function (e) {
      if (e && e.name === 'AbortError') { return; }
      /* Que no suene no es un fallo de la conversación: la respuesta ya
         está escrita en el panel. Se sigue, en silencio PARA EL
         VISITANTE, en vez de pintarle un error a quien ya tiene delante
         lo que preguntó. Pero no en silencio para quien tenga que
         averiguar por qué no suena: eso antes no dejaba ni un rastro. */
      if (e && e.uhp) {
        avisar(v, e.uhp, e.detalle, { frase: e.frase, nivel: e.nivel, ref: e.ref, ruta: e.ruta });
      } else {
        avisar(v, 'UHP-CNV-04', ((e && e.name) || 'Error') + ': ' + ((e && e.message) || ''), { err: e });
      }
      if (mia === v.gen) { fase(v, 'escucha'); }
    });
  }

  function reproducir(v, tipo, base64) {
    pararAudio(v);

    var a;
    var url;
    try {
      var cadena = window.atob(base64);
      var bytes = new Uint8Array(cadena.length);
      for (var i = 0; i < cadena.length; i++) { bytes[i] = cadena.charCodeAt(i); }
      url = window.URL.createObjectURL(new Blob([bytes], { type: tipo }));
      a = new Audio(url);
    } catch (e) {
      // Una respuesta truncada por un proxy con límite de tamaño, casi siempre.
      avisar(v, 'UHP-AUD-01', (e && e.name) || 'Error');
      fase(v, 'escucha');
      return;
    }

    var mia = v.gen;
    v.audio = a;
    v.audioUrl = url;

    /* CADA REPRODUCCIÓN CIERRA SOBRE LA SUYA, y esto no es puntillismo.

       Antes `fin` cerraba sobre `v` y acababa en `pararAudio(v)`, que
       actuaba sobre lo que hubiera en `v.audio` EN ESE INSTANTE. Los
       callbacks del elemento anterior —`ended`, `error` y el rechazo de
       su promesa de `play()`— seguían vivos después de que `v.audio`
       apuntara a otro, de modo que un callback rezagado paraba y
       revocaba el audio que estaba sonando AHORA.

       Y el disparador no era hipotético: `pause()` sobre un elemento con
       una promesa de `play()` pendiente la rechaza con `AbortError`, y
       ese rechazo es una microtarea. Si para entonces ya había arrancado
       una reproducción nueva, el `catch` de la vieja mataba a la nueva. */
    var fin = function () {
      soltarAudio(a, url);
      if (v.audio === a) {
        v.audio = null;
        v.audioUrl = null;
        if (mia === v.gen && v.fase === 'habla') { fase(v, 'escucha'); }
      }
    };

    a.addEventListener('ended', fin);
    a.addEventListener('error', function () {
      /* Solo se anota si este elemento SIGUE siendo el vigente. Revocar
         una URL mientras el elemento la está cargando hace que emita
         `error`, de modo que una parada deliberada se registraba como
         «el navegador rechazó el audio»: la propia parada ensuciaba el
         único rastro que servía para diagnosticar. */
      if (v.audio === a) {
        /* El número de MediaError dice cuál: con 4, casi siempre es la
           CSP del sitio sin `media-src 'self' blob:`, y el navegador
           imprime la violación justo antes; con 3, datos corruptos. */
        avisar(v, 'UHP-AUD-02', 'MediaError ' + ((a.error && a.error.code) || '?'));
      }
      fin();
    });

    var p = a.play();
    if (p && p.catch) {
      p.catch(function (e) {
        var cual = (e && e.name) || 'motivo desconocido';

        /* `AbortError` es nuestra propia parada: lo pidió `pararAudio`.
           No es un fallo y no se anota. */
        if ('AbortError' === cual) { fin(); return; }

        /* `NotAllowedError` es el navegador negándose a sonar sin un
           gesto. Entre el clic del visitante y este `play()` hay dos
           viajes de red como mínimo —con herramientas, hasta ocho—, y
           Safari evalúa el permiso contra el elemento, no contra el
           documento. Aquí NO se tira el audio: se ofrece oírlo con una
           pulsación, que es un gesto nuevo y sí vale. */
        if ('NotAllowedError' === cual && v.audio === a) {
          avisar(v, 'UHP-AUD-03', 'NotAllowedError · se ofrece «' + T.oir + '»');
          ofrecerAudio(v, a);
          return;
        }

        avisar(v, 'UHP-AUD-04', cual);
        fin();
      });
    }
  }

  /* Un botón de un solo uso para oír la respuesta cuando el navegador no
     dejó sonarla sola. Va en el hilo, junto al turno que no se oyó, y no
     en la fila de controles: es del turno, no del panel. */
  function ofrecerAudio(v, a) {
    if (!v.p || !v.p.hilo.isConnected) { return; }

    var b = boton(T.oir, 'uhp-voz__btn uhp-voz__btn--fino', function () {
      if (v.audio !== a) { quitarOir(v); return; }
      // Suena: es «habla», y «Parar» se activa. El foco pasa a él ANTES de
      // quitar este botón, que si no se iría al <body> con el nodo.
      fase(v, 'habla');
      if (v.p && v.p.parar) { v.p.parar.focus(); }
      quitarOir(v);
      var p2 = a.play();
      if (p2 && p2.catch) {
        p2.catch(function (e2) {
          avisar(v, 'UHP-AUD-05', (e2 && e2.name) || 'motivo desconocido');
          if (v.fase === 'habla') { fase(v, 'escucha'); }
        });
      }
    });
    v.botonOir = b;
    v.p.hilo.appendChild(b);
    v.p.hilo.scrollTop = v.p.hilo.scrollHeight;
    if (v.fase === 'habla') { fase(v, 'escucha'); }
  }

  /* Parar y soltar UN elemento concreto. Separado de `pararAudio` para
     que un callback rezagado pueda limpiar lo suyo sin tocar lo ajeno. */
  function soltarAudio(a, url) {
    if (a) {
      try { a.pause(); } catch (e) { /* ya estaba parado */ }
    }
    if (url) {
      try { window.URL.revokeObjectURL(url); } catch (e) { /* ya se fue */ }
    }
  }

  function pararAudio(v) {
    soltarAudio(v.audio, v.audioUrl);
    v.audio = null;
    v.audioUrl = null;
    // El botón apuntaba a un audio cuya URL acaba de revocarse.
    quitarOir(v);
  }

  function quitarOir(v) {
    if (v.botonOir && v.botonOir.parentNode) { v.botonOir.parentNode.removeChild(v.botonOir); }
    v.botonOir = null;
  }

  /* ------------------------------------------------------------------ */
  /* El micrófono: lo pone el navegador                                 */
  /* ------------------------------------------------------------------ */

  /* Pulsar para hablar, y no escucha continua. Dos razones, y la segunda
     pesa más: un micrófono siempre abierto en la página de una entidad
     pública es algo que hay que justificar, y aquí no hace falta. */
  function motorVoz() {
    return window.SpeechRecognition || window.webkitSpeechRecognition || null;
  }

  /* EL DICTADO ESPERA A QUE LA PERSONA TERMINE.

     Antes el reconocedor iba con `continuous = false`, y eso significa
     que el navegador da por terminada la frase en la PRIMERA pausa y
     entrega solo ese trozo. Quien decía «¿cuántos municipios… hay en la
     zona roja?» con un respiro en medio mandaba «¿cuántos municipios»,
     y el asistente contestaba a media pregunta. Es justo el público de
     este tablero —personas de 40 a 65 años— el que hace esas pausas.

     Ahora son tres reglas:

       1. ESPERA A QUE EMPIECE. Hasta que no llega la primera palabra no
          corre ningún reloj. Si el navegador se cansa de esperar —Chrome
          cierra a los pocos segundos de silencio inicial con 'no-speech'—
          se vuelve a abrir, hasta ESPERA_MAX_MS.

       2. NO CORTA EN LAS PAUSAS. Modo continuo y resultados parciales: el
          texto se va acumulando y se ve en el campo mientras se dicta. Si
          el navegador cierra solo a mitad —Chrome en Android lo hace tras
          cada frase—, se vuelve a abrir y se sigue acumulando.

       3. TERMINA CUANDO CALLA. Desde la última palabra corre un reloj de
          `CFG.silencio` segundos, configurable en el panel. Si no dice
          nada más, se entrega la pregunta entera. Pulsar el micrófono
          otra vez la entrega ya; pulsar «parar» la descarta. */
  var ESPERA_MAX_MS = 20000;
  var REINICIOS_MAX = 12;

  function escuchar(v) {
    var Motor = motorVoz();
    /* `v.micMuerto` lo pone el primer fallo del servicio. La detección
       de característica NO basta: en Brave, Vivaldi y Opera el objeto
       existe —así que `motorVoz()` devuelve algo— y falla después, en
       tiempo de ejecución, porque el motor de transcripción es un
       servicio de Google que esos navegadores no tienen. Reintentar ahí
       es pedirle al visitante que pulse un botón roto. */
    if (!Motor || v.micMuerto) {
      avisar(v, Motor ? 'UHP-MIC-02' : 'UHP-MIC-01', Motor ? '' : 'sin SpeechRecognition');
      v.errorCodigo = Motor ? 'UHP-MIC-02' : 'UHP-MIC-01';
      v.errorMicro = true;
      v.error = T.sin_reconocimiento;
      fase(v, 'error');
      actualizarPie(v);
      return;
    }

    // Pulsar otra vez mientras escucha ENTREGA lo dictado: es la forma
    // de no esperar al silencio cuando uno ya sabe que terminó.
    if (v.oyendo && v.dictado) { pedirFinDictado(v, v.dictado); return; }

    // Ni se abre mientras el asistente está respondiendo: lo que se
    // dictara ahí se descartaría en preguntar(), y haber hablado para
    // nada es peor que un botón que no responde.
    if (v.fase === 'pensando') {
      anunciar(v, T.pensando);
      return;
    }

    /* Se calla el asistente antes de abrir el micrófono. Si no, su propia
       voz entra por el micrófono, se transcribe como si la hubiera dicho
       la persona, y el asistente se pone a conversar consigo mismo. */
    pararAudio(v);
    if (v.fase === 'habla') { fase(v, 'escucha'); }

    if (v.fase === 'error') { v.error = ''; v.errorCodigo = ''; v.errorMicro = false; fase(v, 'escucha'); }

    var d = {
      gen: v.gen,
      finales: '',
      parcial: '',
      hablo: false,
      inicio: Date.now(),
      reinicios: 0,
      reloj: null,
      seguro: null,
      terminando: false,
      terminado: false,
      motor: Motor
    };
    v.dictado = d;
    if (v.p && v.p.campo) { v.p.campo.value = ''; }

    if (!abrirMotor(v, d)) { return; }
    v.oyendo = true;
    anunciar(v, T.oyendo);
    actualizarPie(v);
  }

  /* Abre UNA instancia del reconocedor. Se llama al empezar y cada vez
     que el navegador cierra solo antes de tiempo. */
  function abrirMotor(v, d) {
    var r;
    try {
      r = new d.motor();
    } catch (e) {
      avisar(v, 'UHP-MIC-03', (e && e.name) || 'Error');
      soltarDictado(v, d);
      v.errorCodigo = 'UHP-MIC-03';
      v.errorMicro = true;
      v.error = T.sin_reconocimiento;
      fase(v, 'error');
      actualizarPie(v);
      return false;
    }

    r.lang = 'es-CO';
    r.continuous = true;
    r.interimResults = true;
    r.maxAlternatives = 1;

    r.onresult = function (e) {
      if (!vigente(v, d)) { return; }
      var parcial = '';
      var desde = (e && typeof e.resultIndex === 'number') ? e.resultIndex : 0;
      var res = (e && e.results) || [];
      for (var i = desde; i < res.length; i++) {
        var t = (res[i] && res[i][0] && res[i][0].transcript) || '';
        if (res[i].isFinal) {
          d.finales = juntar(d.finales, t);
        } else {
          parcial = juntar(parcial, t);
        }
      }
      d.parcial = parcial;

      var todo = juntar(d.finales, d.parcial);
      if (todo) {
        d.hablo = true;
        // Se ve en el campo mientras se dicta: quien habla comprueba que
        // se le está entendiendo, y quien no ve la pantalla lo oirá como
        // su turno cuando se entregue.
        if (v.p && v.p.campo) { v.p.campo.value = todo; }
        relojSilencio(v, d);
      }
    };

    r.onerror = function (e) {
      if (!vigente(v, d)) { return; }
      var cual = (e && e.error) || '';

      /* 'no-speech' es «todavía no ha dicho nada»: no es un fallo y no se
         corta, que es exactamente lo que pidió la entidad —que espere a
         que la persona hable—. Lo resuelve `onend`, volviendo a abrir.
         'aborted' es nuestra propia parada. */
      if ('no-speech' === cual || 'aborted' === cual) { return; }

      /* El motivo lo da el navegador y decide la frase y si se apaga el
         micrófono. Es el dato que separa «la persona dijo que no»
         ('not-allowed') de «este navegador no puede» ('network',
         'service-not-allowed') de «no hay micrófono» ('audio-capture'). */
      var porMotivo = {
        'not-allowed': 'UHP-MIC-04',
        'service-not-allowed': 'UHP-MIC-05',
        'network': 'UHP-MIC-06',
        'audio-capture': 'UHP-MIC-07',
        'language-not-supported': 'UHP-MIC-08'
      };
      /* 'not-allowed' junta tres causas que el navegador no deja
         distinguir: la persona dijo que no, la cabecera Permissions-Policy
         del sitio, o una página sin HTTPS. */
      avisar(v, porMotivo[cual] || 'UHP-MIC-99', cual || 'sin motivo');
      v.errorCodigo = porMotivo[cual] || 'UHP-MIC-99';
      v.errorMicro = true;
      if ('not-allowed' === cual) {
        v.error = T.sin_micro;
      } else {
        v.micMuerto = true;
        v.error = T.sin_reconocimiento;
      }
      soltarDictado(v, d);
      fase(v, 'error');
      actualizarPie(v);
    };

    r.onend = function () {
      if (!vigente(v, d)) { return; }

      // Lo pidió el reloj de silencio o un segundo toque: se entrega.
      if (d.terminando) { entregarDictado(v, d); return; }

      /* El navegador cerró SOLO. Antes eso era el final; ahora no: si la
         persona aún no habló, se la sigue esperando; si ya habló, puede
         estar tomando aire, y lo decide el reloj de silencio. */
      var esperando = !d.hablo && (Date.now() - d.inicio) < ESPERA_MAX_MS;
      if ((esperando || d.hablo) && d.reinicios < REINICIOS_MAX) {
        d.reinicios++;
        if (abrirMotor(v, d)) { return; }
        return;
      }

      if (d.hablo) {
        entregarDictado(v, d);
      } else {
        // Se acabó la espera sin oír nada. Se dice, y el micrófono queda
        // listo para otro intento.
        avisar(v, 'UHP-MIC-09', Math.round(ESPERA_MAX_MS / 1000) + ' s');
        soltarDictado(v, d);
        anunciar(v, T.no_oi);
        actualizarPie(v);
      }
    };

    try {
      r.start();
      d.instancia = r;
      v.escucha = r;
      return true;
    } catch (e) {
      // `start()` sobre un reconocedor ya activo lanza InvalidStateError.
      // Casi siempre una doble pulsación rápida.
      avisar(v, 'UHP-MIC-10', (e && e.name) || 'Error');
      soltarDictado(v, d);
      actualizarPie(v);
      return false;
    }
  }

  function vigente(v, d) {
    return v.dictado === d && d.gen === v.gen && !d.terminado;
  }

  function juntar(a, b) {
    return (String(a || '') + ' ' + String(b || '')).replace(/\s+/g, ' ').trim();
  }

  // Cada palabra nueva reinicia la cuenta: lo que se mide es el silencio
  // DESPUÉS de la última, no el tiempo desde que se pulsó.
  function relojSilencio(v, d) {
    window.clearTimeout(d.reloj);
    var ms = Math.max(1000, Math.min(6000, (Number(CFG.silencio) || 2) * 1000));
    d.reloj = window.setTimeout(function () {
      if (vigente(v, d)) { pedirFinDictado(v, d); }
    }, ms);
  }

  /* Se le pide al navegador que cierre con `stop()` —no `abort()`—,
     porque `stop()` todavía entrega como definitivo lo último que
     estaba oyendo. Y un seguro por si `onend` no llega nunca, que en
     algunos navegadores pasa tras un corte de red. */
  function pedirFinDictado(v, d) {
    if (d.terminando) { return; }
    d.terminando = true;
    window.clearTimeout(d.reloj);
    d.seguro = window.setTimeout(function () {
      if (vigente(v, d)) { entregarDictado(v, d); }
    }, 1500);
    try {
      if (d.instancia) { d.instancia.stop(); }
    } catch (e) {
      entregarDictado(v, d);
    }
  }

  function entregarDictado(v, d) {
    if (d.terminado) { return; }
    var texto = juntar(d.finales, d.parcial);
    soltarDictado(v, d);
    actualizarPie(v);
    if (v.p && v.p.campo) { v.p.campo.value = ''; }
    if (texto) { preguntar(v, texto); return; }
    // Safari a veces cierra sin evento de error y sin texto: sin esto el
    // visitante creía que el botón no hacía nada.
    avisar(v, 'UHP-MIC-11');
    anunciar(v, T.no_oi);
  }

  function soltarDictado(v, d) {
    if (!d) { return; }
    d.terminado = true;
    window.clearTimeout(d.reloj);
    window.clearTimeout(d.seguro);
    if (v.dictado === d) { v.dictado = null; }
    if (v.escucha === d.instancia) { v.escucha = null; }
    v.oyendo = false;
  }

  // Parar SIN entregar: lo dictado se descarta. Es lo que hace el botón
  // de parar, cerrar la ventana y la tecla Escape.
  function pararEscucha(v) {
    var d = v.dictado;
    if (d) {
      soltarDictado(v, d);
      if (d.instancia) {
        try { d.instancia.abort(); } catch (e) { /* ya terminó */ }
      }
      if (v.p && v.p.campo) { v.p.campo.value = ''; }
    } else if (v.escucha) {
      try { v.escucha.abort(); } catch (e) { /* ya terminó */ }
      v.escucha = null;
    }
    v.oyendo = false;
  }

  /* Suelta TODO lo que una conversación deja abierto. Lo llaman el cierre
     a mano, el corte del proveedor, el fallo y la salida de la página: si
     cada uno soltara lo suyo, alguno se dejaría algo. */
  function soltar(v, conservarEstadoUi) {
    window.clearTimeout(v.temporizadorCtx);
    if (v.desuscribir) { v.desuscribir(); v.desuscribir = null; }

    // Lo que de verdad queda abierto ahora es el audio sonando y el
    // micrófono escuchando. No hay sesión que cerrar contra nadie.
    pararAudio(v);
    pararEscucha(v);
    abortarPeticion(v);
    v.marca = null;

    // Lo sugerido y lo confirmado son de UNA conversación. Si sobreviven,
    // la confirmación por pulsación que protege de elegir el municipio
    // equivocado se sortea en la conversación siguiente.
    v.sugerido = null;
    v.confirmado = null;
    v.mutaciones = [];
    quitarConfirmacion(v);
    if (!conservarEstadoUi) {
      v.gen++;
      // La conversación también. Volver a abrir el panel empieza de cero:
      // conservarla haría que el visitante pagase otra vez, en cada
      // pregunta, un historial que ya no está mirando.
      v.hist = [];
    }
  }

  function fallar(v, err) {
    soltar(v, true);

    /* La clave la pone quien lanza, no se adivina del mensaje: los
       mensajes de red cambian entre navegadores y entre versiones, y una
       clasificación por texto acaba enseñándole al visitante el error
       equivocado. */
    var clave = (err && err.uhp) ? err.message : '';
    var mensaje = T.sin_sesion;

    if (clave === 'sin-cuota') { mensaje = T.sin_cuota; }
    else if (clave === 'muchas') { mensaje = T.muchas; }
    else if (clave === 'larga') { mensaje = T.larga; }
    else if (clave === 'sin-respuesta') { mensaje = T.sin_respuesta; }
    else if (clave === 'sin-red') { mensaje = T.sin_red || T.sin_sesion; }

    if (err && err.uhp) {
      avisar(v, err.uhp, err.detalle, { frase: err.frase, nivel: err.nivel, ref: err.ref, ruta: err.ruta });
    } else {
      // Sin código es un defecto de este archivo, no del servidor: va con
      // la pila del Error.
      avisar(v, 'UHP-CNV-04', ((err && err.name) || 'Error') + ': ' + ((err && err.message) || ''), { err: err });
    }

    v.error = mensaje;
    v.errorCodigo = (err && err.uhp) || 'UHP-CNV-04';
    v.errorMicro = false;
    fase(v, 'error');
    actualizarPie(v);
  }

  function ocupado(v) { return v.fase === 'pensando' || v.fase === 'habla'; }

  /* «PARAR»: corta lo que esté en curso y deja la ventana lista. No
     cierra, no borra el hilo y no toca el tablero.
       · ESPERA del modelo → se aborta y el historial vuelve a antes de la
         pregunta: una pregunta abandonada que siguiera ahí la contestaría
         el modelo en el turno siguiente, mezclada con la nueva.
       · VOZ pidiéndose o sonando → se aborta o se para.
       · DICTADO → se corta SIN entregar.
     v.gen sube: lo que vuelva después es de una pregunta abandonada. */
  function detener(v) {
    var consultando = v.fase === 'pensando';
    var hablando = v.fase === 'habla' || !!(v.audio && !v.audio.paused);
    var dictando = !!v.oyendo;
    if (!consultando && !hablando && !dictando) { return; }

    v.gen++;
    abortarPeticion(v);
    pararEscucha(v);
    pararAudio(v);

    if (consultando && typeof v.marca === 'number' && v.marca <= v.hist.length) {
      v.hist.length = v.marca;
      v.ultimoEstado = '';
    }
    v.marca = null;

    avisar(v, 'UHP-PAN-04', [consultando ? 'consulta' : '', hablando ? 'voz' : '', dictando ? 'dictado' : ''].filter(Boolean).join(' + '));
    fase(v, 'escucha');
    actualizarPie(v);
    if (consultando) { nota(v, T.parado); } else { anunciar(v, T.parado); }
  }

  function nota(v, texto) {
    if (!v.p || !v.p.hilo.isConnected) { return; }
    var n = C.el('p', 'uhp-voz__nota', texto);
    if (v.confirmaCaja && v.confirmaCaja.parentNode === v.p.hilo) { v.p.hilo.insertBefore(n, v.confirmaCaja); }
    else { v.p.hilo.appendChild(n); }
    v.p.hilo.scrollTop = v.p.hilo.scrollHeight;
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
            return noCruza(v, val, 'Solo puedo mostrar dos indicadores: lesión precursora de malignidad e infección por H. pylori.');
          }
          if (!gastar(v)) { return sinCupo(v); }
          if (!m.setIndicador(val)) { return rechazo(v, 'No pude cambiar el indicador.'); }
          var e = m.estado();
          return 'Indicador cambiado a ' + e.indicadorEtiqueta + '. Promedio de la selección: ' + cifra(e.promedio) + '.';
        });
      },

      uhp_filtrar_zona_riesgo: function (p) {
        return conMando(v, function (m) {
          var z = p && p.zona;
          var destino = (!z || z === 'todas') ? null : String(z).toLowerCase();
          if (destino && m.catalogo().zonas.indexOf(destino) < 0) {
            return noCruza(v, z, 'No conozco esa zona. Las zonas son roja, amarilla y verde.');
          }
          if (!gastar(v)) { return sinCupo(v); }
          if (!m.setZona(destino)) { return rechazo(v, 'No pude aplicar ese filtro.'); }
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
            if (!gastar(v)) { return sinCupo(v); }
            if (!m.setSubregion(null)) { return rechazo(v, 'No pude quitar ese filtro.'); }
            return 'Filtro de subregión retirado: ' + m.estado().visibles + ' municipios.';
          }
          var nombre = cruzarSubregion(m, texto);
          if (!nombre) { return noCruza(v, texto, sinSubregion(m)); }
          if (!gastar(v)) { return sinCupo(v); }
          if (!m.setSubregion(nombre)) { return rechazo(v, 'No pude aplicar ese filtro.'); }
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
            avisar(v, 'UHP-HER-06', r.sugerencias.join(', '));
            pedirConfirmacion(v, r.sugerencias);
            return 'Hay varios municipios con ese nombre de cerca: ' + r.sugerencias.join(', ') +
              '. Le puse los botones en pantalla para que elija.';
          }
          if (r.estado === 'sugerencia') {
            v.sugerido = [r.sugerencia];
            avisar(v, 'UHP-HER-06', r.sugerencia);
            pedirConfirmacion(v, [r.sugerencia]);
            return 'No encontré ese municipio. ¿Quiso decir ' + r.sugerencia +
              '? Le puse un botón en pantalla para confirmarlo.';
          }
          if (r.estado !== 'exacto') {
            return noCruza(v, p && p.municipio, 'No encuentro ese municipio entre los 55 que intervino el proyecto.');
          }
          // Lo que llegó por sugerencia necesita la pulsación aunque ahora
          // cruce exacto: si no, el modelo se autoconfirma en el turno
          // siguiente y la salvaguarda no sirve de nada.
          if (v.sugerido && v.sugerido.indexOf(r.municipio.n) >= 0 && v.confirmado !== r.municipio.n) {
            avisar(v, 'UHP-HER-06', r.municipio.n);
            pedirConfirmacion(v, v.sugerido);
            return 'Necesito que confirme ' + r.municipio.n + ' con el botón de la pantalla.';
          }
          if (!gastar(v)) { return sinCupo(v); }
          if (!m.setMunicipio(r.municipio.n)) { return rechazo(v, 'No pude seleccionarlo.'); }
          // De un solo uso: ni dentro de la misma conversación vale dos veces.
          v.sugerido = null;
          v.confirmado = null;
          return describir(r.municipio, r.vecino);
        });
      },

      uhp_limpiar_tablero: function () {
        return conMando(v, function (m) {
          if (!gastar(v)) { return sinCupo(v); }
          if (m.limpiar() === false) { return rechazo(v, 'No pude quitar los filtros.'); }
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
        if (r.estado !== 'exacto') { return noCruza(v, p && p.municipio, 'No encuentro ese municipio entre los 55.'); }
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
            // uhp-dashboard.js y este archivo son de versiones distintas.
            avisar(v, 'UHP-TAB-03', 'rankingSubregiones');
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

          /* TODAS, siempre. Esta herramienta le da DATOS al modelo, no
             redacta la respuesta del visitante: recortar aquí a tres
             sería decidir por el modelo con información que él no tiene,
             y dejarlo sin con qué contestar si le piden la lista
             completa. Cuántas se dicen en alto lo decide el prompt, que
             es donde vive esa regla. Cada línea lleva pegado su rótulo
             «(cifra de la subregión)»: eso no es formato, es honestidad,
             y va igual se lea o se escuche. */
          var tope = parseInt(p && p.cuantas, 10);
          if (!(tope > 0) || tope > con.length) { tope = con.length; }

          return comoTexto(r, con, sinDato, fuera, tope);
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
          if (!m.perfil) {
            avisar(v, 'UHP-TAB-03', 'perfil');
            return 'Este tablero no puede darme el perfil de los participantes.';
          }
          var perfil = m.perfil();
          if (!perfil || !perfil.bloques || !perfil.bloques.length) {
            avisar(v, 'UHP-TAB-04');
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
              return noCruza(v, p && p.aspecto, 'No tengo ese aspecto del perfil. Tengo: ' +
                bloques.map(function (b) { return b.rotulo; }).join(', ') + '.');
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
            return noCruza(v, z, 'No conozco esa zona. Las zonas son roja, amarilla y verde.');
          }
          filtro.zona = z;
        }
        if (p && p.subregion) {
          var sr = cruzarSubregion(m, p.subregion);
          if (!sr) { return noCruza(v, p.subregion, sinSubregion(m)); }
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
  /* Lo mismo que devuelve uhp_leer_estado_tablero, y a propósito: dos
     descripciones distintas de la misma pantalla se contradicen el día
     que una de las dos se queda atrás. Si el tablero aún no cargó
     devuelve cadena vacía, y entonces no se manda nada: decirle al modelo
     «no sé» no le sirve de más que no decirle nada. */
  function estadoDePantalla(v) {
    var m = mandoDe(v);
    if (!m || !m.disponible()) { return ''; }
    try {
      return resumenHablado(m.estado());
    } catch (e) {
      // La pregunta sigue, sin ese contexto.
      avisar(v, 'UHP-TAB-05', (e && e.name) || 'Error', { err: e });
      return '';
    }
  }

  function conMando(v, fn) {
    var m = mandoDe(v);
    if (!m) {
      // uhp-dashboard.js no corrió, o el asistente quedó fuera de un
      // [data-uhp-tablero].
      avisar(v, 'UHP-TAB-01', v.herramientaActual || '');
      return 'El tablero todavía no terminó de cargar.';
    }
    if (!m.disponible()) {
      avisar(v, 'UHP-TAB-02', v.herramientaActual || '');
      return 'El tablero todavía no terminó de cargar.';
    }
    try {
      return fn(m);
    } catch (e) {
      avisar(v, 'UHP-HER-02', (v.herramientaActual || 'herramienta') + ' · ' + ((e && e.name) || 'Error') + ': ' + ((e && e.message) || ''), { err: e });
      return 'No pude hacer ese cambio.';
    }
  }

  /* Los tres desenlaces de una herramienta que no cambian nada, con su
     código y devolviendo lo que el modelo tiene que leer. */
  function rechazo(v, txt) {
    avisar(v, 'UHP-HER-03', v.herramientaActual || '');
    return txt;
  }
  function sinCupo(v) {
    avisar(v, 'UHP-HER-04', v.herramientaActual || '');
    return T.demasiado;
  }
  function noCruza(v, arg, txt) {
    avisar(v, 'UHP-HER-05', (v.herramientaActual || '') + ' · «' + corto(arg) + '»');
    return txt;
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
      v.nodos.panel.classList.remove('uhp-voz__panel--conversacion');
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

  /* Monta la ventana UNA vez: hilo, línea de estado, pastillas y barra.
     Lo que cambia después cambia por textContent o añadiendo un nodo al
     hilo, nunca repintando: el campo de texto tiene que sobrevivir a que
     el agente hable. */
  function montarConversacion(v) {
    var panel = v.nodos.panel;
    if (!panel) { avisar(v, 'UHP-PAN-01'); return; }
    panel.innerHTML = '';
    panel.removeAttribute('role');
    panel.removeAttribute('aria-modal');
    // Lo deja la compuerta; sin rol no nombra nada.
    panel.removeAttribute('aria-label');
    panel.classList.add('uhp-voz__panel--conversacion');

    var base = v.raiz.id || 'uhp-voz';

    // La captura no tiene cabecera: el título queda para quien no ve la
    // pantalla, fuera del flujo.
    var titulo = C.el('h3', 'uhp-voz__sr', T.titulo_dos);
    titulo.id = base + '-titulo';
    panel.appendChild(titulo);

    var hilo = C.el('div', 'uhp-voz__hilo');
    // role="log" y tabindex: una caja con overflow que no puede recorrerse
    // con el teclado deja la transcripción fuera del alcance de quien no
    // usa ratón, y un <div> a secas descarta su propio aria-label.
    hilo.setAttribute('role', 'log');
    hilo.setAttribute('tabindex', '0');
    hilo.setAttribute('aria-label', T.transcripcion);
    hilo.appendChild(C.el('p', 'uhp-voz__vacio', T.conectando));
    panel.appendChild(hilo);

    /* La línea de estado, pegada a la barra: es donde mira quien acaba de
       pulsar. El código del fallo va al lado, visible, pero NO en la
       región viva: el lector no tiene por qué deletrearlo. */
    var estado = C.el('p', 'uhp-voz__estado-txt');
    var pulso = C.el('i', 'uhp-voz__pulso');
    pulso.setAttribute('aria-hidden', 'true');
    var estadoTxt = C.el('span', '', '');
    var codigo = C.el('span', 'uhp-voz__codigo', '');
    codigo.hidden = true;
    estado.appendChild(pulso);
    estado.appendChild(estadoTxt);
    estado.appendChild(codigo);
    panel.appendChild(estado);

    v.p = {
      panel: panel, hilo: hilo, vacio: hilo.firstChild, estadoTxt: estadoTxt, codigo: codigo,
      forma: null, campo: null, mic: null, enviar: null, parar: null, cerrar: null,
      pastillas: []
    };

    var sug = crearSugerencias(v, base);
    if (sug) { panel.appendChild(sug); }
    panel.appendChild(crearFila(v, base));
    actualizarPie(v);
  }

  /* «PUEDES PREGUNTAR:». Botones en un grupo nombrado por el rótulo, que
     se quedan toda la conversación: quitar la pastilla recién pulsada se
     llevaría el foco al <body>, y quitar la fila entera cambiaría el alto
     de la ventana debajo de la persona. Pulsarla ENVÍA la pregunta tal
     cual; no la copia al campo, porque lo que haya escrito ahí es suyo. */
  function crearSugerencias(v, base) {
    var lista = Object.prototype.toString.call(CFG.sugerencias) === '[object Array]' ? CFG.sugerencias : [];
    var textos = [];
    for (var i = 0; i < lista.length && textos.length < 8; i++) {
      var t = String(lista[i] == null ? '' : lista[i]).trim();
      if (t) { textos.push(t); }
    }
    if (!textos.length) {
      avisarUnaVez(v, 'UHP-PAN-06');
      return null;
    }

    var caja = C.el('div', 'uhp-voz__sugerencias');
    var rotulo = C.el('span', 'uhp-voz__sug-rotulo', T.sugerencias);
    rotulo.id = base + '-sugerencias';
    var fila = C.el('div', 'uhp-voz__pastillas');
    fila.setAttribute('role', 'group');
    fila.setAttribute('aria-labelledby', rotulo.id);

    textos.forEach(function (texto) {
      // textContent: el texto lo escribió un administrador, pero entra
      // igual que todo lo demás, como texto.
      var b = C.el('button', 'uhp-voz__pastilla', texto);
      b.type = 'button';
      b.setAttribute('data-uhp-voz-accion', 'sugerencia');
      b.addEventListener('click', function () {
        if (b.getAttribute('aria-disabled') === 'true') { anunciar(v, textoDeFase(v)); return; }
        sugerir(v, texto);
      });
      fila.appendChild(b);
      v.p.pastillas.push(b);
    });

    desplazarConRueda(fila);
    caja.appendChild(rotulo);
    caja.appendChild(fila);
    return caja;
  }

  /* La rueda vertical no mueve una fila horizontal sin script. Solo se
     captura si de verdad desplazó: al llegar al final, la rueda vuelve a
     mover la página. Ctrl+rueda es zoom y no se toca. */
  function desplazarConRueda(fila) {
    fila.addEventListener('wheel', function (e) {
      if (e.ctrlKey || Math.abs(e.deltaY) <= Math.abs(e.deltaX)) { return; }
      var max = fila.scrollWidth - fila.clientWidth;
      if (max <= 0) { return; }
      var paso = e.deltaY * (e.deltaMode === 1 ? 16 : (e.deltaMode === 2 ? fila.clientWidth : 1));
      var antes = fila.scrollLeft;
      fila.scrollLeft = Math.max(0, Math.min(max, antes + paso));
      if (fila.scrollLeft !== antes) { e.preventDefault(); }
    }, { passive: false });
  }

  function sugerir(v, texto) {
    if (ocupado(v)) {
      avisar(v, 'UHP-CNV-01', 'pastilla');
      anunciar(v, textoDeFase(v));
      return;
    }
    if (v.oyendo) { pararEscucha(v); }
    preguntar(v, texto);
  }

  /* LA BARRA SE MONTA UNA VEZ Y NO SE VUELVE A CREAR, en el orden de la
     captura: micrófono, campo en píldora con «enviar» dentro, «parar» y
     «cerrar».

     Antes se reconstruía entera en cada cambio de fase, y eso traía dos
     problemas que no se ven leyendo el código: desprender el <input> del
     árbol mientras tiene el foco lo devuelve al <body>, y con él se va la
     tecla Escape; y la fila cambiaba de ancho entre fases, de modo que un
     botón se movía debajo del dedo que iba a pulsarlo. Ahora lo único que
     cambia son atributos. */
  function crearFila(v, base) {
    var forma = C.el('form', 'uhp-voz__pie uhp-voz__pie--chat');
    forma.setAttribute('novalidate', 'novalidate');
    var idc = base + '-campo';

    /* El micrófono solo si esta página lo ofrece Y el navegador sabe
       dictar. No necesita la clave de ElevenLabs: el dictado lo hace el
       navegador. Si no sabe, no se pinta: un botón desactivado que nunca
       se va a activar es ruido en el recorrido del teclado. */
    if (v.micro && !motorVoz()) {
      // Antes el micrófono simplemente no aparecía y nadie sabía por qué.
      avisarUnaVez(v, 'UHP-MIC-01', 'sin SpeechRecognition');
    }
    var conMicro = !!(v.micro && motorVoz() && !v.micMuerto);
    forma.setAttribute('data-uhp-voz-micro', conMicro ? 'si' : 'no');

    if (conMicro) {
      var mic = iconoBoton(v, T.dictar, 'dictar', SVG_MICRO_REPOSO + SVG_MICRO_ACTIVO);
      mic.setAttribute('aria-pressed', 'false');
      mic.addEventListener('click', function () { escuchar(v); });
      forma.appendChild(mic);
      v.p.mic = mic;
    }

    /* La píldora: el campo y «enviar» son HERMANOS dentro de ella, y el
       texto termina donde empieza el botón por construcción, sin un
       relleno calculado que se rompa con otra letra. Un <label> de verdad,
       recortado, y no un aria-label: así el nombre pasa por las
       herramientas de traducción. */
    var caja = C.el('div', 'uhp-voz__caja');
    var etiqueta = C.el('label', 'uhp-voz__sr', T.escribir);
    etiqueta.setAttribute('for', idc);
    var campo = document.createElement('input');
    campo.type = 'text';
    campo.id = idc;
    campo.className = 'uhp-voz__campo';
    campo.placeholder = T.escribir_ph || T.escribir;
    campo.autocomplete = 'off';
    campo.setAttribute('enterkeyhint', 'send');
    var enviar = iconoBoton(v, T.enviar, 'enviar', SVG_ENVIAR, 'submit', 'uhp-voz__enviar');
    caja.appendChild(etiqueta);
    caja.appendChild(campo);
    caja.appendChild(enviar);
    caja.addEventListener('click', function (e) { if (e.target === caja) { campo.focus(); } });
    forma.appendChild(caja);

    /* «Parar» usa aria-disabled y no disabled: se inactiva en el mismo
       clic que lo usa, y con disabled el foco se iría al <body>. Así
       además sigue en el recorrido del teclado, y quien navega con Tab
       sabe que existe antes de que algo suene. */
    var parar = iconoBoton(v, T.parar, 'parar', SVG_PARAR);
    parar.setAttribute('aria-disabled', 'true');
    parar.addEventListener('click', function () {
      if (parar.getAttribute('aria-disabled') === 'true') { return; }
      detener(v);
    });
    forma.appendChild(parar);

    // Cerrar, Escape y volver a pulsar «Asistente» son la MISMA función.
    var cerrar = iconoBoton(v, T.cerrar, 'cerrar', SVG_CERRAR);
    cerrar.addEventListener('click', function () { cerrarPanel(v); });
    forma.appendChild(cerrar);

    forma.addEventListener('submit', function (e) {
      e.preventDefault();
      var texto = campo.value.trim();
      if (!texto) { return; }
      // Mientras el asistente está pensando o hablando no se encola otra
      // pregunta: el servidor recibiría dos conversaciones divergentes y
      // la segunda llegaría sin lo que acaba de contestar la primera.
      if (ocupado(v)) { anunciar(v, textoDeFase(v)); return; }
      // El texto se lee ANTES de cortar el dictado: pararEscucha() vacía
      // el campo, que es donde el dictado continuo va escribiendo.
      if (v.oyendo) { pararEscucha(v); }
      campo.value = '';
      preguntar(v, texto);
    });

    v.p.forma = forma;
    v.p.campo = campo;
    v.p.enviar = enviar;
    v.p.parar = parar;
    v.p.cerrar = cerrar;
    return forma;
  }

  /* Un botón de icono con su nombre accesible en un <span> recortado. El
     texto va en el árbol, no en un atributo: un aria-label no se ve en
     pantalla ni lo recogen las herramientas de traducción, y aquí es el
     ÚNICO nombre que el control va a tener. Se repite en title para quien
     usa ratón. */
  function iconoBoton(v, rotulo, accion, svg, tipo, clase) {
    var b = C.el('button', clase || 'uhp-voz__redondo');
    b.type = tipo || 'button';
    b.setAttribute('data-uhp-voz-accion', accion);
    b.title = rotulo;
    b.appendChild(C.el('span', 'uhp-voz__sr', rotulo));
    try {
      // Constantes de este archivo, nunca datos.
      b.insertAdjacentHTML('beforeend', svg);
    } catch (e) {
      // Una CSP con Trusted Types lo impide: el botón conserva nombre y
      // función, y se queda sin dibujo.
      avisar(v, 'UHP-PAN-03', accion + ' · ' + ((e && e.name) || 'Error'));
    }
    return b;
  }

  /* Lo único que cambia de la barra: atributos. Lo llama fase() en cada
     transición. El NOMBRE del micrófono no cambia nunca —un nombre que
     cambia junto con aria-pressed se lee al revés—; cambia su title, que
     el navegador expone como descripción y dice qué hace la segunda
     pulsación. */
  function actualizarPie(v) {
    if (!v.p) { return; }
    var oyendo = !!v.oyendo;
    var ocupa = ocupado(v);

    /* Si el motor se murió a mitad de la visita, el botón desaparece en
       vez de quedarse prometiendo algo que ya no ocurre. */
    if (v.p.mic && v.micMuerto) {
      if (document.activeElement === v.p.mic && v.p.campo) { v.p.campo.focus(); }
      if (v.p.mic.parentNode) { v.p.mic.parentNode.removeChild(v.p.mic); }
      v.p.mic = null;
      if (v.p.forma) { v.p.forma.setAttribute('data-uhp-voz-micro', 'no'); }
    }
    if (v.p.mic) {
      v.p.mic.setAttribute('aria-pressed', oyendo ? 'true' : 'false');
      v.p.mic.title = oyendo ? T.parar_micro : T.dictar;
    }
    if (v.p.enviar) { v.p.enviar.setAttribute('aria-disabled', ocupa ? 'true' : 'false'); }
    if (v.p.parar) { v.p.parar.setAttribute('aria-disabled', (ocupa || oyendo) ? 'false' : 'true'); }
    for (var i = 0; i < v.p.pastillas.length; i++) {
      v.p.pastillas[i].setAttribute('aria-disabled', ocupa ? 'true' : 'false');
    }
    // «Te escucho» no es una fase: la línea visible se refresca aquí.
    if (v.p.estadoTxt && v.fase === 'escucha') { v.p.estadoTxt.textContent = textoDeFase(v); }
  }

  /* El nombre de quien habla va ENCIMA y FUERA del globo, como en la
     captura. En los turnos de la persona va recortado: en pantalla lo
     dice el lado del globo, pero al oído no hay lado. */
  function turno(v, quien, texto) {
    if (!v.p || !v.p.hilo.isConnected) { return; }
    if (v.p.vacio && v.p.vacio.parentNode) {
      v.p.hilo.removeChild(v.p.vacio);
      v.p.vacio = null;
    }

    var usted = quien === 'usted';
    var caja = C.el('div', 'uhp-voz__turno ' + (usted ? 'uhp-voz__turno--usted' : 'uhp-voz__turno--asistente'));
    caja.appendChild(C.el('span', usted ? 'uhp-voz__quien uhp-voz__sr' : 'uhp-voz__quien', usted ? T.usted : T.asistente));
    // textContent: lo que devuelve el modelo es texto y solo texto.
    caja.appendChild(C.el('p', 'uhp-voz__globo', texto));

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
        var hecho = !!(m && m.setMunicipio(nombre, 'usuario'));
        if (!hecho) { avisar(v, 'UHP-HER-07', nombre); }
        /* Y se le dice al asistente, como un turno más de la
           conversación: sin esto seguiría esperando una confirmación que
           ya ocurrió, y volvería a pedirla. Lo que de verdad pasó: antes
           decía «ya está seleccionado» aunque el tablero no lo hubiera
           conseguido, y el modelo le leía al visitante una pantalla que
           no existía. */
        v.hist.push({
          rol: 'usuario',
          texto: 'Confirmo que me refería a ' + nombre + '. ' +
            (hecho ? 'Ya está seleccionado en el tablero.' : 'El tablero no pudo seleccionarlo; no lo des por seleccionado.')
        });
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

    v.raiz.classList.remove('uhp-voz--escucha', 'uhp-voz--habla', 'uhp-voz--error', 'uhp-voz--pensando');
    if (f === 'escucha') { v.raiz.classList.add('uhp-voz--escucha'); }
    else if (f === 'habla') { v.raiz.classList.add('uhp-voz--habla'); }
    else if (f === 'pensando') { v.raiz.classList.add('uhp-voz--pensando'); }
    else if (f === 'error') { v.raiz.classList.add('uhp-voz--error'); }

    var texto = textoDeFase(v);
    // El rótulo se actualiza siempre: el color del punto no puede ser la
    // única señal de que el asistente pasó de escuchar a hablar.
    if (v.p && v.p.estadoTxt) { v.p.estadoTxt.textContent = texto; }
    // El código del fallo, solo en la fase de error y solo si lo hay.
    if (v.p && v.p.codigo) {
      var conCodigo = f === 'error' && !!v.errorCodigo;
      v.p.codigo.textContent = conCodigo ? v.errorCodigo : '';
      v.p.codigo.hidden = !conCodigo;
    }
    actualizarPie(v);

    // El lanzador dice lo que hace AHORA, no lo que hacía al cargar: con
    // el panel abierto, cierra. Y lo mismo cualquier lanzador propio de
    // la plantilla que declare aria-expanded.
    pintarLanzadores(v, f !== 'inactivo');

    /* Se anuncia lo que importa. «Habla» solo cuando NO va a sonar: si
       suena, el propio audio dice quién tiene el turno, y anunciarlo
       además es ruido encima del audio. Si no hay voz configurada, quien
       no ve la pantalla no tiene otra forma de saber que el asistente
       está respondiendo. WCAG 2.1 §4.1.3. */
    var relevante = (f === 'cerrada' || f === 'error' || f === 'pensando');
    if (!CFG.voz && (f === 'habla' || f === 'escucha')) { relevante = true; }
    if (relevante) { anunciar(v, texto); }
  }

  function textoDeFase(v) {
    if (v.fase === 'aviso') { return T.aviso_fase; }
    if (v.fase === 'conectando') { return T.conectando; }
    if (v.fase === 'pensando') { return T.pensando; }
    // En reposo, el rótulo dice QUÉ HACER. Y con un solo canal son las
    // dos cosas a la vez: se puede escribir y se puede dictar.
    if (v.fase === 'escucha') {
      if (v.oyendo) { return T.oyendo; }
      // «Escribe o dicta» solo donde hay micrófono.
      return (v.p && v.p.mic) ? T.escribiendo : (T.escribiendo_txt || T.escribiendo);
    }
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

  window.UHPVozAsistente = {
    iniciar: iniciar,
    abrir: abrirPorPrograma,
    cerrar: cerrarPorPrograma,
    // copy(UHPVozAsistente.diagnostico()) en la consola: texto para el
    // soporte en vez de una captura. Solo vive en memoria.
    diagnostico: function () {
      return DIARIO.map(function (d) {
        return [d.hora, d.codigo, d.nivel, d.detalle, d.ref ? 'ref ' + d.ref : ''].filter(Boolean).join(' · ');
      }).join('\n');
    },
    codigos: function () { return JSON.parse(JSON.stringify(CODIGOS)); }
  };
}());
