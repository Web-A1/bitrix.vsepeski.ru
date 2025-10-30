<?php

declare(strict_types=1);

return [
    'client_id' => $_ENV['BITRIX_CLIENT_ID'] ?? '',
    'client_secret' => $_ENV['BITRIX_CLIENT_SECRET'] ?? '',
    'portal_url' => $_ENV['BITRIX_PORTAL_URL'] ?? '',
    'webhook_secret' => $_ENV['BITRIX_WEBHOOK_SECRET'] ?? '',
];

