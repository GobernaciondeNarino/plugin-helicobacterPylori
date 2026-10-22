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

/* FALSA A PROPÓSITO. Aquí no se encola ningún recurso y el número no
   significa nada; dejarlo con forma de versión real invitaba a
   sincronizarlo con la del plugin, que es justo lo que no hay que hacer.
   Una prueba comprueba que sigue siendo esta. */
define( 'UHP_VERSION', '0.0.0-test' );
define( 'UHP_FILE', dirname( __DIR__ ) . '/urkunina-5000.php' );
define( 'UHP_DIR', dirname( __DIR__ ) . '/' );
/* Rutas RELATIVAS a propósito: las páginas de prueba las sirve el
   servidor local de tests/servidor.js desde su propia raíz, y el plugin
   imprime esta misma URL en window.UHP. Con un dominio inventado aquí, el
   navegador pediría los recursos y la API a example.test y la suite
   probaría una página que no carga nada. */
define( 'UHP_URL', '/' );
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

function remove_accents( $texto ) {
	return strtr(
		(string) $texto,
		array(
			'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
			'ü' => 'u', 'ñ' => 'n', 'Á' => 'A', 'É' => 'E', 'Í' => 'I',
			'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
		)
	);
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

function wp_parse_url( $url, $componente = -1 ) {
	return parse_url( (string) $url, $componente );
}

function wp_using_ext_object_cache() {
	return false;
}

function rest_url( $ruta = '' ) {
	return '/wp-json/' . ltrim( (string) $ruta, '/' );
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

/* El contenido de la «entrada» que se está mostrando. El generador de
   páginas lo fija antes de llamar a adelantar_hojas(), de modo que
   has_shortcode() vea lo mismo que vería WordPress. */
$GLOBALS['uhp_test_contenido'] = '';

function is_singular() {
	return '' !== $GLOBALS['uhp_test_contenido'];
}

function get_post() {
	if ( '' === $GLOBALS['uhp_test_contenido'] ) {
		return null;
	}
	return (object) array( 'post_content' => $GLOBALS['uhp_test_contenido'] );
}

function has_shortcode( $contenido, $tag ) {
	// Basta con buscar «[tag» seguido de espacio o cierre: es lo que
	// distingue [urkunina_grafico] de [urkunina_graficos_otro].
	return (bool) preg_match( '/\[' . preg_quote( (string) $tag, '/' ) . '[\s\]\/]/', (string) $contenido );
}
function add_shortcode() {}

function add_filter( $etiqueta, $fn, $prioridad = 10, $args = 1 ) {
	$GLOBALS['uhp_test_filtros'][ $etiqueta ][] = $fn;
	return true;
}

function remove_filter( $etiqueta, $fn, $prioridad = 10 ) {
	if ( ! isset( $GLOBALS['uhp_test_filtros'][ $etiqueta ] ) ) {
		return false;
	}
	foreach ( $GLOBALS['uhp_test_filtros'][ $etiqueta ] as $i => $registrado ) {
		if ( $registrado === $fn ) {
			unset( $GLOBALS['uhp_test_filtros'][ $etiqueta ][ $i ] );
			return true;
		}
	}
	return false;
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

function number_format_i18n( $numero, $decimales = 0 ) {
	// es_CO: punto para los miles, coma para los decimales.
	return number_format( (float) $numero, (int) $decimales, ',', '.' );
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
	require_once $base . 'data/class-uhp-subregiones.php';
	require_once $base . 'data/class-uhp-topojson.php';
	require_once $base . 'analysis/class-uhp-analisis.php';
	require_once $base . 'data/class-uhp-views.php';
	require_once $base . 'data/class-uhp-territorios.php';
	require_once $base . 'class-uhp-llm.php';
	require_once $base . 'class-uhp-rest.php';
	require_once $base . 'class-uhp-voz.php';
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

/**
 * Guarda el script en línea asociado a un handle.
 *
 * Era un no-op, y por eso el generador de páginas no podía emitirlo: las
 * páginas de prueba llevaban a mano un `window.UHP` escrito aparte y todo
 * lo que el plugin añade por esta vía —la configuración del objeto 3D, la
 * del asistente de voz— quedaba fuera de la suite. Un módulo que se apaga
 * en su primera línea por falta de configuración habría pasado las
 * pruebas sin ejecutar nada.
 *
 * @param string $handle   Handle al que se engancha.
 * @param string $data     Código.
 * @param string $posicion 'before' o 'after'.
 */
function wp_add_inline_script( $handle, $data = '', $posicion = 'after' ) {
	$p = ( 'before' === $posicion ) ? 'before' : 'after';
	if ( ! isset( $GLOBALS['uhp_test_inline'] ) ) {
		$GLOBALS['uhp_test_inline'] = array( 'before' => array(), 'after' => array() );
	}
	if ( ! isset( $GLOBALS['uhp_test_inline'][ $p ][ $handle ] ) ) {
		$GLOBALS['uhp_test_inline'][ $p ][ $handle ] = array();
	}
	$GLOBALS['uhp_test_inline'][ $p ][ $handle ][] = (string) $data;
}

/**
 * Código en línea de un handle, en una posición.
 *
 * @param string $handle   Handle.
 * @param string $posicion 'before' o 'after'.
 * @return string
 */
function uhp_test_inline( $handle, $posicion ) {
	$reg = isset( $GLOBALS['uhp_test_inline'][ $posicion ][ $handle ] )
		? $GLOBALS['uhp_test_inline'][ $posicion ][ $handle ]
		: array();
	return implode( "\n", $reg );
}
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
	/* Los bloques en línea vuelven a su LÍNEA BASE, que no es «vacío».

	   En WordPress, `UHP_Assets::registrar()` corre en cada petición y es
	   quien engancha `window.UHP` al núcleo. El generador la llama una
	   sola vez, antes de pintar nada, de modo que ese bloque pertenece a
	   todas las páginas y no a la primera. Vaciar el registro a secas se lo
	   llevaba: las páginas salían completas, con su script y su marcado, y
	   sin la configuración del núcleo —que es de donde sale la base de la
	   API—, así que el tablero pedía «/tablero» en vez de
	   «/wp-json/urkunina/v1/tablero» y se quedaba sin datos.

	   Lo que sí hay que limpiar es lo que añade CADA página, como la
	   configuración del asistente: sin eso, la de la primera se reemitía
	   en todas las siguientes. */
	$GLOBALS['uhp_test_inline'] = isset( $GLOBALS['uhp_test_inline_base'] )
		? $GLOBALS['uhp_test_inline_base']
		: array(
			'before' => array(),
			'after'  => array(),
		);
}

/**
 * Fija la línea base de bloques en línea: lo que engancha el registro de
 * assets, que en WordPress ocurre en cada petición.
 */
function uhp_test_fijar_inline_base() {
	$GLOBALS['uhp_test_inline_base'] = isset( $GLOBALS['uhp_test_inline'] )
		? $GLOBALS['uhp_test_inline']
		: array(
			'before' => array(),
			'after'  => array(),
		);
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

/* ------------------------------------------------------------------ */
/* API HTTP                                                            */
/* ------------------------------------------------------------------ */

/* La suite NO sale a internet: la respuesta la pone la prueba en
   $GLOBALS['uhp_test_http'], una cola que se consume en orden. Verificar
   el agente contra ElevenLabs de verdad haría que la suite dependiera de
   una cuenta, de una clave y de la red, y además gastaría cuota ajena. */

class WP_Error {

	/** @var string */
	public $codigo;
	/** @var string */
	public $mensaje;
	/** @var array */
	public $datos;

	public function __construct( $codigo = '', $mensaje = '', $datos = array() ) {
		$this->codigo  = (string) $codigo;
		$this->mensaje = (string) $mensaje;
		$this->datos   = $datos;
	}

	public function get_error_code() {
		return $this->codigo;
	}

	public function get_error_message() {
		return $this->mensaje;
	}

	public function get_error_data() {
		return $this->datos;
	}
}

function untrailingslashit( $cadena ) {
	return rtrim( (string) $cadena, '/\\' );
}

function trailingslashit( $cadena ) {
	return untrailingslashit( $cadena ) . '/';
}

function wp_create_nonce( $accion = -1 ) {
	// Suficiente para la suite: lo que se comprueba es QUE se emite y
	// cuándo, no su criptografía.
	return 'nonce_' . substr( md5( (string) $accion ), 0, 10 );
}

function is_wp_error( $cosa ) {
	return $cosa instanceof WP_Error;
}

function uhp_test_http( $respuestas ) {
	$GLOBALS['uhp_test_http'] = $respuestas;
	$GLOBALS['uhp_test_http_peticiones'] = array();
}

function uhp_test_http_peticiones() {
	return isset( $GLOBALS['uhp_test_http_peticiones'] ) ? $GLOBALS['uhp_test_http_peticiones'] : array();
}

function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['uhp_test_http_peticiones'][] = array(
		'url'  => $url,
		'args' => $args,
	);
	if ( empty( $GLOBALS['uhp_test_http'] ) ) {
		return new WP_Error( 'sin_doble', 'La prueba no preparó ninguna respuesta HTTP.' );
	}
	return array_shift( $GLOBALS['uhp_test_http'] );
}

/* Las dos salidas comparten la MISMA cola, y a propósito: una
   verificación completa hace una petición al modelo de lenguaje (POST) y
   otra a ElevenLabs (GET), en ese orden, y la prueba que las encole al
   revés debe fallar. */
function wp_remote_post( $url, $args = array() ) {
	$GLOBALS['uhp_test_http_peticiones'][] = array(
		'url'  => $url,
		'args' => $args,
	);
	if ( empty( $GLOBALS['uhp_test_http'] ) ) {
		return new WP_Error( 'sin_doble', 'La prueba no preparó ninguna respuesta HTTP.' );
	}
	return array_shift( $GLOBALS['uhp_test_http'] );
}

function wp_remote_retrieve_response_code( $r ) {
	if ( is_wp_error( $r ) || ! is_array( $r ) ) {
		return 0;
	}
	return isset( $r['response']['code'] ) ? (int) $r['response']['code'] : 0;
}

function wp_remote_retrieve_body( $r ) {
	if ( is_wp_error( $r ) || ! is_array( $r ) ) {
		return '';
	}
	return isset( $r['body'] ) ? (string) $r['body'] : '';
}

/**
 * Carga además la clase de shortcodes.
 */
function uhp_cargar_shortcodes() {
	uhp_cargar_clases_de_datos();
	require_once UHP_DIR . 'includes/shortcodes/class-uhp-shortcodes.php';
}
