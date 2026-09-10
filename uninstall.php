<?php
/**
 * Desinstalación del plugin.
 *
 * Se ejecuta solo cuando el administrador BORRA el plugin, no al
 * desactivarlo. Elimina las opciones y los datos transitorios que el plugin
 * creó, pero NO toca los archivos de /data: son el conjunto de datos del
 * proyecto, versionado con el repositorio, y borrarlos al desinstalar
 * destruiría trabajo que no pertenece al plugin.
 *
 * @package Urkunina5000
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Borra las opciones y transitorios del plugin en un sitio.
 */
function uhp_limpiar_sitio() {
	global $wpdb;

	$opciones = array(
		'uhp_version',
		'uhp_estilo',
		'uhp_dashboard',
		'uhp_3d',
		'uhp_datos_hash',
	);
	foreach ( $opciones as $opcion ) {
		delete_option( $opcion );
	}

	// Transitorios del plugin: caché de archivos, índice de municipios y
	// contadores del límite de peticiones. Se borran por prefijo porque su
	// nombre lleva un hash y no se pueden enumerar de otro modo.
	$prefijos = array( 'uhp_json_', 'uhp_municipios_', 'uhp_rl_', 'uhp_aviso_' );
	foreach ( $prefijos as $prefijo ) {
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . $prefijo ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . $prefijo ) . '%'
			)
		);
	}
}

if ( is_multisite() ) {
	$sitios = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $sitios as $sitio_id ) {
		switch_to_blog( $sitio_id );
		uhp_limpiar_sitio();
		restore_current_blog();
	}
} else {
	uhp_limpiar_sitio();
}
