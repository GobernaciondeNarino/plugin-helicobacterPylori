<?php
/**
 * Pruebas de la capa de datos, sin WordPress.
 *
 * Comprueba que los quince archivos de /data se leen y validan, que las
 * 24 vistas del catálogo producen filas con la forma que declaran, que el
 * análisis automático se redacta y que el cruce de municipios con la
 * geometría del DANE es completo.
 *
 * Uso:  php tests/test-datos.php
 *
 * @package Urkunina5000
 */

require_once __DIR__ . '/stubs-wordpress.php';
uhp_cargar_shortcodes();   // carga también las clases de datos

use GobernacionNarino\Urkunina\UHP_Datos;
use GobernacionNarino\Urkunina\UHP_Municipios;
use GobernacionNarino\Urkunina\UHP_Views;
use GobernacionNarino\Urkunina\UHP_Rest;
use GobernacionNarino\Urkunina\UHP_Security;
use GobernacionNarino\Urkunina\UHP_Topojson;
use GobernacionNarino\Urkunina\UHP_Subregiones;

$pruebas = 0;
$fallos  = array();

/**
 * Afirma una condición.
 *
 * @param bool   $condicion Resultado esperado como verdadero.
 * @param string $mensaje   Descripción de lo que se comprueba.
 */
function comprobar( $condicion, $mensaje ) {
	global $pruebas, $fallos;
	$pruebas++;
	if ( $condicion ) {
		echo "  \033[32m✓\033[0m " . $mensaje . "\n";
		return;
	}
	$fallos[] = $mensaje;
	echo "  \033[31m✗\033[0m " . $mensaje . "\n";
}

echo "\n\033[1mURKUNINA 5000 — pruebas de la capa de datos\033[0m\n";

/* ---------------------------------------------------------------- */
echo "\n1. Archivos del conjunto\n";

$registro = UHP_Datos::registro();
comprobar(
	16 === count( $registro ),
	sprintf( 'El registro declara los 14 archivos JSON y las dos capas de geometría (%d entradas)', count( $registro ) )
);

// La cartografía tiene su propio tope de tamaño: si volviera a compartirlo
// con los archivos de cifras, el de subregiones dejaría de validar.
comprobar(
	UHP_Datos::tope_bytes( 'geojson_subregiones' ) > UHP_Datos::tope_bytes( 'tamizaje' ),
	'La geometría admite un tamaño mayor que un archivo de cifras'
);

foreach ( $registro as $clave => $meta ) {
	$ruta = UHP_Datos::dir() . $meta['archivo'];
	comprobar( is_readable( $ruta ), sprintf( '%s existe y es legible', $meta['archivo'] ) );
}

/* ---------------------------------------------------------------- */
echo "\n2. Validación del contrato de cada archivo\n";

foreach ( $registro as $clave => $meta ) {
	$crudo = file_get_contents( UHP_Datos::dir() . $meta['archivo'] );
	$val   = UHP_Datos::validar( $clave, $crudo );
	comprobar(
		$val['ok'],
		sprintf(
			'%s cumple su contrato%s',
			$meta['archivo'],
			$val['ok'] ? '' : ' — ' . implode( ' ', $val['errores'] )
		)
	);
}

/* ---------------------------------------------------------------- */
echo "\n3. Rechazo de contenido inválido\n";

$casos = array(
	'JSON mal formado'       => '{"proyecto": ',
	'documento raíz escalar' => '"solo una cadena"',
	'contenido vacío'        => '   ',
	'sin claves obligatorias' => '{"_meta":{},"otra_cosa":1}',
);
foreach ( $casos as $nombre => $contenido ) {
	$val = UHP_Datos::validar( 'tamizaje', $contenido );
	comprobar( ! $val['ok'], sprintf( 'Se rechaza: %s', $nombre ) );
}

comprobar(
	'' === UHP_Security::sanitizar_archivo_datos( '../../wp-config.php' ),
	'Un salto de directorio no supera la lista blanca de archivos'
);
comprobar(
	'' === UHP_Security::sanitizar_archivo_datos( 'inventado.json' ),
	'Un archivo fuera del registro no supera la lista blanca'
);
comprobar(
	'00_manifiesto.json' === UHP_Security::sanitizar_archivo_datos( '00_manifiesto.json' ),
	'Un archivo del registro sí supera la lista blanca'
);

/* ---------------------------------------------------------------- */
echo "\n4. Saneado de valores CSS\n";

$peligrosos = array(
	'url(javascript:alert(1))'      => 'url(',
	'red;} body{display:none'       => ';',
	'expression(alert(1))'          => 'expression(',
	"red\"><script>alert(1)</script>" => '<',
	// Las comillas se eliminan aunque hoy todos los destinos escapen con
	// esc_attr(): sin ellas, un punto de inserción futuro sin escapar no
	// puede convertirse en XSS.
	"red' onmouseover='alert(1)"    => "'",
	'red" onmouseover="alert(1)'    => '"',
);
foreach ( $peligrosos as $entrada => $fragmento ) {
	$limpio = GobernacionNarino\Urkunina\UHP_Estilos::sanitizar_css( $entrada );
	comprobar(
		false === strpos( $limpio, $fragmento ),
		sprintf( 'Se neutraliza «%s» en un valor CSS', $fragmento )
	);
}

// Los valores legítimos deben sobrevivir intactos: un saneador que
// rompe rgba() o calc() es un saneador que nadie usará.
foreach ( array( 'rgba(16,161,59,.5)', 'calc(100vh - 80px)', '#10A13B', 'clamp(14px,1.6vw,22px)' ) as $legitimo ) {
	comprobar(
		$legitimo === GobernacionNarino\Urkunina\UHP_Estilos::sanitizar_css( $legitimo ),
		sprintf( 'Se conserva intacto el valor legítimo «%s»', $legitimo )
	);
}

/* ---------------------------------------------------------------- */
echo "\n5. Municipios y geometría\n";

$indice = UHP_Municipios::indice();
comprobar( 64 === count( $indice ), 'La geometría trae los 64 municipios de Nariño' );

$priorizados = UHP_Municipios::priorizados();
comprobar( 55 === count( $priorizados ), 'El proyecto declara 55 municipios priorizados' );

$sin_cruce = array_filter(
	$priorizados,
	static function ( $m ) {
		return '' === $m['divipola'];
	}
);
comprobar(
	0 === count( $sin_cruce ),
	sprintf(
		'Los 55 municipios priorizados cruzan con la geometría%s',
		$sin_cruce ? ' — sin cruce: ' . implode( ', ', wp_list_pluck( $sin_cruce, 'municipio' ) ) : ''
	)
);

comprobar(
	'52683' === UHP_Municipios::divipola_de( 'Sandoná' ),
	'El cruce resuelve un nombre con tilde'
);
comprobar(
	'52203' === UHP_Municipios::divipola_de( 'Colón (Génova)' ),
	'El cruce descarta el nombre popular entre paréntesis'
);
comprobar(
	'52418' === UHP_Municipios::divipola_de( 'Los Andes (Sotomayor)' ),
	'El cruce resuelve «Los Andes (Sotomayor)»'
);
comprobar(
	'departamento' === UHP_Security::sanitizar_divipola( 'Municipio Inventado' ),
	'Un municipio inexistente cae a «departamento»'
);

/* ---------------------------------------------------------------- */
echo "\n5b. Topología para D3plus Geomap\n";

$topo = UHP_Topojson::municipios( true );

comprobar( 'Topology' === $topo['type'], 'La conversión produce un objeto Topology' );
comprobar(
	isset( $topo['objects'][ UHP_Topojson::OBJETO ]['geometries'] ),
	sprintf( 'La topología expone el objeto «%s»', UHP_Topojson::OBJETO )
);

$geometrias = $topo['objects'][ UHP_Topojson::OBJETO ]['geometries'];
comprobar( 64 === count( $geometrias ), 'La topología trae los 64 municipios' );

$ids = wp_list_pluck( $geometrias, 'id' );
comprobar(
	count( $ids ) === count( array_unique( $ids ) ),
	'Cada municipio aparece una sola vez'
);
comprobar(
	0 === count( array_diff( $ids, array_keys( UHP_Municipios::indice() ) ) ),
	'Todos los identificadores son DIVIPOLA conocidos'
);

comprobar(
	isset( $topo['transform']['scale'], $topo['transform']['translate'] ),
	'La topología va cuantizada, con su transformación'
);

// La extensión tiene que ser la de Nariño: si la conversión se comiera un
// signo o mezclara los ejes, la caja se iría del departamento.
list( $bx0, $by0, $bx1, $by1 ) = $topo['bbox'];
comprobar(
	$bx0 > -80 && $bx1 < -76 && $by0 > 0 && $by1 < 3,
	sprintf( 'La caja envolvente cae sobre Nariño (%.2f, %.2f) — (%.2f, %.2f)', $bx0, $by0, $bx1, $by1 )
);

/* Sentido de giro: exterior HORARIO, que es lo que espera D3 y lo contrario
   de lo que dice el RFC 7946 de GeoJSON. Un anillo al revés se dibuja como
   el mundo entero menos el municipio. Se comprueba decodificando la
   topología igual que hace topojson-client. */
$area_anillo = static function ( array $anillo ) {
	$a = 0.0;
	$n = count( $anillo );
	for ( $i = 0, $j = $n - 1; $i < $n; $j = $i, $i++ ) {
		$a += ( $anillo[ $j ][0] * $anillo[ $i ][1] ) - ( $anillo[ $i ][0] * $anillo[ $j ][1] );
	}
	return $a / 2;
};

$decodificar = static function ( $indice ) use ( $topo ) {
	$sx = $topo['transform']['scale'][0];
	$sy = $topo['transform']['scale'][1];
	$tx = $topo['transform']['translate'][0];
	$ty = $topo['transform']['translate'][1];
	$x  = 0;
	$y  = 0;
	$out = array();
	foreach ( $topo['arcs'][ $indice ] as $d ) {
		$x    += $d[0];
		$y    += $d[1];
		$out[] = array( $x * $sx + $tx, $y * $sy + $ty );
	}
	return $out;
};

$invertidos = 0;
$abiertos   = 0;
foreach ( $geometrias as $g ) {
	$poligonos = ( 'Polygon' === $g['type'] ) ? array( $g['arcs'] ) : $g['arcs'];
	foreach ( $poligonos as $k => $poligono ) {
		foreach ( $poligono as $i => $anillo ) {
			$puntos = $decodificar( $anillo[0] );
			if ( 0 === $i && $area_anillo( $puntos ) > 0 ) {
				$invertidos++;   // exterior antihorario: al revés para D3.
			}
			$primero = $puntos[0];
			$ultimo  = $puntos[ count( $puntos ) - 1 ];
			if ( abs( $primero[0] - $ultimo[0] ) > 1e-6 || abs( $primero[1] - $ultimo[1] ) > 1e-6 ) {
				$abiertos++;
			}
		}
	}
}
comprobar( 0 === $invertidos, 'Todos los anillos exteriores giran en el sentido que espera D3' );
comprobar( 0 === $abiertos, 'Todos los anillos quedan cerrados tras cuantizar' );

// El error de cuantización tiene que ser inapreciable: se compara el área
// de cada municipio con la del GeoJSON original.
$geo_original = UHP_Datos::leer( 'geojson' );
$area_de      = static function ( $geometria ) use ( $area_anillo ) {
	$poligonos = ( 'Polygon' === $geometria['type'] )
		? array( $geometria['coordinates'] )
		: $geometria['coordinates'];
	$total = 0.0;
	foreach ( $poligonos as $poligono ) {
		foreach ( $poligono as $i => $anillo ) {
			$a      = abs( $area_anillo( $anillo ) );
			$total += ( 0 === $i ) ? $a : -$a;
		}
	}
	return $total;
};

$originales = array();
foreach ( (array) $geo_original['features'] as $f ) {
	if ( ! empty( $f['properties']['MPIO_CDPMP'] ) ) {
		$originales[ (string) $f['properties']['MPIO_CDPMP'] ] = $area_de( $f['geometry'] );
	}
}

$peor = 0.0;
foreach ( $geometrias as $g ) {
	$poligonos = ( 'Polygon' === $g['type'] ) ? array( $g['arcs'] ) : $g['arcs'];
	$area      = 0.0;
	foreach ( $poligonos as $poligono ) {
		foreach ( $poligono as $i => $anillo ) {
			$a     = abs( $area_anillo( $decodificar( $anillo[0] ) ) );
			$area += ( 0 === $i ) ? $a : -$a;
		}
	}
	$ref = isset( $originales[ $g['id'] ] ) ? $originales[ $g['id'] ] : 0;
	if ( $ref > 0 ) {
		$peor = max( $peor, abs( $area - $ref ) / $ref );
	}
}
comprobar(
	$peor < 0.005,
	sprintf( 'La cuantización no deforma los municipios (peor error: %.4f %%)', $peor * 100 )
);

/* ---------------------------------------------------------------- */
echo "\n5bis. Subregiones y su disuelto\n";

$subs = UHP_Subregiones::indice();
comprobar( 13 === count( $subs ), 'El departamento se divide en 13 subregiones' );

$total_municipios = 0;
foreach ( $subs as $sr ) {
	$total_municipios += count( $sr['municipios'] );
}
comprobar(
	64 === $total_municipios,
	sprintf( 'Las subregiones reparten los 64 municipios (suman %d)', $total_municipios )
);

$por_municipio = UHP_Subregiones::por_municipio();
comprobar( 64 === count( $por_municipio ), 'Cada municipio declara su subregión' );
comprobar(
	'Centro' === $por_municipio['52001']['nombre'],
	'Pasto pertenece a la subregión Centro'
);

// El cruce por nombre es el punto frágil: los informes escriben
// «Piedemonte Costero» y «La Sabana» donde la cartografía dice otra cosa.
comprobar(
	'pie_de_monte_costero' === UHP_Subregiones::codigo_de( 'Piedemonte Costero' ),
	'El cruce resuelve «Piedemonte Costero»'
);
comprobar(
	'sabana' === UHP_Subregiones::codigo_de( 'La Sabana' ),
	'El cruce descarta el artículo inicial de «La Sabana»'
);
comprobar(
	'rio_mayo' === UHP_Subregiones::codigo_de( 'Río Mayo' ),
	'El cruce resuelve un nombre con tilde'
);
comprobar(
	'' === UHP_Subregiones::codigo_de( 'Subregión Inventada' ),
	'Una subregión inexistente no cruza'
);

$sin_cruce = array();
foreach ( (array) UHP_Datos::valor( 'prev_subregional', 'subregiones', array() ) as $fila ) {
	if ( '' === UHP_Subregiones::codigo_de( $fila['subregion'] ) ) {
		$sin_cruce[] = $fila['subregion'];
	}
}
comprobar(
	0 === count( $sin_cruce ),
	sprintf(
		'Las 11 subregiones con dato cruzan con la geometría%s',
		$sin_cruce ? ' — sin cruce: ' . implode( ', ', $sin_cruce ) : ''
	)
);

$topo_sub = UHP_Topojson::subregiones( true );
$geo_sub  = $topo_sub['objects'][ UHP_Topojson::OBJETO_SUB ]['geometries'];
comprobar( 13 === count( $geo_sub ), 'La topología subregional trae las 13 subregiones' );

/* El disuelto es lo que hace publicable esta capa: la geometría subregional
   del archivo pesa cerca de un megabyte y la reconstruida no llega a
   cuarenta kilobytes. Si la unión por cancelación de aristas dejara de
   funcionar, cada subregión traería un polígono por municipio. */
$peso_sub = strlen( wp_json_encode( $topo_sub ) );
comprobar(
	$peso_sub < 120 * 1024,
	sprintf( 'La topología subregional cabe en una página (%s)', size_format( $peso_sub ) )
);

$municipios_por_sub = array();
foreach ( $subs as $codigo => $sr ) {
	$municipios_por_sub[ $codigo ] = count( $sr['municipios'] );
}
$sin_disolver = array();
foreach ( $geo_sub as $g ) {
	$poligonos = ( 'Polygon' === $g['type'] ) ? 1 : count( $g['arcs'] );
	$esperados = isset( $municipios_por_sub[ $g['id'] ] ) ? $municipios_por_sub[ $g['id'] ] : 0;
	// Pacífico Sur suma sus islas, de modo que dos o tres polígonos son
	// legítimos; tantos como municipios significa que no se disolvió.
	if ( $esperados > 2 && $poligonos >= $esperados ) {
		$sin_disolver[] = $g['id'];
	}
}
comprobar(
	0 === count( $sin_disolver ),
	sprintf(
		'Los municipios se disuelven en el contorno de su subregión%s',
		$sin_disolver ? ' — sin disolver: ' . implode( ', ', $sin_disolver ) : ''
	)
);

comprobar(
	'subregiones' === UHP_Topojson::objeto( 'subregion' )
		&& 'municipios' === UHP_Topojson::objeto( 'municipio' ),
	'Cada nivel nombra su propio objeto de topología'
);

/* ---------------------------------------------------------------- */
echo "\n5c. Vistas que pueden llevarse al mapa\n";

$territoriales = UHP_Views::territoriales();
comprobar( count( $territoriales ) >= 4, sprintf( '%d vistas nombran municipios', count( $territoriales ) ) );

foreach ( $territoriales as $t ) {
	$valores = UHP_Rest::valores_vista( $t['id'] );
	comprobar(
		count( $valores ) > 0,
		sprintf( 'La vista «%s» resuelve %d territorios de nivel %s', $t['id'], count( $valores ), $t['nivel'] )
	);
	$conocidos = ( 'subregion' === $t['nivel'] )
		? array_keys( UHP_Subregiones::indice() )
		: array_keys( UHP_Municipios::indice() );
	$fuera     = array_diff( array_keys( $valores ), $conocidos );
	comprobar(
		0 === count( $fuera ),
		sprintf( 'La vista «%s» solo usa códigos de %s conocidos', $t['id'], $t['nivel'] )
	);
}

comprobar(
	! UHP_Views::es_territorial( 'perfil_etnia' ),
	'Una vista sin territorio no se declara territorial'
);
comprobar(
	'subregion' === UHP_Views::nivel( 'prev_subregion_lpm' )
		&& 'municipio' === UHP_Views::nivel( 'prev_lpm_municipios' ),
	'Cada vista territorial declara su nivel'
);

$carga_sub = UHP_Rest::carga_geomapa( 'prev_subregion_lpm' );
comprobar(
	'subregion' === $carga_sub['nivel'] && 'subregiones' === $carga_sub['objeto'],
	'Una vista subregional pide la topología de subregiones'
);
comprobar(
	11 === count( $carga_sub['valores'] ),
	sprintf( 'La vista subregional colorea %d de las 13 subregiones', count( $carga_sub['valores'] ) )
);
comprobar(
	'Pie de Monte Costero' === $carga_sub['valores']['pie_de_monte_costero']['nombre'],
	'El mapa usa el nombre cartográfico de la subregión, no el del informe'
);

$carga = UHP_Rest::carga_geomapa( 'prev_lpm_municipios' );
comprobar( 'vista' === $carga['origen'], 'La carga del geomapa distingue el origen «vista»' );
comprobar( ! empty( $carga['meta']['escala'] ), 'La carga del geomapa trae su rampa de color' );
comprobar(
	UHP_Topojson::OBJETO === $carga['objeto'],
	'La carga del geomapa nombra el objeto de la topología'
);

$carga_ind = UHP_Rest::carga_geomapa( '', 'inventado' );
comprobar(
	'lpm' === $carga_ind['clave'],
	'Un indicador inexistente cae al indicador por defecto'
);

/* ---------------------------------------------------------------- */
echo "\n5d. Tema del tablero\n";

$sc = new GobernacionNarino\Urkunina\UHP_Shortcodes();

$oscuro = $sc->sc_dashboard( array() );
$claro  = $sc->sc_dashboard( array( 'tema' => 'claro' ) );
$raro   = $sc->sc_dashboard( array( 'tema' => 'fucsia' ) );

comprobar(
	false !== strpos( $oscuro, 'uhp-db--oscuro' ) && false !== strpos( $oscuro, 'data-tema="oscuro"' ),
	'Sin atributo, el tablero sale en el tema oscuro'
);
comprobar(
	false !== strpos( $claro, 'uhp-db--claro' ) && false !== strpos( $claro, 'data-tema="claro"' ),
	'tema="claro" marca el tablero con su clase y su atributo'
);
comprobar(
	false !== strpos( $raro, 'uhp-db--oscuro' ),
	'Un tema desconocido cae al oscuro, que es la identidad del proyecto'
);

// La capa base sigue al tema salvo que se pida una concreta: un tablero
// claro con teselas oscuras es el descuido más fácil al cambiar el tema.
comprobar(
	false !== strpos( $claro, 'data-teselas="claro"' ),
	'El tablero claro pide la capa base clara'
);
comprobar(
	false !== strpos( $oscuro, 'data-teselas="oscuro"' ),
	'El tablero oscuro pide la capa base oscura'
);
$forzado = $sc->sc_dashboard(
	array(
		'tema'    => 'claro',
		'teselas' => 'humanitario',
	)
);
comprobar(
	false !== strpos( $forzado, 'data-teselas="humanitario"' ),
	'Una capa base pedida a mano manda sobre la del tema'
);

/* La hoja del tablero tiene una regla: ni un color literal fuera del
   bloque de tokens. Es lo que permite que el tema claro se limite a
   redefinirlos, y lo que evita que un color se quede oscuro por descuido. */
$hoja = file_get_contents( UHP_DIR . 'assets/css/uhp-dashboard.css' );
$tras = substr( $hoja, strpos( $hoja, '/* Foco visible' ) );
preg_match_all( '/#[0-9a-fA-F]{3,8}\b|rgba?\([^)]*\)/', $tras, $literales );
$colados = array_values(
	array_filter(
		$literales[0],
		static function ( $c ) {
			// La parada transparente de la franja de identidad es la misma
			// en los dos temas: no hay nada que retematizar.
			return 'rgba(255, 213, 0, 0)' !== $c;
		}
	)
);
comprobar(
	0 === count( $colados ),
	sprintf(
		'La hoja del tablero no usa colores literales fuera de los tokens%s',
		$colados ? ' — colados: ' . implode( ', ', array_unique( $colados ) ) : ''
	)
);

// Y el tema claro tiene que redefinir TODOS los tokens de color, no solo
// algunos: uno olvidado se queda oscuro sobre fondo blanco.
preg_match( '/\.uhp-db \{(.*?)\n\}/s', $hoja, $bloque_oscuro );
preg_match( '/\.uhp-db--claro \{(.*?)\n\}/s', $hoja, $bloque_claro );
preg_match_all( '/--uhp-db-[a-z0-9-]+(?=\s*:)/', $bloque_oscuro[1], $t_oscuro );
preg_match_all( '/--uhp-db-[a-z0-9-]+(?=\s*:)/', $bloque_claro[1], $t_claro );

// Los que NO dependen del tema: identidad de marca y medidas.
$compartidos = array(
	'--uhp-db-verde',
	'--uhp-db-verde-claro',
	'--uhp-db-amarillo',
	'--uhp-db-alarma',
	'--uhp-db-r-s',
	'--uhp-db-r-m',
	'--uhp-db-cab',
	'--uhp-db-lateral',
	'--uhp-db-panel-ancho',
);
$sin_redefinir = array_diff( $t_oscuro[0], $t_claro[0], $compartidos );
comprobar(
	0 === count( $sin_redefinir ),
	sprintf(
		'El tema claro redefine todos los tokens que dependen del tema%s',
		$sin_redefinir ? ' — faltan: ' . implode( ', ', $sin_redefinir ) : ''
	)
);

/* Los ajustes nuevos tienen que llegar a las instalaciones que ya tenían
   la opción guardada: crear la opción solo si falta dejaba sin `tema` a
   todo el que actualizara. */
$defectos = GobernacionNarino\Urkunina\UHP_Activator::dashboard_por_defecto();
comprobar(
	isset( $defectos['tema'] ) && 'oscuro' === $defectos['tema'],
	'El tablero declara su tema por defecto'
);
comprobar(
	isset( $defectos['teselas'] ) && 'auto' === $defectos['teselas'],
	'La capa base por defecto es «auto»: sigue al tema'
);

/* ---------------------------------------------------------------- */
echo "\n6. Vistas del motor de gráficos\n";

$vistas = UHP_Views::lista();
comprobar( count( $vistas ) >= 20, sprintf( 'El catálogo declara %d vistas', count( $vistas ) ) );

$tipos = UHP_Views::tipos();
foreach ( $vistas as $v ) {
	$completa = UHP_Views::obtener( $v['id'] );

	comprobar( is_array( $completa ), sprintf( '[%s] se resuelve', $v['id'] ) );
	if ( ! is_array( $completa ) ) {
		continue;
	}

	comprobar(
		count( $completa['data'] ) > 0,
		sprintf( '[%s] produce %d fila(s)', $v['id'], count( $completa['data'] ) )
	);

	// Cada fila debe traer todas las dimensiones y todas las medidas
	// que la vista declara: es el contrato que consume el renderer.
	$fila_ok = true;
	foreach ( $completa['data'] as $fila ) {
		foreach ( array_merge( $completa['dimensions'], $completa['measures'] ) as $campo ) {
			if ( ! array_key_exists( $campo, $fila ) ) {
				$fila_ok = false;
			}
		}
	}
	comprobar( $fila_ok, sprintf( '[%s] todas sus filas traen sus dimensiones y medidas', $v['id'] ) );

	comprobar(
		in_array( $v['default'], $v['compatible'], true ),
		sprintf( '[%s] su tipo por defecto está entre los compatibles', $v['id'] )
	);

	$clases_ok = true;
	foreach ( $v['compatible'] as $t ) {
		if ( ! isset( $tipos[ $t ] ) ) {
			$clases_ok = false;
		}
	}
	comprobar( $clases_ok, sprintf( '[%s] todos sus tipos compatibles existen', $v['id'] ) );

	comprobar(
		strlen( $completa['descripcion_larga'] ) >= 375,
		sprintf( '[%s] su descripción publicada llega a 375 caracteres', $v['id'] )
	);
	comprobar(
		strlen( $completa['analisis_largo'] ) >= 375,
		sprintf( '[%s] su análisis publicado llega a 375 caracteres', $v['id'] )
	);
	comprobar(
		'' !== $completa['analisis']['descriptivo'],
		sprintf( '[%s] genera su análisis automático', $v['id'] )
	);
}

/* ---------------------------------------------------------------- */
echo "\n7. Indicadores y mapa\n";

$kpis = UHP_Rest::kpis();
comprobar( 6 === count( $kpis ), 'Se publican seis cifras clave' );

$sin_valor = array_filter(
	$kpis,
	static function ( $k ) {
		return empty( $k['valor'] );
	}
);
comprobar(
	0 === count( $sin_valor ),
	sprintf(
		'Las seis cifras clave tienen valor%s',
		$sin_valor ? ' — vacías: ' . implode( ', ', wp_list_pluck( $sin_valor, 'clave' ) ) : ''
	)
);

foreach ( array_keys( UHP_Rest::indicadores_mapa() ) as $indicador ) {
	$valores = UHP_Rest::valores_mapa( $indicador );
	comprobar(
		count( $valores ) > 0,
		sprintf( 'El indicador «%s» colorea %d municipio(s)', $indicador, count( $valores ) )
	);

	$claves_ok = true;
	foreach ( array_keys( $valores ) as $divipola ) {
		if ( ! UHP_Municipios::existe( $divipola ) ) {
			$claves_ok = false;
		}
	}
	comprobar( $claves_ok, sprintf( 'El indicador «%s» solo usa códigos DIVIPOLA válidos', $indicador ) );
}

/* ---------------------------------------------------------------- */
echo "\n8. Coherencia interna de las cifras\n";

$hp_pos = (int) UHP_Datos::valor( 'tamizaje', 'infeccion_h_pylori.positivos.personas', 0 );
$hp_neg = (int) UHP_Datos::valor( 'tamizaje', 'infeccion_h_pylori.negativos.personas', 0 );
comprobar( 5000 === $hp_pos + $hp_neg, 'Los positivos y negativos de H. pylori suman 5.000' );

$lpm_pos = (int) UHP_Datos::valor( 'tamizaje', 'lesion_precursora_malignidad.positivos.personas', 0 );
$lpm_neg = (int) UHP_Datos::valor( 'tamizaje', 'lesion_precursora_malignidad.negativos.personas', 0 );
comprobar( 5000 === $lpm_pos + $lpm_neg, 'Los positivos y negativos de lesión precursora suman 5.000' );

$casos = 0;
foreach ( (array) UHP_Datos::valor( 'cancer', 'distribucion_por_municipio', array() ) as $c ) {
	$casos += (int) $c['casos'];
}
comprobar(
	(int) UHP_Datos::valor( 'cancer', 'resumen.casos_detectados', 0 ) === $casos,
	'La distribución municipal de casos suma el total declarado'
);

$muestras = 0;
foreach ( (array) UHP_Datos::valor( 'biobanco', 'muestras', array() ) as $m ) {
	$muestras += (int) $m['cantidad'];
}
comprobar(
	(int) UHP_Datos::valor( 'biobanco', 'total_muestras_documentadas_por_tipo', 0 ) === $muestras,
	'Las muestras por tipo suman el total documentado del biobanco'
);

$fuentes = 0;
foreach ( (array) UHP_Datos::valor( 'proyecto', 'financiacion.fuentes', array() ) as $f ) {
	$fuentes += (int) $f['valor'];
}
comprobar(
	(int) UHP_Datos::valor( 'proyecto', 'financiacion.presupuesto_total', 0 ) === $fuentes,
	'Las fuentes de financiación suman el presupuesto total'
);

/* ---------------------------------------------------------------- */
echo "\n";
if ( $fallos ) {
	printf( "\033[31m%d de %d comprobaciones fallaron:\033[0m\n", count( $fallos ), $pruebas );
	foreach ( $fallos as $f ) {
		echo '  · ' . $f . "\n";
	}
	exit( 1 );
}

printf( "\033[32mLas %d comprobaciones pasaron.\033[0m\n\n", $pruebas );
exit( 0 );
