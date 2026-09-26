<?php
function pushDb($pdo){
 $pdo->exec("CREATE TABLE IF NOT EXISTS push_subscriptions(
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  endpoint TEXT NOT NULL,
  endpoint_hash CHAR(64) NOT NULL,
  p256dh TEXT NOT NULL,
  auth TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_endpoint(endpoint_hash),
  INDEX idx_user(user_id)
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 $pdo->exec("CREATE TABLE IF NOT EXISTS push_settings(
  id TINYINT PRIMARY KEY,
  vapid_public_key TEXT NOT NULL,
  vapid_private_key TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 $pdo->exec("CREATE TABLE IF NOT EXISTS push_runs(
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  run_date DATE NOT NULL,
  slot VARCHAR(10) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_run(run_date,slot)
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function pushKeys($pdo){
 pushDb($pdo);
 $row=$pdo->query("SELECT vapid_public_key,vapid_private_key FROM push_settings WHERE id=1 LIMIT 1")->fetch();
 if($row)return $row;
 $keys=\Minishlink\WebPush\VAPID::createVapidKeys();
 $st=$pdo->prepare("INSERT INTO push_settings(id,vapid_public_key,vapid_private_key) VALUES(1,?,?)");
 $st->execute([$keys['publicKey'],$keys['privateKey']]);
 return ['vapid_public_key'=>$keys['publicKey'],'vapid_private_key'=>$keys['privateKey']];
}
function pushB64Url($data){return rtrim(strtr(base64_encode($data),'+/','-_'),'=');}
function pushSend($pdo,$title,$body,$url='/'){
 pushDb($pdo);
 $keys=pushKeys($pdo);
 $settings=[
  'VAPID'=>[
   'subject'=>getenv('PUSH_VAPID_SUBJECT') ?: 'mailto:admin@setmywed.com',
   'publicKey'=>$keys['vapid_public_key'],
   'privateKey'=>$keys['vapid_private_key']
  ]
 ];
 $webPush=new \Minishlink\WebPush\WebPush($settings);
 $rows=$pdo->query("SELECT * FROM push_subscriptions ORDER BY id")->fetchAll();
 $sent=0;$removed=0;$failed=0;$lastError='';
 foreach($rows as $r){
  try{
   $sub=\Minishlink\WebPush\Subscription::create([
    'endpoint'=>$r['endpoint'],
    'keys'=>[
     'p256dh'=>$r['p256dh'],
     'auth'=>$r['auth']
    ]
   ]);
   $report=$webPush->sendOneNotification($sub,json_encode(['title'=>$title,'body'=>$body,'url'=>$url,'icon'=>'/icon.svg']));
   if($report->isSuccess())$sent++;
   elseif($report->isSubscriptionExpired()){
    $pdo->prepare("DELETE FROM push_subscriptions WHERE id=?")->execute([$r['id']]);
    $removed++;
   }
  }catch(Throwable $e){$failed++;$lastError=$e->getMessage();}
 }
 return [$sent,$removed,$failed,$lastError];
}
