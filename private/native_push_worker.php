<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/bootstrap.php';require __DIR__.'/operations.php';require __DIR__.'/push.php';require __DIR__.'/native_push.php';
date_default_timezone_set('Asia/Dhaka');ensure_operations_schema();push_schema();native_push_dispatch();
