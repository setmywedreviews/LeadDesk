<?php
if (PHP_SAPI !== 'cli') { session_start(); if (($_SESSION['role'] ?? '') !== 'admin') { http_response_code(403); exit('Admin only'); } }
$c=require __DIR__.'/config.php'; $localities=require __DIR__.'/google_localities.php';
function apiKey(){ $k=getenv('GOOGLE_MAPS_API_KEY'); if(!$k) throw new RuntimeException('GOOGLE_MAPS_API_KEY is missing'); return $k; }
function googleRequest($method,$url,$body=null,$fieldMask=''){
 $ch=curl_init($url);
 $headers=['Content-Type: application/json','X-Goog-Api-Key: '.apiKey()];
 if($fieldMask!=='')$headers[]='X-Goog-FieldMask: '.$fieldMask;
 $opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_HTTPHEADER=>$headers];
 if($method==='POST'){$opts[CURLOPT_POST]=true;$opts[CURLOPT_POSTFIELDS]=json_encode($body);}
 curl_setopt_array($ch,$opts);
 $raw=curl_exec($ch);$err=curl_error($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
 if($raw===false)throw new RuntimeException('Google connection failed: '.$err);
 $json=json_decode($raw,true);
 if($code>=400)throw new RuntimeException('Google HTTP '.$code.': '.($json['error']['message']??'unknown error'));
 if(!is_array($json))throw new RuntimeException('Google returned invalid JSON');
 return $json;
}
function searchIds($query){
 $all=[];$token=null;
 for($page=0;$page<2;$page++){
  $body=['textQuery'=>$query,'pageSize'=>20,'regionCode'=>'IN'];
  if($token)$body['pageToken']=$token;
  $r=googleRequest('POST','https://places.googleapis.com/v1/places:searchText',$body,'places.id,nextPageToken');
  foreach(($r['places']??[]) as $p)if(!empty($p['id']))$all[$p['id']]=true;
  $token=$r['nextPageToken']??null;
  if(!$token)break;
  usleep(250000);
 }
 return array_keys($all);
}
function googleDetails($placeId){
 try{
  return googleRequest('GET','https://places.googleapis.com/v1/places/'.rawurlencode($placeId),null,'id,displayName,websiteUri,internationalPhoneNumber,nationalPhoneNumber,googleMapsUri');
 }catch(Throwable $e){
  error_log('Google details '.$placeId.': '.$e->getMessage());
  return [];
 }
}
function findInstagramOnWebsite($url){ if(!$url)return ''; $ctx=stream_context_create(['http'=>['method'=>'GET','header'=>"User-Agent: Mozilla/5.0 SetMyWedLeadDesk\r\nAccept: text/html\r\n",'timeout'=>5,'ignore_errors'=>true]]); $html=@file_get_contents($url,false,$ctx); if(!$html)return ''; if(preg_match_all('~https?://(?:www\.)?instagram\.com/[A-Za-z0-9._%/?=-]+~i',$html,$m)){ foreach($m[0] as $u){$u=html_entity_decode($u,ENT_QUOTES,'UTF-8');$u=preg_replace('/[?#].*$/','',$u);if(preg_match('~instagram\.com/(?!p/|reel/|reels/|stories/|explore/|accounts/)[A-Za-z0-9._-]+/?$~i',$u))return rtrim($u,'/');} } return ''; }
$db=$c['db']; $pdo=new PDO("mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4",$db['user'],$db['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$schemaErrors=[];
foreach([
 "ALTER TABLE leads ADD COLUMN locality VARCHAR(120) NULL AFTER city",
 "ALTER TABLE leads ADD COLUMN phone VARCHAR(40) NULL",
 "ALTER TABLE leads ADD COLUMN website VARCHAR(255) NULL",
 "ALTER TABLE leads ADD COLUMN instagram VARCHAR(255) NULL",
 "ALTER TABLE leads ADD INDEX idx_city_locality(city,locality)"
] as $sql){
 try{$pdo->exec($sql);}catch(Throwable $e){
  $msg=$e->getMessage();
  if(!preg_match('/Duplicate column name|Duplicate key name|already exists/i',$msg))$schemaErrors[]=$msg;
 }
}
if($schemaErrors){
 http_response_code(500);
 echo "Google collector database setup error: ".htmlspecialchars(implode(' | ',$schemaErrors));
 exit;
}
$users=$pdo->query("SELECT id,name,category FROM users WHERE role='sales' AND active=1 ORDER BY id")->fetchAll(); $today=[]; foreach($users as $u)$today[$u['id']]=(int)$pdo->query("SELECT COUNT(*) FROM leads WHERE assigned_to=".(int)$u['id']." AND DATE(created_at)=CURDATE()")->fetchColumn();
$targets=['Photography'=>50,'Makeup Artist'=>200]; $queries=['Photography'=>['wedding photographer in %s, %s, India','candid wedding photographer in %s, %s, India','freelance wedding photographer in %s, %s, India','wedding photography team in %s, %s, India'],'Makeup Artist'=>['freelance bridal makeup artist in %s, %s, India','freelance MUA in %s, %s, India','freelance wedding makeup artist in %s, %s, India','independent bridal makeup artist in %s, %s, India','mobile bridal makeup artist in %s, %s, India','bridal makeup artist home service in %s, %s, India','on location bridal makeup artist in %s, %s, India']];
$cityRows=$pdo->query("SELECT city,state,tier FROM google_city_targets WHERE active=1 ORDER BY CASE WHEN LOWER(city)='noida' THEN 0 ELSE 1 END, priority,id")->fetchAll(); $cityCount=count($cityRows); $dayNumber=(int)floor(time()/86400); $cityOffset=0;
$exists=$pdo->prepare("SELECT id FROM leads WHERE google_place_id=? LIMIT 1");
$phoneExists=$pdo->prepare("SELECT id FROM leads WHERE phone=? AND category=? LIMIT 1");
$insert=$pdo->prepare("INSERT INTO leads(business_name,category,city,locality,phone,website,instagram,source,source_url,lead_score,assigned_to,google_place_id,google_query) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)");
$added=0;$duplicates=0;$errors=0;$searched=0;$areas=0;$instagramFound=0;$detailsMissing=0;$maxPerRequest=60;
function slotsLeft($cat,$users,$today,$target){$sum=0;foreach($users as $u)if($u['category']===$cat)$sum+=max(0,$target-(int)$today[$u['id']]);return $sum;}
function pickUser($cat,$users,$today,$target){$best=null;$bestCount=PHP_INT_MAX;foreach($users as $u)if($u['category']===$cat&&$today[$u['id']]<$target&&$today[$u['id']]<$bestCount){$best=$u;$bestCount=$today[$u['id']];}return $best;}
foreach($cityRows as $city){ $areasForCity=$localities[$city['city']]??[]; $lc=count($areasForCity); if($lc){$start=(($dayNumber+$cityOffset)%$lc);$areasForCity=array_merge(array_slice($areasForCity,$start),array_slice($areasForCity,0,$start));$areasForCity=array_slice($areasForCity,0,2);}else{$areasForCity=[''];}
 foreach($areasForCity as $locality){ foreach($targets as $cat=>$target){ if($added>=$maxPerRequest||slotsLeft($cat,$users,$today,$target)<=0)continue; $qList=$queries[$cat];$qCount=count($qList);$qStart=$qCount?(($dayNumber+$cityOffset+($cat==='Makeup Artist'?1:0))%$qCount):0;$qList=array_merge(array_slice($qList,$qStart),array_slice($qList,0,$qStart));
  foreach($qList as $tpl){ if($added>=$maxPerRequest||slotsLeft($cat,$users,$today,$target)<=0)break; $area=$locality!==''?$locality.', '.$city['city']:$city['city'];$q=sprintf($tpl,$area,$city['state']);$searched++;$areas++; try{$ids=searchIds($q);}catch(Throwable $e){$errors++;error_log('Google collector: '.$e->getMessage());continue;}
   foreach($ids as $placeId){ if(slotsLeft($cat,$users,$today,$target)<=0)break; $exists->execute([$placeId]);if($exists->fetchColumn()){$duplicates++;continue;} $u=pickUser($cat,$users,$today,$target);if(!$u)break;
    $d=googleDetails($placeId); if(!$d)$detailsMissing++; $name=$d['displayName']['text']??'Google Place Lead'; $phone=$d['internationalPhoneNumber']??($d['nationalPhoneNumber']??''); $website=$d['websiteUri']??''; $instagram=findInstagramOnWebsite($website); if($instagram)$instagramFound++; $map=$d['googleMapsUri']??('https://www.google.com/maps/search/?api=1&query=Google&query_place_id='.rawurlencode($placeId));
    // A phone number is unique per category. Skip it before INSERT so one duplicate cannot kill the whole batch.
    if($phone!==''){ $phoneExists->execute([$phone,$cat]); if($phoneExists->fetchColumn()){$duplicates++;continue;} }
    try{$insert->execute([$name,$cat,$city['city'],$locality,$phone?:null,$website?:null,$instagram?:null,'Google Places',$map,60,$u['id'],$placeId,$q]);}
    catch(PDOException $e){ if((int)($e->errorInfo[1]??0)===1062){$duplicates++;continue;} $errors++;error_log('Google collector INSERT ERROR: '.$e->getMessage());continue; }
    $today[$u['id']]++;$added++;
   } usleep(150000);
  }
 } } if($added>=$maxPerRequest)break;
}
echo "Google collector batch finished | areas={$areas} | queries={$searched} | added={$added} | duplicates={$duplicates} | details_missing={$detailsMissing} | instagram_found={$instagramFound} | errors={$errors} | reload to continue\n";
?>
