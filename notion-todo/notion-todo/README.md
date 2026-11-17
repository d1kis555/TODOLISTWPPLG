# Notion To-Do — список задач для WordPress с напоминаниями в Telegram

Плагин WordPress. Сайт, REST API и Telegram-бот работают внутри него, сторонних сервисов кроме Telegram Bot API нет.

## Требования

* WordPress 6.6+, PHP 8.1+
* MySQL 8 / MariaDB 10.6
* HTTPS (без него Telegram не примет webhook)
* доступ к системному cron

## Установка

1. Скопируйте папку `notion-todo` в `wp-content/plugins/` и активируйте плагин. Таблицы `wp_ntodo_tasks` и `wp_ntodo_telegram` создадутся сами.
2. Создайте страницу с шорткодом `[notion_todo]` и сделайте её главной: «Настройки → Чтение → Статическая страница».
3. Включите регистрацию: «Настройки → Общие → Любой может зарегистрироваться».

Страница с шорткодом рендерится на собственном пустом шаблоне, поэтому от темы не зависит. Стили темы на ней отключаются.

## Сборка фронтенда

Готовая сборка уже лежит в `assets/dist/`. Пересобрать:

```bash
npm install
npm run build      # assets/src → assets/dist/app.js + app.css
```

## Telegram-бот

1. Создайте бота у [@BotFather](https://t.me/BotFather) командой `/newbot` и скопируйте токен.
2. Откройте «Админка → Notion To-Do → Telegram», вставьте токен и нажмите «Проверить и подключить». Плагин вызовет `getMe`, `setWebhook` со случайным `secret_token` и `setMyCommands`.
3. Токен можно не хранить в БД, а задать в `wp-config.php`:

```php
define( 'NTODO_TG_TOKEN', '123456:ABC...' );
```

Пользователь нажимает на сайте «Подключить Telegram», открывает ссылку `t.me/<бот>?start=<код>` (на десктопе можно отсканировать QR) и нажимает «Запустить». Код одноразовый и живёт 15 минут.

Команды бота: `/start`, `/today`, `/list`, `/add <текст>` (или просто текст), `/digest on|off`, `/stop`, `/help`. В тексте распознаются даты: «завтра в 10», «в пятницу», «через 2 часа», «25.12 в 18:30».

## Cron

В `wp-config.php`:

```php
define( 'DISABLE_WP_CRON', true );
```

В crontab (`crontab -e`):

```
* * * * * cd /var/www/site && wp cron event run --due-now --quiet
```

Без WP-CLI можно дёргать защищённый эндпоинт. Ключ показан на странице «Notion To-Do → Общее»:

```
* * * * * curl -fsS -X POST 'https://site.ru/wp-json/ntodo/v1/cron/reminders?key=КЛЮЧ' >/dev/null
```

Есть и отдельная команда `wp ntodo reminders`.

## REST API

Префикс `/wp-json/ntodo/v1`, все запросы с заголовком `X-WP-Nonce`.

| Метод | Путь | Назначение |
|---|---|---|
| GET | `/tasks?filter=all\|today\|upcoming\|done&q=` | список |
| POST | `/tasks` | создать → 201 |
| PATCH | `/tasks/{id}` | изменить поля |
| DELETE | `/tasks/{id}` | мягкое удаление → 204 |
| POST | `/tasks/{id}/restore` | отменить удаление |
| PUT | `/tasks/order` | порядок `{ids: [...]}` |
| DELETE | `/tasks/completed` | очистить выполненные |
| POST | `/tasks/import` | перенос гостевых задач |
| POST/DELETE | `/telegram/link` | выдать код / отвязать |
| GET | `/telegram/status` | статус привязки |
| POST | `/telegram/webhook` | апдейты Telegram (по secret token) |
| POST | `/cron/reminders?key=` | рассылка без WP-CLI |
| POST | `/profile` | часовой пояс пользователя |
| POST | `/auth/login`, `/auth/register`, `/auth/logout` | своя форма входа |

## Тесты

```bash
npm i -D @playwright/test && npx playwright install
NTODO_URL=https://site.ru/ npx playwright test
```

## Отличия от ТЗ

* Заголовки задач не проходят через `sanitize_text_field`, потому что он вырезает теги, и по сценарию T5 `<script>alert(1)</script>` превратился бы в пустую задачу. Вместо этого из строки удаляются управляющие символы, пробелы схлопываются, длина обрезается до 500. На выводе везде `textContent` и `esc_html`.
* `--text-muted` и цвета текста в тегах приоритета немного затемнены относительно токенов Notion, чтобы пройти WCAG AA (контраст ≥ 4.5:1).
* В таблицу задач добавлены поля `client_uid` (защита от дублей при офлайн-синхронизации), `due_has_time`, `remind_before`, `remind_tries`, `retry_at`. В таблицу Telegram добавлены `digest_date` и `blocked`.

## AI-инструмент

Сайт сделан с помощью Claude: архитектура плагина, PHP-код, фронтенд, бот и тесты.
