<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<script>try{var t=localStorage.getItem('ntodo_theme');if(t==='dark'||t==='light')document.documentElement.dataset.theme=t}catch(e){}</script>
<?php if ( ! has_site_icon() ) : ?>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ctext y='.9em' font-size='90'%3E%E2%9C%85%3C/text%3E%3C/svg%3E">
<?php endif; ?>
<?php wp_head(); ?>
</head>
<body class="ntodo-body">
<?php
wp_body_open();
echo do_shortcode( '[notion_todo]' );
wp_footer();
?>
</body>
</html>
