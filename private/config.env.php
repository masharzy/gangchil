<?php
// Railway / Render Docker demo: environment variables only; no secrets in source control.
declare(strict_types=1);
define('DB_HOST', (getenv('MYSQL_HOST') ?: getenv('MYSQLHOST') ?: 'localhost'));
define('DB_PORT', (int)(getenv('MYSQL_PORT') ?: getenv('MYSQLPORT') ?: '3306'));
define('DB_NAME', (getenv('MYSQL_DATABASE') ?: getenv('MYSQLDATABASE') ?: ''));
define('DB_USER', (getenv('MYSQL_USER') ?: getenv('MYSQLUSER') ?: ''));
define('DB_PASSWORD', (getenv('MYSQL_PASSWORD') ?: getenv('MYSQLPASSWORD') ?: ''));
define('INSTALL_TOKEN', getenv('INSTALL_TOKEN') ?: '');
define('APP_NAME', 'GANGCHIL');

define('PUSHER_APP_ID', getenv('PUSHER_APP_ID') ?: '');
define('PUSHER_KEY', getenv('PUSHER_KEY') ?: '');
define('PUSHER_SECRET', getenv('PUSHER_SECRET') ?: '');
define('PUSHER_CLUSTER', getenv('PUSHER_CLUSTER') ?: '');
