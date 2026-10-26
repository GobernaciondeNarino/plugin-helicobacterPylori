<?php
/**
 * Panel de administración de URKUNINA 5000.
 *
 * Un menú de primer nivel con siete módulos, cada uno con sus pestañas
 * internas y sus tarjetas. La organización sigue el recorrido natural de
 * quien administra la plataforma:
 *
 *   Panel        → estado general y qué revisar hoy
 *   Datos        → los archivos JSON: ver, validar, editar, subir, restaurar
 *   Gráficos     → catálogo de vistas con su descripción y su análisis
 *   Shortcodes   → qué publicar y cómo, con ejemplos copiables
 *   Componentes  → configuración del tablero y del objeto 3D
 *   Apariencia   → identidad visual
 *   Diagnóstico  → entorno, conflictos con otros plugins y seguridad
 *
 * Todo formulario lleva nonce y toda página comprueba la capacidad antes de
 * pintar nada.
 *
 * @package Urkunina5000
 */

namespace GobernacionNarino\Urkunina;

defined( 'ABSPATH' ) || exit;

final class UHP_Admin {

	/** Slug del menú de primer nivel. */
	const MENU = 'uhp-panel';

	/** @var string[] Hooks de las páginas del plugin (para encolar assets). */
	private $hooks = array();

	/** @var UHP_Admin_Datos */
	private $datos;

	public function __construct() {
		$this->datos = new UHP_Admin_Datos();

		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'registrar_ajustes' ) );
		add_action( 'admin_init', array( $this, 'redirigir_pestana_vieja' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_uhp_voz_verificar', array( $this, 'verificar_voz' ) );
		add_filter( 'plugin_action_links_' . UHP_BASENAME, array( $this, 'enlaces_plugin' ) );
	}

	/* ================================================================= */
	/* Menú                                                              */
	/* ================================================================= */

	/**
	 * Registra el menú y sus submenús.
	 */
	public function menu() {
		$cap = UHP_Security::CAP;

		$this->hooks[] = add_menu_page(
			__( 'URKUNINA 5000', 'urkunina-5000' ),
			__( 'URKUNINA 5000', 'urkunina-5000' ),
			$cap,
			self::MENU,
			array( $this, 'pagina_panel' ),
			'dashicons-microscope',
			58
		);

		$paginas = array(
			self::MENU        => array( __( 'Panel', 'urkunina-5000' ), 'pagina_panel' ),
			'uhp-datos'       => array( __( 'Datos', 'urkunina-5000' ), 'pagina_datos' ),
			'uhp-graficos'    => array( __( 'Gráficos', 'urkunina-5000' ), 'pagina_graficos' ),
			'uhp-shortcodes'  => array( __( 'Shortcodes', 'urkunina-5000' ), 'pagina_shortcodes' ),
			'uhp-componentes' => array( __( 'Componentes', 'urkunina-5000' ), 'pagina_componentes' ),
			// Módulo propio: antes era una pestaña de Componentes y quedaba
			// escondida detrás del tablero y del objeto 3D.
			'uhp-asistente'   => array( __( 'Asistente AI', 'urkunina-5000' ), 'pagina_asistente' ),
			'uhp-apariencia'  => array( __( 'Apariencia', 'urkunina-5000' ), 'pagina_apariencia' ),
			'uhp-diagnostico' => array( __( 'Diagnóstico', 'urkunina-5000' ), 'pagina_diagnostico' ),
		);

		foreach ( $paginas as $slug => $def ) {
			$this->hooks[] = add_submenu_page(
				self::MENU,
				$def[0] . ' — URKUNINA 5000',
				$def[0],
				$cap,
				$slug,
				array( $this, $def[1] )
			);
		}
	}

	/**
	 * Añade el enlace de ajustes en la lista de plugins.
	 *
	 * @param array $enlaces Enlaces existentes.
	 * @return array
	 */
	public function enlaces_plugin( $enlaces ) {
		$propio = '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::MENU ) ) . '">' .
			esc_html__( 'Panel', 'urkunina-5000' ) . '</a>';
		array_unshift( $enlaces, $propio );
		return $enlaces;
	}

	/**
	 * Encola los estilos y scripts del panel solo en sus propias páginas.
	 *
	 * Se comparan los hooks exactos que devolvieron add_*_page: buscar el
	 * slug por substring encolaría los assets en páginas de otros plugins
	 * cuyo slug empiece igual.
	 *
	 * @param string $hook Hook de la pantalla actual.
	 */
	public function assets( $hook ) {
		if ( ! in_array( $hook, $this->hooks, true ) ) {
			return;
		}
		wp_enqueue_style( 'uhp-admin', UHP_URL . 'assets/css/uhp-admin.css', array(), UHP_VERSION );
		wp_enqueue_script( 'uhp-admin', UHP_URL . 'assets/js/uhp-admin.js', array(), UHP_VERSION, true );
	}

	/* ================================================================= */
	/* Ajustes                                                           */
	/* ================================================================= */

	/**
	 * Registra las opciones con sus saneadores.
	 */
	public function registrar_ajustes() {
		register_setting(
			'uhp_grupo_apariencia',
			'uhp_estilo',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanear_estilo' ),
				'default'           => UHP_Estilos::por_defecto(),
			)
		);
		register_setting(
			'uhp_grupo_dashboard',
			'uhp_dashboard',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanear_dashboard' ),
				'default'           => UHP_Activator::dashboard_por_defecto(),
			)
		);
		register_setting(
			'uhp_grupo_voz',
			UHP_Voz::OPCION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanear_voz' ),
				'default'           => UHP_Voz::por_defecto(),
			)
		);
		// La clave de API va en su PROPIA opción, fuera del array del que
		// se deriva lo que viaja al navegador. Así, el ajuste que alguien
		// añada mañana a `uhp_voz` no puede publicarla por descuido.
		register_setting(
			'uhp_grupo_voz',
			UHP_Voz::OPCION_CLAVE,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanear_voz_clave' ),
				'default'           => '',
			)
		);
		// La del modelo de lenguaje, en su propia opción por lo mismo.
		register_setting(
			'uhp_grupo_voz',
			UHP_Voz::OPCION_LLM,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanear_llm_clave' ),
				'default'           => '',
			)
		);
		register_setting(
			'uhp_grupo_3d',
			'uhp_3d',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanear_3d' ),
				'default'           => UHP_Activator::tresd_por_defecto(),
			)
		);
	}

	/**
	 * Sanea la configuración de apariencia.
	 *
	 * @param mixed $entrada Valor enviado por el formulario.
	 * @return array
	 */
	public function sanear_estilo( $entrada ) {
		$def    = UHP_Estilos::por_defecto();
		$salida = array();

		foreach ( $def as $clave => $valor ) {
			if ( 'fuentes_cdn' === $clave ) {
				$salida[ $clave ] = ( is_array( $entrada ) && ! empty( $entrada[ $clave ] ) ) ? 1 : 0;
				continue;
			}
			$crudo = ( is_array( $entrada ) && isset( $entrada[ $clave ] ) ) ? $entrada[ $clave ] : $valor;
			// sanitizar_css neutraliza url(), expression() y los caracteres
			// que permitirían cerrar el valor e inyectar reglas nuevas.
			$limpio = UHP_Estilos::sanitizar_css( $crudo );
			$salida[ $clave ] = ( '' === $limpio ) ? $valor : $limpio;
		}
		return $salida;
	}

	/**
	 * Sanea la configuración del tablero.
	 *
	 * @param mixed $entrada Valor enviado por el formulario.
	 * @return array
	 */
	public function sanear_dashboard( $entrada ) {
		$def     = UHP_Activator::dashboard_por_defecto();
		$entrada = is_array( $entrada ) ? $entrada : array();

		$indicador = isset( $entrada['indicador'] ) ? UHP_Security::clave( $entrada['indicador'] ) : $def['indicador'];

		return array(
			'titulo'    => sanitize_text_field( isset( $entrada['titulo'] ) ? $entrada['titulo'] : $def['titulo'] ),
			'lema'      => sanitize_text_field( isset( $entrada['lema'] ) ? $entrada['lema'] : $def['lema'] ),
			// Solo dos indicadores tienen rampa en el mapa del tablero.
			'indicador' => in_array( $indicador, array( 'lpm', 'hp' ), true ) ? $indicador : $def['indicador'],
		);
	}

	/**
	 * Sanea la configuración del módulo 3D.
	 *
	 * @param mixed $entrada Valor enviado por el formulario.
	 * @return array
	 */
	public function sanear_3d( $entrada ) {
		$def     = UHP_Activator::tresd_por_defecto();
		$entrada = is_array( $entrada ) ? $entrada : array();

		return array(
			'alto'         => UHP_Estilos::sanitizar_css( isset( $entrada['alto'] ) ? $entrada['alto'] : $def['alto'] ),
			'autoplay'     => empty( $entrada['autoplay'] ) ? 0 : 1,
			'duracion'     => min( 90, max( 4, isset( $entrada['duracion'] ) ? (int) $entrada['duracion'] : $def['duracion'] ) ),
			'instrumentos' => empty( $entrada['instrumentos'] ) ? 0 : 1,
			'cabecera'     => empty( $entrada['cabecera'] ) ? 0 : 1,
			'desplazar'    => empty( $entrada['desplazar'] ) ? 0 : 1,
		);
	}

	/**
	 * Sanea la configuración del asistente de voz.
	 *
	 * @param mixed $entrada Valor enviado por el formulario.
	 * @return array
	 */
	public function sanear_voz( $entrada ) {
		$def     = UHP_Voz::por_defecto();
		$entrada = is_array( $entrada ) ? $entrada : array();

		return array(
			'activo'          => empty( $entrada['activo'] ) ? 0 : 1,
			'visibilidad'     => self::visibilidad_de_formulario( $entrada ),

			// El cerebro.
			'proveedor'       => UHP_Voz::sanear_proveedor( isset( $entrada['proveedor'] ) ? $entrada['proveedor'] : '' ),
			'modelo'          => UHP_Voz::sanear_modelo( isset( $entrada['modelo'] ) ? $entrada['modelo'] : '' ),
			'esfuerzo'        => UHP_Voz::sanear_esfuerzo( isset( $entrada['esfuerzo'] ) ? $entrada['esfuerzo'] : '' ),

			// La voz.
			'voz_id'          => UHP_Voz::sanear_voz_id( isset( $entrada['voz_id'] ) ? $entrada['voz_id'] : '' ),
			'voz_modelo'      => UHP_Voz::sanear_modelo( isset( $entrada['voz_modelo'] ) ? $entrada['voz_modelo'] : '' ),
			'voz_velocidad'   => UHP_Voz::sanear_velocidad( isset( $entrada['voz_velocidad'] ) ? $entrada['voz_velocidad'] : '' ),

			/* Una casilla: marcada manda «ambos», desmarcada no manda nada.

			   En la 2.0.0 el formulario seguía pintando el desplegable de
			   tres opciones mientras este saneador ya esperaba una casilla,
			   de modo que CUALQUIER opción —también «Solo por escrito»—
			   llegaba no vacía y se guardaba como «ambos». El administrador
			   no podía quitar el micrófono. Ahora formulario y saneador
			   hablan de lo mismo, y una prueba lo comprueba de ida y
			   vuelta. */
			'entrada'         => empty( $entrada['entrada'] ) ? 'texto' : 'ambos',
			'micro_silencio'  => UHP_Voz::sanear_silencio( isset( $entrada['micro_silencio'] ) ? $entrada['micro_silencio'] : $def['micro_silencio'] ),

			// La bienvenida. Vacío = el texto por omisión, resuelto al pintar.
			'anfitrion'       => isset( $entrada['anfitrion'] ) ? mb_substr( sanitize_text_field( trim( (string) $entrada['anfitrion'] ) ), 0, 40 ) : '',
			'saludo'          => isset( $entrada['saludo'] ) ? mb_substr( sanitize_textarea_field( trim( (string) $entrada['saludo'] ) ), 0, 400 ) : '',
			'sugerencias'     => isset( $entrada['sugerencias'] ) ? implode( "\n", UHP_Voz::sanear_sugerencias( (string) $entrada['sugerencias'] ) ) : '',

			'cuota_dia'       => min( 5000, max( 10, isset( $entrada['cuota_dia'] ) ? (int) $entrada['cuota_dia'] : $def['cuota_dia'] ) ),
			'turnos_max'      => min( 20, max( 2, isset( $entrada['turnos_max'] ) ? (int) $entrada['turnos_max'] : $def['turnos_max'] ) ),

			// El aviso, ahora opcional.
			'aviso'           => empty( $entrada['aviso'] ) ? 0 : 1,
			'recordar'        => empty( $entrada['recordar'] ) ? 0 : 1,
			'aviso_version'   => max( 1, isset( $entrada['aviso_version'] ) ? (int) $entrada['aviso_version'] : 1 ),
			'politica'        => isset( $entrada['politica'] ) ? esc_url_raw( trim( (string) $entrada['politica'] ) ) : '',
			'correo'          => isset( $entrada['correo'] ) ? sanitize_email( trim( (string) $entrada['correo'] ) ) : '',
			'canal'           => isset( $entrada['canal'] ) ? sanitize_text_field( trim( (string) $entrada['canal'] ) ) : '',
			'retencion'       => isset( $entrada['retencion'] ) ? sanitize_textarea_field( trim( (string) $entrada['retencion'] ) ) : '',
			'aviso_extra'     => isset( $entrada['aviso_extra'] ) ? sanitize_textarea_field( trim( (string) $entrada['aviso_extra'] ) ) : '',
		);
	}

	/**
	 * La visibilidad a partir de la casilla «Visible en el sitio».
	 *
	 * Marcada: todo el mundo. Desmarcada: activo pero visible solo para
	 * quien puede editar, que es el modo de prueba. Y el tercer modo,
	 * «oculto» —sin botón, para abrirlo desde la plantilla—, que ya no
	 * tiene control propio en el formulario, se CONSERVA si el sitio lo
	 * tenía y nadie marcó la casilla: perderlo en silencio al guardar
	 * cualquier otro ajuste cambiaría la página sin que nadie lo pidiera.
	 *
	 * Se acepta además `visibilidad` directo, que es lo que manda quien
	 * guarde la opción por programa o con WP-CLI.
	 *
	 * @param array $entrada Lo enviado.
	 * @return string todos|editores|oculto
	 */
	private static function visibilidad_de_formulario( $entrada ) {
		if ( isset( $entrada['visibilidad'] ) && ! isset( $entrada['visibilidad_previa'] ) ) {
			return UHP_Voz::sanear_visibilidad( $entrada['visibilidad'] );
		}
		if ( ! empty( $entrada['visible'] ) ) {
			return 'todos';
		}
		$previa = isset( $entrada['visibilidad_previa'] ) ? UHP_Voz::sanear_visibilidad( $entrada['visibilidad_previa'] ) : 'todos';
		return 'oculto' === $previa ? 'oculto' : 'editores';
	}

	/**
	 * Le pregunta a ElevenLabs si el agente está como el plugin cree.
	 *
	 * El informe se guarda en un transitorio, así que después de volver el
	 * formulario lo pinta solo: no hace falta pasarlo por un aviso.
	 */
	public function verificar_voz() {
		UHP_Security::exigir_admin();
		UHP_Voz::verificar();
		wp_safe_redirect( admin_url( 'admin.php?page=uhp-asistente' ) );
		exit;
	}

	/**
	 * URL de la acción de verificación, con su nonce.
	 *
	 * @return string
	 */
	private function url_verificar() {
		return wp_nonce_url(
			add_query_arg( array( 'action' => 'uhp_voz_verificar' ), admin_url( 'admin-post.php' ) ),
			UHP_Security::NONCE,
			'_uhp_nonce'
		);
	}

	/**
	 * Sanea la clave de API.
	 *
	 * El formulario se pinta SIEMPRE vacío: enviarlo en blanco conserva la
	 * clave guardada en vez de borrarla, que es lo que ocurriría al tocar
	 * cualquier otro ajuste de la pestaña.
	 *
	 * @param mixed $entrada Valor enviado.
	 * @return string
	 */
	public function sanear_llm_clave( $entrada ) {
		return $this->sanear_secreto( $entrada, UHP_Voz::OPCION_LLM );
	}

	/**
	 * El tratamiento común de las dos claves.
	 *
	 * El formulario se pinta SIEMPRE vacío: enviarlo en blanco conserva la
	 * que está guardada en vez de borrarla, que es lo que ocurriría al
	 * tocar cualquier otro ajuste de la pestaña.
	 *
	 * @param mixed  $entrada Valor enviado.
	 * @param string $opcion  Opción donde vive la clave actual.
	 * @return string
	 */
	private function sanear_secreto( $entrada, $opcion ) {
		$nueva = is_string( $entrada ) ? trim( $entrada ) : '';
		if ( '' === $nueva ) {
			$actual = get_option( $opcion, '' );
			return is_string( $actual ) ? $actual : '';
		}
		if ( 'borrar' === strtolower( $nueva ) ) {
			return '';
		}
		/* Los tres proveedores y ElevenLabs usan claves de letras, números
		   y guiones. Se limpia lo demás en vez de rechazar: un pegado
		   desde un correo trae espacios y saltos de línea, y un
		   administrador que ve «no se guardó» sin más vuelve a pegar lo
		   mismo. */
		return preg_replace( '/[^A-Za-z0-9_\-]/', '', $nueva );
	}

	public function sanear_voz_clave( $entrada ) {
		return $this->sanear_secreto( $entrada, UHP_Voz::OPCION_CLAVE );
	}

	/* ================================================================= */
	/* Página: Panel                                                     */
	/* ================================================================= */

	/**
	 * Resumen general del estado de la plataforma.
	 */
	public function pagina_panel() {
		$this->exigir_cap();

		$estado = UHP_Datos::estado();
		$kpis   = UHP_Rest::kpis();
		$conteo = $this->contar_estados( $estado );

		$this->cabecera(
			__( 'Panel', 'urkunina-5000' ),
			__( 'Estado de la plataforma de visualización del proyecto URKUNINA 5000.', 'urkunina-5000' )
		);
		$this->aviso();
		?>
		<div class="uhpa">

			<div class="uhpa-rejilla uhpa-rejilla--4">
				<?php
				$this->tarjeta_metrica(
					__( 'Archivos de datos', 'urkunina-5000' ),
					count( $estado ),
					$conteo['error']
						? sprintf(
							/* translators: %d: número de archivos con problemas. */
							_n( '%d archivo con problemas', '%d archivos con problemas', $conteo['error'], 'urkunina-5000' ),
							$conteo['error']
						)
						: __( 'Todos legibles y válidos', 'urkunina-5000' ),
					$conteo['error'] ? 'error' : 'ok'
				);
				$this->tarjeta_metrica(
					__( 'Vistas de gráfico', 'urkunina-5000' ),
					count( UHP_Views::lista() ),
					__( 'Disponibles para publicar con shortcode', 'urkunina-5000' ),
					'ok'
				);
				$this->tarjeta_metrica(
					__( 'Municipios cruzados', 'urkunina-5000' ),
					count( UHP_Municipios::set_priorizados() ) . ' / ' . count( UHP_Municipios::priorizados() ),
					__( 'Priorizados que cruzan con la geometría', 'urkunina-5000' ),
					count( UHP_Municipios::set_priorizados() ) === count( UHP_Municipios::priorizados() ) ? 'ok' : 'aviso'
				);
				$this->tarjeta_metrica(
					__( 'Avisos de integridad', 'urkunina-5000' ),
					$conteo['aviso'] + $conteo['modificado'],
					__( 'Archivos con cambios o notas por revisar', 'urkunina-5000' ),
					( $conteo['aviso'] + $conteo['modificado'] ) ? 'aviso' : 'ok'
				);
				?>
			</div>

			<div class="uhpa-rejilla uhpa-rejilla--2">

				<section class="uhpa-card">
					<header class="uhpa-card__cab">
						<h2><?php esc_html_e( 'Cifras del proyecto', 'urkunina-5000' ); ?></h2>
						<p><?php esc_html_e( 'Lo que publican las tarjetas del shortcode [urkunina_kpi], leído en vivo de los archivos de datos.', 'urkunina-5000' ); ?></p>
					</header>
					<div class="uhpa-card__cuerpo">
						<table class="uhpa-tabla">
							<tbody>
							<?php foreach ( $kpis as $k ) : ?>
								<tr>
									<th scope="row"><?php echo esc_html( $k['etiqueta'] ); ?></th>
									<td class="uhpa-num">
										<?php
										echo esc_html(
											'porcentaje' === $k['formato']
												? UHP_Analisis::pct( $k['valor'] )
												: number_format( (float) $k['valor'], 0, ',', '.' )
										);
										?>
									</td>
									<td class="uhpa-nota"><?php echo esc_html( $k['nota'] ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</section>

				<section class="uhpa-card">
					<header class="uhpa-card__cab">
						<h2><?php esc_html_e( 'Qué revisar', 'urkunina-5000' ); ?></h2>
						<p><?php esc_html_e( 'Puntos que el plugin detecta y conviene atender.', 'urkunina-5000' ); ?></p>
					</header>
					<div class="uhpa-card__cuerpo">
						<?php
						$pendientes = array_filter(
							$estado,
							static function ( $f ) {
								return 'ok' !== $f['estado'];
							}
						);
						?>
						<?php if ( ! $pendientes ) : ?>
							<p class="uhpa-vacio"><?php esc_html_e( 'Nada pendiente: los catorce archivos JSON del conjunto y la geometría municipal se leen sin errores y coinciden con la línea base registrada.', 'urkunina-5000' ); ?></p>
						<?php else : ?>
							<ul class="uhpa-lista">
								<?php foreach ( $pendientes as $f ) : ?>
									<li>
										<span class="uhpa-punto uhpa-punto--<?php echo esc_attr( $f['estado'] ); ?>"></span>
										<strong><?php echo esc_html( $f['titulo'] ); ?></strong>
										<span class="uhpa-nota"><?php echo esc_html( $f['nota'] ); ?></span>
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=uhp-datos&tab=' . $f['clave'] ) ); ?>">
											<?php esc_html_e( 'Abrir', 'urkunina-5000' ); ?>
										</a>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</div>
				</section>

			</div>

			<section class="uhpa-card">
				<header class="uhpa-card__cab">
					<h2><?php esc_html_e( 'Empezar a publicar', 'urkunina-5000' ); ?></h2>
					<p><?php esc_html_e( 'Copie cualquiera de estos shortcodes en una página o entrada. El catálogo completo está en el módulo Shortcodes.', 'urkunina-5000' ); ?></p>
				</header>
				<div class="uhpa-card__cuerpo">
					<div class="uhpa-rejilla uhpa-rejilla--3">
						<?php
						$this->tarjeta_shortcode(
							__( 'Tablero completo', 'urkunina-5000' ),
							'[urkunina_dashboard]',
							__( 'Mapa de OpenStreetMap, controles, filtros y gráficos en un contenedor a pantalla completa.', 'urkunina-5000' )
						);
						$this->tarjeta_shortcode(
							__( 'Objeto 3D', 'urkunina-5000' ),
							'[urkunina_3d]',
							__( 'Recreación tridimensional de la bacteria con su línea de tiempo de la infección.', 'urkunina-5000' )
						);
						$this->tarjeta_shortcode(
							__( 'Un gráfico', 'urkunina-5000' ),
							'[urkunina_grafico view="tamizaje_hp" type="donut"]',
							__( 'Cualquiera de las vistas del catálogo, con su barra de herramientas y su análisis.', 'urkunina-5000' )
						);
						?>
					</div>
				</div>
			</section>

		</div>
		<?php
		$this->pie();
	}

	/* ================================================================= */
	/* Página: Datos                                                     */
	/* ================================================================= */

	/**
	 * Módulo de actualización de los archivos JSON.
	 */
	public function pagina_datos() {
		$this->exigir_cap();

		$registro = UHP_Datos::registro();
		$estado   = UHP_Datos::estado();

		$tab = isset( $_GET['tab'] ) ? UHP_Security::clave( wp_unslash( $_GET['tab'] ) ) : 'resumen'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Solo selecciona qué pestaña pintar.
		if ( 'resumen' !== $tab && ! isset( $registro[ $tab ] ) ) {
			$tab = 'resumen';
		}

		$this->cabecera(
			__( 'Datos', 'urkunina-5000' ),
			__( 'Los catorce archivos JSON del conjunto de datos del proyecto, más la geometría municipal que alimenta el mapa. Aquí se consultan, se validan, se actualizan y se restauran.', 'urkunina-5000' )
		);
		$this->aviso();

		// Pestañas: resumen primero y luego los archivos agrupados por tema.
		$pestanas = array( 'resumen' => __( 'Resumen', 'urkunina-5000' ) );
		foreach ( $registro as $clave => $meta ) {
			$pestanas[ $clave ] = $meta['titulo'];
		}
		$this->pestanas( 'uhp-datos', $pestanas, $tab );
		?>
		<div class="uhpa">
			<?php
			if ( 'resumen' === $tab ) {
				$this->datos_resumen( $estado );
			} else {
				$this->datos_archivo( $tab, $registro[ $tab ], $estado[ $tab ] );
			}
			?>
		</div>
		<?php
		$this->pie();
	}

	/**
	 * Pestaña de resumen del módulo de datos.
	 *
	 * @param array $estado Estado de cada archivo.
	 */
	private function datos_resumen( $estado ) {
		$dir_escribible = is_writable( UHP_Datos::dir() );
		?>
		<?php if ( ! $dir_escribible ) : ?>
			<div class="uhpa-alerta uhpa-alerta--error">
				<strong><?php esc_html_e( 'El directorio /data no tiene permisos de escritura.', 'urkunina-5000' ); ?></strong>
				<p><?php esc_html_e( 'Puede consultar y descargar los archivos, pero no guardar cambios ni crear copias de seguridad. Pida al administrador del servidor que otorgue permiso de escritura al usuario del servidor web sobre el directorio de datos del plugin.', 'urkunina-5000' ); ?></p>
				<code><?php echo esc_html( UHP_Datos::dir() ); ?></code>
			</div>
		<?php endif; ?>

		<section class="uhpa-card">
			<header class="uhpa-card__cab">
				<h2><?php esc_html_e( 'Estado del conjunto', 'urkunina-5000' ); ?></h2>
				<p><?php esc_html_e( 'Cada archivo se comprueba en tres niveles: que exista y sea legible, que su JSON sea válido y cumpla el contrato de la vista, y que su huella SHA-256 coincida con la última registrada por el panel.', 'urkunina-5000' ); ?></p>
			</header>
			<div class="uhpa-card__cuerpo uhpa-card__cuerpo--plano">
				<table class="uhpa-tabla uhpa-tabla--rayada">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Archivo', 'urkunina-5000' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Estado', 'urkunina-5000' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Tamaño', 'urkunina-5000' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Modificado', 'urkunina-5000' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Copias', 'urkunina-5000' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Acción', 'urkunina-5000' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $estado as $f ) : ?>
							<tr>
								<td>
									<strong><?php echo esc_html( $f['titulo'] ); ?></strong><br>
									<code><?php echo esc_html( $f['archivo'] ); ?></code>
								</td>
								<td>
									<span class="uhpa-punto uhpa-punto--<?php echo esc_attr( $f['estado'] ); ?>"></span>
									<?php echo esc_html( $this->nombre_estado( $f['estado'] ) ); ?>
									<?php if ( $f['nota'] ) : ?>
										<span class="uhpa-nota"><?php echo esc_html( $f['nota'] ); ?></span>
									<?php endif; ?>
								</td>
								<td class="uhpa-num"><?php echo esc_html( $f['bytes'] ? size_format( $f['bytes'] ) : '—' ); ?></td>
								<td><?php echo esc_html( $f['modificado'] ? wp_date( 'd/m/Y H:i', $f['modificado'] ) : '—' ); ?></td>
								<td class="uhpa-num"><?php echo esc_html( $f['respaldos'] ); ?></td>
								<td>
									<a class="button button-small"
										href="<?php echo esc_url( admin_url( 'admin.php?page=uhp-datos&tab=' . $f['clave'] ) ); ?>">
										<?php esc_html_e( 'Gestionar', 'urkunina-5000' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</section>

		<div class="uhpa-rejilla uhpa-rejilla--2">
			<section class="uhpa-card">
				<header class="uhpa-card__cab">
					<h2><?php esc_html_e( 'Mantenimiento del conjunto', 'urkunina-5000' ); ?></h2>
					<p><?php esc_html_e( 'Dos operaciones que afectan a todo el conjunto y no a un archivo concreto.', 'urkunina-5000' ); ?></p>
				</header>
				<div class="uhpa-card__cuerpo">
					<div class="uhpa-accion">
						<div>
							<strong><?php esc_html_e( 'Recalcular el manifiesto', 'urkunina-5000' ); ?></strong>
							<p><?php esc_html_e( 'Reescribe en 00_manifiesto.json el tamaño y la huella SHA-256 reales de cada archivo. Ejecútelo después de actualizar cualquier archivo para que el manifiesto siga describiendo con exactitud el conjunto.', 'urkunina-5000' ); ?></p>
						</div>
						<a class="button button-primary" href="<?php echo esc_url( UHP_Admin_Datos::url( 'manifiesto' ) ); ?>">
							<?php esc_html_e( 'Recalcular', 'urkunina-5000' ); ?>
						</a>
					</div>
					<div class="uhpa-accion">
						<div>
							<strong><?php esc_html_e( 'Vaciar la caché de datos', 'urkunina-5000' ); ?></strong>
							<p><?php esc_html_e( 'El plugin cachea los archivos durante doce horas. Vacíe la caché si actualizó un archivo por FTP o por despliegue y quiere ver el cambio de inmediato.', 'urkunina-5000' ); ?></p>
						</div>
						<a class="button" href="<?php echo esc_url( UHP_Admin_Datos::url( 'purgar' ) ); ?>">
							<?php esc_html_e( 'Vaciar', 'urkunina-5000' ); ?>
						</a>
					</div>
				</div>
			</section>

			<section class="uhpa-card">
				<header class="uhpa-card__cab">
					<h2><?php esc_html_e( 'Cómo se protege la escritura', 'urkunina-5000' ); ?></h2>
					<p><?php esc_html_e( 'Este es el único módulo del plugin que escribe en disco. Estas son sus garantías.', 'urkunina-5000' ); ?></p>
				</header>
				<div class="uhpa-card__cuerpo">
					<ul class="uhpa-lista uhpa-lista--check">
						<li><?php esc_html_e( 'Solo puede escribir quien tiene la capacidad manage_options, y cada formulario verifica su nonce.', 'urkunina-5000' ); ?></li>
						<li><?php esc_html_e( 'El archivo se elige de una lista blanca: la ruta nunca se construye con lo que envía el navegador, de modo que un salto de directorio es imposible.', 'urkunina-5000' ); ?></li>
						<li><?php esc_html_e( 'El contenido se valida antes de tocar el disco: JSON bien formado, codificación UTF-8, claves obligatorias del contrato y forma de las listas.', 'urkunina-5000' ); ?></li>
						<li><?php esc_html_e( 'Antes de sobrescribir se guarda una copia de seguridad, y la escritura es atómica: un fallo a medias no deja el archivo truncado.', 'urkunina-5000' ); ?></li>
						<li><?php esc_html_e( 'Las copias de seguridad no se sirven por HTTP: el directorio queda protegido con index.php y .htaccess.', 'urkunina-5000' ); ?></li>
					</ul>
				</div>
			</section>
		</div>
		<?php
	}

	/**
	 * Pestaña de un archivo concreto: consulta, edición, subida y respaldos.
	 *
	 * @param string $clave  Clave del registro.
	 * @param array  $meta   Metadatos del archivo.
	 * @param array  $estado Estado calculado del archivo.
	 */
	private function datos_archivo( $clave, $meta, $estado ) {
		$ruta      = UHP_Datos::dir() . $meta['archivo'];
		$contenido = is_readable( $ruta ) ? file_get_contents( $ruta ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
		$respaldos = UHP_Datos::respaldos( $clave );
		$editable  = $estado['existe'] && $estado['escribible'];

		// El GeoJSON supera los 350 KB: cargarlo en un textarea colgaría el
		// navegador, así que para él solo se ofrece la subida de archivo.
		$muy_grande = $estado['bytes'] > 262144;
		?>
		<section class="uhpa-card">
			<header class="uhpa-card__cab">
				<h2><?php echo esc_html( $meta['titulo'] ); ?></h2>
				<p><?php echo esc_html( $meta['descripcion'] ); ?></p>
				<div class="uhpa-meta">
					<code><?php echo esc_html( $meta['archivo'] ); ?></code>
					<span class="uhpa-punto uhpa-punto--<?php echo esc_attr( $estado['estado'] ); ?>"></span>
					<span><?php echo esc_html( $this->nombre_estado( $estado['estado'] ) ); ?></span>
					<span><?php echo esc_html( $estado['bytes'] ? size_format( $estado['bytes'] ) : '—' ); ?></span>
					<span>
						<?php
						echo esc_html(
							$estado['modificado']
								? sprintf(
									/* translators: %s: fecha y hora. */
									__( 'Modificado el %s', 'urkunina-5000' ),
									wp_date( 'd/m/Y H:i', $estado['modificado'] )
								)
								: ''
						);
						?>
					</span>
				</div>
			</header>
			<div class="uhpa-card__cuerpo">
				<?php if ( $estado['nota'] ) : ?>
					<div class="uhpa-alerta uhpa-alerta--<?php echo esc_attr( 'error' === $estado['estado'] ? 'error' : 'aviso' ); ?>">
						<p><?php echo esc_html( $estado['nota'] ); ?></p>
					</div>
				<?php endif; ?>

				<p class="uhpa-ayuda">
					<?php esc_html_e( 'Claves obligatorias que este archivo debe conservar:', 'urkunina-5000' ); ?>
					<?php foreach ( $meta['claves'] as $c ) : ?>
						<code><?php echo esc_html( $c ); ?></code>
					<?php endforeach; ?>
				</p>

				<p class="uhpa-ayuda">
					<?php esc_html_e( 'También disponible como dato abierto en:', 'urkunina-5000' ); ?>
					<a href="<?php echo esc_url( rest_url( UHP_Rest::NS . '/abierto/' . $clave ) ); ?>" target="_blank" rel="noopener">
						<code><?php echo esc_html( '/wp-json/' . UHP_Rest::NS . '/abierto/' . $clave ); ?></code>
					</a>
				</p>
			</div>
		</section>

		<div class="uhpa-rejilla uhpa-rejilla--2">

			<section class="uhpa-card">
				<header class="uhpa-card__cab">
					<h2><?php esc_html_e( 'Editar el contenido', 'urkunina-5000' ); ?></h2>
					<p>
						<?php if ( $muy_grande ) : ?>
							<?php esc_html_e( 'Este archivo es demasiado grande para editarlo en el navegador. Actualícelo subiendo un archivo nuevo desde la tarjeta de al lado.', 'urkunina-5000' ); ?>
						<?php else : ?>
							<?php esc_html_e( 'El contenido se valida al guardar. Si el JSON tiene un error, no se escribe nada y el archivo actual queda intacto.', 'urkunina-5000' ); ?>
						<?php endif; ?>
					</p>
				</header>
				<div class="uhpa-card__cuerpo">
					<?php if ( $muy_grande ) : ?>
						<p class="uhpa-vacio">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: tamaño del archivo. */
									__( 'El archivo ocupa %s. Use la subida de archivo o edítelo por despliegue del repositorio.', 'urkunina-5000' ),
									size_format( $estado['bytes'] )
								)
							);
							?>
						</p>
					<?php else : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="<?php echo esc_attr( UHP_Admin_Datos::ACCION . 'guardar' ); ?>">
							<input type="hidden" name="archivo" value="<?php echo esc_attr( $clave ); ?>">
							<?php wp_nonce_field( UHP_Security::NONCE, '_uhp_nonce' ); ?>

							<label class="screen-reader-text" for="uhp-editor">
								<?php esc_html_e( 'Contenido del archivo en formato JSON', 'urkunina-5000' ); ?>
							</label>
							<textarea id="uhp-editor" name="contenido" class="uhpa-editor" rows="22" spellcheck="false"
								<?php disabled( ! $editable ); ?>><?php echo esc_textarea( $contenido ); ?></textarea>

							<p class="uhpa-editor-pie">
								<button type="submit" class="button button-primary" <?php disabled( ! $editable ); ?>>
									<?php esc_html_e( 'Validar y guardar', 'urkunina-5000' ); ?>
								</button>
								<button type="button" class="button" data-uhp-formatear="uhp-editor">
									<?php esc_html_e( 'Formatear JSON', 'urkunina-5000' ); ?>
								</button>
								<span class="uhpa-editor-estado" data-uhp-editor-estado aria-live="polite"></span>
							</p>
							<?php if ( ! $editable ) : ?>
								<p class="uhpa-nota"><?php esc_html_e( 'El archivo no tiene permisos de escritura en el servidor.', 'urkunina-5000' ); ?></p>
							<?php endif; ?>
						</form>
					<?php endif; ?>
				</div>
			</section>

			<div class="uhpa-columna">
				<section class="uhpa-card">
					<header class="uhpa-card__cab">
						<h2><?php esc_html_e( 'Subir un archivo', 'urkunina-5000' ); ?></h2>
						<p><?php esc_html_e( 'Reemplaza el archivo por uno preparado fuera del panel. Se valida igual que la edición y se respalda el anterior.', 'urkunina-5000' ); ?></p>
					</header>
					<div class="uhpa-card__cuerpo">
						<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="<?php echo esc_attr( UHP_Admin_Datos::ACCION . 'subir' ); ?>">
							<input type="hidden" name="archivo" value="<?php echo esc_attr( $clave ); ?>">
							<input type="hidden" name="MAX_FILE_SIZE" value="<?php echo esc_attr( UHP_Datos::tope_bytes( $clave ) ); ?>">
							<?php wp_nonce_field( UHP_Security::NONCE, '_uhp_nonce' ); ?>

							<label class="screen-reader-text" for="uhp-subida">
								<?php esc_html_e( 'Archivo JSON de reemplazo', 'urkunina-5000' ); ?>
							</label>
							<input type="file" id="uhp-subida" name="archivo_json"
								accept=".json,.geojson,application/json"
								class="uhpa-file" <?php disabled( ! is_writable( UHP_Datos::dir() ) ); ?>>

							<p>
								<button type="submit" class="button button-primary" <?php disabled( ! is_writable( UHP_Datos::dir() ) ); ?>>
									<?php esc_html_e( 'Subir y reemplazar', 'urkunina-5000' ); ?>
								</button>
							</p>
							<p class="uhpa-nota">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: tamaño máximo permitido. */
										__( 'Tamaño máximo: %s.', 'urkunina-5000' ),
										size_format( UHP_Datos::tope_bytes( $clave ) )
									)
								);
								?>
							</p>
						</form>
					</div>
				</section>

				<section class="uhpa-card">
					<header class="uhpa-card__cab">
						<h2><?php esc_html_e( 'Copias de seguridad', 'urkunina-5000' ); ?></h2>
						<p>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: número máximo de copias conservadas. */
									__( 'Se guarda una copia automáticamente antes de cada escritura. Se conservan las %d más recientes.', 'urkunina-5000' ),
									UHP_Datos::MAX_RESPALDOS
								)
							);
							?>
						</p>
					</header>
					<div class="uhpa-card__cuerpo">
						<p>
							<a class="button" href="<?php echo esc_url( UHP_Admin_Datos::url( 'respaldar', $clave ) ); ?>">
								<?php esc_html_e( 'Crear copia ahora', 'urkunina-5000' ); ?>
							</a>
						</p>

						<?php if ( ! $respaldos ) : ?>
							<p class="uhpa-vacio"><?php esc_html_e( 'Todavía no hay copias de este archivo.', 'urkunina-5000' ); ?></p>
						<?php else : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="<?php echo esc_attr( UHP_Admin_Datos::ACCION . 'restaurar' ); ?>">
								<input type="hidden" name="archivo" value="<?php echo esc_attr( $clave ); ?>">
								<?php wp_nonce_field( UHP_Security::NONCE, '_uhp_nonce' ); ?>

								<label class="uhpa-label" for="uhp-respaldo"><?php esc_html_e( 'Copia a restaurar', 'urkunina-5000' ); ?></label>
								<select id="uhp-respaldo" name="respaldo" class="uhpa-select">
									<?php foreach ( $respaldos as $r ) : ?>
										<option value="<?php echo esc_attr( $r['nombre'] ); ?>">
											<?php echo esc_html( $r['fecha'] . ' · ' . size_format( $r['bytes'] ) ); ?>
										</option>
									<?php endforeach; ?>
								</select>

								<p>
									<button type="submit" class="button"
										data-uhp-confirmar="<?php esc_attr_e( '¿Restaurar esta copia? El contenido actual se guardará como una copia nueva antes de reemplazarlo.', 'urkunina-5000' ); ?>">
										<?php esc_html_e( 'Restaurar', 'urkunina-5000' ); ?>
									</button>
								</p>
							</form>
						<?php endif; ?>
					</div>
				</section>
			</div>
		</div>
		<?php
	}

	/* ================================================================= */
	/* Página: Gráficos                                                  */
	/* ================================================================= */

	/**
	 * Catálogo de vistas del motor de gráficos.
	 */
	public function pagina_graficos() {
		$this->exigir_cap();

		$vistas = UHP_Views::lista();
		$grupos = array();
		foreach ( $vistas as $v ) {
			$grupos[ $v['grupo'] ][] = $v;
		}

		$slugs = array();
		foreach ( array_keys( $grupos ) as $g ) {
			$slugs[ sanitize_title( $g ) ] = $g;
		}

		$tab = isset( $_GET['tab'] ) ? UHP_Security::clave( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Solo selecciona qué pestaña pintar.
		if ( ! isset( $slugs[ $tab ] ) ) {
			$tab = (string) array_key_first( $slugs );
		}

		$this->cabecera(
			__( 'Gráficos', 'urkunina-5000' ),
			__( 'Cada vista es un conjunto de datos listo para graficar. Aquí figura qué muestra, qué tipos de gráfico admite y el shortcode con el que se publica.', 'urkunina-5000' )
		);

		$pestanas = array();
		foreach ( $slugs as $slug => $nombre ) {
			$pestanas[ $slug ] = $nombre;
		}
		$this->pestanas( 'uhp-graficos', $pestanas, $tab );
		?>
		<div class="uhpa">
			<?php $this->tarjeta_grupo( $slugs[ $tab ], $grupos[ $slugs[ $tab ] ] ); ?>
			<div class="uhpa-rejilla uhpa-rejilla--2">
				<?php foreach ( $grupos[ $slugs[ $tab ] ] as $v ) : ?>
					<?php $this->tarjeta_vista( $v ); ?>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
		$this->pie();
	}

	/**
	 * Tarjeta que agrupa todas las vistas de una pestaña.
	 *
	 * Va delante de las tarjetas individuales y resuelve la pregunta que
	 * estas no pueden: cómo publicar la pestaña ENTERA en una sola pieza
	 * de página, en vez de copiar un shortcode por vista.
	 *
	 * La lista de aquí es una vista previa —cambia el nombre y el
	 * identificador que muestran los shortcodes de abajo— para que quien
	 * maqueta vea a qué vista corresponde cada uno antes de copiarlo. El
	 * comportamiento real, con el gráfico y los textos cambiando a la vez,
	 * es el de [urkunina_selector] en la página pública.
	 *
	 * @param string $grupo  Nombre del grupo (la pestaña).
	 * @param array  $vistas Vistas que lo componen.
	 */
	private function tarjeta_grupo( $grupo, $vistas ) {
		$id           = 'uhpa-grupo-' . sanitize_title( $grupo );
		$territoriales = 0;
		foreach ( $vistas as $v ) {
			if ( ! empty( $v['geo'] ) ) {
				$territoriales++;
			}
		}
		?>
		<section class="uhpa-card uhpa-card--grupo" data-uhpa-grupo>
			<header class="uhpa-card__cab">
				<h2>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: nombre del grupo de vistas. */
							__( 'Todas las vistas de %s en una sola tarjeta', 'urkunina-5000' ),
							$grupo
						)
					);
					?>
				</h2>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: número de vistas del grupo. */
							_n(
								'Una lista desplegable gobierna las %d vista de esta pestaña: al elegir un nombre cambian a la vez el título, la descripción, el análisis, las cifras, la tabla y el gráfico.',
								'Una lista desplegable gobierna las %d vistas de esta pestaña: al elegir un nombre cambian a la vez el título, la descripción, el análisis, las cifras, la tabla y el gráfico.',
								count( $vistas ),
								'urkunina-5000'
							),
							count( $vistas )
						)
					);
					?>
				</p>
			</header>
			<div class="uhpa-card__cuerpo">
				<h3 class="uhpa-h3"><?php esc_html_e( 'Vistas que agrupa', 'urkunina-5000' ); ?></h3>
				<p class="uhpa-campo">
					<label class="uhpa-etq" for="<?php echo esc_attr( $id ); ?>">
						<?php esc_html_e( 'Vista', 'urkunina-5000' ); ?>
					</label>
					<select class="uhpa-select" id="<?php echo esc_attr( $id ); ?>" data-uhpa-grupo-select>
						<?php foreach ( $vistas as $v ) : ?>
							<option value="<?php echo esc_attr( $v['id'] ); ?>"
								data-descripcion="<?php echo esc_attr( $v['description'] ); ?>">
								<?php echo esc_html( $v['name'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</p>
				<p class="uhpa-texto" data-uhpa-grupo-desc>
					<?php echo esc_html( $vistas[0]['description'] ); ?>
				</p>

				<h3 class="uhpa-h3"><?php esc_html_e( 'Cómo publicar la pestaña completa', 'urkunina-5000' ); ?></h3>
				<p class="uhpa-nota">
					<?php esc_html_e( 'Cada pieza es un shortcode independiente y ninguna sabe de las otras: pueden colocarse en columnas distintas, en otro orden o repartidas por la página. Lo único que las une es el grupo.', 'urkunina-5000' ); ?>
				</p>
				<?php $this->copiable( '[urkunina_selector grupo="' . $grupo . '"]' ); ?>
				<?php $this->copiable( '[urkunina_titulo grupo="' . $grupo . '" etiqueta="h2"]' ); ?>
				<?php $this->copiable( '[urkunina_grafico grupo="' . $grupo . '" alto="420px" titulo="no"]' ); ?>
				<?php $this->copiable( '[urkunina_descripcion grupo="' . $grupo . '"]' ); ?>
				<?php $this->copiable( '[urkunina_interpretacion grupo="' . $grupo . '"]' ); ?>
				<?php $this->copiable( '[urkunina_resumen grupo="' . $grupo . '"]' ); ?>
				<?php $this->copiable( '[urkunina_cifras grupo="' . $grupo . '"]' ); ?>
				<?php $this->copiable( '[urkunina_tabla grupo="' . $grupo . '"]' ); ?>
				<?php $this->copiable( '[urkunina_fuente grupo="' . $grupo . '"]' ); ?>

				<h3 class="uhpa-h3"><?php esc_html_e( 'Vista concreta al arrancar', 'urkunina-5000' ); ?></h3>
				<p class="uhpa-nota">
					<?php esc_html_e( 'Sin más atributos arranca la primera vista de la lista. Con «view» arranca la que se indique, y con «canal» pueden convivir dos selectores independientes del mismo grupo en una misma página.', 'urkunina-5000' ); ?>
				</p>
				<?php $this->copiable( '[urkunina_selector grupo="' . $grupo . '" view="' . $vistas[ count( $vistas ) - 1 ]['id'] . '" canal="secundario"]' ); ?>

				<?php if ( $territoriales ) : ?>
					<h3 class="uhpa-h3"><?php esc_html_e( 'Vistas de esta pestaña que van al mapa', 'urkunina-5000' ); ?></h3>
					<p class="uhpa-nota">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: vistas territoriales; 2: total de vistas del grupo. */
								_n(
									'%1$d de las %2$d vistas nombra territorios con geometría, de modo que ofrece «Mapa» entre sus tipos de gráfico. El shortcode puede pedir que arranque ya en el mapa.',
									'%1$d de las %2$d vistas nombran territorios con geometría, de modo que ofrecen «Mapa» entre sus tipos de gráfico. El shortcode puede pedir que arranque ya en el mapa.',
									$territoriales,
									'urkunina-5000'
								),
								$territoriales,
								count( $vistas )
							)
						);
						?>
					</p>
					<?php $this->copiable( '[urkunina_grafico grupo="' . $grupo . '" type="mapa" teselas="si"]' ); ?>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Tarjeta de una vista del catálogo.
	 *
	 * @param array $v Datos compactos de la vista.
	 */
	private function tarjeta_vista( $v ) {
		$completa = UHP_Views::obtener( $v['id'] );
		$filas    = $completa ? count( $completa['data'] ) : 0;
		?>
		<section class="uhpa-card uhpa-card--vista">
			<header class="uhpa-card__cab">
				<h2><?php echo esc_html( $v['name'] ); ?></h2>
				<p><?php echo esc_html( $v['description'] ); ?></p>
				<div class="uhpa-meta">
					<code><?php echo esc_html( $v['id'] ); ?></code>
					<span class="uhpa-etiqueta"><?php echo esc_html( $v['category'] ); ?></span>
					<span>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: número de filas de datos. */
								_n( '%d fila', '%d filas', $filas, 'urkunina-5000' ),
								$filas
							)
						);
						?>
					</span>
				</div>
			</header>
			<div class="uhpa-card__cuerpo">
				<h3 class="uhpa-h3"><?php esc_html_e( 'Tipos de gráfico admitidos', 'urkunina-5000' ); ?></h3>
				<p class="uhpa-chips">
					<?php
					$tipos = UHP_Views::tipos();
					foreach ( $v['compatible'] as $t ) :
						?>
						<span class="uhpa-chip<?php echo $t === $v['default'] ? ' uhpa-chip--activo' : ''; ?>">
							<?php echo esc_html( isset( $tipos[ $t ]['label'] ) ? $tipos[ $t ]['label'] : $t ); ?>
						</span>
					<?php endforeach; ?>
				</p>
				<p class="uhpa-nota"><?php esc_html_e( 'El primero resaltado es el tipo por defecto. Quien visita la página puede cambiar entre los admitidos desde la barra del gráfico.', 'urkunina-5000' ); ?></p>

				<?php if ( $completa && $completa['descripcion_larga'] ) : ?>
					<h3 class="uhpa-h3"><?php esc_html_e( 'Descripción publicada', 'urkunina-5000' ); ?></h3>
					<p class="uhpa-texto"><?php echo esc_html( $completa['descripcion_larga'] ); ?></p>
				<?php endif; ?>

				<?php if ( $completa && $completa['analisis_largo'] ) : ?>
					<h3 class="uhpa-h3"><?php esc_html_e( 'Análisis publicado', 'urkunina-5000' ); ?></h3>
					<p class="uhpa-texto"><?php echo esc_html( $completa['analisis_largo'] ); ?></p>
				<?php endif; ?>

				<?php if ( $completa && ! empty( $completa['analisis']['cuantitativo'] ) ) : ?>
					<h3 class="uhpa-h3"><?php esc_html_e( 'Análisis automático', 'urkunina-5000' ); ?></h3>
					<p class="uhpa-texto uhpa-texto--num"><?php echo esc_html( $completa['analisis']['descriptivo'] ); ?></p>
					<p class="uhpa-texto uhpa-texto--num"><?php echo esc_html( $completa['analisis']['cuantitativo'] ); ?></p>
					<p class="uhpa-nota"><?php esc_html_e( 'Se redacta a partir de las cifras del archivo de origen, de modo que se actualiza solo cuando los datos cambian.', 'urkunina-5000' ); ?></p>
				<?php endif; ?>

				<h3 class="uhpa-h3"><?php esc_html_e( 'Cómo publicarla', 'urkunina-5000' ); ?></h3>
				<?php $this->copiable( '[urkunina_grafico view="' . $v['id'] . '" type="' . $v['default'] . '"]' ); ?>
				<?php $this->copiable( '[urkunina_analisis view="' . $v['id'] . '" modo="completo"]' ); ?>
				<?php $this->copiable( '[urkunina_tabla view="' . $v['id'] . '"]' ); ?>

				<?php if ( UHP_Views::es_territorial( $v['id'] ) ) : ?>
					<p class="uhpa-nota">
						<?php
						echo esc_html(
							'subregion' === UHP_Views::nivel( $v['id'] )
								? __( 'Esta vista nombra subregiones: también puede llevarse al mapa.', 'urkunina-5000' )
								: __( 'Esta vista nombra municipios: también puede llevarse al mapa.', 'urkunina-5000' )
						);
						?>
					</p>
					<?php $this->copiable( '[urkunina_geomapa view="' . $v['id'] . '"]' ); ?>
				<?php endif; ?>

				<?php if ( $completa && $completa['fuente'] ) : ?>
					<p class="uhpa-nota"><?php echo esc_html( 'Fuente: ' . $completa['fuente'] ); ?></p>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	/* ================================================================= */
	/* Página: Shortcodes                                                */
	/* ================================================================= */

	/**
	 * Catálogo de shortcodes con sus atributos y ejemplos.
	 */
	public function pagina_shortcodes() {
		$this->exigir_cap();

		$this->cabecera(
			__( 'Shortcodes', 'urkunina-5000' ),
			__( 'Los nueve componentes que el plugin pone a disposición del editor de páginas. Cada uno carga solo los recursos que necesita.', 'urkunina-5000' )
		);
		?>
		<div class="uhpa">
			<div class="uhpa-rejilla uhpa-rejilla--2">
				<?php foreach ( $this->catalogo_shortcodes() as $sc ) : ?>
					<section class="uhpa-card">
						<header class="uhpa-card__cab">
							<h2><?php echo esc_html( $sc['titulo'] ); ?></h2>
							<p><?php echo esc_html( $sc['descripcion'] ); ?></p>
							<div class="uhpa-meta"><code><?php echo esc_html( '[' . $sc['tag'] . ']' ); ?></code></div>
						</header>
						<div class="uhpa-card__cuerpo">
							<h3 class="uhpa-h3"><?php esc_html_e( 'Ejemplos', 'urkunina-5000' ); ?></h3>
							<?php foreach ( $sc['ejemplos'] as $e ) : ?>
								<?php $this->copiable( $e ); ?>
							<?php endforeach; ?>

							<?php if ( ! empty( $sc['atributos'] ) ) : ?>
								<h3 class="uhpa-h3"><?php esc_html_e( 'Atributos', 'urkunina-5000' ); ?></h3>
								<table class="uhpa-tabla uhpa-tabla--compacta">
									<tbody>
										<?php foreach ( $sc['atributos'] as $nombre => $desc ) : ?>
											<tr>
												<th scope="row"><code><?php echo esc_html( $nombre ); ?></code></th>
												<td><?php echo esc_html( $desc ); ?></td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							<?php endif; ?>

							<?php if ( ! empty( $sc['nota'] ) ) : ?>
								<p class="uhpa-nota"><?php echo esc_html( $sc['nota'] ); ?></p>
							<?php endif; ?>
						</div>
					</section>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
		$this->pie();
	}

	/**
	 * Definición del catálogo de shortcodes.
	 *
	 * @return array<int,array>
	 */
	private function catalogo_shortcodes() {
		return array(
			array(
				'tag'         => 'urkunina_dashboard',
				'titulo'      => __( 'Tablero de resultados', 'urkunina-5000' ),
				'descripcion' => __( 'Los 64 municipios sobre un mapa dibujado con D3 —sin teselas ni mapa base—, con filtros por zona de riesgo y por subregión, la lista de los 55 intervenidos, la ficha del municipio elegido, la prevalencia por subregión y el perfil de los 5.000 participantes. Ocupa el 100 % del ancho y toda la altura de la ventana, y todo se recoloca alrededor del territorio que se elija, sin volver a pedir datos.', 'urkunina-5000' ),
				'ejemplos'    => array(
					'[urkunina_dashboard]',
					'[urkunina_dashboard indicador="hp"]',
					'[urkunina_dashboard alto="calc(100vh - 80px)"]',
				),
				'atributos'   => array(
					'titulo'    => __( 'Título mostrado en la cabecera del tablero.', 'urkunina-5000' ),
					'lema'      => __( 'Línea descriptiva bajo el título. Se oculta en pantallas estrechas.', 'urkunina-5000' ),
					'alto'      => __( 'Altura del contenedor. Por defecto 100vh; use calc() si su tema tiene una barra fija.', 'urkunina-5000' ),
					'indicador' => __( 'Con cuál arranca el mapa y las barras: lpm (por defecto) o hp.', 'urkunina-5000' ),
				),
				'nota'        => __( 'Publíquelo en una página con plantilla de ancho completo y sin barra lateral. El tablero tiene un solo tema, el oscuro sobre el que se diseñó: la rampa del mapa y todos los contrastes están calculados sobre él.', 'urkunina-5000' ),
			),
			array(
				'tag'         => 'urkunina_3d',
				'titulo'      => __( 'Recreación 3D de Helicobacter pylori', 'urkunina-5000' ),
				'descripcion' => __( 'Modelo tridimensional científicamente parametrizado de la bacteria, con una línea de tiempo de diez momentos que recorre el contagio, la colonización, el daño y el tratamiento.', 'urkunina-5000' ),
				'ejemplos'    => array(
					'[urkunina_3d]',
					'[urkunina_3d alto="720px" autoplay="no"]',
					'[urkunina_3d cabecera="no" instrumentos="no" duracion="20"]',
					'[urkunina_3d desplazar="si"]',
				),
				'atributos'   => array(
					'alto'         => __( 'Altura del contenedor. Por defecto 100vh.', 'urkunina-5000' ),
					'autoplay'     => __( 'si o no. Con no, el recorrido arranca en pausa.', 'urkunina-5000' ),
					'duracion'     => __( 'Segundos que dura cada momento de la línea de tiempo.', 'urkunina-5000' ),
					'instrumentos' => __( 'si o no. Muestra u oculta el panel de morfometría.', 'urkunina-5000' ),
					'cabecera'     => __( 'si o no. Muestra u oculta la marca institucional dentro de la escena.', 'urkunina-5000' ),
					'desplazar'    => __( 'si o no. Por defecto no. Con si, la página se desplaza hasta el objeto cada vez que la línea de tiempo cambia de momento; solo tiene sentido si el objeto abre la página.', 'urkunina-5000' ),
				),
				'nota'        => __( 'Requiere un navegador con WebGL 2. Si no está disponible, el componente muestra un mensaje explicativo en lugar de quedarse en blanco.', 'urkunina-5000' ),
			),
			array(
				'tag'         => 'urkunina_grafico',
				'titulo'      => __( 'Gráfico de una vista', 'urkunina-5000' ),
				'descripcion' => __( 'Dibuja cualquier vista del catálogo con D3plus, acompañada de su barra de herramientas —detalle, tabla de datos, compartir, exportar a PNG, descargar JSON y cambiar de tipo en vivo— y de su texto de análisis.', 'urkunina-5000' ),
				'ejemplos'    => array(
					'[urkunina_grafico view="tamizaje_hp" type="donut"]',
					'[urkunina_grafico view="prev_lpm_municipios" type="mapa" teselas="si"]',
					'[urkunina_grafico grupo="Prevalencia" alto="420px" titulo="no"]',
					'[urkunina_grafico view="metas_mga" barra="no" titulo="no"]',
				),
				'atributos'   => array(
					'view'      => __( 'Identificador de la vista. Consulte el módulo Gráficos.', 'urkunina-5000' ),
					'type'      => __( 'Tipo de gráfico con el que arranca. Si se omite o la vista no lo admite, se usa su tipo por defecto. Las vistas que nombran territorios admiten además «mapa».', 'urkunina-5000' ),
					'titulo'    => __( 'Sustituye el título de la vista; con «no» se oculta.', 'urkunina-5000' ),
					'alto'      => __( 'Altura del lienzo del gráfico.', 'urkunina-5000' ),
					'tema'      => __( 'claro u oscuro.', 'urkunina-5000' ),
					'acciones'  => __( 'Lista separada por comas de los botones a mostrar.', 'urkunina-5000' ),
					'barra'     => __( 'si o no. Oculta toda la barra de herramientas.', 'urkunina-5000' ),
					'grupo'     => __( 'Nombre de una pestaña del módulo Gráficos. El gráfico obedece entonces al [urkunina_selector] de ese grupo.', 'urkunina-5000' ),
					'views'     => __( 'Lista de vistas separadas por comas, como alternativa a «grupo».', 'urkunina-5000' ),
					'canal'     => __( 'Nombre del canal, para tener dos selectores independientes del mismo grupo en una página.', 'urkunina-5000' ),
					'teselas'   => __( 'si o no. Solo en el tipo «mapa»: enciende la capa base de teselas.', 'urkunina-5000' ),
					'serie'     => __( 'Solo en el tipo «mapa» y en vistas con más de un indicador por territorio: cuál de ellos se colorea.', 'urkunina-5000' ),
					'etiquetas' => __( 'si o no. Solo en el tipo «mapa»: rotula los territorios sobre la geometría.', 'urkunina-5000' ),
				),
				'nota'        => __( 'Dibuja SOLO el gráfico. La descripción, la interpretación, el resumen, las cifras y la fuente son shortcodes aparte, para poder maquetarlos donde convenga. No olvide publicar la fuente: su cita es obligatoria.', 'urkunina-5000' ),
			),
			array(
				'tag'         => 'urkunina_geomapa',
				'titulo'      => __( 'Geomapa de una vista territorial', 'urkunina-5000' ),
				'descripcion' => __( 'Mapa coroplético dibujado con D3plus Geomap: es un gráfico más del módulo, del mismo motor y con la misma lectura que el resto de vistas. Colorea el territorio a partir de una vista del catálogo que nombre municipios o subregiones, o de uno de los cuatro indicadores del mapa. La capa base de teselas se enciende y se apaga desde el propio shortcode.', 'urkunina-5000' ),
				'ejemplos'    => array(
					'[urkunina_geomapa view="prev_lpm_municipios"]',
					'[urkunina_geomapa view="prev_subregion_lpm" alto="520px"]',
					'[urkunina_geomapa view="prev_subregion" serie="Infección por H. pylori"]',
					'[urkunina_geomapa indicador="intervencion" teselas="si"]',
					'[urkunina_geomapa view="cancer_municipios" tema="oscuro" leyenda="no"]',
				),
				'atributos'   => array(
					'view'      => __( 'Vista territorial del catálogo. El nivel —municipios o subregiones— lo decide la propia vista.', 'urkunina-5000' ),
					'indicador' => __( 'Alternativa a «view»: lpm, hpylori, cancer o intervencion. Siempre municipal.', 'urkunina-5000' ),
					'serie'     => __( 'Solo para vistas con más de un indicador por territorio, como «prev_subregion»: elige cuál se colorea. Si se omite se dibuja el primero y el título lo dice.', 'urkunina-5000' ),
					'teselas'   => __( 'si o no (por defecto, no). Enciende la capa base de cartografía bajo el territorio.', 'urkunina-5000' ),
					'capa'      => __( 'claro, oscuro u osm. Elige el proveedor de teselas; si se omite, se usa el que corresponde al tema.', 'urkunina-5000' ),
					'titulo'    => __( 'Sustituye el título; con «no» se oculta.', 'urkunina-5000' ),
					'alto'      => __( 'Altura del lienzo.', 'urkunina-5000' ),
					'tema'      => __( 'claro u oscuro.', 'urkunina-5000' ),
					'leyenda'   => __( 'si o no. Muestra la rampa de color con su rango.', 'urkunina-5000' ),
					'etiquetas' => __( 'si o no. Escribe el nombre sobre cada territorio; conviene solo en mapas grandes.', 'urkunina-5000' ),
					'zoom'      => __( 'si o no. Permite acercar y desplazar el mapa.', 'urkunina-5000' ),
				),
				'nota'        => __( 'Con teselas encendidas, la atribución de OpenStreetMap y del proveedor se imprime sola: es condición de la licencia y no debe retirarse. Para navegar el territorio municipio a municipio, con ficha emergente y selector de indicador, use [urkunina_mapa], que va sobre Leaflet.', 'urkunina-5000' ),
			),
			array(
				'tag'         => 'urkunina_mapa',
				'titulo'      => __( 'Mapa por municipio', 'urkunina-5000' ),
				'descripcion' => __( 'Mapa coroplético de los 64 municipios de Nariño sobre cartografía de OpenStreetMap, con selector de indicador, leyenda de escala y ficha emergente por municipio.', 'urkunina-5000' ),
				'ejemplos'    => array(
					'[urkunina_mapa]',
					'[urkunina_mapa indicador="cancer" alto="620px"]',
					'[urkunina_mapa indicador="intervencion" selector="no" teselas="claro"]',
				),
				'atributos'   => array(
					'titulo'    => __( 'Título de la cabecera del mapa.', 'urkunina-5000' ),
					'indicador' => __( 'lpm, hpylori, cancer o intervencion.', 'urkunina-5000' ),
					'selector'  => __( 'si o no. Permite o impide cambiar de indicador.', 'urkunina-5000' ),
					'teselas'   => __( 'osm, claro o humanitario.', 'urkunina-5000' ),
					'alto'      => __( 'Altura del mapa.', 'urkunina-5000' ),
				),
				'nota'        => __( 'Los municipios sin dato publicado se colorean en gris: los informes solo difunden los diez municipios con mayor prevalencia en cada indicador.', 'urkunina-5000' ),
			),
			array(
				'tag'         => 'urkunina_kpi',
				'titulo'      => __( 'Cifras clave', 'urkunina-5000' ),
				'descripcion' => __( 'Tarjetas con las seis cifras principales del proyecto. Se renderiza en el servidor, de modo que aparece en el HTML aunque el visitante tenga JavaScript desactivado.', 'urkunina-5000' ),
				'ejemplos'    => array(
					'[urkunina_kpi]',
					'[urkunina_kpi solo="participantes,municipios,cancer"]',
					'[urkunina_kpi notas="no"]',
				),
				'atributos'   => array(
					'solo'  => __( 'Lista separada por comas: participantes, municipios, hpylori, lpm, cancer, muestras.', 'urkunina-5000' ),
					'notas' => __( 'si o no. Muestra u oculta la nota aclaratoria de cada tarjeta.', 'urkunina-5000' ),
				),
			),
			array(
				'tag'         => 'urkunina_descripcion',
				'titulo'      => __( 'Texto: qué muestra el gráfico', 'urkunina-5000' ),
				'descripcion' => __( 'Explica qué representa la vista, en qué unidades y cómo leer sus ejes, en lenguaje claro y sin jerga clínica. Es el texto escrito a mano y no depende de las cifras.', 'urkunina-5000' ),
				'ejemplos'    => array(
					'[urkunina_descripcion view="tamizaje_hp"]',
				),
				'atributos'   => array(
					'view' => __( 'Identificador de la vista.', 'urkunina-5000' ),
				),
			),
			array(
				'tag'         => 'urkunina_interpretacion',
				'titulo'      => __( 'Texto: qué significa', 'urkunina-5000' ),
				'descripcion' => __( 'El análisis cualitativo: el contexto epidemiológico o de política pública que convierte el dato en información útil. También escrito a mano.', 'urkunina-5000' ),
				'ejemplos'    => array(
					'[urkunina_interpretacion view="zonas_riesgo"]',
				),
				'atributos'   => array(
					'view' => __( 'Identificador de la vista.', 'urkunina-5000' ),
				),
			),
			array(
				'tag'         => 'urkunina_resumen',
				'titulo'      => __( 'Texto: lectura automática', 'urkunina-5000' ),
				'descripcion' => __( 'Una frase con el hallazgo principal de la vista, redactada a partir de las cifras del archivo de origen. Se actualiza sola cuando los datos cambian.', 'urkunina-5000' ),
				'ejemplos'    => array(
					'[urkunina_resumen view="tamizaje_hp"]',
				),
				'atributos'   => array(
					'view' => __( 'Identificador de la vista.', 'urkunina-5000' ),
				),
			),
			array(
				'tag'         => 'urkunina_cifras',
				'titulo'      => __( 'Texto: cifras de apoyo', 'urkunina-5000' ),
				'descripcion' => __( 'Máximo, mínimo, promedio, total y brecha entre extremos, según lo que corresponda a la vista. También se redacta a partir de los datos.', 'urkunina-5000' ),
				'ejemplos'    => array(
					'[urkunina_cifras view="prev_subregion_lpm"]',
				),
				'atributos'   => array(
					'view' => __( 'Identificador de la vista.', 'urkunina-5000' ),
				),
			),
			array(
				'tag'         => 'urkunina_fuente',
				'titulo'      => __( 'Texto: fuente del dato', 'urkunina-5000' ),
				'descripcion' => __( 'La atribución de la vista. Su cita es obligatoria al publicar los datos del proyecto, y desde que el gráfico no la incluye hay que publicarla explícitamente.', 'urkunina-5000' ),
				'ejemplos'    => array(
					'[urkunina_fuente view="tamizaje_hp"]',
				),
				'atributos'   => array(
					'view' => __( 'Identificador de la vista.', 'urkunina-5000' ),
				),
			),
			array(
				'tag'         => 'urkunina_selector',
				'titulo'      => __( 'Lista que agrupa todas las vistas de una pestaña', 'urkunina-5000' ),
				'descripcion' => __( 'Una sola lista desplegable gobierna al título, la descripción, el análisis, las cifras, la fuente, la tabla y el gráfico que compartan su grupo: al elegir un nombre cambian todos a la vez. Cada pieza sigue siendo su propio shortcode, de modo que pueden colocarse en columnas distintas, en otro orden o repartidas por la página.', 'urkunina-5000' ),
				'ejemplos'    => array(
					'[urkunina_selector grupo="Prevalencia"]',
					'[urkunina_selector grupo="Tamizaje" titulo="Resultados del tamizaje"]',
					'[urkunina_selector views="mortalidad_anio,acceso_oncologico" canal="contexto"]',
				),
				'atributos'   => array(
					'grupo'       => __( 'Nombre de una pestaña del módulo Gráficos: Epidemiología, Tamizaje, Prevalencia, Población, Biobanco, Casos detectados o Proyecto.', 'urkunina-5000' ),
					'views'       => __( 'Lista de vistas separadas por comas, en ese orden, como alternativa a «grupo».', 'urkunina-5000' ),
					'canal'       => __( 'Nombre del canal. Por defecto se deriva del grupo; indíquelo para tener dos selectores independientes del mismo grupo en una página.', 'urkunina-5000' ),
					'view'        => __( 'Vista que arranca seleccionada. Por defecto, la primera de la lista.', 'urkunina-5000' ),
					'etiqueta'    => __( 'Texto de la etiqueta del control. Por defecto «Vista».', 'urkunina-5000' ),
					'titulo'      => __( 'Título opcional encima de la lista.', 'urkunina-5000' ),
					'descripcion' => __( 'si o no. Muestra bajo la lista la descripción corta de la vista elegida.', 'urkunina-5000' ),
					'tarjeta'     => __( 'si o no. Con «no» se pinta solo el control, sin borde ni fondo.', 'urkunina-5000' ),
				),
				'nota'        => __( 'Los mismos atributos «grupo», «views» y «canal» se aceptan en [urkunina_titulo], [urkunina_descripcion], [urkunina_interpretacion], [urkunina_resumen], [urkunina_cifras], [urkunina_fuente], [urkunina_analisis], [urkunina_tabla] y [urkunina_grafico]. Los textos y las tablas llegan ya en el HTML —un panel por vista— de modo que sin JavaScript se lee igualmente la vista activa.', 'urkunina-5000' ),
			),
			array(
				'tag'         => 'urkunina_titulo',
				'titulo'      => __( 'Texto: título de la vista', 'urkunina-5000' ),
				'descripcion' => __( 'El nombre de la vista como encabezado propio, para titular una sección cuyo gráfico se publica sin título.', 'urkunina-5000' ),
				'ejemplos'    => array(
					'[urkunina_titulo view="tamizaje_hp"]',
					'[urkunina_titulo view="zonas_riesgo" etiqueta="h2"]',
				),
				'atributos'   => array(
					'view'     => __( 'Identificador de la vista.', 'urkunina-5000' ),
					'etiqueta' => __( 'h2, h3, h4, h5 o p. Por defecto h3.', 'urkunina-5000' ),
				),
			),
			array(
				'tag'         => 'urkunina_analisis',
				'titulo'      => __( 'Texto: varias piezas juntas', 'urkunina-5000' ),
				'descripcion' => __( 'Atajo que imprime varias de las piezas anteriores de una vez, para cuando no hace falta maquetarlas por separado.', 'urkunina-5000' ),
				'ejemplos'    => array(
					'[urkunina_analisis view="zonas_riesgo" modo="completo"]',
					'[urkunina_analisis view="tamizaje_lpm" modo="cuantitativo"]',
				),
				'atributos'   => array(
					'view' => __( 'Identificador de la vista.', 'urkunina-5000' ),
					'modo' => __( 'descripcion, analisis, descriptivo, cuantitativo, ambos o completo.', 'urkunina-5000' ),
				),
			),
			array(
				'tag'         => 'urkunina_tabla',
				'titulo'      => __( 'Tabla de datos', 'urkunina-5000' ),
				'descripcion' => __( 'Los datos de una vista en forma de tabla accesible, renderizada en el servidor. Útil como alternativa textual del gráfico.', 'urkunina-5000' ),
				'ejemplos'    => array(
					'[urkunina_tabla view="prev_subregion_lpm"]',
					'[urkunina_tabla view="metas_mga" limite="10"]',
				),
				'atributos'   => array(
					'view'   => __( 'Identificador de la vista.', 'urkunina-5000' ),
					'titulo' => __( 'Título accesible de la tabla.', 'urkunina-5000' ),
					'limite' => __( 'Número máximo de filas. 0 las muestra todas.', 'urkunina-5000' ),
				),
			),
			array(
				'tag'         => 'urkunina_ficha',
				'titulo'      => __( 'Ficha del proyecto', 'urkunina-5000' ),
				'descripcion' => __( 'Identificación, aprobación, financiación y estado de ejecución del proyecto en una sola ficha.', 'urkunina-5000' ),
				'ejemplos'    => array(
					'[urkunina_ficha]',
					'[urkunina_ficha titulo="Identificación del proyecto"]',
				),
				'atributos'   => array(
					'titulo' => __( 'Título de la ficha.', 'urkunina-5000' ),
				),
			),
			array(
				'tag'         => 'urkunina_dato',
				'titulo'      => __( 'Un dato suelto', 'urkunina-5000' ),
				'descripcion' => __( 'Intercala una cifra concreta del conjunto de datos en mitad de un párrafo. Al leerse del archivo, el texto no queda desactualizado cuando el dato cambia.', 'urkunina-5000' ),
				'ejemplos'    => array(
					'[urkunina_dato archivo="tamizaje" ruta="infeccion_h_pylori.positivos.porcentaje" formato="porcentaje"]',
					'[urkunina_dato archivo="proyecto" ruta="financiacion.presupuesto_total" formato="pesos"]',
					'[urkunina_dato archivo="cancer" ruta="resumen.casos_detectados" formato="entero"]',
				),
				'atributos'   => array(
					'archivo' => __( 'Clave del archivo del conjunto: proyecto, tamizaje, cancer, biobanco…', 'urkunina-5000' ),
					'ruta'    => __( 'Ruta de la clave dentro del archivo, separada por puntos.', 'urkunina-5000' ),
					'formato' => __( 'auto, entero, porcentaje o pesos.', 'urkunina-5000' ),
				),
			),
		);
	}

	/* ================================================================= */
	/* Página: Componentes                                               */
	/* ================================================================= */

	/**
	 * Lleva la pestaña vieja «Componentes → Asistente de voz» al módulo nuevo.
	 *
	 * Hay marcadores, documentación y el propio historial del navegador del
	 * administrador apuntando ahí. Sin esto, abrían Componentes y caían en
	 * la pestaña del tablero sin saber dónde había ido el asistente.
	 */
	public function redirigir_pestana_vieja() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Solo decide adónde llevar una URL de navegación.
		if ( ! isset( $_GET['page'], $_GET['tab'] ) ) {
			return;
		}
		if ( 'uhp-componentes' !== $_GET['page'] || 'voz' !== $_GET['tab'] ) {
			return;
		}
		// phpcs:enable
		wp_safe_redirect( admin_url( 'admin.php?page=uhp-asistente' ) );
		exit;
	}

	/**
	 * Módulo «Asistente AI».
	 */
	public function pagina_asistente() {
		$this->exigir_cap();

		$this->cabecera(
			__( 'Asistente AI', 'urkunina-5000' ),
			__( 'Un botón «Asistente» en el tablero que atiende preguntas por texto o por voz, mueve el mapa y las gráficas mientras responde, y lee la respuesta en alto.', 'urkunina-5000' )
		);
		?>
		<div class="uhpa">
			<?php $this->form_asistente(); ?>
		</div>
		<?php
		$this->pie();
	}

	/**
	 * Configuración del tablero y del objeto 3D.
	 */
	public function pagina_componentes() {
		$this->exigir_cap();

		$tab = isset( $_GET['tab'] ) ? UHP_Security::clave( wp_unslash( $_GET['tab'] ) ) : 'tablero'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Solo selecciona qué pestaña pintar.
		if ( ! in_array( $tab, array( 'tablero', 'objeto3d' ), true ) ) {
			$tab = 'tablero';
		}

		$this->cabecera(
			__( 'Componentes', 'urkunina-5000' ),
			__( 'Valores por defecto del tablero y del objeto 3D. Cada shortcode puede sobrescribirlos con sus propios atributos.', 'urkunina-5000' )
		);

		$this->pestanas(
			'uhp-componentes',
			array(
				'tablero'  => __( 'Tablero', 'urkunina-5000' ),
				'objeto3d' => __( 'Objeto 3D', 'urkunina-5000' ),
			),
			$tab
		);
		?>
		<div class="uhpa">
			<?php if ( 'tablero' === $tab ) : ?>
				<?php $this->form_tablero(); ?>
			<?php else : ?>
				<?php $this->form_3d(); ?>
			<?php endif; ?>
		</div>
		<?php
		$this->pie();
	}

	/**
	 * Formulario de configuración del tablero.
	 */
	private function form_tablero() {
		$cfg = get_option( 'uhp_dashboard', UHP_Activator::dashboard_por_defecto() );
		$cfg = wp_parse_args( is_array( $cfg ) ? $cfg : array(), UHP_Activator::dashboard_por_defecto() );
		?>
		<div class="uhpa-rejilla uhpa-rejilla--2">
			<section class="uhpa-card">
				<header class="uhpa-card__cab">
					<h2><?php esc_html_e( 'Valores por defecto del tablero', 'urkunina-5000' ); ?></h2>
					<p><?php esc_html_e( 'Se aplican a [urkunina_dashboard] cuando el shortcode no indica lo contrario.', 'urkunina-5000' ); ?></p>
				</header>
				<div class="uhpa-card__cuerpo">
					<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
						<?php settings_fields( 'uhp_grupo_dashboard' ); ?>

						<p>
							<label class="uhpa-label" for="uhp-db-titulo"><?php esc_html_e( 'Título del tablero', 'urkunina-5000' ); ?></label>
							<input type="text" id="uhp-db-titulo" class="uhpa-input" name="uhp_dashboard[titulo]"
								value="<?php echo esc_attr( $cfg['titulo'] ); ?>">
						</p>

						<p>
							<label class="uhpa-label" for="uhp-db-lema"><?php esc_html_e( 'Lema', 'urkunina-5000' ); ?></label>
							<input type="text" id="uhp-db-lema" class="uhpa-input" name="uhp_dashboard[lema]"
								value="<?php echo esc_attr( $cfg['lema'] ); ?>">
						</p>
						<p class="uhpa-ayuda">
							<?php esc_html_e( 'La línea que acompaña al título en la cabecera. Se oculta en pantallas estrechas.', 'urkunina-5000' ); ?>
						</p>

						<p>
							<label class="uhpa-label" for="uhp-db-ind"><?php esc_html_e( 'Indicador inicial', 'urkunina-5000' ); ?></label>
							<select id="uhp-db-ind" class="uhpa-select" name="uhp_dashboard[indicador]">
								<option value="lpm" <?php selected( 'lpm', $cfg['indicador'] ); ?>>
									<?php esc_html_e( 'Lesión precursora de malignidad', 'urkunina-5000' ); ?>
								</option>
								<option value="hp" <?php selected( 'hp', $cfg['indicador'] ); ?>>
									<?php esc_html_e( 'Infección por H. pylori', 'urkunina-5000' ); ?>
								</option>
							</select>
						</p>
						<p class="uhpa-ayuda">
							<?php esc_html_e( 'Con cuál arrancan el mapa y las barras por subregión. Quien visita la página puede cambiarlo desde la tarjeta de prevalencia.', 'urkunina-5000' ); ?>
						</p>

						<?php submit_button( __( 'Guardar', 'urkunina-5000' ) ); ?>
					</form>
				</div>
			</section>

			<section class="uhpa-card">
				<header class="uhpa-card__cab">
					<h2><?php esc_html_e( 'Cómo publicarlo', 'urkunina-5000' ); ?></h2>
					<p><?php esc_html_e( 'El tablero ocupa todo el ancho y toda la altura de la ventana: necesita una plantilla de página sin barra lateral.', 'urkunina-5000' ); ?></p>
				</header>
				<div class="uhpa-card__cuerpo">
					<?php $this->copiable( '[urkunina_dashboard]' ); ?>
					<?php $this->copiable( '[urkunina_dashboard alto="720px"]' ); ?>
					<?php $this->copiable( '[urkunina_dashboard indicador="hp"]' ); ?>

					<h3 class="uhpa-h3"><?php esc_html_e( 'Qué muestra', 'urkunina-5000' ); ?></h3>
					<p class="uhpa-texto">
						<?php esc_html_e( 'Los 64 municipios del departamento sobre un mapa dibujado con D3, con filtros por zona de riesgo y por subregión, la lista de los 55 intervenidos, la ficha del municipio elegido y el perfil de los 5.000 participantes.', 'urkunina-5000' ); ?>
					</p>

					<h3 class="uhpa-h3"><?php esc_html_e( 'Lo que el tablero declara', 'urkunina-5000' ); ?></h3>
					<ul class="uhpa-lista">
						<li><?php esc_html_e( 'Un municipio sin cifra propia se pinta con la de su subregión, atenuado, y lo dice en el tooltip y en la ficha. El informe solo publica los extremos de la distribución municipal.', 'urkunina-5000' ); ?></li>
						<li><?php esc_html_e( 'Un municipio no intervenido va con trama discontinua y sin color: no falta el dato, es que el proyecto no estuvo allí.', 'urkunina-5000' ); ?></li>
						<li><?php esc_html_e( 'El perfil de los participantes no responde a los filtros: está publicado para el conjunto de los 5.000, no municipio a municipio.', 'urkunina-5000' ); ?></li>
						<li><?php esc_html_e( 'La cifra de participantes por selección es un prorrateo y lo dice en su rótulo.', 'urkunina-5000' ); ?></li>
					</ul>

					<h3 class="uhpa-h3"><?php esc_html_e( 'Zona de riesgo', 'urkunina-5000' ); ?></h3>
					<p class="uhpa-nota">
						<?php esc_html_e( 'Los documentos describen las tres zonas y nombran algunos territorios de referencia, pero no reparten el departamento entre ellas. La asignación que usa el tablero se hace por subregión y vive en el archivo 17_zonas_riesgo_subregion.json, marcada como derivación y con su comprobación contra esas referencias.', 'urkunina-5000' ); ?>
					</p>
					<p>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=uhp-datos&tab=zonas' ) ); ?>">
							<?php esc_html_e( 'Ver y editar la tabla de zonas', 'urkunina-5000' ); ?>
						</a>
					</p>
				</div>
			</section>
		</div>
		<?php
	}

	/**
	 * Formulario del asistente de voz.
	 */
	private function form_asistente() {
		$cfg     = UHP_Voz::cfg();
		$falta   = UHP_Voz::pendientes();
		$avisos  = UHP_Voz::advertencias();
		$listo   = UHP_Voz::configurada();
		$con_voz = UHP_Voz::con_voz();

		$tiene_llm = '' !== UHP_Voz::clave_llm();
		$tiene_el  = '' !== UHP_Voz::clave_api();
		$cte_llm   = defined( 'UHP_LLM_API_KEY' );
		$cte_el    = defined( 'UHP_ELEVENLABS_API_KEY' );
		$verif     = UHP_Voz::verificacion();
		$voces     = $tiene_el ? UHP_Voz::voces() : array();
		$voces_ok  = is_array( $voces ) && ! isset( $voces['__error'] );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
			<?php settings_fields( 'uhp_grupo_voz' ); ?>

			<section class="uhpa-card uhpa-card--ancha">
				<header class="uhpa-card__cab">
					<h2><?php esc_html_e( 'Estado del módulo', 'urkunina-5000' ); ?></h2>
				</header>
				<div class="uhpa-card__cuerpo">
					<p>
						<label><input type="checkbox" name="uhp_voz[activo]" value="1" <?php checked( 1, (int) $cfg['activo'] ); ?>>
							<strong><?php esc_html_e( 'Activar', 'urkunina-5000' ); ?></strong>
							— <?php esc_html_e( 'el asistente puede responder consultas.', 'urkunina-5000' ); ?></label>
					</p>

					<?php
					/* Una casilla, como en el resto de módulos de la entidad, en
					   vez del desplegable de tres opciones. Desmarcada no apaga
					   nada: deja el asistente activo pero visible solo para quien
					   puede editar el sitio, que es lo que hace falta para
					   probarlo de verdad antes de publicarlo.

					   El tercer modo de antes —«oculto», sin botón, para abrirlo
					   desde la plantilla— no se pierde: si el sitio ya lo tenía,
					   se conserva mientras nadie toque esta casilla. */
					?>
					<input type="hidden" name="uhp_voz[visibilidad_previa]" value="<?php echo esc_attr( $cfg['visibilidad'] ); ?>">
					<p>
						<label><input type="checkbox" name="uhp_voz[visible]" value="1" <?php checked( 'todos', $cfg['visibilidad'] ); ?>>
							<strong><?php esc_html_e( 'Visible en el sitio', 'urkunina-5000' ); ?></strong>
							— <?php esc_html_e( 'se muestra en el tablero y donde se use el shortcode. Si lo desmarca, el asistente queda activo pero solo lo ven quienes pueden editar el sitio (útil para probarlo antes de publicarlo).', 'urkunina-5000' ); ?></label>
					</p>
					<?php if ( 'oculto' === $cfg['visibilidad'] ) : ?>
						<p class="uhpa-nota"><?php esc_html_e( 'Ahora mismo está en modo «sin botón»: el asistente funciona pero solo se abre desde un control propio de la plantilla. Se conserva mientras no cambie esta casilla.', 'urkunina-5000' ); ?></p>
					<?php elseif ( 'editores' === $cfg['visibilidad'] ) : ?>
						<p class="uhpa-alerta uhpa-alerta--aviso">
							<strong><?php esc_html_e( 'En prueba: el visitante anónimo no lo ve.', 'urkunina-5000' ); ?></strong>
							<?php esc_html_e( 'El plugin le pide a la caché que no guarde el tablero. Si su sitio tiene delante una caché o una CDN que sirve una sola copia a todo el mundo sin mirar la sesión, compruebe en una ventana privada que el botón no aparece.', 'urkunina-5000' ); ?>
						</p>
					<?php endif; ?>

					<p>
						<label class="uhpa-label" for="uhp-voz-anfitrion"><?php esc_html_e( 'Nombre del anfitrión', 'urkunina-5000' ); ?></label>
						<input type="text" id="uhp-voz-anfitrion" class="uhpa-input uhpa-input--medio" name="uhp_voz[anfitrion]" maxlength="40"
							value="<?php echo esc_attr( $cfg['anfitrion'] ); ?>"
							placeholder="<?php echo esc_attr( UHP_Voz::anfitrion( array_merge( $cfg, array( 'anfitrion' => '' ) ) ) ); ?>">
						<span class="uhpa-nota"><?php esc_html_e( 'Se muestra encima del saludo y de cada respuesta, como quien recibe a la persona.', 'urkunina-5000' ); ?></span>
					</p>

					<p>
						<label class="uhpa-label" for="uhp-voz-saludo"><?php esc_html_e( 'Saludo inicial', 'urkunina-5000' ); ?></label>
						<textarea id="uhp-voz-saludo" class="uhpa-input" name="uhp_voz[saludo]" rows="3" maxlength="400"
							placeholder="<?php echo esc_attr( UHP_Voz::saludo_por_omision() ); ?>"><?php echo esc_textarea( $cfg['saludo'] ); ?></textarea>
						<span class="uhpa-nota"><?php esc_html_e( 'Si lo deja vacío se usa el texto del ejemplo. Escríbalo de tú y en tono cercano, como el resto de la conversación: un saludo formal seguido de respuestas cálidas suena a dos personas distintas.', 'urkunina-5000' ); ?></span>
					</p>

					<p>
						<label class="uhpa-label" for="uhp-voz-sugerencias"><?php esc_html_e( 'Preguntas sugeridas (una por línea)', 'urkunina-5000' ); ?></label>
						<textarea id="uhp-voz-sugerencias" class="uhpa-input" name="uhp_voz[sugerencias]" rows="6"
							placeholder="<?php echo esc_attr( implode( "\n", UHP_Voz::sugerencias_por_omision() ) ); ?>"><?php echo esc_textarea( $cfg['sugerencias'] ); ?></textarea>
						<span class="uhpa-nota"><?php esc_html_e( 'Aparecen como pastillas encima de la barra de escritura (máximo 8, de unos 30 caracteres: la pastilla no parte líneas). Escriba preguntas que el asistente pueda responder con los datos del sitio: una sugerencia que acaba en «no tengo ese dato» enseña a no volver a pulsar ninguna. Vacío = las del ejemplo.', 'urkunina-5000' ); ?></span>
					</p>
				</div>
			</section>

			<div class="uhpa-rejilla uhpa-rejilla--2">
				<section class="uhpa-card">
					<header class="uhpa-card__cab">
						<h2><?php esc_html_e( 'Inteligencia artificial', 'urkunina-5000' ); ?></h2>
					</header>
					<div class="uhpa-card__cuerpo">
					<p class="uhpa-texto"><strong><?php esc_html_e( 'El modelo de lenguaje: quien entiende la pregunta', 'urkunina-5000' ); ?></strong></p>
					<p class="uhpa-nota"><?php esc_html_e( 'Es la única pieza obligatoria. Lee la pregunta, decide qué herramienta del tablero usar —mover el mapa, cambiar el indicador, leer una gráfica— y redacta la respuesta con los datos que el tablero le devuelve.', 'urkunina-5000' ); ?></p>

					<p>
						<label class="uhpa-label" for="uhp-voz-proveedor"><?php esc_html_e( 'Proveedor', 'urkunina-5000' ); ?></label>
						<select id="uhp-voz-proveedor" class="uhpa-input" name="uhp_voz[proveedor]">
							<?php foreach ( array( 'claude', 'openai', 'gemini' ) as $p ) : ?>
								<option value="<?php echo esc_attr( $p ); ?>" <?php selected( $p, $cfg['proveedor'] ); ?>>
									<?php echo esc_html( UHP_LLM::nombre( $p ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<span class="uhpa-nota">
							<?php esc_html_e( 'Los tres sirven y se cambia de uno a otro sin tocar nada más: el plugin traduce por dentro. Hace falta UNA clave, la del proveedor elegido. Dónde se saca:', 'urkunina-5000' ); ?>
							<?php echo esc_html( UHP_LLM::consola( $cfg['proveedor'] ) ); ?>
						</span>
					</p>

					<p>
						<label class="uhpa-label" for="uhp-voz-llm-clave"><?php esc_html_e( 'Clave del proveedor', 'urkunina-5000' ); ?></label>
						<?php if ( $cte_llm ) : ?>
							<span class="uhpa-nota">
								<span class="uhpa-punto uhpa-punto--ok"></span>
								<?php esc_html_e( 'Definida en wp-config.php con la constante UHP_LLM_API_KEY. Es la vía preferente: así no entra en la base de datos ni viaja en una exportación. Este campo se ignora.', 'urkunina-5000' ); ?>
							</span>
						<?php else : ?>
							<input type="password" id="uhp-voz-llm-clave" class="uhpa-input" name="uhp_llm_clave"
								value="" autocomplete="off"
								placeholder="<?php echo esc_attr( $tiene_llm ? __( '— guardada —', 'urkunina-5000' ) : __( 'sin configurar', 'urkunina-5000' ) ); ?>">
							<span class="uhpa-nota">
								<?php esc_html_e( 'Se pinta vacío a propósito: dejarlo en blanco conserva la que está guardada, de modo que tocar cualquier otro ajuste de esta pestaña no la borra. Escriba «borrar» para eliminarla. Mejor todavía: póngala en wp-config.php como UHP_LLM_API_KEY.', 'urkunina-5000' ); ?>
							</span>
						<?php endif; ?>
					</p>

					<p>
						<label class="uhpa-label" for="uhp-voz-modelo"><?php esc_html_e( 'Modelo (opcional)', 'urkunina-5000' ); ?></label>
						<input type="text" id="uhp-voz-modelo" class="uhpa-input" name="uhp_voz[modelo]"
							value="<?php echo esc_attr( $cfg['modelo'] ); ?>"
							placeholder="<?php echo esc_attr( UHP_LLM::modelo_por_defecto( $cfg['proveedor'] ) ); ?>">
						<span class="uhpa-nota">
							<?php
							printf(
								/* translators: %s: nombre del modelo por omisión. */
								esc_html__( 'Vacío usa %s. Se deja escribir a mano a propósito: los proveedores publican modelos nuevos cada pocos meses y una lista fija aquí envejecería mal. Si escribe uno que no existe, la verificación de arriba se lo dice.', 'urkunina-5000' ),
								esc_html( UHP_LLM::modelo_por_defecto( $cfg['proveedor'] ) )
							);
							?>
						</span>
					</p>

					<p>
						<label class="uhpa-label" for="uhp-voz-esfuerzo"><?php esc_html_e( 'Cuánto piensa antes de responder', 'urkunina-5000' ); ?></label>
						<select id="uhp-voz-esfuerzo" class="uhpa-input" name="uhp_voz[esfuerzo]">
							<option value="low" <?php selected( 'low', $cfg['esfuerzo'] ); ?>><?php esc_html_e( 'Poco: responde antes y cuesta menos (recomendado)', 'urkunina-5000' ); ?></option>
							<option value="medium" <?php selected( 'medium', $cfg['esfuerzo'] ); ?>><?php esc_html_e( 'Medio', 'urkunina-5000' ); ?></option>
							<option value="high" <?php selected( 'high', $cfg['esfuerzo'] ); ?>><?php esc_html_e( 'Mucho', 'urkunina-5000' ); ?></option>
						</select>
						<span class="uhpa-nota"><?php esc_html_e( 'Las preguntas de este tablero son concretas —un municipio, un indicador, una cifra— y las responde bien con poco. Subirlo alarga la espera del visitante y sube la factura sin mejorar una respuesta que sale de datos que ya están calculados.', 'urkunina-5000' ); ?></span>
					</p>

					</div>
				</section>

				<section class="uhpa-card">
					<header class="uhpa-card__cab">
						<h2><?php esc_html_e( 'Voz', 'urkunina-5000' ); ?></h2>
					</header>
					<div class="uhpa-card__cuerpo">
					<p class="uhpa-texto"><strong><?php esc_html_e( 'La voz: quien lee la respuesta en alto', 'urkunina-5000' ); ?></strong></p>
					<p class="uhpa-nota"><?php esc_html_e( 'OPCIONAL. Sin esto el asistente funciona entero por escrito, que es un canal completo y no una versión capada. Con esto, además, lee la respuesta en voz alta.', 'urkunina-5000' ); ?></p>

					<p>
						<label class="uhpa-label" for="uhp-voz-clave"><?php esc_html_e( 'Clave de API de ElevenLabs', 'urkunina-5000' ); ?></label>
						<?php if ( $cte_el ) : ?>
							<span class="uhpa-nota">
								<span class="uhpa-punto uhpa-punto--ok"></span>
								<?php esc_html_e( 'Definida en wp-config.php con la constante UHP_ELEVENLABS_API_KEY. Este campo se ignora.', 'urkunina-5000' ); ?>
							</span>
						<?php else : ?>
							<input type="password" id="uhp-voz-clave" class="uhpa-input" name="uhp_voz_clave"
								value="" autocomplete="off"
								placeholder="<?php echo esc_attr( $tiene_el ? __( '— guardada —', 'urkunina-5000' ) : __( 'sin configurar', 'urkunina-5000' ) ); ?>">
							<span class="uhpa-nota">
								<?php esc_html_e( 'La de «Desarrolladores → Claves de API» en el panel de ElevenLabs. Igual que la otra: vacío conserva la guardada, «borrar» la elimina, y en wp-config.php está mejor como UHP_ELEVENLABS_API_KEY.', 'urkunina-5000' ); ?>
							</span>
						<?php endif; ?>
					</p>

					<p>
						<label class="uhpa-label" for="uhp-voz-id"><?php esc_html_e( 'Voz', 'urkunina-5000' ); ?></label>
						<?php if ( $voces_ok && ! empty( $voces ) ) : ?>
							<?php
							// Las voces de la cuenta, para ELEGIR. Mientras
							// esto fue un campo de texto, lo que se pegaba
							// era el identificador de los ejemplos de la
							// documentación de ElevenLabs, que tiene forma
							// válida y no está en ninguna cuenta: se
							// guardaba sin protestar y fallaba al hablar.
							$suelta = '' !== $cfg['voz_id'] && ! isset( $voces[ $cfg['voz_id'] ] );
							?>
							<select id="uhp-voz-id" class="uhpa-input" name="uhp_voz[voz_id]">
								<option value="" <?php selected( '', $cfg['voz_id'] ); ?>><?php esc_html_e( '— Sin voz: el asistente responde solo por escrito —', 'urkunina-5000' ); ?></option>
								<?php foreach ( $voces as $id => $nombre ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $id, $cfg['voz_id'] ); ?>>
										<?php echo esc_html( $nombre . ' — ' . $id ); ?>
									</option>
								<?php endforeach; ?>
								<?php if ( $suelta ) : ?>
									<option value="<?php echo esc_attr( $cfg['voz_id'] ); ?>" selected>
										<?php
										printf(
											/* translators: %s: identificador configurado. */
											esc_html__( '%s — NO está en esta cuenta', 'urkunina-5000' ),
											esc_html( $cfg['voz_id'] )
										);
										?>
									</option>
								<?php endif; ?>
							</select>
							<?php if ( $suelta ) : ?>
								<span class="uhpa-alerta uhpa-alerta--error">
									<?php esc_html_e( 'La voz guardada no pertenece a esta cuenta de ElevenLabs. Elija una de la lista: mientras siga así, el asistente responderá por escrito y no dirá nada en alto.', 'urkunina-5000' ); ?>
								</span>
							<?php endif; ?>
							<span class="uhpa-nota"><?php esc_html_e( 'Son las voces guardadas en su cuenta, leídas de ElevenLabs. Elija una que hable español de verdad: una voz entrenada en inglés lee «Ipiales» y «EPS» como lo que no son. En el canal escrito no se usa.', 'urkunina-5000' ); ?></span>
						<?php else : ?>
							<input type="text" id="uhp-voz-id" class="uhpa-input" name="uhp_voz[voz_id]"
								value="<?php echo esc_attr( $cfg['voz_id'] ); ?>"
								placeholder="<?php esc_attr_e( 'vacío = sin voz, solo por escrito', 'urkunina-5000' ); ?>">
							<?php if ( is_array( $voces ) && isset( $voces['__error'] ) ) : ?>
								<span class="uhpa-alerta uhpa-alerta--error">
									<?php
									printf(
										/* translators: %s: motivo. */
										esc_html__( 'No se pudo leer la lista de voces de la cuenta: %s', 'urkunina-5000' ),
										esc_html( $voces['__error'] )
									);
									?>
									<?php esc_html_e( 'Por eso este campo es de texto y no una lista. Arregle la clave y vuelva a cargar la página para elegir de la lista.', 'urkunina-5000' ); ?>
								</span>
							<?php elseif ( ! $tiene_el ) : ?>
								<span class="uhpa-nota"><?php esc_html_e( 'Guarde primero la clave de ElevenLabs y vuelva a cargar esta página: entonces este campo se convierte en una lista con las voces de su cuenta y no hay que copiar identificadores a mano.', 'urkunina-5000' ); ?></span>
							<?php else : ?>
								<span class="uhpa-nota"><?php esc_html_e( 'La cuenta no tiene ninguna voz guardada. Añada una desde la biblioteca de ElevenLabs y vuelva a cargar esta página.', 'urkunina-5000' ); ?></span>
							<?php endif; ?>
						<?php endif; ?>
					</p>

					<p>
						<label class="uhpa-label" for="uhp-voz-modelo-voz"><?php esc_html_e( 'Modelo de lectura (opcional)', 'urkunina-5000' ); ?></label>
						<input type="text" id="uhp-voz-modelo-voz" class="uhpa-input" name="uhp_voz[voz_modelo]"
							value="<?php echo esc_attr( $cfg['voz_modelo'] ); ?>" placeholder="eleven_flash_v2_5">
						<span class="uhpa-nota"><?php esc_html_e( 'Vacío usa eleven_flash_v2_5, que admite fijar el idioma en español, cuesta la mitad y responde antes. Si pone otro, compruebe que acepte español: hay modelos que ignoran el idioma y leen las siglas y las cifras con fonética inglesa. Los modelos «flash v2» y «turbo v2» a secas son solo de inglés.', 'urkunina-5000' ); ?></span>
					</p>

					<p>
						<label class="uhpa-label" for="uhp-voz-vel"><?php esc_html_e( 'Velocidad de habla (opcional)', 'urkunina-5000' ); ?></label>
						<input type="number" step="0.05" min="0.70" max="1.20" id="uhp-voz-vel" class="uhpa-input"
							name="uhp_voz[voz_velocidad]"
							value="<?php echo esc_attr( $cfg['voz_velocidad'] ? number_format( (float) $cfg['voz_velocidad'], 2, '.', '' ) : '' ); ?>"
							placeholder="<?php esc_attr_e( 'vacío = velocidad normal', 'urkunina-5000' ); ?>">
						<span class="uhpa-nota"><?php esc_html_e( 'Entre 0,70 y 1,20. Por debajo de 1 habla más despacio, que es lo que agradece buena parte del público de este tablero: la población tamizada tiene entre 40 y 65 años. Fuera de ese margen el valor se descarta y el campo vuelve a quedar vacío, en vez de guardarle un número que no pidió.', 'urkunina-5000' ); ?></span>
					</p>

					</div>
				</section>
			</div>

			<div class="uhpa-rejilla uhpa-rejilla--2">
				<section class="uhpa-card">
					<header class="uhpa-card__cab">
						<h2><?php esc_html_e( 'Micrófono y límites', 'urkunina-5000' ); ?></h2>
					</header>
					<div class="uhpa-card__cuerpo">
					<p>
						<label><input type="checkbox" name="uhp_voz[entrada]" value="ambos" <?php checked( 'texto' !== $cfg['entrada'] ); ?>>
							<strong><?php esc_html_e( 'Ofrecer el micrófono', 'urkunina-5000' ); ?></strong>
							— <?php esc_html_e( 'aparece el icono para dictar la pregunta.', 'urkunina-5000' ); ?></label>
						<span class="uhpa-nota"><?php esc_html_e( 'El cuadro de escritura está siempre, con esto marcado o sin marcar: es la alternativa equivalente que pide la Resolución 1519 de 2020.', 'urkunina-5000' ); ?></span>
					</p>

					<p>
						<label class="uhpa-label" for="uhp-voz-silencio"><?php esc_html_e( 'Espera tras la última palabra (segundos)', 'urkunina-5000' ); ?></label>
						<input type="number" step="0.5" min="1" max="6" id="uhp-voz-silencio" class="uhpa-input uhpa-input--corto"
							name="uhp_voz[micro_silencio]" value="<?php echo esc_attr( number_format( (float) $cfg['micro_silencio'], 1, '.', '' ) ); ?>">
						<span class="uhpa-nota"><?php esc_html_e( 'Cuánto silencio, después de que la persona empezó a hablar, da por terminada la pregunta. El micrófono espera a que empiece a hablar y no corta en la primera pausa: quien se toma un respiro a mitad de la pregunta no ha terminado. Súbalo si el público hace pausas largas; bájelo si la respuesta tarda en llegar.', 'urkunina-5000' ); ?></span>
					</p>

					<p class="uhpa-alerta uhpa-alerta--aviso">
						<strong><?php esc_html_e( 'El dictado por micrófono no lo hace este servidor.', 'urkunina-5000' ); ?></strong>
						<?php esc_html_e( 'Lo hace el navegador del visitante, y en Chrome, Edge y Safari eso significa que el audio se envía a servidores de Google o de Apple para convertirlo en texto. Ni este sitio ni el proveedor de la voz intervienen ahí, y la entidad no controla ese tratamiento ni puede pactarlo: es del navegador que el ciudadano eligió. En Firefox, Opera, Brave y Vivaldi no funciona en absoluto, y esos visitantes escribirán su pregunta. Si eso no le encaja, desmarque la casilla de arriba: entonces no se pide el micrófono nunca.', 'urkunina-5000' ); ?>
					</p>

						<hr>
					<p>
						<label class="uhpa-label" for="uhp-voz-cuota"><?php esc_html_e( 'Tope de preguntas por día', 'urkunina-5000' ); ?></label>
						<input type="number" min="10" max="5000" id="uhp-voz-cuota" class="uhpa-input"
							name="uhp_voz[cuota_dia]" value="<?php echo esc_attr( (int) $cfg['cuota_dia'] ); ?>">
						<span class="uhpa-nota"><?php esc_html_e( 'Cada pregunta se paga: al proveedor del modelo por el texto que entra y sale, y a ElevenLabs por cada carácter leído en alto. Sin tope, una tarde de tráfico inesperado se lleva el presupuesto del trimestre.', 'urkunina-5000' ); ?></span>
					</p>

					<p>
						<label class="uhpa-label" for="uhp-voz-turnos"><?php esc_html_e( 'Herramientas por pregunta', 'urkunina-5000' ); ?></label>
						<input type="number" min="2" max="20" id="uhp-voz-turnos" class="uhpa-input"
							name="uhp_voz[turnos_max]" value="<?php echo esc_attr( (int) $cfg['turnos_max'] ); ?>">
						<span class="uhpa-nota"><?php esc_html_e( 'Cuántas veces puede el modelo mirar o mover el tablero antes de tener que contestar. Una pregunta como «compara Ipiales con Túquerres» gasta tres o cuatro. El tope existe para que un modelo confundido no se quede dando vueltas a costa de la entidad.', 'urkunina-5000' ); ?></span>
					</p>

					</div>
				</section>

				<section class="uhpa-card">
					<header class="uhpa-card__cab">
						<h2><?php esc_html_e( 'Aviso previo (opcional)', 'urkunina-5000' ); ?></h2>
					</header>
					<div class="uhpa-card__cuerpo">
					<p>
						<label><input type="checkbox" name="uhp_voz[aviso]" value="1" <?php checked( 1, (int) $cfg['aviso'] ); ?>>
							<?php esc_html_e( 'Pedir autorización antes de abrir la conversación', 'urkunina-5000' ); ?></label>
						<span class="uhpa-nota"><?php esc_html_e( 'Viene APAGADO. Con la arquitectura actual el asistente no graba ni guarda la voz de nadie: el navegador convierte el dictado en texto y lo que sale de aquí es texto. Enciéndalo si el área jurídica de la entidad lo pide de todas formas, o si usted prefiere avisar de que la pregunta se envía a un proveedor fuera del país. Mientras esté apagado, el asistente se publica igual: esto ya no es una puerta.', 'urkunina-5000' ); ?></span>
					</p>

					<p>
						<label><input type="checkbox" name="uhp_voz[recordar]" value="1" <?php checked( 1, (int) $cfg['recordar'] ); ?>>
							<?php esc_html_e( 'Recordar la autorización durante la visita', 'urkunina-5000' ); ?></label>
						<span class="uhpa-nota"><?php esc_html_e( 'Se guarda en el almacenamiento de SESIÓN del navegador del visitante y se va al cerrar la pestaña.', 'urkunina-5000' ); ?></span>
					</p>

					<p>
						<label class="uhpa-label" for="uhp-voz-politica"><?php esc_html_e( 'Política de tratamiento de datos (URL)', 'urkunina-5000' ); ?></label>
						<input type="url" id="uhp-voz-politica" class="uhpa-input" name="uhp_voz[politica]"
							value="<?php echo esc_attr( $cfg['politica'] ); ?>">
					</p>
					<p>
						<label class="uhpa-label" for="uhp-voz-correo"><?php esc_html_e( 'Correo para ejercer derechos', 'urkunina-5000' ); ?></label>
						<input type="email" id="uhp-voz-correo" class="uhpa-input" name="uhp_voz[correo]"
							value="<?php echo esc_attr( $cfg['correo'] ); ?>">
					</p>
					<p>
						<label class="uhpa-label" for="uhp-voz-canal"><?php esc_html_e( 'Segundo canal de atención', 'urkunina-5000' ); ?></label>
						<input type="text" id="uhp-voz-canal" class="uhpa-input" name="uhp_voz[canal]"
							value="<?php echo esc_attr( $cfg['canal'] ); ?>"
							placeholder="<?php esc_attr_e( 'Dirección o ventanilla de radicación', 'urkunina-5000' ); ?>">
					</p>
					<p>
						<label class="uhpa-label" for="uhp-voz-retencion"><?php esc_html_e( 'Retención declarada por el proveedor', 'urkunina-5000' ); ?></label>
						<textarea id="uhp-voz-retencion" class="uhpa-input" name="uhp_voz[retencion]" rows="3"><?php echo esc_textarea( $cfg['retencion'] ); ?></textarea>
						<span class="uhpa-nota"><?php esc_html_e( 'Qué dice por escrito sobre cuánto conserva lo que recibe. Se imprime tal cual en el aviso.', 'urkunina-5000' ); ?></span>
					</p>
					<p>
						<label class="uhpa-label" for="uhp-voz-extra"><?php esc_html_e( 'Nota adicional de la entidad (opcional)', 'urkunina-5000' ); ?></label>
						<textarea id="uhp-voz-extra" class="uhpa-input" name="uhp_voz[aviso_extra]" rows="2"><?php echo esc_textarea( $cfg['aviso_extra'] ); ?></textarea>
					</p>
					<p>
						<label class="uhpa-label" for="uhp-voz-version"><?php esc_html_e( 'Versión del aviso', 'urkunina-5000' ); ?></label>
						<input type="number" min="1" id="uhp-voz-version" class="uhpa-input"
							name="uhp_voz[aviso_version]" value="<?php echo esc_attr( (int) $cfg['aviso_version'] ); ?>">
						<span class="uhpa-nota"><?php esc_html_e( 'Súbala cada vez que cambie el texto del aviso: las autorizaciones dadas con la versión anterior dejan de valer y se vuelve a preguntar.', 'urkunina-5000' ); ?></span>
					</p>

					</div>
				</section>
			</div>

			<?php submit_button( __( 'Guardar el asistente', 'urkunina-5000' ) ); ?>
		</form>

		<div class="uhpa-rejilla uhpa-rejilla--2">
			<section class="uhpa-card">
				<header class="uhpa-card__cab">
					<h2><?php esc_html_e( 'Publicación', 'urkunina-5000' ); ?></h2>
					<p><?php esc_html_e( 'El asistente son dos piezas y solo una es obligatoria: un modelo de lenguaje que entiende la pregunta y mueve el tablero, y —si usted quiere— una voz que lee la respuesta en alto.', 'urkunina-5000' ); ?></p>
				</header>
				<div class="uhpa-card__cuerpo">
					<?php if ( $listo ) : ?>
						<p class="uhpa-texto">
							<span class="uhpa-punto uhpa-punto--ok"></span>
							<?php esc_html_e( 'Publicado. El botón aparece en todo [urkunina_dashboard] de este sitio.', 'urkunina-5000' ); ?>
						</p>
						<p class="uhpa-nota">
							<?php
							printf(
								/* translators: 1: preguntas de hoy, 2: tope diario. */
								esc_html__( 'Preguntas de hoy: %1$d de %2$d.', 'urkunina-5000' ),
								(int) UHP_Voz::cuota_usada(),
								(int) $cfg['cuota_dia']
							);
							?>
						</p>
					<?php else : ?>
						<p class="uhpa-texto">
							<span class="uhpa-punto uhpa-punto--error"></span>
							<?php esc_html_e( 'Sin publicar. El tablero sale exactamente como si esta función no existiera.', 'urkunina-5000' ); ?>
						</p>
						<ul class="uhpa-lista">
							<?php foreach ( $falta as $f ) : ?>
								<li><?php echo esc_html( $f ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>

					<?php foreach ( $avisos as $a ) : ?>
						<p class="uhpa-alerta uhpa-alerta--aviso"><?php echo esc_html( $a ); ?></p>
					<?php endforeach; ?>

					<p class="uhpa-nota">
						<?php esc_html_e( 'Ninguna clave viaja al navegador. El visitante pregunta a una ruta de ESTE sitio y es el servidor quien habla con el proveedor, de modo que el tope diario, el límite por IP y la comprobación de origen se aplican de verdad y este panel puede contarlos.', 'urkunina-5000' ); ?>
					</p>

					<p class="uhpa-nota">
						<?php esc_html_e( 'Atributos de página. Pueden ESTRECHAR lo que permite el sitio; ampliarlo no, porque el contenido de un post lo edita cualquiera con permiso de edición y por dónde sale la voz de un ciudadano no es una decisión de redacción.', 'urkunina-5000' ); ?>
					</p>
					<p class="uhpa-nota">
						<?php esc_html_e( 'Apagarlo en esta página:', 'urkunina-5000' ); ?>
						<?php $this->copiable( '[urkunina_dashboard voz="no"]' ); ?>
					</p>
					<p class="uhpa-nota">
						<?php esc_html_e( 'Cargarlo sin botón, para abrirlo desde un control propio de la plantilla:', 'urkunina-5000' ); ?>
						<?php $this->copiable( '[urkunina_dashboard voz_visible="no"]' ); ?>
					</p>
					<p class="uhpa-nota">
						<?php esc_html_e( 'Quitar el micrófono en esta página, aunque el sitio lo ofrezca:', 'urkunina-5000' ); ?>
						<?php $this->copiable( '[urkunina_dashboard voz_entrada="texto"]' ); ?>
					</p>
					<p class="uhpa-nota">
						<strong><?php esc_html_e( 'Las claves no son atributos del shortcode, y no van a serlo.', 'urkunina-5000' ); ?></strong>
						<?php esc_html_e( 'Escritas en el contenido de una página quedarían a la vista de cualquiera con permiso de edición, guardadas en cada revisión del post, y servidas en el HTML a quien abriera la página. Viven aquí, o mejor en wp-config.php.', 'urkunina-5000' ); ?>
					</p>
				</div>
			</section>

			<section class="uhpa-card">
				<header class="uhpa-card__cab">
					<h2><?php esc_html_e( 'Verificación', 'urkunina-5000' ); ?></h2>
					<p><?php esc_html_e( 'Pegar una clave y esperar a que un ciudadano descubra el fallo no es configurar. Esto le pregunta a cada proveedor si lo de aquí abajo sirve.', 'urkunina-5000' ); ?></p>
				</header>
				<div class="uhpa-card__cuerpo">
					<p>
						<a class="button" href="<?php echo esc_url( $this->url_verificar() ); ?>">
							<?php esc_html_e( 'Verificar las claves', 'urkunina-5000' ); ?>
						</a>
					</p>

					<?php if ( ! is_array( $verif ) ) : ?>
						<p class="uhpa-nota"><?php esc_html_e( 'Sin verificar todavía. La comprobación gasta una pregunta mínima: una línea al modelo y un listado de voces. No es una conversación.', 'urkunina-5000' ); ?></p>
					<?php else : ?>
						<?php foreach ( array( 'llm', 'voz' ) as $cual ) : ?>
							<?php
							$bloque = $verif[ $cual ];
							$clase  = null === $bloque['ok'] ? 'aviso' : ( $bloque['ok'] ? 'aviso' : 'error' );
							?>
							<div class="uhpa-alerta uhpa-alerta--<?php echo esc_attr( $clase ); ?>">
								<strong>
									<span class="uhpa-punto uhpa-punto--<?php echo esc_attr( true === $bloque['ok'] ? 'ok' : ( null === $bloque['ok'] ? 'aviso' : 'error' ) ); ?>"></span>
									<?php if ( ! empty( $bloque['codigo'] ) ) : ?>
										<?php // El mismo código que ve el visitante y que queda en el registro de PHP. ?>
										<code class="uhpa-codigo"><?php echo esc_html( $bloque['codigo'] ); ?></code>
									<?php endif; ?>
									<?php echo esc_html( $bloque['mensaje'] ); ?>
								</strong>
								<?php if ( '' !== $bloque['detalle'] ) : ?>
									<p class="uhpa-nota"><?php echo esc_html( $bloque['detalle'] ); ?></p>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
						<p class="uhpa-nota">
							<?php
							printf(
								/* translators: %s: fecha y hora. */
								esc_html__( 'Verificado el %s. El informe se guarda doce horas; vuelva a verificar después de cambiar una clave o la voz.', 'urkunina-5000' ),
								esc_html( wp_date( 'Y-m-d H:i', (int) $verif['momento'] ) )
							);
							?>
						</p>
					<?php endif; ?>

					<hr>
					<p class="uhpa-texto"><strong><?php esc_html_e( 'Antes de encenderlo', 'urkunina-5000' ); ?></strong></p>
					<ul class="uhpa-lista">
						<li><?php esc_html_e( 'Acuerdo de encargo del tratamiento con el proveedor del modelo, y anexo de transferencia internacional (artículo 26 de la Ley 1581 de 2012): lo que el visitante escribe sale del país.', 'urkunina-5000' ); ?></li>
						<li><?php esc_html_e( 'Límite de gasto puesto en la consola del proveedor. El tope de abajo cuenta preguntas en este sitio; no puede frenar un cargo hecho con la misma clave desde otro sitio suyo.', 'urkunina-5000' ); ?></li>
						<li><?php esc_html_e( 'Evaluación propia de accesibilidad: teclado, foco visible, lector de pantalla y móvil (Resolución 1519 de 2020).', 'urkunina-5000' ); ?></li>
						<li><?php esc_html_e( 'Si va a encender el dictado por micrófono, lea antes lo que dice esta misma página sobre a dónde va ese audio: no lo procesa ni este servidor ni el proveedor de la voz.', 'urkunina-5000' ); ?></li>
					</ul>
				</div>
			</section>
		</div>

		<div class="uhpa-rejilla">
			<section class="uhpa-card">
				<header class="uhpa-card__cab">
					<h2><?php esc_html_e( 'Qué sabe hacer', 'urkunina-5000' ); ?></h2>
					<p><?php esc_html_e( 'Estas son las herramientas que el modelo puede usar sobre el tablero. No hay que pegarlas en ninguna parte: el servidor se las manda en cada pregunta, junto con las instrucciones.', 'urkunina-5000' ); ?></p>
				</header>
				<div class="uhpa-card__cuerpo">
					<table class="uhpa-tabla uhpa-tabla--compacta">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Nombre', 'urkunina-5000' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Qué hace', 'urkunina-5000' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( UHP_Voz::definiciones_herramientas() as $h ) : ?>
								<tr>
									<th scope="row"><code><?php echo esc_html( $h['name'] ); ?></code></th>
									<td><?php echo esc_html( $h['description'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p class="uhpa-nota"><?php esc_html_e( 'El JSON completo de cada una está en docs/voz/herramientas.json, dentro del plugin.', 'urkunina-5000' ); ?></p>

					<p class="uhpa-texto"><strong><?php esc_html_e( 'Instrucciones que recibe el modelo', 'urkunina-5000' ); ?></strong></p>
					<p class="uhpa-nota"><?php esc_html_e( 'Se mandan desde el servidor en cada pregunta y no se pueden editar desde una página: son las reglas de honestidad con las que este tablero publica cifras, y no deben depender de quién edita un post.', 'urkunina-5000' ); ?></p>
					<textarea class="uhpa-input" rows="10" readonly onclick="this.select()"><?php echo esc_textarea( UHP_Voz::prompt_sistema() ); ?></textarea>

					<p class="uhpa-texto"><strong><?php esc_html_e( 'Política de seguridad de contenido', 'urkunina-5000' ); ?></strong></p>
					<?php $csp = UHP_Voz::hosts_csp(); ?>
					<?php if ( empty( $csp ) ) : ?>
						<p class="uhpa-nota"><?php esc_html_e( 'Nada que añadir. El navegador no habla con ningún dominio externo: le pregunta a este sitio y el servidor hace el resto. Con default-src \'self\' basta.', 'urkunina-5000' ); ?></p>
					<?php else : ?>
						<p class="uhpa-nota"><?php esc_html_e( 'El navegador no habla con ningún dominio externo. Pero el audio llega dentro de la respuesta y se reproduce desde una URL blob:, que default-src \'self\' NO cubre. Si su servidor aplica una CSP, añada:', 'urkunina-5000' ); ?></p>
						<?php foreach ( $csp as $directiva => $origenes ) : ?>
							<p class="uhpa-nota"><?php $this->copiable( $directiva . ' ' . implode( ' ', $origenes ) ); ?></p>
						<?php endforeach; ?>
						<p class="uhpa-nota"><?php esc_html_e( 'Sin esa línea el panel funciona entero —texto, mapa, gráficas— y simplemente no suena, que es el peor fallo posible: no se nota hasta que alguien pregunta por qué.', 'urkunina-5000' ); ?></p>
					<?php endif; ?>
					<p class="uhpa-nota"><?php esc_html_e( 'Si el sitio envía una cabecera Permissions-Policy, el micrófono tiene que estar permitido para este origen o el dictado no arranca.', 'urkunina-5000' ); ?></p>
				</div>
			</section>
		</div>
		<?php
	}

	/**
	 * Formulario de configuración del objeto 3D.
	 */
	private function form_3d() {
		$cfg = get_option( 'uhp_3d', UHP_Activator::tresd_por_defecto() );
		$cfg = wp_parse_args( is_array( $cfg ) ? $cfg : array(), UHP_Activator::tresd_por_defecto() );
		?>
		<div class="uhpa-rejilla uhpa-rejilla--2">
			<section class="uhpa-card">
				<header class="uhpa-card__cab">
					<h2><?php esc_html_e( 'Valores por defecto del objeto 3D', 'urkunina-5000' ); ?></h2>
					<p><?php esc_html_e( 'Se aplican a [urkunina_3d] cuando el shortcode no indica lo contrario.', 'urkunina-5000' ); ?></p>
				</header>
				<div class="uhpa-card__cuerpo">
					<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
						<?php settings_fields( 'uhp_grupo_3d' ); ?>

						<p>
							<label class="uhpa-label" for="uhp-3d-alto"><?php esc_html_e( 'Altura del contenedor', 'urkunina-5000' ); ?></label>
							<input type="text" id="uhp-3d-alto" class="uhpa-input" name="uhp_3d[alto]"
								value="<?php echo esc_attr( $cfg['alto'] ); ?>">
							<span class="uhpa-nota"><?php esc_html_e( 'Cualquier medida CSS: 100vh, 720px, calc(100vh - 80px)…', 'urkunina-5000' ); ?></span>
						</p>

						<p>
							<label class="uhpa-label" for="uhp-3d-dur"><?php esc_html_e( 'Segundos por momento', 'urkunina-5000' ); ?></label>
							<input type="number" min="4" max="90" id="uhp-3d-dur" class="uhpa-input" name="uhp_3d[duracion]"
								value="<?php echo esc_attr( $cfg['duracion'] ); ?>">
							<span class="uhpa-nota"><?php esc_html_e( 'Tiempo que permanece cada uno de los momentos de la línea de tiempo antes de avanzar solo.', 'urkunina-5000' ); ?></span>
						</p>

						<p>
							<label><input type="checkbox" name="uhp_3d[autoplay]" value="1" <?php checked( 1, (int) $cfg['autoplay'] ); ?>>
								<?php esc_html_e( 'Reproducir el recorrido automáticamente', 'urkunina-5000' ); ?></label><br>
							<label><input type="checkbox" name="uhp_3d[instrumentos]" value="1" <?php checked( 1, (int) $cfg['instrumentos'] ); ?>>
								<?php esc_html_e( 'Mostrar el panel de morfometría', 'urkunina-5000' ); ?></label><br>
							<label><input type="checkbox" name="uhp_3d[cabecera]" value="1" <?php checked( 1, (int) $cfg['cabecera'] ); ?>>
								<?php esc_html_e( 'Mostrar la marca institucional dentro de la escena', 'urkunina-5000' ); ?></label><br>
							<label><input type="checkbox" name="uhp_3d[desplazar]" value="1" <?php checked( 1, (int) $cfg['desplazar'] ); ?>>
								<?php esc_html_e( 'Llevar la página hasta el objeto al cambiar de momento', 'urkunina-5000' ); ?></label>
						</p>
						<p class="uhpa-nota"><?php esc_html_e( 'Lo último solo tiene sentido si el objeto abre la página. Colocado más abajo, y con la reproducción automática puesta, arrastraría el scroll de quien esté leyendo por encima de él.', 'urkunina-5000' ); ?></p>
						<p class="uhpa-nota"><?php esc_html_e( 'Quien tenga activada la reducción de movimiento en su sistema verá el recorrido en pausa aunque la reproducción automática esté activada.', 'urkunina-5000' ); ?></p>

						<?php submit_button( __( 'Guardar el objeto 3D', 'urkunina-5000' ) ); ?>
					</form>
				</div>
			</section>

			<section class="uhpa-card">
				<header class="uhpa-card__cab">
					<h2><?php esc_html_e( 'Cómo se carga Three.js', 'urkunina-5000' ); ?></h2>
					<p><?php esc_html_e( 'La decisión técnica más relevante del componente y la razón por la que convive con otros plugins.', 'urkunina-5000' ); ?></p>
				</header>
				<div class="uhpa-card__cuerpo">
					<p class="uhpa-texto"><?php esc_html_e( 'Un documento HTML solo admite un mapa de importaciones. Otros plugins del sitio ya imprimen el suyo apuntando a una versión distinta de Three.js, de modo que si este plugin imprimiera otro, el segundo se ignoraría y la escena no llegaría a cargar.', 'urkunina-5000' ); ?></p>
					<p class="uhpa-texto"><?php esc_html_e( 'Para evitarlo, el módulo importa Three.js y sus complementos por URL absoluta, usando el paquete que ya trae resueltas sus dependencias internas. El navegador reutiliza una sola instancia de la librería y no hace falta ningún mapa de importaciones.', 'urkunina-5000' ); ?></p>

					<h3 class="uhpa-h3"><?php esc_html_e( 'URLs en uso', 'urkunina-5000' ); ?></h3>
					<table class="uhpa-tabla uhpa-tabla--compacta">
						<tbody>
							<?php foreach ( UHP_Assets::three_urls() as $nombre => $url ) : ?>
								<tr>
									<th scope="row"><code><?php echo esc_html( $nombre ); ?></code></th>
									<td class="uhpa-url"><?php echo esc_html( $url ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p class="uhpa-nota"><?php esc_html_e( 'Si su política de seguridad de contenido no permite CDN externas, use el filtro uhp_url_libreria para servir estas librerías desde el propio dominio.', 'urkunina-5000' ); ?></p>
					<?php $this->copiable( '[urkunina_3d]' ); ?>
				</div>
			</section>
		</div>
		<?php
	}

	/* ================================================================= */
	/* Página: Apariencia                                                */
	/* ================================================================= */

	/**
	 * Identidad visual de los componentes.
	 */
	public function pagina_apariencia() {
		$this->exigir_cap();

		$e   = UHP_Estilos::estilo();
		$def = UHP_Estilos::por_defecto();

		$this->cabecera(
			__( 'Apariencia', 'urkunina-5000' ),
			__( 'Identidad visual de todos los componentes. Los valores por defecto siguen el Manual de Identidad Visual y el Manual de sitios web de la Gobernación de Nariño.', 'urkunina-5000' )
		);
		?>
		<div class="uhpa">
			<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
				<?php settings_fields( 'uhp_grupo_apariencia' ); ?>

				<div class="uhpa-rejilla uhpa-rejilla--2">

					<section class="uhpa-card">
						<header class="uhpa-card__cab">
							<h2><?php esc_html_e( 'Paleta institucional', 'urkunina-5000' ); ?></h2>
							<p><?php esc_html_e( 'Los colores clínicos —positivo y negativo, zonas de riesgo, escala del mapa— no se configuran aquí: codifican significado y su contraste está verificado.', 'urkunina-5000' ); ?></p>
						</header>
						<div class="uhpa-card__cuerpo">
							<?php
							$colores = array(
								'verde'      => __( 'Verde institucional', 'urkunina-5000' ),
								'verde_osc'  => __( 'Verde oscuro', 'urkunina-5000' ),
								'amarillo'   => __( 'Amarillo institucional', 'urkunina-5000' ),
								'azul'       => __( 'Azul de encabezados', 'urkunina-5000' ),
								'azul_claro' => __( 'Azul claro', 'urkunina-5000' ),
								'tinta'      => __( 'Color del texto', 'urkunina-5000' ),
								'suave'      => __( 'Texto secundario', 'urkunina-5000' ),
								'linea'      => __( 'Líneas y bordes', 'urkunina-5000' ),
								'superficie' => __( 'Superficie', 'urkunina-5000' ),
								'fondo'      => __( 'Fondo', 'urkunina-5000' ),
							);
							foreach ( $colores as $clave => $etiqueta ) :
								?>
								<p class="uhpa-color">
									<label class="uhpa-label" for="uhp-c-<?php echo esc_attr( $clave ); ?>">
										<?php echo esc_html( $etiqueta ); ?>
									</label>
									<input type="color" id="uhp-c-<?php echo esc_attr( $clave ); ?>"
										name="uhp_estilo[<?php echo esc_attr( $clave ); ?>]"
										value="<?php echo esc_attr( $e[ $clave ] ); ?>">
									<code><?php echo esc_html( $def[ $clave ] ); ?></code>
								</p>
							<?php endforeach; ?>
						</div>
					</section>

					<div class="uhpa-columna">
						<section class="uhpa-card">
							<header class="uhpa-card__cab">
								<h2><?php esc_html_e( 'Tipografía y forma', 'urkunina-5000' ); ?></h2>
								<p><?php esc_html_e( 'Hind Madurai para el cuerpo y Nunito Sans para etiquetas y cifras, según el manual de la entidad.', 'urkunina-5000' ); ?></p>
							</header>
							<div class="uhpa-card__cuerpo">
								<p>
									<label class="uhpa-label" for="uhp-tipo"><?php esc_html_e( 'Familia principal', 'urkunina-5000' ); ?></label>
									<input type="text" id="uhp-tipo" class="uhpa-input" name="uhp_estilo[tipografia]"
										value="<?php echo esc_attr( $e['tipografia'] ); ?>">
								</p>
								<p>
									<label class="uhpa-label" for="uhp-tipo2"><?php esc_html_e( 'Familia de etiquetas y cifras', 'urkunina-5000' ); ?></label>
									<input type="text" id="uhp-tipo2" class="uhpa-input" name="uhp_estilo[etiquetas]"
										value="<?php echo esc_attr( $e['etiquetas'] ); ?>">
								</p>
								<p>
									<label class="uhpa-label" for="uhp-radio"><?php esc_html_e( 'Radio de las esquinas', 'urkunina-5000' ); ?></label>
									<input type="text" id="uhp-radio" class="uhpa-input" name="uhp_estilo[radio]"
										value="<?php echo esc_attr( $e['radio'] ); ?>">
								</p>
								<p>
									<label class="uhpa-label" for="uhp-ancho"><?php esc_html_e( 'Ancho máximo de los componentes', 'urkunina-5000' ); ?></label>
									<input type="text" id="uhp-ancho" class="uhpa-input" name="uhp_estilo[ancho_max]"
										value="<?php echo esc_attr( $e['ancho_max'] ); ?>">
								</p>
								<p>
									<label>
										<input type="checkbox" name="uhp_estilo[fuentes_cdn]" value="1" <?php checked( 1, (int) $e['fuentes_cdn'] ); ?>>
										<?php esc_html_e( 'Cargar las tipografías desde Google Fonts', 'urkunina-5000' ); ?>
									</label>
									<span class="uhpa-nota"><?php esc_html_e( 'Desactívelo si el tema ya las sirve o si la política de privacidad de la entidad exige autoalojarlas.', 'urkunina-5000' ); ?></span>
								</p>
							</div>
						</section>

						<section class="uhpa-card">
							<header class="uhpa-card__cab">
								<h2><?php esc_html_e( 'Vista previa', 'urkunina-5000' ); ?></h2>
								<p><?php esc_html_e( 'Los colores guardados, aplicados a los elementos más frecuentes.', 'urkunina-5000' ); ?></p>
							</header>
							<div class="uhpa-card__cuerpo">
								<div class="uhpa-preview" style="<?php echo esc_attr( $this->preview_vars( $e ) ); ?>">
									<div class="uhpa-preview__kpi">
										<b>67,4 %</b>
										<span><?php esc_html_e( 'Infección por H. pylori', 'urkunina-5000' ); ?></span>
									</div>
									<div class="uhpa-preview__barras">
										<i style="height:82%"></i><i style="height:64%"></i><i style="height:48%"></i><i style="height:31%"></i>
									</div>
									<p class="uhpa-preview__txt"><?php esc_html_e( 'Texto de análisis con la tipografía y el color de tinta configurados.', 'urkunina-5000' ); ?></p>
								</div>
							</div>
						</section>
					</div>
				</div>

				<?php submit_button( __( 'Guardar la apariencia', 'urkunina-5000' ) ); ?>
			</form>
		</div>
		<?php
		$this->pie();
	}

	/**
	 * Variables CSS de la vista previa de apariencia.
	 *
	 * @param array $e Configuración de estilo.
	 * @return string
	 */
	private function preview_vars( $e ) {
		$mapa = array(
			'--uhp-verde'      => 'verde',
			'--uhp-amarillo'   => 'amarillo',
			'--uhp-azul'       => 'azul',
			'--uhp-tinta'      => 'tinta',
			'--uhp-suave'      => 'suave',
			'--uhp-linea'      => 'linea',
			'--uhp-superficie' => 'superficie',
			'--uhp-fondo'      => 'fondo',
			'--uhp-radio'      => 'radio',
		);
		$out = '';
		foreach ( $mapa as $var => $clave ) {
			$out .= $var . ':' . UHP_Estilos::sanitizar_css( $e[ $clave ] ) . ';';
		}
		return $out;
	}

	/* ================================================================= */
	/* Página: Diagnóstico                                               */
	/* ================================================================= */

	/**
	 * Entorno, convivencia con otros plugins y seguridad.
	 */
	public function pagina_diagnostico() {
		$this->exigir_cap();

		$tab = isset( $_GET['tab'] ) ? UHP_Security::clave( wp_unslash( $_GET['tab'] ) ) : 'entorno'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Solo selecciona qué pestaña pintar.
		if ( ! in_array( $tab, array( 'entorno', 'conflictos', 'seguridad' ), true ) ) {
			$tab = 'entorno';
		}

		$this->cabecera(
			__( 'Diagnóstico', 'urkunina-5000' ),
			__( 'Comprobaciones del entorno, de la convivencia con otros plugins del sitio y de las medidas de seguridad activas.', 'urkunina-5000' )
		);

		$this->pestanas(
			'uhp-diagnostico',
			array(
				'entorno'    => __( 'Entorno', 'urkunina-5000' ),
				'conflictos' => __( 'Convivencia', 'urkunina-5000' ),
				'seguridad'  => __( 'Seguridad', 'urkunina-5000' ),
			),
			$tab
		);
		?>
		<div class="uhpa">
			<?php
			switch ( $tab ) {
				case 'conflictos':
					$this->diag_conflictos();
					break;
				case 'seguridad':
					$this->diag_seguridad();
					break;
				default:
					$this->diag_entorno();
			}
			?>
		</div>
		<?php
		$this->pie();
	}

	/**
	 * Pestaña de entorno.
	 */
	private function diag_entorno() {
		$comprobaciones = array(
			array(
				'nombre' => __( 'Versión de PHP', 'urkunina-5000' ),
				'valor'  => PHP_VERSION,
				'ok'     => version_compare( PHP_VERSION, '7.4', '>=' ),
				'nota'   => __( 'El plugin requiere PHP 7.4 o superior.', 'urkunina-5000' ),
			),
			array(
				'nombre' => __( 'Versión de WordPress', 'urkunina-5000' ),
				'valor'  => get_bloginfo( 'version' ),
				'ok'     => version_compare( get_bloginfo( 'version' ), '5.8', '>=' ),
				'nota'   => __( 'El plugin requiere WordPress 5.8 o superior.', 'urkunina-5000' ),
			),
			array(
				'nombre' => __( 'Extensión JSON', 'urkunina-5000' ),
				'valor'  => extension_loaded( 'json' ) ? __( 'Disponible', 'urkunina-5000' ) : __( 'Ausente', 'urkunina-5000' ),
				'ok'     => extension_loaded( 'json' ),
				'nota'   => __( 'Necesaria para leer y validar los archivos de datos.', 'urkunina-5000' ),
			),
			array(
				'nombre' => __( 'Extensión mbstring', 'urkunina-5000' ),
				'valor'  => extension_loaded( 'mbstring' ) ? __( 'Disponible', 'urkunina-5000' ) : __( 'Ausente', 'urkunina-5000' ),
				'ok'     => extension_loaded( 'mbstring' ),
				'nota'   => __( 'Necesaria para validar la codificación UTF-8 y tratar los nombres con tilde.', 'urkunina-5000' ),
			),
			array(
				'nombre' => __( 'Escritura en /data', 'urkunina-5000' ),
				'valor'  => is_writable( UHP_Datos::dir() ) ? __( 'Permitida', 'urkunina-5000' ) : __( 'Denegada', 'urkunina-5000' ),
				'ok'     => is_writable( UHP_Datos::dir() ),
				'nota'   => __( 'Sin ella, el módulo de datos funciona en modo de solo consulta.', 'urkunina-5000' ),
			),
			array(
				'nombre' => __( 'Directorio de copias', 'urkunina-5000' ),
				'valor'  => is_dir( UHP_Datos::dir_respaldos() ) ? __( 'Creado', 'urkunina-5000' ) : __( 'Sin crear', 'urkunina-5000' ),
				'ok'     => is_dir( UHP_Datos::dir_respaldos() ),
				'nota'   => __( 'Se crea al activar el plugin y queda protegido de accesos por HTTP.', 'urkunina-5000' ),
			),
			array(
				'nombre' => __( 'API REST del plugin', 'urkunina-5000' ),
				'valor'  => '/wp-json/' . UHP_Rest::NS,
				'ok'     => true,
				'nota'   => __( 'Ocho rutas públicas de solo lectura, todas con límite de peticiones por IP.', 'urkunina-5000' ),
			),
		);
		?>
		<section class="uhpa-card">
			<header class="uhpa-card__cab">
				<h2><?php esc_html_e( 'Requisitos del servidor', 'urkunina-5000' ); ?></h2>
				<p><?php esc_html_e( 'Lo que el plugin necesita del entorno para funcionar por completo.', 'urkunina-5000' ); ?></p>
			</header>
			<div class="uhpa-card__cuerpo uhpa-card__cuerpo--plano">
				<table class="uhpa-tabla uhpa-tabla--rayada">
					<tbody>
						<?php foreach ( $comprobaciones as $c ) : ?>
							<tr>
								<th scope="row"><?php echo esc_html( $c['nombre'] ); ?></th>
								<td>
									<span class="uhpa-punto uhpa-punto--<?php echo esc_attr( $c['ok'] ? 'ok' : 'error' ); ?>"></span>
									<?php echo esc_html( $c['valor'] ); ?>
								</td>
								<td class="uhpa-nota"><?php echo esc_html( $c['nota'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</section>
		<?php
		$this->diag_vistas_vacias();
	}

	/**
	 * Vistas que no producen ni una fila.
	 *
	 * Una vista vacía casi siempre significa lo mismo: los archivos de
	 * `data/` instalados se han quedado atrás respecto del código. Pasa
	 * cuando un despliegue copia `includes/` y se salta `data/`, y no se
	 * nota hasta que alguien abre la página y ve un mapa entero en gris con
	 * una leyenda de 0 a 0. Aquí se ve de un vistazo y con el nombre del
	 * archivo que hay que actualizar.
	 */
	private function diag_vistas_vacias() {
		$catalogo = UHP_Views::lista();
		$vacias   = array();
		foreach ( $catalogo as $meta ) {
			$id    = isset( $meta['id'] ) ? $meta['id'] : '';
			$vista = '' !== $id ? UHP_Views::obtener( $id ) : null;
			if ( ! $vista || ! $vista['data'] ) {
				$vacias[ $id ] = isset( $meta['name'] ) ? $meta['name'] : $id;
			}
		}
		?>
		<section class="uhpa-card">
			<header class="uhpa-card__cab">
				<h2><?php esc_html_e( 'Vistas con datos', 'urkunina-5000' ); ?></h2>
				<p><?php esc_html_e( 'Cada vista del catálogo se construye a partir de los archivos de data/. Una vista sin filas suele indicar que esos archivos no están al día con esta versión del plugin.', 'urkunina-5000' ); ?></p>
			</header>
			<div class="uhpa-card__cuerpo">
				<?php if ( ! $vacias ) : ?>
					<p class="uhpa-texto">
						<span class="uhpa-punto uhpa-punto--ok"></span>
						<?php
						printf(
							/* translators: %d: número de vistas del catálogo. */
							esc_html__( 'Las %d vistas del catálogo producen datos.', 'urkunina-5000' ),
							count( $catalogo )
						);
						?>
					</p>
				<?php else : ?>
					<p class="uhpa-texto">
						<span class="uhpa-punto uhpa-punto--error"></span>
						<?php
						printf(
							/* translators: %d: número de vistas sin datos. */
							esc_html__( '%d vista(s) no producen ninguna fila. Actualice los archivos de data/ a los de esta versión del plugin.', 'urkunina-5000' ),
							count( $vacias )
						);
						?>
					</p>
					<ul class="uhpa-lista">
						<?php foreach ( $vacias as $id => $nombre ) : ?>
							<li><code><?php echo esc_html( $id ); ?></code> — <?php echo esc_html( $nombre ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Pestaña de convivencia con otros plugins.
	 */
	private function diag_conflictos() {
		$librerias = array(
			'd3'      => 'D3.js',
			'd3plus'  => 'D3plus.js',
			'leaflet' => 'Leaflet',
			'plotly'  => 'Plotly.js',
		);
		?>
		<section class="uhpa-card">
			<header class="uhpa-card__cab">
				<h2><?php esc_html_e( 'Librerías compartidas', 'urkunina-5000' ); ?></h2>
				<p><?php esc_html_e( 'En este sitio conviven otros plugins de la entidad que usan las mismas librerías. Dos copias de la misma librería en una página rompen desde las leyendas de los gráficos hasta la inicialización de los mapas.', 'urkunina-5000' ); ?></p>
			</header>
			<div class="uhpa-card__cuerpo">
				<p class="uhpa-texto"><?php esc_html_e( 'Para evitarlo, este plugin registra cada librería con su identificador de uso común y comprueba antes si otro plugin ya la registró. Si es así, respeta su registro y no vuelve a cargarla: WordPress garantiza entonces una sola copia en la página.', 'urkunina-5000' ); ?></p>

				<table class="uhpa-tabla uhpa-tabla--compacta">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Librería', 'urkunina-5000' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Identificador', 'urkunina-5000' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Estrategia', 'urkunina-5000' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $librerias as $clave => $nombre ) : ?>
							<tr>
								<th scope="row"><?php echo esc_html( $nombre ); ?></th>
								<td><code><?php echo esc_html( UHP_Assets::handle( $clave ) ); ?></code></td>
								<td class="uhpa-nota"><?php esc_html_e( 'Se registra solo si nadie la registró antes; en caso contrario se reutiliza la del otro plugin.', 'urkunina-5000' ); ?></td>
							</tr>
						<?php endforeach; ?>
						<tr>
							<th scope="row">Three.js</th>
							<td><code><?php echo esc_html( 'sin identificador' ); ?></code></td>
							<td class="uhpa-nota"><?php esc_html_e( 'No se registra como script de WordPress ni se declara ningún mapa de importaciones: el módulo 3D la importa por URL absoluta. Es la única forma de convivir con un plugin que ya declare el suyo.', 'urkunina-5000' ); ?></td>
						</tr>
					</tbody>
				</table>
			</div>
		</section>

		<div class="uhpa-rejilla uhpa-rejilla--2">
			<section class="uhpa-card">
				<header class="uhpa-card__cab">
					<h2><?php esc_html_e( 'Aislamiento del marcado', 'urkunina-5000' ); ?></h2>
					<p><?php esc_html_e( 'Qué impide que este plugin altere el tema o los componentes de otro plugin.', 'urkunina-5000' ); ?></p>
				</header>
				<div class="uhpa-card__cuerpo">
					<ul class="uhpa-lista uhpa-lista--check">
						<li><?php esc_html_e( 'Todas las reglas CSS cuelgan de una clase propia del plugin: no hay ni una sola regla sobre etiquetas sueltas ni sobre identificadores globales.', 'urkunina-5000' ); ?></li>
						<li><?php esc_html_e( 'Las reglas que afectan a clases de Leaflet van anidadas dentro de un contenedor del plugin, de modo que no alcanzan a los mapas de otro plugin en la misma página.', 'urkunina-5000' ); ?></li>
						<li><?php esc_html_e( 'El objeto 3D no usa identificadores: sus elementos se localizan por atributos de datos dentro de su contenedor, y varias instancias pueden convivir en una página.', 'urkunina-5000' ); ?></li>
						<li><?php esc_html_e( 'El teclado se escucha en el contenedor del objeto 3D y no en la ventana, para no apropiarse de las flechas ni de la barra espaciadora del resto de la página.', 'urkunina-5000' ); ?></li>
						<li><?php esc_html_e( 'En JavaScript el plugin solo publica cuatro objetos globales, todos con prefijo propio.', 'urkunina-5000' ); ?></li>
						<li><?php esc_html_e( 'Las opciones, los transitorios y los shortcodes llevan prefijo propio y no coinciden con los de ningún otro plugin de la entidad.', 'urkunina-5000' ); ?></li>
					</ul>
				</div>
			</section>

			<section class="uhpa-card">
				<header class="uhpa-card__cab">
					<h2><?php esc_html_e( 'Rendimiento', 'urkunina-5000' ); ?></h2>
					<p><?php esc_html_e( 'Decisiones que evitan que el plugin pese en páginas donde no se usa.', 'urkunina-5000' ); ?></p>
				</header>
				<div class="uhpa-card__cuerpo">
					<ul class="uhpa-lista uhpa-lista--check">
						<li><?php esc_html_e( 'Las librerías se registran pero no se encolan: solo llegan al navegador en las páginas que contienen un shortcode que las necesita.', 'urkunina-5000' ); ?></li>
						<li><?php esc_html_e( 'Los archivos de datos se cachean doce horas en transitorios y también en memoria durante cada petición.', 'urkunina-5000' ); ?></li>
						<li><?php esc_html_e( 'La geometría municipal se sirve adelgazada: de las noventa propiedades censales del origen solo viajan las cuatro que el mapa usa.', 'urkunina-5000' ); ?></li>
						<li><?php esc_html_e( 'La escena 3D deja de dibujar cuando sale de la vista y libera la GPU si su contenedor desaparece del documento.', 'urkunina-5000' ); ?></li>
						<li><?php esc_html_e( 'Las respuestas de la API llevan cabeceras de caché de quince minutos con revalidación en segundo plano.', 'urkunina-5000' ); ?></li>
					</ul>
				</div>
			</section>
		</div>
		<?php
	}

	/**
	 * Pestaña de seguridad.
	 */
	private function diag_seguridad() {
		?>
		<div class="uhpa-rejilla uhpa-rejilla--2">
			<section class="uhpa-card">
				<header class="uhpa-card__cab">
					<h2><?php esc_html_e( 'Superficie de escritura', 'urkunina-5000' ); ?></h2>
					<p><?php esc_html_e( 'El plugin escribe en un solo sitio: los archivos JSON de su propio directorio de datos.', 'urkunina-5000' ); ?></p>
				</header>
				<div class="uhpa-card__cuerpo">
					<ul class="uhpa-lista uhpa-lista--check">
						<li><?php esc_html_e( 'No crea tablas en la base de datos ni almacena información personal.', 'urkunina-5000' ); ?></li>
						<li><?php esc_html_e( 'Toda acción de escritura exige la capacidad manage_options y un nonce válido.', 'urkunina-5000' ); ?></li>
						<li><?php esc_html_e( 'El archivo a escribir se elige de una lista blanca; la ruta nunca se compone con datos del navegador.', 'urkunina-5000' ); ?></li>
						<li><?php esc_html_e( 'Los archivos subidos se comprueban con el mecanismo de subida de PHP antes de leerse, y su tamaño está acotado.', 'urkunina-5000' ); ?></li>
						<li><?php esc_html_e( 'La escritura es atómica y va precedida de una copia de seguridad automática.', 'urkunina-5000' ); ?></li>
						<li><?php esc_html_e( 'Tras cada escritura se redirige, de modo que recargar la página no repite la operación.', 'urkunina-5000' ); ?></li>
					</ul>
				</div>
			</section>

			<section class="uhpa-card">
				<header class="uhpa-card__cab">
					<h2><?php esc_html_e( 'Entrada y salida', 'urkunina-5000' ); ?></h2>
					<p><?php esc_html_e( 'Toda entrada es no confiable y toda salida se escapa.', 'urkunina-5000' ); ?></p>
				</header>
				<div class="uhpa-card__cuerpo">
					<ul class="uhpa-lista uhpa-lista--check">
						<li><?php esc_html_e( 'Los atributos de los shortcodes se sanean uno a uno y se validan contra listas blancas.', 'urkunina-5000' ); ?></li>
						<li><?php esc_html_e( 'Los valores de color y medida pasan por un saneador de CSS que neutraliza url(), expression() y los caracteres que permitirían inyectar reglas.', 'urkunina-5000' ); ?></li>
						<li><?php esc_html_e( 'Las coordenadas del mapa se validan contra el recuadro geográfico de Nariño.', 'urkunina-5000' ); ?></li>
						<li><?php esc_html_e( 'En el navegador, el contenido que llega de la API se inserta como texto y no como marcado.', 'urkunina-5000' ); ?></li>
						<li><?php esc_html_e( 'Las rutas públicas de la API tienen límite de peticiones por dirección IP.', 'urkunina-5000' ); ?></li>
						<li><?php esc_html_e( 'La API no acepta escrituras: todas sus rutas son de solo lectura.', 'urkunina-5000' ); ?></li>
					</ul>
				</div>
			</section>
		</div>

		<section class="uhpa-card">
			<header class="uhpa-card__cab">
				<h2><?php esc_html_e( 'Privacidad y datos abiertos', 'urkunina-5000' ); ?></h2>
				<p><?php esc_html_e( 'Qué publica el plugin y qué no.', 'urkunina-5000' ); ?></p>
			</header>
			<div class="uhpa-card__cuerpo">
				<p class="uhpa-texto"><?php esc_html_e( 'Todos los datos que sirve el plugin son agregados por municipio, subregión o categoría. No contiene microdatos de los cinco mil participantes, resultados de laboratorio individuales ni la identidad de los pacientes con cáncer detectado: esa información no está en los documentos fuente y no se ha incorporado.', 'urkunina-5000' ); ?></p>
				<p class="uhpa-texto"><?php esc_html_e( 'El conjunto completo se expone además como datos abiertos en la API del plugin, para que cualquier persona pueda reutilizarlo citando la fuente.', 'urkunina-5000' ); ?></p>
				<?php $this->copiable( rest_url( UHP_Rest::NS . '/abierto' ) ); ?>

				<h3 class="uhpa-h3"><?php esc_html_e( 'Atribución obligatoria', 'urkunina-5000' ); ?></h3>
				<ul class="uhpa-lista">
					<li><?php esc_html_e( 'Informe preliminar y presentación final del proyecto URKUNINA 5000 — Fundación CIEDYN y Hospital Universitario Departamental de Nariño.', 'urkunina-5000' ); ?></li>
					<li><?php esc_html_e( 'Instituto Nacional de Cancerología — cifras nacionales de incidencia y mortalidad.', 'urkunina-5000' ); ?></li>
					<li><?php esc_html_e( 'DANE — marco geoestadístico municipal.', 'urkunina-5000' ); ?></li>
					<li><?php esc_html_e( 'OpenStreetMap — cartografía base, © colaboradores de OSM, licencia ODbL.', 'urkunina-5000' ); ?></li>
				</ul>
			</div>
		</section>
		<?php
	}

	/* ================================================================= */
	/* Piezas reutilizables de interfaz                                  */
	/* ================================================================= */

	/**
	 * Corta la ejecución si el usuario no tiene la capacidad requerida.
	 */
	private function exigir_cap() {
		if ( ! current_user_can( UHP_Security::CAP ) ) {
			wp_die(
				esc_html__( 'No tiene permisos para acceder a esta página.', 'urkunina-5000' ),
				esc_html__( 'Permiso denegado', 'urkunina-5000' ),
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Cabecera común de todas las páginas.
	 *
	 * @param string $titulo    Título de la página.
	 * @param string $subtitulo Descripción breve.
	 */
	private function cabecera( $titulo, $subtitulo ) {
		?>
		<div class="wrap uhpa-wrap">
			<header class="uhpa-cabecera">
				<div class="uhpa-cabecera__marca" aria-hidden="true"></div>
				<div>
					<h1 class="uhpa-cabecera__t"><?php echo esc_html( $titulo ); ?></h1>
					<p class="uhpa-cabecera__s"><?php echo esc_html( $subtitulo ); ?></p>
				</div>
				<div class="uhpa-cabecera__meta">
					<span><?php echo esc_html( 'v' . UHP_VERSION ); ?></span>
					<span><?php esc_html_e( 'BPIN 2015000100064', 'urkunina-5000' ); ?></span>
				</div>
			</header>
		<?php
	}

	/**
	 * Cierre común de todas las páginas.
	 */
	private function pie() {
		?>
			<footer class="uhpa-pie">
				<p>
					<?php esc_html_e( 'URKUNINA 5000 — Gobernación de Nariño · Secretaría TIC, Innovación y Gobierno Abierto.', 'urkunina-5000' ); ?>
					<?php esc_html_e( 'Datos del proyecto de investigación de prevalencia de lesiones precursoras de malignidad y erradicación de Helicobacter pylori en el departamento de Nariño.', 'urkunina-5000' ); ?>
				</p>
			</footer>
		</div>
		<?php
	}

	/**
	 * Barra de pestañas de una página.
	 *
	 * @param string $pagina   Slug de la página.
	 * @param array  $pestanas slug => etiqueta.
	 * @param string $activa   Slug de la pestaña activa.
	 */
	private function pestanas( $pagina, $pestanas, $activa ) {
		?>
		<nav class="uhpa-tabs" aria-label="<?php esc_attr_e( 'Secciones del módulo', 'urkunina-5000' ); ?>">
			<?php foreach ( $pestanas as $slug => $etiqueta ) : ?>
				<a class="uhpa-tab<?php echo $slug === $activa ? ' is-activa' : ''; ?>"
					href="<?php echo esc_url( admin_url( 'admin.php?page=' . $pagina . '&tab=' . $slug ) ); ?>"
					<?php echo $slug === $activa ? 'aria-current="page"' : ''; ?>>
					<?php echo esc_html( $etiqueta ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Pinta el aviso pendiente del módulo de datos, si lo hay.
	 */
	private function aviso() {
		$aviso = UHP_Admin_Datos::aviso_pendiente();
		if ( ! $aviso ) {
			return;
		}
		$clase = array(
			'ok'    => 'notice-success',
			'aviso' => 'notice-warning',
			'error' => 'notice-error',
		);
		?>
		<div class="notice <?php echo esc_attr( isset( $clase[ $aviso['tipo'] ] ) ? $clase[ $aviso['tipo'] ] : 'notice-info' ); ?> is-dismissible">
			<p><?php echo esc_html( $aviso['mensaje'] ); ?></p>
		</div>
		<?php
	}

	/**
	 * Tarjeta con una métrica destacada.
	 *
	 * @param string $etiqueta Nombre de la métrica.
	 * @param mixed  $valor    Valor mostrado.
	 * @param string $nota     Aclaración.
	 * @param string $estado   ok | aviso | error.
	 */
	private function tarjeta_metrica( $etiqueta, $valor, $nota, $estado ) {
		?>
		<div class="uhpa-metrica uhpa-metrica--<?php echo esc_attr( $estado ); ?>">
			<span class="uhpa-metrica__valor"><?php echo esc_html( $valor ); ?></span>
			<span class="uhpa-metrica__etq"><?php echo esc_html( $etiqueta ); ?></span>
			<span class="uhpa-metrica__nota"><?php echo esc_html( $nota ); ?></span>
		</div>
		<?php
	}

	/**
	 * Tarjeta con un shortcode de ejemplo.
	 *
	 * @param string $titulo      Nombre del componente.
	 * @param string $shortcode   Shortcode copiable.
	 * @param string $descripcion Qué hace.
	 */
	private function tarjeta_shortcode( $titulo, $shortcode, $descripcion ) {
		?>
		<div class="uhpa-mini">
			<strong><?php echo esc_html( $titulo ); ?></strong>
			<p><?php echo esc_html( $descripcion ); ?></p>
			<?php $this->copiable( $shortcode ); ?>
		</div>
		<?php
	}

	/**
	 * Caja de texto copiable con un botón.
	 *
	 * @param string $texto Contenido a copiar.
	 */
	private function copiable( $texto ) {
		?>
		<span class="uhpa-copiable">
			<code><?php echo esc_html( $texto ); ?></code>
			<button type="button" class="uhpa-copiar" data-uhp-copiar="<?php echo esc_attr( $texto ); ?>"
				aria-label="<?php esc_attr_e( 'Copiar al portapapeles', 'urkunina-5000' ); ?>">
				<?php esc_html_e( 'Copiar', 'urkunina-5000' ); ?>
			</button>
		</span>
		<?php
	}

	/**
	 * Cuenta los archivos por estado.
	 *
	 * @param array $estado Estado de los archivos.
	 * @return array<string,int>
	 */
	private function contar_estados( $estado ) {
		$c = array(
			'ok'         => 0,
			'aviso'      => 0,
			'error'      => 0,
			'falta'      => 0,
			'modificado' => 0,
		);
		foreach ( $estado as $f ) {
			$clave = isset( $c[ $f['estado'] ] ) ? $f['estado'] : 'aviso';
			$c[ $clave ]++;
		}
		// Un archivo que falta es un error a efectos de recuento.
		$c['error'] += $c['falta'];
		return $c;
	}

	/**
	 * Nombre legible de un estado de archivo.
	 *
	 * @param string $estado Clave del estado.
	 * @return string
	 */
	private function nombre_estado( $estado ) {
		switch ( $estado ) {
			case 'ok':
				return __( 'Correcto', 'urkunina-5000' );
			case 'aviso':
				return __( 'Con avisos', 'urkunina-5000' );
			case 'modificado':
				return __( 'Modificado fuera del panel', 'urkunina-5000' );
			case 'falta':
				return __( 'No encontrado', 'urkunina-5000' );
			default:
				return __( 'Con errores', 'urkunina-5000' );
		}
	}
}
