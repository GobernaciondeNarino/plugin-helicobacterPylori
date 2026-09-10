# Informe de auditoría de seguridad — plugin URKUNINA 5000 — 2026-09-10 — TLP:AMBER

**Sistema auditado:** plugin de WordPress URKUNINA 5000, versión 1.0.0
**Entidad:** Gobernación de Nariño · Secretaría TIC, Innovación y Gobierno Abierto
**Tipo de análisis:** estático sobre código propio, más verificación dinámica en navegador
**Autorización:** titularidad del código por parte de la entidad. No se ejecutó ninguna prueba activa contra sistemas en operación.

---

## 1. Resumen ejecutivo

El plugin se revisó completo antes de su publicación: los 14 archivos PHP, los
7 archivos JavaScript (6 del front y el del panel) y el conjunto de datos. **No se encontró
ninguna vulnerabilidad explotable.**

La razón principal es de diseño y no de filtrado: el plugin tiene una
superficie de ataque deliberadamente pequeña. No crea tablas, no almacena
información personal, no consume APIs externas, no acepta escrituras por su
API pública y escribe en un solo directorio, el suyo, siempre por la misma
función. Los datos que publica son agregados por municipio, subregión o
categoría, ya divulgados institucionalmente.

Se aplicaron **dos endurecimientos preventivos** sobre puntos que hoy no son
explotables pero que reducen el margen de error de quien mantenga el código en
el futuro (H-01 y H-02), y se corrigieron **tres defectos funcionales** que
detectó la verificación dinámica (H-03 a H-05). Los cinco están corregidos y
verificados en esta misma entrega.

El barrido automático arrojó 56 candidatos. Los 56 se verificaron uno a uno y
**los 56 son falsos positivos**; quedan documentados en la sección 5 para que
la próxima auditoría no vuelva a levantarlos.

**Recomendación:** el plugin puede publicarse. La única acción pendiente es de
despliegue, no de código: verificar tras la instalación que el directorio
`data/` tiene permisos de escritura solo para el usuario del servidor web.

---

## 2. Alcance y metodología

### Qué se revisó

| Superficie | Detalle |
|---|---|
| Escritura en disco | `UHP_Security::escribir_atomico()` y sus seis puntos de llamada |
| API REST pública | Las 8 rutas de `/wp-json/urkunina/v1/` |
| Panel de administración | 7 páginas, 6 acciones `admin-post`, 3 opciones con saneador |
| Shortcodes | Los 9 componentes y sus atributos |
| Front en el navegador | Inserción en el DOM, tratamiento de la respuesta de la API |
| Datos | Los 15 archivos de `data/` y su validación |
| Terceros | D3plus 2.0.0, Leaflet 1.9.4, Three.js 0.180.0 |

### Qué NO se revisó

- La instalación de WordPress que lo aloje, su tema y el resto de plugins.
- La configuración del servidor web y de PHP.
- La veracidad epidemiológica de los datos, que corresponde a la Fundación
  CIEDYN y al Hospital Universitario Departamental de Nariño.

### Herramientas

- `escanear_repo.py` de la skill de ciberseguridad institucional — barrido
  estático de secretos y patrones peligrosos.
- Revisión manual dirigida: escapado de salida, capacidades, nonces, guardas de
  acceso directo, funciones peligrosas y flujo de escritura.
- Playwright sobre Chromium — 23 pruebas de comportamiento real en navegador.
- `php -l` sobre los 14 archivos PHP y `node --check` sobre los 7 de JavaScript.

---

## 3. Resumen de hallazgos

| ID | Título | Severidad | Componente | Estado |
|---|---|---|---|---|
| H-01 | Los objetos de configuración se serializaban sin escapar `<` ni `&` | Informativa | `class-uhp-assets.php` | Corregido |
| H-02 | El saneador de CSS dejaba pasar la comilla simple | Informativa | `class-uhp-estilos.php` | Corregido |
| H-03 | La ficha del municipio se destruía al cambiar de capa base | Informativa | `class-uhp-shortcodes.php` | Corregido |
| H-04 | El tablero usaba `UHPMapa` sin declararlo como dependencia | Informativa | `class-uhp-assets.php` | Corregido |
| H-05 | El renderer usaba `UHPcore` sin declararlo como dependencia | Informativa | `class-uhp-assets.php` | Corregido |
| O-01 | Los respaldos se protegen solo con `.htaccess` | Observación | `data/respaldos/` | Aceptado |

Ninguno alcanza severidad Baja o superior. H-01 y H-02 son endurecimientos
sobre puntos sin ruta de explotación actual, conforme al principio de que *un
hallazgo sin ruta de explotación es una observación*; H-03, H-04 y H-05 son
defectos funcionales sin consecuencia de seguridad, y se incluyen porque los
detectó la verificación de esta misma auditoría.

---

## 4. Hallazgos detallados

### [H-01] Los objetos de configuración se serializaban sin escapar `<` ni `&`

**Severidad:** Informativa · **Componente:** `includes/class-uhp-assets.php` · **Control:** NIST CSF PR.PS-06 / ISO 27001 A.8.28 / CIS 16.11

**Qué pasa.** El plugin imprime dos objetos de configuración en bloques
`<script>`: `window.UHP` con las URL de la API, y `window.UHP3D` con las URL de
Three.js. Se serializaban con `wp_json_encode()` sin banderas, que no escapa
`<`, `>` ni `&`.

**Cómo se explota.** No se explota en la versión auditada. Los valores salen de
`rest_url()`, de `plugin_dir_url()` y de una constante de idioma: los tres los
determina la configuración del sitio, no el visitante. Para que fuera
explotable haría falta que un administrador lograra introducir la secuencia
`</script>` en la URL del sitio, lo que WordPress no permite.

**Impacto.** Nulo hoy. El riesgo es de futuro: si el filtro
`uhp_url_libreria` —pensado precisamente para que la entidad autoaloje las
librerías— recibiera un valor de una fuente menos controlada, un `</script>`
cerraría el bloque y permitiría inyectar guion.

**Evidencia.**

```php
wp_add_inline_script( self::P . 'core', 'window.UHP=' . wp_json_encode( self::config_front() ) . ';', 'before' );
```

**Remediación.** Serialización centralizada con las banderas HEX, que escapan
`<`, `>`, `&` y ambas comillas a secuencias `\uXXXX`:

```php
public static function json_para_script( $valor ) {
    $json = wp_json_encode(
        $valor,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
    );
    return false === $json ? '{}' : $json;
}
```

Aplicada en los dos puntos de impresión.

**Verificación.** `UHP_Assets::json_para_script( array( 'x' => '</script>' ) )`
devuelve `{"x":"\u003C\/script\u003E"}`: los signos de mayor y menor salen
escapados y la secuencia deja de poder cerrar el bloque. Las 20 pruebas de
navegador siguen en verde, luego el front sigue leyendo la configuración.

---

### [H-02] El saneador de CSS dejaba pasar la comilla simple

**Severidad:** Informativa · **Componente:** `includes/class-uhp-estilos.php:151` · **Control:** NIST CSF PR.PS-06 / ISO 27001 A.8.28 / CIS 16.11

**Qué pasa.** `UHP_Estilos::sanitizar_css()` limpia los valores de color y
medida que llegan desde los atributos de los shortcodes y desde el módulo de
Apariencia. Eliminaba `;`, `{`, `}`, `<`, `>`, `\`, la comilla doble, la tilde
invertida y la arroba, pero **no la comilla simple**.

**Cómo se explota.** No se explota en la versión auditada. Los tres destinos
del valor lo neutralizan:

1. Atributo `style="…"` de un shortcode → pasa por `esc_attr()`, que convierte
   la comilla simple en `&#039;`.
2. Bloque `<style>` de `wp_add_inline_style()` → dentro de una declaración CSS
   la comilla simple es inerte; solo `</style>` cerraría el bloque, y `<` sí se
   elimina.
3. La vista previa del panel → también `esc_attr()`.

Además, el valor solo lo controla quien ya tiene `manage_options` (Apariencia)
o quien puede editar una entrada (atributos del shortcode).

**Impacto.** Nulo hoy. El riesgo es que un punto de inserción futuro que se
olvidara de `esc_attr()` convirtiera el descuido en XSS almacenado. Un saneador
que se llama «saneador» debe poder confiarse.

**Evidencia.**

```
entrada: red' onmouseover='alert(1)   →   salida: red' onmouseover='alert(1)
```

**Remediación.** La comilla simple entra en la lista de caracteres eliminados.
Como efecto colateral las familias tipográficas por defecto pasan a declararse
sin entrecomillar —en CSS un nombre de familia de varias palabras es válido sin
comillas, de modo que la pila resultante es idéntica—; así el valor no cambia
al primer guardado en Apariencia.

**Verificación.** Dos comprobaciones de regresión añadidas a la suite, más
cuatro que verifican lo contrario y son igual de importantes: que `rgba()`,
`calc()`, `clamp()` y los colores hexadecimales **sobreviven intactos**. Un
saneador que rompe los valores legítimos es un saneador que nadie usará.

```
entrada: red' onmouseover='alert(1)   →   salida: red onmouseover=alert(1)
entrada: rgba(16,161,59,.5)           →   salida: rgba(16,161,59,.5)
entrada: calc(100vh - 80px)           →   salida: calc(100vh - 80px)
```

---

### [H-03] La ficha del municipio se destruía al cambiar de capa base

**Severidad:** Informativa (defecto funcional) · **Componente:** `includes/shortcodes/class-uhp-shortcodes.php` · **Control:** —

**Qué pasa.** En el tablero, la ficha que muestra los datos del municipio
seleccionado colgaba del mismo nodo que el mapa vacía al reconstruirse. Al
cambiar de capa base, `montarMapa()` hacía `innerHTML = ''` sobre el
contenedor y eliminaba la ficha; a partir de ahí, pulsar un municipio no
mostraba nada.

**Cómo se reproduce.** Abrir el tablero, cambiar la capa base en el panel de
controles y pulsar cualquier municipio.

**Impacto.** No es de seguridad: es una función del tablero que dejaba de
responder sin aviso. Se incluye en el informe porque lo detectó la verificación
dinámica de esta misma auditoría.

**Remediación.** La ficha pasa a ser hermana del lienzo del mapa y no su hija,
con un contenedor propio para el lienzo:

```html
<div class="uhp-db__mapa">
  <div class="uhp-db__mapa-zona" data-uhp-zona="mapa"></div>
  <div class="uhp-db__ficha" data-uhp-zona="ficha"></div>
</div>
```

**Verificación.** Prueba de navegador «al pulsar un municipio se abre su
ficha», en verde.

---

### [H-04] El tablero usaba `UHPMapa` sin declararlo como dependencia

**Severidad:** Informativa (defecto funcional) · **Componente:** `includes/class-uhp-assets.php` · **Control:** —

**Qué pasa.** El tablero construye su mapa a través de `UHPMapa`, que vive en
`assets/js/uhp-mapa.js`, pero ni el registro del script ni el shortcode
declaraban ese archivo. En el sitio real el mapa no llegaba a dibujarse y el
mensaje de error culpaba a Leaflet, que sí estaba cargado.

**Cómo se reproduce.** Publicar `[urkunina_dashboard]` en una página: el hueco
del mapa muestra «No se pudo iniciar el mapa: la librería Leaflet no está
disponible».

**Impacto.** No es de seguridad. La función central del tablero quedaba
inutilizada y el diagnóstico apuntaba al componente equivocado, que es lo que
convierte un fallo de diez minutos en uno de una tarde.

**Remediación.** `uhp-dashboard` declara `uhp-mapa` entre sus dependencias, de
script y de estilo; el shortcode encola solo su handle principal y deja que el
grafo arrastre el resto. Los dos motivos posibles se distinguen ahora en el
mensaje.

**Verificación.** Prueba «carga el módulo de mapa del plugin, no solo Leaflet».

---

### [H-05] El renderer usaba `UHPcore` sin declararlo como dependencia

**Severidad:** Informativa (defecto funcional) · **Componente:** `includes/class-uhp-assets.php` · **Control:** —

**Qué pasa.** `assets/js/uhp-renderer.js` captura `window.UHPcore` al cargarse y
lo usa para la rampa de color y el formato de cifras, pero se registraba
declarando solo `d3plus`. WordPress lo imprimía **antes** que el núcleo, de modo
que la referencia quedaba indefinida.

**Cómo se reproduce.** Publicar cualquier vista de mapa de calor —las de
ranking, como `prev_lpm_municipios` o `metas_mga`—: son las que piden la rampa
de color y por tanto las únicas que tocaban la referencia indefinida.

**Impacto.** No es de seguridad. Las nueve vistas de mapa de calor, de las 24
del catálogo, no se dibujaban con su tipo por defecto. Se
agravaba porque el manejador de errores del gráfico trataba la excepción de
dibujo como un fallo de red y mostraba «No se pudo cargar el gráfico»,
señalando de nuevo al culpable equivocado.

**Remediación.** `uhp-renderer` declara `uhp-core` además de `d3plus`. Aparte,
el hidratador separa el fallo de red del fallo de dibujo con `then(éxito,
fallo)` y deja traza en consola; y `UHPRenderer.render()` protege también su
propio respaldo, para que un fallo del respaldo no escape al llamador.

**Verificación.** Las ocho vistas de la página de gráficos se dibujan, y la
generación de páginas de prueba resuelve ahora el grafo real de dependencias
(sección 5).

---

## 5. Observaciones y descartes

### [O-01] Los respaldos se protegen solo con `.htaccess` — Aceptado

`data/respaldos/` se protege con `index.php` y un `.htaccess` con
`Require all denied`. En Nginx esa protección no se aplica y el directorio
quedaría legible por HTTP.

**Por qué se acepta.** Los respaldos son copias literales de los archivos de
`data/`, que el propio plugin publica como datos abiertos en
`/wp-json/urkunina/v1/abierto/{clave}`. No exponen nada que no sea ya público
por diseño, y no contienen información personal. Queda documentado en la
sección «Qué queda fuera» de `guia.md` para que una fase futura que introduzca
datos sensibles lo revise antes.

### Falsos positivos verificados (56)

| Regla | Nº | Por qué es falso positivo |
|---|---:|---|
| `JS-001` innerHTML | 25 | 15 son `innerHTML = ''` (vaciado de contenedor, sin dato). Las 10 restantes están en `uhp-3d.js` y escriben desde el arreglo `MOMENTOS`, un literal del propio módulo con marcado `<b>` intencional. Ningún dato externo alcanza un `innerHTML`: lo que llega de la API se inserta con `textContent`. |
| `PHP-004` inclusión dinámica | 22 | Todas son `require_once $base . 'literal'` con `$base` derivado de `UHP_DIR`, una constante de `plugin_dir_path( __FILE__ )`. Ninguna entrada de usuario alcanza una ruta de inclusión. Nueve están además en `tests/`, que no se distribuye. |
| `PHP-010` posible SSRF | 6 | Son lecturas de archivo local: rutas construidas desde `UHP_DIR` más una entrada de la lista blanca, o `$_FILES[…]['tmp_name']` con `is_uploaded_file()` como guarda previa. Nunca una URL. |
| `SEC-010` contraseña embebida | 2 | La regla detectó la palabra «clave» en nombres de variable (`$clave`, `$clave_base`), que en este código significa «clave de registro», no «contraseña». No hay ninguna credencial en el repositorio. |
| `PHP-013` hash débil | 1 | `md5()` deriva el nombre de un transitorio para el límite de peticiones, no un hash de contraseña. `$clave_base` es un literal del propio código y la IP está validada: no hay entrada que permita provocar una colisión. Es la misma práctica que usa el núcleo de WordPress para nombrar transitorios. |

### Por qué la suite no detectaba H-04 ni H-05

Las páginas de prueba enumeraban a mano los CSS y los JS de cada componente, en
vez de derivarlos de lo que el plugin encola. Cargaban de más y, con ello,
tapaban dos dependencias no declaradas.

Corregido de raíz: `tests/generar-paginas.php` resuelve ahora el mismo grafo de
dependencias que resolvería WordPress, a partir de lo que cada shortcode encoló
de verdad, y una dependencia declarada que nadie registró detiene la generación
con un error explícito. Es la clase de fallo que ninguna revisión de código
detecta con fiabilidad y que una prueba fiel detecta siempre.

### Comprobaciones que resultaron correctas

- **Guardas de acceso directo:** los 14 archivos de `includes/` empiezan con
  `defined( 'ABSPATH' ) || exit`.
- **Funciones peligrosas:** cero apariciones de `eval`, `unserialize`,
  `extract`, `assert`, `system`, `exec`, `shell_exec`, `passthru` o `popen`.
- **Escapado de salida:** cero `echo` de variable sin función de escape.
- **Autorización:** las 6 acciones `admin-post` llaman a
  `UHP_Security::exigir_admin()`, que exige `manage_options` **y** nonce. Las 7
  páginas del panel comprueban la capacidad antes de imprimir nada.
- **Saneadores de opciones:** las 3 opciones registradas con `register_setting`
  tienen `sanitize_callback`, y los valores enumerados se validan contra listas
  blancas.
- **Coordenadas:** las del mapa se validan contra el recuadro geográfico de
  Nariño; fuera de él se cae al centro del departamento en lugar de mostrar
  otro territorio.
- **Secretos:** ninguno en el repositorio. El plugin no usa claves de API.

---

## 6. Mapa de cumplimiento

| Control | Marco | Cómo lo cubre el plugin |
|---|---|---|
| PR.AA-05 (privilegio mínimo) | NIST CSF 2.0 | Toda escritura exige `manage_options`; la API pública es de solo lectura |
| PR.DS-01 (datos en reposo) | NIST CSF 2.0 | Sin datos personales; escritura atómica con respaldo previo |
| PR.PS-06 (desarrollo seguro) | NIST CSF 2.0 | Saneado de entrada, escapado de salida, validación por lista blanca |
| DE.CM-09 (integridad) | NIST CSF 2.0 | SHA-256 por archivo; aviso ante cambios hechos fuera del panel |
| A.8.28 (codificación segura) | ISO/IEC 27001:2022 | H-01 y H-02; ausencia de funciones peligrosas |
| A.8.24 (criptografía) | ISO/IEC 27001:2022 | No aplica: el plugin no gestiona secretos |
| CIS 16.11 (validación de entradas) | CIS v8 | Listas blancas en shortcodes, API y módulo de datos |
| CIS 3.3 (acceso a datos) | CIS v8 | Datos agregados; sin información personal identificable |
| Ley 1581 de 2012 | Colombia | No aplica: el plugin no trata datos personales |
| Resolución 1519 de 2020, Anexo 1 | Colombia | Contraste verificado, foco visible, mapa navegable con teclado, alternativa textual de cada gráfico |

---

## 7. Plan de remediación

| ID | Acción | Esfuerzo | Responsable | Plazo | Estado |
|---|---|---|---|---|---|
| H-01 | Serializar con banderas HEX | 15 min | Desarrollo | — | **Hecho** |
| H-02 | Eliminar la comilla simple en el saneador de CSS | 20 min | Desarrollo | — | **Hecho** |
| H-03 | Sacar la ficha del nodo que el mapa reconstruye | 20 min | Desarrollo | — | **Hecho** |
| H-04 | Declarar `uhp-mapa` como dependencia del tablero | 15 min | Desarrollo | — | **Hecho** |
| H-05 | Declarar `uhp-core` como dependencia del renderer | 15 min | Desarrollo | — | **Hecho** |
| O-01 | Revisar la protección de respaldos si una fase futura añade datos sensibles | — | Desarrollo | Fase siguiente | Documentado |
| D-01 | Verificar tras la instalación que `data/` es escribible solo por el usuario del servidor web | 10 min | Infraestructura | Al desplegar | Pendiente |

---

## 8. Anexos

### Terceros

| Librería | Versión | Origen | Vulnerabilidades conocidas |
|---|---|---|---|
| D3plus | 2.0.0 | jsDelivr | No verificado en esta sesión: sin acceso a una base de vulnerabilidades durante la auditoría |
| Leaflet | 1.9.4 | unpkg | No verificado en esta sesión, misma razón |
| Three.js | 0.180.0 | jsDelivr | No verificado en esta sesión, misma razón |

Las tres se cargan desde una CDN. La entidad puede autoalojarlas con el filtro
`uhp_url_libreria` (sección 6.1 de `guia.md`), lo que además permite aplicar
una política de seguridad de contenido sin `cdn.jsdelivr.net` ni `unpkg.com`.

**Se recomienda** verificar las tres versiones contra el catálogo KEV de CISA y
contra el aviso de seguridad de npm antes de cada despliegue. No pudo hacerse
en esta sesión y no debe darse por hecho a partir de este informe.

### Resultado de las pruebas

```
php tests/test-datos.php     →  263 de 263 comprobaciones en verde
npx playwright test          →   23 de 23 pruebas de navegador en verde
php -l  (14 archivos)        →  sin errores de sintaxis
node --check (7 archivos)    →  sin errores de sintaxis
```

---

## Anexo — revisión del módulo de geomapas

*Añadido el mismo día, después del informe anterior, al incorporarse
`[urkunina_geomapa]`, la conversión a TopoJSON y la capa subregional. El
cuerpo del informe describe el estado en el momento de firmarlo; este anexo
cubre lo que se añadió después y no lo reescribe.*

### Superficie nueva

| Superficie | Detalle |
|---|---|
| API REST | Dos rutas más: `/topojson` y `/geomapa`. Diez en total |
| Shortcodes | Siete más desde el informe: los seis de texto y `[urkunina_geomapa]`. Dieciséis en total |
| Datos | Un archivo más: `dep-sub-mun.geojson`. Dieciséis en total |
| Clases | `UHP_Topojson` y `UHP_Subregiones` |

### Hallazgos

Ninguno explotable. Se comprobó lo siguiente:

- **Las dos rutas nuevas son de solo lectura**, públicas y sin efectos
  secundarios, con el mismo límite de peticiones por IP que las demás
  (`/topojson` con el más estricto, 30 por minuto, por ser la más pesada).
  Sus dos únicos parámetros —`nivel`, `view` e `indicador`— pasan por
  `UHP_Security::clave()` y se contrastan contra listas cerradas: un `nivel`
  desconocido cae a `municipio`, un indicador desconocido cae a `lpm` y una
  vista que no sea territorial devuelve 400 sin tocar el disco.
- **La topología se construye solo desde archivos de la lista blanca.** No hay
  ninguna ruta de archivo derivada de la petición.
- **`[urkunina_geomapa]` no acepta el nivel como atributo**: lo decide la vista.
  Es una decisión de corrección de datos, no de seguridad, pero reduce a cero
  las combinaciones que quien maqueta puede pedir. El resto de atributos se
  sanea con `UHP_Security::clave()` o se compara contra una lista cerrada, y la
  altura pasa por `UHP_Estilos::sanitizar_css()`.
- **El nombre de la subregión que llega al mapa es el cartográfico**, tomado del
  índice del plugin, no el que venga en el archivo de cifras.
- **La atribución de las teselas la deriva D3plus de la URL de la capa**, que es
  una constante del plugin. No hay HTML de origen externo en ese punto.

### Cambio deliberado en un límite

`UHP_Security::MAX_JSON_BYTES` seguía en 2 MiB y el archivo de subregiones pesa
3 MiB. **No se aflojó el límite general**: se añadió `MAX_GEO_BYTES` (8 MiB),
que se aplica únicamente a las entradas del registro marcadas `geo`. Los catorce
archivos de cifras conservan su tope de 2 MiB, que es donde importa: son los
que se editan a diario desde el panel y los que un JSON enorme podría usar para
agotar la memoria del sitio. El tope se aplica en los tres puntos donde se mide
el tamaño —validación, subida y el `MAX_FILE_SIZE` del formulario— a través de
`UHP_Datos::tope_bytes()`, de modo que no puede quedar uno desalineado.

### Resultado de las pruebas tras el anexo

```
php tests/test-datos.php     →  311 de 311 comprobaciones en verde
npx playwright test          →   36 de 36 pruebas de navegador en verde
```

---

*Informe generado el 10 de septiembre de 2026. Clasificación TLP:AMBER:
compartir solo dentro de la entidad y con los responsables del despliegue.*
