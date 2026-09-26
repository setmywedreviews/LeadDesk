<?php
session_start();
$c=require __DIR__.'/config.php';
require __DIR__.'/vendor/autoload.php';
require __DIR__.'/push_lib.php';
if(!isset($_SESSION['uid'])){http_response_code(401);header('Content-Type: application/json');echo json_encode(['error'=>'Login required']);exit;}
try{
 $pdo=new PDO("mysql:host={$c['db']['host']};port={$c['db']['port']};dbname={$c['db']['name']};charset=utf8mb4",$c['db']['user'],$c['db']['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 $k=pushKeys($pdo);header('Content-Type: application/json');echo json_encode(['publicKey'=>$k['vapid_public_key']]);
}catch(Throwable $e){http_response_code(500);header('Content-Type: application/json');echo json_encode(['error'=>'Push setup unavailable']);}
