# Asistente del tablero — reporte de error de configuración

Qué falló, por qué, qué se arregló y dónde mirar la próxima vez.

Origen: el panel de **URKUNINA 5000 → Componentes → Asistente** (desde la
2.1, **URKUNINA 5000 → Asistente AI**) devolvió
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

## Desde la 2.1: cada fallo tiene un código

Las frases libres de la 2.0 —`voz/hablar: http 401: …` en el registro,
`voz: no se pudo sintetizar` en la consola— se sustituyeron por **códigos
`UHP-ÁREA-NN`**. El mismo fallo tiene el mismo código en los cuatro sitios
donde se puede mirar:

| Dónde | Cómo se ve |
|---|---|
| Consola del navegador | `[URKUNINA 5000] UHP-VOZ-03 · la síntesis de voz rechazó la clave · HTTP 502 · ref 7c2e9a · /voz/hablar · v2.1.0` |
| Registro de PHP | `[URKUNINA 5000] UHP-VOZ-03 · la síntesis de voz rechazó la clave · ref 7c2e9a · voz/hablar · voz=… modelo=… http=401 · dijo: …` |
| Pantalla del visitante | El mensaje de siempre y, al lado, `UHP-VOZ-03` |
| Panel → Asistente AI → Verificación | El código delante del mensaje |

**La tabla completa, con qué mirar en cada caso, está en
[`codigos.md`](codigos.md)**, que se genera desde los dos catálogos
(`php tests/generar-codigos.php`) y la suite comprueba que no se queda
atrás.

Tres cosas que conviene saber:

- **El `ref`** —seis caracteres— une la captura del visitante con la línea
  exacta del registro de PHP: `grep 7c2e9a` sobre el registro. Un mismo
  fallo repetido escribe **una** línea por minuto (proveedores) o por diez
  minutos (puertas), y todas las peticiones de esa ventana devuelven el
  mismo `ref`: las rutas son públicas y un registro sin tope tira el disco.
- **Al navegador no viajan** claves, ni el nombre del proveedor o del
  modelo, ni lo que contestó el proveedor, ni la pregunta del visitante.
  Eso queda en el registro de PHP, con su `ref`.
- **`UHPVozAsistente.diagnostico()`** en la consola del navegador devuelve
  las últimas veinte entradas como texto: `copy(UHPVozAsistente.diagnostico())`
  y se pega en un correo, sin capturas.

Los niveles importan: `console.error` solo cuando alguien de la entidad
tiene que actuar. Un micrófono denegado o un audio que Safari no deja sonar
sin un gesto salen como `warn`, porque no tienen arreglo en el sitio. Un
sitio sano no tiene ningún `error` en la consola.

### Lo que la clasificación por código corrigió

Hasta la 2.0 los fallos de los proveedores se clasificaban **solo por el
estado HTTP**, y eso se equivocaba en cuatro casos reales. Ahora se lee
primero el tipo de error del cuerpo:

| Caso | La 2.0 decía | La 2.1 dice |
|---|---|---|
| OpenAI sin saldo, que llega como 429 (`insufficient_quota`) | límite de peticiones | `UHP-LLM-07` sin saldo |
| Gemini con clave inválida, que llega como 400 (`API_KEY_INVALID`) | petición mal formada | `UHP-LLM-03` clave rechazada |
| Anthropic sin saldo, que llega como 400 («credit balance») | petición rechazada | `UHP-LLM-07` sin saldo |
| ElevenLabs sin caracteres, que llega como 401 (`quota_exceeded`) | la clave no es válida | `UHP-VOZ-07` sin saldo |

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

## Si no suena: la lista, en orden

1. **¿La página y el servidor son de la misma versión?** Si la consola dice
   `UHP-CFG-03`, la página es una copia en caché de otra versión: purgue la
   caché del sitio y la CDN después de actualizar.
2. **¿Tiene el sitio una CSP?** Hace falta `media-src 'self' blob:`. Sin esa
   línea el panel funciona entero y simplemente no suena: la consola dice
   `UHP-AUD-02 · … · MediaError 4`, y justo encima el navegador imprime la
   violación de la política.
3. **¿Verificó las claves?** Panel → Asistente AI → «Verificar las claves»:
   dice si la voz existe en la cuenta, con su código si no.
4. **Mire la consola del navegador**, filtrando por `URKUNINA`:
   - `UHP-AUD-03`: el navegador bloqueó la reproducción automática. No es un
     fallo de configuración: aparece el botón «Oír la respuesta», que sí vale.
   - `UHP-VOZ-…`: el servidor no pudo sintetizar. La línea trae el `ref`;
     con él, el registro de PHP tiene lo que contestó ElevenLabs.
   - `UHP-ACC-07`: se agotó el cupo diario de lecturas en voz alta.
5. **¿Está en «solo editores»?** Ese modo manda un nonce desde la 2.0.0. Si
   la consola dice `UHP-ACC-03`, la pestaña llevaba más de un día abierta:
   basta con recargar.

## Cómo comprobarlo

```bash
php tests/test-datos.php   # 724 comprobaciones
npm test                   # lo anterior más las del navegador
```

Las secciones **14**, **16** y **17** de `tests/test-datos.php` cubren los
errores de este reporte, los códigos y lo que exige la documentación de cada
proveedor; la **19** comprueba que `codigos.md` tiene todos los códigos. En
la suite de navegador, «cuando el servidor dice que no, se dice por qué» y
«lo que falla en silencio para el visitante deja rastro en la consola»
comprueban que el código llega a la pantalla, al atributo
`data-uhp-voz-error` y a la consola con su `ref`.
