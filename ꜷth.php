<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo'{"ok":false}';exit;}
$p=$_POST['hash']??'';
require_once $_SERVER['DOCUMENT_ROOT'].'/api/db.php';
$r=$pdo->query('SELECT `2` FROM ags WHERE `1`=1 LIMIT 1')->fetchColumn();
$r=preg_replace('/[^a-f0-9]/i','',(string)$r);
if(hash_equals($r,$p)){
 $_SESSION['del_token']=$p;
 echo'{"ok":true}';
}else{http_response_code(403);echo'{"ok":false}';}
