# Asistente del tablero — reporte de error de configuración

Qué falló, por qué, qué se arregló y dónde mirar la próxima vez.

Origen: el panel de **URKUNINA 5000 → Componentes → Asistente** devolvió
estos dos errores al pulsar «Verificar las claves».

> ● Google Gemini no respondió: el proveedor rechazó la petición por mal formada
> Modelo solicitado: gemini-3.8-flash
>
> ● La clave sirve, pero esa voz no está en la cuenta.
> Voces disponibles: Lumina - Neutral and Clear, Ashley - Gentle, Friendly,
> Gabriel, James - Professional voiceover, Luis Casiano - Narrator,
> Salvatore - Epic, Deep & Trustworthy, Alberto Rodríguez - Serious,
> Narrative, LAE

**De los dos, solo el segundo era configuración.** El primero era un defecto
del plugin que se disfrazaba de error de configuración, que es la peor forma
de un fallo: manda a buscar el problema donde no está.

---

## Error 1 — El 400 de Gemini: defecto del plugin, no de la clave

### Qué pasaba

La verificación es la **única** petición del plugin que va sin herramientas:
un mensaje de una línea para comprobar que la clave vale. El adaptador
construía el bloque de herramientas de todas formas, así que salía esto:

```json
"tools": [ { "functionDeclarations": [] } ],
"toolConfig": { "functionCallingConfig": { "mode": "AUTO" } }
```

Gemini exige al menos una declaración dentro de cada `Tool` y rechaza la
petición **entera** —no solo el bloque— con `400 INVALID_ARGUMENT`.

Por eso fallaba **solo** la verificación. Una conversación de verdad manda
las diez herramientas, ese bloque va lleno y funciona. Claude y ChatGPT
toleran `tools: []`, así que el fallo se veía únicamente en Gemini.

La clave de Gemini estaba bien desde el principio.

### Por qué no lo vio la suite

Las pruebas de los tres adaptadores mandaban las diez herramientas. La
única ruta que produce una lista vacía —la verificación— se probaba contra
dobles del HTTP que devolvían respuestas preparadas, de modo que ningún
validador real llegó a ver ese cuerpo. **Un doble no valida: obedece.**

### Qué se arregló

`UHP_LLM::sin_herramientas()` decide, y los tres adaptadores omiten el
bloque entero cuando no hay ninguna. Declarar «cero herramientas» y no
declarar herramientas significan lo mismo para el modelo y cosas distintas
para el validador.

| Proveedor | Antes, sin herramientas | Ahora |
|---|---|---|
| Claude | `tools: []`, `tool_choice: {type:auto}` | no se mandan |
| ChatGPT | `tools: []`, `tool_choice: "auto"` | no se mandan |
| Gemini | `tools:[{functionDeclarations:[]}]` → **400** | no se mandan |

---

## Error 2 — El mensaje inútil: el diagnóstico existía y se tiraba

Este es el defecto que hizo falta arreglar para que el primero se pudiera
encontrar.

Gemini **sí** explica qué campo está mal, con todas las letras:

```
* GenerateContentRequest.tools[0].function_declarations:
  at least one function declaration is required
```

El plugin guardaba esa frase. Y `UHP_Voz::motivo()` la descartaba para
publicar una traducción fija del código HTTP: «el proveedor rechazó la
petición por mal formada». El camino al arreglo estaba escrito y el panel
lo borraba.

**Ahora la traducción va primero y lo que dijo el proveedor detrás**, nunca
en su lugar:

> el proveedor rechazó la petición. Dijo: * GenerateContentRequest.tools[0].function_declarations: at least one function declaration is required

Cada proveedor anida su mensaje en un sitio distinto (`error.message` en
Gemini y OpenAI, `message` en Claude, `detail.message` en ElevenLabs): se
buscan los cuatro, porque probarlos todos cuesta lo mismo que acertar.

**Ninguna URL sale en esos textos.** Se imprimen en una página del panel,
algunos proveedores devuelven la petición entera en el error, y en una URL
puede ir la clave —en Gemini puede ir como `?key=`—. El dominio se
conserva, porque saber contra qué host se habló es parte del diagnóstico;
lo que se tapa es la ruta y la cadena de consulta.

---

## Error 3 — La voz: esto sí era configuración, y el panel lo ponía difícil

El identificador guardado tenía forma válida pero no pertenecía a la
cuenta. Casi con seguridad `21m00Tcm4TlvDq8ikWAM`, que es el de los
ejemplos de la documentación de ElevenLabs: se copia, se guarda sin
protestar y falla la primera vez que alguien pregunta algo.

El panel listaba las voces disponibles **por su nombre** —«Lumina», «Luis
Casiano»— cuando el campo pide el **identificador**. No se podía copiar
nada de ese texto. Y cortaba la lista a ocho sin decirlo, de modo que con
una cuenta de veinte voces la correcta podía no aparecer.

### Qué se arregló

- El campo es ahora una **lista desplegable con las voces de la cuenta**,
  leídas de ElevenLabs. No se puede elegir una que no exista.
- Si la guardada no está en la cuenta, aparece marcada
  `— NO está en esta cuenta` con un aviso en rojo que dice la consecuencia:
  el asistente responderá por escrito y no dirá nada en alto.
- Si la lista no se puede leer, el campo vuelve a ser de texto **y se
  explica por qué**. Antes se veía un campo normal sin sugerencias,
  indistinguible de una cuenta sin voces.
- El informe de verificación da `nombre → identificador` y dice cuál está
  configurado ahora, para poder compararlos.

---

## Lo que ahora se puede diagnosticar y antes no

### En el registro de errores de PHP

Todo con el mismo prefijo: `grep '\[URKUNINA 5000\]'` saca la historia
entera sin saber de antemano qué buscar.

| Línea | Cuándo |
|---|---|
| `verificar: modelo=… voz=…` | En cada verificación, con el resultado de las dos piezas |
| `verificar/<proveedor>: modelo=… codigo=… <cuerpo>` | El modelo rechazó la verificación |
| `verificar/voz: voz configurada no está en la cuenta: <id>` | El identificador no existe |
| `voces: http <codigo>: <cuerpo>` | ElevenLabs rechazó el listado |
| `voces: transporte: <motivo>` | No se pudo abrir la conexión |
| `voz/chat: <cuerpo del proveedor>` | Una conversación falló |
| `voz/chat: se descartó del historial: …` | El navegador mandó algo que el servidor no reconoce |
| `voz/hablar: http <codigo>: <cuerpo> · voz=<id> modelo=<m>` | La síntesis falló |
| `voz/hablar: transporte: <motivo>` | No se pudo abrir la conexión |
| `cupo: El asistente agotó su cupo diario de N preguntas…` | Una vez al día |

Antes había **tres** líneas en todo el plugin y ninguna estaba en el camino
que usa un administrador: el botón «Verificar las claves» no escribía nada.

### En la consola del navegador

Mismo prefijo, y cubren los cuatro caminos por los que el audio puede no
sonar y los cinco por los que el micrófono puede no funcionar. Todos eran
mudos: desde fuera, «no suena» por una clave mal puesta y «no suena»
porque el navegador bloqueó la reproducción se ven exactamente igual.

| Línea | Qué significa |
|---|---|
| `consulta a /voz/chat: HTTP 503 · uhp_voz_cuota → se muestra «sin-cuota»` | Qué contestó el servidor y qué se le pintó al visitante |
| `voz: no se pudo sintetizar: …` | La ruta de audio falló |
| `voz: el servidor contestó sin audio…` | Respondió 200 pero sin nada que reproducir |
| `voz: el navegador rechazó el audio…` | Formato o datos corruptos |
| `voz: el navegador no dejó reproducir: NotAllowedError` | Reproducción automática bloqueada: **no es un fallo de configuración** |
| `micrófono: not-allowed` | El visitante denegó el permiso |
| `micrófono: network` / `service-not-allowed` | El navegador no puede transcribir (Brave, Vivaldi, Opera) |
| `micrófono: este navegador no tiene SpeechRecognition…` | Firefox y los demás sin dictado |
| `micrófono: no arrancó: InvalidStateError` | Se pulsó con el reconocedor ya activo |

**Lo que se pinta al visitante no cambia.** Un mensaje del servidor en
pantalla no le dice nada a quien está preguntando por el cáncer gástrico en
su municipio. La consola es para quien tenga que diagnosticarlo.

---

## Otros tres defectos encontrados por el camino

1. **La verificación probaba lo escrito, no lo que corre.** Pasaba el
   proveedor y el modelo **en crudo**, mientras la ruta real los sanea. Un
   modelo con un espacio fallaba en la verificación con 400 y funcionaba en
   producción; y al revés, el panel podía dar por bueno un nombre que la
   ruta nunca llega a usar. Además «Modelo solicitado: X» no decía si X era
   lo escrito o el valor por omisión del plugin. Ahora se sanea igual y se
   dice **cuál se probó y de dónde sale**.

2. **Un 200 sin contenido se publicaba como éxito.** Un corte por longitud
   o un filtro de seguridad devuelve 200 sin texto, y el panel decía
   «responde» con un `Contestó: «»` vacío. Eso es peor que un error: afirma
   que está todo bien. Ahora se dice que aceptó la petición y no devolvió
   nada, y que la clave y el modelo son correctos.

3. **La verificación de voz consultaba otro host.** `voces()` usaba la
   constante y `sintetizar()` el filtro de residencia de datos. En una
   instalación con ese filtro puesto, el panel podía decir «la voz existe»
   mientras la síntesis fallaba contra otro dominio. Ahora las dos usan
   `api_base()`, y el informe dice contra qué host preguntó.

Y uno de clasificación en el navegador: un **503 por voz sin configurar** se
le anunciaba al visitante como «el asistente alcanzó su límite de consultas
de hoy» —una cifra que no se había gastado y un límite que no existía—.
El estado HTTP no basta para clasificar; hace falta el código del servidor,
que ahora se lee.

---

## Qué se tomó de la aplicación de referencia

Se revisó [`GobernaciondeNarino/app-ondas`](https://github.com/GobernaciondeNarino/app-ondas)
(`497c346`), que resuelve el mismo problema con otra arquitectura.

**En qué coincide**, y confirma que el camino es razonable:

- La entrada de voz la hace **el navegador** con `SpeechRecognition` en
  `es-CO`, no un proveedor. Igual que aquí.
- ElevenLabs se usa **solo para TTS**, y el audio se reproduce con
  `Blob → createObjectURL → new Audio()`. Igual que aquí, y por eso hace
  falta `media-src 'self' blob:` en la política de seguridad.

**En qué se apartó, a propósito:**

| | app-ondas | Este plugin |
|---|---|---|
| Dónde vive la clave | `wj-content/config/site.json`, que el navegador descarga | En el servidor; nunca llega al navegador |
| El identificador de voz | Campo de texto libre, con `EXAVITQu4vr4xnSDxMaL` de ejemplo | Lista con las voces de la cuenta |
| Modelo de lectura | `eleven_multilingual_v2`, sin `language_code` | `eleven_flash_v2_5` con `language_code: es` |
| Errores del proveedor | `if (!res.ok) throw new Error("HTTP " + res.status)` | Se lee el cuerpo y se muestra lo que dijo |

El campo de texto libre con un identificador de ejemplo es **exactamente el
fallo que ocurrió aquí**: en app-ondas sigue abierto. Y su manejo de
errores descarta el cuerpo del proveedor sin leerlo, que es el defecto que
este reporte documenta: no se copió, se corrigió.

**Una idea que sí merece considerarse**: cuando ElevenLabs falla, app-ondas
cae a `speechSynthesis`, la voz del navegador. Es gratis y no necesita
clave. No se ha implementado aquí —cambiar de timbre a mitad de una
conversación sin avisar tiene sus propios problemas— pero queda anotado.

**Tres errores de configuración en app-ondas**, por si le sirven a ese
equipo: su README documenta `wj-includes/js/asistente.js` («la persona
pregunta en voz alta y el asistente mueve el mapa») y ese archivo **no
existe** ni en disco ni en git; documenta un `api.asistente.endpoint` y un
`?kiosco=1` que tampoco están implementados; y su mapa de calor colapsa «no
cruzó la clave» en «cero proyectos», pintando gris y mostrando «0
proyecto(s)» en los dos casos —un error de datos presentado al ciudadano
como un dato afirmativo—.

---

## Cómo comprobarlo

```bash
php tests/test-datos.php   # 614 comprobaciones
npm test                   # lo anterior más 130 de navegador
```

La sección **14** de `tests/test-datos.php` cubre los dos errores de este
reporte, y en la suite de navegador hay tres pruebas nuevas que comprueban
que lo que falla en silencio para el visitante deja rastro en la consola.
