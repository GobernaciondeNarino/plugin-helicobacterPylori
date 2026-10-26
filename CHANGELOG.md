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

## [2.1.0] — 2026-10-26

### Añadido
- **Módulo «Asistente AI»** en el menú del plugin. Arriba, la tarjeta «Estado
  del módulo»: activar, visible en el sitio, **nombre del anfitrión**,
  **saludo inicial** y **preguntas sugeridas** (una por línea, máximo ocho;
  vacío, las del ejemplo). La pestaña de Componentes redirige aquí.
- **La ventana de la captura**: el nombre del anfitrión encima de cada globo,
  «Puedes preguntar:» con pastillas que envían la pregunta, y una barra con
  micrófono (tachado en reposo, entero mientras escucha), el campo con
  «enviar» dentro, **«parar»** y **«cerrar»**. «Parar» corta la consulta —y
  la quita del historial—, la voz o el dictado, sin cerrar la conversación.
  Todos los controles miden 44 × 44 y tienen nombre accesible; a menos de
  354 px la barra se parte en dos filas.
- **Códigos de error `UHP-ÁREA-NN`** —97, en doce áreas—, iguales en la
  consola del navegador, el registro de PHP, la verificación del panel y la
  pantalla del visitante. El servidor los manda en `data.uhp` y en las
  cabeceras `X-UHP-Codigo`, `X-UHP-Ref` y `X-UHP-Version`, con un `ref` que
  une la captura con la línea del registro. `UHPVozAsistente.diagnostico()`
  devuelve las últimas veinte entradas como texto. Tabla en
  `docs/voz/codigos.md`, generada por `php tests/generar-codigos.php`.
- **Lo que el asistente sabe del proyecto sin consultar**: un apartado fijo del
  prompt —el nombre, quién lo hizo y lo financió, cuándo, qué es la bacteria y
  cómo se contagia, la cascada de Correa, qué es una lesión precursora y una
  zona de riesgo, quién podía participar—, atado por la suite a los JSON y sin
  una sola cifra de resultados. Las preguntas sugeridas ya tienen respuesta.
- **Reintento en el servidor de Claude** (`fallbacks: "default"`) si un
  clasificador de seguridad declina una pregunta corriente sobre la infección
  o el cáncer gástrico.

### Cambiado
- **Tono**: de tú, con calidez y paciencia, como alguien de la región que
  conoce el proyecto. Explica los términos técnicos, no hace sentir torpe a
  nadie y, si la pregunta toca la salud de alguien, responde con serenidad y
  remite a la EPS. El aviso legal sigue de usted.
- **El dictado espera la pregunta completa**: espera a que la persona empiece
  a hablar, no corta en las pausas —reabre el reconocedor si el navegador lo
  cierra— y envía tras un silencio configurable (dos segundos por omisión).
  Pulsar otra vez el micrófono envía ya.
- **El esfuerzo de razonamiento llega a los tres proveedores** (antes solo a
  Claude) y el tope de salida deja sitio al razonamiento: 6.000, 12.000 o
  16.000 según el esfuerzo. Con 2.000, OpenAI y Gemini podían gastarlo
  pensando y devolver una respuesta vacía.
- **La lectura en voz alta tiene su propio cupo diario.** Hasta ahora
  `/voz/hablar` solo la frenaban el límite por IP y el control de origen.
- El lanzador dice siempre «Asistente»; el estado lo da `aria-expanded`.
- Escape solo cierra el asistente si el foco está en él: con el foco en el
  mapa o en el modal de un gráfico, la tecla es de ellos.
- El listado de voces de ElevenLabs se pide **por páginas**: con más de cien
  voces, la verificación decía que no existía una que sí estaba.

### Corregido
- **El asistente se quedaba mudo tras llegar al tope de vueltas.** La última
  llamada a una herramienta quedaba sin su resultado y los tres proveedores
  contestan 400 a la pregunta siguiente. Se arregla en el navegador y, para
  pestañas abiertas con la versión anterior, en el servidor.
- **La firma de razonamiento de Gemini se recortaba** a 4.000 caracteres;
  Gemini 3 exige la firma exacta y respondía 400.
- **El micrófono de la 2.0 no se podía apagar**: el panel mostraba un selector
  de tres opciones y el saneado convertía cualquier valor en «ambos». Ahora es
  una casilla que hace lo que dice.
- **Un 200 vacío del proveedor salía como éxito**: el visitante no recibía nada
  y el cupo no se devolvía. Ahora es `UHP-LLM-12` y se le dice que lo pregunte
  de otra forma.
- **Fallos de los proveedores mal clasificados por el estado HTTP**: OpenAI sin
  saldo (429), Gemini con clave inválida (400), Anthropic sin saldo (400) y
  ElevenLabs sin caracteres (401). Ahora manda el cuerpo del error.
- Una respuesta cortada con una herramienta a medias ya no se ejecuta.
- Las negativas de OpenAI (contenido `refusal`) y los bloqueos de Gemini
  (`promptFeedback`, `finishReason`) ya no pasan por respuestas vacías.
- `output_config.effort` ya no se manda a modelos de Claude que no lo admiten.
- La confirmación de un municipio ya no le dice al modelo «ya está
  seleccionado» si el tablero no lo consiguió.
- El prompt usaba «35,1 %» como ejemplo de formato, que es la prevalencia real
  de lesión precursora; y fechaba el tamizaje en 2018, cuando el trabajo de
  campo empezó en octubre de 2019.
- Cada fallo dejaba dos líneas distintas en la consola; ahora una.
- El registro de PHP guardaba trozos del cuerpo crudo de ElevenLabs, que puede
  repetir el texto que se iba a leer; ahora solo el mensaje extraído, sin URL.

### Seguridad
- Al navegador no viaja nada nuevo que comprometa: código, frase fija, `ref` y
  versión. Nunca claves, proveedor, modelo, lo que dijo el proveedor ni la
  pregunta del visitante. El registro de PHP se limita a una línea por código
  y ventana, porque las rutas son públicas.

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
