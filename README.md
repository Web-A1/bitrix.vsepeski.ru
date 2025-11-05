# bitrix.vsepeski.ru (AI brief)

## Snapshot
- Purpose: модуль «Рейсы» для Bitrix24 портала vsepeski.ru; standalone страница совпадает с iframe-плейсментом.
- Stack: PHP 8.2 + Composer, MySQL 8.0 (UUID), Bitrix24 REST, ванильный JS/CSS.
- Deployment: ветка `main`, автодеплой на прод через Beget hook.

## Domain essentials
- Haul (`hauls`): рейс сделки; включает load/unload блоки, sequence, ответственного водителя.
- Truck (`trucks`): самосвал, уникальный `license_plate`.
- Material (`materials`): тип груза.
- Drivers поступают из Bitrix отдела `BITRIX_DRIVERS_DEPARTMENT`.

## Driver accounts & mobile login
- Локальные учётки водителей хранятся в таблице `driver_accounts` (см. миграцию `202511050040_create_driver_accounts_table.sql`). Для авторизации используется `bitrix_user_id` (ID сотрудника в Bitrix24), поле `login` и `password_hash`.
- Логины приводятся к нижнему регистру при проверке — сохраняйте их в базе уже в нижнем регистре (обычно это корпоративный e-mail).
- Сгенерировать хеш можно командой `php -r "echo password_hash('НовыйПароль', PASSWORD_DEFAULT), PHP_EOL;"`.
- Пример вставки:
  ```sql
  INSERT INTO driver_accounts (bitrix_user_id, login, password_hash, name, email, phone)
  VALUES (1234, 'driver@example.com', '$2y$...', 'Иван Иванов', 'driver@example.com', '+7 999 000 00 00');
  ```
- После добавления записи водитель может авторизоваться на `https://bitrix.vsepeski.ru/hauls/`; выдавайте логин/пароль индивидуально. Удаление/смена пароля выполняется через обновление соответствующей записи.
- Для автоматизации создан CLI-хелпер `php bin/driver-account`. Достаточно указать e-mail:
  ```bash
  php bin/driver-account --email=mkurbanov.drv@vsepeski.ru
  ```
  Скрипт подтягивает пользователя из Bitrix24 (по e-mail), назначает ID, генерирует пароль и выводит его в консоль. Параметры `--password`, `--bitrix-id`, `--name`, `--phone` позволяют задать значения вручную.

## System topology
- Container `B24\Center\Core\Application` (инициализация в `bootstrap/app.php`).
- Providers: `DatabaseServiceProvider` (PDO), `HaulsServiceProvider` (репозитории, сервисы, Bitrix REST).
- HTTP entry `public/index.php` → `Infrastructure\Http\Kernel` → контроллеры модуля.
- Persistence: `Infrastructure\Persistence\Database\{ConnectionFactory,Migrator}` + SQL в `database/migrations`.
- Module layout `src/Modules/Hauls/{Domain,Application,Infrastructure,Ui}`.

## API surface
- `GET/POST /api/deals/{dealId}/hauls` — список и создание рейсов.
- `GET/PATCH/DELETE /api/hauls/{haulId}` — чтение/обновление/мягкое удаление.
- `GET/POST /api/trucks`, `DELETE /api/trucks/{id}`.
- `GET/POST /api/materials`, `DELETE /api/materials/{id}`.
- `GET /api/drivers` — Bitrix REST прокси (ожидает webhook URL).
- `GET /hauls` — отдаёт widget с вставленным `window.B24_INSTALL_PAYLOAD`.

## UI widget
- HTML: `public/hauls/index.html`, JS: `public/assets/hauls.js`, CSS: `public/assets/hauls.css`.
- Основные элементы: поле ввода `deal-id`, кнопка `Новый рейс`, FAB `+`, список карт рейсов, модальное редактирование.
- При embed вызывает `BX24.fitWindow`, подстраивает тему и пытается авто-определить `dealId` из payload/query/referrer.

## Bitrix install flow
- `public/bitrix/install.php`: принимает install hook, сохраняет OAuth (`storage/bitrix/oauth.json`), при placement-запросе рендерит widget.
- Авто ребайндинг placements `CRM_DEAL_DETAIL_TAB` и `CRM_DEAL_LIST_MENU` (см. `docs/bitrix/mobile-placement.md`).
- TODO: реализовать проверку `BITRIX_WEBHOOK_SECRET` на входящих REST-запросах (сейчас только сохраняем значение).

## Tooling & commands
- `composer install`, `.env` на базе `.env.example` (dotenv 5.6).
- Миграции: `composer run db:migrate` → `bin/migrate` → SQL в `database/migrations`.
- Виджет локально: `php -S localhost:8000 -t public` → `http://localhost:8000/hauls`.
- Коммит/пуш: `bin/quick-push.sh [note]` (генерирует сообщение по git diff).
- Тесты: `composer test` (PHPUnit 10) — каталоги `tests/` пока пустые.

## Key files map
- `public/index.php`, `src/Infrastructure/Http/{Kernel,Request,Response}.php` — HTTP слой.
- `src/Modules/Hauls/Application/Services/HaulService.php` — CRUD бизнес-логика.
- `src/Modules/Hauls/Infrastructure/*Repository.php` — работа с PDO.
- `src/Modules/Hauls/Ui/{HaulController,...}` — REST-контроллеры.
- `src/Infrastructure/Bitrix/BitrixRestClient.php` — минимальный REST-клиент Bitrix24.

## Known gaps / reminders
- Нет юнит/фича тестов — при изменениях полагаться на ручную проверку.
- Проверка входящих вебхуков (`BITRIX_WEBHOOK_SECRET`) не реализована.
- При расширении модулей регистрировать провайдеры в `config/modules.php`.
