<?php
/**
 * Orquestador singleton del plugin.
 *
 * Responsabilidad única: cargar las dependencias y registrar los hooks de
 * cada subsistema (assets, estilos, REST, shortcodes y administración).
 *
 * @package Urkunina5000
 */

namespace GobernacionNarino\Urkunina;

defined( 'ABSPATH' ) || exit;

final class UHP_Plugin {

	/** @var UHP_Plugin|null Instancia única. */
	private static $instancia = null;

	/** @var bool Evita requerir las dependencias dos veces. */
	private static $cargado = false;

	/** @var UHP_Assets */
	public $assets;
	/** @var UHP_Estilos */
	public $estilos;
	/** @var UHP_Rest */
	public $rest;
	/** @var UHP_Shortcodes */
	public $shortcodes;
	/** @var UHP_Admin|null */
	public $admin = null;

	/**
	 * Devuelve (creando si hace falta) la instancia única.
	 *
	 * @return UHP_Plugin
	 */
	public static function instancia() {
		if ( null === self::$instancia ) {
			self::$instancia = new self();
		}
		return self::$instancia;
	}

	/**
	 * Requiere todos los archivos de clase del plugin.
	 * Idempotente: seguro llamarlo varias veces.
	 */
	public static function cargar_dependencias() {
		if ( self::$cargado ) {
			return;
		}

		$base = UHP_DIR . 'includes/';

		// Núcleo.
		require_once $base . 'class-uhp-activator.php';
		require_once $base . 'class-uhp-security.php';
		require_once $base . 'class-uhp-assets.php';
		require_once $base . 'class-uhp-estilos.php';

		// Datos.
		require_once $base . 'data/class-uhp-datos.php';
		require_once $base . 'data/class-uhp-municipios.php';
		require_once $base . 'data/class-uhp-subregiones.php';
		require_once $base . 'data/class-uhp-topojson.php';
		require_once $base . 'data/class-uhp-views.php';
		require_once $base . 'data/class-uhp-territorios.php';

		// Análisis (generación automática de texto a partir de los datos).
		require_once $base . 'analysis/class-uhp-analisis.php';

		// REST (depende de datos y vistas).
		require_once $base . 'class-uhp-rest.php';

		// Presentación.
		require_once $base . 'shortcodes/class-uhp-shortcodes.php';

		// Administración. Se requiere siempre para que las acciones admin-post
		// y AJAX registradas existan aunque la petición no pinte el panel.
		require_once $base . 'admin/class-uhp-admin-datos.php';
		require_once $base . 'admin/class-uhp-admin.php';

		self::$cargado = true;
	}

	/**
	 * Constructor privado: registra los hooks de los subsistemas.
	 */
	private function __construct() {
		self::cargar_dependencias();

		// Idioma (es_CO).
		add_action( 'init', array( $this, 'cargar_textdomain' ) );

		// Migración al actualizar (en admin): siembra opciones nuevas sin reactivar.
		add_action( 'admin_init', array( UHP_Activator::class, 'migrar_si_necesario' ) );

		// Subsistemas: cada uno registra sus propios hooks en su constructor.
		$this->assets     = new UHP_Assets();
		$this->estilos    = new UHP_Estilos();
		$this->rest       = new UHP_Rest();
		$this->shortcodes = new UHP_Shortcodes();

		if ( is_admin() ) {
			$this->admin = new UHP_Admin();
		}
	}

	/**
	 * Carga las traducciones del plugin.
	 */
	public function cargar_textdomain() {
		load_plugin_textdomain(
			'urkunina-5000',
			false,
			dirname( UHP_BASENAME ) . '/languages'
		);
	}

	/** Clonación e hidratación deshabilitadas (singleton). */
	private function __clone() {}

	public function __wakeup() {
		throw new \Exception( 'No se permite deserializar UHP_Plugin.' );
	}
}
