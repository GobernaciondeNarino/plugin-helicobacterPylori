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

Veintiún archivos en `data/`: diecinueve JSON del proyecto y dos de cartografía.

| Clave | Archivo | Contenido |
|---|---|---|
| `manifiesto` | `00_manifiesto.json` | Procedencia, convenciones, discrepancias detectadas |
| `proyecto` | `01_proyecto.json` | BPIN, aprobación, financiación, ejecución |
| `epidemiologia` | `02_contexto_epidemiologico.json` | Incidencia por zona de riesgo y comparación nacional |
| `cobertura` | `03_municipios_cobertura.json` | Los 55 municipios priorizados |
| `prev_municipal` | `04_prevalencia_municipal.json` | La serie completa de los 55 municipios intervenidos, con los extremos que publicó el informe preliminar |
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
| `criterios` | `18_criterios_participacion.json` | Los cuatro criterios de inclusión y los seis de exclusión de los participantes |
| `geojson` | `narino_municipios.geojson` | Geometría de los 64 municipios (DANE) |
| `geojson_subregiones` | `dep-sub-mun.geojson` | Tres capas: departamento, 13 subregiones y los 64 municipios con la subregión de cada uno |

### 3.1 Convenciones del conjunto

- **Decimales con punto.** Los documentos fuente usan coma; los JSON, punto.
- **Porcentajes como número**, sin el símbolo: `67.4` es 67,4 %.
- **`null`** cuando el dato no está documentado en las fuentes.
- **Bloque `_meta`** en todos los archivos, con procedencia, unidad y notas.

### 3.2 Discrepancias documentadas

El manifiesto registra trece discrepancias entre las fuentes que **no se han
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

Las cinco últimas llegaron con la socialización ante el IDSN y casi todas son de
la misma clase: **cortes distintos del mismo recuento**. La versión de
septiembre de 2023 del informe general daba 35,2 % de LPM y 66,9 % de H. pylori;
el cierre da 35,1 % y 67,4 %. El corte de noviembre de 2022 contaba 1.736
hombres y 3.108 mujeres; el cierre, 1.790 y 3.204. En los dos casos se conserva
la cifra de cierre y se anota la anterior, porque saber que una cifra se movió
—y cuánto— es parte de lo que un portal de datos abiertos debe poder responder.

La quinta es de otro tipo y conviene tenerla presente: el informe técnico
titula una figura «Características sociodemográficas de los voluntarios de
Ipiales», pero el infográfico que trae es el del conjunto —suma 4.844 personas,
y ese mismo informe declara 250 participantes en Ipiales—. El mismo gráfico
aparece en el informe general rotulado «Principales características de los
participantes». **No se creó perfil municipal alguno a partir de esa lámina**:
se leyó como perfil del conjunto y la contradicción quedó anotada.

Los dos archivos de cartografía tienen su propio tope de tamaño
(`UHP_Security::MAX_GEO_BYTES`, 8 MiB) en vez de compartir el de los archivos de
cifras (2 MiB): el de subregiones ocupa 3 MiB solo en vértices, y aflojar el
límite de todos para que quepa uno abriría la puerta a agotar la memoria del
sitio con un JSON enorme.

### 3.2.1 La serie completa de los 55 municipios

Hasta septiembre de 2026 el conjunto solo conocía los **extremos** de la
distribución municipal: los diez municipios con más lesión precursora, los diez
con más infección y los cinco con menos lesión. Quince cifras de cincuenta y
cinco. El mapa del tablero dejaba al resto con la cifra de su subregión,
atenuada y diciéndolo.

La socialización ante el IDSN cerró ese hueco. Entre sus láminas hay capturas de
pantalla del libro de cálculo del proyecto —las hojas «LPM 55» y «Hp 55»— con
los dos indicadores de los 55 municipios. Están en
`04_prevalencia_municipal.json`, bajo `serie_completa`.

Lo que cambia:

- El tablero colorea **55 municipios con cifra propia** en vez de quince. Los
  nueve que no entraron en el estudio siguen sin colorear, con trama
  discontinua: ahí no falta un dato, faltó el tamizaje.
- Dos vistas nuevas, `prev_lpm_55` y `prev_hp_55`, abren directamente en el
  mapa. Los listados de extremos se conservan: son lo que publicó el informe
  preliminar y **cada uno de sus quince valores coincide con el de la serie**,
  que es la prueba de que las dos fuentes hablan de lo mismo. Una comprobación
  cruza los veinticinco valores en cada ejecución.
- Doce municipios traen además **numerador y denominador** (entre 70 y 90
  personas examinadas). Es lo único que se sabe del tamaño de cada muestra
  municipal, y otra comprobación verifica que el porcentaje publicado sale de
  esos dos números.

Dos advertencias que el archivo lleva escritas. La primera: la numeración de la
hoja de origen no es un orden estricto —en «Hp 55», Policarpa (72,2) va antes
que Túquerres (72,0)—, así que aquí se reordena por prevalencia en lugar de
copiar el desliz. La segunda: el denominador es el número de participantes de
cada municipio, no su censo, de modo que estas cifras describen a la población
tamizada y no se extrapolan sin cautela.

### 3.2.2 Lo demás que trajo la socialización

Junto a la serie municipal llegaron seis cosas más, todas repartidas en los
archivos que ya les correspondían:

- **Criterios de participación** (`18_criterios_participacion.json`, nuevo).
  Cuatro de inclusión y seis de exclusión. Dicen de quién habla cada cifra del
  conjunto y, sobre todo, de quién no: sin ellos, la prevalencia se extiende
  sin querer a población que el estudio nunca examinó.
- **Objetivos y cronología** (`01_proyecto.json`). El objetivo general, los
  cuatro específicos y los siete hitos de 2014 a 2022. El reparto de la
  financiación en porcentaje —85,09 % del SGR, 14,91 % de cofinanciación— no se
  copió de la lámina: se calcula de los valores que el archivo ya tenía, y una
  comprobación verifica que sigue saliendo de ellos.
- **Cascada de Correa** (`02_contexto_epidemiologico.json`). Las cinco etapas de
  la secuencia que describió el patólogo nariñense, de la gastritis crónica no
  atrófica al cáncer. Es lo que explica por qué el tamizaje tiene sentido: las
  etapas intermedias son detectables y tratables.
- **Diagnóstico de cada uno de los ocho casos** (`09_casos_cancer_detectados.json`).
  Cinco cánceres gástricos, dos adenocarcinomas de tipo difuso y uno subcardial.
  Sin ningún dato de identificación, como los publicó la entidad; una
  comprobación se asegura de que no se cuele ninguno, y otra de que los ocho
  casos reproducen la distribución municipal y los desenlaces que el archivo ya
  declaraba.
- **Las dos cifras que faltaban del estado nutricional**
  (`07_perfil_sociodemografico.json`): delgadez 18 personas y peso normal 1.429.
  Estaban en `null` desde el principio. Cuadran con los porcentajes que ya había
  sobre una base de 4.830.
- **Un corte preliminar de noviembre de 2022**, en el mismo archivo pero en su
  propio bloque `corte_preliminar_2022` y con su advertencia. Trae procedencia
  urbana y rural, dos rangos de edad, ocupación, sector económico y antecedentes
  familiares: variables que ninguna otra fuente desagrega. **Su base son 4.844
  participantes, no los 5.000 del cierre**, así que va aparte y no se suma ni se
  compara con el resto. Una comprobación vigila justamente eso: que ese bloque
  no haya contaminado las distribuciones de cierre.

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

**Con una excepción: la vista con territorio abre en el mapa.** Al llegar desde
el selector, cualquier vista que declare `geo` se dibuja como mapa aunque su
tipo por defecto sean las barras. Una vista municipal dice mucho más repartida
sobre Nariño que en una lista de cincuenta y cinco barras, y el resto de tipos
sigue a un clic en la barra de herramientas. La preferencia se **gasta al
aplicarla** (`preferenciaDeTipo()` en `uhp-grafico.js`): quien cambia a barras
se queda en barras hasta que vuelva a cambiar de vista, en vez de verse
devuelto al mapa en el siguiente redibujo. Una vista sin territorio no se ve
afectada: vuelve a su propio tipo, no a un mapa vacío.

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

### 4.6.1 Un mapa sin valores lo dice

Una vista puede llegar sin un solo valor. No es hipotético: ocurrió en el sitio
real cuando un despliegue copió `includes/` y se saltó `data/`. La vista existía
—el código la declaraba— pero el archivo de datos instalado no tenía la serie
que necesitaba, así que el mapa se dibujaba entero en gris con una leyenda
rotulada **«0,0 %» a los dos lados**. Eso no se lee como «los datos de este
sitio están atrasados» sino como «en ningún municipio hay dato publicado», que
es una afirmación que nadie ha medido.

Ahora, cuando no hay ni una fila, el mapa se dibuja igual —el territorio sigue
diciendo algo— pero la leyenda sustituye la rampa por «Esta vista no trae ningún
valor publicado», y el componente deja un aviso en la consola con el nombre de
la vista, para quien administre el sitio.

El diagnóstico del panel lo señala antes de que nadie abra la página:
**Diagnóstico → Entorno → Vistas con datos** recorre el catálogo y lista las
vistas que no producen ninguna fila. Con los archivos al día, las 29 producen
datos.

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

### 4.9 El asistente del tablero

El tablero puede atender preguntas y mover sus propios filtros al responderlas.

#### Tres piezas, y cada una donde corresponde

| Pieza | Quién la hace | Dónde |
|---|---|---|
| Entender la pregunta y decidir qué herramienta usar | Un **modelo de lenguaje**: Claude, ChatGPT o Gemini, a elegir | Lo llama **el servidor** |
| Leer la respuesta en alto | **ElevenLabs**, su API de síntesis | Lo llama **el servidor** |
| Convertir el dictado en texto | **El navegador del visitante** | Fuera de nuestro alcance |

`UHP_LLM` traduce el formato neutro de una conversación a las tres APIs y de
vuelta. Sin SDK ni Composer, solo `wp_remote_post()`: hay otros plugins en el
sitio donde esto se instala y un framework compartido es un conflicto esperando
a ocurrir.

Las tres APIs se parecen lo justo para que confundirlas salga caro. Claude
quiere `tools[].input_schema` y la clave en `x-api-key`; OpenAI quiere las
herramientas **planas** —`{type, name, parameters}`, sin el nivel `function`
que tenía la API de *chat completions*— y la respuesta en `output[]`; Gemini
quiere `parametersJsonSchema` y **no** `parameters`, porque el segundo es un
subconjunto restringido que rechaza la mitad de nuestros esquemas, y devuelve
una `thoughtSignature` que hay que devolverle tal cual o pierde el hilo. Los
tres exigen que el esquema vaya **cerrado**, y las dos herramientas sin
argumentos declaran sus propiedades como objeto y no como array de PHP: 
`json_encode()` escribe `[]` donde los tres esperan `{}`, y la petición entera
se rechaza con un 400 que no nombra la herramienta culpable. Dos comprobaciones
de la suite lo sostienen.

#### Ninguna clave en el navegador, y lo que eso compra

El visitante pregunta a `/wp-json/urkunina/v1/voz/chat` y el servidor hace el
resto. Eso no es higiene abstracta: es lo que hace **ciertas** tres cosas que
en la arquitectura anterior solo lo parecían. El tope diario, el límite por IP
y la comprobación de origen se aplican de verdad, porque la conversación pasa
por aquí; antes el navegador abría la sesión contra el proveedor y este
servidor no podía contar nada.

`config_front()` se arma por **lista blanca**, clave por clave, nunca por
resta: así el ajuste que alguien añada mañana no se publica por descuido.
Tampoco viaja qué proveedor hay detrás —no le hace falta al navegador y es una
pista gratis para quien quiera molestar—, ni el identificador de la voz: lo que
viaja es **si** hay voz, que es lo único que cambia lo que el panel pinta.

De la CSP sobrevive una línea, y solo con la voz encendida:
`media-src 'self' blob:`. El audio llega en base64 dentro de la respuesta, se
reconstruye en memoria y se reproduce desde una URL `blob:`, que
`default-src 'self'` **no** cubre. Sin esa línea el panel funciona entero
—texto, mapa, gráficas— y simplemente no suena: un fallo mudo que no se nota
hasta que alguien pregunta por qué. `media-src` no permite ejecutar nada, y es
una directiva mucho más estrecha que las que pedía la arquitectura anterior:
`blob:` en `script-src` —justo lo que una entidad pública no quiere abrir—, el
dominio de una CDN, un WebSocket contra el proveedor y cuatro dominios de
LiveKit con comodín.

#### Dos canales, y la diferencia no es la piel

Se puede entrar **hablando** o **escribiendo**, y el segundo no es el primero
con el micrófono apagado:

- En el canal escrito **no se manda nada a sintetizar**. ElevenLabs cobra por
  carácter leído, y un audio que nadie va a oír se factura igual que uno que
  sí. Una prueba comprueba que la ruta `/voz/hablar` no se toca ni una vez.
- El **prompt es otro**. Una lista de once subregiones no se retiene de oído:
  por voz van los extremos y un par más, y quien quiera la lista entera la
  pide. Por escrito van las once, porque se pueden releer. El navegador manda
  el canal en cada pregunta y el servidor elige el prompt.
- Tampoco se pide el micrófono, ni una vez. Esa es la diferencia entre «no hay
  audio» y «no se pide permiso para el audio», y es lo que convierte el canal
  escrito en la alternativa equivalente que pide la Resolución 1519 de 2020 en
  lugar de un plan B escondido.
- Si la voz no está configurada, el canal escrito aparece **aunque** el ajuste
  diga «solo voz». Quedarse sin ninguno de los dos no es una opción, y un
  lanzador de micrófono en una página donde el servidor no puede sintetizar
  nada promete algo que no va a ocurrir.

#### El dictado lo hace el navegador, y hay que decirlo

El reconocimiento de voz no lo pone este plugin: lo pone la API
`SpeechRecognition` del navegador del visitante. Dos consecuencias, y ninguna
es un detalle de implementación:

- En Chrome, Edge y Safari **el audio se envía a servidores de Google o de
  Apple**. Ni este sitio ni ElevenLabs intervienen, y la entidad no controla
  ese tratamiento ni puede pactarlo: es del navegador que el ciudadano eligió.
  Está escrito en `docs/voz/privacidad.md` y en el propio panel de
  administración, en vez de dejarlo implícito.
- En Firefox, Opera, Brave y Vivaldi **no funciona**. Y en algunos de ellos el
  objeto existe, así que la detección de característica no basta: falla
  después, en tiempo de ejecución, con `network` o `service-not-allowed`,
  porque el motor es un servicio de Google que esos navegadores no tienen. El
  primer fallo de ese tipo apaga el micrófono para toda la visita —reintentar
  es pedirle al visitante que pulse un botón roto— y deja el canal escrito
  entero.

Es **pulsar para hablar**, no escucha continua: un micrófono siempre abierto en
la página de una entidad pública es algo que habría que justificar, y aquí no
hace falta. Y antes de abrirlo se para el audio que esté sonando: si no, la
propia voz del asistente entra por el micrófono, se transcribe como si la
hubiera dicho el ciudadano, y el asistente se pone a conversar consigo mismo a
costa de la entidad.

Cuando el micrófono falla, el campo de escritura **sigue delante**. Un cartel
que dice «puede escribir su pregunta» con nada que pulsar es exactamente lo que
la Resolución 1519 llama no tener alternativa.

#### El aviso previo es un ajuste, no una puerta

Viene apagado. La razón no es comodidad: con esta arquitectura **no se captura,
ni se transmite, ni se almacena ninguna muestra de voz**: el navegador convierte
el dictado en texto y lo que sale de aquí es texto. Pedir una autorización donde
no hay un tratamiento que autorizar enseña a aceptar avisos sin leerlos, y el
día que haya uno que sí importe nadie lo leerá tampoco.

Se puede encender, y entonces pide los datos que la ley manda informar y los
imprime en su aviso. Lo que **no** se puede apagar es lo que el asistente le
dice siempre al visitante: que no dicte ni escriba datos personales.

#### El estado de la pantalla viaja con la pregunta

Antes eran ocho variables interpoladas en el prompt del agente, y si faltaba una
sola el proveedor rechazaba la sesión entera. Ahora el prompt es estático y el
estado va como un turno más de la conversación: si falta, falta un dato y no la
conversación.

Va **solo cuando cambió**. Repetirlo intacto engorda un historial que viaja
entero en cada vuelta de herramientas, y en una pregunta normal hay tres o
cuatro. Y va en cada pregunta, no solo en la primera, porque el visitante puede
mover el tablero a mano mientras conversa: si el modelo no se entera, sigue
hablando de una pantalla que ya no existe y le lee a alguien la cifra de otro
municipio.

#### Publicar y ser visible son dos ejes

Con un solo interruptor la entidad no puede ensayar el asistente en el sitio de
verdad sin ponerlo delante de un ciudadano. Así que hay dos: `activo` y
`visibilidad` (`todos` / `editores` / `oculto`). Con `editores`, el visitante
anónimo recibe el tablero sin asistente, sin su script y sin su hoja de estilos
—la página sale como si no estuviera configurado— y el shortcode define
`DONOTCACHEPAGE`, porque es la única salida del plugin que depende de quién pide
la página. Con `oculto` el panel se carga y el botón no se pinta, para abrirlo
desde un control propio de la plantilla; y entonces tampoco se pinta el rótulo
corto, que si no dejaría la página anunciando un envío al exterior junto a un
hueco sin nada que pulsar.

Los atributos del shortcode (`voz`, `voz_visible`, `voz_entrada`) solo pueden
**estrechar** lo que permite el sitio. La asimetría es deliberada: el contenido
de un post lo edita cualquiera con permiso de edición, y por dónde sale la voz
de un ciudadano no es una decisión de redacción. La clave de API, por lo mismo,
no es un atributo y no va a serlo.

#### Una sola puerta hacia el tablero

Las reglas de coherencia entre los tres filtros vivían dentro de los
manejadores de clic de `uhp-dashboard.js`, repetidas. Ahora están en cinco
funciones con nombre —`aplicarIndicador`, `aplicarZona`, `aplicarSubregion`,
`aplicarMunicipio`, `aplicarLimpiar`— que invocan por igual el ratón y el
asistente. **Hablar y pulsar no pueden divergir porque recorren el mismo
código.** Sobre ellas se levanta `window.UHPTablero.mando(raiz)`, la fachada que
es el único canal del asistente, y que no expone ni una operación que el
teclado no alcance ya: una capacidad que solo existiera detrás del micrófono
sería justo lo que la Resolución 1519 prohíbe.

`aplicarMunicipio` recibe `alternar`: el clic suelta el municipio al pulsarlo
dos veces, la voz no. «Muéstrame Túquerres» dicho dos veces no significa
«quítalo».

Cada cambio pasa por `notificar(st, origen)`, que anuncia **una línea breve**:
qué cambió y cuántos municipios quedan a la vista. Con `origen === 'agente'`
antepone «El asistente…», porque quien no ve la pantalla necesita distinguir lo
que hizo él de lo que le hicieron.

La ficha del municipio llevaba `aria-live` y se quitó: es un bloque de
**contenido**, no un estado, y con la región viva un lector de pantalla leía sus
veinte líneas enteras con cada clic. Molesto con un ratón; con el asistente
moviendo filtros, un secuestro continuo. La línea breve da la misma información
útil sin el ruido.

#### Las diez herramientas

Cinco mueven el tablero y cinco solo leen. La diferencia no es cosmética: las
que leen devuelven un dato que el modelo necesita para seguir, y la respuesta
se detiene hasta tenerlo; las que mueven solo cambian la pantalla. El servidor
manda las diez al modelo en cada pregunta: no hay que declararlas en ninguna
consola, y por tanto no pueden quedarse desfasadas respecto del código.

Las cinco de lectura existen para que el modelo no invente:
`uhp_leer_estado_tablero` son sus ojos, `uhp_listar_municipios` le impide
enumerar municipios de memoria, `uhp_consultar_municipio` responde un dato
**sin** mover la pantalla —que es lo que nadie espera de una pregunta suelta— y
`uhp_comparar_subregiones` le lee el **gráfico de barras** y
`uhp_consultar_perfil`, el **perfil de los 5.000 participantes**.

#### Leer una gráfica sin mentir

`uhp_comparar_subregiones` fue la herramienta más difícil de escribir, y no por
el código. Las barras llevan cifras **subregionales publicadas**: no son el
promedio de los municipios que el proyecto intervino, porque la subregión
incluye municipios donde no estuvo. Y el «promedio» que da
`uhp_leer_estado_tablero` es una tercera cosa: el promedio de la selección
actual. Tres números que un asistente apurado presentaría como uno.

Peor: el gráfico solo dibuja las subregiones **con** cifra. Nariño tiene trece y
el informe publica once. Un asistente que leyera nada más lo que se ve diría
«las subregiones de Nariño» habiendo borrado dos territorios de la costa del
departamento —Pacífico Sur y Sanquianga—, que no están vacíos: no tienen cifra
publicada, y no es lo mismo.

Para poder nombrarlas hubo que llevarlas hasta el navegador, porque el servidor
solo enviaba las once. `UHP_Rest::subregiones_sin_cifra()` las calcula
contrastando la división declarada por la entidad con la prevalencia publicada,
y viajan en `meta.sin_cifra` **aparte** de `meta.subregional`: no entran en
`st.subs` porque no se pueden filtrar —no hay municipio intervenido que
filtrar— y no se dibujan porque no hay barra que dibujar. Viajan solo para poder
decir su nombre. De paso, el mensaje de «no encuentro esa subregión» dejó de
llamar «las documentadas» a once de trece.

La herramienta distingue, por eso, **dos ausencias**: una subregión filtrable
sin cifra de este indicador, y una subregión del departamento que el informe no
publica. Confundirlas sería el error que todo esto quería evitar.

Y dos cosas más que la primera versión no decía. El **puesto se calcula por
valor**, no por índice: Occidente y La Cordillera están las dos en 37,7 % de
lesión precursora, y numerarlas «3.» y «4.» inventaba una diferencia que el dato
no tiene. Y cada línea lleva **cuántos municipios de esa subregión intervino el
proyecto**: Telembí encabeza el gráfico de *H. pylori* con 82,0 %, y allí el
proyecto intervino uno de sus tres municipios. Sin ese denominador, «Telembí,
82 %» invita a entender que el proyecto midió la subregión.

El formato cambia con el canal. Por voz van los extremos y un par más, sin
ordinales: una enumeración hablada de once elementos no se retiene. Por escrito
van las once, y **el rótulo viaja pegado a cada cifra** —«37,7 % (cifra de la
subregión)»— porque lo que el visitante copia de una conversación escrita sale
sin el resto del párrafo, y una cifra suelta sin su territorio se convierte en un
dato falso en cuanto alguien la pega en otra parte.

#### El perfil de los 5.000, y dos categorías que faltaban

`uhp_consultar_perfil` lee el bloque del perfil: género, etnia, educación,
régimen de salud, estado nutricional y el rango de edades. Hacía falta porque
esas once cifras estaban escritas a mano en el PHP del shortcode, fuera del
estado, y el asistente no podía leerlas: a «¿cuántas mujeres participaron?» solo
podía contestar que no lo sabía teniendo el dato en pantalla.

Llevarlas al estado descubrió un fallo que estaba a la vista y nadie había
mirado: el bloque **se dejaba fuera dos categorías publicadas** —«Otro o no
reportado» en etnia, 0,3 %, y «Delgadez» en estado nutricional, 0,4 %, dieciocho
personas—. Tres barras que sumaban 99,6 y nada que dijera que faltaba una cuarta.
Dieciocho personas no son un redondeo. Ahora se pintan.

La herramienta lleva además el **denominador**: en género son 4.994 de los 5.000
los que tienen el campo, y en estado nutricional 4.830. Repartir los porcentajes
sobre 5.000 convertiría unas ausencias en personas. Y lleva el **ámbito**:
«departamental», porque el perfil está publicado para el conjunto del estudio y
con el tablero filtrado por una subregión esas cifras no cambian.

#### Participantes: 80 publicados contra 91 prorrateados

El mismo hilo llevó a una tarjeta que llevaba meses diciendo un número
equivocado. «Participantes est.» mostraba siempre un prorrateo de 5.000 entre
55, con su rótulo honesto —«est.», «prorrateo sobre 5.000»—. Pero el informe
publica cuántas personas examinó en **doce** de los cincuenta y cinco
municipios, y esas cifras no viajaban al navegador. Con Pupiales seleccionado,
la tarjeta decía **91** donde el informe publica **80**.

Un rótulo honesto no arregla un número equivocado cuando el correcto existe y
está en el repositorio. Ahora `part` y `pos` viajan en las propiedades de cada
municipio —`null` donde no se publican, que no es lo mismo que cero— y la
tarjeta tiene tres estados: la suma de lo publicado cuando todos los
seleccionados lo tienen, el prorrateo de siempre cuando ninguno lo tiene, y la
suma más el prorrateo del resto cuando hay mezcla, con el pie nombrando las dos
partes. `uhp_consultar_municipio` lo dice también, y dice de dónde sale.

Donde no se publica, el asistente **no dice nada**: dar el prorrateo del
conjunto como la cifra de un municipio sería inventarla.

#### Nunca se acierta un municipio por parecido

Entre los 55 municipios intervenidos hay exactamente tres pares a dos cambios
de distancia: **Pupiales e Ipiales**, **Albán y Aldana**, **Iles e Imués**. El
reconocimiento de voz entregará tarde o temprano uno por otro, y equivocarse
significa leer en voz alta la cifra de otro pueblo: Pupiales tiene 21,3 % de
lesión precursora e Ipiales 37,2 %.

`resolver()` solo cruza por coincidencia **exacta** del nombre normalizado, de
su alias entre paréntesis —«Génova» por «Colón (Génova)»— o del código DANE. Lo
que no cruza se devuelve como sugerencia y **la confirma una pulsación en la
pantalla**, no una frase del modelo: si la confirmación dependiera de que el
modelo obedezca, se autoconfirmaría en el turno siguiente, porque el nombre que
él mismo sugirió ya sería una coincidencia exacta. Una prueba lo comprueba.

Cuando el nombre sí cruza pero tiene un vecino a dos cambios, la respuesta lo
avisa para que el asistente pregunte antes de leer cifra alguna.

#### Lo que no se deja al modelo

- **Nada de lo que devuelve un proveedor entra como HTML.** Todo se pinta con
  `textContent`. Una prueba le hace devolver `<img onerror=…>` y verifica que se
  lee como texto. Y lo mismo vale para el panel de administración: el nombre de
  una voz lo escribe quien la creó en la cuenta de ElevenLabs, y se sanea antes
  de imprimirlo. Que la cuenta sea de la entidad no hace confiable el texto.
- **Presupuesto de cambios**: un cambio cada 700 ms y veinte por minuto. Las
  cinco herramientas mutadoras no esperan respuesta, de modo que un modelo
  atascado puede encadenar decenas; cada una repinta la lista de 55 municipios y
  reenfoca el mapa.
- **Tope de herramientas por pregunta.** Cada vuelta cuesta: el historial
  entero viaja otra vez. Un modelo que se enreda gastaría la conversación dando
  vueltas mientras el visitante ve el tablero moverse sin recibir respuesta. El
  tope lo pone el plugin y se para de verdad, en vez de pedirle al modelo que
  se pare.
- **El panel se construye una vez y se actualiza.** Repintarlo entero con cada
  frase destruía el campo donde el visitante estaba escribiendo y le dejaba el
  foco en el `<body>`, con lo que la tecla Escape dejaba de llegar. Y eso pasa
  dos veces por pregunta: lo que el modelo dice **antes** de pedir una
  herramienta también se pinta, porque callarlo deja la pantalla muda mientras
  el tablero se mueve solo. Lo que no se hace es leerlo en alto: se pagaría un
  audio de relleno en cada vuelta.
- **Cerrar mientras el modelo piensa descarta la respuesta.** Un testigo de
  generación tira todo lo que vuelve de una pregunta que el visitante ya
  abandonó: sin él, la respuesta llegaba después, se pintaba en un panel cerrado
  y —en el canal hablado— se ponía a sonar sola.

#### Lo que las dos rutas pueden y no pueden

`POST /voz/chat` y `POST /voz/hablar` gastan dinero de la entidad y quien las
llama es un visitante anónimo, de modo que **no hay a quién autenticar**. Lo
que hay son cinco puertas, y ninguna hace imposible el abuso: lo encarecen.

- La comprobación de origen **falla cerrada**: antes dejaba pasar a quien no
  mandaba cabeceras, que es justo el `curl` que había que frenar.
- Límite por IP, y cupo diario que se **reserva antes** de llamar al proveedor
  y se devuelve si la llamada falla.
- El historial se **corta**: sesenta mensajes, 2.000 caracteres por pregunta y
  24.000 en total. Sin eso, el coste de una conversación crece con su longitud
  y quien quisiera gastar el presupuesto de la entidad solo tendría que seguir
  escribiendo.
- El historial que llega del navegador se **sanea entero** antes de reenviarlo:
  los nombres de herramienta contra una lista blanca, los identificadores
  contra un juego de caracteres, la conversación tiene que empezar por un turno
  del visitante. Lo que viene del navegador es texto ajeno aunque lo haya
  escrito nuestro propio JavaScript.

La última línea de defensa no está aquí: es el **límite de gasto de la cuenta
del proveedor**. El tope de este panel cuenta preguntas en **este** sitio y no
puede frenar un cargo hecho con la misma clave desde otro. Así está escrito en
`docs/voz/privacidad.md` y en el panel, en vez de dejarlo implícito.

#### Verificar, que es lo que «configurar la API» debería haber sido siempre

Pegar dos cadenas a ciegas y esperar a que un ciudadano descubra el fallo no es
configurar. `UHP_Voz::verificar()` le pregunta a cada proveedor: una línea al
modelo, sin herramientas, y el listado de voces a ElevenLabs. No es una
conversación, de modo que se puede usar.

Comprueba tres cosas, y la tercera es la que evita un panel mudo en producción:
que la clave del modelo vale, que la de ElevenLabs vale, y **que el
identificador de voz pertenece a esa cuenta**. Uno de otra cuenta, o de una voz
retirada de la biblioteca, no falla al guardarlo: falla la primera vez que
alguien pregunta algo. Si no está, el panel lista las que sí hay.

Los códigos se traducen a algo accionable: 401 y 403 son «la clave no es
válida», 404 es «el modelo o la voz no existen con ese nombre», 402 es saldo,
429 es límite de peticiones, y un 5xx dice con todas las letras que **no es un
problema de esta configuración** —eso evita que alguien se pase la tarde
cambiando claves que estaban bien—. El mensaje de transporte nunca se copia tal
cual: algunos traen la URL completa, y en Gemini la clave viaja en la URL.

#### El panel flota, y no por estética

`.uhp-db__col--izq` declara exactamente tantas filas de rejilla como hijos
tiene; una cuarta tarjeta desplazaría la lista de municipios a una fila que no
existe. Y, sobre todo, `avisar()` vacía `.uhp-db__main` con `innerHTML` cuando
la carga de datos falla: un panel colgado de ahí desaparecería con el micrófono
abierto. El bloque es hijo directo de `.uhp-db` y se posiciona encima.

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
4. **No se apropia del scroll.** Ver 7.1.

La lógica de la escena, la morfometría y los diez momentos de la línea de
tiempo son los del original, sin cambios.

### 7.1 El scroll de la página es de quien la lee

El original ocupaba la ventana entera, de modo que centrar el paso activo de la
línea de tiempo con `scrollIntoView` no tenía consecuencias. Embebido más abajo
en una página larga sí las tiene: `scrollIntoView` desplaza **todos** los
antepasados desplazables del elemento, el documento incluido.

El efecto era desconcertante porque solo aparecía al pasar por delante. El
bucle de dibujo se detiene mientras la escena está fuera de la vista y con él
la línea de tiempo (sección 7, punto 3), así que nada ocurría hasta que el
visitante llegaba bajando a la altura del objeto: ahí el recorrido se
reanudaba, cambiaba de momento y el navegador enganchaba la página al objeto,
justo mientras se estaba desplazando.

`centrarPaso()` mueve ahora solo el carril de los pasos, calculando su
`scrollLeft` desde la posición del botón activo. El efecto visible dentro del
componente es el mismo —el paso en curso queda a la vista— y el documento no se
entera.

Llevar la página hasta el objeto sigue siendo posible donde tenga sentido —si
el objeto **abre** la página y se quiere que el recorrido la gobierne— pero hay
que pedirlo:

```
[urkunina_3d desplazar="si"]
```

| Atributo | Valores | Por defecto | Qué hace |
|---|---|---|---|
| `alto` | cualquier medida CSS | `100vh` | Altura del contenedor. |
| `autoplay` | `si` / `no` | `si` | Avanza la línea de tiempo sola. |
| `duracion` | 4 a 90 | `15` | Segundos por momento. |
| `instrumentos` | `si` / `no` | `si` | Panel de morfometría. |
| `cabecera` | `si` / `no` | `si` | Marca institucional dentro de la escena. |
| `desplazar` | `si` / `no` | `no` | Lleva la página hasta el objeto al cambiar de momento. |

Los valores por defecto se editan en **URKUNINA 5000 → Componentes → Objeto
3D**, y el
shortcode manda sobre ellos. Ni siquiera con `desplazar="si"` se mueve la
página en la **primera** pintada: ahí nadie ha pedido nada todavía y saltar al
objeto nada más abrir sería el mismo problema con otro nombre.

Dos pruebas de navegador cubren las dos mitades del contrato: que avanzar la
línea de tiempo deja `window.scrollY` donde estaba —con el paso activo, aun
así, dentro de su carril— y que `desplazar="si"` sí mueve la página. Los pasos
se activan por código en ambas: si los pulsara Playwright, sería él quien
desplazase la ventana hasta el botón y la prueba no mediría nada.

---

## 8. Verificación

```bash
npm install          # Playwright
npm run test:datos   # capa de datos, sin WordPress
npm test             # lo anterior más las pruebas de navegador
```

### 8.1 Capa de datos — 506 comprobaciones

`tests/test-datos.php` ejecuta las clases del plugin fuera de WordPress, con
sustitutos mínimos de sus funciones (`tests/stubs-wordpress.php`). Comprueba
que los veintiún archivos se leen y cumplen su contrato, que la topología que
consume D3plus se construye bien —anillos cerrados, sentido de giro correcto,
error de cuantización por debajo del 0,03 % y subregiones disueltas—, que el saneador de CSS
neutraliza lo peligroso **y conserva intacto lo legítimo**, que los 55
municipios cruzan con la geometría, que las 29 vistas producen filas con la
forma que declaran, que sus textos llegan a los 375 caracteres, y que las
cifras cuadran entre sí: los positivos y negativos suman 5.000, la distribución
municipal de casos suma el total declarado, las muestras por tipo suman el
inventario y las fuentes de financiación suman el presupuesto.

La serie municipal completa trajo consigo cuatro comprobaciones más, todas del
mismo espíritu: que sus 55 municipios existen en la cobertura y no se repiten,
que **los veinticinco valores de los listados de extremos coinciden uno a uno
con los de la serie** —dos fuentes distintas contando lo mismo—, que el
porcentaje de los doce municipios con numerador sale de su propio numerador, y
que la media de los 55 valores no se aparta de la cifra departamental. Esta
última no dice cómo se calculó el 35,1 % —eso sale de 1.755 sobre 5.000— sino
que sirve de red: si alguien transcribe mal un municipio, la media se mueve y la
prueba lo delata.

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

### 8.2 Navegador — 96 pruebas

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
se descarga una sola vez por página, que la vista de los 55 municipios
colorea 55 y deja nueve sin dato, y que **una vista sin un solo valor lo dice**
en vez de dibujar una rampa de 0 a 0 —la prueba intercepta la respuesta y le
vacía los valores, que es lo que ocurrió en el sitio real con los datos
desfasados—; que el mapa pinta los 64 municipios sobre OpenStreetMap con su
leyenda y su atribución, que cambiar de indicador no vuelve a descargar la
geometría y que los polígonos son accesibles con teclado; que el tablero ocupa
el 100 % de ancho y 100vh de alto sin desbordar, que su mapa dibuja los 64
municipios **cada uno con su propia extensión** —la prueba del sentido de
giro: con los anillos al revés todos medirían lo mismo—, que los siete
municipios con caso llevan su círculo, que filtrar por zona o por subregión
recorta la lista, las cifras y el título del mapa, que elegir un municipio
abre su ficha y lo resalta, que **los 55 municipios intervenidos llevan ya su
propia cifra** y ninguno cae en la de su subregión —la comprobación de que la
serie completa llegó hasta el mapa—, que los nueve municipios fuera del estudio
siguen sin colorear, que cambiar de indicador repinta leyenda,
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
script se imprima primero; que al cambiar de vista **la que tiene territorio
abre en el mapa** y la que no lo tiene vuelve a su propio tipo, no a un mapa
vacío; que cambiar de tipo a mano no devuelve al mapa en el siguiente dibujo;
que el borde del control llega a 3:1 sobre su fondo, como exige WCAG 2.1
§1.4.11; y que un selector sin grupo avisa en vez de romper la página.

Del asistente se comprueba que el tablero sin configurar sale **byte por byte
como antes**; que el rótulo corto viaja en el HTML **con JavaScript
desactivado**; que con aviso encendido pulsar abre el aviso completo —con los
puntos que la Ley 1581 obliga a informar— y **no se gasta una pregunta** hasta
aceptar; que con el aviso apagado, que es como nace, pulsar abre la
conversación directamente; que el navegador sabe ejecutar las diez herramientas
por su nombre; que la primera pregunta ya lleva el estado de la pantalla y que
**no se repite** si no cambió; que las herramientas mueven el tablero de verdad
y el cambio se anuncia atribuyéndolo al asistente; que un nombre que no cruza
exacto **no selecciona** y que el modelo **no puede autoconfirmar su propia
sugerencia**; que un municipio fuera del estudio se dice con esas palabras; que
una respuesta con doce llamadas se ejecuta entera pero no repinta el tablero
doce veces; que lo que devuelve el proveedor se pinta como texto y un
`<img onerror=…>` no se ejecuta; que una respuesta que llega mientras el
visitante escribe **no le borra lo escrito**; que cerrar mientras el modelo
piensa descarta la respuesta tardía y no la pone a sonar; que la confirmación
de un municipio **no sobrevive** a la conversación; que **el asistente no pide
nada a un servidor externo**; y que sus pares tinta/fondo cumplen la norma de
contraste en los tres estados —lanzador, aviso y conversación—, con los
selectores que no encuentran nodo contados como fallo y no saltados en
silencio.

De las claves se comprueba, en las cuatro páginas con asistente, que **ninguna
de las dos aparece en el HTML** por ninguna vía, ni el nombre de ninguna
cabecera de autenticación, ni el dominio de ningún proveedor.

De la verificación se comprueba cada rama con un doble del HTTP: sin clave no
se sale a la red siquiera; 401 y 403 se traducen a «la clave no es válida»; un
modelo inexistente se nombra repitiendo lo que se escribió, que es donde está
la errata; 402 habla de saldo y 429 de límite; un 5xx dice que no es culpa de
lo configurado aquí; una voz que no está en la cuenta se detecta **aquí y no en
producción**, listando las que sí hay; el nombre que devuelve el proveedor se
sanea antes de imprimirlo; y que ElevenLabs falle **no tumba** el informe del
modelo, porque son dos piezas independientes. La petición se inspecciona: la
clave va en su cabecera y **nunca en la URL**, y la pregunta de prueba va sin
herramientas y con un solo mensaje.

De los tres adaptadores se manda una pregunta de verdad por cada uno y se mira
**lo que salió por el cable**: que cada uno llama a su propio dominio, que la
clave no va en la URL, que viajan las diez herramientas y que cada esquema va
cerrado y con su lista de obligatorios, como los tres lo exigen.

Del canal escrito se comprueba lo que lo hace distinto: que en una página con
solo ese canal no hay botón de micrófono ni viaja el aviso de la voz; que **no
se pide el micrófono ni se arranca el dictado ni una vez**; que **no se manda
nada a sintetizar** aunque la voz esté configurada; que el rótulo dice
«escriba» y no «escuchando», porque quien no ve la pantalla depende de esa
frase; que aceptar el aviso escrito **no** autoriza el micrófono y que al revés
sí; y que los dos lanzadores declaran el mismo estado del panel.

Del micrófono se comprueba que el dictado **no arranca solo**; que el asistente
se calla antes de abrirlo, o su propia voz entraría por él; que un navegador
que no sabe dictar lo dice y deja escribir, sin ofrecer un botón que falle; que
uno que falla al transcribir **apaga el micrófono para la visita** en vez de
dejar un botón roto; y que denegarlo **no gasta una pregunta** y deja por donde
seguir, no un cartel y nada que pulsar.

Y de lo que puede salir mal: que las puertas del servidor —cupo, límite por IP,
conversación demasiado larga— llegan al visitante como algo que entiende y
siempre con un botón para volver a preguntar; que un modelo enredado se para en
el tope; que no sonar **no rompe** la conversación, porque la respuesta ya está
escrita; y que sin clave de ElevenLabs no se pinta un lanzador de voz que no
podría hablar.

El entorno de pruebas espeja Three.js, D3plus y Leaflet en local
(`tests/vendor`, no versionado) para no depender de la red, incluida la ruta
absoluta que los complementos de Three.js importan internamente.

### 8.2.1 La suite no sale a la red

Salvo por el espejo de Three.js, ninguna prueba depende de internet. Las
peticiones a los servidores de capa base —OpenStreetMap y CARTO— se interceptan
y se responden con una tesela de un píxel: lo que se comprueba es que el
componente **pide** la capa base y la coloca, no cómo se ve una tesela.

Ya no hay SDK que doblar. Lo que se intercepta con `addInitScript`, antes de
que corra nada de la página, son las **dos rutas del asistente**: cada prueba
pone el guion que el doble irá contestando. Eso hace que se ejercite el camino
de verdad —la vuelta de herramientas, el tablero moviéndose, el turno
pintándose— y que lo único fingido sea lo que diría el modelo, que es
precisamente lo que no se puede comprobar en una suite. El micrófono y el
reconocimiento de voz del navegador llevan su propio doble, porque un Chromium
de pruebas no tiene ninguno de los dos y sin ellos todas las pruebas de voz
caerían por la rama de «su navegador no sabe dictar», que es justo la que hay
que poder distinguir de las demás. Una prueba vigila que no salga ni una
petición fuera del servidor local.

Dos pruebas se saltaban esa regla y salían de verdad a `tile.openstreetmap.org`.
Pasaban mientras el entorno lo permitiera; con un proxy que intercepta el TLS,
Chromium rechaza el certificado y esos fallos de red acababan contados como
errores del plugin. El helper `sinTeselas()` las cubre ahora también.

### 8.2.2 Las páginas de prueba llevan los scripts en línea reales

`tests/generar-paginas.php` escribía a mano `window.UHP` y `window.UHP3D`, y el
sustituto de `wp_add_inline_script()` era un no-op. Todo lo que el plugin manda
al navegador por esa vía quedaba fuera de la suite: un módulo que se apaga en su
primera línea por falta de configuración habría pasado las pruebas **sin
ejecutar una sola**. Ahora el sustituto guarda lo que se le añade y el generador
lo imprime donde WordPress lo pondría, antes o después de su etiqueta. El par
escrito a mano desapareció, y con él la posibilidad de que divergiera del real.

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
