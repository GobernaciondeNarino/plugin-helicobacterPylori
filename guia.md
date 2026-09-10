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
│   │   ├── class-uhp-views.php       registro de vistas del motor de gráficos
│   │   └── textos-graficos.php       descripción y análisis de cada vista
│   ├── analysis/
│   │   └── class-uhp-analisis.php    redacción automática del análisis
│   ├── shortcodes/
│   │   └── class-uhp-shortcodes.php  los nueve componentes
│   └── admin/
│       ├── class-uhp-admin.php       menú, siete módulos con pestañas
│       └── class-uhp-admin-datos.php módulo de actualización de los JSON
├── assets/
│   ├── css/   uhp.css · uhp-grafico.css · uhp-mapa.css
│   │          uhp-dashboard.css · uhp-3d.css · uhp-admin.css
│   └── js/    uhp-core.js · uhp-renderer.js · uhp-grafico.js
│              uhp-mapa.js · uhp-dashboard.js · uhp-3d.js · uhp-admin.js
├── data/                           el conjunto de datos del proyecto
├── tests/                          verificación (sección 8)
└── languages/                      es_CO
```

---

## 3. El conjunto de datos

Quince archivos en `data/`: catorce JSON del proyecto y la geometría municipal.

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
| `geojson` | `narino_municipios.geojson` | Geometría de los 64 municipios (DANE) |

### 3.1 Convenciones del conjunto

- **Decimales con punto.** Los documentos fuente usan coma; los JSON, punto.
- **Porcentajes como número**, sin el símbolo: `67.4` es 67,4 %.
- **`null`** cuando el dato no está documentado en las fuentes.
- **Bloque `_meta`** en todos los archivos, con procedencia, unidad y notas.

### 3.2 Discrepancias documentadas

El manifiesto registra cinco discrepancias entre las fuentes que **no se han
resuelto silenciosamente**. La más relevante para quien lea las cifras: el
biobanco suma 31.190 muestras por tipo, los documentos hablan de «más de
25.000» y la ficha MGA registra una meta de 45.000 cumplida al 100 %. Las tres
cifras están en los datos y su conciliación con la Fundación CIEDYN, custodia
del biobanco, sigue pendiente.

### 3.3 El cruce con la geometría

El GeoJSON del DANE nombra los municipios en mayúsculas y sin sufijos
(`LOS ANDES`, `COLÓN`), mientras los informes del proyecto los escriben en
formato de lectura y con el nombre popular entre paréntesis
(`Los Andes (Sotomayor)`, `Colón (Génova)`). `UHP_Municipios::normalizar()`
descarta el paréntesis, elimina los diacríticos y pasa a mayúsculas. Con esa
sola regla **los 55 municipios priorizados cruzan sin necesidad de una tabla de
alias**, y hay una prueba que lo verifica en cada ejecución.

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

### 4.4 Los dos textos de cada vista

Cada gráfico se publica acompañado de texto. Hay dos clases y viven en sitios
distintos a propósito:

- **Los que no dependen de las cifras** —qué muestra el gráfico y qué significa
  en términos clínicos o de política pública— se escriben a mano en
  `includes/data/textos-graficos.php`, con al menos 375 caracteres cada uno.
- **Los que sí dependen de las cifras** —máximo, mínimo, promedio, brecha— los
  redacta `UHP_Analisis` a partir de los datos, de modo que se actualizan solos
  cuando cambia un archivo.

### 4.5 Añadir una vista

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

### 4.6 Identidad visual del tablero

El tablero viste la paleta del objeto 3D: fondo `#0C1116`, paneles
translúcidos con desenfoque y borde blanco al 9 %, verde `#10A13B` y amarillo
`#FFD500` de la Gobernación, tinta clara `#E7EDF1`. Quien pasa de la escena al
tablero debe percibir una sola pieza, no dos productos distintos.

Tres consecuencias que no son solo de color:

- **La capa base por defecto es la oscura** (CARTO dark, sobre datos de
  OpenStreetMap). Las demás siguen disponibles en el selector y en Componentes.
- **Los gráficos se tiñen para fondo oscuro.** D3plus pinta los ejes en tonos
  pensados para fondo claro; el renderer acepta `tema: 'oscuro'` y fija la
  tinta de títulos, etiquetas, rejilla y leyenda. La paleta categórica también
  cambia: el verde institucional y el azul de encabezados no llegan al
  contraste mínimo sobre `#0C1116`, así que el tema oscuro usa versiones
  aclaradas. Todos los pares tinta/fondo se verificaron por cálculo y superan
  4,5:1 en texto y 3:1 en elemento gráfico.
- **El filete de «municipio priorizado» se atenúa.** 55 de los 64 municipios lo
  son: a plena intensidad sobre fondo oscuro el mapa se convertía en una malla
  verde que ya no distinguía nada.

Los tokens del tablero se declaran con prefijo propio (`--uhp-db-*`) en vez de
reutilizar los `--uhp3d-*`, porque el objeto 3D puede no estar en la página.
Una prueba de navegador compara ambos conjuntos y avisa si la paleta de la
escena cambia y el tablero se queda atrás.

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

### 8.1 Capa de datos — 263 comprobaciones

`tests/test-datos.php` ejecuta las clases del plugin fuera de WordPress, con
sustitutos mínimos de sus funciones (`tests/stubs-wordpress.php`). Comprueba
que los quince archivos se leen y cumplen su contrato, que el saneador de CSS
neutraliza lo peligroso **y conserva intacto lo legítimo**, que los 55
municipios cruzan con la geometría, que las 24 vistas producen filas con la
forma que declaran, que sus textos llegan a los 375 caracteres, y que las
cifras cuadran entre sí: los positivos y negativos suman 5.000, la distribución
municipal de casos suma el total declarado, las muestras por tipo suman el
inventario y las fuentes de financiación suman el presupuesto.

### 8.2 Navegador — 20 pruebas

`tests/navegador.spec.js` abre en Chromium **el marcado real que emiten los
shortcodes**: `tests/generar-paginas.php` lo produce llamando a
`UHP_Shortcodes`, de modo que lo que se prueba no es una copia que pueda quedar
desfasada. Las respuestas de la API salen de `tests/fixtures`, generadas desde
los datos reales.

Se verifica que la escena 3D arranca con contexto WebGL y llena su contenedor,
que sus controles avanzan y pausan, que embebida no se apropia del teclado; que
las ocho vistas de la página de gráficos se dibujan y que el color va por serie
y no por punto; que el mapa pinta los 64 municipios sobre OpenStreetMap con su
leyenda y su atribución, que cambiar de indicador no vuelve a descargar la
geometría y que los polígonos son accesibles con teclado; que el tablero ocupa
el 100 % de ancho y 100vh de alto, que sus filtros responden, que al pulsar un
municipio se abre su ficha, que los paneles se pliegan y que en móvil las zonas
se apilan sin desbordar; que las cifras, la ficha y la tabla llegan en el HTML
**con el JavaScript desactivado**; y que mapa, gráfico y escena 3D funcionan
juntos en una misma página con una sola instancia de cada librería.

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
- **API pública de solo lectura.** Las ocho rutas sirven datos agregados ya
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
