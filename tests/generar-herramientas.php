<?php
/**
 * Escribe docs/voz/herramientas.json desde su única fuente.
 *
 * Las definiciones viven en UHP_Voz::definiciones_herramientas() y el
 * servidor se las manda al modelo en cada pregunta: NO hay que pegarlas
 * en ninguna parte. Este archivo las vuelca para poder leerlas sin abrir
 * código PHP —al revisar el plugin, al documentarlo o al depurar por qué
 * el asistente no encuentra algo—. Una comprobación de la suite verifica
 * que el JSON del repositorio sigue siendo el que produce el plugin: si
 * alguien cambia un parámetro y no regenera, la prueba lo delata.
 *
 * Uso:  php tests/generar-herramientas.php
 *
 * @package Urkunina5000
 */

require_once __DIR__ . '/stubs-wordpress.php';
uhp_cargar_clases_de_datos();

use GobernacionNarino\Urkunina\UHP_Voz;

$salida = array(
	'_meta' => array(
		'proyecto'    => 'URKUNINA 5000',
		'proposito'   => 'Herramientas con las que el asistente lee y mueve el tablero. El servidor se las manda al modelo de lenguaje en cada pregunta.',
		'generado_por' => 'tests/generar-herramientas.php desde UHP_Voz::definiciones_herramientas()',
		'nota'        => 'Las ejecuta el navegador del visitante sobre su propio tablero; el servidor solo transporta la llamada y su resultado. Las que llevan "espera": true devuelven un dato que el modelo necesita para seguir, y la respuesta se detiene hasta tenerlo; las demás solo mueven la pantalla.',
	),
	'prompt' => UHP_Voz::prompt_sistema( 'texto' ),
	'herramientas' => UHP_Voz::definiciones_herramientas(),
);

$json = json_encode( $salida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
file_put_contents( __DIR__ . '/../docs/voz/herramientas.json', $json );

printf( "docs/voz/herramientas.json — %d herramientas, %s\n", count( $salida['herramientas'] ), size_format( strlen( $json ) ) );
