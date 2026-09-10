<?php
/**
 * Conversión de la geometría municipal a TopoJSON.
 *
 * D3plus Geomap no consume GeoJSON: llama a `topojson.feature()` sobre la
 * topología, de modo que necesita un objeto Topology con sus `objects` y
 * sus `arcs`. Esta clase construye esa topología a partir del GeoJSON del
 * DANE que ya trae el plugin, sin dependencias externas.
 *
 * La topología que se genera es «degenerada»: cada anillo es su propio
 * arco y no se comparten fronteras entre municipios vecinos. Es TopoJSON
 * válido —el formato no obliga a compartir arcos— y evita tener que
 * implementar la detección de arcos comunes, que es la parte cara del
 * algoritmo. Lo que sí se aprovecha es la cuantización: las coordenadas
 * pasan a enteros sobre una rejilla y se codifican por diferencias, que es
 * de donde sale casi todo el ahorro de peso.
 *
 * @package Urkunina5000
 */

namespace GobernacionNarino\Urkunina;

defined( 'ABSPATH' ) || exit;

final class UHP_Topojson {

	/** Prefijo de los transients donde se guarda cada topología. */
	const CACHE = 'uhp_topojson_';

	/** Vida de la caché (12 horas), la misma que la de los archivos. */
	const CACHE_TTL = 43200;

	/**
	 * Lado de la rejilla de cuantización.
	 *
	 * Con 1e5 pasos sobre un departamento de algo más de 3° de ancho, el
	 * error máximo por punto queda en torno a los 3 metros: inapreciable a
	 * cualquier escala a la que se publique el mapa, y suficiente para
	 * recortar el peso a menos de la mitad.
	 */
	const REJILLA = 100000;

	/** Nombre del objeto dentro de la topología municipal. */
	const OBJETO = 'municipios';

	/** Nombre del objeto dentro de la topología subregional. */
	const OBJETO_SUB = 'subregiones';

	/** Niveles territoriales que se pueden dibujar. */
	const NIVELES = array( 'municipio', 'subregion' );

	/**
	 * Topología de un nivel territorial, lista para D3plus Geomap.
	 *
	 * @param string $nivel  'municipio' o 'subregion'.
	 * @param bool   $fresco Ignora la caché y reconstruye.
	 * @return array<string,mixed> Topology, o array vacío si no hay geometría.
	 */
	public static function topologia( $nivel = 'municipio', $fresco = false ) {
		$nivel = in_array( $nivel, self::NIVELES, true ) ? $nivel : 'municipio';
		$clave = self::CACHE . $nivel;

		if ( ! $fresco ) {
			$cache = get_transient( $clave );
			if ( is_array( $cache ) && ! empty( $cache['arcs'] ) ) {
				return $cache;
			}
		}

		$topo = ( 'subregion' === $nivel ) ? self::de_subregiones() : self::de_municipios();
		if ( ! empty( $topo['arcs'] ) ) {
			set_transient( $clave, $topo, self::CACHE_TTL );
		}
		return $topo;
	}

	/**
	 * Topología municipal.
	 *
	 * @param bool $fresco Ignora la caché.
	 * @return array<string,mixed>
	 */
	public static function municipios( $fresco = false ) {
		return self::topologia( 'municipio', $fresco );
	}

	/**
	 * Topología subregional.
	 *
	 * @param bool $fresco Ignora la caché.
	 * @return array<string,mixed>
	 */
	public static function subregiones( $fresco = false ) {
		return self::topologia( 'subregion', $fresco );
	}

	/**
	 * Nombre del objeto de la topología de un nivel.
	 *
	 * @param string $nivel Nivel territorial.
	 * @return string
	 */
	public static function objeto( $nivel = 'municipio' ) {
		return ( 'subregion' === $nivel ) ? self::OBJETO_SUB : self::OBJETO;
	}

	/** Invalida todas las topologías en caché. */
	public static function purgar() {
		foreach ( self::NIVELES as $nivel ) {
			delete_transient( self::CACHE . $nivel );
		}
	}

	/* ----------------------------------------------------------------- */
	/* Cada nivel                                                        */
	/* ----------------------------------------------------------------- */

	/**
	 * Los 64 municipios, con su subregión de pertenencia.
	 *
	 * @return array<string,mixed>
	 */
	private static function de_municipios() {
		$geo = UHP_Datos::leer( 'geojson' );
		if ( empty( $geo['features'] ) ) {
			return array();
		}

		$prioritarios = UHP_Municipios::set_priorizados();
		$subregiones  = UHP_Subregiones::por_municipio();

		return self::construir(
			$geo['features'],
			self::OBJETO,
			static function ( $p ) use ( $prioritarios, $subregiones ) {
				if ( empty( $p['MPIO_CDPMP'] ) ) {
					return null;
				}
				$divipola = (string) $p['MPIO_CDPMP'];
				$sub      = isset( $subregiones[ $divipola ] ) ? $subregiones[ $divipola ] : array();
				return array(
					'id'         => $divipola,
					'propiedades' => array(
						'divipola'   => $divipola,
						'nombre'     => UHP_Municipios::titulo( (string) $p['MPIO_CNMBR'] ),
						'lat'        => isset( $p['LATITUD'] ) ? round( (float) $p['LATITUD'], 5 ) : null,
						'lon'        => isset( $p['LONGITUD'] ) ? round( (float) $p['LONGITUD'], 5 ) : null,
						'priorizado' => isset( $prioritarios[ $divipola ] ),
						'subregion'  => isset( $sub['nombre'] ) ? $sub['nombre'] : '',
					),
				);
			}
		);
	}

	/**
	 * Las 13 subregiones.
	 *
	 * La geometría NO se toma de la capa `subregion` del archivo aunque esté
	 * ahí: viene de un disuelto sobre cartografía de alta resolución y pesa
	 * casi un megabyte, diez veces lo que puede viajar en una página. Se
	 * reconstruye disolviendo la capa municipal del mismo archivo, que ya
	 * está generalizada, y el resultado son 13 polígonos de unos pocos
	 * kilobytes con exactamente el mismo contorno exterior.
	 *
	 * @return array<string,mixed>
	 */
	private static function de_subregiones() {
		$geo = UHP_Datos::leer( 'geojson_subregiones' );
		if ( empty( $geo['features'] ) ) {
			return array();
		}

		$municipales = array();
		$fichas      = array();

		foreach ( (array) $geo['features'] as $f ) {
			$p = isset( $f['properties'] ) ? $f['properties'] : array();
			if ( empty( $p['capa'] ) ) {
				continue;
			}
			if ( 'subregion' === $p['capa'] && ! empty( $p['codigo'] ) ) {
				$fichas[ (string) $p['codigo'] ] = $p;
			} elseif ( 'municipio' === $p['capa'] && ! empty( $p['subregion_codigo'] ) && ! empty( $f['geometry'] ) ) {
				$municipales[ (string) $p['subregion_codigo'] ][] = $f['geometry'];
			}
		}

		$features = array();
		foreach ( $municipales as $codigo => $geometrias ) {
			$poligonos = self::disolver( $geometrias );
			if ( empty( $poligonos ) || ! isset( $fichas[ $codigo ] ) ) {
				continue;
			}
			$features[] = array(
				'type'       => 'Feature',
				'properties' => $fichas[ $codigo ],
				'geometry'   => array(
					'type'        => 'MultiPolygon',
					'coordinates' => $poligonos,
				),
			);
		}

		return self::construir(
			$features,
			self::OBJETO_SUB,
			static function ( $p ) {
				if ( empty( $p['codigo'] ) ) {
					return null;
				}
				return array(
					'id'          => (string) $p['codigo'],
					'propiedades' => array(
						'codigo'     => (string) $p['codigo'],
						'nombre'     => (string) $p['nombre'],
						'municipios' => isset( $p['n_municipios'] ) ? (int) $p['n_municipios'] : 0,
						'area_km2'   => isset( $p['area_km2'] ) ? (float) $p['area_km2'] : 0.0,
					),
				);
			}
		);
	}

	/**
	 * Une varios polígonos contiguos en su contorno común.
	 *
	 * Método de cancelación de aristas: en una partición limpia del plano
	 * —y la cartografía municipal del DANE lo es— la frontera entre dos
	 * municipios vecinos es la MISMA secuencia de vértices recorrida en
	 * sentidos opuestos. Contando cada arista dirigida y descartando las
	 * que tienen su opuesta, quedan solo las del contorno exterior; luego
	 * se encadenan por sus extremos hasta cerrar cada anillo.
	 *
	 * Es exacto —no aproxima nada— y no necesita aritmética de polígonos.
	 * A cambio depende de que los vértices compartidos sean idénticos: si
	 * dejaran de serlo, no cancelaría ninguna arista y el resultado sería
	 * el conjunto de municipios sin unir, que se ve, no se rompe. La suite
	 * comprueba que sigue disolviendo.
	 *
	 * @param array $geometrias Geometrías Polygon o MultiPolygon contiguas.
	 * @return array<int,array> Lista de polígonos, cada uno con sus anillos.
	 */
	private static function disolver( $geometrias ) {
		/* 1) Todas las aristas dirigidas de todos los anillos. */
		$aristas = array();
		$puntos  = array();

		foreach ( $geometrias as $g ) {
			foreach ( self::anillos_de( $g ) as $anillo ) {
				$n = count( $anillo );
				for ( $i = 0; $i < $n - 1; $i++ ) {
					$a = self::clave_punto( $anillo[ $i ] );
					$b = self::clave_punto( $anillo[ $i + 1 ] );
					if ( $a === $b ) {
						continue;
					}
					$puntos[ $a ] = array( (float) $anillo[ $i ][0], (float) $anillo[ $i ][1] );
					$puntos[ $b ] = array( (float) $anillo[ $i + 1 ][0], (float) $anillo[ $i + 1 ][1] );

					$clave = $a . '>' . $b;
					$aristas[ $clave ] = isset( $aristas[ $clave ] ) ? $aristas[ $clave ] + 1 : 1;
				}
			}
		}

		/* 2) Se descartan las que tienen opuesta: son fronteras internas. */
		$salientes = array();
		foreach ( $aristas as $clave => $veces ) {
			list( $a, $b ) = explode( '>', $clave, 2 );
			$opuesta       = isset( $aristas[ $b . '>' . $a ] ) ? $aristas[ $b . '>' . $a ] : 0;
			for ( $k = 0; $k < $veces - $opuesta; $k++ ) {
				$salientes[ $a ][] = $b;
			}
		}

		/* 3) Se encadenan las aristas que quedan hasta cerrar anillos. */
		$anillos = array();
		while ( ! empty( $salientes ) ) {
			$inicio = key( $salientes );
			$actual = $inicio;
			$camino = array( $inicio );

			// El tope evita un bucle infinito si la geometría llegara sucia.
			$tope = count( $puntos ) * 4;
			while ( $tope-- > 0 && ! empty( $salientes[ $actual ] ) ) {
				$siguiente = array_pop( $salientes[ $actual ] );
				if ( empty( $salientes[ $actual ] ) ) {
					unset( $salientes[ $actual ] );
				}
				$camino[] = $siguiente;
				$actual   = $siguiente;
				if ( $siguiente === $inicio ) {
					break;
				}
			}
			unset( $salientes[ $actual ] );

			if ( count( $camino ) < 4 ) {
				continue;
			}
			if ( $camino[ count( $camino ) - 1 ] !== $camino[0] ) {
				$camino[] = $camino[0];
			}
			$anillos[] = array_map(
				static function ( $c ) use ( $puntos ) {
					return $puntos[ $c ];
				},
				$camino
			);
		}

		/* 4) Anillo exterior o hueco: un anillo contenido en un número impar
		      de anillos es un hueco; el resto abren polígono. */
		$poligonos = array();
		$huecos    = array();
		foreach ( $anillos as $i => $anillo ) {
			$dentro = 0;
			foreach ( $anillos as $j => $otro ) {
				if ( $i !== $j && self::punto_en_anillo( $anillo[0], $otro ) ) {
					$dentro++;
				}
			}
			if ( 0 === $dentro % 2 ) {
				$poligonos[ $i ] = array( $anillo );
			} else {
				$huecos[ $i ] = $anillo;
			}
		}

		foreach ( $huecos as $hueco ) {
			foreach ( $poligonos as $i => $p ) {
				if ( self::punto_en_anillo( $hueco[0], $p[0] ) ) {
					$poligonos[ $i ][] = $hueco;
					break;
				}
			}
		}

		return array_values( $poligonos );
	}

	/**
	 * Anillos de una geometría Polygon o MultiPolygon.
	 *
	 * @param array $g Geometría.
	 * @return array<int,array>
	 */
	private static function anillos_de( $g ) {
		if ( empty( $g['type'] ) || empty( $g['coordinates'] ) ) {
			return array();
		}
		if ( 'Polygon' === $g['type'] ) {
			return $g['coordinates'];
		}
		if ( 'MultiPolygon' === $g['type'] ) {
			$out = array();
			foreach ( $g['coordinates'] as $poligono ) {
				foreach ( $poligono as $anillo ) {
					$out[] = $anillo;
				}
			}
			return $out;
		}
		return array();
	}

	/**
	 * Clave exacta de un punto, para comparar vértices compartidos.
	 *
	 * @param array $p Punto [lon, lat].
	 * @return string
	 */
	private static function clave_punto( $p ) {
		return sprintf( '%.9F|%.9F', (float) $p[0], (float) $p[1] );
	}

	/**
	 * ¿Cae el punto dentro del anillo? (algoritmo del rayo)
	 *
	 * @param array $punto  Punto [lon, lat].
	 * @param array $anillo Anillo de puntos.
	 * @return bool
	 */
	private static function punto_en_anillo( $punto, $anillo ) {
		$dentro = false;
		$x      = (float) $punto[0];
		$y      = (float) $punto[1];
		$n      = count( $anillo );

		for ( $i = 0, $j = $n - 1; $i < $n; $j = $i, $i++ ) {
			$xi = (float) $anillo[ $i ][0];
			$yi = (float) $anillo[ $i ][1];
			$xj = (float) $anillo[ $j ][0];
			$yj = (float) $anillo[ $j ][1];

			if ( ( $yi > $y ) !== ( $yj > $y )
				&& $x < ( $xj - $xi ) * ( $y - $yi ) / ( ( $yj - $yi ) ?: 1e-12 ) + $xi ) {
				$dentro = ! $dentro;
			}
		}

		return $dentro;
	}

	/* ----------------------------------------------------------------- */
	/* Construcción                                                      */
	/* ----------------------------------------------------------------- */

	/**
	 * Convierte una colección de features en una topología.
	 *
	 * @param array    $features Features del GeoJSON original.
	 * @param string   $objeto   Nombre del objeto dentro de la topología.
	 * @param callable $leer     Recibe las propiedades del feature y devuelve
	 *                           {id, propiedades}, o null para descartarlo.
	 * @return array<string,mixed>
	 */
	private static function construir( $features, $objeto, $leer ) {
		// 1) Extensión de todas las coordenadas, para fijar la rejilla.
		$caja = self::caja( $features );
		if ( null === $caja ) {
			return array();
		}
		list( $x0, $y0, $x1, $y1 ) = $caja;

		$paso    = self::REJILLA - 1;
		$escalax = ( $x1 - $x0 ) / $paso;
		$escalay = ( $y1 - $y0 ) / $paso;
		// Un departamento nunca es un punto, pero una escala en cero
		// produciría divisiones por cero al decodificar.
		$escalax = $escalax > 0 ? $escalax : 1e-9;
		$escalay = $escalay > 0 ? $escalay : 1e-9;

		$arcos      = array();
		$geometrias = array();

		foreach ( $features as $f ) {
			$p = isset( $f['properties'] ) ? $f['properties'] : array();
			if ( empty( $f['geometry']['type'] ) ) {
				continue;
			}

			$campos = $leer( $p );
			if ( ! $campos ) {
				continue;
			}

			$tipo   = $f['geometry']['type'];
			$coords = isset( $f['geometry']['coordinates'] ) ? $f['geometry']['coordinates'] : array();

			if ( 'Polygon' === $tipo ) {
				$indices = self::poligono( $coords, $arcos, $x0, $y0, $escalax, $escalay );
			} elseif ( 'MultiPolygon' === $tipo ) {
				$indices = array();
				foreach ( $coords as $poligono ) {
					$uno = self::poligono( $poligono, $arcos, $x0, $y0, $escalax, $escalay );
					if ( ! empty( $uno ) ) {
						$indices[] = $uno;
					}
				}
			} else {
				continue;
			}

			if ( empty( $indices ) ) {
				continue;
			}

			$geometrias[] = array(
				'type'       => $tipo,
				'id'         => $campos['id'],
				'arcs'       => $indices,
				'properties' => $campos['propiedades'],
			);
		}

		return array(
			'type'      => 'Topology',
			'bbox'      => array( $x0, $y0, $x1, $y1 ),
			'transform' => array(
				'scale'     => array( $escalax, $escalay ),
				'translate' => array( $x0, $y0 ),
			),
			'objects'   => array(
				$objeto => array(
					'type'       => 'GeometryCollection',
					'geometries' => $geometrias,
				),
			),
			'arcs'      => $arcos,
		);
	}

	/**
	 * Convierte los anillos de un poligono en arcos y devuelve sus índices.
	 *
	 * @param array $anillos Anillos del poligono; el primero es el exterior.
	 * @param array $arcos   Lista de arcos, por referencia.
	 * @param float $x0      Origen X de la rejilla.
	 * @param float $y0      Origen Y de la rejilla.
	 * @param float $sx      Escala X.
	 * @param float $sy      Escala Y.
	 * @return array<int,array<int,int>> Índices de arco, uno por anillo.
	 */
	private static function poligono( $anillos, &$arcos, $x0, $y0, $sx, $sy ) {
		$salida = array();

		foreach ( $anillos as $i => $anillo ) {
			if ( ! is_array( $anillo ) || count( $anillo ) < 4 ) {
				continue;
			}

			/* Sentido de giro: exterior HORARIO, huecos antihorario.

			   Es el convenio contrario al del RFC 7946 de GeoJSON, y es a
			   propósito. D3 recorta los polígonos sobre la esfera y decide
			   cuál es el interior por el sentido del anillo, con el criterio
			   inverso al del RFC; un anillo al revés no se ve mal: se ve
			   como el mundo entero MENOS el municipio, y basta uno para que
			   el departamento quede reducido a un punto porque el encuadre
			   se calcula sobre esa extensión. Es lo que hacía este mapa
			   antes de forzar el sentido, y es también el convenio con el
			   que vienen los TopoJSON de world-atlas que consume D3.

			   Los datos del DANE vienen de shapefile y no garantizan un
			   sentido concreto, así que se normaliza anillo a anillo en vez
			   de confiar en el origen. */
			$es_horario   = self::area( $anillo ) < 0;
			$debe_horario = ( 0 === $i );   // exterior horario, huecos antihorario.
			if ( $es_horario !== $debe_horario ) {
				$anillo = array_reverse( $anillo );
			}

			$arco = self::arco( $anillo, $x0, $y0, $sx, $sy );
			if ( count( $arco ) < 4 ) {
				continue;
			}

			$arcos[]  = $arco;
			$salida[] = array( count( $arcos ) - 1 );
		}

		return $salida;
	}

	/**
	 * Cuantiza y codifica un anillo por diferencias.
	 *
	 * @param array $anillo Puntos [lon, lat].
	 * @param float $x0     Origen X.
	 * @param float $y0     Origen Y.
	 * @param float $sx     Escala X.
	 * @param float $sy     Escala Y.
	 * @return array<int,array<int,int>>
	 */
	private static function arco( $anillo, $x0, $y0, $sx, $sy ) {
		$salida  = array();
		$px      = 0;
		$py      = 0;
		$primero = true;

		foreach ( $anillo as $punto ) {
			if ( ! isset( $punto[0], $punto[1] ) ) {
				continue;
			}
			$x = (int) round( ( (float) $punto[0] - $x0 ) / $sx );
			$y = (int) round( ( (float) $punto[1] - $y0 ) / $sy );

			// Dos puntos consecutivos que caen en la misma celda de la
			// rejilla son el mismo punto: se descartan para no dejar
			// segmentos de longitud cero.
			if ( ! $primero && $x === $px && $y === $py ) {
				continue;
			}

			$salida[] = array( $x - $px, $y - $py );
			$px       = $x;
			$py       = $y;
			$primero  = false;
		}

		return $salida;
	}

	/**
	 * Área con signo de un anillo (fórmula del cordón de zapato).
	 *
	 * Positiva si el anillo va en sentido antihorario.
	 *
	 * @param array $anillo Puntos [lon, lat].
	 * @return float
	 */
	private static function area( $anillo ) {
		$a = 0.0;
		$n = count( $anillo );

		for ( $i = 0, $j = $n - 1; $i < $n; $j = $i, $i++ ) {
			if ( ! isset( $anillo[ $i ][0], $anillo[ $j ][0] ) ) {
				continue;
			}
			$a += ( (float) $anillo[ $j ][0] * (float) $anillo[ $i ][1] )
				- ( (float) $anillo[ $i ][0] * (float) $anillo[ $j ][1] );
		}

		return $a / 2;
	}

	/**
	 * Extensión de todas las coordenadas de la colección.
	 *
	 * @param array $features Features del GeoJSON.
	 * @return array{0:float,1:float,2:float,3:float}|null
	 */
	private static function caja( $features ) {
		$x0 = INF;
		$y0 = INF;
		$x1 = -INF;
		$y1 = -INF;

		foreach ( $features as $f ) {
			if ( empty( $f['geometry']['coordinates'] ) ) {
				continue;
			}
			self::recorrer(
				$f['geometry']['coordinates'],
				function ( $x, $y ) use ( &$x0, &$y0, &$x1, &$y1 ) {
					$x0 = min( $x0, $x );
					$y0 = min( $y0, $y );
					$x1 = max( $x1, $x );
					$y1 = max( $y1, $y );
				}
			);
		}

		if ( INF === $x0 || -INF === $x1 ) {
			return null;
		}
		return array( $x0, $y0, $x1, $y1 );
	}

	/**
	 * Recorre coordenadas anidadas a cualquier profundidad.
	 *
	 * @param array    $nodo  Coordenadas.
	 * @param callable $visita Recibe (x, y) por cada punto.
	 * @return void
	 */
	private static function recorrer( $nodo, $visita ) {
		if ( ! is_array( $nodo ) || empty( $nodo ) ) {
			return;
		}
		if ( is_numeric( $nodo[0] ) && isset( $nodo[1] ) && is_numeric( $nodo[1] ) ) {
			$visita( (float) $nodo[0], (float) $nodo[1] );
			return;
		}
		foreach ( $nodo as $hijo ) {
			self::recorrer( $hijo, $visita );
		}
	}
}
