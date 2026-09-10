<?php
/**
 * Sustitutos mínimos de WordPress para ejecutar la capa de datos fuera de él.
 *
 * Permite validar en integración continua —sin instalar WordPress— que los
 * catorce archivos JSON de /data se leen, que todas las vistas producen
 * filas coherentes y que el análisis automático se redacta sin errores.
 *
 * NO pretende emular WordPress: solo cubre las funciones que usan
 * UHP_Datos, UHP_Municipios, UHP_Views, UHP_Analisis y UHP_Rest.
 *
 * @package Urkunina5000
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

define( 'UHP_VERSION', '1.0.0' );
define( 'UHP_FILE', dirname( __DIR__ ) . '/urkunina-5000.php' );
define( 'UHP_DIR', dirname( __DIR__ ) . '/' );
define( 'UHP_URL', 'https://example.test/wp-content/plugins/urkunina-5000/' );
define( 'UHP_BASENAME', 'urkunina-5000/urkunina-5000.php' );

define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

/** @var array<string,mixed> Almacén en memoria de opciones y transitorios. */
$GLOBALS['uhp_test_almacen'] = array();

function get_transient( $clave ) {
	return isset( $GLOBALS['uhp_test_almacen'][ $clave ] ) ? $GLOBALS['uhp_test_almacen'][ $clave ] : false;
}

function set_transient( $clave, $valor, $ttl = 0 ) {
	$GLOBALS['uhp_test_almacen'][ $clave ] = $valor;
	return true;
}

function delete_transient( $clave ) {
	unset( $GLOBALS['uhp_test_almacen'][ $clave ] );
	return true;
}

function get_option( $clave, $defecto = false ) {
	return isset( $GLOBALS['uhp_test_almacen'][ 'opt_' . $clave ] )
		? $GLOBALS['uhp_test_almacen'][ 'opt_' . $clave ]
		: $defecto;
}

function update_option( $clave, $valor, $autoload = null ) {
	$GLOBALS['uhp_test_almacen'][ 'opt_' . $clave ] = $valor;
	return true;
}

function add_option( $clave, $valor, $sin_uso = '', $autoload = null ) {
	if ( ! isset( $GLOBALS['uhp_test_almacen'][ 'opt_' . $clave ] ) ) {
		$GLOBALS['uhp_test_almacen'][ 'opt_' . $clave ] = $valor;
	}
	return true;
}

function delete_option( $clave ) {
	unset( $GLOBALS['uhp_test_almacen'][ 'opt_' . $clave ] );
	return true;
}

function wp_parse_args( $args, $defecto = array() ) {
	if ( ! is_array( $args ) ) {
		$args = array();
	}
	return array_merge( $defecto, $args );
}

function wp_list_pluck( $lista, $campo ) {
	$salida = array();
	foreach ( (array) $lista as $clave => $item ) {
		if ( is_array( $item ) && isset( $item[ $campo ] ) ) {
			$salida[ $clave ] = $item[ $campo ];
		} elseif ( is_object( $item ) && isset( $item->$campo ) ) {
			$salida[ $clave ] = $item->$campo;
		}
	}
	return $salida;
}

function wp_json_encode( $datos, $opciones = 0, $profundidad = 512 ) {
	return json_encode( $datos, $opciones, $profundidad );
}

function sanitize_key( $clave ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $clave ) );
}

function sanitize_text_field( $texto ) {
	$texto = (string) $texto;
	$texto = wp_strip_all_tags( $texto );
	$texto = preg_replace( '/[\r\n\t ]+/', ' ', $texto );
	return trim( $texto );
}

function wp_strip_all_tags( $texto ) {
	return strip_tags( (string) $texto );
}

function sanitize_file_name( $nombre ) {
	$nombre = (string) $nombre;
	$nombre = str_replace( array( '..', '/', '\\', "\0" ), '', $nombre );
	return preg_replace( '/[^A-Za-z0-9._\-]/', '', $nombre );
}

function sanitize_title( $texto ) {
	$texto = strtolower( (string) $texto );
	$texto = str_replace(
		array( 'á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ' ),
		array( 'a', 'e', 'i', 'o', 'u', 'u', 'n' ),
		$texto
	);
	$texto = preg_replace( '/[^a-z0-9]+/', '-', $texto );
	return trim( $texto, '-' );
}

function esc_url_raw( $url ) {
	return (string) $url;
}

function esc_html( $texto ) {
	return htmlspecialchars( (string) $texto, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $texto ) {
	return htmlspecialchars( (string) $texto, ENT_QUOTES, 'UTF-8' );
}

function esc_html__( $texto, $dominio = null ) {
	return esc_html( $texto );
}

function __( $texto, $dominio = null ) {
	return $texto;
}

function rest_url( $ruta = '' ) {
	return 'https://example.test/wp-json/' . ltrim( (string) $ruta, '/' );
}

/** @var array<string,callable[]> Filtros registrados por las pruebas. */
$GLOBALS['uhp_test_filtros'] = array();

function apply_filters( $etiqueta, $valor ) {
	$extra = array_slice( func_get_args(), 2 );
	if ( empty( $GLOBALS['uhp_test_filtros'][ $etiqueta ] ) ) {
		return $valor;
	}
	foreach ( $GLOBALS['uhp_test_filtros'][ $etiqueta ] as $fn ) {
		$valor = call_user_func_array( $fn, array_merge( array( $valor ), $extra ) );
	}
	return $valor;
}

function add_action() {}
function add_shortcode() {}

function add_filter( $etiqueta, $fn, $prioridad = 10, $args = 1 ) {
	$GLOBALS['uhp_test_filtros'][ $etiqueta ][] = $fn;
	return true;
}

function wp_mkdir_p( $ruta ) {
	return is_dir( $ruta ) || mkdir( $ruta, 0755, true );
}

function wp_delete_file( $ruta ) {
	if ( is_file( $ruta ) ) {
		unlink( $ruta );
	}
}

function wp_date( $formato, $marca = null ) {
	return gmdate( $formato, null === $marca ? time() : (int) $marca );
}

function size_format( $bytes, $decimales = 0 ) {
	$unidades = array( 'B', 'KB', 'MB', 'GB' );
	$bytes    = (float) $bytes;
	$i        = 0;
	while ( $bytes >= 1024 && $i < count( $unidades ) - 1 ) {
		$bytes /= 1024;
		$i++;
	}
	return round( $bytes, $decimales ) . ' ' . $unidades[ $i ];
}

function get_current_user_id() {
	return 1;
}

/**
 * Carga las clases del plugin que no dependen del ciclo de vida de WordPress.
 */
function uhp_cargar_clases_de_datos() {
	$base = UHP_DIR . 'includes/';
	require_once $base . 'class-uhp-security.php';
	require_once $base . 'class-uhp-assets.php';
	require_once $base . 'class-uhp-estilos.php';
	require_once $base . 'class-uhp-activator.php';
	require_once $base . 'data/class-uhp-datos.php';
	require_once $base . 'data/class-uhp-municipios.php';
	require_once $base . 'analysis/class-uhp-analisis.php';
	require_once $base . 'data/class-uhp-views.php';
	require_once $base . 'class-uhp-rest.php';
}

/* -------------------------------------------------------------------------
 * Sustitutos adicionales para renderizar los shortcodes fuera de WordPress.
 *
 * Permiten generar las páginas de prueba con el MISMO marcado que el plugin
 * publica en producción, de modo que Playwright ejercita el HTML real y no
 * una copia que podría quedar desfasada.
 * ---------------------------------------------------------------------- */

/*
 * Registro de recursos con resolución de dependencias.
 *
 * No es un adorno: las páginas de prueba se construyen a partir de lo que
 * los shortcodes encolan DE VERDAD, resolviendo el mismo grafo de
 * dependencias que resolvería WordPress. La primera versión de estas
 * pruebas listaba los scripts a mano y por eso no detectó que el tablero
 * usaba UHPMapa sin declarar uhp-mapa como dependencia: la página de
 * prueba lo cargaba aunque el plugin no lo pidiera.
 */
$GLOBALS['uhp_test_recursos'] = array(
	'script' => array(),
	'style'  => array(),
);
$GLOBALS['uhp_test_encolados'] = array();

function shortcode_atts( $pares, $atts, $shortcode = '' ) {
	$atts   = (array) $atts;
	$salida = array();
	foreach ( $pares as $nombre => $defecto ) {
		$salida[ $nombre ] = array_key_exists( $nombre, $atts ) ? $atts[ $nombre ] : $defecto;
	}
	return $salida;
}

function uhp_test_registrar( $tipo, $handle, $src, $deps ) {
	if ( isset( $GLOBALS['uhp_test_recursos'][ $tipo ][ $handle ] ) ) {
		return; // Ya registrado: gana el primero, como en WordPress.
	}
	$GLOBALS['uhp_test_recursos'][ $tipo ][ $handle ] = array(
		'src'  => (string) $src,
		'deps' => array_values( array_filter( (array) $deps ) ),
	);
}

function wp_register_script( $handle, $src = '', $deps = array(), $ver = false, $footer = false ) {
	uhp_test_registrar( 'script', $handle, $src, $deps );
}

function wp_register_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
	uhp_test_registrar( 'style', $handle, $src, $deps );
}

function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $footer = false ) {
	if ( '' !== $src ) {
		uhp_test_registrar( 'script', $handle, $src, $deps );
	}
	$GLOBALS['uhp_test_encolados'][ 'script:' . $handle ] = true;
}

function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
	if ( '' !== $src ) {
		uhp_test_registrar( 'style', $handle, $src, $deps );
	}
	$GLOBALS['uhp_test_encolados'][ 'style:' . $handle ] = true;
}

function wp_script_is( $handle, $estado = 'enqueued' ) {
	if ( 'registered' === $estado ) {
		return isset( $GLOBALS['uhp_test_recursos']['script'][ $handle ] );
	}
	return isset( $GLOBALS['uhp_test_encolados'][ 'script:' . $handle ] );
}

function wp_style_is( $handle, $estado = 'enqueued' ) {
	if ( 'registered' === $estado ) {
		return isset( $GLOBALS['uhp_test_recursos']['style'][ $handle ] );
	}
	return isset( $GLOBALS['uhp_test_encolados'][ 'style:' . $handle ] );
}

function wp_add_inline_script() {}
function wp_add_inline_style() {}

/**
 * Resuelve el grafo de dependencias y devuelve los handles en el orden en
 * que WordPress los imprimiría: cada dependencia antes de quien la pide.
 *
 * @param string $tipo 'script' o 'style'.
 * @return string[]
 */
function uhp_test_resolver( $tipo ) {
	$registro = $GLOBALS['uhp_test_recursos'][ $tipo ];
	$orden    = array();
	$visto    = array();

	$visitar = function ( $handle ) use ( &$visitar, $registro, &$orden, &$visto ) {
		if ( isset( $visto[ $handle ] ) ) {
			return;
		}
		$visto[ $handle ] = true;
		if ( ! isset( $registro[ $handle ] ) ) {
			// Dependencia no registrada: WordPress omitiría el recurso. Se
			// deja constancia para que la prueba pueda fallar por ello.
			$GLOBALS['uhp_test_faltantes'][] = $tipo . ':' . $handle;
			return;
		}
		foreach ( $registro[ $handle ]['deps'] as $dep ) {
			$visitar( $dep );
		}
		$orden[] = $handle;
	};

	foreach ( array_keys( $GLOBALS['uhp_test_encolados'] ) as $clave ) {
		list( $t, $handle ) = explode( ':', $clave, 2 );
		if ( $t === $tipo ) {
			$visitar( $handle );
		}
	}
	return $orden;
}

/** Reinicia lo encolado entre una página de prueba y la siguiente. */
function uhp_test_limpiar_encolados() {
	$GLOBALS['uhp_test_encolados'] = array();
	$GLOBALS['uhp_test_faltantes'] = array();
}

function esc_url( $url ) {
	return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
}

function esc_textarea( $texto ) {
	return htmlspecialchars( (string) $texto, ENT_QUOTES, 'UTF-8' );
}

function esc_html_e( $texto, $dominio = null ) {
	echo esc_html( $texto );
}

function esc_attr_e( $texto, $dominio = null ) {
	echo esc_attr( $texto );
}

function esc_attr__( $texto, $dominio = null ) {
	return esc_attr( $texto );
}

function _e( $texto, $dominio = null ) {
	echo esc_html( $texto );
}

function _n( $singular, $plural, $numero, $dominio = null ) {
	return 1 === (int) $numero ? $singular : $plural;
}

function selected( $seleccionado, $actual = true, $mostrar = true ) {
	$salida = ( (string) $seleccionado === (string) $actual ) ? ' selected="selected"' : '';
	if ( $mostrar ) {
		echo $salida; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	return $salida;
}

function checked( $marcado, $actual = true, $mostrar = true ) {
	$salida = ( (string) $marcado === (string) $actual ) ? ' checked="checked"' : '';
	if ( $mostrar ) {
		echo $salida; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	return $salida;
}

function disabled( $deshabilitado, $actual = true, $mostrar = true ) {
	$salida = ( (bool) $deshabilitado === (bool) $actual ) ? ' disabled="disabled"' : '';
	if ( $mostrar ) {
		echo $salida; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	return $salida;
}

/**
 * Carga además la clase de shortcodes.
 */
function uhp_cargar_shortcodes() {
	uhp_cargar_clases_de_datos();
	require_once UHP_DIR . 'includes/shortcodes/class-uhp-shortcodes.php';
}
