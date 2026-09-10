<?php
/**
 * Registro de vistas del motor de gráficos.
 *
 * Una «vista» describe un conjunto de datos listo para graficar: su categoría
 * (que determina qué tipos de gráfico admite), sus dimensiones (campos
 * categóricos o temporales), sus medidas (campos numéricos) y las filas.
 *
 * El contrato es el mismo del ecosistema de la Secretaría TIC:
 *
 *   { id, name, description, category, dimensions[], measures[], data[] }
 *
 * y el endpoint REST /render lo envuelve en el payload que consume el
 * renderer D3plus:
 *
 *   { chart:{key,class,label}, view:{…}, data:[…], compatible:[…] }
 *
 * Añadir un gráfico nuevo es añadir una entrada a registro() y sus filas a
 * datos(): no hace falta JavaScript nuevo.
 *
 * @package Urkunina5000
 */

namespace GobernacionNarino\Urkunina;

defined( 'ABSPATH' ) || exit;

final class UHP_Views {

	/**
	 * Tipos de gráfico soportados y su clase D3plus.
	 *
	 * @return array<string,array{class:string,label:string}>
	 */
	public static function tipos() {
		return array(
			'bar'          => array(
				'class' => 'BarChart',
				'label' => 'Barras',
			),
			'stacked_bar'  => array(
				'class' => 'BarChart',
				'label' => 'Barras apiladas',
			),
			'line'         => array(
				'class' => 'LinePlot',
				'label' => 'Líneas',
			),
			'area'         => array(
				'class' => 'AreaPlot',
				'label' => 'Área',
			),
			'stacked_area' => array(
				'class' => 'StackedArea',
				'label' => 'Área apilada',
			),
			'pie'          => array(
				'class' => 'Pie',
				'label' => 'Pastel',
			),
			'donut'        => array(
				'class' => 'Donut',
				'label' => 'Dona',
			),
			'treemap'      => array(
				'class' => 'Treemap',
				'label' => 'Treemap',
			),
			'box_whisker'  => array(
				'class' => 'BoxWhisker',
				'label' => 'Caja y bigotes',
			),
		);
	}

	/**
	 * Tipos compatibles con cada categoría de vista.
	 *
	 * @param string $category Categoría de la vista.
	 * @return string[]
	 */
	public static function compatibles( $category ) {
		switch ( $category ) {
			case 'temporal':
				return array( 'line', 'area', 'bar', 'stacked_area' );
			case 'parte_todo':
				return array( 'donut', 'pie', 'bar', 'treemap' );
			case 'ranking':
				return array( 'bar', 'treemap', 'box_whisker' );
			case 'comparativa':
				return array( 'bar', 'stacked_bar', 'line', 'treemap' );
			case 'categorical':
			default:
				return array( 'bar', 'pie', 'donut', 'treemap' );
		}
	}

	/**
	 * Registro de vistas disponibles.
	 *
	 * `heatmap` marca las vistas de magnitud que se colorean por valor (mapa
	 * de calor) en vez de por serie: en un ranking el color debe codificar
	 * la cifra, no la categoría.
	 *
	 * @return array<string,array>
	 */
	private static function registro() {
		static $r = null;
		if ( null !== $r ) {
			return $r;
		}

		$r = array(

			/* ---------- Contexto epidemiológico ---------- */
			'zonas_riesgo'         => array(
				'name'        => 'Incidencia de cáncer gástrico por zona de riesgo',
				'description' => 'Casos por cada 100.000 habitantes en las tres zonas de riesgo de Nariño.',
				'category'    => 'ranking',
				'dimensions'  => array( 'zona' ),
				'measures'    => array( 'incidencia' ),
				'default'     => 'bar',
				'heatmap'     => true,
				'grupo'       => 'Epidemiología',
				'fuente'      => 'Ficha MGA del proyecto · Informe preliminar URKUNINA 5000',
			),
			'comparacion_nacional' => array(
				'name'        => 'Nariño frente a Colombia',
				'description' => 'Tasas de cáncer gástrico por 100.000 habitantes: país, departamento y zona roja.',
				'category'    => 'ranking',
				'dimensions'  => array( 'ambito' ),
				'measures'    => array( 'tasa' ),
				'default'     => 'bar',
				'heatmap'     => true,
				'grupo'       => 'Epidemiología',
				'fuente'      => 'Instituto Nacional de Cancerología · Pardo-Ramos y Cendales-Duarte (2024)',
			),
			'contraste_municipal'  => array(
				'name'        => 'Contraste Cumbal – Barbacoas',
				'description' => 'Mortalidad por cáncer gástrico en dos municipios de zonas y poblaciones distintas.',
				'category'    => 'comparativa',
				'dimensions'  => array( 'municipio' ),
				'measures'    => array( 'mortalidad' ),
				'default'     => 'bar',
				'heatmap'     => true,
				'geo'         => array(
					'nivel'  => 'municipio',
					'campo'  => 'municipio',
					'medida' => 'mortalidad',
				),
				'grupo'       => 'Epidemiología',
				'fuente'      => 'Artículo derivado del proyecto — microbiota gástrica',
			),

			/* ---------- Resultados del tamizaje ---------- */
			'tamizaje_hp'          => array(
				'name'        => 'Infección por Helicobacter pylori',
				'description' => 'Reparto de los 5.000 participantes entre positivos y negativos a la infección.',
				'category'    => 'parte_todo',
				'dimensions'  => array( 'resultado' ),
				'measures'    => array( 'personas' ),
				'default'     => 'donut',
				'grupo'       => 'Tamizaje',
				'fuente'      => 'Endoscopia digestiva alta con biopsia e histopatología',
			),
			'tamizaje_lpm'         => array(
				'name'        => 'Lesión precursora de malignidad',
				'description' => 'Reparto de los 5.000 participantes según presencia de lesión precursora.',
				'category'    => 'parte_todo',
				'dimensions'  => array( 'resultado' ),
				'measures'    => array( 'personas' ),
				'default'     => 'donut',
				'grupo'       => 'Tamizaje',
				'fuente'      => 'Endoscopia digestiva alta con biopsia e histopatología',
			),
			'tamizaje_comparado'   => array(
				'name'        => 'Los dos hallazgos del tamizaje',
				'description' => 'Positivos y negativos de infección y de lesión precursora, uno junto al otro.',
				'category'    => 'comparativa',
				'dimensions'  => array( 'indicador' ),
				'measures'    => array( 'positivos', 'negativos' ),
				'default'     => 'stacked_bar',
				'grupo'       => 'Tamizaje',
				'fuente'      => 'Resultados del tamizaje URKUNINA 5000',
			),

			/* ---------- Prevalencia territorial ---------- */
			'prev_lpm_municipios'  => array(
				'name'        => 'Municipios con mayor lesión precursora',
				'description' => 'Los diez municipios con mayor prevalencia de lesión precursora de malignidad.',
				'category'    => 'ranking',
				'dimensions'  => array( 'municipio' ),
				'measures'    => array( 'prevalencia' ),
				'default'     => 'bar',
				'heatmap'     => true,
				'geo'         => array(
					'nivel'  => 'municipio',
					'campo'  => 'municipio',
					'medida' => 'prevalencia',
				),
				'grupo'       => 'Prevalencia',
				'fuente'      => 'Informe preliminar URKUNINA 5000',
			),
			'prev_hp_municipios'   => array(
				'name'        => 'Municipios con mayor infección por H. pylori',
				'description' => 'Los diez municipios con mayor prevalencia de infección por la bacteria.',
				'category'    => 'ranking',
				'dimensions'  => array( 'municipio' ),
				'measures'    => array( 'prevalencia' ),
				'default'     => 'bar',
				'heatmap'     => true,
				'geo'         => array(
					'nivel'  => 'municipio',
					'campo'  => 'municipio',
					'medida' => 'prevalencia',
				),
				'grupo'       => 'Prevalencia',
				'fuente'      => 'Informe preliminar URKUNINA 5000',
			),
			'prev_subregion'       => array(
				'name'        => 'Prevalencia por subregión',
				'description' => 'Lesión precursora e infección por H. pylori en las once subregiones documentadas.',
				'category'    => 'comparativa',
				'dimensions'  => array( 'subregion', 'indicador' ),
				'measures'    => array( 'valor' ),
				'default'     => 'bar',
								'geo'         => array(
					'nivel'  => 'subregion',
					'campo'  => 'subregion',
					'medida' => 'valor',
					// Esta vista trae DOS indicadores por subregión. Un mapa
					// coroplético solo puede pintar uno, de modo que declara
					// qué dimensión la parte en series y el geomapa obliga a
					// elegir cuál se dibuja en vez de escoger en silencio.
					'serie'  => 'indicador',
				),
'grupo'       => 'Prevalencia',
				'fuente'      => 'Informe preliminar URKUNINA 5000',
			),
			'prev_subregion_lpm'   => array(
				'name'        => 'Lesión precursora por subregión',
				'description' => 'Prevalencia de lesión precursora de malignidad en cada subregión.',
				'category'    => 'ranking',
				'dimensions'  => array( 'subregion' ),
				'measures'    => array( 'prevalencia' ),
				'default'     => 'bar',
				'heatmap'     => true,
				'geo'         => array(
					'nivel'  => 'subregion',
					'campo'  => 'subregion',
					'medida' => 'prevalencia',
				),
				'grupo'       => 'Prevalencia',
				'fuente'      => 'Informe preliminar URKUNINA 5000',
			),
			'prev_subregion_hp'    => array(
				'name'        => 'Infección por H. pylori por subregión',
				'description' => 'Prevalencia de infección por la bacteria en cada subregión.',
				'category'    => 'ranking',
				'dimensions'  => array( 'subregion' ),
				'measures'    => array( 'prevalencia' ),
				'default'     => 'bar',
				'heatmap'     => true,
				'geo'         => array(
					'nivel'  => 'subregion',
					'campo'  => 'subregion',
					'medida' => 'prevalencia',
				),
				'grupo'       => 'Prevalencia',
				'fuente'      => 'Informe preliminar URKUNINA 5000',
			),

			/* ---------- Perfil de la población ---------- */
			'perfil_genero'        => array(
				'name'        => 'Participantes por género',
				'description' => 'Distribución por género de las personas que participaron en el tamizaje.',
				'category'    => 'parte_todo',
				'dimensions'  => array( 'categoria' ),
				'measures'    => array( 'personas' ),
				'default'     => 'donut',
				'grupo'       => 'Población',
				'fuente'      => 'Encuesta sociodemográfica del proyecto (n = 4.994 con dato)',
			),
			'perfil_etnia'         => array(
				'name'        => 'Pertenencia étnica',
				'description' => 'Autorreconocimiento étnico de los participantes.',
				'category'    => 'parte_todo',
				'dimensions'  => array( 'categoria' ),
				'measures'    => array( 'porcentaje' ),
				'default'     => 'donut',
				'grupo'       => 'Población',
				'fuente'      => 'Encuesta sociodemográfica del proyecto',
			),
			'perfil_educacion'     => array(
				'name'        => 'Nivel educativo',
				'description' => 'Último nivel educativo alcanzado por los participantes.',
				'category'    => 'ranking',
				'dimensions'  => array( 'categoria' ),
				'measures'    => array( 'porcentaje' ),
				'default'     => 'bar',
				'grupo'       => 'Población',
				'fuente'      => 'Encuesta sociodemográfica del proyecto',
			),
			'perfil_regimen'       => array(
				'name'        => 'Régimen de afiliación en salud',
				'description' => 'Reparto entre régimen subsidiado y contributivo.',
				'category'    => 'parte_todo',
				'dimensions'  => array( 'categoria' ),
				'measures'    => array( 'porcentaje' ),
				'default'     => 'donut',
				'grupo'       => 'Población',
				'fuente'      => 'Encuesta sociodemográfica del proyecto',
			),
			'perfil_nutricional'   => array(
				'name'        => 'Estado nutricional',
				'description' => 'Clasificación por índice de masa corporal de los participantes.',
				'category'    => 'parte_todo',
				'dimensions'  => array( 'categoria' ),
				'measures'    => array( 'porcentaje' ),
				'default'     => 'bar',
				'grupo'       => 'Población',
				'fuente'      => 'Valoración nutricional del proyecto',
			),
			'perfil_socioeconomico' => array(
				'name'        => 'Indicadores socioeconómicos',
				'description' => 'Condiciones de ingreso, estrato, hacinamiento y acceso a agua potable.',
				'category'    => 'ranking',
				'dimensions'  => array( 'indicador' ),
				'measures'    => array( 'porcentaje' ),
				'default'     => 'bar',
				'grupo'       => 'Población',
				'fuente'      => 'Encuesta sociodemográfica del proyecto',
			),

			/* ---------- Biobanco ---------- */
			'biobanco_tipos'       => array(
				'name'        => 'Muestras del biobanco por tipo',
				'description' => 'Composición del biobanco de material biológico por tipo de muestra.',
				'category'    => 'ranking',
				'dimensions'  => array( 'tipo' ),
				'measures'    => array( 'cantidad' ),
				'default'     => 'treemap',
				'grupo'       => 'Biobanco',
				'fuente'      => 'Inventario del biobanco — Fundación CIEDYN',
			),

			/* ---------- Casos de cáncer ---------- */
			'cancer_municipios'    => array(
				'name'        => 'Casos de cáncer gástrico por municipio',
				'description' => 'Distribución municipal de los ocho casos detectados por tamizaje.',
				'category'    => 'ranking',
				'dimensions'  => array( 'municipio' ),
				'measures'    => array( 'casos' ),
				'default'     => 'bar',
				'heatmap'     => true,
				'geo'         => array(
					'nivel'  => 'municipio',
					'campo'  => 'municipio',
					'medida' => 'casos',
				),
				'grupo'       => 'Casos detectados',
				'fuente'      => 'Seguimiento clínico del proyecto',
			),
			'cancer_desenlace'     => array(
				'name'        => 'Desenlace de los casos detectados',
				'description' => 'Situación de los ocho casos a la fecha del último informe disponible.',
				'category'    => 'parte_todo',
				'dimensions'  => array( 'estado' ),
				'measures'    => array( 'casos' ),
				'default'     => 'donut',
				'grupo'       => 'Casos detectados',
				'fuente'      => 'Seguimiento clínico del proyecto',
			),

			/* ---------- Proyecto y gestión ---------- */
			'financiacion'         => array(
				'name'        => 'Financiación del proyecto',
				'description' => 'Aporte de cada fuente al presupuesto total del proyecto.',
				'category'    => 'parte_todo',
				'dimensions'  => array( 'fuente' ),
				'measures'    => array( 'valor' ),
				'default'     => 'donut',
				'grupo'       => 'Proyecto',
				'fuente'      => 'Ficha MGA · SUIFP — Sistema General de Regalías',
			),
			'metas_mga'            => array(
				'name'        => 'Avance de los productos de la ficha MGA',
				'description' => 'Porcentaje de ejecución de cada uno de los 23 productos comprometidos.',
				'category'    => 'ranking',
				'dimensions'  => array( 'producto' ),
				'measures'    => array( 'avance' ),
				'default'     => 'bar',
				'heatmap'     => true,
				'grupo'       => 'Proyecto',
				'fuente'      => 'Ficha MGA del proyecto',
			),
			'publicaciones_anio'   => array(
				'name'        => 'Producción científica por año',
				'description' => 'Documentos derivados del proyecto publicados cada año.',
				'category'    => 'temporal',
				'dimensions'  => array( 'anio' ),
				'measures'    => array( 'publicaciones' ),
				'default'     => 'bar',
				'grupo'       => 'Proyecto',
				'fuente'      => 'Listado de producción científica del proyecto',
			),
			'actores_tipo'         => array(
				'name'        => 'Actores institucionales por tipo',
				'description' => 'Composición de la red de aliados del proyecto según su naturaleza.',
				'category'    => 'parte_todo',
				'dimensions'  => array( 'tipo' ),
				'measures'    => array( 'entidades' ),
				'default'     => 'treemap',
				'grupo'       => 'Proyecto',
				'fuente'      => 'Mapeo de actores del informe preliminar',
			),
		);

		return $r;
	}

	/* ----------------------------------------------------------------- */
	/* API pública                                                       */
	/* ----------------------------------------------------------------- */

	/**
	 * Lista compacta de vistas para selectores y catálogos del panel.
	 *
	 * @return array<int,array>
	 */
	public static function lista() {
		$salida = array();
		foreach ( self::registro() as $id => $m ) {
			$salida[] = array(
				'id'          => $id,
				'name'        => $m['name'],
				'description' => $m['description'],
				'category'    => $m['category'],
				'grupo'       => $m['grupo'],
				'default'     => $m['default'],
				'compatible'  => self::compatibles( $m['category'] ),
				'geo'         => isset( $m['geo'] ) ? $m['geo'] : null,
			);
		}
		return $salida;
	}

	/**
	 * Vistas que pueden llevarse a un mapa municipal.
	 *
	 * Son las que nombran un territorio con geometría: municipios o
	 * subregiones. Cada una declara en `geo` a qué nivel pertenece, qué
	 * campo de sus filas nombra el territorio y qué medida se colorea.
	 *
	 * @return array<int,array{id:string,name:string,grupo:string,nivel:string,medida:string}>
	 */
	public static function territoriales() {
		$salida = array();
		foreach ( self::registro() as $id => $m ) {
			if ( empty( $m['geo'] ) ) {
				continue;
			}
			$salida[] = array(
				'id'     => $id,
				'name'   => $m['name'],
				'grupo'  => $m['grupo'],
				'nivel'  => $m['geo']['nivel'],
				'medida' => $m['geo']['medida'],
			);
		}
		return $salida;
	}

	/**
	 * ¿Se puede dibujar la vista sobre el mapa?
	 *
	 * @param string $id Identificador.
	 * @return bool
	 */
	public static function es_territorial( $id ) {
		$m = self::meta( $id );
		return ! empty( $m['geo'] );
	}

	/**
	 * Series de una vista territorial partida en varias.
	 *
	 * Una vista como `prev_subregion` trae dos indicadores por subregión.
	 * En un gráfico de barras eso son dos series y se ven las dos; en un
	 * mapa coroplético hay que elegir una, porque un territorio no puede
	 * tener dos colores.
	 *
	 * @param string $id Identificador de la vista.
	 * @return array<int,string> Vacío si la vista no está partida en series.
	 */
	public static function series( $id ) {
		$m = self::meta( $id );
		if ( empty( $m['geo']['serie'] ) ) {
			return array();
		}

		$campo  = $m['geo']['serie'];
		$vistas = self::obtener( $id );
		$salida = array();

		foreach ( (array) ( isset( $vistas['data'] ) ? $vistas['data'] : array() ) as $fila ) {
			if ( isset( $fila[ $campo ] ) && ! in_array( $fila[ $campo ], $salida, true ) ) {
				$salida[] = $fila[ $campo ];
			}
		}
		return $salida;
	}

	/**
	 * Nivel territorial de una vista: 'municipio' o 'subregion'.
	 *
	 * @param string $id Identificador.
	 * @return string Cadena vacía si la vista no es territorial.
	 */
	public static function nivel( $id ) {
		$m = self::meta( $id );
		return empty( $m['geo']['nivel'] ) ? '' : $m['geo']['nivel'];
	}

	/**
	 * ¿Existe la vista?
	 *
	 * @param string $id Identificador.
	 * @return bool
	 */
	public static function existe( $id ) {
		$r = self::registro();
		return isset( $r[ $id ] );
	}

	/**
	 * Tipo de gráfico por defecto de una vista.
	 *
	 * @param string $id Identificador.
	 * @return string
	 */
	public static function default_tipo( $id ) {
		$r = self::registro();
		return isset( $r[ $id ]['default'] ) ? $r[ $id ]['default'] : 'bar';
	}

	/**
	 * Metadatos de una vista sin calcular sus filas (barato).
	 *
	 * @param string $id Identificador.
	 * @return array|null
	 */
	public static function meta( $id ) {
		$r = self::registro();
		return isset( $r[ $id ] ) ? $r[ $id ] : null;
	}

	/**
	 * Vista completa: metadatos, filas, textos y análisis automático.
	 *
	 * @param string $id Identificador.
	 * @return array|null
	 */
	public static function obtener( $id ) {
		$r = self::registro();
		if ( ! isset( $r[ $id ] ) ) {
			return null;
		}
		$m     = $r[ $id ];
		$datos = self::datos( $id );

		return array(
			'id'                => $id,
			'name'              => $m['name'],
			'description'       => $m['description'],
			'descripcion_larga' => self::descripcion_larga( $id ),
			'analisis_largo'    => self::analisis_largo( $id ),
			'category'          => $m['category'],
			'dimensions'        => $m['dimensions'],
			'measures'          => $m['measures'],
			'grupo'             => $m['grupo'],
			'fuente'            => $m['fuente'],
			'heatmap'           => ! empty( $m['heatmap'] ),
			'data'              => $datos,
			'analisis'          => UHP_Analisis::para_vista( $id, $m, $datos ),
		);
	}

	/* ----------------------------------------------------------------- */
	/* Textos largos                                                     */
	/* ----------------------------------------------------------------- */

	/**
	 * Textos descriptivos por vista, cargados de textos-graficos.php.
	 *
	 * @return array<string,array{descripcion:string,analisis:string}>
	 */
	private static function textos() {
		static $t = null;
		if ( null === $t ) {
			$ruta = UHP_DIR . 'includes/data/textos-graficos.php';
			$t    = is_readable( $ruta ) ? include $ruta : array();
			if ( ! is_array( $t ) ) {
				$t = array();
			}
		}
		return $t;
	}

	/**
	 * Descripción larga de una vista (qué muestra y cómo leerla).
	 *
	 * @param string $id Identificador.
	 * @return string
	 */
	public static function descripcion_larga( $id ) {
		$t = self::textos();
		return isset( $t[ $id ]['descripcion'] ) ? $t[ $id ]['descripcion'] : '';
	}

	/**
	 * Análisis cualitativo de una vista (qué significa lo que muestra).
	 *
	 * @param string $id Identificador.
	 * @return string
	 */
	public static function analisis_largo( $id ) {
		$t = self::textos();
		return isset( $t[ $id ]['analisis'] ) ? $t[ $id ]['analisis'] : '';
	}

	/* ----------------------------------------------------------------- */
	/* Filas de datos                                                    */
	/* ----------------------------------------------------------------- */

	/**
	 * Construye las filas de una vista a partir de los archivos JSON.
	 *
	 * @param string $id Identificador de la vista.
	 * @return array<int,array>
	 */
	private static function datos( $id ) {
		switch ( $id ) {

			/* ---------- Epidemiología ---------- */
			case 'zonas_riesgo':
				$filas = array();
				foreach ( (array) UHP_Datos::valor( 'epidemiologia', 'zonas_de_riesgo', array() ) as $z ) {
					$filas[] = array(
						'zona'        => isset( $z['zona'] ) ? (string) $z['zona'] : '',
						'incidencia'  => isset( $z['incidencia_por_100000'] ) ? (float) $z['incidencia_por_100000'] : 0.0,
						'territorio'  => isset( $z['territorio'] ) ? (string) $z['territorio'] : '',
						'nivel_riesgo' => isset( $z['nivel_riesgo'] ) ? (string) $z['nivel_riesgo'] : '',
					);
				}
				return $filas;

			case 'comparacion_nacional':
				$filas = array();
				foreach ( (array) UHP_Datos::valor( 'epidemiologia', 'comparacion_nacional.tasas', array() ) as $t ) {
					$filas[] = array(
						'ambito' => isset( $t['ambito'] ) ? (string) $t['ambito'] : '',
						'tasa'   => isset( $t['tasa_por_100000'] ) ? (float) $t['tasa_por_100000'] : 0.0,
						'tipo'   => isset( $t['tipo'] ) ? (string) $t['tipo'] : '',
					);
				}
				return $filas;

			case 'contraste_municipal':
				$filas = array();
				foreach ( (array) UHP_Datos::valor( 'epidemiologia', 'contraste_documentado.municipios', array() ) as $m ) {
					$filas[] = array(
						'municipio'  => isset( $m['municipio'] ) ? (string) $m['municipio'] : '',
						'mortalidad' => isset( $m['mortalidad_por_100000'] ) ? (float) $m['mortalidad_por_100000'] : 0.0,
						'zona'       => isset( $m['zona'] ) ? (string) $m['zona'] : '',
						'poblacion'  => isset( $m['poblacion_predominante'] ) ? (string) $m['poblacion_predominante'] : '',
					);
				}
				return $filas;

			/* ---------- Tamizaje ---------- */
			case 'tamizaje_hp':
				return self::filas_tamizaje( 'infeccion_h_pylori', 'Infectados', 'No infectados' );

			case 'tamizaje_lpm':
				return self::filas_tamizaje( 'lesion_precursora_malignidad', 'Con lesión precursora', 'Sin lesión precursora' );

			case 'tamizaje_comparado':
				$filas = array();
				$mapa  = array(
					'infeccion_h_pylori'           => 'Infección por H. pylori',
					'lesion_precursora_malignidad' => 'Lesión precursora',
				);
				foreach ( $mapa as $clave => $etiqueta ) {
					$filas[] = array(
						'indicador' => $etiqueta,
						'positivos' => (int) UHP_Datos::valor( 'tamizaje', $clave . '.positivos.personas', 0 ),
						'negativos' => (int) UHP_Datos::valor( 'tamizaje', $clave . '.negativos.personas', 0 ),
					);
				}
				return $filas;

			/* ---------- Prevalencia ---------- */
			case 'prev_lpm_municipios':
				return self::filas_top( 'top10_lesion_precursora_malignidad', 'prevalencia_lpm_porcentaje' );

			case 'prev_hp_municipios':
				return self::filas_top( 'top10_infeccion_h_pylori', 'prevalencia_h_pylori_porcentaje' );

			case 'prev_subregion':
				$filas = array();
				foreach ( (array) UHP_Datos::valor( 'prev_subregion', 'subregiones', array() ) as $s ) {
					$nombre = isset( $s['subregion'] ) ? (string) $s['subregion'] : '';
					if ( '' === $nombre ) {
						continue;
					}
					// Formato largo: una fila por indicador, que es lo que
					// necesita d3plus para dibujar dos series comparables.
					$filas[] = array(
						'subregion' => $nombre,
						'indicador' => 'Lesión precursora',
						'valor'     => isset( $s['prevalencia_lpm_porcentaje'] ) ? (float) $s['prevalencia_lpm_porcentaje'] : 0.0,
					);
					$filas[] = array(
						'subregion' => $nombre,
						'indicador' => 'Infección por H. pylori',
						'valor'     => isset( $s['prevalencia_h_pylori_porcentaje'] ) ? (float) $s['prevalencia_h_pylori_porcentaje'] : 0.0,
					);
				}
				return $filas;

			case 'prev_subregion_lpm':
				return self::filas_subregion( 'prevalencia_lpm_porcentaje' );

			case 'prev_subregion_hp':
				return self::filas_subregion( 'prevalencia_h_pylori_porcentaje' );

			/* ---------- Población ---------- */
			case 'perfil_genero':
				$filas = array();
				foreach ( (array) UHP_Datos::valor( 'sociodemografia', 'genero.distribucion', array() ) as $g ) {
					$filas[] = array(
						'categoria'  => isset( $g['categoria'] ) ? (string) $g['categoria'] : '',
						'personas'   => isset( $g['personas'] ) ? (int) $g['personas'] : 0,
						'porcentaje' => isset( $g['porcentaje'] ) ? (float) $g['porcentaje'] : 0.0,
					);
				}
				return $filas;

			case 'perfil_etnia':
				return self::filas_distribucion( 'pertenencia_etnica.distribucion' );

			case 'perfil_educacion':
				return self::filas_distribucion( 'nivel_educativo.distribucion' );

			case 'perfil_regimen':
				return self::filas_distribucion( 'regimen_salud.distribucion' );

			case 'perfil_nutricional':
				return self::filas_distribucion( 'estado_nutricional.distribucion' );

			case 'perfil_socioeconomico':
				$filas = array();
				foreach ( (array) UHP_Datos::valor( 'sociodemografia', 'indicadores_socioeconomicos', array() ) as $i ) {
					$filas[] = array(
						'indicador'  => isset( $i['indicador'] ) ? (string) $i['indicador'] : '',
						'porcentaje' => isset( $i['porcentaje'] ) ? (float) $i['porcentaje'] : 0.0,
					);
				}
				return $filas;

			/* ---------- Biobanco ---------- */
			case 'biobanco_tipos':
				$filas = array();
				foreach ( (array) UHP_Datos::valor( 'biobanco', 'muestras', array() ) as $m ) {
					$filas[] = array(
						'tipo'     => isset( $m['tipo'] ) ? (string) $m['tipo'] : '',
						'cantidad' => isset( $m['cantidad'] ) ? (int) $m['cantidad'] : 0,
					);
				}
				return $filas;

			/* ---------- Casos de cáncer ---------- */
			case 'cancer_municipios':
				$filas = array();
				foreach ( (array) UHP_Datos::valor( 'cancer', 'distribucion_por_municipio', array() ) as $c ) {
					$filas[] = array(
						'municipio' => isset( $c['municipio'] ) ? (string) $c['municipio'] : '',
						'casos'     => isset( $c['casos'] ) ? (int) $c['casos'] : 0,
					);
				}
				return $filas;

			case 'cancer_desenlace':
				$filas = array();
				foreach ( (array) UHP_Datos::valor( 'cancer', 'desenlace', array() ) as $d ) {
					$filas[] = array(
						'estado' => isset( $d['estado'] ) ? (string) $d['estado'] : '',
						'casos'  => isset( $d['casos'] ) ? (int) $d['casos'] : 0,
					);
				}
				return $filas;

			/* ---------- Proyecto ---------- */
			case 'financiacion':
				$filas = array();
				foreach ( (array) UHP_Datos::valor( 'proyecto', 'financiacion.fuentes', array() ) as $f ) {
					$filas[] = array(
						'fuente' => isset( $f['fuente'] ) ? (string) $f['fuente'] : '',
						'valor'  => isset( $f['valor'] ) ? (int) $f['valor'] : 0,
					);
				}
				return $filas;

			case 'metas_mga':
				$filas = array();
				foreach ( (array) UHP_Datos::valor( 'metas', 'productos', array() ) as $p ) {
					$filas[] = array(
						'producto'  => isset( $p['producto'] ) ? (string) $p['producto'] : '',
						'avance'    => isset( $p['avance_porcentaje'] ) ? (float) $p['avance_porcentaje'] : 0.0,
						'meta'      => isset( $p['meta'] ) ? (int) $p['meta'] : 0,
						'ejecutado' => isset( $p['ejecutado'] ) ? (int) $p['ejecutado'] : 0,
					);
				}
				return $filas;

			case 'publicaciones_anio':
				$filas = array();
				foreach ( (array) UHP_Datos::valor( 'publicaciones', 'publicaciones_por_anio', array() ) as $p ) {
					$filas[] = array(
						// El eje X es una etiqueta de año, no una medida: se
						// envía como texto para que no se trate como número.
						'anio'          => isset( $p['anio'] ) ? (string) $p['anio'] : '',
						'publicaciones' => isset( $p['publicaciones'] ) ? (int) $p['publicaciones'] : 0,
					);
				}
				return $filas;

			case 'actores_tipo':
				$conteo = array();
				foreach ( (array) UHP_Datos::valor( 'actores', 'actores', array() ) as $a ) {
					$tipo = isset( $a['tipo'] ) ? (string) $a['tipo'] : 'Sin clasificar';
					if ( ! isset( $conteo[ $tipo ] ) ) {
						$conteo[ $tipo ] = 0;
					}
					$conteo[ $tipo ]++;
				}
				$filas = array();
				foreach ( $conteo as $tipo => $n ) {
					$filas[] = array(
						'tipo'      => $tipo,
						'entidades' => $n,
					);
				}
				return $filas;
		}

		return array();
	}

	/* ----------------------------------------------------------------- */
	/* Constructores de filas reutilizables                              */
	/* ----------------------------------------------------------------- */

	/**
	 * Filas positivo/negativo de un bloque de tamizaje.
	 *
	 * @param string $bloque      Clave del bloque en 08_resultados_tamizaje.json.
	 * @param string $etq_positivo Etiqueta de la categoría positiva.
	 * @param string $etq_negativo Etiqueta de la categoría negativa.
	 * @return array<int,array>
	 */
	private static function filas_tamizaje( $bloque, $etq_positivo, $etq_negativo ) {
		$filas = array();
		foreach ( array( 'positivos' => $etq_positivo, 'negativos' => $etq_negativo ) as $lado => $etiqueta ) {
			$personas = UHP_Datos::valor( 'tamizaje', $bloque . '.' . $lado . '.personas', null );
			if ( null === $personas ) {
				continue;
			}
			$filas[] = array(
				'resultado'  => $etiqueta,
				'personas'   => (int) $personas,
				'porcentaje' => (float) UHP_Datos::valor( 'tamizaje', $bloque . '.' . $lado . '.porcentaje', 0 ),
			);
		}
		return $filas;
	}

	/**
	 * Filas de un top-10 municipal de 04_prevalencia_municipal.json.
	 *
	 * @param string $bloque Clave del listado.
	 * @param string $campo  Campo con el porcentaje.
	 * @return array<int,array>
	 */
	private static function filas_top( $bloque, $campo ) {
		$filas = array();
		foreach ( (array) UHP_Datos::valor( 'prev_municipal', $bloque, array() ) as $m ) {
			if ( empty( $m['municipio'] ) ) {
				continue;
			}
			$filas[] = array(
				'municipio'   => (string) $m['municipio'],
				'prevalencia' => isset( $m[ $campo ] ) ? (float) $m[ $campo ] : 0.0,
				'posicion'    => isset( $m['posicion'] ) ? (int) $m['posicion'] : 0,
				'divipola'    => UHP_Municipios::divipola_de( $m['municipio'] ),
			);
		}
		return $filas;
	}

	/**
	 * Filas de un indicador subregional.
	 *
	 * @param string $campo Campo con el porcentaje.
	 * @return array<int,array>
	 */
	private static function filas_subregion( $campo ) {
		$filas = array();
		foreach ( (array) UHP_Datos::valor( 'prev_subregion', 'subregiones', array() ) as $s ) {
			if ( empty( $s['subregion'] ) ) {
				continue;
			}
			$filas[] = array(
				'subregion'   => (string) $s['subregion'],
				'prevalencia' => isset( $s[ $campo ] ) ? (float) $s[ $campo ] : 0.0,
			);
		}
		return $filas;
	}

	/**
	 * Filas de una distribución categoría/porcentaje del perfil sociodemográfico.
	 *
	 * @param string $ruta Ruta con puntos dentro de 07_perfil_sociodemografico.json.
	 * @return array<int,array>
	 */
	private static function filas_distribucion( $ruta ) {
		$filas = array();
		foreach ( (array) UHP_Datos::valor( 'sociodemografia', $ruta, array() ) as $d ) {
			if ( empty( $d['categoria'] ) ) {
				continue;
			}
			$fila = array(
				'categoria'  => (string) $d['categoria'],
				'porcentaje' => isset( $d['porcentaje'] ) ? (float) $d['porcentaje'] : 0.0,
			);
			if ( isset( $d['personas'] ) && null !== $d['personas'] ) {
				$fila['personas'] = (int) $d['personas'];
			}
			$filas[] = $fila;
		}
		return $filas;
	}
}
