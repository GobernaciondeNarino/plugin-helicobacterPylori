/* =====================================================================
   [urkunina_3d] — recreación 3D de Helicobacter pylori.

   Adaptación a módulo de WordPress del original
   content/helicobacter-pylori-3d.html. La lógica de la escena, la
   morfometría y la línea de tiempo son las del original; lo que cambia
   es cómo se carga y a qué se ancla:

   1. SIN IMPORTMAP. El original declaraba un <script type="importmap">
      para resolver los especificadores «bare» de Three.js. Un documento
      HTML solo admite un importmap, y en este sitio conviven plugins que
      ya imprimen el suyo (el Monitor Ambiental lo hace, con otra versión
      de Three.js): el segundo se ignoraría y la escena no cargaría.
      Aquí se usan importaciones dinámicas a URLs absolutas del bundle
      `/+esm` de jsDelivr, que trae ya resueltos los `import … from
      'three'` internos de los addons apuntando a esa misma URL. Resultado:
      una sola instancia de Three.js, sin importmap y sin interferir con
      el de ningún otro plugin. Las URLs llegan desde PHP en window.UHP3D,
      filtrables con `uhp_url_libreria` para autoalojarlas.

   2. ANCLADO AL CONTENEDOR, NO AL VIEWPORT. Las medidas del renderer, la
      cámara y la barra de escala se toman del contenedor del shortcode
      mediante ResizeObserver, no de window.innerWidth/innerHeight.

   3. CONVIVE CON LA PÁGINA. El teclado solo actúa cuando el foco está
      dentro de la escena (no le roba las flechas ni el espacio al resto
      de la página) y el bucle de dibujo se detiene cuando la escena sale
      de la vista.
   ===================================================================== */

const CFG = window.UHP3D || {};

/**
 * Carga Three.js y los addons que necesita la escena.
 *
 * Se piden en paralelo y por URL absoluta; todas las rutas resuelven al
 * mismo bundle de Three.js, de modo que el navegador comparte una única
 * instancia entre el módulo principal y los addons.
 */
async function cargarLibrerias() {
  const u = CFG.urls || {};
  const [three, orbit, composer, renderPass, bloom, bokeh, output, room] = await Promise.all([
    import(u.three),
    import(u.orbit),
    import(u.composer),
    import(u.renderPass),
    import(u.bloomPass),
    import(u.bokehPass),
    import(u.outputPass),
    import(u.roomEnvironment)
  ]);
  return {
    THREE: three,
    OrbitControls: orbit.OrbitControls,
    EffectComposer: composer.EffectComposer,
    RenderPass: renderPass.RenderPass,
    UnrealBloomPass: bloom.UnrealBloomPass,
    BokehPass: bokeh.BokehPass,
    OutputPass: output.OutputPass,
    RoomEnvironment: room.RoomEnvironment
  };
}

/* ================================================================
   PARÁMETROS MORFOLÓGICOS  ·  1 unidad de escena = 1 µm
   Promedio de cepas LSH100 / B128 / PMSS1 (Martínez & Bansil et al.,
   Molecular Microbiology) y datos de NCBI Bookshelf.
   ================================================================ */
const MORFO = {
  espiral : { L:3.10, R:0.150, vueltas:2.00, radio:0.280 },  // bacilar helicoidal
  cocoide : { L:1.05, R:0.020, vueltas:0.35, radio:0.520 },  // forma VBNC
  flagelo : { n:5, largo:4.10, radioHelice:0.14, paso:1.58, grosor:0.017 }
};

const movReducido = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const esMovil = window.matchMedia('(max-width: 760px)').matches ||
                /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);

/* ================================================================
   LÍNEA DE TIEMPO
   ================================================================ */
const MOMENTOS = [
  {
    reloj:'Infancia · 0 a 10 años', corto:'Contagio',
    titulo:'La bacteria llega en la niñez',
    entradilla:'Casi siempre entra por la boca, casi siempre dentro de la propia casa.',
    cuerpo:[
      'La transmisión ocurre de persona a persona por vía <b>oral-oral, fecal-oral y gastro-oral</b>, y también a través de <b>agua o alimentos contaminados</b>. La ruta más probable es la intrafamiliar, especialmente de madre a hijo.',
      'En países con menor cobertura de saneamiento, entre el <b>70% y el 90%</b> de las personas se infecta durante la infancia. La infección se instala en silencio y puede acompañar a una persona toda la vida sin dar síntomas.'
    ],
    cifras:[
      {v:'44,3%', t:'prevalencia mundial'},
      {v:'57,6%', t:'América Latina y el Caribe'},
      {v:'≈90%', t:'población de Nariño'}
    ],
    fuente:'Zamani et al., Aliment Pharmacol Ther 2018 · Curado et al., meta-análisis regional.',
    cam:{p:[6.2,1.4,7.6], t:[0,0.2,0]},
    fx:{ureasa:0, moco:1.0, mucosa:0, adhesion:0, t4ss:0, inflama:0, correa:0, cocoide:0, terapia:0, nado:0.55, orgY:1.1}
  },
  {
    reloj:'Minuto 1 en el estómago', corto:'Ácido',
    titulo:'Sobrevivir a un pH de 2',
    entradilla:'La ureasa fabrica una nube de amoníaco que neutraliza el ácido a su alrededor.',
    cuerpo:[
      'El jugo gástrico destruye a la mayoría de microorganismos. <i>H. pylori</i> resiste porque produce <b>ureasa</b> en enormes cantidades: la enzima convierte la urea del estómago en <b>amoníaco y CO₂</b>.',
      'El amoníaco alcaliniza el microambiente que rodea a la bacteria y su espacio periplásmico, creando una burbuja de protección química mientras cruza la luz gástrica.'
    ],
    cifras:[
      {v:'pH ≤ 2', t:'acidez de la luz gástrica'},
      {v:'Urea → NH₃', t:'reacción de la ureasa'}
    ],
    fuente:'NCBI Bookshelf, Helicobacter pylori: Physiology and Genetics.',
    cam:{p:[4.4,0.9,5.2], t:[0,0,0]},
    fx:{ureasa:1, moco:1.0, mucosa:0, adhesion:0, t4ss:0, inflama:0, correa:0, cocoide:0, terapia:0, nado:0.75, orgY:0.9}
  },
  {
    reloj:'Primeras horas', corto:'Nado y moco',
    titulo:'Perfora la capa de moco',
    entradilla:'Entre 2 y 7 flagelos envainados la impulsan como un sacacorchos.',
    cuerpo:[
      'La forma helicoidal y el penacho de <b>flagelos polares envainados</b> —de 3,0 a 4,4 µm— le permiten atornillarse dentro del gel mucoso. La vaina es una extensión de la membrana externa que protege el filamento, que es sensible al ácido.',
      'La ureasa también cambia la física del moco: al subir el pH local, la mucina pasa de gel a líquido y la bacteria nada a través de ella hasta alcanzar el epitelio, donde el pH es casi neutro.'
    ],
    cifras:[
      {v:'2–7', t:'flagelos unipolares'},
      {v:'10–15', t:'µm/s de velocidad'},
      {v:'≈30 nm', t:'diámetro del flagelo'}
    ],
    fuente:'Martínez et al., Mol Microbiol · Cryo-EM del filamento flagelar, 2025.',
    cam:{p:[3.4,0.4,4.4], t:[0,-0.3,0]},
    fx:{ureasa:0.35, moco:1.0, mucosa:0.6, adhesion:0, t4ss:0, inflama:0, correa:0, cocoide:0, terapia:0, nado:1.0, orgY:0.2}
  },
  {
    reloj:'Primeros días', corto:'Adhesión',
    titulo:'Se ancla a las células del estómago',
    entradilla:'Adhesinas específicas encajan en azúcares de la superficie epitelial.',
    cuerpo:[
      'La bacteria se fija al epitelio mediante proteínas de membrana externa: <b>BabA</b> reconoce el antígeno <b>Lewis b</b>, <b>SabA</b> se une al sialil-Lewis x que aparece en mucosa ya inflamada, y <b>HopQ</b>, <b>OipA</b> y <b>AlpA/B</b> refuerzan el anclaje.',
      'Anclada, deja de ser arrastrada por el recambio del moco. Esa permanencia es lo que convierte un contacto pasajero en una infección crónica de por vida.'
    ],
    cifras:[
      {v:'BabA', t:'se une a Lewis b'},
      {v:'SabA', t:'se une a sialil-Lewis x'}
    ],
    fuente:'Revisión de adhesinas de H. pylori, NCBI.',
    cam:{p:[2.6,-1.5,3.6], t:[0,-2.6,0]},
    fx:{ureasa:0.15, moco:0.75, mucosa:1, adhesion:1, t4ss:0, inflama:0.12, correa:0, cocoide:0, terapia:0, nado:0.35, orgY:-2.4}
  },
  {
    reloj:'Semanas', corto:'Inyección de CagA',
    titulo:'Inyecta una proteína dentro de la célula',
    entradilla:'Una jeringa molecular atraviesa la membrana y entrega CagA.',
    cuerpo:[
      'Las cepas más agresivas llevan la isla de patogenicidad <b>cagPAI</b>, que arma un <b>sistema de secreción tipo IV</b>: una estructura en forma de jeringa que perfora la célula epitelial e inyecta la proteína <b>CagA</b>.',
      'Dentro, CagA se fosforila en sus motivos EPIYA, secuestra la fosfatasa SHP-2 y desordena el citoesqueleto: la célula se estira en el llamado <b>fenotipo colibrí</b>. En paralelo, la toxina <b>VacA</b> abre poros, forma vacuolas e induce apoptosis, y la proteasa <b>HtrA</b> rompe la E-cadherina que mantiene unidas a las células.'
    ],
    cifras:[
      {v:'cagPAI', t:'hasta 31 genes'},
      {v:'T4SS', t:'jeringa molecular'},
      {v:'VacA', t:'citotoxina vacuolizante'}
    ],
    fuente:'J Bacteriol, ASM (10.1128/jb.00457-25).',
    cam:{p:[2.0,-1.9,3.0], t:[0.1,-3.0,0]},
    fx:{ureasa:0.1, moco:0.6, mucosa:1, adhesion:1, t4ss:1, inflama:0.45, correa:0, cocoide:0, terapia:0, nado:0.22, orgY:-2.55}
  },
  {
    reloj:'Meses a años', corto:'Inflamación',
    titulo:'Gastritis crónica activa',
    entradilla:'El sistema inmune llega, no logra eliminarla y el daño se vuelve permanente.',
    cuerpo:[
      'El peptidoglicano bacteriano activa el receptor <b>NOD1</b> y la célula epitelial libera <b>interleucina 8</b>, que recluta neutrófilos. Se instala una gastritis crónica activa que puede durar décadas.',
      'La bacteria evade la respuesta inmune y sobrevive dentro del propio foco inflamatorio. Ese ciclo sostenido de daño y regeneración del tejido es el motor de todo lo que viene después.'
    ],
    cifras:[
      {v:'IL-8', t:'recluta neutrófilos'},
      {v:'NOD1', t:'alarma inmune innata'}
    ],
    fuente:'Revisiones de inmunopatogénesis gástrica, NCBI.',
    cam:{p:[3.6,-1.2,4.6], t:[0,-3.0,0]},
    fx:{ureasa:0.05, moco:0.5, mucosa:1, adhesion:1, t4ss:0.5, inflama:1, correa:0, cocoide:0, terapia:0, nado:0.2, orgY:-2.55}
  },
  {
    reloj:'Décadas', corto:'Cascada de Correa',
    titulo:'De la gastritis al cáncer gástrico',
    entradilla:'Una secuencia lenta, descrita por el patólogo nariñense Pelayo Correa.',
    cuerpo:[
      'El tejido recorre una escalera de lesiones: gastritis crónica → <b>atrofia gástrica</b> → <b>metaplasia intestinal</b> → <b>displasia</b> → <b>adenocarcinoma</b>. Cada peldaño tarda años y varios son reversibles si la infección se erradica a tiempo.',
      'El riesgo es real pero no es un destino: <i>H. pylori</i> explica cerca del <b>80% de los adenocarcinomas gástricos</b>, y aun así solo entre el <b>1% y el 3%</b> de las personas infectadas desarrollan cáncer. También causa <b>úlcera péptica</b> y <b>linfoma MALT</b>, que suele remitir al eliminar la bacteria.'
    ],
    cifras:[
      {v:'2,09', t:'atrofia · por 1.000 personas-año'},
      {v:'2,89', t:'metaplasia · por 1.000 p-año'},
      {v:'10,09', t:'displasia · por 1.000 p-año'},
      {v:'Grupo 1', t:'carcinógeno IARC desde 1994', alerta:true}
    ],
    fuente:'Hahn et al., Clin Gastroenterol Hepatol 2024 · IARC Monographs vol. 61.',
    cam:{p:[5.4,-0.6,6.4], t:[0,-3.1,0]},
    fx:{ureasa:0, moco:0.4, mucosa:1, adhesion:1, t4ss:0.3, inflama:0.7, correa:1, cocoide:0, terapia:0, nado:0.16, orgY:-2.5}
  },
  {
    reloj:'Aquí y ahora', corto:'El enigma de Nariño',
    titulo:'Misma bacteria, riesgo muy distinto',
    entradilla:'Nueve de cada diez nariñenses la tienen. El cáncer gástrico, en cambio, se concentra en la montaña.',
    cuerpo:[
      'La prevalencia de infección es prácticamente idéntica en los Andes y en la costa Pacífica del departamento —cerca del 90%— pero la incidencia de cáncer gástrico se multiplica por veinticinco entre una zona y la otra. Es el llamado <b>enigma colombiano</b> o <b>enigma de Nariño</b>.',
      'Las hipótesis convergen en tres frentes: las cepas andinas son mayoritariamente <b>cagA+ / vacA s1m1</b> y de ancestría europea, mientras las costeras son de linaje africano; la dieta y la respuesta inmune difieren; y ciertos polimorfismos del huésped (IL-1B, TNF-A, IL-10) modifican el riesgo. La zona andina de Nariño registra una de las tasas más altas del mundo.'
    ],
    cifras:[
      {v:'150', t:'casos por 100.000 · Andes (Túquerres, Pasto)', alerta:true},
      {v:'6', t:'casos por 100.000 · costa (Tumaco)'},
      {v:'≈90%', t:'infección en ambas zonas'}
    ],
    fuente:'Correa y Bravo · Genomic epidemiology of H. pylori in Colombia (PubMed 41757356).',
    cam:{p:[7.0,0.6,8.0], t:[0,-2.4,0]},
    fx:{ureasa:0, moco:0.35, mucosa:1, adhesion:1, t4ss:0.2, inflama:0.8, correa:1, cocoide:0, terapia:0, nado:0.16, orgY:-2.5}
  },
  {
    reloj:'Bajo presión', corto:'Forma cocoide',
    titulo:'Se encoge y se esconde',
    entradilla:'Ante los antibióticos cambia de forma y deja de ser cultivable.',
    cuerpo:[
      'Frente al oxígeno, los cambios de pH o los antibióticos, la bacteria abandona la hélice y adopta una <b>forma cocoide</b>: viable pero no cultivable. Baja su metabolismo, evade el receptor NOD1, deja de inducir IL-8 y se vuelve invisible para varias pruebas.',
      'Este estado se asocia a recaídas tras el tratamiento y a resistencia. La resistencia a <b>claritromicina</b> —el principal antibiótico de la terapia triple— ya bordea el 28% a nivel mundial y crece año a año.'
    ],
    cifras:[
      {v:'VBNC', t:'viable pero no cultivable'},
      {v:'27,5%', t:'resistencia a claritromicina', alerta:true}
    ],
    fuente:'PeerJ 2023, meta-análisis de 248 estudios de resistencia.',
    cam:{p:[2.9,-1.7,3.4], t:[0,-2.9,0]},
    fx:{ureasa:0, moco:0.5, mucosa:1, adhesion:0.6, t4ss:0, inflama:0.5, correa:0.8, cocoide:1, terapia:0, nado:0.05, orgY:-2.4}
  },
  {
    reloj:'Se puede cortar', corto:'Diagnóstico',
    titulo:'Detectarla y eliminarla',
    entradilla:'La cadena se interrumpe con una prueba sencilla y un tratamiento de dos semanas.',
    cuerpo:[
      'Se detecta con <b>prueba de aliento con urea marcada</b>, <b>antígeno en materia fecal</b> o <b>endoscopia con biopsia</b>. Ninguna requiere procedimientos complejos en el primer nivel de atención.',
      'El tratamiento combina un inhibidor de bomba de protones con dos o tres antibióticos —<b>terapia triple</b> o <b>cuádruple con bismuto</b>— durante 10 a 14 días. Erradicar la infección antes de la atrofia detiene la cascada, y en una región con la incidencia de Nariño esa ventana de tiempo es la intervención de salud pública más rentable que existe.'
    ],
    cifras:[
      {v:'10–14', t:'días de tratamiento'},
      {v:'Reversible', t:'si se actúa antes de la atrofia'}
    ],
    fuente:'Guías de manejo de infección por H. pylori.',
    cam:{p:[4.8,-0.2,5.8], t:[0,-2.2,0]},
    fx:{ureasa:0, moco:0.55, mucosa:1, adhesion:0.2, t4ss:0, inflama:0.2, correa:0.35, cocoide:0.55, terapia:1, nado:0.12, orgY:-2.0}
  }
];

/* ================================================================
   INSTANCIA
   Todo el estado de la escena vive dentro de esta función, de modo
   que varias instancias del shortcode pueden convivir sin pisarse.
   ================================================================ */
function crearInstancia(raiz, libs) {
  const {
    THREE, OrbitControls, EffectComposer, RenderPass,
    UnrealBloomPass, BokehPass, OutputPass, RoomEnvironment
  } = libs;

  const cfg = {
    duracion: Math.max(4, parseFloat(raiz.getAttribute('data-duracion')) || 15),
    autoplay: raiz.getAttribute('data-autoplay') !== '0',
    // Si el componente puede llevarse el scroll de la página hasta él al
    // cambiar de momento. Por defecto no: ver centrarPaso().
    desplazar: raiz.getAttribute('data-desplazar') === '1'
  };

  /* ---------- Medidas del contenedor ---------- */
  // El original se dimensionaba con window.innerWidth/innerHeight porque
  // ocupaba toda la ventana. Embebido, la referencia es el contenedor.
  let _w = 320, _h = 320;
  function medir() {
    const r = raiz.getBoundingClientRect();
    _w = Math.max(320, Math.round(r.width));
    _h = Math.max(320, Math.round(r.height));
  }
  medir();

  let rafId = 0;
  let visible = true;
  let observadorTamano = null;
  let observadorVista = null;

  function observarTamano() {
    if (typeof ResizeObserver === 'function') {
      observadorTamano = new ResizeObserver(() => alRedimensionar());
      observadorTamano.observe(raiz);
    } else {
      window.addEventListener('resize', alRedimensionar);
    }

    if (typeof IntersectionObserver === 'function') {
      observadorVista = new IntersectionObserver(function (entradas) {
        visible = entradas.some(function (e) { return e.isIntersecting; });
      }, { rootMargin: '120px' });
      observadorVista.observe(raiz);
    }
  }

  /**
   * Libera la GPU y los observadores cuando la escena sale del documento.
   *
   * Sin esto, un tema con navegación por AJAX dejaría contextos WebGL
   * huérfanos hasta agotar el límite del navegador.
   */
  function destruir() {
    if (rafId) { cancelAnimationFrame(rafId); rafId = 0; }
    if (observadorTamano) { observadorTamano.disconnect(); }
    if (observadorVista) { observadorVista.disconnect(); }
    window.removeEventListener('resize', alRedimensionar);
    if (compositor && typeof compositor.dispose === 'function') { compositor.dispose(); }
    if (controles) { controles.dispose(); }
    if (renderer) { renderer.dispose(); }
  }
  raiz.uhpDestruir = destruir;

  /* ================================================================
     MOTOR 3D
     ================================================================ */
  let renderer, escena, camara, controles, compositor, bokeh, reloj;
  let organismo, cuerpoTubo, flagelos = [], bulbos = [];
  let haloUreasa, particulasMoco, particulasAmoniaco, particulasInmunes;
  let mucosaGrupo, mucosaCelulas, mucosaBase, jeringa, pulsosCagA = [];
  let luzVerde, luzAmbar;

  const est = { ureasa:0, moco:1, mucosa:0, adhesion:0, t4ss:0, inflama:0, correa:0, cocoide:0, terapia:0, nado:0.55, orgY:1.1 };
  const camPos = new THREE.Vector3(6.2,1.4,7.6);
  const camTgt = new THREE.Vector3(0,0.2,0);

  const COL_SANA   = new THREE.Color('#C4726B');
  const COL_INFLAM = new THREE.Color('#E2453C');
  const COL_META   = new THREE.Color('#9A86AE');
  const COL_DISPL  = new THREE.Color('#6B3050');

  /* ---------- Tubo de radio variable con transporte paralelo ---------- */
  class TuboVariable {
    constructor(seg, rad, material){
      this.seg = seg; this.rad = rad;
      const nv = (seg+1)*(rad+1);
      const pos = new Float32Array(nv*3);
      const uv  = new Float32Array(nv*2);
      const idx = [];
      for(let i=0;i<=seg;i++){
        for(let j=0;j<=rad;j++){
          const k = i*(rad+1)+j;
          uv[k*2] = i/seg; uv[k*2+1] = j/rad;
        }
      }
      for(let i=0;i<seg;i++){
        for(let j=0;j<rad;j++){
          const a = i*(rad+1)+j, b = (i+1)*(rad+1)+j, c = (i+1)*(rad+1)+j+1, d = i*(rad+1)+j+1;
          idx.push(a,b,d, b,c,d);
        }
      }
      const g = new THREE.BufferGeometry();
      g.setAttribute('position', new THREE.BufferAttribute(pos,3));
      g.setAttribute('uv', new THREE.BufferAttribute(uv,2));
      g.setIndex(idx);
      this.geometria = g;
      this.malla = new THREE.Mesh(g, material);
      this.malla.frustumCulled = false;
      this._T = new THREE.Vector3(); this._N = new THREE.Vector3();
      this._B = new THREE.Vector3(); this._prev = new THREE.Vector3();
      this._q = new THREE.Quaternion(); this._tmp = new THREE.Vector3();
    }
    actualizar(pts, radios){
      const seg=this.seg, rad=this.rad;
      const pos = this.geometria.attributes.position.array;
      const T=this._T, N=this._N, B=this._B, q=this._q, tmp=this._tmp;
      let inicial = true;
      for(let i=0;i<=seg;i++){
        const p = pts[i];
        if(i===0)          T.copy(pts[1]).sub(pts[0]);
        else if(i===seg)   T.copy(pts[seg]).sub(pts[seg-1]);
        else               T.copy(pts[i+1]).sub(pts[i-1]);
        if(T.lengthSq() < 1e-12) T.set(0,1,0);
        T.normalize();
        if(inicial){
          N.set(0,0,1);
          if(Math.abs(T.dot(N)) > 0.9) N.set(1,0,0);
          N.crossVectors(T,N).normalize();
          inicial = false;
        } else {
          q.setFromUnitVectors(this._prev, T);
          N.applyQuaternion(q);
          tmp.copy(T).multiplyScalar(N.dot(T));
          N.sub(tmp).normalize();
        }
        this._prev.copy(T);
        B.crossVectors(T,N);
        const r = radios[i];
        const base = i*(rad+1);
        for(let j=0;j<=rad;j++){
          const a = (j/rad)*Math.PI*2;
          const ca = Math.cos(a), sa = Math.sin(a);
          const k = (base+j)*3;
          pos[k]   = p.x + r*(ca*N.x + sa*B.x);
          pos[k+1] = p.y + r*(ca*N.y + sa*B.y);
          pos[k+2] = p.z + r*(ca*N.z + sa*B.z);
        }
      }
      this.geometria.attributes.position.needsUpdate = true;
      this.geometria.computeVertexNormals();
      this.geometria.computeBoundingSphere();
    }
  }

  /* ---------- Texturas procedurales ---------- */
  function texturaMembrana(){
    const s = 512, c = document.createElement('canvas');
    c.width = c.height = s;
    const x = c.getContext('2d');
    x.fillStyle = '#808080'; x.fillRect(0,0,s,s);
    for(let i=0;i<2600;i++){
      const px = Math.random()*s, py = Math.random()*s, r = 1.2 + Math.random()*4.5;
      const g = x.createRadialGradient(px,py,0,px,py,r);
      const luz = Math.random() > .5;
      g.addColorStop(0, luz ? 'rgba(255,255,255,.42)' : 'rgba(0,0,0,.36)');
      g.addColorStop(1, 'rgba(128,128,128,0)');
      x.fillStyle = g; x.beginPath(); x.arc(px,py,r,0,Math.PI*2); x.fill();
    }
    const t = new THREE.CanvasTexture(c);
    t.wrapS = t.wrapT = THREE.RepeatWrapping;
    t.repeat.set(7,2);
    return t;
  }
  function texturaPunto(){
    const s = 64, c = document.createElement('canvas');
    c.width = c.height = s;
    const x = c.getContext('2d');
    const g = x.createRadialGradient(s/2,s/2,0,s/2,s/2,s/2);
    g.addColorStop(0,'rgba(255,255,255,1)');
    g.addColorStop(.35,'rgba(255,255,255,.5)');
    g.addColorStop(1,'rgba(255,255,255,0)');
    x.fillStyle = g; x.fillRect(0,0,s,s);
    return new THREE.CanvasTexture(c);
  }

  /* ---------- Construcción de la escena ---------- */
  function iniciar(){
    const lienzo = raiz.querySelector('.uhp3d__lienzo');
    renderer = new THREE.WebGLRenderer({ canvas:lienzo, antialias:!esMovil, alpha:true, powerPreference:'high-performance' });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, esMovil ? 1.6 : 2));
    renderer.setSize(_w, _h);
    renderer.toneMapping = THREE.ACESFilmicToneMapping;
    renderer.toneMappingExposure = 1.05;

    escena = new THREE.Scene();
    escena.fog = new THREE.FogExp2(0x081015, 0.055);

    camara = new THREE.PerspectiveCamera(42, _w/_h, 0.05, 220);
    camara.position.copy(camPos);

    controles = new OrbitControls(camara, lienzo);
    controles.enableDamping = true;
    controles.dampingFactor = 0.06;
    controles.enablePan = false;
    controles.minDistance = 2.2;
    controles.maxDistance = 18;
    controles.rotateSpeed = 0.55;
    controles.target.copy(camTgt);

    const pmrem = new THREE.PMREMGenerator(renderer);
    escena.environment = pmrem.fromScene(new RoomEnvironment(), 0.05).texture;
    if('environmentIntensity' in escena) escena.environmentIntensity = 0.42;

    escena.add(new THREE.HemisphereLight(0x9FD8FF, 0x140A0C, 0.55));
    const clave = new THREE.DirectionalLight(0xFFF4E2, 1.9);
    clave.position.set(5,7,4); escena.add(clave);
    luzVerde = new THREE.PointLight(0x10A13B, 26, 22, 2);
    luzVerde.position.set(-4.2,1.6,-3.4); escena.add(luzVerde);
    luzAmbar = new THREE.PointLight(0xFFD500, 12, 18, 2);
    luzAmbar.position.set(3.6,-2.4,3.0); escena.add(luzAmbar);

    construirOrganismo();
    construirMucosa();
    construirMedio();

    compositor = new EffectComposer(renderer);
    compositor.addPass(new RenderPass(escena, camara));
    const bloom = new UnrealBloomPass(new THREE.Vector2(_w, _h), 0.62, 0.55, 0.72);
    compositor.addPass(bloom);
    if(!esMovil){
      bokeh = new BokehPass(escena, camara, { focus:9.0, aperture:0.00085, maxblur:0.007 });
      compositor.addPass(bokeh);
    }
    compositor.addPass(new OutputPass());

    reloj = new THREE.Clock();
    observarTamano();
    animar();
  }

  function construirOrganismo(){
    organismo = new THREE.Group();
    organismo.position.y = est.orgY;
    escena.add(organismo);

    const bump = texturaMembrana();
    const matCuerpo = new THREE.MeshPhysicalMaterial({
      color: 0x9BBE6A, roughness: 0.34, metalness: 0.0,
      clearcoat: 0.72, clearcoatRoughness: 0.28,
      sheen: 0.85, sheenColor: new THREE.Color(0xC9E88F), sheenRoughness: 0.5,
      transmission: 0.34, thickness: 0.62, ior: 1.37,
      iridescence: 0.28, iridescenceIOR: 1.25,
      bumpMap: bump, bumpScale: 0.055,
      transparent: true, opacity: 1
    });
    matCuerpo.userData.colBase = new THREE.Color(0x9BBE6A);

    const segC = esMovil ? 96 : 132;
    const radC = esMovil ? 18 : 24;
    cuerpoTubo = new TuboVariable(segC, radC, matCuerpo);
    organismo.add(cuerpoTubo.malla);
    cuerpoTubo.puntos = [];
    cuerpoTubo.radios = new Array(segC+1).fill(0);
    for(let i=0;i<=segC;i++) cuerpoTubo.puntos.push(new THREE.Vector3());

    // Halo de amoníaco generado por la ureasa
    haloUreasa = new THREE.Mesh(
      new THREE.SphereGeometry(1.85, 40, 28),
      new THREE.MeshBasicMaterial({ color:0x8FE6FF, transparent:true, opacity:0, blending:THREE.AdditiveBlending, depthWrite:false, side:THREE.BackSide })
    );
    organismo.add(haloUreasa);

    // Flagelos envainados
    const matFlagelo = new THREE.MeshPhysicalMaterial({
      color: 0xBFD98F, roughness: 0.4, clearcoat: 0.6,
      sheen: 0.7, sheenColor: new THREE.Color(0xE4F3BE),
      transmission: 0.42, thickness: 0.12, ior: 1.35,
      transparent: true, opacity: 1
    });
    const matBulbo = matFlagelo.clone();
    const segF = esMovil ? 40 : 56;
    for(let f=0; f<MORFO.flagelo.n; f++){
      const t = new TuboVariable(segF, 8, matFlagelo);
      t.puntos = []; t.radios = new Array(segF+1).fill(MORFO.flagelo.grosor);
      for(let i=0;i<=segF;i++) t.puntos.push(new THREE.Vector3());
      t.fase = (f/MORFO.flagelo.n) * Math.PI*2;
      const ang = (f/MORFO.flagelo.n) * Math.PI*2;
      t.splayX = Math.cos(ang) * 0.085;
      t.splayZ = Math.sin(ang) * 0.085;
      t.baseX = Math.cos(ang) * 0.09;
      t.baseZ = Math.sin(ang) * 0.09;
      organismo.add(t.malla);
      flagelos.push(t);

      const bulbo = new THREE.Mesh(new THREE.SphereGeometry(0.046, 14, 10), matBulbo);
      organismo.add(bulbo);
      bulbos.push(bulbo);
    }

    // Jeringa molecular (sistema de secreción tipo IV)
    const matJeringa = new THREE.MeshStandardMaterial({
      color:0xFFD500, emissive:0xFFB300, emissiveIntensity:1.4,
      roughness:0.35, transparent:true, opacity:0
    });
    jeringa = new THREE.Mesh(new THREE.CylinderGeometry(0.028, 0.055, 1, 12, 1, true), matJeringa);
    jeringa.position.set(0.12, -0.55, 0.1);
    organismo.add(jeringa);

    for(let i=0;i<5;i++){
      const p = new THREE.Mesh(
        new THREE.SphereGeometry(0.05, 10, 8),
        new THREE.MeshBasicMaterial({ color:0xFFE45C, transparent:true, opacity:0, blending:THREE.AdditiveBlending, depthWrite:false })
      );
      p.userData.off = i/5;
      organismo.add(p);
      pulsosCagA.push(p);
    }
  }

  function construirMucosa(){
    mucosaGrupo = new THREE.Group();
    mucosaGrupo.position.y = -4.15;
    escena.add(mucosaGrupo);

    mucosaBase = new THREE.Mesh(
      new THREE.PlaneGeometry(70,70),
      new THREE.MeshStandardMaterial({ color:0x3A1A1C, roughness:0.95, transparent:true, opacity:0 })
    );
    mucosaBase.rotation.x = -Math.PI/2;
    mucosaBase.position.y = -0.28;
    mucosaGrupo.add(mucosaBase);

    const n = esMovil ? 240 : 460;
    const geoCel = new THREE.SphereGeometry(1, esMovil?14:20, esMovil?10:14);
    geoCel.scale(1, 0.58, 1);
    const matCel = new THREE.MeshPhysicalMaterial({
      roughness:0.52, clearcoat:0.45, clearcoatRoughness:0.4,
      sheen:0.7, sheenColor:new THREE.Color(0xFFB9AE),
      transmission:0.16, thickness:0.5, ior:1.36,
      transparent:true, opacity:0
    });
    mucosaCelulas = new THREE.InstancedMesh(geoCel, matCel, n);
    mucosaCelulas.instanceMatrix.setUsage(THREE.DynamicDrawUsage);
    mucosaCelulas.frustumCulled = false;

    const m = new THREE.Matrix4(), q = new THREE.Quaternion();
    const pos = new THREE.Vector3(), esc = new THREE.Vector3();
    mucosaCelulas.datos = [];
    const cols = Math.ceil(Math.sqrt(n)), sep = 1.02;
    for(let i=0;i<n;i++){
      const cx = i % cols, cz = Math.floor(i/cols);
      const x = (cx - cols/2)*sep + (cz%2)*sep*0.5 + (Math.random()-0.5)*0.22;
      const z = (cz - cols/2)*sep + (Math.random()-0.5)*0.22;
      const r = Math.hypot(x,z);
      const y = -0.06 + Math.sin(x*0.55)*0.14 + Math.cos(z*0.6)*0.13 - r*0.022;
      const s = 0.42 + Math.random()*0.16;
      mucosaCelulas.datos.push({ x, y, z, s, fase: Math.random()*Math.PI*2, caos: Math.random() });
      pos.set(x,y,z); esc.set(s,s,s);
      m.compose(pos, q, esc);
      mucosaCelulas.setMatrixAt(i, m);
      mucosaCelulas.setColorAt(i, COL_SANA);
    }
    mucosaGrupo.add(mucosaCelulas);
  }

  function construirMedio(){
    const spr = texturaPunto();

    const n = esMovil ? 900 : 2200;
    const pos = new Float32Array(n*3);
    for(let i=0;i<n;i++){
      pos[i*3]   = (Math.random()-0.5)*30;
      pos[i*3+1] = (Math.random()-0.5)*22 - 2;
      pos[i*3+2] = (Math.random()-0.5)*30;
    }
    const g = new THREE.BufferGeometry();
    g.setAttribute('position', new THREE.BufferAttribute(pos,3));
    particulasMoco = new THREE.Points(g, new THREE.PointsMaterial({
      size:0.075, map:spr, color:0xBFE3F0, transparent:true, opacity:0.5,
      depthWrite:false, blending:THREE.AdditiveBlending, sizeAttenuation:true
    }));
    escena.add(particulasMoco);

    const na = 340;
    const pa = new Float32Array(na*3);
    for(let i=0;i<na;i++){
      const r = 0.7 + Math.random()*1.6, th = Math.random()*Math.PI*2, ph = Math.acos(2*Math.random()-1);
      pa[i*3]   = r*Math.sin(ph)*Math.cos(th);
      pa[i*3+1] = r*Math.cos(ph);
      pa[i*3+2] = r*Math.sin(ph)*Math.sin(th);
    }
    const ga = new THREE.BufferGeometry();
    ga.setAttribute('position', new THREE.BufferAttribute(pa,3));
    particulasAmoniaco = new THREE.Points(ga, new THREE.PointsMaterial({
      size:0.09, map:spr, color:0x9FF0FF, transparent:true, opacity:0,
      depthWrite:false, blending:THREE.AdditiveBlending
    }));
    organismo.add(particulasAmoniaco);

    const ni = 260;
    const pi = new Float32Array(ni*3);
    for(let i=0;i<ni;i++){
      pi[i*3]   = (Math.random()-0.5)*16;
      pi[i*3+1] = -3.6 + Math.random()*2.6;
      pi[i*3+2] = (Math.random()-0.5)*16;
    }
    const gi = new THREE.BufferGeometry();
    gi.setAttribute('position', new THREE.BufferAttribute(pi,3));
    particulasInmunes = new THREE.Points(gi, new THREE.PointsMaterial({
      size:0.16, map:spr, color:0xFFD500, transparent:true, opacity:0,
      depthWrite:false, blending:THREE.AdditiveBlending
    }));
    escena.add(particulasInmunes);
  }

  /* ---------- Actualización por cuadro ---------- */
  const _colTmp   = new THREE.Color();
  const _colPalid = new THREE.Color(0x8E9AA6);
  const _colCicat = new THREE.Color(0xD9C9B4);
  const _ejeY     = new THREE.Vector3(0,1,0);
  const _mTmp = new THREE.Matrix4(), _qTmp = new THREE.Quaternion();
  const _pTmp = new THREE.Vector3(), _eTmp = new THREE.Vector3();

  function actualizarCuerpo(t){
    const e = MORFO.espiral, c = MORFO.cocoide, k = est.cocoide;
    const L = THREE.MathUtils.lerp(e.L, c.L, k);
    const R = THREE.MathUtils.lerp(e.R, c.R, k);
    const V = THREE.MathUtils.lerp(e.vueltas, c.vueltas, k);
    const RAD = THREE.MathUtils.lerp(e.radio, c.radio, k);
    const seg = cuerpoTubo.seg;
    const flexAmp = 0.055 * (1-k) * (0.35 + est.nado);

    for(let i=0;i<=seg;i++){
      const s = i/seg;
      const a = Math.PI*2*V*(s-0.5);
      const flex = flexAmp * Math.sin(s*4.4 + t*2.1);
      cuerpoTubo.puntos[i].set(
        R*Math.cos(a) + flex,
        L*(s-0.5),
        R*Math.sin(a) + flex*0.4
      );
      const perfil = Math.pow(Math.max(Math.sin(Math.PI*s), 0.0001), THREE.MathUtils.lerp(0.42, 0.62, k));
      cuerpoTubo.radios[i] = Math.max(RAD*perfil, 0.0015);
    }
    cuerpoTubo.actualizar(cuerpoTubo.puntos, cuerpoTubo.radios);

    const mat = cuerpoTubo.malla.material;
    mat.color.copy(mat.userData.colBase).lerp(_colPalid, est.terapia*0.6);
    mat.opacity = 1 - est.terapia*0.42;
    return { L, R, V };
  }

  function actualizarFlagelos(t, geom){
    const f = MORFO.flagelo;
    const kOnda = Math.PI*2 / f.paso;
    const omega = (2.6 + 7.4*est.nado) * Math.PI*2 * 0.16;
    const retraccion = 1 - est.cocoide*0.88;
    const baseY = geom.L*0.5;

    for(let idx=0; idx<flagelos.length; idx++){
      const fl = flagelos[idx];
      const seg = fl.seg;
      const largo = f.largo * retraccion;
      for(let i=0;i<=seg;i++){
        const s = i/seg;
        const env = Math.min(1, s*3.6) * (0.35 + 0.65*est.nado + 0.0);
        const ang = kOnda*(s*largo) - omega*t + fl.fase;
        const amp = f.radioHelice * env;
        fl.puntos[i].set(
          fl.baseX + amp*Math.cos(ang) + fl.splayX*s*largo,
          baseY + s*largo*0.97,
          fl.baseZ + amp*Math.sin(ang) + fl.splayZ*s*largo
        );
        fl.radios[i] = THREE.MathUtils.lerp(0.030, 0.012, s) * retraccion;
      }
      fl.actualizar(fl.puntos, fl.radios);
      fl.malla.material.opacity = (1 - est.cocoide*0.9) * (1 - est.terapia*0.4);
      fl.malla.visible = fl.malla.material.opacity > 0.03;

      const b = bulbos[idx];
      b.position.copy(fl.puntos[seg]);
      b.material.opacity = fl.malla.material.opacity;
      b.visible = fl.malla.visible;
      b.scale.setScalar(retraccion);
    }
  }

  function actualizarMucosa(t){
    const op = est.mucosa;
    mucosaCelulas.material.opacity = op;
    mucosaBase.material.opacity = op*0.95;
    mucosaGrupo.visible = op > 0.02;
    if(op <= 0.02) return;
    mucosaGrupo.position.y = THREE.MathUtils.lerp(-9.5, -4.15, op);

    const col = _colTmp, m = _mTmp, q = _qTmp, p = _pTmp, e = _eTmp;
    const datos = mucosaCelulas.datos;
    const paso = (esMovil || movReducido) ? 3 : 1;
    const desfase = Math.floor(t*20) % paso;

    for(let i=0;i<datos.length;i++){
      const d = datos[i];
      if(paso > 1 && (i % paso) !== desfase) continue;

      col.copy(COL_SANA);
      col.lerp(COL_INFLAM, est.inflama * (0.45 + 0.55*Math.sin(t*1.4 + d.fase)*0.5 + 0.25));
      if(est.correa > 0){
        const c1 = Math.min(1, est.correa*1.7);
        col.lerp(COL_META, c1 * (0.35 + d.caos*0.65));
        if(est.correa > 0.55){
          const c2 = (est.correa-0.55)/0.45;
          col.lerp(COL_DISPL, c2 * d.caos * 0.85);
        }
      }
      col.lerp(_colCicat, est.terapia*0.35);
      mucosaCelulas.setColorAt(i, col);

      const lat = 1 + est.inflama*0.05*Math.sin(t*2.2 + d.fase);
      const desorden = est.correa * d.caos;
      const s = d.s * lat * (1 + desorden*1.05);
      const y = d.y + est.inflama*0.05*Math.sin(t*1.7+d.fase) + desorden*0.42;
      p.set(d.x + desorden*(d.caos-0.5)*0.5, y, d.z);
      e.set(s, s*(1 - desorden*0.25), s);
      q.setFromAxisAngle(_ejeY, desorden*d.caos*3.0);
      m.compose(p, q, e);
      mucosaCelulas.setMatrixAt(i, m);
    }
    mucosaCelulas.instanceMatrix.needsUpdate = true;
    if(mucosaCelulas.instanceColor) mucosaCelulas.instanceColor.needsUpdate = true;
  }

  function actualizarEfectos(t, geom){
    // Ureasa
    haloUreasa.material.opacity = est.ureasa * 0.14;
    haloUreasa.scale.setScalar(1 + Math.sin(t*1.25)*0.05 + est.ureasa*0.1);
    particulasAmoniaco.material.opacity = est.ureasa * 0.75;
    particulasAmoniaco.rotation.y = t*0.16;
    particulasAmoniaco.rotation.x = Math.sin(t*0.22)*0.2;

    // Jeringa T4SS
    const alturaJ = 0.32;
    jeringa.material.opacity = est.t4ss * 0.92;
    jeringa.visible = est.t4ss > 0.03;
    jeringa.scale.set(1, alturaJ, 1);
    jeringa.position.set(0.14, -geom.L*0.32 - alturaJ*0.5, 0.08);
    jeringa.material.emissiveIntensity = 1.1 + Math.sin(t*7)*0.55*est.t4ss;

    for(const p of pulsosCagA){
      const u = ((t*0.55 + p.userData.off) % 1);
      p.material.opacity = est.t4ss * (1-u) * 0.95;
      p.visible = est.t4ss > 0.03;
      p.position.set(0.14, -geom.L*0.32 - u*alturaJ, 0.08);
      p.scale.setScalar(1 - u*0.45);
    }

    // Medio mucoso
    particulasMoco.material.opacity = 0.16 + est.moco*0.36;
    const pm = particulasMoco.geometry.attributes.position;
    const arr = pm.array;
    const vel = 0.006 + est.nado*0.055;
    for(let i=0;i<arr.length;i+=3){
      arr[i+1] += vel;
      arr[i]   += Math.sin(t*0.7 + i)*0.0016;
      arr[i+2] += Math.cos(t*0.6 + i)*0.0016;
      if(arr[i+1] > 12){ arr[i+1] = -12; arr[i] = (Math.random()-0.5)*30; arr[i+2] = (Math.random()-0.5)*30; }
    }
    pm.needsUpdate = true;

    // Neutrófilos
    particulasInmunes.material.opacity = est.inflama * 0.62;
    particulasInmunes.visible = est.inflama > 0.03;
    const pi = particulasInmunes.geometry.attributes.position, ai = pi.array;
    for(let i=0;i<ai.length;i+=3){
      const dx = -ai[i]*0.0018, dz = -ai[i+2]*0.0018;
      ai[i]   += dx + Math.sin(t*1.3+i)*0.004;
      ai[i+2] += dz + Math.cos(t*1.1+i)*0.004;
      ai[i+1] += Math.sin(t*0.9+i)*0.003;
      if(Math.hypot(ai[i],ai[i+2]) < 1.2){
        const a = Math.random()*Math.PI*2, r = 7+Math.random()*6;
        ai[i] = Math.cos(a)*r; ai[i+2] = Math.sin(a)*r; ai[i+1] = -3.6+Math.random()*2.4;
      }
    }
    pi.needsUpdate = true;

    luzVerde.intensity = 26 - est.inflama*10 + Math.sin(t*0.8)*2;
    luzAmbar.intensity = 12 + est.inflama*22 + est.t4ss*10;
    luzAmbar.color.setHex(est.inflama > 0.5 ? 0xFF6A4A : 0xFFD500);
  }

  function animar(){
    rafId = requestAnimationFrame(animar);
    // getDelta() ya acumula elapsedTime: primero el delta, luego el tiempo.
    const dt = Math.min(reloj.getDelta(), 0.05);
    // Embebido en una página: si la escena no está a la vista no se dibuja,
    // pero sí se consume el delta para que la línea de tiempo no salte al
    // volver. Ahorra GPU mientras el visitante lee otra sección.
    if(!visible) return;
    const t = reloj.elapsedTime;

    avanzarLineaDeTiempo(dt);

    // Interpolación de estado
    for(const k in est){
      est[k] = THREE.MathUtils.damp(est[k], objetivo[k], 2.0, dt);
    }

    organismo.position.y = THREE.MathUtils.damp(organismo.position.y, est.orgY, 1.8, dt);
    const deriva = movReducido ? 0 : 1;
    organismo.position.x = Math.sin(t*0.42)*0.16*deriva*(1-est.adhesion*0.85);
    organismo.position.z = Math.cos(t*0.35)*0.13*deriva*(1-est.adhesion*0.85);
    organismo.rotation.y = t*0.10*(0.25+est.nado) * (1-est.adhesion*0.7);
    organismo.rotation.z = Math.sin(t*0.5)*0.09*(1-est.adhesion*0.8);
    organismo.rotation.x = Math.sin(t*0.31)*0.07*(1-est.adhesion*0.8);

    const geom = actualizarCuerpo(t);
    actualizarFlagelos(t, geom);
    actualizarMucosa(t);
    actualizarEfectos(t, geom);

    camara.position.lerp(camPos, 1 - Math.exp(-1.3*dt));
    controles.target.lerp(camTgt, 1 - Math.exp(-1.3*dt));
    controles.update();

    if(bokeh){
      bokeh.uniforms['focus'].value = camara.position.distanceTo(controles.target);
    }

    actualizarInstrumentos(geom, t);
    compositor.render();
  }

  function alRedimensionar(){
    medir();
    const w = _w, h = _h;
    camara.aspect = w/h; camara.updateProjectionMatrix();
    renderer.setSize(w,h);
    compositor.setSize(w,h);
  }

  /* ================================================================
     INTERFAZ Y LÍNEA DE TIEMPO
     ================================================================ */
  let indice = 0, reproduciendo = cfg.autoplay && !movReducido, transcurrido = 0;
  const DURACION = cfg.duracion;
  const objetivo = Object.assign({}, MOMENTOS[0].fx);

  // Los identificadores del original pasan a atributos data-* consultados
  // dentro del contenedor: varias instancias pueden convivir en una misma
  // página y ninguna toca el DOM del tema ni el de otro plugin.
  const $ = n => raiz.querySelector('[data-uhp3d="' + n + '"]');

  function pintarRiel(){
    const cont = $('pasos');
    MOMENTOS.forEach((m,i) => {
      const b = document.createElement('button');
      b.className = 'uhp3d__paso';
      b.type = 'button';
      b.innerHTML = '<i>' + String(i+1).padStart(2,'0') + '</i><b>' + m.corto + '</b>';
      b.addEventListener('click', () => irA(i, true));
      cont.appendChild(b);
    });
  }

  // Solo la pintada inicial de la línea de tiempo; ver centrarPaso().
  let primeraPintada = true;

  /**
   * Centra el paso activo dentro de su carril horizontal.
   *
   * Antes esto era un scrollIntoView sobre el botón. Ese método desplaza
   * TODOS los antepasados desplazables, el documento incluido: con el
   * objeto fuera de la ventana —que es lo normal si no abre la página—,
   * el avance automático de la línea de tiempo arrastraba al visitante
   * hasta aquí sin que lo hubiese pedido. Ahora se mueve solo el carril,
   * calculando su scrollLeft a partir de la posición del botón, y llevar
   * la página hasta el objeto queda detrás del atributo `desplazar` del
   * shortcode, apagado por defecto.
   *
   * @param {HTMLElement} b Botón del paso activo.
   */
  function centrarPaso(b){
    const carril = $('pasos');
    if(carril){
      const rb = b.getBoundingClientRect();
      const rc = carril.getBoundingClientRect();
      const desvio = (rb.left + rb.width/2) - (rc.left + rc.width/2);
      const tope   = Math.max(0, carril.scrollWidth - carril.clientWidth);
      const x      = Math.max(0, Math.min(tope, carril.scrollLeft + desvio));
      if(typeof carril.scrollTo === 'function'){
        carril.scrollTo({ left: x, behavior: movReducido ? 'auto' : 'smooth' });
      } else {
        carril.scrollLeft = x;
      }
    }
    // La primera pintada solo coloca el momento inicial: nadie ha pedido
    // nada todavía, así que ni siquiera con `desplazar` puesto se mueve la
    // página. Lo contrario sería saltar al objeto nada más abrir.
    if(cfg.desplazar && !primeraPintada){
      b.scrollIntoView({ behavior: movReducido ? 'auto' : 'smooth', block:'nearest', inline:'center' });
    }
    primeraPintada = false;
  }

  function irA(i, manual){
    indice = (i + MOMENTOS.length) % MOMENTOS.length;
    transcurrido = 0;
    const m = MOMENTOS[indice];

    $('reloj').textContent = m.reloj;
    $('titulo').textContent = m.titulo;
    $('entradilla').textContent = m.entradilla;
    $('cuerpo').innerHTML = m.cuerpo.map(p => '<p class="uhp3d__parrafo">' + p + '</p>').join('');
    $('cifras').innerHTML = m.cifras.map(c =>
      '<div class="uhp3d__cifra' + (c.alerta ? ' uhp3d__cifra--alerta' : '') + '"><b>' + c.v + '</b><span>' + c.t + '</span></div>'
    ).join('');
    $('fuente').textContent = 'Fuente: ' + m.fuente;
    $('lectura').scrollTop = 0;

    raiz.querySelectorAll('.uhp3d__paso').forEach((b,k) => {
      b.setAttribute('aria-current', k === indice ? 'true' : 'false');
      if(k === indice) centrarPaso(b);
    });

    Object.assign(objetivo, m.fx);
    camPos.set(...m.cam.p);
    camTgt.set(...m.cam.t);
    if(manual && reproduciendo === false){ /* mantiene pausa */ }
  }

  function avanzarLineaDeTiempo(dt){
    if(!reproduciendo) { $('progreso').style.width = (transcurrido/DURACION*100) + '%'; return; }
    transcurrido += dt;
    $('progreso').style.width = Math.min(100, transcurrido/DURACION*100) + '%';
    if(transcurrido >= DURACION) irA(indice+1, false);
  }

  function alternarPlay(){
    reproduciendo = !reproduciendo;
    const b = $('btn-play');
    b.setAttribute('aria-label', reproduciendo ? 'Pausar recorrido' : 'Reanudar recorrido');
    b.title = reproduciendo ? 'Pausar recorrido' : 'Reanudar recorrido';
    $('ico-play').innerHTML = reproduciendo
      ? '<path d="M4 2h3.2v12H4zM8.8 2H12v12H8.8z"/>'
      : '<path d="M4 2l9 6-9 6z"/>';
  }

  const _a = new THREE.Vector3(), _b = new THREE.Vector3();
  let ultimoInstr = 0;
  function actualizarInstrumentos(geom, t){
    if(t - ultimoInstr < 0.25) return;
    ultimoInstr = t;
    const f = MORFO.flagelo, k = est.cocoide;
    $('d-long').innerHTML    = geom.L.toFixed(2).replace('.',',') + ' <em>µm</em>';
    $('d-diam').innerHTML    = (THREE.MathUtils.lerp(MORFO.espiral.radio, MORFO.cocoide.radio, k)*2).toFixed(2).replace('.',',') + ' <em>µm</em>';
    $('d-paso').innerHTML    = (2.50*(1-k) + 0).toFixed(2).replace('.',',') + ' <em>µm</em>';
    $('d-vueltas').textContent = geom.V.toFixed(1).replace('.',',');
    $('d-flag').textContent  = k > 0.6 ? '0 (retraídos)' : String(f.n);
    $('d-flong').innerHTML   = (f.largo*(1-k*0.88)).toFixed(2).replace('.',',') + ' <em>µm</em>';
    $('d-vel').innerHTML     = (est.nado*14).toFixed(1).replace('.',',') + ' <em>µm/s</em>';
    $('d-forma').textContent = k > 0.6 ? 'Cocoide (VBNC)' : (k > 0.25 ? 'En transición' : 'Espiral');

    _a.set(0,0,0).project(camara);
    _b.set(1,0,0).project(camara);
    const px = Math.abs(_b.x - _a.x) * _w * 0.5;
    $('escala-barra').style.width = Math.max(10, Math.min(200, px)) + 'px';
  }

  /* ---------- Arranque ---------- */
  function arrancar(){
    pintarRiel();
    irA(0, false);
    try{
      iniciar();
      setTimeout(() => $('carga').classList.add('is-oculto'), 900);
    }catch(err){
      console.error(err);
      raiz.querySelector('.uhp3d__helice').style.display = 'none';
      $('fallo').classList.add('is-visible');
      $('fallo').innerHTML = 'No fue posible iniciar la escena 3D. Este contenido necesita un navegador con WebGL 2 activo y conexión a la red de distribución de Three.js. Detalle técnico: ' + (err && err.message ? err.message : 'desconocido');
    }
  }


  /* ---------- Controles y teclado ---------- */
  const btnPlay = $('btn-play');
  const btnAtras = $('btn-atras');
  const btnSiguiente = $('btn-siguiente');
  if (btnPlay) { btnPlay.addEventListener('click', alternarPlay); }
  if (btnAtras) { btnAtras.addEventListener('click', () => irA(indice - 1, true)); }
  if (btnSiguiente) { btnSiguiente.addEventListener('click', () => irA(indice + 1, true)); }

  // El teclado se escucha en el contenedor, no en window: embebida en una
  // página, la escena no debe apropiarse de las flechas ni de la barra
  // espaciadora mientras el visitante lee o navega por otra parte.
  raiz.addEventListener('keydown', function (e) {
    if (e.key === 'ArrowRight') { e.preventDefault(); irA(indice + 1, true); }
    else if (e.key === 'ArrowLeft') { e.preventDefault(); irA(indice - 1, true); }
    else if (e.code === 'Space' && e.target === raiz) { e.preventDefault(); alternarPlay(); }
  });

  arrancar();
}

/* ================================================================
   ARRANQUE
   ================================================================ */

/**
 * Pinta el mensaje de error dentro del contenedor indicado.
 *
 * @param {HTMLElement} raiz Contenedor de la instancia.
 * @param {Error}       err  Error capturado.
 */
function mostrarFallo(raiz, err) {
  const helice = raiz.querySelector('.uhp3d__helice');
  const fallo = raiz.querySelector('[data-uhp3d="fallo"]');
  if (helice) { helice.style.display = 'none'; }
  if (!fallo) { return; }
  fallo.classList.add('is-visible');
  // textContent, no innerHTML: el mensaje del error es contenido no
  // confiable (puede venir de la red o del navegador).
  fallo.textContent = 'No fue posible iniciar la escena 3D. Este contenido necesita un navegador con WebGL 2 activo y acceso a la red de distribución de Three.js. Detalle técnico: ' +
    (err && err.message ? err.message : 'desconocido');
}

async function arrancarTodo() {
  const raices = document.querySelectorAll('[data-uhp3d-raiz]');
  if (!raices.length) { return; }

  let libs;
  try {
    libs = await cargarLibrerias();
  } catch (err) {
    Array.prototype.forEach.call(raices, function (r) { mostrarFallo(r, err); });
    return;
  }

  Array.prototype.forEach.call(raices, function (raiz) {
    if (raiz.dataset.uhp3dListo === '1') { return; }
    raiz.dataset.uhp3dListo = '1';
    try {
      crearInstancia(raiz, libs);
    } catch (err) {
      mostrarFallo(raiz, err);
    }
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', arrancarTodo);
} else {
  arrancarTodo();
}
