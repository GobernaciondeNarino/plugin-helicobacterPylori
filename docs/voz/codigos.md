# Códigos de error del asistente

> Archivo generado por `php tests/generar-codigos.php` a partir de los dos catálogos: `includes/class-uhp-codigos.php` (servidor) y el objeto `CODIGOS` de `assets/js/uhp-voz.js` (navegador). No se edita a mano.

Cada fallo del asistente tiene un código `UHP-ÁREA-NN` que es **el mismo** en la consola del navegador, en el registro de PHP, en la verificación del panel y en la pantalla del visitante. El área dice dónde mirar y el número qué pasó. En `LLM` y `VOZ`, del 01 al 10 significan lo mismo: 01 no conecta, 02 tiempo agotado, 03 clave rechazada, 04 falta un permiso, 05 no existe, 06 petición rechazada, 07 sin saldo, 08 límite de ritmo, 09 fallo interno del proveedor, 10 un 200 inutilizable. El 99 de cada área es «sin clasificar».

Un número no se reutiliza nunca ni cambia de sentido.

## Cómo se ve

- **Consola del navegador** (una línea por fallo): `[URKUNINA 5000] UHP-LLM-03 · el proveedor de lenguaje rechazó la clave · HTTP 502 · ref 7c2e9a · /voz/chat · vX.Y.Z`
- **Registro de PHP**: `[URKUNINA 5000] UHP-LLM-03 · el proveedor de lenguaje rechazó la clave · ref 7c2e9a · voz/chat · proveedor=gemini modelo=… http=400 · dijo: API key not valid…`
- **Pantalla del visitante**: el mensaje de siempre y, al lado, el código (`UHP-ACC-06`). Es lo que se le dicta a la mesa de ayuda.
- **Panel**: la verificación de las claves pinta el código delante del mensaje.

El `ref` une la captura del visitante con la línea exacta del registro de PHP: `grep 7c2e9a` sobre el registro. Al navegador nunca viajan claves, el nombre del proveedor o del modelo, lo que contestó el proveedor ni la pregunta del visitante. `UHPVozAsistente.diagnostico()` en la consola devuelve las últimas veinte entradas como texto, para copiarlas.

Niveles: **error** cuando alguien de la entidad tiene que actuar; **warn** cuando algo se degrada pero la conversación sigue, o la causa es el equipo del visitante; **info** cuando no es un fallo pero explica una conducta. Un sitio sano no tiene ningún `error` en la consola.

## Áreas

| Área | Qué abarca | Dónde se mira primero |
|---|---|---|
| `CFG` | Configuración del sitio y de la página | Asistente AI en el panel, wp-config.php, optimizadores de JavaScript y cachés |
| `ACC` | Las puertas de las dos rutas | Activación y visibilidad, URL del sitio, proxy, cupo diario |
| `RED` | Navegador ↔ este sitio | Pestaña Red del navegador, otros plugins, errores de PHP |
| `LLM` | Servidor ↔ proveedor de lenguaje | Registro de PHP con el ref, cuenta del proveedor |
| `VOZ` | Servidor ↔ ElevenLabs | Registro de PHP con el ref, cuenta de ElevenLabs |
| `AUD` | Reproducción del audio en el navegador | CSP media-src, navegador del visitante |
| `MIC` | Micrófono y dictado | Permiso del micrófono, Permissions-Policy, navegador |
| `CNV` | La conversación | Versión de la página frente a la del servidor, tope de vueltas |
| `HER` | Herramientas que pide el modelo | Lo que pidió el modelo y el tablero |
| `TAB` | El puente con el tablero | uhp-dashboard.js y la carga de datos |
| `AVI` | Aviso y autorización | Plantilla del aviso, almacenamiento del navegador |
| `PAN` | La ventana y los lanzadores | Marcado del tema, botones propios, CSP |

## CFG · Configuración del sitio y de la página

| Código | Nivel | Lo detecta | Significa | Qué mirar |
|---|---|---|---|---|
| `UHP-CFG-01` | error | navegador | el script del asistente corrió sin el núcleo o sin su configuración | Un optimizador de JavaScript (combinar, diferir, «retrasar ejecución») que separó el bloque en línea de uhp-voz.js; que uhp-core.js cargue. |
| `UHP-CFG-02` | error | navegador | falta la base de la API del sitio (window.UHP.rest) | El bloque en línea del núcleo; un optimizador que lo separó del archivo. |
| `UHP-CFG-03` | warn | navegador | la página es de otra versión del plugin que el servidor | Purgar la caché de página o la CDN después de actualizar el plugin. |
| `UHP-CFG-04` | warn | servidor | la página ofrece voz pero el servidor ya no la tiene configurada | Clave e identificador de ElevenLabs, o una copia en caché de cuando sí había voz. |
| `UHP-CFG-05` | error | servidor | una constante de clave en wp-config.php no es una cadena | wp-config.php: casi siempre define( …, getenv( 'X' ) ) sin la variable de entorno. |
| `UHP-CFG-07` | warn | servidor | la voz está a medio configurar: falta la clave o el identificador | Los dos campos de ElevenLabs: hacen falta la clave Y el identificador. |
| `UHP-CFG-09` | error | servidor | el filtro uhp_voz_api_base devolvió algo inválido | El código que engancha el filtro uhp_voz_api_base: debe devolver una URL https. |
| `UHP-CFG-10` | info | servidor | resultado de la verificación de las claves | Nada: es la línea que deja cada verificación del panel. |

## ACC · Las puertas de las dos rutas

| Código | Nivel | Lo detecta | Significa | Qué mirar |
|---|---|---|---|---|
| `UHP-ACC-01` | error | servidor | el asistente está apagado o sin clave del modelo | Asistente AI → Activar y la clave del modelo. Si el visitante vio el botón, su página estaba en caché. |
| `UHP-ACC-02` | error | servidor | el asistente está en ensayo y la petición llegó sin sesión | Visibilidad «solo editores»: sesión iniciada y página sin caché. |
| `UHP-ACC-03` | warn | navegador | el nonce caducó o la sesión se cerró: hay que recargar la página | Recargar la página: la pestaña llevaba abierta más de un día. |
| `UHP-ACC-04` | error | servidor | la petición no trae un origen de este sitio | El registro dice cuál: sin cabecera, otro host o otro esquema. Comparar Ajustes → Generales con la URL pública. |
| `UHP-ACC-05` | warn | servidor | se alcanzó el límite de peticiones por dirección IP | Si el registro dice «red privada», todos comparten IP detrás de un proxy: filtro uhp_ip_cliente. |
| `UHP-ACC-06` | warn | servidor | se agotó el cupo diario de consultas | El ajuste «Consultas por día». Cada vuelta de herramientas cuenta una. |
| `UHP-ACC-07` | warn | servidor | se agotó el cupo diario de lecturas en voz | El mismo tope diario, contado aparte para las lecturas en voz alta. |

## RED · Navegador ↔ este sitio

| Código | Nivel | Lo detecta | Significa | Qué mirar |
|---|---|---|---|---|
| `UHP-RED-01` | warn | navegador | el equipo del visitante está sin conexión | Nada en el sitio: el equipo del visitante no tiene conexión. |
| `UHP-RED-02` | error | navegador | la petición se quedó sin respuesta del sitio | La línea roja del navegador justo encima (CSP connect-src, certificado, extensión) y la pestaña Red. |
| `UHP-RED-03` | error | navegador | el sitio contestó con algo que no es JSON | Pestaña Red → Respuesta: avisos de PHP impresos antes del JSON, o una caché que sirve HTML. |
| `UHP-RED-04` | error | navegador | la ruta del asistente no existe en este sitio | Plugin desactivado, JavaScript de otra versión o la API REST cerrada. |
| `UHP-RED-05` | error | navegador | otra pieza de WordPress rechazó la petición | Un plugin de seguridad que cierra la API REST a anónimos: añadir urkunina/v1/voz/* a su lista blanca. |
| `UHP-RED-06` | error | navegador | error fatal de PHP al atender la ruta | El registro de PHP a esa hora: un error fatal al atender la ruta. |
| `UHP-RED-07` | error | navegador | la respuesta de error no la generó este plugin | Las cabeceras de la respuesta (Server, Via): un proxy, una CDN o un cortafuegos respondió por el plugin. |
| `UHP-RED-08` | error | navegador | el sitio contestó con una forma inesperada | Versiones distintas de página y servidor (CFG-03), u otro plugin que reescribe respuestas REST. |

## LLM · Servidor ↔ proveedor de lenguaje

| Código | Nivel | Lo detecta | Significa | Qué mirar |
|---|---|---|---|---|
| `UHP-LLM-01` | error | servidor | el servidor no pudo conectar con el proveedor de lenguaje | Salida por el puerto 443 hacia el proveedor: cortafuegos, DNS o certificados del servidor. |
| `UHP-LLM-02` | error | servidor | el proveedor de lenguaje no contestó a tiempo | La página de estado del proveedor y el proxy de salida. |
| `UHP-LLM-03` | error | servidor | el proveedor de lenguaje rechazó la clave | La clave del proveedor ACTIVO: cambiar de proveedor sin pegar la clave nueva produce esto. |
| `UHP-LLM-04` | error | servidor | a la clave del proveedor de lenguaje le falta un permiso | El «dijo:» del registro y la consola del proveedor: región, proyecto o permiso. |
| `UHP-LLM-05` | error | servidor | el modelo de lenguaje no existe o la cuenta no tiene acceso | El campo «Modelo»: un nombre mal escrito o un modelo retirado. |
| `UHP-LLM-06` | error | servidor | el proveedor de lenguaje rechazó la petición o un parámetro | El «dijo:» del registro. Suele ser un defecto del plugin: reportarlo con el ref. |
| `UHP-LLM-07` | error | servidor | la cuenta del proveedor de lenguaje no tiene saldo | La facturación de la cuenta del proveedor. |
| `UHP-LLM-08` | warn | servidor | la cuenta del proveedor de lenguaje llegó a su límite de ritmo | Los límites del plan del proveedor y los picos de tráfico. |
| `UHP-LLM-09` | warn | servidor | fallo interno o sobrecarga del proveedor de lenguaje | La página de estado del proveedor. No es la configuración. |
| `UHP-LLM-10` | error | servidor | el proveedor de lenguaje contestó algo que no es JSON | Un proxy corporativo o un cortafuegos de salida que devuelve HTML: el «dijo:» trae las primeras líneas. |
| `UHP-LLM-11` | warn | servidor | el proveedor de lenguaje declinó responder | Nada, salvo que se repita con preguntas corrientes: entonces, el prompt. |
| `UHP-LLM-12` | warn | servidor | el proveedor de lenguaje contestó sin texto ni herramientas | El motivo de parada que trae el registro (bloqueo, filtro, corte). |
| `UHP-LLM-13` | warn | servidor | la respuesta se cortó en el tope de salida | Si pasa a menudo, el nivel de «Cuánto piensa antes de responder». |
| `UHP-LLM-14` | warn | servidor | el modelo pidió una herramienta con argumentos ilegibles | Si pasa a menudo con un modelo, probar otro. |
| `UHP-LLM-99` | error | servidor | fallo del proveedor de lenguaje sin clasificar | El «dijo:» del registro. Falta una fila en el catálogo: reportarlo. |

## VOZ · Servidor ↔ ElevenLabs

| Código | Nivel | Lo detecta | Significa | Qué mirar |
|---|---|---|---|---|
| `UHP-VOZ-01` | error | servidor | el servidor no pudo conectar con la síntesis de voz | Salida por el puerto 443 hacia api.elevenlabs.io (o el host del filtro de residencia). |
| `UHP-VOZ-02` | warn | servidor | la síntesis de voz no contestó a tiempo | La página de estado de ElevenLabs. |
| `UHP-VOZ-03` | error | servidor | la síntesis de voz rechazó la clave | La clave de ElevenLabs: revocada o mal copiada. |
| `UHP-VOZ-04` | error | servidor | a la clave de la síntesis de voz le falta un permiso | Los permisos de la clave: text_to_speech para hablar, voices_read para verificar. |
| `UHP-VOZ-05` | error | servidor | la voz configurada no existe en la cuenta | La lista desplegable de voces del panel. |
| `UHP-VOZ-06` | error | servidor | la síntesis rechazó el modelo o un parámetro | El «dijo:» y el «modelo=» del registro: el modelo de voz o la velocidad. |
| `UHP-VOZ-07` | error | servidor | la cuenta de voz se quedó sin caracteres o sin saldo | El plan y el consumo de caracteres de la cuenta de ElevenLabs. |
| `UHP-VOZ-08` | warn | servidor | demasiadas síntesis a la vez para el plan de voz | El plan de ElevenLabs: síntesis simultáneas. |
| `UHP-VOZ-09` | warn | servidor | fallo interno de la síntesis de voz | La página de estado de ElevenLabs. |
| `UHP-VOZ-10` | error | servidor | la síntesis contestó sin audio | Un proxy de salida que reescribe la respuesta: el registro trae el content-type. |
| `UHP-VOZ-11` | warn | servidor | no queda texto que leer después de limpiarlo | Nada: la respuesta del modelo era solo marcado. |
| `UHP-VOZ-12` | info | servidor | la respuesta se recortó para leerla en voz alta | Si pasa a menudo, el problema es la longitud de las respuestas, no el tope. |
| `UHP-VOZ-13` | warn | servidor | el listado de voces volvió con una forma inesperada | El «dijo:» del registro: la forma del listado de voces cambió. |
| `UHP-VOZ-99` | error | servidor | fallo de la síntesis de voz sin clasificar | El «dijo:» del registro. Falta una fila en el catálogo: reportarlo. |

## AUD · Reproducción del audio en el navegador

| Código | Nivel | Lo detecta | Significa | Qué mirar |
|---|---|---|---|---|
| `UHP-AUD-01` | error | navegador | el audio no se pudo decodificar | Una respuesta truncada por un proxy con límite de tamaño. |
| `UHP-AUD-02` | error | navegador | el navegador rechazó el audio al cargarlo | MediaError 4: la CSP del sitio sin media-src 'self' blob:. MediaError 3: datos corruptos. |
| `UHP-AUD-03` | warn | navegador | el navegador no dejó sonar sin un gesto | Nada: el navegador pide un gesto y se ofrece «Oír la respuesta». |
| `UHP-AUD-04` | error | navegador | el navegador no dejó reproducir el audio | Con NotSupportedError, igual que AUD-02: CSP o formato. |
| `UHP-AUD-05` | warn | navegador | tampoco sonó con el gesto del visitante | Pestaña o sistema en silencio, o el ahorro de energía del teléfono. |

## MIC · Micrófono y dictado

| Código | Nivel | Lo detecta | Significa | Qué mirar |
|---|---|---|---|---|
| `UHP-MIC-01` | info | navegador | este navegador no tiene reconocimiento de voz | Nada: Firefox y otros no reconocen voz. Se puede escribir. |
| `UHP-MIC-02` | info | navegador | el micrófono se apagó para esta visita tras un fallo | La línea MIC anterior dice la causa. |
| `UHP-MIC-03` | warn | navegador | no se pudo crear el reconocedor de voz | El nombre del error en el detalle. |
| `UHP-MIC-04` | warn | navegador | se denegó el permiso del micrófono | El candado de la barra de direcciones, la cabecera Permissions-Policy del sitio o una página sin HTTPS. |
| `UHP-MIC-05` | warn | navegador | el navegador no ofrece el servicio de transcripción | Brave, Vivaldi u Opera: no tienen el servicio de transcripción. |
| `UHP-MIC-06` | warn | navegador | el servicio de transcripción del navegador no respondió | La conexión del visitante, o una red que bloquea los servicios de Google. |
| `UHP-MIC-07` | warn | navegador | no hay micrófono o lo está usando otra aplicación | El equipo del visitante: sin micrófono, o en uso por otra aplicación. |
| `UHP-MIC-08` | warn | navegador | el navegador no transcribe español de Colombia | El navegador no transcribe es-CO. |
| `UHP-MIC-09` | info | navegador | no se oyó nada mientras se esperaba la pregunta | Nada: no se oyó ninguna palabra en veinte segundos. |
| `UHP-MIC-10` | warn | navegador | el reconocedor de voz no arrancó | Una doble pulsación muy rápida. |
| `UHP-MIC-11` | info | navegador | el dictado terminó sin texto | Nada: Safari a veces cierra el dictado sin texto. |
| `UHP-MIC-99` | warn | navegador | el reconocedor dio un motivo no previsto | El motivo que dio el navegador, en el detalle. Falta una fila en el catálogo. |

## CNV · La conversación

| Código | Nivel | Lo detecta | Significa | Qué mirar |
|---|---|---|---|---|
| `UHP-CNV-01` | info | navegador | pregunta descartada: el asistente todavía respondía | Nada: es la guarda que impide dos preguntas a la vez. |
| `UHP-CNV-02` | warn | navegador | se alcanzó el tope de vueltas de herramientas | Las líneas HER de encima: una herramienta que invita al modelo a repetir. |
| `UHP-CNV-03` | warn | navegador | la respuesta llegó vacía | Un servidor de versión anterior a la 2.1 (CFG-03). |
| `UHP-CNV-04` | error | navegador | excepción inesperada en la conversación | La pila del Error que va con la línea: es un defecto del plugin. |
| `UHP-CNV-05` | error | servidor | no llegó ninguna conversación utilizable | Versiones distintas de página y servidor, o alguien llamando la ruta a mano. |
| `UHP-CNV-06` | warn | servidor | la conversación es demasiado larga | Nada: el visitante empieza otra conversación. |
| `UHP-CNV-07` | warn | servidor | hubo que descartar o completar herramientas del historial | Una pestaña abierta durante una actualización del plugin. |
| `UHP-CNV-08` | info | servidor | el historial se recortó a los últimos mensajes | Nada: el historial se recortó a los últimos sesenta mensajes. |

## HER · Herramientas que pide el modelo

| Código | Nivel | Lo detecta | Significa | Qué mirar |
|---|---|---|---|---|
| `UHP-HER-01` | warn | navegador | el modelo pidió una herramienta que este navegador no tiene | Un JavaScript en caché anterior a las herramientas del servidor, o un nombre inventado por el modelo. |
| `UHP-HER-02` | error | navegador | una herramienta lanzó una excepción | La pila del Error que va con la línea, y el nombre de la herramienta. |
| `UHP-HER-03` | warn | navegador | el tablero rechazó el cambio | El mando del tablero (uhp-dashboard.js) rechazó el cambio. |
| `UHP-HER-04` | info | navegador | se agotó el presupuesto de cambios del tablero | Nada: el modelo encadenaba cambios y se frenó. |
| `UHP-HER-05` | info | navegador | el argumento de la herramienta no cruza con el tablero | El argumento, en el detalle: zona, subregión o municipio que no cruza. |
| `UHP-HER-06` | info | navegador | la selección espera la confirmación del visitante | Nada: es la confirmación de Pupiales e Ipiales funcionando. |
| `UHP-HER-07` | error | navegador | la confirmación no logró seleccionar el municipio | El mando del tablero no pudo seleccionar el municipio confirmado. |

## TAB · El puente con el tablero

| Código | Nivel | Lo detecta | Significa | Qué mirar |
|---|---|---|---|---|
| `UHP-TAB-01` | error | navegador | no hay mando del tablero | uhp-dashboard.js no corrió, o el asistente quedó fuera de un [data-uhp-tablero]. |
| `UHP-TAB-02` | warn | navegador | el tablero todavía no tiene datos | La carga de datos del tablero. |
| `UHP-TAB-03` | error | navegador | al mando le falta una función que pide la herramienta | uhp-dashboard.js y uhp-voz.js son de versiones distintas. |
| `UHP-TAB-04` | warn | navegador | el perfil de los participantes no ha cargado | La carga de datos del tablero. |
| `UHP-TAB-05` | warn | navegador | no se pudo leer el estado de la pantalla | La pila del Error: la pregunta siguió sin el contexto de la pantalla. |

## AVI · Aviso y autorización

| Código | Nivel | Lo detecta | Significa | Qué mirar |
|---|---|---|---|---|
| `UHP-AVI-01` | error | navegador | el aviso está encendido pero la página no trae su plantilla | Un filtro del tema o de kses que quita la etiqueta <template>, o una copia en caché. |
| `UHP-AVI-03` | info | navegador | el navegador no deja guardar la autorización | Nada: ventana privada o almacenamiento bloqueado; la autorización se pedirá cada vez. |

## PAN · La ventana y los lanzadores

| Código | Nivel | Lo detecta | Significa | Qué mirar |
|---|---|---|---|---|
| `UHP-PAN-01` | error | navegador | falta el nodo del panel del asistente | El marcado del asistente, alterado por el tema, kses o una caché. |
| `UHP-PAN-02` | warn | navegador | un botón no sabe qué asistente abrir | Dos tableros en la página: indicar el destino con data-uhp-voz-abrir="#id". |
| `UHP-PAN-03` | error | navegador | no se pudo dibujar un icono de la barra | Una CSP con Trusted Types: los botones funcionan, pero sin dibujo. |
| `UHP-PAN-04` | info | navegador | la persona paró la consulta, la voz o el dictado | Nada: la persona pulsó «Parar». |
| `UHP-PAN-05` | info | navegador | este navegador no puede cortar la petición en la red | Nada: navegador antiguo; «Parar» descarta la respuesta pero no corta la red. |
| `UHP-PAN-06` | info | navegador | no hay preguntas sugeridas: la fila no se pinta | Asistente AI → Preguntas sugeridas. |
