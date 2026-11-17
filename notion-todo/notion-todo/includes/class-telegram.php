<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ntodo_Telegram {

	const MESYACY = array( 'янв.', 'февр.', 'мар.', 'апр.', 'мая', 'июн.', 'июл.', 'авг.', 'сент.', 'окт.', 'нояб.', 'дек.' );

	public static function Token() {
		if ( defined( 'NTODO_TG_TOKEN' ) && NTODO_TG_TOKEN ) {
			return (string) NTODO_TG_TOKEN;
		}
		return (string) get_option( 'ntodo_tg_token', '' );
	}

	public static function Zapros( $metod, $params = array() ) {
		$token = self::Token();
		if ( '' === $token ) {
			return array(
				'ok'          => false,
				'error_code'  => 0,
				'description' => 'Токен бота не задан',
			);
		}
		$res = wp_remote_post(
			'https://api.telegram.org/bot' . $token . '/' . $metod,
			array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $params ? $params : new stdClass() ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return array(
				'ok'          => false,
				'error_code'  => 0,
				'description' => $res->get_error_message(),
			);
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $body ) ) {
			return array(
				'ok'          => false,
				'error_code'  => (int) wp_remote_retrieve_response_code( $res ),
				'description' => 'Пустой ответ Telegram',
			);
		}
		return $body;
	}

	public static function e( $s ) {
		return htmlspecialchars( (string) $s, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	public static function Napisat( $chat, $text, $knopki = null ) {
		$p = array(
			'chat_id'              => (int) $chat,
			'text'                 => $text,
			'parse_mode'           => 'HTML',
			'link_preview_options' => array( 'is_disabled' => true ),
		);
		if ( $knopki ) {
			$p['reply_markup'] = array( 'inline_keyboard' => $knopki );
		}
		return self::Zapros( 'sendMessage', $p );
	}

	public static function Pravka( $chat, $mid, $text, $knopki = null ) {
		$p = array(
			'chat_id'    => (int) $chat,
			'message_id' => (int) $mid,
			'text'       => $text,
			'parse_mode' => 'HTML',
		);
		if ( $knopki ) {
			$p['reply_markup'] = array( 'inline_keyboard' => $knopki );
		}
		return self::Zapros( 'editMessageText', $p );
	}

	public static function Otvet_knopka( $id, $text = '' ) {
		self::Zapros(
			'answerCallbackQuery',
			array(
				'callback_query_id' => (string) $id,
				'text'              => $text,
			)
		);
	}

	public static function Komandy() {
		return array(
			array(
				'command'     => 'today',
				'description' => 'Задачи на сегодня',
			),
			array(
				'command'     => 'list',
				'description' => 'Все невыполненные задачи',
			),
			array(
				'command'     => 'add',
				'description' => 'Добавить задачу: /add купить хлеб завтра в 10',
			),
			array(
				'command'     => 'digest',
				'description' => 'Утренняя сводка: /digest on или off',
			),
			array(
				'command'     => 'stop',
				'description' => 'Отключить бота от аккаунта',
			),
			array(
				'command'     => 'help',
				'description' => 'Список команд',
			),
		);
	}

	public static function Podklyuchit() {
		$me = self::Zapros( 'getMe' );
		if ( empty( $me['ok'] ) ) {
			return new WP_Error( 'ntodo_tg', 'Telegram не принял токен: ' . ( $me['description'] ?? '' ) );
		}
		update_option( 'ntodo_tg_bot', $me['result']['username'], false );

		$secret = wp_generate_password( 48, false, false );
		$old    = get_option( 'ntodo_tg_secret', '' );
		update_option( 'ntodo_tg_secret', $secret, false );

		$hook = self::Zapros(
			'setWebhook',
			array(
				'url'                  => rest_url( Ntodo_Rest::NS . '/telegram/webhook' ),
				'secret_token'         => $secret,
				'allowed_updates'      => array( 'message', 'callback_query' ),
				'drop_pending_updates' => true,
			)
		);
		if ( empty( $hook['ok'] ) ) {
			update_option( 'ntodo_tg_secret', $old, false );
			return new WP_Error( 'ntodo_tg', 'Не удалось установить webhook: ' . ( $hook['description'] ?? '' ) );
		}

		self::Zapros( 'setMyCommands', array( 'commands' => self::Komandy() ) );
		return $me['result'];
	}

	public static function Webhook( WP_REST_Request $r ) {
		$secret = (string) get_option( 'ntodo_tg_secret', '' );
		$hdr    = (string) $r->get_header( 'X-Telegram-Bot-Api-Secret-Token' );
		if ( '' === $secret || ! hash_equals( $secret, $hdr ) ) {
			return Ntodo_DB::Err( 'ntodo_forbidden', 'Forbidden', 403 );
		}

		update_option( 'ntodo_tg_last_update', time(), false );
		$u = $r->get_json_params();

		try {
			if ( ! empty( $u['message'] ) ) {
				self::Obrabotka_soobsheniya( $u['message'] );
			} elseif ( ! empty( $u['callback_query'] ) ) {
				self::Obrabotka_knopki( $u['callback_query'] );
			}
		} catch ( Throwable $e ) {
			error_log( 'ntodo telegram: ' . $e->getMessage() );
		}

		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function Tekst_pomoshi() {
		return "<b>Команды</b>\n"
			. "/today — задачи на сегодня\n"
			. "/list — все невыполненные\n"
			. "/add <текст> — новая задача («завтра в 10», «в пятницу», «через 2 часа»)\n"
			. "/digest on|off — утренняя сводка в 9:00\n"
			. "/stop — отключить бота\n"
			. "/help — эта справка\n\n"
			. 'Можно просто написать текст — он станет задачей.';
	}

	public static function Obrabotka_soobsheniya( $m ) {
		if ( ( $m['chat']['type'] ?? '' ) !== 'private' ) {
			return;
		}
		$chat = (int) $m['chat']['id'];
		$text = trim( (string) ( $m['text'] ?? '' ) );
		if ( '' === $text ) {
			return;
		}

		if ( preg_match( '~^/start(?:@\w+)?\s+([A-Za-z0-9]{32})~u', $text, $mm ) ) {
			self::Privyazka( $chat, $mm[1], $m['from'] ?? array() );
			return;
		}

		$uid = Ntodo_DB::Polzovatel_po_chatu( $chat );
		$cmd = '';
		$arg = $text;
		if ( preg_match( '~^/(\w+)(?:@\w+)?(?:\s+(.*))?$~su', $text, $mm ) ) {
			$cmd = strtolower( $mm[1] );
			$arg = trim( $mm[2] ?? '' );
		}

		if ( 'help' === $cmd ) {
			self::Napisat( $chat, self::Tekst_pomoshi() );
			return;
		}

		if ( ! $uid ) {
			if ( 'start' === $cmd ) {
				self::Napisat( $chat, "👋 Привет! Я присылаю напоминания о задачах.\n\nОткройте сайт, нажмите «Подключить Telegram» — и всё заработает:\n" . esc_url( home_url( '/' ) ) );
				set_transient( 'ntodo_nl_' . $chat, 1, DAY_IN_SECONDS );
			} elseif ( ! get_transient( 'ntodo_nl_' . $chat ) ) {
				self::Napisat( $chat, "Сначала подключите бота на сайте:\n" . esc_url( home_url( '/' ) ) );
				set_transient( 'ntodo_nl_' . $chat, 1, DAY_IN_SECONDS );
			}
			return;
		}

		switch ( $cmd ) {
			case 'start':
				self::Napisat( $chat, "Бот уже подключён ✅\n\n" . self::Tekst_pomoshi() );
				return;
			case 'today':
				self::Spisok_segodnya( $uid, $chat );
				return;
			case 'list':
				self::Spisok_vseh( $uid, $chat, 1 );
				return;
			case 'add':
				if ( '' === $arg ) {
					self::Napisat( $chat, 'Напишите текст задачи: <code>/add позвонить маме завтра в 19</code>' );
					return;
				}
				self::Dobavit_iz_teksta( $uid, $chat, $arg );
				return;
			case 'digest':
				self::Svodka_vkl( $uid, $chat, $arg );
				return;
			case 'stop':
				Ntodo_DB::Otvyazat_tg( $uid );
				self::Napisat( $chat, '🔌 Бот отключён от аккаунта. Подключить снова можно на сайте.' );
				return;
			case '':
				self::Dobavit_iz_teksta( $uid, $chat, $text );
				return;
			default:
				self::Napisat( $chat, "Не знаю такой команды 🤔\n\n" . self::Tekst_pomoshi() );
		}
	}

	public static function Privyazka( $chat, $code, $from ) {
		global $wpdb;
		$g   = Ntodo_DB::TG();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $g WHERE link_code = %s AND code_expires > %s", $code, Ntodo_DB::Seichas() ) );
		if ( ! $row ) {
			self::Napisat( $chat, '⚠️ Код недействителен или устарел. Нажмите «Подключить Telegram» на сайте ещё раз.' );
			return;
		}

		$wpdb->query( $wpdb->prepare( "UPDATE $g SET chat_id = NULL, username = NULL, linked_at = NULL WHERE chat_id = %d AND user_id <> %d", $chat, $row->user_id ) );
		$wpdb->update(
			$g,
			array(
				'chat_id'      => $chat,
				'username'     => ! empty( $from['username'] ) ? substr( preg_replace( '/[^A-Za-z0-9_]/', '', (string) $from['username'] ), 0, 64 ) : null,
				'link_code'    => null,
				'code_expires' => null,
				'linked_at'    => Ntodo_DB::Seichas(),
				'blocked'      => 0,
			),
			array( 'user_id' => $row->user_id )
		);
		delete_transient( 'ntodo_nl_' . $chat );

		$u    = get_userdata( $row->user_id );
		$imya = $u ? ( $u->display_name ? $u->display_name : $u->user_login ) : '';
		self::Napisat( $chat, '✅ Готово! Аккаунт <b>' . self::e( $imya ) . "</b> подключён.\nНапоминания о задачах будут приходить сюда.\n\n" . self::Tekst_pomoshi() );
	}

	public static function Svodka_vkl( $uid, $chat, $arg ) {
		$arg = mb_strtolower( $arg );
		if ( in_array( $arg, array( 'on', 'вкл', 'да' ), true ) ) {
			Ntodo_DB::Zapisat_tg( $uid, array( 'digest' => 1 ) );
			self::Napisat( $chat, '☀️ Утренняя сводка включена — каждый день в 9:00 по вашему времени.' );
		} elseif ( in_array( $arg, array( 'off', 'выкл', 'нет' ), true ) ) {
			Ntodo_DB::Zapisat_tg( $uid, array( 'digest' => 0 ) );
			self::Napisat( $chat, 'Утренняя сводка выключена.' );
		} else {
			$row = Ntodo_DB::Stroka_tg( $uid );
			self::Napisat( $chat, 'Сводка сейчас ' . ( $row && $row->digest ? '<b>включена</b>' : '<b>выключена</b>' ) . ".\n<code>/digest on</code> или <code>/digest off</code>" );
		}
	}

	public static function Data_krasivo( $utc, $s_vremenem, $tz ) {
		$z     = new DateTimeZone( $tz );
		$d     = ( new DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) ) )->setTimezone( $z );
		$today = new DateTimeImmutable( 'today', $z );
		$raz   = (int) $today->diff( $d->setTime( 0, 0 ) )->format( '%r%a' );

		if ( 0 === $raz ) {
			$t = 'Сегодня';
		} elseif ( 1 === $raz ) {
			$t = 'Завтра';
		} elseif ( -1 === $raz ) {
			$t = 'Вчера';
		} else {
			$t = (int) $d->format( 'j' ) . ' ' . self::MESYACY[ (int) $d->format( 'n' ) - 1 ];
			if ( $d->format( 'Y' ) !== $today->format( 'Y' ) ) {
				$t .= ' ' . $d->format( 'Y' );
			}
		}
		return $s_vremenem ? $t . ', ' . $d->format( 'H:i' ) : $t;
	}

	public static function Prioritet_tekst( $p ) {
		return array(
			1 => '🟢 Низкий',
			2 => '🟡 Средний',
			3 => '🔴 Высокий',
		)[ $p ] ?? '';
	}

	public static function Meta_zadachi( $task, $tz ) {
		$meta = array();
		if ( $task->due_at ) {
			$meta[] = '📅 ' . self::Data_krasivo( $task->due_at, (int) $task->due_has_time, $tz );
		}
		if ( (int) $task->priority > 0 ) {
			$meta[] = self::Prioritet_tekst( (int) $task->priority );
		}
		return implode( ' · ', $meta );
	}

	public static function Tekst_napominaniya( $task, $tz ) {
		$t    = "⏰ <b>Напоминание</b>\n\n" . self::e( $task->title );
		$meta = self::Meta_zadachi( $task, $tz );
		return $meta ? $t . "\n" . $meta : $t;
	}

	public static function Knopki_napominaniya( $id ) {
		return array(
			array(
				array(
					'text'          => '✅ Готово',
					'callback_data' => 'done:' . $id,
				),
				array(
					'text'          => '⏱ +15 мин',
					'callback_data' => 'snz:' . $id . ':15',
				),
				array(
					'text'          => '⏱ +1 ч',
					'callback_data' => 'snz:' . $id . ':60',
				),
				array(
					'text'          => '📅 Завтра',
					'callback_data' => 'tmr:' . $id,
				),
			),
		);
	}

	public static function Otpravit_napominanie( $row ) {
		return self::Napisat( $row->chat_id, self::Tekst_napominaniya( $row, Ntodo_DB::Normalnyi_tz( $row->timezone ) ), self::Knopki_napominaniya( (int) $row->id ) );
	}

	private static function Korotko( $s, $n = 28 ) {
		return mb_strlen( $s ) > $n ? mb_substr( $s, 0, $n - 1 ) . '…' : $s;
	}

	public static function Spisok_segodnya( $uid, $chat, $mid = null, $zagolovok = '📅 <b>Задачи на сегодня</b>' ) {
		$tz    = Ntodo_DB::Polzovatel_tz( $uid );
		$tasks = array_slice( Ntodo_DB::Poluchit_zadachi( $uid, 'today' ), 0, 20 );

		if ( ! $tasks ) {
			$text = $zagolovok . "\n\nНа сегодня задач нет 🎉";
			return $mid ? self::Pravka( $chat, $mid, $text ) : self::Napisat( $chat, $text );
		}

		$lines  = array();
		$knopki = array();
		foreach ( $tasks as $i => $t ) {
			$meta    = self::Meta_zadachi( $t, $tz );
			$lines[] = ( $i + 1 ) . '. ' . self::e( $t->title ) . ( $meta ? "\n    <i>" . self::e( $meta ) . '</i>' : '' );
			if ( $i < 10 ) {
				$knopki[] = array(
					array(
						'text'          => '✅ ' . self::Korotko( $t->title ),
						'callback_data' => 'ld:' . $t->id . ':t',
					),
				);
			}
		}
		$text = $zagolovok . "\n\n" . implode( "\n", $lines );
		return $mid ? self::Pravka( $chat, $mid, $text, $knopki ) : self::Napisat( $chat, $text, $knopki );
	}

	public static function Spisok_vseh( $uid, $chat, $page = 1, $mid = null ) {
		$tz    = Ntodo_DB::Polzovatel_tz( $uid );
		$vse   = array_values(
			array_filter(
				Ntodo_DB::Poluchit_zadachi( $uid ),
				function ( $t ) {
					return ! (int) $t->done;
				}
			)
		);
		$vsego = max( 1, (int) ceil( count( $vse ) / 10 ) );
		$page  = max( 1, min( $vsego, (int) $page ) );

		if ( ! $vse ) {
			$text = "📋 <b>Все задачи</b>\n\nНевыполненных задач нет 🎉";
			return $mid ? self::Pravka( $chat, $mid, $text ) : self::Napisat( $chat, $text );
		}

		$chast  = array_slice( $vse, ( $page - 1 ) * 10, 10 );
		$lines  = array();
		$knopki = array();
		foreach ( $chast as $i => $t ) {
			$meta     = self::Meta_zadachi( $t, $tz );
			$lines[]  = ( ( $page - 1 ) * 10 + $i + 1 ) . '. ' . self::e( $t->title ) . ( $meta ? "\n    <i>" . self::e( $meta ) . '</i>' : '' );
			$knopki[] = array(
				array(
					'text'          => '✅ ' . self::Korotko( $t->title ),
					'callback_data' => 'ld:' . $t->id . ':l:' . $page,
				),
			);
		}

		$nav = array();
		if ( $page > 1 ) {
			$nav[] = array(
				'text'          => '◀ Назад',
				'callback_data' => 'lp:' . ( $page - 1 ),
			);
		}
		if ( $page < $vsego ) {
			$nav[] = array(
				'text'          => 'Вперёд ▶',
				'callback_data' => 'lp:' . ( $page + 1 ),
			);
		}
		if ( $nav ) {
			$knopki[] = $nav;
		}

		$text = '📋 <b>Невыполненные задачи</b>' . ( $vsego > 1 ? " · стр. $page из $vsego" : '' ) . "\n\n" . implode( "\n", $lines );
		return $mid ? self::Pravka( $chat, $mid, $text, $knopki ) : self::Napisat( $chat, $text, $knopki );
	}

	public static function Dobavit_iz_teksta( $uid, $chat, $text ) {
		$tz  = Ntodo_DB::Polzovatel_tz( $uid );
		$raz = self::Razobrat_datu( $text, $tz );

		$d = array( 'title' => $raz[0] );
		if ( $raz[1] ) {
			$d['due_at']        = gmdate( 'c', $raz[1] );
			$d['due_has_time']  = $raz[2];
			$d['remind_before'] = $raz[2] ? 0 : null;
		}

		$task = Ntodo_DB::Sozdat_zadachu( $uid, $d );
		if ( is_wp_error( $task ) ) {
			self::Napisat( $chat, '⚠️ ' . self::e( $task->get_error_message() ) );
			return;
		}

		$meta = self::Meta_zadachi( $task, $tz );
		$otv  = "➕ Задача добавлена\n\n<b>" . self::e( $task->title ) . '</b>' . ( $meta ? "\n" . $meta : '' );
		if ( $raz[2] ) {
			$otv .= "\n🔔 Напомню в это время";
		}
		self::Napisat(
			$chat,
			$otv,
			array(
				array(
					array(
						'text'          => '✅ Готово',
						'callback_data' => 'done:' . $task->id,
					),
				),
			)
		);
	}

	public static function Razobrat_datu( $text, $tz ) {
		$zone  = new DateTimeZone( $tz );
		$now   = new DateTimeImmutable( 'now', $zone );
		$s     = ' ' . $text . ' ';
		$sdvig = null;
		$den   = null;
		$data  = null;
		$chas  = null;
		$min   = 0;

		$s = preg_replace_callback(
			'~(?<!\S)через\s+(\d+\s+)?(минут[уы]?|мин|час(?:а|ов)?|дн(?:я|ей)|день|недел[юиь])(?!\S)~iu',
			function ( $mm ) use ( &$sdvig, &$den ) {
				$n = '' !== trim( $mm[1] ) ? (int) $mm[1] : 1;
				$u = mb_strtolower( $mm[2] );
				if ( str_starts_with( $u, 'мин' ) ) {
					$sdvig = $n * 60;
				} elseif ( str_starts_with( $u, 'час' ) ) {
					$sdvig = $n * 3600;
				} elseif ( str_starts_with( $u, 'недел' ) ) {
					$den = $n * 7;
				} else {
					$den = $n;
				}
				return ' ';
			},
			$s,
			1
		);

		$s = preg_replace_callback(
			'~(?<!\S)(послезавтра|завтра|сегодня)(?!\S)~iu',
			function ( $mm ) use ( &$den ) {
				$den = array(
					'сегодня'     => 0,
					'завтра'      => 1,
					'послезавтра' => 2,
				)[ mb_strtolower( $mm[1] ) ];
				return ' ';
			},
			$s,
			1
		);

		$s = preg_replace_callback(
			'~(?<!\S)(?:во?\s+)?(понедельник|вторник|сред[уа]|четверг|пятниц[уа]|суббот[уа]|воскресенье)(?!\S)~iu',
			function ( $mm ) use ( &$den, $now ) {
				$karta = array(
					'пон' => 1,
					'вто' => 2,
					'сре' => 3,
					'чет' => 4,
					'пят' => 5,
					'суб' => 6,
					'вос' => 7,
				);
				$nuzhno = $karta[ mb_substr( mb_strtolower( $mm[1] ), 0, 3 ) ];
				$raz    = ( $nuzhno - (int) $now->format( 'N' ) + 7 ) % 7;
				$den    = 0 === $raz ? 7 : $raz;
				return ' ';
			},
			$s,
			1
		);

		$s = preg_replace_callback(
			'~(?<!\S)в\s+(\d{1,2})(?:[:.](\d{2}))?(?:\s+(утра|дня|вечера|ночи))?(?!\S)~iu',
			function ( $mm ) use ( &$chas, &$min ) {
				$h = (int) $mm[1];
				$m = isset( $mm[2] ) && '' !== $mm[2] ? (int) $mm[2] : 0;
				$c = mb_strtolower( $mm[3] ?? '' );
				if ( ( 'вечера' === $c || 'дня' === $c ) && $h < 12 ) {
					$h += 12;
				}
				if ( 'ночи' === $c && 12 === $h ) {
					$h = 0;
				}
				if ( $h > 23 || $m > 59 ) {
					return $mm[0];
				}
				$chas = $h;
				$min  = $m;
				return ' ';
			},
			$s,
			1
		);

		if ( null === $chas ) {
			$s = preg_replace_callback(
				'~(?<!\S)(\d{1,2}):(\d{2})(?!\S)~u',
				function ( $mm ) use ( &$chas, &$min ) {
					if ( (int) $mm[1] > 23 || (int) $mm[2] > 59 ) {
						return $mm[0];
					}
					$chas = (int) $mm[1];
					$min  = (int) $mm[2];
					return ' ';
				},
				$s,
				1
			);
		}

		$s = preg_replace_callback(
			'~(?<!\S)(\d{1,2})\.(\d{1,2})(?:\.(\d{2,4}))?(?!\S)~u',
			function ( $mm ) use ( &$data, $now, $zone ) {
				$d = (int) $mm[1];
				$m = (int) $mm[2];
				$y = isset( $mm[3] ) && '' !== $mm[3] ? (int) $mm[3] : (int) $now->format( 'Y' );
				if ( $y < 100 ) {
					$y += 2000;
				}
				if ( ! checkdate( $m, $d, $y ) ) {
					return $mm[0];
				}
				$data = ( new DateTimeImmutable( 'now', $zone ) )->setDate( $y, $m, $d )->setTime( 0, 0 );
				if ( empty( $mm[3] ) && $data < $now->setTime( 0, 0 ) ) {
					$data = $data->modify( '+1 year' );
				}
				return ' ';
			},
			$s,
			1
		);

		$est_vremya = false;
		if ( null !== $sdvig ) {
			$dt         = $now->modify( '+' . $sdvig . ' seconds' );
			$est_vremya = true;
		} elseif ( null !== $data || null !== $den || null !== $chas ) {
			$base = $data ?? $now->setTime( 0, 0 )->modify( '+' . (int) $den . ' days' );
			if ( null !== $chas ) {
				$dt         = $base->setTime( $chas, $min );
				$est_vremya = true;
				if ( null === $den && null === $data && $dt <= $now ) {
					$dt = $dt->modify( '+1 day' );
				}
			} else {
				$dt = $base->setTime( 0, 0 );
			}
		} else {
			return array( Ntodo_DB::Chistyi_tekst( $text ), null, false );
		}

		$title = Ntodo_DB::Chistyi_tekst( preg_replace( '~\s+(в|на|к)\s*$~u', '', $s ) );
		if ( '' === $title ) {
			$title = Ntodo_DB::Chistyi_tekst( $text );
		}
		return array( $title, $dt->getTimestamp(), $est_vremya );
	}

	public static function Obrabotka_knopki( $cq ) {
		$chat = (int) ( $cq['message']['chat']['id'] ?? 0 );
		$mid  = (int) ( $cq['message']['message_id'] ?? 0 );
		$data = (string) ( $cq['data'] ?? '' );
		$cqid = (string) ( $cq['id'] ?? '' );

		$uid = Ntodo_DB::Polzovatel_po_chatu( $chat );
		if ( ! $uid ) {
			self::Otvet_knopka( $cqid, 'Сначала подключите бота на сайте' );
			return;
		}

		$p    = explode( ':', $data );
		$tz   = Ntodo_DB::Polzovatel_tz( $uid );
		$zone = new DateTimeZone( $tz );
		$task = isset( $p[1] ) && in_array( $p[0], array( 'done', 'snz', 'tmr', 'ld' ), true ) ? Ntodo_DB::Naiti_zadachu( $uid, (int) $p[1] ) : null;

		if ( in_array( $p[0], array( 'done', 'snz', 'tmr', 'ld' ), true ) && ! $task ) {
			self::Otvet_knopka( $cqid, 'Задача не найдена — возможно, её удалили' );
			if ( 'ld' !== $p[0] ) {
				self::Pravka( $chat, $mid, '🗑 Задача удалена' );
			}
			return;
		}

		switch ( $p[0] ) {
			case 'done':
				Ntodo_DB::Obnovit_zadachu( $uid, (int) $task->id, array( 'done' => true ) );
				self::Pravka( $chat, $mid, "✅ <b>Выполнено</b>\n\n<s>" . self::e( $task->title ) . '</s>' );
				self::Otvet_knopka( $cqid, 'Отмечено как выполненное' );
				return;

			case 'snz':
				$m  = in_array( (int) ( $p[2] ?? 0 ), array( 15, 60 ), true ) ? (int) $p[2] : 15;
				$ts = time() + $m * 60;
				Ntodo_DB::Perenesti_napominanie( $uid, (int) $task->id, $ts );
				$v = ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $zone )->format( 'H:i' );
				self::Pravka( $chat, $mid, '⏱ Напомню в ' . $v . "\n\n" . self::e( $task->title ) );
				self::Otvet_knopka( $cqid, 'Напомню в ' . $v );
				return;

			case 'tmr':
				$base = $task->remind_at ? new DateTimeImmutable( $task->remind_at, new DateTimeZone( 'UTC' ) ) : new DateTimeImmutable( 'now' );
				$loc  = $base->setTimezone( $zone );
				$zavt = ( new DateTimeImmutable( 'tomorrow', $zone ) )->setTime( (int) $loc->format( 'G' ), (int) $loc->format( 'i' ) );
				Ntodo_DB::Perenesti_napominanie( $uid, (int) $task->id, $zavt->getTimestamp() );
				self::Pravka( $chat, $mid, '📅 Напомню завтра в ' . $zavt->format( 'H:i' ) . "\n\n" . self::e( $task->title ) );
				self::Otvet_knopka( $cqid, 'Перенесено на завтра' );
				return;

			case 'ld':
				Ntodo_DB::Obnovit_zadachu( $uid, (int) $task->id, array( 'done' => true ) );
				if ( 't' === ( $p[2] ?? '' ) ) {
					self::Spisok_segodnya( $uid, $chat, $mid );
				} else {
					self::Spisok_vseh( $uid, $chat, (int) ( $p[3] ?? 1 ), $mid );
				}
				self::Otvet_knopka( $cqid, 'Готово ✓' );
				return;

			case 'lp':
				self::Spisok_vseh( $uid, $chat, (int) ( $p[1] ?? 1 ), $mid );
				self::Otvet_knopka( $cqid );
				return;
		}
		self::Otvet_knopka( $cqid );
	}
}
