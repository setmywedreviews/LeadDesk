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
$url='https://places.googleapis.com/v1/places/'.rawurlencode($lead['google_place_id']);
$ctx=stream_context_create(['http'=>['method'=>'GET','header'=>"Content-Type: application/json
X-Goog-Api-Key: ".$key."
X-Goog-FieldMask: id,displayName,internationalPhoneNumber,nationalPhoneNumber,websiteUri,googleMapsUri
",'timeout'=>20,'ignore_errors'=>true]]);
$raw=@file_get_contents($url,false,$ctx);$code=0;foreach(($http_response_header??[]) as $h){if(preg_match('/^HTTP\/\S+\s+(\d+)/',$h,$m)){$code=(int)$m[1];}}
if($raw===false||$code>=400){http_response_code($code>=400?$code:502);echo json_encode(['error'=>'Google details request failed']);exit;}
$j=json_decode($raw,true);
echo json_encode([
 'id'=>$lead['id'],
 'name'=>$j['displayName']['text']??'Google vendor',
 'phone'=>$j['internationalPhoneNumber']??($j['nationalPhoneNumber']??''),
 'website'=>$j['websiteUri']??'',
 'maps'=>$j['googleMapsUri']??('https://www.google.com/maps/search/?api=1&query=Google&query_place_id='.rawurlencode($lead['google_place_id'])),
 'attribution'=>'Google'
]);
?>