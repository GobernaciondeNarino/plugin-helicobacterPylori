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

	/** Transitorio con el resultado de la última verificación del agente. */
	const VERIF = 'uhp_voz_verif';

	/* Límites de la velocidad de habla. Fuera de este margen la voz deja
	   de ser inteligible, y el proveedor rechaza el valor. 0 significa «no
	   sustituir»: la velocidad que tenga puesta el agente. */
	const VEL_MIN = 0.70;
	const VEL_MAX = 1.20;

	/**
	 * Engancha la ruta REST.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'registrar_rutas' ) );
		// Antes de que se envíen las cabeceras, no durante el contenido:
		// cuando el shortcode se pinta ya es tarde para una cabecera.
		add_action( 'template_redirect', array( $this, 'cabeceras_sin_cache' ) );
	}

	/**
	 * Le dice a la caché que no guarde esta página cuando su salida
	 * depende de quién la pide.
	 *
	 * Solo ocurre con la visibilidad en «editores», que es un estado de
	 * ensayo: ahí el visitante anónimo recibe el tablero sin asistente y
	 * quien puede editar lo recibe con él. Una copia compartida serviría
	 * uno de los dos a todo el mundo.
	 *
	 * `DONOTCACHEPAGE` es el acuerdo que respetan las capas de caché de
	 * WordPress; `nocache_headers()` es para las que están por delante y no
	 * saben nada de ese acuerdo. Y las dos se envían SOLO en las páginas
	 * que llevan el tablero: marcar el sitio entero como no cacheable por
	 * un ajuste de un componente sería desproporcionado, y en un sitio
	 * público el costo lo paga el ciudadano en tiempo de carga.
	 */
	public function cabeceras_sin_cache() {
		if ( ! self::salida_por_usuario() || ! self::configurada() ) {
			return;
		}
		$contenido = UHP_Shortcodes::contenido_de_la_entrada();
		if ( '' === $contenido || ! has_shortcode( $contenido, 'urkunina_dashboard' ) ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
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
			// Visible para quién. Es un eje DISTINTO de «activo»: la
			// entidad necesita poder dejarlo configurado y funcionando
			// sin abrirlo todavía al ciudadano.
			'visibilidad'      => 'todos',
			'agente'           => '',
			// El identificador de la VOZ, que no es el del agente. Vacío
			// deja hablar al agente con la voz que tenga puesta.
			'voz_id'           => '',
			'voz_velocidad'    => 0,
			'modo'             => 'privado',
			'transporte'       => 'websocket',
			'cuota_dia'        => 200,
			// Por dónde se puede entrar: hablando, escribiendo o ambas.
			'entrada'          => 'ambos',
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
	 * ¿Se le pinta el lanzador a QUIEN está pidiendo esta página?
	 *
	 * «Activo» y «visible» son dos ejes distintos y hacen falta los dos.
	 * Una entidad necesita poder dejar el asistente configurado, con su
	 * clave puesta y su agente creado, y ensayarlo en el sitio de verdad
	 * —no en una copia— antes de ponerlo delante de un ciudadano. Con un
	 * solo interruptor eso no se puede: o está apagado y no se puede
	 * probar, o está encendido para todo el mundo.
	 *
	 *   todos     el lanzador se pinta para cualquiera.
	 *   editores  solo para quien pueda editar contenido. El visitante
	 *             anónimo recibe el tablero exactamente como hoy.
	 *   oculto    no se pinta para nadie. El panel sigue en la página y
	 *             lo abre quien lo llame por programa, que es lo que
	 *             necesita un botón propio de la entidad en otra parte
	 *             de la plantilla.
	 *
	 * CUIDADO CON LA CACHÉ. Con «editores» la salida de la página depende
	 * de quién la pide, y eso solo es seguro porque las capas de caché de
	 * página completa dejan de servir copia a quien trae la cookie de
	 * sesión de WordPress. Si la instalación tiene una caché que sirve una
	 * sola copia a todo el mundo, «editores» no protege nada y hay que usar
	 * «oculto». El panel de administración lo advierte.
	 *
	 * @return bool
	 */
	public static function permitido_para_usuario() {
		$v = self::sanear_visibilidad( self::cfg()['visibilidad'] );
		if ( 'editores' === $v ) {
			return function_exists( 'current_user_can' ) && current_user_can( 'edit_posts' );
		}
		// «oculto» SÍ pasa por aquí: el asistente se carga, lo que no se
		// pinta es su botón. Quien no pasa es el visitante anónimo de un
		// sitio en «editores», y para él la página sale byte a byte como
		// si el asistente no existiera.
		return true;
	}

	/**
	 * ¿Depende la salida de quién pide la página?
	 *
	 * Lo pregunta el shortcode para decirle a la caché que no guarde esta
	 * respuesta. Es la única salida del plugin que depende del usuario.
	 *
	 * @return bool
	 */
	public static function salida_por_usuario() {
		return 'editores' === self::sanear_visibilidad( self::cfg()['visibilidad'] );
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
		$c       = self::cfg();
		$modo    = 'publico' === $c['modo'] ? 'publico' : 'privado';
		$entrada = self::sanear_entrada( $c['entrada'] );
		return array(
			// En modo privado el navegador NO necesita el identificador:
			// la sesión la abre con la URL firmada que le da el servidor.
			// Publicarlo solo serviría para que alguien intentara usar el
			// agente por su cuenta.
			'agente'       => 'publico' === $modo ? (string) $c['agente'] : '',
			'modo'         => $modo,
			'transporte'   => 'webrtc' === $c['transporte'] ? 'webrtc' : 'websocket',
			'entrada'      => $entrada,
			// Sustituciones de la voz. Van al navegador porque es él quien
			// las manda al proveedor en el evento de inicio; no son una
			// credencial —sin la clave de la cuenta un identificador de voz
			// no abre nada— y el agente tiene que permitirlas o no surten
			// efecto. Se envían solo si hay algo que sustituir: un evento de
			// sustitución vacío contra un agente que no las admite es una
			// sesión rechazada sin motivo.
			'voz'          => self::sustituciones_tts( $modo ),
			// ¿Puede la sesión declararse «solo texto» también al SERVIDOR?
			// Si no, el canal escrito sigue funcionando —el navegador no
			// abre el micrófono ni reproduce nada— pero el proveedor
			// sintetiza un audio que nadie oye y lo factura. El panel lo
			// dice; aquí no se puede hacer más.
			'textoServidor' => 'publico' === $modo || self::permite( 'text_only' ),
			// En modo solo texto el campo de escritura es la ÚNICA entrada:
			// apagarlo dejaría un panel sin forma de preguntar nada.
			'texto'        => 'texto' === $entrada ? true : ! empty( $c['texto'] ),
			'recordar'     => ! empty( $c['recordar'] ),
			'avisoVersion' => (int) $c['aviso_version'],
			'ruta'         => '/voz/sesion',
			'sdk'          => UHP_Assets::voz_urls(),
		);
	}

	/**
	 * Las sustituciones de voz que de verdad se van a enviar.
	 *
	 * Una sustitución que el agente no admite no degrada la conversación:
	 * la tumba. De modo que solo viaja lo CONFIRMADO por `verificar()`. Lo
	 * configurado y no confirmado se queda en el servidor y el panel lo
	 * dice con todas las letras: preferimos un asistente que funciona con
	 * la voz del agente a uno que no abre por un ajuste de timbre.
	 *
	 * En modo público no hay clave con la que preguntar; ahí se envía lo
	 * configurado y la advertencia está en el panel.
	 *
	 * @param string $modo publico|privado
	 * @return array{id:string,velocidad:float}
	 */
	private static function sustituciones_tts( $modo ) {
		$c  = self::cfg();
		$id = self::sanear_voz_id( $c['voz_id'] );
		$v  = self::sanear_velocidad( $c['voz_velocidad'] );

		if ( 'publico' !== $modo ) {
			if ( ! self::permite( 'voice_id' ) ) {
				$id = '';
			}
			if ( ! self::permite( 'speed' ) ) {
				$v = 0.0;
			}
		}

		return array(
			'id'        => $id,
			'velocidad' => $v,
		);
	}

	/**
	 * Lo que el panel debe advertir sobre las sustituciones configuradas.
	 *
	 * @return array<int,string>
	 */
	public static function avisos_sustituciones() {
		$c     = self::cfg();
		$avisa = array();
		$modo  = 'publico' === $c['modo'] ? 'publico' : 'privado';

		if ( 'publico' === $modo ) {
			if ( '' !== self::sanear_voz_id( $c['voz_id'] ) || self::sanear_velocidad( $c['voz_velocidad'] ) ) {
				$avisa[] = __( 'Con un agente público el plugin no puede verificar nada: la voz y la velocidad se envían tal cual. Si el agente no las admite, la conversación no abrirá.', 'urkunina-5000' );
			}
			return $avisa;
		}

		if ( '' !== self::sanear_voz_id( $c['voz_id'] ) && ! self::permite( 'voice_id' ) ) {
			$avisa[] = __( 'La voz configurada NO se está enviando, porque no está confirmado que el agente admita esa sustitución. El asistente habla con la voz del agente. Habilite «Voice ID» en la pestaña de seguridad del agente y vuelva a verificar.', 'urkunina-5000' );
		}
		if ( self::sanear_velocidad( $c['voz_velocidad'] ) && ! self::permite( 'speed' ) ) {
			$avisa[] = __( 'La velocidad configurada NO se está enviando, por lo mismo. Habilite «Speed» en la pestaña de seguridad del agente y vuelva a verificar.', 'urkunina-5000' );
		}
		if ( 'voz' !== self::sanear_entrada( $c['entrada'] ) && ! self::permite( 'text_only' ) ) {
			$avisa[] = __( 'El canal escrito funciona, pero el proveedor está sintetizando —y facturando— un audio que nadie oye: no está confirmado que el agente admita la sustitución «Text-only mode». Habilítela en la pestaña de seguridad del agente y vuelva a verificar.', 'urkunina-5000' );
		}
		return $avisa;
	}

	/**
	 * Rótulos de la interfaz, para que el JavaScript no lleve texto suelto.
	 *
	 * @return array<string,string>
	 */
	public static function textos() {
		return array(
			'abrir'        => __( 'Preguntar por voz', 'urkunina-5000' ),
			'abrir_texto'  => __( 'Preguntar por escrito', 'urkunina-5000' ),
			'cerrar'       => __( 'Cerrar el asistente', 'urkunina-5000' ),
			'reanudar'     => __( 'Volver a preguntar', 'urkunina-5000' ),
			'aviso_fase'   => __( 'Lea el aviso antes de continuar.', 'urkunina-5000' ),
			'confirmar'    => __( 'Hay un municipio por confirmar.', 'urkunina-5000' ),
			'no'           => __( 'No', 'urkunina-5000' ),
			'aviso_corto'  => __( 'Su voz se envía a un proveedor en el exterior. El tablero funciona igual sin el asistente.', 'urkunina-5000' ),
			'aviso_c_txt'  => __( 'Lo que escriba se envía a un proveedor en el exterior. El tablero funciona igual sin el asistente.', 'urkunina-5000' ),
			'aviso_c_dos'  => __( 'Su voz, o lo que escriba, se envía a un proveedor en el exterior. El tablero funciona igual sin el asistente.', 'urkunina-5000' ),
			'titulo'       => __( 'Asistente de voz', 'urkunina-5000' ),
			'titulo_txt'   => __( 'Asistente por escrito', 'urkunina-5000' ),
			'titulo_dos'   => __( 'Asistente del tablero', 'urkunina-5000' ),
			'aceptar'      => __( 'Acepto y quiero hablar', 'urkunina-5000' ),
			'aceptar_txt'  => __( 'Acepto y quiero escribir', 'urkunina-5000' ),
			'cancelar'     => __( 'Cancelar', 'urkunina-5000' ),
			'retirar'      => __( 'Retirar autorización', 'urkunina-5000' ),
			'terminar'     => __( 'Terminar conversación', 'urkunina-5000' ),
			'escribir'     => __( 'Escriba su pregunta', 'urkunina-5000' ),
			'pasar_texto'  => __( 'Escribir en vez de hablar', 'urkunina-5000' ),
			'enviar'       => __( 'Enviar', 'urkunina-5000' ),
			'conectando'   => __( 'Conectando…', 'urkunina-5000' ),
			'escuchando'   => __( 'Escuchando. Hable cuando quiera.', 'urkunina-5000' ),
			// El modo escrito necesita sus propios rótulos: decirle
			// «escuchando» a quien está teclando, o anunciarle que el
			// asistente «responde» cuando lo que hace es escribir, es
			// describirle una pantalla que no tiene delante.
			'escribiendo'  => __( 'Escriba su pregunta y pulse Enviar.', 'urkunina-5000' ),
			'redactando'   => __( 'El asistente está escribiendo la respuesta.', 'urkunina-5000' ),
			'hablando'     => __( 'El asistente está respondiendo.', 'urkunina-5000' ),
			'cerrada'      => __( 'Conversación terminada.', 'urkunina-5000' ),
			'retirada'     => __( 'Autorización retirada. No se enviará nada más.', 'urkunina-5000' ),
			'sin_micro'    => __( 'No se concedió el micrófono. Puede escribir su pregunta.', 'urkunina-5000' ),
			'sin_micro_no' => __( 'Este navegador no ofrece micrófono. Puede escribir su pregunta.', 'urkunina-5000' ),
			'sin_seguro'   => __( 'El micrófono solo funciona sobre HTTPS.', 'urkunina-5000' ),
			'ocupado'      => __( 'El micrófono lo está usando otra aplicación.', 'urkunina-5000' ),
			'sin_sdk'      => __( 'No se pudo cargar el asistente de voz.', 'urkunina-5000' ),
			'sin_sesion'   => __( 'No se pudo abrir la conversación. Inténtelo más tarde.', 'urkunina-5000' ),
			'pista_sust'   => __( 'Si acaba de configurar una voz o una velocidad, compruebe que el agente tiene habilitadas esas sustituciones.', 'urkunina-5000' ),
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
	 * HAY DOS AVISOS, uno por canal, y la diferencia no es cosmética. El de
	 * voz pide autorización para tratar una muestra de la voz, que es un
	 * dato biométrico; el escrito no trata ninguna. Pedirle a quien va a
	 * teclear que autorice el tratamiento de su voz sería recoger una
	 * autorización por algo que no va a ocurrir, y eso no es un aviso
	 * informado: es un aviso que sobra. Al contrario, servirle el aviso
	 * corto del canal escrito a quien va a encender el micrófono dejaría la
	 * autorización sin informar de lo que importa.
	 *
	 * Lo que NO cambia entre los dos: quién responde, para qué, el
	 * encargado y su país —la transferencia internacional del artículo 26
	 * existe igual con texto— y los derechos del titular.
	 *
	 * @param string $modo 'voz' o 'texto'.
	 * @return array<int,array{t:string,p:string}>
	 */
	public static function aviso_bloques( $modo = 'voz' ) {
		$c   = self::cfg();
		$txt = 'texto' === $modo;

		$bloques = array(
			array(
				't' => __( 'Qué va a pasar', 'urkunina-5000' ),
				'p' => $txt
					? __( 'Si acepta, se abre una conversación escrita. Lo que usted teclee se envía a un proveedor externo que lo lee, responde por escrito y, cuando usted se lo pide, cambia los filtros de este tablero. NO se le pide el micrófono y no se captura ni se envía ninguna grabación de su voz.', 'urkunina-5000' )
					: __( 'Si acepta, el navegador le pedirá permiso para usar el micrófono. Mientras la conversación esté abierta, su voz se envía a un proveedor externo que la convierte en texto, responde por voz y, cuando usted se lo pide, cambia los filtros de este tablero.', 'urkunina-5000' ),
			),
			array(
				't' => __( 'Quién responde por sus datos', 'urkunina-5000' ),
				'p' => __( 'Gobernación de Nariño — Secretaría de Tecnologías de la Información, las Comunicaciones, Innovación y Gobierno Abierto. Pasto, Nariño.', 'urkunina-5000' ),
			),
			array(
				't' => __( 'Qué se trata', 'urkunina-5000' ),
				'p' => $txt
					? __( 'El texto de lo que usted escriba y los datos técnicos de la conexión. No se trata ninguna muestra de su voz. No se le pide su nombre, ni su documento, ni su historia clínica, y el asistente no los necesita.', 'urkunina-5000' )
					: __( 'El audio de lo que usted diga, su transcripción en texto y los datos técnicos de la conexión. No se le pide su nombre, ni su documento, ni su historia clínica, y el asistente no los necesita.', 'urkunina-5000' ),
			),
			array(
				't' => __( 'Para qué', 'urkunina-5000' ),
				'p' => __( 'Únicamente para responder sus preguntas sobre las cifras públicas del proyecto URKUNINA 5000 y para mover los filtros de este tablero.', 'urkunina-5000' ),
			),
			array(
				't' => __( 'Quién lo procesa y dónde', 'urkunina-5000' ),
				// La transferencia internacional NO desaparece porque el
				// dato sea texto: sigue saliendo de Colombia y el artículo
				// 26 sigue aplicando igual. Decir lo contrario sería el
				// tipo de atajo que un aviso no se puede permitir.
				'p' => __( 'ElevenLabs Inc., con servidores fuera de Colombia. Esto es una transferencia internacional de sus datos, prevista en el artículo 26 de la Ley 1581 de 2012.', 'urkunina-5000' ) . ' ' . trim( (string) $c['retencion'] ),
			),
			array(
				't' => __( 'Con qué fundamento', 'urkunina-5000' ),
				'p' => $txt
					? __( 'Con su autorización previa, expresa e informada, que es lo que usted da al pulsar «Acepto y quiero escribir» (artículo 9 de la Ley 1581 de 2012). Es voluntaria: puede negarse y no pierde nada.', 'urkunina-5000' )
					: __( 'Con su autorización previa, expresa e informada, que es lo que usted da al pulsar «Acepto y quiero hablar» (artículo 9 de la Ley 1581 de 2012). Es voluntaria: puede negarse y no pierde nada.', 'urkunina-5000' ),
			),
			$txt
				? array(
					// En modo escrito no hay dato biométrico, pero el
					// riesgo de que el ciudadano cuente de más no se va:
					// cambia de canal. El artículo 6 prohíbe tratar datos
					// sensibles salvo excepciones, y lo que nadie pidió
					// tampoco se quiere recibir.
					't' => __( 'No escriba datos personales ni de salud', 'urkunina-5000' ),
					'p' => __( 'Lo que teclee queda en la transcripción. No escriba su nombre, su documento, sus síntomas, sus resultados de laboratorio ni los de otra persona: son datos sensibles en el sentido del artículo 6 de la Ley 1581 de 2012, el asistente no puede ayudarle con ellos y esta entidad no los está pidiendo.', 'urkunina-5000' ),
				)
				: array(
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
			$txt
				? array(
					't' => __( 'El tablero funciona sin esto', 'urkunina-5000' ),
					'p' => __( 'Los filtros del tablero —indicador, zona, subregión y municipio— se manejan con el teclado y con el ratón, sin asistente alguno. Si no acepta, no pierde ni una cifra.', 'urkunina-5000' ),
				)
				: array(
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
	 * @param string $id  Identificador del tablero que lo contiene.
	 * @param array  $opc Ajustes de ESTA página: 'entrada' y 'visible'.
	 *                    Solo pueden estrechar lo que permite el sitio.
	 * @return string
	 */
	public static function control_html( $id, $opc = array() ) {
		if ( ! self::configurada() ) {
			return '';
		}
		$c = self::cfg();

		/* Un atributo del shortcode puede ESTRECHAR lo que el sitio
		   permite; nunca ampliarlo. Si la entidad decidió que en este
		   sitio solo se entra por escrito, una página no puede encender
		   el micrófono escribiendo un atributo en el contenido de un
		   post: el contenido lo edita cualquiera con permiso de edición,
		   y la decisión sobre el canal por el que sale la voz de un
		   ciudadano no es una decisión de redacción. */
		$entrada = self::sanear_entrada( $c['entrada'] );
		if ( 'ambos' === $entrada && isset( $opc['entrada'] ) ) {
			$entrada = self::sanear_entrada( $opc['entrada'] );
		}

		/* Lo mismo con la visibilidad: el atributo solo puede ocultar.

		   Y hay dos cosas distintas aquí. En «editores» no se pinta NADA
		   para el visitante anónimo: si se pintara el panel sin su botón,
		   la página le quedaría anunciando «su voz se envía a un proveedor
		   en el exterior» junto a un hueco donde no hay nada que pulsar.
		   En «oculto» sí se pinta el panel y se calla el botón, porque eso
		   es justo lo que necesita una plantilla que quiera abrirlo desde
		   un botón propio en otra parte de la página. */
		$vis = self::sanear_visibilidad( $c['visibilidad'] );
		if ( isset( $opc['visible'] ) && ! $opc['visible'] ) {
			$vis = 'oculto';
		}
		if ( 'editores' === $vis && ! self::permitido_para_usuario() ) {
			return '';
		}
		$visible = ( 'oculto' !== $vis );

		$con_voz   = 'texto' !== $entrada;
		$con_texto = 'voz' !== $entrada;

		$t   = self::textos();
		$idv = $id . '-voz';

		// El rótulo corto nombra lo que de verdad va a salir del
		// navegador. Con los dos canales abiertos, los dos.
		if ( 'ambos' === $entrada ) {
			$corto = $t['aviso_c_dos'];
		} elseif ( $con_voz ) {
			$corto = $t['aviso_corto'];
		} else {
			$corto = $t['aviso_c_txt'];
		}

		// Con los dos canales abiertos, un rótulo que no prometa solo uno:
		// quien navega por regiones oye este nombre antes de elegir.
		if ( 'ambos' === $entrada ) {
			$titulo = $t['titulo_dos'];
		} elseif ( $con_voz ) {
			$titulo = $t['titulo'];
		} else {
			$titulo = $t['titulo_txt'];
		}

		ob_start();
		?>
		<div class="uhp-voz<?php echo $visible ? '' : ' uhp-voz--sin-lanzador'; ?>"
			id="<?php echo esc_attr( $idv ); ?>" data-uhp-voz
			data-uhp-voz-entrada="<?php echo esc_attr( $entrada ); ?>"
			role="complementary"
			aria-label="<?php echo esc_attr( $titulo ); ?>">

			<div class="uhp-voz__barra">
				<?php if ( $visible ) : ?>
				<div class="uhp-voz__botones">
				<?php endif; ?>
				<?php
				/* Salen DESHABILITADOS y los habilita el script al
				   cablearlos. Un botón de micrófono que se puede pulsar y
				   no hace nada porque el JavaScript no llegó es peor que
				   no tenerlo: promete algo que no va a ocurrir.

				   Los dos gobiernan el MISMO panel, así que los dos
				   declaran aria-controls contra él y el script mantiene
				   los dos aria-expanded a la vez: quien llegue al segundo
				   botón con el panel ya abierto tiene que enterarse de
				   que está abierto, no creer que lo va a abrir. */
				if ( $visible && $con_voz ) :
					?>
					<button type="button" class="uhp-voz__abrir" data-uhp-voz-accion="abrir"
						data-uhp-voz-canal="voz" disabled
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
					<?php
				endif;
				if ( $visible && $con_texto ) :
					?>
					<button type="button" class="uhp-voz__abrir uhp-voz__abrir--texto"
						data-uhp-voz-accion="abrir" data-uhp-voz-canal="texto" disabled
						aria-expanded="false" aria-controls="<?php echo esc_attr( $idv ); ?>-panel">
						<span class="uhp-voz__icono" aria-hidden="true">
							<svg viewBox="0 0 16 16" width="16" height="16" focusable="false">
								<path d="M2.4 3.2h11.2v7.2H6.8L4 13v-2.6H2.4Z" fill="none" stroke="currentColor" stroke-width="1.5"/>
								<path d="M5 6.4h6M5 8.4h4" fill="none" stroke="currentColor" stroke-width="1.5"/>
							</svg>
						</span>
						<span><?php echo esc_html( $t['abrir_texto'] ); ?></span>
					</button>
					<?php
				endif;
				?>
				<?php if ( $visible ) : ?>
				</div>
					<p class="uhp-voz__aviso-corto"><?php echo esc_html( $corto ); ?></p>
				<?php endif; ?>
			</div>

			<div class="uhp-voz__panel" id="<?php echo esc_attr( $idv ); ?>-panel"
				data-uhp-voz-zona="panel" hidden></div>

			<?php
			/* El aviso completo viaja en el HTML y lo imprime PHP, no lo
			   compone el script ni llega de la configuración de una
			   cuenta ajena: así puede auditarse en el código fuente de
			   la página. Va en un <template> porque solo se muestra al
			   pulsar; sin JavaScript los botones salen deshabilitados y
			   lo que queda visible es el aviso corto de arriba.

			   Uno por canal, y solo los de los canales que esta página
			   ofrece: enviar el aviso de la voz a una página donde no se
			   puede hablar es mandar al navegador un texto que nadie va a
			   leer y que describe algo que no va a pasar. */
			if ( $con_voz ) :
				?>
				<template data-uhp-voz-zona="aviso">
					<?php foreach ( self::aviso_bloques( 'voz' ) as $b ) : ?>
						<section class="uhp-voz__bloque">
							<h4><?php echo esc_html( $b['t'] ); ?></h4>
							<p><?php echo esc_html( $b['p'] ); ?></p>
						</section>
					<?php endforeach; ?>
				</template>
				<?php
			endif;
			if ( $con_texto ) :
				?>
				<template data-uhp-voz-zona="aviso-texto">
					<?php foreach ( self::aviso_bloques( 'texto' ) as $b ) : ?>
						<section class="uhp-voz__bloque">
							<h4><?php echo esc_html( $b['t'] ); ?></h4>
							<p><?php echo esc_html( $b['p'] ); ?></p>
						</section>
					<?php endforeach; ?>
				</template>
				<?php
			endif;
			?>

			<p class="uhp-voz__sr" data-uhp-voz-zona="estado" role="status" aria-live="polite"></p>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ================================================================= */
	/* Ruta de sesión                                                    */
	/* ================================================================= */

	/* ================================================================= */
	/* Verificación del agente                                           */
	/* ================================================================= */

	/**
	 * Le pregunta a ElevenLabs si esto está como el plugin cree.
	 *
	 * POR QUÉ EXISTE. «Configurar la API» consistía en pegar una cadena a
	 * ciegas: no se comprobaba que la clave sirviera, ni que el agente
	 * existiera, ni —lo que de verdad rompe— qué sustituciones admite. Y
	 * las sustituciones vienen DESACTIVADAS por omisión en la pestaña de
	 * seguridad del agente, de modo que configurar aquí una voz y no
	 * habilitarla allí no degrada nada: tumba la conversación entera, con
	 * un «Override is not allowed for this AI agent» que el visitante no ve
	 * y el administrador no sabe dónde buscar.
	 *
	 * El resultado se guarda en un transitorio y `config_front()` lo usa
	 * para NO enviar una sustitución que no esté confirmada. Preferimos un
	 * asistente que funciona con la voz del agente, y un panel que lo dice
	 * con todas las letras, a un asistente que no abre.
	 *
	 * @return array Informe; 'ok' => bool.
	 */
	public static function verificar() {
		$c     = self::cfg();
		$clave = self::clave_api();

		$informe = array(
			'ok'       => false,
			'mensaje'  => '',
			'permite'  => array(
				'voice_id'  => false,
				'speed'     => false,
				'text_only' => false,
			),
			'idioma'   => '',
			'modelo'   => '',
			'voz'      => '',
			'solo_txt' => false,
			'momento'  => time(),
		);

		if ( '' === $c['agente'] ) {
			$informe['mensaje'] = __( 'Falta el identificador del agente.', 'urkunina-5000' );
			set_transient( self::VERIF, $informe, DAY_IN_SECONDS );
			return $informe;
		}
		if ( '' === $clave ) {
			$informe['mensaje'] = __( 'No hay clave de API, así que no hay forma de preguntarle nada al proveedor. En modo público el plugin no puede verificar: lo que usted configure se envía tal cual.', 'urkunina-5000' );
			set_transient( self::VERIF, $informe, DAY_IN_SECONDS );
			return $informe;
		}

		$r = wp_remote_get(
			self::api_base() . '/v1/convai/agents/' . rawurlencode( $c['agente'] ),
			array(
				'timeout' => 12,
				'headers' => array(
					'xi-api-key' => $clave,
					'Accept'     => 'application/json',
				),
			)
		);

		if ( is_wp_error( $r ) ) {
			/* El mensaje del transporte se resume y NO se imprime tal cual:
			   puede traer la URL completa, y la URL de esta petición lleva
			   el identificador del agente. */
			$informe['mensaje'] = __( 'No se pudo contactar con ElevenLabs. Revise la salida a internet del servidor.', 'urkunina-5000' );
			set_transient( self::VERIF, $informe, HOUR_IN_SECONDS );
			return $informe;
		}

		$codigo = (int) wp_remote_retrieve_response_code( $r );
		if ( 401 === $codigo || 403 === $codigo ) {
			$informe['mensaje'] = __( 'La clave de API no sirve, o no tiene permiso sobre este agente.', 'urkunina-5000' );
			set_transient( self::VERIF, $informe, HOUR_IN_SECONDS );
			return $informe;
		}
		if ( 404 === $codigo ) {
			$informe['mensaje'] = __( 'La clave sirve, pero ese agente no existe en esta cuenta. Compruebe que no pegó el identificador de la voz en el campo del agente.', 'urkunina-5000' );
			set_transient( self::VERIF, $informe, HOUR_IN_SECONDS );
			return $informe;
		}
		if ( 200 !== $codigo ) {
			/* translators: %d: código HTTP. */
			$informe['mensaje'] = sprintf( __( 'ElevenLabs respondió %d. Inténtelo más tarde.', 'urkunina-5000' ), $codigo );
			set_transient( self::VERIF, $informe, HOUR_IN_SECONDS );
			return $informe;
		}

		$d = json_decode( (string) wp_remote_retrieve_body( $r ), true );
		if ( ! is_array( $d ) ) {
			$informe['mensaje'] = __( 'ElevenLabs respondió algo que no es JSON.', 'urkunina-5000' );
			set_transient( self::VERIF, $informe, HOUR_IN_SECONDS );
			return $informe;
		}

		$sust = isset( $d['platform_settings']['overrides']['conversation_config_override'] )
			? (array) $d['platform_settings']['overrides']['conversation_config_override']
			: array();

		$informe['ok']      = true;
		$informe['mensaje'] = __( 'La clave sirve y el agente existe.', 'urkunina-5000' );
		$informe['permite'] = array(
			'voice_id'  => ! empty( $sust['tts']['voice_id'] ),
			'speed'     => ! empty( $sust['tts']['speed'] ),
			'text_only' => ! empty( $sust['conversation']['text_only'] ),
		);

		$cc                 = isset( $d['conversation_config'] ) ? (array) $d['conversation_config'] : array();
		$informe['idioma']  = isset( $cc['agent']['language'] ) ? (string) $cc['agent']['language'] : '';
		$informe['modelo']  = isset( $cc['tts']['model_id'] ) ? (string) $cc['tts']['model_id'] : '';
		$informe['voz']     = isset( $cc['tts']['voice_id'] ) ? (string) $cc['tts']['voice_id'] : '';
		$informe['solo_txt'] = ! empty( $cc['conversation']['text_only'] );

		set_transient( self::VERIF, $informe, 12 * HOUR_IN_SECONDS );
		return $informe;
	}

	/**
	 * Lo último que dijo la verificación, o null si nunca se hizo.
	 *
	 * @return array|null
	 */
	public static function verificacion() {
		$v = get_transient( self::VERIF );
		return is_array( $v ) ? $v : null;
	}

	/**
	 * ¿Está CONFIRMADO que el agente admite esta sustitución?
	 *
	 * Sin verificación devuelve false, y eso es deliberado: enviar una
	 * sustitución sin confirmar es arriesgar la conversación entera de cada
	 * visitante por un ajuste de timbre.
	 *
	 * En modo público no hay clave con la que preguntar, de modo que ahí se
	 * envía lo configurado y la responsabilidad es de quien lo configuró;
	 * el panel lo advierte.
	 *
	 * @param string $cual voice_id|speed|text_only
	 * @return bool
	 */
	public static function permite( $cual ) {
		$v = self::verificacion();
		if ( ! is_array( $v ) || empty( $v['ok'] ) ) {
			return false;
		}
		return ! empty( $v['permite'][ $cual ] );
	}

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

		/* Y la visibilidad, que es una puerta de GASTO y no solo de
		   maquetación. Sin esto, un sitio con la visibilidad en «editores»
		   —«lo tengo configurado pero todavía no lo abro al público»—
		   seguía repartiendo URLs firmadas facturables a cualquier
		   visitante anónimo: el eje protegía el botón y no el bolsillo, y
		   `origen_valido()` pasa sin problema porque el navegador manda
		   `Origin` en cualquier POST del propio dominio.

		   «Oculto» sí pasa, y debe pasar: ahí el asistente está publicado
		   y lo que falta es el botón, que lo pone la plantilla. */
		if ( ! self::permitido_para_usuario() ) {
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
	 * Sanea el identificador de la VOZ, que no es el del agente.
	 *
	 * Son dos cosas distintas y se confunden con facilidad: el agente es la
	 * conversación —su prompt, sus herramientas, su idioma— y la voz es
	 * solo el timbre con que la lee. El del agente empieza por «agent_»;
	 * el de la voz es una cadena alfanumérica sin prefijo. Un panel que los
	 * llamara igual acabaría con el identificador equivocado en el campo
	 * equivocado y con una sesión que falla sin decir por qué.
	 *
	 * Solo letras y números: este valor viaja al navegador y de allí al
	 * proveedor dentro de un JSON. No es una credencial —sin la clave de la
	 * cuenta no sirve de nada—, pero tampoco tiene por qué admitir nada más.
	 *
	 * @param string $texto Valor crudo.
	 * @return string Identificador válido, o cadena vacía.
	 */
	public static function sanear_voz_id( $texto ) {
		$v = is_string( $texto ) ? trim( $texto ) : '';
		return preg_match( '/^[A-Za-z0-9]{16,48}$/', $v ) ? $v : '';
	}

	/**
	 * Sanea la velocidad de habla. Fuera del margen, se descarta.
	 *
	 * Descartar y no recortar: si alguien escribe 3 queriendo decir algo,
	 * dejarle 1,20 le hace creer que se guardó lo que pidió. Un campo
	 * vacío dice la verdad —no se sustituye nada— y el panel lo explica.
	 *
	 * @param mixed $valor Valor crudo.
	 * @return float 0 si no se sustituye.
	 */
	public static function sanear_velocidad( $valor ) {
		if ( ! is_scalar( $valor ) || '' === trim( (string) $valor ) ) {
			return 0.0;
		}
		$v = (float) str_replace( ',', '.', (string) $valor );
		if ( $v < self::VEL_MIN || $v > self::VEL_MAX ) {
			return 0.0;
		}
		return round( $v, 2 );
	}

	/**
	 * Normaliza el canal de entrada.
	 *
	 * @param mixed $valor Valor crudo.
	 * @return string ambos|voz|texto
	 */
	public static function sanear_entrada( $valor ) {
		$v = is_string( $valor ) ? strtolower( trim( $valor ) ) : '';
		return in_array( $v, array( 'voz', 'texto' ), true ) ? $v : 'ambos';
	}

	/**
	 * Normaliza la visibilidad.
	 *
	 * @param mixed $valor Valor crudo.
	 * @return string todos|editores|oculto
	 */
	public static function sanear_visibilidad( $valor ) {
		$v = is_string( $valor ) ? strtolower( trim( $valor ) ) : '';
		return in_array( $v, array( 'editores', 'oculto' ), true ) ? $v : 'todos';
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
			array(
				'name'        => 'uhp_consultar_perfil',
				'description' => 'El perfil de los 5.000 participantes del estudio: género, pertenencia étnica, nivel educativo, régimen de salud y estado nutricional, con el rango de edades. Úsela para «quiénes participaron», «cuántas mujeres» o «qué edades». Es una cifra DEPARTAMENTAL del conjunto del estudio: no cambia con el filtro del tablero y no es el perfil de los municipios seleccionados. Cuando el informe solo registró el dato a parte de los participantes, la respuesta lo dice y da el denominador.',
				'espera'      => true,
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'aspecto' => array(
							'type'        => 'string',
							'description' => 'Qué parte del perfil: «genero», «etnia», «educativo», «salud», «nutricional». Omítalo o use «todo» para recibirlo completo.',
						),
					),
					'required'   => array(),
				),
			),
			array(
				'name'        => 'uhp_comparar_subregiones',
				'description' => 'Lee el gráfico de barras: el orden de las subregiones por el indicador activo, de mayor a menor. Úsela para «cuál está peor», «compare las subregiones» o «qué dice la gráfica». Devuelve cifras SUBREGIONALES publicadas, que NO son el promedio de los municipios intervenidos ni el promedio de la selección, con cuántos municipios de cada subregión intervino el proyecto, con los empates marcados, y nombrando las subregiones sin cifra publicada en vez de omitirlas. La respuesta viene formateada para el canal de la conversación: por voz da los extremos y un par más, por escrito la lista entera con el rótulo pegado a cada cifra.',
				'espera'      => true,
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'cuantas' => array(
							'type'        => 'integer',
							'description' => 'Cuántas subregiones devolver, de la más alta hacia abajo. Omítalo para el valor por omisión del canal: por voz tres, porque una enumeración hablada de once no se retiene; por escrito todas. Si recorta, la respuesta dice cuántas quedaron fuera.',
						),
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
				'- En español de Colombia, de usted, claro y breve.',
				'- No inventas NUNCA una cifra ni un nombre de municipio. Si no lo tienes, lo dices.',
				'',
				'DOS CANALES. ESTA CONVERSACIÓN VA POR: {{canal}}',
				'- Si dice «voz», el visitante te escucha: frases cortas, y las cifras dichas como se dicen, «treinta y cinco coma uno por ciento». Nada de listas largas: una enumeración hablada de trece elementos no se retiene.',
				'- Si dice «texto», el visitante te LEE y puede copiar lo que escribas. Entonces: cifras en dígitos («35,1 %»), listas cortas si ayudan, y cada vez que des una cifra nombra de qué territorio es y de qué indicador. Lo que se copia viaja sin el resto de la conversación: una cifra suelta sin su rótulo se vuelve un dato falso en cuanto alguien la pega en otra parte.',
				'',
				'CÓMO ESTABA EL TABLERO AL EMPEZAR',
				'- Indicador {{indicador}}; zona {{zona}}; subregión {{subregion}}; municipio {{municipio}}; {{municipios_visibles}} municipios a la vista; {{casos_visibles}} casos de cáncer gástrico en la selección; promedio {{promedio_visible}}.',
				'- Eso es el estado INICIAL y no se actualiza solo. En cuanto el visitante toque algo —o lo toques tú— esos números dejan de valer: vuelve a llamar a uhp_leer_estado_tablero antes de describir la pantalla.',
				'',
				'DE DÓNDE SACAS LOS DATOS',
				'- Del tablero, con las herramientas. Nunca de tu memoria.',
				'- Antes de describir lo que se está viendo, llama a uhp_leer_estado_tablero.',
				'- Antes de nombrar varios municipios, llama a uhp_listar_municipios.',
				'- Para comparar subregiones, o cuando te pregunten cuál está peor o qué dice la gráfica, llama a uhp_comparar_subregiones. Eso es lo que dibuja el gráfico de barras del tablero.',
				'- Para quiénes participaron —género, etnia, educación, régimen de salud, estado nutricional, edades—, llama a uhp_consultar_perfil. Esas cifras son del conjunto del estudio y NO cambian con el filtro: no las presentes como el perfil de los municipios seleccionados.',
				'- Si te preguntan por un dato sin pedir verlo, usa uhp_consultar_municipio y NO muevas la pantalla.',
				'',
				'LAS TRES REGLAS DE HONESTIDAD DEL PROYECTO',
				'1. Un cero es un dato, no un hueco. «Ningún caso detectado» no es «sin información».',
				'2. Nueve de los 64 municipios de Nariño no entraron en el estudio. Ahí no falta el dato: faltó el tamizaje. Dilo con esas palabras.',
				'3. Cuando una herramienta te devuelva una cifra marcada como «de la subregión, no del municipio», dilo. No la presentes como propia del municipio.',
				'4. El gráfico de barras lleva cifras SUBREGIONALES publicadas. No son el promedio de los municipios que el proyecto intervino —la subregión incluye municipios donde no estuvo— y no son el «promedio» que te da uhp_leer_estado_tablero, que sí se calcula sobre la selección. Son tres cosas distintas y no se intercambian.',
				'5. Si uhp_comparar_subregiones te nombra subregiones sin cifra publicada, nómbralas también tú. No digas «estas son las subregiones» cuando son las que tienen dato.',
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
		$c       = self::cfg();
		$entrada = self::sanear_entrada( $c['entrada'] );

		// En modo solo texto no hay micrófono, no hay AudioContext y no hay
		// worklets: la receta se encoge de verdad, no por optimismo. Una
		// entidad que solo quiera el canal escrito no tiene por qué abrir
		// blob: en su política por un audio que nunca va a sonar.
		$con_voz = 'texto' !== $entrada;

		/* WebRTC solo se usa de verdad en modo PÚBLICO. En privado,
		   `credenciales()` abre siempre con la URL firmada por WebSocket y
		   el ajuste de transporte se ignora; declarar LiveKit en la receta
		   sería pedirle a la entidad que abra cuatro orígenes con comodín
		   en su política para un transporte que su sitio no usa. */
		$webrtc = $con_voz && 'webrtc' === $c['transporte'] && 'publico' === $c['modo'];

		$connect = array( 'https://api.elevenlabs.io', 'wss://api.elevenlabs.io' );
		if ( $webrtc ) {
			// El transporte WebRTC va por LiveKit: lo declara la propia
			// dependencia del SDK. Con WebSocket no hace falta ninguno, y
			// una sesión de solo texto es siempre WebSocket.
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

		$receta = array(
			'script-src'  => array( $origen_sdk ),
			'connect-src' => $connect,
		);

		if ( $con_voz ) {
			// blob: va en script-src y no solo en worker-src: los
			// procesadores de audio del SDK se instalan con
			// AudioWorklet.addModule() desde una URL blob:, y esa carga la
			// gobierna script-src en los navegadores que la aplican. Sin
			// ella, el asistente conecta y no se oye nada.
			//
			// El SDK tiene un segundo recurso: si blob: falla, reintenta
			// con data:. NO se añade data: a la receta. Permitido blob:, el
			// segundo recurso no se usa nunca, y data: en script-src abre
			// una puerta que no hace falta abrir. Quien necesite una
			// política sin blob: puede auto-hospedar los dos worklets y
			// pasarlos en `workletPaths`; está explicado en
			// docs/voz/puesta-en-marcha.md.
			$receta['script-src'][] = 'blob:';
			$receta['worker-src']   = array( "'self'", 'blob:' );
			$receta['media-src']    = array( "'self'", 'blob:' );
		}

		return $receta;
	}
}
