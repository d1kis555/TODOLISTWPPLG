<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ntodo_DB {

	const POVTORY = array( 'daily', 'weekly', 'monthly' );

	public static function T() {
		global $wpdb;
		return $wpdb->prefix . 'ntodo_tasks';
	}

	public static function TG() {
		global $wpdb;
		return $wpdb->prefix . 'ntodo_telegram';
	}

	public static function Sozdat_tablicy() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$c = $wpdb->get_charset_collate();
		$t = self::T();
		$g = self::TG();

		$sql = "CREATE TABLE $t (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  client_uid varchar(40) NULL,
  title varchar(500) NOT NULL,
  note text NULL,
  done tinyint(1) NOT NULL DEFAULT 0,
  priority tinyint(4) NOT NULL DEFAULT 0,
  position int(11) NOT NULL DEFAULT 0,
  due_at datetime NULL,
  due_has_time tinyint(1) NOT NULL DEFAULT 0,
  remind_before int(11) NULL,
  remind_at datetime NULL,
  reminded_at datetime NULL,
  remind_tries tinyint(4) NOT NULL DEFAULT 0,
  retry_at datetime NULL,
  repeat_rule varchar(20) NULL,
  completed_at datetime NULL,
  deleted_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY client (user_id,client_uid),
  KEY user_list (user_id,deleted_at,done,position),
  KEY reminders (remind_at,reminded_at,done)
) $c;
CREATE TABLE $g (
  user_id bigint(20) unsigned NOT NULL,
  chat_id bigint(20) NULL,
  username varchar(64) NULL,
  link_code char(32) NULL,
  code_expires datetime NULL,
  timezone varchar(64) NOT NULL DEFAULT 'UTC',
  digest tinyint(1) NOT NULL DEFAULT 0,
  digest_date date NULL,
  blocked tinyint(1) NOT NULL DEFAULT 0,
  linked_at datetime NULL,
  PRIMARY KEY  (user_id),
  UNIQUE KEY chat (chat_id),
  KEY code (link_code)
) $c;";

		dbDelta( $sql );
		update_option( 'ntodo_db_version', NTODO_DB_VERSION, false );
	}

	public static function Proverka_versii() {
		if ( get_option( 'ntodo_db_version' ) !== NTODO_DB_VERSION ) {
			self::Sozdat_tablicy();
		}
	}

	public static function Err( $code, $msg, $status = 400 ) {
		return new WP_Error( $code, $msg, array( 'status' => $status ) );
	}

	public static function Seichas() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	public static function v_iso( $dt ) {
		return $dt ? str_replace( ' ', 'T', $dt ) . 'Z' : null;
	}

	public static function iz_iso( $s ) {
		if ( null === $s || '' === $s || false === $s ) {
			return null;
		}
		try {
			$d = new DateTimeImmutable( (string) $s, new DateTimeZone( 'UTC' ) );
		} catch ( Exception $e ) {
			return false;
		}
		return $d->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	public static function Chistyi_tekst( $s, $max = 500 ) {
		$s = wp_check_invalid_utf8( (string) $s );
		$s = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $s );
		$s = trim( preg_replace( '/\s+/u', ' ', $s ) );
		return mb_substr( $s, 0, $max );
	}

	public static function chistaya_zametka( $s ) {
		$s = wp_check_invalid_utf8( (string) $s );
		$s = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $s );
		return mb_substr( $s, 0, 10000 );
	}

	public static function Formatirovat( $r ) {
		return array(
			'id'            => (int) $r->id,
			'client_uid'    => $r->client_uid,
			'title'         => $r->title,
			'note'          => (string) $r->note,
			'done'          => (bool) $r->done,
			'priority'      => (int) $r->priority,
			'position'      => (int) $r->position,
			'due_at'        => self::v_iso( $r->due_at ),
			'due_has_time'  => (bool) $r->due_has_time,
			'remind_before' => null === $r->remind_before ? null : (int) $r->remind_before,
			'remind_at'     => self::v_iso( $r->remind_at ),
			'repeat_rule'   => $r->repeat_rule,
			'completed_at'  => self::v_iso( $r->completed_at ),
			'created_at'    => self::v_iso( $r->created_at ),
			'updated_at'    => self::v_iso( $r->updated_at ),
		);
	}

	public static function Polzovatel_tz( $uid ) {
		global $wpdb;
		$tz = $wpdb->get_var( $wpdb->prepare( 'SELECT timezone FROM ' . self::TG() . ' WHERE user_id = %d', $uid ) );
		return self::Normalnyi_tz( $tz );
	}

	public static function Normalnyi_tz( $tz ) {
		if ( ! $tz ) {
			return 'UTC';
		}
		try {
			new DateTimeZone( $tz );
			return $tz;
		} catch ( Exception $e ) {
			return 'UTC';
		}
	}

	public static function Granicy_dnya( $tz ) {
		$start = new DateTimeImmutable( 'today', new DateTimeZone( $tz ) );
		$end   = $start->modify( '+1 day' );
		return array(
			gmdate( 'Y-m-d H:i:s', $start->getTimestamp() ),
			gmdate( 'Y-m-d H:i:s', $end->getTimestamp() ),
		);
	}

	public static function Poluchit_zadachi( $uid, $filter = 'all', $q = '' ) {
		global $wpdb;
		$t     = self::T();
		$where = array( 'user_id = %d', 'deleted_at IS NULL' );
		$args  = array( $uid );

		if ( 'done' === $filter ) {
			$where[] = 'done = 1';
		} elseif ( 'today' === $filter || 'upcoming' === $filter ) {
			$gr      = self::Granicy_dnya( self::Polzovatel_tz( $uid ) );
			$where[] = 'done = 0';
			if ( 'today' === $filter ) {
				$where[] = 'due_at IS NOT NULL AND due_at < %s';
			} else {
				$where[] = 'due_at >= %s';
			}
			$args[] = $gr[1];
		}

		if ( '' !== $q ) {
			$like    = '%' . $wpdb->esc_like( $q ) . '%';
			$where[] = '(title LIKE %s OR note LIKE %s)';
			$args[]  = $like;
			$args[]  = $like;
		}

		$sql = "SELECT * FROM $t WHERE " . implode( ' AND ', $where ) . ' ORDER BY done ASC, position ASC, id ASC LIMIT 2000';
		return $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
	}

	public static function Naiti_zadachu( $uid, $id, $i_udalennye = false ) {
		global $wpdb;
		$sql = 'SELECT * FROM ' . self::T() . ' WHERE id = %d AND user_id = %d';
		if ( ! $i_udalennye ) {
			$sql .= ' AND deleted_at IS NULL';
		}
		return $wpdb->get_row( $wpdb->prepare( $sql, $id, $uid ) );
	}

	public static function Vremya_napominaniya( $due, $s_vremenem, $za ) {
		if ( null === $due || null === $za || '' === $za ) {
			return null;
		}
		$base = strtotime( $due . ' UTC' ) + ( $s_vremenem ? 0 : 9 * HOUR_IN_SECONDS );
		if ( $base < time() ) {
			return null;
		}
		$at = $base - (int) $za * MINUTE_IN_SECONDS;
		return gmdate( 'Y-m-d H:i:s', max( $at, time() ) );
	}

	public static function Razobrat_polya( $d, $task = null ) {
		$set = array();

		if ( array_key_exists( 'title', $d ) ) {
			$title = self::Chistyi_tekst( $d['title'] );
			if ( '' === $title ) {
				return self::Err( 'ntodo_empty', __( 'Задача не может быть пустой.', 'notion-todo' ) );
			}
			$set['title'] = $title;
		}
		if ( array_key_exists( 'note', $d ) ) {
			$set['note'] = self::chistaya_zametka( $d['note'] );
		}
		if ( array_key_exists( 'priority', $d ) ) {
			$set['priority'] = max( 0, min( 3, (int) $d['priority'] ) );
		}
		if ( array_key_exists( 'position', $d ) && $task ) {
			$set['position'] = (int) $d['position'];
		}
		if ( array_key_exists( 'done', $d ) ) {
			$done = (int) (bool) $d['done'];
			if ( ! $task || (int) $task->done !== $done ) {
				$set['done']         = $done;
				$set['completed_at'] = $done ? self::Seichas() : null;
			}
		}
		if ( array_key_exists( 'repeat_rule', $d ) ) {
			$set['repeat_rule'] = in_array( $d['repeat_rule'], self::POVTORY, true ) ? $d['repeat_rule'] : null;
		}

		$pereschet = false;
		if ( array_key_exists( 'due_at', $d ) ) {
			$due = self::iz_iso( $d['due_at'] );
			if ( false === $due ) {
				return self::Err( 'ntodo_date', __( 'Неверный формат даты.', 'notion-todo' ) );
			}
			$set['due_at'] = $due;
			$pereschet     = true;
		}
		if ( array_key_exists( 'due_has_time', $d ) ) {
			$set['due_has_time'] = (int) (bool) $d['due_has_time'];
			$pereschet           = true;
		}
		if ( array_key_exists( 'remind_before', $d ) ) {
			$rb                   = $d['remind_before'];
			$set['remind_before'] = ( null === $rb || '' === $rb ) ? null : max( 0, min( 10080, (int) $rb ) );
			$pereschet            = true;
		}

		if ( $pereschet ) {
			$due = array_key_exists( 'due_at', $set ) ? $set['due_at'] : ( $task ? $task->due_at : null );
			$ht  = array_key_exists( 'due_has_time', $set ) ? $set['due_has_time'] : ( $task ? (int) $task->due_has_time : 0 );
			$rb  = array_key_exists( 'remind_before', $set ) ? $set['remind_before'] : ( $task ? $task->remind_before : null );

			$set['remind_at']    = self::Vremya_napominaniya( $due, $ht, $rb );
			$set['reminded_at']  = null;
			$set['remind_tries'] = 0;
			$set['retry_at']     = null;
		}

		return $set;
	}

	public static function Sozdat_zadachu( $uid, $d ) {
		global $wpdb;
		$t = self::T();

		$title = self::Chistyi_tekst( $d['title'] ?? '' );
		if ( '' === $title ) {
			return self::Err( 'ntodo_empty', __( 'Задача не может быть пустой.', 'notion-todo' ) );
		}

		$cu = isset( $d['client_uid'] ) ? substr( preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $d['client_uid'] ), 0, 40 ) : '';
		if ( '' !== $cu ) {
			$est = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE user_id = %d AND client_uid = %s", $uid, $cu ) );
			if ( $est ) {
				return $est;
			}
		}

		$polya = self::Razobrat_polya( $d, null );
		if ( is_wp_error( $polya ) ) {
			return $polya;
		}

		$pos = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(position),0) FROM $t WHERE user_id = %d", $uid ) ) + 1;
		$now = self::Seichas();
		$row = array_merge(
			array(
				'user_id'    => $uid,
				'client_uid' => '' !== $cu ? $cu : null,
				'note'       => '',
				'done'       => 0,
				'priority'   => 0,
				'created_at' => $now,
				'updated_at' => $now,
			),
			$polya,
			array(
				'title'    => $title,
				'position' => $pos,
			)
		);

		if ( ! $wpdb->insert( $t, $row ) ) {
			return self::Err( 'ntodo_db', __( 'Не удалось сохранить задачу. Попробуйте ещё раз.', 'notion-todo' ), 500 );
		}
		return self::Naiti_zadachu( $uid, (int) $wpdb->insert_id );
	}

	public static function Obnovit_zadachu( $uid, $id, $d ) {
		global $wpdb;
		$task = self::Naiti_zadachu( $uid, $id );
		if ( ! $task ) {
			return null;
		}

		$set = self::Razobrat_polya( $d, $task );
		if ( is_wp_error( $set ) ) {
			return $set;
		}

		$novaya  = null;
		$pravilo = array_key_exists( 'repeat_rule', $set ) ? $set['repeat_rule'] : $task->repeat_rule;
		if ( ! empty( $set['done'] ) && $pravilo ) {
			$novaya = self::Povtorit( $task, $pravilo );
		}

		if ( $set ) {
			$set['updated_at'] = self::Seichas();
			$wpdb->update(
				self::T(),
				$set,
				array(
					'id'      => $id,
					'user_id' => $uid,
				)
			);
		}

		return array(
			'task'    => self::Naiti_zadachu( $uid, $id ),
			'spawned' => $novaya,
		);
	}

	public static function Povtorit( $task, $pravilo ) {
		global $wpdb;
		$tz   = new DateTimeZone( self::Polzovatel_tz( $task->user_id ) );
		$shag = array(
			'daily'   => '+1 day',
			'weekly'  => '+1 week',
			'monthly' => '+1 month',
		)[ $pravilo ];

		$d = $task->due_at
			? ( new DateTimeImmutable( $task->due_at, new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz )
			: new DateTimeImmutable( 'today', $tz );

		$i = 0;
		do {
			$d = $d->modify( $shag );
			++$i;
		} while ( $d->getTimestamp() < time() && $i < 500 );

		$due = gmdate( 'Y-m-d H:i:s', $d->getTimestamp() );
		$now = self::Seichas();

		$wpdb->insert(
			self::T(),
			array(
				'user_id'       => $task->user_id,
				'title'         => $task->title,
				'note'          => (string) $task->note,
				'priority'      => $task->priority,
				'position'      => $task->position,
				'due_at'        => $due,
				'due_has_time'  => $task->due_has_time,
				'remind_before' => $task->remind_before,
				'remind_at'     => self::Vremya_napominaniya( $due, (int) $task->due_has_time, $task->remind_before ),
				'repeat_rule'   => $pravilo,
				'created_at'    => $now,
				'updated_at'    => $now,
			)
		);
		return $wpdb->insert_id ? self::Naiti_zadachu( $task->user_id, (int) $wpdb->insert_id ) : null;
	}

	public static function Udalit_zadachu( $uid, $id ) {
		global $wpdb;
		return (bool) $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::T() . ' SET deleted_at = %s WHERE id = %d AND user_id = %d AND deleted_at IS NULL',
				self::Seichas(),
				$id,
				$uid
			)
		);
	}

	public static function Vosstanovit_zadachu( $uid, $id ) {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::T() . ' SET deleted_at = NULL, updated_at = %s WHERE id = %d AND user_id = %d',
				self::Seichas(),
				$id,
				$uid
			)
		);
		return self::Naiti_zadachu( $uid, $id );
	}

	public static function Poryadok( $uid, $ids ) {
		global $wpdb;
		$ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) ), 0, 2000 );
		if ( ! $ids ) {
			return 0;
		}

		$case = '';
		$args = array();
		foreach ( $ids as $i => $id ) {
			$case  .= ' WHEN %d THEN %d';
			$args[] = $id;
			$args[] = $i + 1;
		}
		$args[] = self::Seichas();
		$args[] = $uid;
		$args   = array_merge( $args, $ids );
		$in     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		return (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::T() . " SET position = CASE id $case ELSE position END, updated_at = %s WHERE user_id = %d AND id IN ($in)",
				$args
			)
		);
	}

	public static function Ochistit_vypolnennye( $uid ) {
		global $wpdb;
		$t   = self::T();
		$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $t WHERE user_id = %d AND done = 1 AND deleted_at IS NULL", $uid ) ) );
		if ( $ids ) {
			$wpdb->query( $wpdb->prepare( "UPDATE $t SET deleted_at = %s WHERE user_id = %d AND done = 1 AND deleted_at IS NULL", self::Seichas(), $uid ) );
		}
		return $ids;
	}

	public static function Perenesti_napominanie( $uid, $id, $ts ) {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::T() . ' SET remind_at = %s, reminded_at = NULL, remind_tries = 0, retry_at = NULL, updated_at = %s WHERE id = %d AND user_id = %d',
				gmdate( 'Y-m-d H:i:s', $ts ),
				self::Seichas(),
				$id,
				$uid
			)
		);
	}

	public static function Stroka_tg( $uid ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::TG() . ' WHERE user_id = %d', $uid ) );
	}

	public static function Zapisat_tg( $uid, $polya ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::TG() . ' (user_id) VALUES (%d)', $uid ) );
		if ( $polya ) {
			$wpdb->update( self::TG(), $polya, array( 'user_id' => $uid ) );
		}
	}

	public static function Status_tg( $uid ) {
		$r = self::Stroka_tg( $uid );
		return array(
			'linked'   => (bool) ( $r && $r->chat_id ),
			'username' => $r ? $r->username : null,
			'blocked'  => (bool) ( $r && $r->blocked ),
			'digest'   => (bool) ( $r && $r->digest ),
			'bot'      => (string) get_option( 'ntodo_tg_bot', '' ),
		);
	}

	public static function Otvyazat_tg( $uid, $zablokirovan = false ) {
		self::Zapisat_tg(
			$uid,
			array(
				'chat_id'   => null,
				'username'  => null,
				'linked_at' => null,
				'blocked'   => $zablokirovan ? 1 : 0,
			)
		);
	}

	public static function Polzovatel_po_chatu( $chat ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM ' . self::TG() . ' WHERE chat_id = %d', $chat ) );
	}
}
