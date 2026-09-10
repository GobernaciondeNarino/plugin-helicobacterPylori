<?php
/**
 * Genera las respuestas de la API como archivos estáticos.
 *
 * El servidor de pruebas del navegador (tests/servidor.js) las sirve tal
 * cual, de modo que las pruebas de Playwright ejercitan el JavaScript real
 * del plugin contra los datos reales del proyecto, sin instalar WordPress.
 *
 * Uso:  php tests/generar-fixtures.php [directorio]
 *
 * @package Urkunina5000
 */

require_once __DIR__ . '/stubs-wordpress.php';
uhp_cargar_clases_de_datos();

use GobernacionNarino\Urkunina\UHP_Datos;
use GobernacionNarino\Urkunina\UHP_Municipios;
use GobernacionNarino\Urkunina\UHP_Views;
use GobernacionNarino\Urkunina\UHP_Rest;

$destino = isset( $argv[1] ) ? rtrim( $argv[1], '/' ) : __DIR__ . '/fixtures';
if ( ! is_dir( $destino ) ) {
	mkdir( $destino, 0755, true );
}

/**
 * Escribe una respuesta como archivo JSON.
 *
 * @param string $destino Directorio de salida.
 * @param string $nombre  Nombre del archivo, sin extensión.
 * @param mixed  $datos   Contenido.
 */
function escribir( $destino, $nombre, $datos ) {
	$ruta = $destino . '/' . $nombre . '.json';
	file_put_contents( $ruta, json_encode( $datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	printf( "  %-42s %s\n", $nombre . '.json', size_format( filesize( $ruta ) ) );
}

echo "Generando respuestas de la API en " . $destino . "\n";

/* ---- /vistas ---- */
escribir(
	$destino,
	'vistas',
	array(
		'vistas' => UHP_Views::lista(),
		'tipos'  => UHP_Views::tipos(),
	)
);

/* ---- /render por vista y por tipo compatible ---- */
$tipos = UHP_Views::tipos();
foreach ( UHP_Views::lista() as $v ) {
	$vista = UHP_Views::obtener( $v['id'] );
	foreach ( $v['compatible'] as $tipo ) {
		escribir(
			$destino,
			'render--' . $v['id'] . '--' . $tipo,
			array(
				'chart'      => array(
					'key'   => $tipo,
					'class' => $tipos[ $tipo ]['class'],
					'label' => $tipos[ $tipo ]['label'],
				),
				'view'       => $vista,
				'data'       => $vista['data'],
				'compatible' => $v['compatible'],
			)
		);
	}
	// Alias sin tipo: el que pide el shortcode cuando no lo especifica.
	copy(
		$destino . '/render--' . $v['id'] . '--' . $v['default'] . '.json',
		$destino . '/render--' . $v['id'] . '.json'
	);
}

/* ---- /kpi ---- */
escribir( $destino, 'kpi', array( 'kpi' => UHP_Rest::kpis() ) );

/* ---- /mapa por indicador ---- */
foreach ( UHP_Rest::indicadores_mapa() as $clave => $meta ) {
	escribir(
		$destino,
		'mapa--' . $clave,
		array(
			'indicador'   => $clave,
			'meta'        => $meta,
			'indicadores' => UHP_Rest::indicadores_mapa(),
			'valores'     => UHP_Rest::valores_mapa( $clave ),
		)
	);
}
copy( $destino . '/mapa--lpm.json', $destino . '/mapa.json' );

/* ---- /geo (misma reducción que hace la ruta real) ---- */
$geo          = UHP_Datos::leer( 'geojson' );
$prioritarios = UHP_Municipios::set_priorizados();
$features     = array();
foreach ( (array) $geo['features'] as $f ) {
	$p = isset( $f['properties'] ) ? $f['properties'] : array();
	if ( empty( $p['MPIO_CDPMP'] ) ) {
		continue;
	}
	$divipola   = (string) $p['MPIO_CDPMP'];
	$features[] = array(
		'type'       => 'Feature',
		'properties' => array(
			'divipola'   => $divipola,
			'nombre'     => UHP_Municipios::titulo( (string) $p['MPIO_CNMBR'] ),
			'lat'        => isset( $p['LATITUD'] ) ? round( (float) $p['LATITUD'], 5 ) : null,
			'lon'        => isset( $p['LONGITUD'] ) ? round( (float) $p['LONGITUD'], 5 ) : null,
			'priorizado' => isset( $prioritarios[ $divipola ] ),
		),
		'geometry'   => isset( $f['geometry'] ) ? $f['geometry'] : null,
	);
}
escribir(
	$destino,
	'geo',
	array(
		'type'     => 'FeatureCollection',
		'features' => $features,
	)
);

/* ---- /dashboard ---- */
escribir(
	$destino,
	'dashboard',
	array(
		'kpi'         => UHP_Rest::kpis(),
		'indicadores' => UHP_Rest::indicadores_mapa(),
		'valores'     => UHP_Rest::valores_mapa( 'lpm' ),
		'subregiones' => UHP_Views::obtener( 'prev_subregion' ),
		'zonas'       => UHP_Views::obtener( 'zonas_riesgo' ),
		'proyecto'    => array(
			'nombre'    => UHP_Datos::valor( 'proyecto', 'identificacion.nombre_corto', 'URKUNINA 5000' ),
			'completo'  => UHP_Datos::valor( 'proyecto', 'identificacion.nombre_completo', '' ),
			'bpin'      => UHP_Datos::valor( 'proyecto', 'identificacion.bpin', '' ),
			'estado'    => UHP_Datos::valor( 'proyecto', 'ejecucion.estado', '' ),
			'inicio'    => UHP_Datos::valor( 'proyecto', 'ejecucion.fecha_inicio', '' ),
			'fin_campo' => UHP_Datos::valor( 'proyecto', 'ejecucion.fecha_fin_trabajo_campo', '' ),
		),
	)
);

echo "Listo.\n";
