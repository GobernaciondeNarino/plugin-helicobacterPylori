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
use GobernacionNarino\Urkunina\UHP_Voz;

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

$assets = new UHP_Assets();
$assets->registrar();          // registra el grafo real de dependencias
/* Lo que acaba de engancharse —entre otras cosas `window.UHP`, de donde
   sale la base de la API— pertenece a TODAS las páginas: en WordPress el
   registro corre en cada petición. Se fija como línea base para que la
   limpieza de cada página no se lo lleve. */
uhp_test_fijar_inline_base();
$GLOBALS['uhp_sc'] = new UHP_Shortcodes();
$GLOBALS['uhp_test_faltantes'] = array();

/**
 * Mapea un handle de WordPress a la URL que sirve el servidor de pruebas.
 *
 * Los recursos propios del plugin salen de /assets; los de terceros, del
 * espejo local de tests/vendor. Devolver '' descarta el recurso.
 *
 * @param string $tipo   'script' o 'style'.
 * @param string $handle Handle de WordPress.
 * @return string
 */
function url_de_handle( $tipo, $handle ) {
	$vendor = array(
		'script' => array(
			// d3 suelto: lo usa el tablero, que dibuja su mapa con él.
			'd3'      => '/assets/js/vendor-d3.js',
			'd3plus'  => '/assets/js/vendor-d3plus.js',
			'leaflet' => '/assets/js/vendor-leaflet.js',
			'plotly'  => '',      // registrado pero sin usar todavía
		),
		'style'  => array(
			'leaflet' => '/assets/css/leaflet-vendor.css',
		),
	);

	if ( isset( $vendor[ $tipo ][ $handle ] ) ) {
		return $vendor[ $tipo ][ $handle ];
	}

	// Las tipografías de Google se excluyen a propósito: la suite no debe
	// depender de la red más que para el espejo de Three.js.
	if ( 'uhp-fuentes' === $handle || 'uhp-fuentes-plex' === $handle ) {
		return '';
	}

	if ( 0 === strpos( $handle, 'uhp-' ) ) {
		$archivo = ( 'uhp-base' === $handle ) ? 'uhp' : $handle;
		return '/assets/' . ( 'script' === $tipo ? 'js/' : 'css/' ) . $archivo . ( 'script' === $tipo ? '.js' : '.css' );
	}

	return '';
}

/**
 * Envuelve el marcado de un shortcode en una página completa.
 *
 * Los CSS y los JS NO se listan a mano: se resuelven del grafo de
 * dependencias que el propio plugin declaró y que los shortcodes
 * encolaron. Así la página de prueba carga exactamente lo que cargaría
 * WordPress, ni más ni menos.
 *
 * @param string $titulo Título de la página.
 * @param string $cuerpo Marcado del shortcode.
 * @return string
 */
function pagina( $titulo, $cuerpo, $temprano = null ) {
	$todas = uhp_test_resolver( 'style' );
	// Sin lista temprana, todo al <head>: es el caso de las páginas que no
	// declaran sus shortcodes y no tienen nada que repartir.
	$en_head = ( null === $temprano ) ? $todas : $temprano;

	$hojas = '';
	$tarde = '';
	foreach ( $todas as $handle ) {
		$url = url_de_handle( 'style', $handle );
		if ( '' === $url ) {
			continue;
		}
		$etiqueta = '<link rel="stylesheet" href="' . $url . '" data-handle="' . $handle . '">' . "\n";
		if ( in_array( $handle, $en_head, true ) ) {
			$hojas .= $etiqueta;
		} else {
			$tarde .= $etiqueta;
		}
	}

	$scripts = '';
	foreach ( uhp_test_resolver( 'script' ) as $handle ) {
		$url = url_de_handle( 'script', $handle );
		if ( '' === $url ) {
			continue;
		}
		// El mismo criterio que UHP_Assets::marcar_modulos() en producción.
		$modulo   = ( UHP_Assets::P . '3d' === $handle );
		// Lo que el plugin añade con wp_add_inline_script va donde lo
		// pondría WordPress: antes o después de su etiqueta. Es lo que
		// lleva al navegador window.UHP, window.UHP3D y window.UHPVoz.
		$antes   = uhp_test_inline( $handle, 'before' );
		$despues = uhp_test_inline( $handle, 'after' );

		if ( '' !== $antes ) {
			$scripts .= '<script data-inline="' . $handle . '-before">' . $antes . '</script>' . "\n";
		}
		$scripts .= '<script ' . ( $modulo ? 'type="module" ' : '' ) .
			'src="' . $url . '" data-handle="' . $handle . '"></script>' . "\n";
		if ( '' !== $despues ) {
			$scripts .= '<script data-inline="' . $handle . '-after">' . $despues . '</script>' . "\n";
		}
	}

	// window.UHP, window.UHP3D y window.UHPVoz ya NO se escriben aquí: los
	// emite el propio plugin con wp_add_inline_script y el bucle de arriba
	// los imprime donde WordPress los pondría. Mientras estuvieron
	// escritos a mano, todo lo que viaja por esa vía quedaba fuera de la
	// suite: un módulo que se apaga en su primera línea por falta de
	// configuración habría pasado las pruebas sin ejecutar una sola.

	return '<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>' . htmlspecialchars( $titulo ) . ' — prueba</title>
<!-- Sin Google Fonts a propósito: la suite no debe depender de la red más
     que para el espejo de Three.js, y el CSS del plugin ya declara pila de
     respaldo. -->
' . $hojas . '<style>' . UHP_Estilos::css_global() . '
body{margin:0;font-family:system-ui,sans-serif;background:#fff;}
.pagina{max-width:1400px;margin:0 auto;padding:0 16px;}
</style>
</head>
<body>
' . $cuerpo . '
' . $tarde . $scripts . '</body>
</html>';
}

/**
 * Construye una página aislando lo que encola cada shortcode.
 *
 * @param string   $nombre Nombre del archivo, sin extensión.
 * @param string   $titulo Título de la página.
 * @param callable $pintar Devuelve el marcado; se le pasa UHP_Shortcodes.
 * @return string
 */
function construir( $nombre, $titulo, $pintar, $shortcodes = '' ) {
	uhp_test_limpiar_encolados();
	// Cada página de la suite finge una PETICIÓN nueva, y este proceso
	// pinta diecisiete seguidas. Sin esto, los dos bloques que el plugin
	// imprime «una vez por petición» —las URLs de Three.js y la
	// configuración del asistente— se quedaban con el valor de la primera
	// página y las demás heredaban una configuración que no era la suya.
	UHP_Shortcodes::reiniciar_peticion();

	/* Primero la pasada TEMPRANA, la que en WordPress corre en
	   `wp_enqueue_scripts` antes de que wp_head imprima. Lo que se encole
	   aquí sale en el <head>; lo que encolen los shortcodes al renderizar
	   sale en el pie, que es exactamente lo que hace WordPress.

	   Reproducirlo importa: el generador imprimía TODO en el <head> y por
	   eso la suite nunca vio que en el sitio real las hojas salían en el
	   pie y la página se pintaba en crudo hasta que llegaban. */
	$GLOBALS['uhp_test_contenido'] = $shortcodes;
	if ( '' !== $shortcodes ) {
		$GLOBALS['uhp_sc']->adelantar_hojas();
	}
	$temprano = uhp_test_resolver( 'style' );

	$cuerpo = $pintar( $GLOBALS['uhp_sc'] );
	$GLOBALS['uhp_test_contenido'] = '';

	return pagina( $titulo, $cuerpo, $temprano );
}

/* ------------------------------------------------------------------ */

$paginas = array();

$paginas['objeto-3d'] = construir(
	'objeto-3d',
	'Objeto 3D',
	function ( $sc ) {
		return $sc->sc_3d( array( 'alto' => '100vh' ) );
	},
	'[urkunina_3d]'
);

$paginas['objeto-3d-embebido'] = construir(
	'objeto-3d-embebido',
	'Objeto 3D embebido',
	function ( $sc ) {
		return '<div class="pagina"><h1>Antes de la escena</h1>' .
			'<p>Contenido de la página anfitriona.</p>' .
			$sc->sc_3d( array( 'alto' => '600px' ) ) .
			'<h2>Después de la escena</h2>' .
			'<p>Más contenido para comprobar que la escena no se apodera de la página.</p></div>';
	},
	'[urkunina_3d]'
);

/* El objeto colocado lejos del principio de la página: así se comprueba
   que avanzar la línea de tiempo NO se lleva el scroll del documento y que
   `desplazar="si"` es lo único que lo permite. */
$paginas['objeto-3d-desplazamiento'] = construir(
	'objeto-3d-desplazamiento',
	'Objeto 3D y desplazamiento de la página',
	function ( $sc ) {
		$relleno = '<div style="height:150vh"><h1>Contenido por encima</h1>' .
			'<p>La escena queda fuera de la ventana al cargar.</p></div>';

		return '<div class="pagina">' . $relleno .
			'<div id="quieto">' . $sc->sc_3d(
				array(
					'alto'     => '600px',
					'autoplay' => 'no',
				)
			) . '</div>' . $relleno .
			'<div id="arrastra">' . $sc->sc_3d(
				array(
					'alto'      => '600px',
					'autoplay'  => 'no',
					'desplazar' => 'si',
				)
			) . '</div>' . $relleno . '</div>';
	},
	'[urkunina_3d]'
);

/* El tablero CON el asistente de voz encendido.

   Se configura aquí y no en la opción por defecto porque el asistente
   nace apagado: encenderlo exige, además del interruptor, los cuatro
   campos del aviso de habeas data y la firma de la evaluación de
   accesibilidad. Esta página comprueba justamente eso, que el bloque solo
   aparece cuando la configuración está completa.

   Modo público a propósito: así la sesión no pasa por el servidor y la
   prueba puede ejercitar el módulo entero con un doble del SDK, sin
   inventar una respuesta firmada. */
update_option(
	'uhp_voz',
	array(
		'activo'          => 1,
		'agente'          => 'agent_pruebadelasuite0000',
		'modo'            => 'publico',
		'transporte'      => 'websocket',
		'cuota_dia'       => 200,
		// Esta página ejercita el canal HABLADO y nada más: un solo
		// lanzador, para que las pruebas que ya existían sigan apuntando a
		// un único botón. Los dos canales y el escrito a secas tienen cada
		// uno su propia página más abajo.
		'entrada'         => 'voz',
		'texto'           => 1,
		'recordar'        => 1,
		'aviso_version'   => 1,
		'politica'        => 'https://ejemplo.narino.gov.co/politica',
		'correo'          => 'datos@narino.gov.co',
		'canal'           => 'Calle 19 No. 23-78, Pasto',
		'retencion'       => 'El proveedor declara conservar las grabaciones 30 días.',
		'aviso_extra'     => '',
		'evaluacion_a11y' => 1,
	)
);

$paginas['voz'] = construir(
	'voz',
	'Tablero con asistente de voz',
	function ( $sc ) {
		return $sc->sc_dashboard( array() );
	},
	'[urkunina_dashboard]'
);

/* La misma configuración, con los DOS canales y con sustituciones de voz
   puestas: dos lanzadores, dos avisos distintos en el HTML y un evento de
   inicio que lleva overrides.tts. */
update_option(
	'uhp_voz',
	array_merge(
		get_option( 'uhp_voz' ),
		array(
			'entrada'       => 'ambos',
			'voz_id'        => 'VozDePruebaDeLaSuite00',
			'voz_velocidad' => 0.9,
		)
	)
);

$paginas['voz-dos'] = construir(
	'voz-dos',
	'Tablero con los dos canales',
	function ( $sc ) {
		return $sc->sc_dashboard( array() );
	},
	'[urkunina_dashboard]'
);

/* Y solo escrito. Aquí no tiene que haber ni botón de micrófono, ni
   aviso de voz en el HTML, ni llamada a getUserMedia. */
update_option(
	'uhp_voz',
	array_merge(
		get_option( 'uhp_voz' ),
		array( 'entrada' => 'texto' )
	)
);

$paginas['voz-texto'] = construir(
	'voz-texto',
	'Tablero con asistente escrito',
	function ( $sc ) {
		return $sc->sc_dashboard( array() );
	},
	'[urkunina_dashboard]'
);

/* Y el modo PRIVADO, que es el recomendado y hasta ahora no tenía página:
   el navegador no conoce el identificador del agente y abre la sesión con
   la URL firmada que le entrega el servidor. Sin verificación guardada, no
   viaja ninguna sustitución. */
update_option(
	'uhp_voz',
	array_merge(
		get_option( 'uhp_voz' ),
		array(
			'modo'    => 'privado',
			'entrada' => 'ambos',
		)
	)
);
/* Un agente privado sin clave no se publica: publicar el botón sería
   ofrecer algo que falla al pulsarlo. Y de paso, esta página es la que
   comprueba que la clave NO aparece en el HTML. */
update_option( 'uhp_voz_clave', 'sk_clave_que_no_debe_salir_nunca' );

$paginas['voz-privado'] = construir(
	'voz-privado',
	'Tablero con asistente privado',
	function ( $sc ) {
		return $sc->sc_dashboard( array() );
	},
	'[urkunina_dashboard]'
);

delete_option( 'uhp_voz_clave' );

// El resto de páginas vuelven a verlo apagado: el tablero de siempre no
// puede cambiar porque exista esta función.
update_option( 'uhp_voz', UHP_Voz::por_defecto() );

$paginas['tablero'] = construir(
	'tablero',
	'Tablero',
	function ( $sc ) {
		return $sc->sc_dashboard( array() );
	},
	'[urkunina_dashboard]'
);

$paginas['graficos'] = construir(
	'graficos',
	'Gráficos',
	function ( $sc ) {
		$vistas = array(
			array( 'tamizaje_hp', 'donut' ),
			array( 'prev_lpm_municipios', 'bar' ),
			array( 'prev_subregion', 'bar' ),
			array( 'tamizaje_comparado', 'stacked_bar' ),
			array( 'publicaciones_anio', 'line' ),
			array( 'biobanco_tipos', 'treemap' ),
			array( 'metas_mga', 'bar' ),
			array( 'perfil_etnia', 'pie' ),
		);
		$html = '';
		foreach ( $vistas as $par ) {
			$html .= $sc->sc_grafico(
				array(
					'view' => $par[0],
					'type' => $par[1],
					'alto' => '360px',
				)
			);
		}
		return '<div class="pagina">' . $html . '</div>';
	},
	'[urkunina_grafico]'
);

$paginas['maqueta'] = construir(
	'maqueta',
	'Gráfico y textos maquetados por separado',
	function ( $sc ) {
		$vista = 'tamizaje_hp';

		// El caso de uso que motiva la separación: el gráfico en una
		// columna y su lectura en otra. Ninguna de las dos piezas sabe de
		// la otra, que es justo lo que permite maquetarlas por libre.
		return '<div class="pagina">' .
			$sc->sc_titulo(
				array(
					'view'     => $vista,
					'etiqueta' => 'h2',
				)
			) .
			'<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;align-items:start;">' .
			'<div data-columna="grafico">' .
			$sc->sc_grafico(
				array(
					'view'   => $vista,
					'type'   => 'donut',
					'alto'   => '360px',
					'titulo' => 'no',
				)
			) .
			'</div>' .
			'<div data-columna="texto">' .
			$sc->sc_descripcion( array( 'view' => $vista ) ) .
			$sc->sc_interpretacion( array( 'view' => $vista ) ) .
			$sc->sc_resumen( array( 'view' => $vista ) ) .
			$sc->sc_cifras( array( 'view' => $vista ) ) .
			$sc->sc_fuente( array( 'view' => $vista ) ) .
			'</div></div>' .
			// El atajo agrupado, para comprobar que sigue funcionando.
			'<div data-columna="grupo">' .
			$sc->sc_analisis(
				array(
					'view' => 'prev_subregion',
					'modo' => 'completo',
				)
			) .
			'</div></div>';
	},
	'[urkunina_titulo][urkunina_grafico][urkunina_descripcion][urkunina_interpretacion][urkunina_resumen][urkunina_cifras][urkunina_fuente][urkunina_analisis]'
);

$paginas['geomapa'] = construir(
	'geomapa',
	'Geomapas de D3plus',
	function ( $sc ) {
		// Los dos modos que pide el enunciado: con capa base y sin ella.
		return '<div class="pagina">' .
			'<div data-caso="sin-teselas">' .
			$sc->sc_geomapa(
				array(
					'view'    => 'prev_lpm_municipios',
					'alto'    => '420px',
					'teselas' => 'no',
				)
			) .
			'</div>' .
			'<div data-caso="con-teselas">' .
			$sc->sc_geomapa(
				array(
					'indicador' => 'intervencion',
					'alto'      => '420px',
					'teselas'   => 'si',
				)
			) .
			'</div>' .
			'<div data-caso="oscuro">' .
			$sc->sc_geomapa(
				array(
					'view'    => 'cancer_municipios',
					'alto'    => '360px',
					'tema'    => 'oscuro',
					'leyenda' => 'no',
				)
			) .
			'</div>' .
			'<div data-caso="serie-55">' .
			$sc->sc_geomapa(
				array(
					'view'    => 'prev_lpm_55',
					'alto'    => '420px',
					'teselas' => 'no',
				)
			) .
			'</div>' .
			'<div data-caso="subregiones">' .
			$sc->sc_geomapa(
				array(
					'view' => 'prev_subregion_lpm',
					'alto' => '420px',
				)
			) .
			'</div>' .
			// Vista con dos indicadores por subregión: el mapa dibuja uno y
			// lo dice en el título.
			'<div data-caso="serie">' .
			$sc->sc_geomapa(
				array(
					'view'  => 'prev_subregion',
					'serie' => 'Infección por H. pylori',
					'alto'  => '360px',
				)
			) .
			'</div>' .
			// Una vista sin territorio con geometría: avisa, no falla.
			'<div data-caso="no-territorial">' .
			$sc->sc_geomapa( array( 'view' => 'perfil_etnia' ) ) .
			'</div></div>';
	},
	'[urkunina_geomapa]'
);

$paginas['tablero-embebido'] = construir(
	'tablero-embebido',
	'Tablero dentro de una página con más contenido',
	function ( $sc ) {
		// El tablero define su propia retícula, su propia tipografía y su
		// propio fondo. Esta página comprueba que nada de eso se escapa al
		// contenido de alrededor.
		return '<div class="pagina"><h1>Antes del tablero</h1>' .
			'<p>Contenido de la página anfitriona.</p></div>' .
			$sc->sc_dashboard( array( 'alto' => '640px' ) ) .
			'<div class="pagina"><h2>Después del tablero</h2>' .
			'<p>Más contenido, para comprobar que el tablero no se apodera de la página.</p></div>';
	},
	'[urkunina_dashboard]'
);

$paginas['selector'] = construir(
	'selector',
	'Una lista que gobierna título, textos, tabla y gráfico',
	function ( $sc ) {
		// El caso del enunciado: la tarjeta que agrupa toda una pestaña,
		// repartida en piezas sueltas para poder maquetarla.
		$g = array( 'grupo' => 'Prevalencia' );

		return '<div class="pagina">' .
			'<div data-zona="selector">' .
			$sc->sc_selector( array_merge( $g, array( 'titulo' => 'Vistas de prevalencia' ) ) ) .
			'</div>' .
			'<div data-zona="titulo">' . $sc->sc_titulo( array_merge( $g, array( 'etiqueta' => 'h2' ) ) ) . '</div>' .
			'<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;align-items:start;">' .
			'<div data-zona="grafico">' .
			$sc->sc_grafico( array_merge( $g, array( 'alto' => '360px', 'titulo' => 'no' ) ) ) .
			'</div>' .
			'<div data-zona="textos">' .
			$sc->sc_descripcion( $g ) .
			$sc->sc_interpretacion( $g ) .
			$sc->sc_resumen( $g ) .
			$sc->sc_cifras( $g ) .
			$sc->sc_fuente( $g ) .
			'</div></div>' .
			'<div data-zona="tabla">' . $sc->sc_tabla( $g ) . '</div>' .

			// Un segundo canal en la misma página: no debe interferir.
			'<hr>' .
			'<div data-zona="otro-canal">' .
			$sc->sc_selector( array( 'grupo' => 'Tamizaje' ) ) .
			$sc->sc_titulo( array( 'grupo' => 'Tamizaje', 'etiqueta' => 'h2' ) ) .
			$sc->sc_descripcion( array( 'grupo' => 'Tamizaje' ) ) .
			'</div>' .

			// Lista explícita y canal propio, con la vista inicial elegida.
			'<div data-zona="lista">' .
			$sc->sc_selector(
				array(
					'views' => 'mortalidad_anio,acceso_oncologico,zonas_riesgo',
					'canal' => 'contexto',
					'view'  => 'acceso_oncologico',
				)
			) .
			$sc->sc_titulo( array( 'views' => 'mortalidad_anio,acceso_oncologico,zonas_riesgo', 'canal' => 'contexto', 'view' => 'acceso_oncologico' ) ) .
			'</div>' .

			// Un canal de SOLO selector y gráfico, sin paneles de texto con
			// los que comparar, y con el selector delante: es el orden que
			// hace que el script del selector se imprima antes que el del
			// gráfico y que un aviso inmediato se perdiera.
			'<div data-zona="solo-grafico">' .
			$sc->sc_selector(
				array(
					'views'       => 'cancer_municipios,cancer_desenlace',
					'canal'       => 'casos',
					'descripcion' => 'no',
				)
			) .
			$sc->sc_grafico(
				array(
					'views' => 'cancer_municipios,cancer_desenlace',
					'canal' => 'casos',
					'alto'  => '320px',
				)
			) .
			'</div>' .

			// Un selector sin grupo: avisa, no rompe la página.
			'<div data-zona="sin-grupo">' . $sc->sc_selector( array() ) . '</div>' .
			'</div>';
	},
	'[urkunina_selector][urkunina_titulo][urkunina_grafico][urkunina_descripcion][urkunina_interpretacion][urkunina_resumen][urkunina_cifras][urkunina_fuente][urkunina_tabla]'
);

$paginas['grafico-mapa'] = construir(
	'grafico-mapa',
	'El mapa como un tipo de gráfico más',
	function ( $sc ) {
		return '<div class="pagina">' .
			// Arranca en mapa: el shortcode elige qué tipo se ve primero.
			'<div data-caso="arranca-en-mapa">' .
			$sc->sc_grafico(
				array(
					'view' => 'prev_lpm_municipios',
					'type' => 'mapa',
					'alto' => '420px',
				)
			) .
			'</div>' .
			// Arranca en barras, pero el mapa está en la barra de tipos.
			'<div data-caso="arranca-en-barras">' .
			$sc->sc_grafico(
				array(
					'view' => 'cancer_municipios',
					'type' => 'bar',
					'alto' => '360px',
				)
			) .
			'</div>' .
			// Subregional, con teselas y en oscuro.
			'<div data-caso="subregion-teselas">' .
			$sc->sc_grafico(
				array(
					'view'    => 'prev_subregion_lpm',
					'type'    => 'mapa',
					'tema'    => 'oscuro',
					'teselas' => 'si',
					'alto'    => '420px',
				)
			) .
			'</div>' .
			// Vista partida en series: el shortcode elige cuál se pinta.
			'<div data-caso="serie">' .
			$sc->sc_grafico(
				array(
					'view'  => 'prev_subregion',
					'type'  => 'mapa',
					'serie' => 'Infección por H. pylori',
					'alto'  => '360px',
				)
			) .
			'</div>' .
			// Vista sin geometría: el mapa NO debe aparecer entre los tipos.
			'<div data-caso="sin-geo">' .
			$sc->sc_grafico(
				array(
					'view' => 'perfil_etnia',
					'type' => 'pie',
					'alto' => '320px',
				)
			) .
			'</div></div>';
	},
	'[urkunina_grafico]'
);

$paginas['mapa'] = construir(
	'mapa',
	'Mapa',
	function ( $sc ) {
		return '<div class="pagina">' . $sc->sc_mapa( array( 'alto' => '560px' ) ) . '</div>';
	},
	'[urkunina_mapa]'
);

$paginas['servidor'] = construir(
	'servidor',
	'Componentes de servidor',
	function ( $sc ) {
		return '<div class="pagina">' .
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
			) . '</p></div>';
	},
	'[urkunina_kpi][urkunina_ficha][urkunina_tabla][urkunina_dato]'
);

$paginas['convivencia'] = construir(
	'convivencia',
	'Convivencia de componentes',
	function ( $sc ) {
		return '<div class="pagina"><h1>Tres componentes en una página</h1>' .
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
			$sc->sc_3d( array( 'alto' => '500px' ) );
	},
	'[urkunina_kpi][urkunina_grafico][urkunina_mapa][urkunina_3d]'
);

foreach ( $paginas as $nombre => $html ) {
	$ruta = $destino . '/' . $nombre . '.html';
	file_put_contents( $ruta, $html );
	printf( "  %-28s %s\n", $nombre . '.html', size_format( filesize( $ruta ) ) );
}

// Una dependencia declarada pero no registrada haría que WordPress omitiera
// el recurso en silencio. Aquí se convierte en un fallo ruidoso.
if ( ! empty( $GLOBALS['uhp_test_faltantes'] ) ) {
	echo "\nDependencias declaradas que nadie registró:\n";
	foreach ( array_unique( $GLOBALS['uhp_test_faltantes'] ) as $f ) {
		echo '  · ' . $f . "\n";
	}
	exit( 1 );
}

echo "Listo.\n";
