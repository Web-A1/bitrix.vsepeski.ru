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
- `GET /health` — health-check (app, база, очередь привязок) возвращает `status: ok|degraded`.

## UI widget
- HTML: `public/hauls/index.html`, JS: `public/assets/hauls.js`, CSS: `public/assets/hauls.css`.
- Основные элементы: поле ввода `deal-id`, кнопка `Новый рейс`, FAB `+`, список карт рейсов, модальное редактирование.
- При embed вызывает `BX24.fitWindow`, подстраивает тему и пытается авто-определить `dealId` из payload/query/referrer.

## Bitrix install flow
- `public/bitrix/install.php`: принимает install hook, сохраняет OAuth (`storage/bitrix/oauth.json`), при placement-запросе рендерит widget.
- Авто ребайндинг placements `CRM_DEAL_DETAIL_TAB` и `CRM_DEAL_LIST_MENU` (см. `docs/bitrix/mobile-placement.md`).
- Привязки выполняются асинхронно, если `INSTALL_QUEUE_PLACEMENTS=true`: `QueuedPlacementBindingDispatcher` складывает задания в `storage/bitrix/placement-jobs`, а CLI `bin/process-placement-jobs` выполняет их (добавьте cron `* * * * * cd ~/bitrix.vsepeski.ru/app && php bin/process-placement-jobs`).
- Если нужно синхронно (отладка), установите `INSTALL_QUEUE_PLACEMENTS=false` — тогда используется `SyncPlacementBindingDispatcher`.
- В install hook проверяем `X-Bitrix-Signature` (HMAC SHA-256 по GET/POST) с `BITRIX_WEBHOOK_SECRET`; запросы с неверной подписью отклоняются.

## Tooling & commands
- `composer install`, `.env` на базе `.env.example` (dotenv 5.6).
- Для автозаполнения выпадающих списков нужны `BITRIX_COMPANY_SUPPLIER_TYPE` и `BITRIX_COMPANY_CARRIER_TYPE` (значения соответствуют `TYPE_ID` в CRM).
- Миграции: `composer run db:migrate` → `bin/migrate` → SQL в `database/migrations`.
- Виджет локально: `php -S localhost:8000 -t public` → `http://localhost:8000/hauls`.
- Очередь привязок: `php bin/process-placement-jobs` (можно повесить на cron/systemd timer).
- Мониторинг: `curl https://bitrix.vsepeski.ru/health` — 200/ok при норме, 503/degraded если БД или очередь недоступны.
- Коммит/пуш: `bin/quick-push.sh [note]` (генерирует сообщение по git diff).
- Тесты: `composer test` (PHPUnit 10) — см. `tests/Unit/InstallRequestHandlerTest.php` для примера, запускаем `./vendor/bin/phpunit`.

## Key files map
- `public/index.php`, `src/Infrastructure/Http/{Kernel,Request,Response}.php` — HTTP слой.
- `src/Modules/Hauls/Application/Services/HaulService.php` — CRUD бизнес-логика.
- `src/Modules/Hauls/Infrastructure/*Repository.php` — работа с PDO.
- `src/Modules/Hauls/Ui/{HaulController,...}` — REST-контроллеры.
- `src/Infrastructure/Bitrix/BitrixRestClient.php` — минимальный REST-клиент Bitrix24.

## Known gaps / reminders
- Тестов всё ещё мало: покрыта только установка (`InstallRequestHandlerTest`); API/UI модули требуют интеграционных сценариев.
- Для других вебхуков пока нет проверок подписи — если появятся новые точки входа, нужно так же применять `WebhookSignatureVerifier`.
- При расширении модулей регистрировать провайдеры в `config/modules.php`.
