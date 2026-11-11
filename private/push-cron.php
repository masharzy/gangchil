<?php
// Optional cPanel cron: php /absolute/path/to/htdocs/private/push-cron.php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/bootstrap.php';require __DIR__.'/push.php';
echo json_encode(push_dispatch(),JSON_UNESCAPED_SLASHES).PHP_EOL;
