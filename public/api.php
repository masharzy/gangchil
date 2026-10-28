<?php
declare(strict_types=1);
require_once is_file(__DIR__.'/private/bootstrap.php') ? __DIR__.'/private/bootstrap.php' : __DIR__.'/../private/bootstrap.php';
require_once is_file(__DIR__.'/private/realtime.php') ? __DIR__.'/private/realtime.php' : __DIR__.'/../private/realtime.php';
date_default_timezone_set('Asia/Dhaka');
header('X-Content-Type-Options: nosniff');
$route=trim((string)($_GET['route']??''),'/');
$method=$_SERVER['REQUEST_METHOD']??'GET';

try {
 ensure_phone_schema();
 if(!STAFF_SURFACE&&!in_array($route,['health','auth/me','menu','customer/orders'],true))fail('Staff sign-in required',401);
 if($route==='health' && $method==='GET'){
  query('SELECT 1');json_response(['ok'=>true]);
 }
 if($route==='auth/me' && $method==='GET'){
  json_response(['user'=>current_user(),'csrf'=>csrf_token()]);
 }
 if($route==='realtime/config' && $method==='GET'){
  require_permission('orders:view');
  json_response(['enabled'=>realtime_enabled(),
   'key'=>realtime_enabled()?PUSHER_KEY:null,
   'cluster'=>realtime_enabled()?PUSHER_CLUSTER:null]);
 }
 if($method!=='GET')require_csrf();
 if($route==='realtime/auth' && $method==='POST'){
  require_permission('orders:view');
  $socket=(string)($_POST['socket_id']??'');
  $channel=(string)($_POST['channel_name']??'');
  if(!realtime_enabled())fail('Realtime unavailable',503);
  try {json_response(realtime_private_auth($socket,$channel));}
  catch(InvalidArgumentException $e){fail('Invalid realtime request',403);}
 }
 if($route==='auth/login' && $method==='POST'){
  $d=request_data();$phone=bd_phone($d['phone']??'');$password=(string)($d['password']??'');
  if($phone===null)fail('Enter a valid Bangladeshi mobile number.');
  $now=time();$attempt=(int)($_SESSION['failed_login_count']??0);$start=(int)($_SESSION['failed_login_at']??0);
  if($now-$start>900){$attempt=0;$start=$now;}
  if($attempt>=8)fail('Too many login attempts. Try again later.',429);
  $s=query('SELECT id,name,phone,role,active,password_hash,permissions FROM users WHERE phone=? LIMIT 1',[$phone]);$u=$s->fetch();
  if(!$u || !$u['active'] || !password_verify($password,$u['password_hash'])){
   $_SESSION['failed_login_count']=$attempt+1;$_SESSION['failed_login_at']=$start;fail('Invalid credentials',401);
  }
  session_regenerate_id(true);$_SESSION['uid']=(int)$u['id'];$_SESSION['failed_login_count']=0;
  $_SESSION['csrf']=bin2hex(random_bytes(32));unset($u['password_hash'],$u['active']);
  $u['permissions']=json_decode((string)($u['permissions']??'{}'),true)?:[];
  json_response(['user'=>$u,'csrf'=>csrf_token()]);
 }
 if($route==='auth/logout' && $method==='POST'){
  $_SESSION=[];session_regenerate_id(true);
  json_response(['ok'=>true,'csrf'=>csrf_token()]);
 }
 if($route==='auth/phone' && $method==='POST'){
  $u=current_user();if(!$u)fail('Login required',401);$d=request_data();$phone=bd_phone($d['phone']??'');
  if($phone===null)fail('Enter a valid Bangladeshi mobile number.');
  $password=$d['current_password']??'';
  if(!is_string($password)||!password_verify($password,(string)query('SELECT password_hash FROM users WHERE id=?',[$u['id']])->fetchColumn()))fail('Current password is incorrect.');
  query('UPDATE users SET phone=? WHERE id=?',[$phone,$u['id']]);
  audit((int)$u['id'],'staff.phone','user',$u['id']);json_response(['user'=>current_user()]);
 }
 if($route==='auth/password' && $method==='POST'){
  $u=current_user();if(!$u)fail('Login required',401);$d=request_data();
  $current=$d['current_password']??'';$password=$d['password']??'';
  if(!is_string($current)||!is_string($password)||strlen($password)<12||strlen($password)>72)fail('Use a new password of 12–72 bytes.');
  $now=time();if($now-(int)($_SESSION['password_attempt_at']??0)>900){$_SESSION['password_attempt_count']=0;$_SESSION['password_attempt_at']=$now;}
  if((int)($_SESSION['password_attempt_count']??0)>=8)fail('Too many password attempts. Try again in 15 minutes.',429);
  $_SESSION['password_attempt_count']=(int)($_SESSION['password_attempt_count']??0)+1;
  $hash=query('SELECT password_hash FROM users WHERE id=?',[$u['id']])->fetchColumn();
  if(!password_verify($current,$hash))fail('Current password is incorrect.',400);
  query('UPDATE users SET password_hash=? WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$u['id']]);
  $_SESSION['password_attempt_count']=0;session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));
  audit((int)$u['id'],'staff.password','user',$u['id']);
  json_response(['ok'=>true,'csrf'=>csrf_token()]);
 }
 if($route==='menu' && $method==='GET'){
  json_response([
   'categories'=>query('SELECT id,name,sort_order,active FROM categories WHERE active=1 ORDER BY sort_order,id')->fetchAll(),
   'products'=>query('SELECT id,name,description,price,image_url,category_id,available FROM products ORDER BY id DESC')->fetchAll()
  ]);
 }
 if($route==='orders' && $method==='GET'){
  require_permission('orders:view');$status=(string)($_GET['status']??'');
  $valid=['','pending_payment','new','preparing','ready','served','cancelled'];
  if(!in_array($status,$valid,true))fail('Invalid status');
  $orders=query('SELECT o.* FROM orders o WHERE (?="" OR o.status=?) ORDER BY o.created_at DESC,o.id DESC LIMIT 150',[$status,$status])->fetchAll();
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
  $name=trim_text($d['customer_name']??'',100);$notes=trim_text($d['notes']??'',500);
  $db=database();$db->beginTransaction();
  try {
   $existing=query('SELECT id,status,total,created_at FROM orders WHERE request_token=?',[$token])->fetch();
   if($existing){$db->commit();json_response(['order'=>$existing,'duplicate'=>true]);}
   if($customerOrder){
    $window=$_SESSION['customer_order_window']??['start'=>time(),'count'=>0];
    if(time()-(int)$window['start']>=60)$window=['start'=>time(),'count'=>0];
    if((int)$window['count']>=12)fail('Please wait a moment before placing another order.',429);
   }
   $ids=array_keys($quantities);$holder=implode(',',array_fill(0,count($ids),'?'));
   $products=query('SELECT id,name,price,available FROM products WHERE id IN ('.$holder.') ORDER BY id FOR UPDATE',$ids)->fetchAll();
   if(count($products)!==count($ids))fail('Product unavailable');
   $totalCents=0;
   foreach($products as $p){
    if(!$p['available'])fail('Product unavailable');
    $totalCents+=(int)round((float)$p['price']*100)*$quantities[(int)$p['id']];
   }
   $total=number_format($totalCents/100,2,'.','');
   query('INSERT INTO orders(request_token,customer_name,notes,total) VALUES(?,?,?,?)',[$token,$name,$notes,$total]);
   $orderId=(int)$db->lastInsertId();
   foreach($products as $p){
    $q=$quantities[(int)$p['id']];$line=number_format(((int)round((float)$p['price']*100)*$q)/100,2,'.','');
    query('INSERT INTO order_items(order_id,product_id,product_name,quantity,unit_price,subtotal) VALUES(?,?,?,?,?,?)',[$orderId,$p['id'],$p['name'],$q,$p['price'],$line]);
   }
   audit($customerOrder?null:(int)$u['id'],$customerOrder?'customer.order.create':'order.create','order',$orderId);$db->commit();
   if($customerOrder){$window['count']++;$_SESSION['customer_order_window']=$window;}
   realtime_publish('order-created');
   json_response(['order'=>['id'=>$orderId,'status'=>'pending_payment','total'=>$total]],201);
  } catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 }
 if(preg_match('#^orders/([1-9][0-9]*)/(pay|prepare|ready|serve|cancel)$#',$route,$m) && $method==='POST'){
  $id=integer_id($m[1]);$action=$m[2];
  $transition=[
   'pay'=>['pending_payment','new','orders:pay','paid_at','paid_by'],
   'prepare'=>['new','preparing','orders:prepare','started_at','started_by'],
   'ready'=>['preparing','ready','orders:prepare','ready_at','ready_by'],
   'serve'=>['ready','served','orders:serve','served_at','served_by'],
   'cancel'=>['pending_payment','cancelled','orders:cancel','cancelled_at','cancelled_by']
  ][$action];
  $u=require_permission($transition[2]);$db=database();$db->beginTransaction();
  try {
   // Column names are constants from the server-owned transition table.
   $sql='UPDATE orders SET status=?, '.$transition[3].'=NOW(), '.$transition[4].'=? WHERE id=? AND status=?';
   $s=query($sql,[$transition[1],$u['id'],$id,$transition[0]]);
   if(!$s->rowCount())fail('Order not found or already updated',409);
   audit((int)$u['id'],'order.'.$action,'order',$id);$db->commit();
   realtime_publish('order-status');
   json_response(['order'=>query('SELECT * FROM orders WHERE id=?',[$id])->fetch()]);
  } catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
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
  require_permission('*');
  json_response(['logs'=>query('SELECT a.*,u.name AS actor FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 150')->fetchAll()]);
 }
 if($route==='staff' && $method==='GET'){
  require_permission('*');
  json_response(['staff'=>query('SELECT id,name,phone,role,active,permissions,created_at FROM users ORDER BY id')->fetchAll()]);
 }
 if($route==='staff' && $method==='POST'){
  $u=require_permission('*');$d=request_data();
  $name=trim_text($d['name']??'',120);$phone=bd_phone($d['phone']??'');
  $role=$d['role']??'';$password=$d['password']??'';
  if(!$name||$phone===null||!is_string($password)||strlen($password)<12||strlen($password)>72||!in_array($role,['owner','manager','chef','waiter','staff'],true))fail('Invalid staff details');
  $permissions=$d['permissions']??[];
  if(!is_array($permissions)||array_is_list($permissions)&&count($permissions)>0)fail('Invalid permissions');
  $hash=password_hash($password,PASSWORD_DEFAULT);
  query('INSERT INTO users(name,phone,password_hash,role,permissions) VALUES(?,?,?,?,?)',[$name,$phone,$hash,$role,json_encode((object)$permissions)]);
  $newId=(int)database()->lastInsertId();audit((int)$u['id'],'staff.create','user',$newId);
  json_response(['staff'=>['id'=>$newId,'name'=>$name,'phone'=>$phone,'role'=>$role]],201);
 }
 if(preg_match('#^staff/([1-9][0-9]*)$#',$route,$m) && $method==='PATCH'){
  $u=require_permission('*');$uid=integer_id($m[1]);$d=request_data();
  $target=query('SELECT id,role,active,permissions FROM users WHERE id=?',[$uid])->fetch();
  if(!$target)fail('Staff not found',404);
  $role=$d['role']??$target['role'];$active=array_key_exists('active',$d)?$d['active']:(bool)$target['active'];
  if(!in_array($role,['owner','manager','chef','waiter','staff'],true)||!is_bool($active))fail('Invalid role or active value');
  if($uid===(int)$u['id'] && (!$active||$role!=='owner'))fail('Cannot remove your own owner access');
  $permissions=$d['permissions']??(json_decode((string)$target['permissions'],true)?:[]);
  if(!is_array($permissions)||array_is_list($permissions)&&count($permissions)>0)fail('Invalid permissions');
  $newPhone=null;$newHash=null;
  if(array_key_exists('phone',$d)){
   if($uid===(int)$u['id'])fail('Use your account page to change your own login number.');
   $newPhone=bd_phone($d['phone']);if($newPhone===null)fail('Enter a valid Bangladeshi mobile number.');
  }
  if(array_key_exists('password',$d)){
   if($uid===(int)$u['id'])fail('Use your account page to change your own password.');
   if(!is_string($d['password'])||strlen($d['password'])<12||strlen($d['password'])>72)fail('Password must be 12–72 bytes.');
   $newHash=password_hash($d['password'],PASSWORD_DEFAULT);
  }
  query('UPDATE users SET role=?,active=?,permissions=?,phone=COALESCE(?,phone),password_hash=COALESCE(?,password_hash) WHERE id=?',[$role,(int)$active,json_encode((object)$permissions),$newPhone,$newHash,$uid]);
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
 if($route==='products' && $method==='POST'){
  $u=require_permission('products:manage');$d=request_data();$name=trim_text($d['name']??'',160);
  $price=$d['price']??null;$cat=integer_id($d['category_id']??null);
  $image=$d['image_url']??null;
  if(!$name||!is_numeric($price)||$price<0||$price>1000000||($image!==null&&!filter_var($image,FILTER_VALIDATE_URL)))fail('Invalid product');
  if($image!==null&&!str_starts_with($image,'https://'))fail('Image must use HTTPS');
  query('INSERT INTO products(name,description,price,category_id,image_url) VALUES(?,?,?,?,?)',[$name,trim_text($d['description']??'',1000),number_format((float)$price,2,'.',''),$cat,$image]);
  $newId=(int)database()->lastInsertId();audit((int)$u['id'],'product.create','product',$newId);realtime_publish('menu');
  json_response(['product'=>query('SELECT * FROM products WHERE id=?',[$newId])->fetch()],201);
 }
 if(preg_match('#^products/([1-9][0-9]*)$#',$route,$m) && $method==='PATCH'){
  $u=require_permission('products:manage');$pid=integer_id($m[1]);$d=request_data();
  $existing=query('SELECT * FROM products WHERE id=?',[$pid])->fetch();
  if(!$existing)fail('Product not found',404);
  $name=array_key_exists('name',$d)?trim_text($d['name'],160):$existing['name'];
  $description=array_key_exists('description',$d)?trim_text($d['description'],1000):$existing['description'];
  $price=$d['price']??$existing['price'];$cat=$d['category_id']??$existing['category_id'];
  $image=array_key_exists('image_url',$d)?$d['image_url']:$existing['image_url'];
  $available=array_key_exists('available',$d)?$d['available']:(bool)$existing['available'];
  if(!$name||!is_numeric($price)||$price<0||$price>1000000||!is_bool($available))fail('Invalid product update');
  if($image!==null&&(!is_string($image)||!str_starts_with($image,'https://')))fail('Invalid image URL');
  query('UPDATE products SET name=?,description=?,price=?,category_id=?,image_url=?,available=? WHERE id=?',[$name,$description,number_format((float)$price,2,'.',''),$cat,$image,(int)$available,$pid]);
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
