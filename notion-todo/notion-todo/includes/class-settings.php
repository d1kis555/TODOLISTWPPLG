<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ntodo_Settings {

	public static function Init() {
		add_action( 'admin_menu', array( __CLASS__, 'Menu' ) );
		add_action( 'admin_post_ntodo_tg_save', array( __CLASS__, 'Sohranit_tg' ) );
		add_action( 'admin_post_ntodo_tg_off', array( __CLASS__, 'otklyuchit_webhook' ) );
		add_action( 'admin_post_ntodo_new_key', array( __CLASS__, 'Novyi_klyuch' ) );
	}

	public static function Menu() {
		add_menu_page( 'Notion To-Do', 'Notion To-Do', 'manage_options', 'ntodo', array( __CLASS__, 'Stranica_obshee' ), 'dashicons-yes-alt', 58 );
		add_submenu_page( 'ntodo', __( 'Общее', 'notion-todo' ), __( 'Общее', 'notion-todo' ), 'manage_options', 'ntodo', array( __CLASS__, 'Stranica_obshee' ) );
		add_submenu_page( 'ntodo', 'Telegram', 'Telegram', 'manage_options', 'ntodo-telegram', array( __CLASS__, 'Stranica_telegram' ) );
	}

	private static function Uvedomlenie() {
		$msg = get_transient( 'ntodo_admin_msg_' . get_current_user_id() );
		if ( ! $msg ) {
			return;
		}
		delete_transient( 'ntodo_admin_msg_' . get_current_user_id() );
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $msg[0] ), esc_html( $msg[1] ) );
	}

	private static function Soobshit( $tip, $text, $stranica ) {
		set_transient( 'ntodo_admin_msg_' . get_current_user_id(), array( $tip, $text ), 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . $stranica ) );
		exit;
	}

	public static function Stranica_obshee() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$key = (string) get_option( 'ntodo_cron_key', '' );
		$url = add_query_arg( 'key', $key, rest_url( Ntodo_Rest::NS . '/cron/reminders' ) );
		?>
		<div class="wrap">
			<h1>Notion To-Do</h1>
			<?php self::Uvedomlenie(); ?>
			<p><?php esc_html_e( 'Создайте страницу с шорткодом [notion_todo] и сделайте её главной (Настройки → Чтение).', 'notion-todo' ); ?></p>

			<h2><?php esc_html_e( 'Планировщик напоминаний', 'notion-todo' ); ?></h2>
			<p><?php esc_html_e( 'WP-Cron без трафика ненадёжен. Добавьте в wp-config.php define( \'DISABLE_WP_CRON\', true ); и один из вариантов в системный crontab:', 'notion-todo' ); ?></p>
			<p><strong>WP-CLI</strong></p>
			<pre style="background:#fff;padding:12px;border:1px solid #ddd;overflow:auto">* * * * * cd <?php echo esc_html( untrailingslashit( ABSPATH ) ); ?> &amp;&amp; wp cron event run --due-now --quiet</pre>
			<p><strong><?php esc_html_e( 'Без WP-CLI', 'notion-todo' ); ?></strong></p>
			<pre style="background:#fff;padding:12px;border:1px solid #ddd;overflow:auto">* * * * * curl -fsS -X POST '<?php echo esc_html( $url ); ?>' &gt;/dev/null</pre>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ntodo_new_key' ); ?>
				<input type="hidden" name="action" value="ntodo_new_key">
				<?php submit_button( __( 'Сгенерировать новый ключ cron', 'notion-todo' ), 'secondary' ); ?>
			</form>
		</div>
		<?php
	}

	public static function Stranica_telegram() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$token      = Ntodo_Telegram::Token();
		$iz_konfiga = defined( 'NTODO_TG_TOKEN' ) && NTODO_TG_TOKEN;
		$maska      = $token ? str_repeat( '•', 10 ) . substr( $token, -4 ) : '';
		$bot        = (string) get_option( 'ntodo_tg_bot', '' );
		$last       = (int) get_option( 'ntodo_tg_last_update', 0 );
		$info       = $token ? Ntodo_Telegram::Zapros( 'getWebhookInfo' ) : null;
		$wh         = $info['result'] ?? array();
		?>
		<div class="wrap">
			<h1>Notion To-Do → Telegram</h1>
			<?php self::Uvedomlenie(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ntodo_tg_save' ); ?>
				<input type="hidden" name="action" value="ntodo_tg_save">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ntodo-token"><?php esc_html_e( 'Токен бота', 'notion-todo' ); ?></label></th>
						<td>
							<?php if ( $iz_konfiga ) : ?>
								<code><?php echo esc_html( $maska ); ?></code>
								<p class="description"><?php esc_html_e( 'Задан константой NTODO_TG_TOKEN в wp-config.php.', 'notion-todo' ); ?></p>
							<?php else : ?>
								<input type="password" id="ntodo-token" name="token" class="regular-text" autocomplete="off" placeholder="<?php echo esc_attr( $maska ? $maska : '123456:ABC-DEF…' ); ?>">
								<p class="description"><?php esc_html_e( 'Получите у @BotFather. Оставьте поле пустым, чтобы не менять сохранённый токен.', 'notion-todo' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Проверить и подключить', 'notion-todo' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Статус', 'notion-todo' ); ?></h2>
			<table class="widefat striped" style="max-width:720px">
				<tbody>
					<tr><td><?php esc_html_e( 'Бот', 'notion-todo' ); ?></td><td><?php echo $bot ? '<a href="' . esc_url( 'https://t.me/' . $bot ) . '" target="_blank" rel="noopener">@' . esc_html( $bot ) . '</a>' : '—'; ?></td></tr>
					<tr><td>Webhook</td><td><?php echo esc_html( $wh['url'] ?? '—' ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Последний апдейт', 'notion-todo' ); ?></td><td><?php echo $last ? esc_html( wp_date( 'd.m.Y H:i:s', $last ) ) : '—'; ?></td></tr>
					<tr><td><?php esc_html_e( 'В очереди у Telegram', 'notion-todo' ); ?></td><td><?php echo esc_html( (string) ( $wh['pending_update_count'] ?? '—' ) ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Последняя ошибка', 'notion-todo' ); ?></td><td>
						<?php
						if ( ! empty( $wh['last_error_message'] ) ) {
							echo esc_html( wp_date( 'd.m.Y H:i', (int) $wh['last_error_date'] ) . ' — ' . $wh['last_error_message'] );
						} else {
							echo '—';
						}
						?>
					</td></tr>
				</tbody>
			</table>

			<?php if ( $token ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px">
					<?php wp_nonce_field( 'ntodo_tg_off' ); ?>
					<input type="hidden" name="action" value="ntodo_tg_off">
					<?php submit_button( __( 'Отключить webhook', 'notion-todo' ), 'delete', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function Sohranit_tg() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'notion-todo' ) );
		}
		check_admin_referer( 'ntodo_tg_save' );

		$token = isset( $_POST['token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['token'] ) ) ) : '';
		if ( '' !== $token ) {
			if ( ! preg_match( '/^\d{5,}:[A-Za-z0-9_-]{30,}$/', $token ) ) {
				self::Soobshit( 'error', __( 'Токен выглядит неверно. Скопируйте его из @BotFather целиком.', 'notion-todo' ), 'ntodo-telegram' );
			}
			update_option( 'ntodo_tg_token', $token, false );
		}

		$res = Ntodo_Telegram::Podklyuchit();
		if ( is_wp_error( $res ) ) {
			self::Soobshit( 'error', $res->get_error_message(), 'ntodo-telegram' );
		}
		self::Soobshit( 'success', sprintf( __( 'Бот @%s подключён, webhook установлен.', 'notion-todo' ), $res['username'] ), 'ntodo-telegram' );
	}

	public static function otklyuchit_webhook() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'notion-todo' ) );
		}
		check_admin_referer( 'ntodo_tg_off' );
		Ntodo_Telegram::Zapros( 'deleteWebhook' );
		delete_option( 'ntodo_tg_secret' );
		self::Soobshit( 'success', __( 'Webhook отключён.', 'notion-todo' ), 'ntodo-telegram' );
	}

	public static function Novyi_klyuch() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'notion-todo' ) );
		}
		check_admin_referer( 'ntodo_new_key' );
		update_option( 'ntodo_cron_key', wp_generate_password( 32, false, false ), false );
		self::Soobshit( 'success', __( 'Новый ключ создан. Обновите строку в crontab.', 'notion-todo' ), 'ntodo' );
	}
}
