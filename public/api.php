<?php
declare(strict_types=1);
require_once is_file(__DIR__.'/private/bootstrap.php') ? __DIR__.'/private/bootstrap.php' : __DIR__.'/../private/bootstrap.php';
require_once is_file(__DIR__.'/private/realtime.php') ? __DIR__.'/private/realtime.php' : __DIR__.'/../private/realtime.php';
require_once is_file(__DIR__.'/private/operations.php') ? __DIR__.'/private/operations.php' : __DIR__.'/../private/operations.php';
require_once is_file(__DIR__.'/private/push.php') ? __DIR__.'/private/push.php' : __DIR__.'/../private/push.php';
require_once is_file(__DIR__.'/private/print_queue.php') ? __DIR__.'/private/print_queue.php' : __DIR__.'/../private/print_queue.php';
require_once is_file(__DIR__.'/private/native_push.php') ? __DIR__.'/private/native_push.php' : __DIR__.'/../private/native_push.php';
date_default_timezone_set('Asia/Dhaka');
header('X-Content-Type-Options: nosniff');
$route=trim((string)($_GET['route']??''),'/');
$method=$_SERVER['REQUEST_METHOD']??'GET';

try {
 $hasUsers=(bool)query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='users'")->fetchColumn();
 $installed=$hasUsers&&(int)query('SELECT COUNT(*) FROM users')->fetchColumn()>0;
 if($route==='health'&&$method==='GET')json_response(['ok'=>true,'installed'=>$installed]);
 if(!$installed){
  if($route==='auth/me'&&$method==='GET')json_response(['user'=>null,'csrf'=>csrf_token(),'installation_required'=>true]);
  if($route==='menu'&&$method==='GET')json_response(['categories'=>[],'products'=>[]]);
  json_response(['error'=>'Owner setup is required.','installation_required'=>true],503);
 }
 ensure_phone_schema();
 ensure_pin_schema();
 ensure_service_schema();
 ensure_cash_workflow();
 if(STAFF_SURFACE){ensure_thermal_schema();print_queue_schema();}
 ensure_product_lifecycle();
 if(STAFF_SURFACE)ensure_operations_schema();
 if(STAFF_SURFACE||$route==='customer/orders'){push_schema();native_push_schema();}
 if(!STAFF_SURFACE&&!in_array($route,['health','auth/me','menu','customer/orders'],true))fail('Staff sign-in required',401);
 if($route==='health' && $method==='GET'){
  query('SELECT 1');json_response(['ok'=>true]);
 }
 if($route==='auth/me' && $method==='GET'){
  json_response(['user'=>current_user(),'csrf'=>csrf_token()]);
 }
 if($route==='push/config'&&$method==='GET'){
  $u=require_permission('orders:view');$problems=[];$public='';if(!function_exists('openssl_pkey_new'))$problems[]='PHP OpenSSL is unavailable.';$problems=array_merge($problems,push_transport_problems());
  if(function_exists('openssl_pkey_new')){try{$keys=push_keys(true);$public=$keys['public_key'];}catch(Throwable $e){$problems[]='The server could not create P-256 push keys.';}}
  $last=query('SELECT last_status,last_sent_at FROM webpush_subscriptions WHERE user_id=? AND origin=? ORDER BY last_sent_at DESC LIMIT 1',[$u['id'],push_origin()])->fetch();
  json_response(['ready'=>!$problems,'public_key'=>$public,'problems'=>$problems,'devices'=>(int)query('SELECT COUNT(*) FROM webpush_subscriptions WHERE user_id=? AND origin=?',[$u['id'],push_origin()])->fetchColumn(),'last_delivery'=>$last?:null,'queued'=>(int)query('SELECT COUNT(*) FROM webpush_jobs j JOIN webpush_subscriptions s ON s.id=j.subscription_id WHERE s.user_id=?',[$u['id']])->fetchColumn()]);
 }
 if($route==='realtime/config' && $method==='GET'){
  require_permission('orders:view');
  json_response(['enabled'=>realtime_enabled(),
   'key'=>realtime_enabled()?PUSHER_KEY:null,
   'cluster'=>realtime_enabled()?PUSHER_CLUSTER:null]);
 }
 native_push_routes($route,$method);
 print_routes($route,$method);
 if($method!=='GET')require_csrf();
 if($route==='push/subscribe'&&$method==='POST'){
  $u=require_permission('orders:view');try{$id=push_subscribe($u,request_data());json_response(['ok'=>true,'device_id'=>$id]);}catch(PDOException $e){throw $e;}catch(InvalidArgumentException|RuntimeException $e){fail($e->getMessage());}
 }
 if($route==='push/unsubscribe'&&$method==='POST'){
  $u=require_permission('orders:view');$d=request_data();$endpoint=(string)($d['endpoint']??'');if(strlen($endpoint)>2048)fail('Invalid device');push_unsubscribe((int)$u['id'],$endpoint);json_response(['ok'=>true]);
 }
 if($route==='push/test'&&$method==='POST'){
  $u=require_permission('orders:view');$d=request_data();$id=(int)query('SELECT id FROM webpush_subscriptions WHERE user_id=? AND endpoint_hash=?',[$u['id'],hash('sha256',(string)($d['endpoint']??''))])->fetchColumn();if(!$id)fail('Enable notifications on this device first.');
  $db=database();$db->beginTransaction();try{query('INSERT IGNORE INTO webpush_test_limits(user_id) VALUES(?)',[$u['id']]);$last=(int)query('SELECT last_test FROM webpush_test_limits WHERE user_id=? FOR UPDATE',[$u['id']])->fetchColumn();if(time()-$last<30)fail('Wait 30 seconds before another test.',429);query('UPDATE webpush_test_limits SET last_test=? WHERE user_id=?',[time(),$u['id']]);$db->commit();}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
  if($problems=push_transport_problems())fail(implode(' ',$problems),503);
  query('INSERT INTO webpush_jobs(subscription_id,event_key,due_at,expires_at) VALUES(?,?,NOW(),DATE_ADD(NOW(),INTERVAL 10 MINUTE))',[$id,'test:'.bin2hex(random_bytes(8))]);try{$result=push_dispatch($id);}catch(PDOException $e){throw $e;}catch(Throwable $e){error_log('[GANGCHIL] Push test failed: '.$e->getMessage());fail($e->getMessage(),503);}json_response(['accepted'=>$result['accepted']>0,'result'=>$result]);
 }
 if($route==='realtime/auth' && $method==='POST'){
  require_permission('orders:view');
  $socket=(string)($_POST['socket_id']??'');
  $channel=(string)($_POST['channel_name']??'');
  if(!realtime_enabled())fail('Realtime unavailable',503);
  try {json_response(realtime_private_auth($socket,$channel));}
  catch(InvalidArgumentException $e){fail('Invalid realtime request',403);}
 }
 if($route==='auth/login' && $method==='POST'){
  $d=request_data();$phone=bd_phone($d['phone']??'');$pin=$d['pin']??'';
  if($phone===null||!valid_pin($pin))fail('Enter your Bangladeshi mobile number and 4-digit PIN.');
  consume_pin_attempt('ip:'.($_SERVER['REMOTE_ADDR']??'unknown'),30);
  $key=consume_pin_attempt('login:'.$phone);
  $u=query('SELECT id,active,pin_hash,role FROM users WHERE phone=? LIMIT 1',[$phone])->fetch();
  if(!$u||!$u['active']||!in_array($u['role'],['owner','manager'],true)||!$u['pin_hash']||!password_verify($pin,$u['pin_hash']))fail('Mobile number or PIN is incorrect.',401);
  clear_pin_attempts($key);session_regenerate_id(true);$_SESSION['uid']=(int)$u['id'];$_SESSION['csrf']=bin2hex(random_bytes(32));
  json_response(['user'=>current_user(),'csrf'=>csrf_token()]);
 }
 if($route==='auth/logout' && $method==='POST'){
  $_SESSION=[];session_regenerate_id(true);
  json_response(['ok'=>true,'csrf'=>csrf_token()]);
 }
 if($route==='auth/access' && $method==='POST'){
  $u=current_user();if(!$u)fail('Login required',401);$d=request_data();$phone=bd_phone($d['phone']??'');
  if($phone===null)fail('Enter a valid Bangladeshi mobile number.');
  $hash=query('SELECT pin_hash FROM users WHERE id=?',[$u['id']])->fetchColumn();
  $key=consume_pin_attempt('access:'.$u['id']);
  if($hash&&(!valid_pin($d['pin']??null)||!password_verify($d['pin'],$hash)))fail('Current PIN is incorrect.');
  $newPin=$d['new_pin']??'';
  if((!$hash||$newPin!=='')&&!valid_pin($newPin))fail('Choose a PIN with exactly 4 digits.');
  $newHash=$newPin!==''?password_hash($newPin,PASSWORD_DEFAULT):$hash;
  query('UPDATE users SET phone=?,pin_hash=? WHERE id=?',[$phone,$newHash,$u['id']]);clear_pin_attempts($key);
  session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));
  audit((int)$u['id'],'staff.access','user',$u['id']);json_response(['user'=>current_user(),'csrf'=>csrf_token()]);
 }
 if($route==='menu' && $method==='GET'){
  json_response([
   'categories'=>query('SELECT id,name,sort_order,active FROM categories WHERE active=1 ORDER BY sort_order,id')->fetchAll(),
   'products'=>query('SELECT id,name,description,price,image_url,category_id,available,published FROM products WHERE deleted_at IS NULL'.(STAFF_SURFACE&&current_user()?'':' AND published=1').' ORDER BY id DESC')->fetchAll()
  ]);
 }
 if(preg_match('#^orders/([1-9][0-9]*)$#',$route,$m)&&$method==='GET'){
  $viewer=require_permission('orders:view');$id=integer_id($m[1]);$order=query('SELECT * FROM orders WHERE id=?',[$id])->fetch();if(!$order)fail('Order not found',404);
  $order['items']=query('SELECT product_name AS name,quantity,unit_price AS price FROM order_items WHERE order_id=? ORDER BY id',[$id])->fetchAll();
  json_response(['order'=>$order]);
 }
 if($route==='orders' && $method==='GET'){
  $viewer=require_permission('orders:view');$status=(string)($_GET['status']??'');
  $valid=['','pending_payment','confirmed','completed'];
  if(!in_array($status,$valid,true))fail('Invalid status');
  if($status===''){$orders=query("SELECT * FROM orders WHERE status IN ('pending_payment','confirmed') UNION ALL SELECT * FROM (SELECT * FROM orders WHERE status='completed' ORDER BY id DESC LIMIT 50) AS recent_closed ORDER BY id DESC")->fetchAll();}
  else $orders=query('SELECT * FROM orders WHERE status=? ORDER BY id DESC LIMIT 150',[$status])->fetchAll();
  if($orders){
   $ids=array_map(fn($o)=>(int)$o['id'],$orders);
   $holders=implode(',',array_fill(0,count($ids),'?'));
   $items=query('SELECT order_id,product_name AS name,quantity,unit_price AS price FROM order_items WHERE order_id IN ('.$holders.') ORDER BY id',$ids)->fetchAll();
   $by=[];foreach($items as $item){$by[(int)$item['order_id']][]=$item;}
   foreach($orders as &$o){$o['items']=$by[(int)$o['id']]??[];}unset($o);
  }
  json_response(['orders'=>$orders]);
 }
 if(in_array($route,['orders','customer/orders'],true) && $method==='POST'){
  $customerOrder=$route==='customer/orders';
  $u=$customerOrder?null:require_permission('orders:create');$d=request_data();
  $token=trim_text($d['request_token']??'',80);
  if(!preg_match('/^[a-zA-Z0-9_-]{20,80}$/',$token))fail('Invalid request token');
  $rawItems=$d['items']??null;
  if(!is_array($rawItems)||!$rawItems||count($rawItems)>40)fail('Invalid cart');
  $quantities=[];
  foreach($rawItems as $raw){
   if(!is_array($raw))fail('Invalid cart item');
   $pid=integer_id($raw['product_id']??null);$qty=$raw['quantity']??null;
   if(!is_int($qty)||$qty<1||$qty>30)fail('Invalid quantity');
   $quantities[$pid]=($quantities[$pid]??0)+$qty;
   if($quantities[$pid]>100)fail('Too many items');
  }
  $name=$customerOrder?'':trim_text($d['customer_name']??'',100);$notes=trim_text($d['notes']??'',500);$rawTable=trim_text($d['table_label']??'',40);$table=table_number($rawTable);
  $db=database();$db->beginTransaction();
  try {
   $existing=query('SELECT id,status,total,created_at FROM orders WHERE request_token=?',[$token])->fetch();
   if($existing){$db->commit();json_response(['order'=>$existing,'duplicate'=>true]);}
   if($table==='')fail('Choose a table from 1 to 5 before placing the order.');
   if($customerOrder){
    $window=$_SESSION['customer_order_window']??['start'=>time(),'count'=>0];
    if(time()-(int)$window['start']>=60)$window=['start'=>time(),'count'=>0];
    if((int)$window['count']>=12)fail('Please wait a moment before placing another order.',429);
   }
   $ids=array_keys($quantities);$holder=implode(',',array_fill(0,count($ids),'?'));
   $products=query('SELECT id,name,price,available FROM products WHERE deleted_at IS NULL AND published=1 AND id IN ('.$holder.') ORDER BY id FOR UPDATE',$ids)->fetchAll();
   if(count($products)!==count($ids))fail('Product unavailable');
   $totalCents=0;
   foreach($products as $p){
    if(!$p['available'])fail('Product unavailable');
    $totalCents+=(int)round((float)$p['price']*100)*$quantities[(int)$p['id']];
   }
   $total=number_format($totalCents/100,2,'.','');
   query('INSERT INTO orders(request_token,customer_name,notes,total,table_label) VALUES(?,?,?,?,?)',[$token,$name,$notes,$total,$table]);
   $orderId=(int)$db->lastInsertId();
   foreach($products as $p){
    $q=$quantities[(int)$p['id']];$line=number_format(((int)round((float)$p['price']*100)*$q)/100,2,'.','');
    query('INSERT INTO order_items(order_id,product_id,product_name,quantity,unit_price,subtotal) VALUES(?,?,?,?,?,?)',[$orderId,$p['id'],$p['name'],$q,$p['price'],$line]);
   }
   $pushCount=push_enqueue_order($orderId)+native_push_enqueue($orderId);audit($customerOrder?null:(int)$u['id'],$customerOrder?'customer.order.create':'order.create','order',$orderId);$db->commit();if($pushCount)$GLOBALS['gangchil_push_flush']=true;
   if($customerOrder){$window['count']++;$_SESSION['customer_order_window']=$window;}
   realtime_publish('order-created');
   json_response(['order'=>['id'=>$orderId,'status'=>'pending_payment','total'=>$total]],201);
  } catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 }
 if(preg_match('#^orders/([1-9][0-9]*)/receipt-html$#',$route,$m)&&$method==='GET'){
  require_permission('orders:view');$order=query('SELECT * FROM orders WHERE id=?',[integer_id($m[1])])->fetch();if(!$order)fail('Order not found',404);if(!$order['paid_at']||!in_array($order['status'],['confirmed','completed'],true))fail('Confirm cash before printing.',409);
  $items=query('SELECT product_name,quantity,unit_price,subtotal FROM order_items WHERE order_id=? ORDER BY id',[$order['id']])->fetchAll();json_response(['html'=>receipt_html($order,$items),'order'=>array_merge($order,['paid_at_iso'=>db_time_iso($order['paid_at'])]),'items'=>$items]);
 }
 if(preg_match('#^orders/([1-9][0-9]*)/receipt-submitted$#',$route,$m)&&$method==='POST'){
  fail('Use the shared printer queue. Reload the app.',409);
  $u=require_permission('orders:pay');$id=integer_id($m[1]);$d=request_data();$job=(string)($d['job_id']??'');if(!preg_match('/^gc-'.$id.'-[A-Za-z0-9-]{20,80}$/D',$job))fail('Invalid receipt job');
  $db=database();$db->beginTransaction();try{
   $order=query('SELECT * FROM orders WHERE id=? FOR UPDATE',[$id])->fetch();if(!$order||!$order['paid_at']||!in_array($order['status'],['confirmed','completed'],true))fail('Confirm cash before printing.',409);
   $duplicate=$order['receipt_submission_job']===$job;
   if(!$duplicate){query("UPDATE orders SET status='completed',receipt_submitted_at=NOW(),receipt_submission_job=? WHERE id=?",[$job,$id]);audit((int)$u['id'],'receipt.submitted','order',$id);}
   $db->commit();realtime_publish('order-status');json_response(['order'=>query('SELECT * FROM orders WHERE id=?',[$id])->fetch(),'duplicate'=>$duplicate]);
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 }
 if(preg_match('#^orders/([1-9][0-9]*)/(pay|complete)$#',$route,$m)&&$method==='POST'){
  $id=integer_id($m[1]);$action=$m[2];$u=require_permission('orders:pay');$d=request_data();$db=database();$db->beginTransaction();
  try{
   $order=query('SELECT * FROM orders WHERE id=? FOR UPDATE',[$id])->fetch();if(!$order)fail('Order not found',404);
   if($action==='pay'){
    if($order['status']!=='pending_payment')fail('Cash already confirmed or order updated.',409);
    $table=table_number($order['table_label']);if(!$table)$table=table_number($d['table_label']??'');if(!$table)fail('Select a table from 1 to 5 before confirming cash.');
    query("UPDATE orders SET status='confirmed',paid_at=NOW(),paid_by=?,table_label=? WHERE id=?",[$u['id'],$table,$id]);record_product_sales($id);print_enqueue($id);
   }else{
    if(($d['receipt_printed']??false)!==true)fail('Confirm that the receipt was printed.');
    if($order['status']==='completed'&&!empty($order['receipt_printed_at'])){$db->commit();json_response(['order'=>$order,'duplicate'=>true]);}
    if(!in_array($order['status'],['confirmed','completed'],true)||!$order['paid_at'])fail('Confirm cash first.',409);
    query("UPDATE orders SET status='completed',receipt_printed_at=NOW(),receipt_printed_by=? WHERE id=?",[$u['id'],$id]);
   }
   audit((int)$u['id'],'order.'.$action,'order',$id);$db->commit();realtime_publish('order-status');json_response(['order'=>query('SELECT * FROM orders WHERE id=?',[$id])->fetch()]);
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 }
 if(str_starts_with($route,'owner/')){
  require_permission('*');ensure_owner_schema();
 }
 if($route==='alerts' && $method==='GET'){
  $u=require_permission('orders:view');$pref=query('SELECT alerts_mute_until FROM staff_preferences WHERE user_id=?',[$u['id']])->fetchColumn();
  $pending=(int)query("SELECT COUNT(*) FROM orders WHERE status='pending_payment'")->fetchColumn();
  $recent=query("SELECT id,total,status,table_label,notes,created_at FROM orders WHERE status IN ('pending_payment','confirmed','completed') AND created_at>=DATE_SUB(NOW(),INTERVAL 1 DAY) ORDER BY id DESC LIMIT 100")->fetchAll();
  if($recent){$ids=array_column(array_filter($recent,fn($row)=>$row['status']==='pending_payment'),'id');$items=$ids?query('SELECT order_id,product_name AS name,quantity,unit_price AS price FROM order_items WHERE order_id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY id',$ids)->fetchAll():[];$by=[];foreach($items as $item)$by[$item['order_id']][]=$item;foreach($recent as &$entry){$entry['created_at']=db_time_iso($entry['created_at']);$entry['items']=$by[$entry['id']]??[];}unset($entry);}
  if((int)query('SELECT COUNT(*) FROM native_push_jobs WHERE due_at<=NOW() AND (locked_until IS NULL OR locked_until<NOW())')->fetchColumn()>0)$GLOBALS['gangchil_push_flush']=true;
  if((int)query('SELECT COUNT(*) FROM webpush_jobs WHERE due_at<=NOW() AND (locked_until IS NULL OR locked_until<NOW())')->fetchColumn()>0)$GLOBALS['gangchil_push_flush']=true;
  json_response(['user_id'=>(int)$u['id'],'pending_count'=>$pending,'orders'=>$recent,'mute_until'=>$pref===false?0:(int)$pref,'server_time'=>time(),'revision'=>(int)query('SELECT COALESCE(MAX(id),0) FROM audit_logs')->fetchColumn()]);
 }
 if($route==='alerts/preferences' && $method==='POST'){
  $u=require_permission('*');$d=request_data();$duration=$d['duration']??null;
  if(!is_int($duration)||!in_array($duration,[-1,0,900,1800,3600,7200,14400,28800,86400,604800],true))fail('Choose a notification pause duration.');
  $until=$duration===-1?-1:($duration===0?0:time()+$duration);
  query('INSERT INTO staff_preferences(user_id,alerts_mute_until) VALUES(?,?) ON DUPLICATE KEY UPDATE alerts_mute_until=VALUES(alerts_mute_until)',[$u['id'],$until]);
  realtime_publish('alert-preferences');json_response(['mute_until'=>$until,'server_time'=>time()]);
 }
 if($route==='owner/overview' && $method==='GET'){
  $today=date('Y-m-d');$yesterday=date('Y-m-d',strtotime('-1 day'));$range=['mode'=>'single','from'=>$today,'to'=>$today];$r=report_data($range);
  [$a,$b]=db_day_bounds($today,$today);[$ya,$yb]=db_day_bounds($yesterday,$yesterday);
  $previous=query("SELECT COALESCE(SUM(total),0) FROM orders WHERE paid_at>=? AND paid_at<? AND status<>'cancelled'",[$ya,$yb])->fetchColumn();
  $hourly=query("SELECT HOUR(TIMESTAMPADD(SECOND,?,paid_at)) AS hour,SUM(total) AS amount FROM orders WHERE paid_at>=? AND paid_at<? AND status<>'cancelled' GROUP BY hour",[21600-db_clock_offset(),$a,$b])->fetchAll();
  $live=query("SELECT status,COUNT(*) AS count FROM orders WHERE status IN ('pending_payment','confirmed') OR (status='completed' AND COALESCE(receipt_printed_at,receipt_submitted_at)>=? AND COALESCE(receipt_printed_at,receipt_submitted_at)<?) GROUP BY status",[$a,$b])->fetchAll();
  $expenses=query('SELECT id,description,amount,created_at FROM expenses WHERE expense_date=? ORDER BY id DESC LIMIT 100',[$today])->fetchAll();
  json_response(['date'=>$today,'sales'=>$r['sales'],'previous_sales'=>$previous,'expenses_total'=>$r['expenses'],'profit'=>$r['profit'],'order_count'=>$r['orders_placed'],'hourly'=>$hourly,'live'=>$live,'expenses'=>$expenses,'orders'=>owner_orders("WHERE o.created_at>=? AND o.created_at<? AND o.status IN ('pending_payment','confirmed','completed')",[$a,$b],5),'updated_at'=>$r['generated_at']]);
 }
 if($route==='owner/orders' && $method==='GET'){
  $input=$_GET;if(!isset($input['mode'])){$input['mode']=($input['date']??'')==='all'?'history':'single';$input['from']=$input['date']??date('Y-m-d');$input['to']=$input['from'];}
  $range=report_range($input);[$dateWhere,$params]=range_where($range,'o.created_at');$where='WHERE '.$dateWhere;
  $where.=" AND o.status IN ('pending_payment','confirmed','completed')";$search=ltrim(trim((string)($_GET['q']??'')),'#');$status=(string)($_GET['status']??'');
  if(!preg_match('/^[0-9]{0,18}$/D',$search)||!in_array($status,['','pending_payment','confirmed','completed'],true))fail('Invalid order filter.');
  if($search!==''){$where.=" AND LPAD(o.id,6,'0') LIKE ?";$params[]='%'.$search.'%';}
  $counts=query('SELECT o.status,COUNT(*) AS count FROM orders o '.$where.' GROUP BY o.status',$params)->fetchAll();
  if($status!==''){$where.=' AND o.status=?';$params[]=$status;}
  $summary=query('SELECT COUNT(*) AS count,COALESCE(SUM(o.total),0) AS total FROM orders o '.$where,$params)->fetch();
  $ascending=$status==='pending_payment';$cursor=(string)($_GET['cursor']??$_GET['before']??'');if($cursor!==''){$where.=$ascending?' AND o.id>?':' AND o.id<?';$params[]=integer_id($cursor);}
  if(isset($_GET['page'])){$page=admin_page();$rows=owner_orders($where,$params,20,$ascending?'ASC':'DESC',($page-1)*20);json_response(['orders'=>$rows,'summary'=>$summary,'counts'=>$counts,'next_before'=>null,'range'=>$range,'page'=>$page]);}
  $rows=owner_orders($where,$params,51,$ascending?'ASC':'DESC');$more=count($rows)>50;if($more)array_pop($rows);
  json_response(['orders'=>$rows,'summary'=>$summary,'counts'=>$counts,'next_before'=>$more?(int)end($rows)['id']:null,'range'=>$range]);
 }
 if($route==='owner/expenses' && $method==='GET'){
  $range=report_range($_GET);$where=$range['mode']==='history'?'1=1':'expense_date>=? AND expense_date<=?';$params=$range['mode']==='history'?[]:[$range['from'],$range['to']];
  $summary=query('SELECT COUNT(*) AS count,COALESCE(SUM(amount),0) AS total FROM expenses WHERE '.$where,$params)->fetch();
  $cursor=(string)($_GET['cursor']??'');if($cursor!==''){$where.=' AND e.id<?';$params[]=integer_id($cursor);}
  $page=isset($_GET['page'])?admin_page():1;$limit=isset($_GET['page'])?'20 OFFSET '.(($page-1)*20):'51';$rows=query('SELECT e.*,u.name AS staff_name FROM expenses e LEFT JOIN users u ON u.id=e.created_by WHERE '.$where.' ORDER BY e.id DESC LIMIT '.$limit,$params)->fetchAll();
  $more=count($rows)>50;if($more)array_pop($rows);json_response(['expenses'=>$rows,'summary'=>$summary,'next'=>$more?(int)end($rows)['id']:null]);
 }
 if($route==='owner/reports' && $method==='GET'){
  $data=report_data(report_range($_GET));$format=(string)($_GET['format']??'json');if($format!=='json'){if(!in_array($format,['csv','html','print'],true))fail('Invalid report format.');export_report($data,$format);}json_response($data);
 }
 if($route==='products/popular' && $method==='GET'){
  $viewer=require_permission('orders:view');$limit=max(1,min(100,(int)($_GET['limit']??10)));
  json_response(['products'=>query('SELECT product_key,product_id,product_name,quantity,revenue,orders_count FROM product_sales_totals ORDER BY quantity DESC,revenue DESC LIMIT '.$limit)->fetchAll()]);
 }
 if($route==='owner/expenses' && $method==='POST'){
  $u=require_permission('*');$d=request_data();$description=trim_text($d['description']??'',180);$amount=$d['amount']??null;$token=$d['request_token']??'';$expenseDate=(string)($d['expense_date']??date('Y-m-d'));
  if(!valid_day($expenseDate))fail('Choose a valid expense date.');
  if(!$description||!is_numeric($amount)||!is_finite((float)$amount)||(float)$amount<0.01||(float)$amount>10000000||!is_string($token)||!preg_match('/^[a-zA-Z0-9_-]{20,80}$/D',$token))fail('Enter a description and valid expense amount.');
  $db=database();$db->beginTransaction();
  try {
   $existing=query('SELECT id FROM expenses WHERE request_token=?',[$token])->fetch();
   if($existing){$db->commit();json_response(['ok'=>true,'duplicate'=>true]);}
   query('INSERT INTO expenses(request_token,description,amount,expense_date,created_by) VALUES(?,?,?,?,?)',[$token,$description,number_format((float)$amount,2,'.',''),$expenseDate,$u['id']]);
   audit((int)$u['id'],'expense.create','expense',(int)$db->lastInsertId());$db->commit();realtime_publish('expense');json_response(['ok'=>true],201);
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 }
 if($route==='products/photo' && $method==='POST'){
  require_permission('products:manage');$file=$_FILES['photo']??null;
  if(!$file||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)fail('Choose an image to upload.');
  if((int)$file['size']>300*1024)fail('Compress the photo to 300 KB or less before uploading.');
  $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);$types=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
  if(!isset($types[$mime]))fail('Use a JPG, PNG or WebP photo.');
  $dimensions=getimagesize($file['tmp_name']);if(!$dimensions||$dimensions[0]>6000||$dimensions[1]>6000)fail('Use an image no larger than 6000 pixels on either side.');
  $directory=__DIR__.'/uploads/menu';if(!is_dir($directory)&&!mkdir($directory,0755,true))fail('Photo folder is not writable.',500);
  $name=bin2hex(random_bytes(16)).'.'.$types[$mime];if(!move_uploaded_file($file['tmp_name'],$directory.'/'.$name))fail('Photo could not be saved.',500);
  json_response(['image_url'=>'/uploads/menu/'.$name,'bytes'=>filesize($directory.'/'.$name),'width'=>$dimensions[0],'height'=>$dimensions[1]],201);
 }
 if($route==='reports' && $method==='GET'){
  require_permission('reports:view');
  $from=(string)($_GET['from']??date('Y-m-d',strtotime('-7 days')));$to=(string)($_GET['to']??date('Y-m-d'));
  foreach([$from,$to] as $v){
   $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$v);
   if(!$parsed||$parsed->format('Y-m-d')!==$v)fail('Invalid date');
  }
  if($from>$to)fail('Invalid date range');
  $sales=query("SELECT COUNT(*) AS orders,COALESCE(SUM(total),0) AS revenue FROM orders WHERE paid_at>=? AND paid_at<DATE_ADD(?,INTERVAL 1 DAY) AND status<>'cancelled'",[$from,$to])->fetch();
  $top=query("SELECT i.product_name,SUM(i.quantity) AS quantity FROM order_items i JOIN orders o ON o.id=i.order_id WHERE o.paid_at>=? AND o.paid_at<DATE_ADD(?,INTERVAL 1 DAY) AND o.status<>'cancelled' GROUP BY i.product_name ORDER BY quantity DESC LIMIT 10",[$from,$to])->fetchAll();
  json_response(['sales'=>$sales,'top'=>$top]);
 }
 if($route==='audit' && $method==='GET'){
  require_permission('*');$page=admin_page();$limit=(int)($_GET['limit']??20);if(!in_array($limit,[10,20,50,100],true))fail('Choose 10, 20, 50 or 100 rows.');
  $range=report_range($_GET+['mode'=>'history']);[$where,$params]=range_where($range,'a.created_at');$q=trim_text($_GET['q']??'',100);$action=trim_text($_GET['action']??'',80);$actor=(string)($_GET['actor']??'');
  if($action!==''){$where.=' AND a.action=?';$params[]=$action;}
  if($actor!==''){$where.=' AND a.user_id=?';$params[]=integer_id($actor);}
  if($q!==''){
   $keys=[];foreach(audit_action_names() as $key=>$label)if(mb_stripos($label,$q)!==false)$keys[]=$key;
   $where.=' AND (u.name LIKE ? OR a.action LIKE ? OR a.entity LIKE ? OR CAST(a.entity_id AS CHAR) LIKE ? OR LPAD(a.entity_id,6,\'0\') LIKE ?';$params=[...$params,'%'.$q.'%','%'.$q.'%','%'.$q.'%','%'.ltrim($q,'#').'%','%'.ltrim($q,'#').'%'];
   if($keys){$where.=' OR a.action IN ('.implode(',',array_fill(0,count($keys),'?')).')';$params=[...$params,...$keys];}$where.=')';
  }
  $total=(int)query('SELECT COUNT(*) FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id WHERE '.$where,$params)->fetchColumn();$offset=($page-1)*$limit;
  $logs=query('SELECT a.*,u.name AS actor FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id WHERE '.$where.' ORDER BY a.id DESC LIMIT '.$limit.' OFFSET '.$offset,$params)->fetchAll();foreach($logs as &$log){$log['created_at']=db_time_iso($log['created_at']);$log['action_label']=audit_action_names()[$log['action']]??ucwords(str_replace(['.','_'],' ',$log['action']));}unset($log);
  json_response(['logs'=>$logs,'total'=>$total,'page'=>$page,'page_size'=>$limit,'range'=>$range,'actions'=>query('SELECT DISTINCT action FROM audit_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN),'actors'=>query('SELECT DISTINCT u.id,u.name FROM users u JOIN audit_logs a ON a.user_id=u.id ORDER BY u.name')->fetchAll()]);
 }
 if($route==='staff' && $method==='GET'){
  require_permission('*');
  json_response(['staff'=>query('SELECT id,name,phone,role,active,permissions,created_at FROM users WHERE deleted_at IS NULL AND role IN (\'owner\',\'manager\') ORDER BY id')->fetchAll()]);
 }
 if($route==='staff' && $method==='POST'){
  $u=require_permission('*');$d=request_data();
  $name=trim_text($d['name']??'',120);$phone=bd_phone($d['phone']??'');
  $role=$d['role']??'';$pin=$d['pin']??'';
  if(!$name||$phone===null||!valid_pin($pin)||!in_array($role,['owner','manager'],true))fail('Invalid staff details');
  $permissions=$d['permissions']??[];
  if(!is_array($permissions)||array_is_list($permissions)&&count($permissions)>0)fail('Invalid permissions');
  $hash=password_hash($pin,PASSWORD_DEFAULT);
  query('INSERT INTO users(name,phone,pin_hash,role,permissions) VALUES(?,?,?,?,?)',[$name,$phone,$hash,$role,json_encode((object)$permissions)]);
  $newId=(int)database()->lastInsertId();audit((int)$u['id'],'staff.create','user',$newId);
  json_response(['staff'=>['id'=>$newId,'name'=>$name,'phone'=>$phone,'role'=>$role]],201);
 }
 if(preg_match('#^staff/([1-9][0-9]*)$#',$route,$m)&&$method==='DELETE'){
  $u=require_permission('*');$id=integer_id($m[1]);if($id===(int)$u['id'])fail('You cannot remove your own account.',409);
  $db=database();$db->beginTransaction();try{
   $owners=query("SELECT id FROM users WHERE role='owner' AND active=1 AND deleted_at IS NULL FOR UPDATE")->fetchAll();
   $target=query('SELECT id,role,active FROM users WHERE id=? AND deleted_at IS NULL FOR UPDATE',[$id])->fetch();if(!$target)fail('Staff not found',404);
   if($target['role']==='owner'&&$target['active']&&count($owners)<=1)fail('The last owner cannot be removed.',409);
   query('UPDATE users SET active=0,deleted_at=NOW() WHERE id=?',[$id]);audit((int)$u['id'],'staff.remove','user',$id);$db->commit();json_response(['ok'=>true]);
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 }
 if(preg_match('#^staff/([1-9][0-9]*)$#',$route,$m) && $method==='PATCH'){
  $u=require_permission('*');$uid=integer_id($m[1]);$d=request_data();
  $target=query('SELECT id,name,role,active,permissions FROM users WHERE id=? AND deleted_at IS NULL',[$uid])->fetch();
  if(!$target)fail('Staff not found',404);
  $name=array_key_exists('name',$d)?trim_text($d['name'],120):$target['name'];if(!$name)fail('Name is required.');
  $role=$d['role']??$target['role'];$active=array_key_exists('active',$d)?$d['active']:(bool)$target['active'];
  if(!in_array($role,['owner','manager'],true)||!is_bool($active))fail('Invalid role or active value');
  if($uid===(int)$u['id'] && (!$active||$role!=='owner'))fail('Cannot remove your own owner access');
  $permissions=$d['permissions']??(json_decode((string)$target['permissions'],true)?:[]);
  if(!is_array($permissions)||array_is_list($permissions)&&count($permissions)>0)fail('Invalid permissions');
  $newPhone=null;$newHash=null;
  if(array_key_exists('phone',$d)){
   if($uid===(int)$u['id'])fail('Use your account page to change your own login number.');
   $newPhone=bd_phone($d['phone']);if($newPhone===null)fail('Enter a valid Bangladeshi mobile number.');
  }
  if(array_key_exists('pin',$d)){
   if($uid===(int)$u['id'])fail('Use your account page to change your own PIN.');
   if(!valid_pin($d['pin']))fail('PIN must contain exactly 4 digits.');
   $newHash=password_hash($d['pin'],PASSWORD_DEFAULT);
  }
  query('UPDATE users SET name=?,role=?,active=?,permissions=?,phone=COALESCE(?,phone),pin_hash=COALESCE(?,pin_hash) WHERE id=?',[$name,$role,(int)$active,json_encode((object)$permissions),$newPhone,$newHash,$uid]);
  audit((int)$u['id'],'staff.update','user',$uid);
  json_response(['staff'=>query('SELECT id,name,phone,role,active,permissions FROM users WHERE id=?',[$uid])->fetch()]);
 }
 if($route==='categories' && $method==='POST'){
  $u=require_permission('products:manage');$d=request_data();$name=trim_text($d['name']??'',120);
  if(!$name)fail('Name required');
  query('INSERT INTO categories(name) VALUES(?)',[$name]);$newId=(int)database()->lastInsertId();
  audit((int)$u['id'],'category.create','category',$newId);realtime_publish('menu');
  json_response(['category'=>['id'=>$newId,'name'=>$name]],201);
 }
 if(preg_match('#^categories/([1-9][0-9]*)$#',$route,$m)&&in_array($method,['PATCH','DELETE'],true)){
  $u=require_permission('products:manage');$id=integer_id($m[1]);$db=database();$db->beginTransaction();
  try{
   if(!query('SELECT id FROM categories WHERE id=? FOR UPDATE',[$id])->fetch())fail('Category not found',404);
   if($method==='PATCH'){$d=request_data();$name=trim_text($d['name']??'',120);if(!$name)fail('Name required');query('UPDATE categories SET name=? WHERE id=?',[$name,$id]);}
   else{if(query('SELECT id FROM products WHERE category_id=? AND deleted_at IS NULL LIMIT 1',[$id])->fetch())fail('Move the products to another category before deleting this category.',409);query('DELETE FROM categories WHERE id=?',[$id]);}
   audit((int)$u['id'],$method==='PATCH'?'category.update':'category.delete','category',$id);$db->commit();realtime_publish('menu');json_response(['ok'=>true]);
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 }
 if($route==='products' && $method==='POST'){
  $u=require_permission('products:manage');$d=request_data();$name=trim_text($d['name']??'',160);
  $price=$d['price']??null;$cat=integer_id($d['category_id']??null);
  $image=$d['image_url']??null;
  if(!$name||!is_numeric($price)||$price<0||$price>1000000||!menu_image_url($image))fail('Invalid product');
  query('INSERT INTO products(name,description,price,category_id,image_url) VALUES(?,?,?,?,?)',[$name,trim_text($d['description']??'',1000),number_format((float)$price,2,'.',''),$cat,$image]);
  $newId=(int)database()->lastInsertId();audit((int)$u['id'],'product.create','product',$newId);realtime_publish('menu');
  json_response(['product'=>query('SELECT * FROM products WHERE id=?',[$newId])->fetch()],201);
 }
 if(preg_match('#^products/([1-9][0-9]*)$#',$route,$m) && $method==='DELETE'){
  $u=require_permission('products:manage');$pid=integer_id($m[1]);
  if(!query('SELECT id FROM products WHERE id=? AND deleted_at IS NULL',[$pid])->fetch())fail('Product not found',404);
  query('UPDATE products SET deleted_at=NOW(),published=0,available=0 WHERE id=?',[$pid]);audit((int)$u['id'],'product.remove','product',$pid);realtime_publish('menu');json_response(['ok'=>true]);
 }
 if(preg_match('#^products/([1-9][0-9]*)$#',$route,$m) && $method==='PATCH'){
  $u=require_permission('products:manage');$pid=integer_id($m[1]);$d=request_data();
  $existing=query('SELECT * FROM products WHERE id=? AND deleted_at IS NULL',[$pid])->fetch();
  if(!$existing)fail('Product not found',404);
  $name=array_key_exists('name',$d)?trim_text($d['name'],160):$existing['name'];
  $description=array_key_exists('description',$d)?trim_text($d['description'],1000):$existing['description'];
  $price=$d['price']??$existing['price'];$cat=$d['category_id']??$existing['category_id'];
  $image=array_key_exists('image_url',$d)?$d['image_url']:$existing['image_url'];
  $available=array_key_exists('available',$d)?$d['available']:(bool)$existing['available'];
  if(!$name||!is_numeric($price)||$price<0||$price>1000000||!is_bool($available))fail('Invalid product update');
  $published=array_key_exists('published',$d)?$d['published']:(bool)$existing['published'];if(!is_bool($published))fail('Invalid publication status');
  if(!menu_image_url($image))fail('Use an uploaded photo or a valid HTTPS image URL.');
  query('UPDATE products SET name=?,description=?,price=?,category_id=?,image_url=?,available=?,published=? WHERE id=?',[$name,$description,number_format((float)$price,2,'.',''),$cat,$image,(int)$available,(int)$published,$pid]);
  audit((int)$u['id'],'product.update','product',$pid);realtime_publish('menu');
  json_response(['product'=>query('SELECT * FROM products WHERE id=?',[$pid])->fetch()]);
 }
 fail('Route not found',404);
} catch(PDOException $e) {
 error_log('[GANGCHIL] DB error: '.$e->getMessage());
 if($e->getCode()==='23000')fail('Duplicate value or conflicting record',409);
 fail('Database operation failed',500);
} catch(Throwable $e) {
 error_log('[GANGCHIL] '.$e->getMessage());fail('Server error',500);
}
