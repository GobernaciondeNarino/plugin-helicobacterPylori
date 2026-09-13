<?php
/**
 * Registro y render de los shortcodes del plugin.
 *
 * Contrato común de todos ellos:
 *  - esqueleto inmediato y carga asíncrona (nunca bloquean el render),
 *  - error elegante con reintento si la REST falla,
 *  - atribución de fuentes al pie,
 *  - toda la salida escapada con esc_html / esc_attr / esc_url,
 *  - los assets se encolan SOLO cuando el shortcode aparece en la página.
 *
 * @package Urkunina5000
 */

namespace GobernacionNarino\Urkunina;

defined( 'ABSPATH' ) || exit;

final class UHP_Shortcodes {

	/** @var int Contador para identificadores únicos. */
	private static $contador = 0;

	public function __construct() {
		add_action( 'init', array( $this, 'registrar' ) );
		// Prioridad 20: después de que UHP_Assets registre las hojas (5) y
		// antes de que wp_head las imprima. Ver adelantar_hojas().
		add_action( 'wp_enqueue_scripts', array( $this, 'adelantar_hojas' ), 20 );
	}

	/**
	 * Encola las hojas de los shortcodes que haya en la entrada, ANTES de
	 * que se renderice el contenido.
	 *
	 * EL PROBLEMA QUE RESUELVE. Cada shortcode encola lo suyo cuando se
	 * renderiza, y eso ocurre durante `the_content`, cuando `wp_head` ya
	 * imprimió las hojas. WordPress no las descarta —las saca en el pie—,
	 * pero para entonces el navegador ya pintó el marcado sin estilo. En la
	 * página del tablero eso son 223 KB de HTML dibujados en crudo antes de
	 * que llegue el CSS: la página entera parpadea, y en una conexión lenta
	 * el parpadeo dura lo suficiente para fotografiarlo.
	 *
	 * Encolar aquí, en `wp_enqueue_scripts`, hace que las hojas salgan en
	 * el `<head>` y el marcado nazca ya vestido.
	 *
	 * Solo se adelantan las HOJAS. Los scripts siguen saliendo en el pie,
	 * que es donde deben estar: no bloquean el pintado y no producen
	 * parpadeo. Y los shortcodes siguen encolando lo suyo al renderizar,
	 * que es lo que cubre los casos que esta función no puede ver —un
	 * widget, una plantilla que llame a do_shortcode(), un constructor de
	 * páginas que guarde el contenido en otro sitio—. Encolar dos veces no
	 * cuesta nada: WordPress ignora el duplicado.
	 */
	public function adelantar_hojas() {
		$contenido = self::contenido_de_la_entrada();
		if ( '' === $contenido ) {
			return;
		}

		// Qué hoja necesita cada shortcode. Es la única duplicación de la
		// tabla que cada sc_* aplica por su cuenta, y va junta y a la vista
		// para que se note si alguna se queda atrás.
		$hojas = array(
			'urkunina_3d'             => '3d',
			'urkunina_dashboard'      => 'dashboard',
			'urkunina_grafico'        => 'grafico',
			'urkunina_selector'       => 'grafico',
			'urkunina_mapa'           => 'mapa',
			'urkunina_geomapa'        => 'geomapa',
			'urkunina_analisis'       => 'base',
			'urkunina_titulo'         => 'base',
			'urkunina_descripcion'    => 'base',
			'urkunina_interpretacion' => 'base',
			'urkunina_resumen'        => 'base',
			'urkunina_cifras'         => 'base',
			'urkunina_fuente'         => 'base',
			'urkunina_kpi'            => 'base',
			'urkunina_tabla'          => 'base',
			'urkunina_ficha'          => 'base',
			'urkunina_dato'           => 'base',
		);

		$hay_tablero = false;
		$hay_resto   = false;

		foreach ( $hojas as $tag => $hoja ) {
			if ( ! has_shortcode( $contenido, $tag ) ) {
				continue;
			}
			wp_enqueue_style( UHP_Assets::P . $hoja );

			if ( 'urkunina_dashboard' === $tag ) {
				$hay_tablero = true;
			} else {
				$hay_resto = true;
			}

			// Un gráfico de vista territorial puede pasar al tipo «mapa»
			// desde su barra, y entonces necesita la hoja del geomapa.
			if ( 'urkunina_grafico' === $tag ) {
				wp_enqueue_style( UHP_Assets::P . 'geomapa' );
			}
		}

		// Las dos familias tipográficas son distintas y no se estorban: el
		// tablero usa IBM Plex y el resto Hind Madurai.
		if ( $hay_tablero ) {
			UHP_Estilos::encolar_fuentes_tablero();
		}
		if ( $hay_resto ) {
			UHP_Estilos::encolar_fuentes();
		}
	}

	/**
	 * Contenido de la entrada que se está mostrando, si lo hay.
	 *
	 * Devuelve '' en archivos, búsquedas y cualquier vista que no sea una
	 * entrada concreta: ahí no hay un contenido que inspeccionar y los
	 * shortcodes encolarán lo suyo al renderizarse, como siempre.
	 *
	 * @return string
	 */
	private static function contenido_de_la_entrada() {
		if ( ! is_singular() ) {
			return '';
		}
		$entrada = get_post();
		return ( $entrada && isset( $entrada->post_content ) ) ? (string) $entrada->post_content : '';
	}

	/**
	 * Registra todos los shortcodes.
	 */
	public function registrar() {
		add_shortcode( 'urkunina_3d', array( $this, 'sc_3d' ) );
		add_shortcode( 'urkunina_dashboard', array( $this, 'sc_dashboard' ) );
		add_shortcode( 'urkunina_grafico', array( $this, 'sc_grafico' ) );

		// Agrupa varias vistas en una sola tarjeta: una lista desplegable
		// gobierna al título, los textos, la tabla y el gráfico del canal.
		add_shortcode( 'urkunina_selector', array( $this, 'sc_selector' ) );

		// Los textos de una vista van aparte del gráfico: cada uno es su
		// propio shortcode para poder maquetarlos por libre en la página.
		add_shortcode( 'urkunina_analisis', array( $this, 'sc_analisis' ) );
		add_shortcode( 'urkunina_titulo', array( $this, 'sc_titulo' ) );
		add_shortcode( 'urkunina_descripcion', array( $this, 'sc_descripcion' ) );
		add_shortcode( 'urkunina_interpretacion', array( $this, 'sc_interpretacion' ) );
		add_shortcode( 'urkunina_resumen', array( $this, 'sc_resumen' ) );
		add_shortcode( 'urkunina_cifras', array( $this, 'sc_cifras' ) );
		add_shortcode( 'urkunina_fuente', array( $this, 'sc_fuente' ) );
		add_shortcode( 'urkunina_mapa', array( $this, 'sc_mapa' ) );
		add_shortcode( 'urkunina_geomapa', array( $this, 'sc_geomapa' ) );
		add_shortcode( 'urkunina_kpi', array( $this, 'sc_kpi' ) );
		add_shortcode( 'urkunina_tabla', array( $this, 'sc_tabla' ) );
		add_shortcode( 'urkunina_ficha', array( $this, 'sc_ficha' ) );
		add_shortcode( 'urkunina_dato', array( $this, 'sc_dato' ) );
	}

	/* ================================================================= */
	/* [urkunina_3d]                                                     */
	/* ================================================================= */

	/**
	 * Recreación 3D de Helicobacter pylori con su línea de tiempo.
	 *
	 * @param array $atts Atributos del shortcode.
	 * @return string
	 */
	public function sc_3d( $atts ) {
		$cfg  = get_option( 'uhp_3d', UHP_Activator::tresd_por_defecto() );
		$cfg  = is_array( $cfg ) ? $cfg : UHP_Activator::tresd_por_defecto();
		$atts = $this->fusionar(
			array(
				'alto'         => isset( $cfg['alto'] ) ? $cfg['alto'] : '100vh',
				'autoplay'     => ! empty( $cfg['autoplay'] ) ? 'si' : 'no',
				'duracion'     => isset( $cfg['duracion'] ) ? $cfg['duracion'] : 15,
				'instrumentos' => ! empty( $cfg['instrumentos'] ) ? 'si' : 'no',
				'cabecera'     => ! empty( $cfg['cabecera'] ) ? 'si' : 'no',
			),
			$atts,
			'urkunina_3d'
		);

		UHP_Estilos::encolar_fuentes();
		wp_enqueue_style( UHP_Assets::P . '3d' );
		wp_enqueue_script( UHP_Assets::P . '3d' );

		// Las URLs de Three.js viajan en un script clásico: un módulo ES no
		// admite un especificador dinámico en su `import`, de modo que las
		// recibe por window.UHP3D. Solo se imprime una vez aunque el
		// shortcode aparezca varias veces en la misma página.
		static $urls_impresas = false;
		if ( ! $urls_impresas ) {
			wp_add_inline_script(
				UHP_Assets::P . 'core',
				'window.UHP3D=' . UHP_Assets::json_para_script( array( 'urls' => UHP_Assets::three_urls() ) ) . ';',
				'after'
			);
			$urls_impresas = true;
		}

		$id      = $this->id( 'uhp3d' );
		$clases  = 'uhp3d';
		$clases .= ( 'si' === $atts['cabecera'] ) ? '' : ' uhp3d--sin-cabecera';
		$clases .= ( 'si' === $atts['instrumentos'] ) ? '' : ' uhp3d--sin-instrumentos';

		$alto = UHP_Estilos::sanitizar_css( $atts['alto'] );

		ob_start();
		?>
		<div id="<?php echo esc_attr( $id ); ?>"
			class="<?php echo esc_attr( $clases ); ?>"
			style="--uhp3d-alto:<?php echo esc_attr( $alto ); ?>"
			data-uhp3d-raiz
			data-autoplay="<?php echo 'si' === $atts['autoplay'] ? '1' : '0'; ?>"
			data-duracion="<?php echo esc_attr( (float) $atts['duracion'] ); ?>"
			tabindex="0"
			role="group"
			aria-label="Recreación tridimensional de Helicobacter pylori y línea de tiempo de la infección">

			<div class="uhp3d__escena">
				<canvas class="uhp3d__lienzo" role="img"
					aria-label="Recreación tridimensional de la bacteria Helicobacter pylori"></canvas>
			</div>
			<div class="uhp3d__vineta" aria-hidden="true"></div>
			<div class="uhp3d__grano" aria-hidden="true"></div>

			<?php if ( 'si' === $atts['cabecera'] ) : ?>
			<div class="uhp3d__cabecera">
				<div class="uhp3d__marca">
					<div class="uhp3d__escudo" aria-hidden="true"></div>
					<div class="uhp3d__marca-txt">
						<b><?php esc_html_e( 'Gobernación de Nariño', 'urkunina-5000' ); ?></b>
						<span><?php esc_html_e( 'Secretaría TIC, Innovación y Gobierno Abierto', 'urkunina-5000' ); ?></span>
					</div>
				</div>
				<div class="uhp3d__titulo-obra">
					<b>Helicobacter pylori</b>
					<span><?php esc_html_e( 'Recreación 3D y línea de tiempo de la infección', 'urkunina-5000' ); ?></span>
				</div>
			</div>
			<?php endif; ?>

			<section class="uhp3d__lectura" data-uhp3d="lectura" aria-live="polite">
				<span class="uhp3d__reloj" data-uhp3d="reloj">Momento 0</span>
				<h2 data-uhp3d="titulo">—</h2>
				<p class="uhp3d__entradilla" data-uhp3d="entradilla">—</p>
				<div data-uhp3d="cuerpo"></div>
				<div class="uhp3d__cifras" data-uhp3d="cifras"></div>
				<p class="uhp3d__fuente" data-uhp3d="fuente"></p>
			</section>

			<aside class="uhp3d__instrumentos" aria-hidden="true">
				<h3><?php esc_html_e( 'Morfometría en pantalla', 'urkunina-5000' ); ?></h3>
				<div class="uhp3d__dato"><span><?php esc_html_e( 'Longitud del cuerpo', 'urkunina-5000' ); ?></span><b data-uhp3d="d-long">3,10 <em>µm</em></b></div>
				<div class="uhp3d__dato"><span><?php esc_html_e( 'Diámetro celular', 'urkunina-5000' ); ?></span><b data-uhp3d="d-diam">0,56 <em>µm</em></b></div>
				<div class="uhp3d__dato"><span><?php esc_html_e( 'Paso de la hélice', 'urkunina-5000' ); ?></span><b data-uhp3d="d-paso">2,50 <em>µm</em></b></div>
				<div class="uhp3d__dato"><span><?php esc_html_e( 'Vueltas', 'urkunina-5000' ); ?></span><b data-uhp3d="d-vueltas">2,0</b></div>
				<div class="uhp3d__dato"><span><?php esc_html_e( 'Flagelos', 'urkunina-5000' ); ?></span><b data-uhp3d="d-flag">5</b></div>
				<div class="uhp3d__dato"><span><?php esc_html_e( 'Longitud flagelar', 'urkunina-5000' ); ?></span><b data-uhp3d="d-flong">4,10 <em>µm</em></b></div>
				<div class="uhp3d__dato"><span><?php esc_html_e( 'Velocidad de nado', 'urkunina-5000' ); ?></span><b data-uhp3d="d-vel">0 <em>µm/s</em></b></div>
				<div class="uhp3d__dato"><span><?php esc_html_e( 'Forma', 'urkunina-5000' ); ?></span><b data-uhp3d="d-forma">Espiral</b></div>
				<div class="uhp3d__escala">
					<div class="uhp3d__escala-barra" data-uhp3d="escala-barra" style="width:60px"></div>
					<div class="uhp3d__escala-txt"><?php esc_html_e( '1 µm · escala real del modelo', 'urkunina-5000' ); ?></div>
				</div>
			</aside>

			<footer class="uhp3d__riel">
				<div class="uhp3d__progreso"><i data-uhp3d="progreso"></i></div>
				<div class="uhp3d__riel-fila">
					<div class="uhp3d__mandos">
						<button class="uhp3d__mando" type="button" data-uhp3d="btn-atras"
							title="<?php esc_attr_e( 'Momento anterior', 'urkunina-5000' ); ?>"
							aria-label="<?php esc_attr_e( 'Momento anterior', 'urkunina-5000' ); ?>">
							<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M11 1.5 4.5 8 11 14.5z"/></svg>
						</button>
						<button class="uhp3d__mando" type="button" data-uhp3d="btn-play"
							title="<?php esc_attr_e( 'Pausar recorrido', 'urkunina-5000' ); ?>"
							aria-label="<?php esc_attr_e( 'Pausar recorrido', 'urkunina-5000' ); ?>">
							<svg data-uhp3d="ico-play" viewBox="0 0 16 16" aria-hidden="true"><path d="M4 2h3.2v12H4zM8.8 2H12v12H8.8z"/></svg>
						</button>
						<button class="uhp3d__mando" type="button" data-uhp3d="btn-siguiente"
							title="<?php esc_attr_e( 'Momento siguiente', 'urkunina-5000' ); ?>"
							aria-label="<?php esc_attr_e( 'Momento siguiente', 'urkunina-5000' ); ?>">
							<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M5 1.5 11.5 8 5 14.5z"/></svg>
						</button>
					</div>
					<nav class="uhp3d__pasos" data-uhp3d="pasos"
						aria-label="<?php esc_attr_e( 'Línea de tiempo de la infección', 'urkunina-5000' ); ?>"></nav>
				</div>
			</footer>

			<div class="uhp3d__carga" data-uhp3d="carga">
				<div class="uhp3d__helice" aria-hidden="true"></div>
				<b><?php esc_html_e( 'Construyendo el modelo celular', 'urkunina-5000' ); ?></b>
				<p><?php esc_html_e( 'Hélice, flagelos envainados y medio mucoso', 'urkunina-5000' ); ?></p>
				<div class="uhp3d__fallo" data-uhp3d="fallo" role="alert"></div>
			</div>

			<p class="uhp3d__sr">
				<?php esc_html_e( 'Con el foco puesto en la escena, use las flechas izquierda y derecha para recorrer la línea de tiempo y la barra espaciadora para pausar o reanudar. Arrastre sobre la escena para girar el modelo.', 'urkunina-5000' ); ?>
			</p>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ================================================================= */
	/* [urkunina_dashboard]                                              */
	/* ================================================================= */

	/**
	 * Tablero de resultados del proyecto.
	 *
	 * Rejilla de tres columnas dentro de un contenedor a pantalla completa:
	 * filtros y lista de municipios a la izquierda, mapa del departamento
	 * al centro, lectura del territorio a la derecha.
	 *
	 * El marcado se imprime completo y vacío; lo rellena uhp-dashboard.js
	 * con una sola petición a /tablero. Las partes que NO dependen de los
	 * datos —el perfil de los 5.000 participantes, la leyenda, los rótulos—
	 * van en el HTML, de modo que la página ya dice algo antes de que llegue
	 * la respuesta y lo sigue diciendo si no llega nunca.
	 *
	 * @param array $atts Atributos del shortcode.
	 * @return string
	 */
	public function sc_dashboard( $atts ) {
		$cfg  = get_option( 'uhp_dashboard', UHP_Activator::dashboard_por_defecto() );
		$cfg  = is_array( $cfg ) ? $cfg : UHP_Activator::dashboard_por_defecto();
		$atts = $this->fusionar(
			array(
				'titulo'    => isset( $cfg['titulo'] ) ? $cfg['titulo'] : 'URKUNINA 5000',
				'lema'      => __( 'Prevalencia de lesiones precursoras de malignidad y erradicación de H. pylori como prevención primaria del cáncer gástrico — Nariño, 2018–2023', 'urkunina-5000' ),
				'alto'      => '100vh',
				'indicador' => isset( $cfg['indicador'] ) ? $cfg['indicador'] : 'lpm',
			),
			$atts,
			'urkunina_dashboard'
		);

		UHP_Estilos::encolar_fuentes_tablero();
		wp_enqueue_style( UHP_Assets::P . 'dashboard' );
		UHP_Assets::encolar_libreria( 'd3' );
		wp_enqueue_script( UHP_Assets::P . 'dashboard' );

		$id = $this->id( 'uhpdb' );

		// Solo dos indicadores tienen rampa en el mapa; cualquier otro
		// valor cae en el de lesión precursora, que es el del proyecto.
		$indicador = UHP_Security::clave( $atts['indicador'] );
		if ( ! in_array( $indicador, array( 'lpm', 'hp' ), true ) ) {
			$indicador = 'lpm';
		}

		$estilo = '--uhp-db-alto:' . UHP_Estilos::sanitizar_css( $atts['alto'] ) . ';';

		ob_start();
		?>
		<div id="<?php echo esc_attr( $id ); ?>"
			class="uhp-db"
			style="<?php echo esc_attr( $estilo ); ?>"
			data-uhp-tablero
			data-indicador="<?php echo esc_attr( $indicador ); ?>"
			role="region"
			aria-label="<?php echo esc_attr( $atts['titulo'] ); ?>">

			<header class="uhp-db__cab">
				<div class="uhp-db__marca">
					<h1><?php echo esc_html( $atts['titulo'] ); ?></h1>
					<span class="uhp-db__lema"><?php echo esc_html( $atts['lema'] ); ?></span>
				</div>
				<div class="uhp-db__chips">
					<div class="uhp-db__pill"><?php esc_html_e( 'Cobertura', 'urkunina-5000' ); ?> <b>55/55</b></div>
					<div class="uhp-db__pill"><?php esc_html_e( 'Participantes', 'urkunina-5000' ); ?> <b>5.000</b></div>
					<div class="uhp-db__pill"><?php esc_html_e( 'Ejecución', 'urkunina-5000' ); ?> <b>100%</b></div>
					<div class="uhp-db__pill"><?php esc_html_e( 'Estado', 'urkunina-5000' ); ?> <b><?php esc_html_e( 'Cierre', 'urkunina-5000' ); ?></b></div>
				</div>
			</header>

			<main class="uhp-db__main">
				<div class="uhp-db__col uhp-db__col--izq">
					<div class="uhp-db__kpis" data-uhp-zona="kpis"
						aria-label="<?php esc_attr_e( 'Cifras de la selección actual', 'urkunina-5000' ); ?>"></div>

					<div class="uhp-db__card">
						<h2><?php esc_html_e( 'Zona de riesgo', 'urkunina-5000' ); ?></h2>
						<div class="uhp-db__zonas" data-uhp-zona="zonas"></div>
					</div>

					<div class="uhp-db__card">
						<div class="uhp-db__cardcab">
							<h2><?php esc_html_e( 'Municipios intervenidos', 'urkunina-5000' ); ?></h2>
							<span class="uhp-db__pill" data-uhp-zona="mcount" style="padding:3px 8px"></span>
						</div>
						<div class="uhp-db__scroll">
							<div class="uhp-db__mlista" data-uhp-zona="mlista"></div>
						</div>
					</div>
				</div>

				<div class="uhp-db__col">
					<div class="uhp-db__card uhp-db__mapacard">
						<div class="uhp-db__mapa" data-uhp-zona="mapa"></div>
						<div class="uhp-db__mapacab">
							<div class="t" data-uhp-zona="mapatitulo"><?php esc_html_e( '55 municipios priorizados · área andina', 'urkunina-5000' ); ?></div>
						</div>
						<div class="uhp-db__tip" data-uhp-zona="tip" role="presentation"></div>
						<button type="button" class="uhp-db__limpiar" data-uhp-accion="limpiar">
							<?php esc_html_e( 'Limpiar', 'urkunina-5000' ); ?>
						</button>
						<p class="uhp-db__fuente">
							<?php esc_html_e( 'Cartografía municipal DANE · datos del proyecto URKUNINA 5000', 'urkunina-5000' ); ?>
						</p>
						<div class="uhp-db__zoomctl">
							<button type="button" data-uhp-accion="zoom-mas"
								aria-label="<?php esc_attr_e( 'Acercar el mapa', 'urkunina-5000' ); ?>">+</button>
							<button type="button" data-uhp-accion="zoom-menos"
								aria-label="<?php esc_attr_e( 'Alejar el mapa', 'urkunina-5000' ); ?>">−</button>
						</div>
						<div class="uhp-db__leyenda">
							<span class="lgtitle" data-uhp-zona="leyenda-titulo"></span>
							<div class="ramp">
								<i data-uhp-zona="rampa"></i>
								<span data-uhp-zona="rampa-min">—</span>
								<span data-uhp-zona="rampa-max">—</span>
							</div>
							<div>
								<i class="sw" data-uhp-zona="muestra-sub"></i>
								<?php esc_html_e( 'Dato subregional (sin cifra municipal)', 'urkunina-5000' ); ?>
							</div>
							<div>
								<i class="sw nd"></i>
								<?php esc_html_e( 'Municipio no intervenido', 'urkunina-5000' ); ?>
							</div>
							<div>
								<i class="ring"></i>
								<?php esc_html_e( 'Caso de cáncer detectado', 'urkunina-5000' ); ?>
							</div>
						</div>
					</div>
				</div>

				<div class="uhp-db__col uhp-db__col--der">
					<div class="uhp-db__card">
						<h2 data-uhp-zona="detalle-titulo"><?php esc_html_e( 'Casos de cáncer gástrico detectados', 'urkunina-5000' ); ?></h2>
						<div class="uhp-db__detalle" data-uhp-zona="detalle" aria-live="polite"></div>
					</div>

					<div class="uhp-db__card">
						<div class="uhp-db__cardcab">
							<h2><?php esc_html_e( 'Prevalencia por subregión', 'urkunina-5000' ); ?></h2>
							<div class="uhp-db__tabs" role="group"
								aria-label="<?php esc_attr_e( 'Indicador de la prevalencia', 'urkunina-5000' ); ?>">
								<button type="button" class="uhp-db__tab" data-uhp-ind="lpm"
									aria-pressed="<?php echo 'lpm' === $indicador ? 'true' : 'false'; ?>">LPM</button>
								<button type="button" class="uhp-db__tab" data-uhp-ind="hp"
									aria-pressed="<?php echo 'hp' === $indicador ? 'true' : 'false'; ?>">H. PYLORI</button>
							</div>
						</div>
						<div class="uhp-db__bars" data-uhp-zona="bars"></div>
					</div>

					<div class="uhp-db__card">
						<h2><?php esc_html_e( 'Perfil de los 5.000 participantes', 'urkunina-5000' ); ?></h2>
						<?php
						/* Estas cuatro filas son departamentales y no se filtran:
						   el perfil está publicado para el conjunto de los 5.000
						   participantes, no municipio a municipio. Por eso van en
						   el HTML y no las toca el JavaScript: si se redibujaran
						   con la selección parecerían responder a ella. */
						$perfil = array(
							array(
								'lab'    => __( 'Género', 'urkunina-5000' ),
								'partes' => array(
									array( 64.2, 'var(--uhp-db-acc)', __( 'Mujeres', 'urkunina-5000' ) ),
									array( 35.8, 'var(--uhp-db-azul)', __( 'Hombres', 'urkunina-5000' ) ),
								),
							),
							array(
								'lab'    => __( 'Régimen de salud', 'urkunina-5000' ),
								'partes' => array(
									array( 81.9, 'var(--uhp-db-amar)', __( 'Subsidiado', 'urkunina-5000' ) ),
									array( 17.7, 'var(--uhp-db-azul)', __( 'Contributivo', 'urkunina-5000' ) ),
								),
							),
							array(
								'lab'    => __( 'Pertenencia étnica', 'urkunina-5000' ),
								'partes' => array(
									array( 87.6, 'var(--uhp-db-ink-3)', __( 'Mestiza', 'urkunina-5000' ) ),
									array( 9.9, 'var(--uhp-db-acc)', __( 'Indígena', 'urkunina-5000' ) ),
									array( 2.2, 'var(--uhp-db-roja)', __( 'Afro', 'urkunina-5000' ) ),
								),
							),
							array(
								'lab'    => __( 'Estado nutricional', 'urkunina-5000' ),
								'partes' => array(
									array( 29.6, 'var(--uhp-db-verde)', __( 'Normal', 'urkunina-5000' ) ),
									array( 48.2, 'var(--uhp-db-amar)', __( 'Sobrepeso', 'urkunina-5000' ) ),
									array( 21.8, 'var(--uhp-db-roja)', __( 'Obesidad', 'urkunina-5000' ) ),
								),
							),
						);
						?>
						<div class="uhp-db__perfil">
							<?php foreach ( $perfil as $fila ) : ?>
								<div class="row">
									<div class="lab"><?php echo esc_html( $fila['lab'] ); ?></div>
									<div class="uhp-db__stack" role="img"
										aria-label="<?php
										$partes = array();
										foreach ( $fila['partes'] as $p ) {
											$partes[] = $p[2] . ' ' . number_format_i18n( $p[0], 1 ) . '%';
										}
										echo esc_attr( $fila['lab'] . ': ' . implode( ', ', $partes ) );
										?>">
										<?php foreach ( $fila['partes'] as $p ) : ?>
											<i style="width:<?php echo esc_attr( (float) $p[0] ); ?>%;background:<?php echo esc_attr( $p[1] ); ?>"></i>
										<?php endforeach; ?>
									</div>
									<div class="leg">
										<?php foreach ( $fila['partes'] as $p ) : ?>
											<span><?php echo esc_html( $p[2] ); ?> <em><?php echo esc_html( number_format_i18n( $p[0], 1 ) . '%' ); ?></em></span>
										<?php endforeach; ?>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				</div>
			</main>

			<p class="uhp-db__sr" data-uhp-zona="estado" role="status" aria-live="polite"></p>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ================================================================= */
	/* [urkunina_selector]                                               */
	/* ================================================================= */

	/**
	 * Lista desplegable que gobierna un canal de piezas.
	 *
	 * Es la tarjeta que agrupa todas las vistas de una pestaña: al elegir
	 * un nombre cambian a la vez el título, la descripción, el análisis,
	 * las cifras, la tabla y el gráfico que compartan su canal.
	 *
	 *   [urkunina_selector grupo="Prevalencia"]
	 *   [urkunina_titulo   grupo="Prevalencia"]
	 *   [urkunina_grafico  grupo="Prevalencia" alto="420px"]
	 *   [urkunina_tabla    grupo="Prevalencia"]
	 *
	 * Cada pieza es su propio shortcode y ninguna sabe de las otras, de
	 * modo que pueden colocarse en columnas distintas, en otro orden o
	 * repartidas por la página.
	 *
	 * @param array $atts Atributos del shortcode.
	 * @return string
	 */
	public function sc_selector( $atts ) {
		$atts = $this->fusionar(
			array_merge(
				$this->atts_canal(),
				array(
					'view'      => '',
					'etiqueta'  => __( 'Vista', 'urkunina-5000' ),
					'titulo'    => '',
					'descripcion' => 'si',
					'tarjeta'   => 'si',
				)
			),
			$atts,
			'urkunina_selector'
		);

		$canal = $this->canal( $atts );
		if ( ! $canal ) {
			return $this->aviso(
				__( 'El selector necesita un grupo de vistas: indique grupo="Prevalencia" o views="vista_a,vista_b". Consulte el catálogo en URKUNINA 5000 → Gráficos.', 'urkunina-5000' )
			);
		}

		UHP_Estilos::encolar_fuentes();
		wp_enqueue_style( UHP_Assets::P . 'grafico' );
		wp_enqueue_script( UHP_Assets::P . 'grupo' );

		$id      = $this->id( 'uhpsel' );
		$tarjeta = $this->afirmativo( $atts['tarjeta'] );
		$clases  = 'uhp uhp-sel' . ( $tarjeta ? '' : ' uhp-sel--plano' );

		ob_start();
		?>
		<div class="<?php echo esc_attr( $clases ); ?>"
			style="<?php echo esc_attr( UHP_Estilos::inline( $atts ) ); ?>"
			data-uhp-selector
			data-canal="<?php echo esc_attr( $canal['canal'] ); ?>">

			<?php if ( '' !== $atts['titulo'] ) : ?>
				<h3 class="uhp-sel__titulo"><?php echo esc_html( $atts['titulo'] ); ?></h3>
			<?php endif; ?>

			<div class="uhp-sel__campo">
				<label class="uhp-sel__etq" for="<?php echo esc_attr( $id ); ?>">
					<?php echo esc_html( $atts['etiqueta'] ); ?>
				</label>
				<select class="uhp-sel__select" id="<?php echo esc_attr( $id ); ?>"
					data-uhp-canal-select>
					<?php foreach ( $canal['vistas'] as $v ) : ?>
						<?php $m = UHP_Views::meta( $v ); ?>
						<option value="<?php echo esc_attr( $v ); ?>"
							<?php selected( $v, $canal['activa'] ); ?>>
							<?php echo esc_html( $m['name'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<?php if ( $this->afirmativo( $atts['descripcion'] ) ) : ?>
				<?php
				/* La descripción corta de la vista elegida vive dentro del
				   propio selector: dice qué se está mirando sin obligar a
				   colocar otro shortcode al lado. */
				?>
				<div class="uhp-sel__pie">
					<?php foreach ( $canal['vistas'] as $v ) : ?>
						<?php $m = UHP_Views::meta( $v ); ?>
						<p class="uhp-sel__desc" data-uhp-panel
							data-canal="<?php echo esc_attr( $canal['canal'] ); ?>"
							data-vista="<?php echo esc_attr( $v ); ?>"
							<?php echo ( $v === $canal['activa'] ) ? '' : 'hidden'; ?>>
							<?php echo esc_html( $m['description'] ); ?>
						</p>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<p class="uhp-sr" role="status" aria-live="polite" data-uhp-canal-estado></p>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ================================================================= */
	/* [urkunina_grafico]                                                */
	/* ================================================================= */

	/**
	 * Gráfico D3plus de una vista, con barra de herramientas y análisis.
	 *
	 * @param array $atts Atributos del shortcode.
	 * @return string
	 */
	public function sc_grafico( $atts ) {
		$atts = $this->fusionar(
			array_merge( $this->atts_canal(), array(
				'view'         => '',
				'type'         => '',
				'titulo'       => '',
				'alto'         => '',
				'tema'         => 'claro',
				'leyenda'      => 'si',
				'leyenda_pos'  => 'bottom',
				'leyenda_estilo' => 'text',
				'acciones'     => '',
				'barra'        => 'si',
				// Opciones del tipo «mapa». Solo cuentan en las vistas
				// territoriales, que son las únicas que lo ofrecen.
				'teselas'      => 'no',
				'serie'        => '',
				'etiquetas'    => 'no',
			) ),
			$atts,
			'urkunina_grafico'
		);

		// Agrupado: el gráfico es UNO solo y cambia de vista en vivo. No se
		// imprime uno por vista como con los textos, porque cada figura
		// pediría sus datos al arrancar: ocho vistas serían ocho peticiones
		// para enseñar una.
		$canal = $this->canal( $atts );
		if ( $canal ) {
			wp_enqueue_script( UHP_Assets::P . 'grupo' );
			$atts['view'] = $canal['activa'];
		}

		$vista = $this->vista_o_defecto( $atts['view'] );
		if ( ! UHP_Views::existe( $vista ) ) {
			return $this->aviso(
				sprintf(
					/* translators: %s: identificador de vista solicitado. */
					__( 'La vista «%s» no existe. Consulte el catálogo en URKUNINA 5000 → Gráficos.', 'urkunina-5000' ),
					$vista
				)
			);
		}

		UHP_Estilos::encolar_fuentes();
		wp_enqueue_style( UHP_Assets::P . 'grafico' );
		UHP_Assets::encolar_libreria( 'd3plus' );
		wp_enqueue_script( UHP_Assets::P . 'grafico' );

		// Una vista territorial ofrece el tipo «mapa» en la barra, de modo
		// que el componente de geomapas tiene que estar cargado aunque el
		// gráfico arranque en barras: el usuario puede cambiar de tipo sin
		// recargar la página y no habría con qué dibujarlo.
		$territorial = UHP_Views::es_territorial( $vista );
		if ( $territorial ) {
			wp_enqueue_style( UHP_Assets::P . 'geomapa' );
			wp_enqueue_script( UHP_Assets::P . 'geomapa' );
		}

		$meta   = UHP_Views::meta( $vista );
		$id     = $this->id( 'uhpg' );
		$clases = 'uhp uhp-g' . ( 'oscuro' === $atts['tema'] ? ' uhp-g--oscuro' : '' );

		// La serie solo existe en las vistas partidas en varias; si se pide
		// una que la vista no declara se ignora, igual que hace el servidor
		// al resolver /geomapa.
		$series = $territorial ? UHP_Views::series( $vista ) : array();
		$serie  = sanitize_text_field( (string) $atts['serie'] );
		if ( ! in_array( $serie, $series, true ) ) {
			$serie = '';
		}

		$estilo = UHP_Estilos::inline( $atts );
		if ( '' !== $atts['alto'] ) {
			$estilo .= '--uhp-g-alto:' . UHP_Estilos::sanitizar_css( $atts['alto'] ) . ';';
		}

		ob_start();
		?>
		<figure id="<?php echo esc_attr( $id ); ?>"
			class="<?php echo esc_attr( $clases ); ?>"
			style="<?php echo esc_attr( $estilo ); ?>"
			data-uhp-grafico
			data-view="<?php echo esc_attr( $vista ); ?>"
			data-type="<?php echo esc_attr( UHP_Security::clave( $atts['type'] ) ); ?>"
			data-legend="<?php echo 'no' === $atts['leyenda'] ? '0' : '1'; ?>"
			data-legend-pos="<?php echo esc_attr( UHP_Security::clave( $atts['leyenda_pos'] ) ); ?>"
			data-legend-style="<?php echo esc_attr( UHP_Security::clave( $atts['leyenda_estilo'] ) ); ?>"
			data-acciones="<?php echo esc_attr( sanitize_text_field( $atts['acciones'] ) ); ?>"
			data-tema="<?php echo 'oscuro' === $atts['tema'] ? 'oscuro' : 'claro'; ?>"
			data-teselas="<?php echo $this->afirmativo( $atts['teselas'] ) ? '1' : '0'; ?>"
			data-serie="<?php echo esc_attr( $serie ); ?>"
			data-etiquetas="<?php echo $this->afirmativo( $atts['etiquetas'] ) ? '1' : '0'; ?>"
			<?php if ( $canal ) : ?>data-canal="<?php echo esc_attr( $canal['canal'] ); ?>"<?php endif; ?>>

			<?php if ( 'no' !== $atts['titulo'] ) : ?>
				<figcaption class="uhp-g__titulo">
					<?php echo esc_html( '' !== $atts['titulo'] ? $atts['titulo'] : $meta['name'] ); ?>
				</figcaption>
			<?php endif; ?>

			<?php if ( 'no' !== $atts['barra'] ) : ?>
				<div class="uhp-g__barra" role="toolbar"
					aria-label="<?php esc_attr_e( 'Acciones del gráfico', 'urkunina-5000' ); ?>"></div>
			<?php endif; ?>

			<div class="uhp-g__lienzo"></div>
			<?php if ( $territorial ) : ?>
				<?php /* La rampa del mapa. D3plus no la dibuja: la pinta el
				         componente de geomapas, y queda vacía mientras el
				         gráfico no esté en modo mapa. */ ?>
				<div class="uhp-g__leyenda" aria-hidden="true"></div>
			<?php endif; ?>
			<?php echo $this->skeleton( __( 'Cargando el gráfico…', 'urkunina-5000' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</figure>
		<?php
		return ob_get_clean();
	}

	/* ================================================================= */
	/* [urkunina_analisis]                                               */
	/* ================================================================= */

	/**
	 * Solo el texto de análisis de una vista, sin el gráfico.
	 *
	 * @param array $atts Atributos del shortcode.
	 * @return string
	 */
	public function sc_analisis( $atts ) {
		$atts = $this->fusionar(
			array_merge(
				$this->atts_canal(),
				array(
					'view' => '',
					'modo' => 'ambos',
				)
			),
			$atts,
			'urkunina_analisis'
		);

		$modo = UHP_Security::clave( $atts['modo'] );

		// Cada modo es una combinación de las piezas sueltas. Se mantiene
		// como atajo para quien quiera el bloque completo sin componerlo.
		$combinaciones = array(
			'descripcion'  => array( 'descripcion' ),
			'analisis'     => array( 'interpretacion' ),
			'descriptivo'  => array( 'resumen' ),
			'cuantitativo' => array( 'cifras' ),
			'ambos'        => array( 'descripcion', 'resumen', 'cifras' ),
			'completo'     => array( 'descripcion', 'interpretacion', 'resumen', 'cifras' ),
		);
		$partes = isset( $combinaciones[ $modo ] ) ? $combinaciones[ $modo ] : $combinaciones['ambos'];

		return $this->bloque_texto( $atts, $partes, 'uhp-texto-grupo' );
	}

	/* ================================================================= */
	/* Piezas de texto sueltas                                           */
	/*                                                                   */
	/* Cada texto de una vista es su propio shortcode para poder          */
	/* maquetarlo por libre: el gráfico va en una columna y su lectura    */
	/* en otra, o el texto abre la sección y el gráfico la cierra.        */
	/* Todos se renderizan en servidor —el contenido llega en el HTML,    */
	/* sin petición ni parpadeo— y funcionan sin JavaScript.              */
	/* ================================================================= */

	/**
	 * [urkunina_titulo] — nombre de la vista.
	 *
	 * @param array $atts Atributos del shortcode.
	 * @return string
	 */
	public function sc_titulo( $atts ) {
		$atts = $this->fusionar(
			array_merge(
				$this->atts_canal(),
				array(
					'view'      => '',
					'etiqueta'  => 'h3',
				)
			),
			$atts,
			'urkunina_titulo'
		);

		// Solo encabezados y párrafo: la etiqueta la elige quien maqueta,
		// pero no puede introducir marcado arbitrario.
		$etiqueta = UHP_Security::clave( $atts['etiqueta'] );
		if ( ! in_array( $etiqueta, array( 'h2', 'h3', 'h4', 'h5', 'p' ), true ) ) {
			$etiqueta = 'h3';
		}

		$canal = $this->canal( $atts );
		$lista = $canal ? $canal['vistas'] : array( $this->vista_o_defecto( $atts['view'] ) );

		if ( ! $canal && ! UHP_Views::existe( $lista[0] ) ) {
			return $this->aviso( __( 'La vista solicitada no existe.', 'urkunina-5000' ) );
		}

		UHP_Estilos::encolar_fuentes();
		wp_enqueue_style( UHP_Assets::P . 'base' );
		if ( $canal ) {
			wp_enqueue_script( UHP_Assets::P . 'grupo' );
		}

		$estilo = esc_attr( UHP_Estilos::inline( $atts ) );
		$html   = '';
		foreach ( $lista as $vista ) {
			$meta = UHP_Views::meta( $vista );
			$uno  = sprintf(
				'<%1$s class="uhp uhp-titulo" style="%2$s">%3$s</%1$s>',
				$etiqueta,
				$estilo,
				esc_html( $meta['name'] )
			);
			$html .= $canal
				? $this->panel( $canal['canal'], $vista, $vista === $canal['activa'], $uno )
				: $uno;
		}
		return $html;
	}

	/**
	 * [urkunina_descripcion] — qué muestra el gráfico y cómo leerlo.
	 *
	 * @param array $atts Atributos del shortcode.
	 * @return string
	 */
	public function sc_descripcion( $atts ) {
		return $this->bloque_texto( $atts, array( 'descripcion' ), '', 'urkunina_descripcion' );
	}

	/**
	 * [urkunina_interpretacion] — qué significa lo que se ve.
	 *
	 * @param array $atts Atributos del shortcode.
	 * @return string
	 */
	public function sc_interpretacion( $atts ) {
		return $this->bloque_texto( $atts, array( 'interpretacion' ), '', 'urkunina_interpretacion' );
	}

	/**
	 * [urkunina_resumen] — lectura automática del hallazgo principal.
	 *
	 * @param array $atts Atributos del shortcode.
	 * @return string
	 */
	public function sc_resumen( $atts ) {
		return $this->bloque_texto( $atts, array( 'resumen' ), '', 'urkunina_resumen' );
	}

	/**
	 * [urkunina_cifras] — cifras de apoyo redactadas a partir de los datos.
	 *
	 * @param array $atts Atributos del shortcode.
	 * @return string
	 */
	public function sc_cifras( $atts ) {
		return $this->bloque_texto( $atts, array( 'cifras' ), '', 'urkunina_cifras' );
	}

	/**
	 * [urkunina_fuente] — atribución de la fuente de la vista.
	 *
	 * @param array $atts Atributos del shortcode.
	 * @return string
	 */
	public function sc_fuente( $atts ) {
		return $this->bloque_texto( $atts, array( 'fuente' ), '', 'urkunina_fuente' );
	}

	/**
	 * Renderiza una o varias piezas de texto de una vista.
	 *
	 * @param array    $atts   Atributos del shortcode.
	 * @param string[] $partes Piezas a pintar, en orden.
	 * @param string   $clase  Clase extra del contenedor.
	 * @param string   $tag    Nombre del shortcode, para shortcode_atts.
	 * @return string
	 */
	private function bloque_texto( $atts, $partes, $clase = '', $tag = 'urkunina_analisis' ) {
		$atts = $this->fusionar(
			array_merge(
				$this->atts_canal(),
				array(
					'view' => '',
					'modo' => '',
				)
			),
			$atts,
			$tag
		);

		// Agrupado: se imprime un panel por vista y el selector enseña el
		// que toque. Cada panel se compone con esta misma función, sin los
		// atributos de canal, de modo que hay un solo camino de render.
		$canal = $this->canal( $atts );
		if ( $canal ) {
			wp_enqueue_script( UHP_Assets::P . 'grupo' );
			$html = '';
			foreach ( $canal['vistas'] as $vista ) {
				$suelto         = $atts;
				$suelto['view'] = $vista;
				unset( $suelto['grupo'], $suelto['views'], $suelto['canal'] );

				$html .= $this->panel(
					$canal['canal'],
					$vista,
					$vista === $canal['activa'],
					$this->bloque_texto( $suelto, $partes, $clase, $tag )
				);
			}
			return $html;
		}

		$vista = $this->vista_o_defecto( $atts['view'] );
		if ( ! UHP_Views::existe( $vista ) ) {
			return $this->aviso(
				sprintf(
					/* translators: %s: identificador de vista solicitado. */
					__( 'La vista «%s» no existe. Consulte el catálogo en URKUNINA 5000 → Gráficos.', 'urkunina-5000' ),
					$vista
				)
			);
		}

		$v      = UHP_Views::obtener( $vista );
		$textos = array(
			'descripcion'    => array( $v['descripcion_larga'], 'uhp-texto--descripcion', 'p' ),
			'interpretacion' => array( $v['analisis_largo'], 'uhp-texto--interpretacion', 'p' ),
			'resumen'        => array( $v['analisis']['descriptivo'], 'uhp-texto--resumen', 'p' ),
			'cifras'         => array( $v['analisis']['cuantitativo'], 'uhp-texto--cifras', 'p' ),
			'fuente'         => array(
				$v['fuente'] ? sprintf(
					/* translators: %s: fuente del dato. */
					__( 'Fuente: %s', 'urkunina-5000' ),
					$v['fuente']
				) : '',
				'uhp-texto--fuente',
				'p',
			),
		);

		$html = '';
		foreach ( $partes as $parte ) {
			if ( empty( $textos[ $parte ][0] ) ) {
				continue;
			}
			$html .= sprintf(
				'<%1$s class="uhp-texto %2$s">%3$s</%1$s>',
				$textos[ $parte ][2],
				esc_attr( $textos[ $parte ][1] ),
				esc_html( $textos[ $parte ][0] )
			);
		}

		if ( '' === $html ) {
			return '';
		}

		UHP_Estilos::encolar_fuentes();
		wp_enqueue_style( UHP_Assets::P . 'base' );

		return sprintf(
			'<div class="%1$s" style="%2$s">%3$s</div>',
			esc_attr( trim( 'uhp ' . $clase ) ),
			esc_attr( UHP_Estilos::inline( $atts ) ),
			$html // Ya escapado pieza a pieza.
		);
	}

	/* ================================================================= */
	/* [urkunina_mapa]                                                   */
	/* ================================================================= */

	/**
	 * Mapa coroplético de Nariño sobre OpenStreetMap.
	 *
	 * @param array $atts Atributos del shortcode.
	 * @return string
	 */
	public function sc_mapa( $atts ) {
		$atts = $this->fusionar(
			array(
				'titulo'    => __( 'Nariño — resultados por municipio', 'urkunina-5000' ),
				'indicador' => 'lpm',
				'teselas'   => 'osm',
				'lat'       => 1.30,
				'lon'       => -77.60,
				'zoom'      => 8,
				'alto'      => '',
				'selector'  => 'si',
			),
			$atts,
			'urkunina_mapa'
		);

		UHP_Estilos::encolar_fuentes();
		wp_enqueue_style( UHP_Assets::P . 'mapa' );
		UHP_Assets::encolar_libreria( 'leaflet' );
		wp_enqueue_script( UHP_Assets::P . 'mapa' );

		$lat = (float) $atts['lat'];
		$lon = (float) $atts['lon'];
		if ( ! UHP_Security::validar_bbox( $lat, $lon ) ) {
			$lat = 1.30;
			$lon = -77.60;
		}

		$id          = $this->id( 'uhpm' );
		$indicadores = UHP_Rest::indicadores_mapa();
		$indicador   = UHP_Security::clave( $atts['indicador'] );
		if ( ! isset( $indicadores[ $indicador ] ) ) {
			$indicador = 'lpm';
		}

		$estilo = UHP_Estilos::inline( $atts );
		if ( '' !== $atts['alto'] ) {
			$estilo .= '--uhp-mapa-alto:' . UHP_Estilos::sanitizar_css( $atts['alto'] ) . ';';
		}

		ob_start();
		?>
		<div id="<?php echo esc_attr( $id ); ?>"
			class="uhp uhp-mapa"
			style="<?php echo esc_attr( $estilo ); ?>"
			data-uhp-mapa
			data-indicador="<?php echo esc_attr( $indicador ); ?>"
			data-teselas="<?php echo esc_attr( UHP_Security::clave( $atts['teselas'] ) ); ?>"
			data-lat="<?php echo esc_attr( $lat ); ?>"
			data-lon="<?php echo esc_attr( $lon ); ?>"
			data-zoom="<?php echo esc_attr( (int) $atts['zoom'] ); ?>">

			<div class="uhp-mapa__cab">
				<h3 class="uhp-mapa__titulo"><?php echo esc_html( $atts['titulo'] ); ?></h3>
				<?php if ( 'no' !== $atts['selector'] ) : ?>
					<div class="uhp-mapa__control">
						<label class="uhp-mapa__label" for="<?php echo esc_attr( $id . '-ind' ); ?>">
							<?php esc_html_e( 'Indicador', 'urkunina-5000' ); ?>
						</label>
						<select class="uhp-mapa__select" id="<?php echo esc_attr( $id . '-ind' ); ?>" data-uhp-indicador>
							<?php foreach ( $indicadores as $clave => $meta ) : ?>
								<option value="<?php echo esc_attr( $clave ); ?>" <?php selected( $clave, $indicador ); ?>>
									<?php echo esc_html( $meta['etiqueta'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
				<?php endif; ?>
			</div>

			<div class="uhp-mapa__lienzo"></div>
			<?php echo $this->skeleton( __( 'Cargando el mapa de Nariño…', 'urkunina-5000' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<p class="uhp-mapa__pie">
				<?php esc_html_e( 'Cartografía: © colaboradores de OpenStreetMap (ODbL). Geometría municipal: marco geoestadístico del DANE. Datos: proyecto URKUNINA 5000 (BPIN 2015000100064).', 'urkunina-5000' ); ?>
			</p>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ================================================================= */
	/* [urkunina_geomapa]                                                */
	/* ================================================================= */

	/**
	 * Mapa coroplético del departamento dibujado con D3plus Geomap.
	 *
	 * Es el gráfico de mapa del módulo de Gráficos, no un visor: se dibuja
	 * con el mismo motor que el resto de vistas y comparte su chrome. Para
	 * navegar el territorio —arrastrar, acercar, consultar municipio a
	 * municipio— está [urkunina_mapa], que va sobre Leaflet.
	 *
	 * La capa base de teselas se enciende y se apaga con `teselas`: sin
	 * ella queda una plancha limpia, que es lo que pide la identidad de la
	 * entidad para una ficha o un impreso; con ella se sitúan mejor los
	 * municipios sobre el relieve.
	 *
	 * @param array $atts Atributos del shortcode.
	 * @return string
	 */
	public function sc_geomapa( $atts ) {
		$atts = $this->fusionar(
			array(
				'view'      => '',
				'indicador' => 'lpm',
				'serie'     => '',
				'titulo'    => '',
				'alto'      => '',
				'tema'      => 'claro',
				'teselas'   => 'no',
				'capa'      => '',
				'zoom'      => 'si',
				'leyenda'   => 'si',
				'etiquetas' => 'no',
			),
			$atts,
			'urkunina_geomapa'
		);

		$vista = UHP_Security::clave( $atts['view'] );
		if ( '' !== $vista && ! UHP_Views::es_territorial( $vista ) ) {
			$nombres = wp_list_pluck( UHP_Views::territoriales(), 'id' );
			return $this->aviso(
				sprintf(
					/* translators: 1: vista solicitada; 2: lista de vistas válidas. */
					__( 'La vista «%1$s» no nombra un territorio con geometría, de modo que no puede dibujarse sobre el mapa. Vistas territoriales disponibles: %2$s.', 'urkunina-5000' ),
					$vista,
					implode( ', ', $nombres )
				)
			);
		}

		$indicadores = UHP_Rest::indicadores_mapa();
		$indicador   = UHP_Security::clave( $atts['indicador'] );
		if ( '' === $vista && ! isset( $indicadores[ $indicador ] ) ) {
			$indicador = 'lpm';
		}

		UHP_Estilos::encolar_fuentes();
		wp_enqueue_style( UHP_Assets::P . 'geomapa' );
		UHP_Assets::encolar_libreria( 'd3plus' );
		wp_enqueue_script( UHP_Assets::P . 'geomapa' );

		$id      = $this->id( 'uhpgeo' );
		$teselas = $this->afirmativo( $atts['teselas'] );
		$capa    = UHP_Security::clave( $atts['capa'] );
		if ( ! in_array( $capa, array( 'claro', 'oscuro', 'osm' ), true ) ) {
			$capa = '';
		}

		// El nivel lo decide la vista, no quien maqueta: una vista subregional
		// dibujada sobre municipios (o al revés) no cruzaría con nada.
		$nivel  = ( '' !== $vista ) ? UHP_Views::nivel( $vista ) : 'municipio';

		// Vista partida en varias series —dos indicadores por subregión, por
		// ejemplo—: un coropleto solo puede pintar una. Si no se pide
		// ninguna, se dibuja la primera y el título lo dice.
		$series = ( '' !== $vista ) ? UHP_Views::series( $vista ) : array();
		$serie  = sanitize_text_field( (string) $atts['serie'] );
		if ( ! empty( $series ) && ! in_array( $serie, $series, true ) ) {
			$serie = $series[0];
		}
		$clases = 'uhp uhp-geo uhp-geo--' . $nivel . ( 'oscuro' === $atts['tema'] ? ' uhp-geo--oscuro' : '' );

		$estilo = UHP_Estilos::inline( $atts );
		if ( '' !== $atts['alto'] ) {
			$estilo .= '--uhp-geo-alto:' . UHP_Estilos::sanitizar_css( $atts['alto'] ) . ';';
		}

		// El título de servidor evita que la tarjeta arranque sin encabezado
		// mientras llega la respuesta; el JavaScript solo lo rellena si se
		// dejó vacío.
		$rotulo = $atts['titulo'];
		if ( '' === $rotulo && 'no' !== $atts['titulo'] ) {
			if ( '' !== $vista ) {
				$rotulo = UHP_Views::meta( $vista )['name'];
				if ( '' !== $serie ) {
					$rotulo .= ' · ' . $serie;
				}
			} else {
				$rotulo = $indicadores[ $indicador ]['etiqueta'];
			}
		}

		ob_start();
		?>
		<figure id="<?php echo esc_attr( $id ); ?>"
			class="<?php echo esc_attr( $clases ); ?>"
			style="<?php echo esc_attr( $estilo ); ?>"
			data-uhp-geomapa
			data-view="<?php echo esc_attr( $vista ); ?>"
			data-indicador="<?php echo esc_attr( '' === $vista ? $indicador : '' ); ?>"
			data-nivel="<?php echo esc_attr( $nivel ); ?>"
			data-serie="<?php echo esc_attr( $serie ); ?>"
			data-tema="<?php echo esc_attr( 'oscuro' === $atts['tema'] ? 'oscuro' : 'claro' ); ?>"
			data-teselas="<?php echo $teselas ? '1' : '0'; ?>"
			data-capa="<?php echo esc_attr( $capa ); ?>"
			data-zoom="<?php echo 'no' === $atts['zoom'] ? '0' : '1'; ?>"
			data-leyenda="<?php echo 'no' === $atts['leyenda'] ? '0' : '1'; ?>"
			data-etiquetas="<?php echo 'si' === $atts['etiquetas'] ? '1' : '0'; ?>">

			<?php if ( 'no' !== $atts['titulo'] ) : ?>
				<figcaption class="uhp-geo__titulo"><?php echo esc_html( $rotulo ); ?></figcaption>
			<?php endif; ?>

			<div class="uhp-geo__lienzo"></div>

			<?php if ( 'no' !== $atts['leyenda'] ) : ?>
				<div class="uhp-geo__leyenda"></div>
			<?php endif; ?>

			<?php echo $this->skeleton( __( 'Cargando el mapa del departamento…', 'urkunina-5000' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</figure>
		<?php
		return ob_get_clean();
	}

	/* ================================================================= */
	/* [urkunina_kpi]                                                    */
	/* ================================================================= */

	/**
	 * Tarjetas con las cifras principales del proyecto.
	 *
	 * A diferencia del resto, se renderiza en servidor: son seis cifras
	 * fijas que conviene que estén en el HTML para SEO y para quien
	 * navegue sin JavaScript.
	 *
	 * @param array $atts Atributos del shortcode.
	 * @return string
	 */
	public function sc_kpi( $atts ) {
		$atts = $this->fusionar(
			array(
				'solo'  => '',
				'notas' => 'si',
			),
			$atts,
			'urkunina_kpi'
		);

		UHP_Estilos::encolar_fuentes();
		wp_enqueue_style( UHP_Assets::P . 'base' );

		$kpis = UHP_Rest::kpis();

		if ( '' !== $atts['solo'] ) {
			$pedidos = array_filter( array_map( array( UHP_Security::class, 'clave' ), explode( ',', $atts['solo'] ) ) );
			$kpis    = array_values(
				array_filter(
					$kpis,
					static function ( $k ) use ( $pedidos ) {
						return in_array( $k['clave'], $pedidos, true );
					}
				)
			);
		}

		if ( ! $kpis ) {
			return $this->aviso( __( 'No hay cifras disponibles: revise los archivos de datos del plugin.', 'urkunina-5000' ) );
		}

		ob_start();
		?>
		<div class="uhp uhp-kpi" style="<?php echo esc_attr( UHP_Estilos::inline( $atts ) ); ?>">
			<?php foreach ( $kpis as $k ) : ?>
				<div class="uhp-kpi__tarjeta">
					<span class="uhp-kpi__valor"><?php echo esc_html( $this->formato( $k['valor'], $k['formato'] ) ); ?></span>
					<span class="uhp-kpi__etiqueta"><?php echo esc_html( $k['etiqueta'] ); ?></span>
					<?php if ( 'no' !== $atts['notas'] && ! empty( $k['nota'] ) ) : ?>
						<span class="uhp-kpi__nota"><?php echo esc_html( $k['nota'] ); ?></span>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ================================================================= */
	/* [urkunina_tabla]                                                  */
	/* ================================================================= */

	/**
	 * Tabla de datos de una vista, renderizada en servidor.
	 *
	 * @param array $atts Atributos del shortcode.
	 * @return string
	 */
	public function sc_tabla( $atts ) {
		$atts = $this->fusionar(
			array_merge(
				$this->atts_canal(),
				array(
					'view'   => '',
					'titulo' => '',
					'limite' => 0,
				)
			),
			$atts,
			'urkunina_tabla'
		);

		// Agrupada: una tabla por vista, y el selector enseña la que toque.
		// Se compone cada una con esta misma función para no duplicar el
		// render ni la lógica de columnas.
		$canal = $this->canal( $atts );
		if ( $canal ) {
			wp_enqueue_script( UHP_Assets::P . 'grupo' );
			$html = '';
			foreach ( $canal['vistas'] as $v ) {
				$suelto         = $atts;
				$suelto['view'] = $v;
				unset( $suelto['grupo'], $suelto['views'], $suelto['canal'] );

				$html .= $this->panel(
					$canal['canal'],
					$v,
					$v === $canal['activa'],
					$this->sc_tabla( $suelto )
				);
			}
			return $html;
		}

		$vista = $this->vista_o_defecto( $atts['view'], 'prev_subregion_lpm' );
		if ( ! UHP_Views::existe( $vista ) ) {
			return $this->aviso( __( 'La vista solicitada no existe.', 'urkunina-5000' ) );
		}

		UHP_Estilos::encolar_fuentes();
		wp_enqueue_style( UHP_Assets::P . 'base' );

		$v     = UHP_Views::obtener( $vista );
		$filas = $v['data'];
		if ( ! $filas ) {
			return $this->aviso( __( 'Esta vista no tiene datos disponibles.', 'urkunina-5000' ) );
		}

		$limite = (int) $atts['limite'];
		if ( $limite > 0 ) {
			$filas = array_slice( $filas, 0, $limite );
		}
		$columnas = array_keys( $filas[0] );

		ob_start();
		?>
		<div class="uhp" style="<?php echo esc_attr( UHP_Estilos::inline( $atts ) ); ?>">
			<div class="uhp-tabla-caja">
				<table class="uhp-tabla">
					<caption class="uhp-sr">
						<?php echo esc_html( '' !== $atts['titulo'] ? $atts['titulo'] : $v['name'] ); ?>
					</caption>
					<thead>
						<tr>
							<?php foreach ( $columnas as $c ) : ?>
								<th scope="col"><?php echo esc_html( $this->etiqueta_campo( $c ) ); ?></th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $filas as $fila ) : ?>
							<tr>
								<?php foreach ( $columnas as $c ) : ?>
									<?php $valor = isset( $fila[ $c ] ) ? $fila[ $c ] : ''; ?>
									<td class="<?php echo is_numeric( $valor ) && ! is_string( $valor ) ? 'uhp-num' : ''; ?>">
										<?php echo esc_html( $this->celda( $valor ) ); ?>
									</td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php if ( ! empty( $v['fuente'] ) ) : ?>
				<p class="uhp-fuentes"><?php echo esc_html( 'Fuente: ' . $v['fuente'] ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ================================================================= */
	/* [urkunina_ficha]                                                  */
	/* ================================================================= */

	/**
	 * Ficha resumen del proyecto.
	 *
	 * @param array $atts Atributos del shortcode.
	 * @return string
	 */
	public function sc_ficha( $atts ) {
		$atts = $this->fusionar( array( 'titulo' => '' ), $atts, 'urkunina_ficha' );

		UHP_Estilos::encolar_fuentes();
		wp_enqueue_style( UHP_Assets::P . 'base' );

		$campos = array(
			__( 'Nombre del proyecto', 'urkunina-5000' )   => UHP_Datos::valor( 'proyecto', 'identificacion.nombre_completo', '' ),
			__( 'Código BPIN', 'urkunina-5000' )           => UHP_Datos::valor( 'proyecto', 'identificacion.bpin', '' ),
			__( 'Formulador de la ficha MGA', 'urkunina-5000' ) => UHP_Datos::valor( 'proyecto', 'identificacion.formulador_ficha_mga', '' ),
			__( 'Instancia de aprobación', 'urkunina-5000' ) => UHP_Datos::valor( 'proyecto', 'aprobacion.instancia', '' ),
			__( 'Acuerdo', 'urkunina-5000' )               => trim(
				UHP_Datos::valor( 'proyecto', 'aprobacion.acuerdo', '' ) . ' — ' .
				UHP_Datos::valor( 'proyecto', 'aprobacion.fecha_acuerdo', '' ),
				' —'
			),
			__( 'Presupuesto total', 'urkunina-5000' )     => UHP_Analisis::pesos( UHP_Datos::valor( 'proyecto', 'financiacion.presupuesto_total', 0 ) ),
			__( 'Inicio de ejecución', 'urkunina-5000' )   => UHP_Datos::valor( 'proyecto', 'ejecucion.fecha_inicio', '' ),
			__( 'Fin del trabajo de campo', 'urkunina-5000' ) => UHP_Datos::valor( 'proyecto', 'ejecucion.fecha_fin_trabajo_campo', '' ),
			__( 'Estado', 'urkunina-5000' )                => UHP_Datos::valor( 'proyecto', 'ejecucion.estado', '' ),
			__( 'Ejecución física', 'urkunina-5000' )      => UHP_Analisis::pct( UHP_Datos::valor( 'proyecto', 'ejecucion.ejecucion_fisica_porcentaje', 0 ) ),
			__( 'Ejecución financiera', 'urkunina-5000' )  => UHP_Analisis::pct( UHP_Datos::valor( 'proyecto', 'ejecucion.ejecucion_financiera_porcentaje', 0 ) ),
		);

		$origen = UHP_Datos::valor( 'proyecto', 'identificacion.origen_del_nombre', array() );

		ob_start();
		?>
		<div class="uhp uhp-ficha" style="<?php echo esc_attr( UHP_Estilos::inline( $atts ) ); ?>">
			<h3 class="uhp-ficha__titulo">
				<?php echo esc_html( '' !== $atts['titulo'] ? $atts['titulo'] : __( 'Ficha del proyecto', 'urkunina-5000' ) ); ?>
			</h3>
			<?php if ( ! empty( $origen['vocablo'] ) ) : ?>
				<p class="uhp-ficha__sub">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: vocablo, 2: significado, 3: referencia geográfica. */
							__( '«%1$s» significa «%2$s» y alude al %3$s.', 'urkunina-5000' ),
							$origen['vocablo'],
							isset( $origen['significado'] ) ? $origen['significado'] : '',
							isset( $origen['referencia'] ) ? $origen['referencia'] : ''
						)
					);
					?>
				</p>
			<?php endif; ?>
			<dl class="uhp-ficha__dl">
				<?php foreach ( $campos as $etiqueta => $valor ) : ?>
					<?php if ( '' === $valor || null === $valor ) { continue; } ?>
					<dt><?php echo esc_html( $etiqueta ); ?></dt>
					<dd><?php echo esc_html( $valor ); ?></dd>
				<?php endforeach; ?>
			</dl>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ================================================================= */
	/* [urkunina_dato]                                                   */
	/* ================================================================= */

	/**
	 * Un solo valor del conjunto de datos, para intercalar en un párrafo.
	 *
	 * Ejemplo: [urkunina_dato archivo="tamizaje" ruta="infeccion_h_pylori.positivos.porcentaje" formato="porcentaje"]
	 *
	 * @param array $atts Atributos del shortcode.
	 * @return string
	 */
	public function sc_dato( $atts ) {
		$atts = $this->fusionar(
			array(
				'archivo' => '',
				'ruta'    => '',
				'formato' => 'auto',
			),
			$atts,
			'urkunina_dato'
		);

		$clave = UHP_Security::clave( $atts['archivo'] );
		$reg   = UHP_Datos::registro();
		if ( ! isset( $reg[ $clave ] ) ) {
			return '';
		}

		// La ruta solo puede contener claves: nada de rutas de sistema.
		$ruta = preg_replace( '/[^A-Za-z0-9_.\-]/', '', (string) $atts['ruta'] );
		if ( '' === $ruta ) {
			return '';
		}

		$valor = UHP_Datos::valor( $clave, $ruta, null );
		if ( null === $valor || is_array( $valor ) ) {
			return '';
		}

		return '<span class="uhp-dato">' . esc_html( $this->formato( $valor, $atts['formato'] ) ) . '</span>';
	}

	/* ================================================================= */
	/* Utilidades                                                        */
	/* ================================================================= */

	/**
	 * Fusiona los atributos con los valores por defecto aplicando shortcode_atts.
	 *
	 * @param array  $def  Valores por defecto.
	 * @param array  $atts Atributos recibidos.
	 * @param string $tag  Nombre del shortcode.
	 * @return array
	 */
	private function fusionar( $def, $atts, $tag ) {
		$atts = shortcode_atts( $def, is_array( $atts ) ? $atts : array(), $tag );
		foreach ( $atts as $k => $v ) {
			if ( is_string( $v ) ) {
				$atts[ $k ] = sanitize_text_field( $v );
			}
		}
		return $atts;
	}

	/**
	 * Identificador único para el contenedor de una instancia.
	 *
	 * @param string $prefijo Prefijo legible.
	 * @return string
	 */
	/**
	 * ¿El atributo dice que sí?
	 *
	 * Los shortcodes se escriben a mano en el editor y llegan con «si»,
	 * «sí», «1» o «true» indistintamente. Aceptarlos todos evita que una
	 * tilde de más apague una capa sin decir por qué.
	 *
	 * @param mixed $valor Valor del atributo.
	 * @return bool
	 */
	/**
	 * Resuelve el canal de un shortcode agrupado.
	 *
	 * Un «canal» es un grupo de piezas de la página —título, descripción,
	 * análisis, tabla, gráfico— que obedecen al mismo selector. Las piezas
	 * no se conocen entre sí: cada una declara a qué canal pertenece y el
	 * selector les habla por ese nombre, que es lo que permite maquetarlas
	 * por libre y en cualquier orden.
	 *
	 * Hay dos formas de nombrar el conjunto de vistas:
	 *
	 *   grupo="Prevalencia"   — todas las vistas de esa pestaña
	 *   views="a,b,c"         — una lista a mano, en ese orden
	 *
	 * `views` gana sobre `grupo` cuando se dan los dos. Si no se da
	 * ninguno, el shortcode no está agrupado y devuelve null: sigue
	 * comportándose como siempre, con su única vista.
	 *
	 * @param array $atts Atributos ya fusionados del shortcode.
	 * @return array{canal:string,vistas:string[],activa:string}|null
	 */
	private function canal( $atts ) {
		$grupo = isset( $atts['grupo'] ) ? trim( (string) $atts['grupo'] ) : '';
		$lista = isset( $atts['views'] ) ? trim( (string) $atts['views'] ) : '';

		if ( '' === $grupo && '' === $lista ) {
			return null;
		}

		$vistas = array();
		if ( '' !== $lista ) {
			foreach ( explode( ',', $lista ) as $v ) {
				$v = UHP_Security::clave( $v );
				// Una vista inexistente se descarta en silencio aquí, pero
				// el selector avisa si no queda ninguna: fallar entero por
				// una errata dejaría la página sin nada que leer.
				if ( '' !== $v && UHP_Views::existe( $v ) && ! in_array( $v, $vistas, true ) ) {
					$vistas[] = $v;
				}
			}
		} else {
			$vistas = UHP_Views::de_grupo( $grupo );
		}

		if ( ! $vistas ) {
			return null;
		}

		// El canal explícito permite dos selectores independientes sobre el
		// mismo grupo en una misma página. Sin él, el nombre del grupo basta
		// y evita tener que inventarse uno.
		$canal = isset( $atts['canal'] ) ? sanitize_title( (string) $atts['canal'] ) : '';
		if ( '' === $canal ) {
			$canal = sanitize_title( '' !== $grupo ? $grupo : implode( '-', array_slice( $vistas, 0, 3 ) ) );
		}
		if ( '' === $canal ) {
			$canal = 'uhp';
		}

		// La vista que arranca visible: la que pida `view` si pertenece al
		// conjunto, y si no la primera.
		$activa = isset( $atts['view'] ) ? UHP_Security::clave( $atts['view'] ) : '';
		if ( ! in_array( $activa, $vistas, true ) ) {
			$activa = $vistas[0];
		}

		return array(
			'canal'  => $canal,
			'vistas' => $vistas,
			'activa' => $activa,
		);
	}

	/**
	 * Atributos comunes que hacen agrupable a un shortcode.
	 *
	 * @return array<string,string>
	 */
	private function atts_canal() {
		return array(
			'grupo' => '',
			'views' => '',
			'canal' => '',
		);
	}

	/**
	 * Envuelve el HTML de una vista como panel de un canal.
	 *
	 * Se imprimen TODOS los paneles y se ocultan los que no están
	 * seleccionados, en vez de pedirlos al cambiar. Son párrafos y tablas,
	 * no consultas: llegan ya en el HTML, el cambio es instantáneo y sin
	 * JavaScript se lee igualmente la vista activa.
	 *
	 * @param string $canal  Nombre del canal.
	 * @param string $vista  Identificador de la vista.
	 * @param bool   $activa Si es la vista que arranca visible.
	 * @param string $html   Contenido ya escapado del panel.
	 * @return string
	 */
	private function panel( $canal, $vista, $activa, $html ) {
		if ( '' === $html ) {
			return '';
		}
		return sprintf(
			'<div class="uhp-panel" data-uhp-panel data-canal="%1$s" data-vista="%2$s"%3$s>%4$s</div>',
			esc_attr( $canal ),
			esc_attr( $vista ),
			$activa ? '' : ' hidden',
			$html
		);
	}

	/**
	 * Vista pedida, o la de ejemplo si el shortcode no nombró ninguna.
	 *
	 * El valor por defecto de `view` se resuelve AQUÍ y no en la lista de
	 * atributos por una razón concreta: en un shortcode agrupado, `view`
	 * elige cuál de las vistas del grupo arranca visible. Si el valor por
	 * defecto viviera en la lista de atributos, una vista que casualmente
	 * perteneciera al grupo actuaría como si el autor la hubiera elegido,
	 * y el grupo arrancaría por una vista que nadie pidió.
	 *
	 * @param string $valor   Valor del atributo `view`, ya saneado.
	 * @param string $defecto Vista a usar cuando no se nombró ninguna.
	 * @return string
	 */
	private function vista_o_defecto( $valor, $defecto = 'tamizaje_hp' ) {
		$v = UHP_Security::clave( $valor );
		return '' !== $v ? $v : $defecto;
	}

	private function afirmativo( $valor ) {
		return in_array(
			strtolower( trim( (string) $valor ) ),
			array( 'si', 'sí', '1', 'true', 'on', 'yes' ),
			true
		);
	}

	private function id( $prefijo ) {
		self::$contador++;
		return $prefijo . '-' . self::$contador;
	}

	/**
	 * Marca del esqueleto de carga.
	 *
	 * @param string $mensaje Texto mostrado.
	 * @return string HTML ya escapado.
	 */
	private function skeleton( $mensaje ) {
		return '<div class="uhp-skeleton" role="status" aria-live="polite">'
			. '<span class="uhp-skeleton__giro" aria-hidden="true"></span>'
			. '<span class="uhp-skeleton__txt">' . esc_html( $mensaje ) . '</span>'
			. '</div>';
	}

	/**
	 * Aviso visible en lugar del componente cuando la configuración falla.
	 *
	 * @param string $mensaje Texto del aviso.
	 * @return string HTML ya escapado.
	 */
	private function aviso( $mensaje ) {
		return '<div class="uhp"><div class="uhp-error" role="alert">'
			. '<p class="uhp-error__txt">' . esc_html( $mensaje ) . '</p>'
			. '</div></div>';
	}

	/**
	 * Formatea un valor según el tipo indicado.
	 *
	 * @param mixed  $valor Valor.
	 * @param string $tipo  auto | entero | porcentaje | pesos.
	 * @return string
	 */
	private function formato( $valor, $tipo ) {
		switch ( $tipo ) {
			case 'porcentaje':
				return UHP_Analisis::pct( $valor );
			case 'entero':
				return number_format( (float) $valor, 0, ',', '.' );
			case 'pesos':
				return UHP_Analisis::pesos( $valor );
			default:
				return is_numeric( $valor ) ? UHP_Analisis::num( $valor ) : (string) $valor;
		}
	}

	/**
	 * Contenido legible de una celda de tabla.
	 *
	 * @param mixed $valor Valor crudo.
	 * @return string
	 */
	private function celda( $valor ) {
		if ( is_bool( $valor ) ) {
			return $valor ? '✓' : '—';
		}
		if ( is_numeric( $valor ) && ! is_string( $valor ) ) {
			return UHP_Analisis::num( $valor );
		}
		return (string) $valor;
	}

	/**
	 * Etiqueta legible del nombre de un campo.
	 *
	 * @param string $campo Nombre del campo.
	 * @return string
	 */
	private function etiqueta_campo( $campo ) {
		$mapa = array(
			'zona'         => __( 'Zona de riesgo', 'urkunina-5000' ),
			'incidencia'   => __( 'Incidencia (por 100.000)', 'urkunina-5000' ),
			'ambito'       => __( 'Ámbito', 'urkunina-5000' ),
			'tasa'         => __( 'Tasa (por 100.000)', 'urkunina-5000' ),
			'municipio'    => __( 'Municipio', 'urkunina-5000' ),
			'mortalidad'   => __( 'Mortalidad (por 100.000)', 'urkunina-5000' ),
			'resultado'    => __( 'Resultado', 'urkunina-5000' ),
			'personas'     => __( 'Personas', 'urkunina-5000' ),
			'porcentaje'   => __( 'Porcentaje', 'urkunina-5000' ),
			'indicador'    => __( 'Indicador', 'urkunina-5000' ),
			'positivos'    => __( 'Positivos', 'urkunina-5000' ),
			'negativos'    => __( 'Negativos', 'urkunina-5000' ),
			'prevalencia'  => __( 'Prevalencia (%)', 'urkunina-5000' ),
			'subregion'    => __( 'Subregión', 'urkunina-5000' ),
			'valor'        => __( 'Valor', 'urkunina-5000' ),
			'categoria'    => __( 'Categoría', 'urkunina-5000' ),
			'tipo'         => __( 'Tipo', 'urkunina-5000' ),
			'cantidad'     => __( 'Muestras', 'urkunina-5000' ),
			'casos'        => __( 'Casos', 'urkunina-5000' ),
			'estado'       => __( 'Estado', 'urkunina-5000' ),
			'fuente'       => __( 'Fuente', 'urkunina-5000' ),
			'producto'     => __( 'Producto', 'urkunina-5000' ),
			'avance'       => __( 'Avance (%)', 'urkunina-5000' ),
			'meta'         => __( 'Meta', 'urkunina-5000' ),
			'ejecutado'    => __( 'Ejecutado', 'urkunina-5000' ),
			'anio'         => __( 'Año', 'urkunina-5000' ),
			'publicaciones' => __( 'Publicaciones', 'urkunina-5000' ),
			'entidades'    => __( 'Entidades', 'urkunina-5000' ),
			'posicion'     => __( 'Posición', 'urkunina-5000' ),
			'divipola'     => __( 'DIVIPOLA', 'urkunina-5000' ),
			'territorio'   => __( 'Territorio', 'urkunina-5000' ),
			'nivel_riesgo' => __( 'Nivel de riesgo', 'urkunina-5000' ),
			'poblacion'    => __( 'Población predominante', 'urkunina-5000' ),
		);
		if ( isset( $mapa[ $campo ] ) ) {
			return $mapa[ $campo ];
		}
		return ucfirst( str_replace( '_', ' ', $campo ) );
	}
}
