<?php
ini_set('session.gc_maxlifetime','2592000');
session_set_cookie_params(['lifetime'=>2592000,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
session_start();
$c=require __DIR__.'/config.php';
try{$pdo=new PDO("mysql:host={$c['db']['host']};port={$c['db']['port']};dbname={$c['db']['name']};charset=utf8mb4",$c['db']['user'],$c['db']['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}catch(Throwable $e){http_response_code(500);exit;}
if(!isset($_SESSION['uid'])){http_response_code(401);exit;}
$id=(int)($_GET['id']??0);
$s=$pdo->prepare('SELECT r.photo,r.photo_mime,r.photo_name,u.id user_id FROM daily_reports r JOIN users u ON u.id=r.user_id WHERE r.id=?');
$s->execute([$id]);$r=$s->fetch();
if(!$r||!$r['photo']){http_response_code(404);exit;}
if($_SESSION['role']!=='admin'&&(int)$r['user_id']!==(int)$_SESSION['uid']){http_response_code(403);exit;}
header('Content-Type: '.($r['photo_mime']?:'image/jpeg'));
header('Content-Disposition: inline; filename="'.basename($r['photo_name']?:'daily-report.jpg').'"');
echo $r['photo'];
