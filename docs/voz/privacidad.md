# Asistente del tablero — qué sale del navegador y con qué amparo

Análisis de protección de datos del asistente de URKUNINA 5000, para que quien
tenga que firmar la decisión de encenderlo sepa exactamente qué se trata, dónde
y con qué fundamento. Este documento describe lo que hace el plugin; la decisión
es de la entidad.

**Lo que cambió respecto de la versión anterior.** El asistente ya no abre una
sesión de voz contra un proveedor externo desde el navegador del visitante.
Ahora el navegador habla **solo con este sitio** y el servidor habla con el
proveedor. Eso elimina el tratamiento que más justificación pedía: **ya no se
captura, ni se transmite, ni se almacena ninguna muestra de voz**. Lo que sale
de este servidor es texto.

---

## 1. Qué se envía, exactamente

### Del navegador del visitante a este sitio

Una petición `POST` a `/wp-json/urkunina/v1/voz/chat` con:

- La **pregunta escrita o dictada**, ya convertida en texto.
- El **historial** de esa conversación: lo preguntado, lo respondido y los
  resultados de las herramientas del tablero.
- Una **línea con lo que hay en pantalla** («Lesión precursora, zona roja, 12
  municipios a la vista»), cuando cambió desde la pregunta anterior.
- El **canal** (`voz` o `texto`), que decide si el modelo resume o enumera.

Y, si la voz está encendida, una petición a `/voz/hablar` con **el texto de la
respuesta** que acaba de pintarse.

Nada más. No viaja ninguna clave, ni qué proveedor hay detrás, ni ningún
identificador del visitante que el plugin genere.

### De este sitio al proveedor del modelo

El historial anterior, más las instrucciones del sistema y las definiciones de
las diez herramientas. **El historial se corta**: sesenta mensajes como máximo,
2.000 caracteres por pregunta y 24.000 en total; pasado eso, la conversación se
cierra y se invita a empezar otra.

### De este sitio a ElevenLabs

El texto de la respuesta y el identificador de la voz. Solo en el canal hablado.

---

## 2. El dictado por micrófono: lo que este plugin NO controla

Esta es la parte que la entidad debe conocer antes de encenderlo, porque es la
única donde hay un tratamiento que no pasa por aquí.

Cuando el visitante pulsa «Hablar», **el reconocimiento de voz lo hace su
navegador**, no este plugin y no ElevenLabs. En la práctica:

| Navegador | Qué ocurre |
|---|---|
| Chrome, Edge | El audio se envía a servidores de **Google** para transcribirlo |
| Safari | El audio se envía a servidores de **Apple** |
| Firefox, Opera, Brave, Vivaldi | **No funciona.** Esos visitantes solo tienen el canal escrito |

Consecuencias que hay que poner por escrito:

1. **La entidad no es responsable de ese tratamiento ni puede pactarlo.** No hay
   encargo que firmar con Google o Apple por esto: el tratamiento es del
   navegador que el ciudadano eligió e instaló, y ocurre igual en cualquier
   página que use dictado, también fuera de este sitio.
2. **Este servidor nunca ve ese audio.** Recibe la transcripción, ya en texto.
3. **Es evitable por completo.** Con el ajuste «solo por escrito», el micrófono
   no se pide nunca y esta sección deja de aplicar.

Si la entidad considera que no puede informar de esto con claridad suficiente en
su aviso, la decisión correcta es dejar el asistente en «solo por escrito». El
asistente funciona entero así; no es una versión capada.

---

## 3. A dónde va lo que sí pasa por aquí

| Destino | Qué recibe | Dónde |
|---|---|---|
| Este sitio | La pregunta y el historial | Donde esté alojado el sitio |
| Proveedor del modelo (Anthropic, OpenAI o Google) | La pregunta, el historial, las instrucciones y las herramientas | Fuera de Colombia |
| ElevenLabs | El texto de la respuesta, solo en el canal hablado | Fuera de Colombia |

Las dos últimas son **transferencias internacionales**: artículo 26 de la Ley
1581 de 2012. Exigen acuerdo de encargo del tratamiento y el anexo
correspondiente, y eso el plugin no lo puede comprobar.

---

## 4. Con qué amparo

El tratamiento que queda es el de **lo que el ciudadano escribe o dicta**, que
es un dato personal solo si él decide ponerlo ahí. No hay dato biométrico, no
hay dato sensible pedido por el plugin, y no se recoge identidad ninguna.

- **Ley 1581 de 2012**, artículos 4 (principios), 9 (autorización) y 26
  (transferencia internacional).
- **Resolución 1519 de 2020**, en lo que toca a la alternativa equivalente: el
  asistente **no es la única vía para nada**. Los cuatro filtros del tablero se
  manejan con el teclado y con el ratón, y el canal escrito existe para quien no
  puede o no quiere hablar.

**El aviso previo es un ajuste del panel, apagado por omisión.** Esa decisión
tiene una razón: pedir una autorización donde no hay un tratamiento que
autorizar enseña a aceptar avisos sin leerlos, y el día que haya uno que sí
importe nadie lo leerá tampoco. Si la entidad prefiere avisar de la
transferencia internacional antes de la primera pregunta, el aviso se enciende
en el panel y pide los datos que la ley manda informar.

Lo que el asistente **sí** le dice al visitante, siempre y sin ajuste que lo
apague: que no dicte ni escriba datos personales, suyos ni de nadie. Las
instrucciones que recibe el modelo se lo repiten.

---

## 5. Lo que el plugin hace para reducir la huella

- **Ninguna clave llega al navegador.** Ni la del modelo ni la de ElevenLabs.
  Viven en una opción sin autocarga o, mejor, en `wp-config.php`.
- **El navegador no habla con ningún dominio externo.** No se descarga ningún
  SDK de terceros, no se abre ningún WebSocket contra el proveedor y no hay
  dominios de terceros que abrir en la política de seguridad de contenido.
- **Tope diario de preguntas y límite por IP**, aplicados en el servidor. En la
  arquitectura anterior la sesión no pasaba por aquí y ninguno de los dos se
  aplicaba de verdad.
- **Tope de herramientas por pregunta**, para que un modelo confundido no se
  quede dando vueltas a costa de la entidad.
- **El historial no se guarda.** Vive en la memoria del navegador mientras dura
  la conversación. Cerrar el panel lo borra; no hay base de datos, ni registro,
  ni transcripción que conservar o que pedir.
- **Lo que devuelve el proveedor nunca se inserta como HTML.** Se pinta como
  texto, nodo a nodo. Un modelo que devolviera una etiqueta la vería el
  visitante escrita, no ejecutada.
- **El prompt y las herramientas los pone el servidor** en cada pregunta, y no
  se pueden editar desde el contenido de una página: son las reglas de
  honestidad con las que este tablero publica cifras, y no deben depender de
  quién edita un post.
- **La autorización, si el aviso está encendido**, se guarda en el
  almacenamiento de **sesión** del navegador del visitante y se va al cerrar la
  pestaña.

---

## 6. Lo que la entidad sí debe resolver

| Punto | Por qué |
|---|---|
| Acuerdo de encargo con el proveedor del modelo | La entidad es responsable; el proveedor, encargado |
| Anexo de transferencia internacional | Artículo 26 de la Ley 1581 |
| Lo mismo con ElevenLabs, si enciende la voz | Recibe el texto de las respuestas |
| Límite de gasto en la consola del proveedor | El tope del panel cuenta preguntas en **este** sitio; no frena un cargo hecho con la misma clave desde otro |
| Evaluación propia de accesibilidad, firmada | Resolución 1519 de 2020 |
| Decidir si informa del dictado del navegador | Sección 2. No es tratamiento de la entidad, pero el ciudadano puede querer saberlo |

---

## 7. Qué pasa si se apaga

El tablero sale **byte por byte** como si esta función no existiera: sin botón,
sin su script, sin su hoja de estilos y sin una sola línea de marcado del
asistente. Las cifras, el mapa, las gráficas y los cuatro filtros siguen
funcionando igual, porque el asistente nunca fue la vía para nada.
