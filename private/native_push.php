<?php
declare(strict_types=1);
function native_push_schema(): void {
 static $ready=false;if($ready)return;
 query('CREATE TABLE IF NOT EXISTS native_push_devices(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,device_key VARCHAR(80) NOT NULL UNIQUE,token VARCHAR(2048) NOT NULL,token_hash CHAR(64) NOT NULL UNIQUE,last_seen_at DATETIME NOT NULL,INDEX(user_id)) ENGINE=InnoDB');
 query('CREATE TABLE IF NOT EXISTS native_push_jobs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,device_id BIGINT UNSIGNED NOT NULL,order_id BIGINT UNSIGNED NOT NULL,attempts INT NOT NULL DEFAULT 0,due_at DATETIME NOT NULL,locked_until DATETIME NULL,UNIQUE KEY(device_id,order_id),INDEX(due_at,locked_until)) ENGINE=InnoDB');$ready=true;
}
function native_push_config(): ?array {
 $file=__DIR__.'/firebase-client.json';if(!is_file($file))return null;
 $c=json_decode((string)file_get_contents($file),true);if(!is_array($c))return null;
 foreach(['apiKey','appId','projectId','messagingSenderId'] as $key)if(empty($c[$key])||!is_string($c[$key]))return null;
 return array_intersect_key($c,array_flip(['apiKey','appId','projectId','messagingSenderId']));
}
function native_push_routes(string $route,string $method): void {
 if(!str_starts_with($route,'native-push/'))return;
 $u=require_permission('orders:view');native_push_schema();
 if($route==='native-push/config'&&$method==='GET'){
  $c=native_push_config();json_response(['ready'=>$c!==null&&is_file(__DIR__.'/firebase-service-account.json'),'config'=>$c]);
 }
 require_csrf();$d=request_data();$key=(string)($d['device_key']??'');
 if(!preg_match('/^[a-zA-Z0-9-]{20,80}$/D',$key))fail('Invalid notification device.');
 if($route==='native-push/unregister'&&$method==='POST'){
  query('DELETE j FROM native_push_jobs j JOIN native_push_devices d ON d.id=j.device_id WHERE d.user_id=? AND d.device_key=?',[$u['id'],$key]);
  query('DELETE FROM native_push_devices WHERE user_id=? AND device_key=?',[$u['id'],$key]);json_response(['ok'=>true]);
 }
 if($route==='native-push/register'&&$method==='POST'){
  $token=(string)($d['token']??'');if(strlen($token)<20||strlen($token)>2048||preg_match('/[\s\x00-\x1F]/',$token))fail('Invalid push token.');
  $db=database();$db->beginTransaction();try{
   query('SELECT id FROM users WHERE id=? FOR UPDATE',[$u['id']]);
   $existing=query('SELECT id,user_id FROM native_push_devices WHERE device_key=? OR token_hash=? FOR UPDATE',[$key,hash('sha256',$token)])->fetchAll();
   if(!$existing&&(int)query('SELECT COUNT(*) FROM native_push_devices WHERE user_id=?',[$u['id']])->fetchColumn()>=8)throw new RuntimeException('Eight notification devices are already registered.');
   foreach($existing as $device){query('DELETE FROM native_push_jobs WHERE device_id=?',[$device['id']]);query('DELETE FROM native_push_devices WHERE id=?',[$device['id']]);}
   query('INSERT INTO native_push_devices(user_id,device_key,token,token_hash,last_seen_at) VALUES(?,?,?,?,NOW())',[$u['id'],$key,$token,hash('sha256',$token)]);$db->commit();json_response(['ok'=>true]);
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 }
 fail('Unknown notification action.',404);
}
function native_push_enqueue(int $order): int {
 native_push_schema();return query("INSERT IGNORE INTO native_push_jobs(device_id,order_id,due_at) SELECT d.id,?,NOW() FROM native_push_devices d JOIN users u ON u.id=d.user_id LEFT JOIN staff_preferences p ON p.user_id=u.id WHERE u.active=1 AND u.deleted_at IS NULL AND u.role IN ('owner','manager') AND (COALESCE(p.alerts_mute_until,0)=0 OR p.alerts_mute_until>0 AND p.alerts_mute_until<=UNIX_TIMESTAMP())",[$order])->rowCount();
}
function native_push_http(string $url,string $body,array $headers): array {
 $c=curl_init($url);curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT_MS=>1500,CURLOPT_TIMEOUT_MS=>3500,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
 $result=curl_exec($c);$code=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);curl_close($c);return [$code,json_decode((string)$result,true)??[]];
}
function native_push_access_token(array $account): string {
 $cache=hash('sha256','native-fcm:'.$account['client_email']);$saved=query('SELECT token,expires_at FROM webpush_tokens WHERE cache_key=?',[$cache])->fetch();if($saved&&(int)$saved['expires_at']>time()+120)return $saved['token'];
 $b64=fn($v)=>rtrim(strtr(base64_encode($v),'+/','-_'),'=');$jwt=$b64('{"alg":"RS256","typ":"JWT"}').'.'.$b64(json_encode(['iss'=>$account['client_email'],'scope'=>'https://www.googleapis.com/auth/firebase.messaging','aud'=>'https://oauth2.googleapis.com/token','iat'=>time(),'exp'=>time()+3600],JSON_THROW_ON_ERROR));
 if(!openssl_sign($jwt,$signature,$account['private_key'],OPENSSL_ALGO_SHA256))throw new RuntimeException('FCM signing unavailable.');
 [$status,$body]=native_push_http('https://oauth2.googleapis.com/token',http_build_query(['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$jwt.'.'.$b64($signature)]),['Content-Type: application/x-www-form-urlencoded']);
 if($status!==200||empty($body['access_token']))throw new RuntimeException('FCM authentication failed.');query('INSERT INTO webpush_tokens(cache_key,token,expires_at) VALUES(?,?,?) ON DUPLICATE KEY UPDATE token=VALUES(token),expires_at=VALUES(expires_at)',[$cache,$body['access_token'],time()+min(3600,(int)($body['expires_in']??3600))]);return $body['access_token'];
}
function native_push_dispatch(): void {
 $file=__DIR__.'/firebase-service-account.json';$client=native_push_config();if(!$client||!is_file($file))return;
 $account=json_decode((string)file_get_contents($file),true);if(empty($account['client_email'])||empty($account['private_key'])||($account['project_id']??'')!==$client['projectId']||!preg_match('/^[a-z0-9-]+$/D',$client['projectId']))throw new RuntimeException('Invalid FCM server configuration.');
 native_push_schema();query("DELETE j FROM native_push_jobs j LEFT JOIN native_push_devices d ON d.id=j.device_id LEFT JOIN users u ON u.id=d.user_id LEFT JOIN staff_preferences p ON p.user_id=u.id WHERE d.id IS NULL OR u.active=0 OR u.deleted_at IS NOT NULL OR u.role NOT IN ('owner','manager') OR p.alerts_mute_until=-1 OR p.alerts_mute_until>UNIX_TIMESTAMP()");query('DELETE FROM native_push_jobs WHERE due_at<DATE_SUB(NOW(),INTERVAL 1 DAY) OR attempts>=5');
 $db=database();$db->beginTransaction();try{
  $jobs=query("SELECT j.*,d.token,d.user_id,o.table_label,o.status FROM native_push_jobs j JOIN native_push_devices d ON d.id=j.device_id JOIN users u ON u.id=d.user_id JOIN orders o ON o.id=j.order_id LEFT JOIN staff_preferences p ON p.user_id=u.id WHERE j.due_at<=NOW() AND (j.locked_until IS NULL OR j.locked_until<NOW()) AND u.active=1 AND u.deleted_at IS NULL AND u.role IN ('owner','manager') AND (COALESCE(p.alerts_mute_until,0)=0 OR p.alerts_mute_until>0 AND p.alerts_mute_until<=UNIX_TIMESTAMP()) ORDER BY j.id LIMIT 8 FOR UPDATE")->fetchAll();
  foreach($jobs as $job)query('UPDATE native_push_jobs SET locked_until=DATE_ADD(NOW(),INTERVAL 60 SECOND) WHERE id=?',[$job['id']]);$db->commit();
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 if(!$jobs)return;
 try{$access=native_push_access_token($account);}catch(Throwable $e){foreach($jobs as $job)query('UPDATE native_push_jobs SET locked_until=NULL,attempts=attempts+1,due_at=DATE_ADD(NOW(),INTERVAL 1 MINUTE) WHERE id=?',[$job['id']]);throw $e;}
 foreach($jobs as $job){
  if($job['status']!=='pending_payment'){query('DELETE FROM native_push_jobs WHERE id=?',[$job['id']]);continue;}
  $id=(string)$job['order_id'];$payload=['message'=>['token'=>$job['token'],'notification'=>['title'=>'New order #'.str_pad($id,6,'0',STR_PAD_LEFT),'body'=>'Table '.$job['table_label'].' · Awaiting cash payment'],'data'=>['order_id'=>$id,'user_id'=>(string)$job['user_id']],'android'=>['priority'=>'HIGH','ttl'=>'7200s','notification'=>['channel_id'=>'orders','tag'=>'order-'.$id,'visibility'=>'PRIVATE']]]];
  [$code,$result]=native_push_http('https://fcm.googleapis.com/v1/projects/'.$client['projectId'].'/messages:send',json_encode($payload,JSON_THROW_ON_ERROR),['Content-Type: application/json','Authorization: Bearer '.$access]);
  if($code===200){query('DELETE FROM native_push_jobs WHERE id=?',[$job['id']]);continue;}
  $unregistered=false;foreach($result['error']['details']??[] as $detail)if(($detail['errorCode']??'')==='UNREGISTERED')$unregistered=true;
  if($unregistered){query('DELETE FROM native_push_jobs WHERE device_id=?',[$job['device_id']]);query('DELETE FROM native_push_devices WHERE id=?',[$job['device_id']]);continue;}
  query('UPDATE native_push_jobs SET locked_until=NULL,attempts=attempts+1,due_at=DATE_ADD(NOW(),INTERVAL 1 MINUTE) WHERE id=?',[$job['id']]);
 }
}
