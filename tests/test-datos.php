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
use GobernacionNarino\Urkunina\UHP_Voz;
use GobernacionNarino\Urkunina\UHP_Codigos;
use GobernacionNarino\Urkunina\UHP_Security;
use GobernacionNarino\Urkunina\UHP_Topojson;
use GobernacionNarino\Urkunina\UHP_Subregiones;
use GobernacionNarino\Urkunina\UHP_Territorios;
use GobernacionNarino\Urkunina\UHP_LLM;

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
	21 === count( $registro ),
	sprintf( 'El registro declara los 19 archivos JSON y las dos capas de geometría (%d entradas)', count( $registro ) )
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

$con_dato  = (array) UHP_Datos::valor( 'prev_subregion', 'subregiones', array() );
$sin_cruce = array();
foreach ( $con_dato as $fila ) {
	if ( '' === UHP_Subregiones::codigo_de( $fila['subregion'] ) ) {
		$sin_cruce[] = $fila['subregion'];
	}
}
// Contar las filas ANTES de recorrerlas no es redundante: con la clave del
// registro mal escrita la lista llegaba vacía y el bucle no encontraba
// ningún fallo, de modo que la comprobación pasaba sin comprobar nada.
comprobar(
	11 === count( $con_dato ),
	sprintf( 'El archivo subregional trae las 11 subregiones documentadas (%d)', count( $con_dato ) )
);
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
echo "\n5bis-2. La división declarada y la cartográfica describen lo mismo\n";

/* El plugin cruza dos fuentes: el archivo oficial de la Gobernación, que
   da los nombres y la composición declarada, y la cartografía del DANE,
   que da la geometría y los códigos DIVIPOLA. Si dejaran de coincidir, el
   tablero estaría mezclando dos divisiones distintas del departamento sin
   que nada lo avisara. Esta comprobación es la que lo impide. */
$declaradas = UHP_Subregiones::declaradas();
comprobar( 13 === count( $declaradas ), sprintf( 'El archivo oficial declara 13 subregiones (%d)', count( $declaradas ) ) );

$total_declarados = 0;
foreach ( $declaradas as $d ) {
	$total_declarados += count( $d['municipios'] );
}
comprobar(
	64 === $total_declarados,
	sprintf( 'El archivo oficial reparte los 64 municipios (%d)', $total_declarados )
);

$geo_por_sub = array();
foreach ( UHP_Subregiones::indice() as $codigo => $sr ) {
	$geo_por_sub[ $codigo ] = $sr['municipios'];
	sort( $geo_por_sub[ $codigo ] );
}

$discrepancias = array();
foreach ( $declaradas as $d ) {
	$codigo = UHP_Subregiones::codigo_de( $d['nombre'] );
	if ( '' === $codigo ) {
		$discrepancias[] = $d['nombre'] . ' (no cruza con la cartografía)';
		continue;
	}

	$divipolas = array();
	foreach ( $d['municipios'] as $m ) {
		$id = UHP_Municipios::divipola_de( $m );
		if ( '' === $id ) {
			$discrepancias[] = $d['nombre'] . ' → ' . $m . ' (municipio sin DIVIPOLA)';
			continue;
		}
		$divipolas[] = $id;
	}
	sort( $divipolas );

	$soloDeclarados = array_diff( $divipolas, $geo_por_sub[ $codigo ] );
	$soloGeo        = array_diff( $geo_por_sub[ $codigo ], $divipolas );
	if ( $soloDeclarados || $soloGeo ) {
		$discrepancias[] = sprintf(
			'%s: solo en el archivo %s / solo en la cartografía %s',
			$d['nombre'],
			implode( ',', $soloDeclarados ) ?: '—',
			implode( ',', $soloGeo ) ?: '—'
		);
	}
}

comprobar(
	0 === count( $discrepancias ),
	sprintf(
		'Cada subregión declarada tiene los mismos municipios que en la cartografía%s',
		$discrepancias ? ' — ' . implode( ' · ', $discrepancias ) : ''
	)
);

// Los nombres que ve el ciudadano son los oficiales de la entidad, no los
// de la cartografía del DANE.
comprobar(
	'Los Abades' === UHP_Subregiones::nombre_de( 'abades' )
		&& 'La Cordillera' === UHP_Subregiones::nombre_de( 'cordillera' )
		&& 'Piedemonte Costero' === UHP_Subregiones::nombre_de( 'pie_de_monte_costero' ),
	'Las subregiones se rotulan con el nombre oficial de la entidad'
);
// Y el cruce sigue admitiendo la grafía del DANE, por si un dato la trae.
comprobar(
	'abades' === UHP_Subregiones::codigo_de( 'Abades' )
		&& 'abades' === UHP_Subregiones::codigo_de( 'Los Abades' ),
	'El cruce acepta el nombre oficial y el cartográfico'
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
	'Piedemonte Costero' === $carga_sub['valores']['pie_de_monte_costero']['nombre'],
	'El mapa rotula la subregión con el nombre oficial de la entidad'
);

$carga = UHP_Rest::carga_geomapa( 'prev_lpm_municipios' );
comprobar( 'vista' === $carga['origen'], 'La carga del geomapa distingue el origen «vista»' );
comprobar( ! empty( $carga['meta']['escala'] ), 'La carga del geomapa trae su rampa de color' );
comprobar(
	UHP_Topojson::OBJETO === $carga['objeto'],
	'La carga del geomapa nombra el objeto de la topología'
);

/* Vista con dos indicadores por subregión: el mapa solo puede pintar uno.
   Debe elegir, decirlo en el rótulo y respetar la serie que se le pida. */
$series = UHP_Views::series( 'prev_subregion' );
comprobar( 2 === count( $series ), sprintf( '«prev_subregion» declara %d series', count( $series ) ) );

$por_defecto = UHP_Rest::carga_geomapa( 'prev_subregion' );
comprobar(
	$por_defecto['serie'] === $series[0] && 11 === count( $por_defecto['valores'] ),
	'Sin serie pedida, el geomapa dibuja la primera y una sola cifra por subregión'
);
comprobar(
	false !== strpos( $por_defecto['meta']['etiqueta'], $series[0] ),
	'El rótulo del mapa dice qué serie se está dibujando'
);

$otra = UHP_Rest::carga_geomapa( 'prev_subregion', 'lpm', $series[1] );
comprobar(
	$otra['serie'] === $series[1] && $otra['valores'] !== $por_defecto['valores'],
	'Pedir la otra serie cambia las cifras del mapa'
);

/* ---------------------------------------------------------------- */
echo "\n5e. Índice territorial del tablero\n";

foreach ( UHP_Territorios::NIVELES as $nivel ) {
	$ent = UHP_Territorios::entidades( $nivel );
	comprobar( count( $ent ) > 0, sprintf( 'El nivel «%s» declara %d entidades', $nivel, count( $ent ) ) );
}
comprobar( 64 === count( UHP_Territorios::entidades( 'municipio' ) ), 'El nivel municipal trae los 64 municipios' );
comprobar( 13 === count( UHP_Territorios::entidades( 'subregion' ) ), 'El nivel subregional trae las 13 subregiones' );

comprobar( null === UHP_Territorios::ficha( 'subregion', 'inventada' ), 'Un territorio inexistente no devuelve ficha' );
comprobar( null === UHP_Territorios::ficha( 'municipio', '99999' ), 'Un DIVIPOLA inexistente no devuelve ficha' );

/* La regla de honestidad del tablero: cada cifra dice en qué condición
   está respecto del territorio, y las que el proyecto no desagrega se
   marcan como departamentales en vez de pasar por locales. */
$estados_validos = array( 'publicado', 'sin_dato', 'agregado', 'departamental' );
$sin_estado      = array();
foreach ( array( array( 'subregion', 'centro' ), array( 'municipio', '52001' ), array( 'departamento', '52' ) ) as $par ) {
	$f = UHP_Territorios::ficha( $par[0], $par[1] );
	foreach ( $f['indicadores'] as $ind ) {
		if ( ! in_array( $ind['estado'], $estados_validos, true ) ) {
			$sin_estado[] = $par[0] . '/' . $ind['clave'] . ' = ' . $ind['estado'];
		}
	}
}
comprobar(
	0 === count( $sin_estado ),
	sprintf( 'Cada cifra de una ficha declara su condición%s', $sin_estado ? ' — ' . implode( ', ', $sin_estado ) : '' )
);

$pasto = UHP_Territorios::ficha( 'municipio', '52001' );
$participantes = null;
foreach ( $pasto['indicadores'] as $ind ) {
	if ( 'participantes' === $ind['clave'] ) {
		$participantes = $ind;
	}
}
comprobar(
	$participantes && 'departamental' === $participantes['estado'],
	'Los participantes tamizados se marcan como departamentales en un municipio'
);

// Las prevalencias NO se promedian para obtener la de una subregión: se
// usan las publicadas o ninguna. Sanquianga no está documentada.
$sanquianga = UHP_Territorios::ficha( 'subregion', 'sanquianga' );
$prev = null;
foreach ( $sanquianga['indicadores'] as $ind ) {
	if ( 'lpm' === $ind['clave'] ) {
		$prev = $ind;
	}
}
comprobar(
	$prev && 'sin_dato' === $prev['estado'] && null === $prev['valor'],
	'Una subregión sin prevalencia publicada no se rellena con un promedio'
);

// Los conteos sí se suman, y la suma cuadra con el total del departamento.
$suma_cancer = 0;
foreach ( UHP_Territorios::valores( 'cancer', 'subregion' ) as $v ) {
	$suma_cancer += (int) $v['valor'];
}
comprobar(
	8 === $suma_cancer,
	sprintf( 'Los casos sumados por subregión dan los 8 del departamento (%d)', $suma_cancer )
);

$suma_interv = 0;
foreach ( UHP_Territorios::valores( 'intervencion', 'subregion' ) as $v ) {
	$suma_interv += (int) $v['valor'];
}
comprobar(
	55 === $suma_interv,
	sprintf( 'Los municipios intervenidos sumados por subregión dan 55 (%d)', $suma_interv )
);

// Un indicador cambia de significado al cambiar de capa y debe decirlo.
$meta_mun = UHP_Territorios::meta_por_nivel( UHP_Rest::indicadores_mapa()['intervencion'], 'intervencion', 'municipio' );
$meta_sub = UHP_Territorios::meta_por_nivel( UHP_Rest::indicadores_mapa()['intervencion'], 'intervencion', 'subregion' );
comprobar(
	$meta_mun['etiqueta'] !== $meta_sub['etiqueta'],
	'El indicador de intervención se rotula distinto en cada capa'
);

/* Las tres capas de geometría existen y traen lo que dicen. */
foreach ( array( 'municipio' => 64, 'subregion' => 13, 'departamento' => 1 ) as $nivel => $esperados ) {
	$f = UHP_Topojson::features( $nivel );
	comprobar(
		count( $f ) === $esperados,
		sprintf( 'La capa «%s» trae %d geometrías (%d)', $nivel, $esperados, count( $f ) )
	);
	$sin_id = 0;
	foreach ( $f as $x ) {
		if ( empty( $x['properties']['id'] ) || empty( $x['properties']['nombre'] ) ) {
			$sin_id++;
		}
	}
	comprobar( 0 === $sin_id, sprintf( 'Toda la capa «%s» trae id y nombre', $nivel ) );
}

$carga_ind = UHP_Rest::carga_geomapa( '', 'inventado' );
comprobar(
	'lpm' === $carga_ind['clave'],
	'Un indicador inexistente cae al indicador por defecto'
);

/* ---------------------------------------------------------------- */
echo "\n5d. Tablero\n";

$sc      = new GobernacionNarino\Urkunina\UHP_Shortcodes();
$tablero = $sc->sc_dashboard( array() );

comprobar(
	false !== strpos( $tablero, 'data-uhp-tablero' ) && false !== strpos( $tablero, 'class="uhp-db"' ),
	'El tablero imprime su contenedor'
);

/* El marcado sale completo y VACÍO: lo rellena el JavaScript con una sola
   petición. Lo que no depende de los datos —el perfil de los 5.000, la
   leyenda, los rótulos— tiene que llegar ya en el HTML, de modo que la
   página diga algo antes de la respuesta y lo siga diciendo si no llega. */
$zonas_esperadas = array(
	'kpis', 'zonas', 'mlista', 'mapa', 'tip', 'mapatitulo',
	'leyenda-titulo', 'rampa', 'bars', 'detalle', 'detalle-titulo', 'estado',
);
$faltan = array();
foreach ( $zonas_esperadas as $z ) {
	if ( false === strpos( $tablero, 'data-uhp-zona="' . $z . '"' ) ) {
		$faltan[] = $z;
	}
}
comprobar(
	0 === count( $faltan ),
	sprintf( 'El marcado declara todas sus zonas%s', $faltan ? ' — faltan: ' . implode( ', ', $faltan ) : '' )
);

comprobar(
	4 === substr_count( $tablero, 'class="uhp-db__stack"' ),
	'El perfil de los participantes llega en el HTML, sin JavaScript'
);
comprobar(
	false !== strpos( $tablero, '64,2' ) && false !== strpos( $tablero, '48,2' ),
	'El perfil trae sus cifras ya escritas'
);

// Un solo tema. El tablero se diseñó sobre fondo oscuro y la rampa del
// mapa está calculada sobre él: no hay atributo de tema que atender.
comprobar(
	false === strpos( $tablero, 'data-tema' ) && false === strpos( $tablero, 'uhp-db--claro' ),
	'El tablero no ofrece temas: el diseño es uno solo'
);

$hp = $sc->sc_dashboard( array( 'indicador' => 'hp' ) );
comprobar(
	false !== strpos( $hp, 'data-indicador="hp"' ),
	'El shortcode elige con qué indicador arranca'
);
comprobar(
	false !== strpos( $sc->sc_dashboard( array( 'indicador' => 'fucsia' ) ), 'data-indicador="lpm"' ),
	'Un indicador desconocido cae en lesión precursora'
);

/* La hoja del tablero tiene una regla: ni un color literal fuera del
   bloque de tokens. Es lo que permite leer de un vistazo la paleta entera
   y cambiarla en un sitio. */
$hoja = file_get_contents( dirname( __DIR__ ) . '/assets/css/uhp-dashboard.css' );
// Los comentarios se descartan antes de buscar: la cabecera de la hoja
// NOMBRA el fondo del diseño para explicar por qué no hay tema claro, y
// un color citado en prosa no es una declaración que se haya colado.
$hoja_sin_prosa   = preg_replace( '/\/\*.*?\*\//s', '', $hoja );
preg_match( '/\.uhp-db \{(.*?)\n\}/s', $hoja_sin_prosa, $bloque );
$fuera_del_bloque = str_replace( $bloque[0], '', $hoja_sin_prosa );
preg_match_all( '/#[0-9a-fA-F]{3,8}\b|rgba?\([^)]*\)/', $fuera_del_bloque, $literales );
$colados = array_values( array_unique( $literales[0] ) );
comprobar(
	0 === count( $colados ),
	sprintf(
		'La hoja del tablero no usa colores literales fuera de los tokens%s',
		$colados ? ' — colados: ' . implode( ', ', $colados ) : ''
	)
);

/* Todo el CSS del tablero nace de `.uhp-db`. El diseño original era una
   página suelta y estilizaba `header`, `main`, `.card`…; dentro de
   WordPress eso alcanzaría al tema y a los demás plugins. */
$reglas = preg_split( '/\}/', preg_replace( '/\/\*.*?\*\//s', '', $hoja ) );
$sueltos = array();
foreach ( $reglas as $regla ) {
	$sel = trim( substr( $regla, 0, strpos( $regla . '{', '{' ) ) );
	if ( '' === $sel || 0 === strpos( $sel, '@' ) || false !== strpos( $sel, '{' ) ) {
		continue;
	}
	foreach ( explode( ',', $sel ) as $uno ) {
		$uno = trim( $uno );
		if ( '' !== $uno && 0 !== strpos( $uno, '.uhp-db' ) ) {
			$sueltos[] = $uno;
		}
	}
}
comprobar(
	0 === count( $sueltos ),
	sprintf(
		'Todo selector de la hoja nace de .uhp-db%s',
		$sueltos ? ' — sueltos: ' . implode( ' | ', array_slice( array_unique( $sueltos ), 0, 5 ) ) : ''
	)
);

/* Los ajustes nuevos tienen que llegar a las instalaciones que ya tenían
   la opción guardada. */
$defectos = GobernacionNarino\Urkunina\UHP_Activator::dashboard_por_defecto();
comprobar(
	isset( $defectos['indicador'] ),
	'El tablero declara su indicador por defecto'
);

/* La carga que consume: los 64 municipios con lo justo para pintarse. */
$carga = GobernacionNarino\Urkunina\UHP_Rest::carga_tablero();
comprobar(
	isset( $carga['type'] ) && 'FeatureCollection' === $carga['type'] && 64 === count( $carga['features'] ),
	sprintf( 'La carga del tablero trae los 64 municipios (%d)', count( $carga['features'] ) )
);

$campos = array( 'c', 'n', 'sub', 'zona', 'int', 'lpm', 'hp', 'casos', 'lat', 'lon' );
$completos = true;
$intervenidos = 0;
$con_geometria = 0;
foreach ( $carga['features'] as $f ) {
	foreach ( $campos as $campo ) {
		if ( ! array_key_exists( $campo, $f['properties'] ) ) {
			$completos = false;
		}
	}
	if ( ! empty( $f['properties']['int'] ) ) {
		$intervenidos++;
	}
	if ( ! empty( $f['geometry']['coordinates'] ) ) {
		$con_geometria++;
	}
}
comprobar( $completos, 'Cada municipio de la carga trae todos sus campos' );
comprobar( 55 === $intervenidos, sprintf( 'La carga marca los 55 municipios intervenidos (%d)', $intervenidos ) );
comprobar( 64 === $con_geometria, sprintf( 'Los 64 municipios traen geometría (%d)', $con_geometria ) );

/* El sentido de giro de los anillos. D3 decide el interior del polígono
   por él, con el criterio INVERSO al del RFC 7946: exterior horario. Un
   anillo al revés se dibuja como el mundo entero menos el municipio, y
   basta uno para que el departamento quede reducido a un punto. */
$mal_orientados = array();
foreach ( $carga['features'] as $f ) {
	$g = $f['geometry'];
	$poligonos = ( 'Polygon' === $g['type'] ) ? array( $g['coordinates'] ) : $g['coordinates'];
	foreach ( $poligonos as $poligono ) {
		foreach ( $poligono as $i => $anillo ) {
			$a = 0.0;
			$n = count( $anillo );
			for ( $j = 0, $k = $n - 1; $j < $n; $k = $j, $j++ ) {
				$a += ( $anillo[ $k ][0] * $anillo[ $j ][1] ) - ( $anillo[ $j ][0] * $anillo[ $k ][1] );
			}
			$es_horario   = ( $a / 2 ) < 0;
			$debe_horario = ( 0 === $i );
			if ( $es_horario !== $debe_horario ) {
				$mal_orientados[] = $f['properties']['n'];
			}
		}
	}
}
comprobar(
	0 === count( $mal_orientados ),
	sprintf(
		'Los anillos van en el sentido que espera D3, exterior horario%s',
		$mal_orientados ? ' — al revés: ' . implode( ', ', array_slice( array_unique( $mal_orientados ), 0, 4 ) ) : ''
	)
);

/* Zona de riesgo: es una DERIVACIÓN, no un dato publicado. El archivo
   trae su comprobación contra los territorios de referencia que sí
   publica la presentación, y aquí se ejecuta. */
$verif = UHP_Datos::valor( 'zonas', 'verificacion.referencias', array() );
comprobar( 6 === count( $verif ), sprintf( 'La tabla de zonas declara sus 6 referencias (%d)', count( $verif ) ) );

$descuadran = array();
foreach ( $verif as $r ) {
	if ( $r['zona_fuente'] !== $r['zona_tabla'] ) {
		$descuadran[] = $r['nombre'];
	}
	if ( GobernacionNarino\Urkunina\UHP_Subregiones::zona_de( $r['subregion'] ) !== $r['zona_fuente'] ) {
		$descuadran[] = $r['nombre'] . ' (índice)';
	}
}
comprobar(
	0 === count( $descuadran ),
	sprintf(
		'Cada territorio de referencia cae en la zona que le da la fuente%s',
		$descuadran ? ' — descuadran: ' . implode( ', ', $descuadran ) : ''
	)
);

$sin_zona = array();
foreach ( $carga['features'] as $f ) {
	if ( '' === $f['properties']['zona'] ) {
		$sin_zona[] = $f['properties']['n'];
	}
}
comprobar(
	0 === count( $sin_zona ),
	sprintf(
		'Los 64 municipios tienen zona asignada%s',
		$sin_zona ? ' — sin zona: ' . implode( ', ', array_slice( $sin_zona, 0, 4 ) ) : ''
	)
);

/* El promedio del tablero se calcula solo sobre los municipios que tienen
   cifra. Dos subregiones de la costa —Pacífico Sur y Sanquianga— no la
   tienen publicada; hoy ninguno de sus municipios fue intervenido, así que
   el caso no se da. Se comprueba que sigue sin darse: el día que aparezca
   uno, el promedio del tablero tendría que dejar de contarlo como cero. */
$sub_con_cifra = $carga['meta']['subregional'];
$sin_ninguna   = array();
foreach ( $carga['features'] as $f ) {
	$p = $f['properties'];
	if ( empty( $p['int'] ) ) {
		continue;
	}
	foreach ( array( 'lpm', 'hp' ) as $ind ) {
		$hay_sub = isset( $sub_con_cifra[ $p['sub'] ][ $ind ] ) && null !== $sub_con_cifra[ $p['sub'] ][ $ind ];
		if ( null === $p[ $ind ] && ! $hay_sub ) {
			$sin_ninguna[] = $p['n'] . ' (' . $ind . ')';
		}
	}
}
comprobar(
	0 === count( $sin_ninguna ),
	sprintf(
		'Todo municipio intervenido tiene cifra, propia o de su subregión%s',
		$sin_ninguna ? ' — sin ninguna: ' . implode( ', ', array_slice( $sin_ninguna, 0, 4 ) ) : ''
	)
);

comprobar(
	11 === count( $sub_con_cifra ),
	sprintf( 'La carga trae las 11 subregiones con prevalencia publicada (%d)', count( $sub_con_cifra ) )
);

/* Los rótulos: la cartografía escribe «Colón» y los informes «Colón
   (Génova)». En un mapa del departamento la segunda es la útil. */
$por_codigo = array();
foreach ( $carga['features'] as $f ) {
	$por_codigo[ $f['properties']['c'] ] = $f['properties']['n'];
}
comprobar(
	'Colón (Génova)' === ( isset( $por_codigo['52203'] ) ? $por_codigo['52203'] : '' )
		&& 'Los Andes (Sotomayor)' === ( isset( $por_codigo['52418'] ) ? $por_codigo['52418'] : '' )
		&& 'Santacruz (Guachavés)' === ( isset( $por_codigo['52699'] ) ? $por_codigo['52699'] : '' ),
	'Los municipios se rotulan como los escriben los informes, no la cartografía'
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

	// `mapa` es un tipo más de la barra, pero solo para quien tiene
	// geometría que colorear: ofrecerlo en una vista sin `geo` produciría
	// un mapa vacío, y no ofrecerlo en una que sí la tiene esconde la
	// mitad de la lectura.
	comprobar(
		in_array( 'mapa', $v['compatible'], true ) === ! empty( $v['geo'] ),
		sprintf(
			'[%s] ofrece el tipo mapa solo si declara geometría (%s)',
			$v['id'],
			empty( $v['geo'] ) ? 'sin geo' : 'nivel ' . $v['geo']['nivel']
		)
	);

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

// La cifra que el informe destaca (+35,64%) debe salir de la propia serie:
// si alguien corrige un año y no el titular, esta comprobación lo delata.
$serie = (array) UHP_Datos::valor( 'mortalidad', 'serie', array() );
comprobar( 4 === count( $serie ), sprintf( 'La serie de mortalidad cubre los cuatro años 2019-2022 (%d)', count( $serie ) ) );

$inicial = (float) UHP_Datos::valor( 'mortalidad', 'variacion_periodo.valor_inicial', 0 );
$final   = (float) UHP_Datos::valor( 'mortalidad', 'variacion_periodo.valor_final', 0 );
$anio_i  = (int) UHP_Datos::valor( 'mortalidad', 'variacion_periodo.anio_inicial', 0 );
$anio_f  = (int) UHP_Datos::valor( 'mortalidad', 'variacion_periodo.anio_final', 0 );
$por_anio = array();
foreach ( $serie as $a ) {
	$por_anio[ (int) $a['anio'] ] = (float) $a['fallecimientos'];
}
comprobar(
	isset( $por_anio[ $anio_i ] ) && isset( $por_anio[ $anio_f ] )
		&& $por_anio[ $anio_i ] === $inicial && $por_anio[ $anio_f ] === $final,
	'Los extremos declarados de la mortalidad coinciden con los años de la serie'
);
comprobar(
	$inicial > 0 && abs( ( $final / $inicial - 1 ) * 100 - (float) UHP_Datos::valor( 'mortalidad', 'variacion_periodo.variacion_porcentaje', 0 ) ) < 0.01,
	'La variación declarada de la mortalidad se deriva de sus propios extremos'
);

$con   = (int) UHP_Datos::valor( 'acceso_oncologico', 'resumen.municipios_con_oferta', 0 );
$sin   = (int) UHP_Datos::valor( 'acceso_oncologico', 'resumen.municipios_sin_oferta', 0 );
$total = (int) UHP_Datos::valor( 'acceso_oncologico', 'resumen.municipios_del_departamento', 0 );
comprobar( $con + $sin === $total, 'Los municipios con y sin oferta oncológica suman los del departamento' );
comprobar(
	count( (array) UHP_Datos::valor( 'acceso_oncologico', 'instituciones', array() ) )
		=== (int) UHP_Datos::valor( 'acceso_oncologico', 'resumen.ips_habilitadas_departamento', 0 ),
	'Las IPS listadas suman las habilitadas que declara el resumen'
);

$municipios_ips = array();
foreach ( (array) UHP_Datos::valor( 'acceso_oncologico', 'instituciones', array() ) as $ips ) {
	$municipios_ips[ $ips['municipio'] ] = true;
}
comprobar(
	count( $municipios_ips ) === (int) UHP_Datos::valor( 'acceso_oncologico', 'resumen.municipios_con_oferta', 0 ),
	'Las IPS se reparten entre tantos municipios como declara el resumen'
);

/* ---------------------------------------------------------------- */
echo "\n9. La serie completa de los 55 municipios\n";

$serie = (array) UHP_Datos::valor( 'prev_municipal', 'serie_completa', array() );

comprobar( 55 === count( $serie ), sprintf( 'La serie trae los 55 municipios intervenidos (%d)', count( $serie ) ) );

// El nombre es la llave con la que el mapa encuentra el municipio: si uno
// se escribiera distinto, su cifra se perdería sin que nada avisara.
$cobertura = array();
foreach ( (array) UHP_Datos::valor( 'cobertura', 'municipios', array() ) as $m ) {
	$cobertura[ $m['municipio'] ] = true;
}
$fuera = array();
foreach ( $serie as $f ) {
	if ( ! isset( $cobertura[ $f['municipio'] ] ) ) {
		$fuera[] = $f['municipio'];
	}
}
comprobar( ! $fuera, 'Cada municipio de la serie está en la cobertura' . ( $fuera ? ': ' . implode( ', ', $fuera ) : '' ) );
comprobar(
	count( $serie ) === count( array_unique( wp_list_pluck( $serie, 'municipio' ) ) ),
	'Ningún municipio aparece dos veces en la serie'
);

// Los dos indicadores son porcentajes y ninguno puede faltar: un municipio
// de la serie sin cifra sería un hueco disfrazado de dato.
$rango = true;
foreach ( $serie as $f ) {
	foreach ( array( 'prevalencia_lpm_porcentaje', 'prevalencia_h_pylori_porcentaje' ) as $k ) {
		if ( ! isset( $f[ $k ] ) || ! is_numeric( $f[ $k ] ) || $f[ $k ] < 0 || $f[ $k ] > 100 ) {
			$rango = false;
		}
	}
}
comprobar( $rango, 'Los dos indicadores de cada municipio son porcentajes entre 0 y 100' );

// La serie tiene que decir lo mismo que los extremos que ya publicaba el
// informe preliminar. Son dos fuentes distintas: si discreparan, una de las
// dos estaría mal leída.
$por_nombre = array();
foreach ( $serie as $f ) {
	$por_nombre[ $f['municipio'] ] = $f;
}
$choques = array();
$bloques = array(
	array( 'top10_lesion_precursora_malignidad', 'prevalencia_lpm_porcentaje' ),
	array( 'menor_prevalencia_lesion_precursora_malignidad', 'prevalencia_lpm_porcentaje' ),
	array( 'top10_infeccion_h_pylori', 'prevalencia_h_pylori_porcentaje' ),
);
$cruzados = 0;
foreach ( $bloques as $b ) {
	foreach ( (array) UHP_Datos::valor( 'prev_municipal', $b[0], array() ) as $m ) {
		$n = $m['municipio'];
		if ( ! isset( $por_nombre[ $n ] ) ) {
			$choques[] = $n . ' (ausente)';
			continue;
		}
		$cruzados++;
		if ( abs( (float) $por_nombre[ $n ][ $b[1] ] - (float) $m[ $b[1] ] ) > 0.001 ) {
			$choques[] = $n;
		}
	}
}
comprobar(
	! $choques,
	sprintf( 'Los %d valores de los extremos coinciden con la serie completa%s', $cruzados, $choques ? ': ' . implode( ', ', $choques ) : '' )
);

// Doce municipios traen numerador y denominador: el porcentaje tiene que
// salir de ellos, o la cifra publicada no es la que dicen sus propios datos.
$con_base = 0;
$mal      = array();
foreach ( $serie as $f ) {
	if ( ! isset( $f['participantes_examinados'], $f['positivos_h_pylori'] ) ) {
		continue;
	}
	$con_base++;
	$calc = round( $f['positivos_h_pylori'] / $f['participantes_examinados'] * 100, 1 );
	if ( abs( $calc - (float) $f['prevalencia_h_pylori_porcentaje'] ) > 0.001 ) {
		$mal[] = sprintf( '%s (%s vs %s)', $f['municipio'], $calc, $f['prevalencia_h_pylori_porcentaje'] );
	}
}
comprobar( 12 === $con_base, sprintf( 'Doce municipios publican numerador y denominador (%d)', $con_base ) );
comprobar( ! $mal, 'El porcentaje de esos doce sale de su propio numerador' . ( $mal ? ': ' . implode( ', ', $mal ) : '' ) );

// Coherencia con la cifra departamental. No es el método de cálculo —eso lo
// da 1.755 y 3.370 sobre 5.000— sino una comprobación: si alguien transcribe
// mal un municipio, la media se aparta y la prueba lo delata.
$dep_lpm = (float) UHP_Datos::valor( 'tamizaje', 'lesion_precursora_malignidad.positivos.porcentaje', 0 );
$dep_hp  = (float) UHP_Datos::valor( 'tamizaje', 'infeccion_h_pylori.positivos.porcentaje', 0 );
$med_lpm = array_sum( wp_list_pluck( $serie, 'prevalencia_lpm_porcentaje' ) ) / count( $serie );
$med_hp  = array_sum( wp_list_pluck( $serie, 'prevalencia_h_pylori_porcentaje' ) ) / count( $serie );
comprobar(
	abs( $med_lpm - $dep_lpm ) < 0.5,
	sprintf( 'La media de los 55 valores de LPM (%.2f) no se aparta de la cifra departamental (%.1f)', $med_lpm, $dep_lpm )
);
comprobar(
	abs( $med_hp - $dep_hp ) < 0.5,
	sprintf( 'La media de los 55 valores de H. pylori (%.2f) no se aparta de la cifra departamental (%.1f)', $med_hp, $dep_hp )
);

/* ---------------------------------------------------------------- */
echo "\n10. Lo demás que anexó la socialización\n";

$casos = (array) UHP_Datos::valor( 'cancer', 'casos', array() );
comprobar( 8 === count( $casos ), sprintf( 'Los ocho casos de cáncer están detallados uno a uno (%d)', count( $casos ) ) );

$por_mun = array();
$estados = array();
foreach ( $casos as $c ) {
	$por_mun[ $c['municipio'] ] = isset( $por_mun[ $c['municipio'] ] ) ? $por_mun[ $c['municipio'] ] + 1 : 1;
	$estados[ $c['estado'] ]    = isset( $estados[ $c['estado'] ] ) ? $estados[ $c['estado'] ] + 1 : 1;
}
$declarada = array();
foreach ( (array) UHP_Datos::valor( 'cancer', 'distribucion_por_municipio', array() ) as $m ) {
	$declarada[ $m['municipio'] ] = (int) $m['casos'];
}
ksort( $por_mun );
ksort( $declarada );
comprobar( $por_mun === $declarada, 'El detalle por caso reproduce la distribución municipal declarada' );
comprobar(
	( isset( $estados['Falleció'] ) ? $estados['Falleció'] : 0 ) === (int) UHP_Datos::valor( 'cancer', 'resumen.fallecieron', -1 )
		&& ( isset( $estados['En atención por su EPS'] ) ? $estados['En atención por su EPS'] : 0 ) === (int) UHP_Datos::valor( 'cancer', 'resumen.en_atencion_por_su_eps', -1 ),
	'Los desenlaces caso a caso suman los que declara el resumen'
);

// Ningún caso puede traer dato personal: ni edad, ni fecha, ni nada que
// permita reconocer a alguien en un municipio pequeño.
$claves_permitidas = array( 'caso', 'municipio', 'diagnostico', 'estado' );
$cotilla           = array();
foreach ( $casos as $c ) {
	foreach ( array_keys( $c ) as $k ) {
		if ( ! in_array( $k, $claves_permitidas, true ) ) {
			$cotilla[] = $k;
		}
	}
}
comprobar( ! $cotilla, 'Los casos no traen más campos que municipio, diagnóstico y desenlace' );

// El estado nutricional ya no tiene huecos y sus cifras cuadran con sus
// propios porcentajes.
$nutri = (array) UHP_Datos::valor( 'sociodemografia', 'estado_nutricional.distribucion', array() );
$n_nut = (int) UHP_Datos::valor( 'sociodemografia', 'estado_nutricional.n_con_dato', 0 );
$sin_personas = 0;
$descuadre    = array();
foreach ( $nutri as $c ) {
	if ( ! isset( $c['personas'] ) || null === $c['personas'] ) {
		$sin_personas++;
		continue;
	}
	$pct = round( $c['personas'] / $n_nut * 100, 1 );
	if ( abs( $pct - (float) $c['porcentaje'] ) > 0.05 ) {
		$descuadre[] = sprintf( '%s (%s vs %s)', $c['categoria'], $pct, $c['porcentaje'] );
	}
}
comprobar( 0 === $sin_personas, 'Las cuatro categorías del estado nutricional traen su número de personas' );
comprobar( array_sum( wp_list_pluck( $nutri, 'personas' ) ) === $n_nut, 'Las personas por categoría suman la base declarada' );
comprobar( ! $descuadre, 'Cada porcentaje del estado nutricional sale de su propio número' . ( $descuadre ? ': ' . implode( ', ', $descuadre ) : '' ) );

// El corte de 2022 va aparte y se identifica como tal: es lo que impide que
// alguien lo lea como si fuera la cifra de cierre.
$corte = (array) UHP_Datos::valor( 'sociodemografia', 'corte_preliminar_2022', array() );
comprobar( ! empty( $corte['advertencia'] ), 'El corte preliminar de 2022 lleva su advertencia' );
comprobar( 4844 === (int) ( isset( $corte['n_declarado'] ) ? $corte['n_declarado'] : 0 ), 'El corte preliminar declara su propia base (4.844)' );
comprobar(
	4844 !== (int) UHP_Datos::valor( 'sociodemografia', 'genero.n_con_dato', 0 ),
	'El corte preliminar no ha contaminado la distribución de cierre'
);

$crit = UHP_Datos::leer( 'criterios' );
comprobar(
	4 === count( (array) UHP_Datos::valor( 'criterios', 'inclusion', array() ) )
		&& 6 === count( (array) UHP_Datos::valor( 'criterios', 'exclusion', array() ) ),
	'Los criterios de participación son cuatro de inclusión y seis de exclusión'
);
comprobar(
	(int) UHP_Datos::valor( 'criterios', 'resumen.rango_etario.minimo', 0 ) === (int) UHP_Datos::valor( 'proyecto', 'metas_globales.rango_etario_participantes.minimo', -1 )
		&& (int) UHP_Datos::valor( 'criterios', 'resumen.rango_etario.maximo', 0 ) === (int) UHP_Datos::valor( 'proyecto', 'metas_globales.rango_etario_participantes.maximo', -1 ),
	'El rango etario de los criterios es el mismo que declaran las metas del proyecto'
);

$etapas = (array) UHP_Datos::valor( 'epidemiologia', 'cascada_de_correa.etapas', array() );
comprobar( 5 === count( $etapas ), sprintf( 'La cascada de Correa tiene sus cinco etapas (%d)', count( $etapas ) ) );
$orden = wp_list_pluck( $etapas, 'orden' );
comprobar( $orden === range( 1, count( $etapas ) ), 'Las etapas de la cascada van numeradas en secuencia' );

comprobar(
	4 === count( (array) UHP_Datos::valor( 'proyecto', 'objetivos.especificos', array() ) ),
	'El proyecto declara sus cuatro objetivos específicos'
);
$hitos = (array) UHP_Datos::valor( 'proyecto', 'cronologia', array() );
comprobar( 7 === count( $hitos ), sprintf( 'La cronología recoge los siete hitos (%d)', count( $hitos ) ) );
$anios     = wp_list_pluck( $hitos, 'anio' );
$ordenados = $anios;
sort( $ordenados );
comprobar( $anios === $ordenados, 'Los hitos de la cronología van en orden cronológico' );

// El reparto en porcentaje tiene que salir de los valores, no de la lámina.
$pres  = (float) UHP_Datos::valor( 'proyecto', 'financiacion.presupuesto_total', 0 );
$bien  = true;
$suma  = 0.0;
foreach ( (array) UHP_Datos::valor( 'proyecto', 'financiacion.fuentes', array() ) as $f ) {
	$suma += (float) $f['porcentaje'];
	if ( abs( round( $f['valor'] / $pres * 100, 2 ) - (float) $f['porcentaje'] ) > 0.001 ) {
		$bien = false;
	}
}
comprobar( $bien, 'El porcentaje de cada fuente de financiación sale de su propio valor' );
comprobar( abs( $suma - 100.0 ) < 0.01, sprintf( 'Los porcentajes de financiación suman 100 (%.2f)', $suma ) );

/* ---------------------------------------------------------------- */
echo "\n11. Asistente: claves, publicación y herramientas\n";

$voz_antes = get_option( 'uhp_voz', array() );

// Nace apagado. Mientras lo esté, el tablero no imprime una sola línea
// del asistente: ni el botón, ni el aviso, ni el script.
update_option( 'uhp_voz', UHP_Voz::por_defecto() );
update_option( 'uhp_llm_clave', '' );
update_option( 'uhp_voz_clave', '' );
comprobar( ! UHP_Voz::configurada(), 'Sin configurar, el asistente no se publica' );
comprobar( '' === UHP_Voz::control_html( 'x' ), 'Sin configurar no emite marcado alguno' );

// Encenderlo no basta: sin el modelo de lenguaje no hay quien entienda
// la pregunta, y esa sigue siendo la ÚNICA puerta que queda.
$parcial           = UHP_Voz::por_defecto();
$parcial['activo'] = 1;
update_option( 'uhp_voz', $parcial );
comprobar(
	! UHP_Voz::configurada(),
	'Encendido pero sin clave del modelo, no se publica: ' . count( UHP_Voz::pendientes() ) . ' pendiente'
);

// Con la clave del modelo, y NADA más, se publica. La voz es opcional y
// el aviso previo también: ninguno de los dos es ya una puerta.
update_option( 'uhp_llm_clave', 'sk-ant-clave-del-modelo' );
comprobar( UHP_Voz::configurada(), 'Con la clave del modelo se publica, sin voz y sin aviso' );
comprobar( ! UHP_Voz::pendientes(), 'Y no queda ninguna pendiente' );
comprobar( ! UHP_Voz::con_voz(), 'Pero el plugin sabe que todavía no puede hablar' );
comprobar(
	1 === count( UHP_Voz::advertencias() ),
	'Y lo advierte sin bloquear: responderá por escrito'
);

// La voz son DOS cosas, y con una sola no suena. Que el panel lo diga es
// la diferencia entre configurar y adivinar.
update_option( 'uhp_voz_clave', 'sk-elevenlabs-de-prueba' );
comprobar( ! UHP_Voz::con_voz(), 'Con la clave de ElevenLabs pero sin ID de voz, sigue sin poder hablar' );
$lleno           = $parcial;
$lleno['voz_id'] = '21m00Tcm4TlvDq8ikWAM';
update_option( 'uhp_voz', $lleno );
comprobar( UHP_Voz::con_voz(), 'Con la clave y el ID de la voz, ya puede hablar' );
comprobar( ! UHP_Voz::advertencias(), 'Y el panel no tiene nada que advertir' );

// LA COMPROBACIÓN QUE IMPORTA: ninguna de las DOS claves puede salir al
// navegador por ninguna vía. config_front() se construye por lista
// blanca, y esto verifica que la lista blanca sigue siéndolo.
$front = UHP_Voz::config_front();
$plano = wp_json_encode( $front );
foreach ( array( 'sk-ant-clave-del-modelo', 'sk-elevenlabs-de-prueba' ) as $secreto ) {
	comprobar(
		false === strpos( $plano, $secreto ),
		'La clave «' . substr( $secreto, 0, 12 ) . '…» no aparece en lo que viaja al navegador'
	);
}
comprobar(
	false === strpos( strtolower( $plano ), 'clave' ) && false === strpos( strtolower( $plano ), 'api_key' ),
	'Ni siquiera una clave con ese nombre viaja al navegador'
);
// Tampoco QUÉ proveedor se usa: no le hace falta al navegador y es una
// pista gratis para quien quiera molestar.
comprobar(
	false === strpos( $plano, 'claude' ) && false === strpos( $plano, 'openai' ) && false === strpos( $plano, 'gemini' ),
	'Ni qué proveedor está detrás, que el navegador no necesita saber'
);

$permitidas = array( 'micro', 'voz', 'silencio', 'sugerencias', 'aviso', 'recordar', 'avisoVersion', 'turnos', 'rutaChat', 'rutaVoz', 'version', 'nonce' );
$sobran     = array_diff( array_keys( $front ), $permitidas );
comprobar(
	! $sobran,
	'config_front() no publica ninguna clave fuera de su lista blanca' . ( $sobran ? ': ' . implode( ', ', $sobran ) : '' )
);

/* EL NONCE, y solo en «editores». Sin él, WordPress trata una petición
   con cookie como anónima y las dos rutas contestan 403: el modo que
   existe justo para ensayar el asistente en el sitio de verdad antes de
   abrirlo al ciudadano era el único que no funcionaba.

   Y SOLO ahí: un nonce en el HTML lo ata a un usuario y a un momento, de
   modo que en una página cacheada se serviría el de otra persona y
   caducado. En «editores» eso no puede pasar porque esa salida ya no es
   cacheable; en los otros dos modos las rutas son públicas. */
$vis_antes = UHP_Voz::cfg();
foreach ( array( 'todos' => false, 'oculto' => false, 'editores' => true ) as $vis => $espera ) {
	update_option( 'uhp_voz', array_merge( $vis_antes, array( 'visibilidad' => $vis ) ) );
	$hay = '' !== UHP_Voz::config_front()['nonce'];
	comprobar(
		$hay === $espera,
		sprintf( 'Con visibilidad «%s» el nonce %s viaja', $vis, $espera ? 'SÍ' : 'no' )
	);
}
comprobar(
	UHP_Voz::salida_por_usuario(),
	'Y «editores» marca la salida como dependiente del usuario, que es lo que la saca de la caché'
);
update_option( 'uhp_voz', $vis_antes );

$html = UHP_Voz::control_html( 'uhpdb-1' );
foreach ( array( 'sk-ant-clave-del-modelo', 'sk-elevenlabs-de-prueba' ) as $secreto ) {
	comprobar(
		false === strpos( $html, $secreto ),
		'El marcado del asistente no filtra la clave «' . substr( $secreto, 0, 12 ) . '…»'
	);
}

// Las herramientas y el archivo que documenta el tablero no pueden
// separarse: si alguien cambia un parámetro y no regenera el JSON, la
// documentación describe una forma que el plugin ya no acepta.
$defs = UHP_Voz::definiciones_herramientas();
comprobar( 10 === count( $defs ), sprintf( 'El asistente declara diez herramientas (%d)', count( $defs ) ) );

$ruta_json = UHP_DIR . 'docs/voz/herramientas.json';
$doc       = is_readable( $ruta_json ) ? json_decode( (string) file_get_contents( $ruta_json ), true ) : null;
comprobar( is_array( $doc ), 'docs/voz/herramientas.json existe y es JSON válido' );
if ( is_array( $doc ) ) {
	comprobar(
		wp_json_encode( $doc['herramientas'] ) === wp_json_encode( json_decode( wp_json_encode( $defs ), true ) ),
		'El JSON documentado es exactamente el que produce el plugin'
	);
	comprobar(
		isset( $doc['prompt'] ) && $doc['prompt'] === UHP_Voz::prompt_sistema( 'texto' ),
		'Las instrucciones documentadas son las que el servidor manda'
	);
}

// Las que mueven el tablero NO esperan respuesta y las que leen SÍ: al
// revés, la pantalla cambiaría después de que el asistente terminara de
// hablar, o describiría una pantalla que aún no conoce.
$mutan = array( 'uhp_cambiar_indicador', 'uhp_filtrar_zona_riesgo', 'uhp_filtrar_subregion', 'uhp_seleccionar_municipio', 'uhp_limpiar_tablero' );
$bien  = true;
foreach ( $defs as $d ) {
	$deberia = ! in_array( $d['name'], $mutan, true );
	if ( (bool) $d['espera'] !== $deberia ) {
		$bien = false;
	}
}
comprobar( $bien, 'Las herramientas que mueven el tablero no esperan respuesta y las que leen sí' );

// Toda herramienta tiene que traer un esquema de objeto: los tres
// proveedores rechazan la petición ENTERA si una declaración está mal
// formada, y entonces no falla «esa» herramienta sino la conversación.
// Cerrarlo —additionalProperties: false— lo hace cada adaptador, porque
// no todos lo escriben en el mismo sitio; eso se comprueba en el 13.
$esquemas = true;
$vacias   = 0;
foreach ( $defs as $d ) {
	$p = isset( $d['parameters'] ) ? $d['parameters'] : null;
	if ( ! is_array( $p ) || ! isset( $p['type'], $p['properties'] ) || 'object' !== $p['type'] ) {
		$esquemas = false;
		continue;
	}
	if ( $p['properties'] instanceof stdClass ) {
		++$vacias;
	} elseif ( ! is_array( $p['properties'] ) ) {
		$esquemas = false;
	}
}
comprobar( $esquemas, 'Cada herramienta declara un esquema de objeto' );

/* Y las que no llevan ningún argumento declaran sus propiedades como un
   OBJETO, no como un array de PHP vacío. No es un detalle de estilo:
   json_encode() escribe [] donde los tres proveedores esperan {}, y la
   petición entera se rechaza con un 400 que no nombra la herramienta
   culpable. */
comprobar( 2 === $vacias, 'Las dos herramientas sin argumentos usan un objeto vacío y no un array' );
$sin_args = true;
foreach ( $defs as $d ) {
	if ( $d['parameters']['properties'] instanceof stdClass
		&& false === strpos( wp_json_encode( $d['parameters'] ), '"properties":{}' ) ) {
		$sin_args = false;
	}
}
comprobar( $sin_args, 'Y al serializarse salen como {}, que es lo que el proveedor acepta' );

// La CSP. El navegador no habla con nadie fuera del sitio, pero el audio
// se reproduce desde una URL blob: y `default-src 'self'` NO la cubre.
// Omitirla deja el panel entero funcionando y mudo, que es el peor fallo
// posible: no se nota hasta que alguien pregunta por qué.
$csp = UHP_Voz::hosts_csp();
comprobar(
	isset( $csp['media-src'] ) && in_array( 'blob:', $csp['media-src'], true ),
	'Con voz, la CSP declara blob: en media-src, que es de donde sale el audio'
);
comprobar(
	array( 'media-src' ) === array_keys( $csp ),
	'Y NADA más: ni script-src, ni connect-src, ni worker-src, ni un solo dominio externo'
);
$sin_voz = $lleno;
$sin_voz['voz_id'] = '';
update_option( 'uhp_voz', $sin_voz );
comprobar( ! UHP_Voz::hosts_csp(), 'Sin voz no hace falta ni esa: la receta queda vacía' );
update_option( 'uhp_voz', $lleno );

// El proveedor se valida: lo que no es uno de los tres cae en el más
// conservador en vez de viajar tal cual a una URL.
comprobar(
	'claude' === UHP_Voz::sanear_proveedor( 'otro' )
		&& 'claude' === UHP_Voz::sanear_proveedor( '' )
		&& 'gemini' === UHP_Voz::sanear_proveedor( '  GEMINI ' ),
	'El proveedor cae en el valor por omisión ante cualquier cosa rara'
);
// Y el nombre del modelo también: va dentro de la URL en dos de los tres
// proveedores, de modo que una barra o un salto de línea ahí no es un
// ajuste mal escrito sino una petición a otra parte.
comprobar(
	'' === UHP_Voz::sanear_modelo( 'modelo con espacios' )
		&& '' === UHP_Voz::sanear_modelo( "claude\n:generateContent" )
		&& '' === UHP_Voz::sanear_modelo( 'ab' ),
	'Un nombre de modelo con forma extraña se descarta entero'
);
comprobar(
	'claude-opus-5-5' === UHP_Voz::sanear_modelo( '  claude-opus-5-5  ' ),
	'Y uno con la forma correcta se conserva, sin espacios'
);
comprobar(
	'low' === UHP_Voz::sanear_esfuerzo( 'altísimo' ) && 'high' === UHP_Voz::sanear_esfuerzo( 'HIGH' ),
	'El esfuerzo cae en «low» ante un valor desconocido: lo caro se pide a propósito'
);

/* ---------------------------------------------------------------- */
echo "\n12. Asistente: canal escrito, voz y visibilidad\n";

// El identificador de voz va dentro de la URL que el servidor consulta:
// lo que no tiene su forma no puede llegar ahí.
comprobar(
	'' === UHP_Voz::sanear_voz_id( 'javascript:alert(1)' )
		&& '' === UHP_Voz::sanear_voz_id( '../../etc/passwd' )
		&& '' === UHP_Voz::sanear_voz_id( 'corta' )
		&& '' === UHP_Voz::sanear_voz_id( '21m00Tcm4TlvDq8i"onerror=' ),
	'Un identificador de voz con forma extraña se descarta'
);
comprobar(
	'21m00Tcm4TlvDq8ikWAM' === UHP_Voz::sanear_voz_id( '  21m00Tcm4TlvDq8ikWAM  ' ),
	'Uno con la forma correcta se conserva'
);

// La velocidad se DESCARTA fuera de margen en vez de recortarse: dejarle
// 1,20 a quien escribió 3 le hace creer que se guardó lo que pidió.
comprobar(
	0.0 === UHP_Voz::sanear_velocidad( 3 )
		&& 0.0 === UHP_Voz::sanear_velocidad( 0.1 )
		&& 0.0 === UHP_Voz::sanear_velocidad( '' )
		&& 0.0 === UHP_Voz::sanear_velocidad( 'rápido' ),
	'Una velocidad fuera de margen se descarta, no se recorta'
);
comprobar(
	0.9 === UHP_Voz::sanear_velocidad( '0,9' ),
	'Y se acepta escrita con coma, como se escribe en Colombia'
);

comprobar(
	'ambos' === UHP_Voz::sanear_entrada( 'cualquier cosa' )
		&& 'texto' === UHP_Voz::sanear_entrada( ' TEXTO ' )
		&& 'todos' === UHP_Voz::sanear_visibilidad( 'inventado' )
		&& 'oculto' === UHP_Voz::sanear_visibilidad( 'oculto' ),
	'Entrada y visibilidad caen en el valor más conservador ante un valor extraño'
);

/* LA SEPARACIÓN QUE ARREGLÓ EL SILENCIO. El micrófono es una forma de
   ENTRAR —la pone el navegador y no cuesta nada— y la voz una de SALIR
   —la pone ElevenLabs—. Estaban en la misma condición, y por eso quien
   entraba escribiendo no recibía respuesta hablada ni con la voz
   perfectamente configurada. Ahora la CSP depende de que haya VOZ. */
$solo_texto            = $lleno;
$solo_texto['entrada'] = 'texto';
update_option( 'uhp_voz', $solo_texto );
comprobar(
	isset( UHP_Voz::hosts_csp()['media-src'] ),
	'Sin micrófono la CSP sigue necesitando media-src: la respuesta se lee en alto igual'
);
comprobar(
	UHP_Voz::con_voz() && ! UHP_Voz::con_micro(),
	'Se puede tener voz sin micrófono, que antes era imposible de expresar'
);
$mudo_con_micro            = $lleno;
$mudo_con_micro['voz_id']  = '';
$mudo_con_micro['entrada'] = 'ambos';
update_option( 'uhp_voz', $mudo_con_micro );
comprobar(
	! UHP_Voz::con_voz() && UHP_Voz::con_micro() && ! UHP_Voz::hosts_csp(),
	'Y micrófono sin voz: se dicta la pregunta y se lee la respuesta, sin tocar la CSP'
);
update_option( 'uhp_voz', $solo_texto );

/* El cuadro de escritura ya no es un ajuste: está SIEMPRE. Era la
   alternativa equivalente que pide la Resolución 1519 de 2020, y hacerla
   depender de una casilla era dejar que una configuración la apagara. */
comprobar(
	! array_key_exists( 'texto', UHP_Voz::config_front() ),
	'El campo de escritura ya no es un ajuste: no hay nada que apagar'
);
comprobar(
	! array_key_exists( 'texto', UHP_Voz::por_defecto() ),
	'Y el ajuste desapareció también de los valores por omisión'
);

// Y al navegador no viaja el identificador de la voz, ni saneado: no lo
// necesita, porque quien sintetiza es el servidor. Solo viaja SI hay voz,
// que es lo único que cambia lo que el panel pinta.
$con_voz           = $solo_texto;
$con_voz['entrada'] = 'ambos';
$con_voz['voz_id']  = '21m00Tcm4TlvDq8ikWAM';
update_option( 'uhp_voz', $con_voz );
$fv = UHP_Voz::config_front();
comprobar(
	true === $fv['voz'] && false === strpos( wp_json_encode( $fv ), '21m00Tcm4TlvDq8ikWAM' ),
	'Al navegador le llega que HAY voz, nunca cuál'
);
/* Y SIN micrófono la voz sigue viajando. Es la comprobación que habría
   impedido el fallo que vio el usuario: antes aquí salía false, de modo
   que el navegador no pedía audio nunca y el asistente mostraba texto
   sin conversar. */
update_option( 'uhp_voz', $solo_texto );
$fv_txt = UHP_Voz::config_front();
comprobar(
	true === $fv_txt['voz'] && false === $fv_txt['micro'],
	'Sin micrófono la respuesta se lee en alto igual: entrar y salir son cosas distintas'
);

/* El marcado. El aviso previo es un AJUSTE, no una puerta: apagado —que
   es como nace— no se imprime ni el rótulo corto ni las plantillas, y el
   asistente se publica igual. */
$solo_texto['voz_id'] = '';
update_option( 'uhp_voz', $solo_texto );
$html_txt = UHP_Voz::control_html( 'x' );
comprobar(
	1 === substr_count( $html_txt, 'data-uhp-voz-accion="abrir"' )
		&& false !== strpos( $html_txt, '>Asistente<' ),
	'Un solo lanzador, y se llama «Asistente»'
);
comprobar(
	false !== strpos( $html_txt, 'data-uhp-voz-micro="no"' ),
	'Y dice que esta página no ofrece micrófono, que es lo único que cambia'
);
comprobar(
	false === strpos( $html_txt, '<template' ) && false === strpos( $html_txt, 'uhp-voz__aviso-corto' ),
	'Con el aviso apagado no se imprime ninguna plantilla de aviso'
);

/* Encendido, UNA plantilla, y la que corresponde. Los dos avisos NO se
   fusionaron al unificar los canales: el de una página con micrófono
   informa del tratamiento de una muestra de voz —dato biométrico— y el
   de una sin él no, porque no ocurre. Fusionarlos pediría autorización
   por algo que no pasa, o la recogería sin informar. Las dos cosas
   incumplen igual el artículo 9. */
$solo_texto['aviso'] = 1;
update_option( 'uhp_voz', $solo_texto );
$html_txt = UHP_Voz::control_html( 'x' );
comprobar(
	1 === substr_count( $html_txt, '<template' )
		&& false === strpos( $html_txt, 'permiso para usar el micr' ),
	'Sin micrófono viaja un solo aviso, y no menciona un micrófono que no se enciende'
);
$con_micro            = $solo_texto;
$con_micro['entrada'] = 'ambos';
update_option( 'uhp_voz', $con_micro );
$html_mic = UHP_Voz::control_html( 'x' );
comprobar(
	1 === substr_count( $html_mic, '<template' )
		&& false !== strpos( $html_mic, 'permiso para usar el micr' ),
	'Con micrófono viaja el otro, que sí informa del dato biométrico'
);
$solo_texto['aviso'] = 0;
update_option( 'uhp_voz', $solo_texto );

/* Un atributo de página puede ESTRECHAR, nunca ampliar: el contenido de
   un post lo edita cualquiera con permiso de edición, y por dónde sale la
   voz de un ciudadano no es una decisión de redacción. */
comprobar(
	false !== strpos( UHP_Voz::control_html( 'x', array( 'entrada' => 'voz' ) ), 'data-uhp-voz-micro="no"' ),
	'Un atributo de página no puede encender el micrófono donde el sitio no lo permite'
);
$ambos            = $lleno;
$ambos['entrada'] = 'ambos';
update_option( 'uhp_voz', $ambos );
$h_ambos = UHP_Voz::control_html( 'x' );
comprobar(
	1 === substr_count( $h_ambos, 'data-uhp-voz-accion="abrir"' )
		&& false !== strpos( $h_ambos, 'data-uhp-voz-micro="si"' ),
	'Con micrófono sigue habiendo UN solo lanzador: no vuelven los dos'
);
comprobar(
	false !== strpos( UHP_Voz::control_html( 'x', array( 'entrada' => 'texto' ) ), 'data-uhp-voz-micro="no"' ),
	'Pero una página concreta sí puede quitarle el micrófono'
);

// Visibilidad «oculto»: el panel se carga y el botón no se pinta, para que
// la plantilla pueda abrirlo desde un control propio.
$oculto                = $ambos;
$oculto['visibilidad'] = 'oculto';
$oculto['aviso']       = 1;
update_option( 'uhp_voz', $oculto );
$h_oculto = UHP_Voz::control_html( 'x' );
comprobar(
	false === strpos( $h_oculto, 'data-uhp-voz-accion="abrir"' )
		&& false !== strpos( $h_oculto, 'data-uhp-voz-zona="panel"' ),
	'Oculto: sin botón pero con panel'
);
comprobar(
	false === strpos( $h_oculto, 'uhp-voz__aviso-corto' ),
	'Y sin el rótulo corto, que anunciaría un envío al exterior junto a un hueco sin nada que pulsar'
);
// Sin lanzador, el bloque no puede quedarse como una caja invisible de
// 360 px con z-index 600 encima del mapa: los municipios de esa esquina
// dejarían de responder al clic y nadie sabría describir el fallo.
comprobar(
	false !== strpos( $h_oculto, 'uhp-voz--sin-lanzador' )
		&& false === strpos( $h_oculto, 'uhp-voz__botones' ),
	'Y marcado para que no intercepte los punteros del mapa'
);

// Visibilidad «editores»: sin capacidad no se pinta NADA, y la página sale
// como si el asistente no existiera.
$solo_ed                = $ambos;
$solo_ed['visibilidad'] = 'editores';
update_option( 'uhp_voz', $solo_ed );
comprobar(
	! UHP_Voz::permitido_para_usuario() && '' === UHP_Voz::control_html( 'x' ),
	'Editores: el visitante sin capacidad no recibe ni el panel'
);
comprobar(
	UHP_Voz::salida_por_usuario(),
	'Y el plugin sabe que esa salida depende del usuario, para pedirle a la caché que no la guarde'
);
$ambos['visibilidad'] = 'todos';
update_option( 'uhp_voz', $ambos );
comprobar(
	! UHP_Voz::salida_por_usuario(),
	'Con visibilidad para todos la salida vuelve a ser la misma para cualquiera'
);

/* Los dos avisos. El escrito no puede prometer un micrófono que no se
   enciende, y tiene que seguir advirtiendo de la transferencia al
   exterior: que el dato sea texto no la hace desaparecer. */
$av_voz = wp_json_encode( UHP_Voz::aviso_bloques( 'voz' ) );
$av_txt = wp_json_encode( UHP_Voz::aviso_bloques( 'texto' ) );
comprobar(
	false !== strpos( $av_voz, 'permiso para usar el micr' )
		&& false === strpos( $av_txt, 'permiso para usar el micr' ),
	'El aviso escrito no anuncia un micrófono que no se va a encender'
);
comprobar(
	false !== strpos( $av_txt, 'NO se le pide el micr' ),
	'Y lo dice con esas palabras, porque es la diferencia que importa'
);
comprobar(
	false !== strpos( $av_txt, 'art' ) && false !== strpos( $av_txt, '26' ),
	'El aviso escrito sigue advirtiendo de la transferencia internacional'
);
comprobar(
	false !== strpos( $av_txt, 'art' ) && false !== strpos( $av_txt, ' 6 ' ),
	'Y advierte de los datos sensibles que nadie pidió, que ahora llegarían escritos'
);
comprobar(
	false !== strpos( $av_txt, 'quiero escribir' )
		&& false === strpos( $av_txt, 'quiero hablar' ),
	'Cada aviso nombra el botón que de verdad lo autoriza'
);

/* LA COMPROBACIÓN QUE SALVABA UNA SESIÓN, al revés. El prompt del agente
   interpolaba variables entre llaves y el navegador mandaba un conjunto
   fijo: si el prompt escribía una que el navegador no mandaba, el
   proveedor rechazaba la conversación entera y nadie sabía por qué.
   Ahora el prompt es estático y lo manda el servidor en cada pregunta,
   así que la garantía es la contraria: que NO quede ninguna llave viva. */
foreach ( array( 'voz', 'texto' ) as $canal ) {
	comprobar(
		! preg_match( '/\{\{|\}\}/', UHP_Voz::prompt_sistema( $canal ) ),
		'El prompt del canal «' . $canal . '» no interpola nada: no hay variable que pueda faltar'
	);
}
/* Y el prompt tiene que seguir llevando las reglas de honestidad, que son
   la razón de que lo mande el servidor y no se pueda editar desde una
   página. */
$ps = UHP_Voz::prompt_sistema( 'texto' );
comprobar(
	false !== strpos( $ps, 'cero' ) && false !== strpos( $ps, 'promedi' ),
	'Lleva la regla del cero y la del promedio subregional, que son las dos que se incumplen solas'
);
comprobar(
	false !== strpos( $ps, 'Pupiales' ) && false !== strpos( $ps, 'Ipiales' ),
	'Y la advertencia sobre los municipios que se confunden entre sí'
);

/* Y la novena herramienta: la que lee el gráfico de barras. */
$nombres = array();
foreach ( UHP_Voz::definiciones_herramientas() as $d ) {
	$nombres[] = $d['name'];
}
comprobar(
	in_array( 'uhp_comparar_subregiones', $nombres, true ),
	'El asistente declara la herramienta que lee el gráfico de barras'
);
foreach ( UHP_Voz::definiciones_herramientas() as $d ) {
	if ( 'uhp_comparar_subregiones' === $d['name'] ) {
		comprobar(
			true === $d['espera'],
			'Y espera respuesta, porque el asistente necesita el dato para seguir hablando'
		);
		comprobar(
			false !== strpos( $d['description'], 'SUBREGIONALES' )
				&& false !== strpos( $d['description'], 'sin cifra' ),
			'Su descripción advierte de qué cifra es y de qué falta'
		);
	}
}

/* Las trece subregiones del departamento frente a las once publicadas. */
$ref = new ReflectionMethod( 'GobernacionNarino\\Urkunina\\UHP_Rest', 'subregiones_sin_cifra' );
$ref->setAccessible( true );
$faltan = $ref->invoke( null );
comprobar(
	array( 'Pacífico Sur', 'Sanquianga' ) === $faltan,
	'El tablero sabe qué dos subregiones de Nariño no tienen cifra publicada'
);
comprobar(
	13 === count( UHP_Subregiones::declaradas() ) && 11 === count( UHP_Datos::valor( 'prev_subregion', 'subregiones', array() ) ),
	'Trece declaradas y once publicadas: la diferencia es un dato, no un descuido'
);

/* Participantes examinados por municipio. El informe los publica en doce
   de los cincuenta y cinco, y hasta ahora no viajaban: la tarjeta de
   participantes mostraba un prorrateo de 5.000 entre 55 también para esos
   doce, y para Pupiales decía 91 donde el informe publica 80. */
$carga_tab = UHP_Rest::carga_tablero();
$con_part  = 0;
$pupiales  = null;
$sin_part  = null;
foreach ( $carga_tab['features'] as $f ) {
	$pr = $f['properties'];
	if ( null !== $pr['part'] ) {
		$con_part++;
		if ( 'Pupiales' === $pr['n'] ) {
			$pupiales = $pr;
		}
	} elseif ( ! empty( $pr['int'] ) && null === $sin_part ) {
		$sin_part = $pr;
	}
}
comprobar( 12 === $con_part, sprintf( 'Doce municipios traen sus participantes publicados (%d)', $con_part ) );
comprobar(
	is_array( $pupiales ) && 80 === $pupiales['part'] && 57 === $pupiales['pos'],
	'Pupiales trae los 80 examinados y los 57 positivos que publica el informe, no un prorrateo de 91'
);
comprobar(
	is_array( $sin_part ) && null === $sin_part['part'] && 0 === $sin_part['casos'],
	'Donde no se publica, participantes es null y NO cero: que no se publique cuántos examinó no significa que no examinara a nadie, mientras que cero casos de cáncer sí es un dato'
);
$perfil = $carga_tab['meta']['perfil'];
comprobar(
	'departamental' === $perfil['ambito'] && 5 === count( $perfil['bloques'] ),
	'El perfil de los 5.000 viaja con su ámbito pegado y sus cinco bloques'
);
$genero = null;
foreach ( $perfil['bloques'] as $b ) {
	if ( 'genero' === $b['clave'] ) {
		$genero = $b;
	}
}
comprobar(
	is_array( $genero ) && 4994 === $genero['n_con_dato'] && 3204 === $genero['filas'][0]['personas'],
	'Y con el denominador: 4.994 de los 5.000 tienen el campo de género, no los 5.000'
);
$etnia = null;
$nutri = null;
foreach ( $perfil['bloques'] as $b ) {
	if ( 'pertenencia_etnica' === $b['clave'] ) {
		$etnia = $b;
	}
	if ( 'estado_nutricional' === $b['clave'] ) {
		$nutri = $b;
	}
}
comprobar(
	is_array( $etnia ) && 4 === count( $etnia['filas'] ) && is_array( $nutri ) && 4 === count( $nutri['filas'] ),
	'Y con TODAS las categorías publicadas: el bloque de la pantalla se dejaba fuera «Otro / no reportado» y «Delgadez»'
);


/* ---------------------------------------------------------------- */
echo "\n13. Asistente: verificación de las claves\n";

/* «Configurar la API» consistía en pegar dos cadenas a ciegas y esperar a
   que un ciudadano descubriera el fallo. Esto comprueba que el plugin le
   pregunta a cada proveedor y que traduce la respuesta a algo que un
   administrador pueda accionar. */
$verif_base                  = $lleno;
$verif_base['entrada']       = 'ambos';
$verif_base['voz_id']        = '21m00Tcm4TlvDq8ikWAM';
$verif_base['voz_velocidad'] = 0.9;
$verif_base['proveedor']     = 'claude';
update_option( 'uhp_voz', $verif_base );
update_option( 'uhp_llm_clave', 'sk-ant-de-prueba' );
update_option( 'uhp_voz_clave', 'sk-el-de-prueba' );
delete_transient( UHP_Voz::VERIF );
delete_transient( UHP_Voz::VERIF . '_voces' );

comprobar( false === UHP_Voz::verificacion(), 'Sin verificar todavía, el panel no inventa un informe' );

/* Respuestas de los dobles. El orden importa: primero el modelo (POST),
   después ElevenLabs (GET). */
$ok_llm = array(
	'response' => array( 'code' => 200 ),
	'body'     => wp_json_encode(
		array(
			'content'     => array( array( 'type' => 'text', 'text' => 'listo' ) ),
			'stop_reason' => 'end_turn',
			'usage'       => array( 'input_tokens' => 12, 'output_tokens' => 2 ),
		)
	),
);
$ok_voces = array(
	'response' => array( 'code' => 200 ),
	'body'     => wp_json_encode(
		array(
			'voices' => array(
				array( 'voice_id' => '21m00Tcm4TlvDq8ikWAM', 'name' => 'Lucía' ),
				array( 'voice_id' => 'AZnzlk1XvdvUeBnXmlld', 'name' => 'Domi' ),
			),
		)
	),
);

// Todo bien.
uhp_test_http( array( $ok_llm, $ok_voces ) );
$inf = UHP_Voz::verificar();
comprobar( true === $inf['llm']['ok'], 'El modelo responde y se reporta como tal' );
comprobar( true === $inf['voz']['ok'], 'La voz existe en la cuenta y se reporta por su nombre' );
comprobar(
	false !== strpos( $inf['voz']['mensaje'], 'Lucía' ),
	'Y se nombra: «21m00Tcm4TlvDq8ikWAM» no le dice nada a nadie, «Lucía» sí'
);

/* LA PETICIÓN. En Gemini la clave viaja en una cabecera y NUNCA en la
   URL: una clave en la URL acaba en los registros del servidor, en los
   del proxy y en el historial del navegador de quien la pegue. */
$peticiones = uhp_test_http_peticiones();
comprobar( 2 === count( $peticiones ), 'La verificación hace exactamente dos peticiones, una por proveedor' );
comprobar(
	false === strpos( $peticiones[0]['url'], 'sk-ant-de-prueba' )
		&& isset( $peticiones[0]['args']['headers']['x-api-key'] )
		&& 'sk-ant-de-prueba' === $peticiones[0]['args']['headers']['x-api-key'],
	'La clave del modelo va en su cabecera y no en la URL'
);
comprobar(
	false !== strpos( $peticiones[1]['url'], '/v2/voices' )
		&& 'sk-el-de-prueba' === $peticiones[1]['args']['headers']['xi-api-key'],
	'Y la de ElevenLabs también, contra el listado de voces de la cuenta'
);
/* La pregunta de prueba es MÍNIMA: sin herramientas y de una línea. Una
   verificación que costara lo que una conversación de verdad no se usaría
   nunca, y entonces no serviría de nada. */
$cuerpo_llm = json_decode( $peticiones[0]['args']['body'], true );
comprobar(
	empty( $cuerpo_llm['tools'] ) && 1 === count( $cuerpo_llm['messages'] ),
	'La prueba contra el modelo va sin herramientas y con un solo mensaje'
);

// Sin clave del modelo no se gasta ni una petición: se dice y ya.
update_option( 'uhp_llm_clave', '' );
uhp_test_http( array() );
$inf = UHP_Voz::verificar();
comprobar(
	false === $inf['llm']['ok'] && false !== strpos( $inf['llm']['mensaje'], 'Sin clave' ),
	'Sin clave del modelo se dice sin salir a la red'
);
update_option( 'uhp_llm_clave', 'sk-ant-de-prueba' );

// Clave mala. 401 y 403 significan lo mismo para quien configura.
foreach ( array( 401, 403 ) as $codigo ) {
	uhp_test_http( array( array( 'response' => array( 'code' => $codigo ), 'body' => '{}' ), $ok_voces ) );
	$inf = UHP_Voz::verificar();
	comprobar(
		false === $inf['llm']['ok'] && false !== strpos( $inf['llm']['mensaje'], 'no es válida' ),
		sprintf( 'Un %d del modelo se traduce a «la clave no es válida»', $codigo )
	);
}

// Modelo que no existe: el error más fácil de cometer al escribirlo a mano.
uhp_test_http( array( array( 'response' => array( 'code' => 404 ), 'body' => '{}' ), $ok_voces ) );
$verif_base['modelo'] = 'claude-opus-99';
update_option( 'uhp_voz', $verif_base );
$inf = UHP_Voz::verificar();
comprobar(
	false === $inf['llm']['ok'] && false !== strpos( $inf['llm']['mensaje'], 'no existen con ese nombre' ),
	'Un modelo inexistente se dice como tal'
);
comprobar(
	false !== strpos( $inf['llm']['detalle'], 'claude-opus-99' ),
	'Y se repite el nombre que se pidió, que es donde está la errata'
);
$verif_base['modelo'] = '';
update_option( 'uhp_voz', $verif_base );

// Saldo y límite: dos cosas distintas que un administrador arregla distinto.
uhp_test_http( array( array( 'response' => array( 'code' => 402 ), 'body' => '{}' ), $ok_voces ) );
$inf = UHP_Voz::verificar();
comprobar( false !== strpos( $inf['llm']['mensaje'], 'saldo' ), 'Un 402 habla de saldo' );
uhp_test_http( array( array( 'response' => array( 'code' => 429 ), 'body' => '{}' ), $ok_voces ) );
$inf = UHP_Voz::verificar();
comprobar( false !== strpos( $inf['llm']['mensaje'], 'límite' ), 'Un 429 habla del límite de peticiones' );
// Y un fallo del proveedor NO es un fallo de esta configuración: decirlo
// evita que alguien se pase la tarde cambiando claves que estaban bien.
uhp_test_http( array( array( 'response' => array( 'code' => 503 ), 'body' => '{}' ), $ok_voces ) );
$inf = UHP_Voz::verificar();
comprobar(
	false !== strpos( $inf['llm']['mensaje'], 'no es un problema de esta configuración' ),
	'Un 5xx se dice que no es culpa de lo configurado aquí'
);

/* LA COMPROBACIÓN QUE EVITA UN PANEL MUDO: el identificador de voz de
   otra cuenta, o de una voz retirada, no falla al guardarlo. Falla la
   primera vez que alguien pregunta algo, en producción. */
delete_transient( UHP_Voz::VERIF . '_voces' );
$verif_base['voz_id'] = 'ZZnzlk1XvdvUeBnXmlZZ';
update_option( 'uhp_voz', $verif_base );
uhp_test_http( array( $ok_llm, $ok_voces ) );
$inf = UHP_Voz::verificar();
comprobar(
	false === $inf['voz']['ok'] && false !== strpos( $inf['voz']['mensaje'], 'no está en la cuenta' ),
	'Una voz que no está en la cuenta se detecta aquí y no en producción'
);
comprobar(
	false !== strpos( $inf['voz']['detalle'], 'Lucía' ) && false !== strpos( $inf['voz']['detalle'], 'Domi' ),
	'Y se listan las que sí hay, en vez de dejar al administrador adivinando'
);
$verif_base['voz_id'] = '21m00Tcm4TlvDq8ikWAM';
update_option( 'uhp_voz', $verif_base );

/* El nombre de la voz lo escribe quien la creó en la cuenta de
   ElevenLabs, y este panel lo imprime: es texto ajeno y se sanea como
   tal. Que el nombre venga de una cuenta propia no lo hace confiable. */
delete_transient( UHP_Voz::VERIF . '_voces' );
uhp_test_http(
	array(
		$ok_llm,
		array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode(
				array(
					'voices' => array(
						array( 'voice_id' => '21m00Tcm4TlvDq8ikWAM', 'name' => '<script>alert(1)</script>' ),
					),
				)
			),
		),
	)
);
$inf = UHP_Voz::verificar();
comprobar(
	false === strpos( $inf['voz']['mensaje'], '<script' ),
	'El nombre que devuelve el proveedor se sanea antes de imprimirlo'
);

/* La voz es opcional y apagarla no es un fallo: decir «error» donde no lo
   hay enseña a ignorar los errores. */
delete_transient( UHP_Voz::VERIF . '_voces' );
$sin_voz           = $verif_base;
$sin_voz['voz_id'] = '';
update_option( 'uhp_voz', $sin_voz );
update_option( 'uhp_voz_clave', '' );
uhp_test_http( array( $ok_llm ) );
$inf = UHP_Voz::verificar();
comprobar(
	null === $inf['voz']['ok'] && false !== strpos( $inf['voz']['mensaje'], 'apagada' ),
	'Sin voz configurada el informe lo dice sin llamarlo error'
);

// Pero con una sola de las dos piezas, sí: es una configuración a medias
// que no va a sonar, y el administrador cree que sí.
update_option( 'uhp_voz_clave', 'sk-el-de-prueba' );
uhp_test_http( array( $ok_llm ) );
$inf = UHP_Voz::verificar();
comprobar(
	false === $inf['voz']['ok'] && false !== strpos( $inf['voz']['mensaje'], 'No va a sonar' ),
	'Con clave pero sin ID de voz se dice que no va a sonar'
);

// Y si ElevenLabs no responde, no se bloquea la verificación del modelo:
// son dos piezas independientes y fallan por separado.
delete_transient( UHP_Voz::VERIF . '_voces' );
update_option( 'uhp_voz', $verif_base );
uhp_test_http( array( $ok_llm, array( 'response' => array( 'code' => 401 ), 'body' => '{}' ) ) );
$inf = UHP_Voz::verificar();
comprobar(
	true === $inf['llm']['ok'] && false === $inf['voz']['ok'],
	'Que ElevenLabs falle no tumba el informe del modelo: son dos piezas'
);

/* LOS TRES ADAPTADORES. Cada proveedor quiere las herramientas en un
   sitio distinto del cuerpo y con otro nombre, pero los tres rechazan la
   petición entera si una declaración está mal formada. Esto manda una
   pregunta de verdad —con herramientas— por cada uno y mira lo que salió
   por el cable, que es lo único que de verdad se puede afirmar. */
$sitio = array(
	'claude' => array( 'api.anthropic.com', 'tools', 'input_schema' ),
	'openai' => array( 'api.openai.com', 'tools', 'parameters' ),
	'gemini' => array( 'generativelanguage.googleapis.com', 'tools', 'parametersJsonSchema' ),
);
foreach ( $sitio as $prov => $donde ) {
	list( $host, $llave, $donde_esquema ) = $donde;

	uhp_test_http( array( array( 'response' => array( 'code' => 500 ), 'body' => '{}' ) ) );
	UHP_LLM::conversar(
		array(
			'proveedor'    => $prov,
			'clave'        => 'clave-' . $prov,
			'modelo'       => '',
			'esfuerzo'     => 'low',
			'sistema'      => 'x',
			'mensajes'     => array( array( 'rol' => 'usuario', 'texto' => 'hola' ) ),
			'herramientas' => UHP_Voz::definiciones_herramientas(),
		)
	);
	$peticiones = uhp_test_http_peticiones();
	$cuerpo     = json_decode( $peticiones[0]['args']['body'], true );

	comprobar(
		false !== strpos( $peticiones[0]['url'], $host ),
		sprintf( 'El adaptador de %s llama a su propio dominio', $prov )
	);
	comprobar(
		false === strpos( $peticiones[0]['url'], 'clave-' . $prov ),
		sprintf( 'Y la clave de %s no va en la URL, que acaba en los registros', $prov )
	);

	$lista = 'gemini' === $prov ? $cuerpo[ $llave ][0]['functionDeclarations'] : $cuerpo[ $llave ];
	comprobar(
		count( $lista ) === count( UHP_Voz::definiciones_herramientas() ),
		sprintf( 'Le manda a %s las diez herramientas', $prov )
	);

	$cerrados = true;
	foreach ( $lista as $h ) {
		$esq = isset( $h[ $donde_esquema ] ) ? $h[ $donde_esquema ] : null;
		if ( ! is_array( $esq )
			|| ! array_key_exists( 'additionalProperties', $esq )
			|| false !== $esq['additionalProperties']
			|| ! isset( $esq['required'] ) ) {
			$cerrados = false;
		}
	}
	comprobar(
		$cerrados,
		sprintf( 'Y cada esquema va cerrado y con su lista de obligatorios, como %s lo exige', $prov )
	);
}

// El informe se guarda, para que el panel no vuelva a gastar en cada carga.
comprobar(
	is_array( UHP_Voz::verificacion() ) && isset( UHP_Voz::verificacion()['momento'] ),
	'El informe queda guardado con su momento, y el panel lo pinta sin gastar otra petición'
);

// Y se deja la opción como estaba, para que una prueba posterior no herede
// la configuración de ésta.
update_option( 'uhp_voz', $voz_antes );
delete_transient( UHP_Voz::VERIF );
delete_transient( UHP_Voz::VERIF . '_voces' );


/* ---------------------------------------------------------------- */
echo "\n14. Asistente: la petición mínima y lo que se dice cuando falla\n";

/* ESTA SECCIÓN EXISTE POR UN FALLO REAL EN PRODUCCIÓN. Un administrador
   pulsó «Verificar las claves» y el panel contestó «Google Gemini no
   respondió: el proveedor rechazó la petición por mal formada». Su clave
   estaba bien; lo que estaba mal era la petición que mandaba el plugin.

   La verificación es la ÚNICA petición del plugin que va sin
   herramientas, y el adaptador construía el bloque de todas formas: con
   la lista vacía salía `"tools":[{"functionDeclarations":[]}]`, y Gemini
   rechaza la petición ENTERA si un Tool no declara ninguna función. Las
   pruebas no lo vieron porque todas mandaban las diez herramientas. */
$verif_voz = array_merge(
	UHP_Voz::por_defecto(),
	array( 'activo' => 1, 'voz_id' => '21m00Tcm4TlvDq8ikWAM' )
);
update_option( 'uhp_voz', $verif_voz );
update_option( 'uhp_llm_clave', 'sk-de-prueba' );
update_option( 'uhp_voz_clave', 'sk-el-de-prueba' );

$cuerpo_de = function ( $proveedor, $herramientas ) {
	uhp_test_http( array( array( 'response' => array( 'code' => 400 ), 'body' => '{}' ) ) );
	UHP_LLM::conversar(
		array(
			'proveedor'    => $proveedor,
			'clave'        => 'k',
			'modelo'       => '',
			'esfuerzo'     => 'low',
			'sistema'      => 'x',
			'mensajes'     => array( array( 'rol' => 'usuario', 'texto' => 'Di: listo' ) ),
			'herramientas' => $herramientas,
		)
	);
	$p = uhp_test_http_peticiones();
	return json_decode( $p[0]['args']['body'], true );
};

foreach ( array( 'claude', 'openai', 'gemini' ) as $prov ) {
	$sin = $cuerpo_de( $prov, array() );
	comprobar(
		! array_key_exists( 'tools', $sin ) && ! array_key_exists( 'toolConfig', $sin ) && ! array_key_exists( 'tool_choice', $sin ),
		sprintf( 'Sin herramientas, a %s no se le manda ningún bloque de herramientas', $prov )
	);

	$con = $cuerpo_de( $prov, UHP_Voz::definiciones_herramientas() );
	comprobar(
		array_key_exists( 'tools', $con ),
		sprintf( 'Y con ellas sí, que es lo que no se puede romper al arreglar lo anterior (%s)', $prov )
	);
}

/* Y la comprobación concreta del fallo, por si alguien vuelve a
   construir el bloque incondicionalmente en otro sitio: lo que Gemini
   rechaza no es «tools» sino una lista de declaraciones VACÍA. */
$g = $cuerpo_de( 'gemini', array() );
comprobar(
	false === strpos( wp_json_encode( $g ), 'functionDeclarations' ),
	'La petición de verificación a Gemini no menciona functionDeclarations en ninguna parte'
);

/* LO QUE HIZO EL MENSAJE INÚTIL. Gemini explicaba con todas las letras
   qué campo estaba mal, el plugin guardaba esa frase, y `motivo()` la
   tiraba para publicar una traducción genérica del código HTTP. Un
   diagnóstico que existe y no se enseña es peor que no tenerlo: manda a
   buscar el fallo donde no está. */
$motivo = new ReflectionMethod( 'GobernacionNarino\\Urkunina\\UHP_Voz', 'motivo' );
$motivo->setAccessible( true );

$cuerpo_gemini = 'http 400: ' . wp_json_encode(
	array(
		'error' => array(
			'code'    => 400,
			'message' => '* GenerateContentRequest.tools[0].function_declarations: at least one function declaration is required',
			'status'  => 'INVALID_ARGUMENT',
		),
	)
);
$dicho = $motivo->invoke( null, $cuerpo_gemini, 400 );
comprobar(
	false !== strpos( $dicho, 'rechazó la petición' ),
	'Un 400 sigue diciendo en castellano que el proveedor rechazó la petición'
);
comprobar(
	false !== strpos( $dicho, 'function declaration' ),
	'Y AÑADE lo que dijo el proveedor, que es lo único que señala el campo culpable'
);

// Cada proveedor anida su mensaje en un sitio distinto. Se buscan todos.
foreach ( array(
	'{"error":{"message":"Mensaje de Gemini y OpenAI"}}'      => 'Mensaje de Gemini y OpenAI',
	'{"message":"Mensaje de Claude"}'                          => 'Mensaje de Claude',
	'{"detail":{"status":"voice_not_found","message":"Mensaje de ElevenLabs"}}' => 'Mensaje de ElevenLabs',
) as $json => $esperado ) {
	comprobar(
		false !== strpos( $motivo->invoke( null, 'http 401: ' . $json, 401 ), $esperado ),
		'Se extrae el mensaje esté donde esté: ' . $esperado
	);
}

/* NINGUNA URL sale en esos mensajes, y no es celo: se imprimen en una
   página del panel, algunos proveedores devuelven la petición entera en
   el error, y en una URL puede ir la clave. */
$con_url = $motivo->invoke(
	null,
	'transporte: cURL error 60: SSL certificate problem (https://generativelanguage.googleapis.com/v1beta/models/x:generateContent?key=AIzaSECRETO)',
	0
);
comprobar(
	false === strpos( $con_url, 'AIzaSECRETO' ) && false === strpos( $con_url, '?key=' ),
	'Una URL dentro del mensaje se tapa: ahí es donde algunos proveedores admiten la clave'
);
comprobar(
	false !== strpos( $con_url, 'generativelanguage.googleapis.com' ),
	'Pero el dominio se conserva, porque saber contra qué host se habló es parte del diagnóstico'
);
comprobar(
	false !== strpos( $con_url, 'certificate' ),
	'Y el motivo del transporte también: un certificado rechazado no se arregla como un cortafuegos'
);

/* Un 200 sin contenido NO es una respuesta. Antes se publicaba como
   «responde» con un «Contestó: «»» vacío, que es peor que un error:
   afirma que está todo bien. */
uhp_test_http(
	array(
		array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'candidates' => array( array( 'content' => array( 'parts' => array() ) ) ) ) ) ),
		array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'voices' => array( array( 'voice_id' => '21m00Tcm4TlvDq8ikWAM', 'name' => 'Lucía' ) ) ) ) ),
	)
);
update_option( 'uhp_voz', array_merge( $verif_voz, array( 'proveedor' => 'gemini' ) ) );
delete_transient( UHP_Voz::VERIF . '_voces' );
$inf = UHP_Voz::verificar();
comprobar(
	false === $inf['llm']['ok'] && false !== strpos( $inf['llm']['mensaje'], 'no devolvió nada' ),
	'Un 200 sin texto ni llamadas NO se publica como «responde»'
);

/* La verificación prueba lo que CORRE, no lo que está escrito. El modelo
   se sanea igual que en la ruta real: antes, uno escrito con un espacio
   fallaba aquí con 400 y funcionaba en producción, porque la ruta lo
   descarta y usa el recomendado. */
uhp_test_http(
	array(
		array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'candidates' => array( array( 'content' => array( 'parts' => array( array( 'text' => 'listo' ) ) ) ) ) ) ) ),
		array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'voices' => array( array( 'voice_id' => '21m00Tcm4TlvDq8ikWAM', 'name' => 'Lucía' ) ) ) ) ),
	)
);
update_option( 'uhp_voz', array_merge( $verif_voz, array( 'proveedor' => 'gemini', 'modelo' => 'modelo con espacios' ) ) );
delete_transient( UHP_Voz::VERIF . '_voces' );
$inf = UHP_Voz::verificar();
$pedido = json_decode( uhp_test_http_peticiones()[0]['args']['body'], true );
comprobar(
	true === $inf['llm']['ok'],
	'Un modelo mal escrito no hace fallar la verificación: la ruta real también lo descartaría'
);
comprobar(
	false !== strpos( uhp_test_http_peticiones()[0]['url'], UHP_LLM::modelo_por_defecto( 'gemini' ) ),
	'Y se prueba el modelo que la ruta real usaría, no la cadena guardada'
);
comprobar(
	false !== strpos( $inf['llm']['detalle'], 'se descartó' ),
	'El panel dice que lo escrito se descartó, en vez de dar por bueno un nombre que nunca se usa'
);

/* El segundo error que vio el administrador: la lista de voces daba
   NOMBRES cuando el campo pide IDENTIFICADORES. No se podía copiar nada
   de ese texto. */
uhp_test_http(
	array(
		array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'candidates' => array( array( 'content' => array( 'parts' => array( array( 'text' => 'listo' ) ) ) ) ) ) ) ),
		array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode(
				array(
					'voices' => array(
						array( 'voice_id' => 'aaaaaaaaaaaaaaaaaaaa', 'name' => 'Lumina' ),
						array( 'voice_id' => 'bbbbbbbbbbbbbbbbbbbb', 'name' => 'Luis Casiano' ),
					),
				)
			),
		),
	)
);
update_option( 'uhp_voz', array_merge( $verif_voz, array( 'proveedor' => 'gemini', 'voz_id' => 'zzzzzzzzzzzzzzzzzzzz' ) ) );
delete_transient( UHP_Voz::VERIF . '_voces' );
$inf = UHP_Voz::verificar();
comprobar(
	false === $inf['voz']['ok'],
	'Una voz que no está en la cuenta se sigue detectando'
);
comprobar(
	false !== strpos( $inf['voz']['detalle'], 'aaaaaaaaaaaaaaaaaaaa' )
		&& false !== strpos( $inf['voz']['detalle'], 'Lumina' ),
	'Y ahora se listan los IDENTIFICADORES junto al nombre, que es lo que hay que copiar'
);
comprobar(
	false !== strpos( $inf['voz']['detalle'], 'zzzzzzzzzzzzzzzzzzzz' ),
	'Y se dice cuál está configurado ahora, para poder compararlo'
);

/* Y cuando ElevenLabs no contesta, se dice contra qué host se preguntó:
   con el filtro de residencia de datos puesto, la verificación y la
   síntesis pueden ir a dominios distintos. */
uhp_test_http(
	array(
		array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'candidates' => array( array( 'content' => array( 'parts' => array( array( 'text' => 'listo' ) ) ) ) ) ) ) ),
		array( 'response' => array( 'code' => 401 ), 'body' => '{"detail":{"status":"invalid_api_key","message":"La clave fue revocada"}}' ),
	)
);
update_option( 'uhp_voz', array_merge( $verif_voz, array( 'proveedor' => 'gemini' ) ) );
delete_transient( UHP_Voz::VERIF . '_voces' );
$inf = UHP_Voz::verificar();
comprobar(
	false !== strpos( $inf['voz']['mensaje'], 'La clave fue revocada' ),
	'Lo que dice ElevenLabs en un 401 llega al panel, en vez de «no respondió» a secas'
);
comprobar(
	false !== strpos( $inf['voz']['detalle'], 'elevenlabs.io' ),
	'Y contra qué host se preguntó, que con residencia de datos puede no ser el de la síntesis'
);

update_option( 'uhp_voz', $voz_antes );
delete_transient( UHP_Voz::VERIF );
delete_transient( UHP_Voz::VERIF . '_voces' );

/* ---------------------------------------------------------------- */
echo "\n15. La versión del plugin\n";

/* ESTO NO ES BUROCRACIA. `UHP_VERSION` es el `?ver=` de los quince
   recursos que encola UHP_Assets y la condición que decide si corre la
   migración de UHP_Activator. Congelada, un sitio que ya sirvió la
   versión anterior sigue entregando el JavaScript viejo desde la caché
   del navegador —y el asistente no es que suene mal: no contesta, porque
   el archivo viejo pide rutas que ya no se registran—.

   Vive en DOS sitios del archivo principal y es la única pareja que
   puede desincronizarse sin que nada lo note. */
$principal = (string) file_get_contents( UHP_DIR . 'urkunina-5000.php' );

preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $principal, $m_cab );
preg_match( "/define\(\s*'UHP_VERSION',\s*'([^']+)'\s*\)/", $principal, $m_cte );

comprobar(
	! empty( $m_cab[1] ) && ! empty( $m_cte[1] ),
	'El archivo principal declara la versión en la cabecera y en la constante'
);
comprobar(
	! empty( $m_cab[1] ) && ! empty( $m_cte[1] ) && $m_cab[1] === $m_cte[1],
	sprintf(
		'Y las dos coinciden (cabecera: %s · constante: %s)',
		isset( $m_cab[1] ) ? $m_cab[1] : '?',
		isset( $m_cte[1] ) ? $m_cte[1] : '?'
	)
);
comprobar(
	! empty( $m_cab[1] ) && preg_match( '/^\d+\.\d+\.\d+$/', $m_cab[1] ),
	'Con tres números, como pide el versionado semántico'
);

// Y el CHANGELOG la nombra: una versión sin entrada es una versión que
// nadie sabe qué trae.
$historial = is_readable( UHP_DIR . 'CHANGELOG.md' ) ? (string) file_get_contents( UHP_DIR . 'CHANGELOG.md' ) : '';
comprobar( '' !== $historial, 'Existe un CHANGELOG.md donde anotar cada cambio' );
comprobar(
	! empty( $m_cab[1] ) && false !== strpos( $historial, '[' . $m_cab[1] . ']' ),
	'Y tiene entrada para la versión actual'
);

/* El stub de la suite lleva una versión OBVIAMENTE falsa, para que nadie
   la sincronice por reflejo creyendo que es otra copia de la de verdad:
   aquí no se encola nada y el número no significa nada. */
comprobar(
	'0.0.0-test' === UHP_VERSION,
	'La versión de los stubs es falsa a propósito y no se sincroniza con la real'
);

echo "\n16. Asistente: códigos de error en el registro, la consola y el panel\n";

/* «No pude consultar en este momento» es lo correcto para el visitante y
   no le sirve a quien tiene que arreglarlo. Desde la 2.1 cada fallo lleva
   un código UHP-ÁREA-NN que es el MISMO en el registro de PHP, en la
   consola del navegador, en el panel y en la pantalla del visitante. */

$catalogo = UHP_Codigos::CATALOGO;
$malos    = array();
foreach ( $catalogo as $cod => $fila ) {
	$frase = $fila[1];
	if ( ! preg_match( '/^UHP-[A-Z]{3}-[0-9]{2}$/', $cod )
		|| ! in_array( $fila[0], array( 'error', 'warn', 'info' ), true )
		|| mb_strlen( $frase ) > 70
		|| false !== strpos( $frase, '%' )
		|| preg_match( '/[.]$/', $frase )
		|| mb_strtolower( mb_substr( $frase, 0, 1 ) ) !== mb_substr( $frase, 0, 1 )
		|| preg_match( '/claude|anthropic|openai|gpt|gemini|google|elevenlabs/i', $frase ) ) {
		$malos[] = $cod;
	}
}
comprobar(
	! $malos,
	'Cada código del servidor tiene forma UHP-ÁREA-NN, un nivel válido y una frase fija, corta, sin variables y sin nombrar al proveedor' . ( $malos ? ': ' . implode( ', ', $malos ) : '' )
);

/* PRIMERO EL CUERPO, DESPUÉS EL ESTADO. Los cuatro casos reales en que
   clasificar solo por HTTP se equivocaba. */
$casos = array(
	array( 'LLM', 429, '{"error":{"message":"You exceeded your current quota","type":"insufficient_quota","code":"insufficient_quota"}}', 'UHP-LLM-07', 'Un 429 de OpenAI por falta de saldo es saldo, no límite de ritmo' ),
	array( 'LLM', 400, '{"error":{"code":400,"message":"API key not valid. Please pass a valid API key.","status":"INVALID_ARGUMENT","details":[{"@type":"type.googleapis.com/google.rpc.ErrorInfo","reason":"API_KEY_INVALID"}]}}', 'UHP-LLM-03', 'Un 400 de Gemini por clave inválida es la clave, no una petición mal formada' ),
	array( 'LLM', 400, '{"type":"error","error":{"type":"invalid_request_error","message":"Your credit balance is too low to access the Anthropic API."}}', 'UHP-LLM-07', 'Un 400 de Anthropic por saldo es saldo' ),
	array( 'VOZ', 401, '{"detail":{"status":"quota_exceeded","message":"This request exceeds your quota."}}', 'UHP-VOZ-07', 'Un 401 de ElevenLabs por cuota agotada es saldo, no la clave' ),
	array( 'LLM', 400, '{"error":{"code":400,"message":"* GenerateContentRequest.tools[0].function_declarations: at least one function declaration is required","status":"INVALID_ARGUMENT"}}', 'UHP-LLM-06', 'El 400 de Gemini de la 1.x sigue siendo una petición rechazada' ),
	array( 'LLM', 401, '{}', 'UHP-LLM-03', 'Sin cuerpo, un 401 es la clave' ),
	array( 'LLM', 403, '{}', 'UHP-LLM-04', 'Sin cuerpo, un 403 es un permiso' ),
	array( 'VOZ', 404, '{"detail":{"status":"voice_not_found","message":"A voice with that id was not found."}}', 'UHP-VOZ-05', 'Una voz que no existe en la cuenta' ),
	array( 'LLM', 529, '{"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}', 'UHP-LLM-09', 'La sobrecarga de Anthropic es del proveedor, no de la configuración' ),
	array( 'VOZ', 422, '{"detail":[{"loc":["body","text"],"msg":"field required","type":"value_error.missing"}]}', 'UHP-VOZ-06', 'Un 422 de validación de ElevenLabs es un parámetro rechazado' ),
	array( 'LLM', 418, 'no es json', 'UHP-LLM-99', 'Lo que no se reconoce cae en el 99, que ya es un aviso: falta una fila' ),
);
foreach ( $casos as $c ) {
	$obtenido = UHP_Codigos::de_proveedor( $c[0], $c[1], $c[2] );
	comprobar( $c[3] === $obtenido, $c[4] . ' (' . $obtenido . ')' );
}
comprobar( 'UHP-LLM-02' === UHP_Codigos::de_transporte( 'LLM', 'http_request_failed · cURL error 28: Operation timed out after 45001 milliseconds' ), 'Un tiempo agotado no es lo mismo que no conectar' );
comprobar( 'UHP-VOZ-01' === UHP_Codigos::de_transporte( 'VOZ', 'http_request_failed · cURL error 6: Could not resolve host' ), 'Y no resolver el nombre es no conectar' );

/* LAS DOS RUTAS DE VERDAD, con sus puertas, su cupo y sus códigos. */
$registro_php = tempnam( sys_get_temp_dir(), 'uhp-reg' );
$registro_ant = ini_set( 'error_log', $registro_php );

$base16 = UHP_Voz::por_defecto();
$base16['activo']      = true;
$base16['visibilidad'] = 'todos';
$base16['voz_id']      = '21m00Tcm4TlvDq8ikWAM';
$base16['proveedor']   = 'openai';
$base16['cuota_dia']   = 50;
update_option( 'uhp_voz', $base16 );
update_option( 'uhp_llm_clave', 'sk-que-no-debe-salir-en-ninguna-parte' );
update_option( 'uhp_voz_clave', 'sk-el-que-no-debe-salir-tampoco' );
foreach ( array_keys( $GLOBALS['uhp_test_almacen'] ) as $k ) {
	if ( 0 === strpos( $k, 'uhp_reg_' ) || 0 === strpos( $k, 'uhp_rl_' ) || 0 === strpos( $k, 'uhp_voz_cuota' ) ) {
		unset( $GLOBALS['uhp_test_almacen'][ $k ] );
	}
}

$voz16    = new UHP_Voz();
$pregunta = new WP_REST_Request( array( 'mensajes' => array( array( 'rol' => 'usuario', 'texto' => '¿Cuál es la cifra de Pasto?' ) ) ), '/urkunina/v1/voz/chat' );

/**
 * El código UHP que trae un WP_Error, o vacío.
 *
 * @param mixed $r Respuesta de una ruta.
 * @return string
 */
function uhp_test_codigo( $r ) {
	if ( ! is_wp_error( $r ) ) {
		return '';
	}
	$d = $r->get_error_data();
	return isset( $d['uhp']['codigo'] ) ? $d['uhp']['codigo'] : '';
}

// Sin Origin ni Referer: ACC-04, y el motivo en el registro.
unset( $_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_REFERER'] );
$r = $voz16->ruta_chat( $pregunta );
comprobar( 'UHP-ACC-04' === uhp_test_codigo( $r ) && 'uhp_voz_origen' === $r->get_error_code(), 'Sin origen: UHP-ACC-04, y el código de WordPress no cambia' );
$d = is_wp_error( $r ) ? $r->get_error_data() : array();
comprobar(
	isset( $d['uhp']['ref'] ) && preg_match( '/^[0-9a-f]{6}$/', $d['uhp']['ref'] ) && '' !== $d['uhp']['frase'],
	'El error lleva el código, su frase fija y un ref de seis caracteres'
);

// Un proxy que termina TLS: el sitio es https y la petición llega por http.
$_SERVER['HTTP_ORIGIN'] = 'http://narino.gov.co';
$r = $voz16->ruta_chat( $pregunta );
comprobar( 'UHP-ACC-04' === uhp_test_codigo( $r ), 'Con otro esquema, también ACC-04' );
$linea = (string) file_get_contents( $registro_php );
comprobar(
	false !== strpos( $linea, 'motivo=sin-cabecera' ),
	'Y el registro dice cuál de las causas fue (aquí, sin cabecera: el esquema cae en la misma ventana de diez minutos)'
);

$_SERVER['HTTP_ORIGIN'] = 'https://narino.gov.co';

// Saldo agotado en OpenAI, que llega como 429.
$antes = UHP_Voz::cuota_usada();
uhp_test_http(
	array(
		array(
			'response' => array( 'code' => 429 ),
			'body'     => '{"error":{"message":"You exceeded your current quota, please check your plan and billing details.","type":"insufficient_quota","code":"insufficient_quota"}}',
		),
	)
);
$r = $voz16->ruta_chat( $pregunta );
comprobar( 'UHP-LLM-07' === uhp_test_codigo( $r ) && 'uhp_voz_proveedor' === $r->get_error_code(), 'Sin saldo en el proveedor: UHP-LLM-07 hacia el navegador' );
comprobar( $antes === UHP_Voz::cuota_usada(), 'Y la plaza del cupo se devuelve: un fallo del proveedor no gasta una consulta' );

$plano = wp_json_encode( is_wp_error( $r ) ? array( $r->get_error_code(), $r->get_error_message(), $r->get_error_data() ) : array() );
comprobar(
	false === strpos( $plano, 'sk-que-no' ) && false === stripos( $plano, 'openai' ) && false === strpos( $plano, 'insufficient_quota' ) && false === strpos( $plano, 'billing' ),
	'Al navegador NO viaja ni la clave, ni el proveedor, ni lo que dijo el proveedor'
);
$ref_1 = $d = is_wp_error( $r ) ? $r->get_error_data()['uhp']['ref'] : '';

$log = (string) file_get_contents( $registro_php );
comprobar(
	false !== strpos( $log, '[URKUNINA 5000] UHP-LLM-07 · ' ) && false !== strpos( $log, 'ref ' . $ref_1 ) && false !== strpos( $log, 'proveedor=openai' ),
	'En el registro sí: el código, el mismo ref y con qué proveedor se hablaba'
);
comprobar( false === strpos( $log, 'sk-que-no' ) && false === strpos( $log, 'sk-el-que' ), 'Y ninguna clave, ni en el registro' );
comprobar( false === strpos( $log, 'Pasto' ), 'Ni la pregunta del visitante' );

// La segunda vez, dentro del minuto: misma línea, mismo ref.
uhp_test_http( array( array( 'response' => array( 'code' => 429 ), 'body' => '{"error":{"type":"insufficient_quota","code":"insufficient_quota"}}' ) ) );
$r     = $voz16->ruta_chat( $pregunta );
$ref_2 = is_wp_error( $r ) ? $r->get_error_data()['uhp']['ref'] : '';
comprobar(
	$ref_1 === $ref_2 && 1 === substr_count( (string) file_get_contents( $registro_php ), 'UHP-LLM-07' ),
	'Un fallo repetido no inunda el registro: una línea por código y ventana, y el ref sigue llevando a ella'
);

// Un 200 vacío ya no es un éxito.
uhp_test_http( array( array( 'response' => array( 'code' => 200 ), 'body' => '{"status":"completed","output":[]}' ) ) );
$antes = UHP_Voz::cuota_usada();
$r     = $voz16->ruta_chat( $pregunta );
comprobar( 'UHP-LLM-12' === uhp_test_codigo( $r ), 'Un 200 sin texto ni herramientas es UHP-LLM-12 y no una respuesta vacía' );
comprobar( $antes === UHP_Voz::cuota_usada(), 'Y tampoco gasta cupo' );

// Una respuesta cortada en el tope: llega, pero con su aviso.
uhp_test_http(
	array(
		array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode(
				array(
					'status'             => 'incomplete',
					'incomplete_details' => array( 'reason' => 'max_output_tokens' ),
					'output'             => array( array( 'type' => 'message', 'content' => array( array( 'type' => 'output_text', 'text' => 'Pasto tiene' ) ) ) ),
				)
			),
		),
	)
);
$r = $voz16->ruta_chat( $pregunta );
$d = ( $r instanceof WP_REST_Response ) ? $r->get_data() : array();
comprobar(
	isset( $d['avisos'][0]['codigo'] ) && 'UHP-LLM-13' === $d['avisos'][0]['codigo'] && 'Pasto tiene' === $d['texto'],
	'Una respuesta cortada llega, pero con el aviso UHP-LLM-13'
);

// Las cabeceras X-UHP-*.
$con_error = new WP_REST_Response( array( 'code' => 'uhp_voz_proveedor', 'data' => array( 'status' => 502, 'uhp' => array( 'codigo' => 'UHP-LLM-07', 'ref' => 'abc123' ) ) ), 502 );
$h = UHP_Codigos::cabeceras( $con_error, null, new WP_REST_Request( array(), '/urkunina/v1/voz/chat' ) )->get_headers();
comprobar(
	isset( $h['X-UHP-Codigo'], $h['X-UHP-Ref'], $h['X-UHP-Version'] ) && 'UHP-LLM-07' === $h['X-UHP-Codigo'] && 'abc123' === $h['X-UHP-Ref'],
	'Las rutas del asistente repiten el código y el ref en cabeceras, con la versión del servidor'
);
$h = UHP_Codigos::cabeceras( new WP_REST_Response( array(), 200 ), null, new WP_REST_Request( array(), '/urkunina/v1/municipios' ) )->get_headers();
comprobar( ! $h, 'Y las demás rutas del plugin no se tocan' );

// Visibilidad «editores» y petición anónima.
$base16['visibilidad'] = 'editores';
update_option( 'uhp_voz', $base16 );
$r = $voz16->ruta_chat( $pregunta );
comprobar( 'UHP-ACC-02' === uhp_test_codigo( $r ), 'En ensayo y sin sesión: UHP-ACC-02, distinto de «apagado»' );
$base16['visibilidad'] = 'todos';

// Apagado.
$base16['activo'] = false;
update_option( 'uhp_voz', $base16 );
$r = $voz16->ruta_chat( $pregunta );
comprobar( 'UHP-ACC-01' === uhp_test_codigo( $r ), 'Apagado: UHP-ACC-01' );
$base16['activo'] = true;
update_option( 'uhp_voz', $base16 );

// Sin conversación.
$r = $voz16->ruta_chat( new WP_REST_Request( array( 'mensajes' => array() ), '/urkunina/v1/voz/chat' ) );
comprobar( 'UHP-CNV-05' === uhp_test_codigo( $r ) && 'uhp_voz_vacio' === $r->get_error_code(), 'Sin conversación: UHP-CNV-05' );

/* LA SÍNTESIS. */
$frase16 = new WP_REST_Request( array( 'texto' => 'Pasto tiene la cifra más alta de su subregión.' ), '/urkunina/v1/voz/hablar' );
uhp_test_http( array( array( 'response' => array( 'code' => 200 ), 'headers' => array( 'content-type' => 'audio/mpeg' ), 'body' => "ID3\x03\x00audio" ) ) );
$r = $voz16->ruta_hablar( $frase16 );
$d = ( $r instanceof WP_REST_Response ) ? $r->get_data() : array();
comprobar( isset( $d['audio'] ) && "ID3\x03\x00audio" === base64_decode( $d['audio'] ), 'La síntesis devuelve el audio' );

uhp_test_http( array( array( 'response' => array( 'code' => 200 ), 'headers' => array( 'content-type' => 'text/html; charset=UTF-8' ), 'body' => '<html>proxy</html>' ) ) );
$r = $voz16->ruta_hablar( $frase16 );
comprobar( 'UHP-VOZ-10' === uhp_test_codigo( $r ), 'Un 200 que no es audio ya no llega mudo al navegador: UHP-VOZ-10' );

uhp_test_http( array( array( 'response' => array( 'code' => 401 ), 'body' => '{"detail":{"status":"quota_exceeded","message":"This request exceeds your quota of 10000."}}' ) ) );
$r = $voz16->ruta_hablar( $frase16 );
comprobar( 'UHP-VOZ-07' === uhp_test_codigo( $r ), 'La cuota de ElevenLabs agotada es UHP-VOZ-07 aunque llegue como 401' );

// LA SÍNTESIS TIENE CUPO. Hasta la 2.0 no lo tenía.
$base16['cuota_dia'] = 1;
update_option( 'uhp_voz', $base16 );
foreach ( array_keys( $GLOBALS['uhp_test_almacen'] ) as $k ) {
	if ( 0 === strpos( $k, 'uhp_voz_cuota' ) ) {
		unset( $GLOBALS['uhp_test_almacen'][ $k ] );
	}
}
uhp_test_http( array( array( 'response' => array( 'code' => 200 ), 'headers' => array( 'content-type' => 'audio/mpeg' ), 'body' => 'ID3' ) ) );
$r1 = $voz16->ruta_hablar( $frase16 );
$r2 = $voz16->ruta_hablar( $frase16 );
comprobar(
	$r1 instanceof WP_REST_Response && 'UHP-ACC-07' === uhp_test_codigo( $r2 ) && 'uhp_voz_cuota' === $r2->get_error_code(),
	'La lectura en voz alta tiene su propio cupo diario: agotado, UHP-ACC-07'
);
comprobar( 0 === UHP_Voz::cuota_usada(), 'Y es un contador aparte: leer no gasta consultas' );

// Sin voz configurada.
$base16['voz_id'] = '';
update_option( 'uhp_voz', $base16 );
$r = $voz16->ruta_hablar( $frase16 );
comprobar( 'UHP-CFG-04' === uhp_test_codigo( $r ), 'Pedir audio a un servidor sin voz: UHP-CFG-04' );

// El panel pinta el mismo código.
update_option( 'uhp_voz', array_merge( $base16, array( 'voz_id' => '21m00Tcm4TlvDq8ikWAM', 'proveedor' => 'gemini' ) ) );
uhp_test_http(
	array(
		array( 'response' => array( 'code' => 400 ), 'body' => '{"error":{"code":400,"message":"API key not valid. Please pass a valid API key.","status":"INVALID_ARGUMENT","details":[{"reason":"API_KEY_INVALID"}]}}' ),
		$ok_voces,
	)
);
$inf = UHP_Voz::verificar();
comprobar(
	'UHP-LLM-03' === $inf['llm']['codigo'] && false !== strpos( $inf['llm']['mensaje'], 'la clave no es válida' ),
	'La verificación de una clave de Gemini inválida dice «clave» con su código, no «petición mal formada»'
);
comprobar( '' === $inf['voz']['codigo'], 'Y lo que está bien no lleva código' );

unset( $_SERVER['HTTP_ORIGIN'] );
update_option( 'uhp_voz', $base16 );

echo "\n17. Asistente: lo que exige la documentación de cada proveedor\n";

/* Cada comprobación de esta sección sale de la documentación oficial
   vigente de su proveedor, contrastada para la 2.1. */

$ok_openai = array(
	'response' => array( 'code' => 200 ),
	'body'     => wp_json_encode( array( 'status' => 'completed', 'output' => array( array( 'type' => 'message', 'content' => array( array( 'type' => 'output_text', 'text' => 'Listo.' ) ) ) ) ) ),
);
$ok_claude = array(
	'response' => array( 'code' => 200 ),
	'body'     => wp_json_encode( array( 'content' => array( array( 'type' => 'text', 'text' => 'Listo.' ) ), 'stop_reason' => 'end_turn' ) ),
);
$ok_gemini = array(
	'response' => array( 'code' => 200 ),
	'body'     => wp_json_encode( array( 'candidates' => array( array( 'content' => array( 'parts' => array( array( 'text' => 'Listo.' ) ) ), 'finishReason' => 'STOP' ) ) ) ),
);

/**
 * Cuerpo JSON de la última petición que salió.
 *
 * @return array
 */
function uhp_test_cuerpo_ultimo() {
	$p = uhp_test_http_peticiones();
	$u = end( $p );
	return $u ? json_decode( $u['args']['body'], true ) : array();
}

/**
 * Cabeceras de la última petición que salió.
 *
 * @return array
 */
function uhp_test_cabeceras_ultimo() {
	$p = uhp_test_http_peticiones();
	$u = end( $p );
	return $u ? $u['args']['headers'] : array();
}

$neutro = array(
	'clave'        => 'k',
	'sistema'      => 'Responde.',
	'mensajes'     => array( array( 'rol' => 'usuario', 'texto' => 'Hola' ) ),
	'herramientas' => array(),
	'esfuerzo'     => 'low',
);

// El tope cuenta el razonamiento: 2.000 se quedaba corto.
uhp_test_http( array( $ok_claude ) );
UHP_LLM::conversar( array_merge( $neutro, array( 'proveedor' => 'claude', 'modelo' => 'claude-opus-5-5' ) ) );
$cu = uhp_test_cuerpo_ultimo();
$ca = uhp_test_cabeceras_ultimo();
comprobar( $cu['max_tokens'] >= 6000, 'Claude: el tope deja sitio para pensar y responder (' . $cu['max_tokens'] . ')' );
comprobar( isset( $cu['output_config']['effort'] ) && 'low' === $cu['output_config']['effort'], 'Claude: el esfuerzo va a un modelo que lo admite' );
comprobar(
	'default' === ( isset( $cu['fallbacks'] ) ? $cu['fallbacks'] : '' ) && 'server-side-fallback-2026-07-01' === ( isset( $ca['anthropic-beta'] ) ? $ca['anthropic-beta'] : '' ),
	'Claude: si un clasificador declina, reintenta en el servidor con su cabecera, que no es la de la forma de lista'
);

uhp_test_http( array( $ok_claude ) );
UHP_LLM::conversar( array_merge( $neutro, array( 'proveedor' => 'claude', 'modelo' => 'claude-haiku-4-5' ) ) );
$cu = uhp_test_cuerpo_ultimo();
$ca = uhp_test_cabeceras_ultimo();
comprobar( ! isset( $cu['output_config'] ), 'Claude: a un modelo sin esfuerzo no se le manda (daría 400)' );
comprobar( ! isset( $cu['fallbacks'] ) && ! isset( $ca['anthropic-beta'] ), 'Ni el reintento, que ese modelo no tiene' );

uhp_test_http( array( $ok_openai ) );
UHP_LLM::conversar( array_merge( $neutro, array( 'proveedor' => 'openai', 'modelo' => 'gpt-6-luna' ) ) );
$cu = uhp_test_cuerpo_ultimo();
comprobar( isset( $cu['reasoning']['effort'] ) && 'low' === $cu['reasoning']['effort'], 'OpenAI: el esfuerzo llega también, en vez de pensar en «medium» por omisión' );
comprobar( $cu['max_output_tokens'] >= 6000, 'OpenAI: y su tope deja sitio al razonamiento' );

uhp_test_http( array( $ok_openai ) );
UHP_LLM::conversar( array_merge( $neutro, array( 'proveedor' => 'openai', 'modelo' => 'gpt-4.1' ) ) );
comprobar( ! isset( uhp_test_cuerpo_ultimo()['reasoning'] ), 'OpenAI: a un modelo que no razona no se le manda (daría 400)' );

uhp_test_http( array( $ok_gemini ) );
UHP_LLM::conversar( array_merge( $neutro, array( 'proveedor' => 'gemini', 'modelo' => 'gemini-3.8-flash' ) ) );
$cu = uhp_test_cuerpo_ultimo();
comprobar(
	isset( $cu['generationConfig']['thinkingConfig']['thinkingLevel'] ) && 'LOW' === $cu['generationConfig']['thinkingConfig']['thinkingLevel'],
	'Gemini 3: el nivel de pensamiento va en generationConfig'
);
uhp_test_http( array( $ok_gemini ) );
UHP_LLM::conversar( array_merge( $neutro, array( 'proveedor' => 'gemini', 'modelo' => 'gemini-2.5-flash' ) ) );
comprobar( ! isset( uhp_test_cuerpo_ultimo()['generationConfig']['thinkingConfig'] ), 'Gemini 2: no, que ahí el campo da error' );

// Cortada con herramientas a medias: no se ejecutan.
uhp_test_http(
	array(
		array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode(
				array(
					'content'     => array( array( 'type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'uhp_seleccionar_municipio', 'input' => array( 'municipio' => 'Pupi' ) ) ),
					'stop_reason' => 'max_tokens',
				)
			),
		),
	)
);
$r = UHP_LLM::conversar( array_merge( $neutro, array( 'proveedor' => 'claude', 'modelo' => 'claude-opus-5-5' ) ) );
comprobar( empty( $r['ok'] ) && 'UHP-LLM-13' === $r['clase'], 'Una respuesta cortada con una herramienta a medias no se ejecuta: unos argumentos truncados moverían el tablero a ciegas' );

// La negativa de OpenAI con 200.
uhp_test_http(
	array(
		array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( array( 'status' => 'completed', 'output' => array( array( 'type' => 'message', 'content' => array( array( 'type' => 'refusal', 'refusal' => 'No puedo.' ) ) ) ) ) ),
		),
	)
);
$r = UHP_LLM::conversar( array_merge( $neutro, array( 'proveedor' => 'openai', 'modelo' => 'gpt-6-luna' ) ) );
comprobar( empty( $r['ok'] ) && 'UHP-LLM-11' === $r['clase'], 'OpenAI: una negativa con 200 es UHP-LLM-11 y no una respuesta vacía' );

// Gemini: bloqueo de la pregunta.
uhp_test_http( array( array( 'response' => array( 'code' => 200 ), 'body' => '{"promptFeedback":{"blockReason":"SAFETY"}}' ) ) );
$r = UHP_LLM::conversar( array_merge( $neutro, array( 'proveedor' => 'gemini', 'modelo' => 'gemini-3.8-flash' ) ) );
comprobar( empty( $r['ok'] ) && 'UHP-LLM-11' === $r['clase'], 'Gemini: un bloqueo de la pregunta es UHP-LLM-11' );

/* CADA LLAMADA CON SU RESULTADO. Al llegar al tope de vueltas, el
   navegador de la 2.0 guardaba la llamada sin resultado, y la pregunta
   siguiente daba 400 en Claude. */
update_option( 'uhp_voz', array_merge( $base16, array( 'proveedor' => 'claude', 'modelo' => 'claude-opus-5-5', 'cuota_dia' => 50, 'activo' => true, 'visibilidad' => 'todos' ) ) );
$_SERVER['HTTP_ORIGIN'] = 'https://narino.gov.co';
foreach ( array_keys( $GLOBALS['uhp_test_almacen'] ) as $k ) {
	if ( 0 === strpos( $k, 'uhp_voz_cuota' ) || 0 === strpos( $k, 'uhp_rl_' ) ) {
		unset( $GLOBALS['uhp_test_almacen'][ $k ] );
	}
}
$huerfana = new WP_REST_Request(
	array(
		'mensajes' => array(
			array( 'rol' => 'usuario', 'texto' => 'Muéstrame Pasto' ),
			array( 'rol' => 'asistente', 'texto' => '', 'llamadas' => array( array( 'id' => 'toolu_a', 'nombre' => 'uhp_seleccionar_municipio', 'args' => array( 'municipio' => 'Pasto' ) ) ) ),
			array( 'rol' => 'usuario', 'texto' => '¿Y Tumaco?' ),
		),
	),
	'/urkunina/v1/voz/chat'
);
uhp_test_http( array( $ok_claude ) );
$r  = $voz16->ruta_chat( $huerfana );
$cu = uhp_test_cuerpo_ultimo();
$tras = isset( $cu['messages'][2]['content'][0] ) ? $cu['messages'][2]['content'][0] : array();
comprobar(
	$r instanceof WP_REST_Response && 'tool_result' === ( isset( $tras['type'] ) ? $tras['type'] : '' ) && 'toolu_a' === $tras['tool_use_id'],
	'Una llamada sin resultado sale con uno que dice la verdad («no se ejecutó»), justo detrás de ella'
);
comprobar( 'user' === $cu['messages'][3]['role'] && '¿Y Tumaco?' === $cu['messages'][3]['content'][0]['text'], 'Y la pregunta siguiente va después, como exige el proveedor' );

$suelto = new WP_REST_Request(
	array(
		'mensajes' => array(
			array( 'rol' => 'usuario', 'texto' => 'Hola' ),
			array( 'rol' => 'herramienta', 'resultados' => array( array( 'id' => 'x1', 'nombre' => 'uhp_leer_estado_tablero', 'salida' => 'algo' ) ) ),
			array( 'rol' => 'usuario', 'texto' => '¿Qué hay?' ),
		),
	),
	'/urkunina/v1/voz/chat'
);
uhp_test_http( array( $ok_claude ) );
$voz16->ruta_chat( $suelto );
$plano = wp_json_encode( uhp_test_cuerpo_ultimo()['messages'] );
comprobar( false === strpos( $plano, 'tool_result' ), 'Un resultado sin su llamada se quita en vez de mandarse' );

// La firma de Gemini vuelve EXACTA, por larga que sea.
update_option( 'uhp_voz', array_merge( $base16, array( 'proveedor' => 'gemini', 'modelo' => 'gemini-3.8-flash', 'cuota_dia' => 50, 'activo' => true, 'visibilidad' => 'todos' ) ) );
$firma = str_repeat( 'QUJD', 1500 ); // 6.000 caracteres: más que el viejo recorte de 4.000.
$con_firma = new WP_REST_Request(
	array(
		'mensajes' => array(
			array( 'rol' => 'usuario', 'texto' => 'Muéstrame Pasto' ),
			array( 'rol' => 'asistente', 'texto' => '', 'llamadas' => array( array( 'id' => 'g1', 'nombre' => 'uhp_seleccionar_municipio', 'args' => array( 'municipio' => 'Pasto' ), 'firma' => $firma ) ) ),
			array( 'rol' => 'herramienta', 'resultados' => array( array( 'id' => 'g1', 'nombre' => 'uhp_seleccionar_municipio', 'salida' => 'Pasto seleccionado.' ) ) ),
		),
	),
	'/urkunina/v1/voz/chat'
);
uhp_test_http( array( $ok_gemini ) );
$voz16->ruta_chat( $con_firma );
$cu = uhp_test_cuerpo_ultimo();
comprobar(
	isset( $cu['contents'][1]['parts'][0]['thoughtSignature'] ) && $firma === $cu['contents'][1]['parts'][0]['thoughtSignature'],
	'La firma de razonamiento de Gemini vuelve exacta: recortada, Gemini 3 contesta 400'
);

// Las voces, por páginas.
update_option( 'uhp_voz', array_merge( $base16, array( 'voz_id' => 'VozDeLaSegundaPagina1', 'activo' => true ) ) );
uhp_test_http(
	array(
		array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'voices' => array( array( 'voice_id' => '21m00Tcm4TlvDq8ikWAM', 'name' => 'Lucía' ) ), 'has_more' => true, 'next_page_token' => 'pag2' ) ) ),
		array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'voices' => array( array( 'voice_id' => 'VozDeLaSegundaPagina1', 'name' => 'Rosa' ) ), 'has_more' => false ) ) ),
	)
);
$voces = UHP_Voz::voces( true );
$pet   = uhp_test_http_peticiones();
comprobar( isset( $voces['VozDeLaSegundaPagina1'] ) && 2 === count( $pet ), 'Una voz de la segunda página del listado también existe: antes se pedía solo la primera' );
comprobar( false !== strpos( $pet[1]['url'], 'next_page_token=pag2' ) && false !== strpos( $pet[0]['url'], 'include_total_count=false' ), 'Con el token de la página siguiente y sin el conteo total, que es lento' );

unset( $_SERVER['HTTP_ORIGIN'] );
update_option( 'uhp_voz', $base16 );

// El registro de PHP de las secciones 16 y 17 iba a un archivo temporal.
ini_set( 'error_log', false === $registro_ant ? '' : $registro_ant );
@unlink( $registro_php ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

echo "\n18. Módulo Asistente AI: ajustes, bienvenida y lo que sabe del proyecto\n";

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return true;
	}
}
require_once UHP_DIR . 'includes/admin/class-uhp-admin-datos.php';
require_once UHP_DIR . 'includes/admin/class-uhp-admin.php';
$admin18 = ( new ReflectionClass( 'GobernacionNarino\\Urkunina\\UHP_Admin' ) )->newInstanceWithoutConstructor();

/**
 * Lo que guarda el formulario del módulo a partir de lo que se envía.
 *
 * @param array $previo  Configuración guardada antes.
 * @param array $entrada Lo que llega del formulario.
 * @return array
 */
function uhp_test_guardar( $previo, $entrada ) {
	global $admin18;
	update_option( 'uhp_voz', $previo );
	// El formulario manda, oculta, la visibilidad que había al pintarlo.
	if ( isset( $entrada['visibilidad_previa'] ) ) {
		$entrada['visibilidad_previa'] = $previo['visibilidad'];
	}
	return $admin18->sanear_voz( $entrada );
}

$previo18 = array_merge( UHP_Voz::por_defecto(), array( 'activo' => true, 'visibilidad' => 'todos' ) );

/* EL MICRÓFONO ES UNA CASILLA. En la 2.0 el panel mostraba un selector
   de tres opciones mientras el saneado convertía cualquier valor en
   «ambos»: elegir «solo texto» no apagaba el micrófono. */
$o = uhp_test_guardar( $previo18, array( 'visibilidad_previa' => '1', 'visible' => '1', 'entrada' => 'ambos' ) );
comprobar( 'ambos' === $o['entrada'], 'Con la casilla marcada se ofrece el micrófono' );
$o = uhp_test_guardar( $previo18, array( 'visibilidad_previa' => '1', 'visible' => '1' ) );
comprobar( 'texto' === $o['entrada'], 'Y desmarcada, de verdad se apaga (en la 2.0 seguía encendido)' );

// «Visible en el sitio» sin perder el modo «oculto» de quien lo eligió.
$o = uhp_test_guardar( $previo18, array( 'visibilidad_previa' => '1' ) );
comprobar( 'editores' === $o['visibilidad'], 'Desmarcar «Visible en el sitio» lo deja en ensayo, para editores' );
$o = uhp_test_guardar( array_merge( $previo18, array( 'visibilidad' => 'oculto' ) ), array( 'visibilidad_previa' => '1' ) );
comprobar( 'oculto' === $o['visibilidad'], 'Y si estaba «oculto» (botón de la plantilla), guardar no lo cambia' );
$o = uhp_test_guardar( array_merge( $previo18, array( 'visibilidad' => 'oculto' ) ), array( 'visibilidad_previa' => '1', 'visible' => '1' ) );
comprobar( 'todos' === $o['visibilidad'], 'Marcarlo lo publica para todos' );

// La bienvenida.
$o = uhp_test_guardar( $previo18, array( 'visibilidad_previa' => '1', 'visible' => '1', 'anfitrion' => '  <b>Tu anfitriona</b>  ', 'saludo' => "¡Hola!\nBienvenida." ) );
comprobar( 'Tu anfitriona' === $o['anfitrion'], 'El nombre del anfitrión se limpia como texto' );
update_option( 'uhp_voz', array_merge( $previo18, array( 'anfitrion' => '', 'saludo' => '' ) ) );
comprobar( 'Tu anfitrión' === UHP_Voz::anfitrion(), 'Vacío, «Tu anfitrión»: sin tilde, porque es posesivo' );
comprobar( UHP_Voz::saludo_por_omision() === UHP_Voz::saludo(), 'Y el saludo vacío es el del ejemplo' );
comprobar( UHP_Voz::anfitrion() === UHP_Voz::textos()['asistente'], 'El nombre llega al navegador como el rótulo de cada globo' );

// Las preguntas sugeridas.
$o = uhp_test_guardar( $previo18, array( 'visibilidad_previa' => '1', 'visible' => '1', 'sugerencias' => "Uno\n\n  Dos  \nUno\n<script>x</script>Tres\n" . str_repeat( 'x', 140 ) ) );
$sug = explode( "\n", $o['sugerencias'] );
comprobar( array( 'Uno', 'Dos' ) === array_slice( $sug, 0, 2 ) && 4 === count( $sug ), 'Las sugerencias se limpian: sin vacías, sin repetidas' );
comprobar( false === strpos( $o['sugerencias'], '<script' ), 'Y sin marcado' );
comprobar( mb_strlen( $sug[3] ) <= 90, 'Y ninguna pasa de 90 caracteres' );
$muchas = implode( "\n", array_map( function ( $i ) { return 'Pregunta ' . $i; }, range( 1, 12 ) ) );
comprobar( 8 === count( UHP_Voz::sanear_sugerencias( $muchas ) ), 'Como mucho ocho' );
update_option( 'uhp_voz', array_merge( $previo18, array( 'sugerencias' => '' ) ) );
$fabrica = UHP_Voz::sugerencias();
comprobar( $fabrica === UHP_Voz::sugerencias_por_omision() && count( $fabrica ) >= 6, 'Vacío, las del ejemplo' );
$largas = array_filter( $fabrica, function ( $t ) { return mb_strlen( $t ) > 32; } );
comprobar( ! $largas, 'Las del ejemplo son cortas: una pastilla no parte líneas y a 400 px se ve al menos una entera' );

// La espera tras la última palabra.
comprobar( 2.0 === UHP_Voz::sanear_silencio( '' ), 'Sin valor, dos segundos de silencio dan por terminada la pregunta' );
comprobar( 2.5 === UHP_Voz::sanear_silencio( '2,5' ), 'Admite la coma decimal de Colombia' );
comprobar( 1.0 === UHP_Voz::sanear_silencio( '0.2' ) && 6.0 === UHP_Voz::sanear_silencio( 30 ), 'Y se queda entre uno y seis segundos' );

/* LO QUE SABE DEL PROYECTO SIN CONSULTAR. Cada dato atado a su JSON: si
   alguien edita un JSON desde el módulo Datos, esto lo dice. */
$prompt = UHP_Voz::prompt_sistema();
$ficha  = implode( "\n", UHP_Voz::ficha_proyecto() );
comprobar( false !== strpos( $prompt, $ficha ), 'La ficha del proyecto va dentro del prompt' );
comprobar( strpos( $prompt, $ficha ) < strpos( $prompt, 'LO QUE NO HACES' ), 'Antes de «LO QUE NO HACES», que sigue cerrando el prompt' );

$nombre = UHP_Datos::valor( 'proyecto', 'identificacion.origen_del_nombre' );
comprobar(
	is_array( $nombre ) && false !== strpos( $ficha, $nombre['vocablo'] ) && false !== strpos( $ficha, $nombre['significado'] ) && false !== stripos( $ficha, $nombre['referencia'] ),
	'El origen del nombre es el del informe: «' . ( is_array( $nombre ) ? $nombre['significado'] : '?' ) . '», por el volcán Galeras'
);
comprobar( false !== strpos( $ficha, (string) UHP_Datos::valor( 'proyecto', 'identificacion.bpin' ) ), 'El BPIN coincide con el del proyecto' );
$total = UHP_Datos::valor( 'proyecto', 'financiacion.presupuesto_total' );
$fuentes = UHP_Datos::valor( 'proyecto', 'financiacion.fuentes' );
comprobar(
	false !== strpos( $ficha, number_format( (float) $total, 0, ',', '.' ) )
		&& false !== strpos( $ficha, number_format( (float) $fuentes[0]['valor'], 0, ',', '.' ) )
		&& false !== strpos( $ficha, number_format( (float) $fuentes[1]['valor'], 0, ',', '.' ) ),
	'El costo y sus dos fuentes coinciden con 01_proyecto.json'
);
comprobar(
	'2017-06-09' === UHP_Datos::valor( 'proyecto', 'aprobacion.fecha_acuerdo' ) && false !== strpos( $ficha, 'Acuerdo número 57 del 9 de junio de 2017' ),
	'El acuerdo de aprobación, con su fecha'
);
$etapas = UHP_Datos::valor( 'epidemiologia', 'cascada_de_correa.etapas' );
$faltan = array();
foreach ( (array) $etapas as $e ) {
	if ( false === strpos( mb_strtolower( $ficha ), mb_strtolower( $e['etapa'] ) ) ) {
		$faltan[] = $e['etapa'];
	}
}
comprobar( 5 === count( (array) $etapas ) && ! $faltan && false !== strpos( $ficha, 'Pelayo Correa' ), 'Las cinco etapas de la cascada de Correa, con su autor' );
$inc = UHP_Datos::valor( 'criterios', 'inclusion' );
$exc = UHP_Datos::valor( 'criterios', 'exclusion' );
comprobar(
	4 === count( (array) $inc ) && 6 === count( (array) $exc )
		&& false !== strpos( $ficha, 'de 30 a 70 años' ) && false !== strpos( $ficha, 'diez años' )
		&& false !== strpos( $ficha, 'consentimiento informado' ) && false !== strpos( $ficha, 'bismuto' ) && false !== strpos( $ficha, 'várices esofágicas' ),
	'Quién podía participar: los cuatro criterios de inclusión y los seis de exclusión'
);
comprobar( false !== strpos( $ficha, 'los documentos no explican más' ), 'Y dice lo que no se sabe: ningún documento explica el «5000»' );

/* NI UNA CIFRA DE RESULTADOS. El bloque es descriptivo: las prevalencias,
   los casos y las incidencias siguen saliendo solo de las herramientas.
   El 35,1 era el ejemplo de formato del prompt y es la prevalencia real
   de lesión precursora: el modelo podía soltarla sin consultar. */
$prohibidas = array( '67,4', '35,1', '3.370', '1.755', '150 casos', '63,4', '90 %', '25.000' );
$halladas   = array();
foreach ( $prohibidas as $cifra ) {
	if ( false !== strpos( $prompt, $cifra ) ) {
		$halladas[] = $cifra;
	}
}
comprobar( ! $halladas, 'El prompt no trae ninguna cifra de resultados' . ( $halladas ? ': ' . implode( ', ', $halladas ) : '' ) );
comprobar( false === strpos( $prompt, '{' ) && false === strpos( $prompt, '}' ), 'Y sigue sin una sola llave: nada se inyecta' );
comprobar( false !== strpos( $prompt, 'de noviembre de 2018 a abril de 2023' ), 'Las fechas son las del proyecto: el trabajo de campo empezó en 2019, no en 2018' );

update_option( 'uhp_voz', $base16 );

echo "\n19. Los códigos de error: un solo catálogo por lado, documentado entero\n";

$js19 = (string) file_get_contents( UHP_DIR . 'assets/js/uhp-voz.js' );
preg_match_all( "/'(UHP-[A-Z]{3}-[0-9]{2})': \\['(error|warn|info)', '([^']*)'\\]/", $js19, $m19, PREG_SET_ORDER );
$cat_js = array();
foreach ( $m19 as $f ) {
	$cat_js[ $f[1] ] = array( $f[2], $f[3] );
}
$cat_php = UHP_Codigos::CATALOGO;
comprobar( count( $cat_js ) > 40, 'El navegador tiene su catálogo (' . count( $cat_js ) . ' códigos)' );
comprobar( ! array_intersect_key( $cat_js, $cat_php ), 'Ningún código está en los dos catálogos: cada fallo lo detecta un lado' );

$malos = array();
foreach ( $cat_js as $cod => $fila ) {
	$frase = $fila[1];
	if ( mb_strlen( $frase ) > 70 || false !== strpos( $frase, '%' ) || mb_strtolower( mb_substr( $frase, 0, 1 ) ) !== mb_substr( $frase, 0, 1 )
		|| preg_match( '/claude|anthropic|openai|gpt|gemini|google|elevenlabs/i', $frase ) ) {
		$malos[] = $cod;
	}
}
comprobar( ! $malos, 'Las frases del navegador siguen las mismas reglas que las del servidor' . ( $malos ? ': ' . implode( ', ', $malos ) : '' ) );

// Todo código que aparece en el código existe en un catálogo.
$todos19 = array_merge( array_keys( $cat_js ), array_keys( $cat_php ) );
$usados  = array();
foreach ( array( 'assets/js/uhp-voz.js', 'includes/class-uhp-voz.php', 'includes/class-uhp-llm.php', 'includes/class-uhp-codigos.php', 'includes/admin/class-uhp-admin.php' ) as $archivo ) {
	preg_match_all( '/UHP-[A-Z]{3}-[0-9]{2}/', (string) file_get_contents( UHP_DIR . $archivo ), $mm );
	$usados = array_merge( $usados, $mm[0] );
}
$huerfanos = array_diff( array_unique( $usados ), $todos19 );
comprobar( ! $huerfanos, 'Todo código que el plugin emite está catalogado' . ( $huerfanos ? ': ' . implode( ', ', $huerfanos ) : '' ) );

// Y la documentación los tiene todos, y solo esos.
$doc19 = is_readable( UHP_DIR . 'docs/voz/codigos.md' ) ? (string) file_get_contents( UHP_DIR . 'docs/voz/codigos.md' ) : '';
preg_match_all( '/^\| `(UHP-[A-Z]{3}-[0-9]{2})`/m', $doc19, $md19 );
sort( $todos19 );
$en_doc = $md19[1];
sort( $en_doc );
comprobar( $todos19 === $en_doc, 'docs/voz/codigos.md tiene exactamente los códigos de los dos catálogos (si falla: php tests/generar-codigos.php)' );

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
