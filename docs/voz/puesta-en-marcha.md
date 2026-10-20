# Asistente de voz del tablero — puesta en marcha

El tablero de URKUNINA 5000 puede responder preguntas habladas y mover sus
propios filtros mientras lo hace: «muéstrame la zona roja», «¿cuánto tiene
Túquerres?», «cambia a *H. pylori*». La voz la pone ElevenLabs; todo lo que el
ciudadano ve lo pone el plugin.

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
2. **Idioma**: español. Voz latinoamericana.
3. **Instrucciones**: pegue el texto del campo `prompt` de
   [`herramientas.json`](herramientas.json). También está, copiable, en
   **URKUNINA 5000 → Componentes → Asistente de voz**.
4. **Herramientas**: cree las **ocho** del archivo, todas como herramientas de
   **cliente** (*client tools*), no de servidor. Las ejecuta el navegador del
   visitante.
   - Marque «esperar respuesta» **solo** en las tres que leen
     (`uhp_leer_estado_tablero`, `uhp_consultar_municipio`,
     `uhp_listar_municipios`). Las cinco que mueven el tablero no la esperan:
     así la pantalla cambia *mientras* el agente habla y no después.
5. **Dominios permitidos**: ponga el dominio del sitio y **ninguno más**. Esta
   es la frontera real. La comprobación de origen que hace el servidor del
   plugin es un badén, no una frontera: la cabecera `Origin` es falsificable.
6. **Agente privado**, no público. Con uno público, cualquiera que lea el código
   fuente de la página puede abrir conversaciones contra su agente desde otro
   sitio, y las paga la entidad.
7. **Anote el identificador** del agente: empieza por `agent_`.

---

## 3. Configurar el plugin

En **URKUNINA 5000 → Componentes → Asistente de voz**:

- Identificador del agente.
- Tipo: **privado**.
- Clave de API. **Mejor en `wp-config.php`**, que así no entra en la base de
  datos ni viaja en una exportación:

  ```php
  define( 'UHP_ELEVENLABS_API_KEY', 'sk_...' );
  ```

- Tope diario de conversaciones. El servicio se factura por minuto: sin tope,
  una tarde de tráfico inesperado se lleva el presupuesto del trimestre.
- Los cuatro datos del aviso: política, correo, segundo canal y retención.
- La casilla de la evaluación de accesibilidad, cuando esté firmada.
- Por último, **Publicar el asistente de voz**.

Para apagarlo en una página concreta sin apagarlo en el sitio:

```
[urkunina_dashboard voz="no"]
```

---

## 4. Política de seguridad de contenido

Si el servidor web aplica una CSP, el asistente necesita:

```
script-src   https://cdn.jsdelivr.net
connect-src  https://api.elevenlabs.io wss://api.elevenlabs.io
worker-src   'self' blob:
media-src    'self' blob:
```

Con transporte **WebRTC** hay que añadir además los dominios de LiveKit
(`*.livekit.cloud`, `*.livekit.run`), por donde va el audio. Con **WebSocket**
—el valor por defecto— no hacen falta: el navegador solo habla con
`api.elevenlabs.io`. Es una razón de peso para no cambiarlo.

Los procesadores de audio del SDK se instalan desde una URL `blob:`; de ahí el
`worker-src`.

Para servir el SDK desde el propio dominio en lugar del CDN:

```php
add_filter( 'uhp_url_libreria', function ( $url, $clave ) {
    return 'elevenlabs' === $clave ? content_url( '/vendor/elevenlabs.js' ) : $url;
}, 10, 2 );
```

---

## 5. Qué comprobar antes de darlo por bueno

- [ ] El botón aparece en el tablero y el aviso corto se lee **con JavaScript
      desactivado**.
- [ ] Al pulsar sale el aviso completo y **el micrófono no se enciende** hasta
      aceptar.
- [ ] «Retirar autorización» corta la conversación en el acto.
- [ ] Todo se maneja con el teclado, y el foco se ve.
- [ ] Un lector de pantalla anuncia los cambios que hace el asistente, y los
      distingue de los que hace el visitante.
- [ ] Pedir un municipio que no existe **no** selecciona otro parecido.
- [ ] Pedir Tumaco responde que no entró en el estudio, no que no hay dato.

Las dieciséis pruebas de `tests/navegador.spec.js` cubren lo que puede
comprobarse sin hablar; las dos últimas casillas tienen prueba propia. Lo que
queda —la voz de verdad, la latencia, la pronunciación de los nombres— hay que
oírlo.
