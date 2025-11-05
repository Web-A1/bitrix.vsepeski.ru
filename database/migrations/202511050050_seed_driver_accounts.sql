INSERT INTO driver_accounts (bitrix_user_id, login, password_hash, name, email, phone)
VALUES (
    1,
    'a1@capital-craft.ru',
    '$2y$12$wj6c9CBoJbm3kkZYUvcdOui/Cq1biBbvHOYXwAjcnT33opDl6nwle',
    'Андрей Филипов',
    'a1@capital-craft.ru',
    NULL
)
ON DUPLICATE KEY UPDATE
    password_hash = VALUES(password_hash),
    name = VALUES(name),
    email = VALUES(email),
    phone = VALUES(phone),
    updated_at = CURRENT_TIMESTAMP;
