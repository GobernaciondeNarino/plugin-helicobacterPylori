<?php
/**
 * Escribe docs/voz/herramientas.json desde su única fuente.
 *
 * Las definiciones viven en UHP_Voz::definiciones_herramientas(). Este
 * archivo las vuelca para que quien configure el agente en ElevenLabs las
 * copie sin abrir código PHP. Una comprobación de la suite verifica que el
 * JSON del repositorio sigue siendo el que produce el plugin: si alguien
 * cambia un parámetro y no regenera, la prueba lo delata.
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
		'proposito'   => 'Herramientas de cliente que el agente de voz debe declarar en ElevenLabs para poder manejar el tablero.',
		'generado_por' => 'tests/generar-herramientas.php desde UHP_Voz::definiciones_herramientas()',
		'nota'        => 'Son herramientas de CLIENTE: las ejecuta el navegador del visitante, no un servidor. Marque «Wait for response» solo en las que aquí llevan "espera": true.',
	),
	'prompt' => UHP_Voz::prompt_agente(),
	'herramientas' => UHP_Voz::definiciones_herramientas(),
);

$json = json_encode( $salida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
file_put_contents( __DIR__ . '/../docs/voz/herramientas.json', $json );

printf( "docs/voz/herramientas.json — %d herramientas, %s\n", count( $salida['herramientas'] ), size_format( strlen( $json ) ) );
