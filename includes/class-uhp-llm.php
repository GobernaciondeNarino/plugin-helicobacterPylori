<?php
/**
 * Los tres proveedores de modelo de lenguaje, detrás de una sola puerta.
 *
 * POR QUÉ ESTA CLASE EXISTE
 *
 * El asistente del tablero necesita tres cosas: entender la pregunta,
 * llamar a las herramientas que mueven el tablero, y redactar la
 * respuesta. Eso lo hace un modelo de lenguaje. La entidad puede tener
 * clave de Anthropic, de OpenAI o de Google, y ninguna razón para
 * preferir una: aquí caben las tres y se elige en un desplegable.
 *
 * DOS DECISIONES QUE CONVIENE NO DESHACER
 *
 *  1. HTTP CRUDO, SIN SDK. Los tres publican su paquete de Composer y
 *     ninguno se usa aquí. No es pereza: este plugin convive con otros en
 *     el mismo sitio de la Gobernación, y dos plugins que cargan sendos
 *     autoloaders de Composer con versiones distintas de la misma
 *     biblioteca es exactamente el conflicto que hay que evitar. Un
 *     `wp_remote_post()` no puede chocar con nada.
 *
 *  2. UN FORMATO NEUTRO EN EL MEDIO. Ni el navegador ni la transcripción
 *     guardada saben qué proveedor hay detrás. Cada adaptador traduce en
 *     los dos sentidos. Así, cambiar de proveedor a mitad de la tarde no
 *     corrompe una conversación abierta, y el día que haya un cuarto
 *     proveedor se añade un adaptador y no se toca nada más.
 *
 * EL FORMATO NEUTRO
 *
 *   array(
 *     array( 'rol' => 'usuario',    'texto' => '…' ),
 *     array( 'rol' => 'asistente',  'texto' => '…', 'llamadas' => array(
 *       array( 'id' => '…', 'nombre' => 'uhp_…', 'args' => array() ) ) ),
 *     array( 'rol' => 'herramienta', 'resultados' => array(
 *       array( 'id' => '…', 'nombre' => 'uhp_…', 'salida' => '…' ) ) ),
 *   )
 *
 * @package Urkunina5000
 */

namespace GobernacionNarino\Urkunina;

defined( 'ABSPATH' ) || exit;

/**
 * Adaptadores de Claude, OpenAI y Gemini.
 */
final class UHP_LLM {

	/** Cuánto espera el servidor a que el proveedor conteste. */
	const ESPERA = 45;

	/**
	 * Tope de la respuesta, según cuánto se le deja pensar.
	 *
	 * HASTA LA 2.0 ERA 2.000 PARA TODO, y no alcanzaba. Los tres modelos
	 * por omisión razonan antes de responder, y en los tres el tope cuenta
	 * el razonamiento ADEMÁS del texto: con el esfuerzo medio que OpenAI y
	 * Gemini usan si no se les dice nada, el modelo podía gastarse los
	 * 2.000 pensando y devolver una respuesta vacía. Eso es lo que
	 * documentan los tres («including thought tokens», «including visible
	 * output tokens and reasoning tokens», «thinking counts toward
	 * max_tokens»).
	 *
	 * El tope es un TECHO, no un gasto: se cobra lo que se usa. Las
	 * respuestas siguen siendo de dos o tres frases porque así lo pide el
	 * prompt; lo que cambia es que pensar ya no deja al visitante sin
	 * respuesta. Y si aun así se corta, el adaptador lo dice con
	 * UHP-LLM-13 en vez de callarlo.
	 *
	 * @param string $esfuerzo low|medium|high.
	 * @return int
	 */
	public static function tope_salida( $esfuerzo ) {
		switch ( $esfuerzo ) {
			case 'high':
				return 16000;
			case 'medium':
				return 12000;
			default:
				return 6000;
		}
	}

	/**
	 * ¿Admite este modelo de Claude el parámetro de esfuerzo?
	 *
	 * Por LISTA DE INCLUSIÓN y no de exclusión: un modelo que no lo admite
	 * rechaza la petición entera con 400 (Haiku 4.5, Sonnet 4.5), y uno
	 * nuevo que no esté en la lista solo pierde el ajuste y usa su valor
	 * por omisión. Equivocarse hacia el lado de no mandarlo no rompe nada.
	 *
	 * @param string $modelo Modelo.
	 * @return bool
	 */
	private static function claude_con_esfuerzo( $modelo ) {
		return (bool) preg_match( '#^claude-(fable-5|mythos-5|opus-5|opus-4-[5-9]|sonnet-5|sonnet-4-6|haiku-5)#', (string) $modelo );
	}

	/**
	 * ¿Tiene este modelo de Claude reintento del lado del servidor?
	 *
	 * Los modelos actuales pasan clasificadores de seguridad que pueden
	 * declinar una pregunta corriente —y este tablero habla de una
	 * infección y de cáncer gástrico—. Con `fallbacks: "default"` la API
	 * reintenta en el modelo que Anthropic recomienda para ese caso, en la
	 * misma petición, en vez de dejar al ciudadano sin respuesta. Solo en
	 * las familias donde está documentado; Haiku 5.5 no lo tiene.
	 *
	 * @param string $modelo Modelo.
	 * @return bool
	 */
	private static function claude_con_reintento( $modelo ) {
		return (bool) preg_match( '#^claude-(fable-5|mythos-5|opus-5|sonnet-5)#', (string) $modelo );
	}

	/**
	 * El modelo recomendado de cada proveedor.
	 *
	 * Se usa cuando el administrador deja el campo vacío, que es lo que
	 * debe pasar si no sabe qué poner. Son nombres que envejecen: el campo
	 * del panel existe justamente para no tener que esperar a una
	 * actualización del plugin cuando salga el siguiente.
	 *
	 * @param string $proveedor claude|openai|gemini
	 * @return string
	 */
	public static function modelo_por_defecto( $proveedor ) {
		switch ( $proveedor ) {
			case 'openai':
				return 'gpt-6-luna';
			case 'gemini':
				return 'gemini-3.8-flash';
			case 'claude':
			default:
				return 'claude-opus-5-5';
		}
	}

	/**
	 * Nombre humano del proveedor, para el panel.
	 *
	 * @param string $proveedor Clave.
	 * @return string
	 */
	public static function nombre( $proveedor ) {
		switch ( $proveedor ) {
			case 'openai':
				return 'OpenAI';
			case 'gemini':
				return 'Google Gemini';
			case 'claude':
			default:
				return 'Anthropic Claude';
		}
	}

	/**
	 * Dónde saca la entidad su clave, para decirlo en el panel.
	 *
	 * @param string $proveedor Clave.
	 * @return string URL.
	 */
	public static function consola( $proveedor ) {
		switch ( $proveedor ) {
			case 'openai':
				return 'https://platform.openai.com/api-keys';
			case 'gemini':
				return 'https://aistudio.google.com/apikey';
			case 'claude':
			default:
				return 'https://console.anthropic.com/settings/keys';
		}
	}

	/* ================================================================= */
	/* Puerta única                                                      */
	/* ================================================================= */

	/**
	 * Un turno de conversación contra el proveedor que toque.
	 *
	 * @param array $pet {
	 *     @type string $proveedor    claude|openai|gemini
	 *     @type string $clave        Clave de API.
	 *     @type string $modelo       Nombre del modelo, o vacío.
	 *     @type string $sistema      Instrucciones del sistema.
	 *     @type array  $mensajes     Conversación en formato neutro.
	 *     @type array  $herramientas Definiciones (UHP_Voz::definiciones_herramientas()).
	 *     @type string $esfuerzo     low|medium|high (solo Claude).
	 * }
	 * @return array {
	 *     @type bool   $ok       Si hubo respuesta utilizable.
	 *     @type string $texto    Lo que el modelo escribió.
	 *     @type array  $llamadas Herramientas que quiere ejecutar.
	 *     @type string $error    Mensaje para el registro, nunca para el visitante.
	 *     @type int    $codigo   HTTP, cuando lo hay.
	 *     @type string $clase    Código UHP del fallo, vacío si no lo hubo.
	 *     @type array  $avisos   Códigos UHP de lo que no corta la respuesta.
	 *     @type array  $uso      Tokens de entrada y salida, cuando el proveedor los da.
	 * }
	 */
	public static function conversar( $pet ) {
		$proveedor = isset( $pet['proveedor'] ) ? $pet['proveedor'] : 'claude';
		$modelo    = ! empty( $pet['modelo'] ) ? $pet['modelo'] : self::modelo_por_defecto( $proveedor );

		switch ( $proveedor ) {
			case 'openai':
				return self::openai( $pet, $modelo );
			case 'gemini':
				return self::gemini( $pet, $modelo );
			case 'claude':
			default:
				return self::claude( $pet, $modelo );
		}
	}

	/**
	 * Un trozo del cuerpo, en una línea y sin desbordar el registro.
	 *
	 * @param string $cuerpo Cuerpo crudo.
	 * @return string
	 */
	private static function recortar( $cuerpo ) {
		$t = trim( preg_replace( '/\s+/', ' ', (string) $cuerpo ) );
		return '' === $t ? '(cuerpo vacío)' : mb_substr( $t, 0, 300 );
	}

	/**
	 * El mensaje de un WP_Error con cualquier URL tapada.
	 *
	 * Tapar y no borrar: saber que el host era otro —un proxy, un espejo
	 * puesto por un filtro— es parte del diagnóstico, y el esquema y el
	 * dominio no son el secreto. Lo que se quita es la ruta y la cadena
	 * de consulta, que es donde algunos proveedores admiten la clave.
	 *
	 * @param \WP_Error $e Error del transporte.
	 * @return string
	 */
	public static function sin_url( $e ) {
		$codigo  = method_exists( $e, 'get_error_code' ) ? (string) $e->get_error_code() : '';
		$mensaje = method_exists( $e, 'get_error_message' ) ? (string) $e->get_error_message() : '';

		$mensaje = preg_replace_callback(
			'#\bhttps?://([^/\s]+)\S*#i',
			function ( $m ) {
				return $m[1] . '/…';
			},
			$mensaje
		);

		$texto = trim( $codigo . ( '' !== $codigo && '' !== $mensaje ? ' · ' : '' ) . $mensaje );
		return '' === $texto ? 'sin detalle' : self::recortar( $texto );
	}

	/**
	 * ¿Esta petición va sin herramientas?
	 *
	 * Importa más de lo que parece. Los tres proveedores tratan de forma
	 * distinta un bloque de herramientas VACÍO, y el único caso del
	 * plugin que lo produce es la verificación de la clave: un mensaje de
	 * una línea, sin herramientas, que es lo primero que un administrador
	 * ejecuta al configurar.
	 *
	 * Gemini rechaza la petición entera con 400 INVALID_ARGUMENT si
	 * recibe un `Tool` con `functionDeclarations` vacío. Claude y OpenAI
	 * toleran `tools: []`, pero mandarlo tampoco significa nada: declarar
	 * «cero herramientas» y no declarar herramientas son lo mismo para el
	 * modelo y distinto para el validador. Así que no se manda.
	 *
	 * @param array $pet Petición en formato neutro.
	 * @return bool
	 */
	private static function sin_herramientas( $pet ) {
		return empty( $pet['herramientas'] ) || ! is_array( $pet['herramientas'] );
	}

	/**
	 * Resultado vacío con un error, para no repetirlo en cada rama.
	 *
	 * @param string $error  Mensaje interno.
	 * @param int    $codigo HTTP.
	 * @param string $clase  Código UHP del fallo (véase UHP_Codigos).
	 * @return array
	 */
	private static function fallo( $error, $codigo = 0, $clase = 'UHP-LLM-99' ) {
		return array(
			'ok'       => false,
			'texto'    => '',
			'llamadas' => array(),
			'error'    => $error,
			'codigo'   => (int) $codigo,
			'clase'    => $clase,
			'avisos'   => array(),
			'uso'      => array(),
		);
	}

	/**
	 * Resultado bueno, o el fallo de una respuesta vacía.
	 *
	 * UN 200 SIN TEXTO NI HERRAMIENTAS NO ES UNA RESPUESTA. Hasta la 2.0
	 * salía como éxito: el visitante preguntaba y no recibía nada, y el
	 * cupo no se devolvía. Pasa con un bloqueo de seguridad, un filtro de
	 * contenido o una respuesta que se cortó antes de empezar, y cada
	 * proveedor dice cuál en un campo distinto: quien llama pasa ese
	 * motivo para el registro.
	 *
	 * @param string $texto    Texto.
	 * @param array  $llamadas Llamadas.
	 * @param array  $uso      Tokens.
	 * @param array  $avisos   Códigos UHP de lo que no corta la respuesta.
	 * @param string $porque   Motivo de parada del proveedor, para el registro.
	 * @return array
	 */
	private static function exito( $texto, $llamadas, $uso, $avisos, $porque ) {
		if ( '' === trim( (string) $texto ) && ! $llamadas ) {
			return self::fallo( 'respuesta sin texto ni herramientas' . ( '' !== $porque ? ' · ' . $porque : '' ), 200, 'UHP-LLM-12' );
		}
		return array(
			'ok'       => true,
			'texto'    => $texto,
			'llamadas' => $llamadas,
			'error'    => '',
			'codigo'   => 200,
			'clase'    => '',
			'avisos'   => array_values( array_unique( $avisos ) ),
			'uso'      => $uso,
		);
	}

	/**
	 * Hace la petición y devuelve el JSON, o un fallo.
	 *
	 * @param string $url      Destino.
	 * @param array  $cabeceras Cabeceras.
	 * @param array  $cuerpo   Cuerpo, se codifica a JSON.
	 * @return array|\WP_Error|array{__fallo:array}
	 */
	private static function pedir( $url, $cabeceras, $cuerpo ) {
		$r = wp_remote_post(
			$url,
			array(
				'timeout' => self::ESPERA,
				'headers' => array_merge( array( 'Content-Type' => 'application/json' ), $cabeceras ),
				'body'    => wp_json_encode( $cuerpo ),
			)
		);

		if ( is_wp_error( $r ) ) {
			/* El mensaje del transporte no se devuelve TAL CUAL: puede
			   traer la URL completa, y una URL puede llevar la clave. Lo
			   que sí se conserva es el código de WordPress y el mensaje
			   con la URL tapada, porque sin eso «no se pudo conectar» no
			   distingue un cortafuegos de un certificado rechazado ni de
			   un tiempo de espera agotado, y son tres arreglos
			   distintos. */
			$dicho = self::sin_url( $r );
			return array( '__fallo' => self::fallo( 'transporte: ' . $dicho, 0, UHP_Codigos::de_transporte( 'LLM', $dicho ) ) );
		}

		$codigo = (int) wp_remote_retrieve_response_code( $r );
		$cuerpo = (string) wp_remote_retrieve_body( $r );

		if ( 200 !== $codigo ) {
			/* Del cuerpo de error solo se guarda un trozo corto, y el
			   registro del sitio es de la entidad: aun así, un proveedor
			   que devuelva la petición completa en el error devolvería
			   también la conversación del ciudadano. */
			return array(
				'__fallo' => self::fallo(
					'http ' . $codigo . ': ' . self::recortar( $cuerpo ),
					$codigo,
					// El cuerpo ENTERO para clasificar: el tipo de error puede
					// ir después de los trescientos caracteres que se anotan.
					UHP_Codigos::de_proveedor( 'LLM', $codigo, $cuerpo )
				),
			);
		}

		$d = json_decode( $cuerpo, true );
		if ( ! is_array( $d ) ) {
			/* Con un trozo del cuerpo: este caso es casi siempre un proxy
			   corporativo o un cortafuegos de aplicación devolviendo una
			   página HTML con 200, y las primeras líneas de esa página
			   dicen cuál. Sin ellas, el panel solo puede decir «respuesta
			   que no es JSON», que no lleva a ninguna parte. */
			return array( '__fallo' => self::fallo( 'respuesta que no es JSON: ' . self::recortar( $cuerpo ), 200, 'UHP-LLM-10' ) );
		}
		return $d;
	}

	/* ================================================================= */
	/* Claude — https://docs.anthropic.com/en/api/messages               */
	/* ================================================================= */

	/**
	 * Un turno contra la API de mensajes de Anthropic.
	 *
	 * @param array  $pet    Petición neutra.
	 * @param string $modelo Modelo.
	 * @return array
	 */
	private static function claude( $pet, $modelo ) {
		$mensajes = array();

		foreach ( $pet['mensajes'] as $m ) {
			if ( 'usuario' === $m['rol'] ) {
				$mensajes[] = array(
					'role'    => 'user',
					'content' => array( array( 'type' => 'text', 'text' => $m['texto'] ) ),
				);
				continue;
			}

			if ( 'asistente' === $m['rol'] ) {
				$bloques = array();
				if ( '' !== trim( (string) $m['texto'] ) ) {
					$bloques[] = array( 'type' => 'text', 'text' => $m['texto'] );
				}
				foreach ( (array) $m['llamadas'] as $l ) {
					$bloques[] = array(
						'type'  => 'tool_use',
						'id'    => $l['id'],
						'name'  => $l['nombre'],
						// Un objeto vacío tiene que viajar como {} y no
						// como [], que es lo que hace json_encode con un
						// array de PHP vacío y lo que el proveedor rechaza.
						'input' => empty( $l['args'] ) ? new \stdClass() : $l['args'],
					);
				}
				if ( $bloques ) {
					$mensajes[] = array( 'role' => 'assistant', 'content' => $bloques );
				}
				continue;
			}

			if ( 'herramienta' === $m['rol'] ) {
				/* TODOS los resultados en UN SOLO mensaje de usuario.
				   Repartirlos en varios le enseña al modelo a dejar de
				   pedir herramientas en paralelo. */
				$bloques = array();
				foreach ( (array) $m['resultados'] as $r ) {
					$bloques[] = array(
						'type'        => 'tool_result',
						'tool_use_id' => $r['id'],
						'content'     => $r['salida'],
					);
				}
				if ( $bloques ) {
					$mensajes[] = array( 'role' => 'user', 'content' => $bloques );
				}
			}
		}

		$esfuerzo = isset( $pet['esfuerzo'] ) ? $pet['esfuerzo'] : 'low';
		$cuerpo   = array(
			'model'      => $modelo,
			'max_tokens' => self::tope_salida( $esfuerzo ),
			/* El prefijo estable —herramientas y sistema, en ese orden—
			   se cachea. Son los mismos dos o tres mil tokens en cada
			   turno de cada conversación de cada visitante: pagarlos
			   enteros cada vez sería tirar el presupuesto. */
			'system'     => array(
				array(
					'type'          => 'text',
					'text'          => $pet['sistema'],
					'cache_control' => array( 'type' => 'ephemeral' ),
				),
			),
			'messages'   => $mensajes,
		);
		if ( self::claude_con_esfuerzo( $modelo ) ) {
			$cuerpo['output_config'] = array( 'effort' => $esfuerzo );
		}
		$cabeceras = array(
			'x-api-key'         => $pet['clave'],
			'anthropic-version' => '2023-06-01',
		);
		if ( self::claude_con_reintento( $modelo ) ) {
			/* La forma «default» va con ESTA cabecera y no con la de la
			   forma de lista (-2026-06-01): cruzarlas da 400. */
			$cuerpo['fallbacks']        = 'default';
			$cabeceras['anthropic-beta'] = 'server-side-fallback-2026-07-01';
		}

		/* El bloque de herramientas SOLO si hay alguna. Véase la nota de
		   `sin_herramientas()`: una lista vacía no es «ninguna
		   herramienta», es una declaración mal formada. */
		if ( ! self::sin_herramientas( $pet ) ) {
			$cuerpo['tools'] = self::herramientas_claude( $pet['herramientas'] );
			/* `auto` y no `any`: forzar la llamada a una herramienta
			   devuelve 400 en los modelos actuales, y además aquí no se
			   quiere forzar nada —hay preguntas que se contestan sin
			   tocar el tablero—. */
			$cuerpo['tool_choice'] = array( 'type' => 'auto' );
		}

		$d = self::pedir( 'https://api.anthropic.com/v1/messages', $cabeceras, $cuerpo );
		if ( isset( $d['__fallo'] ) ) {
			return $d['__fallo'];
		}

		$parada = isset( $d['stop_reason'] ) ? (string) $d['stop_reason'] : '';

		// Una negativa por seguridad llega con 200 y su propio motivo.
		if ( 'refusal' === $parada ) {
			return self::fallo( 'el proveedor declinó la petición', 200, 'UHP-LLM-11' );
		}

		$texto    = '';
		$llamadas = array();
		foreach ( (array) ( isset( $d['content'] ) ? $d['content'] : array() ) as $b ) {
			if ( ! isset( $b['type'] ) ) {
				continue;
			}
			if ( 'text' === $b['type'] ) {
				$texto .= (string) $b['text'];
			} elseif ( 'tool_use' === $b['type'] ) {
				$llamadas[] = array(
					'id'     => (string) $b['id'],
					'nombre' => (string) $b['name'],
					'args'   => isset( $b['input'] ) && is_array( $b['input'] ) ? $b['input'] : array(),
				);
			}
		}

		/* CORTADA CON HERRAMIENTAS A MEDIAS: no se ejecutan. Unos
		   argumentos truncados moverían el tablero a donde nadie pidió. */
		if ( 'max_tokens' === $parada && $llamadas ) {
			return self::fallo( 'respuesta cortada por max_tokens con herramientas a medias', 200, 'UHP-LLM-13' );
		}

		return self::exito(
			$texto,
			$llamadas,
			array(
				'entrada' => isset( $d['usage']['input_tokens'] ) ? (int) $d['usage']['input_tokens'] : 0,
				'salida'  => isset( $d['usage']['output_tokens'] ) ? (int) $d['usage']['output_tokens'] : 0,
				'cache'   => isset( $d['usage']['cache_read_input_tokens'] ) ? (int) $d['usage']['cache_read_input_tokens'] : 0,
			),
			// Una respuesta cortada en el tope. Antes nadie se enteraba de que
			// se cortaba; ahora llega con su aviso (véase tope_salida()).
			'max_tokens' === $parada ? array( 'UHP-LLM-13' ) : array(),
			'' !== $parada ? 'stop_reason=' . $parada : ''
		);
	}

	/**
	 * Las herramientas en la forma que espera Anthropic.
	 *
	 * `strict` obliga a que los argumentos validen contra el esquema, y
	 * para eso el esquema tiene que cerrar `additionalProperties`. Los
	 * otros dos adaptadores también lo cierran: Gemini lo admite porque
	 * se le manda en `parametersJsonSchema` —el antiguo `parameters` sí lo
	 * rechazaba, y de ahí venía la nota que decía lo contrario—.
	 *
	 * @param array $defs Definiciones compartidas.
	 * @return array
	 */
	private static function herramientas_claude( $defs ) {
		$salida = array();
		foreach ( $defs as $d ) {
			$esquema                         = $d['parameters'];
			$esquema['additionalProperties'] = false;
			if ( ! isset( $esquema['required'] ) ) {
				$esquema['required'] = array();
			}
			$salida[] = array(
				'name'         => $d['name'],
				'description'  => $d['description'],
				'input_schema' => $esquema,
				'strict'       => true,
			);
		}
		return $salida;
	}

	/* ================================================================= */
	/* OpenAI                                                            */
	/* ================================================================= */

	/**
	 * Un turno contra la API de OpenAI.
	 *
	 * Se usa la API de RESPUESTAS y no la de completados de chat. Las dos
	 * siguen vivas, pero la propia guía de migración dice que la de
	 * respuestas es la recomendada para proyectos nuevos, y hay una razón
	 * práctica además de esa: su bucle de herramientas tiene la misma
	 * forma que el de Anthropic —se devuelve el historial tal cual y se le
	 * añaden los resultados—, de modo que los dos adaptadores piensan
	 * igual. En la de chat las herramientas van anidadas bajo `function`,
	 * el resultado vuelve con rol `tool`, y mezclar las dos formas es un
	 * error que no avisa.
	 *
	 * @param array  $pet    Petición neutra.
	 * @param string $modelo Modelo.
	 * @return array
	 */
	private static function openai( $pet, $modelo ) {
		$entrada = array();

		foreach ( $pet['mensajes'] as $m ) {
			if ( 'usuario' === $m['rol'] ) {
				$entrada[] = array( 'role' => 'user', 'content' => $m['texto'] );
				continue;
			}

			if ( 'asistente' === $m['rol'] ) {
				if ( '' !== trim( (string) $m['texto'] ) ) {
					$entrada[] = array( 'role' => 'assistant', 'content' => $m['texto'] );
				}
				/* Cada llamada es un ELEMENTO del historial, no un campo
				   de un mensaje del asistente: esta API rechaza un
				   `role: assistant` con `tool_calls`. */
				foreach ( (array) $m['llamadas'] as $l ) {
					$entrada[] = array(
						'type'      => 'function_call',
						'call_id'   => $l['id'],
						'name'      => $l['nombre'],
						// Cadena JSON, no objeto. Es la diferencia que más
						// tiempo hace perder entre este proveedor y los otros.
						'arguments' => wp_json_encode( empty( $l['args'] ) ? new \stdClass() : $l['args'] ),
					);
				}
				continue;
			}

			if ( 'herramienta' === $m['rol'] ) {
				foreach ( (array) $m['resultados'] as $r ) {
					$entrada[] = array(
						'type'    => 'function_call_output',
						'call_id' => $r['id'],
						'output'  => $r['salida'],
					);
				}
			}
		}

		$herramientas = array();
		foreach ( self::sin_herramientas( $pet ) ? array() : $pet['herramientas'] as $d ) {
			$esquema                         = $d['parameters'];
			$esquema['additionalProperties'] = false;
			if ( ! isset( $esquema['required'] ) ) {
				$esquema['required'] = array();
			}
			$herramientas[] = array(
				// El nombre va PLANO, al mismo nivel que `type`. En la API
				// de chat iba anidado bajo `function`.
				'type'        => 'function',
				'name'        => $d['name'],
				'description' => $d['description'],
				'parameters'  => $esquema,
			);
		}

		$esfuerzo = isset( $pet['esfuerzo'] ) ? $pet['esfuerzo'] : 'low';
		$cuerpo   = array(
			'model'             => $modelo,
			// El sistema va en su propio campo, no como un mensaje
			// con rol dentro del historial.
			'instructions'      => $pet['sistema'],
			'input'             => $entrada,
			'max_output_tokens' => self::tope_salida( $esfuerzo ),
			/* NO se guarda la conversación en el proveedor. Por
			   omisión la almacena, y lo que pasa por aquí son las
			   preguntas de ciudadanos a una entidad pública: no hay
			   ninguna razón para dejar una copia en casa ajena. */
			'store'             => false,
		);

		if ( ! self::sin_herramientas( $pet ) ) {
			$cuerpo['tools']       = $herramientas;
			$cuerpo['tool_choice'] = 'auto';
		}

		/* EL ESFUERZO, que hasta la 2.0 solo llegaba a Claude. Sin él, un
		   modelo de razonamiento de OpenAI piensa en «medium» y se come
		   el tope. Solo a las familias que razonan: a un modelo que no lo
		   admite, el parámetro le da 400. */
		if ( preg_match( '#^(gpt-[5-9]|o[1-9])#', (string) $modelo ) ) {
			$cuerpo['reasoning'] = array( 'effort' => $esfuerzo );
		}

		$d = self::pedir(
			'https://api.openai.com/v1/responses',
			array( 'Authorization' => 'Bearer ' . $pet['clave'] ),
			$cuerpo
		);
		if ( isset( $d['__fallo'] ) ) {
			return $d['__fallo'];
		}

		$texto    = '';
		$llamadas = array();
		$avisos   = array();
		$nego     = false;
		foreach ( (array) ( isset( $d['output'] ) ? $d['output'] : array() ) as $item ) {
			if ( ! isset( $item['type'] ) ) {
				continue;
			}
			if ( 'message' === $item['type'] ) {
				foreach ( (array) ( isset( $item['content'] ) ? $item['content'] : array() ) as $c ) {
					// Una negativa viene como contenido de tipo «refusal», con
					// 200: sin mirarlo, se contaba como una respuesta vacía.
					if ( isset( $c['type'] ) && 'refusal' === $c['type'] ) {
						$nego = true;
						continue;
					}
					if ( isset( $c['text'] ) ) {
						$texto .= (string) $c['text'];
					}
				}
			} elseif ( 'function_call' === $item['type'] ) {
				$args = isset( $item['arguments'] ) ? json_decode( (string) $item['arguments'], true ) : array();
				if ( isset( $item['arguments'] ) && '' !== trim( (string) $item['arguments'] ) && ! is_array( $args ) ) {
					// Se ejecuta sin argumentos, pero ya no en silencio.
					$avisos[] = 'UHP-LLM-14';
				}
				$llamadas[] = array(
					'id'     => isset( $item['call_id'] ) ? (string) $item['call_id'] : '',
					'nombre' => isset( $item['name'] ) ? (string) $item['name'] : '',
					'args'   => is_array( $args ) ? $args : array(),
				);
			}
		}

		if ( $nego && '' === trim( $texto ) && ! $llamadas ) {
			return self::fallo( 'el proveedor declinó la petición', 200, 'UHP-LLM-11' );
		}

		$estado = isset( $d['status'] ) ? (string) $d['status'] : '';
		$razon  = isset( $d['incomplete_details']['reason'] ) ? (string) $d['incomplete_details']['reason'] : '';
		if ( 'incomplete' === $estado && 'content_filter' === $razon ) {
			return self::fallo( 'el proveedor declinó la petición · reason=content_filter', 200, 'UHP-LLM-11' );
		}
		if ( 'incomplete' === $estado && 'max_output_tokens' === $razon ) {
			if ( $llamadas ) {
				return self::fallo( 'respuesta cortada por max_output_tokens con herramientas a medias', 200, 'UHP-LLM-13' );
			}
			$avisos[] = 'UHP-LLM-13';
		}

		return self::exito(
			$texto,
			$llamadas,
			array(
				'entrada' => isset( $d['usage']['input_tokens'] ) ? (int) $d['usage']['input_tokens'] : 0,
				'salida'  => isset( $d['usage']['output_tokens'] ) ? (int) $d['usage']['output_tokens'] : 0,
			),
			$avisos,
			trim( ( '' !== $estado ? 'status=' . $estado : '' ) . ( '' !== $razon ? ' reason=' . $razon : '' ) )
		);
	}

	/* ================================================================= */
	/* Google Gemini                                                     */
	/* ================================================================= */

	/**
	 * Un turno contra la API de Gemini.
	 *
	 * @param array  $pet    Petición neutra.
	 * @param string $modelo Modelo.
	 * @return array
	 */
	private static function gemini( $pet, $modelo ) {
		$contenidos = array();

		foreach ( $pet['mensajes'] as $m ) {
			if ( 'usuario' === $m['rol'] ) {
				$contenidos[] = array(
					'role'  => 'user',
					'parts' => array( array( 'text' => $m['texto'] ) ),
				);
				continue;
			}

			if ( 'asistente' === $m['rol'] ) {
				// El rol se llama «model». No existe «assistant» ni «tool».
				$partes = array();
				if ( '' !== trim( (string) $m['texto'] ) ) {
					$partes[] = array( 'text' => $m['texto'] );
				}
				foreach ( (array) $m['llamadas'] as $l ) {
					$parte = array(
						'functionCall' => array(
							'id'   => $l['id'],
							'name' => $l['nombre'],
							'args' => empty( $l['args'] ) ? new \stdClass() : $l['args'],
						),
					);
					/* La firma de razonamiento vuelve tal cual. Es opaca y
					   no se interpreta: es del proveedor, y quitarla de un
					   turno que ya la llevaba le hace perder el hilo de lo
					   que estaba pensando. */
					if ( ! empty( $l['firma'] ) ) {
						$parte['thoughtSignature'] = $l['firma'];
					}
					$partes[] = $parte;
				}
				if ( $partes ) {
					$contenidos[] = array( 'role' => 'model', 'parts' => $partes );
				}
				continue;
			}

			if ( 'herramienta' === $m['rol'] ) {
				// El rol del resultado es «user», no «tool».
				$partes = array();
				foreach ( (array) $m['resultados'] as $r ) {
					$partes[] = array(
						'functionResponse' => array(
							'id'       => $r['id'],
							'name'     => $r['nombre'],
							'response' => array( 'resultado' => $r['salida'] ),
						),
					);
				}
				if ( $partes ) {
					$contenidos[] = array( 'role' => 'user', 'parts' => $partes );
				}
			}
		}

		$declaraciones = array();
		foreach ( self::sin_herramientas( $pet ) ? array() : $pet['herramientas'] as $d ) {
			$esquema                         = $d['parameters'];
			$esquema['additionalProperties'] = false;
			if ( ! isset( $esquema['required'] ) ) {
				$esquema['required'] = array();
			}
			$declaraciones[] = array(
				'name'        => $d['name'],
				'description' => $d['description'],
				/* `parametersJsonSchema` y NO `parameters`: el segundo
				   acepta solo un subconjunto de OpenAPI y rechaza lo que
				   no conoce, de modo que habría que traducir el esquema y
				   mantener dos versiones de cada herramienta. Este admite
				   JSON Schema de verdad, el mismo que va a los otros dos
				   proveedores. Son mutuamente excluyentes. */
				'parametersJsonSchema' => $esquema,
			);
		}

		$modelo_ruta = ( 0 === strpos( $modelo, 'models/' ) ) ? $modelo : 'models/' . $modelo;

		$esfuerzo  = isset( $pet['esfuerzo'] ) ? $pet['esfuerzo'] : 'low';
		$generacion = array( 'maxOutputTokens' => self::tope_salida( $esfuerzo ) );
		/* EL NIVEL DE PENSAMIENTO, solo en Gemini 3 o posterior: en los
		   anteriores el campo da error. Sin él, piensa en «medium» y se
		   puede comer el tope entero. */
		if ( preg_match( '#(^|/)gemini-[3-9]#', (string) $modelo ) ) {
			$generacion['thinkingConfig'] = array( 'thinkingLevel' => strtoupper( $esfuerzo ) );
		}

		$cuerpo = array(
			// El sistema es un Content aparte, no un rol del historial.
			'systemInstruction' => array( 'parts' => array( array( 'text' => $pet['sistema'] ) ) ),
			'contents'          => $contenidos,
			'generationConfig'  => $generacion,
		);

		/* AQUÍ ESTABA EL FALLO que rechazaba la verificación con un 400.
		   El bloque se construía siempre, de modo que una petición sin
		   herramientas —la de verificar la clave, que es justo la que un
		   administrador ejecuta primero— mandaba
		   `"tools":[{"functionDeclarations":[]}]`. Gemini exige al menos
		   una declaración dentro de cada Tool y rechaza la petición
		   ENTERA, no solo el bloque. Por eso fallaba la verificación
		   mientras una conversación de verdad funcionaba: esa sí lleva
		   las diez herramientas. */
		if ( ! self::sin_herramientas( $pet ) ) {
			$cuerpo['tools']      = array( array( 'functionDeclarations' => $declaraciones ) );
			$cuerpo['toolConfig'] = array(
				'functionCallingConfig' => array( 'mode' => 'AUTO' ),
			);
		}

		$d = self::pedir(
			'https://generativelanguage.googleapis.com/v1beta/' . $modelo_ruta . ':generateContent',
			/* La clave en CABECERA y no en la cadena de consulta. Las dos
			   funcionan; una clave en la URL acaba en el registro de
			   accesos del servidor, en el del proxy y en cualquier traza
			   de error que alguien pegue en un correo. */
			array( 'x-goog-api-key' => $pet['clave'] ),
			$cuerpo
		);
		if ( isset( $d['__fallo'] ) ) {
			return $d['__fallo'];
		}

		/* Un bloqueo de la PREGUNTA llega sin candidatos y con su motivo
		   en promptFeedback; uno de la RESPUESTA, con finishReason. Los
		   dos con 200. */
		$bloqueo = isset( $d['promptFeedback']['blockReason'] ) ? (string) $d['promptFeedback']['blockReason'] : '';
		$parada  = isset( $d['candidates'][0]['finishReason'] ) ? (string) $d['candidates'][0]['finishReason'] : '';
		if ( '' !== $bloqueo || in_array( $parada, array( 'SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII', 'IMAGE_SAFETY' ), true ) ) {
			return self::fallo(
				'el proveedor declinó la petición · ' . ( '' !== $bloqueo ? 'blockReason=' . $bloqueo : 'finishReason=' . $parada ),
				200,
				'UHP-LLM-11'
			);
		}

		/* No hay un «stop_reason» que diga que pidió herramientas: se
		   detecta recorriendo las partes. */
		$partes   = isset( $d['candidates'][0]['content']['parts'] ) ? $d['candidates'][0]['content']['parts'] : array();
		$texto    = '';
		$llamadas = array();
		$n        = 0;
		foreach ( (array) $partes as $p ) {
			if ( isset( $p['text'] ) ) {
				$texto .= (string) $p['text'];
				continue;
			}
			if ( ! isset( $p['functionCall']['name'] ) ) {
				continue;
			}
			$n++;
			$llamadas[] = array(
				// El identificador lo pone el proveedor y hay que
				// devolverlo exacto. Si alguna versión no lo mandara, se
				// inventa uno: sin él no se puede casar la llamada con su
				// resultado.
				'id'     => isset( $p['functionCall']['id'] ) && '' !== $p['functionCall']['id']
					? (string) $p['functionCall']['id']
					: ( 'g' . $n . '_' . $p['functionCall']['name'] ),
				'nombre' => (string) $p['functionCall']['name'],
				'args'   => isset( $p['functionCall']['args'] ) && is_array( $p['functionCall']['args'] ) ? $p['functionCall']['args'] : array(),
				'firma'  => isset( $p['thoughtSignature'] ) ? (string) $p['thoughtSignature'] : '',
			);
		}

		/* Una firma de razonamiento que no volvió exacta. Es un defecto
		   del historial que mandó el navegador, no de la configuración. */
		if ( 'MISSING_THOUGHT_SIGNATURE' === $parada ) {
			return self::fallo( 'finishReason=MISSING_THOUGHT_SIGNATURE', 200, 'UHP-LLM-06' );
		}

		$avisos = array();
		if ( 'MAX_TOKENS' === $parada ) {
			if ( $llamadas ) {
				return self::fallo( 'respuesta cortada por MAX_TOKENS con herramientas a medias', 200, 'UHP-LLM-13' );
			}
			$avisos[] = 'UHP-LLM-13';
		}
		if ( 'MALFORMED_FUNCTION_CALL' === $parada ) {
			$avisos[] = 'UHP-LLM-14';
		}

		return self::exito(
			$texto,
			$llamadas,
			array(
				'entrada' => isset( $d['usageMetadata']['promptTokenCount'] ) ? (int) $d['usageMetadata']['promptTokenCount'] : 0,
				// El razonamiento se cobra como salida y viene aparte.
				'salida'  => ( isset( $d['usageMetadata']['candidatesTokenCount'] ) ? (int) $d['usageMetadata']['candidatesTokenCount'] : 0 )
					+ ( isset( $d['usageMetadata']['thoughtsTokenCount'] ) ? (int) $d['usageMetadata']['thoughtsTokenCount'] : 0 ),
			),
			$avisos,
			'' !== $parada ? 'finishReason=' . $parada : ''
		);
	}

}
