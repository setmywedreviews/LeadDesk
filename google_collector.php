<?php
/**
 * Google Places ID-only collector for SetMyWed.
 * Stores only Google Place IDs and our own campaign metadata.
 * Place details (name/phone/website) are fetched just-in-time by google_details.php.
 */
$c=require __DIR__.'/config.php';
function e($s){return trim((string)$s);}
function apiKey(){ $k=getenv('GOOGLE_MAPS_API_KEY'); if(!$k) throw new RuntimeException('GOOGLE_MAPS_API_KEY is missing'); return $k; }
function googlePost($body){
 $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json
X-Goog-Api-Key: ".apiKey()."
X-Goog-FieldMask: places.id,nextPageToken
",'content'=>json_encode($body),'timeout'=>30,'ignore_errors'=>true]]);
 $raw=@file_get_contents('https://places.googleapis.com/v1/places:searchText',false,$ctx);
 $code=0; foreach(($http_response_header??[]) as $h){if(preg_match('/^HTTP\/\S+\s+(\d+)/',$h,$m)){$code=(int)$m[1];}}
 if($raw===false) throw new RuntimeException('Google request failed');
 $json=json_decode($raw,true); if($code>=400) throw new RuntimeException('Google HTTP '.$code.': '.($json['error']['message']??'unknown'));
 return $json;
}
function searchIds($query){
 $all=[];$token=null;
 for($page=0;$page<3;$page++){
  $body=['textQuery'=>$query,'pageSize'=>20,'regionCode'=>'IN'];
  if($token)$body['pageToken']=$token;
  $r=googlePost($body);
  foreach(($r['places']??[]) as $p){if(!empty($p['id']))$all[$p['id']]=true;}
  $token=$r['nextPageToken']??null; if(!$token)break;
  usleep(250000);
 }
 return array_keys($all);
}
$db=$c['db'];
$pdo=new PDO("mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4",$db['user'],$db['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$users=$pdo->query("SELECT id,name,category FROM users WHERE role='sales' AND active=1 ORDER BY id")->fetchAll();
$today=[];
foreach($users as $u)$today[$u['id']]=(int)$pdo->query("SELECT COUNT(*) FROM leads WHERE assigned_to=".(int)$u['id']." AND DATE(created_at)=CURDATE()")->fetchColumn();
$targets=['Photography'=>50,'Makeup Artist'=>200];
$queries=[
 'Photography'=>['wedding photographer in %s, %s, India','candid wedding photographer in %s, %s, India','wedding photography studio in %s, %s, India'],
 'Makeup Artist'=>['bridal makeup artist in %s, %s, India','bridal makeup artist and salon in %s, %s, India','bridal makeup artist studio in %s, %s, India']
];
$cityRows=$pdo->query("SELECT city,state,tier FROM google_city_targets WHERE active=1 ORDER BY priority,id")->fetchAll();
$exists=$pdo->prepare("SELECT id FROM leads WHERE google_place_id=? LIMIT 1");
$insert=$pdo->prepare("INSERT INTO leads(business_name,category,city,source,source_url,lead_score,assigned_to,google_place_id,google_query) VALUES(?,?,?,?,?,?,?,?,?)");
$added=0;$duplicates=0;$errors=0;$searched=0;
function slotsLeft($cat,$users,$today,$target){
 $sum=0;foreach($users as $u)if($u['category']===$cat)$sum+=max(0,$target-(int)$today[$u['id']]);return $sum;
}
function pickUser($cat,$users,$today,$target){
 $best=null;$bestCount=PHP_INT_MAX;
 foreach($users as $u)if($u['category']===$cat && $today[$u['id']]<$target && $today[$u['id']]<$bestCount){$best=$u;$bestCount=$today[$u['id']];}
 return $best;
}
foreach($cityRows as $city){
 foreach($targets as $cat=>$target){
  if(slotsLeft($cat,$users,$today,$target)<=0)continue;
  foreach($queries[$cat] as $tpl){
   if(slotsLeft($cat,$users,$today,$target)<=0)break;
   $q=sprintf($tpl,$city['city'],$city['state']);$searched++;
   try{$ids=searchIds($q);}catch(Throwable $e){$errors++;error_log('Google collector: '.$e->getMessage());continue;}
   foreach($ids as $placeId){
    if(slotsLeft($cat,$users,$today,$target)<=0)break;
    $exists->execute([$placeId]);if($exists->fetchColumn()){$duplicates++;continue;}
    $u=pickUser($cat,$users,$today,$target);if(!$u)break;
    $map='https://www.google.com/maps/search/?api=1&query=Google&query_place_id='.rawurlencode($placeId);
    $insert->execute(['Google Place Lead',$cat,$city['city'],'Google Places',$map,60,$u['id'],$placeId,$q]);
    $today[$u['id']]++;$added++;
   }
   usleep(150000);
  }
 }
}
echo "Google collector finished | queries={$searched} | added={$added} | duplicates={$duplicates} | errors={$errors}
";
?>