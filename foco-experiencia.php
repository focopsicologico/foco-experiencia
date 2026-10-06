<?php
/**
 * Foco · Experiencia interactiva — se despliega desde GitHub en wp-content/foco-experiencia/.
 * La carga mu-plugins/foco-cargador.php. No depende del tema: muestra la experiencia en la portada
 * y en la página /experiencia/. Generado por Agente-prototipo-web/execution/preparar_despliegue.py — no editar a mano.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'FOCO_EXP_DIR', __DIR__ );
define( 'FOCO_EXP_URL', content_url( 'foco-experiencia' ) );

function foco_exp_version() {
	$v = @file_get_contents( FOCO_EXP_DIR . '/VERSION' );
	return $v ? trim( $v ) : '0';
}

/** ¿Esta petición muestra la experiencia? Portada del sitio y la página con slug «experiencia». */
function foco_exp_aplica() {
	return is_front_page() || is_page( 'experiencia' );
}

add_filter( 'template_include', function ( $plantilla ) {
	return foco_exp_aplica() ? FOCO_EXP_DIR . '/plantilla.php' : $plantilla;
}, 99 );

// Los estilos del tema (Tailwind/Stitch) no deben tocar la experiencia.
add_action( 'wp_enqueue_scripts', function () {
	if ( foco_exp_aplica() ) {
		wp_dequeue_style( 'foco-fonts' );
		wp_dequeue_style( 'foco-style' );
	}
}, 100 );
