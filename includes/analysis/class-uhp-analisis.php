<?php
/**
 * Generación automática del texto de análisis de cada gráfico.
 *
 * Principio del ecosistema: ningún gráfico se publica solo. Cada vista se
 * acompaña de dos textos generados a partir de las cifras reales, de modo que
 * se actualizan solos cuando cambian los JSON de /data:
 *
 *  - `descriptivo`: qué se está viendo, en lenguaje claro.
 *  - `cuantitativo`: las cifras que sostienen la lectura (máximo, mínimo,
 *    total, promedio, brecha), redactadas en español y formateadas en es-CO.
 *
 * Los textos cualitativos largos, que explican el significado clínico o de
 * política pública y no dependen de las cifras, viven en
 * includes/data/textos-graficos.php.
 *
 * @package Urkunina5000
 */

namespace GobernacionNarino\Urkunina;

defined( 'ABSPATH' ) || exit;

final class UHP_Analisis {

	/**
	 * Construye el análisis automático de una vista.
	 *
	 * @param string $id    Identificador de la vista.
	 * @param array  $meta  Metadatos de la vista.
	 * @param array  $datos Filas de la vista.
	 * @return array{descriptivo:string,cuantitativo:string}
	 */
	public static function para_vista( $id, $meta, $datos ) {
		if ( ! $datos ) {
			return array(
				'descriptivo'  => 'Todavía no hay datos para esta vista. Revise el archivo de origen en URKUNINA 5000 → Datos.',
				'cuantitativo' => '',
			);
		}

		$dim    = isset( $meta['dimensions'][0] ) ? $meta['dimensions'][0] : '';
		$medida = isset( $meta['measures'][0] ) ? $meta['measures'][0] : '';
		$unidad = self::unidad( $id, $medida );

		return array(
			'descriptivo'  => self::descriptivo( $id, $meta, $datos, $dim, $medida, $unidad ),
			'cuantitativo' => self::cuantitativo( $id, $datos, $dim, $medida, $unidad ),
		);
	}

	/* ----------------------------------------------------------------- */
	/* Texto descriptivo                                                 */
	/* ----------------------------------------------------------------- */

	/**
	 * Frase que describe lo que muestra el gráfico y su hallazgo principal.
	 *
	 * @param string $id     Identificador.
	 * @param array  $meta   Metadatos.
	 * @param array  $datos  Filas.
	 * @param string $dim    Dimensión principal.
	 * @param string $medida Medida principal.
	 * @param string $unidad Unidad de la medida.
	 * @return string
	 */
	private static function descriptivo( $id, $meta, $datos, $dim, $medida, $unidad ) {
		$n = count( $datos );

		// Vistas con lectura propia, donde una frase genérica diría poco.
		switch ( $id ) {
			case 'tamizaje_hp':
			case 'tamizaje_lpm':
				$total = self::suma( $datos, $medida );
				$top   = self::extremo( $datos, $medida, true );
				if ( ! $top ) {
					break;
				}
				return sprintf(
					'De las %s personas tamizadas, %s corresponden a la categoría «%s», es decir el %s del total.',
					self::num( $total ),
					self::num( $top[ $medida ] ),
					$top[ $dim ],
					self::pct( $total > 0 ? ( $top[ $medida ] / $total ) * 100 : 0 )
				);

			case 'zonas_riesgo':
				$max = self::extremo( $datos, $medida, true );
				$min = self::extremo( $datos, $medida, false );
				if ( ! $max || ! $min || (float) $min[ $medida ] <= 0 ) {
					break;
				}
				return sprintf(
					'El gráfico contrasta las tres zonas de riesgo del departamento. La %s registra %s casos por 100.000 habitantes y la %s %s: una diferencia de %s veces dentro del mismo territorio.',
					mb_strtolower( $max[ $dim ], 'UTF-8' ),
					self::num( $max[ $medida ] ),
					mb_strtolower( $min[ $dim ], 'UTF-8' ),
					self::num( $min[ $medida ] ),
					self::num( round( $max[ $medida ] / $min[ $medida ], 1 ) )
				);

			case 'metas_mga':
				$cumplidos = 0;
				foreach ( $datos as $f ) {
					if ( isset( $f['avance'] ) && (float) $f['avance'] >= 100 ) {
						$cumplidos++;
					}
				}
				return sprintf(
					'Se representan los %d productos comprometidos en la ficha MGA. %d alcanzaron o superaron el 100 %% de ejecución.',
					$n,
					$cumplidos
				);

			case 'financiacion':
				$total = self::suma( $datos, $medida );
				$top   = self::extremo( $datos, $medida, true );
				if ( ! $top ) {
					break;
				}
				return sprintf(
					'El presupuesto total del proyecto asciende a %s. La fuente principal es «%s», que aporta el %s.',
					self::pesos( $total ),
					$top[ $dim ],
					self::pct( $total > 0 ? ( $top[ $medida ] / $total ) * 100 : 0 )
				);

			case 'prev_subregion':
				return sprintf(
					'El gráfico compara, en cada una de las %d subregiones documentadas, la prevalencia de lesión precursora de malignidad frente a la de infección por Helicobacter pylori. Cada subregión aparece con sus dos barras para poder leer la distancia entre ambos indicadores.',
					(int) ( $n / 2 )
				);
		}

		// Lectura genérica: qué se compara y cuál encabeza.
		$top = self::extremo( $datos, $medida, true );
		if ( ! $top ) {
			return $meta['description'];
		}

		return sprintf(
			'Se comparan %d %s. El valor más alto corresponde a «%s», con %s%s.',
			$n,
			self::plural_dimension( $dim, $n ),
			$top[ $dim ],
			self::num( $top[ $medida ] ),
			$unidad ? ' ' . $unidad : ''
		);
	}

	/* ----------------------------------------------------------------- */
	/* Texto cuantitativo                                                */
	/* ----------------------------------------------------------------- */

	/**
	 * Frase con las cifras de apoyo: total o promedio, extremos y brecha.
	 *
	 * @param string $id     Identificador.
	 * @param array  $datos  Filas.
	 * @param string $dim    Dimensión principal.
	 * @param string $medida Medida principal.
	 * @param string $unidad Unidad de la medida.
	 * @return string
	 */
	private static function cuantitativo( $id, $datos, $dim, $medida, $unidad ) {
		$valores = array();
		foreach ( $datos as $f ) {
			if ( isset( $f[ $medida ] ) && is_numeric( $f[ $medida ] ) ) {
				$valores[] = (float) $f[ $medida ];
			}
		}
		if ( count( $valores ) < 2 ) {
			return '';
		}

		$max = self::extremo( $datos, $medida, true );
		$min = self::extremo( $datos, $medida, false );
		$prom = array_sum( $valores ) / count( $valores );

		$partes = array();

		// Con porcentajes el total no significa nada; con conteos, sí.
		if ( self::es_porcentaje( $medida, $unidad ) ) {
			$partes[] = sprintf( 'Promedio: %s.', self::pct( $prom ) );
			$partes[] = sprintf( 'Máximo: %s (%s).', self::pct( $max[ $medida ] ), $max[ $dim ] );
			$partes[] = sprintf( 'Mínimo: %s (%s).', self::pct( $min[ $medida ] ), $min[ $dim ] );
			$brecha   = (float) $max[ $medida ] - (float) $min[ $medida ];
			$partes[] = sprintf( 'Brecha entre extremos: %s puntos porcentuales.', self::num( round( $brecha, 1 ) ) );
		} else {
			$total = array_sum( $valores );
			if ( 'financiacion' === $id ) {
				$partes[] = sprintf( 'Total: %s.', self::pesos( $total ) );
				$partes[] = sprintf( 'Mayor aporte: %s (%s).', self::pesos( $max[ $medida ] ), $max[ $dim ] );
			} else {
				$partes[] = sprintf( 'Total: %s%s.', self::num( $total ), $unidad ? ' ' . $unidad : '' );
				$partes[] = sprintf( 'Máximo: %s (%s).', self::num( $max[ $medida ] ), $max[ $dim ] );
				$partes[] = sprintf( 'Mínimo: %s (%s).', self::num( $min[ $medida ] ), $min[ $dim ] );
				$partes[] = sprintf( 'Promedio: %s.', self::num( round( $prom, 1 ) ) );
			}
		}

		return implode( ' ', $partes );
	}

	/* ----------------------------------------------------------------- */
	/* Utilidades                                                        */
	/* ----------------------------------------------------------------- */

	/**
	 * Fila con el valor máximo o mínimo de una medida.
	 *
	 * @param array  $datos  Filas.
	 * @param string $medida Campo numérico.
	 * @param bool   $maximo True para el máximo, false para el mínimo.
	 * @return array|null
	 */
	private static function extremo( $datos, $medida, $maximo = true ) {
		$mejor = null;
		foreach ( $datos as $f ) {
			if ( ! isset( $f[ $medida ] ) || ! is_numeric( $f[ $medida ] ) ) {
				continue;
			}
			if ( null === $mejor ) {
				$mejor = $f;
				continue;
			}
			$actual = (float) $f[ $medida ];
			$ref    = (float) $mejor[ $medida ];
			if ( ( $maximo && $actual > $ref ) || ( ! $maximo && $actual < $ref ) ) {
				$mejor = $f;
			}
		}
		return $mejor;
	}

	/**
	 * Suma de una medida.
	 *
	 * @param array  $datos  Filas.
	 * @param string $medida Campo numérico.
	 * @return float
	 */
	private static function suma( $datos, $medida ) {
		$t = 0.0;
		foreach ( $datos as $f ) {
			if ( isset( $f[ $medida ] ) && is_numeric( $f[ $medida ] ) ) {
				$t += (float) $f[ $medida ];
			}
		}
		return $t;
	}

	/**
	 * Unidad legible de la medida de una vista.
	 *
	 * @param string $id     Identificador de la vista.
	 * @param string $medida Nombre de la medida.
	 * @return string
	 */
	private static function unidad( $id, $medida ) {
		$por_vista = array(
			'zonas_riesgo'         => 'casos por 100.000 habitantes',
			'comparacion_nacional' => 'casos por 100.000 habitantes',
			'contraste_municipal'  => 'muertes por 100.000 habitantes',
			'biobanco_tipos'       => 'muestras',
			'cancer_municipios'    => 'casos',
			'cancer_desenlace'     => 'casos',
			'actores_tipo'         => 'entidades',
			'publicaciones_anio'   => 'publicaciones',
			'tamizaje_hp'          => 'personas',
			'tamizaje_lpm'         => 'personas',
			'perfil_genero'        => 'personas',
		);
		if ( isset( $por_vista[ $id ] ) ) {
			return $por_vista[ $id ];
		}
		if ( in_array( $medida, array( 'porcentaje', 'prevalencia', 'avance', 'valor' ), true ) ) {
			return '%';
		}
		return '';
	}

	/**
	 * ¿La medida se expresa en porcentaje?
	 *
	 * @param string $medida Nombre de la medida.
	 * @param string $unidad Unidad calculada.
	 * @return bool
	 */
	private static function es_porcentaje( $medida, $unidad ) {
		return '%' === $unidad || in_array( $medida, array( 'porcentaje', 'prevalencia', 'avance' ), true );
	}

	/**
	 * Plural legible del nombre de la dimensión.
	 *
	 * @param string $dim Nombre del campo.
	 * @param int    $n   Cantidad.
	 * @return string
	 */
	private static function plural_dimension( $dim, $n ) {
		$mapa = array(
			'municipio' => array( 'municipio', 'municipios' ),
			'subregion' => array( 'subregión', 'subregiones' ),
			'zona'      => array( 'zona', 'zonas' ),
			'categoria' => array( 'categoría', 'categorías' ),
			'indicador' => array( 'indicador', 'indicadores' ),
			'tipo'      => array( 'tipo', 'tipos' ),
			'producto'  => array( 'producto', 'productos' ),
			'fuente'    => array( 'fuente', 'fuentes' ),
			'estado'    => array( 'estado', 'estados' ),
			'ambito'    => array( 'ámbito', 'ámbitos' ),
			'anio'      => array( 'año', 'años' ),
			'resultado' => array( 'resultado', 'resultados' ),
		);
		if ( isset( $mapa[ $dim ] ) ) {
			return 1 === $n ? $mapa[ $dim ][0] : $mapa[ $dim ][1];
		}
		return 1 === $n ? 'categoría' : 'categorías';
	}

	/**
	 * Formatea un número en convención colombiana (punto de miles, coma decimal).
	 *
	 * @param float|int $v Valor.
	 * @return string
	 */
	public static function num( $v ) {
		$v = (float) $v;
		$decimales = ( abs( $v - round( $v ) ) < 0.001 ) ? 0 : ( abs( $v ) < 10 ? 2 : 1 );
		return number_format( $v, $decimales, ',', '.' );
	}

	/**
	 * Formatea un porcentaje con el símbolo.
	 *
	 * @param float $v Valor.
	 * @return string
	 */
	public static function pct( $v ) {
		return self::num( round( (float) $v, 1 ) ) . ' %';
	}

	/**
	 * Formatea un valor en pesos colombianos, abreviando las magnitudes grandes.
	 *
	 * @param float $v Valor en COP.
	 * @return string
	 */
	public static function pesos( $v ) {
		$v = (float) $v;
		if ( $v >= 1000000000 ) {
			return '$' . self::num( round( $v / 1000000000, 2 ) ) . ' mil millones';
		}
		if ( $v >= 1000000 ) {
			return '$' . self::num( round( $v / 1000000, 1 ) ) . ' millones';
		}
		return '$' . self::num( $v );
	}
}
