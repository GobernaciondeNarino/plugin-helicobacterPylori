# Guía técnica — plugin URKUNINA 5000

> Plugin de WordPress para la visualización del proyecto URKUNINA 5000
> (BPIN 2015000100064).
> **Gobernación de Nariño · Secretaría TIC, Innovación y Gobierno Abierto**
> Stack: D3plus.js · Leaflet + OpenStreetMap · Three.js · sin npm ni compilación
> para lo que llega al navegador.

Este documento explica cómo está construido el plugin y cómo extenderlo. Para
instalarlo y usarlo, vea [`README.md`](README.md).

---

## 1. Convenciones heredadas del ecosistema

El plugin sigue las mismas convenciones que el resto de desarrollos de la
Secretaría TIC, para que quien mantenga uno pueda mantener el otro:

| Convención | Valor |
|---|---|
| Namespace PHP | `GobernacionNarino\Urkunina` |
| Prefijo de clases | `UHP_` (Urkunina · *Helicobacter pylori*) |
| Prefijo de opciones y transitorios | `uhp_` |
| Prefijo de clases CSS | `.uhp`, `.uhp3d`, `.uhpa` (panel) |
| Shortcodes | `urkunina_*` |
| API REST | `/wp-json/urkunina/v1/` |
| Idioma | Todo el código, los comentarios y la interfaz en español |
| Caché | Transitorios; sin tablas propias |

El prefijo `UHP_` se eligió deliberadamente distinto de `MAN_`, que usa el
plugin «Monitor Ambiental y Fenómeno El Niño — Nariño», con el que convive en
el mismo sitio.

---

## 2. Arquitectura

### 2.1 Tres capas

```
ARCHIVOS JSON            SERVIDOR (PHP)                  NAVEGADOR
─────────────            ──────────────                  ─────────
data/*.json      ──┐
data/*.geojson   ──┤  UHP_Datos ─── lee, valida, cachea
                   │      │
                   │      ├── UHP_Municipios ── cruza nombres ↔ DIVIPOLA
                   │      │
                   │      └── UHP_Views ─────── vistas: dimensiones,
                   │             │               medidas y filas
                   │             │
                   │             └── UHP_Analisis ── redacta el análisis
                   │                                  a partir de las cifras
                   │
                   └──  UHP_Rest ── /wp-json/urkunina/v1  ──┐
                                                             │
                        UHP_Shortcodes ── marcado + assets ──┤
                                                             ▼
                                          UHPcore · UHPRenderer (D3plus)
                                          UHPMapa (Leaflet) · módulo 3D (Three)
```

A diferencia de otros plugins de la entidad, este **no sincroniza APIs
externas ni usa cron**: el proyecto está cerrado y sus datos son un conjunto
estable, versionado con el repositorio. Lo que cambia se actualiza desde el
panel (sección 5).

### 2.2 Estructura de carpetas

```
urkunina-5000/
├── urkunina-5000.php               bootstrap y cabeceras del plugin
├── uninstall.php                   limpieza al borrar el plugin
├── includes/
│   ├── class-uhp-plugin.php         orquestador singleton
│   ├── class-uhp-activator.php      activación, opciones por defecto
│   ├── class-uhp-security.php       capacidades, nonces, saneado, escritura
│   ├── class-uhp-assets.php         librerías compartidas sin conflicto
│   ├── class-uhp-estilos.php        identidad visual → variables CSS
│   ├── class-uhp-rest.php           /wp-json/urkunina/v1
│   ├── data/
│   │   ├── class-uhp-datos.php       lectura, validación, respaldo, integridad
│   │   ├── class-uhp-municipios.php  cruce con la geometría del DANE
│   │   ├── class-uhp-subregiones.php cruce y composición de las 13 subregiones
│   │   ├── class-uhp-territorios.php índice territorial y condición de cada cifra
│   │   ├── class-uhp-topojson.php    GeoJSON → TopoJSON para D3plus Geomap
│   │   ├── class-uhp-views.php       registro de vistas del motor de gráficos
│   │   └── textos-graficos.php       descripción y análisis de cada vista
│   ├── analysis/
│   │   └── class-uhp-analisis.php    redacción automática del análisis
│   ├── shortcodes/
│   │   └── class-uhp-shortcodes.php  los dieciséis componentes
│   └── admin/
│       ├── class-uhp-admin.php       menú, siete módulos con pestañas
│       └── class-uhp-admin-datos.php módulo de actualización de los JSON
├── assets/
│   ├── css/   uhp.css · uhp-grafico.css · uhp-mapa.css · uhp-geomapa.css
│   │          uhp-dashboard.css · uhp-3d.css · uhp-admin.css
│   └── js/    uhp-core.js · uhp-renderer.js · uhp-grafico.js · uhp-geomapa.js
│              uhp-mapa.js · uhp-dashboard.js · uhp-3d.js · uhp-admin.js
├── data/                           el conjunto de datos del proyecto
├── tests/                          verificación (sección 8)
└── languages/                      es_CO
```

---

## 3. El conjunto de datos

Veinte archivos en `data/`: dieciocho JSON del proyecto y dos de cartografía.

| Clave | Archivo | Contenido |
|---|---|---|
| `manifiesto` | `00_manifiesto.json` | Procedencia, convenciones, discrepancias detectadas |
| `proyecto` | `01_proyecto.json` | BPIN, aprobación, financiación, ejecución |
| `epidemiologia` | `02_contexto_epidemiologico.json` | Incidencia por zona de riesgo y comparación nacional |
| `cobertura` | `03_municipios_cobertura.json` | Los 55 municipios priorizados |
| `prev_municipal` | `04_prevalencia_municipal.json` | Los diez municipios con mayor prevalencia |
| `prev_subregion` | `05_prevalencia_subregional.json` | Prevalencia en las once subregiones |
| `biobanco` | `06_biobanco_muestras.json` | Muestras por tipo |
| `sociodemografia` | `07_perfil_sociodemografico.json` | Perfil de los participantes |
| `tamizaje` | `08_resultados_tamizaje.json` | Positivos y negativos de los dos indicadores |
| `cancer` | `09_casos_cancer_detectados.json` | Casos detectados y su desenlace |
| `actores` | `10_actores_institucionales.json` | Mapeo de actores |
| `publicaciones` | `11_produccion_cientifica.json` | Producción científica derivada |
| `metas` | `12_metas_mga.json` | Los 23 productos de la ficha MGA |
| `retos` | `13_retos_siguiente_fase.json` | Retos para la siguiente fase |
| `subregiones` | `14_subregiones_municipios.json` | División subregional oficial de la Gobernación: las 13 subregiones y sus municipios |
| `mortalidad` | `15_mortalidad_departamental.json` | Fallecimientos anuales por cáncer de estómago, 2019-2022 (IDSN) |
| `acceso_oncologico` | `16_acceso_servicios_oncologicos.json` | Las seis IPS oncológicas del departamento y la barrera de acceso territorial |
| `zonas` | `17_zonas_riesgo_subregion.json` | A qué zona de riesgo pertenece cada subregión. **Derivación declarada**, no dato publicado: trae su comprobación contra los territorios de referencia |
| `geojson` | `narino_municipios.geojson` | Geometría de los 64 municipios (DANE) |
| `geojson_subregiones` | `dep-sub-mun.geojson` | Tres capas: departamento, 13 subregiones y los 64 municipios con la subregión de cada uno |

### 3.1 Convenciones del conjunto

- **Decimales con punto.** Los documentos fuente usan coma; los JSON, punto.
- **Porcentajes como número**, sin el símbolo: `67.4` es 67,4 %.
- **`null`** cuando el dato no está documentado en las fuentes.
- **Bloque `_meta`** en todos los archivos, con procedencia, unidad y notas.

### 3.2 Discrepancias documentadas

El manifiesto registra ocho discrepancias entre las fuentes que **no se han
resuelto silenciosamente**. Tres merecen mención:

- **Tamaño del biobanco.** Suma 31.190 muestras por tipo, los documentos hablan
  de «más de 25.000», la ficha MGA registra una meta de 45.000 cumplida al 100 %
  y la presentación de cierre se contradice a sí misma entre diapositivas
  (25.000 en dos, 45.000 en otra). Las cuatro cifras están en los datos.
- **Custodio del biobanco.** El informe preliminar dice Fundación CIEDYN; la
  presentación de cierre dice Instituto Nacional de Cancerología y reparte los
  demás roles de otra manera. El cuerpo del propio informe sitúa las muestras
  «en el Instituto Nacional de Cáncer de Colombia», así que custodia física y
  custodia documental podrían no coincidir. Se conserva el reparto del informe
  preliminar, que es quien desarrolla la tabla de actores.
- **Forma de la serie de mortalidad.** El informe habla de «crecimiento
  constante desde 2019 hasta 2022», pero su propia Gráfica 3 registra una caída
  en 2020 (77 fallecimientos) antes del repunte. Se publica la serie, que es el
  dato; el crecimiento total del periodo (+35,64 %) sí es correcto, lo que no se
  sostiene es que sea constante. **El conjunto no atribuye causa alguna a la
  caída porque la fuente no la explica.**

Ninguna está conciliada: hacerlo requiere validación con las entidades.

Los dos archivos de cartografía tienen su propio tope de tamaño
(`UHP_Security::MAX_GEO_BYTES`, 8 MiB) en vez de compartir el de los archivos de
cifras (2 MiB): el de subregiones ocupa 3 MiB solo en vértices, y aflojar el
límite de todos para que quepa uno abriría la puerta a agotar la memoria del
sitio con un JSON enorme.

### 3.3 El cruce con la geometría

El GeoJSON del DANE nombra los municipios en mayúsculas y sin sufijos
(`LOS ANDES`, `COLÓN`), mientras los informes del proyecto los escriben en
formato de lectura y con el nombre popular entre paréntesis
(`Los Andes (Sotomayor)`, `Colón (Génova)`). `UHP_Municipios::normalizar()`
descarta el paréntesis, elimina los diacríticos y pasa a mayúsculas. Con esa
sola regla **los 55 municipios priorizados cruzan sin necesidad de una tabla de
alias**, y hay una prueba que lo verifica en cada ejecución.

Con las subregiones ocurre lo mismo y se resuelve igual, en
`UHP_Subregiones::normalizar()`: los informes escriben «Piedemonte Costero»
donde la cartografía dice «Pie de Monte Costero», y «La Sabana» donde dice
«Sabana». Quitando tildes, signos, espacios y el artículo inicial, **las once
subregiones con dato cruzan con las trece del departamento**; las dos restantes
—Pacífico Sur y Sanquianga— quedan como «sin dato», que es lo que corresponde
porque los informes no las documentan.

Cuatro municipios circulan además con dos nombres, y las fuentes usan
indistintamente uno u otro: la cartografía del DANE escribe el oficial completo
y las tablas de la entidad el de uso corriente —Cuaspud Carlosama / Cuaspud,
San Andrés de Tumaco / Tumaco, Magüí / Magüí Payán, Los Andes / Los Andes
Sotomayor—. Quitar el paréntesis no basta, porque «Los Andes Sotomayor» no lo
lleva, así que `UHP_Municipios::normalizar()` los reduce al mismo nombre con una
tabla corta y cerrada: seis líneas, cada una un municipio concreto verificado
contra su DIVIPOLA.

**La división subregional viene de dos fuentes y cada una aporta lo suyo.** El
archivo oficial de la Gobernación (`14_subregiones_municipios.json`) da los
nombres con que se rotulan las subregiones —«Los Abades», «La Cordillera»,
«Piedemonte Costero»— y la composición declarada; la cartografía aporta la
geometría y los códigos DIVIPOLA, que el archivo oficial no trae. Las dos
coinciden municipio a municipio, y hay una prueba que lo comprueba en cada
ejecución: si algún día dejaran de coincidir, la suite lo dice en vez de que el
tablero mezcle en silencio dos divisiones distintas del departamento.

---

## 4. El motor de gráficos

### 4.1 Contrato de datos

Una **vista** es un conjunto de datos listo para graficar:

```json
{
  "id": "prev_subregion_lpm",
  "name": "Lesión precursora por subregión",
  "category": "ranking",
  "dimensions": ["subregion"],
  "measures": ["prevalencia"],
  "data": [ { "subregion": "Río Mayo", "prevalencia": 44.3 } ]
}
```

El endpoint `/render` la envuelve en el payload que consume el renderer:

```json
{
  "chart":      { "key": "bar", "class": "BarChart", "label": "Barras" },
  "view":       { … },
  "data":       [ … ],
  "compatible": ["bar", "treemap", "box_whisker"]
}
```

- `chart.class` → la clase de D3plus que se instancia (`window.d3plus[class]`).
- `compatible` → los tipos que el visitante puede elegir en la barra del gráfico.

### 4.2 Categorías y tipos compatibles

La categoría de la vista decide qué tipos de gráfico admite. No se trata de
limitar por limitar: un pastel de veintitrés productos no comunica nada.

| Categoría | Tipos disponibles |
|---|---|
| `parte_todo` | dona, pastel, barras, treemap |
| `ranking` | barras, treemap, caja y bigotes |
| `comparativa` | barras, barras apiladas, líneas, treemap |
| `temporal` | líneas, área, barras, área apilada |
| `categorical` | barras, pastel, dona, treemap |

A esa lista se suma **`mapa`** cuando la vista declara `geo`, y solo entonces:
un mapa necesita una geometría que colorear, y ofrecerlo en una vista que no
nombra territorios produciría una plancha vacía. Lo decide
`UHP_Views::compatibles_de( $id )`, que es la que hay que llamar para un
gráfico concreto; `compatibles( $category )` sigue existiendo pero solo conoce
la categoría, no la vista.

De las vistas actuales lo ofrecen ocho: cinco municipales
—`contraste_municipal`, `prev_lpm_municipios`, `prev_hp_municipios`,
`prev_lpm_extremos` y `cancer_municipios`— y tres subregionales
—`prev_subregion`, `prev_subregion_lpm` y `prev_subregion_hp`.

El mapa va **al final** de la lista a propósito: es el tipo más caro de dibujar
—arrastra la topología— y el que menos precisión de lectura da. Como primera
opción solo cuando el shortcode lo pide:

```
[urkunina_grafico view="prev_lpm_municipios" type="mapa" teselas="si"]
```

Lo dibuja el mismo componente de `[urkunina_geomapa]`, montado sobre el lienzo
del gráfico con `UHPGeomapa.montar()`. No hay dos implementaciones de mapa: hay
una, con dos contenedores posibles.

Al salir del mapa hay que **desmontarlo** (`UHPGeomapa.destruir()`). Los dos
motores dibujan DENTRO del lienzo y ninguno lo vacía al soltarlo, de modo que
sin el desmontaje explícito el gráfico nuevo quedaría encima del mapa anterior.
Hay una prueba de navegador dedicada a eso.

### 4.3 Reglas de calidad del renderer

Las que separan un gráfico publicable de uno que confunde:

1. **Color por serie, nunca por punto.** Colorear por índice crea una serie
   falsa por cada punto y rompe la leyenda. `colorPorGrupo()` construye un mapa
   estable grupo → color.
2. **Color por valor en los rankings.** Cuando la vista se marca `heatmap` y
   tiene una sola serie, el color codifica la magnitud y la leyenda de
   categorías se apaga: no aporta nada.
3. **Ejes siempre con título**, mapeando el nombre del campo a una etiqueta
   legible con su unidad.
4. **Tooltip con `title` y `tbody` explícitos.** Sin `tbody`, D3plus lo pinta
   vacío.
5. **`detectVisible` apagado.** D3plus aplaza el dibujo hasta que el contenedor
   entra en el viewport y lo comprueba por sondeo. Verificado en navegador: de
   ocho gráficos en una página solo se dibujaba el primero, y tras recorrerla
   seguían faltando tres. Se apaga y cada gráfico se dibuja cuando llegan sus
   datos, que es además lo que necesitan la impresión y los lectores de
   pantalla.
6. **Respaldo en SVG.** Si D3plus no carga —por ejemplo bajo una política de
   seguridad de contenido estricta— el renderer dibuja un gráfico simple en SVG
   en lugar de dejar un hueco.

### 4.4 Los cuatro textos de cada vista

Cada gráfico se publica acompañado de texto. Hay dos clases y viven en sitios
distintos a propósito:

- **Los que no dependen de las cifras** —qué muestra el gráfico y qué significa
  en términos clínicos o de política pública— se escriben a mano en
  `includes/data/textos-graficos.php`, con al menos 375 caracteres cada uno.
- **Los que sí dependen de las cifras** —máximo, mínimo, promedio, brecha— los
  redacta `UHP_Analisis` a partir de los datos, de modo que se actualizan solos
  cuando cambia un archivo.

### 4.5 El gráfico y sus textos son piezas separadas

`[urkunina_grafico]` **no imprime ni una línea de texto**. La tarjeta contiene
el título, la barra de herramientas y el lienzo; nada más. Cada texto de la
vista es su propio shortcode:

| Shortcode | Qué pinta | De dónde sale |
|---|---|---|
| `[urkunina_titulo]` | Nombre de la vista | `UHP_Views::meta()` |
| `[urkunina_descripcion]` | Qué muestra y cómo leerlo | `textos-graficos.php` |
| `[urkunina_interpretacion]` | Qué significa | `textos-graficos.php` |
| `[urkunina_resumen]` | Hallazgo principal | `UHP_Analisis` |
| `[urkunina_cifras]` | Cifras de apoyo | `UHP_Analisis` |
| `[urkunina_fuente]` | Atribución del dato | `UHP_Views::meta()` |
| `[urkunina_analisis modo="…"]` | Varias de las anteriores | atajo agrupado |

La razón es de maquetación: quien arma la página decide si el texto va al lado
del gráfico, encima, debajo o en otra columna, sin pelearse con el ancho de una
tarjeta que ya traía su prosa dentro. Todos se renderizan en el servidor —el
contenido viaja en el HTML, sin petición ni parpadeo— y por tanto funcionan sin
JavaScript. Su marcado no lleva chrome propio: solo fijan medida de línea y
ritmo vertical, y heredan el flujo de la página.

Dos consecuencias prácticas:

- El gráfico admite `titulo="no"` para suprimir también su cabecera cuando el
  título ya se publicó con `[urkunina_titulo]`.
- **La atribución dejó de ser automática.** Al no imprimirla el gráfico, hay que
  publicar `[urkunina_fuente view="…"]` de forma explícita. Citar la procedencia
  del dato no es opcional en una publicación de la entidad.

El texto accesible del gráfico no se pierde: el lienzo conserva
`role="img"` con un `aria-label` que reúne el nombre de la vista, su
descripción y sus cifras, de modo que un lector de pantalla sigue recibiendo la
lectura completa aunque el texto visible se haya maquetado en otro sitio.

### 4.5.1 Un selector que gobierna varias piezas a la vez

Una pestaña del módulo Gráficos puede publicarse **entera** en una sola pieza de
página, en vez de copiar un shortcode por vista. Lo hace `[urkunina_selector]`:

```
[urkunina_selector grupo="Prevalencia"]
[urkunina_titulo    grupo="Prevalencia" etiqueta="h2"]
[urkunina_grafico   grupo="Prevalencia" alto="420px" titulo="no"]
[urkunina_descripcion grupo="Prevalencia"]
[urkunina_tabla     grupo="Prevalencia"]
[urkunina_fuente    grupo="Prevalencia"]
```

Al elegir un nombre en la lista cambian a la vez el título, la descripción, la
interpretación, el resumen, las cifras, la fuente, la tabla y el gráfico.

**Las piezas no se conocen entre sí.** Cada una declara a qué *canal* pertenece
con `data-canal` y el selector les habla por ese nombre, a través de un evento
`uhp:canal` en `document`. Por eso pueden ir en columnas distintas, en otro
orden, o repartidas por la página, y por eso `uhp-grupo.js` no depende ni de
D3plus ni de la figura del gráfico: una página con solo textos y tablas
funciona igual.

Tres atributos, aceptados por todas las piezas:

| Atributo | Qué hace |
|---|---|
| `grupo` | Nombre de una pestaña: `Epidemiología`, `Tamizaje`, `Prevalencia`, `Población`, `Biobanco`, `Casos detectados`, `Proyecto`. Ignora mayúsculas y tildes. |
| `views` | Lista de vistas separadas por comas, en ese orden. Gana sobre `grupo`. |
| `canal` | Nombre del canal. Por defecto se deriva del grupo; indíquelo para tener dos selectores independientes del mismo grupo en una página. |

Y `view` elige cuál arranca seleccionada, aunque no sea la primera.

**Dos mecanismos distintos, según lo que cuesta cada pieza.** Los textos y las
tablas se imprimen TODOS, un panel por vista, y cambiar de vista es enseñar uno
y esconder los demás con el atributo `hidden`: instantáneo, sin petición, y sin
JavaScript se lee igualmente la vista activa. El gráfico, en cambio, es **uno
solo** y se recarga: imprimir una figura por vista obligaría a cada una a pedir
sus datos al arrancar, y ocho vistas serían ocho peticiones para enseñar una.

Al cambiar de vista, el gráfico **no arrastra el tipo**: «dona» no existe en un
ranking y «mapa» no existe en una vista sin geometría, de modo que se deja que
el servidor elija el tipo por defecto de la vista nueva.

Se usa `hidden` y no una clase porque es el mecanismo que los lectores de
pantalla ya entienden: los paneles ocultos quedan fuera del árbol de
accesibilidad sin depender de que el CSS del plugin haya llegado a cargar. El
cambio se anuncia además en una región `aria-live`, porque no mueve el foco ni
altera el orden de lectura y un lector de pantalla no se enteraría por su
cuenta de que media página acaba de cambiar.

### 4.6 El geomapa: llevar una vista al territorio

`[urkunina_geomapa]` dibuja una vista sobre el mapa del departamento con
**D3plus Geomap**. No sustituye a `[urkunina_mapa]`: aquél es un visor sobre
Leaflet, pensado para navegar y consultar; éste es un gráfico más del módulo,
del mismo motor y con la misma lectura que las demás vistas.

**Dos niveles.** Una vista territorial declara en su entrada del registro a qué
nivel pertenece, qué campo de sus filas nombra el territorio y qué medida se
colorea:

```php
'prev_subregion_lpm' => array(
    // …
    'geo' => array(
        'nivel'  => 'subregion',   // o 'municipio'
        'campo'  => 'subregion',
        'medida' => 'prevalencia',
    ),
),
```

El nivel arrastra consigo la topología (`/topojson?nivel=…`), la clave con que
se cruzan los valores (DIVIPOLA o código de subregión) y hasta cómo se nombran
las cosas en el tooltip. **No es un atributo del shortcode a propósito**: una
vista subregional dibujada sobre municipios no cruzaría con nada.

**La capa base se enciende y se apaga** con `teselas="si|no"`. Sin ella queda
una plancha limpia, que es lo que pide la identidad de la entidad para una ficha
o un impreso; con ella se sitúan mejor los municipios sobre el relieve. Cuando
está encendida, la atribución del proveedor la imprime D3plus a partir de la
propia URL de la capa: es condición de la licencia de OpenStreetMap y de CARTO,
y por eso no se escribe a mano —hacerlo solo produciría la misma línea dos
veces, y con el proveedor equivocado si alguien cambiara de capa.

#### 4.6.1 TopoJSON, y por qué se construye en el servidor

D3plus Geomap **no consume GeoJSON**: llama a `topojson.feature()` sobre la
topología. `UHP_Topojson` construye esa topología a partir de los archivos que
ya trae el plugin, sin dependencias externas.

La topología es «degenerada» —cada anillo es su propio arco, sin fronteras
compartidas—, lo que es TopoJSON válido y ahorra implementar la detección de
arcos comunes. El ahorro de peso viene de la **cuantización**: las coordenadas
pasan a enteros sobre una rejilla de 10⁵ pasos y se codifican por diferencias.
El GeoJSON municipal de 354 KB queda en 71 KB con un error máximo de área del
0,025 %.

Tres decisiones que conviene no deshacer:

- **El sentido de giro es el contrario al del RFC 7946.** D3 recorta los
  polígonos sobre la esfera y decide cuál es el interior por el sentido del
  anillo, con el criterio inverso al del RFC: exterior **horario**, huecos
  antihorario. Un anillo al revés no se ve mal, se ve como *el mundo entero
  menos el municipio*, y basta uno para que el departamento se reduzca a un
  punto porque el encuadre se calcula sobre esa extensión. Es el convenio con
  el que vienen los TopoJSON de world-atlas. Hay dos pruebas que lo vigilan:
  una en la capa de datos y otra en el navegador.
- **La geometría subregional no se toma de la capa `subregion` del archivo**,
  aunque esté ahí: viene de un disuelto sobre cartografía de alta resolución y
  pesa casi un megabyte. Se reconstruye disolviendo la capa municipal del mismo
  archivo, que ya está generalizada, y el resultado son 35 KB con exactamente
  el mismo contorno exterior.
- **El disuelto es por cancelación de aristas.** En una partición limpia del
  plano —y la cartografía municipal del DANE lo es— la frontera entre dos
  municipios vecinos es la misma secuencia de vértices recorrida en sentidos
  opuestos. Contando cada arista dirigida y descartando las que tienen su
  opuesta quedan solo las del contorno; luego se encadenan hasta cerrar cada
  anillo. Es exacto, no aproxima nada y no necesita aritmética de polígonos. A
  cambio depende de que los vértices compartidos sean idénticos: si dejaran de
  serlo, no cancelaría ninguna arista y se verían los municipios sueltos —se
  ve, no se rompe—, y la suite lo detecta.

#### 4.6.2 Sin dato no es cero

Los municipios que la vista no nombra se pintan con el relleno de «sin dato», no
con el extremo bajo de la rampa. La distinción es fácil de perder: el relleno
del gráfico va **dentro** de `shapeConfig.Path`, porque Geomap ya trae el suyo y
la configuración de la forma pisa a la general; un `fill` de primer nivel se
ignora en silencio y el mapa entero sale del color de «sin dato». Y el accesor
recibe la fila de datos cuando el territorio tiene cifra y el *feature* crudo
cuando no la tiene, de modo que hay que distinguir los dos casos o un territorio
sin dato caería en el mínimo de la escala y se leería como una cifra real.

Importa más de lo que parece en este proyecto: los informes solo publican los
diez municipios con mayor prevalencia, y de las trece subregiones documentan
once. Pintar el resto como si valieran el mínimo sería inventar datos.

### 4.7 Añadir una vista

Tres pasos, sin JavaScript nuevo:

1. **Registrarla** en `UHP_Views::registro()`: nombre, descripción, categoría,
   dimensiones, medidas y tipo por defecto.
2. **Construir sus filas** en `UHP_Views::datos()`, leyendo de `UHP_Datos`.
3. **Escribir sus textos** en `includes/data/textos-graficos.php`.

Queda disponible de inmediato como `[urkunina_grafico view="…"]`, en el
catálogo del panel y en la API. La suite de pruebas comprueba automáticamente
que produce filas, que sus filas traen todas las dimensiones y medidas que
declara, que su tipo por defecto es compatible y que sus dos textos llegan a
los 375 caracteres.

### 4.8 El tablero

`[urkunina_dashboard]` reproduce el tablero de resultados que diseñó la
Secretaría TIC. Es la pieza más autónoma del plugin: no comparte motor, ni
tipografía, ni paleta con el resto.

```
[urkunina_dashboard]
[urkunina_dashboard alto="720px" indicador="hp"]
```

| Atributo | Qué hace |
|---|---|
| `titulo` | Encabezado del tablero. Por defecto, el de los ajustes. |
| `lema` | Línea descriptiva bajo el título. |
| `alto` | Altura del contenedor. Por defecto `100vh`. |
| `indicador` | `lpm` o `hp`: con cuál arranca el mapa y las barras. |

#### 4.8.1 Rejilla de tres columnas

Filtros y lista a la izquierda (258 px), mapa al centro (elástico), lectura
del territorio a la derecha (300 px). Por debajo de 1180 px las columnas se
estrechan y por debajo de 860 px se apilan, y entonces el contenedor **deja
de estar atado a la altura de la ventana**: con `100vh` repartidos entre tres
filas no queda sitio ni para el mapa ni para leer nada.

La columna del contenedor se declara `minmax(0, 1fr)` y no se deja implícita.
Una columna `auto` crece hasta el contenido más ancho que no pueda encogerse
—un título largo, una píldora con `nowrap`— y entonces el tablero desborda su
propio relleno. Con `100%` de ancho y relleno propio, `.uhp-db` entra además
en su propia regla de `box-sizing`: sin eso suma los 28 px del relleno al
100 % y empuja la página a desbordarse en horizontal.

#### 4.8.2 El mapa es D3 puro, no Leaflet

Proyección Mercator ajustada al departamento con `fitExtent`, trazado con
`d3.geoPath` y desplazamiento con `d3.zoom` sobre un `<g>`. **No hay teselas
ni mapa base.** El tablero habla de los 64 municipios de Nariño; una capa de
calles no aporta nada a esa lectura y sí añadiría una petición externa por
cada celda.

Los trazos se dividen por la **raíz** del zoom y no por el zoom entero: a
escala 12 un borde de 0,6 px dividido por 12 desaparecería, y sin dividir
engordaría hasta tapar los municipios pequeños.

> **El sentido de giro de los anillos.** D3 decide cuál es el interior de un
> polígono por el sentido de su anillo, con el criterio **inverso** al del
> RFC 7946 de GeoJSON: exterior **horario**. Un anillo al revés no se ve mal,
> se ve como el mundo entero **menos** el municipio, y basta uno para que el
> departamento quede reducido a un punto porque el encuadre se calcula sobre
> esa extensión.
>
> `UHP_Topojson::orientar()` normaliza anillo a anillo. Vive ahí y no dentro
> del constructor de la topología porque la usan **los dos** mapas que dibuja
> D3 —el geomapa por la vía del TopoJSON y el tablero por la del GeoJSON—, y
> duplicar la regla es duplicar la ocasión de que solo uno la aplique. Hay
> una prueba de datos que mide el sentido de los 64 municipios y otra de
> navegador que comprueba que sus extensiones en pantalla son distintas
> entre sí: con los anillos al revés todas medirían lo mismo.

#### 4.8.3 Una sola petición

`GET /tablero` devuelve un `FeatureCollection` con los 64 municipios y, en
`meta`, la prevalencia de cada subregión y la ficha de cada zona. Filtrar por
zona, por subregión o por municipio **no vuelve a pedir nada**: todo ocurre
en el cliente.

Cada municipio lleva lo justo para pintarse, filtrarse y explicarse:

| Campo | Qué es |
|---|---|
| `c` | DIVIPOLA |
| `n` | Nombre, con el que lo escriben los informes |
| `sub` · `zona` | Subregión y zona de riesgo |
| `int` | Si el proyecto lo intervino |
| `lpm` · `hp` | Prevalencias municipales, o `null` |
| `casos` | Casos de cáncer detectados |
| `lat` · `lon` | Centroide, para el círculo del caso |

La geometría sale de `UHP_Topojson::features()`, la misma autoridad que
alimenta al geomapa de D3plus: los dos mapas no pueden divergir porque leen
el mismo origen.

**Los rótulos no son los de la cartografía.** El DANE escribe «Colón», «Los
Andes», «Santacruz»; los informes del proyecto escriben «Colón (Génova)»,
«Los Andes (Sotomayor)», «Santacruz (Guachavés)». En un mapa del departamento
la segunda forma es la útil: «Colón» a secas no distingue nada para quien
vive allí, y hay otro Colón en Putumayo.
`UHP_Municipios::nombre_de_lectura()` resuelve el rótulo desde la lista de
cobertura del proyecto y cae en la cartografía cuando el proyecto no nombra
ese municipio. **El cruce sigue siendo por DIVIPOLA**: esto solo decide qué
texto se enseña.

#### 4.8.4 Tres reglas de honestidad que el dibujo sostiene

- **Un municipio sin cifra propia se pinta con la de su subregión**, pero
  atenuado (`fill-opacity: .72`) y diciéndolo: el tooltip marca «(subregión)»
  y la ficha lo escribe. El informe solo publica los extremos de la
  distribución municipal —15 de 55 en LPM, 10 en H. pylori—; dejar en gris a
  los cuarenta restantes escondería lo que sí se sabe de ellos.
- **Un municipio no intervenido no se colorea en absoluto**: trama
  discontinua y sin relleno. No es que falte el dato, es que el proyecto no
  estuvo allí. Son dos estados distintos y el mapa los distingue.
- **Un municipio sin casos tiene cero casos**, no un dato que falte: el
  tamizaje lo cubrió y no encontró ninguno.

A eso se suma lo que el tablero **no** hace: el perfil de los 5.000
participantes no responde a los filtros. Está publicado para el conjunto, no
municipio a municipio; si se redibujara con la selección parecería responder
a ella. Va en el HTML y el JavaScript no lo toca, y hay una prueba que
comprueba que no cambia al filtrar.

Dos cifras del tablero **son estimaciones y lo dicen en su propio rótulo**:
«Participantes est.» reparte los 5.000 a partes iguales entre los municipios
seleccionados —el proyecto no publica cuántos aportó cada uno— y lleva
«prorrateo sobre 5.000» al pie. El promedio de prevalencia de la selección
lleva al lado la cifra departamental para que se lea contra ella.

#### 4.8.5 La zona de riesgo es una derivación declarada

El tablero colorea y filtra por zona, pero **los documentos fuente no
reparten el departamento entre las tres**: describen cada una («norte y
suroccidente», «Pasto y aledaños», «costa Pacífica») y nombran unos pocos
territorios de referencia.

`17_zonas_riesgo_subregion.json` recoge la asignación **por subregión** —trece
filas verificables en vez de sesenta y cuatro decisiones sueltas— y la marca
en su `_meta` como derivación, no como dato publicado. El archivo trae además
su propia comprobación: los seis territorios que la presentación de cierre
nombra como referencia caen todos en la zona que les corresponde
(Túquerres→La Sabana→roja, Pasto→Centro→amarilla, Tumaco→Pacífico Sur→verde…).
Esa comprobación **se ejecuta en la suite**, no se afirma en un comentario.

#### 4.8.6 Un solo tema

No hay variante clara. El tablero se diseñó sobre fondo `#080d11` y la rampa
del mapa, los estados de los filtros y todos los contrastes están calculados
sobre él: una versión clara no es cambiar cuatro tokens, es rehacer la rampa.
El atributo `tema` que existía antes se retiró.

La hoja mantiene la regla de siempre: **ni un color literal fuera del bloque
de tokens**, y hay una prueba que lo comprueba descartando los comentarios
—un color citado en prosa no es una declaración que se haya colado—.

#### 4.8.7 Aislamiento

El diseño original era una página suelta y estilizaba `header`, `main`,
`.card`, `.kpi`… en selectores de elemento y de clase genérica. Dentro de
WordPress eso alcanzaría al tema y a cualquier otro plugin de la página. Aquí
**cada regla nace de `.uhp-db`** y cada clase lleva el prefijo `uhp-db__`: el
resultado visual es el mismo y el radio de acción es el contenedor. Una
prueba recorre la hoja y falla si aparece un selector que no empiece por
`.uhp-db`.

El reinicio `*{margin:0;padding:0}` del diseño también se acotó al
contenedor, y la hoja **no depende de `uhp.css`**: el tablero define su propia
retícula y su propia tipografía, y heredar los tokens del resto del plugin
solo introduciría colores que luego hay que volver a pisar.

#### 4.8.8 Las hojas van en el `<head>`

Un shortcode encola lo suyo **cuando se renderiza**, y eso ocurre durante
`the_content`, cuando `wp_head` ya imprimió las hojas. WordPress no las
descarta —las saca en el pie—, pero para entonces el navegador ya pintó el
marcado sin estilo.

En la página real del tablero eso eran **223 KB de HTML dibujados en crudo**
antes de que llegara el CSS: la página entera parpadeaba, y con la conexión
lenta el parpadeo duraba lo suficiente para parecer que el tablero estaba
roto. Afectaba a todos los componentes del plugin desde el principio; se notó
con el tablero porque ocupa la pantalla entera.

`UHP_Shortcodes::adelantar_hojas()`, enganchada a `wp_enqueue_scripts` con
prioridad 20 —después de que `UHP_Assets` registre, antes de que `wp_head`
imprima—, mira el contenido de la entrada con `has_shortcode()` y encola las
hojas que vaya a hacer falta. El marcado nace ya vestido.

Solo se adelantan las **hojas**. Los scripts siguen en el pie, que es donde
deben estar: no bloquean el pintado y no producen parpadeo. Y los shortcodes
siguen encolando lo suyo al renderizar, que es lo que cubre lo que esa
función no puede ver —un widget, una plantilla que llame a `do_shortcode()`,
un constructor de páginas que guarde el contenido en otro sitio—. Encolar dos
veces no cuesta nada: WordPress ignora el duplicado.

> **Por qué la suite no lo vio.** `tests/generar-paginas.php` imprimía TODAS
> las hojas en el `<head>`, de modo que la página de prueba nunca reprodujo
> el reparto real. Ahora hace la pasada temprana primero y manda al pie lo
> que se encole después, igual que WordPress, y hay una prueba que comprueba
> que la hoja del tablero está en el `<head>` y antes del contenedor.

#### 4.8.9 Accesibilidad

- Cada filtro es un `<button>` con `aria-pressed`, no un `<div>` con un clic.
- El cambio de selección se anuncia en una región `aria-live`: mueve media
  pantalla sin mover el foco, de modo que un lector de pantalla no se
  enteraría por su cuenta.
- La ficha del territorio es `aria-live="polite"`.
- Las barras apiladas del perfil llevan `role="img"` con su composición en el
  `aria-label`.
- **La cifra de cada barra se ancla al relleno, no al borde del track.** El
  diseño la fijaba al borde y elegía tinta por un umbral; con la barra a
  media asta eso deja el número medio sobre el relleno y medio sobre el
  hueco, y la tinta oscura sobre el hueco da **1,17:1**. Anclada al relleno
  solo hay un fondo debajo: dentro cuando la barra pasa de la mitad (8,4:1 en
  LPM, 4,5:1 en H. pylori) y fuera cuando no (14,6:1). Para las barras largas
  —que son la mayoría— se ve igual que en el diseño.
- Con `prefers-reduced-motion` las transiciones del mapa se apagan y los
  desplazamientos son instantáneos.

**Dos valores de la paleta se ajustaron para cumplir la norma**, conservando
tono y saturación:

| Token | Diseño | Aquí | Por qué |
|---|---|---|---|
| `--uhp-db-ink-3` | `#5f7382` — 3,67:1 | `#728898` — 4,53:1 | Rotula las cabeceras de tarjeta, las etiquetas de las cifras y la subregión de cada municipio: es texto y le toca 4,5:1. El valor se calcula contra la superficie **más clara** sobre la que llega a posarse, la de la rejilla de la ficha; ajustarlo al fondo general dejaría esos rótulos por debajo. |
| `--uhp-db-borde-control` | *(usaba `--uhp-db-linea`)* — 1,28:1 | `#546f80` — 3,41:1 | La línea que separa un control de su fondo es información visual necesaria para identificarlo (§1.4.11, 3:1). El borde decorativo de las tarjetas no tiene esa obligación y sigue con `--uhp-db-linea`. |

Son cambios de un punto de luminosidad, apenas perceptibles al lado del
diseño original, y la prueba de contraste los sostiene: mide los colores que
el navegador computa de verdad, de modo que un retoque futuro que los rompa
falla la suite en vez de llegar a producción.

---

## 5. El módulo de datos

Es el único subsistema que escribe en disco, así que concentra las garantías.
Cada acción pasa por la misma puerta:

1. **Capacidad y nonce.** `UHP_Security::exigir_admin()` exige
   `manage_options` y un nonce válido. Sin excepciones.
2. **Lista blanca.** El archivo se elige por su **clave** en el registro, nunca
   por una ruta que venga del navegador. Un salto de directorio es imposible
   por construcción, no por filtrado.
3. **Validación antes de tocar el disco.** JSON bien formado, codificación
   UTF-8, claves obligatorias del contrato, forma de las listas y, en el
   GeoJSON, que las entidades traigan su código municipal.
4. **Respaldo y escritura atómica.** Se copia el archivo anterior y se escribe
   con `tempnam()` + `rename()`: un fallo a mitad de camino no deja un JSON
   truncado que rompería todos los shortcodes.
5. **Redirección.** Patrón POST-Redirect-GET: recargar la página no repite la
   escritura.

Además:

- **Integridad.** Se guarda el SHA-256 de cada archivo. El panel avisa si uno
  cambió fuera del panel —por FTP o por despliegue— y si su huella dejó de
  coincidir con la que declara el manifiesto.
- **Recalcular el manifiesto** reescribe los tamaños y las huellas reales de
  todo el conjunto, para que el manifiesto siga describiéndolo con exactitud.
- **Formato estable.** Lo guardado se reescribe desde la estructura ya validada
  con indentación de dos espacios y UTF-8 sin escapar, igual que los archivos
  originales: así un `git diff` tras editar en el panel muestra el cambio real
  y no la reindentación completa del archivo.

---

## 6. Convivencia con otros plugins

El problema y su solución están detallados en la cabecera de
`includes/class-uhp-assets.php`. En resumen:

### 6.1 Librerías compartidas

D3, D3plus, Leaflet y Plotly se registran con su identificador de uso común
(`d3`, `d3plus`, `leaflet`, `plotly`) y **solo si nadie las registró antes**.
WordPress garantiza entonces una sola copia en la página. Todas las URL pasan
por el filtro `uhp_url_libreria`, de modo que la entidad puede autoalojarlas
bajo una política de seguridad de contenido estricta sin tocar el código:

```php
add_filter( 'uhp_url_libreria', function ( $url, $clave ) {
    $locales = array(
        'd3plus'      => '/wp-content/librerias/d3plus.full.min.js',
        'three-three' => '/wp-content/librerias/three.module.js',
    );
    return isset( $locales[ $clave ] ) ? $locales[ $clave ] : $url;
}, 10, 2 );
```

### 6.2 Three.js sin mapa de importaciones

Un documento HTML solo admite un `<script type="importmap">`, y el plugin del
Monitor Ambiental ya imprime el suyo apuntando a otra versión de Three.js. Si
este plugin imprimiera un segundo, se ignoraría y la escena no cargaría.

La solución es no declarar ninguno: el módulo importa Three.js y sus
complementos por URL absoluta usando el paquete `+esm` de jsDelivr, que trae
los `import … from 'three'` internos de los complementos ya reescritos a esa
misma URL. El navegador reutiliza una única instancia de la librería. Hay una
prueba de navegador que verifica que el plugin no declara ningún mapa de
importaciones y que en una página con mapa, gráfico y escena 3D solo hay una
etiqueta de cada librería.

### 6.3 Aislamiento del marcado

- Ninguna regla CSS sale del contenedor de su componente. No hay selectores
  sobre etiquetas sueltas ni sobre identificadores globales.
- Las reglas que tocan clases de Leaflet van anidadas bajo un contenedor
  propio, para no alcanzar a los mapas de otro plugin en la misma página.
- El objeto 3D localiza sus elementos por atributos `data-*` dentro de su
  contenedor: varias instancias pueden convivir en una página.
- El teclado se escucha en el contenedor de la escena y no en la ventana, para
  no apropiarse de las flechas ni de la barra espaciadora del resto de la
  página. Hay una prueba que lo verifica.

---

## 7. El objeto 3D

`content/helicobacter-pylori-3d.html` era una página completa. Para embeberlo
como componente hicieron falta tres cambios estructurales, documentados en la
cabecera de `assets/js/uhp-3d.js` y `assets/css/uhp-3d.css`:

1. **Sin mapa de importaciones** (sección 6.2).
2. **Anclado al contenedor, no al viewport.** Las medidas del renderer, la
   cámara y la barra de escala se toman del contenedor mediante
   `ResizeObserver`; las capas pasan de `position:fixed` a `position:absolute` y
   las alturas de `100vh` a `100%` del contenedor.
3. **Convivencia con la página.** El teclado solo actúa con el foco dentro de
   la escena, el bucle de dibujo se detiene cuando la escena sale de la vista y
   el contexto WebGL se libera si su contenedor desaparece del documento —sin
   esto, un tema con navegación por AJAX dejaría contextos huérfanos hasta
   agotar el límite del navegador.

La lógica de la escena, la morfometría y los diez momentos de la línea de
tiempo son los del original, sin cambios.

---

## 8. Verificación

```bash
npm install          # Playwright
npm run test:datos   # capa de datos, sin WordPress
npm test             # lo anterior más las pruebas de navegador
```

### 8.1 Capa de datos — 430 comprobaciones

`tests/test-datos.php` ejecuta las clases del plugin fuera de WordPress, con
sustitutos mínimos de sus funciones (`tests/stubs-wordpress.php`). Comprueba
que los veinte archivos se leen y cumplen su contrato, que la topología que
consume D3plus se construye bien —anillos cerrados, sentido de giro correcto,
error de cuantización por debajo del 0,03 % y subregiones disueltas—, que el saneador de CSS
neutraliza lo peligroso **y conserva intacto lo legítimo**, que los 55
municipios cruzan con la geometría, que las 27 vistas producen filas con la
forma que declaran, que sus textos llegan a los 375 caracteres, y que las
cifras cuadran entre sí: los positivos y negativos suman 5.000, la distribución
municipal de casos suma el total declarado, las muestras por tipo suman el
inventario y las fuentes de financiación suman el presupuesto.

Dos comprobaciones nuevas merecen mención porque protegen de un error
silencioso:

- **El titular de la mortalidad se deriva de su propia serie.** El +35,64 % que
  destaca el informe no se copia: se recalcula desde los extremos declarados y
  se comprueba que esos extremos son los años que trae la serie. Si alguien
  corrige un año y no el titular, la prueba lo delata.
- **`mapa` solo se ofrece donde hay geometría.** Cada vista comprueba que el
  tipo `mapa` figura entre sus compatibles **si y solo si** declara `geo`.
  Ofrecerlo en una vista sin territorio produciría un mapa vacío; no ofrecerlo
  en una que sí lo tiene esconde la mitad de la lectura.

### 8.2 Navegador — 61 pruebas

`tests/navegador.spec.js` abre en Chromium **el marcado real que emiten los
shortcodes**: `tests/generar-paginas.php` lo produce llamando a
`UHP_Shortcodes`, de modo que lo que se prueba no es una copia que pueda quedar
desfasada. Las respuestas de la API salen de `tests/fixtures`, generadas desde
los datos reales.

Se verifica que la escena 3D arranca con contexto WebGL y llena su contenedor,
que sus controles avanzan y pausan, que embebida no se apropia del teclado; que
las ocho vistas de la página de gráficos se dibujan y que el color va por serie
y no por punto; que la tarjeta del gráfico **no emite ningún texto** y que los
textos de la vista, que son shortcodes aparte, llegan en el HTML con el
JavaScript desactivado y se maquetan en su propia columna; que el geomapa pinta
los 64 municipios o las 13 subregiones según el nivel de la vista, que colorea
solo los que traen cifra, que ninguna geometría se invierte —la prueba del
sentido de giro—, que las subregiones llegan disueltas, que la capa base se
enciende y se apaga desde el shortcode con su atribución, y que cada topología
se descarga una sola vez por página; que el mapa pinta los 64 municipios sobre OpenStreetMap con su
leyenda y su atribución, que cambiar de indicador no vuelve a descargar la
geometría y que los polígonos son accesibles con teclado; que el tablero ocupa
el 100 % de ancho y 100vh de alto sin desbordar, que su mapa dibuja los 64
municipios **cada uno con su propia extensión** —la prueba del sentido de
giro: con los anillos al revés todos medirían lo mismo—, que los siete
municipios con caso llevan su círculo, que filtrar por zona o por subregión
recorta la lista, las cifras y el título del mapa, que elegir un municipio
abre su ficha y lo resalta, que el tablero **dice cuándo la cifra es de la
subregión y no del municipio**, que cambiar de indicador repinta leyenda,
barras y cifras, que la cifra de cada barra se lee sobre el fondo que le toca,
que «Limpiar» devuelve el estado inicial, que el zoom mueve el mapa, que el
**perfil de los participantes NO responde a los filtros**, que el cambio se
anuncia a los lectores de pantalla, que en móvil las columnas se apilan sin
desbordar, que **todos los pares tinta/fondo cumplen la norma de contraste**
—medidos sobre los colores ya computados por el navegador, no sobre los
tokens— y que **el tablero no se apodera de la página que lo contiene**;
que las cifras, la ficha y la tabla llegan en el HTML
**con el JavaScript desactivado**; y que mapa, gráfico y escena 3D funcionan
juntos en una misma página con una sola instancia de cada librería.

Del tipo `mapa` se comprueba que una vista territorial arranca en él cuando el
shortcode lo pide, que aparece entre los tipos de la barra solo en esas vistas,
que el shortcode elige qué serie se colorea y que las teselas se piden cuando
se encienden. Y sobre todo, que **cambiar de barras a mapa y volver no deja
restos del dibujo anterior**: los dos motores dibujan dentro del mismo lienzo y
ninguno lo vacía al soltarlo, de modo que sin el desmontaje explícito el
gráfico nuevo quedaría encima del mapa.

Del selector se comprueba que elegir en la lista cambia el título, los textos,
la tabla y el gráfico a la vez; que dos canales en la misma página no se pisan;
que una lista explícita respeta su orden y su vista inicial; que el cambio se
anuncia en una región `aria-live` y que **las dos formas de panel** —el `<div>`
con clase y el `<p>` de la descripción dentro del propio selector— salen de
verdad del árbol de accesibilidad al ocultarse; que un canal de solo selector y
gráfico funciona **con el selector delante**, que es el orden que hace que su
script se imprima primero; que el borde del control llega a 3:1 sobre su fondo,
como exige WCAG 2.1 §1.4.11; y que un selector sin grupo avisa en vez de romper
la página.

El entorno de pruebas espeja Three.js, D3plus y Leaflet en local
(`tests/vendor`, no versionado) para no depender de la red, incluida la ruta
absoluta que los complementos de Three.js importan internamente.

### 8.3 Las páginas de prueba no listan sus recursos

`tests/generar-paginas.php` **no enumera** los CSS ni los JS de cada página:
los resuelve del mismo grafo de dependencias que resolvería WordPress, a partir
de lo que cada shortcode encoló de verdad. Los sustitutos de
`tests/stubs-wordpress.php` implementan `wp_register_*`, `wp_enqueue_*` y la
resolución recursiva de `deps`.

No es un refinamiento. La primera versión listaba los scripts a mano y por eso
no detectó **dos fallos de dependencias que sí ocurrían en producción**:

1. El tablero llamaba a `UHPMapa` sin declarar `uhp-mapa` como dependencia. En
   el sitio real el mapa no se dibujaba, y el mensaje culpaba a Leaflet, que sí
   estaba cargado.
2. `uhp-renderer` usaba `UHPcore` declarando solo `d3plus`, de modo que
   WordPress lo imprimía antes que el núcleo. Las vistas de mapa de calor —las
   que piden la rampa de color— fallaban al dibujarse.

Ambos son el mismo error: usar un módulo sin declararlo. La página de prueba
los ocultaba porque cargaba de más. Ahora carga exactamente lo mismo que
WordPress, y una dependencia declarada que nadie registró detiene la generación
con un error explícito en vez de omitir el recurso en silencio.

De ahí también que los shortcodes encolen solo su handle principal y dejen que
las dependencias declaradas arrastren el resto: enumerar cada pieza a mano en
el shortcode fue justo lo que dejó fuera `uhp-mapa`.

---

## 9. Seguridad

Resumen; el informe completo está en
[`docs/auditoria/2026-09-10-auditoria-seguridad.md`](docs/auditoria/2026-09-10-auditoria-seguridad.md).

- **Superficie de escritura mínima.** El plugin no crea tablas, no almacena
  información personal y solo escribe en su propio directorio de datos, siempre
  a través de `UHP_Security::escribir_atomico()`.
- **Autorización.** Las seis acciones de escritura exigen `manage_options` y
  nonce. Las páginas del panel comprueban la capacidad antes de pintar nada.
- **API pública de solo lectura.** Las once rutas sirven datos agregados ya
  divulgados institucionalmente. No se exige nonce a propósito: con caché de
  página, un nonce caducado devolvería 403 a visitantes legítimos. La
  protección es el límite de peticiones por IP y la ausencia total de
  escritura.
- **Entrada y salida.** Todo atributo de shortcode se sanea y se valida contra
  listas blancas; los valores de color y medida pasan por un saneador de CSS;
  las coordenadas se validan contra el recuadro de Nariño; en el navegador, el
  contenido que llega de la API se inserta como texto y no como marcado.
- **Privacidad.** Datos agregados por municipio, subregión o categoría. Sin
  microdatos, sin resultados individuales, sin identidades.

---

## 10. Qué queda fuera

Decisiones tomadas a conciencia, para que quien continúe no las descubra a
medias:

- **No hay sincronización de APIs externas ni cron.** El proyecto está cerrado.
  Si una fase siguiente incorpora datos vivos, el sitio natural es una clase
  `sync/` junto a `data/`, siguiendo el patrón del Monitor Ambiental.
- **El tablero no persiste el estado en la URL.** Cambiar de indicador o de
  gráfico no cambia el enlace, de modo que no se puede compartir una vista
  concreta del tablero. Los gráficos sueltos sí tienen botón de compartir.
- **La escala del mapa es lineal sobre el rango observado.** Con diez
  municipios publicados por indicador funciona; si en una fase siguiente se
  publican los 55, convendrá revisar si una escala por cuantiles comunica
  mejor.
- **Los respaldos se protegen con `.htaccess`.** En Nginx esa protección no
  aplica, pero los respaldos son copias de datos que la API ya sirve
  públicamente, de modo que no exponen nada nuevo.
- **El tablero no ofrece variante clara.** Su identidad es la del objeto 3D, y
  eso implica fondo oscuro. La capa base del mapa sí se puede cambiar, pero el
  cromo del tablero no: hacerlo bien exigiría una segunda paleta completa con
  sus contrastes verificados, y hoy nadie la ha pedido.
- **Plotly.js está registrado pero ningún componente lo usa todavía.** Se dejó
  listo porque el enunciado lo contempla; el motor actual cubre con D3plus todo
  lo que el conjunto de datos necesita. Si se añade un componente que lo
  requiera —superficies 3D o gráficos estadísticos— basta con
  `UHP_Assets::encolar_libreria( 'plotly' )`.
