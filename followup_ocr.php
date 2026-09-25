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
if(!isset($_FILES['diary_photo']))die('Diary upload did not reach the server. Please select the photo again and tap Read Diary.');
if($_FILES['diary_photo']['error']!==UPLOAD_ERR_OK){$ue=(int)$_FILES['diary_photo']['error'];$um=[1=>'Photo is larger than the server upload limit.',2=>'Photo is larger than the allowed upload size.',3=>'Photo upload was incomplete.',4=>'No photo was selected.',6=>'Server temporary upload folder is unavailable.',7=>'Photo could not be written to disk.'];die('Diary upload failed. '.($um[$ue]??('Upload error code '.$ue.'.')));}
if($_FILES['diary_photo']['size']>10*1024*1024)die('Photo must be under 10 MB.');
$mime=mime_content_type($_FILES['diary_photo']['tmp_name']);
if(!in_array($mime,['image/jpeg','image/png','image/webp'],true))die('Only JPG, PNG or WEBP photos are supported.');

$photo=file_get_contents($_FILES['diary_photo']['tmp_name']);
$ch=curl_init('https://api.ocr.space/parse/image');
$post=['apikey'=>$api,'language'=>'eng','isOverlayRequired'=>'false','OCREngine'=>'3','scale'=>'true','isTable'=>'true','detectOrientation'=>'true','file'=>new CURLFile($_FILES['diary_photo']['tmp_name'],$mime,$_FILES['diary_photo']['name'])];
curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$post,CURLOPT_TIMEOUT=>90]);
$res=curl_exec($ch);$curlError=curl_error($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
if($res===false||$code<200||$code>=300)die('Diary OCR failed (OCR.space HTTP '.$code.'). '.($curlError?:'Please try again.'));
$j=json_decode($res,true);
if(!is_array($j)||!empty($j['IsErroredOnProcessing'])){$msg=isset($j['ErrorMessage'])?(is_array($j['ErrorMessage'])?implode(' ',array_map('strval',$j['ErrorMessage'])):(string)$j['ErrorMessage']):'';die('Diary OCR failed (OCR.space). '.($msg?:'The image could not be read.'));}

$parts=[];foreach(($j['ParsedResults']??[]) as $r){if(isset($r['ParsedText']))$parts[]=trim((string)$r['ParsedText']);}
$text=trim(implode("\n",$parts));
if($text==='')die('Could not read the diary. Please upload a clearer, well-lit photo.');

$lines=preg_split('/\R+/',$text);
$rows=[];
foreach($lines as $line){
 $line=trim(preg_replace('/\s+/',' ',$line));
 if($line===''||preg_match('/^date:?$/i',$line))continue;
 // Normal diary format: Business - phone (city)
 if(preg_match('/^(.+?)\s*[-–—]\s*(?:\+?91[\s-]*)?([6-9]\d{9})\s*(?:\(([^)]*)\))?\s*$/u',$line,$m)){
   $rows[]=['business'=>trim($m[1]),'phone'=>trim($m[2]),'city'=>trim($m[3]??'')];continue;
 }
 // More tolerant fallback when OCR changes punctuation.
 if(preg_match('/^(.+?)[\s:-]+(?:\+?91[\s-]*)?([6-9]\d{9})(?:\s*\(([^)]*)\))?/u',$line,$m)){
   $rows[]=['business'=>trim($m[1]),'phone'=>trim($m[2]),'city'=>trim($m[3]??'')];continue;
 }
 // Keep entries without a phone rather than rejecting them.
 if(preg_match('/^(.+?)\s*[-–—]\s*(.+)$/u',$line,$m)){
   $rows[]=['business'=>trim($m[1]),'phone'=>'','city'=>trim($m[2])];continue;
 }
}
if(!$rows)die('Could not identify diary entries. Please upload a clearer photo.');

$category=trim((string)($_SESSION['category']??''));if(!in_array($category,['Photography','Makeup Artist'],true))$category='Diary';
$status='Follow-up';
$count=0;
try{
 $pdo->beginTransaction();
 // Remove only the malformed diary records created by this user's recent OCR attempts.
 $pdo->prepare("DELETE FROM manual_followups WHERE user_id=? AND lead_source='Diary' AND created_at>=DATE_SUB(NOW(),INTERVAL 2 HOUR)")->execute([$me]);
 $find=$pdo->prepare('SELECT id FROM leads WHERE phone=? AND category=? LIMIT 1');
 $findName=$pdo->prepare('SELECT id FROM leads WHERE business_name=? AND city=? AND assigned_to=? LIMIT 1');
 $ins=$pdo->prepare('INSERT INTO leads(business_name,category,city,phone,source,lead_score,status,assigned_to,remark) VALUES(?,?,?,?,?,?,?,?,?)');
 $upd=$pdo->prepare("UPDATE leads SET city=COALESCE(NULLIF(?,''),city),status='Follow-up',assigned_to=?,source='Diary',updated_at=NOW() WHERE id=?");
 foreach($rows as $r){
   $business=trim($r['business']);$phone=preg_replace('/\D+/','',$r['phone']);$city=trim($r['city']);
   if($business==='')continue;
   $existing=null;
   if($phone){$find->execute([$phone,$category]);$existing=$find->fetchColumn();}
   if(!$existing){$findName->execute([$business,$city,$me]);$existing=$findName->fetchColumn();}
   if($existing){$upd->execute([$city,$me,(int)$existing]);}
   else{$ins->execute([$business,$category,$city,$phone?:null,'Diary',50,$status,$me,null]);}
   $count++;
 }
 $pdo->commit();
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();die('Diary import failed. Please try again.');}
header('Location:/?view=followups&ocr_added='.(int)$count);exit;
?>