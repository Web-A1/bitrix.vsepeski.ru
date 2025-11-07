# bitrix.vsepeski.ru (AI brief)

## Snapshot
- Purpose: модуль «Рейсы» для Bitrix24 портала vsepeski.ru; standalone страница совпадает с iframe-плейсментом.
- Stack: PHP 8.2 + Composer, MySQL 8.0 (UUID), Bitrix24 REST, ванильный JS/CSS.
- Deployment: ветка `main`, автодеплой на прод через Beget hook.

## Domain essentials
- Haul (`hauls`): рейс сделки; включает load/unload блоки, sequence, ответственного водителя.
- `hauls.leg_distance_km` хранит плечо (км) между точками, используется в предпросмотре и копировании рейса.
- Truck (`trucks`): самосвал, уникальный `license_plate`.
- Material (`materials`): тип груза.
- Drivers поступают из Bitrix отдела `BITRIX_DRIVERS_DEPARTMENT`.
- Каждое изменение полей фиксируется в таблице `haul_change_events` (кто/что/когда), статусные переходы дополнительно пишутся в `haul_status_events`.

## Driver accounts & mobile login
- Учётки водителей хранятся в таблице `driver_accounts` (см. миграцию `202511050040_create_driver_accounts_table.sql`). Для авторизации используется Bitrix ID (`bitrix_user_id`), логин и хеш пароля.
- Логиновый e-mail всегда приводится к нижнему регистру.
- Для создания/обновления водителя используйте CLI:
  ```bash
  php bin/driver-create --email=mkurbanov.drv@vsepeski.ru
  ```
  Скрипт подтянет пользователя из Bitrix24 (по e-mail), определит `bitrix_user_id`, сгенерирует пароль (или возьмёт из `--password=...`), запишет его в таблицу и выведет на экран. Дополнительно запись попадёт в `storage/logs/driver_passwords.log`, чтобы пароль не потерялся (лог не коммитится).
- Если нужно задать данные вручную, доступны параметры `--bitrix-id`, `--name`, `--phone`, `--password`.
- Запуск можно выполнять напрямую на сервере:
  ```bash
  ssh tdsta@tdsta.beget.tech "cd ~/bitrix.vsepeski.ru/app && php8.2 bin/driver-create --email=..."
  ```
  Для удобства создайте алиас в `~/.ssh/config` и используйте короткую команду.

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
- `GET /api/crm/companies?type=supplier|carrier` — справочники компаний Bitrix24 по типам.
- `GET /api/deals/{dealId}` — заголовок сделки + данные клиента/контакта для автозаполнения формы.
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
- Для автозаполнения выпадающих списков нужны `BITRIX_COMPANY_SUPPLIER_TYPE` и `BITRIX_COMPANY_CARRIER_TYPE` (значения соответствуют `TYPE_ID` в CRM).
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
