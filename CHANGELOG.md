# Historial de cambios — URKUNINA 5000

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/).
Versionado semántico.

**La versión sube con cada cambio.** No es burocracia: `UHP_VERSION` es el
`?ver=` de los quince recursos que encola el plugin y la condición que decide
si corre la migración. Congelada, un sitio que ya sirvió la versión anterior
sigue entregando el JavaScript viejo desde la caché del navegador. Vive en dos
sitios de `urkunina-5000.php` —la cabecera y la constante— y una comprobación
de la suite exige que coincidan.

---

## [2.0.0] — 2026-10-22

Cambio mayor: un sitio que venga de la 1.5.0 **tiene que reconfigurar el
asistente**. Los ajustes del agente de ElevenLabs ya no se usan y, sin una
clave de modelo de lenguaje, el asistente no se publica.

### Añadido
- **Modelo de lenguaje configurable**: Claude, ChatGPT o Gemini, los tres
  detrás de una sola puerta (`UHP_LLM`). Sin SDK ni Composer, solo
  `wp_remote_post()`: hay otros plugins en el sitio y un framework compartido
  es un conflicto esperando a ocurrir.
- **Verificación de las claves** en el panel, que pregunta a cada proveedor y
  comprueba que el identificador de voz pertenece a la cuenta.
- **Lista desplegable de voces** leídas de la cuenta de ElevenLabs.
- **Diagnósticos**: todo lo que falla deja una línea con el mismo prefijo en el
  registro de PHP y en la consola del navegador. Antes había tres líneas en
  todo el plugin y ninguna en el camino que usa un administrador.
- `CHANGELOG.md` y `docs/voz/diagnostico.md`.

### Cambiado
- **Un solo botón, «Asistente»**, y dentro tres controles: cuadro de texto,
  enviar y micrófono. Antes eran dos lanzadores y hasta cinco botones.
- **El micrófono y la voz se configuran aparte.** El micrófono es una forma de
  entrar —lo pone el navegador, no necesita clave— y la voz una de salir. Las
  cuatro combinaciones son conversaciones completas.
- **Un solo prompt**, porque la respuesta se lee y se escucha a la vez. Exige
  consultar una herramienta antes de responder y dejar en pantalla aquello de
  lo que habla.
- El aviso previo pasa a ser un **ajuste apagado por omisión**: con esta
  arquitectura no se graba ni se guarda la voz de nadie.
- «Retirar autorización» sale **fuera del panel**, junto al aviso corto: se ve
  con el panel cerrado, que es cuando alguien decide que no sigue.
- De la política de seguridad de contenido queda **una línea**,
  `media-src 'self' blob:`, y solo con la voz encendida.

### Corregido
- **Gemini devolvía 400 a la verificación.** El adaptador mandaba
  `"tools":[{"functionDeclarations":[]}]` cuando no había herramientas, y
  Gemini rechaza la petición entera. No era la clave del administrador.
- **El asistente no leía la respuesta en alto** si la conversación se había
  abierto por el lanzador escrito, que era casi siempre.
- **Una respuesta nueva podía quedar muda** por el callback rezagado de la
  anterior: `pause()` sobre un elemento con su `play()` pendiente lo rechaza
  con `AbortError`, y ese rechazo llegaba cuando ya sonaba otra cosa.
- **Dictar mientras el asistente respondía** abría una segunda conversación
  sobre el mismo historial. La guardia estaba solo en el formulario.
- **El audio se cortaba a media frase**: el servidor recortaba a 1000
  caracteres en silencio. Ahora corta en un final de frase y lo anota.
- **Con la visibilidad en «editores» las dos rutas devolvían 403**: el
  navegador no mandaba nonce y WordPress trataba la petición como anónima. El
  modo que existe para ensayar era el único que no funcionaba.
- El mensaje del proveedor ya no se descarta: la traducción del código va
  primero y lo que dijo el proveedor detrás.
- El aviso de cupo apuntaba a `/voz/sesion`, una ruta que ya no existe.

### Eliminado
- Los agentes de ElevenLabs y todo lo que arrastraban: SDK de un megabyte
  desde una CDN, WebSocket contra el proveedor, dominios de LiveKit con
  comodín, y las sustituciones que había que habilitar a mano.
- Los ajustes `agente`, `modo`, `transporte`, `texto` y `evaluacion_a11y`.

### Seguridad
- Ninguna de las dos claves llega al navegador, ni qué proveedor hay detrás.
- Como la conversación pasa por este servidor, el tope diario, el límite por
  IP y la comprobación de origen se aplican de verdad. Antes no.

---

## [1.5.0] — 2026-10-20

Asistente del tablero sobre agentes de ElevenLabs, con los dos canales de
entrada, la verificación del agente y la honestidad de la tarjeta de
participantes.
