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
	 * Crea las opciones que aún no existan y completa las que se quedaron
	 * cortas, sin pisar nunca lo que haya elegido el administrador.
	 */
	private static function sembrar_opciones() {
		self::sembrar( 'uhp_estilo', UHP_Estilos::por_defecto() );
		self::sembrar( 'uhp_dashboard', self::dashboard_por_defecto() );
		self::sembrar( 'uhp_3d', self::tresd_por_defecto() );
	}

	/**
	 * Siembra una opción y le añade las claves que le falten.
	 *
	 * Crear la opción solo si no existe no basta: al añadir un ajuste nuevo
	 * —el tema del tablero, sin ir más lejos— las instalaciones que ya
	 * tenían la opción guardada se quedaban sin esa clave, y el formulario
	 * del panel la leía vacía. Se completa con el valor por defecto y se
	 * respeta todo lo demás.
	 *
	 * @param string $clave    Nombre de la opción.
	 * @param array  $defectos Valores por defecto.
	 * @return void
	 */
	private static function sembrar( $clave, $defectos ) {
		$actual = get_option( $clave, false );

		if ( false === $actual || ! is_array( $actual ) ) {
			add_option( $clave, $defectos, '', false );
			return;
		}

		$faltantes = array_diff_key( $defectos, $actual );
		if ( ! empty( $faltantes ) ) {
			update_option( $clave, array_merge( $defectos, $actual ), false );
		}
	}

	/**
	 * Configuración por defecto del tablero.
	 *
	 * @return array
	 */
	public static function dashboard_por_defecto() {
		return array(
			'titulo'    => 'URKUNINA 5000 — Tamizaje de Helicobacter pylori en Nariño',
			'lema'      => 'Prevalencia de lesiones precursoras de malignidad y erradicación de H. pylori como prevención primaria del cáncer gástrico — Nariño, 2018–2023',
			// Con cuál de los dos indicadores arranca el mapa y las barras.
			'indicador' => 'lpm',
		);
	}

	/**
	 * Configuración por defecto del módulo 3D.
	 *
	 * @return array
	 */
	public static function tresd_por_defecto() {
		return array(
			'alto'         => '100vh',
			'autoplay'     => 1,
			'duracion'     => 15,
			'instrumentos' => 1,
			'cabecera'     => 1,
			// El objeto no se lleva el scroll de la página hasta él: si no
			// abre la página, arrastrar al visitante mientras lee más arriba
			// es justo lo que nadie espera de un banner.
			'desplazar'    => 0,
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
