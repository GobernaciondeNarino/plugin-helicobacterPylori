<?php
/**
 * Índice de las subregiones de Nariño y cruce con los nombres del proyecto.
 *
 * El departamento se organiza en trece subregiones. La geometría de cada
 * una —ya disuelta a partir de sus municipios— viene en la capa
 * `subregion` de dep-sub-mun.geojson, junto con la lista de municipios que
 * la componen.
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

		$geo    = UHP_Datos::leer( 'geojson_subregiones' );
		$salida = array();

		foreach ( (array) ( isset( $geo['features'] ) ? $geo['features'] : array() ) as $f ) {
			$p = isset( $f['properties'] ) ? $f['properties'] : array();
			if ( empty( $p['capa'] ) || 'subregion' !== $p['capa'] || empty( $p['codigo'] ) ) {
				continue;
			}

			$codigos = isset( $p['codigos_dane'] ) ? (string) $p['codigos_dane'] : '';
			$salida[ (string) $p['codigo'] ] = array(
				'codigo'     => (string) $p['codigo'],
				'nombre'     => (string) $p['nombre'],
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
				// El propio código también sirve de clave: así una vista
				// puede traer «rio_mayo» en lugar del nombre.
				$porNombre[ self::normalizar( $codigo ) ] = $codigo;
			}
		}

		$clave = self::normalizar( $nombre );
		return isset( $porNombre[ $clave ] ) ? $porNombre[ $clave ] : '';
	}

	/**
	 * Nombre cartográfico de una subregión.
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
			$mapa[ (string) $p['MPIO_CDPMP'] ] = array(
				'codigo' => isset( $p['subregion_codigo'] ) ? (string) $p['subregion_codigo'] : '',
				'nombre' => isset( $p['subregion'] ) ? (string) $p['subregion'] : '',
			);
		}

		return $mapa;
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
