<?php
session_start();
require __DIR__.'/config.php';
require __DIR__.'/vendor/autoload.php';
require __DIR__.'/push_lib.php';
if(!isset($_SESSION['uid'])||($_SESSION['role']??'')!=='admin'){http_response_code(403);die('Forbidden');}
$title=trim($_POST['title']??'');
$body=trim($_POST['body']??'');
$url=trim($_POST['url']??'/');
if($title&&$body){
 try{
  $pdo=new PDO("mysql:host={$c['db']['host']};port={$c['db']['port']};dbname={$c['db']['name']};charset=utf8mb4",$c['db']['user'],$c['db']['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
  [$sent,$removed]=pushSend($pdo,$title,$body,$url);
  header('Location:?view=admin&push_sent='.$sent);exit;
 }catch(Throwable $e){header('Location:?view=admin&push_error=1');exit;}
}
header('Location:?view=admin&push_error=1');exit;
