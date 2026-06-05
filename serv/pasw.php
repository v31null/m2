<?php
declare(strict_types=1);
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/api/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') die('Invalid request');

$no = $_GET['no'] ?? '';
if (!preg_match('/№\s*(\d+)/u', $no, $m)) die('Invalid');
$mid = (int)$m[1];
$action = $_POST['action'] ?? '';

if ($action === 'verify') {
    $stmt = $pdo->prepare('SELECT pasw FROM m_com WHERE id = ? AND mid = ?');
    $stmt->execute([$_POST['id'], $mid]);
    $hash = $stmt->fetchColumn();
    echo json_encode(['ok' => $hash && password_verify($_POST['pasw'], $hash)]);
    exit;
}

if ($action === 'delete') {
    $stmt = $pdo->prepare('SELECT pasw FROM m_com WHERE id = ? AND mid = ?');
    $stmt->execute([$_POST['id'], $mid]);
    $hash = $stmt->fetchColumn();
    if ($hash && password_verify($_POST['pasw'], $hash)) {
        $del = $pdo->prepare("UPDATE m_com SET username='EMPTY', userpfp='EMPTY', string='EMPTY', raw_string='EMPTY', pasw='EMPTY', mtime=0 WHERE id=?");
        $del->execute([$_POST['id']]);
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false]);
    }
    exit;
}

if ($action === 'edit') {
    $edit_id = (int)$_POST['edit_id'];
    $pasw_input = $_POST['pasw'];
    $raw_str = $_POST['string'] ?? '';

    $stmt = $pdo->prepare('SELECT pasw FROM m_com WHERE id = ? AND mid = ?');
    $stmt->execute([$edit_id, $mid]);
    $hash = $stmt->fetchColumn();

    if ($hash && password_verify($pasw_input, $hash)) {
        $str = htmlspecialchars($raw_str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = preg_split('/\r\n|\r|\n/', $str);
        foreach ($lines as &$l) if (trim($l) !== '') $l = '<p>' . $l . '</p>';
        $str = implode('', $lines);

        $str = preg_replace('/\*\*(.*?)\*\*/s', '<b>$1</b>', $str);
        $str = preg_replace('/(?<!\*)\*(?!\*)(.*?)(?<!\*)\*(?!\*)/s', '<i>$1</i>', $str);
        $str = preg_replace('/__(.*?)__/s', '<u>$1</u>', $str);
        $str = preg_replace('/--(.*?)--/s', '<s>$1</s>', $str);
        $str = preg_replace('/\|\|(.*?)\|\|/s', '<span class="spoiler" onclick="this.classList.toggle(\'rev\')">$1</span>', $str);
        
        $str = preg_replace('/&lt;c&gt;(.*?)&lt;\/c&gt;/si', '<div class="c">$1</div>', $str);
        $str = preg_replace('/&lt;l&gt;(.*?)&lt;\/l&gt;/si', '<div class="l">$1</div>', $str);
        $str = preg_replace('/&lt;r&gt;(.*?)&lt;\/r&gt;/si', '<div class="r">$1</div>', $str);
        $str = preg_replace('/&lt;j&gt;(.*?)&lt;\/j&gt;/si', '<div class="j">$1</div>', $str);
        
        $str = preg_replace_callback('/&lt;img src=&quot;(https?:\/\/[^&]+)&quot; width (\d+) height (\d+) ([clr])&gt;/i', function($m) {
            $align = '';
            if ($m[4] === 'c') $align = 'display:block; margin:0 auto;';
            elseif ($m[4] === 'l') $align = 'float:left; margin-right:10px;';
            elseif ($m[4] === 'r') $align = 'float:right; margin-left:10px;';
            return '<img src="' . $m[1] . '" style="width:' . $m[2] . 'px; height:' . $m[3] . 'px; ' . $align . '">';
        }, $str);

        $update = $pdo->prepare('UPDATE m_com SET string = ?, raw_string = ? WHERE id = ?');
        $update->execute([$str, $raw_str, $edit_id]);
    }
    header("Location: com.php?no=" . urlencode($no));
    exit;
}
$user_ans = (int)($_POST['captcha_ans'] ?? -1);
$correct_ans = (int)($_SESSION['captcha_ans'] ?? -2);
if ($user_ans !== $correct_ans) die('Captcha error');

if (isset($_SESSION['last_com']) && (time() - $_SESSION['last_com']) < 10) die('Rate limit active.');
$_SESSION['last_com'] = time();

$username = trim($_POST['username'] ?? 'Anonymous');
$userpfp = trim($_POST['userpfp'] ?? '');
$mtime = (float)($_POST['mtime'] ?? 0);
$reply_to = empty($_POST['reply_to']) ? null : (int)$_POST['reply_to'];
$pasw_input = $_POST['pasw'] ?? '';
$edit_id = empty($_POST['edit_id']) ? null : (int)$_POST['edit_id'];
$raw_str = $_POST['string'] ?? '';

$str = htmlspecialchars($raw_str, ENT_QUOTES | ENT_HTML5, 'UTF-8');

$lines = preg_split('/\r\n|\r|\n/', $str);
foreach ($lines as &$l) {
    if (trim($l) !== '') $l = '<p>' . $l . '</p>';
}
$str = implode('', $lines);

$str = preg_replace('/\*\*(.*?)\*\*/s', '<b>$1</b>', $str);
$str = preg_replace('/(?<!\*)\*(?!\*)(.*?)(?<!\*)\*(?!\*)/s', '<i>$1</i>', $str);
$str = preg_replace('/__(.*?)__/s', '<u>$1</u>', $str);
$str = preg_replace('/--(.*?)--/s', '<s>$1</s>', $str);
$str = preg_replace('/\|\|(.*?)\|\|/s', '<span class="spoiler" onclick="this.classList.toggle(\'rev\')">$1</span>', $str);

$str = preg_replace('/&lt;c&gt;(.*?)&lt;\/c&gt;/si', '<div class="c">$1</div>', $str);
$str = preg_replace('/&lt;l&gt;(.*?)&lt;\/l&gt;/si', '<div class="l">$1</div>', $str);
$str = preg_replace('/&lt;r&gt;(.*?)&lt;\/r&gt;/si', '<div class="r">$1</div>', $str);
$str = preg_replace('/&lt;j&gt;(.*?)&lt;\/j&gt;/si', '<div class="j">$1</div>', $str);

$str = preg_replace_callback('/&lt;img src=&quot;(https?:\/\/[^&]+)&quot; width (\d+) height (\d+) ([clr])&gt;/i', function($matches) {
    $align = '';
    if ($matches[4] === 'c') $align = 'display:block; margin:0 auto;';
    elseif ($matches[4] === 'l') $align = 'float:left; margin-right:10px;';
    elseif ($matches[4] === 'r') $align = 'float:right; margin-left:10px;';
    
    return '<img src="' . $matches[1] . '" style="width:' . $matches[2] . 'px; height:' . $matches[3] . 'px; ' . $align . '">';
}, $str);

$pasw_hash = password_hash($pasw_input, PASSWORD_DEFAULT);
$insert = $pdo->prepare('INSERT INTO m_com (mid, string, raw_string, mtime, reply_to, username, userpfp, pasw) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
$insert->execute([$mid, $str, $raw_str, $mtime, $reply_to, $username, $userpfp, $pasw_hash]);

header("Location: com.php?no=" . urlencode($no));
exit;