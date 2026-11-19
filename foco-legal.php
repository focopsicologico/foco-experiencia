<?php
/**
 * Foco · Legal: documentos, consentimientos trazables, cookies y solicitudes de reembolso.
 *
 * Generado por Agente-prototipo-web/execution/preparar_despliegue.py — no editar a mano en el servidor.
 * Lo carga foco-experiencia.php. Qué hace:
 *  1. Publica /terminos, /privacidad, /cookies y /reembolsos desde legal/*.html (versión y vigencia en legal/legal.json).
 *     Los datos del responsable (NIT, dirección…) se llenan en wp-admin › Foco › Datos legales.
 *  2. Guarda cada versión publicada de cada documento (huella SHA-256 + copia exacta) en la tabla foco_documentos.
 *  3. Registra cada aceptación en la tabla foco_consentimientos (solo se agregan filas; nunca se editan ni se borran desde
 *     la administración): fecha y hora UTC, formulario, documento, versión, huella, texto exacto de la casilla, persona,
 *     referencia, IP, navegador y página. Exportable a CSV para responder a la SIC.
 *  4. Banner de cookies (Aceptar / Rechazar / Configurar al mismo nivel). El píxel de Meta solo se activa si se acepta.
 *  5. Formulario de solicitud de reembolso, retracto o reversión del pago (Decreto 587 de 2016) con número de radicado.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( defined( 'FOCO_LEGAL_CARGADO' ) ) { return; }
define( 'FOCO_LEGAL_CARGADO', true );
define( 'FOCO_LEGAL_DB', '1' );
define( 'FOCO_LEGAL_DOCS', array( 'terminos', 'privacidad', 'cookies', 'reembolsos' ) );

/* =========================================================================================================
 * Configuración
 * ======================================================================================================= */

function foco_legal_json() {
	static $j = null;
	if ( null === $j ) {
		$t = @file_get_contents( __DIR__ . '/legal/legal.json' );
		$j = $t ? json_decode( $t, true ) : array();
		if ( ! is_array( $j ) ) { $j = array(); }
	}
	return $j;
}
function foco_legal_cookies_version() { $j = foco_legal_json(); return isset( $j['cookies']['version'] ) ? (string) $j['cookies']['version'] : '1.0'; }

/** Datos del responsable (wp-admin › Foco › Datos legales). Los vacíos se muestran como «por completar». */
function foco_legal_datos() {
	$def = array(
		'responsable' => 'Juan David Ballesteros Díaz (persona natural)', 'documento' => '', 'direccion' => 'Carrera 48 # 16A Sur - 43', 'ciudad' => 'Medellín',
		'correo' => 'focopsicologico1@gmail.com', 'whatsapp' => '+57 317 303 3125', 'tp' => '208744', 'horas_aviso' => '24',
	);
	$o = get_option( 'foco_legal', array() );
	return array_merge( $def, is_array( $o ) ? array_filter( $o, 'strlen' ) : array() );
}
function foco_legal_pendientes() {
	$d = foco_legal_datos(); $n = array( 'responsable' => 'Nombre o razón social', 'documento' => 'Cédula o NIT', 'direccion' => 'Dirección' );
	return array_values( array_intersect_key( $n, array_filter( $n, function ( $k ) use ( $d ) { return '' === trim( (string) $d[ $k ] ); }, ARRAY_FILTER_USE_KEY ) ) );
}

/* =========================================================================================================
 * Base de datos (solo se agregan filas)
 * ======================================================================================================= */

function foco_legal_tabla( $t ) { global $wpdb; return $wpdb->prefix . 'foco_' . $t; }

add_action( 'init', function () {
	if ( get_option( 'foco_legal_db' ) === FOCO_LEGAL_DB ) { return; }
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$c = $wpdb->get_charset_collate();
	dbDelta( 'CREATE TABLE ' . foco_legal_tabla( 'consentimientos' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		creado_utc datetime NOT NULL,
		formulario varchar(60) NOT NULL DEFAULT '',
		documento varchar(40) NOT NULL DEFAULT '',
		version varchar(20) NOT NULL DEFAULT '',
		huella char(64) NOT NULL DEFAULT '',
		aceptado tinyint(1) NOT NULL DEFAULT 0,
		casilla text NOT NULL,
		persona_id bigint(20) unsigned NOT NULL DEFAULT 0,
		referencia varchar(60) NOT NULL DEFAULT '',
		nombre varchar(120) NOT NULL DEFAULT '',
		correo varchar(190) NOT NULL DEFAULT '',
		whatsapp varchar(40) NOT NULL DEFAULT '',
		ip varchar(64) NOT NULL DEFAULT '',
		ip_reenviada varchar(190) NOT NULL DEFAULT '',
		agente varchar(255) NOT NULL DEFAULT '',
		pagina varchar(255) NOT NULL DEFAULT '',
		PRIMARY KEY  (id),
		KEY correo (correo),
		KEY persona_id (persona_id),
		KEY referencia (referencia)
	) $c;" );
	dbDelta( 'CREATE TABLE ' . foco_legal_tabla( 'documentos' ) . " (
		huella char(64) NOT NULL,
		documento varchar(40) NOT NULL DEFAULT '',
		version varchar(20) NOT NULL DEFAULT '',
		creado_utc datetime NOT NULL,
		html longtext NOT NULL,
		PRIMARY KEY  (huella)
	) $c;" );
	update_option( 'foco_legal_db', FOCO_LEGAL_DB, false );
}, 1 );

/* =========================================================================================================
 * Documentos
 * ======================================================================================================= */

/** Documento listo para mostrar: html, huella (SHA-256 del texto exacto), versión, título, vigencia. Guarda la copia. */
function foco_legal_doc( $slug ) {
	static $cache = array();
	if ( isset( $cache[ $slug ] ) ) { return $cache[ $slug ]; }
	$j    = foco_legal_json();
	$meta = isset( $j['documentos'][ $slug ] ) ? $j['documentos'][ $slug ] : array( 'titulo' => ucfirst( $slug ), 'version' => '0', 'vigencia' => '' );
	$base = (string) @file_get_contents( __DIR__ . '/legal/' . $slug . '.html' );
	$d    = foco_legal_datos();
	$etq  = array( 'responsable' => 'nombre o razón social', 'documento' => 'cédula o NIT', 'direccion' => 'dirección' );
	$rep  = array();
	foreach ( $d as $k => $v ) {
		$rep[ '{{' . $k . '}}' ] = '' === trim( (string) $v ) ? '<mark class="legal-pendiente">[por completar: ' . esc_html( isset( $etq[ $k ] ) ? $etq[ $k ] : $k ) . ']</mark>' : esc_html( $v );
	}
	$rep['{{version}}']  = esc_html( $meta['version'] );
	$rep['{{vigencia}}'] = esc_html( foco_legal_fecha( $meta['vigencia'] ) );
	$html = strtr( $base, $rep );
	$huella = hash( 'sha256', $html );
	$html_final = str_replace( '{{formulario_solicitud}}', 'reembolsos' === $slug ? foco_legal_formulario_solicitud() : '', $html );
	$cache[ $slug ] = array( 'slug' => $slug, 'html' => $html_final, 'huella' => $huella, 'version' => (string) $meta['version'],
		'titulo' => (string) $meta['titulo'], 'vigencia' => (string) $meta['vigencia'], '_texto' => $html );
	foco_legal_guardar_copia( $cache[ $slug ] );
	return $cache[ $slug ];
}
function foco_legal_guardar_copia( $doc ) {
	global $wpdb;
	if ( get_option( 'foco_legal_db' ) !== FOCO_LEGAL_DB ) { return; }
	$t = foco_legal_tabla( 'documentos' );
	if ( $wpdb->get_var( $wpdb->prepare( "SELECT huella FROM $t WHERE huella = %s", $doc['huella'] ) ) ) { return; }
	$wpdb->insert( $t, array( 'huella' => $doc['huella'], 'documento' => $doc['slug'], 'version' => $doc['version'], 'creado_utc' => gmdate( 'Y-m-d H:i:s' ), 'html' => $doc['_texto'] ) );
}
function foco_legal_fecha( $ymd ) {
	$m = array( 1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre' );
	$p = explode( '-', (string) $ymd );
	return 3 === count( $p ) ? (int) $p[2] . ' de ' . $m[ (int) $p[1] ] . ' de ' . $p[0] : (string) $ymd;
}

/** Texto exacto de cada casilla (el mismo que ve la persona; viene de legal.json). */
function foco_legal_casilla( $k ) {
	$j = foco_legal_json(); $c = isset( $j['casillas'][ $k ] ) ? $j['casillas'][ $k ] : array();
	if ( 'terminos' === $k ) { return $c['antes'] . $c['enlace'] . $c['medio'] . $c['enlace2'] . $c['despues']; }
	if ( 'privacidad' === $k ) { return $c['antes'] . $c['enlace'] . $c['despues']; }
	return isset( $c['texto'] ) ? (string) $c['texto'] : '';
}

/* =========================================================================================================
 * Páginas públicas: /terminos /privacidad /cookies /reembolsos
 * ======================================================================================================= */

add_action( 'template_redirect', function () {
	$ruta = trim( (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ), '/' );
	$home = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
	if ( $home && 0 === strpos( $ruta, $home . '/' ) ) { $ruta = substr( $ruta, strlen( $home ) + 1 ); }
	if ( ! in_array( $ruta, FOCO_LEGAL_DOCS, true ) ) { return; }
	status_header( 200 );
	nocache_headers();
	header( 'X-LiteSpeed-Cache-Control: no-cache' );
	foco_legal_pagina( foco_legal_doc( $ruta ) );
	exit;
}, 0 );

function foco_legal_pagina( $doc ) {
	$url  = defined( 'FOCO_EXP_URL' ) ? FOCO_EXP_URL : content_url( 'foco-experiencia' );
	$j    = foco_legal_json();
	$nav  = '';
	foreach ( FOCO_LEGAL_DOCS as $s ) {
		$m = $j['documentos'][ $s ];
		$nav .= '<a href="' . esc_url( home_url( '/' . $s ) ) . '"' . ( $s === $doc['slug'] ? ' aria-current="page"' : '' ) . '>' . esc_html( $m['titulo'] ) . '</a>';
	}
	?><!doctype html>
<html lang="es-CO">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $doc['titulo'] ); ?> · Foco Psicológico</title>
<meta name="robots" content="index, follow">
<link rel="icon" href="<?php echo esc_url( $url . '/app/assets/asterisco.png' ); ?>">
<?php wp_head(); ?>
<style>
@font-face { font-family: "Bricolage Grotesque"; src: url("<?php echo esc_url( $url ); ?>/marca/fuentes/bricolage-grotesque-latin-600-normal.woff2") format("woff2"); font-weight: 600; font-display: swap; }
@font-face { font-family: "Hanken Grotesk"; src: url("<?php echo esc_url( $url ); ?>/marca/fuentes/hanken-grotesk-latin-400-normal.woff2") format("woff2"); font-weight: 400; font-display: swap; }
@font-face { font-family: "Hanken Grotesk"; src: url("<?php echo esc_url( $url ); ?>/marca/fuentes/hanken-grotesk-latin-700-normal.woff2") format("woff2"); font-weight: 700; font-display: swap; }
:root { --noche:#26304B; --ciruela:#5B3B64; --durazno:#ECB69A; --papel:#F5F0EA; --salvia-clara:#B7D0B3; --texto:#2A2530; --suave:#5C5560; }
html, body { margin: 0; background: var(--papel); color: var(--texto); }
body.foco-legal { font: 400 17px/1.65 "Hanken Grotesk", system-ui, sans-serif; }
.legal-cab { background: var(--papel); border-bottom: 1px solid rgba(42,37,48,.1); }
.legal-cab-in, .legal-cuerpo, .legal-pie-in { max-width: 820px; margin: 0 auto; padding: 0 20px; }
.legal-cab-in { display: flex; align-items: center; justify-content: space-between; gap: 16px; min-height: 68px; }
.legal-cab img { height: 26px; width: auto; display: block; }
.legal-volver { color: var(--ciruela); font-weight: 700; text-decoration: none; }
.legal-nav { display: flex; flex-wrap: wrap; gap: 8px; margin: 28px 0 8px; }
.legal-nav a { padding: 8px 14px; border-radius: 999px; background: #fff; color: var(--texto); text-decoration: none; font-size: 15px; border: 1px solid rgba(42,37,48,.12); }
.legal-nav a[aria-current] { background: var(--noche); color: #fff; border-color: var(--noche); }
.legal-cuerpo h1 { font: 600 clamp(30px, 5vw, 44px)/1.1 "Bricolage Grotesque", system-ui, sans-serif; color: var(--noche); margin: 24px 0 6px; letter-spacing: -.01em; }
.legal-meta { color: var(--suave); font-size: 15px; margin: 0 0 24px; }
.legal-cuerpo h2 { font: 600 22px/1.25 "Bricolage Grotesque", system-ui, sans-serif; color: var(--ciruela); margin: 36px 0 10px; }
.legal-cuerpo h3 { font-size: 18px; margin: 22px 0 8px; }
.legal-cuerpo a { color: var(--ciruela); }
.legal-intro { font-size: 19px; }
.legal-tabla { width: 100%; border-collapse: collapse; background: #fff; border-radius: 14px; overflow: hidden; font-size: 15px; margin: 8px 0 12px; }
.legal-tabla th, .legal-tabla td { text-align: left; vertical-align: top; padding: 10px 12px; border-bottom: 1px solid rgba(42,37,48,.08); }
.legal-tabla th { background: #EFE6EC; }
.legal-pendiente { background: #FCE7A8; padding: 0 4px; border-radius: 4px; }
.legal-boton-cookies, .legal-form button { font: 700 16px/1 "Hanken Grotesk", system-ui, sans-serif; background: var(--noche); color: #fff; border: 0; border-radius: 999px; padding: 12px 20px; cursor: pointer; }
.legal-form { display: grid; gap: 14px; background: #fff; padding: 22px; border-radius: 18px; margin: 12px 0; }
.legal-form label { display: grid; gap: 6px; font-weight: 700; font-size: 15px; }
.legal-form input, .legal-form select, .legal-form textarea { font: 400 16px/1.4 "Hanken Grotesk", system-ui, sans-serif; padding: 12px 14px; border-radius: 12px; border: 1.5px solid rgba(42,37,48,.22); background: var(--papel); }
.legal-form .check { display: flex; gap: 10px; align-items: flex-start; font-weight: 400; }
.legal-form .check input { width: 20px; height: 20px; margin-top: 3px; flex: none; }
.legal-form .estado { font-weight: 700; }
.legal-form .estado.ok { color: #2F6B3A; } .legal-form .estado.mal { color: #9B2C2C; }
.legal-nota { color: var(--suave); font-size: 15px; }
.legal-pie { background: var(--noche); color: #F5F0EA; margin-top: 56px; padding: 28px 0; font-size: 15px; }
.legal-pie a, .legal-pie button { color: #F5F0EA; background: none; border: 0; font: inherit; text-decoration: underline; cursor: pointer; padding: 0; }
.legal-pie-in { display: flex; flex-wrap: wrap; gap: 14px 22px; }
:focus-visible { outline: 3px solid var(--ciruela); outline-offset: 3px; }
</style>
</head>
<body <?php body_class( 'foco-legal' ); ?>>
<?php wp_body_open(); ?>
<header class="legal-cab"><div class="legal-cab-in">
	<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><img src="<?php echo esc_url( $url . '/app/assets/logo.png' ); ?>" alt="Foco Psicológico"></a>
	<a class="legal-volver" href="<?php echo esc_url( home_url( '/' ) ); ?>">← Volver a Foco</a>
</div></header>
<main class="legal-cuerpo">
	<nav class="legal-nav" aria-label="Documentos legales"><?php echo $nav; // phpcs:ignore ?></nav>
	<h1><?php echo esc_html( $doc['titulo'] ); ?></h1>
	<p class="legal-meta">Versión <?php echo esc_html( $doc['version'] ); ?> · Vigente desde el <?php echo esc_html( foco_legal_fecha( $doc['vigencia'] ) ); ?></p>
	<?php echo $doc['html']; // phpcs:ignore — contenido propio del repositorio ?>
</main>
<footer class="legal-pie"><div class="legal-pie-in"><?php echo $nav; // phpcs:ignore ?><button type="button" onclick="window.focoCookies&&window.focoCookies.abrir()">Configurar cookies</button></div></footer>
<?php wp_footer(); ?>
</body>
</html>
	<?php
}

function foco_legal_formulario_solicitud() {
	$api = esc_url( rest_url( 'foco/v1/solicitud' ) );
	$c = foco_legal_json()['casillas'];
	ob_start(); ?>
<form class="legal-form" id="foco-solicitud" novalidate>
	<label>Nombre completo<input name="nombre" autocomplete="name" required maxlength="80"></label>
	<label>Correo donde te respondemos<input name="correo" type="email" autocomplete="email" required maxlength="120"></label>
	<label>Referencia del pago o de la reserva (si la tienes)<input name="referencia" maxlength="40" placeholder="FOCO-XXXX"></label>
	<label>¿Qué necesitas?
		<select name="tipo" required>
			<option value="">Elige una opción</option>
			<option value="reembolso">Reembolso por cancelación</option>
			<option value="retracto">Derecho de retracto</option>
			<option value="reversion">Reversión del pago</option>
			<option value="otro">Otra queja o reclamo</option>
		</select>
	</label>
	<label data-causal hidden>Motivo de la reversión
		<select name="causal">
			<option value="">Elige el motivo</option>
			<option value="fraude">Fui víctima de fraude</option>
			<option value="no_solicitada">Es una operación que no hice o no pedí</option>
			<option value="no_recibido">No recibí el servicio o producto</option>
			<option value="no_corresponde">Lo que recibí no corresponde a lo que pedí</option>
			<option value="defectuoso">Lo que recibí tiene fallas</option>
		</select>
	</label>
	<label>Valor pagado (opcional)<input name="valor" maxlength="30" inputmode="decimal" placeholder="Ej.: $150.000 o USD 40"></label>
	<label>Cuéntanos qué pasó<textarea name="descripcion" rows="4" maxlength="1000" required></textarea></label>
	<label class="check"><input type="checkbox" name="acepta_terminos"><span><?php echo esc_html( $c['terminos']['antes'] ); ?><a href="/terminos" target="_blank" rel="noopener"><?php echo esc_html( $c['terminos']['enlace'] ); ?></a><?php echo esc_html( $c['terminos']['medio'] ); ?><a href="/reembolsos" target="_blank" rel="noopener"><?php echo esc_html( $c['terminos']['enlace2'] ); ?></a><?php echo esc_html( $c['terminos']['despues'] ); ?></span></label>
	<label class="check"><input type="checkbox" name="acepta_privacidad"><span><?php echo esc_html( $c['privacidad']['antes'] ); ?><a href="/privacidad" target="_blank" rel="noopener"><?php echo esc_html( $c['privacidad']['enlace'] ); ?></a><?php echo esc_html( $c['privacidad']['despues'] ); ?></span></label>
	<input type="text" name="sitio" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px">
	<p class="estado" role="status" aria-live="polite"></p>
	<div><button type="submit">Enviar solicitud</button></div>
</form>
<script>
(function () {
  var f = document.getElementById('foco-solicitud'); if (!f) return;
  var est = f.querySelector('.estado'), causal = f.querySelector('[data-causal]');
  f.tipo.addEventListener('change', function () { causal.hidden = f.tipo.value !== 'reversion'; });
  f.addEventListener('submit', function (e) {
    e.preventDefault();
    var v = function (k) { return (f[k] && f[k].value || '').trim(); };
    var mal = function (m) { est.className = 'estado mal'; est.textContent = m; };
    if (!v('nombre')) return mal('Escribe tu nombre.');
    if (!/^\S+@\S+\.\S+$/.test(v('correo'))) return mal('Revisa tu correo.');
    if (!v('tipo')) return mal('Elige qué necesitas.');
    if (v('tipo') === 'reversion' && !v('causal')) return mal('Elige el motivo de la reversión.');
    if (!v('descripcion')) return mal('Cuéntanos qué pasó.');
    if (!f.acepta_terminos.checked) return mal(<?php echo wp_json_encode( $c['terminos']['error'] ); ?>);
    if (!f.acepta_privacidad.checked) return mal(<?php echo wp_json_encode( $c['privacidad']['error'] ); ?>);
    var b = f.querySelector('button[type=submit]'); b.disabled = true; est.className = 'estado'; est.textContent = 'Enviando…';
    fetch(<?php echo wp_json_encode( $api ); ?>, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ nombre: v('nombre'), correo: v('correo'), referencia: v('referencia'), tipo: v('tipo'), causal: v('causal'), valor: v('valor'),
        descripcion: v('descripcion'), sitio: v('sitio'), pagina: location.pathname,
        aceptaciones: { terminos: f.acepta_terminos.checked, privacidad: f.acepta_privacidad.checked } }) })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (x) {
        if (!x.ok) { b.disabled = false; return mal(x.j.message || 'No se pudo enviar. Intenta de nuevo.'); }
        f.reset(); causal.hidden = true; est.className = 'estado ok';
        est.textContent = 'Recibimos tu solicitud. Tu número de radicado es ' + x.j.radicado + ' (' + x.j.fecha + '). Te enviamos la constancia a tu correo.';
        b.disabled = false;
      })
      .catch(function () { b.disabled = false; mal('No se pudo enviar. Revisa tu conexión o escríbenos al correo.'); });
  });
})();
</script>
	<?php
	return ob_get_clean();
}

/* =========================================================================================================
 * Registro de consentimientos
 * ======================================================================================================= */

function foco_legal_ip() { return isset( $_SERVER['REMOTE_ADDR'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ), 0, 64 ) : ''; }
function foco_legal_ip_reenviada() {
	foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR' ) as $h ) {
		if ( ! empty( $_SERVER[ $h ] ) ) { return substr( $h . '=' . sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) ), 0, 190 ); }
	}
	return '';
}
function foco_legal_agente() { return isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : ''; }

/** Las dos casillas obligatorias, por separado. Devuelve true o WP_Error. */
function foco_legal_validar( $p ) {
	$a = isset( $p['aceptaciones'] ) && is_array( $p['aceptaciones'] ) ? $p['aceptaciones'] : array();
	$j = foco_legal_json();
	if ( empty( $a['terminos'] ) ) { return new WP_Error( 'foco_terminos', $j['casillas']['terminos']['error'], array( 'status' => 400 ) ); }
	if ( empty( $a['privacidad'] ) ) { return new WP_Error( 'foco_privacidad', $j['casillas']['privacidad']['error'], array( 'status' => 400 ) ); }
	return true;
}

/**
 * Agrega una fila por cada documento aceptado (y una por la casilla opcional de contacto, marcada o no).
 * $quien: nombre, email/correo, whatsapp. Devuelve cuántas filas guardó.
 */
function foco_registrar_consentimientos( $formulario, $p, $persona_id = 0, $referencia = '', $quien = array() ) {
	global $wpdb;
	if ( get_option( 'foco_legal_db' ) !== FOCO_LEGAL_DB ) { return 0; }
	$a = isset( $p['aceptaciones'] ) && is_array( $p['aceptaciones'] ) ? $p['aceptaciones'] : array();
	$base = array(
		'creado_utc' => gmdate( 'Y-m-d H:i:s' ), 'formulario' => substr( sanitize_key( str_replace( ':', '_', $formulario ) ), 0, 60 ),
		'persona_id' => (int) $persona_id, 'referencia' => substr( sanitize_text_field( (string) $referencia ), 0, 60 ),
		'nombre' => substr( sanitize_text_field( isset( $quien['nombre'] ) ? $quien['nombre'] : '' ), 0, 120 ),
		'correo' => substr( sanitize_email( isset( $quien['email'] ) ? $quien['email'] : ( isset( $quien['correo'] ) ? $quien['correo'] : '' ) ), 0, 190 ),
		'whatsapp' => substr( preg_replace( '/[^0-9+ ]/', '', isset( $quien['whatsapp'] ) ? (string) $quien['whatsapp'] : '' ), 0, 40 ),
		'ip' => foco_legal_ip(), 'ip_reenviada' => foco_legal_ip_reenviada(), 'agente' => foco_legal_agente(),
		'pagina' => substr( esc_url_raw( home_url( isset( $p['pagina'] ) ? '/' . ltrim( (string) $p['pagina'], '/' ) : '/' ) ), 0, 255 ),
	);
	$filas = array();
	if ( ! empty( $a['terminos'] ) ) {
		foreach ( array( 'terminos', 'reembolsos' ) as $s ) { $filas[] = array( $s, foco_legal_doc( $s ), 1, foco_legal_casilla( 'terminos' ) ); }
	}
	if ( ! empty( $a['privacidad'] ) ) { $filas[] = array( 'privacidad', foco_legal_doc( 'privacidad' ), 1, foco_legal_casilla( 'privacidad' ) ); }
	if ( array_key_exists( 'contacto', $a ) && null !== $a['contacto'] ) {
		$filas[] = array( 'contacto_comercial', foco_legal_doc( 'privacidad' ), empty( $a['contacto'] ) ? 0 : 1, foco_legal_casilla( 'contacto' ) );
	}
	$n = 0;
	foreach ( $filas as $f ) {
		$ok = $wpdb->insert( foco_legal_tabla( 'consentimientos' ), $base + array( 'documento' => $f[0], 'version' => $f[1]['version'], 'huella' => $f[1]['huella'], 'aceptado' => $f[2], 'casilla' => $f[3] ) );
		if ( $ok ) { $n++; }
	}
	return $n;
}

/* =========================================================================================================
 * API: cookies y solicitudes
 * ======================================================================================================= */

add_action( 'rest_api_init', function () {
	register_rest_route( 'foco/v1', '/cookies', array( 'methods' => 'POST', 'callback' => 'foco_legal_api_cookies', 'permission_callback' => '__return_true' ) );
	register_rest_route( 'foco/v1', '/solicitud', array( 'methods' => 'POST', 'callback' => 'foco_legal_api_solicitud', 'permission_callback' => '__return_true' ) );
} );

/** Registro anónimo de la elección de cookies: id aleatorio del navegador, elección, versión y fecha. Sin IP. */
function foco_legal_api_cookies( WP_REST_Request $req ) {
	global $wpdb;
	if ( function_exists( 'foco_limite' ) && ! foco_limite( 'cookies', 30 ) ) { return array( 'ok' => true ); }
	$p = $req->get_json_params(); if ( ! is_array( $p ) ) { $p = array(); }
	$id = preg_replace( '/[^A-Za-z0-9-]/', '', isset( $p['id'] ) ? (string) $p['id'] : '' );
	if ( ! $id || get_option( 'foco_legal_db' ) !== FOCO_LEGAL_DB ) { return array( 'ok' => true ); }
	$doc = foco_legal_doc( 'cookies' );
	$med = ! empty( $p['medicion'] );
	$wpdb->insert( foco_legal_tabla( 'consentimientos' ), array(
		'creado_utc' => gmdate( 'Y-m-d H:i:s' ), 'formulario' => 'banner_cookies', 'documento' => 'cookies_medicion', 'version' => $doc['version'], 'huella' => $doc['huella'],
		'aceptado' => $med ? 1 : 0, 'casilla' => $med ? 'Aceptó medición y publicidad (Meta)' : 'Rechazó medición y publicidad (Meta)', 'referencia' => substr( 'navegador-' . $id, 0, 60 ),
		'agente' => foco_legal_agente(), 'pagina' => substr( esc_url_raw( home_url( '/' . ltrim( (string) ( isset( $p['pagina'] ) ? $p['pagina'] : '' ), '/' ) ) ), 0, 255 ),
	) );
	return array( 'ok' => true );
}

add_action( 'init', function () {
	register_post_type( 'foco_solicitud', array(
		'labels' => array( 'name' => 'Solicitudes', 'singular_name' => 'Solicitud', 'all_items' => 'Solicitudes (reembolsos)', 'not_found' => 'No hay solicitudes.' ),
		'public' => false, 'show_ui' => true, 'show_in_menu' => 'foco', 'show_in_rest' => false, 'exclude_from_search' => true, 'publicly_queryable' => false,
		'supports' => array( 'title' ), 'capability_type' => 'post', 'capabilities' => array( 'create_posts' => 'do_not_allow' ), 'map_meta_cap' => true,
	) );
} );

function foco_legal_api_solicitud( WP_REST_Request $req ) {
	if ( function_exists( 'foco_limite' ) && ! foco_limite( 'solicitud', 5 ) ) { return new WP_Error( 'foco_limite', 'Intenta de nuevo en un rato.', array( 'status' => 429 ) ); }
	$p = $req->get_json_params(); if ( ! is_array( $p ) ) { $p = array(); }
	if ( ! empty( $p['sitio'] ) ) { return new WP_Error( 'foco_spam', 'No se pudo enviar.', array( 'status' => 400 ) ); }
	$txt = function ( $k, $max ) use ( $p ) { return mb_substr( sanitize_text_field( isset( $p[ $k ] ) ? (string) $p[ $k ] : '' ), 0, $max ); };
	$nombre = $txt( 'nombre', 80 ); $correo = sanitize_email( isset( $p['correo'] ) ? $p['correo'] : '' );
	$tipos = array( 'reembolso' => 'Reembolso por cancelación', 'retracto' => 'Derecho de retracto', 'reversion' => 'Reversión del pago', 'otro' => 'Otra queja o reclamo' );
	$causales = array( 'fraude' => 'Fraude', 'no_solicitada' => 'Operación no solicitada', 'no_recibido' => 'Producto o servicio no recibido', 'no_corresponde' => 'No corresponde a lo solicitado', 'defectuoso' => 'Producto o servicio defectuoso' );
	$tipo = isset( $p['tipo'] ) && isset( $tipos[ $p['tipo'] ] ) ? $p['tipo'] : '';
	$causal = isset( $p['causal'] ) && isset( $causales[ $p['causal'] ] ) ? $p['causal'] : '';
	$desc = mb_substr( sanitize_textarea_field( isset( $p['descripcion'] ) ? (string) $p['descripcion'] : '' ), 0, 1000 );
	if ( mb_strlen( $nombre ) < 2 ) { return new WP_Error( 'foco_nombre', 'Escribe tu nombre.', array( 'status' => 400 ) ); }
	if ( ! $correo ) { return new WP_Error( 'foco_correo', 'Revisa tu correo.', array( 'status' => 400 ) ); }
	if ( ! $tipo ) { return new WP_Error( 'foco_tipo', 'Elige qué necesitas.', array( 'status' => 400 ) ); }
	if ( 'reversion' === $tipo && ! $causal ) { return new WP_Error( 'foco_causal', 'Elige el motivo de la reversión.', array( 'status' => 400 ) ); }
	if ( ! $desc ) { return new WP_Error( 'foco_desc', 'Cuéntanos qué pasó.', array( 'status' => 400 ) ); }
	$ok = foco_legal_validar( $p ); if ( is_wp_error( $ok ) ) { return $ok; }

	$ahora = new DateTime( 'now', new DateTimeZone( 'America/Bogota' ) );
	$radicado = 'SOL-' . $ahora->format( 'Ymd' ) . '-' . strtoupper( substr( wp_generate_password( 6, false, false ), 0, 4 ) );
	$id = wp_insert_post( array( 'post_type' => 'foco_solicitud', 'post_status' => 'private', 'post_title' => $radicado . ' · ' . $tipos[ $tipo ] . ' · ' . $nombre ), true );
	if ( is_wp_error( $id ) ) { return new WP_Error( 'foco_guardar', 'No pude guardar tu solicitud. Escríbenos al correo.', array( 'status' => 500 ) ); }
	$fecha = foco_legal_fecha( $ahora->format( 'Y-m-d' ) ) . ', ' . $ahora->format( 'g:i a' ) . ' (hora de Colombia)';
	$meta = array( 'foco_radicado' => $radicado, 'foco_tipo' => $tipo, 'foco_causal' => $causal, 'foco_nombre' => $nombre, 'foco_email' => $correo,
		'foco_referencia' => $txt( 'referencia', 40 ), 'foco_valor' => $txt( 'valor', 30 ), 'foco_descripcion' => $desc, 'foco_fecha_utc' => gmdate( 'Y-m-d H:i:s' ), 'foco_estado' => 'recibida' );
	foreach ( $meta as $k => $v ) { update_post_meta( $id, $k, $v ); }
	foco_registrar_consentimientos( 'solicitud_reembolso', $p, 0, $radicado, array( 'nombre' => $nombre, 'correo' => $correo ) );

	$filas = array( 'Radicado' => esc_html( $radicado ), 'Fecha' => esc_html( $fecha ), 'Tipo' => esc_html( $tipos[ $tipo ] ) );
	if ( $causal ) { $filas['Motivo'] = esc_html( $causales[ $causal ] ); }
	if ( $meta['foco_referencia'] ) { $filas['Referencia'] = esc_html( $meta['foco_referencia'] ); }
	if ( $meta['foco_valor'] ) { $filas['Valor'] = esc_html( $meta['foco_valor'] ); }
	$filas['Lo que nos contaste'] = nl2br( esc_html( $desc ) );
	if ( function_exists( 'foco_correo' ) ) {
		$extra = 'reversion' === $tipo ? ' Recuerda avisar también a tu banco o al emisor de tu tarjeta dentro de los 5 días hábiles siguientes.' : '';
		foco_correo( $correo, 'Recibimos tu solicitud · ' . $radicado, '<p>Hola, ' . esc_html( strtok( $nombre, ' ' ) ) . '. Esta es la constancia de tu solicitud. Te respondemos en máximo 15 días hábiles.' . esc_html( $extra ) . '</p>', $filas );
		$avisos = function_exists( 'foco_ajuste' ) ? foco_ajuste( 'email_avisos' ) : get_option( 'admin_email' );
		foco_correo( $avisos, 'Nueva solicitud: ' . $tipos[ $tipo ] . ' · ' . $radicado, '<p>Entró una solicitud. Tienes máximo 15 días hábiles para responder' . ( 'reversion' === $tipo ? ' (es una reversión del pago: revisa el Decreto 587 de 2016)' : '' ) . '.</p>',
			$filas + array( 'Nombre' => esc_html( $nombre ), 'Correo' => esc_html( $correo ) ), array( 'Ver solicitudes', admin_url( 'edit.php?post_type=foco_solicitud' ) ) );
	}
	return array( 'ok' => true, 'radicado' => $radicado, 'fecha' => $fecha );
}

/* =========================================================================================================
 * Banner de cookies (todo el sitio). El píxel lee la misma clave «foco_cookies».
 * ======================================================================================================= */

add_action( 'wp_footer', function () {
	if ( is_admin() ) { return; }
	$j = foco_legal_json(); $c = isset( $j['cookies'] ) ? $j['cookies'] : array();
	if ( ! $c ) { return; }
	$cfg = array( 'version' => foco_legal_cookies_version(), 'api' => esc_url_raw( rest_url( 'foco/v1/cookies' ) ), 'politica' => home_url( '/cookies' ), 't' => $c );
	?>
<div id="fcb" class="fcb" role="dialog" aria-modal="false" aria-labelledby="fcb-t" hidden>
	<div class="fcb-caja">
		<p class="fcb-t" id="fcb-t"></p>
		<p class="fcb-x"><span data-t="texto"></span> <a data-pol></a></p>
		<div class="fcb-panel" hidden>
			<label class="fcb-op"><input type="checkbox" checked disabled><span><b data-t="necesarias"></b><small data-t="necesarias_texto"></small></span></label>
			<label class="fcb-op"><input type="checkbox" data-med><span><b data-t="medicion"></b><small data-t="medicion_texto"></small></span></label>
		</div>
		<div class="fcb-acc">
			<button type="button" data-a="rechazar"></button>
			<button type="button" data-a="configurar"></button>
			<button type="button" data-a="guardar" hidden></button>
			<button type="button" data-a="aceptar"></button>
		</div>
	</div>
</div>
<style>
.fcb{position:fixed;left:12px;right:12px;bottom:12px;z-index:2147483000;display:flex;justify-content:center;font:400 15px/1.5 "Hanken Grotesk",system-ui,sans-serif;color:#2A2530}
.fcb[hidden]{display:none}
.fcb-caja{max-width:720px;width:100%;background:#FFFDF9;border-radius:22px;box-shadow:0 18px 50px rgba(38,48,75,.28);padding:18px 20px;display:grid;gap:10px;box-sizing:border-box}
.fcb-t{margin:0;font:600 18px/1.2 "Bricolage Grotesque",system-ui,sans-serif;color:#26304B}
.fcb-x{margin:0}.fcb-x a{color:#5B3B64;font-weight:700}
.fcb-panel{display:grid;gap:10px;border-top:1px solid rgba(42,37,48,.1);padding-top:10px}
.fcb-panel[hidden]{display:none}
.fcb-op{display:flex;gap:10px;align-items:flex-start;cursor:pointer}.fcb-op input{width:20px;height:20px;margin-top:2px;flex:none}
.fcb-op span{display:grid}.fcb-op small{color:#5C5560;font-size:14px}
.fcb-acc{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:8px}
.fcb-acc button{font:700 15px/1 "Hanken Grotesk",system-ui,sans-serif;min-height:46px;border-radius:999px;padding:12px 16px;cursor:pointer;border:1.5px solid #26304B;background:#26304B;color:#fff}
.fcb-acc button[hidden]{display:none}
.fcb-acc button:focus-visible{outline:3px solid #5B3B64;outline-offset:2px}
</style>
<script>
(function (w, d) {
  var C = <?php echo wp_json_encode( $cfg ); ?>, K = 'foco_cookies', DIAS = 365;
  var el = d.getElementById('fcb'); if (!el) return;
  var T = C.t, $ = function (s) { return el.querySelector(s); };
  el.querySelector('.fcb-t').textContent = T.titulo;
  el.querySelectorAll('[data-t]').forEach(function (n) { n.textContent = T[n.getAttribute('data-t')] || ''; });
  var pol = $('[data-pol]'); pol.href = C.politica; pol.textContent = 'Política de cookies';
  ['rechazar', 'configurar', 'guardar', 'aceptar'].forEach(function (a) { $('[data-a="' + a + '"]').textContent = T[a]; });
  function leer() { try { var v = JSON.parse(localStorage.getItem(K) || 'null'); if (v && v.v === C.version && (Date.now() - Date.parse(v.fecha)) < DIAS * 864e5) return v; } catch (e) {} return null; }
  function uid() { try { return crypto.randomUUID(); } catch (e) { return 'n' + Date.now().toString(36) + Math.random().toString(36).slice(2); } }
  function guardar(med) {
    var prev = null; try { prev = JSON.parse(localStorage.getItem(K) || 'null'); } catch (e) {}
    var v = { v: C.version, medicion: !!med, fecha: new Date().toISOString(), id: (prev && prev.id) || uid() };
    try { localStorage.setItem(K, JSON.stringify(v)); } catch (e) {}
    try { fetch(C.api, { method: 'POST', headers: { 'Content-Type': 'application/json' }, keepalive: true, body: JSON.stringify({ id: v.id, medicion: v.medicion, version: v.v, pagina: location.pathname }) }); } catch (e) {}
    cerrar();
    try { w.dispatchEvent(new CustomEvent('foco:cookies', { detail: v })); } catch (e) {}
  }
  function abrir(conPanel) {
    var v = leer(); $('[data-med]').checked = !!(v && v.medicion);
    $('.fcb-panel').hidden = !conPanel; $('[data-a="configurar"]').hidden = !!conPanel; $('[data-a="guardar"]').hidden = !conPanel;
    el.hidden = false; setTimeout(function () { var b = $('[data-a="rechazar"]'); if (b) b.focus({ preventScroll: true }); }, 30);
  }
  function cerrar() { el.hidden = true; }
  el.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('[data-a]'); if (!a) return;
    var q = a.getAttribute('data-a');
    if (q === 'aceptar') guardar(true);
    else if (q === 'rechazar') guardar(false);
    else if (q === 'configurar') abrir(true);
    else if (q === 'guardar') guardar($('[data-med]').checked);
  });
  w.focoCookies = { abrir: function () { abrir(true); }, estado: leer };
  if (!leer()) abrir(false);
})(window, document);
</script>
	<?php
}, 5 );

/* =========================================================================================================
 * Administración: Consentimientos, Datos legales y fichas
 * ======================================================================================================= */

add_action( 'admin_menu', function () {
	add_submenu_page( 'foco', 'Consentimientos', 'Consentimientos', 'manage_options', 'foco-consentimientos', 'foco_legal_admin_consentimientos' );
	add_submenu_page( 'foco', 'Datos legales', 'Datos legales', 'manage_options', 'foco-legal', 'foco_legal_admin_datos' );
}, 20 );

add_action( 'admin_init', function () {
	register_setting( 'foco_legal', 'foco_legal', array( 'sanitize_callback' => function ( $v ) {
		$v = is_array( $v ) ? $v : array(); $o = array();
		foreach ( array( 'responsable' => 120, 'documento' => 60, 'direccion' => 160, 'ciudad' => 60, 'whatsapp' => 30, 'tp' => 20 ) as $k => $max ) { $o[ $k ] = mb_substr( sanitize_text_field( isset( $v[ $k ] ) ? $v[ $k ] : '' ), 0, $max ); }
		$o['correo'] = sanitize_email( isset( $v['correo'] ) ? $v['correo'] : '' );
		$o['horas_aviso'] = (string) max( 1, min( 72, (int) ( isset( $v['horas_aviso'] ) ? $v['horas_aviso'] : 24 ) ) );
		return $o;
	} ) );
} );

add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	$f = foco_legal_pendientes();
	if ( $f ) { echo '<div class="notice notice-warning"><p><b>Foco · documentos legales:</b> faltan datos del responsable (' . esc_html( implode( ', ', $f ) ) . '). Complétalos en <a href="' . esc_url( admin_url( 'admin.php?page=foco-legal' ) ) . '">Foco › Datos legales</a>. Mientras tanto, los documentos muestran «por completar».</p></div>'; }
} );

function foco_legal_admin_datos() {
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	$d = foco_legal_datos();
	$campos = array(
		'responsable' => array( 'Nombre o razón social del responsable', 'Persona natural (tu nombre completo) o tu empresa, tal como aparece en el RUT.' ),
		'documento' => array( 'Cédula o NIT', 'Ej.: «C.C. 1.234.567.890» o «NIT 901.234.567-8».' ),
		'direccion' => array( 'Dirección de notificaciones', 'La que aparece en el RUT.' ),
		'ciudad' => array( 'Ciudad', '' ),
		'correo' => array( 'Correo para datos personales y reclamos', '' ),
		'whatsapp' => array( 'WhatsApp de contacto', 'Como quieres que se vea. Ej.: +57 317 303 3125.' ),
		'tp' => array( 'Tarjeta profesional', '' ),
		'horas_aviso' => array( 'Horas de aviso para reprogramar o cancelar sin costo', 'Se usa en la Política de reembolsos.' ),
	);
	echo '<div class="wrap"><h1>Datos legales de Foco</h1><p>Estos datos aparecen en <a href="' . esc_url( home_url( '/terminos' ) ) . '" target="_blank">Términos</a>, <a href="' . esc_url( home_url( '/privacidad' ) ) . '" target="_blank">Privacidad</a>, <a href="' . esc_url( home_url( '/cookies' ) ) . '" target="_blank">Cookies</a> y <a href="' . esc_url( home_url( '/reembolsos' ) ) . '" target="_blank">Reembolsos</a>. Cada cambio crea una nueva huella del documento, y el registro de consentimientos guarda cuál versión aceptó cada persona.</p><form method="post" action="options.php">';
	settings_fields( 'foco_legal' );
	echo '<table class="form-table" role="presentation">';
	foreach ( $campos as $k => $c ) {
		echo '<tr><th scope="row"><label for="fl_' . esc_attr( $k ) . '">' . esc_html( $c[0] ) . '</label></th><td><input class="regular-text" id="fl_' . esc_attr( $k ) . '" name="foco_legal[' . esc_attr( $k ) . ']" value="' . esc_attr( $d[ $k ] ) . '">' . ( $c[1] ? '<p class="description">' . esc_html( $c[1] ) . '</p>' : '' ) . '</td></tr>';
	}
	echo '</table>';
	submit_button( 'Guardar datos legales' );
	echo '</form></div>';
}

function foco_legal_buscar( $q, $limite ) {
	global $wpdb; $t = foco_legal_tabla( 'consentimientos' );
	if ( '' === $q ) { return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t ORDER BY id DESC LIMIT %d", $limite ), ARRAY_A ); }
	$like = '%' . $wpdb->esc_like( $q ) . '%';
	return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE correo LIKE %s OR whatsapp LIKE %s OR nombre LIKE %s OR referencia LIKE %s ORDER BY id DESC LIMIT %d", $like, $like, $like, $like, $limite ), ARRAY_A );
}

function foco_legal_admin_consentimientos() {
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	$q = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
	$filas = get_option( 'foco_legal_db' ) === FOCO_LEGAL_DB ? foco_legal_buscar( $q, 300 ) : array();
	echo '<div class="wrap"><h1>Registro de consentimientos</h1>';
	echo '<p>Cada fila es una aceptación (o una elección de cookies). Las filas no se pueden editar ni borrar desde aquí: son tu evidencia ante la SIC. La hora está en UTC (Colombia = UTC − 5).</p>';
	echo '<form method="get" style="margin:12px 0"><input type="hidden" name="page" value="foco-consentimientos"><input type="search" name="q" value="' . esc_attr( $q ) . '" placeholder="Correo, WhatsApp, nombre o referencia" class="regular-text"> <button class="button">Buscar</button> ';
	echo '<a class="button button-primary" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=foco_exportar_consentimientos&q=' . rawurlencode( $q ) ), 'foco_exportar_consentimientos' ) ) . '">Descargar CSV' . ( $q ? ' (esta búsqueda)' : ' (todo)' ) . '</a></form>';
	echo '<table class="widefat striped"><thead><tr><th>Fecha (UTC)</th><th>Persona</th><th>Formulario</th><th>Documento</th><th>Versión</th><th>¿Aceptó?</th><th>Referencia</th><th>IP</th></tr></thead><tbody>';
	if ( ! $filas ) { echo '<tr><td colspan="8">Todavía no hay registros' . ( $q ? ' para esa búsqueda' : '' ) . '.</td></tr>'; }
	foreach ( $filas as $f ) {
		echo '<tr><td>' . esc_html( $f['creado_utc'] ) . '</td><td>' . esc_html( trim( $f['nombre'] . ' ' . $f['correo'] . ' ' . $f['whatsapp'] ) ?: '—' ) . '</td><td>' . esc_html( $f['formulario'] ) . '</td><td>' . esc_html( $f['documento'] ) . '</td><td>' . esc_html( $f['version'] ) . ' <small title="' . esc_attr( $f['huella'] ) . '">(' . esc_html( substr( $f['huella'], 0, 8 ) ) . ')</small></td><td>' . ( $f['aceptado'] ? 'Sí' : 'No' ) . '</td><td>' . esc_html( $f['referencia'] ) . '</td><td>' . esc_html( $f['ip'] ) . '</td></tr>';
	}
	echo '</tbody></table></div>';
}

add_action( 'admin_post_foco_exportar_consentimientos', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'foco_exportar_consentimientos' ) ) { wp_die( 'Sin permiso.' ); }
	$q = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
	$filas = foco_legal_buscar( $q, 100000 );
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=foco-consentimientos-' . gmdate( 'Ymd-His' ) . '.csv' );
	$o = fopen( 'php://output', 'w' );
	fwrite( $o, "\xEF\xBB\xBF" );
	fputcsv( $o, array( 'id', 'fecha_utc', 'formulario', 'documento', 'version', 'huella_sha256', 'acepto', 'texto_casilla', 'persona_id', 'referencia', 'nombre', 'correo', 'whatsapp', 'ip', 'ip_reenviada', 'navegador', 'pagina' ) );
	foreach ( $filas as $f ) {
		fputcsv( $o, array( $f['id'], $f['creado_utc'], $f['formulario'], $f['documento'], $f['version'], $f['huella'], $f['aceptado'] ? 'si' : 'no', $f['casilla'], $f['persona_id'], $f['referencia'], $f['nombre'], $f['correo'], $f['whatsapp'], $f['ip'], $f['ip_reenviada'], $f['agente'], $f['pagina'] ) );
	}
	fclose( $o );
	exit;
} );

/** En la ficha de cada persona, reserva o solicitud: sus consentimientos. */
add_action( 'add_meta_boxes', function () {
	foreach ( array( 'foco_persona', 'foco_reserva', 'foco_solicitud' ) as $tipo ) {
		add_meta_box( 'foco_consentimientos', 'Consentimientos (evidencia)', function ( $post ) {
			global $wpdb; $t = foco_legal_tabla( 'consentimientos' );
			if ( 'foco_persona' === $post->post_type ) { $filas = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE persona_id = %d ORDER BY id DESC LIMIT 100", $post->ID ), ARRAY_A ); }
			else { $ref = get_post_meta( $post->ID, 'foco_solicitud' === $post->post_type ? 'foco_radicado' : 'foco_ref', true ); $filas = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE referencia = %s ORDER BY id DESC LIMIT 100", $ref ), ARRAY_A ); }
			if ( ! $filas ) { echo '<p>Sin registros (pudo llegar antes de que existiera el registro de consentimientos).</p>'; return; }
			echo '<table class="widefat striped"><thead><tr><th>Fecha (UTC)</th><th>Documento</th><th>Versión</th><th>¿Aceptó?</th><th>Formulario</th><th>IP</th></tr></thead><tbody>';
			foreach ( $filas as $f ) { echo '<tr><td>' . esc_html( $f['creado_utc'] ) . '</td><td>' . esc_html( $f['documento'] ) . '</td><td>' . esc_html( $f['version'] ) . '</td><td>' . ( $f['aceptado'] ? 'Sí' : 'No' ) . '</td><td>' . esc_html( $f['formulario'] ) . '</td><td>' . esc_html( $f['ip'] ) . '</td></tr>'; }
			echo '</tbody></table>';
		}, $tipo, 'normal', 'default' );
	}
	add_meta_box( 'foco_solicitud_ficha', 'Solicitud', function ( $post ) {
		$k = array( 'foco_radicado' => 'Radicado', 'foco_fecha_utc' => 'Fecha (UTC)', 'foco_tipo' => 'Tipo', 'foco_causal' => 'Motivo', 'foco_nombre' => 'Nombre', 'foco_email' => 'Correo', 'foco_referencia' => 'Referencia', 'foco_valor' => 'Valor', 'foco_descripcion' => 'Descripción' );
		echo '<table class="form-table">';
		foreach ( $k as $m => $e ) { echo '<tr><th>' . esc_html( $e ) . '</th><td>' . nl2br( esc_html( (string) get_post_meta( $post->ID, $m, true ) ) ) . '</td></tr>'; }
		echo '</table><p>Responde por correo en máximo 15 días hábiles. Si apruebas un reembolso, hazlo desde Wompi o PayPal en máximo 30 días calendario.</p>';
	}, 'foco_solicitud', 'normal', 'high' );
} );
