<?php
declare(strict_types=1);
require_once is_file(__DIR__.'/private/bootstrap.php') ? __DIR__.'/private/bootstrap.php' : __DIR__.'/../private/bootstrap.php';
$u=require_permission('orders:view');
$id=integer_id($_GET['id']??null);
$order=query('SELECT o.*,t.name AS table_name FROM orders o LEFT JOIN dining_tables t ON t.id=o.table_id WHERE o.id=?',[$id])->fetch();
if(!$order){http_response_code(404);exit('Receipt not found');}
if(empty($order['paid_at'])){http_response_code(409);exit('Payment not yet confirmed');}
$items=query('SELECT product_name,quantity,unit_price,subtotal FROM order_items WHERE order_id=? ORDER BY id',[$id])->fetchAll();
function e(mixed $s): string{return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>GANGCHIL Receipt #<?=e($id)?></title>
<style>@page{size:80mm auto;margin:2mm}*{box-sizing:border-box}body{margin:0;color:#111;background:#fff;font:12px/1.4 Arial,sans-serif}.paper{width:76mm;max-width:100%;padding:6px}h1{text-align:center;font-size:19px;margin:3px 0}p{text-align:center;margin:3px 0}.row{display:flex;justify-content:space-between;gap:8px;padding:3px 0}.line{border-top:1px dashed #555;margin:8px 0}.bold{font-weight:700}.actions{margin:10px;text-align:center}button{padding:10px 22px;background:#0f3d2e;color:white;border:0}@media print{.actions{display:none}body{width:80mm}}</style></head><body>
<div class="paper"><h1>GANGCHIL</h1><p>Restaurant &amp; Kabsa Place</p><p>Cash Receipt</p><div class="line"></div>
<div class="row"><span>Receipt #</span><strong><?=e($id)?></strong></div>
<div class="row"><span>Date</span><span><?=e($order['paid_at'])?></span></div>
<div class="row"><span>Table</span><span><?=e($order['table_name']??'Takeaway')?></span></div>
<div class="line"></div>
<?php foreach($items as $item): ?>
<div><?=e($item['product_name'])?></div>
<div class="row"><span><?=e($item['quantity'])?> x <?=e(number_format((float)$item['unit_price'],2))?></span><span><?=e(number_format((float)$item['subtotal'],2))?></span></div>
<?php endforeach; ?>
<div class="line"></div><div class="row bold"><span>TOTAL CASH</span><span>BDT <?=e(number_format((float)$order['total'],2))?></span></div><div class="line"></div>
<p>Thank you for visiting!</p></div><div class="actions"><button onclick="window.print()">Print Receipt</button></div></body></html>