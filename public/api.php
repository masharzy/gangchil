<?php
declare(strict_types=1);
require_once __DIR__ . '/../private/bootstrap.php';
require_once __DIR__ . '/../private/realtime.php';
date_default_timezone_set('Asia/Dhaka');
header('X-Content-Type-Options: nosniff');
$route=trim((string)($_GET['route']??''),'/');
$method=$_SERVER['REQUEST_METHOD']??'GET';

try {
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
  $d=request_data();$email=strtolower(trim_text($d['email']??'',190));$password=(string)($d['password']??'');
  $now=time();$attempt=(int)($_SESSION['failed_login_count']??0);$start=(int)($_SESSION['failed_login_at']??0);
  if($now-$start>900){$attempt=0;$start=$now;}
  if($attempt>=8)fail('Too many login attempts. Try again later.',429);
  $s=query('SELECT id,name,email,role,active,password_hash,permissions FROM users WHERE email=? LIMIT 1',[$email]);$u=$s->fetch();
  if(!$u || !$u['active'] || !password_verify($password,$u['password_hash'])){
   $_SESSION['failed_login_count']=$attempt+1;$_SESSION['failed_login_at']=$start;fail('Invalid credentials',401);
  }
  session_regenerate_id(true);$_SESSION['uid']=(int)$u['id'];$_SESSION['failed_login_count']=0;
  $_SESSION['csrf']=bin2hex(random_bytes(32));unset($u['password_hash'],$u['active']);
  $u['permissions']=json_decode((string)($u['permissions']??'{}'),true)?:[];
  json_response(['user'=>$u,'csrf'=>csrf_token()]);
 }
 if($route==='auth/logout' && $method==='POST'){
  $_SESSION=[];session_destroy();setcookie(session_name(),'',time()-3600,'/');
  json_response(['ok'=>true]);
 }
 if($route==='menu' && $method==='GET'){
  require_permission('orders:view');
  json_response([
   'categories'=>query('SELECT id,name,sort_order,active FROM categories WHERE active=1 ORDER BY sort_order,id')->fetchAll(),
   'products'=>query('SELECT id,name,description,price,image_url,category_id,available FROM products ORDER BY id DESC')->fetchAll(),
   'tables'=>query('SELECT id,name FROM dining_tables WHERE active=1 ORDER BY id')->fetchAll()
  ]);
 }
 if($route==='orders' && $method==='GET'){
  require_permission('orders:view');$status=(string)($_GET['status']??'');
  $valid=['','pending_payment','new','preparing','ready','served','cancelled'];
  if(!in_array($status,$valid,true))fail('Invalid status');
  $orders=query('SELECT o.*,t.name AS table_name FROM orders o LEFT JOIN dining_tables t ON t.id=o.table_id WHERE (?="" OR o.status=?) ORDER BY o.created_at DESC,o.id DESC LIMIT 150',[$status,$status])->fetchAll();
  if($orders){
   $ids=array_map(fn($o)=>(int)$o['id'],$orders);
   $holders=implode(',',array_fill(0,count($ids),'?'));
   $items=query('SELECT order_id,product_name AS name,quantity,unit_price AS price FROM order_items WHERE order_id IN ('.$holders.') ORDER BY id',$ids)->fetchAll();
   $by=[];foreach($items as $item){$by[(int)$item['order_id']][]=$item;}
   foreach($orders as &$o){$o['items']=$by[(int)$o['id']]??[];}unset($o);
  }
  json_response(['orders'=>$orders]);
 }
 if($route==='orders' && $method==='POST'){
  $u=require_permission('orders:create');$d=request_data();
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
  $table=null;
  if(!empty($d['table_id']))$table=integer_id($d['table_id']);
  $name=trim_text($d['customer_name']??'',100);$notes=trim_text($d['notes']??'',500);
  $db=database();$db->beginTransaction();
  try {
   $existing=query('SELECT id,status,total,created_at FROM orders WHERE request_token=?',[$token])->fetch();
   if($existing){$db->commit();json_response(['order'=>$existing,'duplicate'=>true]);}
   if($table!==null && !query('SELECT id FROM dining_tables WHERE id=? AND active=1',[$table])->fetch())fail('Table not available');
   $ids=array_keys($quantities);$holder=implode(',',array_fill(0,count($ids),'?'));
   $products=query('SELECT id,name,price,available FROM products WHERE id IN ('.$holder.') ORDER BY id FOR UPDATE',$ids)->fetchAll();
   if(count($products)!==count($ids))fail('Product unavailable');
   $totalCents=0;
   foreach($products as $p){
    if(!$p['available'])fail('Product unavailable');
    $totalCents+=(int)round((float)$p['price']*100)*$quantities[(int)$p['id']];
   }
   $total=number_format($totalCents/100,2,'.','');
   query('INSERT INTO orders(request_token,table_id,customer_name,notes,total) VALUES(?,?,?,?,?)',[$token,$table,$name,$notes,$total]);
   $orderId=(int)$db->lastInsertId();
   foreach($products as $p){
    $q=$quantities[(int)$p['id']];$line=number_format(((int)round((float)$p['price']*100)*$q)/100,2,'.','');
    query('INSERT INTO order_items(order_id,product_id,product_name,quantity,unit_price,subtotal) VALUES(?,?,?,?,?,?)',[$orderId,$p['id'],$p['name'],$q,$p['price'],$line]);
   }
   audit((int)$u['id'],'order.create','order',$orderId);$db->commit();
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
  json_response(['staff'=>query('SELECT id,name,email,role,active,permissions,created_at FROM users ORDER BY id')->fetchAll()]);
 }
 if($route==='staff' && $method==='POST'){
  $u=require_permission('*');$d=request_data();
  $name=trim_text($d['name']??'',120);$email=strtolower(trim_text($d['email']??'',190));
  $role=$d['role']??'';$password=$d['password']??'';
  if(!$name||!filter_var($email,FILTER_VALIDATE_EMAIL)||!is_string($password)||strlen($password)<12||!in_array($role,['owner','manager','chef','waiter','staff'],true))fail('Invalid staff details');
  $permissions=$d['permissions']??[];
  if(!is_array($permissions)||array_is_list($permissions)&&count($permissions)>0)fail('Invalid permissions');
  $hash=password_hash($password,PASSWORD_DEFAULT);
  query('INSERT INTO users(name,email,password_hash,role,permissions) VALUES(?,?,?,?,?)',[$name,$email,$hash,$role,json_encode((object)$permissions)]);
  $newId=(int)database()->lastInsertId();audit((int)$u['id'],'staff.create','user',$newId);
  json_response(['staff'=>['id'=>$newId,'name'=>$name,'email'=>$email,'role'=>$role]],201);
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
  query('UPDATE users SET role=?,active=?,permissions=? WHERE id=?',[$role,(int)$active,json_encode((object)$permissions),$uid]);
  audit((int)$u['id'],'staff.update','user',$uid);
  json_response(['staff'=>query('SELECT id,name,email,role,active,permissions FROM users WHERE id=?',[$uid])->fetch()]);
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
 if($route==='tables' && $method==='POST'){
  $u=require_permission('tables:manage');$d=request_data();$name=trim_text($d['name']??'',100);
  if(!$name)fail('Table name required');
  query('INSERT INTO dining_tables(name) VALUES(?)',[$name]);$newId=(int)database()->lastInsertId();
  audit((int)$u['id'],'table.create','table',$newId);realtime_publish('menu');
  json_response(['table'=>['id'=>$newId,'name'=>$name]],201);
 }
 fail('Route not found',404);
} catch(PDOException $e) {
 error_log('[GANGCHIL] DB error: '.$e->getMessage());
 if($e->getCode()==='23000')fail('Duplicate value or conflicting record',409);
 fail('Database operation failed',500);
} catch(Throwable $e) {
 error_log('[GANGCHIL] '.$e->getMessage());fail('Server error',500);
}
