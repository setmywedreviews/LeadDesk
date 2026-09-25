<?php
/**
 * SetMyWed LeadDesk - authorized source collector
 *
 * This worker is designed for sources for which SetMyWed has permission to
 * collect vendor-directory data. Configure one source domain at a time.
 *
 * Railway variables:
 *   SOURCE_SITEMAP_URL   Full sitemap URL supplied/approved by the source.
 *   SOURCE_DOMAIN        Exact hostname allowed for crawling.
 *   SOURCE_NAME          Label stored in LeadDesk.
 *   COLLECTOR_MAX_PAGES  Optional, default 300.
 *
 * The collector reads public HTML/JSON-LD vendor pages and imports:
 * business name, category, city/address, phone, Instagram, website and source.
 */

$config = require __DIR__ . '/config.php';

function envv($name, $fallback = '') {
    $v = getenv($name);
    return ($v !== false && $v !== '') ? $v : $fallback;
}
function clean($v) {
    return trim(preg_replace('/\\s+/', ' ', html_entity_decode((string)$v, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}
function hostOf($url) {
    return strtolower((string)parse_url($url, PHP_URL_HOST));
}
function allowedHost($url, $domain) {
    $h = hostOf($url);
    $d = strtolower(ltrim($domain, '.'));
    return $h === $d || str_ends_with($h, '.' . $d);
}
function fetchPage($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_USERAGENT => 'SetMyWed LeadDesk Authorized Collector/1.0',
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml']
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $status < 200 || $status >= 300) return null;
    return $body;
}
function parseSitemap($xml) {
    $out = [];
    if (!preg_match_all('/<loc>\\s*([^<]+?)\\s*<\\/loc>/i', $xml, $m)) return $out;
    foreach ($m[1] as $u) $out[] = html_entity_decode(trim($u), ENT_QUOTES | ENT_XML1, 'UTF-8');
    return $out;
}
function textOf($node) {
    return clean($node ? $node->textContent : '');
}
function jsonLdObjects($dom) {
    $out = [];
    foreach ($dom->getElementsByTagName('script') as $s) {
        if (strtolower($s->getAttribute('type')) !== 'application/ld+json') continue;
        $v = json_decode(trim($s->textContent), true);
        if (is_array($v)) {
            if (isset($v['@graph']) && is_array($v['@graph'])) $v = $v['@graph'];
            if (array_is_list($v)) foreach ($v as $x) if (is_array($x)) $out[] = $x;
            else $out[] = $v;
        }
    }
    return $out;
}
function findValue($obj, $keys) {
    foreach ($keys as $k) {
        if (isset($obj[$k]) && is_scalar($obj[$k]) && clean($obj[$k]) !== '') return clean($obj[$k]);
    }
    return '';
}
function socialLinks($dom) {
    $ig = '';
    foreach ($dom->getElementsByTagName('a') as $a) {
        $href = trim($a->getAttribute('href'));
        if ($href && preg_match('~instagram\\.com/~i', $href)) {
            $ig = $href;
            break;
        }
    }
    return $ig;
}
function inferCategory($url, $html) {
    $hay = strtolower($url . ' ' . strip_tags($html));
    if (preg_match('/makeup|make-up|mua|bridal artist|beauty artist/', $hay)) return 'Makeup Artist';
    if (preg_match('/photograph|photo studio|photography/', $hay)) return 'Photography';
    return '';
}
function inferCity($obj) {
    if (!empty($obj['address']) && is_array($obj['address'])) {
        foreach (['addressLocality','addressRegion'] as $k) {
            if (!empty($obj['address'][$k])) return clean($obj['address'][$k]);
        }
    }
    return findValue($obj, ['addressLocality','city','location']);
}

$sitemap = envv('SOURCE_SITEMAP_URL');
$domain = envv('SOURCE_DOMAIN');
$source = envv('SOURCE_NAME', 'Authorized source');
$maxPages = max(1, min(1000, (int)envv('COLLECTOR_MAX_PAGES', '300')));

if (!$sitemap || !$domain) {
    fwrite(STDERR, "Missing SOURCE_SITEMAP_URL or SOURCE_DOMAIN\n");
    exit(2);
}
if (!allowedHost($sitemap, $domain)) {
    fwrite(STDERR, "Sitemap host is outside SOURCE_DOMAIN\n");
    exit(3);
}

$db = $config['db'];
$pdo = new PDO(
    "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4",
    $db['user'],
    $db['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$xml = fetchPage($sitemap);
if (!$xml) {
    fwrite(STDERR, "Could not fetch sitemap\n");
    exit(4);
}

$urls = parseSitemap($xml);
$urls = array_values(array_filter($urls, fn($u) => allowedHost($u, $domain)));
$urls = array_slice($urls, 0, $maxPages);

$exists = $pdo->prepare("SELECT id FROM leads WHERE phone=? AND category=? LIMIT 1");
$insert = $pdo->prepare(
    "INSERT INTO leads
    (business_name,category,city,phone,instagram,website,source,source_url,lead_score,assigned_to)
    VALUES (?,?,?,?,?,?,?,?,?,?)"
);

$users = [];
foreach ($pdo->query("SELECT id,name,category FROM users WHERE role='sales' AND active=1 ORDER BY id")->fetchAll() as $u) {
    $users[$u['category']][] = $u;
}
$roundRobin = ['Photography'=>0, 'Makeup Artist'=>0];
$added = 0; $duplicates = 0; $invalid = 0;

libxml_use_internal_errors(true);

foreach ($urls as $url) {
    $html = fetchPage($url);
    if (!$html) continue;

    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    $objects = jsonLdObjects($dom);

    $business = null;
    foreach ($objects as $obj) {
        $type = strtolower(is_array($obj['@type'] ?? null) ? implode(' ', $obj['@type']) : (string)($obj['@type'] ?? ''));
        if (preg_match('/localbusiness|professionalservice|organization/', $type) || isset($obj['telephone'])) {
            $business = $obj;
            break;
        }
    }
    if (!$business) continue;

    $name = findValue($business, ['name']);
    $phone = findValue($business, ['telephone','phone']);
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    $category = inferCategory($url, $html);
    $city = inferCity($business);
    $instagram = socialLinks($dom);

    if (!$name || !$phone || !isset($users[$category])) {
        $invalid++;
        continue;
    }

    $exists->execute([$phone, $category]);
    if ($exists->fetchColumn()) {
        $duplicates++;
        continue;
    }

    $team = $users[$category];
    $idx = $roundRobin[$category] % count($team);
    $assigned = (int)$team[$idx]['id'];
    $roundRobin[$category]++;

    $insert->execute([
        substr($name,0,190),
        $category,
        substr($city,0,120),
        substr($phone,0,40),
        $instagram ? substr($instagram,0,255) : null,
        $url,
        substr($source,0,80),
        substr($url,0,500),
        60,
        $assigned
    ]);
    $added++;
}

echo "Collector finished | pages=" . count($urls) . " | added={$added} | duplicates={$duplicates} | invalid={$invalid}\n";
