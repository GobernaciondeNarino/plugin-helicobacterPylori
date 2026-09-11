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

    // Estado inicial: la vista que el servidor dejó visible. Se reafirma
    // aquí por si la página trae dos selectores del mismo canal, o por si
    // el navegador restauró la selección anterior al recargar.
    if (sel.value) { mostrar(canal, sel.value); }
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
