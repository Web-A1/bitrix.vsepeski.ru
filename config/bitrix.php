<?php

declare(strict_types=1);

return [
    'client_id' => $_ENV['BITRIX_CLIENT_ID'] ?? '',
    'client_secret' => $_ENV['BITRIX_CLIENT_SECRET'] ?? '',
    'portal_url' => $_ENV['BITRIX_PORTAL_URL'] ?? '',
    'webhook_secret' => $_ENV['BITRIX_WEBHOOK_SECRET'] ?? '',
    'webhook_url' => rtrim($_ENV['BITRIX_WEBHOOK_URL'] ?? '', '/'),
    'drivers_department' => $_ENV['BITRIX_DRIVERS_DEPARTMENT'] ?? 'Водители',
    'company_types' => [
        'supplier' => $_ENV['BITRIX_COMPANY_SUPPLIER_TYPE'] ?? 'SUPPLIER',
        'carrier' => $_ENV['BITRIX_COMPANY_CARRIER_TYPE'] ?? 'CARRIER',
    ],
];
