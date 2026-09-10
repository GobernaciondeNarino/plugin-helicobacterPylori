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
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
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

		$indicadores = array_keys( UHP_Rest::indicadores_mapa() );
		$indicador   = isset( $entrada['indicador'] ) ? UHP_Security::clave( $entrada['indicador'] ) : $def['indicador'];
		$teselas     = isset( $entrada['teselas'] ) ? UHP_Security::clave( $entrada['teselas'] ) : $def['teselas'];

		$lat = isset( $entrada['mapa_lat'] ) ? (float) $entrada['mapa_lat'] : $def['mapa_lat'];
		$lon = isset( $entrada['mapa_lon'] ) ? (float) $entrada['mapa_lon'] : $def['mapa_lon'];
		if ( ! UHP_Security::validar_bbox( $lat, $lon ) ) {
			$lat = $def['mapa_lat'];
			$lon = $def['mapa_lon'];
		}

		return array(
			'titulo'      => sanitize_text_field( isset( $entrada['titulo'] ) ? $entrada['titulo'] : $def['titulo'] ),
			'indicador'   => in_array( $indicador, $indicadores, true ) ? $indicador : $def['indicador'],
			'teselas'     => in_array( $teselas, array( 'oscuro', 'osm', 'claro', 'humanitario' ), true ) ? $teselas : $def['teselas'],
			'mapa_lat'    => $lat,
			'mapa_lon'    => $lon,
			'mapa_zoom'   => min( 14, max( 5, isset( $entrada['mapa_zoom'] ) ? (int) $entrada['mapa_zoom'] : $def['mapa_zoom'] ) ),
			'panel_izq'   => empty( $entrada['panel_izq'] ) ? 0 : 1,
			'panel_der'   => empty( $entrada['panel_der'] ) ? 0 : 1,
			'mostrar_kpi' => empty( $entrada['mostrar_kpi'] ) ? 0 : 1,
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
		);
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
				'titulo'      => __( 'Tablero completo', 'urkunina-5000' ),
				'descripcion' => __( 'Todo el proyecto en una sola pantalla: cintillo de cifras, panel de controles y filtros, mapa de OpenStreetMap al centro y panel de gráficos con su análisis. Ocupa el 100 % del ancho y toda la altura de la ventana.', 'urkunina-5000' ),
				'ejemplos'    => array(
					'[urkunina_dashboard]',
					'[urkunina_dashboard indicador="hpylori" teselas="claro" zoom="9"]',
					'[urkunina_dashboard alto="calc(100vh - 80px)"]',
				),
				'atributos'   => array(
					'titulo'    => __( 'Título mostrado en la cabecera del tablero.', 'urkunina-5000' ),
					'alto'      => __( 'Altura del contenedor. Por defecto 100vh; use calc() si su tema tiene una barra fija.', 'urkunina-5000' ),
					'indicador' => __( 'Indicador inicial del mapa: lpm, hpylori, cancer o intervencion.', 'urkunina-5000' ),
					'teselas'   => __( 'Capa base: osm, claro o humanitario.', 'urkunina-5000' ),
					'lat, lon'  => __( 'Centro inicial del mapa. Debe caer dentro de Nariño.', 'urkunina-5000' ),
					'zoom'      => __( 'Nivel de acercamiento inicial, entre 5 y 14.', 'urkunina-5000' ),
				),
				'nota'        => __( 'Publíquelo en una página con plantilla de ancho completo y sin barra lateral para que el contenedor aproveche la pantalla.', 'urkunina-5000' ),
			),
			array(
				'tag'         => 'urkunina_3d',
				'titulo'      => __( 'Recreación 3D de Helicobacter pylori', 'urkunina-5000' ),
				'descripcion' => __( 'Modelo tridimensional científicamente parametrizado de la bacteria, con una línea de tiempo de siete momentos que recorre el contagio, la colonización, el daño y el tratamiento.', 'urkunina-5000' ),
				'ejemplos'    => array(
					'[urkunina_3d]',
					'[urkunina_3d alto="720px" autoplay="no"]',
					'[urkunina_3d cabecera="no" instrumentos="no" duracion="20"]',
				),
				'atributos'   => array(
					'alto'         => __( 'Altura del contenedor. Por defecto 100vh.', 'urkunina-5000' ),
					'autoplay'     => __( 'si o no. Con no, el recorrido arranca en pausa.', 'urkunina-5000' ),
					'duracion'     => __( 'Segundos que dura cada momento de la línea de tiempo.', 'urkunina-5000' ),
					'instrumentos' => __( 'si o no. Muestra u oculta el panel de morfometría.', 'urkunina-5000' ),
					'cabecera'     => __( 'si o no. Muestra u oculta la marca institucional dentro de la escena.', 'urkunina-5000' ),
				),
				'nota'        => __( 'Requiere un navegador con WebGL 2. Si no está disponible, el componente muestra un mensaje explicativo en lugar de quedarse en blanco.', 'urkunina-5000' ),
			),
			array(
				'tag'         => 'urkunina_grafico',
				'titulo'      => __( 'Gráfico de una vista', 'urkunina-5000' ),
				'descripcion' => __( 'Dibuja cualquier vista del catálogo con D3plus, acompañada de su barra de herramientas —detalle, tabla de datos, compartir, exportar a PNG, descargar JSON y cambiar de tipo en vivo— y de su texto de análisis.', 'urkunina-5000' ),
				'ejemplos'    => array(
					'[urkunina_grafico view="tamizaje_hp" type="donut"]',
					'[urkunina_grafico view="prev_lpm_municipios" type="bar" alto="480px"]',
					'[urkunina_grafico view="metas_mga" barra="no" titulo="no"]',
				),
				'atributos'   => array(
					'view'     => __( 'Identificador de la vista. Consulte el módulo Gráficos.', 'urkunina-5000' ),
					'type'     => __( 'Tipo de gráfico. Si se omite o no es compatible, se usa el tipo por defecto de la vista.', 'urkunina-5000' ),
					'titulo'   => __( 'Sustituye el título de la vista; con «no» se oculta.', 'urkunina-5000' ),
					'alto'     => __( 'Altura del lienzo del gráfico.', 'urkunina-5000' ),
					'tema'     => __( 'claro u oscuro.', 'urkunina-5000' ),
					'acciones' => __( 'Lista separada por comas de los botones a mostrar.', 'urkunina-5000' ),
					'barra'    => __( 'si o no. Oculta toda la barra de herramientas.', 'urkunina-5000' ),
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
					'[urkunina_geomapa indicador="intervencion" teselas="si"]',
					'[urkunina_geomapa view="cancer_municipios" tema="oscuro" leyenda="no"]',
				),
				'atributos'   => array(
					'view'      => __( 'Vista territorial del catálogo. El nivel —municipios o subregiones— lo decide la propia vista.', 'urkunina-5000' ),
					'indicador' => __( 'Alternativa a «view»: lpm, hpylori, cancer o intervencion. Siempre municipal.', 'urkunina-5000' ),
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
		$cfg         = get_option( 'uhp_dashboard', UHP_Activator::dashboard_por_defecto() );
		$cfg         = wp_parse_args( is_array( $cfg ) ? $cfg : array(), UHP_Activator::dashboard_por_defecto() );
		$indicadores = UHP_Rest::indicadores_mapa();
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
							<label class="uhpa-label" for="uhp-db-ind"><?php esc_html_e( 'Indicador inicial del mapa', 'urkunina-5000' ); ?></label>
							<select id="uhp-db-ind" class="uhpa-select" name="uhp_dashboard[indicador]">
								<?php foreach ( $indicadores as $clave => $meta ) : ?>
									<option value="<?php echo esc_attr( $clave ); ?>" <?php selected( $clave, $cfg['indicador'] ); ?>>
										<?php echo esc_html( $meta['etiqueta'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</p>

						<p>
							<label class="uhpa-label" for="uhp-db-tes"><?php esc_html_e( 'Capa base', 'urkunina-5000' ); ?></label>
							<select id="uhp-db-tes" class="uhpa-select" name="uhp_dashboard[teselas]">
								<option value="oscuro" <?php selected( 'oscuro', $cfg['teselas'] ); ?>><?php esc_html_e( 'Tono oscuro — identidad del objeto 3D (recomendado)', 'urkunina-5000' ); ?></option>
								<option value="osm" <?php selected( 'osm', $cfg['teselas'] ); ?>><?php esc_html_e( 'OpenStreetMap estándar', 'urkunina-5000' ); ?></option>
								<option value="claro" <?php selected( 'claro', $cfg['teselas'] ); ?>><?php esc_html_e( 'Tono claro', 'urkunina-5000' ); ?></option>
								<option value="humanitario" <?php selected( 'humanitario', $cfg['teselas'] ); ?>><?php esc_html_e( 'Humanitarian OSM', 'urkunina-5000' ); ?></option>
							</select>
						</p>

						<div class="uhpa-fila3">
							<p>
								<label class="uhpa-label" for="uhp-db-lat"><?php esc_html_e( 'Latitud', 'urkunina-5000' ); ?></label>
								<input type="number" step="0.0001" id="uhp-db-lat" class="uhpa-input" name="uhp_dashboard[mapa_lat]"
									value="<?php echo esc_attr( $cfg['mapa_lat'] ); ?>">
							</p>
							<p>
								<label class="uhpa-label" for="uhp-db-lon"><?php esc_html_e( 'Longitud', 'urkunina-5000' ); ?></label>
								<input type="number" step="0.0001" id="uhp-db-lon" class="uhpa-input" name="uhp_dashboard[mapa_lon]"
									value="<?php echo esc_attr( $cfg['mapa_lon'] ); ?>">
							</p>
							<p>
								<label class="uhpa-label" for="uhp-db-zoom"><?php esc_html_e( 'Zoom', 'urkunina-5000' ); ?></label>
								<input type="number" min="5" max="14" id="uhp-db-zoom" class="uhpa-input" name="uhp_dashboard[mapa_zoom]"
									value="<?php echo esc_attr( $cfg['mapa_zoom'] ); ?>">
							</p>
						</div>
						<p class="uhpa-nota"><?php esc_html_e( 'Las coordenadas deben caer dentro de Nariño. Si no lo hacen, el plugin vuelve al centro del departamento en lugar de mostrar otro territorio.', 'urkunina-5000' ); ?></p>

						<p>
							<label><input type="checkbox" name="uhp_dashboard[mostrar_kpi]" value="1" <?php checked( 1, (int) $cfg['mostrar_kpi'] ); ?>>
								<?php esc_html_e( 'Mostrar el cintillo de cifras clave', 'urkunina-5000' ); ?></label><br>
							<label><input type="checkbox" name="uhp_dashboard[panel_izq]" value="1" <?php checked( 1, (int) $cfg['panel_izq'] ); ?>>
								<?php esc_html_e( 'Abrir el panel de controles al cargar', 'urkunina-5000' ); ?></label><br>
							<label><input type="checkbox" name="uhp_dashboard[panel_der]" value="1" <?php checked( 1, (int) $cfg['panel_der'] ); ?>>
								<?php esc_html_e( 'Abrir el panel de análisis al cargar', 'urkunina-5000' ); ?></label>
						</p>

						<?php submit_button( __( 'Guardar el tablero', 'urkunina-5000' ) ); ?>
					</form>
				</div>
			</section>

			<section class="uhpa-card">
				<header class="uhpa-card__cab">
					<h2><?php esc_html_e( 'Cómo está compuesto', 'urkunina-5000' ); ?></h2>
					<p><?php esc_html_e( 'El tablero reparte una sola pantalla en cuatro zonas.', 'urkunina-5000' ); ?></p>
				</header>
				<div class="uhpa-card__cuerpo">
					<div class="uhpa-esquema" aria-hidden="true">
						<div class="uhpa-esquema__cab"><?php esc_html_e( 'Cabecera y cifras clave', 'urkunina-5000' ); ?></div>
						<div class="uhpa-esquema__fila">
							<div class="uhpa-esquema__izq"><?php esc_html_e( 'Controles y filtros', 'urkunina-5000' ); ?></div>
							<div class="uhpa-esquema__centro"><?php esc_html_e( 'Mapa OpenStreetMap', 'urkunina-5000' ); ?></div>
							<div class="uhpa-esquema__der"><?php esc_html_e( 'Gráficos y análisis', 'urkunina-5000' ); ?></div>
						</div>
					</div>
					<ul class="uhpa-lista uhpa-lista--check">
						<li><?php esc_html_e( 'El contenedor ocupa el 100 % del ancho disponible y toda la altura de la ventana.', 'urkunina-5000' ); ?></li>
						<li><?php esc_html_e( 'Los dos paneles laterales se pliegan para dejar el mapa a pantalla completa.', 'urkunina-5000' ); ?></li>
						<li><?php esc_html_e( 'Al hacer clic en un municipio se abre su ficha sobre el mapa.', 'urkunina-5000' ); ?></li>
						<li><?php esc_html_e( 'Por debajo de 1100 px de ancho las zonas se apilan en una sola columna y el tablero sigue siendo usable en móvil.', 'urkunina-5000' ); ?></li>
						<li><?php esc_html_e( 'La geometría de los 64 municipios se descarga una sola vez: cambiar de indicador solo repide la tabla de valores.', 'urkunina-5000' ); ?></li>
					</ul>
					<?php $this->copiable( '[urkunina_dashboard]' ); ?>
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
								<?php esc_html_e( 'Mostrar la marca institucional dentro de la escena', 'urkunina-5000' ); ?></label>
						</p>
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
