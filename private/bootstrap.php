<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
ini_set('display_errors', '0');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('gangchil_session');
session_set_cookie_params([
 'lifetime'=>0,'path'=>'/','secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
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
function json_response(array $data,int $status=200): never {
 http_response_code($status); header('Content-Type: application/json; charset=utf-8');
 header('Cache-Control: no-store, private');echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;
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
 $stmt=query('SELECT id,name,email,role,active,permissions FROM users WHERE id=?',[(int)$_SESSION['uid']]);
 $user=$stmt->fetch();
 if(!$user || !$user['active']){unset($_SESSION['uid']);return null;}
 $user['permissions']=json_decode((string)($user['permissions']??'{}'),true)?:[];
 return $user;
}
function permissions_for(string $role): array {
 return match($role) {
 'owner'=>['*'],
 'manager'=>['orders:view','orders:create','orders:pay','orders:cancel','products:manage','tables:manage','reports:view'],
 'chef'=>['orders:view','orders:prepare'],
 'waiter'=>['orders:view','orders:create','orders:serve'],
 'staff'=>['orders:view','orders:create'],
 default=>[]
 };
}
function allowed(?array $user,string $perm): bool {
 if(!$user)return false;
 if(isset($user['permissions'][$perm])&&is_bool($user['permissions'][$perm]))return $user['permissions'][$perm];
 return in_array('*',permissions_for($user['role']),true)||in_array($perm,permissions_for($user['role']),true);
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
function change_event(): void {query('INSERT INTO changes() VALUES()');}
function trim_text(mixed $value,int $max): string {
 if(!is_string($value))return '';return mb_substr(trim($value),0,$max,'UTF-8');
}
function require_post(): void {if($_SERVER['REQUEST_METHOD']!=='POST')fail('POST required',405);}
