<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if(!isset($_SESSION['uid'])){
    http_response_code(401);
    echo json_encode(['error'=>'Login required']);
    exit;
}

$c=require __DIR__.'/config.php';
$db=$c['db'];
$pdo=new PDO(
    "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4",
    $db['user'],
    $db['pass'],
    [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
    ]
);

$id=(int)($_GET['id']??0);
$s=$pdo->prepare("SELECT id,business_name,phone,website,source_url,google_place_id,assigned_to FROM leads WHERE id=? LIMIT 1");
$s->execute([$id]);
$lead=$s->fetch();

if(!$lead){
    http_response_code(404);
    echo json_encode(['error'=>'Lead not found']);
    exit;
}
if($_SESSION['role']!=='admin' && (int)$lead['assigned_to']!==(int)$_SESSION['uid']){
    http_response_code(403);
    echo json_encode(['error'=>'Not assigned to you']);
    exit;
}

$storedName=trim((string)($lead['business_name']??''));
$storedPhone=trim((string)($lead['phone']??''));
$storedWebsite=trim((string)($lead['website']??''));
$storedMaps=trim((string)($lead['source_url']??''));
$placeId=trim((string)($lead['google_place_id']??''));

/*
 * Google collector already saves the vendor name, phone, website and Maps URL
 * in the leads table. Use those values first. This makes the CRM usable even
 * when Google's live Place Details endpoint is temporarily unavailable.
 */
if($storedName!=='' || $storedPhone!=='' || $storedWebsite!=='' || $storedMaps!==''){
    echo json_encode([
        'id'=>$lead['id'],
        'name'=>$storedName!==''?$storedName:'Google vendor',
        'phone'=>$storedPhone,
        'website'=>$storedWebsite,
        'maps'=>$storedMaps!==''?$storedMaps:(
            $placeId!==''?
            'https://www.google.com/maps/search/?api=1&query=Google&query_place_id='.rawurlencode($placeId):
            ''
        ),
        'attribution'=>'Google Places',
        'source'=>'database',
        'google_live'=>false
    ]);
    exit;
}

if($placeId===''){
    http_response_code(404);
    echo json_encode(['error'=>'This Google lead has no saved vendor details or Google Place ID']);
    exit;
}

$key=getenv('GOOGLE_MAPS_API_KEY');
if(!$key){
    http_response_code(500);
    echo json_encode(['error'=>'Google API key missing in Railway']);
    exit;
}

function fetchPlaceDetails($placeId,$key){
    $url='https://places.googleapis.com/v1/places/'.rawurlencode($placeId);
    $ch=curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_TIMEOUT=>12,
        CURLOPT_CONNECTTIMEOUT=>5,
        CURLOPT_HTTPHEADER=>[
            'Content-Type: application/json',
            'X-Goog-Api-Key: '.$key,
            'X-Goog-FieldMask: id,displayName,internationalPhoneNumber,nationalPhoneNumber,websiteUri,googleMapsUri,movedPlaceId'
        ]
    ]);
    $raw=curl_exec($ch);
    $curlErr=curl_error($ch);
    $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    if($raw===false){
        return ['code'=>502,'json'=>['error'=>['message'=>'Google connection failed: '.$curlErr]]];
    }
    $json=json_decode($raw,true);
    return ['code'=>$code,'json'=>$json?:[]];
}

$result=fetchPlaceDetails($placeId,$key);
$j=$result['json'];
$code=$result['code'];

if($code>=400){
    $msg=$j['error']['message']??('Google HTTP '.$code);
    http_response_code($code);
    echo json_encode(['error'=>'Google Places: '.$msg]);
    exit;
}

if(!empty($j['movedPlaceId']) && $j['movedPlaceId']!==$placeId){
    $moved=fetchPlaceDetails($j['movedPlaceId'],$key);
    if($moved['code']<400){
        $j=$moved['json'];
        $placeId=$j['id']??$placeId;
    }
}

if(empty($j['id']) && empty($j['displayName'])){
    http_response_code(502);
    echo json_encode(['error'=>'Google returned no place details for this Place ID']);
    exit;
}

echo json_encode([
    'id'=>$lead['id'],
    'name'=>$j['displayName']['text']??$storedName,
    'phone'=>$j['internationalPhoneNumber']??($j['nationalPhoneNumber']??$storedPhone),
    'website'=>$j['websiteUri']??$storedWebsite,
    'maps'=>$j['googleMapsUri']??$storedMaps??'',
    'attribution'=>'Google Places',
    'source'=>'google_live',
    'google_live'=>true
]);
?>