<?php
/**
 * Directorio de municipios de Nariño y unión con los datos del proyecto.
 *
 * El GeoJSON del DANE nombra los municipios en mayúsculas y sin sufijos
 * («LOS ANDES», «COLÓN», «SANTACRUZ»), mientras los informes del proyecto los
 * escriben en formato de lectura y con el nombre popular entre paréntesis
 * («Los Andes (Sotomayor)», «Colón (Génova)», «Santacruz (Guachavés)»). Esta
 * clase normaliza ambos a una misma clave para poder cruzarlos: se descarta
 * el paréntesis, se quitan los acentos y se pasa a mayúsculas.
 *
 * Con esa regla los 55 municipios priorizados del proyecto cruzan con el
 * GeoJSON sin necesidad de una tabla de alias.
 *
 * @package Urkunina5000
 */

namespace GobernacionNarino\Urkunina;

defined( 'ABSPATH' ) || exit;

final class UHP_Municipios {

	/** Transient con el índice ligero (sin geometría). */
	const CACHE = 'uhp_municipios_indice';

	/** @var array<string,array>|null Índice DIVIPOLA → datos. */
	private static $indice = null;

	/**
	 * Índice de los 64 municipios: DIVIPOLA → nombre, clave normalizada y centro.
	 *
	 * Se construye una sola vez desde el GeoJSON y se cachea sin geometría:
	 * el archivo pesa ~350 KB y no conviene decodificarlo en cada petición.
	 *
	 * @return array<string,array{divipola:string,nombre:string,clave:string,lat:float,lon:float,area:float}>
	 */
	public static function indice() {
		if ( null !== self::$indice ) {
			return self::$indice;
		}

		$cache = get_transient( self::CACHE );
		if ( is_array( $cache ) && $cache ) {
			self::$indice = $cache;
			return $cache;
		}

		$geo   = UHP_Datos::leer( 'geojson' );
		$salida = array();

		foreach ( (array) ( isset( $geo['features'] ) ? $geo['features'] : array() ) as $f ) {
			$p = isset( $f['properties'] ) ? $f['properties'] : array();
			if ( empty( $p['MPIO_CDPMP'] ) || empty( $p['MPIO_CNMBR'] ) ) {
				continue;
			}
			$divipola = (string) $p['MPIO_CDPMP'];
			$salida[ $divipola ] = array(
				'divipola' => $divipola,
				'nombre'   => self::titulo( (string) $p['MPIO_CNMBR'] ),
				'crudo'    => (string) $p['MPIO_CNMBR'],
				'clave'    => self::normalizar( (string) $p['MPIO_CNMBR'] ),
				'lat'      => isset( $p['LATITUD'] ) ? (float) $p['LATITUD'] : 0.0,
				'lon'      => isset( $p['LONGITUD'] ) ? (float) $p['LONGITUD'] : 0.0,
				'area'     => isset( $p['AREA'] ) ? (float) $p['AREA'] : 0.0,
			);
		}

		ksort( $salida );
		self::$indice = $salida;
		set_transient( self::CACHE, $salida, DAY_IN_SECONDS );
		return $salida;
	}

	/** Invalida el índice cacheado (tras actualizar el GeoJSON). */
	public static function purgar() {
		self::$indice = null;
		delete_transient( self::CACHE );
	}

	/**
	 * ¿Existe el código DIVIPOLA?
	 *
	 * @param string $divipola Código de 5 dígitos.
	 * @return bool
	 */
	public static function existe( $divipola ) {
		$i = self::indice();
		return isset( $i[ (string) $divipola ] );
	}

	/**
	 * Busca un municipio por nombre, tolerante a acentos y paréntesis.
	 *
	 * @param string $nombre Nombre en cualquiera de las dos convenciones.
	 * @return array|null Ficha del municipio o null.
	 */
	public static function por_nombre( $nombre ) {
		$clave = self::normalizar( $nombre );
		if ( '' === $clave ) {
			return null;
		}
		foreach ( self::indice() as $mun ) {
			if ( $mun['clave'] === $clave ) {
				return $mun;
			}
		}
		return null;
	}

	/**
	 * DIVIPOLA de un nombre de municipio, o '' si no cruza.
	 *
	 * @param string $nombre Nombre del municipio.
	 * @return string
	 */
	public static function divipola_de( $nombre ) {
		$mun = self::por_nombre( $nombre );
		return $mun ? $mun['divipola'] : '';
	}

	/**
	 * Ficha de un municipio por DIVIPOLA.
	 *
	 * @param string $divipola Código de 5 dígitos.
	 * @return array|null
	 */
	public static function por_divipola( $divipola ) {
		$i = self::indice();
		return isset( $i[ (string) $divipola ] ) ? $i[ (string) $divipola ] : null;
	}

	/**
	 * Los 55 municipios priorizados por el proyecto, con su DIVIPOLA resuelto.
	 *
	 * @return array<int,array{orden:int,municipio:string,divipola:string,intervenido:bool}>
	 */
	public static function priorizados() {
		$cob    = UHP_Datos::leer( 'cobertura' );
		$salida = array();

		foreach ( (array) ( isset( $cob['municipios'] ) ? $cob['municipios'] : array() ) as $m ) {
			if ( empty( $m['municipio'] ) ) {
				continue;
			}
			$salida[] = array(
				'orden'       => isset( $m['orden'] ) ? (int) $m['orden'] : 0,
				'municipio'   => (string) $m['municipio'],
				'divipola'    => self::divipola_de( $m['municipio'] ),
				'intervenido' => ! empty( $m['intervenido'] ),
			);
		}
		return $salida;
	}

	/**
	 * Conjunto de los DIVIPOLA priorizados, para consultas rápidas.
	 *
	 * @return array<string,bool>
	 */
	public static function set_priorizados() {
		static $set = null;
		if ( null !== $set ) {
			return $set;
		}
		$set = array();
		foreach ( self::priorizados() as $m ) {
			if ( '' !== $m['divipola'] ) {
				$set[ $m['divipola'] ] = true;
			}
		}
		return $set;
	}

	/* ----------------------------------------------------------------- */
	/* Normalización                                                     */
	/* ----------------------------------------------------------------- */

	/**
	 * Clave de comparación de un nombre de municipio.
	 *
	 * Descarta el paréntesis con el nombre popular, elimina los diacríticos,
	 * colapsa espacios y pasa a mayúsculas.
	 *
	 * @param string $nombre Nombre crudo.
	 * @return string
	 */
	public static function normalizar( $nombre ) {
		$n = (string) $nombre;
		// «Colón (Génova)» → «Colón».
		$n = preg_replace( '/\([^)]*\)/u', ' ', $n );
		$n = self::sin_acentos( $n );
		$n = preg_replace( '/[^A-Za-z0-9 ]/', ' ', $n );
		$n = preg_replace( '/\s+/', ' ', $n );
		return strtoupper( trim( $n ) );
	}

	/**
	 * Elimina los diacríticos de una cadena UTF-8.
	 *
	 * Se usa una tabla explícita en vez de iconv()//TRANSLIT, cuyo resultado
	 * depende de la configuración regional del servidor.
	 *
	 * @param string $texto Texto de entrada.
	 * @return string
	 */
	public static function sin_acentos( $texto ) {
		$de = array( 'á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ', 'Á', 'É', 'Í', 'Ó', 'Ú', 'Ü', 'Ñ', 'à', 'è', 'ì', 'ò', 'ù', 'À', 'È', 'Ì', 'Ò', 'Ù', 'â', 'ê', 'î', 'ô', 'û', 'Â', 'Ê', 'Î', 'Ô', 'Û' );
		$a  = array( 'a', 'e', 'i', 'o', 'u', 'u', 'n', 'A', 'E', 'I', 'O', 'U', 'U', 'N', 'a', 'e', 'i', 'o', 'u', 'A', 'E', 'I', 'O', 'U', 'a', 'e', 'i', 'o', 'u', 'A', 'E', 'I', 'O', 'U' );
		return str_replace( $de, $a, (string) $texto );
	}

	/**
	 * Convierte «SAN PEDRO DE CARTAGO» en «San Pedro de Cartago».
	 *
	 * @param string $nombre Nombre en mayúsculas.
	 * @return string
	 */
	public static function titulo( $nombre ) {
		$minusculas = array( 'de', 'del', 'la', 'las', 'los', 'y' );
		$palabras   = explode( ' ', mb_strtolower( trim( (string) $nombre ), 'UTF-8' ) );

		foreach ( $palabras as $i => $p ) {
			if ( '' === $p ) {
				continue;
			}
			if ( $i > 0 && in_array( $p, $minusculas, true ) ) {
				continue;
			}
			$palabras[ $i ] = mb_strtoupper( mb_substr( $p, 0, 1, 'UTF-8' ), 'UTF-8' ) . mb_substr( $p, 1, null, 'UTF-8' );
		}
		return implode( ' ', $palabras );
	}
}
