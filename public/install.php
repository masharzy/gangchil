<?php
declare(strict_types=1);
require_once __DIR__.'/../private/bootstrap.php';
header('Cache-Control: no-store');
$error='';
try {
 $hasUsers=(bool)query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='users'")->fetchColumn();
 $num=$hasUsers?(int)query('SELECT COUNT(*) FROM users')->fetchColumn():0;
 if($num>0){http_response_code(403);exit('Installer locked. An owner already exists. Delete public/install.php from hosting after setup.');}
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals(csrf_token(),(string)($_POST['csrf']??'')))throw new RuntimeException('Invalid security token. Reload page.');
  $token=(string)($_POST['install_token']??'');
  $name=trim_text($_POST['name']??'',120);$email=strtolower(trim_text($_POST['email']??'',190));
  $pass=(string)($_POST['password']??'');
  if(strlen(INSTALL_TOKEN)<24 || !hash_equals(INSTALL_TOKEN,$token))throw new RuntimeException('Invalid installation token.');
  if(!$name || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($pass)<12)throw new RuntimeException('Valid name, email and 12+ character password required.');
  $db=database();
  // Docker demo can bootstrap its schema automatically after verifying INSTALL_TOKEN.
  // Shared cPanel deployments can still import db/mysql.sql with phpMyAdmin.
  $schemaFile=__DIR__.'/../db/mysql.sql';
  if(!is_file($schemaFile))$schemaFile=__DIR__.'/../private/mysql.sql';
  if(is_file($schemaFile)){
   $schema=file_get_contents($schemaFile);
   if($schema===false)throw new RuntimeException('Cannot read database schema.');
   foreach(preg_split('/;[\\t ]*(?:\\r?\\n|$)/',$schema) as $statement){
    $statement=trim($statement);
    if($statement!=='')$db->exec($statement);
   }
  } elseif(!$hasUsers){
   throw new RuntimeException('First import db/mysql.sql with phpMyAdmin, then run the installer.');
  }
  if((int)query('SELECT COUNT(*) FROM users')->fetchColumn()>0)throw new RuntimeException('Owner already installed.');
  $db->beginTransaction();
  try {
   query('INSERT INTO users(name,email,password_hash,role,permissions) VALUES(?,?,?,?,?)',[$name,$email,password_hash($pass,PASSWORD_DEFAULT),'owner','{}']);
   foreach(['Kabsa','Rice & Biryani','Chicken','Drinks'] as $cat)query('INSERT INTO categories(name) VALUES(?)',[$cat]);
   for($i=1;$i<=12;$i++)query('INSERT INTO dining_tables(name) VALUES(?)',['Table '.$i]);
   $db->commit();header('Location: /#login');exit;
  } catch(Throwable $e) {if($db->inTransaction())$db->rollBack();throw $e;}
 }
} catch(Throwable $e) {$error=$e->getMessage();}
function h(string $value): string {return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>GANGCHIL First-Time Setup</title><style>body{font:16px system-ui;background:#fafaf7;color:#111a15;padding:25px}main{max-width:450px;margin:50px auto;background:#fff;border:1px solid #ddd;padding:25px}label{display:block;margin:14px 0}input{width:100%;padding:12px;margin-top:5px;box-sizing:border-box}button{padding:13px;background:#0f3d2e;color:white;border:0;width:100%;cursor:pointer}.error{color:#b23}</style></head>
<body><main><h1>GANGCHIL Setup</h1><p>Create the very first Owner account. This installer locks once an account exists.</p>
<?php if($error): ?><p class="error"><?=h($error)?></p><?php endif; ?>
<form method="post">
<input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
<label>Installation token<input type="password" name="install_token" required></label>
<label>Owner name<input name="name" maxlength="120" required></label>
<label>Owner email<input type="email" name="email" required></label>
<label>Owner password (12+ characters)<input type="password" name="password" minlength="12" required></label>
<button type="submit">Create Owner Account</button></form>
</main></body></html>