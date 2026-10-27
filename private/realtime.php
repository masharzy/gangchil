<?php
declare(strict_types=1);
// Pusher Channels adapter: use an authenticated private channel.
// No Composer, Node.js, or long-running PHP process required.
function realtime_enabled(): bool {
 foreach(['PUSHER_APP_ID','PUSHER_KEY','PUSHER_SECRET','PUSHER_CLUSTER'] as $name){
  if(!defined($name) || !is_string(constant($name)) || constant($name)==='' ||
     str_starts_with(constant($name),'your-'))return false;
 }
 return (bool)preg_match('/^[a-zA-Z0-9_-]+$/',PUSHER_APP_ID)
    &&(bool)preg_match('/^[a-zA-Z0-9_-]+$/',PUSHER_CLUSTER);
}
function realtime_private_auth(string $socketId,string $channel): array {
 if(!realtime_enabled())throw new RuntimeException('Realtime not configured');
 if(!preg_match('/^[0-9]+\.[0-9]+$/D',$socketId))throw new InvalidArgumentException('Invalid socket');
 if($channel!=='private-gangchil-staff')throw new InvalidArgumentException('Invalid channel');
 $signature=hash_hmac('sha256',$socketId.':'.$channel,PUSHER_SECRET);
 return ['auth'=>PUSHER_KEY.':'.$signature];
}
function realtime_publish(string $kind): bool {
 if(!realtime_enabled())return false;
 $path='/apps/'.PUSHER_APP_ID.'/events';
 $payload=json_encode([
  'name'=>'gangchil:changed',
  'channels'=>['private-gangchil-staff'],
  // Deliberately send no names, table numbers or order contents through Pusher.
  'data'=>json_encode(['kind'=>$kind,'at'=>time()],JSON_UNESCAPED_SLASHES)
 ],JSON_UNESCAPED_SLASHES);
 if($payload===false)return false;
 $params=[
  'auth_key'=>PUSHER_KEY,
  'auth_timestamp'=>(string)time(),
  'auth_version'=>'1.0',
  'body_md5'=>md5($payload),
 ];
 $query=http_build_query($params,'','&',PHP_QUERY_RFC3986);
 $signature=hash_hmac('sha256',"POST\n".$path."\n".$query,PUSHER_SECRET);
 $url='https://api-'.PUSHER_CLUSTER.'.pusher.com'.$path.'?'.$query.'&auth_signature='.$signature;
 // PHP cURL is widely available on shared hosting; if missing, fallback to HTTPS streams.
 try {
  if(function_exists('curl_init')){
   $h=curl_init($url);
   if($h===false)return false;
   curl_setopt_array($h,[
    CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT_MS=>900,
    CURLOPT_TIMEOUT_MS=>2500,CURLOPT_SSL_VERIFYPEER=>true,
    CURLOPT_SSL_VERIFYHOST=>2,
   ]);
   $response=curl_exec($h);
   $status=(int)curl_getinfo($h,CURLINFO_HTTP_CODE);
   $error=curl_error($h);
   curl_close($h);
   if($response===false||$status!==200){
    error_log('[GANGCHIL] Realtime event rejected: HTTP '.$status.' '.$error);
    return false;
   }
   return true;
  }
  $context=stream_context_create(['http'=>[
   'method'=>'POST',
   'header'=>"Content-Type: application/json\r\n",
   'content'=>$payload,'timeout'=>2.5,
   'ignore_errors'=>true,
  ]]);
  $response=@file_get_contents($url,false,$context);
  $header=$http_response_header[0]??'';
  if($response===false||!preg_match('/^HTTP\/\S+ 200 /',$header)){
   error_log('[GANGCHIL] Realtime event HTTP stream failed');
   return false;
  }
  return true;
 }catch(Throwable $e){
  error_log('[GANGCHIL] Realtime publish exception: '.$e->getMessage());
  return false;
 }
}
