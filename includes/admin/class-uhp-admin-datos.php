<?php
/**
 * Módulo de actualización de los archivos JSON del proyecto.
 *
 * Es el subsistema con más superficie de riesgo del plugin —el único que
 * escribe en disco—, de modo que cada acción pasa por la misma puerta:
 *
 *   1. `UHP_Security::exigir_admin()`  → capacidad `manage_options` + nonce.
 *   2. Selección del archivo por CLAVE del registro, nunca por ruta: el
 *      nombre real lo pone UHP_Datos, así que un salto de directorio es
 *      imposible por construcción.
 *   3. Validación del contenido ANTES de tocar el disco (JSON bien formado,
 *      UTF-8, claves obligatorias del contrato, forma de las listas).
 *   4. Respaldo del archivo anterior y escritura atómica.
 *   5. Redirección con el resultado (patrón POST-Redirect-GET: recargar la
 *      página no repite la escritura).
 *
 * @package Urkunina5000
 */

namespace GobernacionNarino\Urkunina;

defined( 'ABSPATH' ) || exit;

final class UHP_Admin_Datos {

	/** Prefijo de las acciones admin-post. */
	const ACCION = 'uhp_datos_';

	/** Clave del transient donde viaja el resultado hasta la redirección. */
	const AVISO = 'uhp_aviso_';

	public function __construct() {
		add_action( 'admin_post_' . self::ACCION . 'guardar', array( $this, 'guardar' ) );
		add_action( 'admin_post_' . self::ACCION . 'subir', array( $this, 'subir' ) );
		add_action( 'admin_post_' . self::ACCION . 'restaurar', array( $this, 'restaurar' ) );
		add_action( 'admin_post_' . self::ACCION . 'respaldar', array( $this, 'respaldar' ) );
		add_action( 'admin_post_' . self::ACCION . 'manifiesto', array( $this, 'manifiesto' ) );
		add_action( 'admin_post_' . self::ACCION . 'purgar', array( $this, 'purgar' ) );
	}

	/* ----------------------------------------------------------------- */
	/* Acciones                                                          */
	/* ----------------------------------------------------------------- */

	/**
	 * Guarda el contenido editado en el área de texto del panel.
	 */
	public function guardar() {
		UHP_Security::exigir_admin();

		$clave = $this->clave_pedida();
		if ( '' === $clave ) {
			$this->volver( $clave, 'error', __( 'No se indicó qué archivo guardar.', 'urkunina-5000' ) );
		}

		// wp_unslash antes de decodificar: WordPress añade barras a $_POST y
		// sin quitarlas el JSON llegaría con \" y no parsearía.
		$crudo = isset( $_POST['contenido'] ) ? wp_unslash( $_POST['contenido'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Es JSON: se valida en UHP_Datos::guardar(), no se puede sanitize_text_field().

		$res = UHP_Datos::guardar( $clave, $crudo );
		$this->tras_escribir( $clave, $res );
	}

	/**
	 * Sustituye un archivo con uno subido desde el equipo del usuario.
	 */
	public function subir() {
		UHP_Security::exigir_admin();

		$clave = $this->clave_pedida();
		if ( '' === $clave ) {
			$this->volver( $clave, 'error', __( 'No se indicó qué archivo reemplazar.', 'urkunina-5000' ) );
		}

		if ( empty( $_FILES['archivo_json'] ) || ! isset( $_FILES['archivo_json']['tmp_name'] ) ) {
			$this->volver( $clave, 'error', __( 'No se recibió ningún archivo.', 'urkunina-5000' ) );
		}

		$error = isset( $_FILES['archivo_json']['error'] ) ? (int) $_FILES['archivo_json']['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_OK !== $error ) {
			$this->volver( $clave, 'error', $this->mensaje_error_subida( $error ) );
		}

		$tmp = sanitize_text_field( wp_unslash( $_FILES['archivo_json']['tmp_name'] ) );

		// is_uploaded_file es la única garantía de que la ruta viene del
		// mecanismo de subida de PHP y no de una ruta arbitraria del sistema.
		if ( ! is_uploaded_file( $tmp ) ) {
			$this->volver( $clave, 'error', __( 'El archivo recibido no procede de una subida válida.', 'urkunina-5000' ) );
		}

		$tamano = (int) filesize( $tmp );
		$tope   = UHP_Datos::tope_bytes( $clave );
		if ( $tamano > $tope ) {
			$this->volver(
				$clave,
				'error',
				sprintf(
					/* translators: 1: tamaño del archivo, 2: tamaño máximo. */
					__( 'El archivo pesa %1$s y el máximo admitido es %2$s.', 'urkunina-5000' ),
					size_format( $tamano ),
					size_format( $tope )
				)
			);
		}

		$crudo = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $crudo ) {
			$this->volver( $clave, 'error', __( 'No se pudo leer el archivo subido.', 'urkunina-5000' ) );
		}

		$res = UHP_Datos::guardar( $clave, $crudo );
		$this->tras_escribir( $clave, $res );
	}

	/**
	 * Restaura una copia de seguridad sobre el archivo vivo.
	 */
	public function restaurar() {
		UHP_Security::exigir_admin();

		$clave = $this->clave_pedida();
		if ( '' === $clave ) {
			$this->volver( $clave, 'error', __( 'No se indicó qué archivo restaurar.', 'urkunina-5000' ) );
		}

		$nombre = isset( $_POST['respaldo'] ) ? sanitize_file_name( wp_unslash( $_POST['respaldo'] ) ) : '';
		$res    = UHP_Datos::restaurar( $clave, $nombre );

		if ( $res['ok'] ) {
			$this->refrescar_caches( $clave );
		}
		$this->volver( $clave, $res['ok'] ? 'ok' : 'error', $res['mensaje'] );
	}

	/**
	 * Crea una copia de seguridad sin modificar el archivo.
	 */
	public function respaldar() {
		UHP_Security::exigir_admin();

		$clave = $this->clave_pedida();
		if ( '' === $clave ) {
			$this->volver( $clave, 'error', __( 'No se indicó qué archivo respaldar.', 'urkunina-5000' ) );
		}

		$nombre = UHP_Datos::respaldar( $clave );
		if ( '' === $nombre ) {
			$this->volver( $clave, 'error', __( 'No se pudo crear la copia de seguridad. Revise los permisos de /data/respaldos.', 'urkunina-5000' ) );
		}
		$this->volver( $clave, 'ok', __( 'Copia de seguridad creada.', 'urkunina-5000' ) );
	}

	/**
	 * Recalcula tamaños y hashes del manifiesto del conjunto.
	 */
	public function manifiesto() {
		UHP_Security::exigir_admin();

		$res = UHP_Datos::recalcular_manifiesto();
		if ( $res['ok'] ) {
			$this->refrescar_caches( 'manifiesto' );
		}
		$this->volver( 'manifiesto', $res['ok'] ? 'ok' : 'error', $res['mensaje'] );
	}

	/**
	 * Vacía la caché de datos del plugin.
	 */
	public function purgar() {
		UHP_Security::exigir_admin();

		UHP_Datos::purgar();
		UHP_Municipios::purgar();
		UHP_Subregiones::purgar();
		UHP_Topojson::purgar();
		$this->volver( '', 'ok', __( 'Caché de datos vaciada. Los componentes volverán a leer los archivos de /data.', 'urkunina-5000' ) );
	}

	/* ----------------------------------------------------------------- */
	/* Utilidades internas                                               */
	/* ----------------------------------------------------------------- */

	/**
	 * Clave de archivo solicitada, validada contra el registro.
	 *
	 * @return string Clave válida o ''.
	 */
	private function clave_pedida() {
		$clave = isset( $_REQUEST['archivo'] ) ? UHP_Security::clave( wp_unslash( $_REQUEST['archivo'] ) ) : '';
		$reg   = UHP_Datos::registro();
		return isset( $reg[ $clave ] ) ? $clave : '';
	}

	/**
	 * Cierra una escritura: refresca cachés y redirige con el resultado.
	 *
	 * @param string $clave Clave del archivo.
	 * @param array  $res   Resultado de UHP_Datos::guardar().
	 */
	private function tras_escribir( $clave, $res ) {
		if ( $res['ok'] ) {
			$this->refrescar_caches( $clave );
		}

		$mensaje = $res['mensaje'];
		if ( ! empty( $res['avisos'] ) ) {
			$mensaje .= ' ' . implode( ' ', $res['avisos'] );
		}

		$this->volver( $clave, $res['ok'] ? ( empty( $res['avisos'] ) ? 'ok' : 'aviso' ) : 'error', $mensaje );
	}

	/**
	 * Invalida las cachés que dependen de un archivo recién escrito.
	 *
	 * @param string $clave Clave del archivo.
	 */
	private function refrescar_caches( $clave ) {
		UHP_Datos::purgar( $clave );

		if ( 'geojson' === $clave || 'cobertura' === $clave ) {
			UHP_Municipios::purgar();
		}
		if ( 'geojson_subregiones' === $clave ) {
			UHP_Subregiones::purgar();
		}
		// Las dos topologías se derivan de la geometría y de la lista de
		// municipios priorizados: si cambia cualquiera de las tres, hay que
		// reconstruirlas o el mapa seguiría sirviendo la anterior durante
		// doce horas.
		if ( in_array( $clave, array( 'geojson', 'geojson_subregiones', 'cobertura' ), true ) ) {
			UHP_Topojson::purgar();
		}
	}

	/**
	 * Guarda el resultado y vuelve a la página de datos (POST-Redirect-GET).
	 *
	 * @param string $clave   Archivo sobre el que se actuó.
	 * @param string $tipo    ok | aviso | error.
	 * @param string $mensaje Texto del resultado.
	 */
	private function volver( $clave, $tipo, $mensaje ) {
		set_transient(
			self::AVISO . get_current_user_id(),
			array(
				'tipo'    => $tipo,
				'mensaje' => $mensaje,
			),
			60
		);

		$url = add_query_arg(
			array(
				'page' => 'uhp-datos',
				'tab'  => '' !== $clave ? $clave : 'resumen',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Traduce el código de error de subida de PHP a un mensaje útil.
	 *
	 * @param int $codigo Constante UPLOAD_ERR_*.
	 * @return string
	 */
	private function mensaje_error_subida( $codigo ) {
		switch ( $codigo ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return __( 'El archivo supera el tamaño máximo de subida del servidor.', 'urkunina-5000' );
			case UPLOAD_ERR_PARTIAL:
				return __( 'La subida se interrumpió antes de completarse. Vuelva a intentarlo.', 'urkunina-5000' );
			case UPLOAD_ERR_NO_FILE:
				return __( 'No se seleccionó ningún archivo.', 'urkunina-5000' );
			case UPLOAD_ERR_NO_TMP_DIR:
			case UPLOAD_ERR_CANT_WRITE:
				return __( 'El servidor no pudo escribir el archivo temporal. Contacte al administrador del hosting.', 'urkunina-5000' );
			default:
				return __( 'No se pudo procesar el archivo subido.', 'urkunina-5000' );
		}
	}

	/**
	 * Recupera y consume el aviso pendiente del usuario actual.
	 *
	 * @return array|null
	 */
	public static function aviso_pendiente() {
		$clave = self::AVISO . get_current_user_id();
		$aviso = get_transient( $clave );
		if ( ! is_array( $aviso ) ) {
			return null;
		}
		delete_transient( $clave );
		return $aviso;
	}

	/**
	 * URL de una acción del módulo, con su nonce.
	 *
	 * @param string $accion  Sufijo de la acción.
	 * @param string $archivo Clave del archivo (opcional).
	 * @return string
	 */
	public static function url( $accion, $archivo = '' ) {
		$args = array( 'action' => self::ACCION . $accion );
		if ( '' !== $archivo ) {
			$args['archivo'] = $archivo;
		}
		return wp_nonce_url(
			add_query_arg( $args, admin_url( 'admin-post.php' ) ),
			UHP_Security::NONCE,
			'_uhp_nonce'
		);
	}
}
