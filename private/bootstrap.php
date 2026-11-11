<?php
declare(strict_types=1);
if (is_file(__DIR__.'/config.php')) require_once __DIR__.'/config.php';
else require_once __DIR__.'/config.env.php';
ini_set('display_errors', '0');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
define('STAFF_SURFACE', str_starts_with((string)(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/'), '/staff/'));
session_name(STAFF_SURFACE?'gangchil_staff_session':'gangchil_customer_session');
session_set_cookie_params([
 'lifetime'=>0,'path'=>STAFF_SURFACE?'/staff/':'/','secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
 'httponly'=>true,'samesite'=>'Strict'
]);
session_start();

function database(): PDO {
 static $pdo = null;
 if (!$pdo) {
  $pdo = new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASSWORD,[
   PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
   PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
   PDO::ATTR_EMULATE_PREPARES=>false,
   PDO::ATTR_PERSISTENT=>false
  ]);
 }
 return $pdo;
}
function query(string $sql,array $params=[]): PDOStatement {
 $stmt=database()->prepare($sql); $stmt->execute($params);return $stmt;
}
function decorate_order_numbers(array $data): array {
 foreach($data as $key=>$value){if(is_array($value))$data[$key]=decorate_order_numbers($value);}
 if(isset($data['id'],$data['status'],$data['total']))$data['order_number']=str_pad((string)$data['id'],6,'0',STR_PAD_LEFT);
 return $data;
}
function json_response(array $data,int $status=200): never {
 $data=decorate_order_numbers($data);
 http_response_code($status); header('Content-Type: application/json; charset=utf-8');
 header('Cache-Control: no-store, private');$body=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
 if(!empty($GLOBALS['gangchil_push_flush'])&&$status<300&&function_exists('push_dispatch')){
  ignore_user_abort(true);
  header('Content-Length: '.strlen($body));echo $body;session_write_close();
  if(function_exists('fastcgi_finish_request'))fastcgi_finish_request();else{while(ob_get_level()>0){if(!@ob_end_flush())break;}flush();}
  try{push_dispatch();}catch(Throwable $e){error_log('[GANGCHIL] Background push dispatch failed');}exit;
 }
 echo $body;exit;
}
function fail(string $message,int $status=400): never { json_response(['error'=>$message],$status); }
function request_data(): array {
 $raw=file_get_contents('php://input');$decoded=json_decode($raw===false?'':$raw,true);
 if (!is_array($decoded)) fail('Invalid JSON'); return $decoded;
}
function csrf_token(): string {
 if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));return $_SESSION['csrf'];
}
function require_csrf(): void {
 if(!hash_equals(csrf_token(),(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'')))fail('Invalid security token. Reload page.',419);
}
function current_user(): ?array {
 if(empty($_SESSION['uid']))return null;
 $stmt=query('SELECT id,name,phone,role,active,permissions,(pin_hash IS NOT NULL) AS has_pin FROM users WHERE id=?',[(int)$_SESSION['uid']]);
 $user=$stmt->fetch();
 if(!$user || !$user['active']||!in_array($user['role'],['owner','manager'],true)){unset($_SESSION['uid']);return null;}
 $user['has_pin']=(bool)$user['has_pin'];
 $user['permissions']=json_decode((string)($user['permissions']??'{}'),true)?:[];
 return $user;
}
function permissions_for(string $role): array {return match($role){'owner'=>['*'],'manager'=>['orders:view','orders:pay','products:manage','reports:view'],default=>[]};}
function allowed(?array $user,string $perm): bool {
 if(!$user||!in_array($user['role'],['owner','manager'],true))return false;
 if($user['role']==='owner')return true;
 if(!in_array($perm,['orders:view','orders:pay','products:manage','reports:view'],true))return false;
 if($perm==='orders:view')return true;
 return $user['permissions'][$perm]??in_array($perm,permissions_for('manager'),true);
}
function require_permission(string $perm): array {
 $u=current_user();
 if(!$u)fail('Login required',401);
 if(!allowed($u,$perm))fail('Permission denied',403);
 return $u;
}
function integer_id(mixed $value): int {
 if(!is_scalar($value)||!preg_match('/^[1-9][0-9]{0,17}$/',(string)$value))fail('Invalid ID');
 return (int)$value;
}
function audit(?int $uid,string $action,string $entity,int|string $id,array $details=[]): void {
 query('INSERT INTO audit_logs(user_id,action,entity,entity_id,details) VALUES(?,?,?,?,?)',[$uid,$action,$entity,(string)$id,json_encode($details,JSON_UNESCAPED_UNICODE)]);
}
function trim_text(mixed $value,int $max): string {
 if(!is_string($value))return '';return mb_substr(trim($value),0,$max,'UTF-8');
}
function require_post(): void {if($_SERVER['REQUEST_METHOD']!=='POST')fail('POST required',405);}

// Existing databases keep their historical records; this adds the new login column.
function ensure_phone_schema(): void {
 try {query('SELECT phone FROM users LIMIT 0');}
 catch(PDOException $e){
  if($e->getCode()!=='42S22')throw $e;
  try {database()->exec('ALTER TABLE users MODIFY COLUMN email VARCHAR(190) NULL, ADD COLUMN phone VARCHAR(11) NULL, ADD UNIQUE KEY users_phone_unique(phone)');}
  catch(PDOException $race){if((int)($race->errorInfo[1]??0)!==1060)throw $race;query('SELECT phone FROM users LIMIT 0');}
 }
}
function bd_phone(mixed $value): ?string {
 if(!is_string($value))return null;
 $value=strtr($value,['০'=>'0','১'=>'1','২'=>'2','৩'=>'3','৪'=>'4','৫'=>'5','৬'=>'6','৭'=>'7','৮'=>'8','৯'=>'9']);
 $value=preg_replace('/[\s()\-]+/u','',trim($value));
 if($value===null)return null;
 if(str_starts_with($value,'+880'))$value='0'.substr($value,4);
 elseif(str_starts_with($value,'00880'))$value='0'.substr($value,5);
 elseif(str_starts_with($value,'880'))$value='0'.substr($value,3);
 return preg_match('/^01[3-9][0-9]{8}$/D',$value)?$value:null;
}

function valid_pin(mixed $value): bool {return is_string($value)&&preg_match('/^[0-9]{4}$/D',$value)===1;}
function ensure_pin_schema(): void {
 try {query('SELECT pin_hash FROM users LIMIT 0');}
 catch(PDOException $e){
  if($e->getCode()!=='42S22')throw $e;
  try {database()->exec('ALTER TABLE users MODIFY COLUMN password_hash VARCHAR(255) NULL, ADD COLUMN pin_hash VARCHAR(255) NULL');}
  catch(PDOException $race){if((int)($race->errorInfo[1]??0)!==1060)throw $race;}
 }
 database()->exec('CREATE TABLE IF NOT EXISTS auth_limits (limit_key CHAR(64) PRIMARY KEY, attempts INT UNSIGNED NOT NULL DEFAULT 0, window_start BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB');
}
// Attempts are committed before checking the PIN, so parallel requests/cookie resets cannot bypass the account limit.
function consume_pin_attempt(string $identity,int $maximum=5): string {
 $key=hash('sha256',$identity);$now=time();$db=database();$db->beginTransaction();
 try {
  query('INSERT IGNORE INTO auth_limits(limit_key,attempts,window_start) VALUES(?,0,?)',[$key,$now]);
  $row=query('SELECT attempts,window_start FROM auth_limits WHERE limit_key=? FOR UPDATE',[$key])->fetch();
  $attempts=$now-(int)$row['window_start']>=900?0:(int)$row['attempts'];
  $start=$attempts===0?$now:(int)$row['window_start'];
  if($attempts>=$maximum){$db->commit();fail('Too many PIN attempts. Try again in 15 minutes.',429);}
  query('UPDATE auth_limits SET attempts=?,window_start=? WHERE limit_key=?',[$attempts+1,$start,$key]);$db->commit();return $key;
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function clear_pin_attempts(string $key): void {query('DELETE FROM auth_limits WHERE limit_key=?',[$key]);}
function ensure_owner_schema(): void {
 database()->exec('CREATE TABLE IF NOT EXISTS expenses (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,request_token VARCHAR(80) NOT NULL UNIQUE,description VARCHAR(180) NOT NULL,amount DECIMAL(12,2) NOT NULL,expense_date DATE NOT NULL,created_by BIGINT UNSIGNED NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(expense_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}
function owner_orders(string $where,array $params,int $limit,string $direction='DESC',int $offset=0): array {
 $direction=$direction==='ASC'?'ASC':'DESC';
 $limit=max(1,min($limit,51));
 $rows=query('SELECT o.*,p.name AS paid_name,c.name AS started_name,r.name AS ready_name,s.name AS served_name,x.name AS cancelled_name FROM orders o LEFT JOIN users p ON p.id=o.paid_by LEFT JOIN users c ON c.id=o.started_by LEFT JOIN users r ON r.id=o.ready_by LEFT JOIN users s ON s.id=o.served_by LEFT JOIN users x ON x.id=o.cancelled_by '.$where.' ORDER BY o.id '.$direction.' LIMIT '.$limit.' OFFSET '.max(0,$offset),$params)->fetchAll();
 if(!$rows)return [];
 $ids=array_column($rows,'id');$holders=implode(',',array_fill(0,count($ids),'?'));
 $items=query('SELECT order_id,product_name AS name,quantity,unit_price AS price FROM order_items WHERE order_id IN ('.$holders.') ORDER BY id',$ids)->fetchAll();$by=[];
 foreach($items as $item)$by[(int)$item['order_id']][]=$item;
 foreach($rows as &$row){$row['items']=$by[(int)$row['id']]??[];if(function_exists('db_time_iso'))foreach(['created_at','paid_at','started_at','ready_at','served_at','cancelled_at'] as $key)if(!empty($row[$key]))$row[$key]=db_time_iso($row[$key]);}unset($row);return $rows;
}
function menu_image_url(mixed $url): bool {
 return $url===null||(is_string($url)&&((str_starts_with($url,'https://')&&filter_var($url,FILTER_VALIDATE_URL))||preg_match('#^/uploads/menu/[a-f0-9]{32}\.(jpg|png|webp)$#D',$url)));
}
