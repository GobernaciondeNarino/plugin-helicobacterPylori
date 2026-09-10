<?php
/**
 * Seguridad transversal del plugin.
 *
 * Sanitización de entradas, validación de municipios contra lista blanca,
 * bounding-box de Nariño, rate-limiting por IP para la REST pública y
 * utilidades de escritura segura de archivos de datos.
 *
 * Principio rector: el plugin es público y sirve datos de salud agregados;
 * toda entrada es no confiable y toda salida se escapa.
 *
 * @package Urkunina5000
 */

namespace GobernacionNarino\Urkunina;

defined( 'ABSPATH' ) || exit;

final class UHP_Security {

	/** Capacidad exigida para todo el módulo de administración. */
	const CAP = 'manage_options';

	/** Acción de nonce compartida por los formularios del panel. */
	const NONCE = 'uhp_admin';

	/** Recuadro válido de Nariño (validación de coordenadas del mapa). */
	const BBOX = array(
		'latMin' => 0.35,
		'latMax' => 2.70,
		'lonMin' => -79.10,
		'lonMax' => -76.85,
	);

	/** Tamaño máximo aceptado al subir un archivo de datos (2 MiB). */
	const MAX_JSON_BYTES = 2097152;

	/**
	 * Tamaño máximo de un archivo de geometría (8 MiB).
	 *
	 * Los catorce archivos de cifras del proyecto no pasan de unos pocos
	 * kilobytes y el tope de 2 MiB les sobra. La cartografía es otra cosa:
	 * el archivo de subregiones ocupa 3 MiB solo en vértices. Se le da su
	 * propio tope, generoso pero acotado, en vez de aflojar el de todos:
	 * un límite laxo en los archivos de cifras sería una puerta abierta a
	 * agotar la memoria del sitio con un JSON enorme.
	 */
	const MAX_GEO_BYTES = 8388608;

	/* ----------------------------------------------------------------- */
	/* Autorización                                                       */
	/* ----------------------------------------------------------------- */

	/**
	 * Exige capacidad y nonce válido; termina la petición si algo falla.
	 *
	 * Se usa al principio de cada handler de admin-post/AJAX. No devuelve
	 * valor: o pasa, o corta la ejecución con un error de WordPress.
	 *
	 * @param string $campo Nombre del campo POST/GET que lleva el nonce.
	 * @param string $accion Acción del nonce (por defecto self::NONCE).
	 */
	public static function exigir_admin( $campo = '_uhp_nonce', $accion = self::NONCE ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die(
				esc_html__( 'No tiene permisos para realizar esta acción.', 'urkunina-5000' ),
				esc_html__( 'Permiso denegado', 'urkunina-5000' ),
				array( 'response' => 403 )
			);
		}

		$nonce = isset( $_REQUEST[ $campo ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $campo ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, $accion ) ) {
			wp_die(
				esc_html__( 'El enlace de seguridad caducó. Vuelva a cargar la página e inténtelo de nuevo.', 'urkunina-5000' ),
				esc_html__( 'Verificación fallida', 'urkunina-5000' ),
				array( 'response' => 403 )
			);
		}
	}

	/* ----------------------------------------------------------------- */
	/* Sanitización de entradas                                          */
	/* ----------------------------------------------------------------- */

	/**
	 * Normaliza un identificador de vista/clave: minúsculas, [a-z0-9_-].
	 *
	 * @param string $valor Valor crudo.
	 * @return string
	 */
	public static function clave( $valor ) {
		$valor = sanitize_key( (string) $valor );
		return preg_replace( '/[^a-z0-9_\-]/', '', $valor );
	}

	/**
	 * Normaliza un municipio al código DIVIPOLA de 5 dígitos o 'departamento'.
	 * Acepta código o nombre; valida contra la lista blanca del geojson.
	 *
	 * @param string $valor Código o nombre.
	 * @return string DIVIPOLA de 5 dígitos o 'departamento'.
	 */
	public static function sanitizar_divipola( $valor ) {
		$valor = sanitize_text_field( (string) $valor );

		if ( '' === $valor || 0 === strcasecmp( $valor, 'departamento' ) ) {
			return 'departamento';
		}

		if ( preg_match( '/^\d{5}$/', $valor ) ) {
			return UHP_Municipios::existe( $valor ) ? $valor : 'departamento';
		}

		$mun = UHP_Municipios::por_nombre( $valor );
		return $mun ? $mun['divipola'] : 'departamento';
	}

	/**
	 * Sanea un nombre de archivo de datos contra la lista blanca del registro.
	 *
	 * Nunca construya una ruta con entrada del usuario sin pasar por aquí: el
	 * archivo debe existir en el registro de UHP_Datos, lo que hace imposible
	 * un salto de directorio (../) por definición.
	 *
	 * @param string $valor Nombre de archivo propuesto.
	 * @return string Nombre válido o '' si no está en la lista blanca.
	 */
	public static function sanitizar_archivo_datos( $valor ) {
		$valor = sanitize_file_name( (string) $valor );
		return UHP_Datos::es_archivo_valido( $valor ) ? $valor : '';
	}

	/**
	 * Valida que un par lat/lon caiga dentro del bounding-box de Nariño.
	 *
	 * @param float $lat Latitud.
	 * @param float $lon Longitud.
	 * @return bool
	 */
	public static function validar_bbox( $lat, $lon ) {
		$lat = (float) $lat;
		$lon = (float) $lon;
		return $lat >= self::BBOX['latMin'] && $lat <= self::BBOX['latMax']
			&& $lon >= self::BBOX['lonMin'] && $lon <= self::BBOX['lonMax'];
	}

	/* ----------------------------------------------------------------- */
	/* Rate-limiting de la REST pública                                  */
	/* ----------------------------------------------------------------- */

	/**
	 * Limita peticiones por IP usando un contador en transient.
	 *
	 * La ventana es FIJA y va codificada en la clave: con ventana deslizante,
	 * cada petición permitida renovaría el TTL y el contador no expiraría
	 * nunca, bloqueando a un cliente legítimo que sondee sin pausa.
	 *
	 * @param string $clave_base Identificador del recurso protegido.
	 * @param int    $max        Máximo de peticiones por ventana.
	 * @param int    $ventana    Tamaño de la ventana en segundos.
	 * @return bool True si se permite; false si se excedió el límite.
	 */
	public static function rate_limit( $clave_base, $max = 90, $ventana = 60 ) {
		$ventana = max( 1, (int) $ventana );
		$ip      = self::ip_cliente();

		$slot  = (int) floor( time() / $ventana );
		$clave = 'uhp_rl_' . md5( $clave_base . '|' . $ip . '|' . $slot );

		$n = (int) get_transient( $clave );
		if ( $n >= (int) $max ) {
			return false;
		}
		// TTL doble: cubre el desfase entre slots sin estirar la ventana.
		set_transient( $clave, $n + 1, $ventana * 2 );
		return true;
	}

	/**
	 * Obtiene la IP del cliente de forma segura.
	 *
	 * Se usa REMOTE_ADDR porque las cabeceras X-Forwarded-* son falsificables.
	 * Tras un proxy o CDN de confianza todos los visitantes comparten IP y
	 * agotarían el mismo cupo: en ese caso resuelva la IP real con el filtro
	 * `uhp_ip_cliente`, que debe devolver una IP ya validada.
	 *
	 * @return string
	 */
	public static function ip_cliente() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
		$ip = filter_var( $ip, \FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';

		/**
		 * Filtra la IP usada para el rate-limit (entornos con proxy inverso).
		 *
		 * @param string $ip IP validada de REMOTE_ADDR.
		 */
		$filtrada = apply_filters( 'uhp_ip_cliente', $ip );
		return ( is_string( $filtrada ) && filter_var( $filtrada, \FILTER_VALIDATE_IP ) ) ? $filtrada : $ip;
	}

	/* ----------------------------------------------------------------- */
	/* Escritura segura de archivos de datos                             */
	/* ----------------------------------------------------------------- */

	/**
	 * Escribe contenido en una ruta de forma atómica (temporal + rename).
	 *
	 * Un fallo a mitad de escritura dejaría un JSON truncado que rompería
	 * todos los shortcodes; con rename() el reemplazo es atómico en POSIX.
	 *
	 * @param string $ruta      Ruta absoluta de destino.
	 * @param string $contenido Contenido a escribir.
	 * @return bool True si se escribió.
	 */
	public static function escribir_atomico( $ruta, $contenido ) {
		$dir = dirname( $ruta );
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return false;
		}

		$tmp = tempnam( $dir, '.uhp' );
		if ( false === $tmp ) {
			return false;
		}

		$bytes = file_put_contents( $tmp, $contenido, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $bytes ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return false;
		}

		// tempnam() crea con 0600; los archivos de datos deben ser legibles
		// por el servidor web para servirse desde la REST.
		@chmod( $tmp, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( ! @rename( $tmp, $ruta ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return false;
		}
		return true;
	}

	/**
	 * Comprueba que una ruta resuelta quede dentro de un directorio base.
	 *
	 * Defensa en profundidad frente a saltos de directorio: se comparan las
	 * rutas ya canonicalizadas con realpath().
	 *
	 * @param string $ruta Ruta a comprobar.
	 * @param string $base Directorio que debe contenerla.
	 * @return bool
	 */
	public static function dentro_de( $ruta, $base ) {
		$rbase = realpath( $base );
		if ( false === $rbase ) {
			return false;
		}
		// El archivo puede no existir aún (creación): se valida su directorio.
		$rruta = realpath( $ruta );
		if ( false === $rruta ) {
			$rruta = realpath( dirname( $ruta ) );
			if ( false === $rruta ) {
				return false;
			}
			$rruta .= DIRECTORY_SEPARATOR . basename( $ruta );
		}

		$rbase = rtrim( $rbase, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		return 0 === strpos( $rruta, $rbase );
	}
}
