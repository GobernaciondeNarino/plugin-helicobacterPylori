<?php
/**
 * Registro de librerías de terceros y scripts propios, sin conflictos.
 *
 * PROBLEMA QUE RESUELVE ESTA CLASE
 * --------------------------------
 * En el mismo sitio conviven otros plugins de la Gobernación que ya cargan
 * D3, D3plus, Leaflet y Three.js (en particular «Monitor Ambiental y Fenómeno
 * El Niño — Nariño», que registra los handles `d3`, `d3plus` y `leaflet`).
 * Dos copias de la misma librería en una página producen desde leyendas rotas
 * hasta mapas que no se inicializan.
 *
 * Reglas aplicadas aquí:
 *
 * 1. HANDLES CANÓNICOS COMPARTIDOS. Las librerías se registran con el handle
 *    de uso común (`d3`, `d3plus`, `leaflet`, `plotly`). Antes de registrar se
 *    comprueba si otro plugin ya lo hizo; si es así se respeta su registro y
 *    NO se vuelve a registrar. WordPress garantiza entonces una sola copia.
 *
 * 2. SIN IMPORTMAP PARA THREE.JS. Un documento HTML solo admite un importmap;
 *    el plugin del Monitor Ambiental ya imprime el suyo apuntando a
 *    three@0.160.0. Si este plugin imprimiera otro con three@0.180.0, el
 *    segundo se ignoraría (o produciría un error) y la escena 3D no cargaría.
 *    Por eso el módulo 3D importa Three.js y sus addons por URL absoluta con
 *    el sufijo `/+esm` de jsDelivr, que resuelve los especificadores «bare»
 *    internos de los addons a esa misma URL: una única instancia de Three.js
 *    sin necesidad de importmap y sin interferir con el de nadie.
 *
 * 3. TODO FILTRABLE. Cada URL pasa por el filtro `uhp_url_libreria` para que
 *    la entidad pueda autoalojar las librerías (política CSP estricta) sin
 *    tocar el código.
 *
 * @package Urkunina5000
 */

namespace GobernacionNarino\Urkunina;

defined( 'ABSPATH' ) || exit;

final class UHP_Assets {

	/** Prefijo de los handles propios del plugin. */
	const P = 'uhp-';

	/**
	 * Versión de Three.js usada por el módulo 3D.
	 *
	 * El objeto original (content/helicobacter-pylori-3d.html) se construyó y
	 * verificó contra esta versión; los addons se piden a la misma.
	 */
	const THREE_VERSION = '0.180.0';

	/**
	 * Catálogo de librerías compartidas.
	 *
	 * `handle` es deliberadamente el nombre canónico y no lleva prefijo: es lo
	 * que hace que dos plugins compartan una sola copia.
	 *
	 * @var array<string,array>
	 */
	private static $librerias = array(
		'd3'      => array(
			'handle' => 'd3',
			'src'    => 'https://cdn.jsdelivr.net/npm/d3@7/dist/d3.min.js',
			'ver'    => '7',
		),
		// d3plus v2.x: bundle UMD apto para navegador que expone window.d3plus.
		// NO usar @d3plus/core@3.x: ese bundle referencia `process`/`require` y
		// rompe en el navegador (window.d3plus queda undefined y no pinta nada).
		// Es además la versión que ya carga el Monitor Ambiental, de modo que
		// compartir handle comparte también versión.
		'd3plus'  => array(
			'handle' => 'd3plus',
			'src'    => 'https://cdn.jsdelivr.net/npm/d3plus@2.0.0/build/d3plus.full.min.js',
			'ver'    => '2.0.0',
		),
		'leaflet' => array(
			'handle' => 'leaflet',
			'src'    => 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
			'css'    => 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
			'ver'    => '1.9.4',
		),
		'plotly'  => array(
			'handle' => 'plotly',
			'src'    => 'https://cdn.jsdelivr.net/npm/plotly.js-dist-min@2.35.2/plotly.min.js',
			'ver'    => '2.35.2',
		),
	);

	public function __construct() {
		// Prioridad 5: las librerías quedan registradas antes de que los
		// shortcodes las encolen en prioridad 10.
		add_action( 'wp_enqueue_scripts', array( $this, 'registrar' ), 5 );
		add_filter( 'script_loader_tag', array( $this, 'marcar_modulos' ), 10, 3 );
	}

	/* ----------------------------------------------------------------- */
	/* Registro                                                           */
	/* ----------------------------------------------------------------- */

	/**
	 * Registra (sin encolar) librerías compartidas y scripts propios.
	 */
	public function registrar() {
		foreach ( array_keys( self::$librerias ) as $nombre ) {
			self::registrar_libreria( $nombre );
		}

		$js  = UHP_URL . 'assets/js/';
		$css = UHP_URL . 'assets/css/';

		// Hojas de estilo propias, todas con selectores bajo `.uhp`.
		wp_register_style( self::P . 'base', $css . 'uhp.css', array(), UHP_VERSION );
		wp_register_style( self::P . 'grafico', $css . 'uhp-grafico.css', array( self::P . 'base' ), UHP_VERSION );
		wp_register_style( self::P . '3d', $css . 'uhp-3d.css', array(), UHP_VERSION );
		wp_register_style( self::P . 'mapa', $css . 'uhp-mapa.css', array( self::P . 'base', self::css( 'leaflet' ) ), UHP_VERSION );
		// El geomapa no usa Leaflet: es un gráfico de D3plus, de modo que su
		// hoja no arrastra el CSS del visor de mapas.
		wp_register_style( self::P . 'geomapa', $css . 'uhp-geomapa.css', array( self::P . 'base' ), UHP_VERSION );
		// El tablero reutiliza el mapa y el motor de gráficos, así que
		// depende de sus dos hojas: la leyenda y el tooltip del mapa viven
		// en uhp-mapa.css y sin ella saldrían sin estilo.
		wp_register_style(
			self::P . 'dashboard',
			$css . 'uhp-dashboard.css',
			array( self::P . 'base', self::P . 'grafico', self::P . 'mapa' ),
			UHP_VERSION
		);

		// Núcleo JS compartido por todos los componentes del front.
		wp_register_script( self::P . 'core', $js . 'uhp-core.js', array(), UHP_VERSION, true );
		wp_add_inline_script(
			self::P . 'core',
			'window.UHP=' . self::json_para_script( self::config_front() ) . ';',
			'before'
		);

		// El renderer usa UHPcore (formato de cifras y rampa de color), así
		// que depende del núcleo además de D3plus. Sin declararlo, WordPress
		// lo imprimía ANTES que el núcleo y `window.UHPcore` no existía
		// todavía: las vistas de mapa de calor, que son las que piden la
		// rampa, fallaban al dibujarse.
		wp_register_script(
			self::P . 'renderer',
			$js . 'uhp-renderer.js',
			array( self::handle( 'd3plus' ), self::P . 'core' ),
			UHP_VERSION,
			true
		);
		wp_register_script( self::P . 'grafico', $js . 'uhp-grafico.js', array( self::P . 'renderer', self::P . 'core' ), UHP_VERSION, true );
		// El controlador de canales solo necesita el núcleo: mueve paneles
		// ya impresos y pide a la figura del gráfico que se recargue. No
		// depende de D3plus ni de la figura, que pueden no estar en la
		// página si alguien maqueta solo textos y tablas.
		wp_register_script( self::P . 'grupo', $js . 'uhp-grupo.js', array( self::P . 'core' ), UHP_VERSION, true );
		wp_register_script( self::P . 'mapa', $js . 'uhp-mapa.js', array( self::handle( 'leaflet' ), self::P . 'core' ), UHP_VERSION, true );
		// Declarar aquí d3plus Y core no es redundante: el geomapa usa las
		// dos, y omitir una deja que WordPress imprima este archivo antes
		// que ella. Ese error ya costó dos módulos rotos en silencio.
		wp_register_script(
			self::P . 'geomapa',
			$js . 'uhp-geomapa.js',
			array( self::handle( 'd3plus' ), self::P . 'core' ),
			UHP_VERSION,
			true
		);
		// El tablero NO habla con Leaflet directamente: construye su mapa a
		// través de UHPMapa, que vive en uhp-mapa.js. Declararlo aquí como
		// dependencia es lo que garantiza que encolar el tablero arrastre
		// también el módulo de mapa y, con él, Leaflet.
		wp_register_script(
			self::P . 'dashboard',
			$js . 'uhp-dashboard.js',
			array( self::P . 'core', self::P . 'renderer', self::P . 'mapa' ),
			UHP_VERSION,
			true
		);

		// Módulo ES del objeto 3D. Importa Three.js por URL absoluta (ver
		// marcar_modulos() y la nota de cabecera de esta clase). Depende del
		// núcleo para que WordPress imprima ANTES el script clásico que lleva
		// window.UHP3D con las URLs: un módulo es diferido y se ejecutaría
		// después de todos modos, pero dejar el orden implícito sería frágil.
		wp_register_script( self::P . '3d', $js . 'uhp-3d.js', array( self::P . 'core' ), UHP_VERSION, true );
	}

	/**
	 * Registra una librería compartida solo si nadie la registró antes.
	 *
	 * @param string $nombre Clave del catálogo.
	 * @return string Handle a usar como dependencia.
	 */
	public static function registrar_libreria( $nombre ) {
		if ( ! isset( self::$librerias[ $nombre ] ) ) {
			return '';
		}
		$lib    = self::$librerias[ $nombre ];
		$handle = $lib['handle'];

		if ( ! wp_script_is( $handle, 'registered' ) ) {
			wp_register_script( $handle, self::url( $nombre, $lib['src'] ), array(), $lib['ver'], true );
		}

		if ( ! empty( $lib['css'] ) && ! wp_style_is( $handle, 'registered' ) ) {
			wp_register_style( $handle, self::url( $nombre . '-css', $lib['css'] ), array(), $lib['ver'] );
		}

		return $handle;
	}

	/**
	 * Handle de script de una librería compartida.
	 *
	 * @param string $nombre Clave del catálogo.
	 * @return string
	 */
	public static function handle( $nombre ) {
		return isset( self::$librerias[ $nombre ] ) ? self::$librerias[ $nombre ]['handle'] : '';
	}

	/**
	 * Handle de hoja de estilo de una librería compartida ('' si no tiene).
	 *
	 * @param string $nombre Clave del catálogo.
	 * @return string
	 */
	public static function css( $nombre ) {
		return ( isset( self::$librerias[ $nombre ]['css'] ) ) ? self::$librerias[ $nombre ]['handle'] : '';
	}

	/**
	 * Encola una librería compartida (script y, si tiene, su CSS).
	 *
	 * @param string $nombre Clave del catálogo.
	 */
	public static function encolar_libreria( $nombre ) {
		$handle = self::registrar_libreria( $nombre );
		if ( '' === $handle ) {
			return;
		}
		wp_enqueue_script( $handle );
		if ( ! empty( self::$librerias[ $nombre ]['css'] ) ) {
			wp_enqueue_style( $handle );
		}
	}

	/**
	 * URL de una librería, filtrable para permitir autoalojamiento.
	 *
	 * @param string $clave Identificador de la librería.
	 * @param string $url   URL por defecto (CDN).
	 * @return string
	 */
	private static function url( $clave, $url ) {
		/**
		 * Filtra la URL de una librería de terceros.
		 *
		 * Devuelva una URL local para servir la librería desde el propio
		 * dominio y poder aplicar una CSP sin `cdn.jsdelivr.net`.
		 *
		 * @param string $url   URL por defecto.
		 * @param string $clave Identificador de la librería.
		 */
		$filtrada = apply_filters( 'uhp_url_libreria', $url, $clave );
		return is_string( $filtrada ) && '' !== $filtrada ? esc_url_raw( $filtrada ) : esc_url_raw( $url );
	}

	/**
	 * URLs de los módulos ES de Three.js para el objeto 3D.
	 *
	 * El sufijo `/+esm` de jsDelivr entrega un bundle ESM en el que los
	 * `import … from 'three'` internos de los addons ya están reescritos a
	 * `/npm/three@VERSION/+esm`. Como es exactamente la misma URL que importa
	 * el módulo principal, el navegador reutiliza la instancia: un solo
	 * Three.js, sin importmap y sin colisionar con el de otros plugins.
	 *
	 * @return array<string,string>
	 */
	public static function three_urls() {
		$base = 'https://cdn.jsdelivr.net/npm/three@' . self::THREE_VERSION;
		$jsm  = $base . '/examples/jsm';

		$urls = array(
			'three'         => $base . '/+esm',
			'orbit'         => $jsm . '/controls/OrbitControls.js/+esm',
			'composer'      => $jsm . '/postprocessing/EffectComposer.js/+esm',
			'renderPass'    => $jsm . '/postprocessing/RenderPass.js/+esm',
			'bloomPass'     => $jsm . '/postprocessing/UnrealBloomPass.js/+esm',
			'bokehPass'     => $jsm . '/postprocessing/BokehPass.js/+esm',
			'outputPass'    => $jsm . '/postprocessing/OutputPass.js/+esm',
			'roomEnvironment' => $jsm . '/environments/RoomEnvironment.js/+esm',
		);

		foreach ( $urls as $k => $v ) {
			$urls[ $k ] = self::url( 'three-' . $k, $v );
		}
		return $urls;
	}

	/**
	 * Convierte en `type="module"` el tag de los scripts que lo requieren.
	 *
	 * @param string $tag    Etiqueta HTML generada por WordPress.
	 * @param string $handle Handle del script.
	 * @param string $src    URL del script.
	 * @return string
	 */
	public function marcar_modulos( $tag, $handle, $src ) {
		if ( self::P . '3d' === $handle ) {
			return '<script type="module" src="' . esc_url( $src ) . '" id="' . esc_attr( $handle ) . '-js"></script>' . "\n";
		}
		return $tag;
	}

	/**
	 * Serializa un valor para incrustarlo en un bloque <script>.
	 *
	 * Las banderas HEX escapan `<`, `>`, `&` y las comillas, de modo que
	 * ninguna cadena pueda cerrar el bloque con un `</script>`. Los valores
	 * que se incrustan hoy salen de la configuración del sitio y no del
	 * visitante, pero el coste de blindarlo es nulo.
	 *
	 * @param mixed $valor Valor a serializar.
	 * @return string
	 */
	public static function json_para_script( $valor ) {
		$json = wp_json_encode(
			$valor,
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
		);
		return false === $json ? '{}' : $json;
	}

	/**
	 * Configuración que el front necesita conocer (objeto global `UHP`).
	 *
	 * No lleva nonce a propósito: todos los endpoints del front son públicos
	 * de solo lectura y un nonce caducado servido desde la caché de página
	 * provocaría 403 (rest_cookie_invalid_nonce) a los visitantes.
	 *
	 * @return array
	 */
	private static function config_front() {
		return array(
			'rest'      => esc_url_raw( rest_url( UHP_Rest::NS ) ),
			'pluginUrl' => esc_url_raw( UHP_URL ),
			'locale'    => 'es-CO',
		);
	}
}
