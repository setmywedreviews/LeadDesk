<?php
ini_set('session.gc_maxlifetime','2592000');
session_set_cookie_params(['lifetime'=>2592000,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
session_start();
$c=require __DIR__.'/config.php';
try{$pdo=new PDO("mysql:host={$c['db']['host']};port={$c['db']['port']};dbname={$c['db']['name']};charset=utf8mb4",$c['db']['user'],$c['db']['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}catch(Throwable $e){http_response_code(500);die('Database connection failed.');}
if(!isset($_SESSION['uid'])){header('Location:/');exit;}
$me=(int)$_SESSION['uid'];
$api=getenv('GEMINI_API_KEY')?:'';
if(!$api)die('Diary OCR is not configured yet. Admin: add GEMINI_API_KEY in Railway Variables.');
if(!isset($_FILES['diary_photo'])||$_FILES['diary_photo']['error']!==UPLOAD_ERR_OK)die('Please upload a diary photo.');
if($_FILES['diary_photo']['size']>10*1024*1024)die('Photo must be under 10 MB.');
$mime=mime_content_type($_FILES['diary_photo']['tmp_name']);
if(!in_array($mime,['image/jpeg','image/png','image/webp'],true))die('Only JPG, PNG or WEBP photos are supported.');
$bytes=file_get_contents($_FILES['diary_photo']['tmp_name']);

$prompt = <<<'PROMPT'
Read this handwritten sales follow-up diary carefully. Return ONLY valid JSON, no markdown. Extract every follow-up entry you can confidently read. Use this exact schema: {"followups":[{"business_name":"string","phone":"string or empty","followup_at":"YYYY-MM-DD HH:MM","status":"Scheduled|Done|Interested|Not Interested|Cancelled","remark":"string","lead_source":"string"}]}. Today is __TODAY__. If a date is written without a year, use the current year. If a time is missing, use 10:00. If a field is not visible, use an empty string. Do not invent business names or phone numbers. For status use Scheduled unless the diary clearly says otherwise. For lead_source use the source written in the diary, otherwise "Diary".
PROMPT;
$prompt=str_replace('__TODAY__',date('Y-m-d'),$prompt);

$payload=json_encode(
 ['contents'=>[['parts'=>[['text'=>$prompt],['inline_data'=>['mime_type'=>$mime,'data'=>base64_encode($bytes)]]]]],
  'generationConfig'=>['temperature'=>0,'responseMimeType'=>'application/json']],
 JSON_UNESCAPED_SLASHES
);

$models=['gemini-3.5-flash','gemini-2.5-flash'];
$lastCode=0;$lastMsg='Unknown Gemini API error';$res=false;
foreach($models as $model){
  for($attempt=0;$attempt<3;$attempt++){
    $ch=curl_init('https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json','x-goog-api-key: '.$api],CURLOPT_POSTFIELDS=>$payload,CURLOPT_TIMEOUT=>60]);
    $res=curl_exec($ch);$curlError=curl_error($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if($res!==false && $code>=200 && $code<300)break 2;
    $err=json_decode((string)$res,true);$msg=$err['error']['message']??($curlError?:'Unknown Gemini API error');
    $lastCode=$code;$lastMsg=$msg;
    if($code===503||$code===429){sleep([2,5,10][$attempt]);continue;}
    if($code===404)break;
    break;
  }
}
if($res===false||$lastCode<200||$lastCode>=300){
 $safe=preg_replace('/AIza[0-9A-Za-z_-]+/','[redacted]',$lastMsg);
 die('Diary OCR failed (Gemini HTTP '.$lastCode.'). '.$safe);
}

$j=json_decode($res,true);
$txt=$j['candidates'][0]['content']['parts'][0]['text']??'';
$data=json_decode($txt,true);
if(!is_array($data)||!isset($data['followups'])||!is_array($data['followups']))die('Could not read the diary. Please upload a clearer photo.');

$ins=$pdo->prepare('INSERT INTO manual_followups(user_id,business_name,phone,followup_at,status,remark,lead_source,photo,photo_mime) VALUES(?,?,?,?,?,?,?,?,?)');
$count=0;
foreach($data['followups'] as $f){
 $bn=trim((string)($f['business_name']??''));$fa=trim((string)($f['followup_at']??''));if(!$bn||!$fa)continue;
 $dt=DateTime::createFromFormat('Y-m-d H:i',$fa);if(!$dt)continue;
 $st=trim((string)($f['status']??'Scheduled'));$allowed=['Scheduled','Done','Interested','Not Interested','Cancelled'];if(!in_array($st,$allowed,true))$st='Scheduled';
 $ins->execute([$me,$bn,trim((string)($f['phone']??''))?:null,$dt->format('Y-m-d H:i:s'),$st,trim((string)($f['remark']??'')),trim((string)($f['lead_source']??'Diary'))?:'Diary',$bytes,$mime]);$count++;
}
header('Location:?view=followups&ocr_added='.$count);exit;
?>