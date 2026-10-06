<?php
/** Plantilla de página completa de la experiencia (la elige foco-experiencia.php). */
if ( ! defined( 'ABSPATH' ) ) { exit; }
// Cada despliegue cambia VERSION; sin caché de página para que el cambio se vea de inmediato.
nocache_headers();
header( 'X-LiteSpeed-Cache-Control: no-cache' );
$foco_app = FOCO_EXP_URL . '/app';
$foco_ver = rawurlencode( foco_exp_version() );
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#F5F0EA">
<?php wp_head(); ?>
<link rel="stylesheet" href="<?php echo esc_url( $foco_app . '/experiencia.css?v=' . $foco_ver ); ?>">
<style>html,body{background:#F5F0EA;margin:0}</style>
</head>
<body <?php body_class( 'foco-experiencia' ); ?>>
<?php wp_body_open(); ?>
<div id="foco-experiencia"></div>
<noscript>Para ver la experiencia de Foco necesitas activar JavaScript.</noscript>
<script type="module" src="<?php echo esc_url( $foco_app . '/experiencia.js?v=' . $foco_ver ); ?>"></script>
<?php wp_footer(); ?>
</body>
</html>
