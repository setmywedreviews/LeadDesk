<?php
session_start();
require __DIR__.'/config.php';
require __DIR__.'/vendor/autoload.php';
require __DIR__.'/push_lib.php';
header('Content-Type: application/json');
if(!isset($_SESSION['uid'])){http_response_code(401);echo json_encode(['error'=>'Login required']);exit;}
$data=json_decode(file_get_contents('php://input'),true) ?: [];
$endpoint=trim($data['endpoint']??'');
$p256dh=trim($data['keys']['p256dh']??'');
$auth=trim($data['keys']['auth']??'');
if(!$endpoint||!$p256dh||!$auth){http_response_code(400);echo json_encode(['error'=>'Invalid push subscription']);exit;}
try{
 $pdo=new PDO("mysql:host={$c['db']['host']};port={$c['db']['port']};dbname={$c['db']['name']};charset=utf8mb4",$c['db']['user'],$c['db']['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 pushDb($pdo);
 $hash=hash('sha256',$endpoint);
 $st=$pdo->prepare("INSERT INTO push_subscriptions(user_id,endpoint,endpoint_hash,p256dh,auth) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),endpoint=VALUES(endpoint),p256dh=VALUES(p256dh),auth=VALUES(auth),updated_at=NOW()");
 $st->execute([(int)$_SESSION['uid'],$endpoint,$hash,$p256dh,$auth]);
 echo json_encode(['ok'=>true]);
}catch(Throwable $e){http_response_code(500);echo json_encode(['error'=>'Could not save subscription']);}
