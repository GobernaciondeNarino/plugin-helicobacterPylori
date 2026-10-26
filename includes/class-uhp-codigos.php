<?php
/**
 * Los códigos de error del asistente, del lado del servidor.
 *
 * POR QUÉ HAY CÓDIGOS
 *
 * «No pude consultar en este momento» es lo correcto para el visitante y
 * no le sirve de nada a quien tiene que arreglarlo. Hasta la 2.0 cada
 * fallo dejaba una frase libre en el registro de PHP y otra distinta en
 * la consola del navegador, y las dos había que interpretarlas. Ahora
 * cada fallo tiene un código fijo, `UHP-<ÁREA>-<NN>`, que es el MISMO en
 * el registro, en la consola, en el panel y en la pantalla del
 * visitante. Una captura del celular de un ciudadano basta para saber
 * dónde mirar.
 *
 *  - El ÁREA dice dónde mirar y el NÚMERO dice qué pasó. En LLM y VOZ los
 *    números del 01 al 10 significan lo mismo: quien aprende uno sabe el
 *    otro.
 *  - Un número no se reutiliza nunca ni cambia de sentido. Un código que
 *    deja de emitirse se queda en el catálogo marcado como retirado.
 *  - El código NO nombra al proveedor ni al modelo: viaja al navegador, y
 *    `config_front()` oculta a propósito con qué se habla.
 *
 * Los códigos que solo detecta el navegador viven en su propio catálogo,
 * al principio de assets/js/uhp-voz.js. La tabla completa, de los dos
 * lados, está en docs/voz/codigos.md, que se genera con
 * `php tests/generar-codigos.php`.
 *
 * LO QUE NUNCA VIAJA AL NAVEGADOR
 *
 * Ni claves, ni el cuerpo del proveedor, ni el nombre del proveedor o
 * del modelo, ni la pregunta del visitante, ni cifras del cupo, ni la IP.
 * Viajan el código, su frase fija y el `ref`, que une la captura con la
 * línea exacta del registro de PHP.
 *
 * @package Urkunina5000
 */

namespace GobernacionNarino\Urkunina;

defined( 'ABSPATH' ) || exit;

/**
 * Catálogo, registro y clasificación de fallos.
 */
final class UHP_Codigos {

	/**
	 * Los códigos que emite el servidor: nivel y frase.
	 *
	 * La frase es FIJA: sin variables, en minúscula, sin punto final y sin
	 * nombres de proveedor. Se busca igual que el código. El nivel es el
	 * de la consola del navegador: `error` solo cuando alguien de la
	 * entidad tiene que actuar.
	 */
	const CATALOGO = array(
		// Configuración.
		'UHP-CFG-04' => array( 'warn', 'la página ofrece voz pero el servidor ya no la tiene configurada' ),
		'UHP-CFG-05' => array( 'error', 'una constante de clave en wp-config.php no es una cadena' ),
		'UHP-CFG-07' => array( 'warn', 'la voz está a medio configurar: falta la clave o el identificador' ),
		'UHP-CFG-09' => array( 'error', 'el filtro uhp_voz_api_base devolvió algo inválido' ),
		'UHP-CFG-10' => array( 'info', 'resultado de la verificación de las claves' ),

		// Las puertas de las dos rutas.
		'UHP-ACC-01' => array( 'error', 'el asistente está apagado o sin clave del modelo' ),
		'UHP-ACC-02' => array( 'error', 'el asistente está en ensayo y la petición llegó sin sesión' ),
		'UHP-ACC-04' => array( 'error', 'la petición no trae un origen de este sitio' ),
		'UHP-ACC-05' => array( 'warn', 'se alcanzó el límite de peticiones por dirección IP' ),
		'UHP-ACC-06' => array( 'warn', 'se agotó el cupo diario de consultas' ),
		'UHP-ACC-07' => array( 'warn', 'se agotó el cupo diario de lecturas en voz' ),

		// La conversación.
		'UHP-CNV-05' => array( 'error', 'no llegó ninguna conversación utilizable' ),
		'UHP-CNV-06' => array( 'warn', 'la conversación es demasiado larga' ),
		'UHP-CNV-07' => array( 'warn', 'hubo que descartar o completar herramientas del historial' ),
		'UHP-CNV-08' => array( 'info', 'el historial se recortó a los últimos mensajes' ),

		// Servidor ↔ proveedor de lenguaje.
		'UHP-LLM-01' => array( 'error', 'el servidor no pudo conectar con el proveedor de lenguaje' ),
		'UHP-LLM-02' => array( 'error', 'el proveedor de lenguaje no contestó a tiempo' ),
		'UHP-LLM-03' => array( 'error', 'el proveedor de lenguaje rechazó la clave' ),
		'UHP-LLM-04' => array( 'error', 'a la clave del proveedor de lenguaje le falta un permiso' ),
		'UHP-LLM-05' => array( 'error', 'el modelo de lenguaje no existe o la cuenta no tiene acceso' ),
		'UHP-LLM-06' => array( 'error', 'el proveedor de lenguaje rechazó la petición o un parámetro' ),
		'UHP-LLM-07' => array( 'error', 'la cuenta del proveedor de lenguaje no tiene saldo' ),
		'UHP-LLM-08' => array( 'warn', 'la cuenta del proveedor de lenguaje llegó a su límite de ritmo' ),
		'UHP-LLM-09' => array( 'warn', 'fallo interno o sobrecarga del proveedor de lenguaje' ),
		'UHP-LLM-10' => array( 'error', 'el proveedor de lenguaje contestó algo que no es JSON' ),
		'UHP-LLM-11' => array( 'warn', 'el proveedor de lenguaje declinó responder' ),
		'UHP-LLM-12' => array( 'warn', 'el proveedor de lenguaje contestó sin texto ni herramientas' ),
		'UHP-LLM-13' => array( 'warn', 'la respuesta se cortó en el tope de salida' ),
		'UHP-LLM-14' => array( 'warn', 'el modelo pidió una herramienta con argumentos ilegibles' ),
		'UHP-LLM-99' => array( 'error', 'fallo del proveedor de lenguaje sin clasificar' ),

		// Servidor ↔ síntesis de voz.
		'UHP-VOZ-01' => array( 'error', 'el servidor no pudo conectar con la síntesis de voz' ),
		'UHP-VOZ-02' => array( 'warn', 'la síntesis de voz no contestó a tiempo' ),
		'UHP-VOZ-03' => array( 'error', 'la síntesis de voz rechazó la clave' ),
		'UHP-VOZ-04' => array( 'error', 'a la clave de la síntesis de voz le falta un permiso' ),
		'UHP-VOZ-05' => array( 'error', 'la voz configurada no existe en la cuenta' ),
		'UHP-VOZ-06' => array( 'error', 'la síntesis rechazó el modelo o un parámetro' ),
		'UHP-VOZ-07' => array( 'error', 'la cuenta de voz se quedó sin caracteres o sin saldo' ),
		'UHP-VOZ-08' => array( 'warn', 'demasiadas síntesis a la vez para el plan de voz' ),
		'UHP-VOZ-09' => array( 'warn', 'fallo interno de la síntesis de voz' ),
		'UHP-VOZ-10' => array( 'error', 'la síntesis contestó sin audio' ),
		'UHP-VOZ-11' => array( 'warn', 'no queda texto que leer después de limpiarlo' ),
		'UHP-VOZ-12' => array( 'info', 'la respuesta se recortó para leerla en voz alta' ),
		'UHP-VOZ-13' => array( 'warn', 'el listado de voces volvió con una forma inesperada' ),
		'UHP-VOZ-99' => array( 'error', 'fallo de la síntesis de voz sin clasificar' ),
	);

	/**
	 * Lo que se le dice al ADMINISTRADOR en el panel, por número.
	 *
	 * Vale para LLM y para VOZ porque los números significan lo mismo.
	 * Va antes de lo que contestó el proveedor, nunca en su lugar.
	 *
	 * @param string $codigo Código UHP.
	 * @return string
	 */
	public static function para_el_panel( $codigo ) {
		switch ( substr( (string) $codigo, -2 ) ) {
			case '01':
				return __( 'este servidor no pudo abrir la conexión (cortafuegos, DNS o certificado)', 'urkunina-5000' );
			case '02':
				return __( 'el proveedor tardó demasiado en contestar (tiempo de espera agotado)', 'urkunina-5000' );
			case '03':
				return __( 'la clave no es válida: está mal copiada, revocada o es de otro proveedor', 'urkunina-5000' );
			case '04':
				return __( 'la clave no es válida para esto: le falta un permiso, o la región o el proyecto no están habilitados', 'urkunina-5000' );
			case '05':
				return __( 'el modelo o la voz no existen con ese nombre, o la cuenta no tiene acceso', 'urkunina-5000' );
			case '06':
				return __( 'el proveedor rechazó la petición', 'urkunina-5000' );
			case '07':
				return __( 'la cuenta no tiene saldo o agotó su cuota', 'urkunina-5000' );
			case '08':
				return __( 'la cuenta llegó a su límite de peticiones', 'urkunina-5000' );
			case '09':
				return __( 'el proveedor tuvo un fallo interno; no es un problema de esta configuración', 'urkunina-5000' );
			case '10':
				return __( 'el proveedor contestó algo que no es una respuesta válida (suele ser un proxy o un cortafuegos en medio)', 'urkunina-5000' );
			default:
				return '';
		}
	}

	/**
	 * La frase fija de un código.
	 *
	 * @param string $codigo Código UHP.
	 * @return string
	 */
	public static function frase( $codigo ) {
		return isset( self::CATALOGO[ $codigo ] ) ? self::CATALOGO[ $codigo ][1] : 'código sin catalogar';
	}

	/**
	 * El nivel de un código: error, warn o info.
	 *
	 * @param string $codigo Código UHP.
	 * @return string
	 */
	public static function nivel( $codigo ) {
		return isset( self::CATALOGO[ $codigo ] ) ? self::CATALOGO[ $codigo ][0] : 'error';
	}

	/**
	 * Un WP_Error que lleva su código UHP.
	 *
	 * El `code` de WordPress NO cambia —es contrato con el navegador y con
	 * la documentación—: el código UHP va encima, en `data.uhp`, como
	 * granularidad.
	 *
	 * @param string $codigo  Código UHP.
	 * @param string $wp      Código de WordPress (uhp_voz_*).
	 * @param string $mensaje Mensaje para el visitante.
	 * @param int    $status  HTTP.
	 * @param string $donde   Contexto para el registro (voz/chat, voz/hablar…).
	 * @param string $detalle Lo que va al registro y NUNCA al navegador.
	 * @return \WP_Error
	 */
	public static function error( $codigo, $wp, $mensaje, $status, $donde, $detalle = '' ) {
		return new \WP_Error(
			$wp,
			$mensaje,
			array(
				'status' => (int) $status,
				'uhp'    => array(
					'codigo' => $codigo,
					'frase'  => self::frase( $codigo ),
					'nivel'  => self::nivel( $codigo ),
					'ref'    => self::anotar( $codigo, $donde, $detalle ),
				),
			)
		);
	}

	/**
	 * Un aviso que viaja DENTRO de una respuesta 200.
	 *
	 * Para lo que no corta la conversación pero conviene que se sepa: una
	 * respuesta recortada, un historial podado.
	 *
	 * @param string $codigo Código UHP.
	 * @return array{codigo:string,frase:string,nivel:string}
	 */
	public static function aviso( $codigo ) {
		return array(
			'codigo' => $codigo,
			'frase'  => self::frase( $codigo ),
			'nivel'  => self::nivel( $codigo ),
		);
	}

	/**
	 * Escribe una línea en el registro de PHP y devuelve su `ref`.
	 *
	 * LIMITADO POR CÓDIGO. Las rutas son públicas: cualquiera puede
	 * provocar un fallo de origen un millón de veces sin coste, y un
	 * registro que crece sin tope tira el disco de un servidor
	 * compartido. Así que cada código escribe como mucho una línea por
	 * ventana —diez minutos en las puertas y la configuración, un minuto
	 * en los proveedores— y las peticiones que caen dentro devuelven el
	 * `ref` de esa línea. El `ref` siempre lleva a una línea que existe.
	 *
	 * La verificación del panel pasa `$ventana = 0`: la pidió un
	 * administrador y cada pulsación tiene que dejar su rastro.
	 *
	 * @param string   $codigo  Código UHP.
	 * @param string   $donde   Contexto.
	 * @param string   $detalle Detalle, ya sin secretos.
	 * @param int|null $ventana Segundos; null para la del área.
	 * @return string Ref de seis caracteres.
	 */
	public static function anotar( $codigo, $donde, $detalle = '', $ventana = null ) {
		if ( null === $ventana ) {
			$area    = substr( (string) $codigo, 4, 3 );
			$ventana = in_array( $area, array( 'LLM', 'VOZ' ), true ) ? MINUTE_IN_SECONDS : 10 * MINUTE_IN_SECONDS;
		}
		$ventana = (int) $ventana;
		$marca   = 'uhp_reg_' . strtolower( str_replace( '-', '', (string) $codigo ) );

		if ( $ventana > 0 ) {
			$previo = get_transient( $marca );
			if ( is_string( $previo ) && 6 === strlen( $previo ) ) {
				return $previo;
			}
		}

		$ref = self::ref();
		if ( $ventana > 0 ) {
			set_transient( $marca, $ref, $ventana );
		}

		$linea = '[URKUNINA 5000] ' . $codigo . ' · ' . self::frase( $codigo ) . ' · ref ' . $ref . ' · ' . $donde;
		$extra = trim( preg_replace( '/\s+/', ' ', (string) $detalle ) );
		if ( '' !== $extra ) {
			$linea .= ' · ' . $extra;
		}

		if ( function_exists( 'error_log' ) ) {
			error_log( $linea ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		return $ref;
	}

	/**
	 * Seis caracteres hexadecimales al azar.
	 *
	 * Sin relación con la IP ni con la hora: no dice nada de nadie.
	 *
	 * @return string
	 */
	private static function ref() {
		try {
			return bin2hex( random_bytes( 3 ) );
		} catch ( \Exception $e ) {
			return substr( md5( uniqid( '', true ) ), 0, 6 );
		}
	}

	/**
	 * Cabeceras X-UHP-* en las respuestas de las dos rutas del asistente.
	 *
	 * El cuerpo manda; la cabecera es un espejo que se ve en la pestaña
	 * Red sin abrir un cuerpo de cien kilobytes de audio. La versión va
	 * en todas: la causa número uno de «no suena» es una página en caché
	 * de una versión y un servidor de otra, y así se ve de un vistazo.
	 *
	 * @param mixed  $respuesta Respuesta ya armada.
	 * @param mixed  $servidor  Servidor REST.
	 * @param object $peticion  Petición.
	 * @return mixed
	 */
	public static function cabeceras( $respuesta, $servidor, $peticion ) {
		if ( ! ( $respuesta instanceof \WP_REST_Response ) || ! is_object( $peticion ) || ! method_exists( $peticion, 'get_route' ) ) {
			return $respuesta;
		}
		if ( 0 !== strpos( (string) $peticion->get_route(), '/' . UHP_Rest::NS . '/voz/' ) ) {
			return $respuesta;
		}

		$respuesta->header( 'X-UHP-Version', UHP_VERSION );

		$d = $respuesta->get_data();
		if ( is_array( $d ) && isset( $d['data']['uhp']['codigo'] ) ) {
			$respuesta->header( 'X-UHP-Codigo', (string) $d['data']['uhp']['codigo'] );
			if ( ! empty( $d['data']['uhp']['ref'] ) ) {
				$respuesta->header( 'X-UHP-Ref', (string) $d['data']['uhp']['ref'] );
			}
		}
		if ( is_array( $d ) && ! empty( $d['avisos'] ) && is_array( $d['avisos'] ) ) {
			$respuesta->header( 'X-UHP-Aviso', implode( ',', array_column( $d['avisos'], 'codigo' ) ) );
		}
		return $respuesta;
	}

	/* ================================================================= */
	/* Clasificación de los fallos de un proveedor                       */
	/* ================================================================= */

	/**
	 * Un fallo de transporte: ¿no conecta, o no contesta a tiempo?
	 *
	 * Son dos arreglos distintos —un cortafuegos de salida frente a un
	 * proveedor lento— y el mensaje de cURL los distingue.
	 *
	 * @param string $area    LLM o VOZ.
	 * @param string $mensaje Mensaje del transporte.
	 * @return string Código UHP.
	 */
	public static function de_transporte( $area, $mensaje ) {
		$m = strtolower( (string) $mensaje );
		$tarde = false !== strpos( $m, 'curl error 28' ) || false !== strpos( $m, 'timed out' ) || false !== strpos( $m, 'timeout' );
		return 'UHP-' . $area . '-' . ( $tarde ? '02' : '01' );
	}

	/**
	 * Un HTTP que no es 200: PRIMERO EL CUERPO, DESPUÉS EL ESTADO.
	 *
	 * Clasificar solo por el estado se equivoca en casos reales: OpenAI
	 * devuelve la falta de saldo como 429 (`insufficient_quota`), Gemini
	 * una clave inválida como 400 (`API_KEY_INVALID`), Anthropic el saldo
	 * insuficiente como 400 con un mensaje sobre el «credit balance», y
	 * ElevenLabs la cuota agotada como 401 (`quota_exceeded`). El tipo de
	 * error que trae el cuerpo manda; el estado es el respaldo.
	 *
	 * @param string $area   LLM o VOZ.
	 * @param int    $http   Estado HTTP.
	 * @param string $cuerpo Cuerpo crudo.
	 * @return string Código UHP.
	 */
	public static function de_proveedor( $area, $http, $cuerpo ) {
		$n = self::por_cuerpo( (string) $cuerpo );
		if ( '' === $n ) {
			$n = self::por_estado( (int) $http );
		}
		return 'UHP-' . $area . '-' . $n;
	}

	/**
	 * El número que dice el cuerpo de error, o vacío si no dice nada.
	 *
	 * @param string $cuerpo Cuerpo crudo.
	 * @return string
	 */
	private static function por_cuerpo( $cuerpo ) {
		$j = json_decode( $cuerpo, true );
		if ( ! is_array( $j ) ) {
			return '';
		}

		/* Las señales de los cuatro, juntas y en minúscula. Ninguna se
		   repite entre proveedores con otro sentido, de modo que no hace
		   falta saber de quién es el cuerpo. */
		$senales = array();
		$mensaje = '';
		$e       = isset( $j['error'] ) && is_array( $j['error'] ) ? $j['error'] : array();
		foreach ( array( 'type', 'code', 'status' ) as $k ) {
			if ( isset( $e[ $k ] ) && is_string( $e[ $k ] ) ) {
				$senales[] = strtolower( $e[ $k ] );
			}
		}
		if ( isset( $e['message'] ) && is_string( $e['message'] ) ) {
			$mensaje = strtolower( $e['message'] );
		}
		// Gemini: error.details[].reason.
		if ( isset( $e['details'] ) && is_array( $e['details'] ) ) {
			foreach ( $e['details'] as $det ) {
				if ( is_array( $det ) && isset( $det['reason'] ) && is_string( $det['reason'] ) ) {
					$senales[] = strtolower( $det['reason'] );
				}
			}
		}
		// ElevenLabs: detail.status (o detail.code en la forma nueva).
		if ( isset( $j['detail'] ) && is_array( $j['detail'] ) ) {
			foreach ( array( 'status', 'code', 'type' ) as $k ) {
				if ( isset( $j['detail'][ $k ] ) && is_string( $j['detail'][ $k ] ) ) {
					$senales[] = strtolower( $j['detail'][ $k ] );
				}
			}
			if ( isset( $j['detail']['message'] ) && is_string( $j['detail']['message'] ) ) {
				$mensaje = strtolower( $j['detail']['message'] );
			}
		}
		// Anthropic: el tipo va también en la raíz como «error».
		if ( isset( $j['type'] ) && 'error' === $j['type'] && isset( $e['type'] ) ) {
			$senales[] = strtolower( (string) $e['type'] );
		}

		// El saldo de Anthropic llega como invalid_request_error: el
		// mensaje es la única pista, y va primero.
		if ( false !== strpos( $mensaje, 'credit balance' ) ) {
			return '07';
		}

		$tabla = array(
			'07' => array( 'insufficient_quota', 'billing_error', 'quota_exceeded', 'payment_required', 'insufficient_credits', 'billing_not_active' ),
			'03' => array( 'authentication_error', 'invalid_api_key', 'unauthenticated', 'api_key_invalid', 'needs_authorization', 'invalid_authentication' ),
			'04' => array( 'permission_error', 'permission_denied', 'missing_permissions', 'unsupported_country_region_territory', 'failed_precondition', 'detected_unusual_activity', 'service_disabled', 'access_denied' ),
			'05' => array( 'not_found_error', 'model_not_found', 'not_found', 'voice_not_found', 'voice_does_not_exist' ),
			'08' => array( 'rate_limit_error', 'rate_limit_exceeded', 'resource_exhausted', 'too_many_concurrent_requests' ),
			'09' => array( 'api_error', 'overloaded_error', 'unavailable', 'internal', 'server_error', 'system_busy', 'deadline_exceeded' ),
			'06' => array( 'invalid_request_error', 'invalid_argument', 'request_too_large', 'invalid_request', 'validation_error', 'unprocessable_entity' ),
		);
		foreach ( $tabla as $numero => $nombres ) {
			if ( array_intersect( $senales, $nombres ) ) {
				return $numero;
			}
		}
		return '';
	}

	/**
	 * El número que dice el estado HTTP, como respaldo.
	 *
	 * @param int $http Estado.
	 * @return string
	 */
	private static function por_estado( $http ) {
		if ( 401 === $http ) {
			return '03';
		}
		if ( 403 === $http ) {
			return '04';
		}
		if ( 404 === $http ) {
			return '05';
		}
		if ( 400 === $http || 413 === $http || 422 === $http ) {
			return '06';
		}
		if ( 402 === $http ) {
			return '07';
		}
		if ( 429 === $http ) {
			return '08';
		}
		if ( $http >= 500 ) {
			return '09';
		}
		return '99';
	}
}
