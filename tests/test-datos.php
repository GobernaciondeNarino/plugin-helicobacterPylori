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
uhp_cargar_clases_de_datos();

use GobernacionNarino\Urkunina\UHP_Datos;
use GobernacionNarino\Urkunina\UHP_Municipios;
use GobernacionNarino\Urkunina\UHP_Views;
use GobernacionNarino\Urkunina\UHP_Rest;
use GobernacionNarino\Urkunina\UHP_Security;

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
	15 === count( $registro ),
	sprintf( 'El registro declara los 14 archivos JSON y la geometría (%d entradas)', count( $registro ) )
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
