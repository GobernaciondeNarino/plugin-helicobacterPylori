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

**Los datos son agregados.** No contienen microdatos de los 5.000 participantes,
resultados de laboratorio individuales ni la identidad de los pacientes con
cáncer detectado: esa información no está en los documentos fuente.

---

## Verificación

```bash
npm install          # instala Playwright
npm run test:datos   # 482 comprobaciones de la capa de datos, sin WordPress
npm test             # lo anterior más 65 pruebas de navegador
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
