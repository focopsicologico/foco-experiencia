<?php
/**
 * Foco · Medición (píxel de Meta)
 *
 * FUENTE ÚNICA: Agente-Programador-web/wordpress/foco-pixel.php. Las copias dentro del paquete de despliegue
 * (foco-experiencia/) las deja `execution/instalar_pixel.py`: no editarlas a mano.
 *
 * Se carga desde foco-experiencia.php (el paquete que Hostinger copia desde GitHub), así que viaja con cada despliegue
 * y no hace falta tocar los mu-plugins de Hostinger. Funciona en todo el sitio porque se engancha a wp_head.
 *
 * Qué hace:
 *  1. Instala el código base del píxel (sin plugins) con «autoConfig» apagado: Meta no lee formularios ni botones.
 *  2. Filtra TODO lo que la app envía: solo salen 5 eventos y, como mucho, el nombre del juego o del programa.
 *     Nunca perfil, lectura, textos escritos, datos de contacto, montos ni palabras clave.
 *  3. «Purchase» no sale desde el navegador (hoy los pagos son de ejemplo; la compra real la confirmará el servidor).
 *  4. Envía un PageView por pantalla (la app navega con # y pushState, que no dispara «hashchange»),
 *     salvo en las pantallas reservadas (ayuda, mi-espacio, lectura, relato).
 *  5. Si la persona llega directo a un juego o programa (#juego-maleta, #programa-ldc, #para-lejos), manda el ViewContent
 *     que la app no manda en ese caso (la app solo lo manda al elegir un juego desde la bienvenida).
 *  6. Cualquier clic a un enlace de WhatsApp de Foco cuenta como «Contact» (Marketing: requisitos-tracking-web.md §3), en toda
 *     la web, salvo en las pantallas reservadas. Los WhatsApp de líneas de ayuda de terceros NO cuentan.
 *  7. Etiqueta de verificación de dominio de Meta, si se define FOCO_META_DOMINIO_VERIFICACION.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
// Guardia contra doble carga. Ojo: no usar function_exists() aquí; PHP declara las funciones de este archivo antes de ejecutarlo.
if ( defined( 'FOCO_PIXEL_CARGADO' ) ) { return; }
define( 'FOCO_PIXEL_CARGADO', true );

// ID del píxel de Meta (Administrador de eventos › Orígenes de datos). Se puede sobrescribir en wp-config.php.
if ( ! defined( 'FOCO_META_PIXEL_ID' ) ) { define( 'FOCO_META_PIXEL_ID', '1478966470829452' ); }
// WhatsApp de Foco (solo dígitos, con indicativo). Debe coincidir con whatsapp_numero de la experiencia (config.json).
if ( ! defined( 'FOCO_WHATSAPP_NUMERO' ) ) { define( 'FOCO_WHATSAPP_NUMERO', '573173033125' ); }
// Solo el valor de content="" de la etiqueta <meta name="facebook-domain-verification" …>. Vacío = no se imprime.
if ( ! defined( 'FOCO_META_DOMINIO_VERIFICACION' ) ) { define( 'FOCO_META_DOMINIO_VERIFICACION', '' ); }

function foco_pixel_js() {
	return <<<'JS'
(function (w, d) {
  if (w.fbq || w.__focoPixel) { return; }
  var ID = '__ID__', WA = '__WA__';
  var registro = w.__focoPixel = { id: ID, enviados: [], descartados: [] };

  // Eventos que SÍ pueden salir hacia Meta. «Purchase» queda fuera a propósito: hoy los pagos son de ejemplo y,
  // cuando sean reales, la compra la confirmará el servidor (webhook), no el navegador.
  var PERMITIDOS = { ViewContent: 1, Lead: 1, Contact: 1, Schedule: 1, InitiateCheckout: 1 };
  // Pantallas que Meta no debe ver (ni siquiera su nombre en la URL).
  var RESERVADAS = { 'ayuda': 1, 'mi-espacio': 1, 'lectura': 1, 'relato': 1 };

  var ultimoContacto = 0;
  function limpio(v) { v = String(v == null ? '' : v); return /^[A-Za-z0-9_-]{1,60}$/.test(v) ? v : ''; }
  function anotar(lista, x) { lista.push(x); if (lista.length > 200) { lista.shift(); } }

  // De todo lo que manda la app, solo se conserva el nombre del juego o del programa/camino.
  function parametros(p) {
    p = p || {};
    var nombre = '';
    if (p.entrada) { nombre = p.entrada === 'relato' ? 'relato' : (limpio(p.entrada) ? 'juego-' + limpio(p.entrada) : ''); }
    if (!nombre) { nombre = limpio(p.camino) || limpio(p.content_name); }
    return nombre ? { content_name: nombre } : {};
  }

  // Código base de Meta (cola hasta que cargue fbevents.js), con el filtro como puerta de entrada.
  var n = function () {
    var a = Array.prototype.slice.call(arguments), cmd = a[0], ev = a[1];
    if (cmd === 'consent') { crudo(a); return; }
    if (cmd === 'track' && PERMITIDOS[ev]) {
      if (ev === 'Contact') { // un clic a WhatsApp lo ven dos vigilantes (este y la app): que cuente una sola vez
        var ahora = Date.now();
        if (ahora - ultimoContacto < 1500) { anotar(registro.descartados, 'Contact:repetido'); return; }
        ultimoContacto = ahora;
      }
      var p = parametros(a[2]);
      anotar(registro.enviados, { evento: ev, parametros: p, pantalla: pantalla() });
      crudo(['track', ev, p]);
      return;
    }
    anotar(registro.descartados, String(cmd) + ':' + String(ev));
  };
  function crudo(a) { if (n.callMethod) { n.callMethod.apply(n, a); } else { n.queue.push(a); } }
  n.push = n; n.loaded = true; n.version = '2.0'; n.queue = [];
  // Se fija window.fbq: ni la librería de Meta ni otro script pueden cambiarlo por una versión sin filtro.
  try { Object.defineProperty(w, 'fbq', { get: function () { return n; }, set: function () {}, configurable: false }); } catch (e) { w.fbq = n; }
  if (!w._fbq) { w._fbq = n; }

  // Páginas vistas: una por pantalla (cambia el # o la ruta), sin repetir y sin las pantallas reservadas.
  var ultima = null;
  function pantalla() { return (w.location.hash || '').replace(/^#/, ''); }
  function pageview() {
    var clave = w.location.pathname + '#' + pantalla();
    if (clave === ultima) { return; }
    ultima = clave;
    if (RESERVADAS[pantalla()]) { anotar(registro.descartados, 'PageView:' + pantalla()); return; }
    anotar(registro.enviados, { evento: 'PageView', parametros: {}, pantalla: pantalla() });
    crudo(['track', 'PageView']);
  }

  crudo(['set', 'autoConfig', false, ID]);   // Meta no lee formularios ni botones, ni hace coincidencia avanzada automática
  crudo(['init', ID]);
  pageview();

  // Quien llega directo a un juego o programa (#juego-maleta, #programa-ldc, #para-lejos) se salta la bienvenida,
  // que es donde la app envía ViewContent. Se manda aquí, una sola vez, con el nombre del juego/programa.
  var m = /^#(juego|programa|para)-([A-Za-z0-9-]+)$/.exec(w.location.hash || '');
  if (m && limpio(m[1] + '-' + m[2])) { n('track', 'ViewContent', { content_name: m[1] + '-' + m[2] }); }

  // Clic a cualquier enlace de WhatsApp de Foco = Contact (también en el resto del sitio, no solo en la experiencia).
  d.addEventListener('click', function (e) {
    var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
    if (!a || RESERVADAS[pantalla()]) { return; }
    var h = a.getAttribute('href') || '';
    if (h.indexOf('https://wa.me/' + WA) === 0 || h.indexOf('https://api.whatsapp.com/send?phone=' + WA) === 0) { n('track', 'Contact', {}); }
  }, true);

  // La app navega con history.pushState, que NO dispara «hashchange»: hay que escuchar los dos.
  ['pushState', 'replaceState'].forEach(function (met) {
    var original = w.history[met];
    w.history[met] = function () { var r = original.apply(this, arguments); pageview(); return r; };
  });
  w.addEventListener('hashchange', pageview);
  w.addEventListener('popstate', pageview);

  var t = d.createElement('script');
  t.async = true; t.src = 'https://connect.facebook.net/en_US/fbevents.js';
  var s = d.getElementsByTagName('script')[0];
  if (s && s.parentNode) { s.parentNode.insertBefore(t, s); } else { d.head.appendChild(t); }
})(window, document);
JS;
}

function foco_pixel_imprimir() {
	$id = preg_replace( '/\D/', '', (string) FOCO_META_PIXEL_ID );
	if ( '' === $id ) { return; }
	$verif = trim( (string) FOCO_META_DOMINIO_VERIFICACION );
	if ( '' !== $verif ) {
		echo '<meta name="facebook-domain-verification" content="' . esc_attr( $verif ) . '" />' . "\n";
	}
	$wa = preg_replace( '/\D/', '', (string) FOCO_WHATSAPP_NUMERO );
	echo "<script>\n" . str_replace( array( '__ID__', '__WA__' ), array( $id, $wa ), foco_pixel_js() ) . "\n</script>\n";
}
add_action( 'wp_head', 'foco_pixel_imprimir', 1 );
