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
			'/dashboard',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'ruta_dashboard' ),
				'permission_callback' => $publico,
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

		$vista = UHP_Views::obtener( $id );
		$tipos = UHP_Views::tipos();

		// El tipo pedido solo se acepta si es compatible con la categoría de
		// la vista; en caso contrario se cae al tipo por defecto.
		$compatible = UHP_Views::compatibles( $vista['category'] );
		$tipo       = (string) $req->get_param( 'type' );
		if ( '' === $tipo || ! in_array( $tipo, $compatible, true ) ) {
			$tipo = UHP_Views::default_tipo( $id );
		}

		return self::respuesta(
			array(
				'chart'      => array(
					'key'   => $tipo,
					'class' => $tipos[ $tipo ]['class'],
					'label' => $tipos[ $tipo ]['label'],
				),
				'view'       => $vista,
				'data'       => $vista['data'],
				'compatible' => $compatible,
			)
		);
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

		return self::respuesta(
			array(
				'indicador'   => $indicador,
				'meta'        => $catalogo[ $indicador ],
				'indicadores' => self::indicadores_mapa(),
				'valores'     => self::valores_mapa( $indicador ),
			)
		);
	}

	/**
	 * GET /geo — geometría municipal de Nariño (GeoJSON).
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function ruta_geo() {
		$limite = self::limitar( 'geo', 30 );
		if ( $limite ) {
			return $limite;
		}

		$geo = UHP_Datos::leer( 'geojson' );
		if ( ! $geo ) {
			return new \WP_Error(
				'uhp_sin_geometria',
				'No se pudo leer la geometría municipal.',
				array( 'status' => 503 )
			);
		}

		// Se adelgaza el GeoJSON: de las ~90 propiedades censales del DANE
		// el mapa solo necesita cuatro, y enviarlas todas multiplicaría por
		// tres el peso de la respuesta.
		$prioritarios = UHP_Municipios::set_priorizados();
		$features     = array();

		foreach ( (array) ( isset( $geo['features'] ) ? $geo['features'] : array() ) as $f ) {
			$p = isset( $f['properties'] ) ? $f['properties'] : array();
			if ( empty( $p['MPIO_CDPMP'] ) ) {
				continue;
			}
			$divipola   = (string) $p['MPIO_CDPMP'];
			$features[] = array(
				'type'       => 'Feature',
				'properties' => array(
					'divipola'   => $divipola,
					'nombre'     => UHP_Municipios::titulo( (string) $p['MPIO_CNMBR'] ),
					'lat'        => isset( $p['LATITUD'] ) ? round( (float) $p['LATITUD'], 5 ) : null,
					'lon'        => isset( $p['LONGITUD'] ) ? round( (float) $p['LONGITUD'], 5 ) : null,
					'priorizado' => isset( $prioritarios[ $divipola ] ),
				),
				'geometry'   => isset( $f['geometry'] ) ? $f['geometry'] : null,
			);
		}

		return self::respuesta(
			array(
				'type'     => 'FeatureCollection',
				'features' => $features,
			)
		);
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
				'Esa vista no nombra municipios, de modo que no puede dibujarse sobre el mapa.',
				array( 'status' => 400 )
			);
		}

		return self::respuesta(
			self::carga_geomapa( $vista, (string) $peticion->get_param( 'indicador' ) )
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
	public static function carga_geomapa( $vista = '', $indicador = 'lpm' ) {
		$catalogo = self::indicadores_mapa();

		if ( '' !== $vista && UHP_Views::es_territorial( $vista ) ) {
			$meta  = UHP_Views::meta( $vista );
			$carga = array(
				'origen'  => 'vista',
				'clave'   => $vista,
				'nivel'   => $meta['geo']['nivel'],
				'meta'    => array(
					'etiqueta' => $meta['name'],
					'corto'    => $meta['name'],
					'unidad'   => self::unidad_de( $meta['geo']['medida'] ),
					'nota'     => $meta['description'],
					'fuente'   => isset( $meta['fuente'] ) ? $meta['fuente'] : '',
					// Los indicadores traen su rampa; una vista no, de modo
					// que toma la de lesión precursora, que es la escala
					// cálida con la que se lee el resto del proyecto.
					'escala'   => $catalogo['lpm']['escala'],
				),
				'valores' => self::valores_vista( $vista ),
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
	 * @return array<string,array{valor:float,nombre:string}>
	 */
	public static function valores_vista( $vista ) {
		$meta = UHP_Views::meta( $vista );
		if ( empty( $meta['geo'] ) ) {
			return array();
		}

		$campo  = $meta['geo']['campo'];
		$medida = $meta['geo']['medida'];
		$sub    = ( 'subregion' === $meta['geo']['nivel'] );
		$v      = UHP_Views::obtener( $vista );
		$salida = array();

		foreach ( (array) ( isset( $v['data'] ) ? $v['data'] : array() ) as $fila ) {
			if ( ! isset( $fila[ $campo ], $fila[ $medida ] ) ) {
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
	 * GET /dashboard — todo lo que el tablero necesita en una sola petición.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function ruta_dashboard() {
		$limite = self::limitar( 'dashboard' );
		if ( $limite ) {
			return $limite;
		}

		return self::respuesta(
			array(
				'kpi'         => self::kpis(),
				'indicadores' => self::indicadores_mapa(),
				'valores'     => self::valores_mapa( 'lpm' ),
				'subregiones' => UHP_Views::obtener( 'prev_subregion' ),
				'zonas'       => UHP_Views::obtener( 'zonas_riesgo' ),
				'proyecto'    => array(
					'nombre'    => UHP_Datos::valor( 'proyecto', 'identificacion.nombre_corto', 'URKUNINA 5000' ),
					'completo'  => UHP_Datos::valor( 'proyecto', 'identificacion.nombre_completo', '' ),
					'bpin'      => UHP_Datos::valor( 'proyecto', 'identificacion.bpin', '' ),
					'estado'    => UHP_Datos::valor( 'proyecto', 'ejecucion.estado', '' ),
					'inicio'    => UHP_Datos::valor( 'proyecto', 'ejecucion.fecha_inicio', '' ),
					'fin_campo' => UHP_Datos::valor( 'proyecto', 'ejecucion.fecha_fin_trabajo_campo', '' ),
				),
			)
		);
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
