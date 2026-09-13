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
use GobernacionNarino\Urkunina\UHP_Topojson;
use GobernacionNarino\Urkunina\UHP_Territorios;

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

/* ---- /render por vista y por tipo compatible ----
   Se pide a UHP_Rest::carga_render(), el mismo constructor que usa la
   ruta: rehacer aquí la forma del payload dejaría a las pruebas de
   navegador validando contra algo que el servidor no sirve. */
foreach ( UHP_Views::lista() as $v ) {
	foreach ( $v['compatible'] as $tipo ) {
		escribir(
			$destino,
			'render--' . $v['id'] . '--' . $tipo,
			UHP_Rest::carga_render( $v['id'], $tipo )
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

/* ---- /mapa por indicador y por nivel ----
   El nivel cambia los valores Y la meta: un mismo indicador no siempre
   mide lo mismo al cambiar de capa. */
foreach ( UHP_Rest::indicadores_mapa() as $clave => $meta ) {
	foreach ( UHP_Territorios::NIVELES as $nivel ) {
		escribir(
			$destino,
			'mapa--' . $clave . '--' . $nivel,
			array(
				'indicador'   => $clave,
				'nivel'       => $nivel,
				'meta'        => UHP_Territorios::meta_por_nivel( $meta, $clave, $nivel ),
				'indicadores' => UHP_Rest::indicadores_mapa(),
				'valores'     => UHP_Territorios::valores( $clave, $nivel ),
			)
		);
	}
	copy( $destino . '/mapa--' . $clave . '--municipio.json', $destino . '/mapa--' . $clave . '.json' );
}
copy( $destino . '/mapa--lpm.json', $destino . '/mapa.json' );

/* ---- /geo por nivel ----
   Sale de UHP_Topojson::features(), que es la misma función que usa la
   ruta real y de la que también se construye la topología de D3plus. */
foreach ( UHP_Topojson::NIVELES_GEO as $nivel ) {
	escribir(
		$destino,
		'geo--' . $nivel,
		array(
			'type'     => 'FeatureCollection',
			'nivel'    => $nivel,
			'features' => UHP_Topojson::features( $nivel ),
		)
	);
}
copy( $destino . '/geo--municipio.json', $destino . '/geo.json' );

/* ---- /territorio: el departamento, cada subregión y cada municipio ---- */
escribir( $destino, 'territorio--departamento-52', UHP_Territorios::ficha( 'departamento' ) );
foreach ( UHP_Territorios::entidades( 'subregion' ) as $t ) {
	escribir( $destino, 'territorio--subregion-' . $t['id'], UHP_Territorios::ficha( 'subregion', $t['id'] ) );
}
foreach ( UHP_Territorios::entidades( 'municipio' ) as $t ) {
	escribir( $destino, 'territorio--municipio-' . $t['id'], UHP_Territorios::ficha( 'municipio', $t['id'] ) );
}

/* ---- /topojson por nivel (la topología que consume D3plus Geomap) ---- */
foreach ( UHP_Topojson::NIVELES as $nivel ) {
	escribir( $destino, 'topojson--' . $nivel, UHP_Topojson::topologia( $nivel, true ) );
}
copy( $destino . '/topojson--municipio.json', $destino . '/topojson.json' );

/* ---- /geomapa por indicador y por vista territorial ----
   Se llama a la MISMA función que usa la ruta real: si la carga cambia,
   los fixtures cambian con ella y la suite no puede quedarse probando una
   respuesta que ya no existe. */
foreach ( array_keys( UHP_Rest::indicadores_mapa() ) as $clave ) {
	escribir( $destino, 'geomapa--ind-' . $clave, UHP_Rest::carga_geomapa( '', $clave ) );
}
copy( $destino . '/geomapa--ind-lpm.json', $destino . '/geomapa.json' );

foreach ( UHP_Views::territoriales() as $t ) {
	escribir( $destino, 'geomapa--vista-' . $t['id'], UHP_Rest::carga_geomapa( $t['id'] ) );

	// Vistas partidas en series: una respuesta por serie, porque el mapa
	// solo puede pintar una y el shortcode deja elegir cuál.
	foreach ( UHP_Views::series( $t['id'] ) as $i => $serie ) {
		escribir(
			$destino,
			'geomapa--vista-' . $t['id'] . '--serie' . $i,
			UHP_Rest::carga_geomapa( $t['id'], 'lpm', $serie )
		);
	}
}

/* ---- /tablero ----
   Se pide al mismo constructor que usa la ruta: rehacer aquí la forma del
   payload dejaría a las pruebas de navegador validando contra algo que el
   servidor no sirve. */
escribir( $destino, 'tablero', UHP_Rest::carga_tablero() );

echo "Listo.\n";
