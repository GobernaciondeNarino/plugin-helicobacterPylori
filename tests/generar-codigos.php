<?php
/**
 * Genera docs/voz/codigos.md con la tabla de TODOS los códigos de error.
 *
 *   php tests/generar-codigos.php
 *
 * Lee los dos catálogos —el del servidor (UHP_Codigos::CATALOGO) y el del
 * navegador (el objeto CODIGOS de assets/js/uhp-voz.js)— y escribe una
 * sola tabla. Así la documentación no se escribe a mano y no se queda
 * atrás: tests/test-datos.php comprueba que el archivo generado coincide
 * con lo que hay en el código.
 *
 * Lo que dice «Qué mirar» vive aquí, junto a la tabla, porque es texto
 * para quien diagnostica y no para el visitante ni para la consola.
 *
 * @package Urkunina5000
 */

require __DIR__ . '/stubs-wordpress.php';
uhp_cargar_shortcodes();

use GobernacionNarino\Urkunina\UHP_Codigos;

/**
 * El catálogo del navegador, leído del propio archivo.
 *
 * @return array<string,array{0:string,1:string}>
 */
function uhp_codigos_navegador() {
	$js = (string) file_get_contents( UHP_DIR . 'assets/js/uhp-voz.js' );
	preg_match_all( "/'(UHP-[A-Z]{3}-[0-9]{2})': \\['(error|warn|info)', '([^']*)'\\]/", $js, $m, PREG_SET_ORDER );
	$salida = array();
	foreach ( $m as $fila ) {
		$salida[ $fila[1] ] = array( $fila[2], $fila[3] );
	}
	return $salida;
}

/**
 * La tabla completa, ordenada por área y número.
 *
 * @return array<string,array{nivel:string,frase:string,lado:string}>
 */
function uhp_codigos_todos() {
	$todos = array();
	foreach ( UHP_Codigos::CATALOGO as $cod => $fila ) {
		$todos[ $cod ] = array( 'nivel' => $fila[0], 'frase' => $fila[1], 'lado' => 'servidor' );
	}
	foreach ( uhp_codigos_navegador() as $cod => $fila ) {
		$todos[ $cod ] = array( 'nivel' => $fila[0], 'frase' => $fila[1], 'lado' => 'navegador' );
	}
	uksort( $todos, 'strcmp' );
	return $todos;
}

$areas = array(
	'CFG' => array( 'Configuración del sitio y de la página', 'Asistente AI en el panel, wp-config.php, optimizadores de JavaScript y cachés' ),
	'ACC' => array( 'Las puertas de las dos rutas', 'Activación y visibilidad, URL del sitio, proxy, cupo diario' ),
	'RED' => array( 'Navegador ↔ este sitio', 'Pestaña Red del navegador, otros plugins, errores de PHP' ),
	'LLM' => array( 'Servidor ↔ proveedor de lenguaje', 'Registro de PHP con el ref, cuenta del proveedor' ),
	'VOZ' => array( 'Servidor ↔ ElevenLabs', 'Registro de PHP con el ref, cuenta de ElevenLabs' ),
	'AUD' => array( 'Reproducción del audio en el navegador', 'CSP media-src, navegador del visitante' ),
	'MIC' => array( 'Micrófono y dictado', 'Permiso del micrófono, Permissions-Policy, navegador' ),
	'CNV' => array( 'La conversación', 'Versión de la página frente a la del servidor, tope de vueltas' ),
	'HER' => array( 'Herramientas que pide el modelo', 'Lo que pidió el modelo y el tablero' ),
	'TAB' => array( 'El puente con el tablero', 'uhp-dashboard.js y la carga de datos' ),
	'AVI' => array( 'Aviso y autorización', 'Plantilla del aviso, almacenamiento del navegador' ),
	'PAN' => array( 'La ventana y los lanzadores', 'Marcado del tema, botones propios, CSP' ),
);

$mirar = array(
	'UHP-CFG-01' => 'Un optimizador de JavaScript (combinar, diferir, «retrasar ejecución») que separó el bloque en línea de uhp-voz.js; que uhp-core.js cargue.',
	'UHP-CFG-02' => 'El bloque en línea del núcleo; un optimizador que lo separó del archivo.',
	'UHP-CFG-03' => 'Purgar la caché de página o la CDN después de actualizar el plugin.',
	'UHP-CFG-04' => 'Clave e identificador de ElevenLabs, o una copia en caché de cuando sí había voz.',
	'UHP-CFG-05' => 'wp-config.php: casi siempre define( …, getenv( \'X\' ) ) sin la variable de entorno.',
	'UHP-CFG-07' => 'Los dos campos de ElevenLabs: hacen falta la clave Y el identificador.',
	'UHP-CFG-09' => 'El código que engancha el filtro uhp_voz_api_base: debe devolver una URL https.',
	'UHP-CFG-10' => 'Nada: es la línea que deja cada verificación del panel.',
	'UHP-ACC-01' => 'Asistente AI → Activar y la clave del modelo. Si el visitante vio el botón, su página estaba en caché.',
	'UHP-ACC-02' => 'Visibilidad «solo editores»: sesión iniciada y página sin caché.',
	'UHP-ACC-03' => 'Recargar la página: la pestaña llevaba abierta más de un día.',
	'UHP-ACC-04' => 'El registro dice cuál: sin cabecera, otro host o otro esquema. Comparar Ajustes → Generales con la URL pública.',
	'UHP-ACC-05' => 'Si el registro dice «red privada», todos comparten IP detrás de un proxy: filtro uhp_ip_cliente.',
	'UHP-ACC-06' => 'El ajuste «Consultas por día». Cada vuelta de herramientas cuenta una.',
	'UHP-ACC-07' => 'El mismo tope diario, contado aparte para las lecturas en voz alta.',
	'UHP-RED-01' => 'Nada en el sitio: el equipo del visitante no tiene conexión.',
	'UHP-RED-02' => 'La línea roja del navegador justo encima (CSP connect-src, certificado, extensión) y la pestaña Red.',
	'UHP-RED-03' => 'Pestaña Red → Respuesta: avisos de PHP impresos antes del JSON, o una caché que sirve HTML.',
	'UHP-RED-04' => 'Plugin desactivado, JavaScript de otra versión o la API REST cerrada.',
	'UHP-RED-05' => 'Un plugin de seguridad que cierra la API REST a anónimos: añadir urkunina/v1/voz/* a su lista blanca.',
	'UHP-RED-06' => 'El registro de PHP a esa hora: un error fatal al atender la ruta.',
	'UHP-RED-07' => 'Las cabeceras de la respuesta (Server, Via): un proxy, una CDN o un cortafuegos respondió por el plugin.',
	'UHP-RED-08' => 'Versiones distintas de página y servidor (CFG-03), u otro plugin que reescribe respuestas REST.',
	'UHP-LLM-01' => 'Salida por el puerto 443 hacia el proveedor: cortafuegos, DNS o certificados del servidor.',
	'UHP-LLM-02' => 'La página de estado del proveedor y el proxy de salida.',
	'UHP-LLM-03' => 'La clave del proveedor ACTIVO: cambiar de proveedor sin pegar la clave nueva produce esto.',
	'UHP-LLM-04' => 'El «dijo:» del registro y la consola del proveedor: región, proyecto o permiso.',
	'UHP-LLM-05' => 'El campo «Modelo»: un nombre mal escrito o un modelo retirado.',
	'UHP-LLM-06' => 'El «dijo:» del registro. Suele ser un defecto del plugin: reportarlo con el ref.',
	'UHP-LLM-07' => 'La facturación de la cuenta del proveedor.',
	'UHP-LLM-08' => 'Los límites del plan del proveedor y los picos de tráfico.',
	'UHP-LLM-09' => 'La página de estado del proveedor. No es la configuración.',
	'UHP-LLM-10' => 'Un proxy corporativo o un cortafuegos de salida que devuelve HTML: el «dijo:» trae las primeras líneas.',
	'UHP-LLM-11' => 'Nada, salvo que se repita con preguntas corrientes: entonces, el prompt.',
	'UHP-LLM-12' => 'El motivo de parada que trae el registro (bloqueo, filtro, corte).',
	'UHP-LLM-13' => 'Si pasa a menudo, el nivel de «Cuánto piensa antes de responder».',
	'UHP-LLM-14' => 'Si pasa a menudo con un modelo, probar otro.',
	'UHP-LLM-99' => 'El «dijo:» del registro. Falta una fila en el catálogo: reportarlo.',
	'UHP-VOZ-01' => 'Salida por el puerto 443 hacia api.elevenlabs.io (o el host del filtro de residencia).',
	'UHP-VOZ-02' => 'La página de estado de ElevenLabs.',
	'UHP-VOZ-03' => 'La clave de ElevenLabs: revocada o mal copiada.',
	'UHP-VOZ-04' => 'Los permisos de la clave: text_to_speech para hablar, voices_read para verificar.',
	'UHP-VOZ-05' => 'La lista desplegable de voces del panel.',
	'UHP-VOZ-06' => 'El «dijo:» y el «modelo=» del registro: el modelo de voz o la velocidad.',
	'UHP-VOZ-07' => 'El plan y el consumo de caracteres de la cuenta de ElevenLabs.',
	'UHP-VOZ-08' => 'El plan de ElevenLabs: síntesis simultáneas.',
	'UHP-VOZ-09' => 'La página de estado de ElevenLabs.',
	'UHP-VOZ-10' => 'Un proxy de salida que reescribe la respuesta: el registro trae el content-type.',
	'UHP-VOZ-11' => 'Nada: la respuesta del modelo era solo marcado.',
	'UHP-VOZ-12' => 'Si pasa a menudo, el problema es la longitud de las respuestas, no el tope.',
	'UHP-VOZ-13' => 'El «dijo:» del registro: la forma del listado de voces cambió.',
	'UHP-VOZ-99' => 'El «dijo:» del registro. Falta una fila en el catálogo: reportarlo.',
	'UHP-AUD-01' => 'Una respuesta truncada por un proxy con límite de tamaño.',
	'UHP-AUD-02' => 'MediaError 4: la CSP del sitio sin media-src \'self\' blob:. MediaError 3: datos corruptos.',
	'UHP-AUD-03' => 'Nada: el navegador pide un gesto y se ofrece «Oír la respuesta».',
	'UHP-AUD-04' => 'Con NotSupportedError, igual que AUD-02: CSP o formato.',
	'UHP-AUD-05' => 'Pestaña o sistema en silencio, o el ahorro de energía del teléfono.',
	'UHP-MIC-01' => 'Nada: Firefox y otros no reconocen voz. Se puede escribir.',
	'UHP-MIC-02' => 'La línea MIC anterior dice la causa.',
	'UHP-MIC-03' => 'El nombre del error en el detalle.',
	'UHP-MIC-04' => 'El candado de la barra de direcciones, la cabecera Permissions-Policy del sitio o una página sin HTTPS.',
	'UHP-MIC-05' => 'Brave, Vivaldi u Opera: no tienen el servicio de transcripción.',
	'UHP-MIC-06' => 'La conexión del visitante, o una red que bloquea los servicios de Google.',
	'UHP-MIC-07' => 'El equipo del visitante: sin micrófono, o en uso por otra aplicación.',
	'UHP-MIC-08' => 'El navegador no transcribe es-CO.',
	'UHP-MIC-09' => 'Nada: no se oyó ninguna palabra en veinte segundos.',
	'UHP-MIC-10' => 'Una doble pulsación muy rápida.',
	'UHP-MIC-11' => 'Nada: Safari a veces cierra el dictado sin texto.',
	'UHP-MIC-99' => 'El motivo que dio el navegador, en el detalle. Falta una fila en el catálogo.',
	'UHP-CNV-01' => 'Nada: es la guarda que impide dos preguntas a la vez.',
	'UHP-CNV-02' => 'Las líneas HER de encima: una herramienta que invita al modelo a repetir.',
	'UHP-CNV-03' => 'Un servidor de versión anterior a la 2.1 (CFG-03).',
	'UHP-CNV-04' => 'La pila del Error que va con la línea: es un defecto del plugin.',
	'UHP-CNV-05' => 'Versiones distintas de página y servidor, o alguien llamando la ruta a mano.',
	'UHP-CNV-06' => 'Nada: el visitante empieza otra conversación.',
	'UHP-CNV-07' => 'Una pestaña abierta durante una actualización del plugin.',
	'UHP-CNV-08' => 'Nada: el historial se recortó a los últimos sesenta mensajes.',
	'UHP-HER-01' => 'Un JavaScript en caché anterior a las herramientas del servidor, o un nombre inventado por el modelo.',
	'UHP-HER-02' => 'La pila del Error que va con la línea, y el nombre de la herramienta.',
	'UHP-HER-03' => 'El mando del tablero (uhp-dashboard.js) rechazó el cambio.',
	'UHP-HER-04' => 'Nada: el modelo encadenaba cambios y se frenó.',
	'UHP-HER-05' => 'El argumento, en el detalle: zona, subregión o municipio que no cruza.',
	'UHP-HER-06' => 'Nada: es la confirmación de Pupiales e Ipiales funcionando.',
	'UHP-HER-07' => 'El mando del tablero no pudo seleccionar el municipio confirmado.',
	'UHP-TAB-01' => 'uhp-dashboard.js no corrió, o el asistente quedó fuera de un [data-uhp-tablero].',
	'UHP-TAB-02' => 'La carga de datos del tablero.',
	'UHP-TAB-03' => 'uhp-dashboard.js y uhp-voz.js son de versiones distintas.',
	'UHP-TAB-04' => 'La carga de datos del tablero.',
	'UHP-TAB-05' => 'La pila del Error: la pregunta siguió sin el contexto de la pantalla.',
	'UHP-AVI-01' => 'Un filtro del tema o de kses que quita la etiqueta <template>, o una copia en caché.',
	'UHP-AVI-03' => 'Nada: ventana privada o almacenamiento bloqueado; la autorización se pedirá cada vez.',
	'UHP-PAN-01' => 'El marcado del asistente, alterado por el tema, kses o una caché.',
	'UHP-PAN-02' => 'Dos tableros en la página: indicar el destino con data-uhp-voz-abrir="#id".',
	'UHP-PAN-03' => 'Una CSP con Trusted Types: los botones funcionan, pero sin dibujo.',
	'UHP-PAN-04' => 'Nada: la persona pulsó «Parar».',
	'UHP-PAN-05' => 'Nada: navegador antiguo; «Parar» descarta la respuesta pero no corta la red.',
	'UHP-PAN-06' => 'Asistente AI → Preguntas sugeridas.',
);

if ( PHP_SAPI === 'cli' && realpath( $argv[0] ) === realpath( __FILE__ ) ) {
	$todos = uhp_codigos_todos();
	$md    = array();
	$md[]  = '# Códigos de error del asistente';
	$md[]  = '';
	$md[]  = '> Archivo generado por `php tests/generar-codigos.php` a partir de los dos catálogos: `includes/class-uhp-codigos.php` (servidor) y el objeto `CODIGOS` de `assets/js/uhp-voz.js` (navegador). No se edita a mano.';
	$md[]  = '';
	$md[]  = 'Cada fallo del asistente tiene un código `UHP-ÁREA-NN` que es **el mismo** en la consola del navegador, en el registro de PHP, en la verificación del panel y en la pantalla del visitante. El área dice dónde mirar y el número qué pasó. En `LLM` y `VOZ`, del 01 al 10 significan lo mismo: 01 no conecta, 02 tiempo agotado, 03 clave rechazada, 04 falta un permiso, 05 no existe, 06 petición rechazada, 07 sin saldo, 08 límite de ritmo, 09 fallo interno del proveedor, 10 un 200 inutilizable. El 99 de cada área es «sin clasificar».';
	$md[]  = '';
	$md[]  = 'Un número no se reutiliza nunca ni cambia de sentido.';
	$md[]  = '';
	$md[]  = '## Cómo se ve';
	$md[]  = '';
	$md[]  = '- **Consola del navegador** (una línea por fallo): `[URKUNINA 5000] UHP-LLM-03 · el proveedor de lenguaje rechazó la clave · HTTP 502 · ref 7c2e9a · /voz/chat · vX.Y.Z`';
	$md[]  = '- **Registro de PHP**: `[URKUNINA 5000] UHP-LLM-03 · el proveedor de lenguaje rechazó la clave · ref 7c2e9a · voz/chat · proveedor=gemini modelo=… http=400 · dijo: API key not valid…`';
	$md[]  = '- **Pantalla del visitante**: el mensaje de siempre y, al lado, el código (`UHP-ACC-06`). Es lo que se le dicta a la mesa de ayuda.';
	$md[]  = '- **Panel**: la verificación de las claves pinta el código delante del mensaje.';
	$md[]  = '';
	$md[]  = 'El `ref` une la captura del visitante con la línea exacta del registro de PHP: `grep 7c2e9a` sobre el registro. Al navegador nunca viajan claves, el nombre del proveedor o del modelo, lo que contestó el proveedor ni la pregunta del visitante. `UHPVozAsistente.diagnostico()` en la consola devuelve las últimas veinte entradas como texto, para copiarlas.';
	$md[]  = '';
	$md[]  = 'Niveles: **error** cuando alguien de la entidad tiene que actuar; **warn** cuando algo se degrada pero la conversación sigue, o la causa es el equipo del visitante; **info** cuando no es un fallo pero explica una conducta. Un sitio sano no tiene ningún `error` en la consola.';
	$md[]  = '';
	$md[]  = '## Áreas';
	$md[]  = '';
	$md[]  = '| Área | Qué abarca | Dónde se mira primero |';
	$md[]  = '|---|---|---|';
	foreach ( $areas as $a => $f ) {
		$md[] = '| `' . $a . '` | ' . $f[0] . ' | ' . $f[1] . ' |';
	}
	foreach ( $areas as $a => $f ) {
		$md[] = '';
		$md[] = '## ' . $a . ' · ' . $f[0];
		$md[] = '';
		$md[] = '| Código | Nivel | Lo detecta | Significa | Qué mirar |';
		$md[] = '|---|---|---|---|---|';
		foreach ( $todos as $cod => $f2 ) {
			if ( substr( $cod, 4, 3 ) !== $a ) {
				continue;
			}
			$md[] = '| `' . $cod . '` | ' . $f2['nivel'] . ' | ' . $f2['lado'] . ' | ' . $f2['frase'] . ' | ' . ( isset( $mirar[ $cod ] ) ? str_replace( '|', '\\|', $mirar[ $cod ] ) : '' ) . ' |';
		}
	}
	$md[] = '';
	file_put_contents( UHP_DIR . 'docs/voz/codigos.md', implode( "\n", $md ) );
	printf( "docs/voz/codigos.md — %d códigos\n", count( $todos ) );
}
