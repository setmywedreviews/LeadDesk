<?php
$c=require __DIR__.'/config.php';
$pdo=new PDO("mysql:host={$c['db']['host']};port={$c['db']['port']};dbname={$c['db']['name']};charset=utf8mb4",$c['db']['user'],$c['db']['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$schema=file_get_contents(__DIR__.'/schema.sql');
foreach(array_filter(array_map('trim',preg_split('/;\\s*/',$schema))) as $q){$pdo->exec($q);}
$people=[
 ['Arun','admin','admin'],
 ['Mayur','sales','Photography'],
 ['Bharti','sales','Makeup Artist'],
 ['Manju','sales','Makeup Artist'],
 ['Diksha','sales','Makeup Artist'],
 ['Swirkriti','sales','Makeup Artist']
];
if($_SERVER['REQUEST_METHOD']==='POST'){
 foreach($people as $x){
  $u=strtolower($x[0]);$pw=$_POST['pw_'.$u]??'';
  if(!$pw) continue;
  $s=$pdo->prepare('SELECT id FROM users WHERE username=?');$s->execute([$u]);
  if(!$s->fetch()) $pdo->prepare('INSERT INTO users(name,username,password_hash,role,category) VALUES(?,?,?,?,?)')->execute([$x[0],$u,password_hash($pw,PASSWORD_DEFAULT),$x[1],$x[2]]);
 }
 echo '<h2>Users created.</h2><p>Delete setup.php now, then open the LeadDesk.</p><a href="/">Open LeadDesk</a>';exit;
}
?><!doctype html><meta name="viewport" content="width=device-width"><style>body{font-family:system-ui;max-width:650px;margin:30px auto;padding:15px}input{padding:10px;width:100%;box-sizing:border-box;margin:6px 0 14px;border:1px solid #ddd;border-radius:8px}button{padding:11px 16px;background:#111;color:#fff;border:0;border-radius:8px}</style><h2>SetMyWed LeadDesk setup</h2><p>Create a password for each account. Delete this file after setup.</p><form method="post"><?php foreach($people as $x):$u=strtolower($x[0]);?><label><b><?=htmlspecialchars($x[0])?></b> — <?=htmlspecialchars($x[2])?></label><input type="password" name="pw_<?=$u?>" required minlength="8"><?php endforeach;?><button>Create accounts</button></form>