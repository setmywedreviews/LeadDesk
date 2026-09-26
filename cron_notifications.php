<?php
require __DIR__.'/config.php';
require __DIR__.'/vendor/autoload.php';
require __DIR__.'/push_lib.php';
$secret=getenv('PUSH_CRON_SECRET') ?: '';
if(!$secret || !hash_equals($secret,(string)($_GET['key']??''))){http_response_code(403);die('Forbidden');}
date_default_timezone_set('Asia/Kolkata');
$now=new DateTimeImmutable('now',new DateTimeZone('Asia/Kolkata'));
$weekday=(int)$now->format('N');
$hour=(int)$now->format('G');
$allowed=[10,12,14,16,18];
if($weekday>5 || !in_array($hour,$allowed,true)){echo 'No notification slot';exit;}
try{
 $pdo=new PDO("mysql:host={$c['db']['host']};port={$c['db']['port']};dbname={$c['db']['name']};charset=utf8mb4",$c['db']['user'],$c['db']['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 pushDb($pdo);
 $slot=(string)$hour;
 $ins=$pdo->prepare("INSERT IGNORE INTO push_runs(run_date,slot) VALUES(?,?)");
 $ins->execute([$now->format('Y-m-d'),$slot]);
 if($ins->rowCount()===0){echo 'Already sent';exit;}
 $titles=[10=>'☀️ Good Morning — LeadDesk',12=>'🎯 12 PM LeadDesk',14=>'🔥 2 PM LeadDesk',16=>'⚡ 4 PM LeadDesk',18=>'🏁 6 PM LeadDesk'];
 $bodies=[10=>'Your sales day is starting. Let’s make the first calls count.',12=>'2 hours done. Keep the momentum going — follow up and close.',14=>'Halfway through the day. Focus on quality conversations.',16=>'Two hours left. Push the active leads and follow-ups.',18=>'Day complete. Finish strong and update your leads.'];
 [$sent,$removed]=pushSend($pdo,$titles[$hour],$bodies[$hour],'/');
 echo 'Sent '.$sent.' push notifications. Removed '.$removed.' expired subscriptions.';
}catch(Throwable $e){http_response_code(500);echo 'Notification error';}
