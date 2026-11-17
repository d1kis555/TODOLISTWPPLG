<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div id="ntodo" class="nt-app">
	<div class="nt-scrim nt-scrim--side" id="nt-side-scrim"></div>
	<aside class="nt-side" id="nt-side" aria-label="<?php esc_attr_e( 'Боковая панель', 'notion-todo' ); ?>">
		<div class="nt-side__head">
			<div class="nt-user" id="nt-user"></div>
			<button type="button" class="nt-ibtn nt-side__collapse" id="nt-side-toggle" aria-label="<?php esc_attr_e( 'Свернуть боковую панель', 'notion-todo' ); ?>"></button>
		</div>
		<button type="button" class="nt-side__item" id="nt-search-btn"></button>
		<nav class="nt-filters" id="nt-filters" aria-label="<?php esc_attr_e( 'Фильтры', 'notion-todo' ); ?>"></nav>
		<div class="nt-side__foot">
			<div class="nt-tg" id="nt-tg"></div>
			<button type="button" class="nt-side__item" id="nt-theme-btn"></button>
			<button type="button" class="nt-side__item" id="nt-help-btn"></button>
		</div>
	</aside>

	<main class="nt-main" id="nt-main">
		<div class="nt-topbar">
			<button type="button" class="nt-ibtn nt-burger" id="nt-burger" aria-label="<?php esc_attr_e( 'Открыть боковую панель', 'notion-todo' ); ?>"></button>
			<div class="nt-crumb"><span aria-hidden="true">✅</span> <?php esc_html_e( 'Мои задачи', 'notion-todo' ); ?></div>
			<div class="nt-status" id="nt-status" role="status"></div>
		</div>
		<div class="nt-cover" aria-hidden="true"></div>
		<div class="nt-page" id="nt-page">
			<div class="nt-page__icon" aria-hidden="true">✅</div>
			<h1 class="nt-title"><?php esc_html_e( 'Мои задачи', 'notion-todo' ); ?></h1>
			<div class="nt-progress" id="nt-progress">
				<span class="nt-progress__text" id="nt-progress-text">&nbsp;</span>
				<span class="nt-progress__bar"><i id="nt-progress-bar"></i></span>
			</div>
			<div class="nt-search" id="nt-search" hidden>
				<input type="search" id="nt-search-inp" class="nt-search__inp" placeholder="<?php esc_attr_e( 'Поиск по задачам…', 'notion-todo' ); ?>" aria-label="<?php esc_attr_e( 'Поиск по задачам', 'notion-todo' ); ?>" autocomplete="off">
			</div>
			<div class="nt-bulk" id="nt-bulk" hidden></div>
			<div class="nt-list" id="nt-list" role="list" aria-label="<?php esc_attr_e( 'Задачи', 'notion-todo' ); ?>">
				<?php for ( $i = 0; $i < 5; $i++ ) : ?>
					<div class="nt-skel" aria-hidden="true"><b></b><i style="width:<?php echo (int) ( 40 + ( $i * 37 ) % 45 ); ?>%"></i></div>
				<?php endfor; ?>
			</div>
			<div class="nt-new" id="nt-new"></div>
			<div class="nt-empty" id="nt-empty" hidden></div>
			<section class="nt-done" id="nt-done" hidden>
				<div class="nt-done__bar">
					<button type="button" class="nt-done__head" id="nt-done-head" aria-expanded="true"></button>
					<button type="button" class="nt-link" id="nt-clear"><?php esc_html_e( 'Очистить выполненные', 'notion-todo' ); ?></button>
				</div>
				<div class="nt-list" id="nt-done-list" role="list" aria-label="<?php esc_attr_e( 'Выполненные задачи', 'notion-todo' ); ?>"></div>
			</section>
		</div>
	</main>

	<div class="nt-scrim nt-scrim--panel" id="nt-panel-scrim"></div>
	<aside class="nt-panel" id="nt-panel" aria-label="<?php esc_attr_e( 'Свойства задачи', 'notion-todo' ); ?>" inert></aside>
	<button type="button" class="nt-fab" id="nt-fab" aria-label="<?php esc_attr_e( 'Новая задача', 'notion-todo' ); ?>">+</button>
	<div class="nt-toasts" id="nt-toasts" aria-live="polite"></div>
	<noscript><p class="nt-noscript"><?php esc_html_e( 'Для работы списка задач включите JavaScript.', 'notion-todo' ); ?></p></noscript>
</div>
