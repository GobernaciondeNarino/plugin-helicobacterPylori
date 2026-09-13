<?php
/**
 * Índice de las subregiones de Nariño y cruce con los nombres del proyecto.
 *
 * El departamento se organiza en trece subregiones. La geometría de cada
 * una —ya disuelta a partir de sus municipios— viene en la capa
 * `subregion` de dep-sub-mun.geojson, junto con la lista de municipios que
 * la componen.
 *
 * DOS FUENTES, CADA UNA PARA LO SUYO:
 *
 *   · `14_subregiones_municipios.json` es la división oficial que entregó
 *     la Gobernación. De ahí salen los NOMBRES con los que se rotulan las
 *     subregiones —«Los Abades», «La Cordillera», «Piedemonte Costero»— y
 *     la composición declarada de cada una.
 *   · `dep-sub-mun.geojson` aporta la GEOMETRÍA y el cruce con los códigos
 *     DIVIPOLA, que el archivo oficial no trae.
 *
 * Las dos coinciden municipio a municipio —verificado, y hay una prueba
 * que lo comprueba en cada ejecución—, de modo que no hay que elegir entre
 * ellas: cada una aporta lo que la otra no tiene. Si algún día dejaran de
 * coincidir, la suite lo dice en vez de que el tablero mezcle en silencio
 * dos divisiones distintas del departamento.
 *
 * El cruce por nombre no es directo: los informes del proyecto escriben
 * «Piedemonte Costero» donde la cartografía dice «Pie de Monte Costero», y
 * «La Sabana» donde dice «Sabana». Se normaliza igual que en
 * UHP_Municipios: sin tildes, sin espacios, sin signos y sin el artículo
 * inicial, de modo que las dos formas caen en la misma clave.
 *
 * @package Urkunina5000
 */

namespace GobernacionNarino\Urkunina;

defined( 'ABSPATH' ) || exit;

final class UHP_Subregiones {

	/** Transient del índice ya construido. */
	const CACHE = 'uhp_subregiones_indice';

	/** @var array<string,array>|null Caché en memoria por petición. */
	private static $memoria = null;

	/**
	 * Índice de subregiones por código.
	 *
	 * @return array<string,array{codigo:string,nombre:string,municipios:string[],n:int,area:float}>
	 */
	public static function indice() {
		if ( null !== self::$memoria ) {
			return self::$memoria;
		}

		$cache = get_transient( self::CACHE );
		if ( is_array( $cache ) && ! empty( $cache ) ) {
			self::$memoria = $cache;
			return $cache;
		}

		$geo      = UHP_Datos::leer( 'geojson_subregiones' );
		$oficiales = self::nombres_oficiales();
		$salida    = array();

		foreach ( (array) ( isset( $geo['features'] ) ? $geo['features'] : array() ) as $f ) {
			$p = isset( $f['properties'] ) ? $f['properties'] : array();
			if ( empty( $p['capa'] ) || 'subregion' !== $p['capa'] || empty( $p['codigo'] ) ) {
				continue;
			}

			$codigos = isset( $p['codigos_dane'] ) ? (string) $p['codigos_dane'] : '';
			$clave   = self::normalizar( (string) $p['nombre'] );

			$salida[ (string) $p['codigo'] ] = array(
				'codigo'     => (string) $p['codigo'],
				// El nombre oficial de la entidad manda sobre el de la
				// cartografía: es el que ve el ciudadano y el que usan los
				// informes del proyecto.
				'nombre'     => isset( $oficiales[ $clave ] ) ? $oficiales[ $clave ] : (string) $p['nombre'],
				'cartografia' => (string) $p['nombre'],
				'n'          => isset( $p['n_municipios'] ) ? (int) $p['n_municipios'] : 0,
				'area'       => isset( $p['area_km2'] ) ? (float) $p['area_km2'] : 0.0,
				'municipios' => array_values(
					array_filter( array_map( 'trim', explode( ',', $codigos ) ) )
				),
			);
		}

		self::$memoria = $salida;
		set_transient( self::CACHE, $salida, DAY_IN_SECONDS );
		return $salida;
	}

	/** Invalida el índice. */
	public static function purgar() {
		self::$memoria = null;
		delete_transient( self::CACHE );
	}

	/**
	 * Nombres oficiales por clave normalizada.
	 *
	 * @return array<string,string>
	 */
	private static function nombres_oficiales() {
		$salida = array();
		foreach ( (array) UHP_Datos::valor( 'subregiones', 'subregiones', array() ) as $s ) {
			if ( ! empty( $s['nombre'] ) ) {
				$salida[ self::normalizar( $s['nombre'] ) ] = (string) $s['nombre'];
			}
		}
		return $salida;
	}

	/**
	 * División declarada por la entidad, tal cual viene del archivo.
	 *
	 * Se expone para poder contrastarla con la geometría: es la prueba de
	 * que las dos fuentes describen el mismo departamento.
	 *
	 * @return array<int,array{nombre:string,municipios:string[]}>
	 */
	public static function declaradas() {
		return (array) UHP_Datos::valor( 'subregiones', 'subregiones', array() );
	}

	/**
	 * ¿Existe la subregión?
	 *
	 * @param string $codigo Código de subregión.
	 * @return bool
	 */
	public static function existe( $codigo ) {
		$i = self::indice();
		return isset( $i[ (string) $codigo ] );
	}

	/**
	 * Código de subregión a partir de su nombre, como lo escriban los datos.
	 *
	 * @param string $nombre Nombre de la subregión.
	 * @return string Código, o cadena vacía si no cruza.
	 */
	public static function codigo_de( $nombre ) {
		static $porNombre = null;

		if ( null === $porNombre ) {
			$porNombre = array();
			foreach ( self::indice() as $codigo => $s ) {
				$porNombre[ self::normalizar( $s['nombre'] ) ] = $codigo;
				// El nombre cartográfico también, por si un dato viene con
				// la grafía del DANE en vez de la de la entidad.
				if ( ! empty( $s['cartografia'] ) ) {
					$porNombre[ self::normalizar( $s['cartografia'] ) ] = $codigo;
				}
				// El propio código también sirve de clave: así una vista
				// puede traer «rio_mayo» en lugar del nombre.
				$porNombre[ self::normalizar( $codigo ) ] = $codigo;
			}
		}

		$clave = self::normalizar( $nombre );
		return isset( $porNombre[ $clave ] ) ? $porNombre[ $clave ] : '';
	}

	/**
	 * Nombre oficial de una subregión.
	 *
	 * @param string $codigo Código.
	 * @return string
	 */
	public static function nombre_de( $codigo ) {
		$i = self::indice();
		return isset( $i[ (string) $codigo ] ) ? $i[ (string) $codigo ]['nombre'] : '';
	}

	/**
	 * Subregión de cada municipio, por DIVIPOLA.
	 *
	 * Sirve para enriquecer el mapa municipal: al pasar por un municipio se
	 * puede decir a qué subregión pertenece sin volver a leer el archivo.
	 *
	 * @return array<string,array{codigo:string,nombre:string}>
	 */
	public static function por_municipio() {
		static $mapa = null;
		if ( null !== $mapa ) {
			return $mapa;
		}

		$geo  = UHP_Datos::leer( 'geojson_subregiones' );
		$mapa = array();

		foreach ( (array) ( isset( $geo['features'] ) ? $geo['features'] : array() ) as $f ) {
			$p = isset( $f['properties'] ) ? $f['properties'] : array();
			if ( empty( $p['capa'] ) || 'municipio' !== $p['capa'] || empty( $p['MPIO_CDPMP'] ) ) {
				continue;
			}
			$codigo = isset( $p['subregion_codigo'] ) ? (string) $p['subregion_codigo'] : '';
			$mapa[ (string) $p['MPIO_CDPMP'] ] = array(
				'codigo' => $codigo,
				// El nombre oficial, no el de la cartografía: es el que
				// acaba en el tooltip del mapa y en la ficha.
				'nombre' => '' !== $codigo ? self::nombre_de( $codigo ) : '',
			);
		}

		return $mapa;
	}

	/**
	 * Zona de riesgo de cada subregión, indexada por su nombre normalizado.
	 *
	 * La zona NO es un dato del tamizaje sino de incidencia histórica, y
	 * NO viene publicada: los documentos describen las tres zonas y nombran
	 * unos pocos territorios de referencia, pero no reparten el
	 * departamento entre ellas. `17_zonas_riesgo_subregion.json` recoge esa
	 * asignación como derivación declarada, con su comprobación contra las
	 * referencias que sí publica la presentación.
	 *
	 * Se indexa por el nombre normalizado para que «La Cordillera» de la
	 * división oficial y «Cordillera» del informe caigan en la misma clave.
	 *
	 * @return array<string,string> nombre normalizado => roja|amarilla|verde
	 */
	public static function zonas() {
		static $mapa = null;
		if ( null !== $mapa ) {
			return $mapa;
		}

		$mapa = array();
		foreach ( (array) UHP_Datos::valor( 'zonas', 'subregiones', array() ) as $fila ) {
			if ( empty( $fila['subregion'] ) || empty( $fila['zona'] ) ) {
				continue;
			}
			$mapa[ self::normalizar( $fila['subregion'] ) ] = (string) $fila['zona'];
		}
		return $mapa;
	}

	/**
	 * Zona de riesgo de una subregión.
	 *
	 * @param string $nombre Nombre de la subregión, en cualquiera de sus formas.
	 * @return string roja|amarilla|verde, o '' si no está asignada.
	 */
	public static function zona_de( $nombre ) {
		$mapa  = self::zonas();
		$clave = self::normalizar( $nombre );
		return isset( $mapa[ $clave ] ) ? $mapa[ $clave ] : '';
	}

	/**
	 * Ficha de cada zona: etiqueta, territorio, incidencia y color.
	 *
	 * @return array<string,array>
	 */
	public static function fichas_zona() {
		static $fichas = null;
		if ( null !== $fichas ) {
			return $fichas;
		}

		$fichas = array();
		foreach ( (array) UHP_Datos::valor( 'zonas', 'zonas', array() ) as $z ) {
			if ( empty( $z['zona'] ) ) {
				continue;
			}
			$fichas[ (string) $z['zona'] ] = array(
				'etiqueta'   => isset( $z['etiqueta'] ) ? (string) $z['etiqueta'] : '',
				'territorio' => isset( $z['territorio'] ) ? (string) $z['territorio'] : '',
				'incidencia' => isset( $z['incidencia_por_100000'] ) ? (float) $z['incidencia_por_100000'] : null,
				'color'      => isset( $z['color'] ) ? (string) $z['color'] : '',
			);
		}
		return $fichas;
	}

	/**
	 * Normaliza un nombre de subregión para compararlo.
	 *
	 * Quita tildes, signos, espacios y el artículo inicial: «La Sabana» y
	 * «Sabana» son la misma, y «Piedemonte Costero» es «Pie de Monte
	 * Costero».
	 *
	 * @param string $nombre Nombre.
	 * @return string
	 */
	public static function normalizar( $nombre ) {
		$n = UHP_Municipios::sin_acentos( (string) $nombre );
		$n = strtolower( $n );
		$n = preg_replace( '/^(la|el|los|las)\s+/', '', $n );
		return preg_replace( '/[^a-z0-9]/', '', $n );
	}
}
