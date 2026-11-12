<?php
declare(strict_types=1);
/* Shared queue: queued -> claimed -> dispatching -> submitted|unknown.
   Only claimed work can expire safely. dispatching never auto-retries. */
function print_queue_schema(): void {
 static $ready=false;if($ready)return;if(query("SELECT version FROM app_migrations WHERE version='shared_print_queue_v1'")->fetchColumn()){$ready=true;return;}
 query("CREATE TABLE IF NOT EXISTS print_destinations(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(100) NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
 query("CREATE TABLE IF NOT EXISTS print_devices(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,device_key VARCHAR(80) NOT NULL UNIQUE,name VARCHAR(100) NOT NULL,token_hash CHAR(64) NOT NULL UNIQUE,printer_id BIGINT UNSIGNED NULL,enabled TINYINT NOT NULL DEFAULT 1,last_seen DATETIME NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX(printer_id)) ENGINE=InnoDB");
 query("CREATE TABLE IF NOT EXISTS receipt_jobs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,copy_number INT NOT NULL DEFAULT 0,printer_id BIGINT UNSIGNED NULL,status ENUM('queued','claimed','dispatching','submitted','unknown') NOT NULL DEFAULT 'queued',device_id BIGINT UNSIGNED NULL,claim_token CHAR(64) NULL,lease_until DATETIME NULL,detail VARCHAR(250) NOT NULL DEFAULT '',created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY order_copy(order_id,copy_number),INDEX(status,printer_id,id)) ENGINE=InnoDB");
 query("INSERT IGNORE INTO print_destinations(id,name) VALUES(1,'Counter receipt printer')");query("INSERT IGNORE INTO app_migrations(version) VALUES('shared_print_queue_v1')");$ready=true;
}
function print_pair_schema(): void {
 static $ready=false;if($ready)return;print_queue_schema();
 query("CREATE TABLE IF NOT EXISTS print_pairings(device_key VARCHAR(80) PRIMARY KEY,name VARCHAR(100) NOT NULL,proof_hash CHAR(64) NOT NULL,code CHAR(6) NOT NULL,expires_at DATETIME NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
 if(!query("SELECT version FROM app_migrations WHERE version='print_connection_v2'")->fetchColumn()){
  if(!(int)query("SELECT GET_LOCK('gangchil-print-connection-v2',10)")->fetchColumn())fail('Printer setup busy. Try again.',503);try{
  $columns=query('SHOW COLUMNS FROM print_devices')->fetchAll(PDO::FETCH_COLUMN);
  if(!in_array('connection_state',$columns,true))query("ALTER TABLE print_devices ADD connection_state VARCHAR(12) NOT NULL DEFAULT 'offline', ADD connection_at DATETIME NULL");
  query("INSERT IGNORE INTO app_migrations(version) VALUES('print_connection_v2')");
  }finally{query("SELECT RELEASE_LOCK('gangchil-print-connection-v2')");}
 }$ready=true;
}
function print_online_sql(): string {return "d.enabled=1 AND d.connection_state='online' AND d.connection_at>DATE_SUB(NOW(),INTERVAL 20 SECOND) AND u.active=1 AND u.deleted_at IS NULL AND u.role IN ('owner','manager') AND (u.role='owner' OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(u.permissions,'$.\"orders:pay\"')),'true')<>'false')";}
function print_enqueue(int $order): void {query('INSERT IGNORE INTO receipt_jobs(order_id,printer_id) VALUES(?,1)',[$order]);}
function print_device(): array {
 $token=(string)($_SERVER['HTTP_X_PRINT_TOKEN']??'');if(!preg_match('/^[a-f0-9]{64}$/D',$token))fail('Print device authentication required',401);
 $d=query('SELECT d.*,u.active,u.role,u.permissions,u.deleted_at FROM print_devices d JOIN users u ON u.id=d.user_id WHERE d.token_hash=?',[hash('sha256',$token)])->fetch();
 if(!$d||!$d['enabled']||!$d['active']||$d['deleted_at']||!in_array($d['role'],['owner','manager'],true))fail('Print device access revoked',403);
 $p=json_decode((string)$d['permissions'],true)?:[];if($d['role']!=='owner'&&array_key_exists('orders:pay',$p)&&!$p['orders:pay'])fail('Cash access revoked',403);
 query('UPDATE print_devices SET last_seen=NOW() WHERE id=?',[$d['id']]);return $d;
}
function print_routes(string $route,string $method,?array $input=null): void {
 if(!str_starts_with($route,'printing/'))return;
 print_pair_schema();header('Cache-Control: no-store');
 if($route==='printing/status'&&$method==='GET')json_response(['online'=>(bool)query('SELECT 1 FROM print_devices d JOIN users u ON u.id=d.user_id WHERE '.print_online_sql().' LIMIT 1')->fetchColumn()]);
 if($route==='printing/pair'&&$method==='POST'){
  require_csrf();$d=$input??request_data();$key=(string)($d['device_key']??'');$proof=(string)($d['proof']??'');$name=trim_text($d['name']??'',100);
  if(!preg_match('/^[A-Za-z0-9-]{20,80}$/D',$key)||!preg_match('/^[a-f0-9]{64}$/D',$proof)||!$name)fail('Invalid device');
  query('DELETE FROM print_pairings WHERE expires_at<NOW()');
  $existing=query('SELECT * FROM print_pairings WHERE device_key=?',[$key])->fetch();
  if($existing&&!hash_equals($existing['proof_hash'],hash('sha256',$proof)))fail('Device request already exists',409);
  if(!$existing){if((int)query('SELECT COUNT(*) FROM print_pairings')->fetchColumn()>=30)fail('Too many setup requests. Try later.',429);$code=strtoupper(substr(bin2hex(random_bytes(4)),0,6));query('INSERT INTO print_pairings(device_key,name,proof_hash,code,expires_at) VALUES(?,?,?,?,DATE_ADD(NOW(),INTERVAL 10 MINUTE))',[$key,$name,hash('sha256',$proof),$code]);}else $code=$existing['code'];
  json_response(['code'=>$code,'pending'=>true]);
 }
 if($route==='printing/pair-status'&&$method==='GET'){
  $proof=(string)($_SERVER['HTTP_X_PRINT_PAIR']??'');$key=(string)($_SERVER['HTTP_X_PRINT_DEVICE']??'');if(!preg_match('/^[a-f0-9]{64}$/D',$proof))fail('Device proof required',401);
  $approved=query('SELECT d.enabled,u.active,u.deleted_at,u.role FROM print_devices d JOIN users u ON u.id=d.user_id WHERE d.device_key=? AND d.token_hash=?',[$key,hash('sha256',$proof)])->fetch();
  json_response(['approved'=>(bool)($approved&&$approved['enabled']&&$approved['active']&&!$approved['deleted_at']&&in_array($approved['role'],['owner','manager'],true))]);
 }

 if(str_starts_with($route,'printing/worker/')){
  $device=print_device();$d=$method==='POST'?($input??request_data()):[];
  if($route==='printing/worker/heartbeat'&&$method==='POST'){query('UPDATE print_devices SET connection_state=?,connection_at=NOW() WHERE id=?',[($d['online']??false)===true?'online':'offline',$device['id']]);json_response(['ok'=>true]);}
  if($route==='printing/worker/claim'&&$method==='POST'){
   $db=database();$db->beginTransaction();try{
    // Serialize only queue selection; expired pre-dispatch claims are safe to recover.
    $dest=query('SELECT id FROM print_destinations WHERE id=? FOR UPDATE',[$device['printer_id']])->fetch();
    if(!$dest){$db->commit();json_response(['job'=>null,'message'=>'No printer assigned']);}
    query("UPDATE receipt_jobs SET status='queued',device_id=NULL,claim_token=NULL,lease_until=NULL WHERE printer_id=? AND status='claimed' AND lease_until<NOW()",[$device['printer_id']]);
    // Unknown or in-flight delivery blocks this physical printer, avoiding overlap.
    $blocked=query("SELECT id FROM receipt_jobs WHERE printer_id=? AND status IN ('claimed','dispatching','unknown') LIMIT 1",[$device['printer_id']])->fetch();
    $job=$blocked?false:query("SELECT * FROM receipt_jobs WHERE printer_id=? AND status='queued' ORDER BY id LIMIT 1 FOR UPDATE",[$device['printer_id']])->fetch();
    if(!$job){$db->commit();json_response(['job'=>null,'blocked'=>(bool)$blocked]);}
    $claim=bin2hex(random_bytes(32));query("UPDATE receipt_jobs SET status='claimed',device_id=?,claim_token=?,lease_until=DATE_ADD(NOW(),INTERVAL 90 SECOND) WHERE id=?",[$device['id'],$claim,$job['id']]);
    $order=query('SELECT * FROM orders WHERE id=?',[$job['order_id']])->fetch();$items=query('SELECT product_name,quantity,unit_price,subtotal FROM order_items WHERE order_id=? ORDER BY id',[$job['order_id']])->fetchAll();
    $db->commit();json_response(['job'=>['id'=>(int)$job['id'],'order_id'=>(int)$job['order_id'],'claim'=>$claim,'order'=>$order,'items'=>$items,'html'=>receipt_html($order,$items)]]);
   }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
  }
  if($route==='printing/worker/recover'&&$method==='POST'){
   $id=integer_id($d['id']??null);$claim=(string)($d['claim']??'');$db=database();$db->beginTransaction();try{
    $j=query('SELECT * FROM receipt_jobs WHERE id=? FOR UPDATE',[$id])->fetch();
    if(!$j||(int)$j['device_id']!==(int)$device['id']||!hash_equals((string)$j['claim_token'],$claim)){$db->commit();json_response(['resolved'=>true]);}
    if($j['status']==='claimed'){query("UPDATE receipt_jobs SET status='queued',device_id=NULL,claim_token=NULL,lease_until=NULL WHERE id=?",[$id]);}
    elseif($j['status']==='dispatching'){query("UPDATE receipt_jobs SET status='unknown',detail='App restarted during delivery. Check paper.' WHERE id=?",[$id]);}
    $db->commit();json_response(['resolved'=>true]);
   }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
  }
  if(in_array($route,['printing/worker/begin','printing/worker/result'],true)&&$method==='POST'){
   $id=integer_id($d['id']??null);$claim=(string)($d['claim']??'');$db=database();$db->beginTransaction();try{
    $j=query('SELECT *, (lease_until>=NOW()) AS lease_valid FROM receipt_jobs WHERE id=? FOR UPDATE',[$id])->fetch();if(!$j||(int)$j['device_id']!==(int)$device['id']||!hash_equals((string)$j['claim_token'],$claim))fail('Print claim lost',409);
    if($route==='printing/worker/begin'){
     // A duplicate begin must NOT authorize a second physical write.
     if($j['status']!=='claimed'||!$j['lease_valid'])fail('Claim expired or already dispatched',409);
     query("UPDATE receipt_jobs SET status='dispatching',lease_until=NULL WHERE id=?",[$id]);
    }else{
     $result=(string)($d['status']??'');if(!in_array($result,['submitted','unknown'],true))fail('Invalid print result');
     if($j['status']==='submitted'&&$result==='submitted'){$db->commit();json_response(['ok'=>true]);}
     if($j['status']!=='dispatching')fail('Delivery already resolved',409);
     query('UPDATE receipt_jobs SET status=?,detail=? WHERE id=?',[$result,substr((string)($d['detail']??''),0,250),$id]);
     if($result==='submitted'){query("UPDATE orders SET status='completed',receipt_submitted_at=COALESCE(receipt_submitted_at,NOW()),receipt_submission_job=? WHERE id=? AND paid_at IS NOT NULL",['queue-'.$id,$j['order_id']]);audit((int)$device['user_id'],'receipt.submitted','order',(int)$j['order_id']);}
    }$db->commit();json_response(['ok'=>true]);
   }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
  }
  fail('Unknown print worker action',404);
 }
 require_permission('orders:pay');if($method!=='GET')require_csrf();
 if($route==='printing/settings'&&$method==='GET'){
  json_response(['printers'=>query('SELECT * FROM print_destinations ORDER BY id')->fetchAll(),'pairing_requests'=>query('SELECT device_key,name,code,expires_at FROM print_pairings WHERE expires_at>NOW() ORDER BY created_at')->fetchAll(),'devices'=>query("SELECT id,name,printer_id,last_seen,enabled,connection_state,(connection_state='online' AND connection_at>DATE_SUB(NOW(),INTERVAL 20 SECOND)) AS connected FROM print_devices ORDER BY id")->fetchAll(),'pending'=>query("SELECT j.id,j.order_id,j.status,j.detail,j.device_id,d.name AS device_name,j.updated_at FROM receipt_jobs j LEFT JOIN print_devices d ON d.id=j.device_id WHERE j.status<>'submitted' ORDER BY j.id LIMIT 100")->fetchAll()]);
 }
 if($route==='printing/approve'&&$method==='POST'){
  $u=require_permission('orders:pay');$d=$input??request_data();$key=(string)($d['device_key']??'');$db=database();$db->beginTransaction();try{
   $pair=query('SELECT * FROM print_pairings WHERE device_key=? AND expires_at>NOW() FOR UPDATE',[$key])->fetch();if(!$pair)fail('Setup request expired',409);
   query("INSERT INTO print_devices(user_id,device_key,name,token_hash,printer_id) VALUES(?,?,?,?,1) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),name=VALUES(name),token_hash=VALUES(token_hash),enabled=1,connection_state='offline',connection_at=NULL",[$u['id'],$key,$pair['name'],$pair['proof_hash']]);
   query('DELETE FROM print_pairings WHERE device_key=?',[$key]);audit((int)$u['id'],'printer.approved','device',$key);$db->commit();json_response(['ok'=>true]);
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 }
 if($route==='printing/register' &&$method==='POST'){
  $u=require_permission('orders:pay');$d=$input??request_data();$key=(string)($d['device_key']??'');if(!preg_match('/^[A-Za-z0-9-]{20,80}$/D',$key))fail('Invalid device');$name=trim_text($d['name']??'',100);if(!$name)fail('Device name required');
  $token=bin2hex(random_bytes(32));query('INSERT INTO print_devices(user_id,device_key,name,token_hash,printer_id) VALUES(?,?,?,?,1) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),name=VALUES(name),token_hash=VALUES(token_hash),enabled=1',[$u['id'],$key,$name,hash('sha256',$token)]);
  json_response(['token'=>$token,'printer_id'=>1]);
 }
 if($route==='printing/device'&&$method==='POST'){
  require_permission('orders:pay');$d=$input??request_data();$id=integer_id($d['id']??null);query("UPDATE print_devices SET enabled=?,connection_state='offline',connection_at=NULL WHERE id=?",[!empty($d['enabled'])?1:0,$id]);json_response(['ok'=>true]);
 }
 if($route==='printing/resolve'&&$method==='POST'){
  $u=require_permission('orders:pay');$d=$input??request_data();$id=integer_id($d['id']??null);$action=(string)($d['action']??'');if(!in_array($action,['printed','retry'],true)||($d['checked_paper']??false)!==true)fail('Check the paper before resolving this receipt.');
  $db=database();$db->beginTransaction();try{$j=query('SELECT *, (lease_until>=NOW()) AS lease_valid FROM receipt_jobs WHERE id=? FOR UPDATE',[$id])->fetch();if(!$j||!in_array($j['status'],['unknown','dispatching'],true))fail('Receipt is not awaiting review',409);
   // Do not reset the old claim: make a distinct copy for explicit retries.
   query("UPDATE receipt_jobs SET status='submitted',detail=? WHERE id=?",[$action==='printed'?'Paper checked: printed':'Paper checked: retry requested',$id]);
   if($action==='retry'){$copy=1+(int)query('SELECT MAX(copy_number) FROM receipt_jobs WHERE order_id=?',[$j['order_id']])->fetchColumn();query('INSERT INTO receipt_jobs(order_id,copy_number,printer_id) VALUES(?,?,?)',[$j['order_id'],$copy,$j['printer_id']]);}
   else query("UPDATE orders SET status='completed',receipt_printed_at=NOW(),receipt_printed_by=? WHERE id=?",[$u['id'],$j['order_id']]);
   audit((int)$u['id'],'receipt.'.$action,'order',(int)$j['order_id']);$db->commit();json_response(['ok'=>true]);
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 }
 if($route==='printing/reprint'&&$method==='POST'){
  $u=require_permission('orders:pay');$d=$input??request_data();$id=integer_id($d['order_id']??null);$db=database();$db->beginTransaction();try{$o=query('SELECT id,paid_at FROM orders WHERE id=? FOR UPDATE',[$id])->fetch();if(!$o||!$o['paid_at'])fail('Confirm cash first',409);if(($d['confirm_copy']??false)!==true)fail('Confirm another receipt copy');$copy=1+(int)query('SELECT MAX(copy_number) FROM receipt_jobs WHERE order_id=?',[$id])->fetchColumn();query('INSERT INTO receipt_jobs(order_id,copy_number,printer_id) VALUES(?,?,1)',[$id,$copy]);audit((int)$u['id'],'receipt.reprint','order',$id);$db->commit();json_response(['ok'=>true]);}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 }
 fail('Unknown printer action',404);
}
