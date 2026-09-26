<?php
session_start();
if(!isset($_SESSION['uid']) || ($_SESSION['role']??'')!=='admin'){http_response_code(403);exit('Admin access required');}
$c=require __DIR__.'/config.php';$db=$c['db'];
$pdo=new PDO("mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4",$db['user'],$db['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function cleanUrl($u){$u=trim($u);if($u==='')return '';if(!preg_match('~^https?://~i',$u))$u='https://'.$u;return $u;}
function instagramUrl($u){$u=cleanUrl($u);$p=parse_url($u);if(!$p||empty($p['host'])||stripos($p['host'],'instagram.com')===false)return '';return rtrim($u,'/');}
function fetchPage($url){
 $ch=curl_init($url);
 curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>3,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,CURLOPT_USERAGENT=>'Mozilla/5.0 (compatible; SetMyWed LeadDesk public profile importer)',CURLOPT_HTTPHEADER=>['Accept: text/html,application/xhtml+xml']]);
 $body=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$final=curl_getinfo($ch,CURLINFO_EFFECTIVE_URL);curl_close($ch);
 return [$code,$body?:'',$final?:$url];
}
function meta($html,$name){
 if(preg_match('~<meta[^>]+(?:property|name)=["\']'.preg_quote($name,'~').'["\'][^>]+content=["\']([^"\']*)["\']~i',$html,$m))return html_entity_decode($m[1],ENT_QUOTES,'UTF-8');
 if(preg_match('~<meta[^>]+content=["\']([^"\']*)["\'][^>]+(?:property|name)=["\']'.preg_quote($name,'~').'["\']~i',$html,$m))return html_entity_decode($m[1],ENT_QUOTES,'UTF-8');
 return '';
}
function phoneFrom($text){
 preg_match_all('/(?:\+91[\s-]?)?[6-9]\d{9}\b/',$text,$m);foreach($m[0] as $p){$d=preg_replace('/\D+/','',$p);if(strlen($d)===12&&str_starts_with($d,'91'))$d=substr($d,2);if(strlen($d)===10&&preg_match('/^[6-9]/',$d))return '+91 '.$d;}return '';
}
function profileName($url,$title,$desc){
 $host=parse_url($url,PHP_URL_HOST);$path=trim((string)parse_url($url,PHP_URL_PATH),'/');$slug=explode('/',$path)[0]??'';
 $title=trim(preg_replace('/\s*[|·]\s*Instagram.*$/i','',$title));
 if($title&&stripos($title,'Instagram')===false)return $title;
 if($desc&&preg_match('/^([^·|]+?)\s*(?:·|\||$)/u',$desc,$m))return trim($m[1]);
 return $slug?:'Instagram vendor';
}
$category=$_POST['category']??'Photography';if(!in_array($category,['Photography','Makeup Artist'],true))$category='Photography';
$city=trim($_POST['city']??'');$sourceNote=trim($_POST['source_note']??'Instagram public profile');
$selected=array_values(array_unique(array_map('intval',$_POST['employees']??[])));
$employees=[];
if($selected){$in=implode(',',array_fill(0,count($selected),'?'));$st=$pdo->prepare("SELECT id,name,category FROM users WHERE role='sales' AND active=1 AND id IN ($in) ORDER BY id");$st->execute($selected);$employees=$st->fetchAll();}
$raw=trim($_POST['profiles']??'');$urls=[];
if($raw){
 foreach(preg_split('/\R+/',$raw) as $line){
  $line=trim($line);if(!$line)continue;
  preg_match_all('~https?://(?:www\.)?instagram\.com/[A-Za-z0-9._-]+(?:/[^\s,;]*)?~i',$line,$mm);
  if($mm[0])foreach($mm[0] as $u)$urls[]=instagramUrl($u);
  else{$u=instagramUrl($line);if($u)$urls[]=$u;}
 }
}
$urls=array_values(array_unique(array_filter($urls)));
$stats=['found'=>count($urls),'added'=>0,'duplicates'=>0,'invalid'=>0,'blocked'=>0,'errors'=>0,'noPhone'=>0];$messages=[];$i=0;
foreach($urls as $url){
 if(!$url){$stats['invalid']++;continue;}
 [$code,$html,$final]=fetchPage($url);
 if($code>=400||!$html){$stats['blocked']++;$messages[]='Could not read '.$url;continue;}
 $title=meta($html,'og:title')?:meta($html,'twitter:title');
 $desc=meta($html,'og:description')?:meta($html,'description');
 $name=profileName($url,$title,$desc);
 $phone=phoneFrom(strip_tags($html).' '.$desc);
 $canonical=instagramUrl(meta($html,'og:url'))?:instagramUrl($final)?:$url;
 $dup=$pdo->prepare('SELECT id FROM leads WHERE instagram=? AND category=? LIMIT 1');$dup->execute([$canonical,$category]);
 if($dup->fetchColumn()){$stats['duplicates']++;continue;}
 if($phone){$dup=$pdo->prepare('SELECT id FROM leads WHERE phone=? AND category=? LIMIT 1');$dup->execute([$phone,$category]);if($dup->fetchColumn()){$stats['duplicates']++;continue;}}
 if(!$employees){$stats['errors']++;$messages[]='Select at least one employee.';break;}
 $assigned=$employees[$i%count($employees)]['id'];$i++;
 try{
  $ins=$pdo->prepare("INSERT INTO leads(business_name,category,city,phone,instagram,source,source_url,lead_score,assigned_to) VALUES(?,?,?,?,?,'Instagram Public',?,?,?,?)");
  $ins->execute([$name,$category,$city,$phone?:null,$canonical,$canonical,75,$assigned]);
  $stats['added']++;
  if(!$phone)$stats['noPhone']++;
 }catch(Throwable $e){$stats['errors']++;error_log($e->getMessage());}
}
?>
<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><style>
body{font-family:'Plus Jakarta Sans',system-ui;background:#f4f8ff;color:#0f172a;margin:0}.wrap{max-width:900px;margin:28px auto;padding:18px}.card{background:#fff;border:1px solid #dbeafe;border-radius:20px;padding:20px;box-shadow:0 10px 28px #1e40af0a}.grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}input,select,textarea{width:100%;box-sizing:border-box;padding:11px;border:1px solid #dbe3ef;border-radius:11px;margin-top:6px}.btn{display:inline-block;padding:10px 14px;border:0;border-radius:11px;background:#2563eb;color:#fff;text-decoration:none;font-weight:800;margin-top:12px}.muted{color:#64748b;font-size:13px}.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:15px}.stat{padding:13px;background:#eff6ff;border-radius:12px}.stat b{display:block;font-size:22px;color:#1d4ed8}@media(max-width:650px){.grid,.stats{grid-template-columns:1fr 1fr}}
</style><div class="wrap"><a href="?view=admin" class="btn" style="margin-top:0;background:#fff;color:#1d4ed8;border:1px solid #bfdbfe">← Admin</a><div class="card" style="margin-top:12px"><h2>📸 Instagram Public Profile Importer</h2><p class="muted">Paste public Instagram profile URLs, one per line. LeadDesk reads only publicly accessible page information, deduplicates profiles, and distributes new leads equally among selected employees.</p>
<form method="post"><div class="grid"><label>Category<select name="category"><option>Photography</option><option>Makeup Artist</option></select></label><label>City<input name="city" placeholder="Noida, Delhi, Gurgaon..."></label></div>
<label style="display:block;margin-top:12px">Select employees</label><div class="grid"><?php foreach($pdo->query("SELECT id,name,category FROM users WHERE role='sales' AND active=1 ORDER BY id")->fetchAll() as $u):?><label><input type="checkbox" name="employees[]" value="<?=$u['id']?>" style="width:auto"> <?=h($u['name'])?> · <?=h($u['category'])?></label><?php endforeach;?></div>
<label style="display:block;margin-top:12px">Instagram profile URLs</label><textarea name="profiles" rows="12" placeholder="https://www.instagram.com/examplephotography/
https://www.instagram.com/examplemua/"></textarea>
<button class="btn" type="submit">📥 Fetch & Distribute Leads</button></form>
<?php if($_SERVER['REQUEST_METHOD']==='POST'):?><div class="stats"><div class="stat">Profiles<b><?=$stats['found']?></b></div><div class="stat">Added<b><?=$stats['added']?></b></div><div class="stat">Duplicates<b><?=$stats['duplicates']?></b></div><div class="stat">No phone<b><?=$stats['noPhone']?></b></div></div><?php if($messages):?><p class="muted"><?=h(implode(' | ',$messages))?></p><?php endif;?><?php endif;?></div></div>