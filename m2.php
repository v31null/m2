<?php

declare(strict_types=1);
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/api/db.php';




$indexdb = [];
$imgDir = $_SERVER['DOCUMENT_ROOT'] . '/m/m/img';
if (is_dir($imgDir)) {
    $files = scandir($imgDir);
    if ($files !== false) {
        foreach ($files as $f) {
            if ($f === '.' || $f === '..') continue;
            $info = pathinfo($f);
            if (isset($info['extension'])) {
                $indexdb[$info['filename']] = strtolower($info['extension']);
            }
        }
    }
}
$preloadAssets = [];
$pdir = $_SERVER['DOCUMENT_ROOT'] . '/m/img';
if (is_dir($pdir)) {
    foreach (scandir($pdir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (in_array($ext, ['png', 'gif', 'svg', 'mp3', 'jpg', 'jpeg', 'webp', 'wav', 'ogg'], true)) {
            $preloadAssets[] = $f;
        }
    }
}

$data_stmt = $pdo->prepare('SELECT category,link_id,title,lyrics,location,url,is_yes FROM song_links');

$data_stmt->execute();
$results = $data_stmt->fetchAll(PDO::FETCH_ASSOC);
$com_stmt = $pdo->query("SELECT id, mid, userpfp, mtime, reply_to FROM m_com WHERE username != 'EMPTY' ORDER BY mtime ASC, id ASC");
$all_coms = $com_stmt->fetchAll(PDO::FETCH_ASSOC);
$organized_coms = [];
$coms_by_mid = [];
foreach ($all_coms as $c) {
    $coms_by_mid[$c['mid']][] = $c;
}

foreach ($coms_by_mid as $m => $coms) {
    $parents = array_filter($coms, fn($c) => is_null($c['reply_to']));
    $org = [];
    foreach ($parents as $p) {
        $org[] = $p;
        $r1 = array_filter($coms, fn($c) => $c['reply_to'] == $p['id']);
        foreach ($r1 as $r) {
            $org[] = $r;
            $r2 = array_filter($coms, fn($c) => $c['reply_to'] == $r['id']);
            foreach ($r2 as $rr) $org[] = $rr;
        }
    }
    $organized_coms[$m] = $org;
}
function german_to_normal_letters($text)
{
    $text = (string)$text;

    $text = preg_replace_callback('/ss(\d{2})/i', function ($matches) {
        return '___STYLESET___' . $matches[1] . '___';
    }, $text);

    $map = [
        ' )' => ' )',
        '( ' => '( ',
        ' ,' => ' ,',
        ' ;' => ' ;',
        'z ' => 'ʒ ',
        'ç' => 'č',
        'Ç' => 'Č',
        'ş' => 'ș',
        'Ş' => 'Ș',
        'ı' => 'i',
        'İ' => 'I',
        'ğ' => 'g',
        'Ğ' => 'G',
        'ä' => 'aͤ',
        'Ä' => 'Ae',
        'ö' => 'oͤ',
        'Ö' => 'Oe',
        'ü' => 'uͤ',
        'Ü' => 'Ue',
        'ß' => 'ſs',
        'ss' => 'ſs',
        'Tzsch' => 'Č',
        'tzsch' => 'č',
        'Zsch'  => 'Č',
        'zsch'  => 'č',
        'Tsch'  => 'Č',
        'tsch'  => 'č',
        'TSCH'  => 'Č',
        'the ' => 'þͤ ',
        'Sch' => 'Ș',
        'sch' => 'ș',
        'SCH' => 'Ș',
        'Tz' => 'Ț',
        'tz' => 'ț',
        'TZ' => 'Ț',
        'Th' => 'Þ',
        'th' => 'þ',
        'TH' => 'Þ',
        '\'' => '’'
    ];

    if (strpos($text, '<') !== false && strpos($text, '>') !== false) {
        $text = preg_replace_callback('/(<[^>]*>)|([^<]+)/', function ($matches) use ($map) {
            if (!empty($matches[1])) {
                return $matches[1];
            }
            return strtr($matches[2], $map);
        }, $text);
    } else {
        $text = strtr($text, $map);
    }

    $text = preg_replace_callback('/___STYLESET___(\d{2})___/i', function ($matches) {
        return 'ss' . $matches[1];
    }, $text);

    return $text;
}

function convert_st_sp_word_starts($text)
{
    $text = preg_replace('/\bSt/u', 'Șt', $text);
    $text = preg_replace('/\bst/u', 'șt', $text);
    $text = preg_replace('/\bSp/u', 'Șp', $text);
    $text = preg_replace('/\bsp/u', 'șp', $text);
    return $text;
}

function normalize_german($text)
{
    return preg_replace_callback('/(<[^>]*>)|([^<]+)/', function ($matches) {
        if (!empty($matches[1])) {
            return $matches[1];
        }
        $part = $matches[2];
        $part = german_to_normal_letters($part);
        $part = convert_st_sp_word_starts($part);
        return $part;
    }, (string)$text);
}

foreach ($results as &$row) {
    $row['category'] = normalize_german($row['category']);
    $row['title'] = normalize_german($row['title']);
}
unset($row);

$customAlphabet = [
    '0',
    '1',
    '2',
    '3',
    '4',
    '5',
    '6',
    '7',
    '8',
    '9',
    'aа',
    'bб',
    'úvв',
    'uу',
    'ùw',
    'gг',
    'dд',
    'eеэ',
    'żjж',
    'zз',
    'iи',
    'yíй',
    'kк',
    'lл',
    'mм',
    'nн',
    'oо',
    'pп',
    'rр',
    'sс',
    'tтþ',
    'fф',
    'țц',
    'čч',
    'șш',
    'c',
    'q',
    'x',
    'hх',
];

$charRanks = [];
foreach ($customAlphabet as $rank => $chars) {
    if ((string)$chars !== '') {
        foreach (mb_str_split(mb_strtolower((string)$chars)) as $c) {
            $charRanks[$c] = $rank;
        }
    }
}

function customStrCmp($str1, $str2)
{
    global $charRanks;

    $str1 = html_entity_decode(strip_tags((string)$str1), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $str2 = html_entity_decode(strip_tags((string)$str2), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    if (class_exists('Normalizer')) {
        $str1 = Normalizer::normalize($str1, Normalizer::FORM_D);
        $str2 = Normalizer::normalize($str2, Normalizer::FORM_D);
    }

    $str1 = preg_replace('/(.)\x{0308}/u', '$1$1', $str1);
    $str2 = preg_replace('/(.)\x{0308}/u', '$1$1', $str2);

    if (class_exists('Normalizer')) {
        $str1 = Normalizer::normalize($str1, Normalizer::FORM_C);
        $str2 = Normalizer::normalize($str2, Normalizer::FORM_C);
    }

    $str1 = preg_replace_callback('/№\s*(\d+)/u', fn($m) => sprintf('№%05d', (int)$m[1]), $str1);
    $str2 = preg_replace_callback('/№\s*(\d+)/u', fn($m) => sprintf('№%05d', (int)$m[1]), $str2);

    $s1 = mb_strtolower($str1);
    $s2 = mb_strtolower($str2);

    $len1 = mb_strlen($s1);
    $len2 = mb_strlen($s2);
    $minLen = min($len1, $len2);

    for ($i = 0; $i < $minLen; $i++) {
        $c1 = mb_substr($s1, $i, 1);
        $c2 = mb_substr($s2, $i, 1);

        if ($c1 !== $c2) {
            $has1 = isset($charRanks[$c1]);
            $has2 = isset($charRanks[$c2]);

            if ($has1 && $has2) {
                $cmp = $charRanks[$c1] <=> $charRanks[$c2];
                if ($cmp !== 0) return $cmp;
            } elseif ($has1) {
                return -1;
            } elseif ($has2) {
                return 1;
            } else {
                $cmp = $c1 <=> $c2;
                if ($cmp !== 0) return $cmp;
            }
        }
    }

    return $len1 <=> $len2;
}

usort($results, function ($a, $b) {
    $catCmp = customStrCmp($a['category'], $b['category']);
    if ($catCmp !== 0) return $catCmp;

    return customStrCmp($a['title'], $b['title']);
});

$cat_stmt = $pdo->query('SELECT DISTINCT category FROM song_links');
$cats = $cat_stmt->fetchAll(PDO::FETCH_COLUMN);
$cats = array_map('normalize_german', $cats);
$cats = array_unique($cats);
usort($cats, 'customStrCmp');
$max = 3;
$choices = [];
for ($i = 0; $i <= $max; $i++) {
    if ($i !== 1) {
        $choices[] = $i;
    }
}
$n = $choices[array_rand($choices)];
$chosenLoader = 'waiting' . ($n ?: '');
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Nullpunkts</title>
    <link rel="shortcut icon" href="/img/logomonochrome.ico" type="image/x-icon">
    <link rel="preload" href="img/<?= htmlspecialchars($chosenLoader, ENT_QUOTES, 'UTF-8') ?>.mp3" as="audio" type="audio/mpeg">
    <link rel="preload" href="img/<?= htmlspecialchars($chosenLoader, ENT_QUOTES, 'UTF-8') ?>.gif" as="image" type="image/gif">
    <link rel="preload" href="/m/img/BabelStoneHan.ttf" as="font" type="font/ttf" crossorigin>
    <style>
        @font-face {
            font-family: junicode;
            src: url(/css/fonts/JunicodeVF-Roman.woff2);
        }

        @font-face {
            font-family: junicode;
            src: url(/css/fonts/JunicodeVF-Italic.woff2);
            font-style: italic;
        }

        @font-face {
            font-family: 'BabelStone Han';
            src: url(/m/img/BabelStoneHan.ttf);
        }

        @font-face {
            font-family: nullpunktsenergie;
            src: url(/css/fonts/nullpunktsenergiefont-Regular.ttf);
        }

        @counter-style ca {
            system: alphabetic;
            prefix: " ( ";
            symbols: "a" "b" "ú" "u" "ù" "g" "d" "e" "ż" "z" "i" "y" "k" "l" "m" "n" "o" "p" "r" "s" "t" "f" "ț" "č" "c" "q" "x" "h";
            suffix: " ) ";
            fallback: lower-alpha;
        }

        *:not(.katex):not(.katex *) {
            font-family: 'Junicode', 'nullpunktsenergiefont', 'BabelStone Han', 'Amiri';
            letter-spacing: 0;
            box-sizing: border-box;
            text-rendering: auto;
            image-rendering: optimizeQuality !important;
            font-variant-ligatures: discretionary-ligatures contextual common-ligatures;
            font-feature-settings: "ss17", "cv01", "cv02", "cv22" 2, "cv48", "cv57" 9, "cv33" 4;
            font-variant-numeric: lining-nums;
        }

        :root {
            --text-primary: #111111;
        }

        body {
            background: black;
            color: white;
            margin: 0;
            padding-right: 130px;
            padding-left: 36px;
            box-sizing: border-box;
            overflow-x: hidden
        }

        .tBar {
            display: flex;
            border-bottom: 1px solid white;
            padding: 5px;
            font-size: 1.2em;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 999;
            background: black
        }

        #srch {
            background: transparent;
            border: none;
            border-bottom: 1px dotted white;
            color: white;
            flex: 1;
            margin-left: 15px;
            outline: none;
            min-height: 1.4em;
            font: inherit;
            cursor: text
        }

        #srch:empty::before {
            content: 'search...';
            color: rgba(255, 255, 255, .4);
            pointer-events: none
        }


        .cardWrap,
        .card,
        .cMain,
        .cImg,
        .cSide,
        .cName,
        .cLyr,
        .catLabel,
        #songList,
        .uBox,
        .tBar,
        #rBar,
        #rBarWrapper,
        #lBar,
        #seekWrap,
        #seekFill,
        #seekDot,
        #volWrap,
        .tJump {
            width: auto;
            margin: 0
        }

        .uBox {
            border: 1px solid white;
            width: 90%;
            margin: 10px auto;
            padding: 10px;
            display: none
        }

        .uBox.open {
            display: block
        }

        .catLabel {
            grid-column: 1/-1;
            width: 100%;
            text-align: center;
            padding: 6px 10px;
            cursor: pointer;
            font-style: italic;
            font-size: 1.1em;
            border: 1px solid white;
            user-select: none;
            box-sizing: border-box
        }

        #songList {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            padding: 10px
        }

        .cardWrap {
            display: flex;
            flex-direction: row;
            cursor: pointer
        }

        .cSide {
            cursor: auto;
            user-select: none;
            width: 25px;
            min-width: 25px;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            align-items: center;
            font-size: 1.2em;
            border: 1px solid white;
            border-left: none;
            gap: 6px;
            padding-top: 4px
        }

        .cSide * {
            cursor: pointer;
            color: white;
            text-decoration: none
        }

        .card {

            border-top-left-radius: 10px;

            border-bottom-left-radius: 10px;
            border: 1px solid white;
            display: flex;
            flex-direction: column;
            background: black;
            position: relative;
            flex: 1;
            min-width: 0;
            overflow-wrap: break-word;
            word-break: break-word;
            hyphens: auto
        }

        .cMain {
            display: flex;
            border-bottom: 1px solid white;
            min-height: 180px;
            flex: 1
        }

        .cImg {
            flex: 1;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
            cursor: pointer
        }

        .cImg img,
        .cImg video {
            left: 0;
            top: 0;
            border-top-left-radius: 10px;
            position: absolute;
            width: 100%;
            height: 100%;
            object-fit: cover;
            z-index: 1
        }

        .cImg span {
            z-index: 0;
            font-size: 1.5em
        }

        .cName {
            border-bottom: 1px solid white;
            padding: 5px;
            text-align: center;
            font-style: italic
        }

        .cLyr {
            color: #a7a7a7;
            padding: 5px;
            text-align: center;
            height: 160px;
            overflow-y: auto;
            overflow-x: visible;
            font-size: 1em;
            scrollbar-width: none;
            font-style: italic;
            position: relative;
        }

        .playHead {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 0px;
            background: white;
            mix-blend-mode: difference;
            pointer-events: none;
            z-index: 5;
        }

        .cLyr.hasPlayHead {
            cursor: pointer;
        }

        .pfp-stack {
            position: absolute;
            left: 2px;
            top: 50%;
            transform: translateY(-50%) translateX(-21px);
            display: flex;
            z-index: 10;
        }

        .pfp-stack img {
            width: 1lh;
            height: 1lh;
            border-radius: 50%;
            object-fit: cover;
            cursor: pointer;
            margin-left: -0.6em;
            border: 1px solid black;
        }

        .pfp-stack img:first-child {
            margin-left: 0;
        }

        .pfp-stack img:hover {
            z-index: 20;
        }

        .cLyr::-webkit-scrollbar {
            display: none
        }

        .lrcLine {
            display: block;
            padding: 2px 0;
            transition: color .25s, font-size .25s;
            white-space: normal;
            position: relative;
        }

        .lrcLine.lrcActive {
            color: white;
            position: relative;
            z-index: 0;
        }

        .lrcLine.lrcActive::before,
        .lrcLine.lrcActive::after {
            content: attr(data-txt);
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, #ff0000, #ffff00, #00ff00, #00ffff, #0000ff, #ff00ff, #ff0000);
            background-size: 200% 100%;
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
            animation: lrcGlow 1.5s linear infinite;
            z-index: -1;
            pointer-events: none
        }

        .lrcLine.lrcActive::before {
            filter: blur(8px) brightness(1.2)
        }

        .lrcLine.lrcActive::after {
            filter: blur(15px);
            opacity: .8;
            z-index: -2
        }

        @keyframes lrcGlow {
            0% {
                background-position: 0% 50%
            }

            100% {
                background-position: 200% 50%
            }
        }

        #rBarWrapper {
            position: fixed;
            right: 0;
            top: 0;
            bottom: 0;
            display: flex;
            flex-direction: row;
            z-index: 1000
        }

        #lBar {
            left: 0;
            top: 0;
            bottom: 0;
            width: 32px;
            border-left: 1px solid white;
            background: black;
            display: flex;
            flex-direction: column;
            align-items: center;
            z-index: 1000;
            padding-top: 10px
        }

        #rBar {
            width: 68px;
            font-size: 1.8em;
            border-left: 1px solid white;
            background: black;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 2px;
            padding-top: 10px;
        }


        #rBar .mRow {
            display: flex;
            align-items: flex-start;
            justify-content: center;
            gap: 6px;
        }

        #rBar .mCol {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1px;
        }


        #rBar .mLbl {
            font-size: 11pt;
            line-height: 1;
            text-align: center;
            color: white;
        }

        #rBar #lBtn {
            padding: 0 2px;
            font-size: 1em;
        }

        #rBar button,
        #rBar a {
            background: none;
            border: none;
            color: white;
            cursor: pointer;
            font-family: inherit;
            font-size: 1.2em;
            text-decoration: none;
            display: block;
            text-align: center
        }


        #seekTime,
        #speedTime,
        #revTime {
            font-size: .6em;
            color: white;
            margin: 2px 0;
            pointer-events: none;
            text-align: center
        }

        #seekWrap {
            height: 45vh;
            flex-shrink: 0;
            width: 3px;
            background: rgba(255, 255, 255, .2);
            margin: 4px 0;
            position: relative;
            cursor: pointer;
        }

        #speedWrap,
        #revWrap {
            height: 80px;
            flex-shrink: 0;
            width: 3px;
            background: rgba(255, 255, 255, .2);
            margin: 4px 0;
            position: relative;
            cursor: pointer;
        }

        #speedWrapWrap,
        #revWrapWrap {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin: 4px 0;
            width: 100%;
        }

        #navWrap {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin: 4px 0;
            width: 100%;
        }

        #upNavWrap,
        #unNavWrap {
            width: 100%;
            padding: 0;
            margin: 0;
        }

        #upNavWrap {
            display: flex;
        }

        #upNavWrap>div,
        #unNavWrap>div {
            display: flex;
            justify-content: space-around
        }

        #upNavWrap button,
        #unNavWrap button {
            flex: 1;
            margin: 2px;
            padding: 2px 0;
        }

        #seekWrap::before,
        #speedWrap::before,
        #revWrap::before {
            content: '';
            position: absolute;
            top: 0;
            left: 50%;
            transform: translateX(-50%);
            width: var(--seek-hit-w, 30px);
            height: 100%;
            z-index: 1
        }

        #seekFill,
        #speedFill,
        #revFill {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 0%;
            background: white
        }

        #seekDot,
        #speedDot,
        #revDot {
            position: absolute;
            width: 11px;
            height: 11px;
            background: white;
            border-radius: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            top: 0%;
            cursor: pointer;
            z-index: 2
        }


        #volWrap {
            position: relative;
            width: 36px;
            height: 80px;
            margin: 4px 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-end
        }

        #volSlider {
            writing-mode: vertical-lr;
            direction: rtl;
            width: 28px;
            height: 70px;
            -webkit-appearance: none;
            appearance: none;
            background: transparent;
            outline: none;
            margin: 0;
            cursor: pointer
        }

        #volSlider::-webkit-slider-runnable-track {
            width: 2px;
            background: white;
            border-radius: 1px
        }

        #volSlider::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 10px;
            height: 10px;
            background: white;
            border-radius: 50%;
            margin-left: -4px
        }

        #volSlider::-moz-range-track {
            width: 2px;
            background: white
        }

        #volSlider::-moz-range-thumb {
            width: 10px;
            height: 10px;
            background: white;
            border-radius: 50%;
            border: none
        }


        .tJump {
            flex: 1;
            padding: 6px 0 10px;
            font-size: .75em;
            line-height: 1;
            text-align: center;
            overflow-y: auto;
            scrollbar-width: none;
            width: 100%
        }

        .tJump::-webkit-scrollbar {
            display: none
        }

        .tJumpCat {
            color: white;
            text-decoration: none;
            display: block;
            padding: 3px 2px;
            white-space: pre-line;
            line-height: 1.1;
            cursor: pointer;
        }

        .tJumpCat:hover,
        .tJumpActive {
            position: relative;
            z-index: 10;
            scale: 1.5;
            padding: 10px 0;
            font-weight: bold;
            text-shadow: 0 0 8px rgba(255, 255, 255, 0.8);
            transition: all 0.3s ease;
            color: white;
            margin: 8px 0
        }

        .tJumpCat:hover {
            padding: 0;
        }

        .tJumpCat:hover::before,
        .tJumpCat:hover::after,
        .tJumpActive::before,
        .tJumpActive::after {
            content: '';
            position: absolute;
            left: 0;
            top: 25%;
            width: 100%;
            height: 50%;
            background: linear-gradient(90deg, #ff0000, #ffff00, #00ff00, #00ffff, #0000ff, #ff00ff, #ff0000);
            background-size: 200% 100%;
            animation: lrcGlow 1.5s linear infinite;
            z-index: -1;
            pointer-events: none;
            border-radius: 20px
        }

        .tJumpCat:hover::before,
        .tJumpActive::before {
            filter: blur(8px) brightness(1.2)
        }

        .tJumpCat:hover::after,
        .tJumpActive::after {
            filter: blur(15px);
            opacity: .8;
            z-index: -2
        }

        .tJumpSub {
            display: none;
            flex-direction: column;
            align-items: stretch;
            gap: 2px;
            margin-bottom: 15px;
            padding: 0;
            width: 100%;
        }

        .tJumpCat.tJumpActive+.tJumpSub {
            display: flex;
        }

        .tJumpSub a {
            color: rgba(255, 255, 255, 0.5);
            text-decoration: none;
            padding: 4px 0;
            font-size: 0.85em;
            transition: all 0.2s;
            text-align: center;
            display: block;
        }

        .tJumpSub a:hover {
            color: white;
            scale: 1.3;
            font-weight: bold;
        }


        .playing {

            border: 1px solid yellow !important;
        }


        @keyframes lFlash {

            0%,
            100% {
                color: #ff0000;
            }

            50% {
                color: #ffff00;
            }
        }

        #lBtn.lFlashing {
            animation: lFlash .16s steps(1) infinite;
        }

        #lBtn.lArmed {
            color: #00ff00 !important;
        }



        #rBar {
            --knob-h: 27px;
            --swh: calc(var(--knob-h) * 2.3);
            --msw: calc(var(--swh) * 64 / 165);
        }

        .mKnob {
            height: var(--knob-h);
            aspect-ratio: 102 / 115;
            background: url(img/knob.png) center / contain no-repeat;
            transform-origin: 49.5% 55.2%;
            transform: rotate(0deg);
            cursor: grab;
            touch-action: none;
            margin: 4px auto;
        }

        .mKnob.dragging {
            cursor: grabbing;
        }

        .mKnob2 {
            width: var(--knob-h);
            height: var(--knob-h);
            background: url(img/rotate.png) center / contain no-repeat;
            transform-origin: 50% 50%;
            transform: rotate(0deg);
            cursor: grab;
            touch-action: none;
            margin: 4px auto;
        }

        .mKnob2.dragging {
            cursor: grabbing;
        }

        .mKnobsWrap {
            height: calc(var(--knob-h) * 255 / 115);
            aspect-ratio: 226 / 255;
            background: url(img/knobswrap.png) center / contain no-repeat;
            position: relative;
            margin: 4px auto;
        }

        .mKnobsWrap .mKdial {
            position: absolute;
            left: 50%;
            top: 50%;
            height: var(--knob-h);
            aspect-ratio: 102 / 115;
            background: url(img/knob.png) center / contain no-repeat;
            transform-origin: 49.5% 55.2%;
            transform: translate(-49.5%, -55.2%) rotate(0deg);
            cursor: pointer;
            touch-action: none;
        }


        .mSwGroup {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 3px;
            margin: 4px 0;
        }


        .mSwSlot {
            position: relative;
            width: var(--swh);
            height: var(--msw);
        }

        .mSwSlot .mSw {
            position: absolute;
            left: 50%;
            top: 50%;
            margin: 0;
            transform: translate(-50%, -50%) rotate(-90deg);
        }

        .mSw {
            position: relative;
            width: var(--msw);
            height: var(--swh);
            overflow: hidden;
        }

        .mSwimg {
            position: absolute;
            left: 50%;
            top: 50%;
            width: var(--msw);
            transform: translate(-49.2%, -49.1%);
            cursor: pointer;
            touch-action: none;
            user-select: none;
        }

        .mBtn {
            height: var(--knob-h);
            width: auto;
            cursor: pointer;
            user-select: none;
            touch-action: none;
            -webkit-user-drag: none;
            margin: 4px auto;
            display: block;
        }

        .card {
            scroll-margin-top: 56px;
        }


        .rowgrp {
            display: flex;
            gap: .5rem;
            margin-bottom: .5rem
        }

        .rowgrp input {
            flex: 1 1 auto
        }

        .copyBtn,
        .delBtn {
            display: inline-block;
            width: 1em;
            height: 1em;
            text-align: center;
            line-height: 1em;
            vertical-align: middle;
            cursor: pointer
        }

        audio::-webkit-media-controls-panel,
        video::-webkit-media-controls-panel {
            background-color: black;

        }

        audio::-webkit-media-controls-play-button {
            display: none !important;
            pointer-events: none !important;
            opacity: 0 !important;
        }

        audio::-webkit-media-controls-start-playback-button {
            display: none !important;
            pointer-events: none !important;
            opacity: 0 !important;
        }



        input,
        button,
        textarea,
        select {
            padding: .5em
        }

        .loadBox {
            display: none;
            width: 22px;
            height: 22px;
            border: 1px solid #808080;
            border-radius: 50%;
            font-size: 7px;
            color: #808080;
            justify-content: center;
            align-items: center;
            text-align: center;
            font-family: monospace;
            margin-top: 4px;
            user-select: none;
        }

        .loading-active .loadBox {
            display: flex;
        }

        #comFrame {
            display: none;
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 50vh;
            z-index: 99;
            border: none;
            background: transparent;
        }

        #comFrame.open {
            display: block;
        }

        .cLeft {
            width: 0px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            transform: translateX(1px);
            user-select: none;
            z-index: 99;
        }

        .cLeft img {
            width: 1lh;
            height: 1lh;
            border-radius: 50%;
            object-fit: cover;
            cursor: pointer;
            transition: all 0.2s;
        }

        .cLeft img:hover {
            transform: scale(1.5);
            z-index: 10;
        }

        #loaderInfoScroll p {
            display: none;
        }

        #loaderInfoScroll.waiting p.waiting,
        #loaderInfoScroll.waiting2 p.waiting2,
        #loaderInfoScroll.waiting3 p.waiting3 {
            display: block;
        }

        .mob-copy-btn,
        .mob-com-btn {
            display: none !important;
        }


        @media (max-width: 700px) {
            body {
                padding-right: 100px;
                padding-left: 0;
            }


            .tBar {
                padding: 4px 8px;
            }


            #songList {
                display: flex;
                flex-direction: column;
                gap: 0;
                padding: 0;
            }


            .catLabel {
                width: 100%;
                font-size: 0.85em;
                font-style: italic;
                padding: 3px 10px;
                text-align: left;
                border: none;
                border-top: 1px solid rgba(255, 255, 255, 0.25);
                border-bottom: 1px solid rgba(255, 255, 255, 0.25);
                background: rgba(255, 255, 255, 0.05);
                letter-spacing: 0.05em;
                box-sizing: border-box;
            }


            .cardWrap {
                flex-direction: row;
                width: 100%;
                border-bottom: 1px solid rgba(255, 255, 255, 0.12);
                position: relative;
                min-height: 64px;
                overflow: hidden;
            }


            .cLeft {
                display: none;
            }


            .card {
                border: none !important;
                border-radius: 0 !important;
                flex: 1;
                display: flex;
                flex-direction: column;
                min-height: 64px;
                position: relative;
                overflow: hidden;
            }


            .cLyr {
                display: none;
            }


            .cSide {
                display: none;
            }


            .cMain {
                flex: 1;
                display: flex;
                flex-direction: row;
                min-height: 64px;
                border-bottom: none;
                position: relative;
            }


            .cImg {
                width: 30%;
                min-width: 30%;
                max-width: 30%;
                flex: none;
                position: relative;
                overflow: hidden;
                display: block;
            }

            .cImg img,
            .cImg video {
                left: 0;
                top: 0;
                border-radius: 0 !important;
                position: absolute;
                width: 100%;
                height: 100%;
                object-fit: cover;
                z-index: 1;
            }


            .cImg::after {
                content: '';
                position: absolute;
                top: 0;
                right: 0;
                width: 60%;
                height: 100%;
                background: linear-gradient(to right, transparent 0%, black 100%);
                z-index: 2;
                pointer-events: none;
                transition: all 0.35s ease;
            }


            .cName {
                position: absolute;
                left: 30%;
                right: 0;
                top: 0;
                bottom: 50%;
                padding: 5px 8px 2px;
                border-bottom: none;
                font-style: italic;
                font-size: 0.88em;
                overflow: hidden;
                white-space: nowrap;
                text-overflow: ellipsis;
                display: block;
                line-height: 1.3;
                transition: all 0.3s ease;
            }


            .cCard-cat {
                position: absolute;
                left: 30%;
                right: 0;
                top: 50%;
                bottom: 0;
                padding: 2px 8px 5px;
                font-size: 0.72em;
                color: rgba(255, 255, 255, 0.45);
                overflow: hidden;
                white-space: nowrap;
                text-overflow: ellipsis;
                display: block;
                line-height: 1.3;
                transition: opacity 0.2s ease;
            }


            .mob-pfp-stack {
                position: absolute;
                right: 5px;
                bottom: 5px;
                display: flex;
                flex-direction: row;
                z-index: 20;
                transition: opacity 0.2s ease;
            }

            .mob-pfp-stack img {
                width: 1.3em;
                height: 1.3em;
                border-radius: 50%;
                object-fit: cover;
                cursor: pointer;
                margin-left: -0.5em;
                border: 1px solid black;
            }

            .mob-pfp-stack img:first-child {
                margin-left: 0;
            }




            .cardWrap.mob-open .card {
                flex-direction: column;
                overflow: visible;
            }


            .cardWrap.mob-open .cMain {
                flex-direction: column;
                min-height: 200px;
                height: 200px;
                flex: none;
            }


            .cardWrap.mob-open .cImg {
                width: 100%;
                min-width: 100%;
                max-width: 100%;
                height: 200px;
                min-height: 200px;
            }


            .cardWrap.mob-open .cImg::after {
                top: auto;
                bottom: 0;
                right: 0;
                width: 100%;
                height: 50%;
                background: linear-gradient(to bottom, transparent 0%, black 100%);
            }


            .cardWrap.mob-open .cName,
            .cardWrap.mob-open .cCard-cat,
            .cardWrap.mob-open .mob-pfp-stack {
                display: none;
            }


            .mob-drawer {
                overflow: hidden;
                max-height: 0;
                transition: max-height 0.42s cubic-bezier(0.4, 0, 0.2, 1);
            }

            .cardWrap.mob-open .mob-drawer {
                max-height: 600px;
            }


            .mob-drawer-name {
                text-align: center;
                font-style: italic;
                font-size: 0.95em;
                padding: 8px 12px 4px;
                border-bottom: 1px solid rgba(255, 255, 255, 0.15);
                overflow: hidden;
                white-space: nowrap;
                text-overflow: ellipsis;
            }


            .mob-drawer .cLyr {
                display: block;
                height: 160px;
                overflow-y: auto;
            }


            #seekWrap {
                height: 15vh;
            }

            .cardWrap.mob-open .mob-copy-btn,
            .cardWrap.mob-open .mob-com-btn {
                display: flex !important;
            }

            .mob-copy-btn,
            .mob-com-btn {
                position: absolute;
                z-index: 100;
                width: 28px;
                height: 28px;
                background: rgba(255, 255, 255, 0.9);
                color: var(--text-primary, #111111);
                border: 1px solid rgba(0, 0, 0, 0.1);
                border-radius: 50%;
                cursor: pointer;
                align-items: center;
                justify-content: center;
                transition: all 0.2s ease;
                text-decoration: none;
            }

            .mob-copy-btn {
                top: 10px;
                left: 10px;
            }

            .mob-com-btn {
                top: 10px;
                right: 10px;
            }

            .mob-copy-btn:hover,
            .mob-com-btn:hover {
                transform: scale(1.1);
                background: rgba(255, 255, 255, 1);
            }

            .mob-copy-btn:active,
            .mob-com-btn:active {
                transform: scale(0.95);
            }
        }
    </style>
</head>

<body>
    <div id="loaderOverlay" style="position:fixed;top:0;left:0;width:100%;height:100%;background:black;z-index:99999;display:flex;flex-direction:column;align-items:center;justify-content:center;font-family:'BabelStone Han','Junicode',sans-serif;color:white;transition:opacity 0.5s ease;">
        <img src="img/<?= htmlspecialchars($chosenLoader, ENT_QUOTES, 'UTF-8') ?>.gif" alt="Loading" style="width:30vw;margin-bottom:20px;">
        <div style="font-size:2em;margin-bottom:10px;text-align:center;">仕対始為使中我々，𬐘有你々。</div>
        <div id="loaderPct" style="font-size:1.2em;color:#a7a7a7;">0%</div>
        <div id="loaderInfo" style="height:220px;width:80%;max-width:400px;margin-top:20px;overflow:hidden;opacity:0;transition:opacity 1s ease;font-size:1em;line-height:1.5;text-align:center;">
            <div id="loaderInfoScroll" class="<?= htmlspecialchars($chosenLoader, ENT_QUOTES, 'UTF-8') ?>" style="transition:transform 0.1s linear;">
                <p class="waiting" style="margin: 5px 0; color: #ffffff; opacity: 0; transition: opacity 4s ease;">知中乎済你々？其今繋鳴現歌之名其vivaldi , antonio之𡶘有「始春候」協奏曲其確ーvivaldi，此作之入様提琴音々対手鳥々之啼鳴其対写作命確。</p>
                <p class="waiting" style="margin: 5px 0; color: #ffffff; opacity: 0; transition: opacity 4s ease;">其今聞的你々作之曲家其vivaldi，antonio根対於或聖職済！緋髪々其以依対彼向「il prete roßo」，風斯「緋聖職」，言常々済唁喘息病人其有的其爲磔聖堂於仕作事地其向全時其対歌教員態其向䣏曲家態其向奉命済。</p>

                <p class="waiting2" style="margin: 5px 0; color: #ffffff; opacity: 0; transition: opacity 4s ease;">知中乎済你々？delibes，leo之„ flowerduet „曲其根対於１８８３年其作産其lakme歌劇其従或連吟ー唯二女之川岸其於花集法其対解現安有或場面有法其向𤆩…</p>
                <p class="waiting2" style="margin: 5px 0; color: #ffffff; opacity: 0; transition: opacity 4s ease;">年々来広告々以映画々向程毎所於鳴被的其爲界之最的現歌劇分々其以或其状其向継済。古以BRITISH　AIRWAYS広告々其於也用被事於済！</p>

                <p class="waiting3" style="margin: 5px 0; color: #ffffff; opacity: 0; transition: opacity 4s ease;">知中乎済你々？mozart之eine kleine nachtmusikー小或夜歌其ー作其根対於唯或„ 楽態 „有状曲獲命済。此作対或演奏会爲鄦：ー友辺其於鳴被将軽愉有或室歌其有状書命済，𠾖；</p>
                <p class="waiting3" style="margin: 5px 0; color: #ffffff; opacity: 0; transition: opacity 4s ease;">手紙々其対記的其己人性単覧其向此分其唯« 或小夜歌其 »言状有程般或状於記作命済ー風斯此日界之最民山有古典作々其以或其有将其対推作不命済。</p>
            </div>
        </div>
        <audio id="loaderAudio" src="img/<?= htmlspecialchars($chosenLoader, ENT_QUOTES, 'UTF-8') ?>.mp3" loop autoplay style="display:none;"></audio>
    </div>
    <div class="tBar">
        <a id="npTitle" style="color:inherit;text-decoration:none;cursor:pointer">―</a>
        <div id="srch" contenteditable="true"></div>
    </div>
    <div class="uBox">
        <div id="progressZone"></div>
        <div id="entryZone"></div>
    </div>
    <div id="songList">
        <?php
        $current = null;
        $uniqueCats = [];
        foreach ($results as $r) if (($c = trim((string)($r['category'] ?? ''))) !== '') $uniqueCats[$c] = true;
        $uniqueCats = array_keys($uniqueCats);
        $catRefs = [];
        foreach ($uniqueCats as $idx => $cat) {
            $depth = 1;
            $found = false;
            while (!$found && $depth <= mb_strlen($cat, 'UTF-8')) {
                $pre = mb_strtolower(mb_substr($cat, 0, $depth, 'UTF-8'), 'UTF-8');
                $unique = true;
                foreach ($uniqueCats as $j => $other) {
                    if ($idx === $j) continue;
                    if (mb_strtolower(mb_substr($other, 0, $depth, 'UTF-8'), 'UTF-8') === $pre) {
                        $unique = false;
                        break;
                    }
                }
                if ($unique) $found = true;
                else $depth++;
            }
            $catRefs[$cat] = mb_substr($cat, 0, $depth, 'UTF-8');
        }

        foreach ($results as $row) {
            $rowCat = trim((string)$row['category']);
            if ($row['category'] !== $current) {
                if ($rowCat !== '' && isset($catRefs[$rowCat])) {
                    $cRef = $catRefs[$rowCat];
                    echo '<div class="catLabel" id="' . htmlspecialchars($cRef, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($rowCat, ENT_QUOTES, 'UTF-8') . '</div>';
                }
                $current = $row['category'];
            }
            $url = (string)$row['url'];
            $idRaw = (string)$row['link_id'] . '_' . substr($url, -5);
            $id = htmlspecialchars(preg_replace('/[^\x20-\x7E]/', '', $idRaw), ENT_QUOTES, 'UTF-8');
            $src = '/m/m/' . rawurlencode($url) . '.mp3';
            $imgBase = 'm/img/' . rawurlencode($url);
            $lyrRaw = (string)($row['lyrics'] ?? '');
            $isSRT = (bool)preg_match('/\d{2}:\d{2}:\d{2}[,.]\d+\s*-->\s*\d{2}:\d{2}:\d{2}[,.]\d+/m', $lyrRaw);
            $isLRC = !$isSRT && (bool)preg_match('/^\[\d{2}:\d{2}\.\d+\]/m', $lyrRaw);
            $hasTimed = $isSRT || $isLRC;

            $mid = $row['link_id'];
            $song_coms = $organized_coms[$mid] ?? [];

            $cLeftHTML = '<div class="cLeft">';
            $limit = array_slice($song_coms, 0, 15);
            foreach ($limit as $c) {
                $pfp = empty($c['userpfp']) ? '/m/serv/npfp.png' : $c['userpfp'];
                $cLeftHTML .= '<img data-src="' . htmlspecialchars($pfp, ENT_QUOTES) . '" onclick="openComFromPfp(event, ' . $c['id'] . ', this)">';
            }
            $cLeftHTML .= '</div>';
        ?><div class="cardWrap" data-category="<?= htmlspecialchars($rowCat, ENT_QUOTES, 'UTF-8') ?>">
                <?= $cLeftHTML ?>
                <div class="card" id="<?= $id ?>" data-title="<?= htmlspecialchars((string)$row['title'], ENT_QUOTES, 'UTF-8') ?>">
                    <button class="mob-copy-btn" onclick="event.stopPropagation();copyLinkId('<?= $id ?>')">§</button>
                    <a href="serv/com.php?no=Союз Советских Социалистических Республик , Министерство Иностранных Дел СССР , Комитет Государственной Безопасности СССР , Шестнадцатое Главное Управление (Перехват и анализ международных коммуникаций) — Дипломатический Канал № <?= htmlspecialchars((string)$row['link_id'], ENT_QUOTES, 'UTF-8') ?> под совместным наблюдением. Þͤ GOVERNMENT OF UNITED STATES OF AMERICA , DEPARTMENT OF STATE , BUREAU OF INTELLIGENCE AND RESEARCH , NATIONAL SECURITY AGENCY — SIGNALS INTELLIGENCE DIRECTORATE" class="mob-com-btn" onclick="openCom(event, this)">C</a>
                    <div class="cMain">
                        <div class="cImg">
                            <?php
                            $ext = $indexdb[$url] ?? '';
                            if ($ext !== ''):
                                $fullPath = '/m/m/img/' . rawurlencode($url) . '.' . $ext;
                                if (in_array($ext, ['mp4', 'webm'])):
                                    $isSync = !empty($row['is_yes']);
                            ?>
                                    <video <?= $isSync ? 'loop muted playsinline data-sync="true"' : 'autoplay loop muted playsinline' ?> style="position:absolute;left:0;top:0;width:100%;height:100%;object-fit:cover;z-index:1" data-src="<?= $fullPath ?>"></video>
                                <?php else: ?>
                                    <img data-src="<?= $fullPath ?>" style="position:absolute;left:0;top:0;width:100%;height:100%;object-fit:cover;z-index:1">
                            <?php
                                endif;
                            endif;
                            ?>
                        </div>
                    </div>
                    <div class="cName"><?= (string)$row['title'] ?></div>
                    <div class="cLyr"><?php
                                        $lyrRaw = (string)($row['lyrics'] ?? '');
                                        $lyrStyle = '';
                                        if (preg_match('/style=(["\'])(.*?)\1/', (string)$row['title'], $mStyle)) {
                                            $styleContent = $mStyle[2];
                                            if (strpos($styleContent, 'font-variant-ligatures') !== false || strpos($styleContent, 'font-feature-settings') !== false) {
                                                $lyrStyle = $styleContent;
                                            }
                                        }

                                        $srtToSec = function (string $ts): float {
                                            preg_match('/(\d+):(\d+):(\d+)[,.](\d+)/', $ts, $m);
                                            return (int)$m[1] * 3600 + (int)$m[2] * 60 + (int)$m[3] + (float)('0.' . $m[4]);
                                        };
                                        $lrcLines = [];
                                        $isSRT = (bool)preg_match('/\d{2}:\d{2}:\d{2}[,.]\d+\s*-->\s*\d{2}:\d{2}:\d{2}[,.]\d+/m', $lyrRaw);
                                        $isLRC = !$isSRT && (bool)preg_match('/^\[\d{2}:\d{2}\.\d+\]/m', $lyrRaw);

                                        if ($isLRC) {
                                            foreach (explode("\n", $lyrRaw) as $lrcLine) {
                                                $lrcLine = trim($lrcLine);
                                                $stamps = [];
                                                $text = preg_replace_callback(
                                                    '/\[(\d{2}):(\d{2}\.\d+)\]/',
                                                    function ($m) use (&$stamps) {
                                                        $stamps[] = (int)$m[1] * 60 + (float)$m[2];
                                                        return '';
                                                    },
                                                    $lrcLine
                                                );
                                                $text = trim($text);
                                                foreach ($stamps as $t) {
                                                    $lrcLines[] = [$t, $text];
                                                }
                                            }
                                        } elseif ($isSRT) {
                                            $blocks = preg_split('/\r?\n\s*\r?\n/', trim($lyrRaw));
                                            foreach ($blocks as $block) {
                                                $lines = preg_split('/\r?\n/', trim($block));
                                                if (count($lines) < 2) continue;
                                                $tsLine = (preg_match('/-->/', $lines[0])) ? $lines[0] : (isset($lines[1]) ? $lines[1] : '');
                                                if (!preg_match('/(\d{2}:\d{2}:\d{2}[,.]\d+)\s*-->\s*(\d{2}:\d{2}:\d{2}[,.]\d+)/', $tsLine, $m)) continue;
                                                $startT = $srtToSec($m[1]);
                                                $endT = $srtToSec($m[2]);
                                                $textStart = (preg_match('/-->/', $lines[0])) ? 1 : 2;
                                                $text = implode(' ', array_slice($lines, $textStart));
                                                $text = strip_tags($text);
                                                $lrcLines[] = [$startT, trim($text), $endT];
                                            }
                                        }

                                        if ($lrcLines) {
                                            usort($lrcLines, fn($a, $b) => $a[0] <=> $b[0]);

                                            foreach ($lrcLines as $idx => $line) {
                                                $t = $line[0];
                                                $txt = $line[1];
                                                $te = $line[2] ?? null;

                                                if ($txt === '' && !$isLRC) continue;

                                                $norm = german_to_normal_letters($txt);
                                                $safe = htmlspecialchars($norm, ENT_QUOTES, 'UTF-8');
                                                $attr = sprintf('data-t="%.3f"', $t);
                                                if ($te !== null) $attr .= sprintf(' data-te="%.3f"', $te);

                                                $styleAttr = !empty($lyrStyle) ? sprintf(' style="%s"', htmlspecialchars($lyrStyle, ENT_QUOTES, 'UTF-8')) : '';

                                                printf('<span class="lrcLine"%s %s data-txt="%s">%s</span>', $styleAttr, $attr, $safe, $safe);
                                            }
                                        } else {
                                            if (!empty($lyrStyle)) {
                                                printf('<span style="%s">', htmlspecialchars($lyrStyle, ENT_QUOTES, 'UTF-8'));
                                            }
                                            echo nl2br(htmlspecialchars(german_to_normal_letters($lyrRaw), ENT_QUOTES, 'UTF-8'));
                                            if (!empty($lyrStyle)) {
                                                echo '</span>';
                                            }
                                        }
                                        if (!$lrcLines) echo '<div class="playHead"></div>';
                                        ?></div><audio crossorigin="anonymous" data-src="<?= htmlspecialchars($src, ENT_QUOTES, 'UTF-8') ?>" preload="none"></audio>
                </div>
                <div class="cSide">
                    <span onclick="event.stopPropagation();copyLinkId('<?= $id ?>')">§</span>
                    <span class="delBtn" data-anchor="<?= $id ?>" style="display:none">T</span>
                    <a href="editm.php?id=<?= htmlspecialchars((string)$row['link_id'], ENT_QUOTES, 'UTF-8') ?>" class="editBtn" onclick="event.stopPropagation()" style="display:none">E</a>

                    <a href="serv/com.php?no=Союз Советских Социалистических Республик , Министерство Иностранных Дел СССР , Комитет Государственной Безопасности СССР , Шестнадцатое Главное Управление (Перехват и анализ международных коммуникаций) — Дипломатический Канал № <?= htmlspecialchars((string)$row['link_id'], ENT_QUOTES, 'UTF-8') ?> под совместным наблюдением. Þͤ GOVERNMENT OF UNITED STATES OF AMERICA , DEPARTMENT OF STATE , BUREAU OF INTELLIGENCE AND RESEARCH , NATIONAL SECURITY AGENCY — SIGNALS INTELLIGENCE DIRECTORATE" id="comBtn" onclick="openCom(event, this)">C</a>
                    <div class="loadBox">00:00</div>
                </div>
            </div>
        <?php
        } ?>
    </div>
    <div id="rBarWrapper">

        <div id="lBar">
            <div class="tJump" id="tJump">
                <?php
                $catLetters = [];
                foreach ($results as $row) {
                    $cat = trim((string)$row['category']);
                    $title = trim((string)$row['title']);
                    if ($cat === '' || $title === '') continue;
                    $plainTitle = trim(html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    if ($plainTitle === '') continue;
                    $rawFirstLower = mb_strtolower(mb_substr($plainTitle, 0, 1, 'UTF-8'), 'UTF-8');
                    $firstChar = mb_strtoupper($rawFirstLower, 'UTF-8');
                    if (isset($charRanks[$rawFirstLower])) {
                        $rank = $charRanks[$rawFirstLower];
                        $canonicalLower = mb_substr($customAlphabet[$rank], 0, 1, 'UTF-8');
                        $firstChar = mb_strtoupper($canonicalLower, 'UTF-8');
                    }
                    if (!isset($catLetters[$cat][$firstChar])) {
                        $url = (string)$row['url'];
                        $idRaw = (string)$row['link_id'] . '_' . substr($url, -5);
                        $id = htmlspecialchars(preg_replace('/[^\x20-\x7E]/', '', $idRaw), ENT_QUOTES, 'UTF-8');
                        $catLetters[$cat][$firstChar] = $id;
                    }
                }

                foreach ($catRefs as $cat => $pre) {
                    echo '<div class="tJumpCat" data-href="#' . htmlspecialchars($pre, ENT_QUOTES, 'UTF-8') . '" onclick="location.hash=\'#' . htmlspecialchars($pre, ENT_QUOTES, 'UTF-8') . '\'">' . htmlspecialchars($pre, ENT_QUOTES, 'UTF-8') . '</div>';
                    if (isset($catLetters[$cat])) {
                        echo '<div class="tJumpSub">';
                        foreach ($catLetters[$cat] as $letter => $firstId) {
                            echo '<a href="#' . $firstId . '">' . htmlspecialchars((string)$letter, ENT_QUOTES, 'UTF-8') . '</a>';
                        }
                        echo '</div>';
                    }
                }
                ?>
            </div>
        </div>
        <div id="rBar">
            <img id="mPl" class="mBtn" src="img/ploff.png" data-asset="ploff.png" draggable="false" alt="play">
            <div id="seekTime">0:00</div>
            <div id="seekWrap">
                <div id="seekFill"></div>
                <div id="seekDot"></div>
            </div>
            <div class="mSwGroup">
                <div class="mSwSlot">
                    <div class="mSw"><img id="mNav" class="mSwimg" src="img/sc.png" data-asset="sc.png" draggable="false" alt="prev/next"></div>
                </div>
                <div class="mLbl">M</div>
                <div class="mSwSlot">
                    <div class="mSw"><img id="mK" class="mSwimg" src="img/sd.png" data-asset="sd.png" draggable="false" alt="K"></div>
                </div>
                <div class="mLbl">K</div>
            </div>
            <div class="mKnobsWrap" data-bg="knobswrap.png"><span id="mISR" class="mKdial" data-bg="knob.png"></span></div>
            <div class="mRow">
                <div class="mCol"><span id="mRev" class="mKnob" data-bg="knob.png" title="reverb"></span>
                    <div class="mLbl">R</div>
                </div>
                <div class="mCol"><span id="mSpd" class="mKnob2" data-bg="rotate.png" title="speed"></span>
                    <div class="mLbl">S</div>
                </div>
            </div>
            <button id="lBtn" style="color: yellow">L</button>
            <img id="mR2" class="mBtn" src="img/r2off.png" data-asset="r2off.png" draggable="false" alt="reset speed">
            <span id="mVol" class="mKnob" data-bg="knob.png" title="volume"></span>
            <div class="mLbl">V</div>
        </div>
    </div>
    <div class="coloredText" style="text-align:center"><a href="/index.html">aber er hat aufgehört</a></div><br>
    <div class="coloredText" style="width:100%;text-align:center">und wir haben die Konsequenzen noch nicht gesehen。</div>
    <button id="spawn" class="ctlBtn" style="position:fixed;left:10px;bottom:10px;z-index:9999;display:none">J</button>
    <datalist id="catList">
        <?php foreach ($cats as $c) {
            $decoded = html_entity_decode($c, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            echo '<option value="' . htmlspecialchars($decoded, ENT_QUOTES, 'UTF-8') . '">';
        } ?>
    </datalist>
    <script>
        function openComFromPfp(e, cid, el) {
            e.stopPropagation();
            e.preventDefault();
            const wrap = el.closest('.cardWrap');
            const btn = wrap.querySelector('#comBtn');
            if (btn) {
                const frame = document.getElementById('comFrame');
                const targetHash = '#com-body-' + cid;

                if (!frame.classList.contains('open') || !frame.src.startsWith(btn.href)) {
                    frame.src = btn.href + targetHash;
                    frame.classList.add('open');
                } else {
                    frame.contentWindow.location.replace(btn.href + targetHash);
                }
            }
        }
        let loadInterval = null;

        let audioCtx = null,
            convolver = null,
            dryGain = null,
            wetGain = null,
            wetInputGain = null,
            convolverConnected = false;
        let currentRevPct = 0.0;
        let lMode = true;
        const sourceMap = new WeakMap();




        const UI_SOUND_FILES = {
            chime: '/m/img/chime.mp3',
            chimetwice: '/m/img/chimetwice.mp3',
            arm: '/m/img/arm.mp3',
            noava: '/m/img/noava.mp3',
            click: '/m/img/click.mp3',
            firealarm: '/m/img/fire-alarm.mp3',
            singelpress: '/m/img/singelpress.mp3'
        };
        const uiAudio = {};
        Object.keys(UI_SOUND_FILES).forEach(name => {
            fetch(UI_SOUND_FILES[name])
                .then(r => r.blob())
                .then(b => {
                    const a = new Audio(URL.createObjectURL(b));
                    a.preload = 'auto';
                    uiAudio[name] = a;
                })
                .catch(() => {});
        });

        const ASSET = {};
        const aimg = f => ASSET[f] || ('img/' + f);
        const ASSET_FILES = <?= json_encode($preloadAssets, JSON_UNESCAPED_SLASHES) ?>;
        const ASSET_READY = Promise.all(ASSET_FILES.map(f =>
            fetch('img/' + f).then(r => r.blob()).then(b => {
                ASSET[f] = URL.createObjectURL(b);
            }).catch(() => {})
        ));

        function applyAssetBlobs() {
            document.querySelectorAll('[data-bg]').forEach(el => {
                el.style.backgroundImage = 'url(' + aimg(el.dataset.bg) + ')';
            });
            document.querySelectorAll('img[data-asset]').forEach(el => {
                el.src = aimg(el.dataset.asset);
            });
        }
        ASSET_READY.then(() => { applyAssetBlobs(); mSyncControls(); });

        function playUi(name) {
            const a = uiAudio[name];
            if (!a) return;
            try {
                a.currentTime = 0;
                a.play().catch(() => {});
            } catch (e) {}
        }

        function startAlarm() {
            const a = uiAudio.firealarm;
            if (!a) return;
            try {
                a.loop = true;
                a.currentTime = 0;
                a.play().catch(() => {});
            } catch (e) {}
        }

        function stopAlarm() {
            const a = uiAudio.firealarm;
            if (!a) return;
            try {
                a.pause();
                a.currentTime = 0;
            } catch (e) {}
        }




        function foldSearch(s) {
            if (!s) return '';
            s = s.toLowerCase()
                .replace(/ͤ/g, 'e')
                .replace(/þ/g, 'th')
                .replace(/tzsch|tsch|zsch/g, 'c')
                .replace(/sch/g, 's')
                .replace(/tz/g, 't')
                .replace(/ß/g, 'ss')
                .replace(/ſ/g, 's')
                .replace(/ʒ/g, 'z')
                .replace(/ı/g, 'i');


            return s.normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[‘’']/g, "'");
        }



        function snapshotSettings() {
            try {
                localStorage.setItem('m2settings', JSON.stringify({
                    vol: PAGE_VOLUME,
                    speed: currentPct,
                    rev: currentRevPct,
                    l: lMode,
                    k: lrcMode,
                    i: iBtn.style.color === 'yellow',
                    s: sBtn.style.color === 'yellow',
                    r: loopBtn.style.color === 'yellow'
                }));
            } catch (e) {}
        }
        window.addEventListener('pagehide', snapshotSettings);
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'hidden') snapshotSettings();
        });


        if ('mediaSession' in navigator) {
            const ms = navigator.mediaSession;
            const h = (name, fn) => {
                try {
                    ms.setActionHandler(name, fn);
                } catch (e) {}
            };
            h('play', () => {
                if (currentAudio) currentAudio.play();
            });
            h('pause', () => {
                if (currentAudio) currentAudio.pause();
            });
            h('previoustrack', () => prevBtn.click());
            h('nexttrack', () => nextBtn.click());
            h('seekbackward', (d) => {
                if (currentAudio) currentAudio.currentTime = Math.max(0, currentAudio.currentTime - (d.seekOffset || 5));
            });
            h('seekforward', (d) => {
                if (currentAudio) currentAudio.currentTime = Math.min(currentAudio.duration || 0, currentAudio.currentTime + (d.seekOffset || 5));
            });
            h('seekto', (d) => {
                if (currentAudio && d.seekTime != null) currentAudio.currentTime = d.seekTime;
            });
        }

        function updateReverbVisuals() {
            const revTime = document.getElementById('revTime');
            const revFill = document.getElementById('revFill');
            const revDot = document.getElementById('revDot');
            if (revDot) revDot.style.top = ((1 - currentRevPct) * 100) + '%';
            if (revFill) {
                revFill.style.top = ((1 - currentRevPct) * 100) + '%';
                revFill.style.height = (currentRevPct * 100) + '%';
            }
            if (revTime) revTime.textContent = Math.round(currentRevPct * 100) + '%';
            renderRevKnob();
            if (wetGain && dryGain && audioCtx && wetInputGain && convolver) {
                if (currentRevPct > 0) {
                    if (!convolverConnected) {
                        try {
                            wetInputGain.connect(convolver);
                            convolver.connect(wetGain);
                            convolverConnected = true;
                        } catch (e) {
                            console.error("Error connecting convolver", e);
                        }
                    }
                    wetGain.gain.setTargetAtTime(currentRevPct * 1.5, audioCtx.currentTime, 0.05);
                    dryGain.gain.setTargetAtTime(1 - (currentRevPct * 0.5), audioCtx.currentTime, 0.05);
                } else {
                    if (convolverConnected) {
                        try {
                            wetInputGain.disconnect(convolver);
                            convolver.disconnect(wetGain);
                            convolverConnected = false;
                        } catch (e) {
                            console.error("Error disconnecting convolver", e);
                        }
                    }
                    wetGain.gain.setTargetAtTime(0, audioCtx.currentTime, 0.05);
                    dryGain.gain.setTargetAtTime(1.0, audioCtx.currentTime, 0.05);
                }
            }
        }

        function initAudioContext() {
            if (audioCtx) return;
            const AudioContext = window.AudioContext || window.webkitAudioContext;
            audioCtx = new AudioContext();
            convolver = audioCtx.createConvolver();
            dryGain = audioCtx.createGain();
            wetGain = audioCtx.createGain();
            wetInputGain = audioCtx.createGain();

            dryGain.connect(audioCtx.destination);
            wetGain.connect(audioCtx.destination);

            const revLength = audioCtx.sampleRate * 3.0;
            const impulse = audioCtx.createBuffer(2, revLength, audioCtx.sampleRate);
            const lChannel = impulse.getChannelData(0);
            const rChannel = impulse.getChannelData(1);
            for (let i = 0; i < revLength; i++) {
                const decay = Math.pow(1 - i / revLength, 3);
                lChannel[i] = (Math.random() * 2 - 1) * decay;
                rChannel[i] = (Math.random() * 2 - 1) * decay;
            }
            convolver.buffer = impulse;

            updateReverbVisuals();
        }

        function loadAudio(a) {
            initAudioContext();
            if (audioCtx.state === 'suspended') audioCtx.resume();
            if (!a.getAttribute('src')) {
                a.setAttribute('src', a.getAttribute('data-src'));
                a.load();
            }
            if (!sourceMap.has(a)) {
                try {
                    const source = audioCtx.createMediaElementSource(a);
                    source.connect(dryGain);
                    source.connect(wetInputGain);
                    sourceMap.set(a, source);
                } catch (e) {
                    console.error('Audio node error', e);
                }
            }
        }

        function startLoadingAnim(card) {
            if (!card) return;
            const box = card.querySelector('.loadBox');
            if (!box) return;
            card.classList.add('loading-active');
            const start = performance.now();

            if (loadInterval) clearInterval(loadInterval);
            loadInterval = setInterval(() => {
                const diff = performance.now() - start;
                const s = Math.floor(diff / 1000).toString().padStart(2, '0');
                const ms = Math.floor(diff % 1000).toString().padStart(3, '0').slice(0, 2);
                box.textContent = `${s}:${ms}`;
            }, 40);
        }

        function stopLoadingAnim(card) {
            if (!card) return;
            card.classList.remove('loading-active');
            if (loadInterval) {
                clearInterval(loadInterval);
                loadInterval = null;
            }
        }

        document.querySelectorAll('audio').forEach(audio => {
            const card = audio.closest('.cardWrap');
            audio.addEventListener('waiting', () => startLoadingAnim(card));
            audio.addEventListener('canplay', () => stopLoadingAnim(card));
            audio.addEventListener('playing', () => stopLoadingAnim(card));
            audio.addEventListener('pause', () => stopLoadingAnim(card));
            audio.addEventListener('error', () => stopLoadingAnim(card));
        });
        let TOKEN = '';
        const zone = document.getElementById('entryZone');
        const progressZone = document.getElementById('progressZone');
        const uBox = document.querySelector('.uBox');
        const SALT = 'asfjaƕꜹacvkasjsajfashfasufghjgs';
        let currentAudio = null;
        let PAGE_VOLUME = 1;
        const np = document.getElementById('npTitle');


        const stub = () => ({
            style: {},
            textContent: '',
            onclick: null,
            click() {
                if (this.onclick) this.onclick();
            }
        });
        const pBtn = stub(),
            loopBtn = stub(),
            kBtn = stub(),
            iBtn = stub(),
            sBtn = stub(),
            nextBtn = stub(),
            prevBtn = stub();
        const seekWrap = document.getElementById('seekWrap');
        const seekFill = document.getElementById('seekFill');
        const seekDot = document.getElementById('seekDot');
        const seekTime = document.getElementById('seekTime');
        let lrcMode = true;
        window.__npK = () => lrcMode;

        let renderSpeedKnob = () => {},
            renderRevKnob = () => {},
            renderVolKnob = () => {},
            mSyncControls = () => {};
        kBtn.style.color = 'white';

        function updateLoopState() {
            if (!currentAudio) return;
            currentAudio.loop = loopBtn.style.color !== 'yellow';
        }

        iBtn.onclick = () => {
            iBtn.style.color = iBtn.style.color === 'yellow' ? 'white' : 'yellow';
            if (iBtn.style.color === 'yellow') {
                sBtn.style.color = 'white';
                loopBtn.style.color = 'yellow';
            } else {
                if (sBtn.style.color !== 'yellow') loopBtn.style.color = 'white';
            }
            updateLoopState();
            playUi(iBtn.style.color === 'yellow' ? 'chime' : 'noava');
        };

        sBtn.onclick = () => {
            sBtn.style.color = sBtn.style.color === 'yellow' ? 'white' : 'yellow';
            if (sBtn.style.color === 'yellow') {
                iBtn.style.color = 'white';
                loopBtn.style.color = 'yellow';
            } else {
                if (iBtn.style.color !== 'yellow') loopBtn.style.color = 'white';
            }
            updateLoopState();
            playUi(sBtn.style.color === 'yellow' ? 'chime' : 'noava');
        };

        kBtn.onclick = () => {
            lrcMode = !lrcMode;
            kBtn.style.color = lrcMode ? 'white' : 'yellow';
        };


        function fmtTime(s) {
            if (!s || isNaN(s)) return '0:00';
            const m = Math.floor(s / 60);
            const ss = Math.floor(s % 60);
            return m + ':' + (ss < 10 ? '0' : '') + ss;
        }

        let lastActiveLine = null;
        let lastActiveAudio = null;



        let collageAudio = null;
        let collageSegEnd = null;


        let lastTickTime = -1;

        function updateSeek() {
            if (currentAudio && currentAudio.duration) {
                if (currentAudio !== lastActiveAudio) {
                    lastActiveAudio = currentAudio;
                    lastActiveLine = null;
                    lastTickTime = -1;
                }
                const prevTick = lastTickTime;
                lastTickTime = currentAudio.currentTime;

                const pct = (currentAudio.currentTime / currentAudio.duration) * 100;
                seekFill.style.height = pct + '%';
                seekDot.style.top = pct + '%';
                seekTime.textContent = fmtTime(currentAudio.currentTime);

                if (lrcMode &&
                    (iBtn.style.color === 'yellow' || sBtn.style.color === 'yellow') &&
                    !currentAudio.paused) {
                    const t = currentAudio.currentTime;
                    const playedThrough = prevTick >= 0 && t >= prevTick && (t - prevTick) < 1.0;
                    if (collageAudio === currentAudio && collageSegEnd !== null && playedThrough) {
                        if (t >= collageSegEnd) {
                            collageAudio = null;
                            collageSegEnd = null;
                            advanceShuffle();
                            requestAnimationFrame(updateSeek);
                            return;
                        }
                    } else {
                        const seg = currentCollageSegment(currentAudio);
                        if (seg) {
                            collageAudio = currentAudio;
                            collageSegEnd = seg[1];
                        } else {
                            collageAudio = null;
                            collageSegEnd = null;
                        }
                    }
                }


                const card = currentAudio.closest('.card');
                if (card) {
                    const v = card.querySelector('video[data-sync="true"]');
                    if (v && v.readyState >= 1) {
                        if (Math.abs(v.currentTime - currentAudio.currentTime) > 0.2) {
                            v.currentTime = currentAudio.currentTime;
                        }
                    }

                    const lyr = card.querySelector('.cLyr');
                    if (lyr) {
                        const lines = [...lyr.querySelectorAll('.lrcLine')];
                        if (lines.length) {
                            const t = currentAudio.currentTime;
                            let active = null;

                            for (let i = 0; i < lines.length; i++) {
                                const l = lines[i];
                                const start = parseFloat(l.dataset.t);
                                const end = l.dataset.te ? parseFloat(l.dataset.te) : null;

                                if (end !== null) {
                                    if (t >= start && t <= end) {
                                        active = l;
                                        break;
                                    }
                                } else {
                                    if (t >= start) active = l;
                                    else break;
                                }
                            }

                            if (active && active.textContent.trim() === '') active = null;

                            if (active !== lastActiveLine) {
                                lastActiveLine = active;
                                lines.forEach(l => l.classList.toggle('lrcActive', l === active));

                                if (active && !currentAudio.paused) {
                                    const lyTop = lyr.getBoundingClientRect().top;
                                    const elTop = active.getBoundingClientRect().top;
                                    const target = lyr.scrollTop + (elTop - lyTop) - (lyr.clientHeight / 2) + (active.clientHeight / 2);
                                    lyr.scrollTo({
                                        top: target,
                                        behavior: 'smooth'
                                    });
                                }
                            }
                        } else {
                            const ph = lyr.querySelector('.playHead');
                            if (ph && currentAudio.duration) {
                                const pct = currentAudio.currentTime / currentAudio.duration;
                                ph.style.height = (pct * lyr.clientHeight) + 'px';
                                ph.style.transform = `translateY(${lyr.scrollTop}px)`;
                            }
                        }
                    }
                }
            }
            requestAnimationFrame(updateSeek);
        }
        updateSeek();

        document.querySelectorAll('.cLyr').forEach(lyr => {
            if (lyr.querySelector('.playHead')) {
                lyr.style.color = 'white';
                lyr.classList.add('hasPlayHead');
                const ph = lyr.querySelector('.playHead');
                lyr.addEventListener('scroll', () => {
                    if (ph) ph.style.transform = `translateY(${lyr.scrollTop}px)`;
                });
                lyr.addEventListener('click', e => {
                    e.stopPropagation();
                    const card = lyr.closest('.card');
                    const cardAudio = card ? card.querySelector('audio') : null;
                    if (!cardAudio) return;
                    const rect = lyr.getBoundingClientRect();
                    const clickY = e.clientY - rect.top;
                    const pct = Math.max(0, Math.min(1, clickY / rect.height));

                    const wasPlaying = (currentAudio === cardAudio && !cardAudio.paused);

                    if (currentAudio !== cardAudio) {
                        document.querySelectorAll('audio').forEach(a => {
                            if (a !== cardAudio) {
                                a.pause();
                                a.loop = false;
                            }
                        });
                        currentAudio = cardAudio;
                        loadAudio(currentAudio);
                        currentAudio.volume = PAGE_VOLUME;
                        currentAudio.playbackRate = currentSpeed;
                    }

                    if (wasPlaying) {
                        const setPct = () => {
                            if (currentAudio.duration) {
                                currentAudio.currentTime = pct * currentAudio.duration;
                            }
                        };

                        if (currentAudio.readyState >= 1) {
                            setPct();
                        } else {
                            currentAudio.addEventListener('loadedmetadata', function onMeta() {
                                setPct();
                                currentAudio.removeEventListener('loadedmetadata', onMeta);
                            });
                        }
                    } else {
                        updateLoopState();
                        currentAudio.play();
                    }
                });
            }
        });


        function seekFromY(clientY) {
            if (!currentAudio || !currentAudio.duration) return;
            const rect = seekWrap.getBoundingClientRect();
            let pct = (clientY - rect.top) / rect.height;
            pct = Math.max(0, Math.min(1, pct));
            currentAudio.currentTime = pct * currentAudio.duration;
        }
        let seekDragging = false;
        seekWrap.addEventListener('mousedown', e => {
            seekDragging = true;
            seekFromY(e.clientY);
        });
        seekDot.addEventListener('mousedown', e => {
            e.stopPropagation();
            seekDragging = true;
        });
        document.addEventListener('mousemove', e => {
            if (seekDragging) seekFromY(e.clientY);
        });
        document.addEventListener('mouseup', () => {
            seekDragging = false;
        });
        seekWrap.addEventListener('touchstart', e => {
            seekDragging = true;
            seekFromY(e.touches[0].clientY);
        }, {
            passive: true
        });
        document.addEventListener('touchmove', e => {
            if (seekDragging) seekFromY(e.touches[0].clientY);
        }, {
            passive: true
        });
        document.addEventListener('touchend', () => {
            seekDragging = false;
        });

        let currentPct = 0.5;
        let currentSpeed = 1.0;

        function updateSpeedVisuals() {
            let y = (0.5 - currentPct) * 100;
            let x = Math.abs(y);
            let delta = 0;
            if (x <= 10) {
                delta = 0.01 * x;
            } else {
                delta = 0.1 + 0.01 * (x - 10) + Math.pow(x - 10, 2) / 900;
            }
            currentSpeed = y >= 0 ? 1 + delta : 1 - delta;
            currentSpeed = Math.max(0.05, currentSpeed);

            renderSpeedKnob();

            if (lMode) {
                currentRevPct = Math.min(1, Math.pow(Math.max(0, 1 - currentSpeed), 2 / 3));
                updateReverbVisuals();
            }
        }

        const propagateSpeed = () => {
            document.querySelectorAll('audio, video').forEach(media => {
                media.playbackRate = currentSpeed;
                media.preservesPitch = false;
                media.mozPreservesPitch = false;
                media.webkitPreservesPitch = false;
            });
        };

        updateSpeedVisuals();

        const lb = document.getElementById('lBtn');
        if (lb) {




            let lHoldTimer = null;
            let lHolding = false;
            let lArmed = false;
            let lSuppressClick = false;

            const lStopAlarm = stopAlarm;
            const lCleanupHold = () => {
                if (lHoldTimer) {
                    clearTimeout(lHoldTimer);
                    lHoldTimer = null;
                }
                lStopAlarm();
                lb.classList.remove('lFlashing', 'lArmed');
                lHolding = false;
                lArmed = false;
            };

            lb.addEventListener('pointerdown', (e) => {
                lSuppressClick = false;
                if (!lMode) return;
                e.preventDefault();
                lHolding = true;
                lArmed = false;
                lb.style.color = '';
                lb.classList.add('lFlashing');
                startAlarm();
                lHoldTimer = setTimeout(() => {
                    lHoldTimer = null;
                    lStopAlarm();
                    lb.classList.remove('lFlashing');
                    lb.classList.add('lArmed');
                    lArmed = true;
                }, 2000);
            });

            const lRelease = (fromPointerUp) => {
                if (!lHolding) return;
                if (fromPointerUp) lSuppressClick = true;
                const completed = lArmed;
                lCleanupHold();
                if (completed) {
                    lMode = false;
                    lb.style.color = 'white';
                } else {
                    lb.style.color = 'yellow';
                }
            };
            lb.addEventListener('pointerup', () => lRelease(true));
            lb.addEventListener('pointerleave', () => lRelease(false));
            lb.addEventListener('pointercancel', () => lRelease(false));

            lb.addEventListener('click', () => {
                if (lSuppressClick) {
                    lSuppressClick = false;
                    return;
                }
                if (lMode) return;
                lMode = true;
                lb.style.color = 'yellow';
                currentRevPct = Math.max(0, (currentPct - 0.5) * 2);
                updateReverbVisuals();
                playUi('chimetwice');
            });
        }


        document.addEventListener('play', (e) => {
            if (e.target.tagName !== 'AUDIO') return;
            e.target.playbackRate = currentSpeed;
            if (currentAudio && currentAudio !== e.target) currentAudio.pause();
            currentAudio = e.target;
            updateLoopState();
            const card = currentAudio.closest('.card');
            document.querySelectorAll('.card').forEach(c => c.classList.remove('playing'));
            document.querySelectorAll('.lrcLine.lrcActive').forEach(l => l.classList.remove('lrcActive'));


            lastActiveLine = null;
            if (card) {
                card.classList.add('playing');
                np.innerHTML = card.dataset.title;
                np.setAttribute('href', '#' + card.id);
                const v = card.querySelector('video[data-sync="true"]');
                if (v) {
                    v.playbackRate = currentSpeed;
                    v.play();
                }
                if ('mediaSession' in navigator) {
                    try {
                        const imgEl = card.querySelector('.cImg img, .cImg video');
                        const art = imgEl ? (imgEl.currentSrc || imgEl.src || imgEl.dataset.src || '') : '';
                        navigator.mediaSession.metadata = new MediaMetadata({
                            title: (card.querySelector('.cName')?.textContent || '').trim(),
                            artist: (card.closest('.cardWrap')?.dataset.category || '').trim(),
                            album: 'Nullpunkts',
                            artwork: art ? [{
                                src: art
                            }] : []
                        });
                        navigator.mediaSession.playbackState = 'playing';
                    } catch (e) {}
                }
            }
            pBtn.textContent = '||';
        }, true);

        document.addEventListener('pause', (e) => {
            if (e.target.tagName !== 'AUDIO') return;
            pBtn.textContent = '▶';
            const v = e.target.closest('.card')?.querySelector('video[data-sync="true"]');
            if (v) v.pause();
            if ('mediaSession' in navigator) navigator.mediaSession.playbackState = 'paused';
        }, true);


        function getCollageSegments(audio) {
            const card = audio.closest('.card');
            if (!card) return null;
            const lines = [...card.querySelectorAll('.lrcLine')];
            if (!lines.length) return null;
            const dur = audio.duration;
            if (!dur || !isFinite(dur)) return null;
            const segs = [];
            for (let i = 0; i < lines.length; i++) {
                const start = parseFloat(lines[i].dataset.t);
                if (isNaN(start)) continue;
                let end = dur;
                const next = lines[i + 1];
                if (next) {
                    const ns = parseFloat(next.dataset.t);
                    if (!isNaN(ns)) end = ns;
                }
                if (end - start > 45) segs.push([start, end]);
            }
            return segs.length ? segs : null;
        }


        function currentCollageSegment(audio) {
            const segs = getCollageSegments(audio);
            if (!segs) return null;
            const t = audio.currentTime;
            for (const seg of segs) {
                if (t >= seg[0] && t < seg[1]) return seg;
            }
            return null;
        }





        function startCollageIfNeeded(audio) {
            collageAudio = null;
            collageSegEnd = null;
            if (!lrcMode) return;
            if (iBtn.style.color !== 'yellow' && sBtn.style.color !== 'yellow') return;
            const apply = () => {
                const segs = getCollageSegments(audio);
                if (!segs) return;
                const idx = crypto.getRandomValues(new Uint32Array(1))[0] % segs.length;
                try {
                    audio.currentTime = segs[idx][0];
                } catch (e) {}
                collageAudio = null;
                collageSegEnd = null;
            };
            if (audio.readyState >= 1 && audio.duration && isFinite(audio.duration)) apply();
            else audio.addEventListener('loadedmetadata', apply, {
                once: true
            });
        }



        function advanceShuffle() {
            const cards = [...document.querySelectorAll('.cardWrap')]
                .filter(c => c.style.display !== 'none');
            let pool = cards;
            if (iBtn.style.color === 'yellow') {
                const currentCat = currentAudio?.closest('.cardWrap')?.dataset.category;
                if (currentCat) pool = cards.filter(c => c.dataset.category === currentCat);
            }
            if (!pool.length) return;
            const randomUint = crypto.getRandomValues(new Uint32Array(1))[0];
            const nextCardWrap = pool[randomUint % pool.length];
            const a = nextCardWrap.querySelector('audio');
            if (!a) return;
            if (currentAudio && currentAudio !== a) {
                currentAudio.pause();
                currentAudio.loop = false;
            }
            loadAudio(a);
            a.volume = PAGE_VOLUME;
            a.playbackRate = currentSpeed;
            a.loop = false;
            pBtn.textContent = '||';
            startCollageIfNeeded(a);
            a.play();
        }

        document.addEventListener('ended', (e) => {
            if (e.target.tagName !== 'AUDIO') return;
            seekFill.style.height = '100%';
            seekDot.style.top = '100%';
            pBtn.textContent = '▶';

            if (loopBtn.style.color === 'yellow' &&
                (iBtn.style.color === 'yellow' || sBtn.style.color === 'yellow')) {
                advanceShuffle();
            }
        }, true);

        pBtn.onclick = () => {
            if (!currentAudio) return;
            currentAudio.paused ? currentAudio.play() : currentAudio.pause();
        };

        loopBtn.onclick = () => {
            const isLooping = loopBtn.style.color === 'yellow';
            loopBtn.style.color = isLooping ? 'white' : 'yellow';
            if (loopBtn.style.color === 'white') {
                iBtn.style.color = 'white';
                sBtn.style.color = 'white';
            }
            updateLoopState();
            playUi(loopBtn.style.color === 'yellow' ? 'arm' : 'click');
        };


        seekWrap.style.setProperty('--seek-hit-w', '45px');

        nextBtn.onclick = () => {
            const cards = [...document.querySelectorAll('.card:not([style*="display: none"])')]
                .filter(c => c.closest('.cardWrap')?.style.display !== 'none');
            const idx = cards.indexOf(currentAudio?.closest('.card'));
            const next = cards[idx + 1] || cards[0];
            if (next) {
                const a = next.querySelector('audio');
                if (a) {
                    loadAudio(a);
                    a.volume = PAGE_VOLUME;
                    a.playbackRate = currentSpeed;
                    pBtn.textContent = '||';
                    a.play();
                }
            }
        };

        prevBtn.onclick = () => {
            const cards = [...document.querySelectorAll('.card')]
                .filter(c => c.closest('.cardWrap')?.style.display !== 'none');
            const idx = cards.indexOf(currentAudio?.closest('.card'));
            const prev = cards[idx - 1] || cards[cards.length - 1];
            if (prev) {
                const a = prev.querySelector('audio');
                if (a) {
                    loadAudio(a);
                    a.volume = PAGE_VOLUME;
                    a.playbackRate = currentSpeed;
                    pBtn.textContent = '||';
                    a.play();
                }
            }
        };





        (function() {
            const $ = id => document.getElementById(id);
            const lBtnEl = $('lBtn');
            const click = () => playUi('click');
            const arm = () => playUi('arm');
            const playSingelPress = () => playUi('singelpress');
            const playChimeTwice = () => playUi('chimetwice');
            const startFire = startAlarm;
            const stopFire = stopAlarm;


            function makeRotary(el, onTwist, onDown, onUp) {
                if (!el) return;
                const MIN_R = 6;
                let drag = false,
                    last = null;
                const geom = e => {
                    const r = el.getBoundingClientRect();
                    const dx = e.clientX - (r.left + r.width / 2),
                        dy = e.clientY - (r.top + r.height / 2);
                    return {
                        a: Math.atan2(dy, dx) * 180 / Math.PI,
                        rad: Math.hypot(dx, dy)
                    };
                };
                el.addEventListener('pointerdown', e => {
                    drag = true;
                    const g = geom(e);
                    last = g.rad < MIN_R ? null : g.a;
                    el.classList.add('dragging');
                    el.setPointerCapture(e.pointerId);
                    onDown && onDown();
                    e.preventDefault();
                });
                el.addEventListener('pointermove', e => {
                    if (!drag) return;
                    const g = geom(e);
                    if (g.rad < MIN_R) {
                        last = null;
                        return;
                    }
                    if (last === null) {
                        last = g.a;
                        return;
                    }
                    let d = g.a - last;
                    if (d > 180) d -= 360;
                    else if (d < -180) d += 360;
                    last = g.a;
                    onTwist(d);
                });
                const end = e => {
                    if (!drag) return;
                    drag = false;
                    last = null;
                    el.classList.remove('dragging');
                    onUp && onUp();
                    try {
                        el.releasePointerCapture(e.pointerId);
                    } catch (_) {}
                };
                el.addEventListener('pointerup', end);
                el.addEventListener('pointercancel', end);
            }


            const mVol = $('mVol');
            const renderVol = () => {
                if (mVol) mVol.style.transform = 'rotate(' + (Math.cbrt(PAGE_VOLUME) * 305) + 'deg)';
            };
            const setVol = lin => {
                lin = Math.max(0, Math.min(1, lin));
                PAGE_VOLUME = Math.pow(lin, 3);
                propagateVolume();
                renderVol();
            };
            makeRotary(mVol, d => setVol(Math.cbrt(PAGE_VOLUME) + d / 305));
            if (mVol) mVol.addEventListener('wheel', e => {
                e.preventDefault();
                e.stopPropagation();
                setVol(Math.cbrt(PAGE_VOLUME) + (e.deltaY < 0 ? 0.04 : -0.04));
            }, {
                passive: false
            });
            renderVol();


            const mRev = $('mRev');
            const renderRev = () => {
                if (mRev) mRev.style.transform = 'rotate(' + (currentRevPct * 305) + 'deg)';
            };
            const setRev = v => {
                currentRevPct = Math.max(0, Math.min(1, v));
                updateReverbVisuals();
            };


            makeRotary(mRev,
                d => {
                    if (!lMode) setRev(currentRevPct + d / 305);
                },
                () => {
                    if (lMode) startFire();
                },
                stopFire);
            if (mRev) mRev.addEventListener('wheel', e => {
                e.preventDefault();
                if (lMode) return;
                setRev(currentRevPct + (e.deltaY < 0 ? 0.05 : -0.05));
            }, {
                passive: false
            });
            renderRev();


            const mSpd = $('mSpd');
            const renderSpd = () => {
                if (mSpd) mSpd.style.transform = 'rotate(' + ((0.5 - currentPct) * 360) + 'deg)';
            };
            const setSpd = p => {
                currentPct = Math.max(0, Math.min(1, p));
                updateSpeedVisuals();
                propagateSpeed();
            };
            makeRotary(mSpd, d => setSpd(currentPct - d / 360));
            if (mSpd) mSpd.addEventListener('wheel', e => {
                e.preventDefault();
                e.stopPropagation();
                setSpd(currentPct + (e.deltaY < 0 ? -0.01 : 0.01));
            }, {
                passive: false
            });
            renderSpd();


            const mR2 = $('mR2');
            if (mR2) {
                mR2.addEventListener('pointerdown', e => {
                    e.preventDefault();
                    try {
                        mR2.setPointerCapture(e.pointerId);
                    } catch (_) {}
                    mR2.src = aimg('r2on.png');
                    setSpd(0.5);
                    playChimeTwice();
                });
                const up = () => {
                    mR2.src = aimg('r2off.png');
                };
                mR2.addEventListener('pointerup', up);
                mR2.addEventListener('pointercancel', up);
            }


            const mPl = $('mPl');
            const PL = {
                off: 'ploff.png',
                on: 'plon.png',
                toOn: 'plpresstoon.png',
                toOff: 'plpresstooff.png'
            };
            let plBusy = false;
            const playing = () => !!(currentAudio && !currentAudio.paused);
            const paintPl = () => {
                if (mPl && !plBusy) mPl.src = aimg(playing() ? PL.on : PL.off);
            };
            if (mPl) mPl.addEventListener('click', () => {
                if (plBusy) return;
                plBusy = true;
                mPl.src = aimg(playing() ? PL.toOff : PL.toOn);
                playSingelPress();
                setTimeout(() => {
                    plBusy = false;
                    if (pBtn.onclick) pBtn.onclick();
                    paintPl();
                }, 130);
            });
            document.addEventListener('play', e => {
                if (e.target.tagName === 'AUDIO') paintPl();
            }, true);
            document.addEventListener('pause', e => {
                if (e.target.tagName === 'AUDIO') paintPl();
            }, true);
            paintPl();


            function knobs(el, steps, onStep, sound) {
                let i = 0;
                const paint = () => {
                    el.style.transform = 'translate(-49.5%, -55.2%) rotate(' + steps[i].deg + 'deg)';
                };
                const step = dir => {
                    const n = Math.max(0, Math.min(steps.length - 1, i + dir));
                    if (n !== i) {
                        i = n;
                        (sound || click)();
                        paint();
                        onStep(steps[i], i);
                    }
                };
                el.addEventListener('click', () => step(-1));
                el.addEventListener('contextmenu', e => {
                    e.preventDefault();
                    step(+1);
                });
                el.addEventListener('wheel', e => {
                    e.preventDefault();
                    step(e.deltaY < 0 ? +1 : -1);
                }, {
                    passive: false
                });
                paint();
                return {
                    sync: combo => {
                        const n = steps.findIndex(s => s.combo === combo);
                        if (n >= 0) {
                            i = n;
                            paint();
                        }
                    }
                };
            }
            const OFF_ANGLE = 117.7;
            const ISR_STEPS = [{
                    deg: 0,
                    combo: ''
                },
                {
                    deg: 180 - OFF_ANGLE,
                    combo: 'R'
                },
                {
                    deg: 225 - OFF_ANGLE,
                    combo: 'IR'
                },
                {
                    deg: 270 - OFF_ANGLE,
                    combo: 'SR'
                },
            ];
            const curCombo = () => iBtn.style.color === 'yellow' ? 'IR' : sBtn.style.color === 'yellow' ? 'SR' : loopBtn.style.color === 'yellow' ? 'R' : '';
            const mISR = $('mISR');
            let isrCtl = null;
            if (mISR) isrCtl = knobs(mISR, ISR_STEPS, s => {
                iBtn.style.color = s.combo === 'IR' ? 'yellow' : 'white';
                sBtn.style.color = s.combo === 'SR' ? 'yellow' : 'white';
                loopBtn.style.color = (s.combo === 'R' || s.combo === 'IR' || s.combo === 'SR') ? 'yellow' : 'white';
                updateLoopState();
            }, click);


            function switchControl(img, states, onChange, sound, reverseClicks) {
                let i = states.findIndex(s => s.def);
                if (i < 0) i = 0;
                const paint = () => {
                    img.src = aimg(states[i].f);
                    img.style.transform = 'translate(-49.2%, ' + states[i].ty + '%)';
                };
                const setIdx = n => {
                    n = Math.max(0, Math.min(states.length - 1, n));
                    if (n !== i) {
                        i = n;
                        (sound || arm)();
                        paint();
                        onChange(states[i], i);
                    }
                };
                const leftDir = reverseClicks ? +1 : -1;
                img.addEventListener('click', () => setIdx(i + leftDir));
                img.addEventListener('contextmenu', e => {
                    e.preventDefault();
                    setIdx(i - leftDir);
                });
                img.addEventListener('wheel', e => {
                    e.preventDefault();
                    e.stopPropagation();
                    setIdx(i + (e.deltaY < 0 ? +1 : -1));
                }, {
                    passive: false
                });
                paint();
            }
            const ST = {
                D: {
                    f: 'sd.png',
                    ty: -25.7
                },
                C: {
                    f: 'sc.png',
                    ty: -49.1
                },
                U: {
                    f: 'st.png',
                    ty: -74.3
                }
            };



            let armed = 0,
                lastSide = 0;
            const mNav = $('mNav');
            if (mNav) switchControl(mNav, [{
                    f: ST.D.f,
                    ty: ST.D.ty,
                    name: 'D'
                },
                {
                    f: ST.C.f,
                    ty: ST.C.ty,
                    name: 'C',
                    def: true
                },
                {
                    f: ST.U.f,
                    ty: ST.U.ty,
                    name: 'U'
                },
            ], s => {
                const dir = s.name === 'U' ? -1 : s.name === 'D' ? +1 : 0;
                if (dir === 0) {
                    armed = 0;
                    return;
                }
                if (lastSide === dir)(dir > 0 ? nextBtn : prevBtn).click();
                lastSide = dir;
                armed = dir;
            }, arm, true);
            document.addEventListener('ended', e => {
                if (e.target.tagName !== 'AUDIO') return;
                const shuffle = loopBtn.style.color === 'yellow' && (iBtn.style.color === 'yellow' || sBtn.style.color === 'yellow');
                if (armed !== 0 && !shuffle)(armed > 0 ? nextBtn : prevBtn).click();
            }, true);


            const mK = $('mK');
            const paintK = () => {
                if (!mK) return;
                const s = lrcMode ? ST.D : ST.C;
                mK.src = aimg(s.f);
                mK.style.transform = 'translate(-49.2%, ' + s.ty + '%)';
            };
            if (mK) {
                mK.addEventListener('click', () => {
                    if (lrcMode) {
                        lrcMode = false;
                        kBtn.style.color = 'yellow';
                        arm();
                        paintK();
                    }
                });
                mK.addEventListener('contextmenu', e => {
                    e.preventDefault();
                    if (!lrcMode) {
                        lrcMode = true;
                        kBtn.style.color = 'white';
                        arm();
                        paintK();
                    }
                });
                mK.addEventListener('wheel', e => {
                    e.preventDefault();
                    e.stopPropagation();
                    const goOn = e.deltaY < 0;
                    if (goOn !== lrcMode) {
                        lrcMode = goOn;
                        kBtn.style.color = lrcMode ? 'white' : 'yellow';
                        arm();
                        paintK();
                    }
                }, {
                    passive: false
                });
                paintK();
            }


            renderSpeedKnob = renderSpd;
            renderRevKnob = renderRev;
            renderVolKnob = renderVol;
            mSyncControls = () => {
                renderSpd();
                renderRev();
                renderVol();
                if (isrCtl) isrCtl.sync(curCombo());
                paintK();
                paintPl();
            };
        })();

        const srchEl = document.getElementById('srch');
        srchEl.addEventListener('input', () => {
            const v = foldSearch(srchEl.textContent || '');
            document.querySelectorAll('.cardWrap').forEach(w => {
                if (w.__search === undefined) {
                    const name = w.querySelector('.cName');
                    const lyrics = w.querySelector('.cLyr');
                    w.__search = foldSearch((name ? name.textContent : '') + ' ' + (lyrics ? lyrics.textContent : ''));
                }
                w.style.display = (v === '' || w.__search.includes(v)) ? '' : 'none';
            });
            document.querySelectorAll('.catLabel').forEach(label => {
                let el = label.nextElementSibling;
                let anyVisible = false;
                while (el && !el.classList.contains('catLabel')) {
                    if (el.classList.contains('cardWrap') && el.style.display !== 'none') anyVisible = true;
                    el = el.nextElementSibling;
                }
                label.style.display = v === '' || anyVisible ? '' : 'none';
            });
        });

        const propagateVolume = () => {
            document.querySelectorAll('audio').forEach(a => {
                a.volume = PAGE_VOLUME;
            });
        };

        window.addEventListener('DOMContentLoaded', () => {

            try {
                const st = JSON.parse(localStorage.getItem('m2settings') || 'null');
                if (st) {
                    if (typeof st.vol === 'number') PAGE_VOLUME = Math.max(0, Math.min(1, st.vol));
                    if (typeof st.speed === 'number') currentPct = Math.max(0, Math.min(1, st.speed));
                    if (typeof st.rev === 'number') currentRevPct = Math.max(0, Math.min(1, st.rev));
                    if (typeof st.k === 'boolean') {
                        lrcMode = st.k;
                        kBtn.style.color = lrcMode ? 'white' : 'yellow';
                    }
                    if (typeof st.l === 'boolean') {
                        lMode = st.l;
                        if (lb) lb.style.color = lMode ? 'yellow' : 'white';
                    }
                    if (st.i) iBtn.style.color = 'yellow';
                    if (st.s) sBtn.style.color = 'yellow';
                    if (st.r) loopBtn.style.color = 'yellow';
                    updateSpeedVisuals();
                    if (!lMode) updateReverbVisuals();
                    updateLoopState();
                    renderVolKnob();
                    mSyncControls();
                }
            } catch (e) {}

            propagateVolume();
            propagateSpeed();
            const hash = location.hash.slice(1);
            if (hash) {
                const target = document.getElementById(hash);
                if (target && target.classList.contains('card')) {
                    const a = target.querySelector('audio');
                    const nameEl = target.querySelector('.cName');
                    if (nameEl) {
                        nameEl.style.background = 'yellow';
                        nameEl.style.color = 'black';
                        const clearHighlight = () => {
                            nameEl.style.background = '';
                            nameEl.style.color = '';
                        };
                        document.addEventListener('click', clearHighlight, {
                            once: true
                        });
                        document.addEventListener('play', clearHighlight, {
                            once: true,
                            capture: true
                        });
                    }
                    if (a) {
                        loadAudio(a);
                        currentAudio = a;
                        a.volume = PAGE_VOLUME;
                        a.playbackRate = currentSpeed;
                        updateLoopState();
                        a.play();
                        target.scrollIntoView({
                            block: 'center'
                        });
                    }
                }
            }
        });

        document.addEventListener('click', e => {
            if (e.target.closest('.cSide')) return;
            if (e.target.classList.contains('delBtn') || e.target.classList.contains('editBtn')) return;

            const lrcLine = e.target.closest('.lrcLine');
            const cLyr = e.target.closest('.cLyr');

            const wrap = e.target.closest('.cardWrap');
            if (!wrap) return;
            const card = wrap.querySelector('.card');
            if (!card) return;
            const audio = card.querySelector('audio');
            if (!audio) return;

            if (lrcLine) {
                const time = parseFloat(lrcLine.dataset.t);
                if (currentAudio !== audio) {
                    document.querySelectorAll('audio').forEach(a => {
                        if (a !== audio) {
                            a.pause();
                            a.loop = false;
                        }
                    });
                    currentAudio = audio;
                    loadAudio(currentAudio);
                    currentAudio.volume = PAGE_VOLUME;
                    currentAudio.playbackRate = currentSpeed;
                }
                const setT = () => {
                    currentAudio.currentTime = time;
                    updateLoopState();
                    currentAudio.play();
                };
                if (currentAudio.readyState >= 1) setT();
                else {
                    currentAudio.addEventListener('loadedmetadata', setT, {
                        once: true
                    });
                    currentAudio.play();
                }
                return;
            }

            if (cLyr && cLyr.querySelector('.lrcLine')) {
                const rect = cLyr.getBoundingClientRect();
                const clickY = e.clientY - rect.top;
                const pct = Math.max(0, Math.min(1, clickY / rect.height));
                if (currentAudio !== audio) {
                    document.querySelectorAll('audio').forEach(a => {
                        if (a !== audio) {
                            a.pause();
                            a.loop = false;
                        }
                    });
                    currentAudio = audio;
                    loadAudio(currentAudio);
                    currentAudio.volume = PAGE_VOLUME;
                    currentAudio.playbackRate = currentSpeed;
                }
                const setT = () => {
                    if (currentAudio.duration) {
                        currentAudio.currentTime = pct * currentAudio.duration;
                        updateLoopState();
                        currentAudio.play();
                    }
                };
                if (currentAudio.readyState >= 1) setT();
                else {
                    currentAudio.addEventListener('loadedmetadata', setT, {
                        once: true
                    });
                    currentAudio.play();
                }
                return;
            }

            currentAudio = audio;
            loadAudio(currentAudio);
            currentAudio.volume = PAGE_VOLUME;
            currentAudio.playbackRate = currentSpeed;
            document.querySelectorAll('audio').forEach(a => {
                if (a !== audio) {
                    a.pause();
                    a.loop = false;
                }
            });
            if (audio.paused) {
                updateLoopState();
                audio.play();
            } else {
                audio.pause();
                audio.loop = false;
            }
        });

        document.addEventListener('keydown', e => {
            if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                e.preventDefault();
                srchEl.focus();
                const range = document.createRange();
                range.selectNodeContents(srchEl);
                range.collapse(false);
                const sel = window.getSelection();
                sel.removeAllRanges();
                sel.addRange(range);
                return;
            }
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.isContentEditable) return;
            if (!currentAudio) return;
            const key = e.key;
            if (key >= '0' && key <= '9') {
                e.preventDefault();
                if (!isNaN(currentAudio.duration) && currentAudio.duration > 0) {
                    const num = parseInt(key, 10);
                    let handled = false;
                    if (lrcMode) {
                        const card = currentAudio.closest('.card');
                        const activeLine = card ? card.querySelector('.lrcLine.lrcActive') : null;
                        if (activeLine) {
                            const start = parseFloat(activeLine.dataset.t);
                            let end = currentAudio.duration;
                            const next = activeLine.nextElementSibling;
                            if (next && next.classList.contains('lrcLine')) {
                                end = parseFloat(next.dataset.t);
                            }
                            const lineDur = end - start;
                            if (lineDur > 45) {
                                currentAudio.currentTime = start + (lineDur * num * 0.1);
                                handled = true;
                            }
                        }
                    }
                    if (!handled) {
                        currentAudio.currentTime = currentAudio.duration * num * 0.1;
                    }
                }
                return;
            }
            if (e.key === 'Escape') {
                const frame = document.getElementById('comFrame');
                frame.classList.remove('open');
                frame.src = 'about:blank';
            }
            if (key === 'ArrowLeft') {
                e.preventDefault();
                currentAudio.currentTime = Math.max(0, currentAudio.currentTime - 5);
                return;
            }
            if (key === 'ArrowRight') {
                e.preventDefault();
                currentAudio.currentTime = Math.min(currentAudio.duration, currentAudio.currentTime + 5);
                return;
            }
            if (key === ' ' || key === 'Spacebar') {
                e.preventDefault();
                if (currentAudio.paused) {
                    updateLoopState();
                    currentAudio.play();
                } else currentAudio.pause();
            }
        });

        const rowTpl = () => `<div class="rowgrp entry">
        <input name="category[]" list="catList" placeholder="category" required>
        <input name="title[]" placeholder="title" required autocomplete="off">
        <input type="file" name="file[]" accept=".mp3" required>
        <button type="button" class="ctlBtn addBtn">+</button>
        <button type="button" class="ctlBtn delBtn">D</button>
    </div>`;

        const addBtn = document.createElement('button');
        addBtn.id = 'submitRows';
        addBtn.className = 'ctlBtn';
        addBtn.textContent = 'Add.';
        addBtn.style.display = 'none';
        zone.appendChild(addBtn);

        const wait = ms => new Promise(r => setTimeout(r, ms));

        function makeProgressLine(fileName, title) {
            const line = document.createElement('div');
            line.textContent = `${fileName} — ${title} > 0%`;
            progressZone.appendChild(line);
            return line;
        }

        function uploadFile(row, progLine) {
            const [c, t, f] = row.querySelectorAll('input');
            let attempt = 0;
            return new Promise(resolve => {
                function tryUpload() {
                    attempt++;
                    const form = new FormData();
                    form.append('category[]', c.value.trim());
                    form.append('title[]', t.value.trim());
                    form.append('file[]', f.files[0]);
                    form.append('auth', TOKEN);
                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', 'amqury.php');
                    xhr.upload.onprogress = ev => {
                        if (ev.lengthComputable) {
                            const decile = Math.floor(ev.loaded / ev.total * 10) * 10;
                            const retry = attempt > 1 ? ` (retry ${attempt - 1})` : '';
                            progLine.textContent = `${f.files[0].name} — ${t.value.trim()} > ${decile}%${retry}`;
                        }
                    };
                    xhr.onload = () => {
                        let ok = false,
                            reason = 'unknown';
                        try {
                            const j = JSON.parse(xhr.responseText);
                            ok = !!j.ok;
                            reason = j.reason || reason;
                        } catch {}
                        if (ok) {
                            progLine.textContent = `${f.files[0].name} — ${t.value.trim()} > 100%`;
                            return resolve();
                        }
                        progLine.textContent = `${f.files[0].name} — ${t.value.trim()} > error (${reason}), retrying…`;
                        wait(2000).then(tryUpload);
                    };
                    xhr.onerror = () => {
                        progLine.textContent = `${f.files[0].name} — ${t.value.trim()} > network error, retrying…`;
                        wait(2000).then(tryUpload);
                    };
                    xhr.send(form);
                }
                tryUpload();
            });
        }

        addBtn.addEventListener('click', async () => {
            if (!TOKEN) return;
            const rows = [...document.querySelectorAll('.rowgrp.entry')];
            if (!rows.length) return;
            for (const r of rows) {
                const [c, t, f] = r.querySelectorAll('input');
                if (!c.value.trim() || !t.value.trim() || !f.files.length) return;
            }
            await Promise.all(rows.map(row => {
                const [, t, f] = row.querySelectorAll('input');
                return uploadFile(row, makeProgressLine(f.files[0].name, t.value.trim()));
            }));
            setTimeout(() => location.reload(), 1000);
        });

        function enableAdds() {
            addBtn.style.display = 'inline';
        }

        function refreshDel() {
            const r = zone.querySelectorAll('.rowgrp.entry');
            r.forEach(el => el.querySelector('.delBtn').disabled = r.length === 1);
        }

        function copyLinkId(a) {
            navigator.clipboard.writeText(location.origin + location.pathname + '#' + a);
        }

        function enableDeletes() {
            document.querySelectorAll('.delBtn').forEach(b => b.style.display = 'inline');
            document.querySelectorAll('.editBtn').forEach(b => b.style.display = 'inline');
        }

        document.addEventListener('click', e => {
            if (!e.target.classList.contains('delBtn')) return;
            if (!TOKEN) return;
            const id = parseInt(e.target.dataset.anchor.split('_')[0], 10);
            if (confirm('delete?')) {
                fetch('dmqury.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'id=' + id + '&auth=' + encodeURIComponent(TOKEN)
                }).then(r => r.json()).then(j => {
                    if (j.ok) location.reload();
                });
            }
        });

        function addRow(ref = null) {
            const w = document.createElement('div');
            w.innerHTML = rowTpl();
            const r = w.firstElementChild;
            ref ? ref.after(r) : zone.prepend(r);
            refreshDel();
        }

        zone.addEventListener('click', e => {
            if (e.target.classList.contains('addBtn')) addRow(e.target.parentElement);
            if (e.target.classList.contains('delBtn')) {
                e.target.parentElement.remove();
                refreshDel();
            }
        });

        async function sha256(m) {
            const b = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(m));
            return [...new Uint8Array(b)].map(x => x.toString(16).padStart(2, '0')).join('');
        }

        function requestToken(h) {
            fetch('ꜷth.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: 'hash=' + h
            }).then(r => r.json()).then(j => {
                if (j.ok) {
                    TOKEN = h;
                    enableDeletes();
                    enableAdds();
                    addRow();
                    uBox.classList.add('open');
                }
            });
        }

        document.getElementById('spawn').addEventListener('click', async () => {
            window.scrollTo(0, 0);
            uBox.classList.add('open');
            if (TOKEN) {
                addRow();
                return;
            }
            if (!document.getElementById('codeRow')) {
                const cwrap = document.createElement('div');
                cwrap.id = 'codeRow';
                cwrap.className = 'rowgrp';
                cwrap.innerHTML = '<input id="codeInput" type="password" placeholder="access-code" autocomplete="off"><button id="checkBtn" class="ctlBtn">✔</button>';
                zone.prepend(cwrap);
                document.getElementById('checkBtn').addEventListener('click', async () => {
                    const val = document.getElementById('codeInput').value;
                    requestToken(await sha256(SALT + val));
                    cwrap.remove();
                });
            }
        });


        let _ck = 0,
            _ct = null;
        document.addEventListener('click', e => {
            if (!e.target.closest('.catLabel')) return;
            _ck++;
            if (_ck === 1) _ct = setTimeout(() => {
                _ck = 0;
            }, 400);
            if (_ck >= 3) {
                clearTimeout(_ct);
                _ck = 0;
                document.getElementById('spawn').click();
            }
        });


        const tJumpLinks = [...document.querySelectorAll('.tJumpCat')];
        const catLabels = [...document.querySelectorAll('.catLabel')];

        const updateActiveCategory = () => {
            const triggerY = window.innerHeight * 0.4;
            let activeId = null;

            for (let i = 0; i < catLabels.length; i++) {
                const rect = catLabels[i].getBoundingClientRect();
                if (rect.top <= triggerY) {
                    activeId = catLabels[i].id;
                } else {
                    break;
                }
            }
            if (!activeId && catLabels.length > 0) activeId = catLabels[0].id;

            tJumpLinks.forEach(a => {
                a.classList.toggle('tJumpActive', a.getAttribute('data-href') === '#' + activeId);
            });
        };

        let isScrollThrottling = false;
        window.addEventListener('scroll', () => {
            if (!isScrollThrottling) {
                window.requestAnimationFrame(() => {
                    updateActiveCategory();
                    isScrollThrottling = false;
                });
                isScrollThrottling = true;
            }
        }, {
            passive: true
        });
        updateActiveCategory();


        if (window.matchMedia('(max-width: 700px)').matches) {
            document.querySelectorAll('.cardWrap').forEach(wrap => {
                const card = wrap.querySelector('.card');
                if (!card) return;


                const cat = wrap.dataset.category || '';
                if (cat) {
                    const catEl = document.createElement('div');
                    catEl.className = 'cCard-cat';
                    catEl.textContent = cat;
                    card.appendChild(catEl);
                }


                const cLeft = wrap.querySelector('.cLeft');
                if (cLeft) {
                    const imgs = [...cLeft.querySelectorAll('img')];
                    if (imgs.length > 0) {
                        const stack = document.createElement('div');
                        stack.className = 'mob-pfp-stack';
                        imgs.forEach(img => {
                            const clone = img.cloneNode(true);
                            stack.appendChild(clone);
                        });
                        card.appendChild(stack);
                    }
                }



                const existingLyr = card.querySelector('.cLyr');
                const existingName = card.querySelector('.cName');
                const drawer = document.createElement('div');
                drawer.className = 'mob-drawer';

                const drawerName = document.createElement('div');
                drawerName.className = 'mob-drawer-name';
                drawerName.textContent = existingName ? existingName.textContent : '';
                drawer.appendChild(drawerName);

                if (existingLyr) {
                    drawer.appendChild(existingLyr);
                }

                card.appendChild(drawer);
            });


            document.addEventListener('play', e => {
                if (e.target.tagName !== 'AUDIO') return;
                const playingWrap = e.target.closest('.cardWrap');
                document.querySelectorAll('.cardWrap.mob-open').forEach(w => {
                    if (w !== playingWrap) w.classList.remove('mob-open');
                });
                if (playingWrap) playingWrap.classList.add('mob-open');
            }, true);
        }

        function openCom(e, btn) {
            e.preventDefault();
            e.stopPropagation();
            const frame = document.getElementById('comFrame');
            if (frame.classList.contains('open') && frame.src === btn.href) {
                frame.classList.remove('open');
                frame.src = 'about:blank';
            } else {
                frame.src = btn.href;
                frame.classList.add('open');
            }
        }

        window.playAndSeek = function(mid, time) {
            const editBtn = document.querySelector(`a.editBtn[href="editm.php?id=${mid}"]`);
            if (!editBtn) return;

            const wrap = editBtn.closest('.cardWrap');
            const audio = wrap.querySelector('audio');

            if (audio) {
                if (currentAudio !== audio) {
                    document.querySelectorAll('audio').forEach(a => {
                        if (a !== audio) {
                            a.pause();
                            a.loop = false;
                        }
                    });
                    currentAudio = audio;
                    loadAudio(currentAudio);
                    currentAudio.volume = PAGE_VOLUME;
                    currentAudio.playbackRate = currentSpeed;
                }
                currentAudio.currentTime = time;
                if (currentAudio.paused) {
                    updateLoopState();
                    currentAudio.play();
                }
                wrap.scrollIntoView({
                    behavior: 'smooth',
                    block: 'center'
                });
            }
        };

        (function() {
            window.addEventListener('DOMContentLoaded', () => {
                const overlay = document.getElementById('loaderOverlay');
                const pctEl = document.getElementById('loaderPct');
                const audio = document.getElementById('loaderAudio');

                let completed = false;
                let assetsLoaded = false;

                function getVersionedUrl(url) {
                    if (!url) return url;

                    const assetVersions = [
                        ['go']
                    ];

                    for (let i = 0; i < assetVersions.length; i++) {
                        const version = i + 1;
                        const patterns = assetVersions[i];
                        for (const pattern of patterns) {
                            if (url.includes(pattern)) {
                                const separator = url.includes('?') ? '&' : '?';
                                return `${url}${separator}v=${version}`;
                            }
                        }
                    }
                    return url;
                }

                function checkFinish() {
                    if (assetsLoaded && !completed) {
                        completed = true;
                        setTimeout(() => {
                            if (pctEl) pctEl.textContent = '100%';
                            hideLoader();
                        }, 1000);
                    }
                }

                function hideLoader() {
                    if (overlay) {
                        overlay.style.opacity = '0';

                        if (audio) {
                            const fadeInterval = setInterval(() => {
                                if (audio.volume > 0.05) {
                                    audio.volume -= 0.05;
                                } else {
                                    audio.volume = 0;
                                    audio.pause();
                                    clearInterval(fadeInterval);
                                }
                            }, 25);
                        }

                        setTimeout(() => {
                            overlay.style.display = 'none';
                        }, 500);
                    }
                }

                setTimeout(() => {
                    const info = document.getElementById('loaderInfo');
                    const scroller = document.getElementById('loaderInfoScroll');
                    if (info && scroller && overlay && overlay.style.display !== 'none') {
                        info.style.opacity = '1';

                        const chosenClass = scroller.classList.contains('waiting3') ? 'waiting3' : (scroller.classList.contains('waiting2') ? 'waiting2' : 'waiting');
                        const paragraphs = [...scroller.querySelectorAll('p')].filter(p => p.classList.contains(chosenClass));

                        function startScrolling() {
                            if (overlay.style.display === 'none') return;
                            const scrollHeight = scroller.scrollHeight;
                            const clientHeight = info.clientHeight;
                            if (scrollHeight > clientHeight) {
                                let currentY = 0;
                                const maxScroll = scrollHeight - clientHeight;
                                const scrollInterval = setInterval(() => {
                                    if (overlay.style.display === 'none') {
                                        clearInterval(scrollInterval);
                                        return;
                                    }
                                    currentY += 0.5;
                                    if (currentY >= maxScroll) {
                                        currentY = maxScroll;
                                        clearInterval(scrollInterval);
                                    }
                                    scroller.style.transform = `translateY(${-currentY}px)`;
                                }, 30);
                            }
                        }

                        function showParagraph(i) {
                            if (overlay.style.display === 'none') return;
                            if (i >= paragraphs.length) {
                                startScrolling();
                                return;
                            }

                            const p = paragraphs[i];
                            p.style.opacity = '1';

                            const style = window.getComputedStyle(p);
                            const durationSec = parseFloat(style.transitionDuration) || 4;
                            const textLength = p.textContent.length;
                            const readingTimeSec = Math.max(3, textLength / 12);
                            const totalWaitMs = (durationSec + readingTimeSec) * 1000;

                            setTimeout(() => {
                                showParagraph(i + 1);
                            }, totalWaitMs);
                        }

                        showParagraph(0);
                    }
                }, 5000);

                function startDeferredLoading() {
                    const images = [...document.querySelectorAll('img[data-src]')];
                    const videos = [...document.querySelectorAll('video[data-src]')];
                    const totalCount = images.length + videos.length;

                    if (totalCount === 0) {
                        assetsLoaded = true;
                        checkFinish();
                        return;
                    }

                    const loadedAssets = new Set();

                    function updateProgress(asset) {
                        if (loadedAssets.has(asset)) return;
                        loadedAssets.add(asset);

                        const rawPct = Math.round((loadedAssets.size / totalCount) * 100);
                        const displayPct = Math.min(99, rawPct);
                        if (pctEl) pctEl.textContent = displayPct + '%';

                        if (loadedAssets.size >= totalCount && !assetsLoaded) {
                            assetsLoaded = true;
                            checkFinish();
                        }
                    }

                    function loadVideos() {
                        videos.forEach(video => {
                            video.addEventListener('loadeddata', () => updateProgress(video));
                            video.addEventListener('error', () => updateProgress(video));
                            video.src = getVersionedUrl(video.dataset.src);
                        });
                    }

                    document.fonts.load('1em "BabelStone Han"').then(() => {
                        if (images.length === 0) {
                            loadVideos();
                        } else {
                            let loadedImagesCount = 0;
                            images.forEach(img => {
                                const onImgDone = () => {
                                    updateProgress(img);
                                    loadedImagesCount++;
                                    if (loadedImagesCount === images.length) {
                                        loadVideos();
                                    }
                                };
                                img.addEventListener('load', onImgDone);
                                img.addEventListener('error', onImgDone);
                                img.src = getVersionedUrl(img.dataset.src);
                            });
                        }
                    }).catch(() => {
                        images.forEach(img => {
                            img.addEventListener('load', () => updateProgress(img));
                            img.addEventListener('error', () => updateProgress(img));
                            img.src = getVersionedUrl(img.dataset.src);
                        });
                        loadVideos();
                    });
                }

                if (audio) {
                    audio.volume = 1.0;

                    let started = false;
                    const triggerLoad = () => {
                        if (started) return;
                        started = true;
                        startDeferredLoading();
                    };

                    audio.addEventListener('playing', triggerLoad);
                    audio.addEventListener('canplaythrough', triggerLoad);

                    const playPromise = audio.play();
                    if (playPromise !== undefined) {
                        playPromise.then(triggerLoad).catch(() => {
                            const startOnInteraction = () => {
                                audio.play().then(triggerLoad).catch(triggerLoad);
                                document.removeEventListener('click', startOnInteraction);
                                document.removeEventListener('keydown', startOnInteraction);
                            };
                            document.addEventListener('click', startOnInteraction);
                            document.addEventListener('keydown', startOnInteraction);
                        });
                    } else {
                        triggerLoad();
                    }
                } else {
                    startDeferredLoading();
                }

                setTimeout(() => {
                    if (overlay && overlay.style.display !== 'none' && !completed) {
                        completed = true;
                        assetsLoaded = true;
                        if (pctEl) pctEl.textContent = '100%';
                        hideLoader();
                    }
                }, 45000);
            });
        })();
    </script>
    <iframe id="comFrame" src="about:blank"></iframe>
    <script>
        (function () {
            const PRESENCE_PORT = 6700;
            const ENDPOINT = 'http://127.0.0.1:' + PRESENCE_PORT + '/np';
            const ART_BASE = (location.hostname === 'nullpunkts.com.pr')
                ? 'https://nullpunkts.share.zrok.io'
                : location.origin;

            const isCardAudio = el =>
                el && el.tagName === 'AUDIO' && el.id !== 'loaderAudio' && el.closest('.card');

            function collageInfo(audio) {
                if (!(window.__npK && window.__npK())) return null;
                const card = audio.closest('.card');
                if (!card) return null;
                const dur = audio.duration;
                if (!dur || !isFinite(dur)) return null;
                const lines = card.querySelectorAll('.lrcLine');
                if (lines.length < 2) return null;
                const segs = [];
                for (let i = 0; i < lines.length; i++) {
                    const start = parseFloat(lines[i].dataset.t);
                    if (isNaN(start)) continue;
                    let end = dur;
                    const next = lines[i + 1];
                    if (next) { const ns = parseFloat(next.dataset.t); if (!isNaN(ns)) end = ns; }
                    if (end - start > 45) segs.push([start, end, (lines[i].textContent || '').trim()]);
                }
                if (!segs.length) return null;
                const t = audio.currentTime;
                let cur = segs[segs.length - 1];
                for (const s of segs) { if (t >= s[0] && t < s[1]) { cur = s; break; } }
                return { part: cur[2], start: cur[0], end: cur[1] };
            }

            function buildState(audio, paused) {
                const card = audio.closest('.card');
                if (!card) return null;
                let name = (card.querySelector('.cName')?.textContent || '').trim();
                const category = (card.closest('.cardWrap')?.dataset.category || '').trim();
                let art = '';
                const imgEl = card.querySelector('.cImg img');
                const raw = imgEl ? (imgEl.dataset.src || imgEl.getAttribute('src') || '') : '';
                if (raw && !raw.startsWith('blob:')) {
                    try { art = new URL(raw, ART_BASE).href; } catch (e) { art = ''; }
                }
                let startMs = 0, endMs = 0;
                const dur = audio.duration;
                const col = collageInfo(audio);
                if (col) {
                    if (col.part) name = name ? (name + ' — ' + col.part) : col.part;
                    startMs = Math.round(Date.now() - (audio.currentTime - col.start) * 1000);
                    endMs = Math.round(startMs + (col.end - col.start) * 1000);
                } else if (dur && isFinite(dur)) {
                    startMs = Math.round(Date.now() - audio.currentTime * 1000);
                    endMs = Math.round(startMs + dur * 1000);
                }
                return { name, category, art, startMs, endMs, paused: !!paused };
            }

            function push(obj) {
                try {
                    fetch(ENDPOINT, {
                        method: 'POST',
                        headers: { 'content-type': 'application/json' },
                        body: JSON.stringify(obj),
                        keepalive: true
                    }).catch(() => {});
                } catch (e) {}
            }

            let lastKey = '', lastSentAt = 0;
            function send(audio, paused) {
                const st = buildState(audio, paused);
                if (!st || !st.name) return;
                if (!paused && !st.endMs) return;
                const k = (paused ? 'P|' : '') + st.name + '|' + Math.round(st.startMs / 1000) + '|' + Math.round(st.endMs / 1000);
                const now = Date.now();
                if (k === lastKey && now - lastSentAt < 15000) return;
                lastKey = k;
                lastSentAt = now;
                push(st);
            }

            ['play', 'playing', 'loadedmetadata', 'durationchange', 'seeked'].forEach(ev =>
                document.addEventListener(ev, e => {
                    if (isCardAudio(e.target) && !e.target.paused) send(e.target, false);
                }, true));
            document.addEventListener('pause', e => { if (isCardAudio(e.target)) send(e.target, true); }, true);
            document.addEventListener('ended', e => { if (isCardAudio(e.target)) { lastKey = ''; push({ clear: true }); } }, true);
            document.addEventListener('timeupdate', e => { if (isCardAudio(e.target) && !e.target.paused) send(e.target, false); }, true);
            window.addEventListener('pagehide', () => { lastKey = ''; push({ clear: true }); });
        })();
    </script>
</body>

</html>