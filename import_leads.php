<?php
ini_set('session.gc_maxlifetime','2592000');
session_set_cookie_params(['lifetime'=>2592000,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
session_start();
if(($_SESSION['role']??'')!=='admin'){http_response_code(403);exit('Admin only');}
$c=require __DIR__.'/config.php'; $db=$c['db'];
$pdo=new PDO("mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4",$db['user'],$db['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
$users=$pdo->query("SELECT id,name,category FROM users WHERE role='sales' AND active=1 ORDER BY id")->fetchAll();
$category=$_POST['category']??'Photography'; $source=trim($_POST['source']??'Imported'); $selected=array_values(array_filter(array_map('intval',$_POST['users']??[])));
$message='';$error='';

function cleanPhone($v){$v=trim((string)$v);if($v==='')return '';if(preg_match_all('/(?:\+?91[\s.-]?)?[6-9]\d[\d\s().-]{7,}/',$v,$m))$v=$m[0][0];return preg_replace('/[^0-9+]/','',$v);}
function urlFrom($v,$type=''){$v=trim((string)$v);if($v==='')return '';if(stripos($v,'instagram.com')!==false&&!preg_match('~^https?://~i',$v))$v='https://'.$v;if(preg_match('~^https?://~i',$v))return $v;if($type==='instagram'&&preg_match('/^[A-Za-z0-9._@-]+$/',$v))return 'https://instagram.com/'.trim($v,'@/');if($type==='website'&&preg_match('/^[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/',$v))return 'https://'.$v;return '';}
function parseRows($raw){
 $out=[];$raw=str_replace(["\r\n","\r"],"\n",$raw);$lines=array_values(array_filter(array_map('trim',explode("\n",$raw)),fn($x)=>$x!==''));
 if(!$lines)return [];
 $first=$lines[0];$looksCsv=(strpos($first,",")!==false||strpos($first,"\t")!==false||strpos($first,";")!==false);
 if($looksCsv){
  $delim=strpos($first,"\t")!==false?"\t":(strpos($first,";")!==false?";":",");$tmp=str_getcsv($first,$delim);$header=[];
  foreach($tmp as $i=>$v)$header[$i]=strtolower(trim(preg_replace('/[^a-z0-9]+/','_',str_replace(['phone_number','mobile_number'],['phone','phone'],(string)$v))));
  $known=0;foreach($header as $v)if(preg_match('/name|business|vendor|company|phone|mobile|city|location|instagram|website|url/',$v))$known++;
  if($known>=2){array_shift($lines);foreach($lines as $line){$a=str_getcsv($line,$delim);$r=['business_name'=>'','phone'=>'','city'=>'','instagram'=>'','website'=>''];
   foreach($a as $i=>$v){$k=$header[$i]??'';$v=trim($v);if(preg_match('/business|vendor|company|name/',$k)&&$r['business_name']==='')$r['business_name']=$v;elseif(preg_match('/phone|mobile|whatsapp|contact/',$k))$r['phone']=cleanPhone($v);elseif(preg_match('/city|location|area/',$k))$r['city']=$v;elseif(stripos($k,'instagram')!==false)$r['instagram']=urlFrom($v,'instagram');elseif(stripos($k,'website')!==false||$k==='url')$r['website']=urlFrom($v,'website');}
   if($r['business_name']!==''||$r['phone']!=='')$out[]=$r;}return $out;}
 }
 foreach($lines as $line){$r=['business_name'=>'','phone'=>'','city'=>'','instagram'=>'','website'=>''];preg_match('/(?:\+?91[\s.-]?)?[6-9]\d[\d\s().-]{7,}/',$line,$pm);if($pm)$r['phone']=cleanPhone($pm[0]);preg_match('~https?://[^\s,;]+~i',$line,$um);if($um){$u=rtrim($um[0],"\"'");if(stripos($u,'instagram.com')!==false)$r['instagram']=$u;else$r['website']=$u;}if(preg_match('/(?:instagram\.com\/)([A-Za-z0-9._-]+)/i',$line,$im))$r['instagram']='https://instagram.com/'.$im[1];if(preg_match('/\(([^()]{2,50})\)\s*$/',$line,$cm))$r['city']=trim($cm[1]);$name=$line;if($r['phone'])$name=str_replace($pm[0],'',$name);if($r['instagram'])$name=str_replace($r['instagram'],'',$name);if($r['website'])$name=str_replace($r['website'],'',$name);$name=preg_replace('/\([^()]{2,50}\)\s*$/','',$name);$name=preg_replace('/^[\s\-:|,]+|[\s\-:|,]+$/','',$name);$r['business_name']=trim($name);if($r['business_name']===''&&$r['phone'])$r['business_name']='Imported Lead '.$r['phone'];if($r['business_name']!==''||$r['phone']!=='')$out[]=$r;}
 return $out;
}
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='import'){
 $raw=trim($_POST['raw_data']??'');
 if(isset($_FILES['csv_file'])&&$_FILES['csv_file']['error']===UPLOAD_ERR_OK){if($_FILES['csv_file']['size']>10*1024*1024)$error='CSV/text file must be under 10 MB.';else{$file=file_get_contents($_FILES['csv_file']['tmp_name']);if($file!==false)$raw=$file;}}
 if(!$error&&count($selected)<1)$error='Select at least one employee.';
 if(!$error&&!in_array($category,['Photography','Makeup Artist'],true))$error='Select a valid category.';
 if(!$error&&$raw==='')$error='Paste data or upload a CSV/text file.';
 if(!$error){$rows=parseRows($raw);if(!$rows)$error='No lead rows could be detected. Try one lead per line or upload a CSV with headers.';
  else{$existsPhone=$pdo->prepare('SELECT id FROM leads WHERE phone=? AND category=? LIMIT 1');$existsName=$pdo->prepare('SELECT id FROM leads WHERE business_name=? AND city=? AND category=? LIMIT 1');$ins=$pdo->prepare('INSERT INTO leads(business_name,category,city,phone,instagram,website,source,lead_score,assigned_to) VALUES(?,?,?,?,?,?,?,?,?)');$added=0;$duplicates=0;$invalid=0;
   foreach($rows as $r){$phone=$r['phone'];$name=trim($r['business_name']);$city=trim($r['city']);if($phone!==''){$existsPhone->execute([$phone,$category]);if($existsPhone->fetchColumn()){$duplicates++;continue;}}elseif($name!==''){$existsName->execute([$name,$city,$category]);if($existsName->fetchColumn()){$duplicates++;continue;}}else{$invalid++;continue;}$uid=$selected[$added%count($selected)];try{$ins->execute([$name?:'Imported Lead',$category,$city?:null,$phone?:null,$r['instagram']?:null,$r['website']?:null,$source?:'Imported',50,$uid]);$added++;}catch(Throwable $e){$duplicates++;}}
   $message="Imported {$added} leads equally across ".count($selected)." employees. Skipped {$duplicates} duplicates and {$invalid} unusable rows.";
  }
 }
}
?>
<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>LeadDesk Lead Importer</title><style>
*{box-sizing:border-box}body{margin:0;font-family:Inter,system-ui,-apple-system,sans-serif;background:#f4f8ff;color:#0f172a}.wrap{max-width:1100px;margin:auto;padding:24px}.top{background:linear-gradient(135deg,#1d4ed8,#2563eb 55%,#4f46e5);color:#fff;border-radius:24px;padding:22px 25px;display:flex;justify-content:space-between;align-items:center}.btn{display:inline-block;padding:10px 14px;border:1px solid #dbe3ef;border-radius:11px;background:#fff;color:#172033;text-decoration:none;font-weight:750;cursor:pointer}.primary{background:#2563eb;color:#fff;border-color:#2563eb}.card{background:#fff;border:1px solid #dbeafe;border-radius:20px;padding:20px;margin-top:15px;box-shadow:0 10px 28px #1e40af0a}.grid{display:grid;grid-template-columns:1fr 1fr;gap:15px}.input,textarea,select{width:100%;padding:11px;border:1px solid #dbe3ef;border-radius:11px;background:#fff;font:inherit}.muted{color:#64748b;font-size:13px}.users{display:grid;grid-template-columns:repeat(2,1fr);gap:9px}.user{border:1px solid #dbe3ef;border-radius:12px;padding:11px}.user input{margin-right:7px}@media(max-width:700px){.wrap{padding:12px}.grid,.users{grid-template-columns:1fr}.top{border-radius:18px}}
</style></head><body><div class="wrap"><div class="top"><div><h2 style="margin:0">📥 Lead Importer</h2><div style="opacity:.85;margin-top:4px">Paste raw data or upload CSV → convert → distribute equally</div></div><a class="btn" href="?view=admin">← Back to Admin</a></div>
<?php if($message):?><div class="card" style="border-color:#86efac;background:#f0fdf4"><b>✅ <?=h($message)?></b></div><?php endif;?><?php if($error):?><div class="card" style="border-color:#fecaca;background:#fef2f2"><b>⚠️ <?=h($error)?></b></div><?php endif;?>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="import">
<div class="card"><h3 style="margin-top:0">1. Lead category & source</h3><div class="grid"><div><label>Category</label><select name="category"><option value="Photography" <?=$category==='Photography'?'selected':''?>>📷 Photography</option><option value="Makeup Artist" <?=$category==='Makeup Artist'?'selected':''?>>💄 Makeup Artist</option></select></div><div><label>Source</label><input class="input" name="source" value="<?=h($source)?>" placeholder="Google, Instagram, CSV, Referral..."></div></div></div>
<div class="card"><h3 style="margin-top:0">2. Select employees</h3><p class="muted">Leads are assigned round-robin so selected employees receive them as equally as possible.</p><div class="users"><?php foreach($users as $u):?><label class="user"><input type="checkbox" name="users[]" value="<?=$u['id']?>" <?=in_array((int)$u['id'],$selected,true)?'checked':''?>> <b><?=h($u['name'])?></b><span class="muted"> · <?=h($u['category'])?></span></label><?php endforeach;?></div></div>
<div class="card"><h3 style="margin-top:0">3. Paste raw data</h3><p class="muted">Accepted: one lead per line, tab-separated data, comma-separated CSV, or CSV with headers such as Name, Phone, City, Instagram, Website.</p><textarea name="raw_data" rows="12" placeholder="Honey Makeup — 9876543210 (Noida)
Priya Photography, 9876543211, Noida, https://instagram.com/example

Or:
Business Name,Phone,City,Instagram,Website
ABC Photography,9876543210,Noida,https://instagram.com/abc,https://abc.com"></textarea></div>
<div class="card"><h3 style="margin-top:0">4. Or upload CSV / TXT</h3><input type="file" name="csv_file" accept=".csv,.txt,text/csv,text/plain" style="width:100%;padding:12px;border:1px dashed #93c5fd;border-radius:12px"></div>
<div class="card"><button class="btn primary" type="submit">🚀 Convert & Distribute Leads</button></div></form></div></body></html>