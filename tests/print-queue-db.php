<?php
declare(strict_types=1);
$_SERVER['REQUEST_URI']='/staff/api/printing/test';
require __DIR__.'/../private/bootstrap.php';
require __DIR__.'/../private/operations.php';
require __DIR__.'/../private/print_queue.php';
if(DB_NAME!=='gangchil_ci')throw new RuntimeException('Disposable CI database required');
if(isset($argv[1])){
 $args=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR);
 $_SERVER['HTTP_X_PRINT_TOKEN']=$args['token']??'';$_SESSION['uid']=1;$_SESSION['csrf']='ci-print-csrf';$_SERVER['HTTP_X_CSRF_TOKEN']='ci-print-csrf';
 $_SERVER['HTTP_X_PRINT_PAIR']=$args['proof']??'';$_SERVER['HTTP_X_PRINT_DEVICE']=$args['key']??'';if(!empty($args['guest']))unset($_SESSION['uid']);
 print_routes($args['route'],$args['method']??'POST',$args['data']??[]);exit;
}
function check(bool $value,string $message): void {if(!$value)throw new RuntimeException($message);}
foreach(preg_split('/;[\t ]*(?:\r?\n|$)/',file_get_contents(__DIR__.'/../db/mysql.sql')) as $sql)if(trim($sql)!=='')database()->exec($sql);
ensure_pin_schema();ensure_service_schema();ensure_cash_workflow();ensure_thermal_schema();print_pair_schema();
$tokens=[str_repeat('a',64),str_repeat('b',64)];
foreach($tokens as $i=>$token)query('INSERT INTO print_devices(user_id,device_key,name,token_hash,printer_id) VALUES(1,?,?,?,1)',['ci-device-'.str_repeat((string)$i,24),'CI station '.$i,hash('sha256',$token)]);
foreach([1,2] as $i){query("INSERT INTO orders(request_token,status,total,paid_at,table_label) VALUES(?,'confirmed',10,NOW(),'1')",['ci-order-'.str_repeat((string)$i,24)]);print_enqueue((int)database()->lastInsertId());}
function spawn(string $route,array $data=[],int $device=0): array {
 global $tokens;$pipes=[];$proc=proc_open([PHP_BINARY,__FILE__,json_encode(['route'=>$route,'data'=>$data,'token'=>$tokens[$device]])],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);return [$proc,$pipes];
}
function result(array $task): array {[$proc,$pipes]=$task;$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($proc);if($exit!==0||!($json=json_decode($out,true)))throw new RuntimeException('Child route failed: '.$err.' '.$out);return $json;}
function call_route(string $route,array $data=[],int $device=0): array {return result(spawn('printing/'.$route,$data,$device));}
// Two processes compete for one physical printer. Only one may claim any job.
$a=spawn('printing/worker/claim',[],0);$b=spawn('printing/worker/claim',[],1);$aa=result($a);$bb=result($b);
check((int)!empty($aa['job'])+(int)!empty($bb['job'])===1,'Two devices claimed the same printer concurrently');
$winner=!empty($aa['job'])?0:1;$job=($winner===0?$aa:$bb)['job'];$ref=['id'=>$job['id'],'claim'=>$job['claim']];
check(!empty(call_route('worker/begin',$ref,$winner)['ok']),'Begin failed');
check(isset(call_route('worker/begin',$ref,$winner)['error']),'Duplicate begin permitted another print');
check(!empty(call_route('worker/result',$ref+['status'=>'submitted'],$winner)['ok']),'Result failed');
check(!empty(call_route('worker/result',$ref+['status'=>'submitted'],$winner)['ok']),'Acknowledgement retry failed');
// Before begin, an expired claim can safely move to another station.
$next=call_route('worker/claim',[],$winner)['job'];query('UPDATE receipt_jobs SET lease_until=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE id=?',[$next['id']]);
$other=1-$winner;$replacement=call_route('worker/claim',[],$other)['job'];check($replacement['id']===$next['id']&&$replacement['claim']!==$next['claim'],'Expired claim did not transfer');
check(isset(call_route('worker/begin',['id'=>$next['id'],'claim'=>$next['claim']],$winner)['error']),'Stale station could dispatch');
$r=['id'=>$replacement['id'],'claim'=>$replacement['claim']];call_route('worker/begin',$r,$other);call_route('worker/recover',$r,$other);
check(call_route('worker/claim',[],$winner)['job']===null,'Uncertain physical delivery was blindly reassigned');
call_route('resolve',['id'=>$replacement['id'],'action'=>'retry','checked_paper'=>true],$winner);
$retry=call_route('worker/claim',[],$winner)['job'];check($retry['id']!==$replacement['id'],'Manual retry must have a new delivery identity');
query('UPDATE print_devices SET enabled=0 WHERE device_key=?',['ci-device-'.str_repeat((string)$other,24)]);check(isset(call_route('worker/claim',[],$other)['error']),'Revoked station retained access');
// A guest can request pairing but cannot approve itself or read receipts.
function guest_route(string $route,string $method='GET',array $data=[],string $proof='',string $key=''): array {
 $pipes=[];$proc=proc_open([PHP_BINARY,__FILE__,json_encode(['route'=>'printing/'.$route,'method'=>$method,'data'=>$data,'guest'=>true,'proof'=>$proof,'key'=>$key])],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);return result([$proc,$pipes]);
}
$proof=str_repeat('c',64);$key='guest-'.str_repeat('x',24);
$pair=guest_route('pair','POST',['device_key'=>$key,'name'=>'Customer tablet','proof'=>$proof]);check(strlen($pair['code']??'')===6,'Guest setup code missing');
check(!guest_route('pair-status','GET',[],$proof,$key)['approved'],'Guest approved itself');
check(isset(guest_route('settings')['error']),'Guest read shared printer details');
check(isset(guest_route('approve','POST',['device_key'=>$key])['error']),'Guest approved print access');
check(!empty(call_route('approve',['device_key'=>$key])['ok']),'Owner approval failed');
check(guest_route('pair-status','GET',[],$proof,$key)['approved'],'Approved device cannot activate');
call_route('worker/heartbeat',['online'=>true],$winner);check(guest_route('status')['online'],'Connected printer not shared');
call_route('worker/heartbeat',['online'=>false],$winner);check(!guest_route('status')['online'],'Disconnected printer remained online');
query("UPDATE print_devices SET connection_state='online',connection_at=DATE_SUB(NOW(),INTERVAL 30 SECOND) WHERE token_hash=?",[hash('sha256',$tokens[$winner])]);check(!guest_route('status')['online'],'Stale printer remained online');
echo "PASS: real MySQL concurrent claims, printer serialization, fencing, lease failover, duplicate begin, acknowledgement retry, uncertain delivery hold, explicit retry, device revocation\n";
