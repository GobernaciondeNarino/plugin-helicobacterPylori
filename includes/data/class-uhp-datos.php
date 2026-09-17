<?php
/**
 * Capa de datos: lectura, validación, escritura y respaldo de los JSON.
 *
 * Toda la información del proyecto URKUNINA 5000 vive en /data: catorce
 * archivos JSON más la geometría municipal, todos versionados. Esta clase es el ÚNICO punto por el que se leen y se
 * escriben, y por tanto donde se concentran las garantías:
 *
 *  - Lista blanca de archivos: ninguna ruta se construye con entrada de
 *    usuario, se selecciona del registro. Un salto de directorio es imposible.
 *  - Validación en dos pasos: JSON bien formado y luego contrato de la vista
 *    (claves obligatorias y tipos), antes de tocar el disco.
 *  - Escritura atómica con respaldo previo: si algo falla, el archivo anterior
 *    sigue intacto y se puede restaurar desde el panel.
 *  - Integridad: se guarda el SHA-256 de cada archivo para detectar cambios
 *    hechos fuera del panel (por FTP, por despliegue) y avisar de ello.
 *
 * @package Urkunina5000
 */

namespace GobernacionNarino\Urkunina;

defined( 'ABSPATH' ) || exit;

final class UHP_Datos {

	/** Opción con el SHA-256 de cada archivo en su última escritura conocida. */
	const OPT_LINEA_BASE = 'uhp_datos_hash';

	/** Prefijo de los transients de contenido. */
	const CACHE = 'uhp_json_';

	/** Vida de la caché de un archivo leído (12 horas). */
	const CACHE_TTL = 43200;

	/** Máximo de respaldos conservados por archivo. */
	const MAX_RESPALDOS = 10;

	/** @var array<string,array> Caché en memoria por petición. */
	private static $memoria = array();

	/* ----------------------------------------------------------------- */
	/* Registro de archivos                                              */
	/* ----------------------------------------------------------------- */

	/**
	 * Catálogo de los archivos de datos del proyecto.
	 *
	 * `raiz` es la clave de primer nivel que contiene el cuerpo de datos y
	 * `tipo` describe su forma, lo que permite validar sin escribir un
	 * validador a medida por archivo.
	 *
	 * @return array<string,array>
	 */
	public static function registro() {
		static $r = null;
		if ( null !== $r ) {
			return $r;
		}

		$r = array(
			'manifiesto'      => array(
				'archivo'     => '00_manifiesto.json',
				'titulo'      => 'Manifiesto del conjunto',
				'descripcion' => 'Procedencia, convenciones, archivos que componen el conjunto, datos no incluidos y discrepancias detectadas entre las fuentes.',
				'claves'      => array( 'documentos_fuente', 'archivos', 'convenciones' ),
				'grupo'       => 'gobernanza',
			),
			'proyecto'        => array(
				'archivo'     => '01_proyecto.json',
				'titulo'      => 'Identificación y ejecución',
				'descripcion' => 'BPIN, aprobación por el OCAD, financiación, fechas de ejecución y metas globales del proyecto.',
				'claves'      => array( 'identificacion', 'financiacion', 'ejecucion', 'metas_globales' ),
				'grupo'       => 'proyecto',
			),
			'epidemiologia'   => array(
				'archivo'     => '02_contexto_epidemiologico.json',
				'titulo'      => 'Contexto epidemiológico',
				'descripcion' => 'Incidencia de cáncer gástrico por zona de riesgo (roja, amarilla, verde) y comparación con las tasas nacionales.',
				'claves'      => array( 'zonas_de_riesgo', 'comparacion_nacional' ),
				'grupo'       => 'epidemiologia',
			),
			'cobertura'       => array(
				'archivo'     => '03_municipios_cobertura.json',
				'titulo'      => 'Cobertura municipal',
				'descripcion' => 'Los 55 municipios priorizados del área andina y su estado de intervención.',
				'claves'      => array( 'resumen', 'municipios' ),
				'lista'       => 'municipios',
				'grupo'       => 'territorio',
			),
			'prev_municipal'  => array(
				'archivo'     => '04_prevalencia_municipal.json',
				'titulo'      => 'Prevalencia por municipio',
				'descripcion' => 'Los diez municipios con mayor prevalencia de lesión precursora de malignidad y los diez con mayor infección por H. pylori.',
				'claves'      => array( 'top10_lesion_precursora_malignidad', 'top10_infeccion_h_pylori' ),
				'grupo'       => 'resultados',
			),
			'prev_subregion'  => array(
				'archivo'     => '05_prevalencia_subregional.json',
				'titulo'      => 'Prevalencia por subregión',
				'descripcion' => 'Prevalencia de LPM e infección por H. pylori en las once subregiones documentadas.',
				'claves'      => array( 'subregiones' ),
				'lista'       => 'subregiones',
				'grupo'       => 'resultados',
			),
			'biobanco'        => array(
				'archivo'     => '06_biobanco_muestras.json',
				'titulo'      => 'Biobanco',
				'descripcion' => 'Muestras biológicas recolectadas y almacenadas, desagregadas por tipo.',
				'claves'      => array( 'muestras' ),
				'lista'       => 'muestras',
				'grupo'       => 'resultados',
			),
			'sociodemografia' => array(
				'archivo'     => '07_perfil_sociodemografico.json',
				'titulo'      => 'Perfil sociodemográfico',
				'descripcion' => 'Género, pertenencia étnica, nivel educativo, régimen de salud, indicadores socioeconómicos y estado nutricional de los participantes.',
				'claves'      => array( 'genero', 'pertenencia_etnica', 'nivel_educativo', 'regimen_salud' ),
				'grupo'       => 'poblacion',
			),
			'tamizaje'        => array(
				'archivo'     => '08_resultados_tamizaje.json',
				'titulo'      => 'Resultados del tamizaje',
				'descripcion' => 'Positivos y negativos de infección por H. pylori y de lesión precursora de malignidad sobre los 5.000 participantes.',
				'claves'      => array( 'infeccion_h_pylori', 'lesion_precursora_malignidad' ),
				'grupo'       => 'resultados',
			),
			'cancer'          => array(
				'archivo'     => '09_casos_cancer_detectados.json',
				'titulo'      => 'Casos de cáncer detectados',
				'descripcion' => 'Casos de cáncer gástrico hallados por tamizaje endoscópico preventivo, su distribución municipal y su desenlace.',
				'claves'      => array( 'resumen', 'distribucion_por_municipio', 'desenlace' ),
				'grupo'       => 'resultados',
			),
			'actores'         => array(
				'archivo'     => '10_actores_institucionales.json',
				'titulo'      => 'Actores institucionales',
				'descripcion' => 'Mapeo de las entidades participantes, su rol en el proyecto y su relevancia para la plataforma de datos.',
				'claves'      => array( 'actores' ),
				'lista'       => 'actores',
				'grupo'       => 'gobernanza',
			),
			'publicaciones'   => array(
				'archivo'     => '11_produccion_cientifica.json',
				'titulo'      => 'Producción científica',
				'descripcion' => 'Publicaciones derivadas del proyecto, su año, la revista o medio y su aporte clave.',
				'claves'      => array( 'publicaciones', 'publicaciones_por_anio' ),
				'lista'       => 'publicaciones',
				'grupo'       => 'gobernanza',
			),
			'metas'           => array(
				'archivo'     => '12_metas_mga.json',
				'titulo'      => 'Metas de la ficha MGA',
				'descripcion' => 'Los 23 productos de la ficha MGA con su meta, lo ejecutado y su porcentaje de avance.',
				'claves'      => array( 'resumen', 'productos' ),
				'lista'       => 'productos',
				'grupo'       => 'proyecto',
			),
			'retos'           => array(
				'archivo'     => '13_retos_siguiente_fase.json',
				'titulo'      => 'Retos de la siguiente fase',
				'descripcion' => 'Retos técnicos identificados para la fase siguiente y las orientaciones de diseño derivadas.',
				'claves'      => array( 'retos' ),
				'lista'       => 'retos',
				'grupo'       => 'gobernanza',
			),
			'mortalidad'      => array(
				'archivo'     => '15_mortalidad_departamental.json',
				'titulo'      => 'Mortalidad departamental',
				'descripcion' => 'Fallecimientos anuales por cáncer de estómago en Nariño entre 2019 y 2022, según el Instituto Departamental de Salud. Son muertes registradas, no casos nuevos ni tasas: no se comparan con las cifras de incidencia del contexto epidemiológico.',
				'claves'      => array( 'variacion_periodo', 'serie' ),
				'lista'       => 'serie',
				'grupo'       => 'epidemiologia',
			),
			'acceso_oncologico' => array(
				'archivo'     => '16_acceso_servicios_oncologicos.json',
				'titulo'      => 'Acceso a servicios oncológicos',
				'descripcion' => 'Las seis IPS del departamento con servicios oncológicos habilitados —las seis en Pasto— y la barrera de acceso territorial que eso supone para los otros 63 municipios.',
				'claves'      => array( 'resumen', 'instituciones', 'barrera_de_acceso_documentada' ),
				'lista'       => 'instituciones',
				'grupo'       => 'epidemiologia',
			),
			'subregiones'     => array(
				'archivo'     => '14_subregiones_municipios.json',
				'titulo'      => 'Subregiones y sus municipios',
				'descripcion' => 'División subregional oficial de la Gobernación: las 13 subregiones con los municipios que componen cada una. Es la nomenclatura con la que se rotulan las subregiones en el tablero y en los gráficos.',
				'claves'      => array( 'departamento', 'subregiones' ),
				'lista'       => 'subregiones',
				'grupo'       => 'territorio',
			),
			'zonas'           => array(
				'archivo'     => '17_zonas_riesgo_subregion.json',
				'titulo'      => 'Zona de riesgo por subregión',
				'descripcion' => 'A qué zona de riesgo —roja, amarilla o verde— pertenece cada una de las 13 subregiones. Es una DERIVACIÓN declarada, no un dato publicado: los documentos describen las zonas y nombran territorios de referencia, pero no reparten el departamento entre ellas. El archivo trae la comprobación contra esas referencias.',
				'claves'      => array( 'zonas', 'subregiones', 'verificacion' ),
				'lista'       => 'subregiones',
				'grupo'       => 'territorio',
			),
			'criterios'       => array(
				'archivo'     => '18_criterios_participacion.json',
				'titulo'      => 'Criterios de participación',
				'descripcion' => 'Los cuatro criterios de inclusión y los seis de exclusión con los que se seleccionó a los 5.000 voluntarios. Dicen de quién habla cada cifra del conjunto y, sobre todo, de quién no.',
				'claves'      => array( 'inclusion', 'exclusion', 'resumen' ),
				'grupo'       => 'proyecto',
			),
			'geojson'         => array(
				'archivo'     => 'narino_municipios.geojson',
				'titulo'      => 'Geometría municipal (GeoJSON)',
				'descripcion' => 'Marco geoestadístico de los 64 municipios de Nariño; alimenta el mapa del tablero. Origen DANE.',
				'claves'      => array( 'type', 'features' ),
				'lista'       => 'features',
				'grupo'       => 'territorio',
				'geo'         => true,
			),
			'geojson_subregiones' => array(
				'archivo'     => 'dep-sub-mun.geojson',
				'titulo'      => 'Geometría por subregiones (GeoJSON)',
				'descripcion' => 'Tres capas en un solo archivo: el departamento, sus 13 subregiones con la geometría ya disuelta y los 64 municipios con la subregión a la que pertenece cada uno. Es lo que permite llevar al mapa las vistas subregionales. Origen DANE + agrupación subregional de la Gobernación.',
				'claves'      => array( 'type', 'features' ),
				'lista'       => 'features',
				'grupo'       => 'territorio',
				'geo'         => true,
			),
		);

		return $r;
	}

	/**
	 * Nombres de archivo aceptados (lista blanca).
	 *
	 * @return string[]
	 */
	public static function archivos_validos() {
		return wp_list_pluck( self::registro(), 'archivo' );
	}

	/**
	 * Tamaño máximo admitido para un archivo del conjunto.
	 *
	 * La cartografía tiene su propio tope: pesa órdenes de magnitud más que
	 * un archivo de cifras y compartir límite obligaría a aflojar el de
	 * todos, que es justo lo que no conviene.
	 *
	 * @param string $clave Clave del registro.
	 * @return int Bytes.
	 */
	public static function tope_bytes( $clave ) {
		$r = self::registro();
		return ( ! empty( $r[ $clave ]['geo'] ) )
			? UHP_Security::MAX_GEO_BYTES
			: UHP_Security::MAX_JSON_BYTES;
	}

	/**
	 * ¿Está el nombre de archivo en la lista blanca?
	 *
	 * @param string $archivo Nombre de archivo.
	 * @return bool
	 */
	public static function es_archivo_valido( $archivo ) {
		return in_array( (string) $archivo, self::archivos_validos(), true );
	}

	/**
	 * Devuelve la clave del registro a partir del nombre de archivo.
	 *
	 * @param string $archivo Nombre de archivo.
	 * @return string Clave o '' si no existe.
	 */
	public static function clave_de( $archivo ) {
		foreach ( self::registro() as $clave => $meta ) {
			if ( $meta['archivo'] === $archivo ) {
				return $clave;
			}
		}
		return '';
	}

	/* ----------------------------------------------------------------- */
	/* Rutas                                                             */
	/* ----------------------------------------------------------------- */

	/** Directorio de datos del plugin. */
	public static function dir() {
		return UHP_DIR . 'data/';
	}

	/** Directorio de copias de seguridad. */
	public static function dir_respaldos() {
		return self::dir() . 'respaldos/';
	}

	/** URL pública del directorio de datos. */
	public static function url() {
		return UHP_URL . 'data/';
	}

	/**
	 * Ruta absoluta de un archivo del registro.
	 *
	 * @param string $clave Clave del registro.
	 * @return string Ruta o '' si la clave no existe.
	 */
	public static function ruta( $clave ) {
		$r = self::registro();
		return isset( $r[ $clave ] ) ? self::dir() . $r[ $clave ]['archivo'] : '';
	}

	/**
	 * Crea el directorio de respaldos y lo protege de accesos directos.
	 */
	public static function asegurar_directorio_respaldos() {
		$dir = self::dir_respaldos();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		// Los respaldos son copias de trabajo: no deben servirse por HTTP.
		$index = $dir . 'index.php';
		if ( is_dir( $dir ) && ! file_exists( $index ) ) {
			UHP_Security::escribir_atomico( $index, "<?php\n// Silencio.\n" );
		}
		$ht = $dir . '.htaccess';
		if ( is_dir( $dir ) && ! file_exists( $ht ) ) {
			UHP_Security::escribir_atomico( $ht, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		}
	}

	/* ----------------------------------------------------------------- */
	/* Lectura                                                           */
	/* ----------------------------------------------------------------- */

	/**
	 * Lee y decodifica un archivo del registro.
	 *
	 * @param string $clave  Clave del registro.
	 * @param bool   $fresco Si true, ignora la caché.
	 * @return array Contenido decodificado; array vacío si falla.
	 */
	public static function leer( $clave, $fresco = false ) {
		$clave = UHP_Security::clave( $clave );
		if ( ! $fresco && isset( self::$memoria[ $clave ] ) ) {
			return self::$memoria[ $clave ];
		}

		$ruta = self::ruta( $clave );
		if ( '' === $ruta ) {
			return array();
		}

		if ( ! $fresco ) {
			$cache = get_transient( self::CACHE . $clave );
			if ( is_array( $cache ) ) {
				self::$memoria[ $clave ] = $cache;
				return $cache;
			}
		}

		if ( ! is_readable( $ruta ) ) {
			self::$memoria[ $clave ] = array();
			return array();
		}

		$crudo = file_get_contents( $ruta ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$datos = ( false === $crudo ) ? null : json_decode( $crudo, true );
		if ( ! is_array( $datos ) ) {
			$datos = array();
		}

		self::$memoria[ $clave ] = $datos;
		set_transient( self::CACHE . $clave, $datos, self::CACHE_TTL );
		return $datos;
	}

	/**
	 * Atajo para leer un valor anidado con notación de puntos.
	 *
	 * Ej.: UHP_Datos::valor( 'tamizaje', 'infeccion_h_pylori.positivos.porcentaje' )
	 *
	 * @param string $clave    Clave del registro.
	 * @param string $ruta     Ruta separada por puntos.
	 * @param mixed  $defecto  Valor devuelto si no existe.
	 * @return mixed
	 */
	public static function valor( $clave, $ruta, $defecto = null ) {
		$nodo = self::leer( $clave );
		foreach ( explode( '.', $ruta ) as $paso ) {
			if ( ! is_array( $nodo ) || ! array_key_exists( $paso, $nodo ) ) {
				return $defecto;
			}
			$nodo = $nodo[ $paso ];
		}
		return $nodo;
	}

	/** Invalida la caché de un archivo (o de todos si no se indica clave). */
	public static function purgar( $clave = '' ) {
		if ( '' !== $clave ) {
			unset( self::$memoria[ $clave ] );
			delete_transient( self::CACHE . $clave );
			return;
		}
		self::$memoria = array();
		foreach ( array_keys( self::registro() ) as $k ) {
			delete_transient( self::CACHE . $k );
		}
	}

	/* ----------------------------------------------------------------- */
	/* Validación                                                        */
	/* ----------------------------------------------------------------- */

	/**
	 * Valida una cadena JSON contra el contrato del archivo indicado.
	 *
	 * @param string $clave Clave del registro.
	 * @param string $crudo Contenido JSON en crudo.
	 * @return array{ok:bool,errores:string[],avisos:string[],datos:array}
	 */
	public static function validar( $clave, $crudo ) {
		$res = array(
			'ok'      => false,
			'errores' => array(),
			'avisos'  => array(),
			'datos'   => array(),
		);

		$r = self::registro();
		if ( ! isset( $r[ $clave ] ) ) {
			$res['errores'][] = 'El archivo indicado no pertenece al conjunto de datos del proyecto.';
			return $res;
		}
		$meta = $r[ $clave ];

		$crudo = (string) $crudo;
		if ( '' === trim( $crudo ) ) {
			$res['errores'][] = 'El contenido está vacío.';
			return $res;
		}

		$tope = self::tope_bytes( $clave );
		if ( strlen( $crudo ) > $tope ) {
			$res['errores'][] = sprintf(
				'El archivo pesa %s y el máximo admitido es %s.',
				size_format( strlen( $crudo ) ),
				size_format( $tope )
			);
			return $res;
		}

		// 1) JSON bien formado.
		$datos = json_decode( $crudo, true );
		if ( null === $datos && JSON_ERROR_NONE !== json_last_error() ) {
			$res['errores'][] = 'JSON inválido: ' . json_last_error_msg() . '.';
			return $res;
		}
		if ( ! is_array( $datos ) ) {
			$res['errores'][] = 'El documento raíz debe ser un objeto JSON, no un valor suelto.';
			return $res;
		}

		// 2) Codificación: los documentos del proyecto son UTF-8.
		if ( ! mb_check_encoding( $crudo, 'UTF-8' ) ) {
			$res['errores'][] = 'El contenido no está codificado en UTF-8.';
			return $res;
		}

		// 3) Claves obligatorias del contrato.
		foreach ( $meta['claves'] as $obligatoria ) {
			if ( ! array_key_exists( $obligatoria, $datos ) ) {
				$res['errores'][] = sprintf( 'Falta la clave obligatoria «%s».', $obligatoria );
			}
		}
		if ( $res['errores'] ) {
			return $res;
		}

		// 4) Forma de la lista principal, si el archivo declara una.
		if ( ! empty( $meta['lista'] ) ) {
			$lista = isset( $datos[ $meta['lista'] ] ) ? $datos[ $meta['lista'] ] : null;
			if ( ! is_array( $lista ) ) {
				$res['errores'][] = sprintf( 'La clave «%s» debe ser una lista.', $meta['lista'] );
				return $res;
			}
			if ( ! $lista ) {
				$res['avisos'][] = sprintf( 'La lista «%s» quedó vacía: los componentes que la usan no mostrarán datos.', $meta['lista'] );
			}
		}

		// 5) GeoJSON: comprobaciones propias del formato.
		if ( ! empty( $meta['geo'] ) ) {
			if ( ! isset( $datos['type'] ) || 'FeatureCollection' !== $datos['type'] ) {
				$res['errores'][] = 'El GeoJSON debe ser de tipo «FeatureCollection».';
				return $res;
			}
			$sin_codigo = 0;
			foreach ( (array) $datos['features'] as $f ) {
				if ( empty( $f['properties']['MPIO_CDPMP'] ) ) {
					$sin_codigo++;
				}
			}
			if ( $sin_codigo ) {
				$res['avisos'][] = sprintf(
					'%d entidad(es) no traen la propiedad MPIO_CDPMP: el mapa no podrá unirlas con los datos del proyecto.',
					$sin_codigo
				);
			}
		}

		// 6) Bloque _meta: convención del conjunto, no obligatorio pero sí esperado.
		if ( empty( $meta['geo'] ) && ! isset( $datos['_meta'] ) ) {
			$res['avisos'][] = 'Falta el bloque «_meta» con la procedencia del dato; es la convención del conjunto URKUNINA 5000.';
		}

		$res['ok']    = true;
		$res['datos'] = $datos;
		return $res;
	}

	/* ----------------------------------------------------------------- */
	/* Escritura y respaldo                                              */
	/* ----------------------------------------------------------------- */

	/**
	 * Guarda un archivo de datos, respaldando antes el que había.
	 *
	 * @param string $clave Clave del registro.
	 * @param string $crudo Contenido JSON.
	 * @return array{ok:bool,mensaje:string,avisos:string[]}
	 */
	public static function guardar( $clave, $crudo ) {
		$clave = UHP_Security::clave( $clave );
		$val   = self::validar( $clave, $crudo );

		if ( ! $val['ok'] ) {
			return array(
				'ok'      => false,
				'mensaje' => implode( ' ', $val['errores'] ),
				'avisos'  => $val['avisos'],
			);
		}

		$ruta = self::ruta( $clave );
		if ( '' === $ruta || ! UHP_Security::dentro_de( $ruta, self::dir() ) ) {
			return array(
				'ok'      => false,
				'mensaje' => 'Ruta de destino no válida.',
				'avisos'  => array(),
			);
		}

		if ( file_exists( $ruta ) && ! is_writable( $ruta ) ) {
			return array(
				'ok'      => false,
				'mensaje' => 'El archivo no tiene permisos de escritura en el servidor. Ajuste los permisos de /data y vuelva a intentarlo.',
				'avisos'  => array(),
			);
		}

		// Respaldo del contenido anterior antes de sobrescribir.
		$respaldo = self::respaldar( $clave );

		// Se reescribe desde la estructura decodificada: normaliza el formato
		// (indentación de 2 espacios, UTF-8 sin escapar, barras sin escapar)
		// y garantiza que lo guardado es exactamente lo que se validó.
		$json = wp_json_encode(
			$val['datos'],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
		if ( false === $json ) {
			return array(
				'ok'      => false,
				'mensaje' => 'No se pudo serializar el contenido validado.',
				'avisos'  => $val['avisos'],
			);
		}
		$json = self::indentar_dos_espacios( $json ) . "\n";

		if ( ! UHP_Security::escribir_atomico( $ruta, $json ) ) {
			return array(
				'ok'      => false,
				'mensaje' => 'No se pudo escribir el archivo. Revise los permisos del directorio /data.',
				'avisos'  => $val['avisos'],
			);
		}

		self::purgar( $clave );
		self::actualizar_hash( $clave );

		$mensaje = sprintf( 'Se guardó «%s».', self::registro()[ $clave ]['archivo'] );
		if ( $respaldo ) {
			$mensaje .= ' Se guardó una copia de seguridad del contenido anterior.';
		}

		return array(
			'ok'      => true,
			'mensaje' => $mensaje,
			'avisos'  => $val['avisos'],
		);
	}

	/**
	 * Copia el archivo actual al directorio de respaldos.
	 *
	 * @param string $clave Clave del registro.
	 * @return string Nombre del respaldo o '' si no se pudo.
	 */
	public static function respaldar( $clave ) {
		$ruta = self::ruta( $clave );
		if ( '' === $ruta || ! is_readable( $ruta ) ) {
			return '';
		}
		self::asegurar_directorio_respaldos();

		$r      = self::registro();
		$nombre = $r[ $clave ]['archivo'] . '.' . gmdate( 'Ymd-His' ) . '.bak';
		$dest   = self::dir_respaldos() . $nombre;

		$crudo = file_get_contents( $ruta ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $crudo || ! UHP_Security::escribir_atomico( $dest, $crudo ) ) {
			return '';
		}

		self::podar_respaldos( $clave );
		return $nombre;
	}

	/**
	 * Lista los respaldos disponibles de un archivo, del más reciente al más antiguo.
	 *
	 * @param string $clave Clave del registro.
	 * @return array<int,array{nombre:string,fecha:string,bytes:int}>
	 */
	public static function respaldos( $clave ) {
		$r = self::registro();
		if ( ! isset( $r[ $clave ] ) || ! is_dir( self::dir_respaldos() ) ) {
			return array();
		}

		$patron  = self::dir_respaldos() . $r[ $clave ]['archivo'] . '.*.bak';
		$rutas   = glob( $patron );
		$salida  = array();
		if ( ! $rutas ) {
			return $salida;
		}

		foreach ( $rutas as $ruta ) {
			$nombre = basename( $ruta );
			$salida[] = array(
				'nombre' => $nombre,
				'fecha'  => self::fecha_de_respaldo( $nombre ),
				'bytes'  => (int) filesize( $ruta ),
			);
		}

		usort(
			$salida,
			static function ( $a, $b ) {
				return strcmp( $b['nombre'], $a['nombre'] );
			}
		);
		return $salida;
	}

	/**
	 * Restaura un respaldo sobre el archivo vivo.
	 *
	 * @param string $clave  Clave del registro.
	 * @param string $nombre Nombre del respaldo.
	 * @return array{ok:bool,mensaje:string}
	 */
	public static function restaurar( $clave, $nombre ) {
		$r = self::registro();
		if ( ! isset( $r[ $clave ] ) ) {
			return array(
				'ok'      => false,
				'mensaje' => 'Archivo desconocido.',
			);
		}

		// El nombre del respaldo se valida contra la lista real de respaldos:
		// no se acepta ninguna cadena que no aparezca en ella.
		$nombre     = sanitize_file_name( (string) $nombre );
		$existentes = wp_list_pluck( self::respaldos( $clave ), 'nombre' );
		if ( ! in_array( $nombre, $existentes, true ) ) {
			return array(
				'ok'      => false,
				'mensaje' => 'La copia de seguridad indicada no existe.',
			);
		}

		$origen = self::dir_respaldos() . $nombre;
		$crudo  = is_readable( $origen ) ? file_get_contents( $origen ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $crudo ) {
			return array(
				'ok'      => false,
				'mensaje' => 'No se pudo leer la copia de seguridad.',
			);
		}

		// Se restaura por la misma vía de guardado: la copia también se valida
		// (podría haberse alterado en disco) y el estado actual se respalda.
		$res = self::guardar( $clave, $crudo );
		return array(
			'ok'      => $res['ok'],
			'mensaje' => $res['ok'] ? sprintf( 'Se restauró la copia del %s.', self::fecha_de_respaldo( $nombre ) ) : $res['mensaje'],
		);
	}

	/**
	 * Conserva solo los MAX_RESPALDOS más recientes de un archivo.
	 *
	 * @param string $clave Clave del registro.
	 */
	private static function podar_respaldos( $clave ) {
		$lista = self::respaldos( $clave );
		if ( count( $lista ) <= self::MAX_RESPALDOS ) {
			return;
		}
		foreach ( array_slice( $lista, self::MAX_RESPALDOS ) as $viejo ) {
			$ruta = self::dir_respaldos() . $viejo['nombre'];
			if ( UHP_Security::dentro_de( $ruta, self::dir_respaldos() ) && is_file( $ruta ) ) {
				wp_delete_file( $ruta );
			}
		}
	}

	/**
	 * Extrae y formatea la marca de tiempo del nombre de un respaldo.
	 *
	 * @param string $nombre Nombre del respaldo.
	 * @return string
	 */
	private static function fecha_de_respaldo( $nombre ) {
		if ( preg_match( '/\.(\d{8})-(\d{6})\.bak$/', $nombre, $m ) ) {
			$ts = strtotime( $m[1] . 'T' . substr( $m[2], 0, 2 ) . ':' . substr( $m[2], 2, 2 ) . ':' . substr( $m[2], 4, 2 ) . 'Z' );
			if ( $ts ) {
				return wp_date( 'd/m/Y H:i', $ts );
			}
		}
		return $nombre;
	}

	/* ----------------------------------------------------------------- */
	/* Integridad                                                        */
	/* ----------------------------------------------------------------- */

	/**
	 * Guarda el SHA-256 actual de todos los archivos como línea base.
	 */
	public static function registrar_linea_base() {
		$hashes = array();
		foreach ( array_keys( self::registro() ) as $clave ) {
			$hashes[ $clave ] = self::hash_actual( $clave );
		}
		update_option( self::OPT_LINEA_BASE, $hashes, false );
	}

	/**
	 * Actualiza la línea base de un solo archivo tras guardarlo.
	 *
	 * @param string $clave Clave del registro.
	 */
	private static function actualizar_hash( $clave ) {
		$hashes           = get_option( self::OPT_LINEA_BASE, array() );
		$hashes           = is_array( $hashes ) ? $hashes : array();
		$hashes[ $clave ] = self::hash_actual( $clave );
		update_option( self::OPT_LINEA_BASE, $hashes, false );
	}

	/**
	 * SHA-256 del archivo tal y como está ahora en disco.
	 *
	 * @param string $clave Clave del registro.
	 * @return string Hash o '' si no se puede leer.
	 */
	public static function hash_actual( $clave ) {
		$ruta = self::ruta( $clave );
		if ( '' === $ruta || ! is_readable( $ruta ) ) {
			return '';
		}
		$h = hash_file( 'sha256', $ruta );
		return false === $h ? '' : $h;
	}

	/**
	 * Estado de cada archivo: existencia, tamaño, integridad y frescura.
	 *
	 * @return array<string,array>
	 */
	public static function estado() {
		$linea_base = get_option( self::OPT_LINEA_BASE, array() );
		$linea_base = is_array( $linea_base ) ? $linea_base : array();
		$manifiesto = self::hashes_del_manifiesto();

		$salida = array();
		foreach ( self::registro() as $clave => $meta ) {
			$ruta   = self::dir() . $meta['archivo'];
			$existe = is_readable( $ruta );
			$hash   = $existe ? self::hash_actual( $clave ) : '';

			$fila = array(
				'clave'       => $clave,
				'archivo'     => $meta['archivo'],
				'titulo'      => $meta['titulo'],
				'descripcion' => $meta['descripcion'],
				'grupo'       => $meta['grupo'],
				'existe'      => $existe,
				'escribible'  => $existe && is_writable( $ruta ),
				'bytes'       => $existe ? (int) filesize( $ruta ) : 0,
				'modificado'  => $existe ? (int) filemtime( $ruta ) : 0,
				'hash'        => $hash,
				'respaldos'   => count( self::respaldos( $clave ) ),
				'estado'      => 'ok',
				'nota'        => '',
			);

			if ( ! $existe ) {
				$fila['estado'] = 'falta';
				$fila['nota']   = 'El archivo no está en /data. Los componentes que dependen de él no mostrarán datos.';
				$salida[ $clave ] = $fila;
				continue;
			}

			// JSON legible.
			$val = self::validar( $clave, (string) file_get_contents( $ruta ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( ! $val['ok'] ) {
				$fila['estado'] = 'error';
				$fila['nota']   = implode( ' ', $val['errores'] );
				$salida[ $clave ] = $fila;
				continue;
			}
			if ( $val['avisos'] ) {
				$fila['estado'] = 'aviso';
				$fila['nota']   = implode( ' ', $val['avisos'] );
			}

			// Cambio fuera del panel: el hash difiere de la línea base.
			$base = isset( $linea_base[ $clave ] ) ? $linea_base[ $clave ] : '';
			if ( $base && $hash && ! hash_equals( $base, $hash ) ) {
				$fila['estado'] = 'aviso' === $fila['estado'] ? 'aviso' : 'modificado';
				$fila['nota']  .= ' El archivo cambió fuera del panel (despliegue, FTP o edición directa).';
			}

			// Contraste con el manifiesto del conjunto, cuando lo declara.
			if ( isset( $manifiesto[ $meta['archivo'] ] ) && $hash ) {
				$declarado = $manifiesto[ $meta['archivo'] ];
				if ( ! hash_equals( $declarado, substr( $hash, 0, strlen( $declarado ) ) ) ) {
					$fila['nota'] .= ' El SHA-256 no coincide con el declarado en 00_manifiesto.json: actualice el manifiesto si el cambio es intencionado.';
					if ( 'ok' === $fila['estado'] ) {
						$fila['estado'] = 'aviso';
					}
				}
			}

			$fila['nota'] = trim( $fila['nota'] );
			$salida[ $clave ] = $fila;
		}
		return $salida;
	}

	/**
	 * SHA-256 abreviados que el manifiesto declara para cada archivo.
	 *
	 * @return array<string,string>
	 */
	private static function hashes_del_manifiesto() {
		$m = self::leer( 'manifiesto' );
		$h = array();
		foreach ( (array) ( isset( $m['archivos'] ) ? $m['archivos'] : array() ) as $f ) {
			if ( ! empty( $f['archivo'] ) && ! empty( $f['sha256_16'] ) ) {
				$h[ $f['archivo'] ] = (string) $f['sha256_16'];
			}
		}
		return $h;
	}

	/**
	 * Reescribe 00_manifiesto.json con los tamaños y hashes reales.
	 *
	 * Cierra el ciclo del módulo de actualización: tras editar cualquier
	 * archivo, el manifiesto vuelve a describir con exactitud el conjunto.
	 *
	 * @return array{ok:bool,mensaje:string}
	 */
	public static function recalcular_manifiesto() {
		$m = self::leer( 'manifiesto', true );
		if ( ! $m || ! isset( $m['archivos'] ) || ! is_array( $m['archivos'] ) ) {
			return array(
				'ok'      => false,
				'mensaje' => 'No se pudo leer 00_manifiesto.json o no contiene la lista «archivos».',
			);
		}

		$cambios = 0;
		foreach ( $m['archivos'] as $i => $f ) {
			if ( empty( $f['archivo'] ) ) {
				continue;
			}
			$clave = self::clave_de( $f['archivo'] );
			if ( '' === $clave ) {
				continue;
			}
			$ruta = self::dir() . $f['archivo'];
			if ( ! is_readable( $ruta ) ) {
				continue;
			}

			$bytes = (int) filesize( $ruta );
			$hash  = substr( (string) hash_file( 'sha256', $ruta ), 0, 16 );

			if ( ( isset( $f['bytes'] ) ? (int) $f['bytes'] : -1 ) !== $bytes || ( isset( $f['sha256_16'] ) ? $f['sha256_16'] : '' ) !== $hash ) {
				$cambios++;
			}
			$m['archivos'][ $i ]['bytes']     = $bytes;
			$m['archivos'][ $i ]['sha256_16'] = $hash;
		}

		if ( isset( $m['_meta'] ) && is_array( $m['_meta'] ) ) {
			$m['_meta']['fecha_generacion'] = wp_date( 'Y-m-d' );
		}

		$json = wp_json_encode( $m, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			return array(
				'ok'      => false,
				'mensaje' => 'No se pudo serializar el manifiesto.',
			);
		}

		$res = self::guardar( 'manifiesto', $json );
		if ( ! $res['ok'] ) {
			return array(
				'ok'      => false,
				'mensaje' => $res['mensaje'],
			);
		}

		return array(
			'ok'      => true,
			'mensaje' => $cambios
				? sprintf( 'Manifiesto actualizado: %d archivo(s) tenían tamaño o hash desactualizado.', $cambios )
				: 'Manifiesto verificado: todos los tamaños y hashes ya estaban al día.',
		);
	}

	/* ----------------------------------------------------------------- */
	/* Utilidades                                                        */
	/* ----------------------------------------------------------------- */

	/**
	 * Convierte la indentación de 4 espacios de json_encode a 2 espacios.
	 *
	 * Mantiene el formato con el que se generaron los archivos originales,
	 * de modo que un `git diff` tras editar en el panel muestre solo el
	 * cambio real y no la reindentación completa del archivo.
	 *
	 * @param string $json JSON con JSON_PRETTY_PRINT.
	 * @return string
	 */
	private static function indentar_dos_espacios( $json ) {
		return preg_replace_callback(
			'/^(?: {4})+/m',
			static function ( $m ) {
				return str_repeat( ' ', strlen( $m[0] ) / 2 );
			},
			$json
		);
	}
}
