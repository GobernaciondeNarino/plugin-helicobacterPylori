<?php
/**
 * Asistente de voz del tablero.
 *
 * Deja que un visitante pregunte en voz alta por las cifras del proyecto y
 * que el agente mueva los filtros del tablero mientras responde. El habla
 * y la síntesis de voz las pone ElevenLabs; todo lo que el ciudadano ve lo
 * pone este plugin.
 *
 * TRES DECISIONES QUE CONVIENE NO DESHACER:
 *
 *  1. NACE APAGADO Y NO SE ENCIENDE SOLO. `configurada()` exige, además
 *     del interruptor, el identificador del agente, el enlace a la
 *     política de tratamiento de datos, dos canales para ejercer los
 *     derechos del titular, lo que el proveedor declare por escrito sobre
 *     retención, y la firma de la evaluación propia de accesibilidad.
 *     Mientras falte uno, el tablero sale exactamente como hoy. Esto no es
 *     celo decorativo: sin esos campos el aviso del artículo 9 de la Ley
 *     1581 de 2012 quedaría con huecos, y un aviso con huecos no recoge
 *     una autorización informada.
 *
 *  2. LA CLAVE DE API VIVE APARTE. No está en `uhp_voz` —el array del que
 *     se deriva lo que viaja al navegador— sino en su propia opción sin
 *     autoload, y mejor todavía en la constante UHP_ELEVENLABS_API_KEY del
 *     wp-config. `config_front()` se construye por LISTA BLANCA, clave por
 *     clave: así, añadir un ajuste nuevo nunca puede publicarlo por
 *     descuido. Una prueba comprueba que la clave no aparece en el HTML.
 *
 *  3. LA RUTA DE SESIÓN NO VA EN UHP_Rest. Esa clase declara en su
 *     cabecera que todas sus rutas son públicas, de solo lectura y sin
 *     efectos. Esta es POST, consume cuota facturable y habla con un
 *     tercero. Meterla allí dejaría mintiendo un comentario que alguien
 *     leerá como garantía.
 *
 * @package Urkunina5000
 */

namespace GobernacionNarino\Urkunina;

defined( 'ABSPATH' ) || exit;

/**
 * Configuración, ruta de sesión y marcado del asistente de voz.
 */
final class UHP_Voz {

	/** Opción con la configuración visible. */
	const OPCION = 'uhp_voz';

	/** Opción con la clave de API. Aparte y sin autoload, a propósito. */
	const OPCION_CLAVE = 'uhp_voz_clave';

	/** Base de la API de ElevenLabs. */
	const API = 'https://api.elevenlabs.io';

	/**
	 * Engancha la ruta REST.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'registrar_rutas' ) );
	}

	/* ================================================================= */
	/* Configuración                                                     */
	/* ================================================================= */

	/**
	 * Valores por defecto. Todo apagado y todo vacío.
	 *
	 * @return array
	 */
	public static function por_defecto() {
		return array(
			'activo'           => 0,
			'agente'           => '',
			'modo'             => 'privado',
			'transporte'       => 'websocket',
			'cuota_dia'        => 200,
			'texto'            => 1,
			'recordar'         => 1,
			'aviso_version'    => 1,
			'politica'         => '',
			'correo'           => '',
			'canal'            => '',
			'retencion'        => '',
			'aviso_extra'      => '',
			'evaluacion_a11y'  => 0,
		);
	}

	/**
	 * Configuración completa, con los valores que falten rellenados.
	 *
	 * @return array
	 */
	public static function cfg() {
		$c = get_option( self::OPCION, array() );
		return wp_parse_args( is_array( $c ) ? $c : array(), self::por_defecto() );
	}

	/**
	 * Clave de API, de la constante o de su propia opción.
	 *
	 * La constante gana: una clave en wp-config.php no entra en la base de
	 * datos, no viaja en una exportación y no la ve quien administre el
	 * sitio sin acceso al servidor.
	 *
	 * @return string
	 */
	public static function clave_api() {
		if ( defined( 'UHP_ELEVENLABS_API_KEY' ) && is_string( UHP_ELEVENLABS_API_KEY ) ) {
			return trim( UHP_ELEVENLABS_API_KEY );
		}
		$c = get_option( self::OPCION_CLAVE, '' );
		return is_string( $c ) ? trim( $c ) : '';
	}

	/**
	 * ¿Está el asistente listo para publicarse?
	 *
	 * @return bool
	 */
	public static function configurada() {
		$c = self::cfg();

		if ( empty( $c['activo'] ) || '' === $c['agente'] ) {
			return false;
		}
		// Los cuatro campos del aviso de habeas data. Sin ellos el texto
		// que ve el ciudadano tendría huecos donde debe ir quién responde,
		// dónde reclamar y cuánto se conserva lo que diga.
		foreach ( array( 'politica', 'correo', 'canal', 'retencion' ) as $k ) {
			if ( '' === trim( (string) $c[ $k ] ) ) {
				return false;
			}
		}
		if ( empty( $c['evaluacion_a11y'] ) ) {
			return false;
		}
		// Un agente privado sin clave no puede abrir sesión: publicar el
		// botón sería ofrecer algo que falla al pulsarlo.
		if ( 'privado' === $c['modo'] && '' === self::clave_api() ) {
			return false;
		}
		return true;
	}

	/**
	 * Qué le falta para poder publicarse, en lenguaje llano.
	 *
	 * @return array<int,string>
	 */
	public static function pendientes() {
		$c     = self::cfg();
		$falta = array();

		if ( empty( $c['activo'] ) ) {
			$falta[] = __( 'El asistente está apagado.', 'urkunina-5000' );
		}
		if ( '' === $c['agente'] ) {
			$falta[] = __( 'Falta el identificador del agente de ElevenLabs.', 'urkunina-5000' );
		}
		if ( 'privado' === $c['modo'] && '' === self::clave_api() ) {
			$falta[] = __( 'El agente es privado y no hay clave de API configurada.', 'urkunina-5000' );
		}
		$rotulos = array(
			'politica'  => __( 'Falta el enlace a la política de tratamiento de datos personales.', 'urkunina-5000' ),
			'correo'    => __( 'Falta el correo para ejercer los derechos del titular.', 'urkunina-5000' ),
			'canal'     => __( 'Falta el segundo canal de atención al titular.', 'urkunina-5000' ),
			'retencion' => __( 'Falta lo que el proveedor declara sobre la retención de grabaciones y transcripciones.', 'urkunina-5000' ),
		);
		foreach ( $rotulos as $k => $texto ) {
			if ( '' === trim( (string) $c[ $k ] ) ) {
				$falta[] = $texto;
			}
		}
		if ( empty( $c['evaluacion_a11y'] ) ) {
			$falta[] = __( 'La evaluación propia de accesibilidad no está firmada.', 'urkunina-5000' );
		}
		return $falta;
	}

	/**
	 * Lo que viaja al navegador.
	 *
	 * LISTA BLANCA, clave por clave. Nunca `cfg()` menos algo: así, el
	 * ajuste que alguien añada mañana no se publica por omisión.
	 *
	 * @return array{agente:string,modo:string,transporte:string,texto:bool,recordar:bool,avisoVersion:int,ruta:string}
	 */
	public static function config_front() {
		$c    = self::cfg();
		$modo = 'publico' === $c['modo'] ? 'publico' : 'privado';
		return array(
			// En modo privado el navegador NO necesita el identificador:
			// la sesión la abre con la URL firmada que le da el servidor.
			// Publicarlo solo serviría para que alguien intentara usar el
			// agente por su cuenta.
			'agente'       => 'publico' === $modo ? (string) $c['agente'] : '',
			'modo'         => $modo,
			'transporte'   => 'webrtc' === $c['transporte'] ? 'webrtc' : 'websocket',
			'texto'        => ! empty( $c['texto'] ),
			'recordar'     => ! empty( $c['recordar'] ),
			'avisoVersion' => (int) $c['aviso_version'],
			'ruta'         => '/voz/sesion',
			'sdk'          => UHP_Assets::voz_urls(),
		);
	}

	/**
	 * Rótulos de la interfaz, para que el JavaScript no lleve texto suelto.
	 *
	 * @return array<string,string>
	 */
	public static function textos() {
		return array(
			'abrir'        => __( 'Preguntar por voz', 'urkunina-5000' ),
			'cerrar'       => __( 'Cerrar el asistente', 'urkunina-5000' ),
			'reanudar'     => __( 'Volver a preguntar', 'urkunina-5000' ),
			'aviso_fase'   => __( 'Lea el aviso antes de continuar.', 'urkunina-5000' ),
			'confirmar'    => __( 'Hay un municipio por confirmar.', 'urkunina-5000' ),
			'no'           => __( 'No', 'urkunina-5000' ),
			'aviso_corto'  => __( 'Su voz se envía a un proveedor en el exterior. El tablero funciona igual sin el asistente.', 'urkunina-5000' ),
			'titulo'       => __( 'Asistente de voz', 'urkunina-5000' ),
			'aceptar'      => __( 'Acepto y quiero hablar', 'urkunina-5000' ),
			'cancelar'     => __( 'Cancelar', 'urkunina-5000' ),
			'retirar'      => __( 'Retirar autorización', 'urkunina-5000' ),
			'terminar'     => __( 'Terminar conversación', 'urkunina-5000' ),
			'escribir'     => __( 'Escribir en vez de hablar', 'urkunina-5000' ),
			'enviar'       => __( 'Enviar', 'urkunina-5000' ),
			'conectando'   => __( 'Conectando…', 'urkunina-5000' ),
			'escuchando'   => __( 'Escuchando. Hable cuando quiera.', 'urkunina-5000' ),
			'hablando'     => __( 'El asistente está respondiendo.', 'urkunina-5000' ),
			'cerrada'      => __( 'Conversación terminada.', 'urkunina-5000' ),
			'retirada'     => __( 'Autorización retirada. No se enviará nada más.', 'urkunina-5000' ),
			'sin_micro'    => __( 'No se concedió el micrófono. Puede escribir su pregunta.', 'urkunina-5000' ),
			'sin_micro_no' => __( 'Este navegador no ofrece micrófono. Puede escribir su pregunta.', 'urkunina-5000' ),
			'sin_seguro'   => __( 'El micrófono solo funciona sobre HTTPS.', 'urkunina-5000' ),
			'ocupado'      => __( 'El micrófono lo está usando otra aplicación.', 'urkunina-5000' ),
			'sin_sdk'      => __( 'No se pudo cargar el asistente de voz.', 'urkunina-5000' ),
			'sin_sesion'   => __( 'No se pudo abrir la conversación. Inténtelo más tarde.', 'urkunina-5000' ),
			'sin_cuota'    => __( 'El asistente alcanzó su límite de conversaciones de hoy.', 'urkunina-5000' ),
			'demasiado'    => __( 'He hecho demasiados cambios seguidos. Dígame uno concreto.', 'urkunina-5000' ),
			'usted'        => __( 'Usted', 'urkunina-5000' ),
			'asistente'    => __( 'Asistente', 'urkunina-5000' ),
			'transcripcion' => __( 'Transcripción de la conversación', 'urkunina-5000' ),
		);
	}

	/* ================================================================= */
	/* Aviso de habeas data                                              */
	/* ================================================================= */

	/**
	 * Los nueve bloques del aviso, con los datos de la entidad puestos.
	 *
	 * Se imprimen desde PHP, escapados, y no desde un campo que el agente
	 * de ElevenLabs pueda traer: lo que el ciudadano lee antes de autorizar
	 * no puede depender de la configuración de una cuenta ajena.
	 *
	 * @return array<int,array{t:string,p:string}>
	 */
	public static function aviso_bloques() {
		$c = self::cfg();

		$bloques = array(
			array(
				't' => __( 'Qué va a pasar', 'urkunina-5000' ),
				'p' => __( 'Si acepta, el navegador le pedirá permiso para usar el micrófono. Mientras la conversación esté abierta, su voz se envía a un proveedor externo que la convierte en texto, responde por voz y, cuando usted se lo pide, cambia los filtros de este tablero.', 'urkunina-5000' ),
			),
			array(
				't' => __( 'Quién responde por sus datos', 'urkunina-5000' ),
				'p' => __( 'Gobernación de Nariño — Secretaría de Tecnologías de la Información, las Comunicaciones, Innovación y Gobierno Abierto. Pasto, Nariño.', 'urkunina-5000' ),
			),
			array(
				't' => __( 'Qué se trata', 'urkunina-5000' ),
				'p' => __( 'El audio de lo que usted diga, su transcripción en texto y los datos técnicos de la conexión. No se le pide su nombre, ni su documento, ni su historia clínica, y el asistente no los necesita.', 'urkunina-5000' ),
			),
			array(
				't' => __( 'Para qué', 'urkunina-5000' ),
				'p' => __( 'Únicamente para responder sus preguntas sobre las cifras públicas del proyecto URKUNINA 5000 y para mover los filtros de este tablero.', 'urkunina-5000' ),
			),
			array(
				't' => __( 'Quién lo procesa y dónde', 'urkunina-5000' ),
				'p' => __( 'ElevenLabs Inc., con servidores fuera de Colombia. Esto es una transferencia internacional de sus datos, prevista en el artículo 26 de la Ley 1581 de 2012.', 'urkunina-5000' ) . ' ' . trim( (string) $c['retencion'] ),
			),
			array(
				't' => __( 'Con qué fundamento', 'urkunina-5000' ),
				'p' => __( 'Con su autorización previa, expresa e informada, que es lo que usted da al pulsar «Acepto y quiero hablar» (artículo 9 de la Ley 1581 de 2012). Es voluntaria: puede negarse y no pierde nada.', 'urkunina-5000' ),
			),
			array(
				't' => __( 'La voz puede ser un dato sensible', 'urkunina-5000' ),
				'p' => __( 'No dicte datos personales ni de salud. El asistente solo conoce cifras agregadas y públicas: no le cuente sus síntomas, sus resultados de laboratorio ni los de otra persona. No puede ayudarle con eso y esa información quedaría en la transcripción.', 'urkunina-5000' ),
			),
			array(
				't' => __( 'Sus derechos', 'urkunina-5000' ),
				'p' => sprintf(
					/* translators: 1: correo, 2: canal de atención, 3: URL de la política. */
					__( 'Puede conocer, actualizar, rectificar y suprimir sus datos, y revocar esta autorización en cualquier momento, escribiendo a %1$s o radicando su solicitud en %2$s. En esta misma página, el botón «Retirar autorización» cierra la conversación y borra la aceptación de inmediato. La política completa está en %3$s.', 'urkunina-5000' ),
					trim( (string) $c['correo'] ),
					trim( (string) $c['canal'] ),
					trim( (string) $c['politica'] )
				),
			),
			array(
				't' => __( 'Sin micrófono también funciona', 'urkunina-5000' ),
				'p' => __( 'Puede escribir sus preguntas en lugar de hablarlas. Y los filtros del tablero —indicador, zona, subregión y municipio— se manejan con el teclado y con el ratón, sin asistente alguno.', 'urkunina-5000' ),
			),
		);

		$extra = trim( (string) $c['aviso_extra'] );
		if ( '' !== $extra ) {
			$bloques[] = array(
				't' => __( 'Nota de la entidad', 'urkunina-5000' ),
				'p' => $extra,
			);
		}
		return $bloques;
	}

	/* ================================================================= */
	/* Marcado                                                           */
	/* ================================================================= */

	/**
	 * El bloque del asistente, para incrustarlo en el tablero.
	 *
	 * Sale servido por PHP y no inyectado por JavaScript: el aviso corto y
	 * la alternativa escrita tienen que estar en la página aunque el script
	 * no llegue a cargar.
	 *
	 * @param string $id Identificador del tablero que lo contiene.
	 * @return string
	 */
	public static function control_html( $id ) {
		if ( ! self::configurada() ) {
			return '';
		}
		$t       = self::textos();
		$bloques = self::aviso_bloques();
		$idv     = $id . '-voz';

		ob_start();
		?>
		<div class="uhp-voz" id="<?php echo esc_attr( $idv ); ?>" data-uhp-voz
			role="complementary"
			aria-label="<?php echo esc_attr( $t['titulo'] ); ?>">

			<div class="uhp-voz__barra">
				<?php
				/* Sale DESHABILITADO y lo habilita el script al cablearlo.
				   Un botón de micrófono que se puede pulsar y no hace nada
				   porque el JavaScript no llegó es peor que no tenerlo:
				   promete algo que no va a ocurrir. */
				?>
				<button type="button" class="uhp-voz__abrir" data-uhp-voz-accion="abrir" disabled
					aria-expanded="false" aria-controls="<?php echo esc_attr( $idv ); ?>-panel">
					<span class="uhp-voz__icono" aria-hidden="true">
						<svg viewBox="0 0 16 16" width="16" height="16" focusable="false">
							<path d="M8 1.6a2.2 2.2 0 0 1 2.2 2.2v4a2.2 2.2 0 0 1-4.4 0v-4A2.2 2.2 0 0 1 8 1.6Z"/>
							<path d="M3.4 7.2v.6a4.6 4.6 0 0 0 9.2 0v-.6" fill="none" stroke="currentColor" stroke-width="1.5"/>
							<path d="M8 12.4v2" fill="none" stroke="currentColor" stroke-width="1.5"/>
						</svg>
					</span>
					<span><?php echo esc_html( $t['abrir'] ); ?></span>
				</button>
				<p class="uhp-voz__aviso-corto"><?php echo esc_html( $t['aviso_corto'] ); ?></p>
			</div>

			<div class="uhp-voz__panel" id="<?php echo esc_attr( $idv ); ?>-panel"
				data-uhp-voz-zona="panel" hidden></div>

			<!-- El aviso completo viaja en el HTML y lo imprime PHP, no lo
				 compone el script ni llega de la configuración de una
				 cuenta ajena: así puede auditarse en el código fuente de
				 la página. Va en un <template> porque solo se muestra al
				 pulsar; sin JavaScript el botón sale deshabilitado y lo
				 que queda visible es el aviso corto de arriba. -->
			<template data-uhp-voz-zona="aviso">
				<?php foreach ( $bloques as $b ) : ?>
					<section class="uhp-voz__bloque">
						<h4><?php echo esc_html( $b['t'] ); ?></h4>
						<p><?php echo esc_html( $b['p'] ); ?></p>
					</section>
				<?php endforeach; ?>
			</template>

			<p class="uhp-voz__sr" data-uhp-voz-zona="estado" role="status" aria-live="polite"></p>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ================================================================= */
	/* Ruta de sesión                                                    */
	/* ================================================================= */

	/**
	 * Registra POST /voz/sesion en el namespace del plugin.
	 */
	public function registrar_rutas() {
		register_rest_route(
			UHP_Rest::NS,
			'/voz/sesion',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'ruta_sesion' ),
				// El filtrado real va dentro: así cada motivo devuelve su
				// propio código y su propio mensaje en español.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Entrega una URL firmada para abrir la conversación.
	 *
	 * No acepta ni un parámetro: todo lo que determina la sesión sale de la
	 * configuración del servidor. Lo que el navegador pida no puede cambiar
	 * con qué agente se habla ni con qué clave.
	 *
	 * HASTA DÓNDE LLEGA LA PUERTA, DICHO SIN ADORNOS. Lo que esta ruta
	 * entrega es una CREDENCIAL: una URL firmada, comprada con la clave de
	 * la entidad, que abre una conversación facturable desde cualquier
	 * máquina. Y quien la pide es un visitante anónimo, de modo que no hay
	 * a quién autenticar. Las cuatro puertas que siguen encarecen el abuso
	 * y lo acotan; ninguna lo hace imposible:
	 *
	 *   1. Origen igual al del sitio, fallando CERRADO. Frena al script que
	 *      no manda cabeceras, que es el caso corriente. No frena a quien
	 *      las falsifica.
	 *   2. Cinco peticiones cada diez minutos por IP.
	 *   3. Cupo diario de todo el sitio, reservado antes de gastar, con
	 *      aviso en el registro cuando se agota.
	 *   4. La lista de dominios permitidos del agente en ElevenLabs, que
	 *      es un control de NAVEGADOR: no ata una URL firmada usada desde
	 *      un script.
	 *
	 * Por eso el cupo diario importa de verdad y por eso el panel insiste
	 * en ponerle un número que la entidad pueda perder sin dolor. La
	 * última línea de defensa no está aquí: está en el límite de gasto de
	 * la cuenta del proveedor. Ver docs/voz/privacidad.md.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function ruta_sesion() {
		$c = self::cfg();

		if ( ! self::configurada() || 'privado' !== $c['modo'] ) {
			return new \WP_Error(
				'uhp_voz_inactivo',
				__( 'El asistente de voz no está disponible.', 'urkunina-5000' ),
				array( 'status' => 403 )
			);
		}

		// Badén, no frontera: la cabecera es falsificable y la frontera de
		// verdad es la lista de dominios permitidos del propio agente, que
		// se configura en ElevenLabs. Esto solo frena el uso casual desde
		// otra página.
		if ( ! self::origen_valido() ) {
			return new \WP_Error(
				'uhp_voz_origen',
				__( 'Petición desde un origen que no es este sitio.', 'urkunina-5000' ),
				array( 'status' => 403 )
			);
		}

		// Cinco cada diez minutos por IP, no noventa por minuto: cada
		// sesión consume minutos facturables de la entidad.
		if ( ! UHP_Security::rate_limit( 'voz_sesion', 5, 600 ) ) {
			return new \WP_Error(
				'uhp_rate_limit',
				__( 'Demasiadas peticiones. Espere un momento.', 'urkunina-5000' ),
				array( 'status' => 429 )
			);
		}

		$clave = self::clave_api();
		if ( '' === $clave ) {
			return new \WP_Error(
				'uhp_voz_sin_clave',
				__( 'El asistente de voz no está disponible.', 'urkunina-5000' ),
				array( 'status' => 503 )
			);
		}

		// Se reserva ANTES de llamar al proveedor; si la llamada falla, se
		// devuelve. Al revés, el tope se salta con peticiones simultáneas.
		if ( ! self::cuota_reservar( (int) $c['cuota_dia'] ) ) {
			self::anotar_tope( $c );
			return new \WP_Error(
				'uhp_voz_cuota',
				self::textos()['sin_cuota'],
				array( 'status' => 503 )
			);
		}

		$url = add_query_arg(
			array( 'agent_id' => rawurlencode( $c['agente'] ) ),
			self::api_base() . '/v1/convai/conversation/get-signed-url'
		);

		$r = wp_remote_get(
			$url,
			array(
				'timeout'     => 8,
				// Sin redirecciones: una respuesta 302 llevaría la cabecera
				// con la clave a donde diga el tercero.
				'redirection' => 0,
				'headers'     => array(
					'xi-api-key' => $clave,
					'accept'     => 'application/json',
				),
			)
		);

		if ( is_wp_error( $r ) ) {
			self::cuota_devolver();
			return new \WP_Error(
				'uhp_voz_red',
				self::textos()['sin_sesion'],
				array( 'status' => 502 )
			);
		}

		$codigo = (int) wp_remote_retrieve_response_code( $r );
		$cuerpo = json_decode( (string) wp_remote_retrieve_body( $r ), true );

		if ( 200 !== $codigo || ! is_array( $cuerpo ) || empty( $cuerpo['signed_url'] ) ) {
			self::cuota_devolver();
			// El detalle del proveedor se queda en el registro del sitio:
			// devolverlo al navegador filtraría si el agente existe, si la
			// clave es válida y qué cuenta hay detrás.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[URKUNINA 5000] voz/sesion: ' . $codigo . ' ' . substr( (string) wp_remote_retrieve_body( $r ), 0, 300 ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return new \WP_Error(
				'uhp_voz_proveedor',
				self::textos()['sin_sesion'],
				array( 'status' => 502 )
			);
		}

		return rest_ensure_response(
			array(
				'url'        => (string) $cuerpo['signed_url'],
				'transporte' => 'websocket',
			)
		);
	}

	/**
	 * Deja constancia de que el cupo diario se agotó.
	 *
	 * Sin esto, el abuso es invisible: el ciudadano ve un botón que no
	 * funciona el resto del día y nadie se entera de por qué. Se anota una
	 * vez al día, no en cada petición rechazada.
	 *
	 * @param array $c Configuración vigente.
	 */
	private static function anotar_tope( $c ) {
		$marca = 'uhp_voz_tope_' . wp_date( 'Ymd' );
		if ( get_transient( $marca ) ) {
			return;
		}
		set_transient( $marca, 1, DAY_IN_SECONDS );
		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			sprintf(
				'[URKUNINA 5000] El asistente de voz agotó su cupo diario de %d sesiones. Si no lo esperaba, revise el registro de accesos a /wp-json/urkunina/v1/voz/sesion.',
				(int) $c['cuota_dia']
			)
		);
	}

	/**
	 * Base de la API, filtrable para instalaciones con residencia de datos.
	 *
	 * No se ofrecen dominios alternativos en el panel porque dependen del
	 * contrato de cada cuenta: inventarlos aquí sería adivinar.
	 *
	 * @return string
	 */
	public static function api_base() {
		/**
		 * Filtra la base de la API de ElevenLabs.
		 *
		 * @param string $base URL por defecto.
		 */
		$base = apply_filters( 'uhp_voz_api_base', self::API );
		return is_string( $base ) && '' !== $base ? untrailingslashit( esc_url_raw( $base ) ) : self::API;
	}

	/**
	 * ¿Viene la petición de este mismo sitio?
	 *
	 * FALLA CERRADA. Antes, sin ninguna de las dos cabeceras se dejaba
	 * pasar «porque no se puede decidir», y eso abría de par en par el
	 * único camino que importa: un `curl -X POST` no manda ni Origin ni
	 * Referer, de modo que la comprobación solo frenaba a los navegadores,
	 * que son justamente los que sí mandan Origin. Lo que se entregaba a
	 * cambio no es una página: es una URL firmada, comprada con la clave
	 * de la entidad y utilizable como credencial desde cualquier máquina.
	 *
	 * Sigue sin ser una frontera —un script puede falsificar la cabecera—
	 * y por eso no es el único control: ver la nota de `ruta_sesion()`.
	 *
	 * @return bool
	 */
	private static function origen_valido() {
		$propio = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! is_string( $propio ) || '' === $propio ) {
			return false;
		}

		foreach ( array( 'HTTP_ORIGIN', 'HTTP_REFERER' ) as $k ) {
			if ( empty( $_SERVER[ $k ] ) ) {
				continue;
			}
			$partes = wp_parse_url( esc_url_raw( wp_unslash( $_SERVER[ $k ] ) ) );
			if ( ! is_array( $partes ) || empty( $partes['host'] ) ) {
				return false;
			}
			// Esquema además de host: http://sitio y https://sitio no son
			// el mismo origen, y aceptar el primero regala el tránsito.
			$esquema_ok = ! isset( $partes['scheme'] )
				|| strtolower( $partes['scheme'] ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_SCHEME ) );

			return $esquema_ok && strtolower( $partes['host'] ) === strtolower( $propio );
		}

		return false;
	}

	/**
	 * Reserva una plaza del cupo diario ANTES de llamar al proveedor.
	 *
	 * Comprobar primero y apuntar después deja una ventana entre la
	 * lectura y la escritura: veinte peticiones a la vez leen todas el
	 * mismo número, todas pasan y todas escriben el mismo valor, de modo
	 * que el tope se salta por donde más caro sale. Aquí se reserva y, si
	 * la llamada al proveedor falla, se devuelve la reserva.
	 *
	 * Con caché de objetos persistente el incremento es atómico. Sin ella
	 * —el caso de una instalación corriente— queda una ventana muy
	 * estrecha que no se puede cerrar con la API de opciones de WordPress;
	 * se documenta en vez de fingir que no está.
	 *
	 * @param int $tope Sesiones permitidas hoy.
	 * @return bool
	 */
	private static function cuota_reservar( $tope ) {
		$tope  = max( 1, (int) $tope );
		$clave = self::clave_cuota();

		if ( wp_using_ext_object_cache() ) {
			// add() solo crea si no existe, así que la primera petición
			// del día fija el contador y las demás incrementan.
			wp_cache_add( $clave, 0, 'uhp_voz', DAY_IN_SECONDS );
			$n = wp_cache_incr( $clave, 1, 'uhp_voz' );
			if ( false === $n ) {
				$n = 1;
			}
			if ( $n > $tope ) {
				wp_cache_decr( $clave, 1, 'uhp_voz' );
				return false;
			}
			return true;
		}

		$n = (int) get_transient( $clave );
		if ( $n >= $tope ) {
			return false;
		}
		set_transient( $clave, $n + 1, DAY_IN_SECONDS );
		return true;
	}

	/**
	 * Devuelve una reserva que no llegó a convertirse en sesión.
	 */
	private static function cuota_devolver() {
		$clave = self::clave_cuota();
		if ( wp_using_ext_object_cache() ) {
			wp_cache_decr( $clave, 1, 'uhp_voz' );
			return;
		}
		$n = (int) get_transient( $clave );
		if ( $n > 0 ) {
			set_transient( $clave, $n - 1, DAY_IN_SECONDS );
		}
	}

	/**
	 * Clave del transitorio del cupo, por día.
	 *
	 * El día es el LOCAL del sitio y no UTC: en Nariño (UTC-5) un corte en
	 * UTC reiniciaría el cupo a las siete de la tarde, en plena jornada.
	 *
	 * @return string
	 */
	private static function clave_cuota() {
		return 'uhp_voz_cuota_' . wp_date( 'Ymd' );
	}

	/**
	 * Sesiones consumidas hoy, para el panel.
	 *
	 * @return int
	 */
	public static function cuota_usada() {
		return (int) get_transient( self::clave_cuota() );
	}

	/**
	 * Sanea un identificador de agente.
	 *
	 * El identificador acaba en una URL que el servidor consulta con la
	 * clave de la entidad en la cabecera. Lo que no tenga su forma no
	 * entra: ni una ruta relativa, ni un esquema, ni un espacio.
	 *
	 * @param mixed $texto Valor recibido.
	 * @return string Identificador válido, o cadena vacía.
	 */
	public static function sanear_agente( $texto ) {
		$v = is_string( $texto ) ? trim( $texto ) : '';
		return preg_match( '/^agent_[A-Za-z0-9_-]{6,96}$/', $v ) ? $v : '';
	}

	/**
	 * Definiciones de las herramientas que el agente debe declarar.
	 *
	 * Fuente única: de aquí salen el bloque copiable del panel y el
	 * archivo de documentación. Si estuvieran escritas dos veces, el día
	 * que cambie un parámetro una de las dos se quedaría vieja y el agente
	 * llamaría a una herramienta con la forma equivocada.
	 *
	 * `espera` dice si en el panel de ElevenLabs hay que marcar «Wait for
	 * response»: las que solo leen sí —el agente necesita el dato para
	 * seguir hablando— y las que mueven el tablero no, para que la pantalla
	 * cambie mientras habla y no después.
	 *
	 * @return array<int,array>
	 */
	public static function definiciones_herramientas() {
		$zonas  = array( 'roja', 'amarilla', 'verde', 'todas' );
		$muni   = 'Nombre del municipio, su nombre popular o su código DANE de cinco dígitos. Tres municipios circulan con dos nombres y se aceptan los dos: Colón o Génova, Los Andes o Sotomayor, Santacruz o Guachavés. Solo se pueden seleccionar los 55 que el proyecto intervino.';

		return array(
			array(
				'name'        => 'uhp_cambiar_indicador',
				'description' => 'Cambia el indicador que colorea el mapa y alimenta las cifras del tablero.',
				'espera'      => false,
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'indicador' => array(
							'type'        => 'string',
							'enum'        => array( 'lpm', 'hp' ),
							'description' => 'Use «lpm» para la prevalencia de lesiones precursoras de malignidad y «hp» para la infección por Helicobacter pylori.',
						),
					),
					'required'   => array( 'indicador' ),
				),
			),
			array(
				'name'        => 'uhp_filtrar_zona_riesgo',
				'description' => 'Filtra el tablero por zona de riesgo de cáncer gástrico.',
				'espera'      => false,
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'zona' => array(
							'type'        => 'string',
							'enum'        => $zonas,
							'description' => 'La zona roja es el norte y el suroccidente; la amarilla, Pasto y los municipios circunvecinos; la verde, la costa Pacífica. Use «todas» para quitar el filtro.',
						),
					),
					'required'   => array( 'zona' ),
				),
			),
			array(
				'name'        => 'uhp_filtrar_subregion',
				'description' => 'Filtra el tablero por subregión de Nariño.',
				'espera'      => false,
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'subregion' => array(
							'type'        => 'string',
							'description' => 'Nombre de la subregión, o «todas» para quitar el filtro.',
						),
					),
					'required'   => array( 'subregion' ),
				),
			),
			array(
				'name'        => 'uhp_seleccionar_municipio',
				'description' => 'Selecciona un municipio: abre su ficha y lo enfoca en el mapa.',
				'espera'      => false,
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'municipio' => array( 'type' => 'string', 'description' => $muni ),
					),
					'required'   => array( 'municipio' ),
				),
			),
			array(
				'name'        => 'uhp_limpiar_tablero',
				'description' => 'Quita todos los filtros y devuelve el mapa a la vista del departamento.',
				'espera'      => false,
				'parameters'  => array( 'type' => 'object', 'properties' => new \stdClass(), 'required' => array() ),
			),
			array(
				'name'        => 'uhp_leer_estado_tablero',
				'description' => 'Devuelve qué se está viendo ahora mismo en el tablero. Llámela ANTES de describir la pantalla: sin ella no puede saber qué hay a la vista.',
				'espera'      => true,
				'parameters'  => array( 'type' => 'object', 'properties' => new \stdClass(), 'required' => array() ),
			),
			array(
				'name'        => 'uhp_consultar_municipio',
				'description' => 'Consulta las cifras de un municipio SIN tocar la pantalla. Úsela cuando le pregunten por un dato y no le pidan verlo.',
				'espera'      => true,
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'municipio' => array( 'type' => 'string', 'description' => $muni ),
					),
					'required'   => array( 'municipio' ),
				),
			),
			array(
				'name'        => 'uhp_listar_municipios',
				'description' => 'Enumera municipios, opcionalmente filtrados. Úsela siempre antes de nombrar varios municipios: evita inventarlos.',
				'espera'      => true,
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'zona'      => array( 'type' => 'string', 'enum' => array( 'roja', 'amarilla', 'verde' ) ),
						'subregion' => array( 'type' => 'string' ),
					),
					'required'   => array(),
				),
			),
		);
	}

	/**
	 * Instrucciones que debe llevar el agente en ElevenLabs.
	 *
	 * @return string
	 */
	public static function prompt_agente() {
		return implode(
			"\n",
			array(
				'Eres el asistente del tablero de datos abiertos del proyecto URKUNINA 5000, de la Gobernación de Nariño (Colombia). El proyecto estudió la prevalencia de lesiones precursoras de malignidad gástrica y de infección por Helicobacter pylori en 5.000 voluntarios de 55 municipios del área andina de Nariño, entre 2018 y 2023.',
				'',
				'CÓMO HABLAS',
				'- En español de Colombia, de usted, claro y breve. Frases cortas: esto se escucha, no se lee.',
				'- Las cifras, dichas como se dicen: «treinta y cinco coma uno por ciento».',
				'- No inventas NUNCA una cifra ni un nombre de municipio. Si no lo tienes, lo dices.',
				'',
				'DE DÓNDE SACAS LOS DATOS',
				'- Del tablero, con las herramientas. Nunca de tu memoria.',
				'- Antes de describir lo que se está viendo, llama a uhp_leer_estado_tablero.',
				'- Antes de nombrar varios municipios, llama a uhp_listar_municipios.',
				'- Si te preguntan por un dato sin pedir verlo, usa uhp_consultar_municipio y NO muevas la pantalla.',
				'',
				'LAS TRES REGLAS DE HONESTIDAD DEL PROYECTO',
				'1. Un cero es un dato, no un hueco. «Ningún caso detectado» no es «sin información».',
				'2. Nueve de los 64 municipios de Nariño no entraron en el estudio. Ahí no falta el dato: faltó el tamizaje. Dilo con esas palabras.',
				'3. Cuando una herramienta te devuelva una cifra marcada como «de la subregión, no del municipio», dilo en voz alta. No la presentes como propia del municipio.',
				'',
				'MUNICIPIOS QUE SE PARECEN',
				'- Hay tres pares de municipios intervenidos que se confunden al dictarlos: Pupiales e Ipiales, Albán y Aldana, Iles e Imués. Si la herramienta te avisa de un municipio de nombre parecido, pregunta cuál quería ANTES de leer ninguna cifra.',
				'- Si la herramienta te pide una confirmación en pantalla, pídesela al visitante y espera. No vuelvas a llamarla con el mismo nombre.',
				'',
				'LO QUE NO HACES',
				'- No das consejo médico, ni diagnósticos, ni interpretas síntomas. Si alguien te cuenta un síntoma, le dices con amabilidad que consulte a su EPS o a su médico, y que tú solo manejas cifras públicas agregadas.',
				'- No pides ni repites datos personales. Si alguien dicta un nombre, una cédula o un resultado de laboratorio, le recuerdas que no debe hacerlo.',
				'- No hablas de otros temas: si te preguntan por algo ajeno al proyecto, lo dices y vuelves al tablero.',
			)
		);
	}

	/**
	 * Hosts a los que el navegador tendrá que poder conectarse.
	 *
	 * Para que quien administre el servidor web pueda escribir la CSP sin
	 * averiguarlo a base de errores en consola.
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function hosts_csp() {
		$c     = self::cfg();
		$webrtc = 'webrtc' === $c['transporte'];

		$connect = array( 'https://api.elevenlabs.io', 'wss://api.elevenlabs.io' );
		if ( $webrtc ) {
			// El transporte WebRTC va por LiveKit: lo declara la propia
			// dependencia del SDK. Con WebSocket no hace falta ninguno.
			$connect[] = 'https://*.livekit.cloud';
			$connect[] = 'wss://*.livekit.cloud';
			$connect[] = 'https://*.livekit.run';
			$connect[] = 'wss://*.livekit.run';
		}

		// El host del SDK se deriva de su URL y no se escribe a mano: si
		// la entidad lo sirve desde su propio dominio con el filtro
		// `uhp_url_libreria`, la receta tiene que seguirlo.
		$sdk  = UHP_Assets::voz_urls();
		$host = wp_parse_url( $sdk['sdk'], PHP_URL_HOST );
		$esq  = wp_parse_url( $sdk['sdk'], PHP_URL_SCHEME );
		$origen_sdk = ( $host && $esq ) ? $esq . '://' . $host : 'https://cdn.jsdelivr.net';

		return array(
			// blob: va en script-src y no solo en worker-src: los
			// procesadores de audio del SDK se instalan con
			// AudioWorklet.addModule() desde una URL blob:, y esa carga la
			// gobierna script-src en los navegadores que la aplican. Sin
			// ella, el asistente conecta y no se oye nada.
			'script-src'  => array( $origen_sdk, 'blob:' ),
			'connect-src' => $connect,
			'worker-src'  => array( "'self'", 'blob:' ),
			'media-src'   => array( "'self'", 'blob:' ),
		);
	}
}
