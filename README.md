# URKUNINA 5000 — plugin de WordPress

Plataforma de visualización del proyecto **URKUNINA 5000** (BPIN 2015000100064):
investigación de la prevalencia de lesiones precursoras de malignidad y efecto de
la erradicación de *Helicobacter pylori* como prevención primaria del cáncer
gástrico en el departamento de Nariño.

> **Gobernación de Nariño · Secretaría TIC, Innovación y Gobierno Abierto**
> Pasto, Nariño · Colombia

El nombre viene de *Urkunina*, «montaña de fuego» en lengua indígena, como se
conoce al volcán Galeras.

---

## Qué hace

Convierte el conjunto de datos del proyecto —diecinueve archivos JSON más la
cartografía municipal y subregional— en componentes publicables desde el editor
de WordPress:

| Componente | Shortcode |
|---|---|
| Tablero de resultados: mapa del departamento, filtros y lectura del territorio | `[urkunina_dashboard]` |
| Asistente sobre el tablero, por voz o por escrito, con filtros que obedecen | `[urkunina_dashboard voz="si\|no" voz_visible="si\|no" voz_entrada="ambos\|voz\|texto"]` |
| Recreación 3D de *Helicobacter pylori* con línea de tiempo | `[urkunina_3d desplazar="si\|no"]` |
| Gráfico de cualquiera de las 29 vistas del catálogo, con el mapa entre sus tipos | `[urkunina_grafico view="…" type="…"]` |
| Mapa coroplético de los 64 municipios sobre OpenStreetMap | `[urkunina_mapa]` |
| Geomapa de D3plus de una vista territorial, por municipio o por subregión | `[urkunina_geomapa view="…" teselas="si\|no"]` |
| Tarjetas con las cifras clave del proyecto | `[urkunina_kpi]` |
| Tabla accesible de los datos de una vista | `[urkunina_tabla view="…"]` |
| Ficha de identificación y ejecución del proyecto | `[urkunina_ficha]` |
| Un dato suelto intercalado en un párrafo | `[urkunina_dato archivo="…" ruta="…"]` |

Los textos de cada vista son shortcodes independientes del gráfico, para poder
maquetarlos por libre —el gráfico en una columna y su lectura en otra, o el texto
abriendo la sección y el gráfico cerrándola—:

| Pieza de texto | Shortcode |
|---|---|
| Nombre de la vista, con la etiqueta que se le indique | `[urkunina_titulo view="…" etiqueta="h3"]` |
| Qué muestra el gráfico y cómo leerlo | `[urkunina_descripcion view="…"]` |
| Qué significa lo que se ve | `[urkunina_interpretacion view="…"]` |
| Lectura automática del hallazgo principal | `[urkunina_resumen view="…"]` |
| Cifras de apoyo redactadas a partir de los datos | `[urkunina_cifras view="…"]` |
| Atribución de la fuente del dato | `[urkunina_fuente view="…"]` |
| Varias de las anteriores en un solo bloque | `[urkunina_analisis view="…" modo="completo"]` |
| Lista que agrupa todas las vistas de una pestaña | `[urkunina_selector grupo="…"]` |

Los siete se renderizan en el servidor: el texto viaja en el HTML, sin petición
ni parpadeo, y sigue ahí con JavaScript desactivado o para un buscador.

### Una lista que gobierna todas las piezas

Una pestaña entera del catálogo puede publicarse con una sola lista
desplegable. Al elegir un nombre cambian a la vez el título, la descripción, la
interpretación, el resumen, las cifras, la fuente, la tabla y el gráfico:

```
[urkunina_selector    grupo="Prevalencia"]
[urkunina_titulo      grupo="Prevalencia" etiqueta="h2"]
[urkunina_grafico     grupo="Prevalencia" alto="420px" titulo="no"]
[urkunina_descripcion grupo="Prevalencia"]
[urkunina_tabla       grupo="Prevalencia"]
[urkunina_fuente      grupo="Prevalencia"]
```

Las piezas siguen siendo independientes y no se conocen entre sí: pueden ir en
columnas distintas, en otro orden o repartidas por la página. Lo único que las
une es el grupo. `views="a,b,c"` sustituye a `grupo` para una lista a mano, y
`canal="…"` permite dos selectores independientes del mismo grupo en una página.

### El mapa es un tipo de gráfico más

Las vistas que nombran municipios o subregiones ofrecen **Mapa** junto a barras,
líneas y dona en la barra del gráfico, y el shortcode elige con cuál arranca:

```
[urkunina_grafico view="prev_lpm_municipios" type="mapa" teselas="si"]
```

> **La atribución dejó de ser automática.** Como `[urkunina_grafico]` ya no
> imprime la línea de fuente, publique `[urkunina_fuente view="…"]` junto a cada
> gráfico: citar la procedencia del dato es obligatorio.

El catálogo completo, con todos los atributos y ejemplos copiables, está en el
panel: **URKUNINA 5000 → Shortcodes**.

---

> **Al actualizar el plugin, suba la versión.** `UHP_VERSION` en
> `urkunina-5000.php` es el `?ver=` de cada hoja y cada script. Si no
> cambia, el navegador y la caché del sitio siguen sirviendo los archivos
> viejos aunque los nuevos ya estén en disco, y la página se ve a medias.
> Tras subirla, purgue la caché del sitio.

## Instalación

1. Copie la carpeta del plugin en `wp-content/plugins/urkunina-5000/`.
2. Actívelo desde **Plugins** en el escritorio de WordPress.
3. Compruebe **URKUNINA 5000 → Diagnóstico → Entorno**: ahí figura si el
   directorio `data/` tiene permisos de escritura, que es lo que habilita el
   módulo de actualización de datos.

**Requisitos:** WordPress 5.8 o superior, PHP 7.4 o superior, extensiones `json`
y `mbstring`. Para la escena 3D, un navegador con WebGL 2.

Hay dos maneras de llevar los datos al territorio y no compiten:
`[urkunina_mapa]` es un **visor** sobre Leaflet —se navega, se consulta municipio
a municipio, se cambia de indicador—; `[urkunina_geomapa]` es un **gráfico** de
D3plus, del mismo motor que el resto de vistas, que dibuja una plancha del
departamento con o sin cartografía de fondo según convenga a la página.

Publicar el tablero requiere una plantilla de página de ancho completo y sin
barra lateral: el contenedor ocupa el 100 % del ancho y toda la altura de la
ventana.

Es **interactivo de punta a punta** y con una sola petición: los 64 municipios
llegan con su geometría y sus cifras, y filtrar por zona, por subregión o por
municipio no vuelve a pedir nada. Se selecciona pulsando en el mapa, en la
lista de municipios, en una barra de subregión o en la lista de casos, y todo
lo demás se recoloca alrededor.

El mapa lo dibuja **D3 directamente** —proyección Mercator, zoom y arrastre
propios—, sin Leaflet y sin teselas: el tablero habla de los 64 municipios de
Nariño y una capa de calles no aporta a esa lectura.

Y **lo que no se puede afirmar, lo dice**:

- Un municipio **sin cifra propia** se pinta con la de su subregión, pero
  atenuado, y tanto el tooltip como la ficha lo marcan. El informe solo publica
  los extremos de la distribución municipal.
- Un municipio **no intervenido** no se colorea: va con trama discontinua. No
  falta el dato, es que el proyecto no estuvo allí.
- Un municipio **sin casos** tiene cero casos, no un hueco.
- El **perfil de los 5.000 participantes no responde a los filtros**: está
  publicado para el conjunto, no municipio a municipio.
- La cifra de **participantes por selección es un prorrateo** y lo dice en su
  propio rótulo.

La **zona de riesgo** que colorea los filtros es una derivación declarada: los
documentos describen las tres zonas y nombran territorios de referencia, pero
no reparten el departamento entre ellas. La asignación se hace por subregión,
vive en `data/17_zonas_riesgo_subregion.json` marcada como derivación, y trae
su propia comprobación contra esas referencias —que la suite ejecuta—.

El tablero tiene **un solo tema**, el oscuro sobre el que se diseñó: la rampa
del mapa y todos los contrastes están calculados sobre él.

---

## Módulos del panel

| Módulo | Para qué |
|---|---|
| **Panel** | Estado general, cifras del proyecto y qué conviene revisar hoy. |
| **Datos** | Consultar, validar, editar, subir y restaurar cada archivo del conjunto. |
| **Gráficos** | Catálogo de vistas con su descripción, su análisis y su shortcode. |
| **Shortcodes** | Los dieciséis componentes con sus atributos y ejemplos. |
| **Componentes** | Valores por defecto del tablero y del objeto 3D. |
| **Apariencia** | Identidad visual: paleta institucional y tipografía. |
| **Diagnóstico** | Entorno, convivencia con otros plugins y medidas de seguridad. |

---

## Convivencia con otros plugins

En el mismo sitio conviven otros desarrollos de la entidad que usan D3, D3plus,
Leaflet y Three.js. Dos copias de la misma librería en una página rompen desde
las leyendas de los gráficos hasta la inicialización de los mapas, así que el
plugin toma tres medidas:

- **Librerías compartidas.** D3, D3plus, Leaflet y Plotly se registran con su
  identificador de uso común y solo si nadie las registró antes. Si otro plugin
  ya las cargó, se reutiliza su copia.
- **Sin mapa de importaciones.** Un documento HTML solo admite uno, y otros
  plugins ya imprimen el suyo. El módulo 3D importa Three.js por URL absoluta,
  con un paquete que trae resueltas sus dependencias internas: una sola
  instancia de la librería sin declarar ningún mapa.
- **Todo bajo prefijo propio.** Clases CSS, objetos globales de JavaScript,
  opciones, transitorios y shortcodes llevan prefijo propio, y ninguna regla de
  estilo sale del contenedor de su componente.

El detalle está en **Diagnóstico → Convivencia** y en la cabecera de
`includes/class-uhp-assets.php`.

---

## Datos abiertos

El conjunto completo se expone en la API del plugin para su reutilización:

```
GET /wp-json/urkunina/v1/abierto            → índice de los archivos
GET /wp-json/urkunina/v1/abierto/{clave}    → contenido de un archivo
GET /wp-json/urkunina/v1/vistas             → catálogo de vistas
GET /wp-json/urkunina/v1/render?view=…      → datos listos para graficar
GET /wp-json/urkunina/v1/mapa?indicador=…   → valores por municipio
GET /wp-json/urkunina/v1/geo                → geometría municipal
GET /wp-json/urkunina/v1/kpi                → cifras clave
GET /wp-json/urkunina/v1/tablero            → todo lo que necesita el tablero
```

Todas son públicas y de solo lectura, con límite de peticiones por IP.

### Prevalencia de los 55 municipios

Desde septiembre de 2026, `04_prevalencia_municipal.json` trae la serie completa:
la prevalencia de lesión precursora de malignidad y de infección por *H. pylori*
de **cada uno de los 55 municipios** que intervino el proyecto, y no solo los
extremos de la distribución. Doce de ellos publican además cuántas personas se
examinaron.

```
[urkunina_grafico view="prev_lpm_55"]   → lesión precursora, los 55 municipios
[urkunina_grafico view="prev_hp_55"]    → infección por H. pylori, los 55
```

Las dos vistas abren en el mapa y admiten barras, treemap y caja de bigotes
desde su propia barra de herramientas. El tablero pasó a colorear 55 municipios
con cifra propia en lugar de quince.

Al elegir en un `[urkunina_selector]` una vista que tenga territorio, el gráfico
**abre en el mapa** aunque su tipo por defecto sean las barras. Quien prefiera
otro tipo lo cambia en la barra de herramientas y se queda ahí hasta el
siguiente cambio de vista.

**Los datos son agregados.** No contienen microdatos de los 5.000 participantes,
resultados de laboratorio individuales ni la identidad de los pacientes con
cáncer detectado: esa información no está en los documentos fuente.

---

## Preguntar por voz o por escrito

El tablero puede responder preguntas y mover sus propios filtros mientras lo
hace: «muéstrame la zona roja», «¿cuánto tiene Túquerres?», «cambia a H.
pylori», «¿qué subregión está peor?». Todo lo que el ciudadano ve lo pone el
plugin, con los mismos colores y los mismos controles que el resto del tablero.

**Son dos piezas y solo una es obligatoria.** Un **modelo de lenguaje** —Claude,
ChatGPT o Gemini, a elegir— entiende la pregunta, decide qué herramienta del
tablero usar y redacta la respuesta con los datos que el tablero le devuelve.
Y, si la entidad quiere, **ElevenLabs** lee esa respuesta en alto. Sin la
segunda, el asistente funciona entero por escrito, que es un canal completo y
no una versión capada.

**Ninguna clave llega al navegador.** El visitante pregunta a una ruta de este
mismo sitio y es el servidor quien habla con el proveedor, de modo que el tope
diario de preguntas, el límite por IP y la comprobación de origen se aplican de
verdad y el panel puede contarlos. El navegador no habla con ningún dominio
externo: no hay SDK que descargar de una CDN, ni WebSocket contra el proveedor,
ni dominios de terceros que abrir en la política de seguridad de contenido.

**El dictado por micrófono, en cambio, no lo hace este plugin.** Lo hace el
navegador del visitante, y conviene saber qué significa eso antes de
encenderlo: en Chrome, Edge y Safari el audio se envía a servidores de Google o
de Apple para convertirlo en texto. Ni este sitio ni ElevenLabs intervienen
ahí, y la entidad no controla ese tratamiento ni puede pactarlo, porque es del
navegador que el ciudadano eligió. En Firefox, Opera, Brave y Vivaldi no
funciona en absoluto, y esos visitantes solo tendrán el canal escrito: el
asistente lo dice con esas palabras en vez de dejar un botón que falla. Quien
no quiera ese tratamiento puede dejar el asistente en «solo por escrito», y
entonces no se pide el micrófono nunca.

**Un botón, «Asistente», y dentro tres controles:** un cuadro de texto, el
icono de enviar y el icono del micrófono. Nada más. Antes eran dos lanzadores
—«Preguntar por voz» y «Preguntar por escrito»— y hasta cinco botones dentro
del panel.

**Dictar y hablar son cosas distintas, y se configuran aparte.** El micrófono
es una forma de ENTRAR: lo pone el navegador del visitante, no cuesta nada y no
necesita la clave de ElevenLabs. La voz es una forma de SALIR: la pone
ElevenLabs desde el servidor. Una instalación puede tener micrófono sin voz,
voz sin micrófono, las dos o ninguna, y las cuatro son conversaciones
completas.

Juntarlas en la misma condición era un defecto con consecuencias: una
conversación abierta por el lanzador escrito no sonaba **nunca**, ni con la voz
perfectamente configurada. Como el panel se abría casi siempre por ahí, el
asistente mostraba texto y no conversaba.

**Cada pregunta mueve el tablero.** Las instrucciones que recibe el modelo le
exigen consultar una herramienta antes de responder —no tiene ni una cifra del
proyecto en la memoria— y dejar en pantalla aquello de lo que habla: si
preguntan por un municipio lo selecciona, si preguntan por un indicador lo
cambia, si preguntan por una subregión la filtra. Y lo dice en una frase, porque
quien escucha sin mirar necesita saber que la pantalla se movió.

**Nace apagado.** Encenderlo pide el interruptor y la clave del modelo de
lenguaje, que es quien entiende la pregunta; hasta entonces el tablero sale
byte por byte como si esta función no existiera. El aviso previo al visitante
es un **ajuste**, no un requisito: viene apagado porque con esta arquitectura
no se graba ni se guarda la voz de nadie, y se puede encender si el área
jurídica de la entidad lo pide o si se prefiere avisar de que la pregunta viaja
a un proveedor fuera del país.

**Publicar y ser visible son dos ejes distintos.** La entidad puede dejarlo
configurado y visible solo para quien pueda editar contenido, para ensayarlo en
el sitio de verdad antes de ponerlo delante de un ciudadano; o cargarlo sin
botón, para abrirlo desde un control propio de la plantilla.

El visitante puede terminar la conversación en cualquier momento, y los cuatro
filtros siguen manejándose con el teclado y con el ratón: el asistente no es la
única vía para nada.

El asistente no inventa: cada cifra sale de una herramienta que lee el tablero, y
cuando una cifra es de la subregión y no del municipio, lo dice. Un municipio
que el proyecto no intervino se responde con esas palabras —«allí no hubo
tamizaje»—, no como si faltara el dato. Y un nombre que no cruce exacto nunca
selecciona otro parecido: pregunta, y lo confirma una pulsación.

También lee el **gráfico de barras** y el **perfil de los 5.000
participantes**. En el gráfico nombra Pacífico Sur y Sanquianga: son dos de las
trece subregiones de Nariño, el informe no publica su cifra y por eso no tienen
barra. Enumerar once y llamarlas «las subregiones de Nariño» habría borrado dos
territorios del departamento. Y en el perfil da el denominador: en género son
4.994 de los 5.000 los que tienen el campo, no los 5.000.

Buscando eso apareció otra cosa: la tarjeta «Participantes» mostraba siempre un
prorrateo de 5.000 entre 55, y el informe publica la cifra real en doce
municipios. Con Pupiales seleccionado decía 91 donde el informe publica 80. Un
rótulo honesto no arregla un número equivocado cuando el correcto existe: ahora
se suma lo publicado, se prorratea solo el resto, y el pie dice qué parte es
cada cosa.

**Al actualizar, purgue la caché del sitio.** `UHP_VERSION` es el `?ver=` de
los recursos que encola el plugin: si una capa de caché sirve el JavaScript
viejo, el asistente no es que suene mal, es que no contesta. El historial de
cambios está en [`CHANGELOG.md`](CHANGELOG.md).

Puesta en marcha, definiciones de las herramientas y CSP en
[`docs/voz/puesta-en-marcha.md`](docs/voz/puesta-en-marcha.md). El análisis de
protección de datos, en [`docs/voz/privacidad.md`](docs/voz/privacidad.md). Y
qué significa cada error del panel, con las líneas que el plugin escribe en el
registro y en la consola, en
[`docs/voz/diagnostico.md`](docs/voz/diagnostico.md).

---

## Verificación

```bash
npm install          # instala Playwright
npm run test:datos   # 629 comprobaciones de la capa de datos, sin WordPress
npm test             # lo anterior más 135 pruebas de navegador
```

Las pruebas de navegador abren en Chromium el marcado real que emiten los
shortcodes. Detalle en la sección «Verificación» de [`guia.md`](guia.md).

---

## Documentación

- [`guia.md`](guia.md) — guía técnica: arquitectura, motor de gráficos, cómo
  añadir una vista, módulo de datos y verificación.
- [`docs/auditoria/2026-09-10-auditoria-seguridad.md`](docs/auditoria/2026-09-10-auditoria-seguridad.md)
  — informe de auditoría de seguridad.
- [`docs/datos/2026-09-11-informacion-nueva.md`](docs/datos/2026-09-11-informacion-nueva.md)
  — qué aportó la revisión de septiembre del informe de cierre, qué confirmó lo
  que ya había y qué discrepancias quedaron registradas sin reconciliar.
- `Investigacion/` y `documentacion/` — documentos fuente del proyecto.

---

## Atribución de fuentes

Su cita es obligatoria al reutilizar los datos:

- Informe preliminar y presentación final del proyecto URKUNINA 5000 —
  Fundación CIEDYN y Hospital Universitario Departamental de Nariño.
- Instituto Nacional de Cancerología — cifras nacionales de incidencia y
  mortalidad (Pardo-Ramos y Cendales-Duarte, 2024).
- DANE — marco geoestadístico municipal.
- OpenStreetMap — cartografía base, © colaboradores de OSM, licencia ODbL.

## Licencia

GPL-2.0-or-later, como WordPress.
