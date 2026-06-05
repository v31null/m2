<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD']!=='POST') {
    http_response_code(405);
    echo '{"ok":false}'; exit;
}
require_once $_SERVER['DOCUMENT_ROOT'].'/api/db.php';
$auth = $_POST['auth'] ?? '';
if (!isset($_SESSION['del_token']) || !hash_equals($_SESSION['del_token'], $auth)) {
    http_response_code(403);
    echo '{"ok":false}'; exit;
}
$id = $_POST['id'] ?? '';
if (!ctype_digit($id)) {
    http_response_code(400);
    echo '{"ok":false}'; exit;
}
$q = $pdo->prepare('DELETE FROM song_links WHERE link_id = :id LIMIT 1');
$q->execute([':id' => (int)$id]);
echo '{"ok":true,"rows":'.$q->rowCount().'}';
