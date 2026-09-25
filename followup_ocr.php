<?php
ini_set('session.gc_maxlifetime','2592000');
session_set_cookie_params(['lifetime'=>2592000,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
session_start();
$c=require __DIR__.'/config.php';
try{$pdo=new PDO("mysql:host={$c['db']['host']};port={$c['db']['port']};dbname={$c['db']['name']};charset=utf8mb4",$c['db']['user'],$c['db']['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}catch(Throwable $e){http_response_code(500);die('Database connection failed.');}
if(!isset($_SESSION['uid'])){header('Location:/');exit;}
$me=(int)$_SESSION['uid'];
$api=getenv('OCR_SPACE_API_KEY')?:'';
if(!$api)die('Diary OCR is not configured yet. Admin: add OCR_SPACE_API_KEY in Railway Variables.');
if(!isset($_FILES['diary_photo'])||$_FILES['diary_photo']['error']!==UPLOAD_ERR_OK)die('Please upload a diary photo.');
if($_FILES['diary_photo']['size']>10*1024*1024)die('Photo must be under 10 MB.');
$mime=mime_content_type($_FILES['diary_photo']['tmp_name']);
if(!in_array($mime,['image/jpeg','image/png','image/webp'],true))die('Only JPG, PNG or WEBP photos are supported.');

$ch=curl_init('https://api.ocr.space/parse/image');
$post=[
 'apikey'=>$api,
 'language'=>'eng',
 'isOverlayRequired'=>'false',
 'OCREngine'=>'3',
 'scale'=>'true',
 'isTable'=>'true',
 'detectOrientation'=>'true',
 'file'=>new CURLFile($_FILES['diary_photo']['tmp_name'],$mime,$_FILES['diary_photo']['name'])
];
curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$post,CURLOPT_TIMEOUT=>90]);
$res=curl_exec($ch);$curlError=curl_error($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
if($res===false||$code<200||$code>=300)die('Diary OCR failed (OCR.space HTTP '.$code.'). '.($curlError?:'Please try again.'));
$j=json_decode($res,true);
if(!is_array($j)||!empty($j['IsErroredOnProcessing'])){
 $msg='';
 if(isset($j['ErrorMessage']))$msg=is_array($j['ErrorMessage'])?implode(' ',array_map('strval',$j['ErrorMessage'])):(string)$j['ErrorMessage'];
 die('Diary OCR failed (OCR.space). '.($msg?:'The image could not be read.'));
}
$parts=[];
foreach(($j['ParsedResults']??[]) as $r){if(isset($r['ParsedText']))$parts[]=trim((string)$r['ParsedText']);}
$text=trim(implode("\n",$parts));
if($text==='')die('Could not read the diary. Please upload a clearer, well-lit photo.');

$today=date('Y-m-d');$year=date('Y');
$lines=preg_split('/\R+/',$text);
$entries=[];$current='';
foreach($lines as $line){
 $line=trim(preg_replace('/\s+/',' ',$line));
 if($line==='')continue;
 $looksNew=(bool)preg_match('/(?:^|\s)(?:\d{1,2}[\/-]\d{1,2}(?:[\/-]\d{2,4})?|\d{1,2}(?:st|nd|rd|th)?\s+(?:jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)|(?:jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)\s+\d{1,2})(?:\s|$)/i',$line);
 if($looksNew && $current!==''){$entries[]=$current;$current=$line;}else{$current.=($current?' ':'').$line;}
}
if($current!=='')$entries[]=$current;
if(!$entries)$entries=[$text];

function parseDiaryDate($s,$year){
 $s=trim($s);
 $formats=['d/m/Y','d-m-Y','d/m/y','d-m-y','d M Y','d-M-Y','d F Y','d-F-Y','M d Y','F d Y','d M','d-M','d F','d-F'];
 foreach($formats as $fmt){
  $v=$s;
  if(strpos($fmt,'Y')===false&&strpos($fmt,'y')===false)$v=$s.' '.$year;
  $d=DateTime::createFromFormat($fmt,$v);
  if($d instanceof DateTime)return $d;
 }
 return null;
}
function parseTime($s){
 if(preg_match('/\b(\d{1,2})(?::|\.)?(\d{2})?\s*(am|pm)?\b/i',$s,$m)){
  $h=(int)$m[1];$mi=isset($m[2])&&$m[2]!==''?(int)$m[2]:0;$ap=strtolower($m[3]??'');
  if($ap==='pm'&&$h<12)$h+=12;if($ap==='am'&&$h===12)$h=0;
  if($h>=0&&$h<=23&&$mi<60)return sprintf('%02d:%02d',$h,$mi);
 }
 return '10:00';
}
function cleanPhone($s){
 if(preg_match('/(?:\+?91[\s-]*)?([6-9]\d{9})\b/',$s,$m))return $m[1];
 return '';
}
foreach($entries as $entry){
 $date=null;
 if(preg_match('/\b(\d{1,2}[\/-]\d{1,2}(?:[\/-]\d{2,4})?)\b/',$entry,$m))$date=parseDiaryDate($m[1],$year);
 if(!$date&&preg_match('/\b(\d{1,2}(?:st|nd|rd|th)?\s+(?:jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)(?:\s+\d{2,4})?)\b/i',$entry,$m))$date=parseDiaryDate(preg_replace('/(st|nd|rd|th)/i','',$m[1]),$year);
 if(!$date)$date=new DateTime($today);
 $time=parseTime($entry);
 $phone=cleanPhone($entry);
 $status='Scheduled';
 if(preg_match('/\b(done|complete|completed)\b/i',$entry))$status='Done';
 elseif(preg_match('/\b(interested)\b/i',$entry))$status='Interested';
 elseif(preg_match('/\b(not interested|not\s*int)\b/i',$entry))$status='Not Interested';
 elseif(preg_match('/\b(cancel+ed|cancelled)\b/i',$entry))$status='Cancelled';
 $business=$entry;
 $business=preg_replace('/\b\d{1,2}[\/-]\d{1,2}(?:[\/-]\d{2,4})?\b/','',$business);
 $business=preg_replace('/\b(?:\d{1,2}(?::|\.)\d{2}\s*(?:am|pm)?|\d{1,2}\s*(?:am|pm))\b/i','',$business);
 $business=preg_replace('/(?:\+?91[\s-]*)?[6-9]\d{9}\b/','',$business);
 $business=preg_replace('/\b(done|complete|completed|interested|not interested|not\s*int|cancel+ed|follow[- ]?up|call|called|tomorrow|today)\b/i','',$business);
 $business=trim(preg_replace('/[|,;:-]+/',' ',$business));
 if($business==='')$business='Diary follow-up';
 $remark=trim($entry);
 $entriesOut[]=null;
 $ins=$pdo->prepare('INSERT INTO manual_followups(user_id,business_name,phone,followup_at,status,remark,lead_source,photo,photo_mime) VALUES(?,?,?,?,?,?,?,?,?)');
 $ins->execute([$me,$business,$phone?:null,$date->format('Y-m-d').' '.$time.':00',$status,$remark,'Diary',$bytes??file_get_contents($_FILES['diary_photo']['tmp_name']),$mime]);
 $count=($count??0)+1;
}
header('Location:?view=followups&ocr_added='.(int)$count);exit;
?>