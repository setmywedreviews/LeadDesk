<?php
session_start();
if (($_SESSION['role'] ?? '') !== 'admin') { http_response_code(403); exit('Admin only'); }
$c=require __DIR__.'/config.php';
$db=$c['db'];
$pdo=new PDO("mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4",$db['user'],$db['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE IF NOT EXISTS google_city_targets(
 id INT AUTO_INCREMENT PRIMARY KEY,city VARCHAR(120) NOT NULL,state VARCHAR(120) NOT NULL,tier VARCHAR(10) NOT NULL,
 active TINYINT(1) DEFAULT 1,priority INT DEFAULT 100,UNIQUE KEY uniq_city_state(city,state),INDEX idx_active_priority(active,priority)
)");
$cols=$pdo->query("SHOW COLUMNS FROM leads")->fetchAll(PDO::FETCH_COLUMN);
if(!in_array('google_place_id',$cols,true)) $pdo->exec("ALTER TABLE leads ADD COLUMN google_place_id VARCHAR(255) NULL");
if(!in_array('google_query',$cols,true)) $pdo->exec("ALTER TABLE leads ADD COLUMN google_query VARCHAR(255) NULL");
try{$pdo->exec("ALTER TABLE leads ADD UNIQUE KEY uniq_google_place(google_place_id)");}catch(Throwable $e){}
$cities=require __DIR__.'/google_cities.php';
$ins=$pdo->prepare("INSERT INTO google_city_targets(city,state,tier,active,priority) VALUES(?,?,?,1,?) ON DUPLICATE KEY UPDATE tier=VALUES(tier),active=1");
$n=0;
foreach($cities as $i=>$x){$ins->execute([$x[0],$x[1],$x[2],$i+1]);$n++;}
echo "<h2>Google collector setup complete</h2><p>City targets loaded: {$n}</p><p><a href='/'>Back to LeadDesk</a></p>";
?>