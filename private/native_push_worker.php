<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/bootstrap.php';require __DIR__.'/operations.php';require __DIR__.'/push.php';require __DIR__.'/native_push.php';
date_default_timezone_set('Asia/Dhaka');
$hasUsers=(bool)query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='users'")->fetchColumn();
if(!$hasUsers||(int)query('SELECT COUNT(*) FROM users')->fetchColumn()===0)exit;
ensure_operations_schema();push_schema();native_push_dispatch();
