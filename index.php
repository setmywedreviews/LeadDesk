<?php
ini_set('session.gc_maxlifetime','2592000');
session_set_cookie_params(['lifetime'=>2592000,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
session_start();
$c=require __DIR__.'/config.php';
try{$pdo=new PDO("mysql:host={$c['db']['host']};port={$c['db']['port']};dbname={$c['db']['name']};charset=utf8mb4",$c['db']['user'],$c['db']['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}catch(Throwable $e){http_response_code(500);die('Database connection failed.');}
function h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function rememberSet($u){$exp=time()+2592000;$payload=(int)$u['id'].'|'.$exp;$sig=hash_hmac('sha256',$payload,$u['password_hash']);setcookie('SMW_REMEMBER',base64_encode($payload.'|'.$sig),['expires'=>$exp,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);}
function rememberClear(){setcookie('SMW_REMEMBER','',['expires'=>time()-3600,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);}
function restoreRemember($pdo){$raw=$_COOKIE['SMW_REMEMBER']??'';if(!$raw)return false;$d=base64_decode($raw,true);if(!$d)return false;$p=explode('|',$d);if(count($p)!==3)return false;[$uid,$exp,$sig]=$p;if(!ctype_digit($uid)||!ctype_digit($exp)||$exp<time())return false;$s=$pdo->prepare('SELECT * FROM users WHERE id=? AND active=1 LIMIT 1');$s->execute([(int)$uid]);$u=$s->fetch();if(!$u)return false;$ok=hash_hmac('sha256',$uid.'|'.$exp,$u['password_hash']);if(!hash_equals($ok,$sig))return false;$_SESSION['uid']=$u['id'];$_SESSION['role']=$u['role'];$_SESSION['name']=$u['name'];$_SESSION['category']=$u['category'];rememberSet($u);return true;}
if(isset($_GET['logout'])){session_unset();session_destroy();rememberClear();header('Location:/');exit;}
if(!isset($_SESSION['uid']))restoreRemember($pdo);
if(!isset($_SESSION['uid'])){if($_SERVER['REQUEST_METHOD']==='POST'){$s=$pdo->prepare('SELECT * FROM users WHERE username=? AND active=1');$s->execute([trim($_POST['username']??'')]);$u=$s->fetch();if($u&&password_verify($_POST['password']??'',$u['password_hash'])){$_SESSION['uid']=$u['id'];$_SESSION['role']=$u['role'];$_SESSION['name']=$u['name'];$_SESSION['category']=$u['category'];rememberSet($u);header('Location:/');exit;}$err='Invalid username or password.';}?><!doctype html><meta name="viewport" content="width=device-width"><style>body{font-family:Inter,system-ui;background:linear-gradient(135deg,#eff6ff,#f8faff);display:grid;place-items:center;min-height:100vh}.box{background:#fff;padding:34px;border:1px solid #dbeafe;border-radius:24px;width:min(390px,88%);box-shadow:0 25px 70px #2563eb18}input,button{width:100%;padding:13px;margin:7px 0;border:1px solid #dbe3ef;border-radius:12px;box-sizing:border-box}button{background:#2563eb;color:#fff;font-weight:800;border:0}.err{color:#dc2626}</style><div class="box"><h2>💙 SetMyWed LeadDesk</h2><p>Sales team login</p><?=isset($err)?'<p class="err">'.h($err).'</p>':''?><form method="post"><input name="username" placeholder="Username" required><input type="password" name="password" placeholder="Password" required><button>Login</button></form></div><?php exit;}
$me=(int)$_SESSION['uid'];$isAdmin=$_SESSION['role']==='admin';
$pdo->exec("CREATE TABLE IF NOT EXISTS daily_reports(id BIGINT AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,report_date DATE NOT NULL,report_text TEXT NULL,photo MEDIUMBLOB NULL,photo_mime VARCHAR(80) NULL,photo_name VARCHAR(255) NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uniq_user_date(user_id,report_date),INDEX idx_report_date(report_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
try{$pdo->exec("ALTER TABLE leads ADD COLUMN contacted_at DATETIME NULL");}catch(Throwable $e){}
try{$pdo->exec("ALTER TABLE leads ADD COLUMN starred TINYINT(1) NOT NULL DEFAULT 0");}catch(Throwable $e){}
try{$pdo->exec("ALTER TABLE leads ADD COLUMN existing_customer TINYINT(1) NOT NULL DEFAULT 0");}catch(Throwable $e){}
$pdo->exec("CREATE TABLE IF NOT EXISTS manual_followups(id BIGINT AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,business_name VARCHAR(190) NOT NULL,phone VARCHAR(40) NULL,followup_at DATETIME NOT NULL,status VARCHAR(40) NOT NULL DEFAULT 'Scheduled',remark TEXT NULL,lead_source VARCHAR(120) NULL,photo MEDIUMBLOB NULL,photo_mime VARCHAR(80) NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX idx_user_time(user_id,followup_at),INDEX idx_time(followup_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$cat=$_GET['cat']??'';$q=trim($_GET['q']??'');$emp=(int)($_GET['emp']??0);$view=$_GET['view']??'dashboard';$dateFrom=$_GET['date_from']??'';$dateTo=$_GET['date_to']??'';$starred=(int)($_GET['starred']??0);
$users=$pdo->query("SELECT id,name,category FROM users WHERE role='sales' AND active=1 ORDER BY id")->fetchAll();$where=[];$p=[];if(!$isAdmin){$where[]='l.assigned_to=?';$p[]=$me;}if($q){$where[]='(l.business_name LIKE ? OR l.city LIKE ? OR l.phone LIKE ?)';array_push($p,"%$q%","%$q%","%$q%");}if($cat){$where[]='l.category=?';$p[]=$cat;}if($isAdmin&&$emp){$where[]='l.assigned_to=?';$p[]=$emp;}if($view==='existing')$where[]='l.existing_customer=1';else{$where[]='l.existing_customer=0';if($view==='new')$where[]="l.status='New'";if($view==='followups')$where[]="(l.status='Follow-up' OR l.next_followup IS NOT NULL)";if($view==='starred')$where[]='l.starred=1';}if($dateFrom){$where[]='DATE(l.created_at)>=?';$p[]=$dateFrom;}if($dateTo){$where[]='DATE(l.created_at)<=?';$p[]=$dateTo;}
$sql="SELECT l.*,u.name assigned_name FROM leads l LEFT JOIN users u ON u.id=l.assigned_to".($where?' WHERE '.implode(' AND ',$where):'')." ORDER BY CASE WHEN l.starred=1 THEN 0 WHEN l.status='New' THEN 1 WHEN (l.status='Follow-up' OR l.next_followup IS NOT NULL) THEN 2 ELSE 3 END,l.created_at DESC,l.id DESC LIMIT 250";$s=$pdo->prepare($sql);$s->execute($p);$leads=$s->fetchAll();
if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['action'])){
if($_POST['action']==='save_report'){$rd=$_POST['report_date']??date('Y-m-d');$txt=trim($_POST['report_text']??'');$photo=null;$mime=null;$pname=null;if(isset($_FILES['report_photo'])&&$_FILES['report_photo']['error']===UPLOAD_ERR_OK){if($_FILES['report_photo']['size']>8*1024*1024)die('Report photo must be under 8 MB.');$mime=mime_content_type($_FILES['report_photo']['tmp_name']);if(!in_array($mime,['image/jpeg','image/png','image/webp'],true))die('Only JPG, PNG or WEBP photos are allowed.');$photo=file_get_contents($_FILES['report_photo']['tmp_name']);$pname=$_FILES['report_photo']['name'];}$old=$pdo->prepare('SELECT photo,photo_mime,photo_name FROM daily_reports WHERE user_id=? AND report_date=?');$old->execute([$me,$rd]);$prev=$old->fetch();if($photo===null&&$prev){$photo=$prev['photo'];$mime=$prev['photo_mime'];$pname=$prev['photo_name'];}$st=$pdo->prepare('INSERT INTO daily_reports(user_id,report_date,report_text,photo,photo_mime,photo_name) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE report_text=VALUES(report_text),photo=VALUES(photo),photo_mime=VALUES(photo_mime),photo_name=VALUES(photo_name),updated_at=NOW()');$st->bindValue(1,$me,PDO::PARAM_INT);$st->bindValue(2,$rd);$st->bindValue(3,$txt);$st->bindValue(4,$photo,PDO::PARAM_LOB);$st->bindValue(5,$mime);$st->bindValue(6,$pname);$st->execute();header('Location:?view=daily_report&saved=1');exit;}
if($_POST['action']==='remove_manual_followup'){
 $fid=(int)($_POST['followup_id']??0);
 if($fid>0){
  if($isAdmin)$pdo->prepare('DELETE FROM manual_followups WHERE id=?')->execute([$fid]);
  else $pdo->prepare('DELETE FROM manual_followups WHERE id=? AND user_id=?')->execute([$fid,$me]);
 }
 header('Location:?view=followups&removed=1');exit;
}
if($_POST['action']==='remove_lead_followup'){
 $fid=(int)($_POST['id']??0);
 $ok=$isAdmin;
 if(!$isAdmin&&$fid>0){$zz=$pdo->prepare('SELECT assigned_to FROM leads WHERE id=?');$zz->execute([$fid]);$ok=((int)$zz->fetchColumn()===$me);}
 if($ok){$zz=$pdo->prepare('SELECT status FROM leads WHERE id=?');$zz->execute([$fid]);$oldStatus=$zz->fetchColumn();$newStatus=($oldStatus==='Follow-up')?'New':$oldStatus;$pdo->prepare("UPDATE leads SET next_followup=NULL,status=?,updated_at=NOW() WHERE id=?")->execute([$newStatus,$fid]);$pdo->prepare('INSERT INTO activities(lead_id,user_id,type,note) VALUES(?,?,?,?)')->execute([$fid,$me,'followup_removed','Follow-up removed']);}
 header('Location:?view=followups&removed=1');exit;
}
if($_POST['action']==='toggle_existing'){
 $eid=(int)($_POST['id']??0);$val=(int)($_POST['existing_customer']??0);
 $ok=$isAdmin;
 if(!$ok&&$eid>0){$zz=$pdo->prepare('SELECT assigned_to FROM leads WHERE id=?');$zz->execute([$eid]);$ok=((int)$zz->fetchColumn()===$me);}
 if($ok){$pdo->prepare('UPDATE leads SET existing_customer=?,updated_at=NOW() WHERE id=?')->execute([$val?1:0,$eid]);$pdo->prepare('INSERT INTO activities(lead_id,user_id,type,note) VALUES(?,?,?,?)')->execute([$eid,$me,$val?'existing_customer':'existing_customer_removed',$val?'Moved to Existing Customers':'Moved back to active leads']);}
 header('Location:'.($_SERVER['HTTP_REFERER']??'/'));exit;
}
if($_POST['action']==='toggle_star'){
 $sid=(int)($_POST['id']??0);$val=(int)($_POST['starred']??0);
 $ok=$isAdmin;
 if(!$ok&&$sid>0){$zz=$pdo->prepare('SELECT assigned_to FROM leads WHERE id=?');$zz->execute([$sid]);$ok=((int)$zz->fetchColumn()===$me);}
 if($ok)$pdo->prepare('UPDATE leads SET starred=?,updated_at=NOW() WHERE id=?')->execute([$val?1:0,$sid]);
 header('Location:'.($_SERVER['HTTP_REFERER']??'/'));exit;
}
if($_POST['action']==='contact'){
 $cid=(int)($_POST['id']??0);$ok=$isAdmin;
 if(!$ok&&$cid>0){$zz=$pdo->prepare('SELECT assigned_to FROM leads WHERE id=?');$zz->execute([$cid]);$ok=((int)$zz->fetchColumn()===$me);}
 if($ok)$pdo->prepare('UPDATE leads SET contacted_at=NOW(),updated_at=NOW() WHERE id=?')->execute([$cid]);
 http_response_code($ok?204:403);exit;
}
if($_POST['action']==='add_manual_followup'){ $bn=trim($_POST['business_name']??''); $ph=trim($_POST['phone']??''); $fa=trim($_POST['followup_at']??''); $st=trim($_POST['status']??'Scheduled'); $rm=trim($_POST['remark']??''); $src=trim($_POST['lead_source']??'Other'); if($bn&&$fa){$x=$pdo->prepare('INSERT INTO manual_followups(user_id,business_name,phone,followup_at,status,remark,lead_source) VALUES(?,?,?,?,?,?,?)');$x->execute([$me,$bn,$ph?:null,$fa,$st,$rm,$src]);} header('Location:?view=followups&saved=1'); exit;}
if($_POST['action']==='add_lead'&&$isAdmin){$business=trim($_POST['business_name']??'');$category=trim($_POST['category']??'');$city=trim($_POST['city']??'');$phone=trim($_POST['phone']??'');$instagram=trim($_POST['instagram']??'');$website=trim($_POST['website']??'');$assigned=(int)($_POST['assigned_to']??0);$score=(int)($_POST['lead_score']??50);if($business&&in_array($category,['Photography','Makeup Artist'],true)&&$assigned>0){try{$x=$pdo->prepare('INSERT INTO leads(business_name,category,city,phone,instagram,website,source,lead_score,assigned_to) VALUES(?,?,?,?,?,?,?,?,?)');$x->execute([$business,$category,$city,$phone?:null,$instagram?:null,$website?:null,'Manual',$score,$assigned]);}catch(Throwable $e){}}header('Location:?view=admin');exit;}$id=(int)($_POST['id']??0);$allowed=$isAdmin||($id>0&&$pdo->query("SELECT assigned_to FROM leads WHERE id=$id")->fetchColumn()==$me);if($allowed){if($_POST['action']==='remark'){$r=trim($_POST['remark']??'');$pdo->prepare('UPDATE leads SET remark=?,updated_at=NOW() WHERE id=?')->execute([$r,$id]);$pdo->prepare('INSERT INTO activities(lead_id,user_id,type,note) VALUES(?,?,?,?)')->execute([$id,$me,'remark',$r]);}if($_POST['action']==='followup'){$dt=$_POST['next']??'';$pdo->prepare("UPDATE leads SET next_followup=?,status='Follow-up',updated_at=NOW() WHERE id=?")->execute([$dt,$id]);$pdo->prepare('INSERT INTO activities(lead_id,user_id,type,note) VALUES(?,?,?,?)')->execute([$id,$me,'followup','Follow-up scheduled for '.$dt]);}if($_POST['action']==='status'){$st=$_POST['status'];$existing=($st==='Existing')?1:0;if($existing)$st='Converted';$pdo->prepare('UPDATE leads SET status=?,existing_customer=?,updated_at=NOW() WHERE id=?')->execute([$st,$existing,$id]);$pdo->prepare('INSERT INTO activities(lead_id,user_id,type,note) VALUES(?,?,?,?)')->execute([$id,$me,'status',$existing?'Existing':$st]);}}header('Location:'.($_SERVER['HTTP_REFERER']??'/'));exit;}
$stats=$isAdmin?$pdo->query("SELECT COUNT(*) total,SUM(status='New') newc,SUM(status='Follow-up') fup,SUM(status='Interested') interested FROM leads")->fetch():$pdo->query("SELECT COUNT(*) total,SUM(status='New') newc,SUM(status='Follow-up') fup,SUM(status='Interested') interested FROM leads WHERE assigned_to=$me")->fetch();
?><!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#2563eb"><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Playfair+Display:ital,wght@0,400;0,500;0,600;0,700;0,800;0,900;1,400;1,500;1,600;1,700;1,800;1,900&display=swap" rel="stylesheet"><link rel="manifest" href="/manifest.json"><link rel="icon" href="/icon.svg"><title>SetMyWed LeadDesk</title><style>
*{box-sizing:border-box}body{margin:0;font-family:'Plus Jakarta Sans',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f4f8ff;color:#0f172a}.app{min-height:100vh;background:radial-gradient(circle at 10% 0,#dbeafe 0,transparent 30%),radial-gradient(circle at 100% 10%,#e0e7ff 0,transparent 25%)}.wrap{max-width:1240px;margin:auto;padding:22px}.top{background:linear-gradient(135deg,#1d4ed8,#2563eb 55%,#4f46e5);color:white;border-radius:24px;padding:22px 24px;box-shadow:0 18px 45px #2563eb30;display:flex;justify-content:space-between;align-items:center;gap:15px}.brand{font-size:25px;font-weight:900;letter-spacing:-.5px}.hello{margin-top:4px;color:#dbeafe;font-size:13px}.logout{color:#fff!important;border:1px solid #ffffff55!important;background:#ffffff18!important}.nav{display:flex;gap:8px;margin:18px 0;flex-wrap:wrap}.btn,a.btn{padding:10px 14px;border:1px solid #dbe3ef;border-radius:12px;background:#fff;text-decoration:none;color:#172033;font-weight:750;box-shadow:0 3px 12px #0f172a08}.btn:hover,a.btn:hover{transform:translateY(-1px);box-shadow:0 7px 18px #2563eb18}.primary{background:#2563eb!important;color:#fff!important;border-color:#2563eb!important}.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.stat{background:#fff;border:1px solid #dbeafe;border-radius:18px;padding:17px;box-shadow:0 8px 25px #1e40af0b}.stat b{font-size:30px;display:block;color:#1d4ed8;margin-top:3px}.stat .ico{font-size:18px}.filters{background:#fff;padding:12px;border:1px solid #dbeafe;border-radius:16px;display:flex;gap:8px;margin:15px 0;flex-wrap:wrap;box-shadow:0 7px 22px #1e40af0a}.input,select,textarea{border:1px solid #dbe3ef;border-radius:11px;padding:11px;background:#fff;color:#172033}.input{flex:1;min-width:210px}.lead{margin:12px 0}.card{background:#fff;border:1px solid #dbeafe;border-radius:20px;padding:17px;box-shadow:0 10px 28px #1e40af0a}.lead:hover{border-color:#93c5fd;box-shadow:0 15px 35px #2563eb15}.head{display:flex;justify-content:space-between;gap:10px}.name{font-weight:850;font-size:18px;color:#0f172a}.muted{color:#64748b;font-size:13px}.pill{font-size:11px;padding:6px 9px;background:#eff6ff;color:#1d4ed8;border-radius:999px;font-weight:750}.links,.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.links a,.iconlink{padding:8px 10px;border-radius:10px;background:#f8fafc;color:#334155;text-decoration:none;font-size:13px}.iconlink:hover{background:#eff6ff}.actions form{display:flex;gap:7px;flex-wrap:wrap}.actions button,.actions a{padding:9px 11px;border:1px solid #dbe3ef;border-radius:10px;background:#fff;text-decoration:none;color:#172033;cursor:pointer;font-weight:700}.actions .call{background:#16a34a;color:#fff;border-color:#16a34a}.actions .wa{background:#25d366;color:#fff;border-color:#25d366}.grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}@media(max-width:700px){.wrap{padding:12px}.top{border-radius:18px;padding:17px}.stats{grid-template-columns:1fr 1fr}.grid{grid-template-columns:1fr}.brand{font-size:20px}.actions form{width:100%}}
</style></head><body><div class="app"><div class="wrap"><div class="top"><div><div class="brand">💙 SetMyWed LeadDesk</div><div class="hello">Good to see you, <?=h($_SESSION['name'])?> · <?=h($_SESSION['category'])?></div></div><a class="btn logout" href="?logout=1">Logout</a></div>
<div id="notifyBanner" class="card" style="display:none;margin-top:15px;background:#eff6ff"><b>🔔 Enable notifications</b><div class="muted" style="margin:5px 0 10px">Allow LeadDesk to notify you when your daily leads are ready.</div><button id="enableNotify" class="btn primary">Enable Notifications</button><button id="testNotify" class="btn" style="margin-left:8px">🔔 Test</button></div><div class="nav"><a class="btn <?= $view==='dashboard'?'primary':''?>" href="/">⌂ Dashboard</a><a class="btn <?= $view==='leads'?'primary':''?>" href="?view=leads">📋 My Leads</a><a class="btn <?= $view==='new'?'primary':''?>" href="?view=new&cat=<?=urlencode($cat)?>">🆕 New Leads</a><a class="btn <?= $view==='followups'?'primary':''?>" href="?view=followups">⏰ Follow-ups</a><a class="btn <?= $view==='starred'?'primary':''?>" href="?view=starred">⭐ Starred</a><a class="btn <?= $view==='existing'?'primary':''?>" href="?view=existing">👥 Existing</a><?php if($isAdmin):?><a class="btn" href="?view=admin">⚙️ Admin</a><?php endif;?><a class="btn <?= $view==='daily_report'?'primary':''?>" href="?view=daily_report">📷 Daily Report</a><?php if($isAdmin):?><a class="btn <?= $view==='reports'?'primary':''?>" href="?view=reports">📊 Reports</a><?php endif;?></div>
<div class="motivation" style="margin:15px 0;background:linear-gradient(135deg,#eff6ff,#eef2ff);border:1px solid #bfdbfe;border-radius:18px;padding:16px 18px;color:#1e3a8a"><b>🔥 Daily Motivation</b><div id="quote" style="margin-top:5px;font-size:16px;font-weight:800"></div></div><div class="stats"><div class="stat"><span class="muted">🆕 New</span><b><?=intval($stats['newc'])?></b></div><div class="stat"><span class="muted">⏰ Follow-ups</span><b><?=intval($stats['fup'])?></b></div><div class="stat"><span class="muted">🔥 Interested</span><b><?=intval($stats['interested'])?></b></div><div class="stat"><span class="muted">📊 Total</span><b><?=intval($stats['total'])?></b></div></div>
<?php if($view==='daily_report'):?>
<div class="card" style="margin-top:15px"><h3>📷 Daily Sales Report</h3><p class="muted">Upload today's report photo and add your daily summary.</p>
<?php $rr=$pdo->prepare('SELECT report_text,photo_name FROM daily_reports WHERE user_id=? AND report_date=?');$rr->execute([$me,date('Y-m-d')]);$myReport=$rr->fetch();?>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="save_report"><input type="hidden" name="report_date" value="<?=h(date('Y-m-d'))?>">
<textarea name="report_text" rows="5" style="width:100%;margin:8px 0" placeholder="Calls made, WhatsApp, interested, follow-ups, conversions..."><?=h($myReport['report_text']??'')?></textarea>
<input type="file" name="report_photo" accept="image/jpeg,image/png,image/webp" required style="width:100%;padding:12px;border:1px dashed #93c5fd;border-radius:12px"><button class="btn primary" style="margin-top:10px">📤 Submit Daily Report</button></form></div>
<?php elseif($view==='reports'&&$isAdmin):?>
<div class="card" style="margin-top:15px"><h3>📊 Date-wise Team Reports</h3><form class="filters" style="box-shadow:none"><input type="date" name="date_from" value="<?=h($dateFrom)?>"><input type="date" name="date_to" value="<?=h($dateTo)?>"><input type="hidden" name="view" value="reports"><select name="emp"><option value="0">All employees</option><?php foreach($users as $u):?><option value="<?=$u['id']?>" <?=$emp==$u['id']?'selected':''?>><?=h($u['name'])?></option><?php endforeach;?></select><button class="btn primary">Filter</button></form>
<?php $rw="SELECT r.*,u.name,u.category FROM daily_reports r JOIN users u ON u.id=r.user_id WHERE 1=1";$rp=[];if($dateFrom){$rw.=" AND r.report_date>=?";$rp[]=$dateFrom;}if($dateTo){$rw.=" AND r.report_date<=?";$rp[]=$dateTo;}if($emp){$rw.=" AND r.user_id=?";$rp[]=$emp;}$rw.=" ORDER BY r.report_date DESC,r.user_id";$rs=$pdo->prepare($rw);$rs->execute($rp);$reports=$rs->fetchAll();?>
<div style="overflow:auto"><table style="width:100%;border-collapse:collapse"><tr><th style="text-align:left;padding:10px">Date</th><th style="text-align:left;padding:10px">Employee</th><th style="text-align:left;padding:10px">Summary</th><th style="padding:10px">Photo</th></tr><?php foreach($reports as $r):?><tr style="border-top:1px solid #e2e8f0"><td style="padding:10px"><?=h(date('d M Y',strtotime($r['report_date'])))?></td><td style="padding:10px"><?=h($r['name'])?><div class="muted"><?=h($r['category'])?></div></td><td style="padding:10px"><?=nl2br(h($r['report_text']))?></td><td style="padding:10px"><?php if($r['photo']):?><a class="btn" target="_blank" href="report_photo.php?id=<?=$r['id']?>">📷 View</a><?php else:?>—<?php endif;?></td></tr><?php endforeach;?></table></div></div>
<?php else:?><?php if($view==='admin'&&$isAdmin):?><div class="card" style="margin-top:15px"><h3>👥 Team</h3><?php foreach($users as $u){$st=$pdo->query("SELECT COUNT(*) FROM leads WHERE assigned_to=".$u['id']." AND DATE(created_at)=CURDATE()")->fetchColumn();?><p><?=h($u['name'])?> · <?=h($u['category'])?> <b style="float:right;color:#2563eb"><?=$st?> / 50</b></p><?php }?></div><div class="card" style="margin-top:12px"><h3>📥 Import & Distribute Leads</h3><p class="muted">Paste raw text or CSV data. LeadDesk will detect business name, phone, city, Instagram and website, then distribute the leads equally among selected employees.</p><a class="btn primary" href="import_leads.php">Open Lead Importer →</a></div><div class="card" style="margin-top:12px"><h3>➕ Add Lead</h3><form method="post" class="grid" style="margin-top:10px"><input type="hidden" name="action" value="add_lead"><input class="input" name="business_name" placeholder="Business / vendor name" required><input class="input" name="city" placeholder="City"><input class="input" name="phone" placeholder="Phone"><input class="input" name="instagram" placeholder="Instagram URL"><input class="input" name="website" placeholder="Website URL"><select name="category" required><option value="">Category</option><option>Photography</option><option>Makeup Artist</option></select><select name="assigned_to" required><option value="">Assign employee</option><?php foreach($users as $u):?><option value="<?=$u['id']?>"><?=h($u['name'])?> · <?=h($u['category'])?></option><?php endforeach;?></select><input class="input" type="number" name="lead_score" value="50" min="0" max="100"><div><button class="btn primary" type="submit">Add Lead</button></div></form></div>
<?php else:?><?php if($view==='followups'):?>
<div class="card" style="margin-top:15px"><h3>➕ Add Follow-up from Outside LeadDesk</h3><p class="muted">Add a follow-up you made by phone, WhatsApp, diary, referral, or another source.</p>
<form method="post" class="grid"><input type="hidden" name="action" value="add_manual_followup"><input class="input" name="business_name" placeholder="Vendor / business name" required><input class="input" name="phone" placeholder="Phone number"><input class="input" type="datetime-local" name="followup_at" required><select name="status"><option>Scheduled</option><option>Done</option><option>Interested</option><option>Not Interested</option><option>Cancelled</option></select><input class="input" name="lead_source" placeholder="Lead source (WhatsApp, Referral, Instagram...)"><textarea name="remark" rows="2" placeholder="Remark"></textarea><div><button class="btn primary">➕ Add Follow-up</button></div></form></div>
<div class="card" style="margin-top:12px"><h3>📷 Upload Diary Follow-ups</h3><p class="muted">Take a clear photo of your handwritten follow-up diary. LeadDesk will read the entries and add them here automatically.</p><form id="diaryOcrForm" method="POST" action="/followup_ocr.php" enctype="multipart/form-data"><input type="hidden" name="MAX_FILE_SIZE" value="9437184"><input type="file" name="diary_photo" accept="image/*" capture="environment" required style="width:100%;padding:12px;border:1px dashed #93c5fd;border-radius:12px"><button class="btn primary" style="margin-top:10px">🤖 Read Diary & Add Follow-ups</button></form></div>
<?php $mf=$pdo->prepare("SELECT * FROM manual_followups WHERE user_id=? ORDER BY followup_at ASC LIMIT 100");$mf->execute([$me]);$manuals=$mf->fetchAll();?>
<?php if($manuals):?><div class="card" style="margin-top:12px"><h3>📌 My Added Follow-ups</h3><?php foreach($manuals as $m):?><div style="padding:12px 0;border-top:1px solid #e2e8f0"><div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start"><div><b><?=h($m['business_name'])?></b><div class="muted"><?=h($m['lead_source']?:'Other')?> · <?=h(date('d M Y, h:i A',strtotime($m['followup_at'])))?> · <?=h($m['status'])?></div><?php if($m['phone']):?><div class="muted">📞 <?=h($m['phone'])?></div><?php endif;?><?php if($m['remark']):?><div style="margin-top:5px"><?=nl2br(h($m['remark']))?></div><?php endif;?></div><form method="post" onsubmit="return confirm('Remove this follow-up?')"><input type="hidden" name="action" value="remove_manual_followup"><input type="hidden" name="followup_id" value="<?=$m['id']?>"><button class="btn" type="submit">🗑️ Remove</button></form></div></div><?php endforeach;?></div><?php endif;?>
<?php endif;?><form class="filters"><input class="input" name="q" value="<?=h($q)?>" placeholder="🔎 Search vendor, city or phone"><select name="cat"><option value="">All categories</option><option value="Photography" <?=$cat==='Photography'?'selected':''?>>📷 Photography</option><option value="Makeup Artist" <?=$cat==='Makeup Artist'?'selected':''?>>💄 Makeup Artist</option></select><?php if($isAdmin):?><select name="emp"><option value="0">All employees</option><?php foreach($users as $u):?><option value="<?=$u['id']?>" <?=$emp==$u['id']?'selected':''?>><?=h($u['name'])?></option><?php endforeach;?></select><?php endif;?><input type="date" name="date_from" value="<?=h($dateFrom)?>" title="Added from"><input type="date" name="date_to" value="<?=h($dateTo)?>" title="Added to"><input type="hidden" name="view" value="<?=h($view)?>"><button class="btn primary">Search</button></form>
<?php foreach($leads as $l):?><?php $isGoogle=!empty($l['google_place_id']);$phoneRaw=preg_replace('/[^0-9+]/','',$l['phone']??'');$waPhone=preg_replace('/\D/','',$phoneRaw);if(strlen($waPhone)===10)$waPhone='91'.$waPhone;$waMsg=rawurlencode('Hi '.$l['business_name'].', this is '.$_SESSION['name'].' from SetMyWed. We help wedding vendors get more enquiries and bookings. Would you like to know more?');?><div class="card lead" data-lead-id="<?=$l['id']?>"><div class="head"><div><div class="name" id="name-<?=$l['id']?>"><?=h($isGoogle?'Google vendor lead':$l['business_name'])?></div><div class="muted"><?=h($l['category'])?> · <?=h($l['city'])?> · <?=h($l['assigned_name'])?> · Added <?=h(date('d M Y, h:i A',strtotime($l['created_at'])))?></div></div></div><div class="links"><span class="pill" id="contact-<?=$l['id']?>"><?=!empty($l['contacted_at'])?'✅ Contacted':'📞 Yet to Contact'?></span><?php if($isGoogle):?><button class="iconlink" type="button" onclick="loadGoogleDetails(<?=$l['id']?>,this)">👤 Get vendor details</button><a class="iconlink" id="maps-<?=$l['id']?>" href="<?=h($l['source_url'])?>" target="_blank" rel="noopener">📍 Maps</a><span id="phone-<?=$l['id']?>"></span><span id="website-<?=$l['id']?>"></span><?php else:?><?php if($l['phone']):?><a class="iconlink" href="tel:<?=h($l['phone'])?>">📞 <?=h($l['phone'])?></a><?php endif;?><?php if($l['instagram']):?><a class="iconlink" href="<?=h($l['instagram'])?>" target="_blank" rel="noopener">📸 Instagram</a><?php endif;?><?php if($l['website']):?><a class="iconlink" href="<?=h($l['website'])?>" target="_blank" rel="noopener">🌐 Website</a><?php endif;?><?php endif;?><span class="pill"><?=h($l['status'])?></span></div><div class="actions"><form method="post" style="display:inline"><input type="hidden" name="action" value="toggle_star"><input type="hidden" name="id" value="<?=$l['id']?>"><input type="hidden" name="starred" value="<?=!empty($l['starred'])?0:1?>"><button type="submit"><?=!empty($l['starred'])?'⭐ Starred':'☆ Star'?></button></form><?php if($isGoogle):?><button class="call" type="button" onclick="loadGoogleDetails(<?=$l['id']?>,this,true)">📞 Call</button><button class="wa" type="button" onclick="loadGoogleDetails(<?=$l['id']?>,this,false).then(()=>openWA(<?=$l['id']?>))">💬 WhatsApp</button><?php else:?><a class="call" href="tel:<?=h($l['phone'])?>" onclick="markContacted(<?=$l['id']?>)">📞 Call</a><?php if($waPhone):?><a class="wa" href="https://wa.me/<?=h($waPhone)?>?text=<?=$waMsg?>" target="_blank" rel="noopener">💬 WhatsApp</a><?php endif;?><?php endif;?><form method="post"><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?=$l['id']?>"><select name="status"><option>New</option><option>Follow-up</option><option>Interested</option><option>Not Interested</option><option>Converted</option><option>Wrong Number</option><option>DNC</option><option>Existing</option></select><button>Save status</button></form></div><details style="margin-top:12px"><summary>📝 Remark / Follow-up</summary><form method="post" style="margin-top:10px"><input type="hidden" name="action" value="remark"><input type="hidden" name="id" value="<?=$l['id']?>"><textarea name="remark" rows="2" style="width:100%;box-sizing:border-box" placeholder="What happened on the call?"><?=h($l['remark'])?></textarea><button class="btn" style="margin-top:6px">Save remark</button></form><form method="post" style="margin-top:8px"><input type="hidden" name="action" value="followup"><input type="hidden" name="id" value="<?=$l['id']?>"><input type="datetime-local" name="next" value="<?=h($l['next_followup'])?>" required><button class="btn primary">Schedule follow-up</button></form><?php if($view==='followups'&&($l['status']==='Follow-up'||$l['next_followup'])):?><form method="post" style="margin-top:8px" onsubmit="return confirm('Remove this lead from Follow-ups?')"><input type="hidden" name="action" value="remove_lead_followup"><input type="hidden" name="id" value="<?=$l['id']?>"><button class="btn" type="submit">🗑️ Remove from Follow-ups</button></form><?php endif;?></details><?php if($l['next_followup']):?><div class="muted" style="margin-top:8px">⏰ Next call: <?=h($l['next_followup'])?></div><?php endif;?></div><?php endforeach;?></div><?php endif;?><?php endif;?></div></div><script>
const googleCache={};
function markContacted(id){
 const el=document.getElementById('contact-'+id);if(el){el.textContent='✅ Contacted';}
 try{fetch('/',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=contact&id='+encodeURIComponent(id),credentials:'same-origin',keepalive:true}).catch(()=>{});}catch(e){}
}
async function loadGoogleDetails(id,button,callNow=false){if(googleCache[id]){if(callNow&&googleCache[id].phone)location.href='tel:'+googleCache[id].phone;return googleCache[id];}button.disabled=true;const old=button.textContent;button.textContent='Loading...';try{const r=await fetch('google_details.php?id='+encodeURIComponent(id),{credentials:'same-origin'});const j=await r.json();if(!r.ok)throw new Error(j.error||'Unable to load details');googleCache[id]=j;document.getElementById('name-'+id).textContent=j.name||'Google vendor';const phone=document.getElementById('phone-'+id);phone.innerHTML=j.phone?'<a class="iconlink" href="tel:'+escapeAttr(j.phone)+'">📞 '+escapeHtml(j.phone)+'</a>':'<span class="muted">No phone listed</span>';if(j.website)document.getElementById('website-'+id).innerHTML='<a class="iconlink" href="'+escapeAttr(j.website)+'" target="_blank" rel="noopener">🌐 Website</a>';if(j.maps)document.getElementById('maps-'+id).href=j.maps;if(callNow&&j.phone){markContacted(id);location.href='tel:'+j.phone;}button.textContent='✓ Details loaded';return j;}catch(e){alert(e.message);button.textContent=old;return null;}finally{button.disabled=false;}}
function openWA(id){const j=googleCache[id];if(!j||!j.phone){alert('No mobile number available for WhatsApp.');return;}let p=String(j.phone).replace(/\D/g,'');if(p.length===10)p='91'+p;let msg=encodeURIComponent('Hi '+(j.name||'there')+', this is '+<?=json_encode($_SESSION['name'])?>+' from SetMyWed. We help wedding vendors get more enquiries and bookings. Would you like to know more?');window.open('https://wa.me/'+p+'?text='+msg,'_blank');}
function escapeHtml(v){return String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));}function escapeAttr(v){return escapeHtml(v);}
const userName=<?=json_encode($_SESSION['name'])?>;
const leadStats={newc:<?=intval($stats['newc'])?>,fup:<?=intval($stats['fup'])?>,interested:<?=intval($stats['interested'])?>,total:<?=intval($stats['total'])?>};
const motivation=[
 userName+", you don't need to be the best today. You need to be better than yesterday.",
 userName+", winners are built by showing up when nobody is watching.",
 userName+", one good conversation can change your entire day. Make that conversation happen.",
 userName+", break the target into the next call, the next follow-up, and the next win.",
 userName+", don't let one rejection decide how the rest of your day feels.",
 userName+", discipline will take you places that motivation alone never can.",
 userName+", your future results are hiding inside today's small actions.",
 userName+", stop waiting for the perfect lead. Work the lead in front of you.",
 userName+", every expert was once a beginner who refused to quit.",
 userName+", don't count the hours. Make the hours count.",
 userName+", one focused hour can be more valuable than an entire distracted day.",
 userName+", don't chase motivation. Build momentum.",
 userName+", when the day feels slow, your consistency matters even more.",
 userName+", make yourself proud today by giving your best effort.",
 userName+", success often looks boring: call, follow-up, learn, repeat.",
 userName+", today's discipline becomes tomorrow's confidence.",
 userName+", your next breakthrough may be hiding behind one more attempt.",
 userName+", make fewer excuses and more attempts. Your numbers will eventually tell the story.",
 userName+", don't fear a 'no'. It moves you one step closer to the next 'yes'.",
 userName+", protect your energy. Protect your focus. Protect your goals.",
 userName+", start strong, stay sharp, finish proud.",
 userName+", you have another opportunity today to become the person you want to be.",
 "🔥 "+userName+", मेहनत इतनी खामोशी से करो कि सफलता शोर मचा दे।",
 "✨ "+userName+", रास्ते कभी खत्म नहीं होते, बस हिम्मत खत्म नहीं होनी चाहिए।",
 "💪 "+userName+", गिरना बुरा नहीं है, हर बार गिरकर फिर खड़ा न होना बुरा है।",
 "🚀 "+userName+", मंज़िल उन्हें मिलती है जो सपनों को मेहनत में बदलते हैं।",
 "🌟 "+userName+", आज की छोटी जीतों को हल्का मत समझो — यही कल की बड़ी सफलता बनती हैं।",
 "⚡ "+userName+", वक्त खराब हो सकता है, लेकिन तुम्हारी मेहनत का इरादा नहीं।",
 "🏆 "+userName+", जब तक जीत नहीं मिलती, तब तक कहानी खत्म नहीं होती।",
 "💫 "+userName+", खुद पर इतना भरोसा रखो कि मुश्किल रास्ता भी छोटा लगने लगे।",
 "🌅 "+userName+", हर सुबह एक नया मौका है — कल की गलती आज की पहचान मत बनने देना।",
 "🎯 "+userName+", सपने देखने वाले बहुत हैं, उन्हें पूरा करने वाले रोज़ आगे बढ़ते हैं।",
 "❤️ "+userName+", खुद से किया हुआ वादा निभाओ, दुनिया की तालियाँ अपने आप मिलेंगी।"
];
const jokes=[
 "हाथी और चींटी शादी में जा रहे थे।\nहाथी: मैं आगे चलूँगा।\nचींटी: क्यों?\nहाथी: बारात में मेरी इज्जत ज्यादा है।\nचींटी: ठीक है, लेकिन फोटो में मैं आगे रहूँगी… मेरी height कम है, इसलिए पूरा frame मेरा होगा। 😂",
 "संता: डॉक्टर साहब, मुझे लगता है मैं invisible हो गया हूँ।\nडॉक्टर: कौन बोला?\nसंता: यही तो समस्या है, कोई मुझे देख ही नहीं रहा! 😂",
 "बंता: भाई, शादी के बाद जिंदगी कैसी है?\nसंता: पहले मैं अपनी मर्जी से सोता था।\nबंता: अब?\nसंता: अब उनके खर्राटे पहले आ जाते हैं। 😂",
 "टीचर: अगर तुम्हारे पास 10 आम हैं और मैं 2 ले लूँ, तो क्या बचेगा?\nछात्र: सर, दुश्मनी। 😂",
 "मच्छर ने हाथी से पूछा: इतना बड़ा शरीर लेकर कैसे घूमते हो?\nहाथी: आत्मविश्वास से।\nमच्छर: थोड़ा मुझे भी दे दो।\nहाथी: पहले मेरे कान से हटो, आत्मविश्वास की परीक्षा मत लो। 😂",
 "चींटी ने हाथी से कहा: चलो दौड़ लगाते हैं।\nहाथी: तुम जीतोगी कैसे?\nचींटी: मैं दौड़ूँगी, तुम सोचते रहना। 😂",
 "एक आदमी डॉक्टर के पास गया।\nआदमी: डॉक्टर साहब, मुझे भूलने की बीमारी है।\nडॉक्टर: कब से?\nआदमी: क्या कब से? 😂",
 "बंता: भाई, तू रोज़ सुबह जल्दी कैसे उठ जाता है?\nसंता: अलार्म लगाता हूँ।\nबंता: फिर वापस नहीं सोता?\nसंता: नहीं, मैं अलार्म को ही बंद करके सो जाता हूँ। 😂",
 "संता: मैंने आज से gym शुरू कर दिया।\nबंता: कितनी exercise की?\nसंता: रास्ते में gym देखा, अंदर तक गया… फिर वापस आ गया। 😂",
 "पापा: बेटा, फोन इतना क्यों चला रहे हो?\nबेटा: टाइम देख रहा हूँ।\nपापा: घड़ी सामने रखी है।\nबेटा: उसमें notifications नहीं आते ना। 😂",
 "हाथी ने चींटी से पूछा: तुम्हें दुनिया कैसी दिखती है?\nचींटी: बिल्कुल साफ… मेरे सामने कोई बड़ा सिर नहीं होता। 😂",
 "संता: बंता, तू इतना खुश क्यों है?\nबंता: मेरी बीवी ने कहा कि मैं आज handsome लग रहा हूँ।\nसंता: फिर?\nबंता: मैंने पूछा 'आज ही?'… अब वो मुझसे बात नहीं कर रही। 😂",
 "संता: मैंने अपनी बीवी से कहा कि आज खाना मैं बनाऊँगा।\nबंता: फिर?\nसंता: Recipe देखी और बाहर से खाना मंगा लिया। 😂",
 "चींटी हाथी के घर गई।\nचींटी: भाई, कल मेरे घर चाय पर आना।\nहाथी: तुम्हारे घर में मैं आ जाऊँगा?\nचींटी: हाँ, बस पड़ोसी के घर से होकर आना… हमारा दरवाज़ा छोटा है। 😂"
];const q=document.getElementById("quote");if(q){
 const key="smw_motivation_open_"+<?=intval($me)?>;
 let n=parseInt(localStorage.getItem(key)||"0",10);
 const isJoke=(n%2===1);
 if(isJoke){
  q.innerHTML="<span style='display:block;white-space:pre-line'>"+jokes[Math.floor(Math.random()*jokes.length)]+"</span>";
 }else{
  q.textContent=motivation[Math.floor(Math.random()*motivation.length)];
 }
 localStorage.setItem(key,String(n+1));
}
const isAdminPage=<?=json_encode($isAdmin&&$view==='admin')?>;
if(!isAdminPage){
 const googleCards=[...document.querySelectorAll(".lead[data-lead-id]")].filter(card=>card.querySelector("button[onclick^='loadGoogleDetails']"));
 googleCards.forEach((card,i)=>{
  const btn=card.querySelector("button[onclick^='loadGoogleDetails']");
  setTimeout(()=>loadGoogleDetails(card.dataset.leadId,btn,false),i*120);
 });
}
if("serviceWorker" in navigator)navigator.serviceWorker.register("/sw.js").catch(()=>{});

const notificationKey="smw_notifications_enabled_"+<?=intval($me)?>;
const notifyBanner=document.getElementById("notifyBanner");
const enableNotify=document.getElementById("enableNotify");

async function enableLeadDeskNotifications(){
  if(!("Notification" in window)){alert("This browser does not support notifications.");return;}
  const permission=await Notification.requestPermission();
  if(permission==="granted"){
    localStorage.setItem(notificationKey,"1");
    if(notifyBanner)notifyBanner.style.display="none";
    scheduleLeadDeskReminders();
  }else{
    alert("Please allow notifications for LeadDesk in your browser settings.");
  }
}
if(enableNotify)enableNotify.addEventListener("click",enableLeadDeskNotifications);
const testNotify=document.getElementById("testNotify");
if(testNotify)testNotify.addEventListener("click",async()=>{
 if(!("Notification" in window)){alert("Notifications are not supported on this browser.");return;}
 if(Notification.permission!=="granted"){await Notification.requestPermission();}
 if(Notification.permission==="granted"){
  new Notification("🔔 SetMyWed LeadDesk",{body:"Test notification successful! LeadDesk reminders are working.",icon:"/icon.svg",tag:"smw-test"});
 }else alert("Please allow notifications first.");
});

function nextWeekdaySlot(){
  const now=new Date();
  const slots=[10,12,14,16,18];
  for(let d=0;d<8;d++){
    const day=new Date(now);
    day.setDate(now.getDate()+d);
    day.setHours(0,0,0,0);
    const dow=day.getDay();
    if(dow===0||dow===6)continue;
    for(const hour of slots){
      const t=new Date(day);
      t.setHours(hour,0,0,0);
      if(t>now)return t;
    }
  }
  return null;
}

function scheduleLeadDeskReminders(){
  if(!("Notification" in window)||Notification.permission!=="granted")return;
  const next=nextWeekdaySlot();
  if(!next)return;
  const delay=Math.max(1000,next.getTime()-Date.now());
  setTimeout(()=>{
    const d=new Date();
    if(d.getDay()!==0&&d.getDay()!==6){
      const followups=leadStats.fup||0;
      const newLeads=leadStats.newc||0;
      new Notification("🔔 SetMyWed LeadDesk",{
        body:"Check your new leads"+(followups?" and complete your "+followups+" follow-up"+(followups===1?"":"s"):"")+"."+(!followups&&newLeads?" You have "+newLeads+" new lead"+(newLeads===1?"":"s")+" waiting.":""),
        icon:"/icon.svg",
        tag:"smw-leaddesk-reminder"
      });
    }
    scheduleLeadDeskReminders();
  },delay);
}

if("Notification" in window){
  if(localStorage.getItem(notificationKey)==="1"&&Notification.permission==="granted"){
    if(notifyBanner)notifyBanner.style.display="none";
    scheduleLeadDeskReminders();
  }else if(Notification.permission!=="denied"){
    if(notifyBanner)notifyBanner.style.display="block";
  }
}</script></body></html>
