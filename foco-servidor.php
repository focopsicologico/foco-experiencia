<?php
/**
 * Foco · Servidor (personas, agenda y reservas) — fase 1.
 *
 * Generado por Agente-prototipo-web/execution/plantillas/despliegue/foco-servidor.php — no editar en el sitio.
 * Lo carga foco-experiencia.php. No crea tablas: guarda en tipos de contenido privados de WordPress.
 *
 *  - Personas (leads): nombre, WhatsApp, correo, de dónde llegó, programa de interés y su consentimiento (Ley 1581).
 *    Nunca guarda lo que la persona escribió en los juegos ni su «lectura».
 *  - Agenda: horario base de la experiencia (foco-datos.json) menos lo ocupado en Google Calendar (enlace iCal secreto)
 *    y menos las reservas vigentes.
 *  - Reservas: quedan «pendientes de pago» y apartan el horario unas horas. El pago (Bre-B / Nequi / Bancolombia, o PayPal
 *    en dólares) lo confirma JuanDa a mano en «Foco › Reservas». La web nunca marca nada como pagado.
 *
 * Ajustes (llave Bre-B, correo de avisos, enlace iCal, etc.): Escritorio de WordPress › Foco › Ajustes.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( defined( 'FOCO_SERVIDOR_CARGADO' ) ) { return; }
define( 'FOCO_SERVIDOR_CARGADO', true );

const FOCO_TZ        = 'America/Bogota';
const FOCO_PROGRAMAS = array( 'ynsa', 'ldc', 'ado', 'par', 'fmq', 'hqh' );
const FOCO_ORIGENES  = array( 'guia', 'espacio', 'reserva', 'contacto' );

/* =========================================================================================================
 * Ajustes y datos
 * ======================================================================================================= */

function foco_ajustes() {
	$def = array(
		'reservas'       => '',
		'email_avisos'   => get_option( 'admin_email' ),
		'breb_llave'     => '',
		'breb_titular'   => '',
		'breb_nota'      => 'Desde Nequi, Bancolombia o cualquier banco, con Bre-B.',
		'paypal_me'      => '',
		'horas_pago'     => 24,
		'ical_url'       => '',
		'videollamada'   => '',
	);
	$o = get_option( 'foco_ajustes', array() );
	return array_merge( $def, is_array( $o ) ? $o : array() );
}
function foco_ajuste( $k ) { $a = foco_ajustes(); return isset( $a[ $k ] ) ? $a[ $k ] : ''; }
function foco_reservas_activas() { return foco_ajuste( 'reservas' ) === '1'; }

/** Horario base y precios publicados con la experiencia (los deja preparar_despliegue.py). */
function foco_datos() {
	static $d = null;
	if ( null === $d ) {
		$j = @file_get_contents( __DIR__ . '/foco-datos.json' );
		$d = $j ? json_decode( $j, true ) : array();
		if ( ! is_array( $d ) ) { $d = array(); }
	}
	return $d;
}
function foco_tz() { return new DateTimeZone( FOCO_TZ ); }

/** Lo que la experiencia necesita saber del servidor. Sin secretos: la llave Bre-B es pública por diseño. */
function foco_config_publica() {
	$a = foco_ajustes();
	return array(
		'url'      => esc_url_raw( rest_url( 'foco/v1/' ) ),
		'leads'    => true,
		'reservas' => foco_reservas_activas(),
		'pago'     => array(
			'breb_llave'   => $a['breb_llave'],
			'breb_titular' => $a['breb_titular'],
			'breb_nota'    => $a['breb_nota'],
			'paypal_me'    => $a['paypal_me'],
			'horas'        => (int) $a['horas_pago'],
		),
	);
}
add_action( 'wp_head', function () {
	if ( function_exists( 'foco_exp_aplica' ) && ! foco_exp_aplica() ) { return; }
	echo "<script>window.FOCO_API=" . wp_json_encode( foco_config_publica() ) . ";</script>\n";
}, 2 );

/* =========================================================================================================
 * Tipos de contenido privados
 * ======================================================================================================= */

add_action( 'init', function () {
	$comun = array(
		'public'              => false,
		'show_ui'             => true,
		'show_in_menu'        => 'foco',
		'show_in_rest'        => false,
		'exclude_from_search' => true,
		'publicly_queryable'  => false,
		'supports'            => array( 'title' ),
		'capability_type'     => 'post',
		'capabilities'        => array( 'create_posts' => 'do_not_allow' ),
		'map_meta_cap'        => true,
	);
	register_post_type( 'foco_persona', $comun + array( 'labels' => array( 'name' => 'Personas', 'singular_name' => 'Persona', 'all_items' => 'Personas', 'search_items' => 'Buscar personas', 'not_found' => 'Todavía no hay personas.' ) ) );
	register_post_type( 'foco_reserva', $comun + array( 'labels' => array( 'name' => 'Reservas', 'singular_name' => 'Reserva', 'all_items' => 'Reservas', 'search_items' => 'Buscar reservas', 'not_found' => 'Todavía no hay reservas.' ) ) );
} );

/* =========================================================================================================
 * Utilidades
 * ======================================================================================================= */

function foco_txt( $v, $max = 120 ) { return mb_substr( sanitize_text_field( (string) $v ), 0, $max ); }
function foco_digitos( $v ) { return preg_replace( '/\D+/', '', (string) $v ); }
function foco_ip_hash() { return hash( 'sha256', ( isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '' ) . wp_salt( 'nonce' ) ); }

/** Límite simple contra abuso: $max solicitudes por hora y por IP para cada acción. */
function foco_limite( $accion, $max ) {
	$k = 'foco_lim_' . $accion . '_' . substr( foco_ip_hash(), 0, 24 );
	$n = (int) get_transient( $k );
	if ( $n >= $max ) { return false; }
	set_transient( $k, $n + 1, HOUR_IN_SECONDS );
	return true;
}

function foco_utm( $u ) {
	$out = array();
	if ( is_array( $u ) ) {
		foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term' ) as $k ) {
			if ( ! empty( $u[ $k ] ) ) { $out[ $k ] = foco_txt( $u[ $k ], 80 ); }
		}
	}
	return $out;
}

function foco_dinero( $valor, $moneda ) {
	$valor = (int) $valor;
	return 'USD' === $moneda ? 'USD ' . number_format( $valor, 0, ',', '.' ) : '$' . number_format( $valor, 0, ',', '.' );
}
function foco_fecha_larga( $ts ) {
	$dias  = array( 'domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado' );
	$meses = array( 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre' );
	$d = ( new DateTime( '@' . $ts ) )->setTimezone( foco_tz() );
	$h = (int) $d->format( 'G' ); $m = $d->format( 'i' );
	$hora = ( $h % 12 ?: 12 ) . ':' . $m . ( $h < 12 ? ' a. m.' : ' p. m.' );
	return $dias[ (int) $d->format( 'w' ) ] . ' ' . $d->format( 'j' ) . ' de ' . $meses[ (int) $d->format( 'n' ) - 1 ] . ', ' . $hora . ' (hora Colombia)';
}
function foco_enlace_calendario( $inicio, $fin, $titulo, $detalle = '' ) {
	$f = function ( $ts ) { return gmdate( 'Ymd\THis\Z', $ts ); };
	return 'https://calendar.google.com/calendar/render?' . http_build_query( array( 'action' => 'TEMPLATE', 'text' => $titulo, 'dates' => $f( $inicio ) . '/' . $f( $fin ), 'details' => $detalle ) );
}
function foco_wa( $numero, $texto ) { return 'https://wa.me/' . foco_digitos( $numero ) . '?text=' . rawurlencode( $texto ); }
function foco_whatsapp_foco() { return defined( 'FOCO_WHATSAPP_NUMERO' ) ? FOCO_WHATSAPP_NUMERO : '573173033125'; }

/** Correo sencillo con la marca. $filas: [etiqueta => valor]. */
function foco_correo( $para, $asunto, $intro, $filas = array(), $boton = null, $pie = '' ) {
	$t = '<div style="font-family:Arial,sans-serif;background:#F5F0EA;padding:24px"><div style="max-width:560px;margin:auto;background:#fff;border-radius:18px;padding:28px;color:#2A2530">';
	$t .= '<p style="font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#5B3B64;font-weight:bold;margin:0 0 12px">Foco Psicológico</p>';
	$t .= '<div style="font-size:16px;line-height:1.55">' . $intro . '</div>';
	if ( $filas ) {
		$t .= '<table style="width:100%;border-collapse:collapse;margin:18px 0;font-size:15px">';
		foreach ( $filas as $k => $v ) { $t .= '<tr><td style="padding:8px 0;color:#5C5560;width:40%;vertical-align:top">' . esc_html( $k ) . '</td><td style="padding:8px 0;font-weight:bold">' . $v . '</td></tr>'; }
		$t .= '</table>';
	}
	if ( $boton ) { $t .= '<p><a href="' . esc_url( $boton[1] ) . '" style="display:inline-block;background:#5B3B64;color:#fff;text-decoration:none;padding:12px 20px;border-radius:999px;font-weight:bold">' . esc_html( $boton[0] ) . '</a></p>'; }
	if ( $pie ) { $t .= '<p style="font-size:13px;color:#5C5560;margin-top:20px">' . $pie . '</p>'; }
	$t .= '</div></div>';
	return wp_mail( $para, $asunto, $t, array( 'Content-Type: text/html; charset=UTF-8' ) );
}

/* =========================================================================================================
 * Personas (leads)
 * ======================================================================================================= */

/** Crea o actualiza a la persona por correo o WhatsApp. Devuelve [id, es_nueva]. */
function foco_guardar_persona( $d ) {
	$email = isset( $d['email'] ) ? sanitize_email( $d['email'] ) : '';
	$wa    = isset( $d['whatsapp'] ) ? foco_digitos( $d['whatsapp'] ) : '';
	$id    = 0;
	foreach ( array( array( 'foco_email', $email ), array( 'foco_wa', $wa ) ) as $par ) {
		if ( $id || ! $par[1] ) { continue; }
		$q = get_posts( array( 'post_type' => 'foco_persona', 'post_status' => 'private', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => $par[0], 'meta_value' => $par[1] ) );
		if ( $q ) { $id = (int) $q[0]; }
	}
	$nueva = ! $id;
	if ( $nueva ) {
		$id = wp_insert_post( array( 'post_type' => 'foco_persona', 'post_status' => 'private', 'post_title' => $d['nombre'] ), true );
		if ( is_wp_error( $id ) ) { return array( 0, false ); }
	} else {
		wp_update_post( array( 'ID' => $id, 'post_title' => $d['nombre'] ) );
	}
	if ( $email ) { update_post_meta( $id, 'foco_email', $email ); }
	if ( $wa ) { update_post_meta( $id, 'foco_wa', $wa ); update_post_meta( $id, 'foco_wa_texto', foco_txt( $d['whatsapp'], 30 ) ); }
	if ( ! empty( $d['pais'] ) ) { update_post_meta( $id, 'foco_pais', foco_txt( $d['pais'], 40 ) ); }
	foreach ( array( 'foco_origenes' => 'origen', 'foco_programas' => 'programa' ) as $meta => $k ) {
		if ( empty( $d[ $k ] ) ) { continue; }
		$lista = (array) get_post_meta( $id, $meta, true );
		$lista = array_values( array_unique( array_filter( array_merge( $lista, array( $d[ $k ] ) ) ) ) );
		update_post_meta( $id, $meta, $lista );
	}
	if ( isset( $d['contacto'] ) && null !== $d['contacto'] ) { update_post_meta( $id, 'foco_acepta_contacto', $d['contacto'] ? '1' : '0' ); }
	update_post_meta( $id, 'foco_consentimiento', array( 'texto' => foco_txt( isset( $d['consentimiento_texto'] ) ? $d['consentimiento_texto'] : '', 400 ), 'fecha' => current_time( 'mysql' ), 'ip' => substr( foco_ip_hash(), 0, 16 ) ) );
	if ( ! empty( $d['utm'] ) ) { update_post_meta( $id, 'foco_utm', $d['utm'] ); }
	update_post_meta( $id, 'foco_ultima', time() );
	return array( (int) $id, $nueva );
}

function foco_nombre_programa( $id ) {
	$d = foco_datos();
	return isset( $d['programas'][ $id ] ) ? $d['programas'][ $id ] : $id;
}
function foco_nombre_origen( $o ) {
	$n = array( 'guia' => 'Guía gratis', 'espacio' => 'Mi espacio', 'reserva' => 'Reserva', 'contacto' => 'Contacto' );
	return isset( $n[ $o ] ) ? $n[ $o ] : $o;
}

/* =========================================================================================================
 * Agenda: horario base − Google Calendar (iCal) − reservas vigentes
 * ======================================================================================================= */

/** Intervalos ocupados [inicio, fin] en timestamps, leídos del enlace iCal secreto. Se guardan 5 minutos. */
function foco_ocupado_ical( $desde, $hasta ) {
	$url = trim( (string) foco_ajuste( 'ical_url' ) );
	if ( ! $url ) { return array(); }
	$cache = get_transient( 'foco_ical_' . md5( $url ) );
	if ( false === $cache ) {
		$r = wp_remote_get( $url, array( 'timeout' => 12 ) );
		if ( is_wp_error( $r ) || 200 !== wp_remote_retrieve_response_code( $r ) ) { return array( 'error' => true ); }
		$cache = foco_ical_eventos( wp_remote_retrieve_body( $r ), time() - DAY_IN_SECONDS, time() + 70 * DAY_IN_SECONDS );
		set_transient( 'foco_ical_' . md5( $url ), $cache, 5 * MINUTE_IN_SECONDS );
	}
	return array_values( array_filter( $cache, function ( $e ) use ( $desde, $hasta ) { return $e[1] > $desde && $e[0] < $hasta; } ) );
}

/** Lector iCal mínimo: VEVENT con DTSTART/DTEND/DURATION, día completo, TRANSP, RRULE (DAILY/WEEKLY/MONTHLY) y EXDATE. */
function foco_ical_eventos( $ics, $desde, $hasta ) {
	$ics    = preg_replace( "/\r?\n[ \t]/", '', (string) $ics );
	$lineas = preg_split( "/\r?\n/", $ics );
	$evs = array(); $ev = null;
	foreach ( $lineas as $l ) {
		if ( 'BEGIN:VEVENT' === $l ) { $ev = array(); continue; }
		if ( 'END:VEVENT' === $l ) { if ( $ev !== null ) { $evs[] = $ev; } $ev = null; continue; }
		if ( null === $ev || false === strpos( $l, ':' ) ) { continue; }
		list( $izq, $val ) = explode( ':', $l, 2 );
		$partes = explode( ';', $izq ); $nombre = strtoupper( array_shift( $partes ) );
		$params = array();
		foreach ( $partes as $p ) { if ( strpos( $p, '=' ) ) { list( $a, $b ) = explode( '=', $p, 2 ); $params[ strtoupper( $a ) ] = trim( $b, '"' ); } }
		if ( 'EXDATE' === $nombre ) { foreach ( explode( ',', $val ) as $x ) { $ev['EXDATE'][] = array( $x, $params ); } continue; }
		$ev[ $nombre ] = array( $val, $params );
	}
	$fechaDe = function ( $v ) {
		list( $val, $p ) = $v;
		if ( ( isset( $p['VALUE'] ) && 'DATE' === $p['VALUE'] ) || preg_match( '/^\d{8}$/', $val ) ) {
			return array( ( new DateTime( substr( $val, 0, 8 ), foco_tz() ) )->getTimestamp(), true );
		}
		try {
			if ( substr( $val, -1 ) === 'Z' ) { return array( ( new DateTime( $val, new DateTimeZone( 'UTC' ) ) )->getTimestamp(), false ); }
			$tz = isset( $p['TZID'] ) ? new DateTimeZone( $p['TZID'] ) : foco_tz();
			return array( ( new DateTime( $val, $tz ) )->getTimestamp(), false );
		} catch ( Exception $e ) { return array( 0, false ); }
	};
	$out = array();
	foreach ( $evs as $e ) {
		if ( empty( $e['DTSTART'] ) ) { continue; }
		if ( isset( $e['TRANSP'] ) && 'TRANSPARENT' === strtoupper( $e['TRANSP'][0] ) ) { continue; }
		if ( isset( $e['STATUS'] ) && 'CANCELLED' === strtoupper( $e['STATUS'][0] ) ) { continue; }
		list( $ini, $todoDia ) = $fechaDe( $e['DTSTART'] );
		if ( ! $ini ) { continue; }
		if ( ! empty( $e['DTEND'] ) ) { list( $fin ) = $fechaDe( $e['DTEND'] ); }
		elseif ( ! empty( $e['DURATION'] ) ) { try { $fin = ( new DateTime( '@' . $ini ) )->add( new DateInterval( $e['DURATION'][0] ) )->getTimestamp(); } catch ( Exception $x ) { $fin = $ini + HOUR_IN_SECONDS; } }
		else { $fin = $todoDia ? $ini + DAY_IN_SECONDS : $ini + HOUR_IN_SECONDS; }
		$dur = max( 60, $fin - $ini );
		$excluir = array();
		if ( ! empty( $e['EXDATE'] ) ) { foreach ( $e['EXDATE'] as $x ) { list( $t ) = $fechaDe( $x ); $excluir[ $t ] = true; } }
		$inicios = array( $ini );
		if ( ! empty( $e['RRULE'] ) ) { $inicios = foco_rrule( $e['RRULE'][0], $ini, $hasta, isset( $e['DTSTART'][1]['TZID'] ) ? $e['DTSTART'][1]['TZID'] : FOCO_TZ ); }
		foreach ( $inicios as $s ) {
			if ( isset( $excluir[ $s ] ) ) { continue; }
			if ( $s + $dur > $desde && $s < $hasta ) { $out[] = array( $s, $s + $dur ); }
		}
	}
	return $out;
}

/** Repeticiones de un evento hasta $hasta (máximo 400). Cubre los casos comunes de Google Calendar. */
function foco_rrule( $regla, $ini, $hasta, $tzid ) {
	$r = array();
	foreach ( explode( ';', $regla ) as $p ) { if ( strpos( $p, '=' ) ) { list( $a, $b ) = explode( '=', $p, 2 ); $r[ strtoupper( $a ) ] = $b; } }
	$freq = isset( $r['FREQ'] ) ? $r['FREQ'] : '';
	$int  = max( 1, isset( $r['INTERVAL'] ) ? (int) $r['INTERVAL'] : 1 );
	$cuenta = isset( $r['COUNT'] ) ? (int) $r['COUNT'] : 0;
	$fin = $hasta;
	if ( ! empty( $r['UNTIL'] ) ) { try { $u = new DateTime( $r['UNTIL'], new DateTimeZone( substr( $r['UNTIL'], -1 ) === 'Z' ? 'UTC' : $tzid ) ); $fin = min( $hasta, $u->getTimestamp() + ( strlen( $r['UNTIL'] ) <= 8 ? DAY_IN_SECONDS : 0 ) ); } catch ( Exception $e ) {} }
	try { $tz = new DateTimeZone( $tzid ); } catch ( Exception $e ) { $tz = foco_tz(); }
	$base = ( new DateTime( '@' . $ini ) )->setTimezone( $tz );
	$out = array(); $n = 0;
	$dias = array( 'SU' => 0, 'MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6 );
	if ( 'WEEKLY' === $freq ) {
		$by = isset( $r['BYDAY'] ) ? array_map( function ( $x ) use ( $dias ) { $x = preg_replace( '/[^A-Z]/', '', $x ); return isset( $dias[ $x ] ) ? $dias[ $x ] : null; }, explode( ',', $r['BYDAY'] ) ) : array( (int) $base->format( 'w' ) );
		$by = array_filter( $by, function ( $x ) { return null !== $x; } ); sort( $by );
		$semana = clone $base; $semana->modify( '-' . (int) $base->format( 'w' ) . ' days' );
		for ( $s = 0; $s < 400; $s++ ) {
			foreach ( $by as $dw ) {
				$c = clone $semana; $c->modify( '+' . ( $s * 7 * $int + $dw ) . ' days' );
				$c->setTime( (int) $base->format( 'G' ), (int) $base->format( 'i' ), (int) $base->format( 's' ) );
				$t = $c->getTimestamp();
				if ( $t < $ini ) { continue; }
				if ( $t > $fin || ( $cuenta && $n >= $cuenta ) ) { return $out; }
				$out[] = $t; $n++;
			}
		}
		return $out;
	}
	$paso = array( 'DAILY' => 'day', 'MONTHLY' => 'month', 'YEARLY' => 'year' );
	if ( ! isset( $paso[ $freq ] ) ) { return array( $ini ); }
	for ( $i = 0; $i < 400; $i++ ) {
		$c = clone $base; $c->modify( '+' . ( $i * $int ) . ' ' . $paso[ $freq ] );
		$t = $c->getTimestamp();
		if ( $t > $fin || ( $cuenta && $n >= $cuenta ) ) { break; }
		$out[] = $t; $n++;
	}
	return $out;
}

/** Reservas que hoy apartan horario: confirmadas, o pendientes sin vencer. */
function foco_ocupado_reservas( $excepto = 0 ) {
	$q = get_posts( array( 'post_type' => 'foco_reserva', 'post_status' => 'private', 'numberposts' => 500, 'fields' => 'ids',
		'meta_query' => array( array( 'key' => 'foco_inicio', 'value' => time() - DAY_IN_SECONDS, 'compare' => '>', 'type' => 'NUMERIC' ) ) ) );
	$out = array();
	foreach ( $q as $id ) {
		if ( $id == $excepto ) { continue; }
		$estado = foco_estado_reserva( $id );
		if ( 'confirmada' !== $estado && 'pendiente' !== $estado ) { continue; }
		$ini = (int) get_post_meta( $id, 'foco_inicio', true );
		$out[] = array( $ini, $ini + 60 * (int) get_post_meta( $id, 'foco_minutos', true ) );
	}
	return $out;
}

/** Horarios libres: [ 'YYYY-MM-DD' => [timestamps] ]. */
function foco_horarios_libres( $minutos ) {
	$d   = foco_datos();
	$ag  = isset( $d['agenda'] ) ? $d['agenda'] : array();
	$hor = isset( $ag['horario'] ) ? $ag['horario'] : array();
	$dias = min( 60, isset( $ag['dias_adelante'] ) ? (int) $ag['dias_adelante'] : 30 );
	$minAnt = time() + 3600 * ( isset( $ag['anticipacion_horas'] ) ? (int) $ag['anticipacion_horas'] : 24 );
	$hoy = new DateTime( 'today', foco_tz() );
	$desde = $hoy->getTimestamp(); $hasta = $desde + ( $dias + 1 ) * DAY_IN_SECONDS;
	$ical = foco_ocupado_ical( $desde, $hasta );
	if ( isset( $ical['error'] ) ) { return new WP_Error( 'foco_calendario', 'No pude leer el calendario.', array( 'status' => 503 ) ); }
	$ocupado = array_merge( $ical, foco_ocupado_reservas() );
	$libres = array();
	for ( $i = 0; $i <= $dias; $i++ ) {
		$dia = clone $hoy; $dia->modify( "+$i day" );
		$dw = (int) $dia->format( 'N' );
		if ( empty( $hor[ (string) $dw ] ) ) { continue; }
		foreach ( $hor[ (string) $dw ] as $hhmm ) {
			list( $h, $m ) = array_map( 'intval', explode( ':', $hhmm ) );
			$s = clone $dia; $s->setTime( $h, $m );
			$ini = $s->getTimestamp(); $fin = $ini + 60 * $minutos;
			if ( $ini < $minAnt ) { continue; }
			$choca = false;
			foreach ( $ocupado as $o ) { if ( $o[0] < $fin && $o[1] > $ini ) { $choca = true; break; } }
			if ( ! $choca ) { $libres[ $dia->format( 'Y-m-d' ) ][] = $ini; }
		}
	}
	return $libres;
}

/* =========================================================================================================
 * Reservas
 * ======================================================================================================= */

function foco_estado_reserva( $id ) {
	$e = get_post_meta( $id, 'foco_estado', true );
	if ( 'pendiente' === $e && (int) get_post_meta( $id, 'foco_expira', true ) < time() ) { return 'vencida'; }
	return $e ?: 'pendiente';
}
function foco_nombre_estado( $e ) {
	$n = array( 'pendiente' => 'Pendiente de pago', 'confirmada' => 'Confirmada', 'vencida' => 'Vencida (no pagó a tiempo)', 'cancelada' => 'Cancelada' );
	return isset( $n[ $e ] ) ? $n[ $e ] : $e;
}
function foco_referencia_nueva() {
	$abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
	do {
		$r = 'FOCO-'; for ( $i = 0; $i < 6; $i++ ) { $r .= $abc[ random_int( 0, strlen( $abc ) - 1 ) ]; }
		$existe = get_posts( array( 'post_type' => 'foco_reserva', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => 'foco_ref', 'meta_value' => $r ) );
	} while ( $existe );
	return $r;
}

/** Instrucciones de pago según la moneda: Bre-B en pesos; PayPal (o enlace por WhatsApp) en dólares. */
function foco_instrucciones_pago( $valor, $moneda, $ref ) {
	$a = foco_ajustes();
	if ( 'USD' === $moneda ) {
		$pp = trim( $a['paypal_me'] );
		return array(
			'metodo'  => 'paypal',
			'enlace'  => $pp ? 'https://paypal.me/' . rawurlencode( $pp ) . '/' . (int) $valor . 'USD' : '',
			'texto'   => $pp ? 'Paga con PayPal en dólares. En la nota escribe tu referencia ' . $ref . '.' : 'Te envío el enlace de pago de PayPal por WhatsApp o correo.',
		);
	}
	return array( 'metodo' => 'breb', 'llave' => $a['breb_llave'], 'titular' => $a['breb_titular'], 'nota' => $a['breb_nota'] );
}

/* =========================================================================================================
 * API (REST): /wp-json/foco/v1/…
 * ======================================================================================================= */

add_action( 'rest_api_init', function () {
	register_rest_route( 'foco/v1', '/lead', array( 'methods' => 'POST', 'callback' => 'foco_api_lead', 'permission_callback' => '__return_true' ) );
	register_rest_route( 'foco/v1', '/horarios', array( 'methods' => 'GET', 'callback' => 'foco_api_horarios', 'permission_callback' => '__return_true' ) );
	register_rest_route( 'foco/v1', '/reservas', array( 'methods' => 'POST', 'callback' => 'foco_api_reserva', 'permission_callback' => '__return_true' ) );
} );

function foco_validar_persona( $p ) {
	if ( ! empty( $p['sitio'] ) ) { return new WP_Error( 'foco_spam', 'No se pudo enviar.', array( 'status' => 400 ) ); }
	$nombre = foco_txt( isset( $p['nombre'] ) ? $p['nombre'] : '', 80 );
	$email  = isset( $p['email'] ) ? sanitize_email( $p['email'] ) : '';
	$wa     = isset( $p['whatsapp'] ) ? foco_txt( $p['whatsapp'], 30 ) : '';
	if ( mb_strlen( $nombre ) < 2 ) { return new WP_Error( 'foco_nombre', 'Escribe tu nombre.', array( 'status' => 400 ) ); }
	if ( ! $email && strlen( foco_digitos( $wa ) ) < 7 ) { return new WP_Error( 'foco_contacto', 'Déjame un WhatsApp o un correo.', array( 'status' => 400 ) ); }
	if ( empty( $p['consentimiento'] ) ) { return new WP_Error( 'foco_consentimiento', 'Falta tu autorización de datos.', array( 'status' => 400 ) ); }
	$prog = isset( $p['programa'] ) && in_array( $p['programa'], FOCO_PROGRAMAS, true ) ? $p['programa'] : '';
	return array( 'nombre' => $nombre, 'email' => $email, 'whatsapp' => $wa, 'programa' => $prog, 'pais' => isset( $p['pais'] ) ? $p['pais'] : '',
		'contacto' => array_key_exists( 'contacto', $p ) ? ! empty( $p['contacto'] ) : null, 'consentimiento_texto' => isset( $p['consentimiento_texto'] ) ? $p['consentimiento_texto'] : '', 'utm' => foco_utm( isset( $p['utm'] ) ? $p['utm'] : array() ) );
}

function foco_api_lead( WP_REST_Request $req ) {
	if ( ! foco_limite( 'lead', 10 ) ) { return new WP_Error( 'foco_limite', 'Intenta de nuevo en un rato.', array( 'status' => 429 ) ); }
	$p = $req->get_json_params(); if ( ! is_array( $p ) ) { $p = array(); }
	$d = foco_validar_persona( $p );
	if ( is_wp_error( $d ) ) { return $d; }
	$d['origen'] = isset( $p['origen'] ) && in_array( $p['origen'], FOCO_ORIGENES, true ) ? $p['origen'] : 'contacto';
	list( $id, $nueva ) = foco_guardar_persona( $d );
	if ( ! $id ) { return new WP_Error( 'foco_guardar', 'No pude guardar tus datos.', array( 'status' => 500 ) ); }
	if ( $nueva ) {
		$filas = array( 'Nombre' => esc_html( $d['nombre'] ), 'Llegó por' => esc_html( foco_nombre_origen( $d['origen'] ) ) );
		if ( $d['programa'] ) { $filas['Le interesa'] = esc_html( foco_nombre_programa( $d['programa'] ) ); }
		if ( $d['whatsapp'] ) { $filas['WhatsApp'] = '<a href="' . esc_url( foco_wa( $d['whatsapp'], 'Hola ' . $d['nombre'] . ', te escribo de Foco Psicológico.' ) ) . '">' . esc_html( $d['whatsapp'] ) . '</a>'; }
		if ( $d['email'] ) { $filas['Correo'] = esc_html( $d['email'] ); }
		$filas['¿Quiere que le escribas?'] = $d['contacto'] ? 'Sí' : 'No, solo quiere lo que pidió';
		foco_correo( foco_ajuste( 'email_avisos' ), 'Nueva persona en Foco: ' . $d['nombre'], '<p>Alguien dejó sus datos en la web.</p>', $filas, array( 'Ver personas', admin_url( 'edit.php?post_type=foco_persona' ) ) );
	}
	return array( 'ok' => true );
}

function foco_api_horarios( WP_REST_Request $req ) {
	if ( ! foco_reservas_activas() ) { return new WP_Error( 'foco_off', 'La agenda en línea no está activa.', array( 'status' => 404 ) ); }
	$min = max( 30, min( 120, (int) $req->get_param( 'minutos' ) ?: 60 ) );
	$l = foco_horarios_libres( $min );
	if ( is_wp_error( $l ) ) { return $l; }
	$dias = array();
	foreach ( $l as $fecha => $ts ) {
		$dias[] = array( 'fecha' => $fecha, 'horas' => array_map( function ( $t ) { return ( new DateTime( '@' . $t ) )->setTimezone( foco_tz() )->format( 'Y-m-d\TH:i:sP' ); }, $ts ) );
	}
	$r = new WP_REST_Response( array( 'zona' => FOCO_TZ, 'dias' => $dias ) );
	$r->header( 'Cache-Control', 'no-store' );
	return $r;
}

function foco_api_reserva( WP_REST_Request $req ) {
	if ( ! foco_reservas_activas() ) { return new WP_Error( 'foco_off', 'Las reservas en línea no están activas.', array( 'status' => 404 ) ); }
	if ( ! foco_limite( 'reserva', 6 ) ) { return new WP_Error( 'foco_limite', 'Intenta de nuevo en un rato.', array( 'status' => 429 ) ); }
	$p = $req->get_json_params(); if ( ! is_array( $p ) ) { $p = array(); }
	$d = foco_validar_persona( $p );
	if ( is_wp_error( $d ) ) { return $d; }
	if ( ! $d['email'] || strlen( foco_digitos( $d['whatsapp'] ) ) < 7 ) { return new WP_Error( 'foco_contacto', 'Para reservar necesito tu correo y tu WhatsApp.', array( 'status' => 400 ) ); }
	$moneda = isset( $p['moneda'] ) && 'USD' === $p['moneda'] ? 'USD' : 'COP';
	$valor  = isset( $p['valor'] ) ? (int) $p['valor'] : 0;
	$min    = max( 30, min( 120, isset( $p['minutos'] ) ? (int) $p['minutos'] : 60 ) );
	$camino = isset( $p['camino'] ) && in_array( $p['camino'], array( 'exploracion', 'consulta', 'programa' ), true ) ? $p['camino'] : 'exploracion';
	if ( $valor <= 0 || $valor > ( 'USD' === $moneda ? 5000 : 20000000 ) ) { return new WP_Error( 'foco_valor', 'Revisa el valor de la reserva.', array( 'status' => 400 ) ); }
	try { $ini = ( new DateTime( (string) ( isset( $p['inicio'] ) ? $p['inicio'] : '' ) ) )->getTimestamp(); } catch ( Exception $e ) { $ini = 0; }
	if ( ! $ini ) { return new WP_Error( 'foco_hora', 'Elige un horario.', array( 'status' => 400 ) ); }

	// El horario tiene que seguir libre ahora mismo (otra persona pudo tomarlo).
	$bloqueo = 'foco_bloqueo_' . $ini;
	if ( get_transient( $bloqueo ) ) { return new WP_Error( 'foco_ocupado', 'Alguien acaba de tomar ese horario. Elige otro, por favor.', array( 'status' => 409 ) ); }
	set_transient( $bloqueo, 1, 20 );
	$libres = foco_horarios_libres( $min );
	if ( is_wp_error( $libres ) ) { delete_transient( $bloqueo ); return $libres; }
	$dia = ( new DateTime( '@' . $ini ) )->setTimezone( foco_tz() )->format( 'Y-m-d' );
	if ( empty( $libres[ $dia ] ) || ! in_array( $ini, $libres[ $dia ], true ) ) { delete_transient( $bloqueo ); return new WP_Error( 'foco_ocupado', 'Ese horario ya no está libre. Elige otro, por favor.', array( 'status' => 409 ) ); }

	$d['origen'] = 'reserva';
	list( $persona ) = foco_guardar_persona( $d );
	$ref    = foco_referencia_nueva();
	$horas  = max( 2, min( 72, (int) foco_ajuste( 'horas_pago' ) ?: 24 ) );
	$expira = min( time() + $horas * HOUR_IN_SECONDS, $ini - HOUR_IN_SECONDS );
	$concepto = foco_txt( isset( $p['concepto'] ) ? $p['concepto'] : 'Sesión', 120 );
	$detalle  = foco_txt( isset( $p['detalle'] ) ? $p['detalle'] : '', 160 );
	$id = wp_insert_post( array( 'post_type' => 'foco_reserva', 'post_status' => 'private', 'post_title' => $ref . ' · ' . $d['nombre'] ), true );
	if ( is_wp_error( $id ) ) { delete_transient( $bloqueo ); return new WP_Error( 'foco_guardar', 'No pude guardar la reserva.', array( 'status' => 500 ) ); }
	$meta = array( 'foco_ref' => $ref, 'foco_estado' => 'pendiente', 'foco_persona' => $persona, 'foco_inicio' => $ini, 'foco_minutos' => $min, 'foco_expira' => $expira,
		'foco_valor' => $valor, 'foco_moneda' => $moneda, 'foco_camino' => $camino, 'foco_concepto' => $concepto, 'foco_detalle' => $detalle,
		'foco_programa' => $d['programa'], 'foco_nombre' => $d['nombre'], 'foco_email' => $d['email'], 'foco_wa' => $d['whatsapp'], 'foco_pais' => foco_txt( $d['pais'], 40 ), 'foco_utm' => $d['utm'] );
	foreach ( $meta as $k => $v ) { update_post_meta( $id, $k, $v ); }
	delete_transient( $bloqueo );

	$pago = foco_instrucciones_pago( $valor, $moneda, $ref );
	$cuando = foco_fecha_larga( $ini ); $vence = foco_fecha_larga( $expira );
	$monto = foco_dinero( $valor, $moneda );
	$waComprobante = foco_wa( foco_whatsapp_foco(), 'Hola, envío el comprobante de mi reserva ' . $ref . ' (' . $monto . ').' );

	// Correo a la persona: cómo pagar.
	$filas = array( 'Referencia' => esc_html( $ref ), 'Sesión' => esc_html( $concepto ), 'Cuándo' => esc_html( $cuando ), 'Valor' => esc_html( $monto ) );
	if ( 'breb' === $pago['metodo'] ) {
		$filas['Paga a la llave Bre-B'] = esc_html( $pago['llave'] ?: 'Te la envío por WhatsApp' );
		if ( $pago['titular'] ) { $filas['A nombre de'] = esc_html( $pago['titular'] ); }
		$intro = '<p>Hola, ' . esc_html( strtok( $d['nombre'], ' ' ) ) . '. Tu horario quedó apartado.</p><p>Para confirmarlo, haz la transferencia por Bre-B antes del <b>' . esc_html( $vence ) . '</b> y envíame el comprobante por WhatsApp. ' . esc_html( $pago['nota'] ) . '</p>';
		$boton = array( 'Enviar el comprobante por WhatsApp', $waComprobante );
	} else {
		$intro = '<p>Hola, ' . esc_html( strtok( $d['nombre'], ' ' ) ) . '. Tu horario quedó apartado.</p><p>' . esc_html( $pago['texto'] ) . ' Tienes hasta el <b>' . esc_html( $vence ) . '</b>.</p>';
		$boton = $pago['enlace'] ? array( 'Pagar con PayPal', $pago['enlace'] ) : array( 'Escribir por WhatsApp', $waComprobante );
	}
	foco_correo( $d['email'], 'Tu reserva en Foco · ' . $ref, $intro, $filas, $boton, 'Si no alcanzas a pagar a tiempo, el horario se libera. Puedes reagendar sin costo. El WhatsApp no es una línea de crisis: en una emergencia busca las líneas de atención de tu país.' );

	// Aviso a JuanDa.
	$fin = $ini + 60 * $min;
	foco_correo( foco_ajuste( 'email_avisos' ), 'Nueva reserva (pendiente de pago): ' . $d['nombre'] . ' · ' . $cuando,
		'<p>Entró una reserva. Cuando veas el pago en tu cuenta, confírmala en WordPress: <b>Foco › Reservas › Confirmar pago</b>.</p>',
		array( 'Referencia' => esc_html( $ref ), 'Persona' => esc_html( $d['nombre'] ), 'Sesión' => esc_html( $concepto . ( $detalle ? ' · ' . $detalle : '' ) ), 'Cuándo' => esc_html( $cuando ), 'Valor' => esc_html( $monto ),
			'WhatsApp' => '<a href="' . esc_url( foco_wa( $d['whatsapp'], 'Hola ' . $d['nombre'] . ', te escribo de Foco por tu reserva ' . $ref . '.' ) ) . '">' . esc_html( $d['whatsapp'] ) . '</a>', 'Correo' => esc_html( $d['email'] ), 'Vence el pago' => esc_html( $vence ) ),
		array( 'Ver la reserva', admin_url( 'edit.php?post_type=foco_reserva' ) ),
		'<a href="' . esc_url( foco_enlace_calendario( $ini, $fin, 'Foco · ' . $d['nombre'] . ' (' . $ref . ')', $concepto ) ) . '">Agregar a mi Google Calendar</a>' );

	return array( 'ok' => true, 'referencia' => $ref, 'expira' => ( new DateTime( '@' . $expira ) )->setTimezone( foco_tz() )->format( 'c' ), 'vence_texto' => $vence,
		'pago' => $pago + array( 'valor' => $valor, 'moneda' => $moneda, 'whatsapp' => $waComprobante ) );
}

/* =========================================================================================================
 * Escritorio de WordPress: menú «Foco», listas, confirmar pago, exportar y ajustes
 * ======================================================================================================= */

add_action( 'admin_menu', function () {
	add_menu_page( 'Foco', 'Foco', 'edit_posts', 'foco', 'foco_admin_inicio', 'dashicons-heart', 3 );
	add_submenu_page( 'foco', 'Resumen', 'Resumen', 'edit_posts', 'foco', 'foco_admin_inicio', 0 );
	add_submenu_page( 'foco', 'Ajustes de Foco', 'Ajustes', 'manage_options', 'foco-ajustes', 'foco_admin_ajustes' );
} );

function foco_contar( $tipo, $estado = null ) {
	$q = get_posts( array( 'post_type' => $tipo, 'post_status' => 'private', 'numberposts' => 500, 'fields' => 'ids' ) );
	if ( ! $estado ) { return count( $q ); }
	return count( array_filter( $q, function ( $id ) use ( $estado ) { return foco_estado_reserva( $id ) === $estado; } ) );
}

function foco_admin_inicio() {
	$act = foco_reservas_activas();
	echo '<div class="wrap"><h1>Foco</h1>';
	echo '<p style="font-size:15px">Reservas en la web: <b>' . ( $act ? 'activas' : 'apagadas (la web muestra la agenda de ejemplo)' ) . '</b>. Cámbialo en <a href="' . esc_url( admin_url( 'admin.php?page=foco-ajustes' ) ) . '">Ajustes</a>.</p>';
	echo '<div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:16px">';
	foreach ( array(
		array( 'Pendientes de pago', foco_contar( 'foco_reserva', 'pendiente' ), 'edit.php?post_type=foco_reserva' ),
		array( 'Citas confirmadas', foco_contar( 'foco_reserva', 'confirmada' ), 'edit.php?post_type=foco_reserva' ),
		array( 'Personas', foco_contar( 'foco_persona' ), 'edit.php?post_type=foco_persona' ),
	) as $c ) {
		echo '<a href="' . esc_url( admin_url( $c[2] ) ) . '" style="flex:1 1 200px;background:#fff;border-radius:12px;padding:20px;text-decoration:none;color:#2A2530;box-shadow:0 1px 3px rgba(0,0,0,.08)"><span style="font-size:36px;font-weight:bold;display:block">' . (int) $c[1] . '</span>' . esc_html( $c[0] ) . '</a>';
	}
	echo '</div><p style="margin-top:20px"><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=foco_exportar' ), 'foco_exportar' ) ) . '">Descargar personas (CSV)</a></p></div>';
}

/* Columnas de Personas */
add_filter( 'manage_foco_persona_posts_columns', function () {
	return array( 'cb' => '<input type="checkbox" />', 'title' => 'Nombre', 'foco_wa' => 'WhatsApp', 'foco_email' => 'Correo', 'foco_origen' => 'Llegó por', 'foco_prog' => 'Le interesa', 'foco_contacto' => '¿Escribirle?', 'foco_fecha' => 'Última vez' );
} );
add_action( 'manage_foco_persona_posts_custom_column', function ( $col, $id ) {
	switch ( $col ) {
		case 'foco_wa': $w = get_post_meta( $id, 'foco_wa_texto', true ); if ( $w ) { echo '<a href="' . esc_url( foco_wa( $w, 'Hola ' . get_the_title( $id ) . ', te escribo de Foco Psicológico.' ) ) . '" target="_blank" rel="noopener">' . esc_html( $w ) . '</a>'; } break;
		case 'foco_email': echo esc_html( get_post_meta( $id, 'foco_email', true ) ); break;
		case 'foco_origen': echo esc_html( implode( ', ', array_map( 'foco_nombre_origen', (array) get_post_meta( $id, 'foco_origenes', true ) ) ) ); break;
		case 'foco_prog': echo esc_html( implode( ', ', array_map( 'foco_nombre_programa', array_filter( (array) get_post_meta( $id, 'foco_programas', true ) ) ) ) ); break;
		case 'foco_contacto': echo get_post_meta( $id, 'foco_acepta_contacto', true ) === '1' ? 'Sí' : 'No'; break;
		case 'foco_fecha': $t = (int) get_post_meta( $id, 'foco_ultima', true ); echo $t ? esc_html( wp_date( 'j M Y, g:i a', $t, foco_tz() ) ) : ''; break;
	}
}, 10, 2 );

/* Columnas de Reservas */
add_filter( 'manage_foco_reserva_posts_columns', function () {
	return array( 'cb' => '<input type="checkbox" />', 'title' => 'Reserva', 'foco_cita' => 'Cita', 'foco_serv' => 'Sesión', 'foco_valor' => 'Valor', 'foco_estado' => 'Estado', 'foco_acciones' => '' );
} );
add_action( 'manage_foco_reserva_posts_custom_column', function ( $col, $id ) {
	switch ( $col ) {
		case 'foco_cita': echo esc_html( foco_fecha_larga( (int) get_post_meta( $id, 'foco_inicio', true ) ) ); break;
		case 'foco_serv': echo esc_html( get_post_meta( $id, 'foco_concepto', true ) ); $det = get_post_meta( $id, 'foco_detalle', true ); if ( $det ) { echo '<br><small>' . esc_html( $det ) . '</small>'; } break;
		case 'foco_valor': echo esc_html( foco_dinero( get_post_meta( $id, 'foco_valor', true ), get_post_meta( $id, 'foco_moneda', true ) ) ); break;
		case 'foco_estado':
			$e = foco_estado_reserva( $id );
			$c = array( 'pendiente' => '#B26B00', 'confirmada' => '#2E7D32', 'vencida' => '#777', 'cancelada' => '#777' );
			echo '<b style="color:' . ( isset( $c[ $e ] ) ? $c[ $e ] : '#333' ) . '">' . esc_html( foco_nombre_estado( $e ) ) . '</b>';
			if ( 'pendiente' === $e ) { echo '<br><small>Vence: ' . esc_html( foco_fecha_larga( (int) get_post_meta( $id, 'foco_expira', true ) ) ) . '</small>'; }
			break;
		case 'foco_acciones':
			$e = foco_estado_reserva( $id );
			$url = function ( $accion ) use ( $id ) { return wp_nonce_url( admin_url( 'admin-post.php?action=foco_reserva&hacer=' . $accion . '&id=' . $id ), 'foco_reserva_' . $id ); };
			if ( in_array( $e, array( 'pendiente', 'vencida' ), true ) ) { echo '<a class="button button-primary" href="' . esc_url( $url( 'confirmar' ) ) . '" onclick="return confirm(\'¿Ya viste el pago en tu cuenta? La persona recibirá la confirmación.\')">Confirmar pago</a> '; }
			if ( 'cancelada' !== $e ) { echo '<a class="button" href="' . esc_url( $url( 'cancelar' ) ) . '" onclick="return confirm(\'¿Cancelar esta reserva y liberar el horario?\')">Cancelar</a>'; }
			$wa = get_post_meta( $id, 'foco_wa', true );
			if ( $wa ) { echo ' <a class="button" target="_blank" rel="noopener" href="' . esc_url( foco_wa( $wa, 'Hola ' . get_post_meta( $id, 'foco_nombre', true ) . ', te escribo de Foco por tu reserva ' . get_post_meta( $id, 'foco_ref', true ) . '.' ) ) . '">WhatsApp</a>'; }
			break;
	}
}, 10, 2 );
add_filter( 'post_row_actions', function ( $acc, $post ) {
	if ( in_array( $post->post_type, array( 'foco_reserva', 'foco_persona' ), true ) ) { unset( $acc['inline hide-if-no-js'], $acc['view'] ); }
	return $acc;
}, 10, 2 );

/** Confirmar o cancelar una reserva. Solo personas con permiso de edición y con nonce. */
add_action( 'admin_post_foco_reserva', function () {
	$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
	if ( ! $id || ! current_user_can( 'edit_post', $id ) || 'foco_reserva' !== get_post_type( $id ) ) { wp_die( 'Sin permiso.' ); }
	check_admin_referer( 'foco_reserva_' . $id );
	$hacer = isset( $_GET['hacer'] ) ? sanitize_key( $_GET['hacer'] ) : '';
	$ref = get_post_meta( $id, 'foco_ref', true );
	$nombre = get_post_meta( $id, 'foco_nombre', true );
	$email = get_post_meta( $id, 'foco_email', true );
	$ini = (int) get_post_meta( $id, 'foco_inicio', true );
	$fin = $ini + 60 * (int) get_post_meta( $id, 'foco_minutos', true );
	$concepto = get_post_meta( $id, 'foco_concepto', true );
	if ( 'confirmar' === $hacer ) {
		update_post_meta( $id, 'foco_estado', 'confirmada' );
		update_post_meta( $id, 'foco_pagado_en', current_time( 'mysql' ) );
		update_post_meta( $id, 'foco_confirmado_por', get_current_user_id() );
		$video = trim( (string) foco_ajuste( 'videollamada' ) );
		$filas = array( 'Referencia' => esc_html( $ref ), 'Sesión' => esc_html( $concepto ), 'Cuándo' => esc_html( foco_fecha_larga( $ini ) ) );
		if ( $video ) { $filas['Videollamada'] = '<a href="' . esc_url( $video ) . '">' . esc_html( $video ) . '</a>'; }
		foco_correo( $email, 'Tu cita en Foco está confirmada · ' . $ref,
			'<p>Hola, ' . esc_html( strtok( $nombre, ' ' ) ) . '. Recibí tu pago: tu cita quedó confirmada.</p>' . ( $video ? '' : '<p>Un día antes te envío el enlace de la videollamada.</p>' ),
			$filas, array( 'Agregar a mi calendario', foco_enlace_calendario( $ini, $fin, 'Sesión en Foco Psicológico', $video ? 'Videollamada: ' . $video : 'Te envío el enlace de la videollamada un día antes.' ) ),
			'Si necesitas mover la cita, escríbeme y la reagendamos. El WhatsApp no es una línea de crisis: en una emergencia busca las líneas de atención de tu país.' );
	} elseif ( 'cancelar' === $hacer ) {
		update_post_meta( $id, 'foco_estado', 'cancelada' );
	}
	wp_safe_redirect( admin_url( 'edit.php?post_type=foco_reserva&foco_hecho=' . $hacer ) );
	exit;
} );
add_action( 'admin_notices', function () {
	if ( empty( $_GET['foco_hecho'] ) ) { return; }
	$m = array( 'confirmar' => 'Listo: la reserva quedó confirmada y la persona recibió el correo.', 'cancelar' => 'Listo: la reserva quedó cancelada y el horario se liberó.' );
	$k = sanitize_key( $_GET['foco_hecho'] );
	if ( isset( $m[ $k ] ) ) { echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $m[ $k ] ) . '</p></div>'; }
} );

/** Exportar personas a CSV (para Marketing). Solo administración. */
add_action( 'admin_post_foco_exportar', function () {
	if ( ! current_user_can( 'edit_posts' ) ) { wp_die( 'Sin permiso.' ); }
	check_admin_referer( 'foco_exportar' );
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=foco-personas-' . gmdate( 'Y-m-d' ) . '.csv' );
	$f = fopen( 'php://output', 'w' );
	fwrite( $f, "\xEF\xBB\xBF" );
	fputcsv( $f, array( 'Nombre', 'WhatsApp', 'Correo', 'País', 'Llegó por', 'Le interesa', 'Acepta que le escriban', 'Autorizó datos el', 'utm_campaign', 'Última vez' ) );
	foreach ( get_posts( array( 'post_type' => 'foco_persona', 'post_status' => 'private', 'numberposts' => -1 ) ) as $p ) {
		$c = (array) get_post_meta( $p->ID, 'foco_consentimiento', true );
		$u = (array) get_post_meta( $p->ID, 'foco_utm', true );
		$t = (int) get_post_meta( $p->ID, 'foco_ultima', true );
		fputcsv( $f, array( $p->post_title, get_post_meta( $p->ID, 'foco_wa_texto', true ), get_post_meta( $p->ID, 'foco_email', true ), get_post_meta( $p->ID, 'foco_pais', true ),
			implode( ', ', array_map( 'foco_nombre_origen', (array) get_post_meta( $p->ID, 'foco_origenes', true ) ) ),
			implode( ', ', array_map( 'foco_nombre_programa', array_filter( (array) get_post_meta( $p->ID, 'foco_programas', true ) ) ) ),
			get_post_meta( $p->ID, 'foco_acepta_contacto', true ) === '1' ? 'Sí' : 'No', isset( $c['fecha'] ) ? $c['fecha'] : '', isset( $u['utm_campaign'] ) ? $u['utm_campaign'] : '', $t ? wp_date( 'Y-m-d H:i', $t, foco_tz() ) : '' ) );
	}
	fclose( $f );
	exit;
} );

/* Ajustes */
add_action( 'admin_init', function () {
	register_setting( 'foco_ajustes', 'foco_ajustes', array( 'sanitize_callback' => function ( $v ) {
		$v = is_array( $v ) ? $v : array();
		return array(
			'reservas'     => ! empty( $v['reservas'] ) ? '1' : '',
			'email_avisos' => sanitize_email( isset( $v['email_avisos'] ) ? $v['email_avisos'] : '' ) ?: get_option( 'admin_email' ),
			'breb_llave'   => foco_txt( isset( $v['breb_llave'] ) ? $v['breb_llave'] : '', 60 ),
			'breb_titular' => foco_txt( isset( $v['breb_titular'] ) ? $v['breb_titular'] : '', 80 ),
			'breb_nota'    => foco_txt( isset( $v['breb_nota'] ) ? $v['breb_nota'] : '', 160 ),
			'paypal_me'    => preg_replace( '/[^A-Za-z0-9_.-]/', '', isset( $v['paypal_me'] ) ? $v['paypal_me'] : '' ),
			'horas_pago'   => max( 2, min( 72, (int) ( isset( $v['horas_pago'] ) ? $v['horas_pago'] : 24 ) ) ),
			'ical_url'     => esc_url_raw( trim( isset( $v['ical_url'] ) ? $v['ical_url'] : '' ), array( 'https' ) ),
			'videollamada' => esc_url_raw( trim( isset( $v['videollamada'] ) ? $v['videollamada'] : '' ), array( 'https' ) ),
		);
	} ) );
} );

function foco_admin_ajustes() {
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	$a = foco_ajustes();
	$campo = function ( $k, $etq, $ayuda = '', $tipo = 'text' ) use ( $a ) {
		echo '<tr><th scope="row"><label for="foco_' . esc_attr( $k ) . '">' . esc_html( $etq ) . '</label></th><td>';
		if ( 'checkbox' === $tipo ) { echo '<label><input type="checkbox" id="foco_' . esc_attr( $k ) . '" name="foco_ajustes[' . esc_attr( $k ) . ']" value="1" ' . checked( $a[ $k ], '1', false ) . '> Sí</label>'; }
		else { echo '<input class="regular-text" type="' . esc_attr( $tipo ) . '" id="foco_' . esc_attr( $k ) . '" name="foco_ajustes[' . esc_attr( $k ) . ']" value="' . esc_attr( $a[ $k ] ) . '" autocomplete="off">'; }
		if ( $ayuda ) { echo '<p class="description">' . wp_kses( $ayuda, array( 'b' => array(), 'br' => array() ) ) . '</p>'; }
		echo '</td></tr>';
	};
	$ical = $a['ical_url'] ? foco_ocupado_ical( time(), time() + 30 * DAY_IN_SECONDS ) : null;
	echo '<div class="wrap"><h1>Ajustes de Foco</h1><form method="post" action="options.php">';
	settings_fields( 'foco_ajustes' );
	echo '<h2>Agenda y reservas</h2><table class="form-table" role="presentation">';
	$campo( 'reservas', 'Activar reservas en la web', 'Si está apagado, la web sigue mostrando la agenda de ejemplo y no se puede reservar.', 'checkbox' );
	$campo( 'ical_url', 'Google Calendar (dirección secreta iCal)', 'En Google Calendar › Configuración › tu calendario › «Dirección secreta en formato iCal». Es privada: pégala solo aquí.<br>Lo que esté ocupado en tu calendario no se ofrece en la web.', 'password' );
	if ( null !== $ical ) { echo '<tr><th></th><td><b>' . ( isset( $ical['error'] ) ? '⚠️ No pude leer el calendario. Revisa la dirección.' : '✓ Calendario conectado: ' . count( $ical ) . ' eventos ocupados en los próximos 30 días.' ) . '</b></td></tr>'; }
	$campo( 'horas_pago', 'Horas para pagar', 'Tiempo que el horario queda apartado esperando el pago (entre 2 y 72).', 'number' );
	$campo( 'videollamada', 'Enlace fijo de videollamada (opcional)', 'Si siempre usas el mismo enlace (Meet o Zoom), va en el correo de confirmación.' );
	echo '</table><h2>Pagos</h2><table class="form-table" role="presentation">';
	$campo( 'breb_llave', 'Llave Bre-B', 'Tu llave (celular, cédula, correo o alfanumérica). Se muestra a quien reserva en pesos.' );
	$campo( 'breb_titular', 'A nombre de', 'Como aparece en el banco, para que la persona confirme que paga a la cuenta correcta.' );
	$campo( 'breb_nota', 'Nota de pago', 'Una línea corta. Ej.: «Desde Nequi, Bancolombia o cualquier banco, con Bre-B».' );
	$campo( 'paypal_me', 'Usuario de PayPal.me (dólares, opcional)', 'Solo el usuario: de paypal.me/<b>focopsicologico</b> escribe «focopsicologico». Si lo dejas vacío, a quien paga en dólares le dices que le envías el enlace por WhatsApp.' );
	echo '</table><h2>Avisos</h2><table class="form-table" role="presentation">';
	$campo( 'email_avisos', 'Correo donde te llegan los avisos', '', 'email' );
	echo '</table>';
	submit_button( 'Guardar ajustes' );
	echo '</form></div>';
}

/* Ficha de solo lectura al abrir una persona o una reserva. */
add_action( 'add_meta_boxes', function () {
	add_meta_box( 'foco_ficha', 'Ficha', function ( $post ) {
		$filas = array();
		if ( 'foco_reserva' === $post->post_type ) {
			$id = $post->ID;
			$filas = array( 'Referencia' => get_post_meta( $id, 'foco_ref', true ), 'Estado' => foco_nombre_estado( foco_estado_reserva( $id ) ), 'Cita' => foco_fecha_larga( (int) get_post_meta( $id, 'foco_inicio', true ) ),
				'Duración' => (int) get_post_meta( $id, 'foco_minutos', true ) . ' minutos', 'Sesión' => trim( get_post_meta( $id, 'foco_concepto', true ) . ' · ' . get_post_meta( $id, 'foco_detalle', true ), ' ·' ),
				'Valor' => foco_dinero( get_post_meta( $id, 'foco_valor', true ), get_post_meta( $id, 'foco_moneda', true ) ), 'Persona' => get_post_meta( $id, 'foco_nombre', true ),
				'Correo' => get_post_meta( $id, 'foco_email', true ), 'WhatsApp' => get_post_meta( $id, 'foco_wa', true ), 'País' => get_post_meta( $id, 'foco_pais', true ) );
		} else {
			$id = $post->ID; $c = (array) get_post_meta( $id, 'foco_consentimiento', true );
			$filas = array( 'WhatsApp' => get_post_meta( $id, 'foco_wa_texto', true ), 'Correo' => get_post_meta( $id, 'foco_email', true ), 'País' => get_post_meta( $id, 'foco_pais', true ),
				'Llegó por' => implode( ', ', array_map( 'foco_nombre_origen', (array) get_post_meta( $id, 'foco_origenes', true ) ) ),
				'Le interesa' => implode( ', ', array_map( 'foco_nombre_programa', array_filter( (array) get_post_meta( $id, 'foco_programas', true ) ) ) ),
				'¿Acepta que le escribas?' => get_post_meta( $id, 'foco_acepta_contacto', true ) === '1' ? 'Sí' : 'No',
				'Autorizó el tratamiento de datos' => isset( $c['fecha'] ) ? $c['fecha'] : '', 'Texto aceptado' => isset( $c['texto'] ) ? $c['texto'] : '' );
		}
		echo '<table class="widefat striped">';
		foreach ( $filas as $k => $v ) { echo '<tr><th style="width:220px">' . esc_html( $k ) . '</th><td>' . esc_html( $v ) . '</td></tr>'; }
		echo '</table><p class="description">Si la persona pide borrar sus datos (Ley 1581), envía esta ficha a la papelera y vacíala.</p>';
	}, array( 'foco_persona', 'foco_reserva' ), 'normal', 'high' );
} );
