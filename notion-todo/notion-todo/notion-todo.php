<?php
/**
 * Plugin Name: Notion To-Do
 * Description: Список задач в стиле Notion с напоминаниями в Telegram.
 * Version: 1.0.0
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Author: d1kis555
 * Text Domain: notion-todo
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NTODO_VERSION', '1.0.0' );
define( 'NTODO_DB_VERSION', '1.0.0' );
define( 'NTODO_DIR', plugin_dir_path( __FILE__ ) );
define( 'NTODO_URL', plugin_dir_url( __FILE__ ) );

require_once NTODO_DIR . 'includes/class-db.php';
require_once NTODO_DIR . 'includes/class-rest.php';
require_once NTODO_DIR . 'includes/class-telegram.php';
require_once NTODO_DIR . 'includes/class-reminders.php';
require_once NTODO_DIR . 'includes/class-settings.php';
require_once NTODO_DIR . 'includes/class-front.php';

add_filter( 'cron_schedules', 'ntodo_Dobavit_minutu' );

function ntodo_Dobavit_minutu( $s ) {
	$s['ntodo_minuta'] = array(
		'interval' => 60,
		'display'  => __( 'Раз в минуту', 'notion-todo' ),
	);
	return $s;
}

register_activation_hook( __FILE__, 'ntodo_Aktivaciya' );
register_deactivation_hook( __FILE__, 'ntodo_deaktivaciya' );

function ntodo_Aktivaciya() {
	Ntodo_DB::Sozdat_tablicy();
	if ( ! get_option( 'ntodo_cron_key' ) ) {
		add_option( 'ntodo_cron_key', wp_generate_password( 32, false, false ), '', false );
	}
	if ( ! wp_next_scheduled( 'ntodo_kazhduyu_minutu' ) ) {
		wp_schedule_event( time(), 'ntodo_minuta', 'ntodo_kazhduyu_minutu' );
	}
}

function ntodo_deaktivaciya() {
	wp_clear_scheduled_hook( 'ntodo_kazhduyu_minutu' );
}

add_action( 'ntodo_kazhduyu_minutu', array( 'Ntodo_Reminders', 'Zapusk_rassylki' ) );
add_action( 'plugins_loaded', 'ntodo_Start' );

function ntodo_Start() {
	load_plugin_textdomain( 'notion-todo', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	Ntodo_DB::Proverka_versii();
	Ntodo_Rest::Init();
	Ntodo_Settings::Init();
	Ntodo_Front::Init();

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::add_command(
			'ntodo reminders',
			function () {
				$r = Ntodo_Reminders::Zapusk_rassylki();
				WP_CLI::success( 'Отправлено: ' . (int) ( $r['sent'] ?? 0 ) );
			}
		);
	}
}
