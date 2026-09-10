<?php
/**
 * Ciclo de vida del plugin: activación, desactivación y migraciones.
 *
 * El plugin no crea tablas: toda la información vive en los archivos JSON de
 * /data, versionados con el repositorio. La activación se limita a sembrar
 * opciones, crear el directorio de copias de seguridad y registrar la línea
 * base de integridad de los datos.
 *
 * @package Urkunina5000
 */

namespace GobernacionNarino\Urkunina;

defined( 'ABSPATH' ) || exit;

final class UHP_Activator {

	/** Opción con la versión instalada (dispara migraciones). */
	const OPT_VERSION = 'uhp_version';

	/**
	 * Se ejecuta al activar el plugin.
	 */
	public static function activar() {
		self::sembrar_opciones();
		UHP_Datos::asegurar_directorio_respaldos();
		UHP_Datos::registrar_linea_base();
		update_option( self::OPT_VERSION, UHP_VERSION, false );
		// Los shortcodes cambian el HTML servido: conviene invalidar la caché.
		self::purgar_cache();
	}

	/**
	 * Se ejecuta al desactivar el plugin.
	 *
	 * No borra datos ni opciones: eso corresponde a uninstall.php, para que
	 * desactivar y reactivar no pierda la configuración del administrador.
	 */
	public static function desactivar() {
		self::purgar_cache();
	}

	/**
	 * Siembra opciones nuevas cuando el plugin se actualiza sin reactivarse.
	 */
	public static function migrar_si_necesario() {
		$instalada = get_option( self::OPT_VERSION, '' );
		if ( UHP_VERSION === $instalada ) {
			return;
		}
		self::sembrar_opciones();
		UHP_Datos::asegurar_directorio_respaldos();
		UHP_Datos::registrar_linea_base();
		update_option( self::OPT_VERSION, UHP_VERSION, false );
	}

	/**
	 * Crea las opciones que aún no existan, sin pisar las del administrador.
	 */
	private static function sembrar_opciones() {
		if ( false === get_option( 'uhp_estilo', false ) ) {
			add_option( 'uhp_estilo', UHP_Estilos::por_defecto(), '', false );
		}
		if ( false === get_option( 'uhp_dashboard', false ) ) {
			add_option( 'uhp_dashboard', self::dashboard_por_defecto(), '', false );
		}
		if ( false === get_option( 'uhp_3d', false ) ) {
			add_option( 'uhp_3d', self::tresd_por_defecto(), '', false );
		}
	}

	/**
	 * Configuración por defecto del tablero.
	 *
	 * @return array
	 */
	public static function dashboard_por_defecto() {
		return array(
			'titulo'      => 'URKUNINA 5000 — Tamizaje de Helicobacter pylori en Nariño',
			'indicador'   => 'lpm',
			'mapa_lat'    => 1.30,
			'mapa_lon'    => -77.60,
			'mapa_zoom'   => 8,
			'teselas'     => 'osm',
			'panel_izq'   => 1,
			'panel_der'   => 1,
			'mostrar_kpi' => 1,
		);
	}

	/**
	 * Configuración por defecto del módulo 3D.
	 *
	 * @return array
	 */
	public static function tresd_por_defecto() {
		return array(
			'alto'        => '100vh',
			'autoplay'    => 1,
			'duracion'    => 15,
			'instrumentos' => 1,
			'cabecera'    => 1,
		);
	}

	/**
	 * Purga la caché de páginas de los plugins de caché más comunes.
	 */
	private static function purgar_cache() {
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
	}
}
