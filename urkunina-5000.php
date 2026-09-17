<?php
/**
 * Plugin Name:  URKUNINA 5000 — Helicobacter pylori y cáncer gástrico (Nariño)
 * Plugin URI:   https://gobiernoabierto.narino.gov.co/datos/urkunina5000/
 * Description:  Plataforma de visualización del proyecto URKUNINA 5000 (BPIN 2015000100064): recreación 3D de Helicobacter pylori, motor de gráficos D3plus, mapa OpenStreetMap y tablero completo de resultados del tamizaje en los 55 municipios priorizados de Nariño. Cada componente es un shortcode independiente.
 * Version:      1.3.1
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author:       Gobernación de Nariño · Secretaría TIC, Innovación y Gobierno Abierto
 * Author URI:   https://gobiernoabierto.narino.gov.co
 * License:      GPL-2.0-or-later
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:  urkunina-5000
 * Domain Path:  /languages
 *
 * Fuentes de datos (atribución obligatoria): Informe Preliminar URKUNINA 5000 y
 * Presentación Informe Final (Fundación CIEDYN / Hospital Universitario
 * Departamental de Nariño), Instituto Nacional de Cancerología, DANE (marco
 * geoestadístico municipal) y OpenStreetMap (© colaboradores de OSM, ODbL).
 *
 * @package Urkunina5000
 */

// Salida directa bloqueada: ningún acceso fuera de WordPress.
defined( 'ABSPATH' ) || exit;

use GobernacionNarino\Urkunina\UHP_Plugin;
use GobernacionNarino\Urkunina\UHP_Activator;

/* -------------------------------------------------------------------------
 * Constantes del plugin.
 *
 * El prefijo UHP_ (Urkunina · Helicobacter Pylori) se eligió para no chocar
 * con MAN_ del plugin «Monitor Ambiental y Fenómeno El Niño — Nariño», que
 * convive con este en el mismo sitio. Ver includes/class-uhp-assets.php para
 * la política de librerías compartidas (D3, D3plus, Leaflet, Plotly, Three).
 * ---------------------------------------------------------------------- */
define( 'UHP_VERSION', '1.3.1' );
define( 'UHP_FILE', __FILE__ );
define( 'UHP_DIR', plugin_dir_path( __FILE__ ) );   // .../urkunina-5000/
define( 'UHP_URL', plugin_dir_url( __FILE__ ) );     // URL pública de assets
define( 'UHP_BASENAME', plugin_basename( __FILE__ ) );

/* -------------------------------------------------------------------------
 * Carga del orquestador y de todas las dependencias.
 * Se hace al cargar el archivo (no en un hook) para que el activador tenga
 * las clases disponibles al sembrar opciones y copias de seguridad.
 * ---------------------------------------------------------------------- */
require_once UHP_DIR . 'includes/class-uhp-plugin.php';
UHP_Plugin::cargar_dependencias();

/* -------------------------------------------------------------------------
 * Ciclo de vida
 * ---------------------------------------------------------------------- */
register_activation_hook( __FILE__, array( UHP_Activator::class, 'activar' ) );
register_deactivation_hook( __FILE__, array( UHP_Activator::class, 'desactivar' ) );

// Arranque: instancia singleton en plugins_loaded (registra todos los hooks).
add_action( 'plugins_loaded', array( UHP_Plugin::class, 'instancia' ) );
