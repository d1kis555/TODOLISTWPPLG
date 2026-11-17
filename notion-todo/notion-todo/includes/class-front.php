<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ntodo_Front {

	public static function Init() {
		add_shortcode( 'notion_todo', array( __CLASS__, 'Shortcode' ) );
		add_filter( 'template_include', array( __CLASS__, 'Pustoi_shablon' ), 99 );
		add_action( 'template_redirect', array( __CLASS__, 'pered_stranicei' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'Podklyuchit_faily' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'ubrat_lishnee' ), 999 );
	}

	public static function Eto_nasha_stranica() {
		if ( ! is_singular() ) {
			return false;
		}
		$p = get_post();
		return $p && has_shortcode( $p->post_content, 'notion_todo' );
	}

	public static function pered_stranicei() {
		if ( ! self::Eto_nasha_stranica() ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		add_filter( 'show_admin_bar', '__return_false' );
	}

	public static function Pustoi_shablon( $template ) {
		return self::Eto_nasha_stranica() ? NTODO_DIR . 'templates/page.php' : $template;
	}

	public static function ubrat_lishnee() {
		if ( ! self::Eto_nasha_stranica() ) {
			return;
		}
		global $wp_styles;
		foreach ( (array) $wp_styles->queue as $h ) {
			$src = $wp_styles->registered[ $h ]->src ?? '';
			if ( is_string( $src ) && str_contains( $src, '/themes/' ) ) {
				wp_dequeue_style( $h );
			}
		}
		foreach ( array( 'wp-block-library', 'wp-block-library-theme', 'classic-theme-styles', 'global-styles' ) as $h ) {
			wp_dequeue_style( $h );
		}
	}

	public static function Podklyuchit_faily() {
		if ( ! self::Eto_nasha_stranica() ) {
			return;
		}
		$js  = 'assets/dist/app.js';
		$css = 'assets/dist/app.css';

		wp_enqueue_style( 'ntodo-app', NTODO_URL . $css, array(), (string) @filemtime( NTODO_DIR . $css ) );
		wp_enqueue_script(
			'ntodo-app',
			NTODO_URL . $js,
			array(),
			(string) @filemtime( NTODO_DIR . $js ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_add_inline_script( 'ntodo-app', 'window.NTODO=' . wp_json_encode( self::Dannye_dlya_starta() ) . ';', 'before' );
	}

	public static function Dannye_dlya_starta() {
		$uid = get_current_user_id();
		$d   = array(
			'rest'        => untrailingslashit( rest_url( Ntodo_Rest::NS ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'ajax'        => admin_url( 'admin-ajax.php' ),
			'home'        => home_url( '/' ),
			'user'        => null,
			'tasks'       => array(),
			'telegram'    => null,
			'tz'          => 'UTC',
			'botReady'    => '' !== (string) get_option( 'ntodo_tg_bot', '' ) && '' !== Ntodo_Telegram::Token(),
			'canRegister' => (bool) get_option( 'users_can_register' ),
		);

		if ( $uid ) {
			$u             = wp_get_current_user();
			$d['user']     = array(
				'id'   => $uid,
				'name' => $u->display_name ? $u->display_name : $u->user_login,
			);
			$d['tasks']    = array_map( array( 'Ntodo_DB', 'Formatirovat' ), Ntodo_DB::Poluchit_zadachi( $uid ) );
			$d['telegram'] = Ntodo_DB::Status_tg( $uid );
			$d['tz']       = Ntodo_DB::Polzovatel_tz( $uid );
		}
		return $d;
	}

	public static function Shortcode() {
		ob_start();
		include NTODO_DIR . 'templates/app.php';
		return ob_get_clean();
	}
}
