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
$subcity=trim($_GET['subcity']??'');
$category=$_GET['category']??'Makeup Artist';
$pages=max(1,min(10,(int)($_GET['pages']??5)));

if(!in_array($category,['Photography','Makeup Artist'],true)) exit('Invalid category');

$areas=[
 'Delhi'=>['South Delhi','East Delhi','West Delhi','Central Delhi','North Delhi','Dwarka','Rohini','Saket','Vasant Kunj','Lajpat Nagar','Greater Kailash'],
 'Noida'=>['Sector 18','Sector 27','Sector 41','Sector 50','Sector 62','Sector 75','Sector 93','Greater Noida'],
 'Gurgaon'=>['Golf Course Road','DLF Phase 1','DLF Phase 2','DLF Phase 3','Sohna Road','Sector 14','Sector 29','Sector 49','New Gurgaon'],
 'Mumbai'=>['Andheri','Bandra','Borivali','Powai','Thane','Navi Mumbai','South Mumbai'],
 'Jaipur'=>['Malviya Nagar','Vaishali Nagar','C Scheme','Mansarovar','Jagatpura'],
 'Chandigarh'=>['Sector 17','Sector 22','Sector 35','Sector 43','Mohali','Zirakpur'],
 'Bengaluru'=>['Indiranagar','Koramangala','Whitefield','HSR Layout','Jayanagar','Electronic City'],
 'Hyderabad'=>['Banjara Hills','Jubilee Hills','Gachibowli','Hitech City','Secunderabad'],
 'Pune'=>['Koregaon Park','Baner','Wakad','Hinjewadi','Kharadi','Viman Nagar']
];
$searchLocation=$subcity ? $subcity.', '.$city : $city;
$locationTerms=$subcity ? [$subcity,$city] : [$city];
$terms=$category==='Photography'
 ? ['wedding photographer','candid wedding photographer','wedding photography','freelance wedding photographer','destination wedding photographer','bridal photographer']
 : ['makeup artist','bridal makeup artist','MUA','freelance makeup artist','bridal MUA','wedding makeup artist','on location makeup artist'];
$queries=[];
foreach($terms as $term){ foreach($locationTerms as $loc){
 $queries[]='site:instagram.com "'.$term.'" "'.$loc.'" "91"';
 $queries[]='site:instagram.com "'.$term.'" "'.$loc.'" "+91"';
}}
$queries=array_values(array_unique($queries));
function serp($key,$q,$start){
 $u='https://serpapi.com/search.json?'.http_build_query([
  'engine'=>'google','q'=>$q,'location'=>$searchLocation,
  'google_domain'=>'google.co.in','gl'=>'in','hl'=>'en','start'=>$start,'num'=>10,'api_key'=>$key
 ]);
 return serpRequest($u);
}
function serpRequest($u){
 $lastRaw=false; $lastCode=0;
 for($attempt=1;$attempt<=2;$attempt++){
  $ch=curl_init($u);
  curl_setopt_array($ch,[
   CURLOPT_RETURNTRANSFER=>true,
   CURLOPT_FOLLOWLOCATION=>true,
   CURLOPT_CONNECTTIMEOUT=>8,
   CURLOPT_TIMEOUT=>25,
   CURLOPT_HTTPHEADER=>['Accept: application/json'],
   CURLOPT_USERAGENT=>'SetMyWed LeadDesk/1.0'
  ]);
  $raw=curl_exec($ch);
  $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
  $err=curl_error($ch);
  curl_close($ch);
  if($raw!==false && $code>0) return [$code,$raw];
  $lastRaw=$raw; $lastCode=0;
  if($attempt===1) usleep(500000);
 }
 return [$lastCode,$lastRaw];
}
function phones($text){
 preg_match_all('/(?:\\+91[\\s-]?)?[6-9]\\d{9}\\b/',$text,$m);
 $out=[];
 foreach($m[0] as $p){
  $d=preg_replace('/\\D+/','',$p);
  if(strlen($d)===12 && str_starts_with($d,'91')) $d=substr($d,2);
  if(strlen($d)===10 && preg_match('/^[6-9]/',$d)) $out[$d]='+91 '.$d;
 }
 return array_values($out);
}
function searchText($key,$q,$start=0){
 $u='https://serpapi.com/search.json?'.http_build_query([
  'engine'=>'google','q'=>$q,'location'=>$_GET['city']??'Delhi',
  'google_domain'=>'google.co.in','gl'=>'in','hl'=>'en','start'=>$start,'num'=>10,'api_key'=>$key
 ]);
 return serpRequest($u);
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
   if(!$ps){
    // Search the vendor name/profile title for a public business phone.
    $nameQuery=trim(preg_replace('/\\s+\\|\\s+Instagram.*$/i','',$title));
    if($nameQuery){
      [$dcode,$draw]=searchText($key,'"'.$nameQuery.'" "'.$city.'" phone');
      if($dcode<400 && $draw){
        $dj=json_decode($draw,true);
        $dtext=$nameQuery.' '.($dj['answer_box']['snippet']??'').' '.($dj['knowledge_graph']['description']??'').' '.json_encode($dj['organic_results']??[]);
        $ps=phones($dtext);
      }
    }
   }
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
<style>body{font-family:system-ui;background:#f6f6f7;margin:0}.wrap{max-width:800px;margin:35px auto;padding:20px}.card{background:#fff;border:1px solid #ddd;border-radius:16px;padding:20px}label{display:block;font-weight:700;margin:12px 0 6px}select{width:100%;padding:12px;border:1px solid #ccc;border-radius:9px;background:#fff}.btn{padding:12px 16px;background:#111;color:#fff;border:0;border-radius:9px;margin-top:16px;cursor:pointer}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}@media(max-width:650px){.grid{grid-template-columns:1fr}}</style>
<div class="wrap"><div class="card"><h2>Instagram Lead Collector</h2>
<form method="get"><div class="grid">
<div><label>Service</label><select name="category"><option value="Makeup Artist" <?=$category==='Makeup Artist'?'selected':''?>>Makeup Artist</option><option value="Photography" <?=$category==='Photography'?'selected':''?>>Photography</option></select></div>
<div><label>City</label><select name="city" id="city"><?php foreach(array_keys($areas) as $cname):?><option value="<?=htmlspecialchars($cname)?>" <?=$city===$cname?'selected':''?>><?=htmlspecialchars($cname)?></option><?php endforeach;?></select></div>
<div><label>Sub-city / Area</label><select name="subcity" id="subcity"><option value="">Any / All areas</option><?php foreach(($areas[$city]??[]) as $area):?><option value="<?=htmlspecialchars($area)?>" <?=$subcity===$area?'selected':''?>><?=htmlspecialchars($area)?></option><?php endforeach;?></select></div>
<div><label>Pages per search</label><select name="pages"><?php for($p=1;$p<=10;$p++):?><option value="<?=$p?>" <?=$pages===$p?'selected':''?>><?=$p?> pages</option><?php endfor;?></select></div>
</div><button class="btn" type="submit">🔍 Collect Leads</button></form><hr>
<p><b>Search:</b> <?=htmlspecialchars($searchLocation)?> · <?=htmlspecialchars($category)?> · <b><?=$pages?> pages/query</b></p>
<ul><li>search queries=<?=htmlspecialchars($qcount)?></li><li>pages searched per query=<?=htmlspecialchars($pages)?></li><li>results seen=<?=htmlspecialchars($seen)?></li><li><b>added=<?=htmlspecialchars($added)?></b></li><li>duplicates=<?=htmlspecialchars($duplicates)?></li><li>no mobile → skipped=<?=htmlspecialchars($noPhone)?></li><li>errors=<?=htmlspecialchars($errors)?></li></ul>
<?php if($messages):?><p><?=htmlspecialchars(implode(' | ',array_unique($messages)))?></p><?php endif;?><p>Only leads with a detected Indian mobile number are inserted.</p><p><a href="/">Back to LeadDesk</a></p>
</div></div><script>
const areas=<?=json_encode($areas)?>;document.getElementById('city').addEventListener('change',function(){const s=document.getElementById('subcity'),list=areas[this.value]||[];s.innerHTML='<option value="">Any / All areas</option>'+list.map(x=>'<option value="'+x+'">'+x+'</option>').join('');});
</script>