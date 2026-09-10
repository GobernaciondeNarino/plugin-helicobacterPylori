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

Convierte el conjunto de datos del proyecto —catorce archivos JSON más la
geometría municipal— en componentes publicables desde el editor de WordPress:

| Componente | Shortcode |
|---|---|
| Tablero completo con mapa, controles, filtros y gráficos | `[urkunina_dashboard]` |
| Recreación 3D de *Helicobacter pylori* con línea de tiempo | `[urkunina_3d]` |
| Gráfico de cualquiera de las 24 vistas del catálogo | `[urkunina_grafico view="…"]` |
| Mapa coroplético de los 64 municipios sobre OpenStreetMap | `[urkunina_mapa]` |
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

Los siete se renderizan en el servidor: el texto viaja en el HTML, sin petición
ni parpadeo, y sigue ahí con JavaScript desactivado o para un buscador.

> **La atribución dejó de ser automática.** Como `[urkunina_grafico]` ya no
> imprime la línea de fuente, publique `[urkunina_fuente view="…"]` junto a cada
> gráfico: citar la procedencia del dato es obligatorio.

El catálogo completo, con todos los atributos y ejemplos copiables, está en el
panel: **URKUNINA 5000 → Shortcodes**.

---

## Instalación

1. Copie la carpeta del plugin en `wp-content/plugins/urkunina-5000/`.
2. Actívelo desde **Plugins** en el escritorio de WordPress.
3. Compruebe **URKUNINA 5000 → Diagnóstico → Entorno**: ahí figura si el
   directorio `data/` tiene permisos de escritura, que es lo que habilita el
   módulo de actualización de datos.

**Requisitos:** WordPress 5.8 o superior, PHP 7.4 o superior, extensiones `json`
y `mbstring`. Para la escena 3D, un navegador con WebGL 2.

Publicar el tablero requiere una plantilla de página de ancho completo y sin
barra lateral: el contenedor ocupa el 100 % del ancho y toda la altura de la
ventana. Viste la misma paleta que el objeto 3D —fondo oscuro, verde y amarillo
institucionales— para que ambos se lean como una sola pieza.

---

## Módulos del panel

| Módulo | Para qué |
|---|---|
| **Panel** | Estado general, cifras del proyecto y qué conviene revisar hoy. |
| **Datos** | Consultar, validar, editar, subir y restaurar cada archivo del conjunto. |
| **Gráficos** | Catálogo de vistas con su descripción, su análisis y su shortcode. |
| **Shortcodes** | Los nueve componentes con sus atributos y ejemplos. |
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
GET /wp-json/urkunina/v1/dashboard          → todo lo que necesita el tablero
```

Todas son públicas y de solo lectura, con límite de peticiones por IP.

**Los datos son agregados.** No contienen microdatos de los 5.000 participantes,
resultados de laboratorio individuales ni la identidad de los pacientes con
cáncer detectado: esa información no está en los documentos fuente.

---

## Verificación

```bash
npm install          # instala Playwright
npm run test:datos   # 263 comprobaciones de la capa de datos, sin WordPress
npm test             # lo anterior más 20 pruebas de navegador
```

Las pruebas de navegador abren en Chromium el marcado real que emiten los
shortcodes. Detalle en la sección «Verificación» de [`guia.md`](guia.md).

---

## Documentación

- [`guia.md`](guia.md) — guía técnica: arquitectura, motor de gráficos, cómo
  añadir una vista, módulo de datos y verificación.
- [`docs/auditoria/2026-09-10-auditoria-seguridad.md`](docs/auditoria/2026-09-10-auditoria-seguridad.md)
  — informe de auditoría de seguridad.
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
