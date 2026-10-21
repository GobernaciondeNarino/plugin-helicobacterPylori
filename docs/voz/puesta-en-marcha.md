# Asistente del tablero — puesta en marcha

El tablero de URKUNINA 5000 puede responder preguntas sobre sus propias cifras y
mover sus filtros mientras lo hace: «muéstrame la zona roja», «¿cuánto tiene
Túquerres?», «cambia a *H. pylori*», «¿qué subregión está peor?». El motor de
conversación lo pone ElevenLabs; todo lo que el ciudadano ve lo pone el plugin.

**Hay dos canales y no son la misma cosa.**

| | Por voz | Por escrito |
|---|---|---|
| Micrófono | Se pide permiso | **No se pide nunca** |
| Qué sale del navegador | Audio y su transcripción | Solo el texto que se teclea |
| Aviso de habeas data | El largo: incluye el tratamiento de una muestra de voz, que es un dato biométrico | El corto: sin dato biométrico que autorizar |
| Lo que factura el proveedor | Conversación y síntesis de voz | Conversación, sin síntesis |
| CSP que necesita el sitio | Con `blob:` en `script-src` | Sin `blob:` |
| Transporte | WebSocket o WebRTC | Siempre WebSocket |

La elección está en el panel («por dónde se puede preguntar») y se puede
estrechar por página. **Lo recomendable es dejar los dos**: la Resolución 1519
de 2020 pide una alternativa equivalente, y el canal escrito es la que sirve a
quien no puede hablar, no tiene micrófono, o está en un sitio donde no va a
hablarle en voz alta a una página web.

**Nace apagado y no se enciende solo.** Esta guía es la lista de lo que hay que
tener resuelto antes de que el botón aparezca en la página.

---

## 1. Lo que no es técnico y va primero

Estas cuatro cosas no las resuelve el panel de WordPress. Sin ellas, encender el
asistente deja a la entidad expuesta.

| Puerta | Por qué |
|---|---|
| **Acuerdo de encargo del tratamiento** con ElevenLabs Inc. | La entidad sigue siendo responsable de los datos; el proveedor es encargado. |
| **Anexo de transferencia internacional** | Los servidores están fuera de Colombia. Artículo 26 de la Ley 1581 de 2012. |
| **Qué conserva el proveedor**, por escrito | Va literalmente en el aviso que lee el ciudadano. Sin ese dato, el aviso no informa. |
| **Evaluación propia de accesibilidad**, firmada | Resolución 1519 de 2020. Teclado, foco visible, lector de pantalla y móvil. |

Mientras falte una, `UHP_Voz::configurada()` devuelve `false` y el tablero sale
byte por byte como si esta función no existiera.

---

## 2. Crear el agente en ElevenLabs

1. **Cree un agente** en la plataforma de agentes de ElevenLabs.
2. **Idioma**: español. Voz latinoamericana. Y **compruebe el modelo de voz**:
   el identificador de la voz **no** cambia el modelo, y los modelos «flash v2»
   y «turbo v2» a secas son solo de inglés. Un agente con uno de ellos no habla
   español por mucho que se le ponga una voz colombiana. Los multilingües sí.
   Además, las voces con **moderación en vivo** no se pueden usar en agentes:
   si la que eligió la tiene, elija otra.
3. **Instrucciones**: pegue el texto del campo `prompt` de
   [`herramientas.json`](herramientas.json). También está, copiable, en
   **URKUNINA 5000 → Componentes → Asistente de voz**.
4. **Herramientas**: cree las **diez** del archivo, todas como herramientas de
   **cliente** (*client tools*), no de servidor. Las ejecuta el navegador del
   visitante.
   - Marque «esperar respuesta» **solo** en las cinco que leen
     (`uhp_leer_estado_tablero`, `uhp_consultar_municipio`,
     `uhp_listar_municipios`, `uhp_comparar_subregiones`,
     `uhp_consultar_perfil`). Las cinco que mueven el tablero no la esperan:
     así la pantalla cambia *mientras* el agente habla y no después.
5. **Variables dinámicas**: el prompt interpola ocho entre llaves —`canal`,
   `indicador`, `zona`, `subregion`, `municipio`, `municipios_visibles`,
   `casos_visibles`, `promedio_visible`—. El navegador las manda **siempre**,
   también cuando el tablero aún no ha cargado sus datos. No hace falta
   declararlas aparte, pero **no borre una a medias del prompt**: si el prompt
   escribe una que el navegador no manda, el proveedor rechaza la conversación.
   Una comprobación de `tests/test-datos.php` compara las dos listas.
6. **Sustituciones** (*overrides*), en la configuración de seguridad del agente.
   Habilite solo las que vaya a usar:
   - **`text_only`**, si va a ofrecer el canal escrito. Sin ella el proveedor
     sintetiza el audio de cada respuesta —y lo factura— para que el navegador
     lo tire a la basura.
   - **`voice_id`** y **`speed`**, solo si va a fijar una voz o una velocidad
     distintas de las que tenga el agente.

   **No es una degradación silenciosa.** Si se envía una sustitución que el
   agente no admite, la conversación entera falla, y en el historial de llamadas
   de ElevenLabs aparece *«Override is not allowed for this AI agent»*. Por eso
   el plugin **solo envía lo que su verificación confirme** (véase más abajo):
   preferimos un asistente que funciona con la voz del agente, y un panel que lo
   dice, a un asistente que no abre.

   Si lo único que quiere es el canal escrito, hay una alternativa mejor que la
   sustitución: configurar el agente **entero** como solo texto, en su pestaña
   avanzada. Eso no necesita habilitar ninguna sustitución —una sustitución
   habilitada significa que cualquier página que pueda abrir sesión contra el
   agente puede fijar ese valor—, pero entonces el canal hablado no funcionará
   contra ese agente. El panel lo detecta y lo avisa.
7. **Dominios permitidos**: ponga el dominio del sitio y **ninguno más**. Esta
   es la frontera real. La comprobación de origen que hace el servidor del
   plugin es un badén, no una frontera: la cabecera `Origin` es falsificable.
8. **Agente privado**, no público. Con uno público, cualquiera que lea el código
   fuente de la página puede abrir conversaciones contra su agente desde otro
   sitio, y las paga la entidad.
9. **Anote dos identificadores distintos**, porque se confunden:
   - el del **agente**, que empieza por `agent_`, y es la conversación entera:
     su prompt, sus herramientas, su idioma;
   - el de la **voz**, si va a fijar una: una cadena de letras y números, sin
     prefijo, que ElevenLabs muestra en cada voz de la biblioteca. Es solo el
     timbre con que se lee la respuesta, y en el canal escrito no se usa.

   Poner uno en el campo del otro es el error más fácil de cometer y el más
   difícil de ver: el plugin rechaza lo que no tiene la forma correcta y deja el
   campo vacío, de modo que el síntoma es «no se guarda», no «está mal».

---

## 3. Configurar el plugin

En **URKUNINA 5000 → Componentes → Asistente de voz**:

- **Identificador del agente** (`agent_…`).
- **Identificador de la voz** y **velocidad de habla**, si quiere fijarlas. Las
  dos son opcionales y las dos exigen que el agente admita la sustitución
  correspondiente. La velocidad va entre 0,70 y 1,20; por debajo de 1 habla más
  despacio, que es lo que agradece buena parte del público de este tablero —la
  población tamizada tiene entre 40 y 65 años—. Un valor fuera de margen se
  **descarta** en vez de recortarse: dejarle 1,20 a quien escribió 3 le haría
  creer que se guardó lo que pidió.
- **Por dónde se puede preguntar**: por voz y por escrito, solo por voz, o solo
  por escrito. Véase la tabla del principio.
- **Visible para**: todo el mundo, solo quien pueda editar contenido, o nadie.
- Tipo: **privado**.
- Clave de API. **Mejor en `wp-config.php`**, que así no entra en la base de
  datos ni viaja en una exportación:

  ```php
  define( 'UHP_ELEVENLABS_API_KEY', 'sk_...' );
  ```

  **La clave no es un atributo del shortcode y no va a serlo.** Escrita en el
  contenido de una página quedaría a la vista de cualquiera con permiso de
  edición, guardada en cada revisión del post, y servida en el HTML a quien
  abriera la página.
- Tope diario de conversaciones. Sin tope, una tarde de tráfico inesperado se
  lleva el presupuesto del trimestre. **Cuenta las dos clases de conversación**:
  el servidor firma una credencial de sesión sin saber si el navegador la va a
  usar para hablar o para escribir, de modo que una sesión escrita gasta una
  ranura igual aunque cueste menos.
- Los cuatro datos del aviso: política, correo, segundo canal y retención.
- La casilla de la evaluación de accesibilidad, cuando esté firmada.
- Por último, **Publicar el asistente**.

### Publicar y ser visible son dos ejes

Hacen falta los dos. Con un solo interruptor no se puede ensayar el asistente en
el sitio de verdad sin ponerlo delante de un ciudadano: o está apagado y no se
puede probar, o está encendido para todos.

| Visible para | Qué recibe el visitante anónimo |
|---|---|
| **Todo el mundo** | El asistente, con su botón. |
| **Solo quien pueda editar** | El tablero **sin** asistente: ni botón, ni panel, ni script, ni hoja de estilos. La página sale como si no estuviera configurado. |
| **Nadie** | El panel cargado y **sin botón**, para abrirlo desde un control propio de la plantilla. |

Con «solo quien pueda editar», la salida de la página **depende de quién la
pide**. El plugin define `DONOTCACHEPAGE`, que es el acuerdo que respetan las
capas de caché de WordPress, y esas capas además dejan de servir copia a quien
trae la cookie de sesión. Si su instalación tiene delante una caché o una CDN
que sirve una sola copia a todo el mundo sin mirar la cookie, **esta opción no
protege nada**: use «nadie» mientras ensaya.

### Verificar antes de publicar

El botón **«Verificar la configuración del agente»** del panel le pregunta a
ElevenLabs, con la clave ya guardada, tres cosas que no se pueden adivinar:

- si la **clave** sirve;
- si el **agente existe** en esa cuenta —y si no, nombra el error más fácil de
  cometer: haber pegado el identificador de la voz en el campo del agente—;
- qué **sustituciones** admite, y en qué **idioma** y con qué **modelo de voz**
  está configurado.

El resultado se guarda doce horas y es lo que decide qué se envía. **Mientras no
verifique, la voz y la velocidad que configure aquí no se envían.** Vuelva a
verificar cada vez que toque la seguridad del agente.

En **modo público** no hay clave con la que preguntar: ahí se envía lo
configurado y la responsabilidad es de quien lo configuró. El panel lo advierte.

### Atributos de página

Pueden **estrechar** lo que permite el sitio; ampliarlo no.

```
[urkunina_dashboard voz="no"]               apagado en esta página
[urkunina_dashboard voz_visible="no"]       cargado y sin botón
[urkunina_dashboard voz_entrada="texto"]    aquí solo el canal escrito
```

La asimetría es deliberada: el contenido de un post lo edita cualquiera con
permiso de edición, y por dónde sale la voz de un ciudadano no es una decisión
de redacción.

---

## 4. Política de seguridad de contenido

La receta depende del canal, y el panel la genera a partir de la configuración
real: no la copie de aquí si en el panel dice otra cosa.

**Con canal de voz** (solo voz, o los dos):

```
script-src   https://cdn.jsdelivr.net blob:
connect-src  https://api.elevenlabs.io wss://api.elevenlabs.io
worker-src   'self' blob:
media-src    'self' blob:
```

**Solo canal escrito** — se encoge de verdad, no por optimismo:

```
script-src   https://cdn.jsdelivr.net
connect-src  https://api.elevenlabs.io wss://api.elevenlabs.io
```

Sin micrófono no hay `AudioContext`, sin `AudioContext` no hay worklets, y sin
worklets no hay nada que cargar desde un `blob:`. Una entidad que solo quiera el
canal escrito no tiene por qué abrir su política por un audio que nunca va a
sonar.

El `blob:` de **`script-src`** no es opcional en el canal de voz: los
procesadores de audio del SDK se instalan con `AudioWorklet.addModule()` desde
una URL `blob:`, y esa carga la gobierna `script-src` en los navegadores que la
aplican. Sin él, el asistente conecta y no se oye nada.

El SDK tiene un segundo recurso: si `blob:` falla, reintenta desde una URL
`data:`. **La receta no incluye `data:` a propósito.** Permitido `blob:`, ese
segundo recurso no se usa nunca, y `data:` en `script-src` abre una puerta que
no hace falta abrir.

Con transporte **WebRTC** hay que añadir además los dominios de LiveKit
(`*.livekit.cloud`, `*.livekit.run`), por donde va el audio. Con **WebSocket**
—el valor por defecto— no hacen falta: el navegador solo habla con
`api.elevenlabs.io`. Es una razón de peso para no cambiarlo. Una sesión escrita
va siempre por WebSocket, de modo que no los arrastra ni con WebRTC configurado.

### Para una CSP sin `blob:` y con voz

El SDK admite auto-hospedar sus dos worklets y el remuestreador, y entonces los
carga desde una ruta normal del sitio en lugar de un `blob:`. El plugin no lo
hace por omisión —habría que versionar copias de archivos generados que cambian
con cada versión del SDK—, pero la puerta existe: `AudioWorkletConfig`, con
`workletPaths.rawAudioProcessor`, `workletPaths.audioConcatProcessor` y
`libsampleratePath`. Si la entidad necesita esa política, es el camino; hay que
extraer los archivos del paquete `@elevenlabs/client` y fijarlos a la versión
que sirve el sitio.

Para servir el SDK desde el propio dominio en lugar del CDN:

```php
add_filter( 'uhp_url_libreria', function ( $url, $clave ) {
    return 'elevenlabs' === $clave ? content_url( '/vendor/elevenlabs.js' ) : $url;
}, 10, 2 );
```

El panel deriva el host de la receta de esa URL, así que la sigue sola.

---

## 5. Qué comprobar antes de darlo por bueno

- [ ] Los botones aparecen en el tablero y el aviso corto se lee **con
      JavaScript desactivado**.
- [ ] Al pulsar sale el aviso completo y **el micrófono no se enciende** hasta
      aceptar.
- [ ] En el canal escrito **el navegador no pide el micrófono ni una vez**. Es
      la diferencia entre «no hay audio» y «no se pide permiso para el audio»,
      y es lo que lo convierte en una alternativa de verdad.
- [ ] Aceptar el aviso escrito **no** autoriza el micrófono. Al revés sí: el
      aviso de la voz dice que puede escribir en lugar de hablar.
- [ ] «Retirar autorización» corta la conversación en el acto, y retira las dos.
- [ ] Todo se maneja con el teclado, y el foco se ve.
- [ ] Un lector de pantalla anuncia los cambios que hace el asistente, y los
      distingue de los que hace el visitante.
- [ ] Pedir un municipio que no existe **no** selecciona otro parecido.
- [ ] Pedir Tumaco responde que no entró en el estudio, no que no hay dato.
- [ ] Preguntar por las subregiones nombra **Pacífico Sur y Sanquianga**: son
      dos de las trece del departamento, el informe no publica su cifra y no
      aparecen en el gráfico de barras. Si el asistente enumera once y las llama
      «las subregiones de Nariño», ha borrado dos territorios.
- [ ] **Verificó la configuración del agente** y el informe no deja ninguna
      sustitución en rojo. Sin eso, la voz configurada no se envía y el canal
      escrito está pagando una síntesis de voz que nadie oye.

Las pruebas de `tests/navegador.spec.js` cubren lo que puede comprobarse sin
hablar; las casillas de los municipios y las subregiones tienen prueba propia.
Lo que queda —la voz de verdad, la latencia, la pronunciación de los nombres—
hay que oírlo.
