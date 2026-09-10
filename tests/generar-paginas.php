<?php
/**
 * Genera páginas HTML con el marcado REAL que emiten los shortcodes.
 *
 * Playwright las abre en un navegador de verdad, de modo que lo que se
 * verifica es el HTML, el CSS y el JavaScript que el plugin publica en
 * producción, no una copia escrita a mano que podría quedar desfasada.
 *
 * Uso:  php tests/generar-paginas.php [directorio]
 *
 * @package Urkunina5000
 */

require_once __DIR__ . '/stubs-wordpress.php';
uhp_cargar_shortcodes();

use GobernacionNarino\Urkunina\UHP_Shortcodes;
use GobernacionNarino\Urkunina\UHP_Estilos;
use GobernacionNarino\Urkunina\UHP_Assets;

$destino = isset( $argv[1] ) ? rtrim( $argv[1], '/' ) : __DIR__ . '/paginas';
if ( ! is_dir( $destino ) ) {
	mkdir( $destino, 0755, true );
}

/*
 * Three.js se sirve desde el espejo local de tests/vendor en lugar de la
 * CDN, por dos razones: la suite no debe depender de la red, y así se
 * ejercita de paso el filtro `uhp_url_libreria`, que es la vía oficial
 * para que la entidad autoaloje las librerías bajo una CSP estricta.
 *
 * Los complementos traen sus importaciones internas apuntando a la ruta
 * absoluta /npm/three@VERSION/+esm; el servidor de pruebas la sirve desde
 * el mismo archivo, igual que hace la CDN. Es justamente lo que garantiza
 * una única instancia de Three.js sin mapa de importaciones.
 */
add_filter(
	'uhp_url_libreria',
	function ( $url, $clave ) {
		$espejo = array(
			'three-three'           => '/vendor/three-core.js',
			'three-orbit'           => '/vendor/three-orbit.js',
			'three-composer'        => '/vendor/three-composer.js',
			'three-renderPass'      => '/vendor/three-renderpass.js',
			'three-bloomPass'       => '/vendor/three-bloom.js',
			'three-bokehPass'       => '/vendor/three-bokeh.js',
			'three-outputPass'      => '/vendor/three-output.js',
			'three-roomEnvironment' => '/vendor/three-room.js',
		);
		return isset( $espejo[ $clave ] ) ? $espejo[ $clave ] : $url;
	},
	10,
	2
);

$sc = new UHP_Shortcodes();

/**
 * Envuelve el marcado de un shortcode en una página completa.
 *
 * Reproduce lo que WordPress imprimiría: las variables de apariencia, el
 * objeto de configuración del front y las hojas y scripts del plugin.
 *
 * @param string   $titulo Título de la página.
 * @param string   $cuerpo Marcado del shortcode.
 * @param string[] $css    Hojas de estilo del plugin a incluir.
 * @param array    $js     Scripts a incluir: array(ruta, esModulo).
 * @param string   $extra  Marcado o script adicional.
 * @return string
 */
function pagina( $titulo, $cuerpo, $css, $js, $extra = '' ) {
	$hojas = '';
	foreach ( $css as $c ) {
		$hojas .= '<link rel="stylesheet" href="/assets/css/' . $c . '.css">' . "\n";
	}

	$scripts = '';
	foreach ( $js as $par ) {
		list( $ruta, $modulo ) = $par;
		$scripts .= '<script ' . ( $modulo ? 'type="module" ' : '' ) .
			'src="/assets/js/' . $ruta . '.js"></script>' . "\n";
	}

	$config = json_encode(
		array(
			'rest'      => '/wp-json/urkunina/v1',
			'pluginUrl' => '/',
			'locale'    => 'es-CO',
		)
	);
	$three = json_encode( array( 'urls' => UHP_Assets::three_urls() ) );

	return '<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>' . htmlspecialchars( $titulo ) . ' — prueba</title>
<!-- Sin Google Fonts a propósito: la suite no debe depender de la red más
     que para Three.js, y el CSS del plugin ya declara pila de respaldo. -->
' . $hojas . '<style>' . UHP_Estilos::css_global() . '
body{margin:0;font-family:system-ui,sans-serif;background:#fff;}
.pagina{max-width:1400px;margin:0 auto;padding:0 16px;}
</style>
<script>window.UHP=' . $config . ';window.UHP3D=' . $three . ';</script>
</head>
<body>
' . $cuerpo . '
' . $extra . '
' . $scripts . '</body>
</html>';
}

/* ------------------------------------------------------------------ */

$paginas = array();

/* --- Objeto 3D --- */
$paginas['objeto-3d'] = pagina(
	'Objeto 3D',
	$sc->sc_3d( array( 'alto' => '100vh' ) ),
	array( 'uhp-3d' ),
	array( array( 'uhp-core', false ), array( 'uhp-3d', true ) )
);

/* --- Objeto 3D embebido dentro de una página con más contenido --- */
$paginas['objeto-3d-embebido'] = pagina(
	'Objeto 3D embebido',
	'<div class="pagina"><h1>Antes de la escena</h1><p>Contenido de la página anfitriona.</p>' .
	$sc->sc_3d( array( 'alto' => '600px' ) ) .
	'<h2>Después de la escena</h2><p>Más contenido para comprobar que la escena no se apodera de la página.</p></div>',
	array( 'uhp-3d' ),
	array( array( 'uhp-core', false ), array( 'uhp-3d', true ) )
);

/* --- Tablero completo --- */
$paginas['tablero'] = pagina(
	'Tablero',
	$sc->sc_dashboard( array() ),
	array( 'leaflet-vendor', 'uhp', 'uhp-grafico', 'uhp-dashboard' ),
	array(
		array( 'vendor-d3plus', false ),
		array( 'vendor-leaflet', false ),
		array( 'uhp-core', false ),
		array( 'uhp-renderer', false ),
		array( 'uhp-mapa', false ),
		array( 'uhp-dashboard', false ),
	)
);

/* --- Gráficos: uno por tipo compatible de una vista representativa --- */
$graficos = '';
foreach ( array(
	array( 'tamizaje_hp', 'donut' ),
	array( 'prev_lpm_municipios', 'bar' ),
	array( 'prev_subregion', 'bar' ),
	array( 'tamizaje_comparado', 'stacked_bar' ),
	array( 'publicaciones_anio', 'line' ),
	array( 'biobanco_tipos', 'treemap' ),
	array( 'metas_mga', 'bar' ),
	array( 'perfil_etnia', 'pie' ),
) as $par ) {
	$graficos .= $sc->sc_grafico(
		array(
			'view' => $par[0],
			'type' => $par[1],
			'alto' => '360px',
		)
	);
}
$paginas['graficos'] = pagina(
	'Gráficos',
	'<div class="pagina">' . $graficos . '</div>',
	array( 'uhp', 'uhp-grafico' ),
	array(
		array( 'vendor-d3plus', false ),
		array( 'uhp-core', false ),
		array( 'uhp-renderer', false ),
		array( 'uhp-grafico', false ),
	)
);

/* --- Mapa --- */
$paginas['mapa'] = pagina(
	'Mapa',
	'<div class="pagina">' . $sc->sc_mapa( array( 'alto' => '560px' ) ) . '</div>',
	array( 'leaflet-vendor', 'uhp', 'uhp-mapa' ),
	array(
		array( 'vendor-leaflet', false ),
		array( 'uhp-core', false ),
		array( 'uhp-mapa', false ),
	)
);

/* --- Componentes renderizados en servidor --- */
$paginas['servidor'] = pagina(
	'Componentes de servidor',
	'<div class="pagina">' .
	$sc->sc_kpi( array() ) .
	$sc->sc_ficha( array() ) .
	$sc->sc_tabla( array( 'view' => 'prev_subregion_lpm' ) ) .
	'<p>Prevalencia de infección: ' .
	$sc->sc_dato(
		array(
			'archivo' => 'tamizaje',
			'ruta'    => 'infeccion_h_pylori.positivos.porcentaje',
			'formato' => 'porcentaje',
		)
	) . '</p>' .
	'</div>',
	array( 'uhp' ),
	array()
);

/* --- Convivencia: tablero, gráficos y objeto 3D en la MISMA página --- */
$paginas['convivencia'] = pagina(
	'Convivencia de componentes',
	'<div class="pagina"><h1>Tres componentes en una página</h1>' .
	$sc->sc_kpi( array() ) .
	$sc->sc_grafico(
		array(
			'view' => 'zonas_riesgo',
			'type' => 'bar',
			'alto' => '300px',
		)
	) .
	$sc->sc_mapa( array( 'alto' => '380px' ) ) .
	'</div>' .
	$sc->sc_3d( array( 'alto' => '500px' ) ),
	array( 'leaflet-vendor', 'uhp', 'uhp-grafico', 'uhp-mapa', 'uhp-3d' ),
	array(
		array( 'vendor-d3plus', false ),
		array( 'vendor-leaflet', false ),
		array( 'uhp-core', false ),
		array( 'uhp-renderer', false ),
		array( 'uhp-grafico', false ),
		array( 'uhp-mapa', false ),
		array( 'uhp-3d', true ),
	)
);

foreach ( $paginas as $nombre => $html ) {
	$ruta = $destino . '/' . $nombre . '.html';
	file_put_contents( $ruta, $html );
	printf( "  %-28s %s\n", $nombre . '.html', size_format( filesize( $ruta ) ) );
}

echo "Listo. Recursos que los shortcodes pidieron encolar:\n";
foreach ( array_keys( $GLOBALS['uhp_test_encolados'] ) as $handle ) {
	echo '  · ' . $handle . "\n";
}
