<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json');

ini_set('upload_max_filesize', '50M');
ini_set('post_max_size', '50M');
ini_set('memory_limit', '128M');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo '{"ok":false,"reason":"method"}'; exit;
    }

    require_once $_SERVER['DOCUMENT_ROOT'].'/api/db.php';

    $auth = $_POST['auth'] ?? '';
    if (!isset($_SESSION['del_token']) || !hash_equals($_SESSION['del_token'], $auth)) {
        http_response_code(403);
        echo '{"ok":false,"reason":"auth"}'; exit;
    }
    if (empty($_FILES['file']))     { echo '{"ok":false,"reason":"nofile"}'; exit; }

    $cat = $_POST['category'] ?? [];
    $tit = $_POST['title'] ?? [];
    $isyes = $_POST['isyes'] ?? [];
    $f   = $_FILES['file'];

    if (count($cat) !== count($f['name'])) {
        echo '{"ok":false,"reason":"count_mismatch"}'; exit;
    }

    foreach ($f['error'] as $e) {
        if ($e !== UPLOAD_ERR_OK) {
            echo '{"ok":false,"reason":"upload_err_'.$e.'"}'; exit;
        }
    }

    $dir = $_SERVER['DOCUMENT_ROOT'].'/m/m/';
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        echo '{"ok":false,"reason":"mkdir"}'; exit;
    }

    $pdo->beginTransaction();
    $max = $pdo->query(
        "SELECT url FROM song_links WHERE url REGEXP '^[a-z]+$'
         ORDER BY LENGTH(url) DESC, url DESC LIMIT 1"
    )->fetchColumn() ?: '';

    function nextTag(string $s): string {
        if ($s === '') return 'a';
        $c = str_split($s);
        $i = count($c) - 1;
        $carry = 1;
        while ($i >= 0 && $carry) {
            $n = ord($c[$i]) - 97 + $carry;
            $carry = $n >= 26 ? 1 : 0;
            $c[$i] = chr(97 + ($n % 26));
            $i--;
        }
        return $carry ? ('a' . implode('', $c)) : implode('', $c);
    }

    for ($i = 0; $i < count($cat); $i++) {
        $tag = $max = nextTag($max);
        $dst = $dir . $tag . '.mp3';
        if (!move_uploaded_file($f['tmp_name'][$i], $dst)) {
            $pdo->rollBack();
            echo '{"ok":false,"reason":"move"}'; exit;
        }

        if (isset($_FILES['imgfile']['error'][$i]) && $_FILES['imgfile']['error'][$i] === UPLOAD_ERR_OK) {
            $itmp  = $_FILES['imgfile']['tmp_name'][$i];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($itmp);
            $allowed = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/gif'  => 'gif',
                'image/webp' => 'webp',
                'video/webm' => 'webm',
                'video/mp4'  => 'mp4',
            ];
            if (isset($allowed[$mime])) {
                $imgDir = $dir . 'img/';
                if (!is_dir($imgDir)) mkdir($imgDir, 0775, true);
                move_uploaded_file($itmp, $imgDir . $tag . '.' . $allowed[$mime]);
            }
        }

        $isYesVal = !empty($isyes[$i]) && $isyes[$i] !== '0' ? 1 : 0;
        $ins = $pdo->prepare(
            'INSERT INTO song_links(category,title,url,location,lyrics,is_yes) VALUES(?,?,?,?,?,?)'
        );
        $ins->execute([$cat[$i], $tit[$i], $tag, '', '', $isYesVal]);
    }

    $pdo->commit();
    echo '{"ok":true}';

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'reason' => 'exception',
        'message' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ]);
    exit;
}
