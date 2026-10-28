<?php
declare(strict_types=1);
require __DIR__.'/../private/bootstrap.php';
$valid=['01712345678','+8801712345678','8801712345678','008801712345678','01712-345678','০১৭১২৩৪৫৬৭৮'];
foreach($valid as $v){if(bd_phone($v)!=='01712345678')throw new RuntimeException('Normalization failed');}
foreach(['01212345678','+911712345678','0171234567','017123456789','email@example.com',123,null] as $v){if(bd_phone($v)!==null)throw new RuntimeException('Invalid phone accepted');}
echo "PASS: Bangladeshi mobile normalization and validation\n";
