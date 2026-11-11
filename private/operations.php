<?php
declare(strict_types=1);
function ensure_operations_schema(): void {
 static $ready=false;if($ready)return;$db=database();
 try {if(query("SELECT version FROM app_migrations WHERE version='operations_v2'")->fetchColumn()){$ready=true;return;}}
 catch(PDOException $e){if($e->getCode()!=='42S02')throw $e;$db->exec('CREATE TABLE IF NOT EXISTS app_migrations(version VARCHAR(80) PRIMARY KEY,applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');}
 ensure_owner_schema();
 $db->exec('CREATE TABLE IF NOT EXISTS staff_preferences(user_id BIGINT UNSIGNED PRIMARY KEY,alerts_mute_until BIGINT NOT NULL DEFAULT 0) ENGINE=InnoDB');
 $db->exec('CREATE TABLE IF NOT EXISTS product_sales(order_item_id BIGINT UNSIGNED PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,product_id BIGINT UNSIGNED NULL,product_key VARCHAR(190) NOT NULL,product_name VARCHAR(160) NOT NULL,quantity INT NOT NULL,subtotal DECIMAL(14,2) NOT NULL,sold_at DATETIME NOT NULL,totals_accounted TINYINT NOT NULL DEFAULT 0,INDEX(sold_at,product_id),INDEX(order_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
 $db->exec('CREATE TABLE IF NOT EXISTS product_sales_totals(product_key VARCHAR(190) PRIMARY KEY,product_id BIGINT UNSIGNED NULL,product_name VARCHAR(160) NOT NULL,quantity BIGINT NOT NULL DEFAULT 0,revenue DECIMAL(16,2) NOT NULL DEFAULT 0,orders_count BIGINT NOT NULL DEFAULT 0,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
 $db->beginTransaction();try {
  $claim=query("INSERT IGNORE INTO app_migrations(version) VALUES('operations_v2')");
  if($claim->rowCount()){
   query("INSERT IGNORE INTO product_sales(order_item_id,order_id,product_id,product_key,product_name,quantity,subtotal,sold_at) SELECT i.id,i.order_id,i.product_id,IF(i.product_id IS NULL,CONCAT('legacy:',SHA2(i.product_name,256)),CONCAT('product:',i.product_id)),i.product_name,i.quantity,i.subtotal,o.paid_at FROM order_items i JOIN orders o ON o.id=i.order_id WHERE o.paid_at IS NOT NULL AND o.status<>'cancelled'");
   query('INSERT INTO product_sales_totals(product_key,product_id,product_name,quantity,revenue,orders_count) SELECT product_key,MAX(product_id),MAX(product_name),SUM(quantity),SUM(subtotal),COUNT(DISTINCT order_id) FROM product_sales GROUP BY product_key ON DUPLICATE KEY UPDATE quantity=VALUES(quantity),revenue=VALUES(revenue),orders_count=VALUES(orders_count),product_name=VALUES(product_name)');
   query('UPDATE product_sales SET totals_accounted=1');
  }$db->commit();$ready=true;
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
// Called inside the cash-confirmation transaction. Repeated calls cannot count a sale twice.
function record_product_sales(int $orderId): void {
 query("INSERT IGNORE INTO product_sales(order_item_id,order_id,product_id,product_key,product_name,quantity,subtotal,sold_at) SELECT i.id,i.order_id,i.product_id,IF(i.product_id IS NULL,CONCAT('legacy:',SHA2(i.product_name,256)),CONCAT('product:',i.product_id)),i.product_name,i.quantity,i.subtotal,o.paid_at FROM order_items i JOIN orders o ON o.id=i.order_id WHERE o.id=? AND o.paid_at IS NOT NULL AND o.status<>'cancelled'",[$orderId]);
 query('INSERT INTO product_sales_totals(product_key,product_id,product_name,quantity,revenue,orders_count) SELECT product_key,MAX(product_id),MAX(product_name),SUM(quantity),SUM(subtotal),COUNT(DISTINCT order_id) FROM product_sales WHERE order_id=? AND totals_accounted=0 GROUP BY product_key ON DUPLICATE KEY UPDATE quantity=product_sales_totals.quantity+VALUES(quantity),revenue=product_sales_totals.revenue+VALUES(revenue),orders_count=product_sales_totals.orders_count+VALUES(orders_count),product_name=VALUES(product_name)',[$orderId]);
 query('UPDATE product_sales SET totals_accounted=1 WHERE order_id=? AND totals_accounted=0',[$orderId]);
}
function db_clock_offset(): int {static $offset=null;if($offset===null)$offset=(int)query('SELECT TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),NOW())')->fetchColumn();return $offset;}
function db_time_iso(string $value): string {return (new DateTimeImmutable('@'.(strtotime($value.' UTC')-db_clock_offset())))->setTimezone(new DateTimeZone('Asia/Dhaka'))->format(DATE_ATOM);}
function valid_day(string $value): bool {if(!preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D',$value))return false;$date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('Asia/Dhaka'));return $date&&$date->format('Y-m-d')===$value;}
function report_range(array $input): array {
 $mode=(string)($input['mode']??'range');if($mode==='history')return ['mode'=>'history','from'=>null,'to'=>null];
 $from=(string)($input['from']??date('Y-m-d'));$to=(string)($input['to']??$from);
 if(!valid_day($from)||!valid_day($to)||$from>$to)fail('Choose a valid date or date range.');
 return ['mode'=>$mode,'from'=>$from,'to'=>$to];
}
function db_day_bounds(string $from,string $to): array {
 $zone=new DateTimeZone('Asia/Dhaka');$start=new DateTimeImmutable($from.' 00:00:00',$zone);$end=(new DateTimeImmutable($to.' 00:00:00',$zone))->modify('+1 day');$offset=db_clock_offset();return [gmdate('Y-m-d H:i:s',$start->getTimestamp()+$offset),gmdate('Y-m-d H:i:s',$end->getTimestamp()+$offset)];
}
function range_where(array $range,string $column): array {if($range['mode']==='history')return ['1=1',[]];[$a,$b]=db_day_bounds($range['from'],$range['to']);return [$column.'>=? AND '.$column.'<?',[$a,$b]];}
function report_data(array $range): array {
 [$paid,$pp]=range_where($range,'o.paid_at');[$created,$cp]=range_where($range,'o.created_at');[$sold,$sp]=range_where($range,'s.sold_at');
 $sales=query("SELECT COUNT(*) AS paid_orders,COALESCE(SUM(o.total),0) AS revenue FROM orders o WHERE o.paid_at IS NOT NULL AND o.status<>'cancelled' AND ".$paid,$pp)->fetch();
 $placed=(int)query('SELECT COUNT(*) FROM orders o WHERE '.$created,$cp)->fetchColumn();
 $expenseWhere=$range['mode']==='history'?'1=1':'expense_date>=? AND expense_date<=?';$ep=$range['mode']==='history'?[]:[$range['from'],$range['to']];
 $expenses=query('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE '.$expenseWhere,$ep)->fetchColumn();
 $products=query('SELECT s.product_key,MAX(s.product_id) AS product_id,COALESCE(MAX(p.name),MAX(s.product_name)) AS name,SUM(s.quantity) AS quantity,SUM(s.subtotal) AS revenue,COUNT(DISTINCT s.order_id) AS orders_count FROM product_sales s LEFT JOIN products p ON p.id=s.product_id WHERE '.$sold.' GROUP BY s.product_key ORDER BY quantity DESC,revenue DESC,s.product_key',$sp)->fetchAll();
 $units=0;foreach($products as $product)$units+=(int)$product['quantity'];
 return ['range'=>$range,'generated_at'=>(new DateTimeImmutable('now',new DateTimeZone('Asia/Dhaka')))->format(DATE_ATOM),'sales'=>$sales['revenue'],'paid_orders'=>(int)$sales['paid_orders'],'orders_placed'=>$placed,'units_sold'=>$units,'expenses'=>$expenses,'profit'=>number_format((float)$sales['revenue']-(float)$expenses,2,'.',''),'most_sold'=>$products[0]??null,'products'=>$products];
}
function csv_cell(mixed $value): string {$value=(string)$value;if(preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D',$value))return $value;return preg_match('/^[\s]*[=+@\-]/u',$value)?"'".$value:$value;}
function export_report(array $data,string $format): never {
 $range=$data['range'];$label=$range['mode']==='history'?'Entire history':$range['from'].' to '.$range['to'];
 header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');
 if($format==='csv'){
  header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="gangchil-report.csv"');$out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");
  foreach([['GANGCHIL report',$label],['Timezone','Asia/Dhaka'],['Sales (BDT)',$data['sales']],['Expenses (BDT)',$data['expenses']],['Profit (BDT)',$data['profit']],['Orders placed',$data['orders_placed']],['Paid orders',$data['paid_orders']],['Units sold',$data['units_sold']],['Most sold product',$data['most_sold']['name']??'None'],[],['Product','Units sold','Sales (BDT)','Paid orders']] as $row)fputcsv($out,array_map('csv_cell',$row),',','"','');
  foreach($data['products'] as $p)fputcsv($out,array_map('csv_cell',[$p['name'],$p['quantity'],$p['revenue'],$p['orders_count']]),',','"','');fclose($out);exit;
 }
 header('Content-Type: text/html; charset=utf-8');if($format==='html')header('Content-Disposition: attachment; filename="gangchil-report.html"');
 $e=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
 echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>GANGCHIL report</title><style>body{font:14px/1.6 system-ui;margin:35px;color:#10201a}header{border-bottom:2px solid #0d3b2c}h1{font-family:Georgia}table{width:100%;border-collapse:collapse;margin-top:25px}th,td{text-align:left;padding:10px;border-bottom:1px solid #ddd}.metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:15px;margin:25px 0}.metric{border:1px solid #ddd;padding:15px}.metric b{display:block;font-size:22px}@media print{button{display:none}body{margin:10mm}tr{break-inside:avoid}}</style><header><h1>GANGCHIL</h1><p>Restaurant sales report · '.$e($label).'</p></header><div class="metrics">';
 foreach(['Sales (BDT)'=>$data['sales'],'Expenses (BDT)'=>$data['expenses'],'Profit (BDT)'=>$data['profit'],'Orders placed'=>$data['orders_placed'],'Paid orders'=>$data['paid_orders'],'Units sold'=>$data['units_sold']] as $key=>$value)echo '<div class="metric">'.$e($key).'<b>'.$e(is_numeric($value)?number_format((float)$value,str_contains($key,'orders')||str_contains($key,'Orders')||str_contains($key,'Units')?0:2):$value).'</b></div>';
 echo '</div><p>Sales and units count paid, non-cancelled orders. Order placement and payment may fall in different periods. Dates use Asia/Dhaka.</p><p>Most sold product: <b>'.$e($data['most_sold']['name']??'None').'</b></p><table><thead><tr><th>Product</th><th>Units sold</th><th>Sales (BDT)</th><th>Paid orders</th></tr></thead><tbody>';
 foreach($data['products'] as $p)echo '<tr><td>'.$e($p['name']).'</td><td>'.$e($p['quantity']).'</td><td>'.$e(number_format((float)$p['revenue'],2)).'</td><td>'.$e($p['orders_count']).'</td></tr>';
 echo '</tbody></table><p>Generated '.$e($data['generated_at']).'</p><button onclick="window.print()">Print / Save as PDF</button></html>';exit;
}

function ensure_service_schema(): void {
 static $ready=false;if($ready)return;
 if(!query("SHOW COLUMNS FROM orders LIKE 'table_label'")->fetch()){
  try{query("ALTER TABLE orders ADD COLUMN table_label VARCHAR(40) NOT NULL DEFAULT ''");}catch(PDOException $e){if(($e->errorInfo[1]??0)!==1060)throw $e;}
 }
 if(!query("SHOW COLUMNS FROM users LIKE 'deleted_at'")->fetch()){
  try{query('ALTER TABLE users ADD COLUMN deleted_at DATETIME NULL');}catch(PDOException $e){if(($e->errorInfo[1]??0)!==1060)throw $e;}
 }$ready=true;
}
function order_number(int $id): string {return str_pad((string)$id,6,'0',STR_PAD_LEFT);}

function admin_page(): int {$p=(string)($_GET['page']??'1');if(!preg_match('/^[0-9]+$/D',$p)||(int)$p<1||(int)$p>1000000)fail('Invalid page');return (int)$p;}

function ensure_cash_workflow(): void {
 static $ready=false;if($ready)return;
 query('CREATE TABLE IF NOT EXISTS app_migrations(version VARCHAR(80) PRIMARY KEY,applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
 if(query("SELECT version FROM app_migrations WHERE version='simple_cash_v1'")->fetchColumn()){$ready=true;return;}
 $lock='gc_cash_'.substr(hash('sha256',DB_NAME),0,32);
 if(!(int)query('SELECT GET_LOCK(?,10)',[$lock])->fetchColumn())throw new RuntimeException('Workflow update in progress. Retry shortly.');
 try{
  if(query("SELECT version FROM app_migrations WHERE version='simple_cash_v1'")->fetchColumn()){$ready=true;return;}
  query("ALTER TABLE orders MODIFY status ENUM('pending_payment','confirmed','completed','cancelled','new','preparing','ready','served') NOT NULL DEFAULT 'pending_payment'");
  foreach(['receipt_printed_at'=>'DATETIME NULL','receipt_printed_by'=>'BIGINT UNSIGNED NULL'] as $column=>$type){if(!query("SHOW COLUMNS FROM orders LIKE '".$column."'")->fetch())query('ALTER TABLE orders ADD COLUMN '.$column.' '.$type);}
  query("UPDATE orders SET status='confirmed' WHERE status IN ('new','preparing','ready')");
  query("UPDATE orders SET status='completed' WHERE status='served'");
  query("UPDATE users SET active=0,role='manager' WHERE role NOT IN ('owner','manager')");
  query("ALTER TABLE orders MODIFY status ENUM('pending_payment','confirmed','completed','cancelled') NOT NULL DEFAULT 'pending_payment'");
  query("ALTER TABLE users MODIFY role ENUM('owner','manager') NOT NULL");
  query("INSERT IGNORE INTO app_migrations(version) VALUES('simple_cash_v1')");$ready=true;
 }finally{query('SELECT RELEASE_LOCK(?)',[$lock]);}
}
function table_number(mixed $value): string {if(!is_scalar($value))return '';return preg_match('/^(?:Table\s*)?([1-5])$/iD',trim((string)$value),$m)?$m[1]:'';}
function receipt_html(array $order,array $items): string {
 $e=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$lines='';
 foreach($items as $i)$lines.='<div class="item">'.$e($i['product_name']).'</div><div class="row"><span>'.$e($i['quantity']).' × '.$e(number_format((float)$i['unit_price'],2)).'</span><b>'.$e(number_format((float)$i['subtotal'],2)).'</b></div>';
 return '<!doctype html><html><head><meta charset="utf-8"><title>Receipt #'.$e(order_number((int)$order['id'])).'</title><style>@page{size:58mm auto;margin:0}*{box-sizing:border-box}body{margin:0;padding:0;width:48mm;max-width:100%;font:12px/1.5 "Hind Siliguri",Arial,sans-serif;color:#111;background:white}h1,p{text-align:center;margin:4px 0}h1{font-size:20px}.row{display:flex;justify-content:space-between;gap:5px;padding:3px 0;flex-wrap:wrap}.line{border-top:1px dashed #555;margin:8px 0}.total{font-size:16px;font-weight:bold}.item{margin-top:7px;overflow-wrap:anywhere}.row>span,.row>b{overflow-wrap:anywhere;max-width:100%}</style></head><body><h1>GANGCHIL</h1><p>Cash receipt</p><div class="line"></div><div class="row"><span>Order</span><b>#'.$e(order_number((int)$order['id'])).'</b></div><div class="row"><span>Table</span><b>'.$e($order['table_label']).'</b></div><div class="row"><span>Paid</span><span>'.$e(db_time_iso($order['paid_at'])).'</span></div>'.($order['customer_name']?'<div class="row"><span>Customer</span><b>'.$e($order['customer_name']).'</b></div>':'').'<div class="line"></div>'.$lines.'<div class="line"></div><div class="row total"><span>TOTAL CASH</span><span>BDT '.$e(number_format((float)$order['total'],2)).'</span></div><p>Thank you for visiting!</p></body></html>';
}

function audit_action_names(): array {return ['receipt.submitted'=>'Receipt sent to printer','customer.order.create'=>'Customer placed an order','order.create'=>'Staff placed an order','order.pay'=>'Cash payment confirmed','order.prepare'=>'Preparation started','order.ready'=>'Order marked ready','order.serve'=>'Order served','order.cancel'=>'Unpaid order cancelled','order.complete'=>'Receipt printed · Order completed','staff.create'=>'Staff account added','staff.update'=>'Staff access updated','staff.remove'=>'Staff account removed','staff.access'=>'Login details updated','category.create'=>'Category added','category.update'=>'Category renamed','category.delete'=>'Category deleted','product.create'=>'Menu item added','product.update'=>'Menu item updated','expense.create'=>'Expense recorded','auth.login'=>'Signed in','auth.logout'=>'Signed out'];}

function ensure_product_lifecycle(): void {
 static $ready=false;if($ready)return;
 if(query("SELECT version FROM app_migrations WHERE version='product_lifecycle_v1'")->fetchColumn()){$ready=true;return;}
 foreach(['published'=>'TINYINT(1) NOT NULL DEFAULT 1','deleted_at'=>'DATETIME NULL'] as $column=>$definition){try{query('ALTER TABLE products ADD COLUMN '.$column.' '.$definition);}catch(PDOException $e){if((int)($e->errorInfo[1]??0)!==1060)throw $e;}}
 query("INSERT IGNORE INTO app_migrations(version) VALUES('product_lifecycle_v1')");$ready=true;
}

function ensure_thermal_schema(): void {
 static $ready=false;if($ready)return;
 if(query("SELECT version FROM app_migrations WHERE version='thermal_submit_v1'")->fetchColumn()){$ready=true;return;}
 foreach(['receipt_submitted_at'=>'DATETIME NULL','receipt_submission_job'=>'VARCHAR(100) NULL'] as $column=>$type){try{query('ALTER TABLE orders ADD COLUMN '.$column.' '.$type);}catch(PDOException $e){if((int)($e->errorInfo[1]??0)!==1060)throw $e;}}
 query("INSERT IGNORE INTO app_migrations(version) VALUES('thermal_submit_v1')");$ready=true;
}
