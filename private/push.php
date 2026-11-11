<?php
declare(strict_types=1);
// Standards-based empty-body Web Push: VAPID/ES256 via OpenSSL, no unencrypted order payload.
function push_schema(): void {
 static $ready=false;if($ready)return;
 try{if(query("SELECT version FROM app_migrations WHERE version='webpush_v1'")->fetchColumn()){$ready=true;return;}}catch(PDOException $e){throw $e;}
 query('CREATE TABLE IF NOT EXISTS staff_preferences(user_id BIGINT UNSIGNED PRIMARY KEY,alerts_mute_until BIGINT NOT NULL DEFAULT 0) ENGINE=InnoDB');
 query('CREATE TABLE IF NOT EXISTS webpush_keys(id TINYINT PRIMARY KEY,public_key VARCHAR(100) NOT NULL,private_key TEXT NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
 query('CREATE TABLE IF NOT EXISTS webpush_tokens(cache_key CHAR(64) PRIMARY KEY,token TEXT NOT NULL,expires_at BIGINT NOT NULL) ENGINE=InnoDB');
 query('CREATE TABLE IF NOT EXISTS webpush_subscriptions(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,origin VARCHAR(255) NOT NULL,endpoint_hash CHAR(64) NOT NULL UNIQUE,endpoint VARCHAR(2048) NOT NULL,last_status INT NOT NULL DEFAULT 0,last_sent_at DATETIME NULL,last_seen_at DATETIME NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX(user_id),CONSTRAINT fk_webpush_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB');
 query('CREATE TABLE IF NOT EXISTS webpush_jobs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,subscription_id BIGINT UNSIGNED NOT NULL,event_key VARCHAR(100) NOT NULL,attempts INT NOT NULL DEFAULT 0,due_at DATETIME NOT NULL,expires_at DATETIME NOT NULL,locked_until DATETIME NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY(subscription_id,event_key),INDEX(due_at,locked_until),CONSTRAINT fk_webpush_subscription FOREIGN KEY(subscription_id) REFERENCES webpush_subscriptions(id) ON DELETE CASCADE) ENGINE=InnoDB');
 query('CREATE TABLE IF NOT EXISTS webpush_test_limits(user_id BIGINT UNSIGNED PRIMARY KEY,last_test BIGINT NOT NULL DEFAULT 0) ENGINE=InnoDB');
 query("INSERT IGNORE INTO app_migrations(version) VALUES('webpush_v1')");$ready=true;
}
function push_b64(string $bytes): string {return rtrim(strtr(base64_encode($bytes),'+/','-_'),'=');}
function push_unb64(string $text): string {if(!preg_match('/^[A-Za-z0-9_-]+={0,2}$/D',$text))throw new InvalidArgumentException('Invalid subscription key');$out=base64_decode(strtr($text,'-_','+/'),true);if($out===false)throw new InvalidArgumentException('Invalid subscription key');return $out;}
function push_new_keys(): array {
 if(!function_exists('openssl_pkey_new'))throw new RuntimeException('PHP OpenSSL is required for push notifications.');
 $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);if(!$key||!openssl_pkey_export($key,$pem))throw new RuntimeException('Could not create push signing keys.');
 $details=openssl_pkey_get_details($key);if(!isset($details['ec']['x'],$details['ec']['y']))throw new RuntimeException('P-256 elliptic curves are unavailable.');
 return ['public_key'=>push_b64("\x04".str_pad($details['ec']['x'],32,"\0",STR_PAD_LEFT).str_pad($details['ec']['y'],32,"\0",STR_PAD_LEFT)),'private_key'=>$pem];
}
function push_keys(bool $create=false): ?array {
 push_schema();$keys=query('SELECT public_key,private_key FROM webpush_keys WHERE id=1')->fetch();if($keys||!$create)return $keys?:null;
 $generated=push_new_keys();query('INSERT IGNORE INTO webpush_keys(id,public_key,private_key) VALUES(1,?,?)',[$generated['public_key'],$generated['private_key']]);return query('SELECT public_key,private_key FROM webpush_keys WHERE id=1')->fetch();
}
function push_endpoint(string $endpoint): string {
 if(strlen($endpoint)>2048||preg_match('/[\r\n\x00]/',$endpoint))throw new InvalidArgumentException('Invalid push endpoint');
 $url=parse_url($endpoint);$host=strtolower((string)($url['host']??''));
 $allowed=in_array($host,['fcm.googleapis.com','updates.push.services.mozilla.com','web.push.apple.com'],true)||preg_match('/^[a-z0-9-]+(?:\.[a-z0-9-]+)*\.(?:push\.services\.mozilla\.com|push\.apple\.com|notify\.windows\.com)$/D',$host);
 if(($url['scheme']??'')!=='https'||!$allowed||isset($url['user'])||isset($url['pass'])||isset($url['fragment'])||isset($url['port'])&&(int)$url['port']!==443||empty($url['path']))throw new InvalidArgumentException('Unsupported or invalid browser push endpoint');
 return 'https://'.$host;
}
function push_origin(): string {
 $host=(string)($_SERVER['HTTP_HOST']??'localhost');if(!preg_match('/^[a-zA-Z0-9.-]+(?::[0-9]{1,5})?$/D',$host))throw new RuntimeException('Invalid site host');
 $https=!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off';return ($https?'https':'http').'://'.strtolower($host);
}
function push_subject(?string $origin=null): string {
 if(defined('WEBPUSH_SUBJECT')&&preg_match('#^(?:mailto:[^\s]+@[^\s]+|https://[^\s]+)$#D',WEBPUSH_SUBJECT))return WEBPUSH_SUBJECT;
 return preg_replace('#^http://#','https://',($origin??push_origin())).'/';
}
function push_raw_signature(string $der): string {
 if(strlen($der)<8||ord($der[0])!==48||ord($der[2])!==2)throw new RuntimeException('Invalid ES256 signature');
 $rlen=ord($der[3]);$r=substr($der,4,$rlen);$offset=4+$rlen;if(!isset($der[$offset+1])||ord($der[$offset])!==2)throw new RuntimeException('Invalid ES256 signature');$slen=ord($der[$offset+1]);$s=substr($der,$offset+2,$slen);
 $r=ltrim($r,"\0");$s=ltrim($s,"\0");if(strlen($r)>32||strlen($s)>32)throw new RuntimeException('Invalid ES256 scalar');return str_pad($r,32,"\0",STR_PAD_LEFT).str_pad($s,32,"\0",STR_PAD_LEFT);
}
function push_jwt(string $audience,string $subject,array $keys,?int $now=null): string {
 $now??=time();$header=push_b64('{"typ":"JWT","alg":"ES256"}');$claims=push_b64(json_encode(['aud'=>$audience,'exp'=>$now+43200,'sub'=>$subject],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));$input=$header.'.'.$claims;
 if(!openssl_sign($input,$signature,$keys['private_key'],OPENSSL_ALGO_SHA256))throw new RuntimeException('Push signing failed');return $input.'.'.push_b64(push_raw_signature($signature));
}
function push_token(string $audience,array $keys,?string $subject=null): string {
 $subject??=push_subject();$cache=hash('sha256',$audience.'|'.$subject.'|'.$keys['public_key']);$row=query('SELECT token,expires_at FROM webpush_tokens WHERE cache_key=?',[$cache])->fetch();
 if($row&&(int)$row['expires_at']>time()+3600)return $row['token'];$token=push_jwt($audience,$subject,$keys);query('INSERT INTO webpush_tokens(cache_key,token,expires_at) VALUES(?,?,?) ON DUPLICATE KEY UPDATE token=VALUES(token),expires_at=VALUES(expires_at)',[$cache,$token,time()+43200]);return $token;
}
function push_subscribe(array $user,array $data): int {
 push_schema();$endpoint=(string)($data['endpoint']??'');push_endpoint($endpoint);$keys=$data['keys']??[];$point=push_unb64((string)($keys['p256dh']??''));$auth=push_unb64((string)($keys['auth']??''));if(strlen($point)!==65||$point[0]!=="\x04"||strlen($auth)!==16)throw new InvalidArgumentException('Invalid browser subscription');
 $server=push_keys(true);if(!hash_equals($server['public_key'],(string)($data['public_key']??'')))throw new InvalidArgumentException('Push key changed. Turn notifications off and enable again.');
 $hash=hash('sha256',$endpoint);$db=database();$db->beginTransaction();try{
  query('SELECT id FROM users WHERE id=? FOR UPDATE',[$user['id']]);$existing=query('SELECT id,user_id FROM webpush_subscriptions WHERE endpoint_hash=? FOR UPDATE',[$hash])->fetch();
  if((!$existing||(int)$existing['user_id']!==(int)$user['id'])&&(int)query('SELECT COUNT(*) FROM webpush_subscriptions WHERE user_id=?',[$user['id']])->fetchColumn()>=8)throw new RuntimeException('This account already has eight notification devices.');
  if($existing&&(int)$existing['user_id']!==(int)$user['id'])query('DELETE FROM webpush_jobs WHERE subscription_id=?',[$existing['id']]);
  query('INSERT INTO webpush_subscriptions(user_id,origin,endpoint_hash,endpoint,last_seen_at) VALUES(?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),origin=VALUES(origin),endpoint=VALUES(endpoint),last_seen_at=NOW()',[$user['id'],push_origin(),$hash,$endpoint]);
  $id=(int)query('SELECT id FROM webpush_subscriptions WHERE endpoint_hash=?',[$hash])->fetchColumn();$db->commit();return $id;
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function push_unsubscribe(int $userId,string $endpoint): void {push_schema();query('DELETE FROM webpush_subscriptions WHERE user_id=? AND endpoint_hash=?',[$userId,hash('sha256',$endpoint)]);}
// Enqueue inside the same order transaction. Keys/endpoints stay on this server.
function push_enqueue_order(int $orderId): int {
 push_schema();return query("INSERT IGNORE INTO webpush_jobs(subscription_id,event_key,due_at,expires_at) SELECT s.id,?,NOW(),DATE_ADD(NOW(),INTERVAL 2 HOUR) FROM webpush_subscriptions s JOIN users u ON u.id=s.user_id LEFT JOIN staff_preferences p ON p.user_id=u.id WHERE s.origin=? AND u.active=1 AND u.deleted_at IS NULL AND u.role IN ('owner','manager') AND (COALESCE(p.alerts_mute_until,0)=0 OR p.alerts_mute_until>0 AND p.alerts_mute_until<=UNIX_TIMESTAMP())",['order:'.$orderId,push_origin()])->rowCount();
}
function push_transport_problems(): array {
 $missing=[];foreach(['curl_init','curl_setopt_array','curl_exec','curl_errno','curl_getinfo','curl_close'] as $fn)if(!function_exists($fn))$missing[]=$fn;
 return $missing?['Hosting has disabled required PHP cURL functions: '.implode(', ',$missing).'. Ask the host to enable them.']:[];
}
function push_http_batch(array $requests): array {
 if(push_transport_problems())throw new RuntimeException(implode(' ',push_transport_problems()));
 $parallel=true;foreach(['curl_multi_init','curl_multi_add_handle','curl_multi_exec','curl_multi_select','curl_multi_remove_handle','curl_multi_close'] as $fn)if(!function_exists($fn))$parallel=false;
 if(!$parallel){$results=[];foreach($requests as $id=>$request){$handle=curl_init($request['endpoint']);curl_setopt_array($handle,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>'',CURLOPT_HTTPHEADER=>$request['headers'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT_MS=>1000,CURLOPT_TIMEOUT_MS=>2500,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);curl_exec($handle);$results[$id]=['status'=>curl_errno($handle)?0:(int)curl_getinfo($handle,CURLINFO_HTTP_CODE)];curl_close($handle);}return $results;}
 $multi=curl_multi_init();$handles=[];$results=[];
 foreach($requests as $id=>$request){$handle=curl_init($request['endpoint']);curl_setopt_array($handle,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>'',CURLOPT_HTTPHEADER=>$request['headers'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT_MS=>1000,CURLOPT_TIMEOUT_MS=>2500,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);curl_multi_add_handle($multi,$handle);$handles[$id]=$handle;}
 do{$code=curl_multi_exec($multi,$running);if($running)curl_multi_select($multi,0.2);}while($running&&$code===CURLM_OK);
 foreach($handles as $id=>$handle){$results[$id]=['status'=>curl_errno($handle)?0:(int)curl_getinfo($handle,CURLINFO_HTTP_CODE)];curl_multi_remove_handle($multi,$handle);curl_close($handle);}curl_multi_close($multi);return $results;
}
function push_dispatch(?int $onlySubscription=null,?callable $sender=null): array {
 push_schema();$stats=['accepted'=>0,'retry'=>0,'expired'=>0,'failed'=>0];$keys=push_keys();if(!$keys)return $stats;
 query('DELETE FROM webpush_jobs WHERE expires_at<=NOW()');
 query("DELETE j FROM webpush_jobs j JOIN webpush_subscriptions s ON s.id=j.subscription_id JOIN users u ON u.id=s.user_id LEFT JOIN staff_preferences p ON p.user_id=u.id WHERE u.active=0 OR u.deleted_at IS NOT NULL OR u.role NOT IN ('owner','manager') OR p.alerts_mute_until=-1 OR p.alerts_mute_until>UNIX_TIMESTAMP()");
 $db=database();$db->beginTransaction();try{$where=$onlySubscription===null?'':' AND s.id='.(int)$onlySubscription;$jobs=query('SELECT j.*,s.endpoint,s.origin FROM webpush_jobs j JOIN webpush_subscriptions s ON s.id=j.subscription_id WHERE j.due_at<=NOW() AND (j.locked_until IS NULL OR j.locked_until<NOW())'.$where.' ORDER BY j.id LIMIT 16 FOR UPDATE')->fetchAll();foreach($jobs as $job)query('UPDATE webpush_jobs SET locked_until=DATE_ADD(NOW(),INTERVAL 30 SECOND) WHERE id=?',[$job['id']]);$db->commit();}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 $requests=[];foreach($jobs as $job){try{$aud=push_endpoint($job['endpoint']);$ttl=max(1,min(7200,(int)query('SELECT TIMESTAMPDIFF(SECOND,NOW(),expires_at) FROM webpush_jobs WHERE id=?',[$job['id']])->fetchColumn()));$requests[$job['id']]=['endpoint'=>$job['endpoint'],'headers'=>['TTL: '.$ttl,'Urgency: high','Topic: gangchil-orders','Content-Length: 0','Content-Type:','Expect:','Authorization: vapid t='.push_token($aud,$keys,push_subject($job['origin'])).', k='.$keys['public_key']]];}catch(Throwable $e){$requests[$job['id']]=null;}}
 $valid=array_filter($requests);$responses=[];if($valid){try{$responses=$sender?$sender($valid):push_http_batch($valid);}catch(Throwable $e){foreach($jobs as $job)query('UPDATE webpush_jobs SET locked_until=NULL WHERE id=?',[$job['id']]);throw new RuntimeException('Push transport failed. Check PHP cURL functions, outbound HTTPS and the hosting error log.',0,$e);}}
 foreach($jobs as $job){$status=(int)($responses[$job['id']]['status']??0);query('UPDATE webpush_subscriptions SET last_status=?,last_sent_at=NOW() WHERE id=?',[$status,$job['subscription_id']]);
  if($status>=200&&$status<300){query('DELETE FROM webpush_jobs WHERE id=?',[$job['id']]);$stats['accepted']++;}
  elseif(in_array($status,[404,410],true)){query('DELETE FROM webpush_subscriptions WHERE id=?',[$job['subscription_id']]);$stats['expired']++;}
  elseif(($status===0||$status===429||$status>=500)&&(int)$job['attempts']<4){$delay=min(1800,60*(2**(int)$job['attempts']));query('UPDATE webpush_jobs SET attempts=attempts+1,locked_until=NULL,due_at=DATE_ADD(NOW(),INTERVAL '.$delay.' SECOND) WHERE id=?',[$job['id']]);$stats['retry']++;}
  else{query('DELETE FROM webpush_jobs WHERE id=?',[$job['id']]);$stats['failed']++;}
 }
 return $stats;
}
