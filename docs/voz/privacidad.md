# Asistente de voz — qué sale del navegador y con qué amparo

Documento de análisis para la Secretaría TIC y para quien audite el sitio. No
es el aviso que lee el ciudadano: ese lo imprime el plugin y está en
`UHP_Voz::aviso_bloques()`.

---

## 1. Qué se envía, exactamente

Cuando un visitante acepta y habla, salen del navegador:

- **El audio de su voz**, en tiempo real, mientras la conversación esté abierta.
- **Su transcripción**, que produce el proveedor.
- **Los metadatos de la conexión**: dirección IP, agente de usuario, momento.

Y **no** sale nada más. En particular, el plugin **no envía los datos del
proyecto**: el agente los obtiene llamando a herramientas que corren en el
propio navegador y leen lo que el tablero ya descargó. Las cifras no viajan al
proveedor; viajan las preguntas y las respuestas habladas.

## 2. A dónde va

| Transporte | Destinos |
|---|---|
| **WebSocket** (por defecto) | `api.elevenlabs.io` |
| WebRTC | `api.elevenlabs.io` + infraestructura de **LiveKit** (`*.livekit.cloud`, `*.livekit.run`) |

El valor por defecto es WebSocket por esto: un destino en lugar de varios, y
una CSP que se puede escribir sin comodines.

ElevenLabs Inc. tiene sus servidores fuera de Colombia. Es una **transferencia
internacional** en el sentido del artículo 26 de la Ley 1581 de 2012, a un país
sin declaración de conformidad de la Superintendencia de Industria y Comercio,
y por tanto requiere la autorización del titular. Esa autorización es
exactamente lo que recoge la compuerta.

## 3. Con qué amparo

- **Artículo 9 de la Ley 1581 de 2012** — autorización previa, expresa e
  informada. Se pide ANTES de encender el micrófono, en una compuerta que no se
  puede saltar, con los nueve bloques del aviso a la vista.
- **Artículo 8 de la Ley 1581** — derechos del titular. El botón «Retirar
  autorización» corta la conversación y borra la aceptación en el acto; el
  aviso da el correo y el canal de radicación.
- **Artículo 5** — la voz puede constituir **dato sensible**. El aviso lo dice y
  pide expresamente no dictar síntomas, resultados de laboratorio ni datos de
  terceros. El agente, además, lleva en sus instrucciones no pedirlos y no
  repetirlos.

## 4. Lo que el plugin hace para reducir la huella

| Medida | Dónde |
|---|---|
| Nace apagado y exige cinco puertas para publicarse | `UHP_Voz::configurada()` |
| El SDK no se descarga hasta que el visitante pulsa | `cargarSdk()` en `uhp-voz.js` |
| La clave de API vive fuera del array que alimenta al front, y mejor en `wp-config.php` | `UHP_Voz::OPCION_CLAVE`, `clave_api()` |
| Lo que viaja al navegador se arma por lista blanca, nunca por resta | `UHP_Voz::config_front()` |
| La autorización se guarda en `sessionStorage`, no en `localStorage`: vale para la visita | `claveConsentimiento()` |
| Cerrar el panel mientras conecta cierra también la sesión que llegue tarde | testigo de generación en `uhp-voz.js` |
| La clave se guarda sin `autoload`: no se carga en cada petición del sitio | `UHP_Activator` |
| Versionado del aviso: al cambiarlo, las autorizaciones viejas dejan de valer | ajuste `aviso_version` |
| Tope diario de conversaciones en todo el sitio | `cuota_disponible()` |
| Cinco peticiones de sesión cada diez minutos por IP | `UHP_Security::rate_limit()` |
| La sesión se cierra al ocultarse la pestaña | `pagehide` en `uhp-voz.js` |

## 4.1 Hasta dónde llega la puerta de la ruta de sesión

Conviene decirlo sin adornos, porque de ello depende cuánto dinero puede
perderse. `POST /voz/sesion` entrega una **credencial**: una URL firmada,
comprada con la clave de la entidad, que abre una conversación facturable desde
cualquier máquina. Quien la pide es un visitante anónimo, de modo que **no hay a
quién autenticar**. Las cuatro puertas encarecen el abuso y lo acotan; ninguna
lo hace imposible:

| Puerta | Frena | No frena |
|---|---|---|
| Origen igual al del sitio, **fallando cerrado** | Al script que no manda cabeceras, que es el caso corriente | A quien las falsifica |
| Cinco peticiones cada diez minutos por IP | El goteo desde una sola dirección | Una red de direcciones |
| Cupo diario de todo el sitio, **reservado antes de gastar** | Que una tarde se lleve el presupuesto del trimestre | Que ese cupo se agote y el ciudadano se quede sin asistente |
| Lista de dominios permitidos del agente | El uso desde otra página web | Un cliente de WebSocket escrito a mano, que no manda `Origin` |

Dos consecuencias prácticas:

1. **El cupo diario no es un adorno.** Póngalo en un número que la entidad pueda
   perder sin dolor. Cuando se agota, queda constancia en el registro de errores
   del sitio con una línea que lo dice; si aparece sin explicación, revise los
   accesos a `/wp-json/urkunina/v1/voz/sesion`.
2. **La última línea de defensa no está en este plugin**, sino en el **límite de
   gasto de la cuenta de ElevenLabs**. Configúrelo.

En modo **público** no hay ninguna de las cuatro: la sesión no pasa por este
servidor, el identificador del agente está en el código fuente de la página, y
el tope diario y el límite por IP no se aplican. El panel lo dice con estas
palabras. Es la razón por la que el modo privado es el valor por defecto.

## 5. Lo que el plugin NO resuelve y la entidad sí debe resolver

1. **El acuerdo de encargo del tratamiento** y el **anexo de transferencia
   internacional** con el proveedor.
2. **La retención**: cuánto conserva el proveedor las grabaciones y las
   transcripciones, y si la cuenta tiene activada alguna modalidad de retención
   mínima. Lo que la entidad escriba en ese campo se imprime literalmente en el
   aviso, de modo que **tiene que ser verdad y tiene que estar comprobado**.
3. **La prueba de la autorización.** El artículo 9 de la Ley 1581 y el artículo
   7 del Decreto 1377 de 2013 piden al responsable conservar prueba de la
   autorización. Una marca en el navegador del propio titular no es prueba: la
   escribe y la borra él. **Este plugin no registra ninguna prueba en el
   servidor**, a propósito —hacerlo significaría guardar un rastro por visitante
   donde hoy no hay ninguno—, y esa es una decisión que la entidad debe tomar
   con su área jurídica antes de publicar. Si se decide registrarla, el lugar
   natural es un apunte mínimo y no identificante (sello de tiempo, versión del
   aviso, hash truncado de IP con sal rotatoria) con purga automática.
4. **La lista de dominios permitidos del agente**, que es la única frontera
   real contra el uso del agente desde otro sitio.

## 6. Qué pasa si se apaga

El tablero vuelve a ser exactamente el de antes: ni botón, ni script, ni
petición. Los cuatro filtros —indicador, zona, subregión y municipio— se manejan
con el ratón y con el teclado, como siempre. **El asistente no es la única vía
para nada**, y eso no es una concesión: es el requisito de la Resolución 1519 de
2020 y la razón por la que el aviso termina diciéndolo.
