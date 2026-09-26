<?php
session_start();
if(!isset($_SESSION['uid']) || ($_SESSION['role']??'')!=='admin'){http_response_code(403);exit('Admin access required');}
$c=require __DIR__.'/config.php';$db=$c['db'];
$pdo=new PDO("mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4",$db['user'],$db['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function searchWeb($base,$q){
 $base=rtrim($base,'/');
 $url=$base.'/search?'.http_build_query(['q'=>$q,'format'=>'json','categories'=>'general','language'=>'en','safesearch'=>1,'pageno'=>1]);
 $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>6,CURLOPT_TIMEOUT=>18,CURLOPT_USERAGENT=>'Mozilla/5.0 SetMyWed LeadDesk public web search']);
 $raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
 if($code>=400||!$raw)return [[], 'Search service returned HTTP '.$code];
 $j=json_decode($raw,true);if(!is_array($j))return [[], 'Search service returned invalid JSON'];
 return [$j['results']??[], ''];
}
function profileUrl($url){
 $p=parse_url($url);if(!$p||empty($p['host'])||stripos($p['host'],'instagram.com')===false)return '';
 $path=trim((string)($p['path']??''),'/');$parts=explode('/',$path);$u=$parts[0]??'';
 if(!$u||in_array(strtolower($u),['p','reel','reels','stories','explore','accounts','direct','about','developer','web','tv'],true))return '';
 if(!preg_match('/^[a-z0-9._-]+$/i',$u))return '';
 return 'https://www.instagram.com/'.trim($u).'/';
}
function phoneFrom($text){
 preg_match_all('/(?:\+91[\s-]?)?[6-9]\d{9}\b/',$text,$m);
 foreach($m[0] as $p){$d=preg_replace('/\D+/','',$p);if(strlen($d)===12&&str_starts_with($d,'91'))$d=substr($d,2);if(strlen($d)===10&&preg_match('/^[6-9]/',$d))return '+91 '.$d;}
 return '';
}
function nameFrom($title,$content,$url){
 $bad=[
  'the site owner hides the web page description',
  'the site owner hides this page',
  'instagram',
  'log in',
  'login',
  'sign up'
 ];
 $title=trim(preg_replace('/\s*[|·-]\s*Instagram.*$/i','',$title));
 $tl=strtolower(trim($title));
 if($title&&!in_array($tl,$bad,true)&&stripos($title,'Instagram')===false)return $title;
 if($content&&preg_match('/^([^·|]+?)\s*(?:·|\||$)/u',$content,$m)){
  $candidate=trim($m[1]);$cl=strtolower($candidate);
  if($candidate&&!in_array($cl,$bad,true)&&stripos($candidate,'site owner hides')===false)return $candidate;
 }
 $p=parse_url($url,PHP_URL_PATH);$username=trim(explode('/',trim((string)$p,'/'))[0]??'');
 return $username?'@'.$username:'Instagram vendor';
}
function getEmployees(PDO $pdo,$ids){
 $ids=array_values(array_unique(array_map('intval',$ids)));if(!$ids)return [];
 $in=implode(',',array_fill(0,count($ids),'?'));$s=$pdo->prepare("SELECT id,name,category FROM users WHERE role='sales' AND active=1 AND id IN ($in) ORDER BY id");$s->execute($ids);return $s->fetchAll();
}
$category=$_POST['category']??'Photography';if(!in_array($category,['Photography','Makeup Artist'],true))$category='Photography';
$city=trim($_POST['city']??'');$keyword=trim($_POST['keyword']??'');$limit=max(1,min(100,(int)($_POST['limit']??50)));$employees=getEmployees($pdo,$_POST['employees']??[]);
$stats=['queries'=>0,'results'=>0,'profiles'=>0,'added'=>0,'duplicates'=>0,'skipped'=>0,'noPhone'=>0,'errors'=>0];$messages=[];$profiles=[];
$searchBase=getenv('SEARCH_API_URL')?:'https://search.lumy.live';
// Repair generic crawler text accidentally saved as Instagram lead names.
$repairRows=$pdo->query("SELECT id,instagram FROM leads WHERE source='Instagram Search' AND (business_name LIKE 'The site owner hides%' OR business_name IN ('Instagram','Login','Log in','Sign up'))")->fetchAll();
foreach($repairRows as $rr){
 $u=profileUrl($rr['instagram']??'');
 if($u){
  $path=trim((string)parse_url($u,PHP_URL_PATH),'/');$username=trim(explode('/',$path)[0]??'');
  if($username)$pdo->prepare('UPDATE leads SET business_name=? WHERE id=?')->execute(['@'.$username,$rr['id']]);
 }
}
if($_SERVER['REQUEST_METHOD']==='POST'&&$keyword&&$city&&$employees){
 $k=str_replace('"','',$keyword);$ct=str_replace('"','',$city);
 $templates=[
  'site:instagram.com "'.$k.'" "'.$ct.'"',
  'site:instagram.com "'.$k.'" '.$ct,
  'site:instagram.com "'.$k.'" "'.$ct.'" India',
  'site:instagram.com '.$k.' '.$ct.' Instagram',
  'site:instagram.com "bridal makeup" "'.$ct.'"',
  'site:instagram.com "makeup artist" "'.$ct.'"',
  'site:instagram.com "MUA" "'.$ct.'"',
  'site:instagram.com "bridal MUA" "'.$ct.'"',
  'site:instagram.com "freelance makeup artist" "'.$ct.'"',
  'site:instagram.com "wedding makeup artist" "'.$ct.'"',
  'site:instagram.com "makeup artist" '.$ct,
  'site:instagram.com "bridal" "makeup" '.$ct
 ];
 foreach($templates as $q){
  if(count($profiles)>=$limit)break;
  for($page=1;$page<=3;$page++){
   if(count($profiles)>=$limit)break;
   $stats['queries']++;
   $searchQ=$q.' ';
   $baseUrl=$searchBase;
   $url=rtrim($baseUrl,'/').'/search?'.http_build_query(['q'=>$searchQ,'format'=>'json','categories'=>'general','language'=>'en','safesearch'=>1,'pageno'=>$page]);
   $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>6,CURLOPT_TIMEOUT=>18,CURLOPT_USERAGENT=>'Mozilla/5.0 SetMyWed LeadDesk public web search']);
   $raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
   if($code>=400||!$raw){$messages[]='Search service returned HTTP '.$code;continue;}
   $j=json_decode($raw,true);$results=is_array($j)?($j['results']??[]):[];
   foreach($results as $r){
    if(count($profiles)>=$limit)break;
    $u=profileUrl($r['url']??'');if(!$u)continue;
    $profiles[$u]=['url'=>$u,'title'=>trim(strip_tags($r['title']??'')),'content'=>trim(strip_tags($r['content']??''))];
   }
   if(count($results)<5)break;
  }
 }
 $profiles=array_values($profiles);$stats['results']=count($profiles);
 $i=0;
 foreach($profiles as $r){
  if($stats['added']>=$limit)break;
  $url=$r['url'];$name=nameFrom($r['title'],$r['content'],$url);$phone=phoneFrom($r['title'].' '.$r['content']);
  $dup=$pdo->prepare('SELECT id FROM leads WHERE instagram=? AND category=? LIMIT 1');$dup->execute([$url,$category]);
  if($dup->fetchColumn()){$stats['duplicates']++;continue;}
  if($phone){$dup=$pdo->prepare('SELECT id FROM leads WHERE phone=? AND category=? LIMIT 1');$dup->execute([$phone,$category]);if($dup->fetchColumn()){$stats['duplicates']++;continue;}}
  $assigned=$employees[$i%count($employees)]['id'];$i++;
  try{
   $ins=$pdo->prepare("INSERT INTO leads(business_name,category,city,phone,instagram,source,source_url,lead_score,assigned_to) VALUES(?,?,?,?,?,'Instagram Search',?,?,?)");
   $ins->execute([$name,$category,$city,$phone?:null,$url,$url,75,$assigned]);$stats['added']++;if(!$phone)$stats['noPhone']++;
  }catch(Throwable $e){$stats['errors']++;error_log($e->getMessage());}
 }
}elseif($_SERVER['REQUEST_METHOD']==='POST'){
 if(!$keyword)$messages[]='Enter a search keyword.';if(!$city)$messages[]='Enter a city.';if(!$employees)$messages[]='Select at least one employee.';
}
?>
<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><style>
body{font-family:'Plus Jakarta Sans',system-ui;background:#f4f8ff;color:#0f172a;margin:0}.wrap{max-width:950px;margin:28px auto;padding:18px}.card{background:#fff;border:1px solid #dbeafe;border-radius:20px;padding:20px;box-shadow:0 10px 28px #1e40af0a}.grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}input,select{width:100%;box-sizing:border-box;padding:11px;border:1px solid #dbe3ef;border-radius:11px;margin-top:6px}.btn{display:inline-block;padding:10px 14px;border:0;border-radius:11px;background:#2563eb;color:#fff;text-decoration:none;font-weight:800;margin-top:12px}.muted{color:#64748b;font-size:13px}.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:15px}.stat{padding:13px;background:#eff6ff;border-radius:12px}.stat b{display:block;font-size:22px;color:#1d4ed8}.results{margin-top:15px}.result{padding:10px;border-top:1px solid #e2e8f0}.result a{color:#2563eb;text-decoration:none}@media(max-width:650px){.grid,.stats{grid-template-columns:1fr 1fr}}
</style><div class="wrap"><a href="?view=admin" class="btn" style="margin-top:0;background:#fff;color:#1d4ed8;border:1px solid #bfdbfe">← Admin</a><div class="card" style="margin-top:12px"><h2>🔎 Instagram Lead Search</h2><p class="muted">Search public web results for Instagram profiles. Example: <b>Bridal Makeup Artist + Delhi</b>. Only public/indexed profile URLs are collected.</p>
<form method="post"><div class="grid"><label>Keyword<input name="keyword" value="<?=h($keyword)?>" placeholder="Bridal Makeup Artist"></label><label>City<input name="city" value="<?=h($city)?>" placeholder="Delhi"></label><label>Category<select name="category"><option <?= $category==='Photography'?'selected':''?>>Photography</option><option <?= $category==='Makeup Artist'?'selected':''?>>Makeup Artist</option></select></label><label>Leads wanted<input type="number" name="limit" min="1" max="100" value="<?=$limit?>"></label></div>
<label style="display:block;margin-top:14px">Distribute to employees</label><div class="grid"><?php foreach($pdo->query("SELECT id,name,category FROM users WHERE role='sales' AND active=1 ORDER BY id")->fetchAll() as $u):?><label><input type="checkbox" name="employees[]" value="<?=$u['id']?>" style="width:auto"> <?=h($u['name'])?> · <?=h($u['category'])?></label><?php endforeach;?></div>
<button class="btn" type="submit">🔎 Search & Add Leads</button></form>
<?php if($_SERVER['REQUEST_METHOD']==='POST'):?><div class="stats"><div class="stat">Searches<b><?=$stats['queries']?></b></div><div class="stat">Profiles<b><?=$stats['profiles']?:$stats['results']?></b></div><div class="stat">Added<b><?=$stats['added']?></b></div><div class="stat">Duplicates<b><?=$stats['duplicates']?></b></div></div><?php if($messages):?><p class="muted"><?=h(implode(' | ',array_unique($messages)))?></p><?php endif;?><?php if($profiles):?><div class="results"><b>Profiles found</b><?php foreach(array_slice($profiles,0,50) as $r):?><div class="result"><a href="<?=h($r['url'])?>" target="_blank" rel="noopener"><?=h($r['title']?:$r['url'])?></a><div class="muted"><?=h($r['content'])?></div></div><?php endforeach;?></div><?php endif;?><?php endif;?></div></div>