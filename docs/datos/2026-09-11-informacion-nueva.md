# Información nueva del informe de cierre — 2026-09-11

**Proyecto:** URKUNINA 5000 · BPIN 2015000100064
**Entidad:** Gobernación de Nariño · Secretaría TIC, Innovación y Gobierno Abierto
**Documentos revisados:**

| Documento | Revisión anterior | Revisión de septiembre |
|---|---|---|
| `Informe Preliminar_URKUNINA 5000.pdf` | 14 páginas | **17 páginas** |
| `URKUNINA 5000 — Presentación Informe Final.pptx` | 16 diapositivas | **18 diapositivas** |

Ambos documentos se compararon frase a frase con la revisión anterior y con el
conjunto de datos ya publicado, para separar lo que **confirma** cifras
existentes de lo que **añade** información. La mayor parte del contenido
confirma: el presupuesto, la cobertura de los 55 municipios, los 5.000
participantes, los 8 casos detectados, el perfil sociodemográfico completo, la
tabla MGA, los aliados y la producción científica ya estaban en `data/`.

Lo que sigue es lo que **no estaba**.

---

## 1. Mortalidad departamental por cáncer de estómago, 2019–2022

**Archivo nuevo:** `data/15_mortalidad_departamental.json`
**Fuente primaria:** Instituto Departamental de Salud de Nariño (IDSN), 2022
**Vista:** `mortalidad_anio` (temporal, líneas por defecto)

| Año | Fallecimientos |
|---|---|
| 2019 | 101 |
| 2020 | **77** |
| 2021 | 118 |
| 2022 | 137 |

Variación 2019 → 2022: **+35,64 %**, la cifra que el informe destaca. Se
verificó: 137 / 101 − 1 = 0,3564.

**De dónde sale la serie completa.** El cuerpo del informe solo cita los
extremos (101 y 137). Los cuatro valores están en las etiquetas de la Gráfica 3,
que se extrajo del PDF y se leyó directamente. Sin ese paso, la serie habría
tenido dos huecos.

> ### Discrepancia registrada
>
> El informe describe el periodo como de «crecimiento constante desde 2019
> hasta 2022», pero **su propia gráfica cae en 2020**, hasta 77 fallecimientos,
> antes del repunte.
>
> Se publica la serie, que es el dato. El crecimiento total del periodo sí es
> correcto; lo que no se sostiene es que sea constante.
>
> **El conjunto no atribuye causa alguna a la caída de 2020 porque la fuente no
> la explica.** Cualquier lectura sobre por qué bajó y volvió a subir tendría
> que apoyarse en otra fuente, no en este informe.

**Advertencia de uso:** son fallecimientos registrados, no casos nuevos ni tasas
por 100.000 habitantes. No deben compararse con las cifras de incidencia del
conjunto `02_contexto_epidemiologico.json`.

---

## 2. Acceso a servicios oncológicos

**Archivo nuevo:** `data/16_acceso_servicios_oncologicos.json`
**Fuente primaria:** Instituto Departamental de Salud de Nariño (IDSN), 2022
**Vista:** `acceso_oncologico` (parte-todo, dona por defecto)

El departamento tiene **seis IPS** con servicios oncológicos habilitados
—diagnóstico, quimioterapia, cirugía oncológica y consulta externa— y **las seis
están en Pasto**:

1. Hospital Universitario Departamental de Nariño
2. Hospital San Pedro
3. Hospital Infantil Los Ángeles
4. Instituto Cancerológico de Nariño
5. Clínica Pabón
6. Clínica La Aurora

De los 64 municipios, **1 tiene oferta y 63 no**. La concentración es del 100 %.

**Barrera documentada:** un paciente de Belén, al norte del departamento, recorre
**90 km** hasta Pasto por cada tratamiento y por cada examen diagnóstico.

El dato adquiere otra dimensión al cruzarlo con el tamizaje: **Belén es el
municipio que concentra 2 de los 8 casos** de cáncer gástrico detectados.

El informe no publica la distancia desde los demás municipios: solo documenta
este caso como ejemplo. Ese hueco queda declarado en `datos_no_incluidos`.

---

## 3. Territorios de referencia de cada zona de riesgo

**Archivo ampliado:** `data/02_contexto_epidemiologico.json` →
`municipios_referencia_por_zona`
**Fuente:** presentación de cierre, diapositiva 5

| Zona | Incidencia | Territorios que nombra la fuente |
|---|---|---|
| Roja | 150 × 100.000 | Juanambú *(subregión)*, Túquerres, Cumbal |
| Amarilla | 40–46 × 100.000 | Pasto |
| Verde | 6 × 100.000 | Tumaco, Barbacoas |

**La fuente mezcla niveles territoriales.** «Juanambú» es el nombre de una
**subregión** de Nariño, no de un municipio; los otros cinco sí son municipios.
Se comprobó contra `14_subregiones_municipios.json`. Cada referencia lleva por
eso un campo `tipo` (`municipio` o `subregion`), para que pueda cruzarse con la
cartografía correcta sin tener que adivinarlo.

No es una asignación exhaustiva: la fuente cita ejemplos y añade «y municipios
circunvecinos» sin enumerarlos, de modo que **los municipios no nombrados quedan
sin zona declarada**. Eso queda registrado en `datos_no_incluidos`: repartir los
64 municipios entre las tres zonas exigiría una fuente que hoy no existe.

---

## 4. Municipios con MENOR prevalencia de lesión precursora

**Archivo ampliado:** `data/04_prevalencia_municipal.json` →
`menor_prevalencia_lesion_precursora_malignidad`
**Vista nueva:** `prev_lpm_extremos` (comparativa, con mapa entre sus tipos)

| Municipio | LPM |
|---|---|
| Mallama | 11,4 % |
| Gualmatán | 17,1 % |
| Pupiales | 21,3 % |
| Pasto | 26,0 % |
| Yacuanquer | 26,6 % |

Con el top 10 que ya existía, la vista `prev_lpm_extremos` muestra los **dos
extremos publicados** en un solo gráfico: del 11,4 % de Mallama al 54,4 % de La
Cruz hay casi 43 puntos porcentuales de diferencia, dentro del mismo
departamento y con un protocolo de tamizaje idéntico.

**Lo que la vista NO permite.** Entre ambos extremos quedan **40 municipios sin
cifra publicada**. La vista muestra los bordes de la distribución, no la
distribución completa, y su texto lo dice explícitamente. Ordenar el
departamento entero exigiría acudir a la base histórica del proyecto.

No se publica el listado equivalente de menor prevalencia de *H. pylori*: ese
extremo queda sin dato.

---

## 5. National Cancer Institute (Estados Unidos)

**Archivo ampliado:** `data/10_actores_institucionales.json` (11 → 12 actores)

La tabla de actores del informe **no lo incluye**, pero el cuerpo del mismo
informe y la presentación de cierre sí lo nombran como asesor internacional. Se
incorpora con esa procedencia declarada.

> **No confundir** el *Instituto Nacional de Cancerología (INC)* de Colombia con
> el *National Cancer Institute (NCI)* de Estados Unidos. Son dos entidades
> distintas y el informe abrevia ambas como «Instituto Nacional de Cáncer». La
> nota queda en el `_meta` del archivo.

---

## 6. Discrepancias nuevas, registradas sin reconciliar

El manifiesto pasa de cinco a **ocho** discrepancias. Las tres nuevas:

### 6.1 Custodio del biobanco

| Fuente | Qué dice |
|---|---|
| Informe preliminar | **Fundación CIEDYN** — custodio del biobanco y de las bases de datos históricas (2018–2023) |
| Presentación de cierre | **Instituto Nacional de Cancerología** — custodio del biobanco (+45.000 muestras); a CIEDYN le asigna el «liderazgo técnico y administrativo» y al HUDN las «bases de datos históricas» |

El cuerpo del propio informe sitúa las muestras «en el Instituto Nacional de
Cáncer de Colombia», así que **custodia física y custodia documental podrían no
coincidir**. Se conserva el reparto del informe preliminar, que es el documento
que desarrolla la tabla de actores.

**Requiere validación con las dos entidades antes de publicarse como dato de
gobernanza.**

### 6.2 Tamaño del biobanco

La presentación de cierre **se contradice a sí misma entre diapositivas**:
«más de 25.000 muestras» en las diapositivas 10 y 18, «+45.000 muestras» en la
14. Sumadas a las dos cifras que ya estaban en conflicto:

| Cifra | Origen |
|---|---|
| 31.190 | Suma de los tipos documentados |
| 25.000 | Texto de los documentos |
| 45.000 | Meta de la ficha MGA, cumplida al 100 % |
| 45.000 | Presentación de cierre, diapositiva 14 |

Las cuatro están en los datos. Ninguna se da por buena.

### 6.3 Número de retos de la siguiente fase

El informe dice que el equipo «identifica **cuatro** retos técnicos pendientes»
y la tabla que sigue a esa frase enumera **cinco**. Se conservan los cinco de la
tabla; el «cuatro» parece no haberse actualizado al añadirse el repositorio
digital.

---

## 7. Cambios de estructura que acompañan a lo anterior

- **Registro de datos:** `UHP_Datos::registro()` pasa de 17 a 19 entradas.
- **Vistas:** de 24 a 27 (`mortalidad_anio`, `acceso_oncologico`,
  `prev_lpm_extremos`), cada una con su descripción y su análisis escritos.
- **Manifiesto:** pasa a cubrir el conjunto entero. Faltaban por declarar
  `14_subregiones_municipios.json` y las dos capas de geometría, de modo que la
  comprobación de integridad **no las alcanzaba**. Ahora declara 18 archivos:
  todos salvo el propio manifiesto, que no puede declarar su propio hash.
- **Pruebas:** 390 → 417 comprobaciones. Dos nuevas merecen mención:
  - El **+35,64 %** no se copia: se recalcula desde los extremos declarados y se
    comprueba que esos extremos son los años que trae la serie. Si alguien
    corrige un año y no el titular, la prueba lo delata.
  - Las IPS listadas deben sumar las habilitadas que declara el resumen, y
    repartirse entre tantos municipios como este declara.

---

## 8. Qué sigue pendiente de fuente

Añadido a `datos_no_incluidos` del manifiesto:

- Municipios con menor prevalencia de *H. pylori*.
- Mortalidad anterior a 2019 o posterior a 2022.
- Distancia a Pasto desde cada municipio (solo se documenta Belén).
- Asignación de los 64 municipios a las tres zonas de riesgo.
