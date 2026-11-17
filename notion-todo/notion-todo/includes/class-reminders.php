<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ntodo_Reminders {

	const POVTOR_CHEREZ = array( 1, 5, 15 );

	public static function Zapusk_rassylki() {
		if ( get_transient( 'ntodo_cron_lock' ) ) {
			return array( 'skipped' => true );
		}
		set_transient( 'ntodo_cron_lock', 1, 55 );

		$res = array(
			'sent'   => 0,
			'failed' => 0,
		);
		try {
			if ( '' !== Ntodo_Telegram::Token() ) {
				$res = self::Otpravit_napominaniya();
				self::Utrennyaya_svodka();
			}
			self::Uborka();
		} finally {
			delete_transient( 'ntodo_cron_lock' );
		}
		return $res;
	}

	public static function Otpravit_napominaniya() {
		global $wpdb;
		$t   = Ntodo_DB::T();
		$g   = Ntodo_DB::TG();
		$now = Ntodo_DB::Seichas();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.*, g.chat_id, g.timezone FROM $t t
				INNER JOIN $g g ON g.user_id = t.user_id
				WHERE t.remind_at IS NOT NULL AND t.remind_at <= %s AND t.reminded_at IS NULL
				AND t.done = 0 AND t.deleted_at IS NULL
				AND (t.retry_at IS NULL OR t.retry_at <= %s)
				AND g.chat_id IS NOT NULL
				ORDER BY t.remind_at ASC LIMIT 300",
				$now,
				$now
			)
		);

		$sent   = 0;
		$failed = 0;
		$pachka = 0;

		foreach ( $rows as $row ) {
			$zahvat = $wpdb->query( $wpdb->prepare( "UPDATE $t SET reminded_at = %s WHERE id = %d AND reminded_at IS NULL", $now, $row->id ) );
			if ( 1 !== (int) $zahvat ) {
				continue;
			}

			$otvet = Ntodo_Telegram::Otpravit_napominanie( $row );

			if ( 429 === (int) ( $otvet['error_code'] ?? 0 ) ) {
				$zhdat = (int) ( $otvet['parameters']['retry_after'] ?? 5 );
				if ( $zhdat <= 10 ) {
					sleep( $zhdat );
					$otvet = Ntodo_Telegram::Otpravit_napominanie( $row );
				}
				if ( 429 === (int) ( $otvet['error_code'] ?? 0 ) ) {
					$wpdb->update(
						$t,
						array(
							'reminded_at' => null,
							'retry_at'    => gmdate( 'Y-m-d H:i:s', time() + max( 1, $zhdat ) ),
						),
						array( 'id' => $row->id )
					);
					break;
				}
			}

			if ( ! empty( $otvet['ok'] ) ) {
				++$sent;
			} elseif ( 403 === (int) ( $otvet['error_code'] ?? 0 ) ) {
				Ntodo_DB::Otvyazat_tg( (int) $row->user_id, true );
				++$failed;
			} else {
				self::Oshibka_otpravki( $row );
				++$failed;
			}

			if ( ++$pachka >= 25 ) {
				$pachka = 0;
				sleep( 1 );
			}
		}

		return array(
			'sent'   => $sent,
			'failed' => $failed,
		);
	}

	private static function Oshibka_otpravki( $row ) {
		global $wpdb;
		$popytka = (int) $row->remind_tries + 1;
		if ( $popytka > count( self::POVTOR_CHEREZ ) ) {
			return;
		}
		$wpdb->update(
			Ntodo_DB::T(),
			array(
				'reminded_at'  => null,
				'remind_tries' => $popytka,
				'retry_at'     => gmdate( 'Y-m-d H:i:s', time() + self::POVTOR_CHEREZ[ $popytka - 1 ] * MINUTE_IN_SECONDS ),
			),
			array( 'id' => $row->id )
		);
	}

	public static function Utrennyaya_svodka() {
		global $wpdb;
		$g    = Ntodo_DB::TG();
		$rows = $wpdb->get_results( "SELECT * FROM $g WHERE digest = 1 AND chat_id IS NOT NULL" );

		foreach ( $rows as $row ) {
			$loc = new DateTimeImmutable( 'now', new DateTimeZone( Ntodo_DB::Normalnyi_tz( $row->timezone ) ) );
			if ( 9 !== (int) $loc->format( 'G' ) ) {
				continue;
			}
			$den = $loc->format( 'Y-m-d' );
			if ( $row->digest_date === $den ) {
				continue;
			}
			Ntodo_DB::Zapisat_tg( (int) $row->user_id, array( 'digest_date' => $den ) );
			Ntodo_Telegram::Spisok_segodnya( (int) $row->user_id, $row->chat_id, null, "☀️ <b>Доброе утро!</b>\nВот что на сегодня:" );
		}
	}

	public static function Uborka() {
		if ( get_transient( 'ntodo_uborka' ) ) {
			return;
		}
		set_transient( 'ntodo_uborka', 1, HOUR_IN_SECONDS );

		global $wpdb;
		$t = Ntodo_DB::T();
		$g = Ntodo_DB::TG();
		$wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE deleted_at IS NOT NULL AND deleted_at < %s", gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
		$wpdb->query( $wpdb->prepare( "UPDATE $g SET link_code = NULL, code_expires = NULL WHERE code_expires IS NOT NULL AND code_expires < %s", Ntodo_DB::Seichas() ) );
	}
}
