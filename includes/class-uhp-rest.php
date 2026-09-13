<?php
/**
 * API REST interna del plugin: /wp-json/urkunina/v1/…
 *
 * Todos los endpoints son públicos y de solo lectura: sirven datos agregados
 * de salud pública que ya son de divulgación institucional, sin ninguna
 * información personal identificable. Por eso `permission_callback` devuelve
 * `true` de forma deliberada y explícita, y la protección se apoya en:
 *
 *  - rate-limiting por IP (UHP_Security::rate_limit),
 *  - validación de todos los parámetros contra listas blancas,
 *  - ausencia total de escritura: ninguna ruta muta estado.
 *
 * No se exige nonce a propósito: con caché de página, un nonce caducado
 * serviría 403 a visitantes legítimos.
 *
 * @package Urkunina5000
 */

namespace GobernacionNarino\Urkunina;

defined( 'ABSPATH' ) || exit;

final class UHP_Rest {

	/** Espacio de nombres de la API. */
	const NS = 'urkunina/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'registrar_rutas' ) );
	}

	/**
	 * Registra todas las rutas del plugin.
	 */
	public function registrar_rutas() {
		// Endpoints públicos de solo lectura. Ver nota de cabecera.
		$publico = '__return_true';

		register_rest_route(
			self::NS,
			'/vistas',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'ruta_vistas' ),
				'permission_callback' => $publico,
			)
		);

		register_rest_route(
			self::NS,
			'/render',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'ruta_render' ),
				'permission_callback' => $publico,
				'args'                => array(
					'view' => array(
						'required'          => true,
						'sanitize_callback' => array( UHP_Security::class, 'clave' ),
					),
					'type' => array(
						'required'          => false,
						'sanitize_callback' => array( UHP_Security::class, 'clave' ),
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/kpi',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'ruta_kpi' ),
				'permission_callback' => $publico,
			)
		);

		register_rest_route(
			self::NS,
			'/mapa',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'ruta_mapa' ),
				'permission_callback' => $publico,
				'args'                => array(
					'indicador' => array(
						'required'          => false,
						'sanitize_callback' => array( UHP_Security::class, 'clave' ),
					),
					'nivel'     => array(
						'required'          => false,
						'sanitize_callback' => array( UHP_Security::class, 'clave' ),
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/geo',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'ruta_geo' ),
				'permission_callback' => $publico,
				'args'                => array(
					'nivel' => array(
						'required'          => false,
						'sanitize_callback' => array( UHP_Security::class, 'clave' ),
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/topojson',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'ruta_topojson' ),
				'permission_callback' => $publico,
				'args'                => array(
					'nivel' => array(
						'required'          => false,
						'sanitize_callback' => array( UHP_Security::class, 'clave' ),
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/tablero',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'ruta_tablero' ),
				'permission_callback' => $publico,
			)
		);

		register_rest_route(
			self::NS,
			'/geomapa',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'ruta_geomapa' ),
				'permission_callback' => $publico,
				'args'                => array(
					'indicador' => array(
						'required'          => false,
						'sanitize_callback' => array( UHP_Security::class, 'clave' ),
					),
					'view'      => array(
						'required'          => false,
						'sanitize_callback' => array( UHP_Security::class, 'clave' ),
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/territorio',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'ruta_territorio' ),
				'permission_callback' => $publico,
				'args'                => array(
					'nivel' => array(
						'required'          => false,
						'sanitize_callback' => array( UHP_Security::class, 'clave' ),
					),
					'id'    => array(
						'required'          => false,
						'sanitize_callback' => array( UHP_Security::class, 'clave' ),
					),
				),
			)
		);

		// Datos abiertos: sirve cada archivo del conjunto tal cual.
		register_rest_route(
			self::NS,
			'/abierto/(?P<clave>[a-z0-9_\-]+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'ruta_abierto' ),
				'permission_callback' => $publico,
				'args'                => array(
					'clave' => array(
						'required'          => true,
						'sanitize_callback' => array( UHP_Security::class, 'clave' ),
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/abierto',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'ruta_abierto_indice' ),
				'permission_callback' => $publico,
			)
		);
	}

	/* ----------------------------------------------------------------- */
	/* Rutas                                                             */
	/* ----------------------------------------------------------------- */

	/**
	 * GET /vistas — catálogo de vistas disponibles.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function ruta_vistas() {
		$limite = self::limitar( 'vistas' );
		if ( $limite ) {
			return $limite;
		}
		return self::respuesta(
			array(
				'vistas' => UHP_Views::lista(),
				'tipos'  => UHP_Views::tipos(),
			)
		);
	}

	/**
	 * GET /render — payload de una vista listo para el renderer D3plus.
	 *
	 * @param \WP_REST_Request $req Petición.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function ruta_render( $req ) {
		$limite = self::limitar( 'render' );
		if ( $limite ) {
			return $limite;
		}

		$id = (string) $req->get_param( 'view' );
		if ( ! UHP_Views::existe( $id ) ) {
			return new \WP_Error(
				'uhp_vista_desconocida',
				'La vista solicitada no existe.',
				array( 'status' => 404 )
			);
		}

		return self::respuesta( self::carga_render( $id, (string) $req->get_param( 'type' ) ) );
	}

	/**
	 * GET /tablero — todo lo que pinta el tablero, en una sola respuesta.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function ruta_tablero() {
		$limite = self::limitar( 'tablero' );
		if ( $limite ) {
			return $limite;
		}
		return self::respuesta( self::carga_tablero() );
	}

	/**
	 * Los 64 municipios con su geometría y sus cifras.
	 *
	 * Devuelve un FeatureCollection porque el tablero lo dibuja con d3
	 * directamente, sin capa intermedia. Cada municipio lleva lo justo
	 * para pintarse, filtrarse y explicarse:
	 *
	 *   c     DIVIPOLA          n     nombre
	 *   sub   subregión         zona  roja | amarilla | verde
	 *   int   ¿intervenido?     lpm   prevalencia municipal de LPM
	 *   hp    prevalencia municipal de H. pylori
	 *   casos casos de cáncer detectados
	 *   lat   latitud del centroide    lon  longitud
	 *
	 * `lpm` y `hp` van a null cuando el informe no publica cifra municipal,
	 * que es lo normal: solo se publican los extremos de la distribución.
	 * El tablero cae entonces en la cifra de la subregión y LO DICE. De ahí
	 * que `meta.subregional` viaje en la misma respuesta: sin ella, el mapa
	 * tendría que dejar en gris a cuarenta y tantos municipios sobre los
	 * que sí se sabe algo.
	 *
	 * La geometría sale de UHP_Topojson::features(), la misma autoridad que
	 * alimenta al geomapa de D3plus: los dos mapas no pueden divergir
	 * porque leen el mismo origen.
	 *
	 * Vive aparte de la ruta para que el generador de fixtures produzca
	 * exactamente lo que produce el servidor.
	 *
	 * @return array
	 */
	public static function carga_tablero() {
		$lpm   = self::indice_municipal( 'prev_municipal', 'top10_lesion_precursora_malignidad', 'prevalencia_lpm_porcentaje' );
		$lpm  += self::indice_municipal( 'prev_municipal', 'menor_prevalencia_lesion_precursora_malignidad', 'prevalencia_lpm_porcentaje' );
		$hp    = self::indice_municipal( 'prev_municipal', 'top10_infeccion_h_pylori', 'prevalencia_h_pylori_porcentaje' );
		$casos = self::indice_municipal( 'cancer', 'distribucion_por_municipio', 'casos' );

		$rasgos = array();
		foreach ( UHP_Topojson::features( 'municipio' ) as $f ) {
			$p  = isset( $f['properties'] ) ? $f['properties'] : array();
			$id = isset( $p['divipola'] ) ? (string) $p['divipola'] : '';
			if ( '' === $id ) {
				continue;
			}

			$sub = isset( $p['subregion'] ) ? (string) $p['subregion'] : '';

			$rasgos[] = array(
				'type'       => 'Feature',
				'properties' => array(
					'c'     => $id,
					// El rótulo es el de los informes cuando lo hay —«Colón
					// (Génova)», no «Colón»—; el cruce sigue siendo por código.
					'n'     => UHP_Municipios::nombre_de_lectura( $id, isset( $p['nombre'] ) ? (string) $p['nombre'] : '' ),
					'sub'   => $sub,
					'zona'  => UHP_Subregiones::zona_de( $sub ),
					'int'   => ! empty( $p['priorizado'] ),
					'lpm'   => isset( $lpm[ $id ] ) ? $lpm[ $id ] : null,
					'hp'    => isset( $hp[ $id ] ) ? $hp[ $id ] : null,
					// Un municipio sin casos tiene CERO casos, no un dato
					// que falte: el tamizaje lo cubrió y no encontró
					// ninguno. Por eso 0 y no null.
					'casos' => isset( $casos[ $id ] ) ? (int) $casos[ $id ] : 0,
					'lat'   => isset( $p['lat'] ) ? (float) $p['lat'] : null,
					'lon'   => isset( $p['lon'] ) ? (float) $p['lon'] : null,
				),
				// Reorientada al sentido que espera D3 —exterior horario—,
				// que es el CONTRARIO al del RFC 7946. Sin esto cada
				// municipio se dibuja como el mundo entero menos él mismo
				// y el departamento queda reducido a un punto.
				'geometry'   => isset( $f['geometry'] ) ? UHP_Topojson::orientar( $f['geometry'] ) : null,
			);
		}

		return array(
			'type'     => 'FeatureCollection',
			'meta'     => array(
				'fuente'      => 'Cartografía municipal DANE · datos del proyecto URKUNINA 5000',
				'subregional' => self::prevalencia_subregional(),
				'zonas'       => UHP_Subregiones::fichas_zona(),
			),
			'features' => $rasgos,
		);
	}

	/**
	 * Indexa por DIVIPOLA una lista de municipios con un valor.
	 *
	 * Los informes nombran municipios; la geometría los identifica por
	 * código. UHP_Municipios resuelve el cruce, y un nombre que no cruce se
	 * descarta en silencio AQUÍ pero no pasa desapercibido: hay una prueba
	 * que comprueba que los 55 priorizados cruzan.
	 *
	 * @param string $archivo Clave del archivo en el registro.
	 * @param string $ruta    Ruta de la lista dentro del archivo.
	 * @param string $campo   Campo del que sale el valor.
	 * @return array<string,float>
	 */
	private static function indice_municipal( $archivo, $ruta, $campo ) {
		$salida = array();
		foreach ( (array) UHP_Datos::valor( $archivo, $ruta, array() ) as $fila ) {
			if ( empty( $fila['municipio'] ) || ! isset( $fila[ $campo ] ) ) {
				continue;
			}
			$divipola = UHP_Municipios::divipola_de( $fila['municipio'] );
			if ( '' !== $divipola ) {
				$salida[ $divipola ] = (float) $fila[ $campo ];
			}
		}
		return $salida;
	}

	/**
	 * Prevalencia de LPM y de H. pylori de cada subregión documentada.
	 *
	 * La clave es el nombre OFICIAL de la subregión —el de la división que
	 * entregó la Gobernación—, no el del informe: es el que llevan los
	 * municipios de la geometría, y si no coincidieran el tablero no podría
	 * caer en la cifra subregional de nadie.
	 *
	 * @return array<string,array{lpm:float|null,hp:float|null}>
	 */
	private static function prevalencia_subregional() {
		$salida = array();
		foreach ( (array) UHP_Datos::valor( 'prev_subregion', 'subregiones', array() ) as $s ) {
			if ( empty( $s['subregion'] ) ) {
				continue;
			}
			$codigo = UHP_Subregiones::codigo_de( $s['subregion'] );
			$nombre = ( '' !== $codigo ) ? UHP_Subregiones::nombre_de( $codigo ) : (string) $s['subregion'];

			$salida[ $nombre ] = array(
				'lpm' => isset( $s['prevalencia_lpm_porcentaje'] ) ? (float) $s['prevalencia_lpm_porcentaje'] : null,
				'hp'  => isset( $s['prevalencia_h_pylori_porcentaje'] ) ? (float) $s['prevalencia_h_pylori_porcentaje'] : null,
			);
		}
		return $salida;
	}

	/**
	 * Payload de /render para una vista y un tipo.
	 *
	 * Vive aparte de la ruta para que el generador de fixtures produzca
	 * exactamente lo que produce el servidor: si el generador rehiciera el
	 * payload por su cuenta, las pruebas de navegador validarían contra una
	 * forma que nadie sirve.
	 *
	 * @param string $id   Identificador de la vista (debe existir).
	 * @param string $tipo Tipo pedido; si no es compatible se usa el de la vista.
	 * @return array
	 */
	public static function carga_render( $id, $tipo = '' ) {
		$vista = UHP_Views::obtener( $id );
		$tipos = UHP_Views::tipos();

		// El tipo pedido solo se acepta si la vista lo admite; en caso
		// contrario se cae al tipo por defecto. `mapa` entra aquí solo
		// para las vistas territoriales: lo decide compatibles_de().
		$compatible = UHP_Views::compatibles_de( $id );
		$tipo       = (string) $tipo;
		if ( '' === $tipo || ! in_array( $tipo, $compatible, true ) ) {
			$tipo = UHP_Views::default_tipo( $id );
		}

		$carga = array(
			'chart'      => array(
				'key'   => $tipo,
				'class' => $tipos[ $tipo ]['class'],
				'label' => $tipos[ $tipo ]['label'],
			),
			'view'       => $vista,
			'data'       => $vista['data'],
			'compatible' => $compatible,
		);

		// Una vista territorial dice a qué nivel se dibuja y en qué series
		// se parte, para que el cliente pueda montar el mapa sin tener que
		// deducirlo ni pedir antes /geomapa solo para averiguarlo.
		if ( UHP_Views::es_territorial( $id ) ) {
			$meta         = UHP_Views::meta( $id );
			$carga['geo'] = array(
				'nivel'  => $meta['geo']['nivel'],
				'medida' => $meta['geo']['medida'],
				'series' => UHP_Views::series( $id ),
			);
		}

		return $carga;
	}

	/**
	 * GET /kpi — cifras principales del proyecto.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function ruta_kpi() {
		$limite = self::limitar( 'kpi' );
		if ( $limite ) {
			return $limite;
		}
		return self::respuesta( array( 'kpi' => self::kpis() ) );
	}

	/**
	 * GET /mapa — indicador por municipio, listo para colorear el mapa.
	 *
	 * Devuelve solo la tabla de valores, no la geometría: el GeoJSON pesa
	 * ~350 KB y se pide una sola vez por separado (/geo), de modo que
	 * cambiar de indicador no vuelva a descargarlo.
	 *
	 * @param \WP_REST_Request $req Petición.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function ruta_mapa( $req ) {
		$limite = self::limitar( 'mapa' );
		if ( $limite ) {
			return $limite;
		}

		$indicador = (string) $req->get_param( 'indicador' );
		$catalogo  = self::indicadores_mapa();
		if ( ! isset( $catalogo[ $indicador ] ) ) {
			$indicador = 'lpm';
		}

		// El nivel cambia los valores Y la meta: un mismo indicador no
		// siempre mide lo mismo al cambiar de capa, y anunciarlo con la
		// etiqueta de otro nivel induce a leer mal el mapa.
		$nivel = UHP_Territorios::nivel( (string) $req->get_param( 'nivel' ) );

		return self::respuesta(
			array(
				'indicador'   => $indicador,
				'nivel'       => $nivel,
				'meta'        => UHP_Territorios::meta_por_nivel( $catalogo[ $indicador ], $indicador, $nivel ),
				'indicadores' => $catalogo,
				'valores'     => UHP_Territorios::valores( $indicador, $nivel ),
			)
		);
	}

	/**
	 * GET /geo — geometría de Nariño en el nivel pedido (GeoJSON).
	 *
	 * `nivel` admite municipio (64 polígonos, por defecto), subregion (13)
	 * y departamento (el contorno). Es lo que permite al tablero cambiar de
	 * capa territorial sin cambiar de mapa.
	 *
	 * @param \WP_REST_Request $peticion Petición.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function ruta_geo( $peticion ) {
		$limite = self::limitar( 'geo', 30 );
		if ( $limite ) {
			return $limite;
		}

		$nivel = UHP_Territorios::nivel( (string) $peticion->get_param( 'nivel' ) );
		if ( ! in_array( $nivel, UHP_Topojson::NIVELES_GEO, true ) ) {
			$nivel = 'municipio';
		}

		// La geometría sale de UHP_Topojson::features(), que es la misma
		// fuente de la que se construye la topología de D3plus: los dos
		// mapas del plugin dibujan así el mismo departamento, vértice a
		// vértice, y no pueden divergir con un cambio en uno solo.
		$features = UHP_Topojson::features( $nivel );
		if ( empty( $features ) ) {
			return new \WP_Error(
				'uhp_sin_geometria',
				'No se pudo leer la geometría del nivel solicitado.',
				array( 'status' => 503 )
			);
		}

		return self::respuesta(
			array(
				'type'     => 'FeatureCollection',
				'nivel'    => $nivel,
				'features' => $features,
			)
		);
	}

	/**
	 * GET /territorio — ficha de un territorio para el tablero.
	 *
	 * Devuelve, para cada indicador, no solo la cifra sino en qué
	 * condición está: publicada para ese territorio, sin publicar para él,
	 * agregada de sus municipios o disponible solo para el departamento.
	 * El tablero necesita esa condición para no presentar una cifra
	 * departamental como si fuera local.
	 *
	 * @param \WP_REST_Request $peticion Petición.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function ruta_territorio( $peticion ) {
		$limite = self::limitar( 'territorio' );
		if ( $limite ) {
			return $limite;
		}

		$nivel = UHP_Territorios::nivel( (string) $peticion->get_param( 'nivel' ) );
		$ficha = UHP_Territorios::ficha( $nivel, (string) $peticion->get_param( 'id' ) );

		if ( null === $ficha ) {
			return new \WP_Error(
				'uhp_territorio_desconocido',
				'Ese territorio no existe en el nivel indicado.',
				array( 'status' => 404 )
			);
		}

		return self::respuesta( $ficha );
	}

	/**
	 * GET /topojson — geometría municipal como topología para D3plus Geomap.
	 *
	 * Va por separado de los valores y con la caché más generosa del plugin:
	 * la geometría no cambia nunca, mientras que los valores sí lo hacen cada
	 * vez que alguien edita un JSON. Así, cambiar de indicador en el mapa no
	 * vuelve a descargar los 70 KB del territorio.
	 *
	 * @param \WP_REST_Request $peticion Petición; `nivel` elige municipio o
	 *                                    subregión.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function ruta_topojson( $peticion ) {
		$limite = self::limitar( 'topojson', 30 );
		if ( $limite ) {
			return $limite;
		}

		$topo = UHP_Topojson::topologia( (string) $peticion->get_param( 'nivel' ) );
		if ( empty( $topo['arcs'] ) ) {
			return new \WP_Error(
				'uhp_sin_geometria',
				'No se pudo construir la topología municipal.',
				array( 'status' => 503 )
			);
		}

		return self::respuesta( $topo );
	}

	/**
	 * GET /geomapa — valores por municipio para pintar el mapa de D3plus.
	 *
	 * Admite dos orígenes. Con `view` toma las filas de una vista del
	 * catálogo que nombre municipios; con `indicador`, uno de los cuatro
	 * indicadores del mapa. Devuelve siempre la misma forma, de modo que el
	 * componente no tiene que saber de dónde vino el dato.
	 *
	 * @param \WP_REST_Request $peticion Petición.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function ruta_geomapa( $peticion ) {
		$limite = self::limitar( 'geomapa' );
		if ( $limite ) {
			return $limite;
		}

		$vista = (string) $peticion->get_param( 'view' );
		if ( '' !== $vista && ! UHP_Views::es_territorial( $vista ) ) {
			return new \WP_Error(
				'uhp_vista_no_territorial',
				'Esa vista no nombra un territorio con geometría, de modo que no puede dibujarse sobre el mapa.',
				array( 'status' => 400 )
			);
		}

		return self::respuesta(
			self::carga_geomapa(
				$vista,
				(string) $peticion->get_param( 'indicador' ),
				(string) $peticion->get_param( 'serie' )
			)
		);
	}

	/**
	 * Carga de /geomapa.
	 *
	 * Vive aparte de la ruta y es pública a propósito: el generador de
	 * fixtures de la suite la llama directamente, de modo que lo que
	 * ejercita el navegador en las pruebas es exactamente la misma
	 * respuesta que devuelve WordPress. Duplicar aquí la construcción de la
	 * carga fue lo que dejó pasar dos fallos de dependencias.
	 *
	 * @param string $vista     Vista territorial, o cadena vacía.
	 * @param string $indicador Indicador del mapa; se usa si no hay vista.
	 * @return array<string,mixed>
	 */
	public static function carga_geomapa( $vista = '', $indicador = 'lpm', $serie = '' ) {
		$catalogo = self::indicadores_mapa();

		if ( '' !== $vista && UHP_Views::es_territorial( $vista ) ) {
			$meta   = UHP_Views::meta( $vista );
			$series = UHP_Views::series( $vista );

			// Con la vista partida en series, la elegida forma parte del
			// rótulo: un mapa de «Prevalencia por subregión» sin decir de
			// cuál de los dos indicadores no se puede leer.
			if ( ! empty( $series ) && ( '' === $serie || ! in_array( $serie, $series, true ) ) ) {
				$serie = $series[0];
			}
			$rotulo = ( '' !== $serie ) ? $meta['name'] . ' · ' . $serie : $meta['name'];

			$carga = array(
				'origen'  => 'vista',
				'clave'   => $vista,
				'nivel'   => $meta['geo']['nivel'],
				'serie'   => $serie,
				'series'  => $series,
				'meta'    => array(
					'etiqueta' => $rotulo,
					'corto'    => ( '' !== $serie ) ? $serie : $meta['name'],
					'unidad'   => self::unidad_de( $meta['geo']['medida'] ),
					'nota'     => $meta['description'],
					'fuente'   => isset( $meta['fuente'] ) ? $meta['fuente'] : '',
					// Los indicadores traen su rampa; una vista no, de modo
					// que toma la de lesión precursora, que es la escala
					// cálida con la que se lee el resto del proyecto.
					'escala'   => $catalogo['lpm']['escala'],
				),
				'valores' => self::valores_vista( $vista, $serie ),
			);
		} else {
			if ( ! isset( $catalogo[ $indicador ] ) ) {
				$indicador = 'lpm';
			}
			$carga = array(
				'origen'  => 'indicador',
				'clave'   => $indicador,
				// Los cuatro indicadores del mapa son municipales: se
				// publican municipio a municipio, no por subregión.
				'nivel'   => 'municipio',
				'meta'    => $catalogo[ $indicador ],
				'valores' => self::valores_mapa( $indicador ),
			);
		}

		$carga['indicadores']   = $catalogo;
		$carga['territoriales'] = UHP_Views::territoriales();
		$carga['objeto']        = UHP_Topojson::objeto( $carga['nivel'] );

		return $carga;
	}

	/**
	 * Valores por territorio de una vista territorial.
	 *
	 * La clave del resultado es el identificador que lleva la geometría:
	 * el DIVIPOLA en el nivel municipal y el código de subregión en el
	 * subregional.
	 *
	 * @param string $vista Identificador de la vista.
	 * @param string $serie Serie a dibujar en las vistas partidas en varias.
	 * @return array<string,array{valor:float,nombre:string}>
	 */
	public static function valores_vista( $vista, $serie = '' ) {
		$meta = UHP_Views::meta( $vista );
		if ( empty( $meta['geo'] ) ) {
			return array();
		}

		$campo  = $meta['geo']['campo'];
		$medida = $meta['geo']['medida'];
		$sub    = ( 'subregion' === $meta['geo']['nivel'] );
		$v      = UHP_Views::obtener( $vista );
		$salida = array();

		// Vista partida en series: un territorio no puede tener dos colores,
		// así que se dibuja una sola y las filas de las demás se descartan.
		$campo_serie = isset( $meta['geo']['serie'] ) ? $meta['geo']['serie'] : '';
		if ( '' !== $campo_serie ) {
			$disponibles = UHP_Views::series( $vista );
			if ( '' === $serie || ! in_array( $serie, $disponibles, true ) ) {
				$serie = isset( $disponibles[0] ) ? $disponibles[0] : '';
			}
		}

		foreach ( (array) ( isset( $v['data'] ) ? $v['data'] : array() ) as $fila ) {
			if ( ! isset( $fila[ $campo ], $fila[ $medida ] ) ) {
				continue;
			}
			if ( '' !== $campo_serie && isset( $fila[ $campo_serie ] ) && $fila[ $campo_serie ] !== $serie ) {
				continue;
			}
			if ( $sub ) {
				self::acumular_subregion( $salida, $fila[ $campo ], (float) $fila[ $medida ] );
			} else {
				self::acumular_mapa( $salida, $fila[ $campo ], (float) $fila[ $medida ] );
			}
		}

		return $salida;
	}

	/**
	 * Añade una subregión a la tabla del mapa resolviendo su código.
	 *
	 * @param array  $salida    Tabla acumulada (por referencia).
	 * @param string $subregion Nombre de la subregión, como lo escriban los datos.
	 * @param float  $valor     Valor.
	 */
	private static function acumular_subregion( &$salida, $subregion, $valor ) {
		$codigo = UHP_Subregiones::codigo_de( $subregion );
		if ( '' === $codigo ) {
			return;
		}
		$salida[ $codigo ] = array(
			'valor'  => $valor,
			// El nombre cartográfico, no el del informe: es el que el
			// visitante ve en el mapa y en la ficha del territorio.
			'nombre' => UHP_Subregiones::nombre_de( $codigo ),
		);
	}

	/**
	 * Unidad legible de una medida territorial.
	 *
	 * @param string $medida Nombre de la medida.
	 * @return string
	 */
	private static function unidad_de( $medida ) {
		$unidades = array(
			'prevalencia' => '%',
			'mortalidad'  => 'por 100.000',
			'casos'       => 'casos',
		);
		return isset( $unidades[ $medida ] ) ? $unidades[ $medida ] : '';
	}

	/**
	 * GET /abierto — índice de los archivos del conjunto de datos.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function ruta_abierto_indice() {
		$limite = self::limitar( 'abierto' );
		if ( $limite ) {
			return $limite;
		}

		$salida = array();
		foreach ( UHP_Datos::registro() as $clave => $meta ) {
			$salida[] = array(
				'clave'       => $clave,
				'archivo'     => $meta['archivo'],
				'titulo'      => $meta['titulo'],
				'descripcion' => $meta['descripcion'],
				'url'         => esc_url_raw( rest_url( self::NS . '/abierto/' . $clave ) ),
			);
		}
		return self::respuesta( array( 'archivos' => $salida ) );
	}

	/**
	 * GET /abierto/{clave} — contenido íntegro de un archivo del conjunto.
	 *
	 * @param \WP_REST_Request $req Petición.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function ruta_abierto( $req ) {
		$limite = self::limitar( 'abierto' );
		if ( $limite ) {
			return $limite;
		}

		$clave    = (string) $req->get_param( 'clave' );
		$registro = UHP_Datos::registro();
		if ( ! isset( $registro[ $clave ] ) ) {
			return new \WP_Error(
				'uhp_archivo_desconocido',
				'El archivo solicitado no pertenece al conjunto de datos.',
				array( 'status' => 404 )
			);
		}

		$datos = UHP_Datos::leer( $clave );
		if ( ! $datos ) {
			return new \WP_Error(
				'uhp_archivo_ilegible',
				'El archivo existe en el catálogo pero no se pudo leer.',
				array( 'status' => 503 )
			);
		}

		return self::respuesta( $datos );
	}

	/* ----------------------------------------------------------------- */
	/* Cálculos compartidos                                              */
	/* ----------------------------------------------------------------- */

	/**
	 * Cifras principales del proyecto, para las tarjetas de KPI.
	 *
	 * @return array<int,array>
	 */
	public static function kpis() {
		$hp  = UHP_Datos::valor( 'tamizaje', 'infeccion_h_pylori.positivos.porcentaje', null );
		$lpm = UHP_Datos::valor( 'tamizaje', 'lesion_precursora_malignidad.positivos.porcentaje', null );

		return array(
			array(
				'clave'   => 'participantes',
				'etiqueta' => 'Participantes tamizados',
				'valor'   => (int) UHP_Datos::valor( 'proyecto', 'metas_globales.participantes_captados', 0 ),
				'formato' => 'entero',
				'nota'    => 'Personas entre 30 y 70 años, con endoscopia y biopsia.',
			),
			array(
				'clave'   => 'municipios',
				'etiqueta' => 'Municipios intervenidos',
				'valor'   => (int) UHP_Datos::valor( 'proyecto', 'metas_globales.municipios_intervenidos', 0 ),
				'formato' => 'entero',
				'nota'    => 'De 55 municipios priorizados del área andina.',
			),
			array(
				'clave'   => 'hpylori',
				'etiqueta' => 'Infección por H. pylori',
				'valor'   => null === $hp ? 0 : (float) $hp,
				'formato' => 'porcentaje',
				'nota'    => 'Positivos confirmados por histopatología.',
			),
			array(
				'clave'   => 'lpm',
				'etiqueta' => 'Lesión precursora',
				'valor'   => null === $lpm ? 0 : (float) $lpm,
				'formato' => 'porcentaje',
				'nota'    => 'Atrofia, metaplasia intestinal o displasia.',
			),
			array(
				'clave'   => 'cancer',
				'etiqueta' => 'Cáncer detectado',
				'valor'   => (int) UHP_Datos::valor( 'cancer', 'resumen.casos_detectados', 0 ),
				'formato' => 'entero',
				'nota'    => 'Casos hallados en personas asintomáticas.',
			),
			array(
				'clave'   => 'muestras',
				'etiqueta' => 'Muestras en biobanco',
				'valor'   => (int) UHP_Datos::valor( 'biobanco', 'total_muestras_documentadas_por_tipo', 0 ),
				'formato' => 'entero',
				'nota'    => 'Suma por tipo del inventario documentado.',
			),
		);
	}

	/**
	 * Catálogo de indicadores que pueden colorear el mapa.
	 *
	 * @return array<string,array>
	 */
	public static function indicadores_mapa() {
		return array(
			'lpm'         => array(
				'etiqueta' => 'Lesión precursora de malignidad',
				'unidad'   => '%',
				'corto'    => 'LPM',
				'nota'     => 'Prevalencia entre los participantes del municipio. Solo se publican los diez municipios con mayor prevalencia.',
				'escala'   => array( '#FFF8E1', '#FFD500', '#F08A00', '#D64525', '#8C1D18' ),
			),
			'hpylori'     => array(
				'etiqueta' => 'Infección por Helicobacter pylori',
				'unidad'   => '%',
				'corto'    => 'H. pylori',
				'nota'     => 'Prevalencia entre los participantes del municipio. Solo se publican los diez municipios con mayor prevalencia.',
				'escala'   => array( '#EAF4FF', '#9CC7E8', '#4A90C2', '#1E5F8C', '#0B2E4A' ),
			),
			'cancer'      => array(
				'etiqueta' => 'Casos de cáncer detectados',
				'unidad'   => 'casos',
				'corto'    => 'Cáncer',
				'nota'     => 'Casos hallados por tamizaje endoscópico preventivo en personas asintomáticas.',
				'escala'   => array( '#FDECEA', '#F5B7B1', '#E2453C', '#A93226', '#641E16' ),
			),
			'intervencion' => array(
				'etiqueta' => 'Municipios intervenidos',
				'unidad'   => 'orden',
				'corto'    => 'Cobertura',
				'nota'     => 'Los 55 municipios priorizados, en el orden de intervención en campo registrado por el proyecto.',
				'escala'   => array( '#E8F6ED', '#A5DCBA', '#3FD26E', '#10A13B', '#0B5C24' ),
			),
		);
	}

	/**
	 * Valores por municipio de un indicador del mapa.
	 *
	 * @param string $indicador Clave del indicador.
	 * @return array<string,array{valor:float,nombre:string}>
	 */
	public static function valores_mapa( $indicador ) {
		$salida = array();

		switch ( $indicador ) {
			case 'hpylori':
				foreach ( (array) UHP_Datos::valor( 'prev_municipal', 'top10_infeccion_h_pylori', array() ) as $m ) {
					self::acumular_mapa( $salida, $m['municipio'], (float) $m['prevalencia_h_pylori_porcentaje'] );
				}
				break;

			case 'cancer':
				foreach ( (array) UHP_Datos::valor( 'cancer', 'distribucion_por_municipio', array() ) as $m ) {
					self::acumular_mapa( $salida, $m['municipio'], (float) $m['casos'] );
				}
				break;

			case 'intervencion':
				foreach ( UHP_Municipios::priorizados() as $m ) {
					self::acumular_mapa( $salida, $m['municipio'], (float) $m['orden'] );
				}
				break;

			case 'lpm':
			default:
				foreach ( (array) UHP_Datos::valor( 'prev_municipal', 'top10_lesion_precursora_malignidad', array() ) as $m ) {
					self::acumular_mapa( $salida, $m['municipio'], (float) $m['prevalencia_lpm_porcentaje'] );
				}
				break;
		}

		return $salida;
	}

	/**
	 * Añade un municipio a la tabla del mapa resolviendo su DIVIPOLA.
	 *
	 * @param array  $salida    Tabla acumulada (por referencia).
	 * @param string $municipio Nombre del municipio.
	 * @param float  $valor     Valor del indicador.
	 */
	private static function acumular_mapa( &$salida, $municipio, $valor ) {
		$divipola = UHP_Municipios::divipola_de( $municipio );
		if ( '' === $divipola ) {
			return;
		}
		$salida[ $divipola ] = array(
			'valor'  => $valor,
			'nombre' => (string) $municipio,
		);
	}

	/* ----------------------------------------------------------------- */
	/* Utilidades                                                        */
	/* ----------------------------------------------------------------- */

	/**
	 * Aplica el rate-limit y devuelve el error si se excedió.
	 *
	 * @param string $recurso Nombre del recurso.
	 * @param int    $max     Peticiones por minuto permitidas.
	 * @return \WP_Error|null
	 */
	private static function limitar( $recurso, $max = 90 ) {
		if ( UHP_Security::rate_limit( 'rest_' . $recurso, $max, 60 ) ) {
			return null;
		}
		return new \WP_Error(
			'uhp_rate_limit',
			'Demasiadas peticiones. Espere un momento y vuelva a intentarlo.',
			array( 'status' => 429 )
		);
	}

	/**
	 * Envuelve la carga en una respuesta con cabeceras de caché.
	 *
	 * Los datos cambian solo cuando un administrador edita un JSON, de modo
	 * que una caché intermedia generosa es segura y descarga el servidor.
	 *
	 * @param mixed $carga Datos a devolver.
	 * @return \WP_REST_Response
	 */
	private static function respuesta( $carga ) {
		$r = new \WP_REST_Response( $carga, 200 );
		$r->header( 'Cache-Control', 'public, max-age=900, stale-while-revalidate=3600' );
		return $r;
	}
}
