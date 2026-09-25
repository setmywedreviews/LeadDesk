<?php
session_start();
if (!isset($_SESSION['uid']) || ($_SESSION['role'] ?? '') !== 'admin') {
 http_response_code(403); exit('Admin access required');
}
$c=require __DIR__.'/config.php';
$db=$c['db'];
$pdo=new PDO("mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4",$db['user'],$db['pass'],[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
]);

$key=getenv('SERPAPI_API_KEY');
if(!$key){exit('<h2>SerpApi is not configured</h2><p>Add SERPAPI_API_KEY in Railway Variables.</p>');}

$city=trim($_GET['city']??'Delhi');
$category=$_GET['category']??'Makeup Artist';
$pages=max(1,min(10,(int)($_GET['pages']??3)));

if(!in_array($category,['Photography','Makeup Artist'],true)) exit('Invalid category');

$queries=$category==='Photography'
 ? [
   'site:instagram.com "wedding photographer" "'.$city.'" "91"',
   'site:instagram.com "candid wedding photographer" "'.$city.'" "91"',
   'site:instagram.com "wedding photography" "'.$city.'" "91"'
 ]
 : [
   'site:instagram.com "makeup artist" "'.$city.'" "91"',
   'site:instagram.com "bridal makeup artist" "'.$city.'" "91"',
   'site:instagram.com "MUA" "'.$city.'" "91"'
 ];

function serp($key,$q,$start){
 $u='https://serpapi.com/search.json?'.http_build_query([
  'engine'=>'google','q'=>$q,'location'=>$_GET['city']??'Delhi','google_domain'=>'google.co.in',
  'gl'=>'in','hl'=>'en','start'=>$start,'num'=>10,'api_key'=>$key
 ]);
 $ctx=stream_context_create(['http'=>['method'=>'GET','timeout'=>30,'ignore_errors'=>true,'header'=>"Accept: application/json\r\n"]]);
 $raw=@file_get_contents($u,false,$ctx);
 $code=0;
 foreach(($http_response_header??[]) as $h) if(preg_match('/^HTTP\/\S+\s+(\d+)/',$h,$m)) $code=(int)$m[1];
 return [$code,$raw];
}
function phones($text){
 preg_match_all('/(?:\+91[\s-]?)?[6-9]\d{9}\b/',$text,$m);
 $out=[];
 foreach($m[0] as $p){
  $d=preg_replace('/\D+/','',$p);
  if(strlen($d)===12 && str_starts_with($d,'91')) $d=substr($d,2);
  if(strlen($d)===10 && preg_match('/^[6-9]/',$d)) $out[$d]='+91 '.$d;
 }
 return array_values($out);
}
function assign(PDO $pdo,$cat){
 $s=$pdo->prepare("SELECT u.id FROM users u LEFT JOIN leads l ON l.assigned_to=u.id AND DATE(l.created_at)=CURDATE()
 WHERE u.role='sales' AND u.active=1 AND u.category=? GROUP BY u.id ORDER BY COUNT(l.id),u.id LIMIT 1");
 $s->execute([$cat]); $id=$s->fetchColumn(); return $id?(int)$id:null;
}

$added=$duplicates=$errors=$seen=$noPhone=0;$messages=[];$qcount=0;
foreach($queries as $q){
 for($page=0;$page<$pages;$page++){
  [$code,$raw]=serp($key,$q,$page*10);$qcount++;
  if($code>=400||!$raw){$errors++;$messages[]='HTTP '.$code;continue;}
  $j=json_decode($raw,true);
  if(isset($j['error'])){$errors++;$messages[]=$j['error'];continue;}
  $items=$j['organic_results']??[];
  if(!$items) break;
  foreach($items as $it){
   $seen++;
   $link=trim($it['link']??'');
   if(!$link||stripos($link,'instagram.com')===false) continue;
   $title=trim($it['title']??'');
   $snippet=trim($it['snippet']??'');
   $text=$title.' '.$snippet.' '.($it['rich_snippet']['top']['detected_extensions']['phone']??'');
   $ps=phones($text);
   if(!$ps){$noPhone++;continue;}
   $phone=$ps[0];
   $exists=$pdo->prepare("SELECT id FROM leads WHERE phone=? AND category=? LIMIT 1");
   $exists->execute([$phone,$category]);
   if($exists->fetchColumn()){$duplicates++;continue;}
   $assigned=assign($pdo,$category);
   if(!$assigned){$errors++;continue;}
   try{
    $ins=$pdo->prepare("INSERT INTO leads(business_name,category,city,phone,instagram,source,source_url,lead_score,assigned_to,google_query) VALUES(?,?,?,?,?,'Instagram Search',?,?,?,?)");
    $ins->execute([$title?:'Instagram vendor',$category,$city,$phone,$link,$link,70,$assigned,$q]);
    $added++;
   }catch(Throwable $e){$errors++;$messages[]='DB insert failed';error_log($e->getMessage());}
  }
 }
}
?>
<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1">
<style>body{font-family:system-ui;background:#f6f6f7;margin:0}.wrap{max-width:800px;margin:35px auto;padding:20px}.card{background:#fff;border:1px solid #ddd;border-radius:16px;padding:20px}li{margin:7px 0}.ok{font-size:22px;font-weight:800}</style>
<div class="wrap"><div class="card"><h2>Instagram lead collector finished</h2>
<p><?=htmlspecialchars($city)?> · <?=htmlspecialchars($category)?> · <?=$pages?> pages/query</p>
<ul><li>queries=<?=htmlspecialchars($qcount)?></li><li>results seen=<?=htmlspecialchars($seen)?></li><li><b>added=<?=htmlspecialchars($added)?></b></li><li>duplicates=<?=htmlspecialchars($duplicates)?></li><li>no mobile → skipped=<?=htmlspecialchars($noPhone)?></li><li>errors=<?=htmlspecialchars($errors)?></li></ul>
<?php if($messages):?><p><?=htmlspecialchars(implode(' | ',array_unique($messages)))?></p><?php endif;?>
<p>Only leads with a detected Indian mobile number are inserted.</p><p><a href="/">Back to LeadDesk</a></p>
</div></div>