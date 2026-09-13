<?php
/**
 * Módulo de Apariencia: identidad visual de la Gobernación de Nariño.
 *
 * Convierte la opción `uhp_estilo` en variables CSS `--uhp-*` aplicadas al
 * contenedor `.uhp` que envuelve cada shortcode. Los valores por defecto
 * siguen el Manual de Identidad Visual (MIV, junio 2024) y el Manual de
 * sitios web de la entidad: verde institucional #10A13B, amarillo #FFD500 y
 * azul de encabezados #003366, con Hind Madurai como tipografía principal y
 * Nunito Sans para etiquetas.
 *
 * Los colores clínicos (positivo/negativo, zonas de riesgo, escala del mapa)
 * quedan fuera de configuración: codifican significado y su contraste está
 * verificado contra el Anexo 1 de la Resolución 1519 de 2020 (WCAG 2.1 AA).
 *
 * @package Urkunina5000
 */

namespace GobernacionNarino\Urkunina;

defined( 'ABSPATH' ) || exit;

final class UHP_Estilos {

	const HANDLE = 'uhp-base';

	public function __construct() {
		// Prioridad 6: después del registro de assets (5), antes de que los
		// shortcodes encolen (10).
		add_action( 'wp_enqueue_scripts', array( $this, 'inyectar' ), 6 );
	}

	/**
	 * Añade las variables de apariencia a la hoja base ya registrada.
	 */
	public function inyectar() {
		if ( wp_style_is( self::HANDLE, 'registered' ) ) {
			wp_add_inline_style( self::HANDLE, self::css_global() );
		}
	}

	/**
	 * Valores por defecto de la apariencia (identidad institucional).
	 *
	 * @return array
	 */
	public static function por_defecto() {
		return array(
			'verde'      => '#10A13B',
			'verde_osc'  => '#0B7A2C',
			'amarillo'   => '#FFD500',
			'azul'       => '#003366',
			'azul_claro' => '#2E6FA8',
			'tinta'      => '#0F172A',
			'suave'      => '#5B6773',
			'linea'      => '#E2E8F0',
			'superficie' => '#F8FAFC',
			'fondo'      => '#FFFFFF',
			// Sin comillas a propósito: sanitizar_css() las elimina, de modo
			// que entrecomilladas el valor cambiaría al primer guardado en
			// Apariencia. En CSS un nombre de familia de varias palabras es
			// válido sin entrecomillar, así que la pila es la misma.
			'tipografia' => 'Hind Madurai, system-ui, -apple-system, Segoe UI, sans-serif',
			'etiquetas'  => 'Nunito Sans, system-ui, sans-serif',
			'radio'      => '14px',
			'ancho_max'  => '100%',
			'fuentes_cdn' => 1,
		);
	}

	/**
	 * Configuración de apariencia fusionada con los valores por defecto.
	 *
	 * @return array
	 */
	public static function estilo() {
		$cfg = get_option( 'uhp_estilo', array() );
		return wp_parse_args( is_array( $cfg ) ? $cfg : array(), self::por_defecto() );
	}

	/**
	 * Construye el bloque CSS con las variables globales bajo `.uhp`.
	 *
	 * @return string
	 */
	public static function css_global() {
		$e = self::estilo();

		$vars = array(
			'--uhp-verde'      => self::sanitizar_css( $e['verde'] ),
			'--uhp-verde-osc'  => self::sanitizar_css( $e['verde_osc'] ),
			'--uhp-amarillo'   => self::sanitizar_css( $e['amarillo'] ),
			'--uhp-azul'       => self::sanitizar_css( $e['azul'] ),
			'--uhp-azul-claro' => self::sanitizar_css( $e['azul_claro'] ),
			'--uhp-tinta'      => self::sanitizar_css( $e['tinta'] ),
			'--uhp-suave'      => self::sanitizar_css( $e['suave'] ),
			'--uhp-linea'      => self::sanitizar_css( $e['linea'] ),
			'--uhp-superficie' => self::sanitizar_css( $e['superficie'] ),
			'--uhp-fondo'      => self::sanitizar_css( $e['fondo'] ),
			'--uhp-fuente'     => self::sanitizar_css( $e['tipografia'] ),
			'--uhp-fuente-etq' => self::sanitizar_css( $e['etiquetas'] ),
			'--uhp-radio'      => self::sanitizar_css( $e['radio'] ),
			'--uhp-ancho-max'  => self::sanitizar_css( $e['ancho_max'] ),
		);

		$cuerpo = '';
		foreach ( $vars as $k => $v ) {
			$cuerpo .= $k . ':' . $v . ';';
		}
		return '.uhp{' . $cuerpo . '}';
	}

	/**
	 * Genera el estilo en línea de overrides por atributo de shortcode.
	 *
	 * @param array $atts Atributos del shortcode.
	 * @return string CSS listo para un atributo style (sin comillas).
	 */
	public static function inline( $atts ) {
		$map = array(
			'acento' => '--uhp-verde',
			'azul'   => '--uhp-azul',
			'fondo'  => '--uhp-fondo',
			'texto'  => '--uhp-tinta',
			'ancho'  => '--uhp-ancho-max',
			'radio'  => '--uhp-radio',
			'alto'   => '--uhp-alto',
		);

		$out = '';
		foreach ( $map as $att => $var ) {
			if ( isset( $atts[ $att ] ) && '' !== $atts[ $att ] ) {
				$out .= $var . ':' . self::sanitizar_css( $atts[ $att ] ) . ';';
			}
		}
		return $out;
	}

	/**
	 * Sanea un valor para inserción segura en CSS (anti-inyección).
	 *
	 * Conserva funciones legítimas (rgba(), calc(), var(), clamp()) pero
	 * neutraliza las peligrosas (url(), expression(), image-set(),
	 * -moz-binding) y los caracteres que permitirían salir del valor o
	 * inyectar reglas nuevas. La salida vuelve a escaparse con esc_attr()
	 * cuando va a un atributo.
	 *
	 * @param string $v Valor crudo.
	 * @return string
	 */
	public static function sanitizar_css( $v ) {
		$v = (string) $v;
		// Una sola línea: elimina saltos y caracteres de control.
		$v = preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $v );
		// Caracteres que permitirían cerrar el valor/regla o inyectar markup.
		// Las DOS comillas entran aquí. Hoy todos los destinos escapan con
		// esc_attr() y una comilla sería inerte, pero dejarla pasar
		// convertiría en XSS cualquier punto de inserción futuro que se
		// olvidara de escapar, y quitarla no cuesta nada.
		$v = str_replace( array( ';', '{', '}', '<', '>', '\\', '"', "'", '`', '@' ), '', $v );
		// Comentarios CSS (podrían ocultar payloads).
		$v = str_replace( array( '/*', '*/' ), '', $v );
		// Funciones peligrosas (se conservan rgba()/calc()/var()/clamp()).
		$v = preg_replace( '/(?:url|expression|image-set|-moz-binding)\s*\(/i', '', $v );
		$v = trim( $v );
		return function_exists( 'mb_substr' ) ? mb_substr( $v, 0, 200 ) : substr( $v, 0, 200 );
	}

	/**
	 * Encola las tipografías institucionales desde Google Fonts.
	 *
	 * Se puede desactivar en Apariencia si el tema ya las sirve o si la
	 * política de privacidad de la entidad exige autoalojarlas.
	 */
	public static function encolar_fuentes() {
		$e = self::estilo();
		if ( empty( $e['fuentes_cdn'] ) ) {
			return;
		}
		if ( wp_style_is( 'uhp-fuentes', 'registered' ) || wp_style_is( 'uhp-fuentes', 'enqueued' ) ) {
			wp_enqueue_style( 'uhp-fuentes' );
			return;
		}
		wp_register_style(
			'uhp-fuentes',
			'https://fonts.googleapis.com/css2?family=Hind+Madurai:wght@300;400;500;600;700&family=Nunito+Sans:wght@400;600;700&display=swap',
			array(),
			null // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Google Fonts versiona por URL.
		);
		wp_enqueue_style( 'uhp-fuentes' );
	}

	/**
	 * Tipografías del tablero: IBM Plex Sans y IBM Plex Mono.
	 *
	 * El tablero no comparte tipografía con el resto del plugin. Su diseño
	 * se apoya en una monoespaciada para los rótulos y las cifras —de ahí
	 * que las columnas de números queden alineadas y los rótulos en
	 * versalitas tengan el mismo ancho— y eso Hind Madurai no lo da. Van
	 * bajo su propio handle para que una página con tablero y gráficos no
	 * descargue dos veces lo mismo ni pierda ninguna de las dos familias.
	 */
	public static function encolar_fuentes_tablero() {
		$e = self::estilo();
		if ( empty( $e['fuentes_cdn'] ) ) {
			return;
		}
		if ( wp_style_is( 'uhp-fuentes-plex', 'registered' ) || wp_style_is( 'uhp-fuentes-plex', 'enqueued' ) ) {
			wp_enqueue_style( 'uhp-fuentes-plex' );
			return;
		}
		wp_register_style(
			'uhp-fuentes-plex',
			'https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap',
			array(),
			null // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Google Fonts versiona por URL.
		);
		wp_enqueue_style( 'uhp-fuentes-plex' );
	}
}
