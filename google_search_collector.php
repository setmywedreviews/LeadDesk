<?php
session_start();
header('Content-Type: text/html; charset=utf-8');

if (!isset($_SESSION['uid']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('<h2>Admin access required</h2>');
}

$c = require __DIR__ . '/config.php';
$db = $c['db'];
$pdo = new PDO(
    "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4",
    $db['user'],
    $db['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$key = getenv('GOOGLE_SEARCH_API_KEY');
$cx  = getenv('GOOGLE_SEARCH_CX');

if (!$key || !$cx) {
    exit('<h2>Google Search is not configured</h2><p>Add GOOGLE_SEARCH_API_KEY and GOOGLE_SEARCH_CX in Railway Variables.</p>');
}

$city = trim($_GET['city'] ?? 'Delhi');
$state = trim($_GET['state'] ?? 'Delhi');
$category = trim($_GET['category'] ?? 'Photography');

if (!in_array($category, ['Photography', 'Makeup Artist'], true)) {
    exit('<h2>Invalid category</h2>');
}

$queries = $category === 'Photography'
    ? [
        'wedding photographer in %s, %s India',
        'candid wedding photographer in %s, %s India',
        'wedding photography studio in %s, %s India',
      ]
    : [
        'bridal makeup artist in %s, %s India',
        'bridal makeup artist studio in %s, %s India',
        'wedding makeup artist in %s, %s India',
      ];

function searchGoogle($key, $cx, $q) {
    $params = http_build_query([
        'key' => $key,
        'cx' => $cx,
        'q' => $q,
        'num' => 10,
        'gl' => 'in',
        'cr' => 'countryIN',
        'safe' => 'active'
    ]);
    $url = 'https://www.googleapis.com/customsearch/v1?' . $params;
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 25,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\n"
        ]
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    $code = 0;
    foreach (($http_response_header ?? []) as $h) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $h, $m)) $code = (int)$m[1];
    }
    return [$code, $raw];
}

function chooseAssignee(PDO $pdo, string $category): ?int {
    $s = $pdo->prepare(
        "SELECT u.id
         FROM users u
         LEFT JOIN leads l
           ON l.assigned_to=u.id AND DATE(l.created_at)=CURDATE()
         WHERE u.role='sales' AND u.active=1 AND u.category=?
         GROUP BY u.id
         ORDER BY COUNT(l.id), u.id
         LIMIT 1"
    );
    $s->execute([$category]);
    $id = $s->fetchColumn();
    return $id ? (int)$id : null;
}

$added = 0;
$duplicates = 0;
$errors = 0;
$queriesRun = 0;
$resultsSeen = 0;

foreach ($queries as $template) {
    $q = sprintf($template, $city, $state);
    [$code, $raw] = searchGoogle($key, $cx, $q);
    $queriesRun++;

    if ($code >= 400 || !$raw) {
        $errors++;
        continue;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $errors++;
        continue;
    }

    foreach (($data['items'] ?? []) as $item) {
        $resultsSeen++;
        $title = trim($item['title'] ?? '');
        $link = trim($item['link'] ?? '');
        if (!$title || !$link) continue;

        $exists = $pdo->prepare("SELECT id FROM leads WHERE source_url=? AND category=? LIMIT 1");
        $exists->execute([$link, $category]);
        if ($exists->fetchColumn()) {
            $duplicates++;
            continue;
        }

        $assigned = chooseAssignee($pdo, $category);
        if (!$assigned) {
            $errors++;
            continue;
        }

        $score = (stripos($link, 'instagram.com') !== false) ? 75 : 65;

        try {
            $ins = $pdo->prepare(
                "INSERT INTO leads
                (business_name, category, city, source, source_url, lead_score, assigned_to, google_query)
                VALUES (?, ?, ?, 'Google Search', ?, ?, ?, ?)"
            );
            $ins->execute([$title, $category, $city, $link, $score, $assigned, $q]);
            $added++;
        } catch (Throwable $e) {
            $errors++;
            error_log('Google Search collector: ' . $e->getMessage());
        }
    }
}

echo '<!doctype html><meta name="viewport" content="width=device-width">';
echo '<h2>Google Search collector test finished</h2>';
echo '<p>city=' . htmlspecialchars($city) . ' · category=' . htmlspecialchars($category) . '</p>';
echo '<ul>';
echo '<li>queries=' . $queriesRun . '</li>';
echo '<li>results seen=' . $resultsSeen . '</li>';
echo '<li>added=' . $added . '</li>';
echo '<li>duplicates=' . $duplicates . '</li>';
echo '<li>errors=' . $errors . '</li>';
echo '</ul>';
echo '<p><a href="/">Back to LeadDesk</a></p>';
?>