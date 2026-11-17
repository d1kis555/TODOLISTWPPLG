<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ntodo_Rest {

	const NS = 'ntodo/v1';

	const POLYA = array( 'title', 'note', 'done', 'priority', 'due_at', 'due_has_time', 'remind_before', 'repeat_rule', 'position' );

	public static function Init() {
		add_action( 'rest_api_init', array( __CLASS__, 'Marshruty' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'bez_kesha' ), 10, 3 );
	}

	public static function bez_kesha( $resp, $server, $req ) {
		if ( str_starts_with( $req->get_route(), '/' . self::NS ) && $resp instanceof WP_HTTP_Response ) {
			$resp->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		}
		return $resp;
	}

	public static function Marshruty() {
		$vhod     = array( __CLASS__, 'Proverka_vhoda' );
		$vladelec = array( __CLASS__, 'Proverka_vladelca' );
		$id       = '/tasks/(?P<id>\d+)';

		register_rest_route(
			self::NS,
			'/tasks',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'Spisok' ),
					'permission_callback' => $vhod,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'Sozdat' ),
					'permission_callback' => $vhod,
				),
			)
		);
		register_rest_route(
			self::NS,
			$id,
			array(
				array(
					'methods'             => 'PATCH',
					'callback'            => array( __CLASS__, 'Izmenit' ),
					'permission_callback' => $vladelec,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'Udalit' ),
					'permission_callback' => $vladelec,
				),
			)
		);
		register_rest_route(
			self::NS,
			$id . '/restore',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'Vernut' ),
				'permission_callback' => $vladelec,
			)
		);
		register_rest_route(
			self::NS,
			'/tasks/order',
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'Poryadok' ),
				'permission_callback' => $vhod,
			)
		);
		register_rest_route(
			self::NS,
			'/tasks/completed',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'Ochistit' ),
				'permission_callback' => $vhod,
			)
		);
		register_rest_route(
			self::NS,
			'/tasks/import',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'Import' ),
				'permission_callback' => $vhod,
			)
		);
		register_rest_route(
			self::NS,
			'/telegram/link',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'Tg_kod' ),
					'permission_callback' => $vhod,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'Tg_otvyazat' ),
					'permission_callback' => $vhod,
				),
			)
		);
		register_rest_route(
			self::NS,
			'/telegram/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'Tg_status' ),
				'permission_callback' => $vhod,
			)
		);
		register_rest_route(
			self::NS,
			'/telegram/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( 'Ntodo_Telegram', 'Webhook' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/cron/reminders',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'Cron' ),
				'permission_callback' => array( __CLASS__, 'proverka_klyucha' ),
			)
		);
		register_rest_route(
			self::NS,
			'/profile',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'Profil' ),
				'permission_callback' => $vhod,
			)
		);
		register_rest_route(
			self::NS,
			'/auth/login',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'Vhod' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/auth/register',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'Registraciya' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/auth/logout',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'Vyhod' ),
				'permission_callback' => 'is_user_logged_in',
			)
		);
	}

	public static function Limit_zaprosov( $uid ) {
		$k = 'ntodo_rl_' . $uid . '_' . (int) floor( time() / 60 );
		$n = (int) get_transient( $k );
		if ( $n >= 60 ) {
			return false;
		}
		set_transient( $k, $n + 1, 70 );
		return true;
	}

	public static function Proverka_vhoda( WP_REST_Request $r ) {
		if ( ! is_user_logged_in() ) {
			return Ntodo_DB::Err( 'ntodo_auth', __( 'Войдите в аккаунт, чтобы продолжить.', 'notion-todo' ), 401 );
		}
		if ( 'GET' !== $r->get_method() && ! self::Limit_zaprosov( get_current_user_id() ) ) {
			return Ntodo_DB::Err( 'ntodo_rate', __( 'Слишком много запросов. Подождите минуту.', 'notion-todo' ), 429 );
		}
		return true;
	}

	public static function Proverka_vladelca( WP_REST_Request $r ) {
		$ok = self::Proverka_vhoda( $r );
		if ( true !== $ok ) {
			return $ok;
		}
		if ( ! Ntodo_DB::Naiti_zadachu( get_current_user_id(), (int) $r['id'], true ) ) {
			return Ntodo_DB::Err( 'ntodo_not_found', __( 'Задача не найдена.', 'notion-todo' ), 404 );
		}
		return true;
	}

	public static function proverka_klyucha( WP_REST_Request $r ) {
		$key = (string) get_option( 'ntodo_cron_key', '' );
		if ( '' === $key || ! hash_equals( $key, (string) $r['key'] ) ) {
			return Ntodo_DB::Err( 'ntodo_forbidden', __( 'Неверный ключ.', 'notion-todo' ), 403 );
		}
		return true;
	}

	private static function Telo( WP_REST_Request $r ) {
		$d = $r->get_json_params();
		return is_array( $d ) ? $d : $r->get_body_params();
	}

	public static function Spisok( WP_REST_Request $r ) {
		$f    = in_array( $r['filter'], array( 'all', 'today', 'upcoming', 'done' ), true ) ? $r['filter'] : 'all';
		$q    = mb_substr( trim( (string) $r['q'] ), 0, 200 );
		$rows = Ntodo_DB::Poluchit_zadachi( get_current_user_id(), $f, $q );
		return rest_ensure_response( array_map( array( 'Ntodo_DB', 'Formatirovat' ), $rows ) );
	}

	public static function Sozdat( WP_REST_Request $r ) {
		$d   = array_intersect_key( self::Telo( $r ), array_flip( array_merge( self::POLYA, array( 'client_uid' ) ) ) );
		$res = Ntodo_DB::Sozdat_zadachu( get_current_user_id(), $d );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$resp = rest_ensure_response( Ntodo_DB::Formatirovat( $res ) );
		$resp->set_status( 201 );
		return $resp;
	}

	public static function Izmenit( WP_REST_Request $r ) {
		$d   = array_intersect_key( self::Telo( $r ), array_flip( self::POLYA ) );
		$res = Ntodo_DB::Obnovit_zadachu( get_current_user_id(), (int) $r['id'], $d );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( ! $res || ! $res['task'] ) {
			return Ntodo_DB::Err( 'ntodo_not_found', __( 'Задача не найдена.', 'notion-todo' ), 404 );
		}
		$out            = Ntodo_DB::Formatirovat( $res['task'] );
		$out['spawned'] = $res['spawned'] ? Ntodo_DB::Formatirovat( $res['spawned'] ) : null;
		return rest_ensure_response( $out );
	}

	public static function Udalit( WP_REST_Request $r ) {
		Ntodo_DB::Udalit_zadachu( get_current_user_id(), (int) $r['id'] );
		return new WP_REST_Response( null, 204 );
	}

	public static function Vernut( WP_REST_Request $r ) {
		$task = Ntodo_DB::Vosstanovit_zadachu( get_current_user_id(), (int) $r['id'] );
		return rest_ensure_response( Ntodo_DB::Formatirovat( $task ) );
	}

	public static function Poryadok( WP_REST_Request $r ) {
		$d = self::Telo( $r );
		if ( empty( $d['ids'] ) || ! is_array( $d['ids'] ) ) {
			return Ntodo_DB::Err( 'ntodo_bad', __( 'Передайте список ids.', 'notion-todo' ) );
		}
		Ntodo_DB::Poryadok( get_current_user_id(), $d['ids'] );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function Ochistit( WP_REST_Request $r ) {
		return rest_ensure_response( array( 'ids' => Ntodo_DB::Ochistit_vypolnennye( get_current_user_id() ) ) );
	}

	public static function Import( WP_REST_Request $r ) {
		$d     = self::Telo( $r );
		$spisok = isset( $d['tasks'] ) && is_array( $d['tasks'] ) ? array_slice( $d['tasks'], 0, 500 ) : array();
		$uid   = get_current_user_id();
		$out   = array();

		foreach ( $spisok as $one ) {
			if ( ! is_array( $one ) ) {
				continue;
			}
			$one = array_intersect_key( $one, array_flip( array_merge( self::POLYA, array( 'client_uid' ) ) ) );
			unset( $one['position'] );
			$res = Ntodo_DB::Sozdat_zadachu( $uid, $one );
			if ( $res && ! is_wp_error( $res ) ) {
				$out[] = Ntodo_DB::Formatirovat( $res );
			}
		}
		return rest_ensure_response( array( 'tasks' => $out ) );
	}

	public static function Tg_kod( WP_REST_Request $r ) {
		$bot = (string) get_option( 'ntodo_tg_bot', '' );
		if ( '' === $bot || '' === Ntodo_Telegram::Token() ) {
			return Ntodo_DB::Err( 'ntodo_no_bot', __( 'Бот ещё не настроен администратором сайта.', 'notion-todo' ) );
		}
		$code = wp_generate_password( 32, false, false );
		Ntodo_DB::Zapisat_tg(
			get_current_user_id(),
			array(
				'link_code'    => $code,
				'code_expires' => gmdate( 'Y-m-d H:i:s', time() + 15 * MINUTE_IN_SECONDS ),
			)
		);
		return rest_ensure_response(
			array(
				'code'       => $code,
				'url'        => 'https://t.me/' . rawurlencode( $bot ) . '?start=' . $code,
				'expires_in' => 900,
			)
		);
	}

	public static function Tg_status() {
		return rest_ensure_response( Ntodo_DB::Status_tg( get_current_user_id() ) );
	}

	public static function Tg_otvyazat() {
		$uid = get_current_user_id();
		$row = Ntodo_DB::Stroka_tg( $uid );
		if ( $row && $row->chat_id ) {
			Ntodo_Telegram::Napisat( $row->chat_id, __( '🔌 Аккаунт отключён на сайте. Напоминания больше не придут.', 'notion-todo' ) );
		}
		Ntodo_DB::Otvyazat_tg( $uid );
		return rest_ensure_response( Ntodo_DB::Status_tg( $uid ) );
	}

	public static function Cron() {
		return rest_ensure_response( Ntodo_Reminders::Zapusk_rassylki() );
	}

	public static function Profil( WP_REST_Request $r ) {
		$d  = self::Telo( $r );
		$tz = isset( $d['timezone'] ) ? Ntodo_DB::Normalnyi_tz( substr( (string) $d['timezone'], 0, 64 ) ) : 'UTC';
		Ntodo_DB::Zapisat_tg( get_current_user_id(), array( 'timezone' => $tz ) );
		return rest_ensure_response( array( 'timezone' => $tz ) );
	}

	private static function Limit_vhoda() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0';
		$k  = 'ntodo_login_' . md5( $ip );
		$n  = (int) get_transient( $k );
		if ( $n >= 10 ) {
			return false;
		}
		set_transient( $k, $n + 1, 10 * MINUTE_IN_SECONDS );
		return true;
	}

	public static function Vhod( WP_REST_Request $r ) {
		if ( ! self::Limit_vhoda() ) {
			return Ntodo_DB::Err( 'ntodo_rate', __( 'Слишком много попыток. Попробуйте через 10 минут.', 'notion-todo' ), 429 );
		}
		$d    = self::Telo( $r );
		$user = wp_signon(
			array(
				'user_login'    => sanitize_text_field( (string) ( $d['login'] ?? '' ) ),
				'user_password' => (string) ( $d['password'] ?? '' ),
				'remember'      => ! empty( $d['remember'] ),
			),
			is_ssl()
		);
		if ( is_wp_error( $user ) ) {
			return Ntodo_DB::Err( 'ntodo_login', __( 'Неверный логин или пароль.', 'notion-todo' ) );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function Registraciya( WP_REST_Request $r ) {
		if ( ! get_option( 'users_can_register' ) ) {
			return Ntodo_DB::Err( 'ntodo_closed', __( 'Регистрация на сайте закрыта.', 'notion-todo' ), 403 );
		}
		if ( ! self::Limit_vhoda() ) {
			return Ntodo_DB::Err( 'ntodo_rate', __( 'Слишком много попыток. Попробуйте через 10 минут.', 'notion-todo' ), 429 );
		}
		$d = self::Telo( $r );
		if ( ! empty( $d['website'] ) ) {
			return Ntodo_DB::Err( 'ntodo_bot', __( 'Не удалось зарегистрироваться.', 'notion-todo' ) );
		}

		$login = sanitize_user( (string) ( $d['login'] ?? '' ), true );
		$email = sanitize_email( (string) ( $d['email'] ?? '' ) );
		$pass  = (string) ( $d['password'] ?? '' );

		if ( strlen( $login ) < 3 ) {
			return Ntodo_DB::Err( 'ntodo_login', __( 'Логин — минимум 3 символа (латиница, цифры).', 'notion-todo' ) );
		}
		if ( ! is_email( $email ) ) {
			return Ntodo_DB::Err( 'ntodo_email', __( 'Введите корректный email.', 'notion-todo' ) );
		}
		if ( strlen( $pass ) < 8 ) {
			return Ntodo_DB::Err( 'ntodo_pass', __( 'Пароль — минимум 8 символов.', 'notion-todo' ) );
		}
		if ( username_exists( $login ) || email_exists( $email ) ) {
			return Ntodo_DB::Err( 'ntodo_exists', __( 'Такой логин или email уже зарегистрирован.', 'notion-todo' ) );
		}

		$uid = wp_insert_user(
			array(
				'user_login' => $login,
				'user_email' => $email,
				'user_pass'  => $pass,
				'role'       => 'subscriber',
			)
		);
		if ( is_wp_error( $uid ) ) {
			return Ntodo_DB::Err( 'ntodo_reg', $uid->get_error_message() );
		}
		wp_set_current_user( $uid );
		wp_set_auth_cookie( $uid, true, is_ssl() );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function Vyhod() {
		wp_logout();
		return rest_ensure_response( array( 'ok' => true ) );
	}
}
