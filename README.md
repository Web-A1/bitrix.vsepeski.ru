# bitrix.vsepeski.ru

Серверная платформа для кастомных интеграций Bitrix24 проекта vsepeski.ru. Код организован по модулям (`src/Modules`), сейчас реализован модуль управления перевозками.

## Требования

- PHP 8.2+ с расширениями `pdo_mysql`, `openssl`, `mbstring` и `curl`
- Composer 2.x
- MySQL/MariaDB 8.0+ (InnoDB, поддержка функции `UUID()`)
- Доступ к порталу Bitrix24 с правами на создание приложений/вебхуков

## Быстрый старт

```bash
git clone git@github.com:web-a1/bitrix-vsepeski.git
cd bitrix-vsepeski
composer install
cp .env.example .env
```

1. Заполните `.env`, указав параметры базы данных и Bitrix24 (см. раздел «Переменные окружения»).
2. Примените миграции: `composer run db:migrate`.
3. Запустите встроенный сервер PHP: `php -S localhost:8000 -t public`.
4. Откройте `http://localhost:8000/hauls` для проверки UI перевозок.

## Переменные окружения

Основные переменные, которые нужно указать в `.env`:

- `APP_NAME`, `APP_ENV`, `APP_DEBUG`, `APP_TIMEZONE`
- `DB_CONNECTION=mysql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_CHARSET`
- `BITRIX_CLIENT_ID`, `BITRIX_CLIENT_SECRET`, `BITRIX_PORTAL_URL`
- `BITRIX_WEBHOOK_URL`, `BITRIX_WEBHOOK_SECRET`
- `BITRIX_DRIVERS_DEPARTMENT` — название отдела в портале, куда добавляются водители

## Миграции БД

Для применения миграций используется консольный скрипт `bin/migrate` (обёртка на `B24\Center\Infrastructure\Persistence\Database\Migrator`):

```bash
composer run db:migrate    # с автозагрузкой из vendor/
```

Миграции создают таблицы материалов, грузовиков и перевозок. Идентификаторы генерируются функцией `UUID()` на стороне MySQL.

## Локальный запуск

```bash
php -S localhost:8000 -t public
```

Сервер отдаёт публичный каталог `public/`, Bitrix-установщик доступен по адресу `/bitrix/install.php`.

## Структура проекта

- `public/` — HTTP-точки входа Bitrix (`bitrix/install.php`) и статические ассеты (`assets/`, `hauls/`).
- `src/Modules/Hauls` — доменная логика и UI модуля перевозок (контроллеры, сервисы, сущности).
- `src/Infrastructure/Persistence/Database` — фабрика подключения, мигратор и слой доступа к БД.
- `config/*.php` — конфигурация приложения, БД и Bitrix.
- `database/migrations` — SQL-миграции для MySQL/MariaDB.
- `bin/migrate` — CLI-инструмент для применения миграций.

## Интеграция с Bitrix24

- Файл `public/bitrix/install.php` используется для установки приложения в портале Bitrix24.
- В `.env` должны быть заданы идентификатор и секрет приложения, адрес портала и URL входящего вебхука.
- Секрет вебхука (`BITRIX_WEBHOOK_SECRET`) проверяется в контроллерах при получении запросов от Bitrix24.

## Тестирование

Автотесты запускаются командой:

```bash
composer test
```

Тесты располагаются в каталоге `tests/` (фреймворк PHPUnit 10).

## Разработка и деплой

- Основная ветка `main` — рабочая и продакшен-ветка; коммиты делаются локально и пушатся напрямую.
- Пуш в удалённый репозиторий — обязательный этап: CI/CD сразу выкатывает изменения на единственный сервер (production).
- Автодеплой работает через Beget: подписанный хуком репозиторий разворачивается в каталог проекта, база — MySQL 8.0 из панели Beget.
- Перед пушем прогоняйте ключевые проверки (миграции, UI/Bitrix-флоу). Если правка оказалась неудачной, откатите репозиторий на предыдущий коммит.
- Для быстрой публикации изменений используйте `./bin/quick-push.sh [комментарий]` — скрипт сам сформирует сообщение коммита (по файлам и строкам) и выполнит `git push`. Дополнительный комментарий опционален.

## Дополнительно

- Для фронтенда модуля перевозок используются ассеты `public/assets/hauls.js` и `public/assets/hauls.css`.
