<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$ntodo_token = defined( 'NTODO_TG_TOKEN' ) && NTODO_TG_TOKEN ? NTODO_TG_TOKEN : get_option( 'ntodo_tg_token' );
if ( $ntodo_token ) {
	wp_remote_post(
		'https://api.telegram.org/bot' . $ntodo_token . '/deleteWebhook',
		array(
			'timeout'  => 5,
			'blocking' => false,
		)
	);
}

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ntodo_tasks" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ntodo_telegram" );

foreach ( array( 'ntodo_db_version', 'ntodo_tg_token', 'ntodo_tg_secret', 'ntodo_tg_bot', 'ntodo_tg_last_update', 'ntodo_cron_key' ) as $ntodo_opt ) {
	delete_option( $ntodo_opt );
}

$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_ntodo\_%' OR option_name LIKE '\_transient\_timeout\_ntodo\_%'" );

wp_clear_scheduled_hook( 'ntodo_kazhduyu_minutu' );
