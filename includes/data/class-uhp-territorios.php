<?php
/**
 * Índice territorial: qué puede decirse de cada territorio y qué no.
 *
 * El tablero deja seleccionar un territorio —el departamento entero, una
 * de sus 13 subregiones o uno de sus 64 municipios— y todo lo demás se
 * recoloca alrededor de esa selección. Esta clase es la que decide, para
 * cada indicador y cada nivel, cuál de estas cuatro cosas ocurre:
 *
 *   `publicado`       el dato existe para ese territorio y se muestra;
 *   `sin_dato`        el indicador SÍ se publica a ese nivel, pero no para
 *                     ese territorio en concreto —los informes solo
 *                     difunden los diez municipios con mayor prevalencia,
 *                     y 11 de las 13 subregiones—;
 *   `agregado`        no viene dado, pero se puede sumar sin inventar nada
 *                     a partir de sus municipios (casos de cáncer,
 *                     municipios intervenidos);
 *   `departamental`   el proyecto no lo publica por territorio, de modo
 *                     que la cifra que existe es la del departamento y se
 *                     dice así.
 *
 * La distinción no es un adorno. Enseñar el 67,4 % de infección del
 * departamento con «Telembí» seleccionado, sin decir que esa cifra es
 * departamental, es afirmar algo que el proyecto no ha medido. Un tablero
 * público de una entidad no puede hacer eso, y por eso el estado viaja
 * con cada cifra hasta la interfaz.
 *
 * Lo que NO se hace, a propósito:
 *
 *   · Promediar prevalencias de municipios para obtener la de su
 *     subregión. Son porcentajes sobre bases distintas y sin las bases no
 *     hay media ponderada posible; el promedio simple daría un número
 *     verosímil y falso. La subregión usa su valor publicado o nada.
 *   · Repartir los 5.000 participantes o las muestras del biobanco entre
 *     territorios. No están desagregados en ninguna fuente.
 *
 * @package Urkunina5000
 */

namespace GobernacionNarino\Urkunina;

defined( 'ABSPATH' ) || exit;

final class UHP_Territorios {

	/** Niveles, del más grueso al más fino. */
	const NIVELES = array( 'departamento', 'subregion', 'municipio' );

	/** Identificador del departamento; el DANE lo codifica como 52. */
	const DEPARTAMENTO = '52';

	/**
	 * Nivel válido, con caída al departamento.
	 *
	 * @param string $nivel Nivel pedido.
	 * @return string
	 */
	public static function nivel( $nivel ) {
		$nivel = UHP_Security::clave( $nivel );
		return in_array( $nivel, self::NIVELES, true ) ? $nivel : 'departamento';
	}

	/**
	 * Entidades de un nivel, para poblar un selector.
	 *
	 * @param string $nivel Nivel territorial.
	 * @return array<int,array{id:string,nombre:string,padre:string}>
	 */
	public static function entidades( $nivel ) {
		$nivel  = self::nivel( $nivel );
		$salida = array();

		if ( 'departamento' === $nivel ) {
			return array(
				array(
					'id'     => self::DEPARTAMENTO,
					'nombre' => 'Nariño',
					'padre'  => '',
				),
			);
		}

		if ( 'subregion' === $nivel ) {
			foreach ( UHP_Subregiones::indice() as $codigo => $s ) {
				$salida[] = array(
					'id'     => $codigo,
					'nombre' => $s['nombre'],
					'padre'  => self::DEPARTAMENTO,
				);
			}
			return $salida;
		}

		$subregiones = UHP_Subregiones::por_municipio();
		foreach ( UHP_Municipios::indice() as $divipola => $m ) {
			$salida[] = array(
				'id'     => (string) $divipola,
				'nombre' => $m['nombre'],
				'padre'  => isset( $subregiones[ $divipola ]['codigo'] ) ? $subregiones[ $divipola ]['codigo'] : '',
			);
		}
		return $salida;
	}

	/**
	 * ¿Existe ese territorio en ese nivel?
	 *
	 * @param string $nivel Nivel territorial.
	 * @param string $id    Identificador.
	 * @return bool
	 */
	public static function existe( $nivel, $id ) {
		$nivel = self::nivel( $nivel );
		$id    = (string) $id;

		if ( 'departamento' === $nivel ) {
			return self::DEPARTAMENTO === $id;
		}
		if ( 'subregion' === $nivel ) {
			return UHP_Subregiones::existe( $id );
		}
		return UHP_Municipios::existe( $id );
	}

	/**
	 * Ficha completa de un territorio.
	 *
	 * @param string $nivel Nivel territorial.
	 * @param string $id    Identificador; vacío para el departamento.
	 * @return array<string,mixed>|null
	 */
	public static function ficha( $nivel, $id = '' ) {
		$nivel = self::nivel( $nivel );
		$id    = ( 'departamento' === $nivel ) ? self::DEPARTAMENTO : (string) $id;

		if ( ! self::existe( $nivel, $id ) ) {
			return null;
		}

		switch ( $nivel ) {
			case 'municipio':
				return self::ficha_municipio( $id );
			case 'subregion':
				return self::ficha_subregion( $id );
			default:
				return self::ficha_departamento();
		}
	}

	/* ----------------------------------------------------------------- */
	/* Una ficha por nivel                                               */
	/* ----------------------------------------------------------------- */

	/**
	 * El departamento: la cifra de referencia de todo el proyecto.
	 *
	 * @return array<string,mixed>
	 */
	private static function ficha_departamento() {
		$indicadores = array();
		foreach ( UHP_Rest::kpis() as $k ) {
			$indicadores[] = array(
				'clave'    => $k['clave'],
				'etiqueta' => $k['etiqueta'],
				'valor'    => $k['valor'],
				'formato'  => $k['formato'],
				'estado'   => 'publicado',
				'nota'     => $k['nota'],
			);
		}

		return array(
			'nivel'       => 'departamento',
			'id'          => self::DEPARTAMENTO,
			'nombre'      => 'Nariño',
			'padre'       => null,
			'hijos'       => self::entidades( 'subregion' ),
			'indicadores' => $indicadores,
			'vistas'      => self::vistas_de( 'departamento' ),
			'resumen'     => 'Cifras del departamento: las que publica el proyecto para el conjunto de las 5.000 personas tamizadas.',
		);
	}

	/**
	 * Una subregión.
	 *
	 * @param string $codigo Código de subregión.
	 * @return array<string,mixed>
	 */
	private static function ficha_subregion( $codigo ) {
		$indice = UHP_Subregiones::indice();
		$s      = $indice[ $codigo ];
		$hijos  = array();

		$nombres = UHP_Municipios::indice();
		foreach ( $s['municipios'] as $divipola ) {
			$hijos[] = array(
				'id'     => (string) $divipola,
				'nombre' => isset( $nombres[ $divipola ] ) ? $nombres[ $divipola ]['nombre'] : $divipola,
				'padre'  => $codigo,
			);
		}

		// Prevalencias: solo las publicadas para la subregión. NO se
		// promedian las de sus municipios (ver la cabecera del archivo).
		$publicadas = self::prevalencias_subregionales();
		$suyas      = isset( $publicadas[ $codigo ] ) ? $publicadas[ $codigo ] : array();

		// Estas dos sí se pueden sumar sin inventar: son conteos de casos y
		// de municipios, no porcentajes sobre bases distintas.
		$casos       = 0;
		$intervenidos = 0;
		$por_cancer  = UHP_Rest::valores_mapa( 'cancer' );
		$priorizados = UHP_Municipios::set_priorizados();
		foreach ( $s['municipios'] as $divipola ) {
			if ( isset( $por_cancer[ $divipola ] ) ) {
				$casos += (int) $por_cancer[ $divipola ]['valor'];
			}
			if ( isset( $priorizados[ $divipola ] ) ) {
				$intervenidos++;
			}
		}

		$indicadores = array(
			self::indicador(
				'lpm',
				'Lesión precursora',
				isset( $suyas['lpm'] ) ? $suyas['lpm'] : null,
				'porcentaje',
				'Prevalencia publicada para la subregión.',
				'Los informes documentan 11 de las 13 subregiones.'
			),
			self::indicador(
				'hpylori',
				'Infección por H. pylori',
				isset( $suyas['hpylori'] ) ? $suyas['hpylori'] : null,
				'porcentaje',
				'Prevalencia publicada para la subregión.',
				'Los informes documentan 11 de las 13 subregiones.'
			),
			array(
				'clave'    => 'cancer',
				'etiqueta' => 'Cáncer detectado',
				'valor'    => $casos,
				'formato'  => 'entero',
				'estado'   => 'agregado',
				'nota'     => sprintf(
					/* translators: %d: número de municipios de la subregión. */
					'Suma de los casos de sus %d municipios.',
					count( $s['municipios'] )
				),
			),
			array(
				'clave'    => 'municipios',
				'etiqueta' => 'Municipios intervenidos',
				'valor'    => $intervenidos,
				'formato'  => 'entero',
				'estado'   => 'agregado',
				'nota'     => sprintf(
					/* translators: %d: total de municipios de la subregión. */
					'De los %d que componen la subregión.',
					count( $s['municipios'] )
				),
			),
			self::solo_departamental( 'participantes', 'Participantes tamizados' ),
			self::solo_departamental( 'muestras', 'Muestras en biobanco' ),
		);

		return array(
			'nivel'       => 'subregion',
			'id'          => $codigo,
			'nombre'      => $s['nombre'],
			'padre'       => array(
				'nivel'  => 'departamento',
				'id'     => self::DEPARTAMENTO,
				'nombre' => 'Nariño',
			),
			'hijos'       => $hijos,
			'area_km2'    => $s['area'],
			'indicadores' => $indicadores,
			'vistas'      => self::vistas_de( 'subregion' ),
			'resumen'     => sprintf(
				/* translators: 1: nombre de la subregión; 2: número de municipios; 3: área. */
				'%1$s reúne %2$d municipios sobre %3$s km². Las prevalencias son las publicadas para la subregión; los conteos, la suma de sus municipios.',
				$s['nombre'],
				count( $s['municipios'] ),
				number_format_i18n( $s['area'], 0 )
			),
		);
	}

	/**
	 * Un municipio.
	 *
	 * @param string $divipola Código DIVIPOLA.
	 * @return array<string,mixed>
	 */
	private static function ficha_municipio( $divipola ) {
		$m           = UHP_Municipios::por_divipola( $divipola );
		$subregiones = UHP_Subregiones::por_municipio();
		$sub         = isset( $subregiones[ $divipola ] ) ? $subregiones[ $divipola ] : array();

		$lpm     = UHP_Rest::valores_mapa( 'lpm' );
		$hp      = UHP_Rest::valores_mapa( 'hpylori' );
		$cancer  = UHP_Rest::valores_mapa( 'cancer' );
		$orden   = UHP_Rest::valores_mapa( 'intervencion' );

		$indicadores = array(
			self::indicador(
				'lpm',
				'Lesión precursora',
				isset( $lpm[ $divipola ] ) ? $lpm[ $divipola ]['valor'] : null,
				'porcentaje',
				'Prevalencia entre los participantes del municipio.',
				'Solo se publican los diez municipios con mayor prevalencia.'
			),
			self::indicador(
				'hpylori',
				'Infección por H. pylori',
				isset( $hp[ $divipola ] ) ? $hp[ $divipola ]['valor'] : null,
				'porcentaje',
				'Prevalencia entre los participantes del municipio.',
				'Solo se publican los diez municipios con mayor prevalencia.'
			),
			self::indicador(
				'cancer',
				'Cáncer detectado',
				isset( $cancer[ $divipola ] ) ? (int) $cancer[ $divipola ]['valor'] : null,
				'entero',
				'Casos hallados por tamizaje en personas asintomáticas.',
				'Sin casos documentados en este municipio.'
			),
			array(
				'clave'    => 'municipios',
				'etiqueta' => 'Intervención',
				'valor'    => isset( $orden[ $divipola ] ) ? (int) $orden[ $divipola ]['valor'] : null,
				'formato'  => 'entero',
				'estado'   => isset( $orden[ $divipola ] ) ? 'publicado' : 'sin_dato',
				'nota'     => isset( $orden[ $divipola ] )
					? 'Orden de intervención en campo registrado por el proyecto.'
					: 'Este municipio no está entre los 55 priorizados.',
			),
			self::solo_departamental( 'participantes', 'Participantes tamizados' ),
			self::solo_departamental( 'muestras', 'Muestras en biobanco' ),
		);

		return array(
			'nivel'       => 'municipio',
			'id'          => (string) $divipola,
			'nombre'      => $m ? $m['nombre'] : (string) $divipola,
			'padre'       => empty( $sub['codigo'] ) ? null : array(
				'nivel'  => 'subregion',
				'id'     => $sub['codigo'],
				'nombre' => $sub['nombre'],
			),
			'hijos'       => array(),
			'priorizado'  => isset( $orden[ $divipola ] ),
			'indicadores' => $indicadores,
			'vistas'      => self::vistas_de( 'municipio' ),
			'resumen'     => sprintf(
				/* translators: 1: municipio; 2: subregión. */
				'%1$s pertenece a la subregión %2$s. Las prevalencias municipales solo se publican para los diez municipios con mayor valor en cada indicador.',
				$m ? $m['nombre'] : (string) $divipola,
				isset( $sub['nombre'] ) ? $sub['nombre'] : '—'
			),
		);
	}

	/* ----------------------------------------------------------------- */
	/* Valores para colorear el mapa en cada nivel                       */
	/* ----------------------------------------------------------------- */

	/**
	 * Valores de un indicador en un nivel territorial.
	 *
	 * Es lo que colorea el mapa del tablero cuando se cambia de capa. Las
	 * reglas de agregación son las mismas de la ficha y por el mismo
	 * motivo: los conteos se suman, los porcentajes no.
	 *
	 * @param string $indicador lpm, hpylori, cancer o intervencion.
	 * @param string $nivel     Nivel territorial.
	 * @return array<string,array{valor:float,nombre:string,estado:string}>
	 */
	public static function valores( $indicador, $nivel = 'municipio' ) {
		$nivel = self::nivel( $nivel );

		if ( 'municipio' === $nivel ) {
			$salida = array();
			foreach ( UHP_Rest::valores_mapa( $indicador ) as $id => $v ) {
				$salida[ $id ] = array(
					'valor'  => $v['valor'],
					'nombre' => $v['nombre'],
					'estado' => 'publicado',
				);
			}
			return $salida;
		}

		if ( 'subregion' === $nivel ) {
			return self::valores_subregion( $indicador );
		}

		// Departamento: una sola cifra, la del proyecto.
		foreach ( UHP_Rest::kpis() as $k ) {
			if ( $k['clave'] === $indicador || ( 'intervencion' === $indicador && 'municipios' === $k['clave'] ) ) {
				return array(
					self::DEPARTAMENTO => array(
						'valor'  => $k['valor'],
						'nombre' => 'Nariño',
						'estado' => 'publicado',
					),
				);
			}
		}
		return array();
	}

	/**
	 * Valores subregionales de un indicador.
	 *
	 * @param string $indicador Clave del indicador.
	 * @return array<string,array{valor:float,nombre:string,estado:string}>
	 */
	private static function valores_subregion( $indicador ) {
		$indice = UHP_Subregiones::indice();
		$salida = array();

		if ( 'lpm' === $indicador || 'hpylori' === $indicador ) {
			// Publicadas, nunca promediadas: son porcentajes sobre bases
			// distintas y la media simple daría un número falso.
			foreach ( self::prevalencias_subregionales() as $codigo => $p ) {
				if ( ! isset( $indice[ $codigo ] ) || null === $p[ $indicador ] ) {
					continue;
				}
				$salida[ $codigo ] = array(
					'valor'  => (float) $p[ $indicador ],
					'nombre' => $indice[ $codigo ]['nombre'],
					'estado' => 'publicado',
				);
			}
			return $salida;
		}

		// Conteos: estos sí se suman.
		$por_cancer  = UHP_Rest::valores_mapa( 'cancer' );
		$priorizados = UHP_Municipios::set_priorizados();

		foreach ( $indice as $codigo => $s ) {
			$total = 0;
			foreach ( $s['municipios'] as $divipola ) {
				if ( 'cancer' === $indicador && isset( $por_cancer[ $divipola ] ) ) {
					$total += (int) $por_cancer[ $divipola ]['valor'];
				} elseif ( 'intervencion' === $indicador && isset( $priorizados[ $divipola ] ) ) {
					$total++;
				}
			}
			/* El cero se publica, no se esconde. Una subregión sin casos
			   documentados tiene CERO casos —eso es un dato— y pintarla
			   como «sin dato publicado» diría algo distinto y falso: que no
			   se sabe. Se sabe, y es cero. */
			$salida[ $codigo ] = array(
				'valor'  => $total,
				'nombre' => $s['nombre'],
				'estado' => 'agregado',
			);
		}

		return $salida;
	}

	/**
	 * Ajustes del indicador según el nivel.
	 *
	 * Un mismo indicador no siempre mide lo mismo al cambiar de capa: el
	 * orden de intervención de un municipio es un ordinal, y en la
	 * subregión pasa a ser el número de municipios intervenidos. Anunciar
	 * los dos con la misma etiqueta induciría a leer mal el mapa.
	 *
	 * @param array  $meta  Meta del indicador tal como la publica el catálogo.
	 * @param string $clave Clave del indicador.
	 * @param string $nivel Nivel territorial.
	 * @return array
	 */
	public static function meta_por_nivel( $meta, $clave, $nivel ) {
		$nivel = self::nivel( $nivel );
		if ( 'municipio' === $nivel ) {
			return $meta;
		}

		if ( 'subregion' === $nivel ) {
			if ( 'intervencion' === $clave ) {
				// En el municipio es el ORDEN de intervención (un ordinal);
				// en la subregión, CUÁNTOS de sus municipios se
				// intervinieron. Dos cantidades distintas: llamarlas igual
				// haría leer el mapa al revés.
				$meta['etiqueta'] = 'Municipios intervenidos por subregión';
				$meta['corto']    = 'Intervenidos';
				$meta['unidad']   = 'municipios';
				$meta['nota']     = 'Número de municipios priorizados en cada subregión. Es una suma de sus municipios, no un dato publicado por subregión.';
			} elseif ( 'cancer' === $clave ) {
				$meta['nota'] = 'Suma de los casos de los municipios de cada subregión.';
			} else {
				$meta['nota'] = 'Prevalencia publicada por subregión. Los informes documentan 11 de las 13.';
			}
			return $meta;
		}

		$meta['nota'] = 'Cifra del departamento en su conjunto.';
		return $meta;
	}

	/* ----------------------------------------------------------------- */
	/* Utilidades                                                        */
	/* ----------------------------------------------------------------- */

	/**
	 * Indicador con estado según haya o no valor.
	 *
	 * @param string      $clave      Clave.
	 * @param string      $etiqueta   Etiqueta legible.
	 * @param float|int|null $valor   Valor, o null si no se publica.
	 * @param string      $formato    Formato de la cifra.
	 * @param string      $nota_ok    Nota cuando hay valor.
	 * @param string      $nota_sin   Nota cuando no lo hay.
	 * @return array<string,mixed>
	 */
	private static function indicador( $clave, $etiqueta, $valor, $formato, $nota_ok, $nota_sin ) {
		$hay = ( null !== $valor );
		return array(
			'clave'    => $clave,
			'etiqueta' => $etiqueta,
			'valor'    => $hay ? $valor : null,
			'formato'  => $formato,
			'estado'   => $hay ? 'publicado' : 'sin_dato',
			'nota'     => $hay ? $nota_ok : $nota_sin,
		);
	}

	/**
	 * Indicador que el proyecto solo publica para el departamento.
	 *
	 * Se devuelve con su cifra departamental y marcado como tal: mostrarla
	 * sin la marca sería atribuir al territorio un dato que no es suyo.
	 *
	 * @param string $clave    Clave del KPI.
	 * @param string $etiqueta Etiqueta legible.
	 * @return array<string,mixed>
	 */
	private static function solo_departamental( $clave, $etiqueta ) {
		$valor   = null;
		$formato = 'entero';
		foreach ( UHP_Rest::kpis() as $k ) {
			if ( $k['clave'] === $clave ) {
				$valor   = $k['valor'];
				$formato = $k['formato'];
				break;
			}
		}

		return array(
			'clave'    => $clave,
			'etiqueta' => $etiqueta,
			'valor'    => $valor,
			'formato'  => $formato,
			'estado'   => 'departamental',
			'nota'     => 'El proyecto no desagrega esta cifra por territorio: la que se muestra es la del departamento.',
		);
	}

	/**
	 * Prevalencias publicadas por subregión, indexadas por código.
	 *
	 * @return array<string,array{lpm:float,hpylori:float}>
	 */
	private static function prevalencias_subregionales() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}

		$cache = array();
		foreach ( (array) UHP_Datos::valor( 'prev_subregion', 'subregiones', array() ) as $fila ) {
			$codigo = UHP_Subregiones::codigo_de( isset( $fila['subregion'] ) ? $fila['subregion'] : '' );
			if ( '' === $codigo ) {
				continue;
			}
			$cache[ $codigo ] = array(
				'lpm'     => isset( $fila['prevalencia_lpm_porcentaje'] ) ? (float) $fila['prevalencia_lpm_porcentaje'] : null,
				'hpylori' => isset( $fila['prevalencia_h_pylori_porcentaje'] ) ? (float) $fila['prevalencia_h_pylori_porcentaje'] : null,
			);
		}
		return $cache;
	}

	/**
	 * Qué vistas del catálogo pueden hablar de un nivel.
	 *
	 * `propias` son las que traen ese territorio en sus filas y por tanto
	 * pueden resaltarlo; `departamentales` las que solo existen para el
	 * conjunto y se muestran igual, pero advertidas.
	 *
	 * @param string $nivel Nivel territorial.
	 * @return array{propias:array<int,array>,departamentales:int}
	 */
	private static function vistas_de( $nivel ) {
		$propias = array();
		$otras   = 0;

		foreach ( UHP_Views::lista() as $v ) {
			$suyo = UHP_Views::nivel( $v['id'] );

			// `prev_subregion` compara dos indicadores en las once
			// subregiones: no es una vista «territorial» para el geomapa,
			// porque trae dos series, pero sí nombra subregiones y puede
			// resaltar la que se haya seleccionado.
			if ( '' === $suyo && 'prev_subregion' === $v['id'] ) {
				$suyo = 'subregion';
			}

			if ( $suyo === $nivel ) {
				$propias[] = array(
					'id'    => $v['id'],
					'name'  => $v['name'],
					'grupo' => $v['grupo'],
				);
			} elseif ( '' === $suyo ) {
				$otras++;
			}
		}

		return array(
			'propias'         => $propias,
			'departamentales' => $otras,
		);
	}
}
