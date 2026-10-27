<?php
// Render Docker demo only: use environment variables; no secrets in source control.
declare(strict_types=1);
define('DB_HOST', getenv('MYSQL_HOST') ?: 'localhost');
define('DB_PORT', (int)(getenv('MYSQL_PORT') ?: '3306'));
define('DB_NAME', getenv('MYSQL_DATABASE') ?: '');
define('DB_USER', getenv('MYSQL_USER') ?: '');
define('DB_PASSWORD', getenv('MYSQL_PASSWORD') ?: '');
define('INSTALL_TOKEN', getenv('INSTALL_TOKEN') ?: '');
define('APP_NAME', 'GANGCHIL');
