<?php
session_start();
if (($_SESSION['role'] ?? '') !== 'admin') { http_response_code(403); exit('Admin only'); }
$c=require __DIR__.'/config.php'; $db=$c['db'];
$pdo=new PDO("mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4",$db['user'],$db['pass'],[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
]);
$rows=$pdo->query("SELECT id FROM leads WHERE DATE(created_at)=CURDATE() AND source IN ('Instagram Search','Google Places')")->fetchAll(PDO::FETCH_COLUMN);
$deleted=0;
if($rows){
 $in=implode(',',array_fill(0,count($rows),'?'));
 $pdo->prepare("DELETE FROM activities WHERE lead_id IN ($in)")->execute($rows);
 $stmt=$pdo->prepare("DELETE FROM leads WHERE id IN ($in)");
 $stmt->execute($rows);
 $deleted=$stmt->rowCount();
}
?>
<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1">
<style>body{font-family:system-ui;background:#f6f6f7;margin:0}.wrap{max-width:700px;margin:50px auto;padding:20px}.card{background:#fff;border:1px solid #ddd;border-radius:16px;padding:25px}a{display:inline-block;margin:6px 6px 0 0;padding:11px 14px;background:#111;color:#fff;border-radius:9px;text-decoration:none}</style>
<div class="wrap"><div class="card">
<h2>Today's lead test data reset</h2>
<p>Deleted <b><?=htmlspecialchars((string)$deleted)?></b> leads generated today by Google Places and Instagram Search.</p>
<p>Users, city targets, API settings, and other lead sources were not changed.</p>
<a href="google_collector.php">Run Google collector</a>
<a href="instagram_collector.php?batch=0">Run Instagram collector</a>
<a href="index.php">Back to LeadDesk</a>
</div></div>
