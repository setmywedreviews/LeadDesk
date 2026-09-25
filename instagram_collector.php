<?php
session_start();
$isCli = (PHP_SAPI === 'cli');
if (!$isCli && (!isset($_SESSION['uid']) || ($_SESSION['role'] ?? '') !== 'admin')) {
 http_response_code(403); exit('Admin access required');
}
$c=require __DIR__.'/config.php'; $db=$c['db'];
$pdo=new PDO("mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4",$db['user'],$db['pass'],[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
]);
$key=getenv('SERPAPI_API_KEY');
if(!$key) exit("SERPAPI_API_KEY is not configured\n");

$cities=require __DIR__.'/google_cities.php';
$batch=max(0,(int)($_GET['batch']??0));
$batchSize=10;
$start=$batch*$batchSize;
$cityRows=array_slice($cities,$start,$batchSize);

$queries=[
 'Photography'=>[
  'site:instagram.com "wedding photographer" "%s" "91"',
  'site:instagram.com "candid wedding photographer" "%s" "91"',
  'site:instagram.com "wedding photography" "%s" "91"',
  'site:instagram.com "pre wedding photographer" "%s" "91"',
  'site:instagram.com "destination wedding photographer" "%s" "91"',
  'site:instagram.com "bridal photographer" "%s" "91"'
 ],
 'Makeup Artist'=>[
  'site:instagram.com "makeup artist" "%s" "91"',
  'site:instagram.com "bridal makeup artist" "%s" "91"',
  'site:instagram.com "MUA" "%s" "91"',
  'site:instagram.com "bridal MUA" "%s" "91"',
  'site:instagram.com "makeup studio" "%s" "91"',
  'site:instagram.com "wedding makeup artist" "%s" "91"'
 ]
];

function serp($key,$q,$city,$start=0){
 $u='https://serpapi.com/search.json?'.http_build_query([
  'engine'=>'google','q'=>$q,'location'=>$city,'google_domain'=>'google.co.in',
  'gl'=>'in','hl'=>'en','start'=>$start,'num'=>10,'api_key'=>$key
 ]);
 $ctx=stream_context_create(['http'=>['method'=>'GET','timeout'=>20,'ignore_errors'=>true,'header'=>"Accept: application/json\r\n"]]);
 $raw=@file_get_contents($u,false,$ctx); $code=0;
 foreach(($http_response_header??[]) as $h) if(preg_match('/^HTTP\/\S+\s+(\d+)/',$h,$m)) $code=(int)$m[1];
 return [$code,$raw];
}
function phones($text){
 preg_match_all('/(?:\+91[\s-]?)?[6-9]\d{9}\b/',$text,$m); $out=[];
 foreach($m[0] as $p){$d=preg_replace('/\D+/','',$p);if(strlen($d)===12&&str_starts_with($d,'91'))$d=substr($d,2);if(strlen($d)===10&&preg_match('/^[6-9]/',$d))$out[$d]='+91 '.$d;}
 return array_values($out);
}
function assign(PDO $pdo,$cat){
 $s=$pdo->prepare("SELECT u.id FROM users u LEFT JOIN leads l ON l.assigned_to=u.id AND DATE(l.created_at)=CURDATE()
 WHERE u.role='sales' AND u.active=1 AND u.category=? GROUP BY u.id HAVING COUNT(l.id)<50 ORDER BY COUNT(l.id),u.id LIMIT 1");
 $s->execute([$cat]); $id=$s->fetchColumn(); return $id?(int)$id:null;
}
function quotaRows(PDO $pdo,$cat){
 $s=$pdo->prepare("SELECT u.id,u.name,COUNT(l.id) cnt FROM users u LEFT JOIN leads l ON l.assigned_to=u.id AND DATE(l.created_at)=CURDATE()
 WHERE u.role='sales' AND u.active=1 AND u.category=? GROUP BY u.id ORDER BY u.id");
 $s->execute([$cat]); return $s->fetchAll();
}
function remainingCapacity(PDO $pdo,$cat){
 $sum=0; foreach(quotaRows($pdo,$cat) as $r) $sum += max(0,50-(int)$r['cnt']); return $sum;
}
function dailyTarget(PDO $pdo,$cat){ return remainingCapacity($pdo,$cat); }
function dailyCount(PDO $pdo,$cat){
 $s=$pdo->prepare("SELECT COUNT(*) FROM leads WHERE category=? AND DATE(created_at)=CURDATE()");$s->execute([$cat]);return (int)$s->fetchColumn();
}

if(!$isCli && isset($_GET['reset']) && $_GET['reset']==='1'){
 $ids=$pdo->query("SELECT id FROM leads WHERE DATE(created_at)=CURDATE() AND source='Instagram Search'")->fetchAll(PDO::FETCH_COLUMN);
 if($ids){
  $in=implode(',',array_fill(0,count($ids),'?'));
  $pdo->prepare("DELETE FROM activities WHERE lead_id IN ($in)")->execute($ids);
  $pdo->prepare("DELETE FROM leads WHERE id IN ($in)")->execute($ids);
 }
 header('Location: ?batch=0&reset_done=1'); exit;
}
$stats=['cities'=>0,'queries'=>0,'seen'=>0,'added'=>0,'duplicates'=>0,'noPhone'=>0,'errors'=>0];
$messages=[]; $stop=false;

foreach($cityRows as $row){
 [$city,$state,$tier]=$row; $stats['cities']++;
 foreach(['Photography','Makeup Artist'] as $category){
  if(dailyCount($pdo,$category)>=dailyTarget($pdo,$category)) continue;
  foreach($queries[$category] as $template){
   if(dailyCount($pdo,$category)>=dailyTarget($pdo,$category)){ $stop=true; break; }
   $q=sprintf($template,$city);
   [$code,$raw]=serp($key,$q,$city,0); $stats['queries']++;
   if($code>=400||!$raw){$stats['errors']++;$messages[]='HTTP '.$code;continue;}
   $j=json_decode($raw,true);
   if(isset($j['error'])){$stats['errors']++;$messages[]=$j['error'];continue;}
   foreach(($j['organic_results']??[]) as $it){
    if(dailyCount($pdo,$category)>=dailyTarget($pdo,$category)){ $stop=true; break 2; }
    $stats['seen']++;
    $link=trim($it['link']??''); if(!$link||stripos($link,'instagram.com')===false) continue;
    $title=trim($it['title']??''); $snippet=trim($it['snippet']??'');
    $text=$title.' '.$snippet.' '.json_encode($it);
    $ps=phones($text);
    if(!$ps){
      $nameQuery=trim(preg_replace('/\s+\|\s+Instagram.*$/i','',$title));
      if($nameQuery){
       [$dc,$dr]=serp($key,'"'.$nameQuery.'" "'.$city.'" phone',$city,0);$stats['queries']++;
       if($dc<400&&$dr){$dj=json_decode($dr,true);$dtext=$nameQuery.' '.json_encode($dj);$ps=phones($dtext);}
      }
    }
    if(!$ps){$stats['noPhone']++;continue;}
    $phone=$ps[0];
    $x=$pdo->prepare("SELECT id FROM leads WHERE phone=? AND category=? LIMIT 1");$x->execute([$phone,$category]);
    if($x->fetchColumn()){$stats['duplicates']++;continue;}
    $assigned=assign($pdo,$category); if(!$assigned){$stats['errors']++;continue;}
    try{
      $ins=$pdo->prepare("INSERT INTO leads(business_name,category,city,phone,instagram,source,source_url,lead_score,assigned_to,google_query) VALUES(?,?,?,?,?,'Instagram Search',?,?,?,?)");
      $ins->execute([$title?:'Instagram vendor',$category,$city,$phone,$link,$link,70,$assigned,$q]);$stats['added']++;
    }catch(Throwable $e){$stats['errors']++;error_log($e->getMessage());}
   }
  }
 }
}

$next=$start+$batchSize<count($cities)?$batch+1:null;
if($isCli){
 echo json_encode(['batch'=>$batch,'next_batch'=>$next,'stats'=>$stats,'messages'=>array_values(array_unique($messages))],JSON_PRETTY_PRINT)."\n"; exit;
}
?>
<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1">
<style>body{font-family:system-ui;background:#f6f6f7;margin:0}.wrap{max-width:800px;margin:35px auto;padding:20px}.card{background:#fff;border:1px solid #ddd;border-radius:16px;padding:20px}li{margin:7px 0}.btn{display:inline-block;padding:11px 14px;background:#111;color:#fff;border-radius:9px;text-decoration:none}</style>
<div class="wrap"><div class="card"><h2>Instagram daily collector</h2>
<p>Batch <?=$batch+1?> · cities <?=$start+1?>–<?=min($start+$batchSize,count($cities))?> of <?=count($cities)?></p>
<p><a class="btn" href="?batch=0&reset=1" onclick="return confirm('Delete all Instagram Search leads created today and start fresh?')">Reset today's Instagram leads &amp; fetch fresh</a></p>
<ul>
<li>cities processed=<?=$stats['cities']?></li><li>searches=<?=$stats['queries']?></li><li>results seen=<?=$stats['seen']?></li>
<li><b>added=<?=$stats['added']?></b></li><li>duplicates=<?=$stats['duplicates']?></li><li>no mobile → skipped=<?=$stats['noPhone']?></li><li>errors=<?=$stats['errors']?></li>
</ul>
<p><b>Daily quota:</b> Photography <?=dailyCount($pdo,'Photography')?> / <?=dailyCount($pdo,'Photography')+remainingCapacity($pdo,'Photography')?> · Makeup <?=dailyCount($pdo,'Makeup Artist')?> / <?=dailyCount($pdo,'Makeup Artist')+remainingCapacity($pdo,'Makeup Artist')?></p>
<p><b>Employee quota:</b> <?php foreach(['Photography','Makeup Artist'] as $cat): foreach(quotaRows($pdo,$cat) as $qr): ?><?=htmlspecialchars($qr['name'])?> <?=((int)$qr['cnt'])?>/50 &nbsp; <?php endforeach; endforeach;?></p>
<?php if(isset($_GET['reset_done'])):?><p><b>Today's Instagram leads were reset. Fresh collection started.</b></p><?php endif;?>
<?php if($messages):?><p><?=htmlspecialchars(implode(' | ',array_unique($messages)))?></p><?php endif;?>
<?php if($next!==null):?><a class="btn" href="?batch=<?=$next?>">Run next 10 cities</a><?php else:?><p>All city batches processed.</p><?php endif;?>
</div></div>