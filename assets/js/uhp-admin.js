/* Interacciones del panel de administración de URKUNINA 5000.

   Tres utilidades pequeñas y sin dependencias: copiar al portapapeles,
   validar y formatear el JSON del editor antes de enviarlo al servidor, y
   pedir confirmación en las acciones que reemplazan contenido.

   La validación del navegador es solo una comodidad: la que decide es la
   del servidor, en UHP_Datos::validar(). */
(function () {
  'use strict';

  function listo(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  listo(function () {
    copiar();
    editor();
    confirmar();
  });

  /* ------------------------------------------------------------------ */
  /* Copiar al portapapeles                                             */
  /* ------------------------------------------------------------------ */

  function copiar() {
    document.addEventListener('click', function (e) {
      var boton = e.target.closest ? e.target.closest('[data-uhp-copiar]') : null;
      if (!boton) { return; }
      e.preventDefault();

      var texto = boton.getAttribute('data-uhp-copiar') || '';
      var original = boton.textContent;

      function exito() {
        boton.classList.add('is-ok');
        boton.textContent = 'Copiado';
        setTimeout(function () {
          boton.classList.remove('is-ok');
          boton.textContent = original;
        }, 1500);
      }

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(texto).then(exito).catch(seleccionar);
      } else {
        seleccionar();
      }

      /* Respaldo sin API de portapapeles: se selecciona el texto para que
         quien lo necesite pueda copiarlo con el teclado. */
      function seleccionar() {
        var code = boton.parentNode.querySelector('code');
        if (!code || !window.getSelection) { return; }
        var rango = document.createRange();
        rango.selectNodeContents(code);
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(rango);
      }
    });
  }

  /* ------------------------------------------------------------------ */
  /* Editor JSON                                                        */
  /* ------------------------------------------------------------------ */

  function editor() {
    var area = document.getElementById('uhp-editor');
    if (!area) { return; }

    var estado = document.querySelector('[data-uhp-editor-estado]');
    var formatear = document.querySelector('[data-uhp-formatear]');
    var formulario = area.form;

    function comprobar() {
      if (!estado) { return true; }
      try {
        JSON.parse(area.value);
        estado.className = 'uhpa-editor-estado is-ok';
        estado.textContent = 'JSON válido.';
        return true;
      } catch (err) {
        estado.className = 'uhpa-editor-estado is-error';
        estado.textContent = 'JSON con errores: ' + err.message;
        return false;
      }
    }

    // Comprobación al escribir, con pausa para no analizar en cada tecla.
    var reloj = null;
    area.addEventListener('input', function () {
      if (reloj) { clearTimeout(reloj); }
      reloj = setTimeout(comprobar, 400);
    });

    if (formatear) {
      formatear.addEventListener('click', function () {
        try {
          // Reindentado a dos espacios: es el formato con el que se
          // generaron los archivos del conjunto, así el control de
          // versiones muestra solo el cambio real.
          area.value = JSON.stringify(JSON.parse(area.value), null, 2);
          comprobar();
        } catch (err) {
          comprobar();
        }
      });
    }

    if (formulario) {
      formulario.addEventListener('submit', function (e) {
        if (comprobar()) { return; }
        e.preventDefault();
        if (estado) { estado.textContent += ' No se envió: corrija el JSON antes de guardar.'; }
        area.focus();
      });
    }
  }

  /* ------------------------------------------------------------------ */
  /* Confirmación                                                       */
  /* ------------------------------------------------------------------ */

  function confirmar() {
    document.addEventListener('click', function (e) {
      var boton = e.target.closest ? e.target.closest('[data-uhp-confirmar]') : null;
      if (!boton) { return; }
      var mensaje = boton.getAttribute('data-uhp-confirmar');
      if (mensaje && !window.confirm(mensaje)) {
        e.preventDefault();
      }
    });
  }
})();
