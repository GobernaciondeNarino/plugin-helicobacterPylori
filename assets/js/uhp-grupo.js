/* [urkunina_selector] — controlador de canales.

   Un «canal» agrupa varias piezas de la página —título, descripción,
   análisis, cifras, fuente, tabla y gráfico— bajo una misma lista
   desplegable. Al elegir una vista, todas cambian a la vez.

   Las piezas NO se conocen entre sí. Cada una declara a qué canal
   pertenece con data-canal y este archivo les habla por ese nombre a
   través de un evento en `document`. Por eso pueden colocarse en
   columnas distintas, en otro orden o repartidas por la página, y por
   eso este script no depende ni de D3plus ni de la figura del gráfico:
   una página con solo textos y tablas funciona igual.

   Dos mecanismos distintos, según lo que cueste cada pieza:

     · Los textos y las tablas ya están en el HTML, un panel por vista.
       Cambiar de vista es enseñar uno y esconder los demás: instantáneo,
       sin petición, y sin JavaScript se lee igualmente la vista activa.
     · El gráfico es uno solo y se recarga. Imprimir una figura por vista
       obligaría a cada una a pedir sus datos al arrancar: ocho vistas
       serían ocho peticiones para enseñar una.                           */
(function () {
  'use strict';

  var C = window.UHPcore;

  /* Nombre del evento que anuncia el cambio de vista de un canal.
     Va en `document` y no en cada pieza porque el selector no sabe
     dónde está el resto: puede estar en otra columna, en otra sección
     o incluso antes en el documento. */
  var EVENTO = 'uhp:canal';

  C.ready(function () {
    var selectores = document.querySelectorAll('[data-uhp-selector]');
    for (var i = 0; i < selectores.length; i++) { iniciar(selectores[i]); }
  });

  function iniciar(caja) {
    var sel = caja.querySelector('[data-uhp-canal-select]');
    var canal = caja.getAttribute('data-canal') || '';
    if (!sel || !canal) { return; }

    var estado = caja.querySelector('[data-uhp-canal-estado]');

    sel.addEventListener('change', function () {
      var vista = sel.value;
      if (!vista) { return; }

      mostrar(canal, vista);

      // El cambio no mueve el foco ni altera el orden de lectura, de modo
      // que un lector de pantalla no se enteraría por su cuenta de que
      // media página acaba de cambiar. Se anuncia el nombre elegido.
      if (estado) {
        var opcion = sel.options[sel.selectedIndex];
        estado.textContent = 'Mostrando: ' + (opcion ? opcion.text : vista);
      }

      document.dispatchEvent(new CustomEvent(EVENTO, {
        detail: { canal: canal, vista: vista }
      }));
    });

    // Estado inicial. El servidor dejó un panel visible y marcó esa misma
    // opción como seleccionada, de modo que normalmente no hay nada que
    // hacer. Pero al recargar, el navegador RESTAURA el valor anterior del
    // <select> y puede no coincidir con lo que el servidor pintó: entonces
    // los paneles enseñarían una vista y el gráfico tendría otra cargada.
    //
    // Se compara contra el panel que llegó visible y, si difieren, se
    // avisa al canal entero como si el usuario acabara de elegir.
    var servidor = servidorPinto(canal);
    if (sel.value) {
      mostrar(canal, sel.value);

      // Se avisa también cuando no hay paneles con los que comparar —un
      // canal de solo selector y gráfico—, porque entonces no hay forma
      // de saber si el valor restaurado coincide. El gráfico descarta el
      // aviso si ya tiene esa vista cargada, de modo que sobrar no cuesta
      // nada y faltar deja las dos mitades desincronizadas.
      //
      // El aviso se aplaza un turno A PROPÓSITO. `C.ready` ejecuta sus
      // devoluciones en el orden en que se registraron, que es el orden
      // en que WordPress imprime los scripts, que a su vez depende de qué
      // shortcode aparece antes en la página. Con el selector delante del
      // gráfico, este avisaría antes de que la figura tuviera puesto su
      // oyente y el aviso se perdería. Aplazarlo garantiza que todas las
      // piezas ya están escuchando, sea cual sea el orden de maquetación.
      if (servidor !== sel.value) {
        var pendiente = sel.value;
        setTimeout(function () {
          document.dispatchEvent(new CustomEvent(EVENTO, {
            detail: { canal: canal, vista: pendiente }
          }));
        }, 0);
      }
    }
  }

  /* Qué vista dejó visible el servidor en este canal.

     Se lee ANTES de tocar nada: en cuanto `mostrar()` corre, todos los
     paneles siguen ya al <select> y la respuesta se habría perdido. */
  function servidorPinto(canal) {
    var visible = document.querySelector(
      '[data-uhp-panel][data-canal="' + escapar(canal) + '"]:not([hidden])'
    );
    return visible ? visible.getAttribute('data-vista') : '';
  }

  /* Enseña el panel de una vista y esconde los demás del mismo canal.

     Se usa el atributo `hidden` y no una clase: es el mecanismo que los
     lectores de pantalla ya entienden, de modo que los paneles ocultos
     quedan fuera del árbol de accesibilidad sin depender de que el CSS
     del plugin haya llegado a cargar. */
  function mostrar(canal, vista) {
    var paneles = document.querySelectorAll('[data-uhp-panel][data-canal="' + escapar(canal) + '"]');
    for (var i = 0; i < paneles.length; i++) {
      paneles[i].hidden = paneles[i].getAttribute('data-vista') !== vista;
    }

    // Los selectores del mismo canal se mantienen sincronizados entre sí:
    // una página puede llevar el mismo canal arriba y abajo.
    var otros = document.querySelectorAll('[data-uhp-selector][data-canal="' + escapar(canal) + '"] [data-uhp-canal-select]');
    for (var j = 0; j < otros.length; j++) {
      if (otros[j].value !== vista) { otros[j].value = vista; }
    }
  }

  /* El canal viene de un atributo de shortcode ya saneado por el
     servidor, pero se escapa igualmente antes de meterlo en un selector
     CSS: una comilla suelta ahí rompería la consulta entera. */
  function escapar(s) {
    if (window.CSS && typeof window.CSS.escape === 'function') {
      return window.CSS.escape(s);
    }
    return String(s).replace(/["\\\]]/g, '\\$&');
  }

  window.UHPGrupo = { mostrar: mostrar, EVENTO: EVENTO };
}());
