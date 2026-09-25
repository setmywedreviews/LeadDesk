<?php
session_start();
if (!isset($_SESSION['uid']) || ($_SESSION['role'] ?? '') !== 'admin') {
 http_response_code(403); exit('Admin access required');
}
$city=trim($_GET['city']??'Delhi');
$category=$_GET['category']??'Makeup Artist';
if($category==='Photography'){
 $query='site:instagram.com ("wedding photographer" OR "candid wedding photographer" OR "wedding photography") "'.addslashes($city).'" "91"';
}else{
 $query='site:instagram.com ("makeup artist" OR "bridal makeup" OR "bridal makeup artist") "'.addslashes($city).'" "91"';
}
$url='https://www.google.com/search?q='.rawurlencode($query);
?>
<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Instagram Lead Finder</title>
<style>
body{font-family:system-ui;background:#f6f6f7;margin:0}.wrap{max-width:800px;margin:40px auto;padding:20px}.card{background:#fff;border:1px solid #ddd;border-radius:16px;padding:20px}input,select,button{padding:11px;border:1px solid #ddd;border-radius:9px;font-size:15px}input{width:100%;box-sizing:border-box;margin:8px 0}select{width:100%;margin:8px 0}button{background:#111;color:#fff;font-weight:700;cursor:pointer}.query{background:#f4f4f5;padding:12px;border-radius:10px;word-break:break-word;margin:15px 0}
</style>
<div class="wrap"><div class="card">
<h2>Instagram Lead Finder</h2>
<p>Creates a Google search for public Instagram vendor profiles. It does not log into or scrape Instagram.</p>
<form>
<label>City</label><input name="city" value="<?=htmlspecialchars($city)?>">
<label>Category</label><select name="category"><option <?= $category==='Makeup Artist'?'selected':'' ?>>Makeup Artist</option><option <?= $category==='Photography'?'selected':'' ?>>Photography</option></select>
<button>Generate Search</button>
</form>
<h3>Search query</h3><div class="query"><?=htmlspecialchars($query)?></div>
<p><a href="<?=htmlspecialchars($url)?>" target="_blank" rel="noopener"><button type="button">Open Google Search</button></a></p>
</div></div>