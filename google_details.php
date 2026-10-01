<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
if(!isset($_SESSION['uid'])){http_response_code(401);echo json_encode(['error'=>'Login required']);exit;}
$c=require __DIR__.'/config.php';
$db=$c['db'];
$pdo=new PDO("mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4",$db['user'],$db['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$id=(int)($_GET['id']??0);
$s=$pdo->prepare("SELECT * FROM leads WHERE id=? LIMIT 1");$s->execute([$id]);$lead=$s->fetch();
if(!$lead || empty($lead['google_place_id'])){http_response_code(404);echo json_encode(['error'=>'Google lead not found']);exit;}
if($_SESSION['role']!=='admin' && (int)$lead['assigned_to']!==(int)$_SESSION['uid']){http_response_code(403);echo json_encode(['error'=>'Not assigned to you']);exit;}
$key=getenv('GOOGLE_MAPS_API_KEY');if(!$key){http_response_code(500);echo json_encode(['error'=>'Google API key missing']);exit;}
$placeId=trim((string)$lead['google_place_id']);
if($placeId===''){http_response_code(404);echo json_encode(['error'=>'This lead has no Google Place ID in the database']);exit;}
function fetchPlaceDetails($placeId,$key){
 $url='https://places.googleapis.com/v1/places/'.rawurlencode($placeId);
 $ch=curl_init($url);
 curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_HTTPHEADER=>[
  'Content-Type: application/json',
  'X-Goog-Api-Key: '.$key,
  'X-Goog-FieldMask: id,displayName,internationalPhoneNumber,nationalPhoneNumber,websiteUri,googleMapsUri,businessStatus,movedPlaceId'
 ]]);
 $raw=curl_exec($ch);$curlErr=curl_error($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
 if($raw===false)return ['code'=>502,'json'=>['error'=>['message'=>'Google connection failed: '.$curlErr]]];
 $json=json_decode($raw,true);
 return ['code'=>$code,'json'=>$json?:[]];
}
$result=fetchPlaceDetails($placeId,$key);
$j=$result['json'];$code=$result['code'];
if($code>=400){
 $msg=$j['error']['message']??('Google HTTP '.$code);
 http_response_code($code);echo json_encode(['error'=>'Google Places: '.$msg]);exit;
}
if(!empty($j['movedPlaceId'])&&$j['movedPlaceId']!==$placeId){
 $moved=fetchPlaceDetails($j['movedPlaceId'],$key);
 if($moved['code']<400){$j=$moved['json'];$placeId=$moved['json']['id']??$j['id']??$placeId;}
}
if(empty($j['id'])&&empty($j['displayName'])){
 http_response_code(502);echo json_encode(['error'=>'Google returned no place details for this Place ID']);exit;
}
echo json_encode([
 'id'=>$lead['id'],
 'name'=>$j['displayName']['text']??'Google vendor',
 'phone'=>$j['internationalPhoneNumber']??($j['nationalPhoneNumber']??''),
 'website'=>$j['websiteUri']??'',
 'maps'=>$j['googleMapsUri']??('https://www.google.com/maps/search/?api=1&query=Google&query_place_id='.rawurlencode($placeId)),
 'attribution'=>'Google'
]);
?>
