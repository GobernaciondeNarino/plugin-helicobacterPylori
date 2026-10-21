# Asistente del tablero — puesta en marcha

El tablero de URKUNINA 5000 puede responder preguntas sobre sus propias cifras y
mover sus filtros mientras lo hace: «muéstrame la zona roja», «¿cuánto tiene
Túquerres?», «cambia a *H. pylori*», «¿qué subregión está peor?». Todo lo que el
ciudadano ve lo pone el plugin.

**Son dos piezas y solo una es obligatoria.**

| | Qué hace | ¿Hace falta? |
|---|---|---|
| **Modelo de lenguaje** (Claude, ChatGPT o Gemini) | Entiende la pregunta, decide qué herramienta del tablero usar y redacta la respuesta con los datos que el tablero le devuelve | **Sí.** Sin esto no hay asistente |
| **ElevenLabs** | Lee la respuesta en voz alta | No. Sin esto el asistente funciona entero por escrito |

Hace falta **una** clave de modelo, la del proveedor que elija. Los tres sirven
y se cambia de uno a otro sin tocar nada más: el plugin traduce por dentro.

**Ninguna clave llega al navegador.** El visitante pregunta a una ruta de este
mismo sitio —`/wp-json/urkunina/v1/voz/chat` y `/voz/hablar`— y es el servidor
quien habla con el proveedor. De eso dependen tres cosas que de otro modo no
serían ciertas: el tope diario de preguntas se aplica de verdad, el límite por
IP también, y este panel puede contar lo que se gastó.

---

## 1. Lo que no es técnico y va primero

| Punto | Por qué |
|---|---|
| **Acuerdo de encargo del tratamiento** con el proveedor del modelo | La entidad sigue siendo responsable de los datos; el proveedor es encargado. Lo que el visitante escribe sale del país |
| **Anexo de transferencia internacional** | Artículo 26 de la Ley 1581 de 2012 |
| **Límite de gasto** en la consola del proveedor | El tope del panel cuenta preguntas **en este sitio**. No puede frenar un cargo hecho con la misma clave desde otro sitio suyo |
| **Evaluación propia de accesibilidad**, firmada | Resolución 1519 de 2020. Teclado, foco visible, lector de pantalla y móvil |

Ninguno de los cuatro es una puerta técnica: el plugin no los puede comprobar y
no finge comprobarlos. Lo que sí impide publicar es faltar la clave del modelo.

---

## 2. Conseguir las claves

### La del modelo de lenguaje (obligatoria)

| Proveedor | Dónde | Modelo por omisión |
|---|---|---|
| Claude | `console.anthropic.com` → Settings → API keys | `claude-opus-5-5` |
| ChatGPT | `platform.openai.com` → API keys | `gpt-6-luna` |
| Gemini | `aistudio.google.com` → Get API key | `gemini-3.8-flash` |

El campo «modelo» del panel se deja escribir a mano a propósito: los proveedores
publican modelos nuevos cada pocos meses y una lista fija aquí envejecería mal.
Vacío usa el de la tabla. Si escribe uno que no existe, la verificación del
panel se lo dice.

### La de ElevenLabs (opcional, solo para la voz)

1. En el panel de ElevenLabs, **Desarrolladores → Claves de API → Crear clave**.
2. En **Voces**, elija una y copie su **identificador de voz**: una cadena de
   letras y números, sin prefijo. **No** es el nombre de la voz.

Hacen falta **las dos**: con la clave y sin el identificador, o al revés, no
suena nada. El panel lo dice en vez de dejarlo descubrir en producción.

Elija una voz que hable español de verdad. Y compruebe el **modelo de lectura**:
el identificador de la voz **no** cambia el modelo. El plugin usa por omisión
`eleven_flash_v2_5`, que admite fijar el idioma en español, cuesta la mitad y
responde antes; los modelos «flash v2» y «turbo v2» a secas son solo de inglés y
leerían «EPS», «IPS» y las cifras con fonética inglesa, en un tablero colombiano
lleno de siglas y de porcentajes.

---

## 3. Configurar el plugin

**URKUNINA 5000 → Componentes → Asistente de voz.**

Las dos claves se pueden escribir en el panel, pero **es mejor ponerlas en
`wp-config.php`**: así no entran en la base de datos ni viajan en una
exportación, y la constante gana sobre lo guardado.

```php
define( 'UHP_LLM_API_KEY', 'la-clave-del-modelo' );
define( 'UHP_ELEVENLABS_API_KEY', 'la-clave-de-elevenlabs' );
```

Los campos de clave del panel se pintan **siempre vacíos**: dejarlos en blanco
conserva la guardada, de modo que tocar cualquier otro ajuste de la pestaña no
la borra. Para eliminar una, escriba `borrar`.

### Por dónde pregunta el visitante

| | Por voz | Por escrito |
|---|---|---|
| Micrófono | Lo pide el **navegador**, no este plugin | **No se pide nunca** |
| Qué sale de este sitio | Texto: lo que el navegador transcribió | Texto: lo que se tecleó |
| Lo que factura ElevenLabs | Cada carácter leído en alto | Nada |
| Instrucciones que recibe el modelo | Resumir: una lista de once no se retiene de oído | Enumerar: se puede releer |
| CSP que necesita el sitio | `media-src 'self' blob:` | Nada |

**Lo recomendable es dejar los dos.** La Resolución 1519 de 2020 pide una
alternativa equivalente, y el canal escrito es la que sirve a quien no puede
hablar, no tiene micrófono, usa un navegador sin dictado o está en un sitio
donde no va a hablarle en voz alta a una página web.

Si la voz no está configurada, el canal escrito aparece de todas formas aunque
el ajuste diga «solo voz»: quedarse sin ninguno de los dos no es una opción.

### El dictado por micrófono no lo hace este plugin

Lo hace el navegador del visitante, con su propio motor de reconocimiento de
voz, y eso tiene dos consecuencias que conviene decir antes de encenderlo:

- En **Chrome, Edge y Safari** el audio se envía a servidores de **Google o de
  Apple** para convertirlo en texto. Ni este sitio ni ElevenLabs intervienen
  ahí. La entidad **no controla ese tratamiento y no puede pactarlo**: es del
  navegador que el ciudadano eligió, y ocurre igual en cualquier página que use
  dictado.
- En **Firefox, Opera, Brave y Vivaldi** no funciona. En algunos el objeto
  existe y falla después, en tiempo de ejecución, porque el motor es un servicio
  de Google que esos navegadores no tienen. El asistente lo detecta, lo dice con
  esas palabras y deja el canal escrito, en vez de ofrecer un botón que falla
  cada vez.

Si eso no le encaja, ponga **«solo por escrito»**: entonces no se pide el
micrófono nunca y no hay audio de nadie en ninguna parte.

### El aviso previo es un ajuste, no una puerta

Viene **apagado**. Con esta arquitectura el asistente no graba ni guarda la voz
de nadie: el navegador convierte el dictado en texto y lo que sale de aquí es
texto. Enciéndalo si el área jurídica de la entidad lo pide de todas formas, o
si prefiere avisar de que la pregunta se envía a un proveedor fuera del país.
Encendido, pide la política de tratamiento, un correo y un segundo canal para
ejercer derechos, y lo que el proveedor declare sobre retención.

Suba la **versión del aviso** cada vez que cambie su texto: las autorizaciones
dadas con la versión anterior dejan de valer y se vuelve a preguntar.

### Publicar y ser visible son dos ejes

Con un solo interruptor no se puede ensayar el asistente en el sitio de verdad
sin ponerlo delante de un ciudadano.

| Visibilidad | Qué recibe el visitante anónimo |
|---|---|
| **Todo el mundo** | El asistente |
| **Solo quien pueda editar contenido** | El tablero sin asistente: sin botón, sin su script y sin su hoja de estilos |
| **Nadie** | El panel se carga pero no se pinta ningún botón: lo abre el control que usted ponga en la plantilla |

Con «solo quien pueda editar», la página **depende de quién la pide**. El plugin
le pide a la caché que no la guarde y las capas de caché de WordPress dejan de
servir copia a quien trae la cookie de sesión. Si su instalación tiene delante
una caché o una CDN que sirve una sola copia a todo el mundo sin mirar la
cookie, esa opción no protege nada: use «nadie» mientras ensaya.

### Verificar antes de publicar

El botón **«Verificar las claves»** le pregunta a cada proveedor si lo
configurado sirve. Gasta una pregunta mínima —una línea al modelo, sin
herramientas, y un listado de voces a ElevenLabs—, no una conversación.

Comprueba tres cosas, y la tercera es la que evita un panel mudo en producción:

1. Que la clave del modelo vale y que el modelo escrito existe.
2. Que la clave de ElevenLabs vale.
3. **Que el identificador de voz pertenece a esa cuenta.** Uno de otra cuenta, o
   de una voz retirada de la biblioteca, no falla al guardarlo: falla la primera
   vez que alguien pregunta algo. Si no está, el panel lista las voces que sí
   hay.

El informe se guarda doce horas. Vuelva a verificar después de cambiar una clave
o la voz.

### Atributos de página

Pueden **estrechar** lo que permite el sitio; ampliarlo no, porque el contenido
de un post lo edita cualquiera con permiso de edición y por dónde sale la voz de
un ciudadano no es una decisión de redacción.

```
[urkunina_dashboard voz="no"]              apagarlo en esta página
[urkunina_dashboard voz_visible="no"]      sin botón, para abrirlo desde la plantilla
[urkunina_dashboard voz_entrada="texto"]   solo el canal escrito
```

Las claves **no** son atributos del shortcode, y no van a serlo: escritas en el
contenido de una página quedarían a la vista de cualquiera con permiso de
edición, guardadas en cada revisión del post, y servidas en el HTML a quien
abriera la página.

---

## 4. Política de seguridad de contenido

Casi nada, y es el mejor resultado de haber cambiado de arquitectura. El
navegador no habla con **ningún** dominio externo: le pregunta a este sitio y el
servidor hace el resto.

Queda **una** directiva, y solo si la voz está encendida:

```
media-src 'self' blob:
```

El audio llega dentro de la respuesta del servidor, en base64, se reconstruye en
memoria y se reproduce desde una URL `blob:`. **`default-src 'self'` no cubre
`blob:`**, de modo que sin esta línea el panel funciona entero —texto, mapa,
gráficas— y simplemente no suena. Es un fallo mudo y desconcertante: no se nota
hasta que alguien pregunta por qué.

`media-src` no permite ejecutar nada. Es una directiva mucho más estrecha que
las que pedía la arquitectura anterior, que necesitaba `blob:` en `script-src`
—justo lo que una entidad pública no quiere abrir—, el dominio de una CDN, un
WebSocket contra el proveedor y cuatro dominios de LiveKit con comodín.

Si la voz está apagada o sin configurar, no hace falta ni eso.

Aparte de la CSP: si el sitio envía una cabecera `Permissions-Policy`, el
micrófono tiene que estar permitido para este origen o el dictado no arranca.

---

## 5. Qué comprobar antes de darlo por bueno

- [ ] **Verificar las claves** en el panel, y que las dos líneas salen en verde
      (o que la de la voz dice «apagada», si no la quiere).
- [ ] Preguntar algo por escrito y comprobar que el tablero **se mueve**.
- [ ] Preguntar algo por voz en Chrome y comprobar que **suena**.
- [ ] Abrirlo en Firefox: tiene que decir que ese navegador no reconoce la voz
      y dejar escribir, no quedarse con un botón que no responde.
- [ ] Denegar el micrófono a propósito: tiene que quedar **por dónde seguir**,
      no un cartel y nada que pulsar.
- [ ] Recorrer el panel entero **con el teclado**: foco visible en cada paso.
- [ ] Mirar el código fuente de la página y buscar las claves. No pueden estar.
- [ ] Comprobar el **tope diario** y el límite de gasto del proveedor.

Si algo de esto falla, el reporte de los errores de configuración ya
diagnosticados —qué significa cada mensaje del panel, qué líneas escribe el
plugin en el registro de PHP y en la consola del navegador, y qué mirar en
cada caso— está en [`diagnostico.md`](diagnostico.md).

El JSON con las definiciones de las diez herramientas está en
[`herramientas.json`](herramientas.json). No hay que pegarlo en ninguna parte:
el servidor se lo manda al modelo en cada pregunta. Está ahí para poder leerlo
sin abrir código PHP.
