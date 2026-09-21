<?php

declare(strict_types=1);

const M2_BROWSER_CACHE_VERSION = '15';
const M2_PAGE_CODE_VERSION = '104';
const M2_ARCHIVE_FINGERPRINT_PROTOCOL = 1;
const M2_ARCHIVE_SAMPLE_BYTES = 65536;

require_once dirname(__DIR__) . '/wiki/vendor/autoload.php';

function m2_code_variant(): string
{
    $variant = $_GET['v'] ?? M2_PAGE_CODE_VERSION;
    if (!is_string($variant)) return M2_PAGE_CODE_VERSION;
    $variant = trim($variant);
    if ($variant === '' || strlen($variant) > 96) return M2_PAGE_CODE_VERSION;
    return $variant;
}

function m2_xor_script(string $source, int $state): string
{
    $result = '';
    $length = strlen($source);
    for ($i = 0; $i < $length; $i++) {
        $state = (($state * 1664525) + 1013904223) & 0xffffffff;
        $result .= chr(ord($source[$i]) ^ (($state >> 24) & 0xff));
    }
    return $result;
}

function m2_encode_script(string $source, string $variant, int $index): string
{
    try {
        $source = Wikimedia\Minify\JavaScriptMinifier::minify($source);
    } catch (Throwable) {
    }
    $digest = hash('sha256', M2_PAGE_CODE_VERSION . "\0" . $variant . "\0" . $index, true);
    $state = unpack('N', substr($digest, 0, 4))[1] ?: 1;
    $suffix = substr(bin2hex($digest), 8, 12);
    $stateName = '_s' . $suffix;
    $bytesName = '_b' . $suffix;
    $charName = '_c' . $suffix;
    $indexName = '_i' . $suffix;
    $textName = '_t' . $suffix;
    $payload = base64_encode(m2_xor_script($source, $state));
    return '<script>(()=>{let ' . $stateName . '=' . $state . ',' . $bytesName
        . '=Uint8Array.from(atob(\'' . $payload . '\'),' . $charName . '=>'
        . $charName . '.charCodeAt(0));for(let ' . $indexName . '=0;' . $indexName
        . '<' . $bytesName . '.length;' . $indexName . '++){' . $stateName
        . '=(Math.imul(' . $stateName . ',1664525)+1013904223)>>>0;'
        . $bytesName . '[' . $indexName . ']^=' . $stateName
        . '>>>24}let ' . $textName . '=new TextDecoder().decode(' . $bytesName
        . ');(0,eval)(' . $textName . ')})()</script>';
}

function m2_transform_document(string $html): string
{
    $variant = m2_code_variant();
    $scriptIndex = 0;
    $html = preg_replace_callback(
        '~<script\b([^>]*)>(.*?)</script\s*>~is',
        static function (array $match) use ($variant, &$scriptIndex): string {
            if (preg_match('/\bsrc\s*=/i', $match[1])) return $match[0];
            if (preg_match('/\btype\s*=\s*([\'\"])(?!text\/javascript\1|application\/javascript\1)/i', $match[1])) {
                return $match[0];
            }
            return m2_encode_script($match[2], $variant, $scriptIndex++);
        },
        $html
    ) ?? $html;
    $html = preg_replace_callback(
        '~<style\b([^>]*)>(.*?)</style\s*>~is',
        static function (array $match): string {
            try {
                $css = Wikimedia\Minify\CSSMin::minify($match[2]);
            } catch (Throwable) {
                $css = $match[2];
            }
            return '<style' . $match[1] . '>' . $css . '</style>';
        },
        $html
    ) ?? $html;
    return preg_replace('/>\s+</u', '> <', $html) ?? $html;
}

function m2_versioned_asset(string $url): string
{
    $separator = str_contains($url, '?') ? '&' : '?';
    return $url . $separator . 'v=' . rawurlencode(M2_BROWSER_CACHE_VERSION);
}

function m2_archive_mime(string $path): string
{
    $types = [
        'mp3' => 'audio/mpeg',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'css' => 'text/css',
        'js' => 'text/javascript'
    ];
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (isset($types[$extension])) return $types[$extension];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($path);
    return is_string($mime) && $mime !== '' ? strtolower($mime) : 'application/octet-stream';
}

function m2_archive_read_bytes($handle, int $length): string
{
    $data = '';
    while (strlen($data) < $length && !feof($handle)) {
        $chunk = fread($handle, $length - strlen($data));
        if ($chunk === false) throw new RuntimeException('Unable to read archive fingerprint sample');
        if ($chunk === '') break;
        $data .= $chunk;
    }
    if (strlen($data) !== $length) throw new RuntimeException('Archive fingerprint sample was incomplete');
    return $data;
}

function m2_archive_fingerprint(string $path, string $key, string $mime, int $size): string
{
    $firstLength = min(M2_ARCHIVE_SAMPLE_BYTES, $size);
    $lastStart = max($firstLength, $size - M2_ARCHIVE_SAMPLE_BYTES);
    $lastLength = $size - $lastStart;
    $metadata = json_encode([
        'protocol' => M2_ARCHIVE_FINGERPRINT_PROTOCOL,
        'key' => $key,
        'mime' => $mime,
        'size' => $size,
        'sampleBytes' => M2_ARCHIVE_SAMPLE_BYTES
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    $handle = fopen($path, 'rb');
    if ($handle === false) throw new RuntimeException('Unable to open archive fingerprint source');
    try {
        $context = hash_init('sha256');
        hash_update($context, $metadata);
        if ($firstLength > 0) hash_update($context, m2_archive_read_bytes($handle, $firstLength));
        if ($lastLength > 0) {
            if (fseek($handle, $lastStart) !== 0) throw new RuntimeException('Unable to seek archive fingerprint source');
            hash_update($context, m2_archive_read_bytes($handle, $lastLength));
        }
        return hash_final($context);
    } finally {
        fclose($handle);
    }
}

function m2_archive_url_path(string $root, string $path): string
{
    $relative = ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');
    return '/' . implode('/', array_map('rawurlencode', explode('/', $relative)));
}

function m2_archive_inventory(): array
{
    $root = realpath((string)$_SERVER['DOCUMENT_ROOT']);
    if ($root === false) throw new RuntimeException('Document root is unavailable');
    $allowed = '/\.(?:mp3|wav|mp4|webm|png|jpe?g|gif|svg|ico|woff2?|ttf|otf|css|js)$/i';
    $files = [];
    foreach (['/m/m', '/m/img', '/css/fonts', '/m/css/cursors'] as $relativeDirectory) {
        $directory = realpath($root . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory));
        if ($directory === false || !is_dir($directory)) continue;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;
            $path = $file->getRealPath();
            if ($path === false || preg_match($allowed, $path) !== 1) continue;
            $files[m2_archive_url_path($root, $path)] = $path;
        }
    }
    foreach (['/img/logomonochrome.ico', '/m/serv/npfp.png'] as $relativeFile) {
        $path = realpath($root . str_replace('/', DIRECTORY_SEPARATOR, $relativeFile));
        if ($path !== false && is_file($path)) $files[$relativeFile] = $path;
    }
    ksort($files, SORT_STRING);
    return $files;
}

function m2_refresh_archive_list(): void
{
    set_time_limit(0);
    $m2Root = __DIR__;
    $listPath = $m2Root . '/m2list.json';
    $indexPath = $m2Root . '/.m2-archive-fingerprints.json';
    if (is_file($listPath)) {
        $current = json_decode((string)file_get_contents($listPath), true);
        if (is_array($current) && ($current['v'] ?? null) === (int)M2_BROWSER_CACHE_VERSION &&
            is_array($current['list'] ?? null)) return;
    }
    $lock = fopen($indexPath . '.lock', 'c+');
    if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('Archive fingerprint index is unavailable');
    try {
        if (is_file($listPath)) {
            $current = json_decode((string)file_get_contents($listPath), true);
            if (is_array($current) && ($current['v'] ?? null) === (int)M2_BROWSER_CACHE_VERSION &&
                is_array($current['list'] ?? null)) return;
        }
        $index = [];
        if (is_file($indexPath)) {
            $decoded = json_decode((string)file_get_contents($indexPath), true);
            if (is_array($decoded)) $index = $decoded;
        }
        $nextIndex = [];
        $list = [];
        foreach (m2_archive_inventory() as $key => $path) {
            clearstatcache(true, $path);
            $size = filesize($path);
            $mtime = filemtime($path);
            if ($size === false || $mtime === false) continue;
            $mime = m2_archive_mime($path);
            $stored = is_array($index[$key] ?? null) ? $index[$key] : [];
            $valid = ($stored['protocol'] ?? null) === M2_ARCHIVE_FINGERPRINT_PROTOCOL &&
                ($stored['sampleBytes'] ?? null) === M2_ARCHIVE_SAMPLE_BYTES &&
                ($stored['size'] ?? null) === $size &&
                ($stored['mtime'] ?? null) === $mtime &&
                ($stored['mime'] ?? null) === $mime &&
                is_string($stored['fingerprint'] ?? null) &&
                preg_match('/^[a-f0-9]{64}$/', $stored['fingerprint']) === 1;
            if (!$valid) {
                $stored = [
                    'protocol' => M2_ARCHIVE_FINGERPRINT_PROTOCOL,
                    'sampleBytes' => M2_ARCHIVE_SAMPLE_BYTES,
                    'size' => $size,
                    'mtime' => $mtime,
                    'mime' => $mime,
                    'fingerprint' => m2_archive_fingerprint($path, $key, $mime, $size)
                ];
            }
            $nextIndex[$key] = $stored;
            $list[$key] = $stored['fingerprint'];
        }
        $encodedIndex = json_encode(
            $nextIndex,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $encodedList = json_encode(
            ['v' => (int)M2_BROWSER_CACHE_VERSION, 'list' => $list],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $currentIndex = is_file($indexPath) ? (string)file_get_contents($indexPath) : '';
        $currentList = is_file($listPath) ? (string)file_get_contents($listPath) : '';
        if ($currentIndex !== $encodedIndex && file_put_contents($indexPath, $encodedIndex, LOCK_EX) === false) {
            throw new RuntimeException('Unable to update archive fingerprint index');
        }
        if ($currentList !== $encodedList && file_put_contents($listPath, $encodedList, LOCK_EX) === false) {
            throw new RuntimeException('Unable to update archive list');
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

m2_refresh_archive_list();

ob_start('m2_transform_document');

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
$data_stmt = $pdo->prepare('SELECT category,link_id,title,lyrics,trans,location,url,is_yes,alt FROM song_links');

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
function substituteforanrealnormaliserfromforeigntextsaswellasbrokendatabaseentriesþoughmostlylanguagenaturalneedsonelychangingþemaparray($text)
{
    $text = (string)$text;

    $text = preg_replace_callback('/ss(\d{2})/i', function ($matches) {
        return '___STYLESET___' . $matches[1] . '___';
    }, $text);

    $protectedDiaereses = [];
    $text = preg_replace_callback('/\p{L}\x{0308}/u', function ($matches) use (&$protectedDiaereses) {
        $token = "\u{E000}" . count($protectedDiaereses) . "\u{E001}";
        $protectedDiaereses[$token] = $matches[0];
        return $token;
    }, $text) ?? $text;

    $map = [
        ' )' => ' )',
        ')' => ' )',
        '( ' => '( ',
        '(' => '( ',
        ' - ' => ' — ',
        '-' => '‑',
        '«' => '« ',
        '»' => ' »',
        '«  ' => '« ',
        '  »' => ' »',
        ' ,' => ',',
        ' ;' => ' ;',
        ';' => ' ;',
        ':' => ' :',
        ' ?' => ' ?',
        '?' => ' ?',
        '  ?' => ' ?',
        ' !' => ' !',
        '!' => ' !',
        '  ' => ' ',
        '  !' => ' !',
        ',' => ' ,',
        '  ,' => ' ,',
        '  :' => ' :',
        'ç' => 'č',
        'Ç' => 'Č',
        'ş' => 'ș',
        'Ş' => 'Ș',
        'ı' => 'i',
        'İ' => 'I',
        'ğ' => 'ă',
        'Ğ' => 'Ă',
        'ä' => 'aͤ',
        'Ä' => 'Ae',
        'ö' => 'oͤ',
        'Ö' => 'Oe',
        'ü' => 'uͤ',
        'Ü' => 'Ue',
        'ss' => 'ß',
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
        ' \'' => '‘',
        '\'' => '’',
    ];

    $applyMap = static function (string $part) use ($map): string {
        foreach ($map as $from => $to) {
            $part = str_replace($from, $to, $part);
        }
        $part = preg_replace('/[ \x{00A0}]+\x{00A0}/u', "\u{202F}", $part) ?? $part;
        $part = preg_replace('/[ \x{202F}]+\x{00A0}/u', "\u{202F}", $part) ?? $part;
        $part = preg_replace('/[ \x{00A0}]+\x{0020}/u', "\u{202F}", $part) ?? $part;
        $part = preg_replace('/\x{00A0}\x{202F}/u', "\u{202F}", $part) ?? $part;
        return $part;
    };

    if (strpos($text, '<') !== false && strpos($text, '>') !== false) {
        $text = preg_replace_callback('/(<[^>]*>)|([^<]+)/', function ($matches) use ($applyMap) {
            if (!empty($matches[1])) {
                return $matches[1];
            }
            return $applyMap($matches[2]);
        }, $text);
    } else {
        $text = $applyMap($text);
    }

    if ($protectedDiaereses) {
        $text = strtr($text, $protectedDiaereses);
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
        $part = substituteforanrealnormaliserfromforeigntextsaswellasbrokendatabaseentriesþoughmostlylanguagenaturalneedsonelychangingþemaparray($part);
        $part = convert_st_sp_word_starts($part);
        return $part;
    }, (string)$text);
}

function render_m2_lyrics(string $raw, string $style): string
{
    $srtToSec = static function (string $ts): float {
        preg_match('/(\d+):(\d+):(\d+)[,.](\d+)/', $ts, $match);
        return (int)$match[1] * 3600 + (int)$match[2] * 60 + (int)$match[3] + (float)('0.' . $match[4]);
    };
    $lines = [];
    $isSRT = (bool)preg_match('/\d{2}:\d{2}:\d{2}[,.]\d+\s*-->\s*\d{2}:\d{2}:\d{2}[,.]\d+/m', $raw);
    $isLRC = !$isSRT && (bool)preg_match('/^\[\d{2}:\d{2}\.\d+L?\]/m', $raw);

    if ($isLRC) {
        foreach (explode("\n", $raw) as $lrcLine) {
            $lrcLine = trim($lrcLine);
            $stamps = [];
            $text = preg_replace_callback(
                '/\[(\d{2}):(\d{2}\.\d+)(L?)\]/',
                static function (array $match) use (&$stamps): string {
                    $stamps[] = [(int)$match[1] * 60 + (float)$match[2], $match[3] === 'L'];
                    return '';
                },
                $lrcLine
            );
            $text = trim((string)$text);
            foreach ($stamps as [$time, $lyric]) $lines[] = [$time, $text, null, $lyric];
        }
    } elseif ($isSRT) {
        foreach (preg_split('/\r?\n\s*\r?\n/', trim($raw)) ?: [] as $block) {
            $blockLines = preg_split('/\r?\n/', trim($block)) ?: [];
            if (count($blockLines) < 2) continue;
            $timestampLine = preg_match('/-->/', $blockLines[0]) ? $blockLines[0] : ($blockLines[1] ?? '');
            if (!preg_match('/(\d{2}:\d{2}:\d{2}[,.]\d+)\s*-->\s*(\d{2}:\d{2}:\d{2}[,.]\d+)/', $timestampLine, $match)) continue;
            $start = $srtToSec($match[1]);
            $end = $srtToSec($match[2]);
            $lyric = preg_match('/^\s*L(?=\d{2}:\d{2}:\d{2}[,.]\d+\s*-->)/', $timestampLine) === 1;
            $textStart = preg_match('/-->/', $blockLines[0]) ? 1 : 2;
            $text = strip_tags(implode(' ', array_slice($blockLines, $textStart)));
            $lines[] = [$start, trim($text), $end, $lyric];
        }
    }

    ob_start();
    if ($lines) {
        usort($lines, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        foreach ($lines as $line) {
            $time = $line[0];
            $text = $line[1];
            $end = $line[2] ?? null;
            $lyric = $line[3] ?? false;
            if ($text === '' && !$isLRC) continue;
            $safe = htmlspecialchars(substituteforanrealnormaliserfromforeigntextsaswellasbrokendatabaseentriesþoughmostlylanguagenaturalneedsonelychangingþemaparray($text), ENT_QUOTES, 'UTF-8');
            $attributes = sprintf('data-t="%.3f"', $time);
            if ($end !== null) $attributes .= sprintf(' data-te="%.3f"', $end);
            if ($lyric) $attributes .= ' data-l="1"';
            $styleAttribute = $style !== '' ? sprintf(' style="%s"', htmlspecialchars($style, ENT_QUOTES, 'UTF-8')) : '';
            printf('<span class="lrcLine"%s %s data-txt="%s">%s</span>', $styleAttribute, $attributes, $safe, $safe);
        }
    } else {
        if ($style !== '') printf('<span style="%s">', htmlspecialchars($style, ENT_QUOTES, 'UTF-8'));
        echo nl2br(htmlspecialchars(substituteforanrealnormaliserfromforeigntextsaswellasbrokendatabaseentriesþoughmostlylanguagenaturalneedsonelychangingþemaparray($raw), ENT_QUOTES, 'UTF-8'));
        if ($style !== '') echo '</span>';
        echo '<div class="playHead"></div>';
    }
    return (string)ob_get_clean();
}

function normalize_m_song_link_text($text)
{
    $map = [
        'Tzsch' => 'Č', 'tzsch' => 'č',
        'Zsch'  => 'Č', 'zsch'  => 'č',
        'Tsch'  => 'Č', 'tsch'  => 'č',
        'TSCH'  => 'Č',
        'Sch' => 'Ș', 'sch' => 'ș',
        'SCH' => 'Ș',
        'Tz' => 'Ț', 'tz' => 'ț',
        'TZ' => 'Ț',
        'Z' => 'Ț', 'z' => 'ț'
    ];

    $text = (string)$text;
    if (strpos($text, '<') !== false && strpos($text, '>') !== false) {
        $text = preg_replace_callback('/(<[^>]*>)|([^<]+)/', function ($matches) use ($map) {
            if (!empty($matches[1])) return $matches[1];
            return strtr($matches[2], $map);
        }, $text);
    } else {
        $text = strtr($text, $map);
    }

    return convert_st_sp_word_starts($text);
}

function create_m_song_item_id(array $row): string
{
    $title = normalize_m_song_link_text($row['title'] ?? '');
    $category = normalize_m_song_link_text($row['category'] ?? '');
    $url = (string)($row['url'] ?? '');

    $bytes = unpack('C*', mb_convert_encoding($title, 'ISO-8859-1', 'UTF-8'));
    $digits = '';
    foreach ($bytes as $byte) {
        $digits .= ($byte * 186216) % 8;
    }
    $num = str_pad(substr($digits, -2), 2, '0');

    $combo = $category . '&' . $title;
    $letters = '';
    for ($i = 0; $i < 2; $i++) {
        $cp = mb_ord(mb_substr($combo, $i % mb_strlen($combo), 1, 'UTF-8'));
        $letters .= chr(65 + (($cp + 0xFA) % 26));
    }

    $id = (string)($row['link_id'] ?? '') . '_' . $num . substr($url, -5) . $letters;
    return preg_replace('/[^\x20-\x7E]/', '', $id);
}

function split_m2_title(string $title): array
{
    $title = trim($title);
    if (preg_match('/^\[([^\]]+)\]\s*(.+)$/us', $title, $matches)) {
        return ['sort' => trim($matches[1]), 'display' => trim($matches[2]), 'has_sort_key' => true];
    }
    if (preg_match('/^(<span[^>]*>)\s*\[([^\]]+)\]\s*(.+?)(<\/span>)$/usi', $title, $matches)) {
        return ['sort' => trim($matches[2]), 'display' => $matches[1] . trim($matches[3]) . $matches[4], 'has_sort_key' => true];
    }
    return ['sort' => $title, 'display' => $title, 'has_sort_key' => false];
}

foreach ($results as &$row) {
    $parsedTitle = split_m2_title((string)($row['title'] ?? ''));
    $row['sort_title'] = normalize_german($parsedTitle['sort']);
    $row['display_title'] = normalize_german($parsedTitle['display']);
    $row['sort_key_raw'] = $parsedTitle['has_sort_key'] ? $parsedTitle['sort'] : '';
    $row['title'] = $row['display_title'];
    $row['_m_song_item_id'] = create_m_song_item_id($row);
    $row['category'] = normalize_german($row['category']);
}
unset($row);

$fixþebrokenorderingofdefaultphpineedtomakeþisanklaßlatertobefairwellfornowweshallkeepusingþischangenotnameofþisvartwillbeanfunktionwiþtimejslaterwewillimportsotakeþisasantodoplease = [
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
    'dдþ',
    'eеэ',
    'żjж',
    'zз',
    'iи',
    'íyй',
    'kк',
    'lл',
    'mм',
    'nн',
    'oо',
    'pп',
    'rр',
    'sс',
    'tт',
    'fф',
    'țц',
    'čч',
    'șш',
    'c',
    'q',
    'x',
    'hх‘',
];

$charRanks = [];
foreach ($fixþebrokenorderingofdefaultphpineedtomakeþisanklaßlatertobefairwellfornowweshallkeepusingþischangenotnameofþisvartwillbeanfunktionwiþtimejslaterwewillimportsotakeþisasantodoplease as $rank => $chars) {
    if ((string)$chars !== '') {
        foreach (mb_str_split(mb_strtolower((string)$chars)) as $c) {
            $charRanks[$c] = $rank;
        }
    }
}

function stripLeadingTitleMarks($text)
{
    $text = (string)$text;
    return preg_replace('/^[\p{P}\p{Z}\s]+/u', '', $text) ?? $text;
}

function customStrCmp($str1, $str2, $ignoreLeadingTitleMarks = false)
{
    global $charRanks;

    $str1 = html_entity_decode(strip_tags((string)$str1), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $str2 = html_entity_decode(strip_tags((string)$str2), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    if (preg_match('/^\s*\[([^\]]+)\]/u', $str1, $m1)) {
        $str1 = $m1[1];
    }
    if (preg_match('/^\s*\[([^\]]+)\]/u', $str2, $m2)) {
        $str2 = $m2[1];
    }

    if ($ignoreLeadingTitleMarks) {
        $str1 = stripLeadingTitleMarks($str1);
        $str2 = stripLeadingTitleMarks($str2);
    }

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

    $titleCmp = customStrCmp($a['sort_title'] ?? $a['title'], $b['sort_title'] ?? $b['title'], true);
    if ($titleCmp !== 0) return $titleCmp;

    return customStrCmp($a['display_title'] ?? $a['title'], $b['display_title'] ?? $b['title'], true);
});

$cat_stmt = $pdo->query('SELECT DISTINCT category FROM song_links');
$cats = $cat_stmt->fetchAll(PDO::FETCH_COLUMN);
$cats = array_map('normalize_german', $cats);
$cats = array_unique($cats);
usort($cats, 'customStrCmp');
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Services for þe populace General et Private Musik applikation of Per.-Gen.-portal  nullpunkts as ver. 2 for General use.</title>
    <link rel="shortcut icon" href="<?= htmlspecialchars(m2_versioned_asset('/img/logomonochrome.ico'), ENT_QUOTES, 'UTF-8') ?>" type="image/x-icon">
    <style>
        @font-face {
            font-family: junicode;
            src: url('<?= htmlspecialchars(m2_versioned_asset('/css/fonts/JunicodeVF-Roman.woff2'), ENT_QUOTES, 'UTF-8') ?>');
        }

        @font-face {
            font-family: junicode;
            src: url('<?= htmlspecialchars(m2_versioned_asset('/css/fonts/JunicodeVF-Italic.woff2'), ENT_QUOTES, 'UTF-8') ?>');
            font-style: italic;
        }

        @font-face {
            font-family: 'BabelStone Han';
            src: url('<?= htmlspecialchars(m2_versioned_asset('/m/img/BabelStoneHan.ttf'), ENT_QUOTES, 'UTF-8') ?>');
        }

        @font-face {
            font-family: nullpunktsenergie;
            src: url('<?= htmlspecialchars(m2_versioned_asset('/css/fonts/nullpunktsenergiefont-Regular.ttf'), ENT_QUOTES, 'UTF-8') ?>');
        }
        @font-face {
            font-family: frank;
            src: url('<?= htmlspecialchars(m2_versioned_asset('/css/fonts/FrankRuhlLibre-VariableFont_wght.ttf'), ENT_QUOTES, 'UTF-8') ?>');
        }

        @font-face {
            font-family: anyocr;
            src: url('<?= htmlspecialchars(m2_versioned_asset('/css/fonts/BoeingCDU-Large.ttf'), ENT_QUOTES, 'UTF-8') ?>');
        }

        @counter-style ca {
            system: alphabetic;
            prefix: " ( ";
            symbols: "a" "b" "ú" "u" "ù" "g" "d" "e" "ż" "z" "i" "í" "k" "l" "m" "n" "o" "p" "r" "s" "t" "f" "ț" "č" "ș" "h";
            suffix: " ) ";
            fallback: lower-alpha;
        }

        :root {
            --page-font-stack: 'Junicode', 'nullpunktsenergiefont', 'frank',  'Amiri', serif;
        }

        body.babelstone-ready {
            --page-font-stack: 'Junicode', 'nullpunktsenergiefont', 'BabelStone Han', 'frank',  'Amiri', serif;
        }

        *:not(.katex):not(.katex *) {
            font-family: var(--page-font-stack);
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
            --m2-cursor-default: url('<?= htmlspecialchars(m2_versioned_asset('/m/css/cursors/MOUSE_DEFAULT_ARROW.png'), ENT_QUOTES, 'UTF-8') ?>') 4 2, auto;
            --m2-cursor-pointer: url('<?= htmlspecialchars(m2_versioned_asset('/m/css/cursors/MOUSE_POINTING_HAND.png'), ENT_QUOTES, 'UTF-8') ?>') 10 3, pointer;
            --m2-cursor-grab: url('<?= htmlspecialchars(m2_versioned_asset('/m/css/cursors/MOUSE_GRABBING_HAND_OPEN.png'), ENT_QUOTES, 'UTF-8') ?>') 15 17, grab;
            --m2-cursor-grabbing: url('<?= htmlspecialchars(m2_versioned_asset('/m/css/cursors/MOUSE_GRABBING_HAND_CLOSED.png'), ENT_QUOTES, 'UTF-8') ?>') 15 15, grabbing;
            --m2-cursor-curvy-left: url('<?= htmlspecialchars(m2_versioned_asset('/m/css/cursors/MOUSE_BIG_CURVY_ARROW_LEFT.png'), ENT_QUOTES, 'UTF-8') ?>') 20 10, pointer;
            --m2-cursor-curvy-right: url('<?= htmlspecialchars(m2_versioned_asset('/m/css/cursors/MOUSE_BIG_CURVY_ARROW_RIGHT.png'), ENT_QUOTES, 'UTF-8') ?>') 8 10, pointer;
            --m2-cursor-small-curvy-left: url('<?= htmlspecialchars(m2_versioned_asset('/m/css/cursors/MOUSE_SMALL_CURVY_ARROW_LEFT.png'), ENT_QUOTES, 'UTF-8') ?>') 20 10, pointer;
            --m2-cursor-small-curvy-right: url('<?= htmlspecialchars(m2_versioned_asset('/m/css/cursors/MOUSE_SMALL_CURVY_ARROW_RIGHT.png'), ENT_QUOTES, 'UTF-8') ?>') 8 10, pointer;
            --m2-cursor-upside-down-curvy-left: url('<?= htmlspecialchars(m2_versioned_asset('/m/css/cursors/MOUSE_UPSIDEDOWN_CURVY_ARROW_LEFT.png'), ENT_QUOTES, 'UTF-8') ?>') 20 10, pointer;
            --m2-cursor-upside-down-curvy-right: url('<?= htmlspecialchars(m2_versioned_asset('/m/css/cursors/MOUSE_UPSIDEDOWN_CURVY_ARROW_RIGHT.png'), ENT_QUOTES, 'UTF-8') ?>') 8 9, pointer;
            --m2-cursor-switch-left: url('<?= htmlspecialchars(m2_versioned_asset('/m/css/cursors/MOUSE_LEFT_ARROW.png'), ENT_QUOTES, 'UTF-8') ?>') 29 7, pointer;
            --m2-cursor-switch-right: url('<?= htmlspecialchars(m2_versioned_asset('/m/css/cursors/MOUSE_RIGHT_ARROW.png'), ENT_QUOTES, 'UTF-8') ?>') 2 7, pointer;
            --m2-cursor-switch-both: url('<?= htmlspecialchars(m2_versioned_asset('/m/css/cursors/MOUSE_LEFT_RIGHT_ARROW.png'), ENT_QUOTES, 'UTF-8') ?>') 15 15, pointer;
        }

        body {
            --m2-song-left-edge: 60px;
            --m2-song-right-edge: 130px;
            background: black;
            color: white;
            margin: 0;
            padding-right: 130px;
            padding-left: 60px;
            box-sizing: border-box;
            overflow-x: hidden;
            cursor: var(--m2-cursor-default)
        }

        a[href],
        button:not(:disabled),
        [role="button"] {
            cursor: var(--m2-cursor-pointer);
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
        #seekRadial,
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
            cursor: var(--m2-cursor-pointer);
            font-style: italic;
            font-size: 1.1em;
            border: 1px solid white;
            user-select: none;
            box-sizing: border-box
        }

        #songList {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    border-left: 1px solid white;
    border-bottom: 1px solid white;
            gap: 15px;
            padding: 10px
        }

        .cardWrap {
            display: flex;
            flex-direction: row;
            cursor: var(--m2-cursor-pointer)
        }

        .cSide {
            cursor: var(--m2-cursor-default);
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
            cursor: var(--m2-cursor-pointer);
            color: white;
            text-decoration: none
        }

        .mLyricsToggle {
            border: 0;
            background: transparent;
            padding: 0;
            font: inherit;
            line-height: inherit
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
            cursor: var(--m2-cursor-pointer)
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
            cursor: var(--m2-cursor-pointer);
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
            cursor: var(--m2-cursor-pointer);
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
            hyphens: none;
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
            width: 97px;
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
            cursor: var(--m2-cursor-grab);
            transform-origin: center;
            transition: transform .16s ease;
            touch-action: none;
        }

        #rBar #lBtn.lPopped {
            transform: scale(1.4);
        }

        #rBar #lBtn.lInactive {
            cursor: var(--m2-cursor-pointer);
        }

        #rBar #lBtn.m2CursorGrabbing {
            cursor: var(--m2-cursor-grabbing);
            transition: none;
        }

        #rBar button,
        #rBar a {
            background: none;
            border: none;
            color: white;
            cursor: var(--m2-cursor-pointer);
            font-family: inherit;
            font-size: 1.2em;
            text-decoration: none;
            display: block;
            text-align: center
        }


        #speedTime,
        #revTime {
            font-size: .6em;
            color: white;
            margin: 2px 0;
            pointer-events: none;
            text-align: center
        }

        #speedWrap,
        #revWrap {
            height: 80px;
            flex-shrink: 0;
            width: 3px;
            background: rgba(255, 255, 255, .2);
            margin: 4px 0;
            position: relative;
            cursor: var(--m2-cursor-pointer);
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

        #speedFill,
        #revFill {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 0%;
            background: white
        }

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
            cursor: var(--m2-cursor-pointer);
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
            cursor: var(--m2-cursor-pointer)
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
            cursor: var(--m2-cursor-pointer);
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

        #seekRadial {
            display: block;
            width: 98%;
            height: auto;
            flex: 0 0 auto;
            margin: 0 auto;
            overflow: visible;
            touch-action: none;
            user-select: none;
            font-family: Arial, Helvetica, sans-serif;
            filter: drop-shadow(0 13px 22px rgba(0, 0, 0, .55));
        }

        .seekRadialBezelOuter {
            fill: #121412;
            stroke: #626660;
            stroke-width: 5;
        }

        .seekRadialBezelInner {
            fill: #060706;
            stroke: #252825;
            stroke-width: 3;
        }

        .seekRadialGlass {
            fill: url(#seekRadialGlassShade);
            stroke: rgba(235, 239, 231, .18);
            stroke-width: 1.5;
        }

        .seekRadialOuterTick,
        .seekRadialInnerTick {
            stroke: #f2f1e9;
            stroke-linecap: square;
            pointer-events: none;
        }

        .seekRadialOuterTick.minor {
            stroke-width: 1.3;
            opacity: .8;
        }

        .seekRadialOuterTick.major {
            stroke-width: 3.2;
        }

        .seekRadialInnerTick {
            stroke-width: 1.7;
        }

        .seekRadialInnerTick.major {
            stroke-width: 2.7;
        }

        .seekRadialDialNumber {
            fill: #f1f0e8;
            font-size: 22px;
            font-weight: 700;
            text-anchor: middle;
            dominant-baseline: middle;
            pointer-events: none;
        }

        .seekRadialInnerNumber {
            font-size: 16px;
        }

        .seekRadialControl {
            cursor: var(--m2-cursor-pointer);
            outline: none;
        }

        .seekRadialControl:focus-visible .seekRadialGlass {
            stroke: #fff;
            stroke-width: 2.5;
        }

        .seekRadialHit {
            fill: transparent;
            pointer-events: all;
        }

        .seekRadialTargetHand {
            fill: #f2f1e9;
            stroke: #bfc1bb;
            stroke-width: .8;
            filter: url(#seekRadialHandShadow);
            pointer-events: none;
        }

        .seekRadialSectionHand {
            fill: #d8d8d1;
            stroke: #9d9f99;
            stroke-width: .8;
            filter: url(#seekRadialHandShadow);
            pointer-events: none;
        }

        .seekRadialHubOuter {
            fill: #202220;
            stroke: #656862;
            stroke-width: 2;
            pointer-events: none;
        }

        .seekRadialHubInner {
            fill: #090a09;
            stroke: #2c2e2b;
            stroke-width: 1.5;
            pointer-events: none;
        }

        .seekRadialDisplayLabel {
            fill: #f3f3ed;
            font-size: 40px;
            font-weight: 700;
            letter-spacing: .14em;
            text-anchor: middle;
        }

        .lts2x01Segment,
        .lts2x01Indicator {
            fill: #f7f8f4;
        }

        .lts2x01Segment.is-off,
        .lts2x01Indicator.is-off {
            fill: #151715;
        }

        .seekRadialSet {
            cursor: var(--m2-cursor-pointer);
            outline: none;
        }

        .seekRadialButtonFace {
            fill: #494949;
            stroke: #343434;
            stroke-width: 1.4;
        }

        .seekRadialButtonCap {
            fill: #3b3b3b;
            stroke: #686868;
            stroke-width: 1;
        }

        .seekRadialButtonCopy {
            fill: #fff;
            font-family: Arial, Helvetica, sans-serif !important;
            font-size: 24px;
            font-weight: 700;
            text-anchor: middle;
            pointer-events: none;
        }

        .seekRadialSet:hover .seekRadialButtonCap,
        .seekRadialSet:focus-visible .seekRadialButtonCap {
            stroke: #f1f1ed;
        }

        .seekRadialSet.is-pressed .seekRadialButtonCap {
            fill: #202020;
        }

        .seekRadialKnobControl {
            cursor: var(--m2-cursor-pointer);
            outline: none;
        }

        .seekRadialKnobControl:focus-visible .seekRadialTuningOuterRing,
        .seekRadialKnobControl:focus-visible .seekRadialTuningInnerRing {
            stroke: #fff;
        }

        .seekRadialTuningOuterRing {
            fill: #b4b6b4;
            stroke: #d6d8d6;
            stroke-width: 1.5;
        }

        .seekRadialTuningOuterBase {
            fill: #666866;
            stroke: #858785;
            stroke-width: 1.5;
            pointer-events: none;
        }

        .seekRadialTuningInnerRing {
            fill: #a4a6a4;
            stroke: #d5d7d5;
            stroke-width: 1.5;
        }

        .seekRadialTuningInnerBase {
            fill: #707270;
            stroke: #8c8e8c;
            stroke-width: 1.5;
            pointer-events: none;
        }

        .seekRadialTuningHit {
            fill: transparent;
        }

        #rBar {
            --knob-h: 27px;
            --swh: calc(var(--knob-h) * 2.3);
            --msw: calc(var(--swh) * 64 / 165);
        }

        .mKnob {
            height: var(--knob-h);
            aspect-ratio: 102 / 115;
            background: url('<?= htmlspecialchars(m2_versioned_asset('/m/img/knob.png'), ENT_QUOTES, 'UTF-8') ?>') center / contain no-repeat;
            transform-origin: 49.5% 55.2%;
            transform: rotate(0deg);
            cursor: var(--m2-cursor-pointer);
            touch-action: none;
            margin: 4px auto;
        }

        .mKnob2 {
            width: var(--knob-h);
            height: var(--knob-h);
            background: url('<?= htmlspecialchars(m2_versioned_asset('/m/img/rotate.png'), ENT_QUOTES, 'UTF-8') ?>') center / contain no-repeat;
            transform-origin: 50% 50%;
            transform: rotate(0deg);
            cursor: var(--m2-cursor-pointer);
            touch-action: none;
            margin: 4px auto;
        }

        .mKnobsWrap {
            height: calc(var(--knob-h) * 255 / 115);
            aspect-ratio: 226 / 255;
            background: url('<?= htmlspecialchars(m2_versioned_asset('/m/img/knobswrap.png'), ENT_QUOTES, 'UTF-8') ?>') center / contain no-repeat;
            position: relative;
            margin: 4px auto;
        }

        .mKnobsWrap .mKdial {
            position: absolute;
            left: 50%;
            top: 50%;
            height: var(--knob-h);
            aspect-ratio: 102 / 115;
            background: url('<?= htmlspecialchars(m2_versioned_asset('/m/img/knob.png'), ENT_QUOTES, 'UTF-8') ?>') center / contain no-repeat;
            transform-origin: 49.5% 55.2%;
            transform: translate(-49.5%, -55.2%) rotate(0deg);
            cursor: var(--m2-cursor-pointer);
            touch-action: none;
        }


        .mSwGroup {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 3px;
            margin: 4px 0;
            width: var(--swh);
        }

        .mDcAmpsDisplay {
            display: block;
            width: var(--swh);
            margin: 0 0 1px;
            overflow: visible;
            pointer-events: none;
            user-select: none;
        }

        .mDcAmpsDisplay > svg {
            display: block;
            width: 100%;
            height: auto;
            overflow: visible;
        }

        .mSwitchCol {
            width: var(--swh);
        }

        .mKControlRow {
            position: relative;
            width: var(--swh);
            height: calc(var(--knob-h) + 12pt + 1px);
        }

        .mKControlRow > .mCol:first-child {
            position: absolute;
            left: 0;
            top: 0;
            width: var(--knob-h);
        }

        .mKControlRow > .mSwitchCol {
            position: absolute;
            right: 0;
            top: 0;
            pointer-events: none;
        }

        .mKControlRow > .mSwitchCol .mSwimg {
            pointer-events: auto;
        }

        .mSwitchCol.mRightOnly .mSwSlot .mSw {
            left: 67%;
        }

        .mSwitchCol.mRightOnly .mLbl {
            transform: translateX(calc(var(--swh) * .17));
        }

        .mKControlFace {
            height: var(--knob-h);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .mKControlFace .mKnob2 {
            margin: 0;
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
            cursor: var(--m2-cursor-pointer);
            touch-action: none;
            user-select: none;
        }

        .mBtn {
            height: var(--knob-h);
            width: auto;
            cursor: var(--m2-cursor-pointer);
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
            cursor: var(--m2-cursor-pointer)
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
            cursor: var(--m2-cursor-pointer);
            transition: all 0.2s;
        }

        .cLeft img:hover {
            transform: scale(1.5);
            z-index: 10;
        }

        .mob-copy-btn,
        .mob-com-btn {
            display: none !important;
        }


        @media (max-width: 700px) {
            body {
                --m2-song-left-edge: 0px;
                padding-right: 130px;
                padding-left: 0;
            }


            .tBar {
                padding: 4px 8px;
            }

            #uliverie,
            #umusik2,
            #oyrslogo {
                display: none;
                visibility: hidden;
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
                cursor: var(--m2-cursor-pointer);
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
                cursor: var(--m2-cursor-pointer);
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
        body.loading-overlay-active {
            overflow: hidden !important;
        }

        #m2StrobeField {
            position: fixed;
            inset: 0;
            z-index: 100001;
            pointer-events: none;
            user-select: none;
        }

        .m2Strobe {
            position: absolute;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            opacity: 0;
            transform: translate(-50%, -50%);
            background: currentColor;
        }

        .m2Strobe.is-lit {
            opacity: 1;
            box-shadow:
                0 0 3px 2px currentColor,
                0 0 10px 5px currentColor,
                0 0 22px 9px currentColor;
        }

        .m2StrobeTop {
            left: 50%;
            top: 5px;
            color: #ff1b12;
        }

        .m2StrobeLeft {
            left: var(--m2-song-left-edge);
            top: 50%;
            color: #ffffff;
        }

        .m2StrobeRight {
            right: var(--m2-song-right-edge);
            top: 50%;
            color: #ffffff;
            transform: translate(50%, -50%);
        }

        .m2StrobeBottomLeft {
            left: var(--m2-song-left-edge);
            bottom: 5px;
            color: #2dff46;
            transform: translate(-50%, 50%);
        }

        .m2StrobeBottomRight {
            right: var(--m2-song-right-edge);
            bottom: 5px;
            color: #ff1b12;
            transform: translate(50%, 50%);
        }

        .pageBusControl {
            position: relative;
            --knob-h: 27px;
            --readie-blue: #2095e8;
            --display-green: #78ff63;
            --display-amber: #ff9f32;
            width: min(230px, calc(100vw - 32px));
            user-select: none;
            outline: none;
        }

        .pageBusDial {
            position: relative;
            width: calc(var(--knob-h) * 226 / 115);
            height: calc(var(--knob-h) * 255 / 115);
            aspect-ratio: 226 / 255;
            margin-inline: auto;
            background: url('<?= htmlspecialchars(m2_versioned_asset('/m/img/kboswrapforalign.png'), ENT_QUOTES, 'UTF-8') ?>') center / contain no-repeat;
        }

        .pageBusKnob {
            position: absolute;
            left: 50%;
            top: 50%;
            width: auto;
            height: var(--knob-h);
            transform: translate(-49.5%, -55.2%) rotate(0deg);
            transform-origin: 50% 50%;
            cursor: var(--m2-cursor-pointer);
            touch-action: none;
        }

        .mKnob.m2CursorLeft,
        .mKnob2.m2CursorLeft,
        .mKnobsWrap .mKdial.m2CursorLeft,
        .pageBusKnob.m2CursorLeft {
            cursor: var(--m2-cursor-curvy-left);
        }

        .mKnob.m2CursorRight,
        .mKnob2.m2CursorRight,
        .mKnobsWrap .mKdial.m2CursorRight,
        .pageBusKnob.m2CursorRight {
            cursor: var(--m2-cursor-curvy-right);
        }

        #seekRadialSectionKnob.m2CursorLeft {
            cursor: var(--m2-cursor-upside-down-curvy-left);
        }

        #seekRadialSectionKnob.m2CursorRight {
            cursor: var(--m2-cursor-upside-down-curvy-right);
        }

        #seekRadialTimeKnob.m2CursorLeft {
            cursor: var(--m2-cursor-small-curvy-left);
        }

        #seekRadialTimeKnob.m2CursorRight {
            cursor: var(--m2-cursor-small-curvy-right);
        }

        .mKnob.dragging,
        .mKnob2.dragging,
        .mKnob.m2CursorGrabbing,
        .mKnob2.m2CursorGrabbing,
        #seekRadialSectionKnob.m2CursorGrabbing,
        #seekRadialTimeKnob.m2CursorGrabbing {
            cursor: var(--m2-cursor-grabbing);
        }

        .mSwimg[data-m2-switch-cursor="left"] {
            cursor: var(--m2-cursor-switch-left);
        }

        .mSwimg[data-m2-switch-cursor="right"] {
            cursor: var(--m2-cursor-switch-right);
        }

        .mSwimg[data-m2-switch-cursor="both"] {
            cursor: var(--m2-cursor-switch-both);
        }

        .pageBusLight {
            display: block;
            width: calc(var(--knob-h) * 194 / 115);
            height: auto;
            margin: 1% auto 0;
            pointer-events: none;
            transition: filter 120ms linear;
        }

        .pageBusLight.is-on {
            filter:
                brightness(1.16)
                drop-shadow(0 0 2px var(--readie-blue))
                drop-shadow(0 0 6px var(--readie-blue))
                drop-shadow(0 0 13px rgba(32, 149, 232, 0.82));
        }

        .pageBusInstrument {
            box-sizing: border-box;
            width: 100%;
            margin: 8px auto 0;
            padding: 7px 10px 9px;
            color: var(--display-amber);
            background: #465158;
            border: 2px solid #313b41;
            border-radius: 2px;
            box-shadow:
                inset 0 1px 0 rgba(255, 255, 255, 0.1),
                inset 0 -1px 0 rgba(0, 0, 0, 0.28),
                0 2px 6px rgba(0, 0, 0, 0.35);
            font-family: "Arial Narrow", "Roboto Condensed", Arial, sans-serif;
        }

        .pageBusInstrumentLabels,
        .pageBusInstrumentValues {
            display: grid;
            align-items: center;
            text-align: center;
        }

        .pageBusInstrumentLabels {
            font-size: 9px;
            font-weight: 700;
            line-height: 1;
            letter-spacing: 0.35px;
            text-shadow:
                0 0 2px var(--display-amber),
                0 0 6px rgba(255, 159, 50, 0.55);
        }

        .pageBusInstrumentLabels.top,
        .pageBusInstrumentValues.top {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .pageBusInstrumentLabels.bottom,
        .pageBusInstrumentValues.bottom {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .pageBusInstrumentLabels.top {
            margin-bottom: 5px;
        }

        .pageBusInstrumentLabels.bottom {
            margin-top: 5px;
        }

        .pageBusInstrumentScreen {
            position: relative;
            height: 74px;
            overflow: hidden;
            background:
                #07100d;
            border: 2px solid #222b2f;
            border-radius: 9px;
            box-shadow:
                inset 0 0 0 1px rgba(255, 255, 255, 0.04),
                inset 0 0 13px rgba(0, 0, 0, 0.95);
        }

        .pageBusInstrumentValues {
            position: absolute;
            left: 6px;
            right: 6px;
            color: var(--display-green);
            font-family: "Courier New", monospace;
            font-size: 16px;
            font-weight: 700;
            line-height: 1;
            letter-spacing: -0.5px;
            font-variant-numeric: tabular-nums;
            text-shadow:
                0 0 2px var(--display-green),
                0 0 7px rgba(120, 255, 99, 0.72);
        }

        .pageBusInstrumentValues.top {
            top: 11px;
        }

        .pageBusInstrumentValues.bottom {
            bottom: 10px;
            font-size: 14px;
        }

        .pageBusInstrument.is-fault .pageBusInstrumentValues {
            color: var(--display-amber);
            text-shadow:
                0 0 2px var(--display-amber),
                0 0 7px rgba(255, 159, 50, 0.72);
        }

        .tJump {
            overscroll-behavior: contain;
        }

        #mUnderPanel {
            width: 100%;
            margin: 16px 0 24px;
            padding: 0;
            border: 0;
            background: #000;
            color: #fff;
        }

        #mCircuitBreakerPanelTitle {
            width: 50%;
            margin: 0 0 10px;
            box-sizing: border-box;
            color: #fff;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 16px;
            font-weight: 700;
            line-height: 1;
            letter-spacing: 0.8px;
            text-align: center;
        }

        .mUnderPanelDisplay {
            display: flex;
            width: 100%;
            align-items: flex-start;
        }

        #mCircuitBreakerBoard {
            flex: 0 0 100%;
            width: 100%;
            min-width: 0;
            background: #000;
        }

        .mUnderPanelFuture {
            display: none;
        }

        .mCircuitBreakerTier {
            display: flex;
            width: max-content;
            max-width: 100%;
            min-width: 0;
            flex-wrap: wrap;
            align-items: stretch;
        }

        .mCircuitBreakerGroup {
            flex: 0 0 auto;
            width: max-content;
            max-width: 100%;
            min-width: 0;
            margin: 0;
            margin-top: 10px;
            padding: 8px 4px 5px;
            border: 1px solid #fff;
            background: #000;
        }

#mCircuitBreakerBoard>.mCircuitBreakerGroup {
    width: 50%;
    display: flex;
    box-sizing: border-box;
}

        .mCircuitBreakerGroup+.mCircuitBreakerGroup {
            border-left: 0;
        }

        .mCircuitBreakerGroup legend {
            width: auto;
            margin: 0 auto;
            padding: 0 5px;
            color: #fff;
            background: #000;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 12px;
            font-weight: 700;
            line-height: 1;
            letter-spacing: 0.45px;
            text-align: center;
            white-space: nowrap;
        }

        .mCircuitBreakerCells {
            display: flex;
            max-width: 100%;
            overflow: visible;
            flex-wrap: wrap;
            align-items: stretch;
            align-content: flex-start;
            justify-content: flex-start;
            gap: 0;
        }

        .mCircuitBreaker {
            display: flex;
            flex: 0 0 96px;
            width: 96px;
            min-width: 0;
            padding: 8px 5px 7px;
            border: 1px solid #fff;
            box-sizing: border-box;
            appearance: none;
            align-items: center;
            flex-direction: column;
            background: #000;
            color: #fff;
            cursor: var(--m2-cursor-pointer);
            font-family: Arial, Helvetica, sans-serif;
            text-align: center;
        }

        .mCircuitBreaker[data-breaker-position="center"],
        .mCircuitBreaker[data-breaker-position="right"] {
            border-left: 0;
        }

        .mCircuitBreaker[data-breaker-row]:not([data-breaker-row="0"]) {
            margin-top: -1px;
        }

        .mCircuitBreakerSvg {
            display: block;
            width: auto;
            height: 100px;
            overflow: visible;
        }

        .mCircuitBreakerSvg .mCircuitBreakerBar {
            fill: #515655;
        }

        .mCircuitBreaker[data-breaker-lamp="on"] .mCircuitBreakerBar {
            fill: #fff86a;
            filter: var(--m-circuit-breaker-glow);
        }

        .mCircuitBreaker:focus-visible {
            outline: 1px solid #fff;
            outline-offset: -4px;
        }

        .mCircuitBreakerDesignation {
            margin-top: 6px;
            font-size: 16px;
            font-weight: 700;
            line-height: 1;
            letter-spacing: 0.25px;
            overflow-wrap: anywhere;
        }

        .mCircuitBreakerName {
            margin-top: 4px;
            color: #fff;
            font-size: 12px;
            font-weight: 700;
            line-height: 1.12;
            letter-spacing: 0.08px;
            overflow-wrap: anywhere;
        }

        #mTerminal {
            display: flex;
            width: max-content;
            max-width: 100%;
            min-width: 0;
            margin: 0 0 24px;
            padding: 12px 20px;
            align-items: flex-start;
            gap: 34px;
            background: #000;
            color: #fff;
            border: 1px solid #fff;
            font-size: 16px;
            overflow-x: auto;
            user-select: none;
            -webkit-user-select: none;
        }

        #mTerminal,
        #mTerminal * {
            font-family: anyocr, monospace;
        }

        .mTerminalConsole {
            display: grid;
            grid-template-columns: 38px minmax(176px, 342px) 38px;
            align-items: start;
            gap: 14px;
            flex: 0 0 auto;
        }

        .mTerminalDashColumn {
            display: grid;
            height: 356px;
            box-sizing: border-box;
            padding-top: calc(356px / 14);
            grid-template-rows: repeat(6, calc(356px / 14 * 2));
            align-content: start;
        }

        .mLSK,
        .mTerminalKey {
            min-width: 0;
            border: 1px solid #fff;
            border-radius: 2px;
            background: #000;
            color: #fff;
            cursor: var(--m2-cursor-pointer);
            font: inherit;
            line-height: 0;
        }

        .mLSK {
            width: 38px;
            display: inline-flex;
            height: 24px;
            padding: 0px;
            align-self: end;
            margin-top: 0;
            font-size: 2.2em;
            line-height: 0.4;
            padding-left: 8px;
            transition: transform .08s ease;
        }

        .mLSK:active {
            transform: translateY(1px);
        }

        #mTerminalScreen {
            display: grid;
            min-width: 0;
            height: 356px;
            padding: 3px;
            grid-template-columns: repeat(24, minmax(0, 1fr));
            grid-template-rows: repeat(14, minmax(0, 1fr));
            border: 1px solid #fff;
            background: #000;
            overflow: hidden;
        }

        .mTerminalCell {
            display: flex;
            min-width: 0;
            min-height: 0;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            color: #fff;
            font-size: 1.5em;
            line-height: 1;
            white-space: pre;
        }

        .mTerminalCell.is-small {
            font-size: .85em;
        }

        .mTerminalCell.is-green {
            color: #00ff00;
        }

        .mTerminalCell.is-amber {
            color: yellow;
        }

        .mTerminalCell.is-red {
            color: red;
        }

        .mTerminalCell.is-gray {
            color: gray;
        }

        .mTerminalCell.is-cyan {
            color: cyan;
        }

        .mTerminalCell.is-magenta {
            color: magenta;
        }

        .mTerminalCell.is-inverted {
            color: #000;
            background: #fff;
        }

        .mTerminalCell.is-flashing {
            animation: mTerminalFlash 1s steps(1, end) infinite;
        }

        @keyframes mTerminalFlash {
            50% {
                visibility: hidden;
            }
        }

        .mTerminalKeys {
            display: flex;
            flex: 0 0 auto;
            align-items: flex-start;
            gap: 30px;
        }

        .mTerminalKeyGrid {
            display: grid;
            grid-template-columns: repeat(5, 46px);
            column-gap: 10px;
            row-gap: 16px;
        }

        .mTerminalNumeralSection {
            display: flex;
            flex-direction: column;
        }

        .mTerminalSpecialisenBox {
            display: grid;
            width: 270px;
            height: 176px;
            box-sizing: border-box;
            grid-template-columns: repeat(5, 46px);
            column-gap: 5.6px;
            row-gap: 10px;
            align-content: start;
            margin-top: 16px;
            padding: 8px;
            border: 1px solid #fff;
        }

        .mTerminalSpecialButton,
        .mTerminalSave {
            min-width: 0;
            height: 46px;
            border: 1px solid #fff;
            border-radius: 2px;
            background: #000;
            color: #fff;
            cursor: var(--m2-cursor-pointer);
            font: inherit;
            line-height: 0;
        }

        .mTerminalSpecialButton {
            width: 46px;
            transition: transform .08s ease;
        }

        .mTerminalSpecialButton:active {
            transform: translateY(1px);
        }

        .mTerminalSave {
            position: relative;
            grid-column: span 2;
            transition: transform .08s ease;
        }

        .mTerminalSave:active {
            transform: translateY(1px);
        }

        .mTerminalSaveLight {
            position: absolute;
            right: 8px;
            bottom: 7px;
            left: 8px;
            height: 2px;
            background: #000;
        }

        .mTerminalSave.is-saving .mTerminalSaveLight {
            background: #fff;
            box-shadow: 0 0 8px #fff;
        }

        .mTerminalKey {
    width: 46px;
    display: inline-flex;
    height: 46px;
    padding: 0px;
    line-height: 2;
    padding-left: 17px;
    font-size: 1.25em;
    transition: transform .08s ease;
        }
.mTerminalNumeralSection > .mTerminalKeyGrid > .mTerminalKey {
    width: 2.2em;
    height: 2.2em;
}
        .mTerminalKey:active {
            transform: translateY(1px);
        }

        .mTerminalNumeralSection .mTerminalKey {
            border-radius: 50%;
        }

        .mTerminalKeyWord { 
    padding-left: 12px;
        }

        @media (max-width: 900px) {
            #mTerminal {
                padding: 16px;
            }
        }

    </style>
</head>

<body class="loading-overlay-active">
    <div id="m2StrobeField" aria-hidden="true">
        <span class="m2Strobe m2StrobeTop" data-strobe="top"></span>
        <span class="m2Strobe m2StrobeLeft" data-strobe="left"></span>
        <span class="m2Strobe m2StrobeRight" data-strobe="right"></span>
        <span class="m2Strobe m2StrobeBottomLeft" data-strobe="bottomLeft"></span>
        <span class="m2Strobe m2StrobeBottomRight" data-strobe="bottomRight"></span>
    </div>
    <div id="loaderOverlay" style="position:fixed;inset:0;background:black;z-index:99999;display:flex;align-items:center;justify-content:center;">
        <div id="pageBusControl" class="pageBusControl" role="application" tabindex="0" aria-label="Page control bus selector" aria-busy="true">
            <div class="pageBusDial">
                <img id="pageBusKnob" class="pageBusKnob" src="<?= htmlspecialchars(m2_versioned_asset('/m/img/knob.png'), ENT_QUOTES, 'UTF-8') ?>" draggable="false" alt="">
            </div>
            <img id="pageBusLight" class="pageBusLight" src="<?= htmlspecialchars(m2_versioned_asset('/m/img/lightforalignoff.png'), ENT_QUOTES, 'UTF-8') ?>" draggable="false" alt="READIE">
            <div id="pageBusInstrument" class="pageBusInstrument" role="group" aria-label="Page control electrical instruments">
                <div class="pageBusInstrumentLabels top" aria-hidden="true">
                    <span data-bus-signal="dcAmps">DC AMPS</span>
                    <span>DOWN‑LOAD %</span>
                </div>
                <div class="pageBusInstrumentScreen" aria-live="polite">
                    <div class="pageBusInstrumentValues top">
                        <output data-bus-meter="dcAmps">0.000</output>
                        <output data-bus-meter="archivePercent">0.0</output>
                    </div>
                    <div class="pageBusInstrumentValues bottom">
                        <output data-bus-meter="dcVolts">0.0</output>
                        <output data-bus-meter="archiveCompleted">0</output>
                        <output data-bus-meter="archiveTotal">0</output>
                    </div>
                </div>
                <div class="pageBusInstrumentLabels bottom" aria-hidden="true">
                    <span>DC VOLTS</span>
                    <span>KOMPLETE</span>
                    <span>TOTAL</span>
                </div>
            </div>
            <img id="pageArchiveR2" class="mBtn" src="<?= htmlspecialchars(m2_versioned_asset('/m/img/r2off.png'), ENT_QUOTES, 'UTF-8') ?>" data-asset="r2off.png" data-avionics-type="momentary-button" data-avionics-id="PAGE_ARCHIVE_R2" data-avionics-power="archive" data-avionics-command="ARCHIVE.INSTALL" draggable="false" alt="download archive">
        </div>
    </div>
    <div class="tBar">
        <a id="npTitle" style="color:inherit;text-decoration:none;cursor:var(--m2-cursor-pointer)">―</a>
        <div id="srch" contenteditable="true"></div>
    </div>
    <div class="uBox">
        <div id="progressZone"></div>
        <div class="rowgrp"><textarea id="jsonBox" rows="1" placeholder="json" style="flex:1;resize:vertical"></textarea><button type="button" id="jsonBtn" class="ctlBtn">J</button></div>
        <div id="entryZone"></div>
    </div>
    <div id="songList">
        
<img id="uliverie" src="<?= htmlspecialchars(m2_versioned_asset('/m/img/100liverie.png'), ENT_QUOTES, 'UTF-8') ?>" alt="100liverie." style="
    position: fixed;
    left: 0;
    top: min(116px, calc(100vh - 829px)); 
    width: 641px;
    height: 200px; 
    margin-left: -247.8px;
    transform: rotate(274deg) scaleX(1.1) scaleY(0.8);
    transform-origin: bottom;
">

<img id="umusik2" src="<?= htmlspecialchars(m2_versioned_asset('/m/img/100musik2.png'), ENT_QUOTES, 'UTF-8') ?>" alt="100 % Musik." style="
    position: fixed;
    left: 0;
    top: min(719px, calc(100vh - 226px));
    width: 180.5px;
    margin-left: -64px;
    transform: rotate(275deg);
">

<img id="oyrslogo" src="<?= htmlspecialchars(m2_versioned_asset('/m/img/1yrslogo.png'), ENT_QUOTES, 'UTF-8') ?>" alt="1 YRS TOGEÞER." style="
    position: fixed;
    left: 0;
    bottom: 20px; 
    width: 108.2px;
    margin-left: -25px;
    transform: rotate(272deg);
">
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
            $id = htmlspecialchars((string)$row['_m_song_item_id'], ENT_QUOTES, 'UTF-8');
            $src = '/m/m/' . rawurlencode($url) . '.mp3';
            $imgBase = 'm/img/' . rawurlencode($url);

            $artExt = $indexdb[$url] ?? '';
            $artPath = '';
            if ($artExt !== '') {
                $coverRel = '/m/m/img/' . rawurlencode($url) . '.' . $artExt;
                if ($artExt === 'mp4') {
                    $posterRel = '/m/m/img/poster/' . rawurlencode($url) . '.png';
                    $posterAbs = $imgDir . '/poster/' . $url . '.png';
                    $artPath = is_file($posterAbs) ? $posterRel : '';
                } elseif ($artExt === 'webm') {
                    $posterRel = '/m/m/img/poster/' . rawurlencode($url) . '.jpg';
                    $posterAbs = $imgDir . '/poster/' . $url . '.jpg';
                    $artPath = is_file($posterAbs) ? $posterRel : '';
                } elseif ($artExt === 'gif') {
                    $artPath = $coverRel;
                } else {
                    $artPath = $coverRel;
                }
            }

            $lyrStyle = '';
            if (preg_match('/style=(["\'])(.*?)\1/', (string)$row['title'], $styleMatch)) {
                $styleContent = $styleMatch[2];
                if (strpos($styleContent, 'font-variant-ligatures') !== false || strpos($styleContent, 'font-feature-settings') !== false) {
                    $lyrStyle = $styleContent;
                }
            }
            $lyricsHtml = render_m2_lyrics((string)($row['lyrics'] ?? ''), $lyrStyle);
            $transHtml = render_m2_lyrics((string)($row['trans'] ?? ''), $lyrStyle);

            $mid = $row['link_id'];
            $song_coms = $organized_coms[$mid] ?? [];

            $cLeftHTML = '<div class="cLeft">';
            $limit = array_slice($song_coms, 0, 15);
            foreach ($limit as $c) {
                $pfp = empty($c['userpfp']) ? '/m/serv/npfp.png' : $c['userpfp'];
                $cLeftHTML .= '<img data-src="' . htmlspecialchars($pfp, ENT_QUOTES) . '" onclick="openComFromPfp(event, ' . $c['id'] . ', this)">';
            }
            $cLeftHTML .= '</div>';
        ?><div class="cardWrap" data-category="<?= htmlspecialchars($rowCat, ENT_QUOTES, 'UTF-8') ?>"<?php if (!empty($row['sort_key_raw'])): ?> data-sort-key="<?= htmlspecialchars((string)$row['sort_key_raw'], ENT_QUOTES, 'UTF-8') ?>"<?php endif; ?>>
                <?= $cLeftHTML ?>
                <div class="card" id="<?= $id ?>" data-song-url="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" data-title="<?= htmlspecialchars((string)$row['title'], ENT_QUOTES, 'UTF-8') ?>" data-art="<?= htmlspecialchars($artPath, ENT_QUOTES, 'UTF-8') ?>">
                    <button class="mob-copy-btn" onclick="event.stopPropagation();copyLinkId('<?= $id ?>')">§</button>
                    <a href="serv/com.php?no=№ <?= htmlspecialchars((string)$row['link_id'], ENT_QUOTES, 'UTF-8') ?>" class="mob-com-btn" onclick="openCom(event, this)">C</a>
                    <div class="cMain">
                        <div class="cImg">
                            <?php
                            $ext = $indexdb[$url] ?? '';
                            if ($ext !== ''):
                                $fullPath = '/m/m/img/' . rawurlencode($url) . '.' . $ext;
                                if (in_array($ext, ['mp4', 'webm'])):
                                    $isSync = !empty($row['is_yes']);
                            ?>
                                    <video <?= $isSync ? 'loop muted playsinline preload="none" data-sync="true"' : 'autoplay loop muted playsinline' ?> alt="<?= htmlspecialchars((string)($row['alt'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" style="position:absolute;left:0;top:0;width:100%;height:100%;object-fit:cover;z-index:1" data-src="<?= $fullPath ?>"></video>
                                <?php else: ?>
                                    <img data-src="<?= $fullPath ?>" alt="<?= htmlspecialchars((string)($row['alt'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" style="position:absolute;left:0;top:0;width:100%;height:100%;object-fit:cover;z-index:1">
                            <?php
                                endif;
                            endif;
                            ?>
                        </div>
                    </div>
                    <div class="cName"><?= (string)$row['title'] ?></div>
                    <div class="cLyr"><?= $lyricsHtml ?></div>
                    <template class="mLyricsOriginal"><?= $lyricsHtml ?></template>
                    <template class="mLyricsTrans"><?= $transHtml ?></template>
                    <audio crossorigin="anonymous" data-src="<?= htmlspecialchars($src, ENT_QUOTES, 'UTF-8') ?>" preload="none"></audio>
                </div>
                <div class="cSide">
                    <span onclick="event.stopPropagation();copyLinkId('<?= $id ?>')">§</span>
                    <span class="delBtn" data-anchor="<?= $id ?>" style="display:none">T</span>
                    <a href="editm.php?id=<?= htmlspecialchars((string)$row['link_id'], ENT_QUOTES, 'UTF-8') ?>" class="editBtn" onclick="event.stopPropagation()">E</a>

                    <a href="serv/com.php?no=№ <?= htmlspecialchars((string)$row['link_id'], ENT_QUOTES, 'UTF-8') ?>" id="comBtn" onclick="openCom(event, this)">C</a>
                    <?php if (trim((string)($row['trans'] ?? '')) !== ''): ?>
                    <button type="button" class="mLyricsToggle" aria-pressed="false" onclick="toggleCardLyrics(event, this)">L</button>
                    <?php endif; ?>
                    <div class="loadBox">00:00</div>
                </div>
            </div>
        <?php
        } ?>
    </div>
    <div id="rBarWrapper">

        <div id="lBar">
            <div class="tJump" id="tJump">
                <div class="tJumpCat" data-href="#mTerminal">T</div>
                <?php
                $catLetters = [];
                foreach ($results as $row) {
                    $cat = trim((string)$row['category']);
                    $title = trim((string)($row['sort_title'] ?? $row['title']));
                    if ($cat === '' || $title === '') continue;
                    $plainTitle = stripLeadingTitleMarks(trim(html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                    if ($plainTitle === '') continue;
                    $rawFirstLower = mb_strtolower(mb_substr($plainTitle, 0, 1, 'UTF-8'), 'UTF-8');
                    $firstChar = mb_strtoupper($rawFirstLower, 'UTF-8');
                    if (isset($charRanks[$rawFirstLower])) {
                        $rank = $charRanks[$rawFirstLower];
                        $canonicalLower = mb_substr($fixþebrokenorderingofdefaultphpineedtomakeþisanklaßlatertobefairwellfornowweshallkeepusingþischangenotnameofþisvartwillbeanfunktionwiþtimejslaterwewillimportsotakeþisasantodoplease[$rank], 0, 1, 'UTF-8');
                        $firstChar = mb_strtoupper($canonicalLower, 'UTF-8');
                    }
                    if (!isset($catLetters[$cat][$firstChar])) {
                        $id = htmlspecialchars((string)$row['_m_song_item_id'], ENT_QUOTES, 'UTF-8');
                        $catLetters[$cat][$firstChar] = $id;
                    }
                }

                foreach ($catRefs as $cat => $pre) {
                    echo '<div class="tJumpCat" data-href="#' . htmlspecialchars($pre, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($pre, ENT_QUOTES, 'UTF-8') . '</div>';
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
            <img id="mPl" class="mBtn" src="<?= htmlspecialchars(m2_versioned_asset('/m/img/ploff.png'), ENT_QUOTES, 'UTF-8') ?>" data-asset="ploff.png" draggable="false" alt="play">
            <svg id="seekRadial" viewBox="0 0 390 735" role="group" aria-label="Active and standby seek instrument">
                <defs>
                    <radialGradient id="seekRadialGlassShade" cx="35%" cy="22%" r="78%">
                        <stop offset="0" stop-color="#222522"/>
                        <stop offset=".4" stop-color="#0b0c0b"/>
                        <stop offset="1" stop-color="#030403"/>
                    </radialGradient>
                    <filter id="seekRadialHandShadow" x="-30%" y="-30%" width="160%" height="160%">
                        <feDropShadow dx="2" dy="2" stdDeviation="1.5" flood-color="#000" flood-opacity=".85"/>
                    </filter>
                    <mask id="seekRadialTuningOuterScoopMask" x="-42" y="-42" width="84" height="84" maskUnits="userSpaceOnUse">
                        <rect x="-42" y="-42" width="84" height="84" fill="#000"/>
                        <circle cx="0" cy="0" r="37" fill="#fff"/>
                        <g fill="#000">
                            <circle cx="0" cy="-37" r="3.7"/>
                            <circle cx="0" cy="-37" r="3.7" transform="rotate(30)"/>
                            <circle cx="0" cy="-37" r="3.7" transform="rotate(60)"/>
                            <circle cx="0" cy="-37" r="3.7" transform="rotate(90)"/>
                            <circle cx="0" cy="-37" r="3.7" transform="rotate(120)"/>
                            <circle cx="0" cy="-37" r="3.7" transform="rotate(150)"/>
                            <circle cx="0" cy="-37" r="3.7" transform="rotate(180)"/>
                            <circle cx="0" cy="-37" r="3.7" transform="rotate(210)"/>
                            <circle cx="0" cy="-37" r="3.7" transform="rotate(240)"/>
                            <circle cx="0" cy="-37" r="3.7" transform="rotate(270)"/>
                            <circle cx="0" cy="-37" r="3.7" transform="rotate(300)"/>
                            <circle cx="0" cy="-37" r="3.7" transform="rotate(330)"/>
                        </g>
                    </mask>
                    <mask id="seekRadialTuningInnerScoopMask" x="-30" y="-30" width="60" height="60" maskUnits="userSpaceOnUse">
                        <rect x="-30" y="-30" width="60" height="60" fill="#000"/>
                        <circle cx="0" cy="0" r="24" fill="#fff"/>
                        <g fill="#000">
                            <circle cx="0" cy="-24" r="3.6"/>
                            <circle cx="0" cy="-24" r="3.6" transform="rotate(45)"/>
                            <circle cx="0" cy="-24" r="3.6" transform="rotate(90)"/>
                            <circle cx="0" cy="-24" r="3.6" transform="rotate(135)"/>
                            <circle cx="0" cy="-24" r="3.6" transform="rotate(180)"/>
                            <circle cx="0" cy="-24" r="3.6" transform="rotate(225)"/>
                            <circle cx="0" cy="-24" r="3.6" transform="rotate(270)"/>
                            <circle cx="0" cy="-24" r="3.6" transform="rotate(315)"/>
                        </g>
                    </mask>
                </defs>
                <g id="seekRadialGauge">
                    <circle class="seekRadialBezelOuter" cx="195" cy="190" r="171"/>
                    <circle class="seekRadialBezelInner" cx="195" cy="190" r="160"/>
                    <g id="seekRadialControl" class="seekRadialControl" tabindex="0" role="slider" aria-label="Active seek position" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                        <circle class="seekRadialGlass" cx="195" cy="190" r="153"/>
                        <g id="seekRadialOuterTicks"></g>
                        <g id="seekRadialOuterLabels"></g>
                        <circle class="seekRadialHit" cx="195" cy="190" r="153"/>
                    </g>
                    <circle cx="195" cy="190" r="86" fill="none" stroke="#777b75" stroke-width="1.2" opacity=".62" pointer-events="none"/>
                    <g id="seekRadialInnerTicks"></g>
                    <g id="seekRadialInnerLabels"></g>
                    <g id="seekRadialTargetHand"><path class="seekRadialTargetHand" d="M191.5 207L193.2 55L195 36L196.8 55L198.5 207Z"/></g>
                    <g id="seekRadialSectionHand"><path class="seekRadialSectionHand" d="M189 207L191.5 119L195 101L198.5 119L201 207Z"/></g>
                    <circle class="seekRadialHubOuter" cx="195" cy="190" r="17"/>
                    <circle class="seekRadialHubInner" cx="195" cy="190" r="9"/>
                </g>
                <g>
                    <text class="seekRadialDisplayLabel" x="195" y="400">AKTIV</text>
                    <g id="seekRadialActiveDigits" model-segment="88:888:88" transform="translate(20 410) scale(6.1)" role="img"></g>
                    <text class="seekRadialDisplayLabel" x="195" y="545">STANDBY</text>
                    <g id="seekRadialStandbyDigits" model-segment="88:888:88" transform="translate(20 555) scale(6.1)" role="img"></g>
                    <g id="seekRadialSet" class="seekRadialSet" transform="translate(109 709) scale(1.4) translate(-124 -675)" tabindex="0" role="button" aria-label="Transfer standby target to active position" aria-pressed="false">
                        <rect class="seekRadialButtonFace" x="86" y="650" width="76" height="50" rx="8"/>
                        <rect class="seekRadialButtonCap" x="92" y="656" width="64" height="38" rx="4"/>
                        <text class="seekRadialButtonCopy" x="124" y="684">SET</text>
                    </g>
                    <g transform="translate(296 709) scale(1.7)">
                        <circle class="seekRadialTuningOuterBase" cx="0" cy="0" r="39"/>
                        <g id="seekRadialSectionKnob" class="seekRadialKnobControl" tabindex="0" role="slider" aria-label="Standby section" aria-valuemin="0" aria-valuemax="99" aria-valuenow="0">
                            <circle class="seekRadialTuningHit" cx="0" cy="0" r="39"/>
                            <g id="seekRadialSectionRotor">
                                <circle class="seekRadialTuningOuterRing" cx="0" cy="0" r="37" mask="url(#seekRadialTuningOuterScoopMask)"/>
                            </g>
                        </g>
                        <circle class="seekRadialTuningInnerBase" cx="0" cy="0" r="26"/>
                        <g id="seekRadialTimeKnob" class="seekRadialKnobControl" tabindex="0" role="slider" aria-label="Standby time" aria-valuemin="0" aria-valuemax="59939" aria-valuenow="0" aria-valuetext="1:01">
                            <circle class="seekRadialTuningHit" cx="0" cy="0" r="26"/>
                            <g id="seekRadialTimeRotor">
                                <circle class="seekRadialTuningInnerRing" cx="0" cy="0" r="24" mask="url(#seekRadialTuningInnerScoopMask)"/>
                            </g>
                        </g>
                    </g>
                </g>
            </svg>
                        <hr style="width: 84%;">

            <div class="mSwGroup">
                <span id="mDcAmpsDisplay" class="mDcAmpsDisplay" model-segment="8.888" role="img" aria-live="polite" aria-label="DC amps 0.000"></span>
                <label class="mLbl">DCAMPS</label>
                <div class="mCol mSwitchCol">
                    <div class="mSwSlot">
                        <div class="mSw"><img id="mNav" class="mSwimg" src="<?= htmlspecialchars(m2_versioned_asset('/m/img/sc.png'), ENT_QUOTES, 'UTF-8') ?>" data-asset="sc.png" draggable="false" alt="prev/next"></div>
                    </div>
                    <div class="mLbl">M</div>
                </div>
                <div class="mKControlRow">
                    <div class="mCol">
                        <div class="mKControlFace"><span id="mR3" class="mKnob2" data-bg="rotate.png"></span></div>
                        <div class="mLbl">R3</div>
                    </div>
                    <div class="mCol mSwitchCol mRightOnly">
                        <div class="mKControlFace">
                            <div class="mSwSlot">
                                <div class="mSw"><img id="mK" class="mSwimg" src="<?= htmlspecialchars(m2_versioned_asset('/m/img/sd.png'), ENT_QUOTES, 'UTF-8') ?>" data-asset="sd.png" draggable="false" alt="K"></div>
                            </div>
                        </div>
                        <div class="mLbl">K</div>
                    </div>
                </div>
                <div class="mCol mSwitchCol mRightOnly">
                    <div class="mSwSlot">
                        <div class="mSw"><img id="mKR" class="mSwimg" src="<?= htmlspecialchars(m2_versioned_asset('/m/img/sc.png'), ENT_QUOTES, 'UTF-8') ?>" data-asset="sc.png" draggable="false" alt="KR"></div>
                    </div>
                    <div class="mLbl">KR</div>
                </div>
            </div>
            <div class="mKnobsWrap" data-bg="knobswrap.png"><span id="mISR" class="mKdial" data-bg="knob.png"></span></div>
            <hr style="width: 84%;">
            <div class="mRow">
                <div class="mCol"><span id="mRev" class="mKnob" data-bg="knob.png" title="reverb"></span>
                    <div class="mLbl">R</div>
                </div>
                <div class="mCol"><span id="mSpd" class="mKnob2" data-bg="rotate.png" title="speed"></span>
                    <div class="mLbl">S</div>
                </div>
            </div>
            <button id="lBtn" style="color: yellow">L</button>
            <img id="mR2" class="mBtn" src="<?= htmlspecialchars(m2_versioned_asset('/m/img/r2off.png'), ENT_QUOTES, 'UTF-8') ?>" data-asset="r2off.png" draggable="false" alt="reset speed">
            <span id="mVol" class="mKnob" data-bg="knob.png" title="volume"></span>
            <div class="mLbl">V</div>
        </div>
    </div>
    <br>
    <br>
    <section id="mUnderPanel" aria-labelledby="mCircuitBreakerPanelTitle">
        <div id="mCircuitBreakerPanelTitle">CIRKUIT BREAKERS</div>
        <div class="mUnderPanelDisplay">
            <div id="mCircuitBreakerBoard"></div>
            <div class="mUnderPanelFuture" aria-hidden="true"></div>
        </div>
    </section>
    <section id="mTerminal">
        <div class="mTerminalConsole">
            <div class="mTerminalDashColumn">
                <button type="button" class="mLSK" data-terminal-field="1L">—</button>
                <button type="button" class="mLSK" data-terminal-field="2L">—</button>
                <button type="button" class="mLSK" data-terminal-field="3L">—</button>
                <button type="button" class="mLSK" data-terminal-field="4L">—</button>
                <button type="button" class="mLSK" data-terminal-field="5L">—</button>
                <button type="button" class="mLSK" data-terminal-field="6L">—</button>
            </div>
            <div id="mTerminalScreen"></div>
            <div class="mTerminalDashColumn">
                <button type="button" class="mLSK" data-terminal-field="1R">—</button>
                <button type="button" class="mLSK" data-terminal-field="2R">—</button>
                <button type="button" class="mLSK" data-terminal-field="3R">—</button>
                <button type="button" class="mLSK" data-terminal-field="4R">—</button>
                <button type="button" class="mLSK" data-terminal-field="5R">—</button>
                <button type="button" class="mLSK" data-terminal-field="6R">—</button>
            </div>
        </div>
        <div class="mTerminalKeys">
            <div class="mTerminalKeyGrid">
                <button type="button" class="mTerminalKey">A</button>
                <button type="button" class="mTerminalKey">B</button>
                <button type="button" class="mTerminalKey">C</button>
                <button type="button" class="mTerminalKey">D</button>
                <button type="button" class="mTerminalKey">E</button>
                <button type="button" class="mTerminalKey">F</button>
                <button type="button" class="mTerminalKey">G</button>
                <button type="button" class="mTerminalKey">H</button>
                <button type="button" class="mTerminalKey">I</button>
                <button type="button" class="mTerminalKey">J</button>
                <button type="button" class="mTerminalKey">K</button>
                <button type="button" class="mTerminalKey">L</button>
                <button type="button" class="mTerminalKey">M</button>
                <button type="button" class="mTerminalKey">N</button>
                <button type="button" class="mTerminalKey">O</button>
                <button type="button" class="mTerminalKey">P</button>
                <button type="button" class="mTerminalKey">Q</button>
                <button type="button" class="mTerminalKey">R</button>
                <button type="button" class="mTerminalKey">S</button>
                <button type="button" class="mTerminalKey">T</button>
                <button type="button" class="mTerminalKey">U</button>
                <button type="button" class="mTerminalKey">V</button>
                <button type="button" class="mTerminalKey">W</button>
                <button type="button" class="mTerminalKey">X</button>
                <button type="button" class="mTerminalKey">Y</button>
                <button type="button" class="mTerminalKey">Z</button>
                <button type="button" class="mTerminalKey mTerminalKeyWord">SP</button>
                <button type="button" class="mTerminalKey mTerminalKeyWord">DL</button>
                <button type="button" class="mTerminalKey">/</button>
                <button type="button" class="mTerminalKey mTerminalKeyWord">BS</button>
            </div>
            <div class="mTerminalNumeralSection">
                <div class="mTerminalKeyGrid">
                    <button type="button" class="mTerminalKey">0</button>
                    <button type="button" class="mTerminalKey">1</button>
                    <button type="button" class="mTerminalKey">2</button>
                    <button type="button" class="mTerminalKey">3</button>
                    <button type="button" class="mTerminalKey">4</button>
                    <button type="button" class="mTerminalKey">5</button>
                    <button type="button" class="mTerminalKey">6</button>
                    <button type="button" class="mTerminalKey">7</button>
                    <button type="button" class="mTerminalKey">8</button>
                    <button type="button" class="mTerminalKey">9</button>
                    <button type="button" class="mTerminalKey">-</button>
                    <button type="button" class="mTerminalKey">+</button>
                    <button type="button" class="mTerminalKey">.</button>
                    <button type="button" class="mTerminalKey">,</button>
                    <button type="button" class="mTerminalKey">*</button>
                </div>
                <div class="mTerminalSpecialisenBox">
                    <button type="button" class="mTerminalSpecialButton" data-terminal-special="first">FP</button>
                    <button type="button" class="mTerminalSpecialButton" data-terminal-special="last">LP</button>
                    <button type="button" class="mTerminalSpecialButton" data-terminal-special="index">IN</button>
                    <button type="button" class="mTerminalSave" data-terminal-special="save">SAVE<span class="mTerminalSaveLight"></span></button>
                    <button type="button" class="mTerminalSpecialButton" data-terminal-special="song">SON</button>
                    <button type="button" class="mTerminalSpecialButton" data-terminal-special="legs">LEG</button>
                    <button type="button" class="mTerminalSpecialButton" data-terminal-special="direct">DCT</button>
                    <button type="button" class="mTerminalSpecialButton" data-terminal-special="clear">CLR</button>
                </div>
            </div>
        </div>
    </section>
    <button id="spawn" class="ctlBtn" style="position:fixed;left:10px;bottom:10px;z-index:9999;display:none">J</button>
    <datalist id="catList">
        <?php foreach ($cats as $c) {
            $decoded = html_entity_decode($c, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            echo '<option value="' . htmlspecialchars($decoded, ENT_QUOTES, 'UTF-8') . '">';
        } ?>
    </datalist>
    <script>
        const M2_BROWSER_CACHE_VERSION = <?= json_encode(M2_BROWSER_CACHE_VERSION, JSON_UNESCAPED_SLASHES) ?>;
        const M2_PAGE_CODE_VERSION = <?= json_encode(M2_PAGE_CODE_VERSION, JSON_UNESCAPED_SLASHES) ?>;

        const blockBrowserContextMenu = target => {
            target.addEventListener('contextmenu', event => event.preventDefault(), true);
        };
        blockBrowserContextMenu(document);
        document.addEventListener('DOMContentLoaded', () => {
            const frame = document.getElementById('comFrame');
            frame?.addEventListener('load', () => {
                if (frame.contentDocument) blockBrowserContextMenu(frame.contentDocument);
            });
        });

        function bindM2DirectionalCursor(element, grabbable) {
            if (!element) return;
            const update = event => {
                const rect = element.getBoundingClientRect();
                const right = event.clientX >= rect.left + rect.width / 2;
                element.classList.toggle('m2CursorRight', right);
                element.classList.toggle('m2CursorLeft', !right);
            };
            const release = () => element.classList.remove('m2CursorGrabbing');
            element.addEventListener('pointerenter', update);
            element.addEventListener('pointermove', update);
            element.addEventListener('pointerdown', event => {
                if (event.button !== 0) return;
                update(event);
                if (grabbable) element.classList.add('m2CursorGrabbing');
            });
            element.addEventListener('pointerup', release);
            element.addEventListener('pointercancel', release);
            element.addEventListener('lostpointercapture', release);
            element.addEventListener('pointerleave', event => {
                element.classList.remove('m2CursorLeft', 'm2CursorRight');
                if (!element.hasPointerCapture?.(event.pointerId)) release();
            });
        }

        class ThreeBarCircuitBreaker {
            static sequence = 0;

            constructor(host, circuit) {
                this.host = host;
                this.circuit = circuit;
                this.closed = circuit.initialClosed !== false;
                this.conducting = false;
                this.render();
                this.host.addEventListener('click', () => this.setClosed(!this.closed));
            }

            render() {
                const glowId = `mCircuitBreakerGlow${++ThreeBarCircuitBreaker.sequence}`;
                const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                svg.classList.add('mCircuitBreakerSvg');
                svg.setAttribute('viewBox', '55 25 110 175');
                svg.setAttribute('aria-hidden', 'true');
                svg.style.setProperty('--m-circuit-breaker-glow', `url(#${glowId})`);
                svg.innerHTML = `<defs><filter id="${glowId}" x="-120%" y="-40%" width="340%" height="180%"><feGaussianBlur stdDeviation="3.2" result="blur"></feGaussianBlur><feMerge><feMergeNode in="blur"></feMergeNode><feMergeNode in="SourceGraphic"></feMergeNode></feMerge></filter></defs><rect x="63" y="34" width="94" height="157" rx="5" fill="#050606" stroke="#252828" stroke-width="1.5"></rect><rect x="70" y="43" width="80" height="139" rx="3" fill="#101211" stroke="#030303" stroke-width="3"></rect><rect class="mCircuitBreakerBar" x="87" y="68" width="10" height="89" rx="1"></rect><rect class="mCircuitBreakerBar" x="105" y="68" width="10" height="89" rx="1"></rect><rect class="mCircuitBreakerBar" x="123" y="68" width="10" height="89" rx="1"></rect>`;
                const designation = document.createElement('span');
                designation.className = 'mCircuitBreakerDesignation';
                designation.textContent = this.circuit.designation;
                const name = document.createElement('span');
                name.className = 'mCircuitBreakerName';
                name.textContent = this.circuit.name;
                this.host.className = 'mCircuitBreaker';
                this.host.setAttribute('model-circuit-breaker', this.circuit.id);
                this.host.dataset.circuitId = this.circuit.id;
                this.host.dataset.circuitMembers = this.circuit.members.join(' ');
                this.host.title = `${this.circuit.designation} ${this.circuit.name}`;
                this.host.setAttribute('aria-label', this.host.title);
                this.host.replaceChildren(svg, designation, name);
                this.synchronizeState();
            }

            setClosed(closed, emit = true) {
                const next = Boolean(closed);
                if (next === this.closed) return false;
                this.closed = next;
                this.synchronizeState();
                if (emit) {
                    panelSoundBank.play('circuitBreaker');
                    this.host.dispatchEvent(new CustomEvent('breakercommand', {
                        bubbles: true,
                        detail: {
                            id: this.circuit.id,
                            closed: this.closed,
                            members: [...this.circuit.members]
                        }
                    }));
                }
                return true;
            }

            setConducting(conducting) {
                const next = Boolean(conducting);
                if (next === this.conducting) return false;
                this.conducting = next;
                this.synchronizeState();
                return true;
            }

            synchronizeState() {
                this.host.dataset.contactState = this.closed ? 'closed' : 'open';
                this.host.dataset.breakerLamp = this.closed && this.conducting ? 'on' : 'off';
                this.host.setAttribute('aria-pressed', String(this.closed));
            }
        }

        class M2CircuitBreakerPanel {
            constructor(root, layout) {
                this.root = root;
                this.layout = layout;
                this.breakers = new Map();
                this.pendingCells = new Set();
                this.resizeObserver = new ResizeObserver(entries => {
                    entries.forEach(entry => this.scheduleRowLayout(entry.target));
                });
                this.render();
            }

            render() {
                this.breakers.clear();
                this.root.replaceChildren(this.createGroup(this.layout));
            }

            createTier(groups) {
                const tier = document.createElement('div');
                tier.className = 'mCircuitBreakerTier';
                groups.forEach(group => tier.append(this.createGroup(group)));
                return tier;
            }

            createGroup(group) {
                const fieldset = document.createElement('fieldset');
                fieldset.className = 'mCircuitBreakerGroup';
                fieldset.setAttribute('model-circuit-breaker-group', group.busbar);
                fieldset.dataset.busbar = group.busbar;
                const legend = document.createElement('legend');
                legend.textContent = group.busbar;
                const cells = document.createElement('div');
                cells.className = 'mCircuitBreakerCells';
                group.circuits.forEach(circuit => {
                    const host = document.createElement('button');
                    host.type = 'button';
                    cells.append(host);
                    this.breakers.set(
                        circuit.id,
                        new ThreeBarCircuitBreaker(host, circuit)
                    );
                });
                fieldset.append(legend, cells);
                (group.rows || []).forEach(row => fieldset.append(this.createTier(row)));
                this.resizeObserver.observe(cells);
                this.scheduleRowLayout(cells);
                return fieldset;
            }

            getBreaker(id) {
                return this.breakers.get(id) || null;
            }

            scheduleRowLayout(cells) {
                if (this.pendingCells.has(cells)) return;
                this.pendingCells.add(cells);
                requestAnimationFrame(() => {
                    this.pendingCells.delete(cells);
                    this.layoutRows(cells);
                });
            }

            layoutRows(cells) {
                const breakers = [...cells.children];
                breakers.forEach(breaker => {
                    delete breaker.dataset.breakerPosition;
                    delete breaker.dataset.breakerRow;
                });
                const rows = [];
                breakers.forEach(breaker => {
                    const top = breaker.offsetTop;
                    const row = rows.find(candidate => Math.abs(candidate.top - top) <= 1);
                    if (row) {
                        row.breakers.push(breaker);
                    } else {
                        rows.push({ top, breakers: [breaker] });
                    }
                });
                rows.forEach((row, rowIndex) => {
                    row.breakers.forEach((breaker, index) => {
                        breaker.dataset.breakerRow = String(rowIndex);
                        breaker.dataset.breakerPosition = row.breakers.length === 1
                            ? 'single'
                            : (index === 0 ? 'left' : (index === row.breakers.length - 1 ? 'right' : 'center'));
                    });
                });
            }
        }

        const m2CircuitBreakerLayout = {
            busbar: 'PAGE CONTROL BUS',
            circuits: [
                {
                    id: 'pageControlBreaker',
                    designation: 'MASTER',
                    name: 'PAGE CONTROL',
                    members: [
                        'pagePower',
                        'pageModeSelector',
                        'tier1Relay',
                        'tier2Relay',
                        'tier3Relay',
                        'mainSystemsRelay',
                        'readieLamp'
                    ]
                }
            ],
            rows: [
                [
                    {
                        busbar: 'CONTROL BUS',
                        circuits: [
                            {
                                id: 'archiveBreaker',
                                designation: 'A4',
                                name: 'ARCHIVE CONTROLLER',
                                members: [
                                    'archiveSystemsLoad',
                                    'archiveActivityLoad',
                                    'ARCHIVE_PAGE_ARCHIVE_R2'
                                ]
                            },
                            {
                                id: 'lightingBreaker',
                                designation: 'A5',
                                name: 'LIGHTING CONTROL UNIT',
                                members: [
                                    'lightingSystemsLoad',
                                    'topMainBusStrobe',
                                    'leftPlaybackStrobe',
                                    'rightPlaybackStrobe',
                                    'bottomLeftCurrentStrobe',
                                    'bottomRightPanelStrobe'
                                ]
                            }
                        ]
                    },
                    {
                        busbar: 'MAIN POWER BUS',
                        circuits: [
                            {
                                id: 'mainSenseBreaker',
                                designation: 'A0',
                                name: 'MAIN AVIONICS BUS SENSE',
                                members: ['mainSystemsLoad']
                            },
                            {
                                id: 'panelBreaker',
                                designation: 'A1',
                                name: 'PANEL CONTROLLER',
                                members: ['panelSystemsLoad']
                            },
                            {
                                id: 'playbackBreaker',
                                designation: 'A2',
                                name: 'PLAYBACK CONTROLLER',
                                members: [
                                    'playbackSystemsLoad',
                                    'playbackActivityLoad',
                                    'PLAYBACK_PL',
                                    'PLAYBACK_ISR_OFF',
                                    'PLAYBACK_ISR_R',
                                    'PLAYBACK_ISR_I',
                                    'PLAYBACK_ISR_S',
                                    'PLAYBACK_K_OFF',
                                    'PLAYBACK_K_ON',
                                    'PLAYBACK_R3',
                                    'PLAYBACK_KR_OFF',
                                    'PLAYBACK_KR_ON',
                                    'PLAYBACK_M_U',
                                    'PLAYBACK_M_C',
                                    'PLAYBACK_M_D'
                                ]
                            },
                            {
                                id: 'soundBreaker',
                                designation: 'A3',
                                name: 'SOUND CONTROLLER',
                                members: [
                                    'soundSystemsLoad',
                                    'soundActivityLoad',
                                    'SOUND_SOUND_L_COUPLING_OFF',
                                    'SOUND_SOUND_L_COUPLING_ON',
                                    'SOUND_VOLUME',
                                    'SOUND_SPEED',
                                    'SOUND_REVERB'
                                ]
                            }
                        ],
                        rows: [
                            [
                                {
                                    busbar: 'TIME BUS',
                                    circuits: [
                                        {
                                            id: 'timeBreaker',
                                            designation: 'TIME',
                                            name: 'TIME BUS',
                                            members: [
                                                'timeActiveDisplay',
                                                'timeStandbyDisplay',
                                                'timeDcAmpsDisplay',
                                                'mainSeekSensor',
                                                'sectionSensor',
                                                'timeSensor',
                                                'setInput'
                                            ]
                                        }
                                    ]
                                }
                            ]
                        ]
                    }
                ]
            ]
        };

        const m2CircuitBreakerPanel = new M2CircuitBreakerPanel(
            document.getElementById('mCircuitBreakerBoard'),
            m2CircuitBreakerLayout
        );

        function m2VersionedAssetUrl(rawUrl) {
            if (!rawUrl || /^(?:blob:|data:|about:)/i.test(rawUrl)) return rawUrl;
            try {
                const url = new URL(rawUrl, document.baseURI);
                if (url.origin === location.origin) {
                    url.searchParams.set('v', M2_BROWSER_CACHE_VERSION);
                }
                return url.href;
            } catch (error) {
                return rawUrl;
            }
        }

        let m2WorkerRegistrationPromise = null;

        function m2ArchiveWorkerUrl() {
            const workerUrl = new URL('/m2-cache-worker.js', location.origin);
            workerUrl.searchParams.set('v', M2_BROWSER_CACHE_VERSION);
            workerUrl.searchParams.set('p', M2_PAGE_CODE_VERSION);
            return workerUrl;
        }

        function waitForM2WorkerActivation(worker) {
            if (worker.state === 'activated') return Promise.resolve(worker);
            return new Promise((resolve, reject) => {
                const onStateChange = () => {
                    if (worker.state === 'activated') {
                        worker.removeEventListener('statechange', onStateChange);
                        resolve(worker);
                    } else if (worker.state === 'redundant') {
                        worker.removeEventListener('statechange', onStateChange);
                        reject(new Error('Browser archive worker became redundant'));
                    }
                };
                worker.addEventListener('statechange', onStateChange);
                onStateChange();
            });
        }

        function ensureM2WorkerRegistration() {
            if (m2WorkerRegistrationPromise) return m2WorkerRegistrationPromise;
            m2WorkerRegistrationPromise = (async () => {
                if (!('serviceWorker' in navigator) || !window.isSecureContext) {
                    throw new Error('Service Worker requires HTTPS');
                }
                const workerUrl = m2ArchiveWorkerUrl();
                let registration = await navigator.serviceWorker.getRegistration('/');
                const currentWorkerUrls = [
                    registration?.installing?.scriptURL,
                    registration?.waiting?.scriptURL,
                    registration?.active?.scriptURL
                ].filter(Boolean);
                if (!registration || !currentWorkerUrls.includes(workerUrl.href)) {
                    registration = await navigator.serviceWorker.register(workerUrl.href, {
                        scope: '/',
                        updateViaCache: 'none'
                    });
                }
                let worker = [registration.installing, registration.waiting, registration.active]
                    .find(candidate => candidate?.scriptURL === workerUrl.href) ||
                    registration.installing || registration.waiting || registration.active;
                if (!worker) throw new Error('Browser archive worker was not created');
                worker = await waitForM2WorkerActivation(worker);
                return { registration, worker };
            })().catch(error => {
                m2WorkerRegistrationPromise = null;
                throw error;
            });
            return m2WorkerRegistrationPromise;
        }

        function armM2SurvivalShell() {
            const root = document.documentElement;
            root.dataset.m2SurvivalState = 'arming';
            return ensureM2WorkerRegistration().then(({ worker }) => new Promise((resolve, reject) => {
                const requestId = 'm2-survival-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2);
                const onMessage = event => {
                    const message = event.data;
                    if (!message || message.requestId !== requestId) return;
                    if (message.type === 'M2_SURVIVAL_READY') {
                        navigator.serviceWorker.removeEventListener('message', onMessage);
                        root.dataset.m2SurvivalState = 'ready';
                        resolve(message);
                    } else if (message.type === 'M2_SURVIVAL_ERROR') {
                        navigator.serviceWorker.removeEventListener('message', onMessage);
                        root.dataset.m2SurvivalState = 'fault';
                        reject(new Error(message.error || 'Survival shell failed'));
                    }
                };
                navigator.serviceWorker.addEventListener('message', onMessage);
                worker.postMessage({
                    type: 'M2_SURVIVAL_ARM',
                    requestId,
                    pageVersion: M2_PAGE_CODE_VERSION,
                    documentUrl: location.href
                });
            })).catch(error => {
                root.dataset.m2SurvivalState = 'fault';
                throw error;
            });
        }

        armM2SurvivalShell().catch(() => {});

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
            masterGain = null,
            convolverConnected = false;
        const sourceMap = new WeakMap();

        const freezeTelemetry = value => {
            if (!value || typeof value !== 'object' || Object.isFrozen(value)) return value;
            Object.values(value).forEach(freezeTelemetry);
            return Object.freeze(value);
        };

        const publishReadOnlyWindow = (name, value) => {
            const descriptor = Object.getOwnPropertyDescriptor(window, name);
            if (descriptor && !descriptor.configurable) return false;
            Object.defineProperty(window, name, {
                value,
                writable: false,
                configurable: false
            });
            return true;
        };

        class CircuitContact extends EventTarget {
            #closed;
            #authority;

            constructor(name, closed = false, authority = null) {
                super();
                this.name = name;
                this.#closed = !!closed;
                this.#authority = authority;
                Object.defineProperty(this, 'closed', {
                    enumerable: true,
                    configurable: false,
                    get: () => this.#closed
                });
                Object.preventExtensions(this);
            }

            setClosed(closed, authority = null) {
                if (this.#authority !== null && authority !== this.#authority) {
                    throw new Error('Unauthorized contact operation: ' + this.name);
                }
                const next = !!closed;
                if (next === this.#closed) return false;
                this.#closed = next;
                this.dispatchEvent(new Event('change'));
                return true;
            }
        }

        class CommandRegistry {
            #commands = new Map();

            define(label, { version = 1, validate = () => true } = {}) {
                if (typeof label !== 'string' || !label.length) throw new TypeError('Command label required');
                if (this.#commands.has(label)) throw new Error('Duplicate command label: ' + label);
                if (!Number.isInteger(version) || version < 1) throw new TypeError('Invalid command version: ' + label);
                if (typeof validate !== 'function') throw new TypeError('Command validator required: ' + label);
                this.#commands.set(label, Object.freeze({ label, version, validate }));
                return this;
            }

            has(label) {
                return this.#commands.has(label);
            }

            get(label) {
                return this.#commands.get(label) || null;
            }

            validate(label, payload, version = null) {
                const command = this.get(label);
                if (!command) return { valid: false, fault: 'UNKNOWN_COMMAND' };
                if (version !== null && version !== command.version) {
                    return { valid: false, fault: 'UNSUPPORTED_VERSION', expected: command.version };
                }
                try {
                    return command.validate(payload)
                        ? { valid: true, version: command.version }
                        : { valid: false, fault: 'INVALID_PAYLOAD', version: command.version };
                } catch (error) {
                    return { valid: false, fault: 'INVALID_PAYLOAD', version: command.version, error };
                }
            }

            get labels() {
                return [...this.#commands.keys()];
            }
        }

        class AvionicsDataBus extends EventTarget {
            #receivers = new Map();
            #sequence = 0;

            constructor(commands = null) {
                super();
                this.commands = commands;
            }

            register(label, receiver, powered = () => true) {
                if (typeof label !== 'string' || !label.length) throw new TypeError('Avionics label required');
                if (typeof receiver !== 'function') throw new TypeError('Avionics receiver required: ' + label);
                if (this.commands && !this.commands.has(label)) throw new Error('Unregistered avionics command: ' + label);
                const endpoint = { receiver, powered };
                if (!this.#receivers.has(label)) this.#receivers.set(label, new Set());
                this.#receivers.get(label).add(endpoint);
                return () => this.#receivers.get(label)?.delete(endpoint);
            }

            hasReceiver(label) {
                return (this.#receivers.get(label)?.size || 0) > 0;
            }

            hasCommand(label) {
                return this.commands ? this.commands.has(label) : this.hasReceiver(label);
            }

            get labels() {
                return [...this.#receivers.keys()];
            }

            transmit(label, payload = {}, source = null, version = null) {
                const validation = this.commands
                    ? this.commands.validate(label, payload, version)
                    : { valid: true, version: version || 1 };
                const frame = {
                    label,
                    version: validation.version || version || null,
                    payload,
                    source,
                    sourceId: source?.id || source?.name || null,
                    sequence: ++this.#sequence,
                    timestamp: Date.now(),
                    handled: false,
                    deliveries: 0,
                    results: [],
                    errors: []
                };
                if (!validation.valid) {
                    frame.errors.push(Object.assign(new Error(validation.fault), validation));
                    this.dispatchEvent(new CustomEvent('message', { detail: frame }));
                    return frame;
                }
                for (const endpoint of this.#receivers.get(label) || []) {
                    if (!endpoint.powered()) continue;
                    try {
                        const result = endpoint.receiver(payload, frame);
                        if (result !== false) {
                            frame.handled = true;
                            frame.deliveries++;
                            frame.results.push(result);
                            if (result && typeof result.catch === 'function') {
                                result.catch(error => frame.errors.push(error));
                            }
                        }
                    } catch (error) {
                        frame.errors.push(error);
                    }
                }
                this.dispatchEvent(new CustomEvent('message', { detail: frame }));
                return frame;
            }
        }

        class DiscreteInput extends EventTarget {
            #closed;
            #electricalInput = null;

            constructor(name, { closed = false, power = null } = {}) {
                super();
                this.name = name;
                this.power = power;
                this.#closed = !!closed;
                power?.addEventListener('change', () => this.dispatchEvent(new Event('change')));
            }

            get closed() {
                return this.#closed;
            }

            get powered() {
                return !this.power || this.power.closed;
            }

            get active() {
                return this.powered && this.#closed && (!this.#electricalInput || this.#electricalInput.active);
            }

            get state() {
                if (!this.powered) return 'OPEN';
                if (this.#electricalInput) return this.#electricalInput.state;
                return this.#closed ? 'POWERED' : 'OPEN';
            }

            get voltage() {
                return this.#electricalInput?.voltage || 0;
            }

            get current() {
                return this.#electricalInput?.current || 0;
            }

            bindElectricalInput(input) {
                if (this.#electricalInput) throw new Error('Discrete input already wired: ' + this.name);
                this.#electricalInput = input;
                input.addEventListener('change', () => this.dispatchEvent(new Event('change')));
                input.setClosed(this.#closed);
                this.dispatchEvent(new Event('change'));
                return this;
            }

            setClosed(closed) {
                const next = !!closed;
                if (next && !this.powered) return false;
                if (next === this.#closed) return false;
                this.#closed = next;
                this.#electricalInput?.setClosed(next);
                this.dispatchEvent(new Event('mechanicalchange'));
                this.dispatchEvent(new Event('change'));
                return true;
            }
        }

        Object.freeze(CircuitContact.prototype);
        Object.freeze(CircuitContact);

        class AvionicsSelector extends EventTarget {
            #position;
            #electricalInputs = new Map();

            constructor(name, positions, initialPosition, { power = null } = {}) {
                super();
                this.name = name;
                this.positions = [...positions];
                this.power = power;
                this.#position = this.positions.includes(initialPosition) ? initialPosition : this.positions[0];
                power?.addEventListener('change', () => this.dispatchEvent(new Event('change')));
            }

            get position() {
                return this.#position;
            }

            get powered() {
                return !this.power || this.power.closed;
            }

            get effectivePosition() {
                if (!this.powered) return null;
                if (!this.#electricalInputs.size) return this.#position;
                return this.#electricalInputs.get(this.#position)?.active ? this.#position : null;
            }

            get state() {
                if (!this.powered) return 'OPEN';
                const active = [...this.#electricalInputs.entries()].filter(([, input]) => input.active);
                if (!this.#electricalInputs.size) return 'POWERED';
                if (active.length !== 1 || active[0][0] !== this.#position) return 'FAULT';
                return 'POWERED';
            }

            bindElectricalPosition(position, input) {
                if (!this.positions.includes(position)) throw new Error('Invalid selector pole: ' + this.name + ':' + position);
                if (this.#electricalInputs.has(position)) throw new Error('Selector pole already wired: ' + this.name + ':' + position);
                this.#electricalInputs.set(position, input);
                input.addEventListener('change', () => this.dispatchEvent(new Event('change')));
                input.setClosed(position === this.#position);
                this.dispatchEvent(new Event('change'));
                return this;
            }

            setPosition(position) {
                if (!this.positions.includes(position)) throw new Error('Invalid selector position: ' + this.name + ':' + position);
                if (position === this.#position) return false;
                const previous = this.#position;
                this.#electricalInputs.get(previous)?.setClosed(false);
                this.#position = position;
                this.#electricalInputs.get(position)?.setClosed(true);
                this.dispatchEvent(new CustomEvent('change', { detail: { previous, position } }));
                return true;
            }
        }

        class AvionicsPermissive extends EventTarget {
            constructor(name, inputs, test) {
                super();
                this.name = name;
                this.inputs = [...inputs];
                this.test = test;
                this.energized = !!test();
                this.inputs.forEach(input => input.addEventListener('change', () => this.recompute()));
            }

            recompute() {
                const energized = !!this.test();
                if (energized === this.energized) return false;
                this.energized = energized;
                this.dispatchEvent(new Event('change'));
                return true;
            }
        }

        class AnalogControl extends EventTarget {
            #value;
            #electricalInput = null;

            constructor(name, value, { power = null, referenceVoltage = 5 } = {}) {
                super();
                this.name = name;
                this.power = power;
                this.referenceVoltage = referenceVoltage;
                this.#value = value;
                power?.addEventListener('change', () => this.dispatchEvent(new Event('change')));
            }

            get value() {
                return this.#value;
            }

            get powered() {
                return !this.power || this.power.closed;
            }

            get signalVoltage() {
                return this.#electricalInput
                    ? this.#electricalInput.signalVoltage
                    : (this.powered ? this.#value * this.referenceVoltage : 0);
            }

            get state() {
                if (!this.powered) return 'OPEN';
                return this.#electricalInput?.state || 'POWERED';
            }

            bindElectricalInput(input) {
                if (this.#electricalInput) throw new Error('Analog input already wired: ' + this.name);
                this.#electricalInput = input;
                input.setValue(this.#value);
                input.addEventListener('change', () => this.dispatchEvent(new Event('change')));
                this.dispatchEvent(new Event('change'));
                return this;
            }

            setValue(value) {
                const next = Math.max(0, Math.min(1, Number(value)));
                if (!isFinite(next) || next === this.#value) return false;
                this.#value = next;
                this.#electricalInput?.setValue(next);
                this.dispatchEvent(new Event('change'));
                return true;
            }
        }

        class AvionicsMomentaryButton extends DiscreteInput {
            constructor(name, { power = null, bus, command, payload = {} } = {}) {
                super(name, { power });
                this.bus = bus;
                this.command = command;
                this.payload = payload;
            }

            pulse(payload = this.payload) {
                if (!this.setClosed(true)) return false;
                const frame = this.bus.transmit(this.command, payload, this);
                this.setClosed(false);
                return frame;
            }
        }

        class AvionicsDeviceRegistry {
            #factories = new Map();

            register(type, factory) {
                if (this.#factories.has(type)) throw new Error('Duplicate avionics device type: ' + type);
                this.#factories.set(type, factory);
                return this;
            }

            create(spec, context) {
                const factory = this.#factories.get(spec.type);
                if (!factory) throw new Error('Unknown avionics device type: ' + spec.type);
                return factory(spec, context);
            }
        }

        class AvionicsBoard {
            #devices = new Map();
            #specs = new Map();
            #electricalNetlist = null;
            #electricalOptions = {};

            constructor(registry, bus, powerContacts = {}) {
                this.registry = registry;
                this.bus = bus;
                this.powerContacts = powerContacts;
            }

            add(spec) {
                if (!spec.id || this.#devices.has(spec.id)) throw new Error('Duplicate or missing avionics device id: ' + spec.id);
                const device = this.registry.create(spec, {
                    bus: this.bus,
                    powerContacts: this.powerContacts
                });
                device.id = spec.id;
                this.#devices.set(spec.id, device);
                this.#specs.set(spec.id, { ...spec });
                this.#electricalNetlist?.attachAvionicsDevice(spec, device, this.#electricalOptions);
                return device;
            }

            install(specs) {
                return Object.fromEntries(specs.map(spec => [spec.id, this.add(spec)]));
            }

            get(id) {
                return this.#devices.get(id) || null;
            }

            get entries() {
                return [...this.#devices.entries()];
            }

            get specs() {
                return [...this.#specs.entries()].map(([id, spec]) => [id, { ...spec }]);
            }

            connectElectricalNetlist(netlist, options = {}) {
                if (this.#electricalNetlist && this.#electricalNetlist !== netlist) {
                    throw new Error('Avionics board already connected to another electrical netlist');
                }
                this.#electricalNetlist = netlist;
                this.#electricalOptions = { ...options };
                this.#specs.forEach((spec, id) => {
                    netlist.attachAvionicsDevice(spec, this.#devices.get(id), this.#electricalOptions);
                });
                return this;
            }

            validate() {
                const faults = [];
                this.#specs.forEach((spec, id) => {
                    if (spec.power && !(spec.power in this.powerContacts)) {
                        faults.push({ id, fault: 'UNKNOWN_POWER', value: spec.power });
                    }
                    if (spec.command && !this.bus.hasCommand(spec.command)) {
                        faults.push({ id, fault: 'UNKNOWN_COMMAND', value: spec.command });
                    } else if (spec.command && !this.bus.hasReceiver(spec.command)) {
                        faults.push({ id, fault: 'NO_COMMAND_RECEIVER', value: spec.command });
                    }
                });
                return faults;
            }

            mount(root = document) {
                root.querySelectorAll('[data-avionics-type][data-avionics-id]').forEach(element => {
                    const id = element.dataset.avionicsId;
                    if (this.#devices.has(id)) return;
                    let payload = {};
                    try {
                        if (element.dataset.avionicsPayload) payload = JSON.parse(element.dataset.avionicsPayload);
                    } catch (error) {}
                    const device = this.add({
                        id,
                        name: element.getAttribute('aria-label') || id,
                        type: element.dataset.avionicsType,
                        power: element.dataset.avionicsPower || 'main',
                        command: element.dataset.avionicsCommand || '',
                        payload
                    });
                    if (device instanceof AvionicsMomentaryButton) {
                        element.addEventListener('click', event => {
                            event.preventDefault();
                            device.pulse();
                        });
                    }
                });
                return this;
            }
        }

        class BoardValidator {
            validate({ boards = [], netlists = [] } = {}) {
                return [
                    ...boards.flatMap(board => board.validate()),
                    ...netlists.flatMap(netlist => netlist.validate())
                ];
            }
        }

        const commandPayloadIsObject = payload => payload !== null && typeof payload === 'object' && !Array.isArray(payload);
        const avionicsCommands = new CommandRegistry();
        [
            'PLAYBACK.REPEAT.WHOLE',
            'PLAYBACK.REPEAT.SECTION',
            'PLAYBACK.CARD.PREVIOUS',
            'PLAYBACK.STOP',
            'PLAYBACK.CARD.NEXT',
            'PLAYBACK.RANDOM.CATEGORY.WHOLE',
            'PLAYBACK.RANDOM.CATEGORY.SECTION',
            'PLAYBACK.RANDOM.VISIBLE.WHOLE',
            'PLAYBACK.RANDOM.VISIBLE.SECTION',
            'SOUND.SPEED.CENTRE',
            'SOUND.ALARM.START',
            'SOUND.ALARM.STOP',
            'ARCHIVE.INSTALL'
        ].forEach(label => avionicsCommands.define(label, {
            version: 1,
            validate: commandPayloadIsObject
        }));
        avionicsCommands.define('PANEL.MECHANICAL.PLAY', {
            version: 1,
            validate: payload => commandPayloadIsObject(payload) &&
                typeof payload.name === 'string' && payload.name.length > 0
        });
        avionicsCommands.define('SOUND.UI.PLAY', {
            version: 1,
            validate: payload => commandPayloadIsObject(payload) &&
                typeof payload.name === 'string' && payload.name.length > 0
        });
        const avionicsBus = new AvionicsDataBus(avionicsCommands);
        const avionicsDeviceRegistry = new AvionicsDeviceRegistry();
        const boardValidator = new BoardValidator();
        const resolveAvionicsPower = (spec, context) => {
            if (!spec.power) return null;
            if (!(spec.power in context.powerContacts)) {
                throw new Error('Unknown avionics power bus: ' + spec.power);
            }
            return context.powerContacts[spec.power];
        };
        avionicsDeviceRegistry
            .register('maintained-discrete', (spec, context) => new DiscreteInput(
                spec.name || spec.id,
                {
                    closed: spec.closed,
                    power: resolveAvionicsPower(spec, context)
                }
            ))
            .register('selector', (spec, context) => new AvionicsSelector(
                spec.name || spec.id,
                spec.positions,
                spec.initial,
                { power: resolveAvionicsPower(spec, context) }
            ))
            .register('analog-control', (spec, context) => new AnalogControl(
                spec.name || spec.id,
                spec.value,
                {
                    power: resolveAvionicsPower(spec, context),
                    referenceVoltage: spec.referenceVoltage || 5
                }
            ))
            .register('momentary-button', (spec, context) => new AvionicsMomentaryButton(
                spec.name || spec.id,
                {
                    power: resolveAvionicsPower(spec, context),
                    bus: context.bus,
                    command: spec.command,
                    payload: spec.payload
                }
            ));

        class SoundAvionicsUnit extends EventTarget {
            #activities = new Set();

            constructor(bus, powerContact) {
                super();
                this.name = 'SOUND AVIONICS UNIT';
                this.powerContact = powerContact;
                this.volume = new AnalogControl('VOLUME', 1, { power: powerContact });
                this.speed = new AnalogControl('SPEED', 0.5, { power: powerContact });
                this.reverb = new AnalogControl('REVERB', 0, { power: powerContact });
                this.coupling = new AvionicsSelector(
                    'L COUPLING', ['OFF', 'ON'], 'ON', { power: powerContact }
                );
                this.bus = bus;
                this.bus.register(
                    'SOUND.SPEED.CENTRE',
                    () => this.speed.setValue(0.5),
                    () => this.powerContact.closed
                );
                this.bus.register(
                    'SOUND.UI.PLAY',
                    payload => driveElectricalSound(payload.name),
                    () => this.powerContact.closed
                );
                this.bus.register(
                    'SOUND.ALARM.START',
                    () => driveStartAlarm(),
                    () => this.powerContact.closed
                );
                this.bus.register(
                    'SOUND.ALARM.STOP',
                    () => driveStopAlarm(),
                    () => this.powerContact.closed
                );
            }

            get active() {
                return this.#activities.size > 0;
            }

            setActivity(source, active) {
                const before = this.active;
                if (active) this.#activities.add(source);
                else this.#activities.delete(source);
                if (before === this.active) return false;
                this.dispatchEvent(new Event('activitychange'));
                return true;
            }

            clearActivity() {
                if (!this.#activities.size) return false;
                this.#activities.clear();
                this.dispatchEvent(new Event('activitychange'));
                return true;
            }

            get playbackRate() {
                const y = (0.5 - this.speed.value) * 100;
                const x = Math.abs(y);
                const delta = x <= 10 ? 0.01 * x : 0.1 + 0.01 * (x - 10) + Math.pow(x - 10, 2) / 900;
                return Math.max(0.05, y >= 0 ? 1 + delta : 1 - delta);
            }

            pulseR2() {
                return this.bus.transmit('SOUND.SPEED.CENTRE', {}, this);
            }
        }

        class PlaybackAvionicsUnit extends EventTarget {
            #activities = new Set();
            #sectionWindowPowerAuthority = Symbol('K R3 POWER');

            constructor(loads, powerContact) {
                super();
                this.name = 'PLAYBACK AVIONICS UNIT';
                this.bus = avionicsBus;
                this.sectionWindowPower = new CircuitContact(
                    'K R3 ANALOG POWER',
                    false,
                    this.#sectionWindowPowerAuthority
                );
                this.board = new AvionicsBoard(avionicsDeviceRegistry, this.bus, {
                    main: powerContact,
                    r3: this.sectionWindowPower
                });
                const devices = this.board.install([
                    { id: 'PAGE', type: 'maintained-discrete', name: 'PAGE CONTROL DISCRETE', power: 'main', electrical: false },
                    { id: 'PL', type: 'maintained-discrete', name: 'PL PLAY DISCRETE', power: 'main' },
                    { id: 'ISR', type: 'selector', name: 'ISR', power: 'main', positions: ['OFF', 'R', 'I', 'S', 'E'], initial: 'OFF' },
                    { id: 'K', type: 'selector', name: 'K', power: 'main', positions: ['OFF', 'ON'], initial: 'ON' },
                    { id: 'R3', type: 'analog-control', name: 'R3 SECTION WINDOW', power: 'r3', dcSupply: 'k', value: 0.25, inputResistance: 56000 },
                    { id: 'KR', type: 'selector', name: 'KR', power: 'main', positions: ['OFF', 'ON'], initial: 'OFF' },
                    { id: 'M', type: 'selector', name: 'M', power: 'main', positions: ['U', 'C', 'D'], initial: 'C' }
                ]);
                this.pageContact = devices.PAGE;
                this.plContact = devices.PL;
                this.isr = devices.ISR;
                this.k = devices.K;
                this.sectionWindow = devices.R3;
                this.kr = devices.KR;
                this.m = devices.M;
                this.routes = Object.freeze([
                    { positions: { ISR: 'OFF', K: 'OFF' }, command: 'PLAYBACK.REPEAT.WHOLE' },
                    { positions: { ISR: 'OFF', K: 'ON', KR: 'OFF' }, command: 'PLAYBACK.REPEAT.WHOLE' },
                    { positions: { ISR: 'OFF', K: 'ON', KR: 'ON' }, command: 'PLAYBACK.REPEAT.SECTION' },
                    { positions: { ISR: 'R', M: 'U' }, command: 'PLAYBACK.CARD.PREVIOUS' },
                    { positions: { ISR: 'R', M: 'C' }, command: 'PLAYBACK.STOP' },
                    { positions: { ISR: 'R', M: 'D' }, command: 'PLAYBACK.CARD.NEXT' },
                    { positions: { ISR: 'I', K: 'OFF' }, command: 'PLAYBACK.RANDOM.CATEGORY.WHOLE' },
                    { positions: { ISR: 'I', K: 'ON' }, command: 'PLAYBACK.RANDOM.CATEGORY.SECTION' },
                    { positions: { ISR: 'S', K: 'OFF' }, command: 'PLAYBACK.RANDOM.VISIBLE.WHOLE' },
                    { positions: { ISR: 'S', K: 'ON' }, command: 'PLAYBACK.RANDOM.VISIBLE.SECTION' }
                ]);
                const commands = {
                    'PLAYBACK.REPEAT.WHOLE': loads.wholeRepeat,
                    'PLAYBACK.REPEAT.SECTION': loads.sectionRepeat,
                    'PLAYBACK.CARD.PREVIOUS': loads.previousCard,
                    'PLAYBACK.STOP': loads.stop,
                    'PLAYBACK.CARD.NEXT': loads.nextCard,
                    'PLAYBACK.RANDOM.CATEGORY.WHOLE': loads.categoryWhole,
                    'PLAYBACK.RANDOM.CATEGORY.SECTION': loads.categorySection,
                    'PLAYBACK.RANDOM.VISIBLE.WHOLE': loads.visibleWhole,
                    'PLAYBACK.RANDOM.VISIBLE.SECTION': loads.visibleSection
                };
                Object.entries(commands).forEach(([label, receiver]) => {
                    this.bus.register(label, receiver, () => this.pageContact.active);
                });
                this.sectionRepeatFeed = new AvionicsPermissive(
                    'KR EFFECTIVE PERMISSIVE',
                    [this.pageContact, this.plContact, this.isr, this.k, this.kr],
                    () => this.pageContact.active &&
                        this.plContact.active &&
                        this.isr.effectivePosition === 'OFF' &&
                        this.k.effectivePosition === 'ON' &&
                        this.kr.effectivePosition === 'ON'
                );
                const synchronizeSectionWindowPower = () => this.sectionWindowPower.setClosed(
                    powerContact.closed && this.k.effectivePosition === 'ON',
                    this.#sectionWindowPowerAuthority
                );
                this.k.addEventListener('change', synchronizeSectionWindowPower);
                powerContact.addEventListener('change', () => {
                    this.pageContact.setClosed(powerContact.closed);
                    synchronizeSectionWindowPower();
                    if (!powerContact.closed) {
                        this.dropPl();
                        this.clearActivity();
                    }
                });
                this.pageContact.setClosed(powerContact.closed);
                synchronizeSectionWindowPower();
            }

            get sectionWindowEffectiveValue() {
                return this.sectionWindow.signalVoltage / this.sectionWindow.referenceVoltage;
            }

            get active() {
                return this.#activities.size > 0;
            }

            setActivity(source, active) {
                const before = this.active;
                if (active) this.#activities.add(source);
                else this.#activities.delete(source);
                if (before === this.active) return false;
                this.dispatchEvent(new Event('activitychange'));
                return true;
            }

            clearActivity() {
                if (!this.#activities.size) return false;
                this.#activities.clear();
                this.dispatchEvent(new Event('activitychange'));
                return true;
            }

            energizePl() {
                return this.plContact.setClosed(true);
            }

            dropPl() {
                return this.plContact.setClosed(false);
            }

            pulseBoundary(detail) {
                const signal = { ...detail, handled: false };
                if (!this.pageContact.active || !this.plContact.active) return signal;
                const positions = {
                    ISR: this.isr.effectivePosition,
                    K: this.k.effectivePosition,
                    KR: this.kr.effectivePosition,
                    M: this.m.effectivePosition
                };
                const route = this.routes.find(candidate => Object.entries(candidate.positions)
                    .every(([selector, position]) => positions[selector] === position));
                if (!route) return signal;
                signal.handled = this.bus.transmit(route.command, signal, this).handled;
                return signal;
            }
        }

        class ArchiveAvionicsUnit extends EventTarget {
            #checkPromise = null;
            #installPromise = null;
            #operationController = null;
            #generation = 0;

            constructor(bus, powerContact, checker, installer) {
                super();
                this.name = 'ARCHIVE AVIONICS UNIT';
                this.bus = bus;
                this.powerContact = powerContact;
                this.checker = checker;
                this.installer = installer;
                this.state = 'standby';
                this.active = false;
                this.bus.register('ARCHIVE.INSTALL', () => this.install(), () => powerContact.closed);
                powerContact.addEventListener('change', () => {
                    if (powerContact.closed) queueMicrotask(() => {
                        const operation = this.check();
                        if (operation && typeof operation.catch === 'function') operation.catch(() => {});
                    });
                    else {
                        this.#generation++;
                        this.#operationController?.abort();
                        this.#operationController = null;
                        this.#checkPromise = null;
                        this.#installPromise = null;
                        this.state = 'standby';
                        this.setActive(false);
                        this.dispatchEvent(new Event('statechange'));
                    }
                });
            }

            setActive(active) {
                const next = !!active;
                if (next === this.active) return false;
                this.active = next;
                this.dispatchEvent(new Event('activitychange'));
                return true;
            }

            check() {
                if (!this.powerContact.closed) return false;
                if (this.#installPromise) return this.#installPromise;
                if (this.#checkPromise) return this.#checkPromise;
                const generation = this.#generation;
                const controller = new AbortController();
                this.#operationController = controller;
                this.state = 'checking';
                this.setActive(true);
                this.dispatchEvent(new Event('statechange'));
                const operation = Promise.resolve()
                    .then(() => this.checker(controller.signal))
                    .then(result => {
                        if (generation === this.#generation && this.powerContact.closed && !this.#installPromise) {
                            this.state = result?.complete ? 'ready' : 'incomplete';
                            this.dispatchEvent(new Event('statechange'));
                        }
                        return result;
                    })
                    .catch(error => {
                        if (generation !== this.#generation || error?.name === 'AbortError') return false;
                        if (this.powerContact.closed && !this.#installPromise) {
                            this.state = 'fault';
                            this.dispatchEvent(new Event('statechange'));
                        }
                        throw error;
                    })
                    .finally(() => {
                        if (generation !== this.#generation) return;
                        if (this.#checkPromise === operation) this.#checkPromise = null;
                        if (this.#operationController === controller) this.#operationController = null;
                        if (!this.#installPromise) this.setActive(false);
                    });
                this.#checkPromise = operation;
                return operation;
            }

            install() {
                if (!this.powerContact.closed) return false;
                if (this.#installPromise) return this.#installPromise;
                const generation = this.#generation;
                this.state = 'installing';
                this.setActive(true);
                this.dispatchEvent(new Event('statechange'));
                const pendingCheck = this.#checkPromise;
                let controller = null;
                const operation = Promise.resolve()
                    .then(() => pendingCheck?.catch(() => null))
                    .then(() => {
                        if (generation !== this.#generation || !this.powerContact.closed) return false;
                        controller = new AbortController();
                        this.#operationController = controller;
                        return this.installer(controller.signal);
                    })
                    .then(result => {
                        if (generation !== this.#generation || !this.powerContact.closed || result === false) return false;
                        this.state = result?.complete === false ? 'incomplete' : 'ready';
                        this.dispatchEvent(new Event('statechange'));
                        return result;
                    })
                    .catch(error => {
                        if (generation !== this.#generation || error?.name === 'AbortError') return false;
                        this.state = 'fault';
                        this.dispatchEvent(new Event('statechange'));
                        throw error;
                    })
                    .finally(() => {
                        if (generation !== this.#generation) return;
                        if (this.#installPromise === operation) this.#installPromise = null;
                        if (this.#operationController === controller) this.#operationController = null;
                        if (!this.#checkPromise) this.setActive(false);
                    });
                this.#installPromise = operation;
                return operation;
            }
        }

        const PAGE_CONTROL_AUTHORITY = Symbol('PAGE CONTROL BUS');
        const pageMainSystemsPower = new CircuitContact('MAIN SYSTEMS POWER', false, PAGE_CONTROL_AUTHORITY);
        const panelAvionicsPower = new CircuitContact('PANEL AVIONICS POWER', false, PAGE_CONTROL_AUTHORITY);
        const playbackAvionicsPower = new CircuitContact('PLAYBACK AVIONICS POWER', false, PAGE_CONTROL_AUTHORITY);
        const soundAvionicsPower = new CircuitContact('SOUND AVIONICS POWER', false, PAGE_CONTROL_AUTHORITY);
        const archiveAvionicsPower = new CircuitContact('ARCHIVE AVIONICS POWER', false, PAGE_CONTROL_AUTHORITY);
        const timeBusPower = new CircuitContact('TIME BUS POWER', false, PAGE_CONTROL_AUTHORITY);
        let timeBusInputs = null;
        const timeBusIsPowered = () => timeBusPower.closed;
        const setTimeBusInputValue = (id, value) => timeBusInputs?.[id]?.setValue(value) || false;
        const setTimeBusSetClosed = closed => timeBusInputs?.setInput?.setClosed(closed) || false;
        const timeBusSetIsActive = () => !!timeBusInputs?.setInput?.active;
        const soundCircuit = new SoundAvionicsUnit(avionicsBus, soundAvionicsPower);
        avionicsBus.register(
            'PANEL.MECHANICAL.PLAY',
            payload => driveMechanicalSound(payload.name)
        );
        const archiveCircuit = new ArchiveAvionicsUnit(
            avionicsBus,
            archiveAvionicsPower,
            signal => checkM2BrowserArchive(signal),
            signal => installM2BrowserArchive(signal)
        );
        const lIsOn = () => soundCircuit.coupling.effectivePosition === 'ON';
        const mainSystemsEvents = new EventTarget();
        pageMainSystemsPower.addEventListener('change', () => {
            if (!pageMainSystemsPower.closed) return;
            mainSystemsEvents.dispatchEvent(new Event('ready'));
            window.dispatchEvent(new Event('m2mainready'));
        });
        const m2ExpansionBoard = new AvionicsBoard(avionicsDeviceRegistry, avionicsBus, {
            main: pageMainSystemsPower,
            panel: panelAvionicsPower,
            playback: playbackAvionicsPower,
            sound: soundAvionicsPower,
            archive: archiveAvionicsPower,
            time: timeBusPower
        });
        window.addEventListener('DOMContentLoaded', () => m2ExpansionBoard.mount(document));
        publishReadOnlyWindow('m2Avionics', Object.freeze({
            get powers() {
                return freezeTelemetry({
                    main: pageMainSystemsPower.closed,
                    panel: panelAvionicsPower.closed,
                    playback: playbackAvionicsPower.closed,
                    sound: soundAvionicsPower.closed,
                    archive: archiveAvionicsPower.closed,
                    time: timeBusPower.closed
                });
            },
            get units() {
                return freezeTelemetry({
                    sound: {
                        powered: soundAvionicsPower.closed,
                        active: soundCircuit.active,
                        coupling: soundCircuit.coupling.effectivePosition,
                        volume: soundCircuit.volume.value,
                        speed: soundCircuit.speed.value,
                        reverb: soundCircuit.reverb.value
                    },
                    archive: {
                        powered: archiveAvionicsPower.closed,
                        active: archiveCircuit.active,
                        state: archiveCircuit.state
                    }
                });
            },
            get commands() {
                return Object.freeze(avionicsCommands.labels);
            },
            validate() {
                return freezeTelemetry(boardValidator.validate({
                    boards: [m2ExpansionBoard]
                }).map(fault => ({ ...fault })));
            }
        }));




        const PANEL_SOUND_GROUPS = {
            starterAmbient: ['177453744'],
            starterHold: ['111819169'],
            starterReleaseMechanical: ['346954404'],
            starterDisconnect: ['131262607'],
            starterEngage: ['914468481'],
            confirmationChime: ['631327589'],
            playControl: ['724533677', '735346396'],
            resetControl: ['908114107', '114859927'],
            terminalLsk: ['47948028', '107515464', '327588892', '829893984', '1071964624'],
            terminalKey: ['23241251', '32529879', '193750109', '469742299', '648317260', '919666272', '927494565', '947016727'],
            seekInner: ['248188445', '707423860', '889538338', '890116213', '991818329'],
            seekOuter: ['188329246', '225809707', '646941696', '965108196', '1063255697'],
            r3Section: ['118362385', '231899970', '261572059', '279596147', '382269597', '430349130', '733227784', '786115389'],
            rAndV: ['665625102', '676588915', '841433751', '894826028', '1022663785', '1048800092'],
            modeSwitch: ['170719619', '177501697', '820688366', '914468481'],
            lightingSwitch: ['171057543', '185280866', '230386663', '310757194', '349497041', '375577561', '397341398', '428440949', '523923703', '672653334', '776937022', '798009520', '901634826', '938498470', '1044353637'],
            loadSelector: ['270312930', '600825204', '830737197', '906891840'],
            isrRelease: ['23849211', '96279692', '582713912'],
            circuitBreaker: ['460872579', '533713888', '591444713', '751895121'],
            warningHorn: ['512845995'],
            outerHand: ['417087732'],
            innerHand: ['755522096']
        };
        Object.freeze(PANEL_SOUND_GROUPS);
        const PANEL_SOUND_LOOPS = Object.freeze({
            '177453744': [29245 / 48000, 150025 / 48000],
            '111819169': [8604 / 44100, 36992 / 44100],
            '417087732': [48073 / 44100, 251783 / 44100],
            '755522096': [78221 / 44100, 222247 / 44100]
        });
        const PANEL_WAV_IDS = [...new Set(Object.values(PANEL_SOUND_GROUPS).flat())];

        const UI_SOUND_FILES = {
            chime: '/m/img/chime.mp3',
            arm: '/m/img/arm.mp3',
            noava: '/m/img/noava.mp3',
            click: '/m/img/click.mp3',
            firealarm: '/m/img/fire-alarm.mp3'
        };
        const MECHANICAL_SOUND_NAMES = new Set([
            'arm', 'click'
        ]);
        const ELECTRICAL_SOUND_NAMES = new Set([
            'chime', 'noava'
        ]);
        const uiAudio = {};
        const ASSET = {};
        const aimg = f => ASSET[f] || ('/m/img/' + f);
        const CONTROL_ASSET_FILES = [
            'ploff.png', 'plon.png', 'plpresstoon.png', 'plpresstooff.png',
            'sc.png', 'sd.png', 'st.png', 'knobswrap.png', 'knob.png',
            'rotate.png', 'r2off.png', 'r2on.png', 'kboswrapforalign.png',
            'lightforalignoff.png', 'lightforalignon.png'
        ];
        function fetchRequiredBlob(url) {
            const versionedUrl = m2VersionedAssetUrl(url);
            return fetch(versionedUrl).then(response => {
                if (!response.ok) throw new Error('HTTP ' + response.status + ' for ' + url);
                return response.blob();
            });
        }

        function prepareUiAudio(objectUrl) {
            return new Promise((resolve, reject) => {
                const sound = new Audio();
                const ready = () => {
                    sound.removeEventListener('loadeddata', ready);
                    sound.removeEventListener('error', failed);
                    resolve(sound);
                };
                const failed = () => {
                    sound.removeEventListener('loadeddata', ready);
                    sound.removeEventListener('error', failed);
                    reject(sound.error || new Error('Control sound failed to decode'));
                };
                sound.preload = 'auto';
                sound.addEventListener('loadeddata', ready);
                sound.addEventListener('error', failed);
                sound.src = objectUrl;
                sound.load();
                if (sound.readyState >= 2) ready();
            });
        }

        const panelSoundBank = (() => {
            const AudioContext = window.AudioContext || window.webkitAudioContext;
            let context = null;
            const buffers = new Map();
            const loading = new Map();
            const active = new Set();
            const held = new Map();
            const hands = new Map();
            const rotary = new Map();
            const rotaryTextures = new Map();
            const innerMotion = {
                bed: null, intro: null, outro: null, idleTimer: null,
                phase: 'idle', rate: NaN, level: NaN,
                lastUpdate: -Infinity, lastOutroStart: -Infinity
            };

            const getContext = () => context || (context = new AudioContext({ latencyHint: 'interactive' }));
            const unlock = () => {
                const ctx = getContext();
                if (ctx.state === 'suspended') ctx.resume().catch(() => {});
            };
            const load = id => {
                if (buffers.has(id)) return Promise.resolve(buffers.get(id));
                if (loading.has(id)) return loading.get(id);
                const pending = fetchRequiredBlob('/m/img/' + id + '.wav')
                    .then(blob => blob.arrayBuffer())
                    .then(bytes => getContext().decodeAudioData(bytes))
                    .then(buffer => {
                        buffers.set(id, buffer);
                        return buffer;
                    })
                    .catch(error => {
                        loading.delete(id);
                        throw error;
                    });
                loading.set(id, pending);
                return pending;
            };

            const choose = group => {
                const ids = PANEL_SOUND_GROUPS[group];
                return ids?.length ? ids[Math.floor(Math.random() * ids.length)] : null;
            };

            const stop = (slot, fade = 0.04) => {
                if (!slot || slot.stopping) return;
                slot.stopping = true;
                const time = getContext().currentTime;
                if (typeof slot.gain.gain.cancelAndHoldAtTime === 'function') {
                    slot.gain.gain.cancelAndHoldAtTime(time);
                } else {
                    const level = slot.gain.gain.value;
                    slot.gain.gain.cancelScheduledValues(time);
                    slot.gain.gain.setValueAtTime(level, time);
                }
                slot.gain.gain.linearRampToValueAtTime(0, time + fade);
                try { slot.source.stop(time + fade); } catch (error) {}
            };

            const start = (id, { loop = false, electrical = false, gain = 1, offset = 0, duration = null, bufferOverride = null, lowpass = null } = {}) => {
                const buffer = bufferOverride || buffers.get(id);
                if (!buffer || (electrical && !soundCircuit.powerContact.closed)) return null;
                const ctx = getContext();
                unlock();
                const source = ctx.createBufferSource();
                const level = ctx.createGain();
                source.buffer = buffer;
                source.loop = loop;
                if (loop && PANEL_SOUND_LOOPS[id]) {
                    source.loopStart = PANEL_SOUND_LOOPS[id][0];
                    source.loopEnd = PANEL_SOUND_LOOPS[id][1];
                }
                level.gain.value = gain * (electrical ? soundCircuit.volume.value : 1);
                const filter = lowpass === null ? null : ctx.createBiquadFilter();
                if (filter) {
                    filter.type = 'lowpass';
                    filter.frequency.value = lowpass;
                    source.connect(filter);
                    filter.connect(level);
                } else {
                    source.connect(level);
                }
                level.connect(ctx.destination);
                const slot = { source, gain: level, baseGain: gain, electrical, stopping: false };
                active.add(slot);
                source.onended = () => {
                    active.delete(slot);
                    if (innerMotion.bed === slot) innerMotion.bed = null;
                    if (innerMotion.intro === slot) innerMotion.intro = null;
                    if (innerMotion.outro === slot) innerMotion.outro = null;
                    rotary.forEach(state => {
                        if (state.bed === slot) state.bed = null;
                        state.clicks.delete(slot);
                    });
                    source.disconnect();
                    if (filter) filter.disconnect();
                    level.disconnect();
                };
                if (duration === null) source.start(0, offset);
                else source.start(0, offset, duration);
                return slot;
            };

            const play = (group, options = {}) => {
                const id = choose(group);
                if (!id) return null;
                if (!buffers.has(id)) {
                    load(id).then(() => start(id, options)).catch(() => {});
                    return null;
                }
                return start(id, options);
            };

            const rotaryTexture = group => {
                if (rotaryTextures.has(group)) return rotaryTextures.get(group);
                const base = buffers.get(PANEL_SOUND_GROUPS[group]?.[0]);
                if (!base) return null;
                const rate = base.sampleRate;
                const offset = Math.round(rate * 0.014);
                const length = Math.round(rate * 0.065);
                const overlap = Math.round(rate * 0.012);
                const texture = getContext().createBuffer(base.numberOfChannels, length, rate);
                for (let channel = 0; channel < base.numberOfChannels; channel++) {
                    const input = base.getChannelData(channel);
                    const output = texture.getChannelData(channel);
                    for (let index = 0; index < length; index++) {
                        const value = input[offset + index] || 0;
                        output[index] = index < overlap ?
                            (input[offset + length + index] || 0) * (1 - index / overlap) + value * (index / overlap) :
                            value;
                    }
                }
                rotaryTextures.set(group, texture);
                return texture;
            };

            const driveRotary = (group, key, degrees, detent) => {
                if (!Number.isFinite(degrees) || !degrees || !Number.isFinite(detent) || detent <= 0) return;
                let state = rotary.get(key);
                if (!state) {
                    state = { bed: null, clicks: new Set(), timer: null, lastMove: -Infinity,
                        lastClick: -Infinity, speed: 0, travel: 0 };
                    rotary.set(key, state);
                }
                const now = performance.now();
                const elapsed = now - state.lastMove;
                const previousDetent = Math.trunc((Math.abs(state.travel) + 0.000001) / detent) * Math.sign(state.travel);
                state.travel += degrees;
                const nextDetent = Math.trunc((Math.abs(state.travel) + 0.000001) / detent) * Math.sign(state.travel);
                const instantSpeed = Math.min(900, Math.abs(degrees) * 1000 / Math.max(16, elapsed > 120 ? 16 : elapsed));
                state.speed = elapsed > 120 ?
                    (Math.abs(degrees) > detent * 2 ? instantSpeed * 0.5 : 0) :
                    state.speed * 0.65 + instantSpeed * 0.35;
                state.lastMove = now;
                if (nextDetent !== previousDetent && now - state.lastClick >= 0.065 * 1000) {
                    const id = choose(group);
                    const buffer = buffers.get(id);
                    if (buffer) {
                        while (state.clicks.size >= 3) {
                            const oldest = state.clicks.values().next().value;
                            state.clicks.delete(oldest);
                            stop(oldest, 0.015);
                        }
                        const duration = Math.min(buffer.duration, 0.16);
                        const click = start(id, { duration });
                        if (click) {
                            state.clicks.add(click);
                            const time = getContext().currentTime;
                            click.gain.gain.setValueAtTime(1, time + Math.max(0, duration - 0.025));
                            click.gain.gain.linearRampToValueAtTime(0, time + duration);
                            state.lastClick = now;
                        }
                    }
                }
                const intensity = Math.max(0, Math.min(1, (state.speed - 70) / 320));
                if (intensity > 0 && (!state.bed || !active.has(state.bed) || state.bed.stopping)) {
                    const texture = rotaryTexture(group);
                    if (texture) state.bed = start(PANEL_SOUND_GROUPS[group][0], {
                        loop: true, gain: 0, bufferOverride: texture, lowpass: 1800
                    });
                }
                if (state.bed) {
                    const time = getContext().currentTime;
                    const volume = state.bed.gain.gain;
                    if (typeof volume.cancelAndHoldAtTime === 'function') volume.cancelAndHoldAtTime(time);
                    else {
                        const current = volume.value;
                        volume.cancelScheduledValues(time);
                        volume.setValueAtTime(current, time);
                    }
                    volume.linearRampToValueAtTime(0.14 * intensity, time + 0.045);
                    state.bed.source.playbackRate.setTargetAtTime(0.9 + 0.25 * intensity, time, 0.025);
                }
                if (state.timer !== null) return;
                const checkIdle = () => {
                    const remaining = 70 - (performance.now() - state.lastMove);
                    if (remaining > 0) {
                        state.timer = setTimeout(checkIdle, Math.max(1, remaining));
                        return;
                    }
                    if (state.bed) stop(state.bed, 0.035);
                    state.bed = null;
                    state.speed = 0;
                    state.timer = null;
                };
                state.timer = setTimeout(checkIdle, 70);
            };

            const stopRotary = key => {
                const state = rotary.get(key);
                if (!state) return;
                if (state.timer !== null) clearTimeout(state.timer);
                if (state.bed) stop(state.bed, 0.035);
                state.bed = null;
                state.speed = 0;
                state.timer = null;
            };

            const startHeld = (group, owner = group) => {
                const existing = held.get(group);
                if (existing) {
                    existing.owners.add(owner);
                    return existing.slot;
                }
                const id = choose(group);
                if (!id) return null;
                const slot = start(id, { loop: true, gain: 0 });
                if (slot) {
                    held.set(group, { slot, owners: new Set([owner]) });
                    const time = getContext().currentTime;
                    slot.gain.gain.setValueAtTime(0, time);
                    slot.gain.gain.linearRampToValueAtTime(1, time + (group === 'starterHold' ? 0.35 : group === 'starterAmbient' ? 1 : 0.04));
                }
                return slot;
            };

            const stopHeld = (group, owner = group) => {
                const current = held.get(group);
                if (!current) return;
                current.owners.delete(owner);
                if (current.owners.size) return;
                stop(current.slot, group === 'starterHold' ? 0.35 : 0.04);
                held.delete(group);
            };

            const driveHand = (group, angle, now = performance.now()) => {
                let state = hands.get(group);
                if (!state) {
                    state = {
                        angle, time: now, slot: null, timer: null,
                        velocity: 0, rawVelocity: 0, lastMove: -Infinity,
                        rate: NaN, level: NaN, updateTime: -Infinity
                    };
                    hands.set(group, state);
                    return;
                }
                const elapsed = now - state.time;
                const moved = Math.abs(angle - state.angle);
                state.angle = angle;
                state.time = now;
                if (elapsed <= 0) return;
                if (moved > 0.00001) {
                    state.rawVelocity = moved * 1000 / elapsed;
                    state.lastMove = now;
                }
                if ((!state.slot || state.slot.stopping) && moved > 0.00001) {
                    state.slot = start(PANEL_SOUND_GROUPS[group][0], { loop: true, gain: 0 });
                    state.velocity = 0;
                    state.rate = NaN;
                    state.level = NaN;
                    state.updateTime = -Infinity;
                }
                if (!state.slot) return;
                const raw = now - state.lastMove < 90 ? state.rawVelocity : 0;
                const easing = 1 - Math.exp(-elapsed / (raw > state.velocity ? 180 : 220));
                state.velocity += (raw - state.velocity) * easing;
                const strength = Math.min(1, state.velocity / 360);
                const rate = 1 + 0.2 * strength;
                const level = 0.5 * Math.sqrt(strength);
                if ((!Number.isFinite(state.rate) || Math.abs(rate - state.rate) >= 0.01 ||
                    Math.abs(level - state.level) >= 0.02) && now - state.updateTime >= 40) {
                    const time = getContext().currentTime;
                    const pitch = state.slot.source.playbackRate;
                    const volume = state.slot.gain.gain;
                    for (const param of [pitch, volume]) {
                        if (typeof param.cancelAndHoldAtTime === 'function') param.cancelAndHoldAtTime(time);
                        else {
                            const value = param.value;
                            param.cancelScheduledValues(time);
                            param.setValueAtTime(value, time);
                        }
                    }
                    pitch.setTargetAtTime(rate, time, 0.1);
                    volume.setTargetAtTime(level, time, 0.12);
                    state.rate = rate;
                    state.level = level;
                    state.updateTime = now;
                }
                if (state.timer !== null) return;
                const checkIdle = () => {
                    const remaining = 450 - (performance.now() - state.lastMove);
                    if (remaining > 0) {
                        state.timer = setTimeout(checkIdle, Math.max(1, remaining));
                        return;
                    }
                    if (state.slot) stop(state.slot, 0.12);
                    state.slot = null;
                    state.velocity = 0;
                    state.timer = null;
                };
                state.timer = setTimeout(checkIdle, 450);
            };

            const moveInnerParam = (param, value, time, constant) => {
                if (typeof param.cancelAndHoldAtTime === 'function') param.cancelAndHoldAtTime(time);
                else {
                    const current = param.value;
                    param.cancelScheduledValues(time);
                    param.setValueAtTime(current, time);
                }
                param.setTargetAtTime(value, time, constant);
            };

            const driveInner = (phase, speed, maximum, now = performance.now(), brakeSeconds = 0) => {
                const id = PANEL_SOUND_GROUPS.innerHand[0];
                if (phase === 'idle') {
                    if (innerMotion.phase === 'idle') return;
                    const time = getContext().currentTime;
                    innerMotion.phase = 'idle';
                    if (innerMotion.intro) {
                        stop(innerMotion.intro, 0.25);
                        innerMotion.intro = null;
                    }
                    if (innerMotion.bed) {
                        moveInnerParam(innerMotion.bed.gain.gain, 0, time, 0.09);
                        const bed = innerMotion.bed;
                        innerMotion.idleTimer = setTimeout(() => {
                            if (innerMotion.phase === 'idle' && innerMotion.bed === bed) {
                                stop(bed, 0.05);
                                innerMotion.bed = null;
                            }
                            innerMotion.idleTimer = null;
                        }, 600);
                    }
                    innerMotion.rate = NaN;
                    innerMotion.level = 0;
                    innerMotion.lastUpdate = -Infinity;
                    return;
                }
                const time = getContext().currentTime;
                if (innerMotion.idleTimer !== null) {
                    clearTimeout(innerMotion.idleTimer);
                    innerMotion.idleTimer = null;
                }
                const strength = Math.max(0, Math.min(1, Math.abs(speed) / maximum));
                const previousPhase = innerMotion.phase;
                if (!innerMotion.bed || innerMotion.bed.stopping) {
                    innerMotion.bed = start(id, {
                        loop: true, gain: 0, offset: PANEL_SOUND_LOOPS[id][0]
                    });
                    innerMotion.intro = start(id, {
                        gain: 0, duration: PANEL_SOUND_LOOPS[id][0]
                    });
                    if (innerMotion.intro) {
                        innerMotion.intro.gain.gain.linearRampToValueAtTime(0.3, time + 0.28);
                    }
                    innerMotion.rate = NaN;
                    innerMotion.level = NaN;
                    innerMotion.lastUpdate = -Infinity;
                }
                if (innerMotion.outro && phase !== 'brake') {
                    stop(innerMotion.outro, 0.3);
                    innerMotion.outro = null;
                }
                if (phase === 'brake' && previousPhase !== 'brake') {
                    if (innerMotion.outro) {
                        stop(innerMotion.outro, 0.25);
                        innerMotion.outro = null;
                    }
                    if (innerMotion.intro) {
                        stop(innerMotion.intro, Math.min(0.35, Math.max(0.18, brakeSeconds * 0.5)));
                        innerMotion.intro = null;
                    }
                    if (now - innerMotion.lastOutroStart >= 300) {
                        const ending = PANEL_SOUND_LOOPS[id][1];
                        const duration = buffers.get(id)?.duration || ending;
                        const remaining = Math.max(0, duration - ending);
                        const matchingOffset = (1 - strength) * 0.43;
                        const timedOffset = remaining - 1.2 * Math.max(0.06, brakeSeconds);
                        const offset = Math.max(0, Math.min(remaining - 0.08, Math.max(matchingOffset, timedOffset)));
                        innerMotion.outro = start(id, { gain: 0, offset: ending + offset });
                        if (innerMotion.outro) {
                            const endingDuration = remaining - offset;
                            const rate = Math.min(1.2, Math.max(0.15, endingDuration / Math.max(0.06, brakeSeconds)));
                            const level = Math.min(0.45, 0.18 + 0.32 * strength);
                            const fade = Math.min(0.38, Math.max(0.2, brakeSeconds * 0.45));
                            innerMotion.outro.source.playbackRate.setValueAtTime(rate, time);
                            innerMotion.outro.gain.gain.linearRampToValueAtTime(level, time + fade);
                            innerMotion.lastOutroStart = now;
                        }
                    }
                }
                innerMotion.phase = phase;
                if (!innerMotion.bed) return;
                const rate = 0.75 + 0.45 * strength;
                const level = 0.9 * Math.sqrt(strength);
                if (now - innerMotion.lastUpdate < 40 && Number.isFinite(innerMotion.rate)) return;
                if (Math.abs(rate - innerMotion.rate) < 0.01 && Math.abs(level - innerMotion.level) < 0.02) return;
                moveInnerParam(innerMotion.bed.source.playbackRate, rate, time, 0.12);
                moveInnerParam(innerMotion.bed.gain.gain, level, time, 0.1);
                innerMotion.rate = rate;
                innerMotion.level = level;
                innerMotion.lastUpdate = now;
            };

            const updateVolume = () => {
                if (!context) return;
                active.forEach(slot => {
                    if (slot.stopping || !slot.electrical) return;
                    slot.gain.gain.setTargetAtTime(
                        slot.baseGain * soundCircuit.volume.value,
                        context.currentTime,
                        0.01
                    );
                });
            };

            const stopElectrical = () => active.forEach(slot => {
                if (slot.electrical) stop(slot);
            });

            const stopAll = () => {
                active.forEach(slot => stop(slot));
                held.clear();
                if (innerMotion.idleTimer !== null) clearTimeout(innerMotion.idleTimer);
                innerMotion.bed = null;
                innerMotion.intro = null;
                innerMotion.outro = null;
                innerMotion.idleTimer = null;
                innerMotion.phase = 'idle';
                innerMotion.lastOutroStart = -Infinity;
                hands.forEach(state => {
                    if (state.timer !== null) clearTimeout(state.timer);
                    state.slot = null;
                    state.timer = null;
                });
                rotary.forEach(state => {
                    if (state.timer !== null) clearTimeout(state.timer);
                    state.bed = null;
                    state.clicks.clear();
                    state.speed = 0;
                    state.timer = null;
                });
            };

            return { load, unlock, play, driveRotary, stopRotary, startHeld, stopHeld, driveHand, driveInner, updateVolume, stopElectrical, stopAll };
        })();
        document.addEventListener('m2terminalsound', event => {
            const group = event.detail;
            if (group !== 'terminalLsk' && group !== 'terminalKey') return;
            panelSoundBank.unlock();
            panelSoundBank.play(group);
        });
        PANEL_SOUND_GROUPS.loadSelector.forEach(id => panelSoundBank.load(id).catch(() => {}));

        let tierOneResourcePromise = null;
        function loadTierOneResources(report = () => {}) {
            if (tierOneResourcePromise) return tierOneResourcePromise;
            const controlEntries = CONTROL_ASSET_FILES.map(name => ({ kind: 'control', name, url: '/m/img/' + name }));
            const soundEntries = Object.entries(UI_SOUND_FILES).map(([name, url]) => ({ kind: 'sound', name, url }));
            const wavEntries = PANEL_WAV_IDS.map(name => ({ kind: 'wav', name }));
            const entries = [...controlEntries, ...soundEntries, ...wavEntries];
            let completed = 0;
            tierOneResourcePromise = Promise.all(entries.map(async entry => {
                if (entry.kind === 'wav') {
                    await panelSoundBank.load(entry.name);
                } else {
                    const blob = await fetchRequiredBlob(entry.url);
                    const objectUrl = URL.createObjectURL(blob);
                    if (entry.kind === 'control') {
                        ASSET[entry.name] = objectUrl;
                    } else if (entry.kind === 'sound') {
                        uiAudio[entry.name] = await prepareUiAudio(objectUrl);
                    }
                }
                completed += 1;
                report(completed, entries.length);
            })).then(() => {
                applyAssetBlobs();
                mSyncControls();
            });
            return tierOneResourcePromise;
        }

        const M2_ARCHIVE_FIXED_ASSETS = [
            '/img/logomonochrome.ico',
            '/css/fonts/JunicodeVF-Roman.woff2',
            '/css/fonts/JunicodeVF-Italic.woff2',
            '/css/fonts/nullpunktsenergiefont-Regular.ttf',
            '/m/img/BabelStoneHan.ttf',
            '/m/serv/npfp.png',
            '/m/css/cursors/MOUSE_DEFAULT_ARROW.png',
            '/m/css/cursors/MOUSE_POINTING_HAND.png',
            '/m/css/cursors/MOUSE_GRABBING_HAND_OPEN.png',
            '/m/css/cursors/MOUSE_GRABBING_HAND_CLOSED.png',
            '/m/css/cursors/MOUSE_BIG_CURVY_ARROW_LEFT.png',
            '/m/css/cursors/MOUSE_BIG_CURVY_ARROW_RIGHT.png',
            '/m/css/cursors/MOUSE_SMALL_CURVY_ARROW_LEFT.png',
            '/m/css/cursors/MOUSE_SMALL_CURVY_ARROW_RIGHT.png',
            '/m/css/cursors/MOUSE_UPSIDEDOWN_CURVY_ARROW_LEFT.png',
            '/m/css/cursors/MOUSE_UPSIDEDOWN_CURVY_ARROW_RIGHT.png',
            '/m/css/cursors/MOUSE_LEFT_ARROW.png',
            '/m/css/cursors/MOUSE_RIGHT_ARROW.png',
            '/m/css/cursors/MOUSE_LEFT_RIGHT_ARROW.png',
            ...CONTROL_ASSET_FILES.map(name => '/m/img/' + name),
            ...Object.values(UI_SOUND_FILES),
            ...PANEL_WAV_IDS.map(id => '/m/img/' + id + '.wav')
        ];

        function collectM2ArchiveManifest() {
            const rawUrls = new Set(M2_ARCHIVE_FIXED_ASSETS);
            document.querySelectorAll('[src], [data-src], [poster], [data-art]').forEach(element => {
                ['src', 'data-src', 'poster', 'data-art'].forEach(attribute => {
                    const value = element.getAttribute(attribute);
                    if (value) rawUrls.add(value);
                });
            });

            return [...new Set([...rawUrls]
                .filter(url => url && !/^(?:blob:|data:|about:)/i.test(url))
                .map(m2VersionedAssetUrl))];
        }

        function m2ArchiveAssetKey(rawUrl) {
            const url = new URL(rawUrl, document.baseURI);
            url.hash = '';
            if (url.origin === location.origin) {
                url.searchParams.delete('v');
                return url.pathname + url.search;
            }
            return url.href;
        }

        async function requestM2ArchiveCatalogue(signal = null) {
            const response = await fetch(new URL('/m/m2list.json', location.origin).href, {
                cache: 'no-store',
                credentials: 'same-origin',
                signal
            });
            if (!response.ok) throw new Error('Archive catalogue HTTP ' + response.status);
            const result = await response.json();
            if (!result || typeof result.list !== 'object' || Array.isArray(result.list)) {
                throw new Error('Archive catalogue is incompatible');
            }
            return result;
        }

        function reconcileM2ArchiveManifest(manifest, catalogue) {
            return manifest.map(url => {
                const key = m2ArchiveAssetKey(url);
                const fingerprint = typeof catalogue?.list?.[key] === 'string' &&
                    /^[a-f0-9]{64}$/i.test(catalogue.list[key])
                    ? catalogue.list[key].toLowerCase()
                    : null;
                return {
                    url,
                    key,
                    fingerprint,
                    sampleBytes: 65536
                };
            });
        }

        function runM2BrowserArchive(installMissing, signal = null) {
            return (async () => {
                if (signal?.aborted) throw new DOMException('Archive operation aborted', 'AbortError');
                const root = document.documentElement;
                root.dataset.m2ArchiveVersion = M2_BROWSER_CACHE_VERSION;
                if (!('serviceWorker' in navigator) || !window.isSecureContext) {
                    root.dataset.m2ArchiveState = 'unsupported';
                    throw new Error('Service Worker requires HTTPS');
                }

                const manifest = collectM2ArchiveManifest();
                const initialTotal = manifest.length + 1;
                const initialCompleted = Math.min(
                    Number(root.dataset.m2ArchiveCompleted || 0),
                    initialTotal
                );
                const initialState = installMissing ? 'preparing' : 'checking';
                root.dataset.m2ArchiveState = initialState;
                root.dataset.m2ArchiveCompleted = String(initialCompleted);
                root.dataset.m2ArchiveTotal = String(initialTotal);
                window.dispatchEvent(new CustomEvent('m2archiveprogress', {
                    detail: {
                        version: M2_BROWSER_CACHE_VERSION,
                        state: initialState,
                        completed: initialCompleted,
                        total: initialTotal,
                        reused: 0,
                        fetched: 0,
                        failures: 0
                    }
                }));
                let catalogue = null;
                try {
                    catalogue = await requestM2ArchiveCatalogue(signal);
                } catch (error) {
                    if (error?.name === 'AbortError') throw error;
                    root.dataset.m2ArchiveState = 'offline';
                }
                if (catalogue && String(catalogue.v) !== M2_BROWSER_CACHE_VERSION) {
                    root.dataset.m2ArchiveLatestVersion = String(catalogue.v);
                    throw new Error('Archive catalogue version ' + catalogue.v +
                        ' does not match page version ' + M2_BROWSER_CACHE_VERSION);
                }
                const assets = reconcileM2ArchiveManifest(manifest, catalogue);
                const { worker } = await ensureM2WorkerRegistration();

                let persistent = false;
                let storageEstimate = null;
                if (navigator.storage) {
                    try {
                        persistent = await navigator.storage.persisted();
                        if (!persistent && navigator.storage.persist) {
                            persistent = await navigator.storage.persist();
                        }
                        if (navigator.storage.estimate) storageEstimate = await navigator.storage.estimate();
                    } catch (error) {}
                }

                const requestId = 'm2-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2);
                const documentUrl = new URL(location.href);
                documentUrl.hash = '';
                return await new Promise((resolve, reject) => {
                    let reconciliationStarted = false;
                    const finish = result => {
                        navigator.serviceWorker.removeEventListener('message', onMessage);
                        signal?.removeEventListener('abort', onAbort);
                        resolve(result);
                    };
                    const fail = error => {
                        navigator.serviceWorker.removeEventListener('message', onMessage);
                        signal?.removeEventListener('abort', onAbort);
                        root.dataset.m2ArchiveState = 'fault';
                        reject(error instanceof Error ? error : new Error(String(error)));
                    };
                    const onAbort = () => {
                        navigator.serviceWorker.removeEventListener('message', onMessage);
                        signal?.removeEventListener('abort', onAbort);
                        root.dataset.m2ArchiveState = 'standby';
                        reject(new DOMException('Archive operation aborted', 'AbortError'));
                    };
                    const onMessage = event => {
                        const message = event.data;
                        if (!message || message.requestId !== requestId) return;
                        if (message.type === 'M2_ARCHIVE_CHECK_PROGRESS') {
                            const completed = Math.max(
                                Number(root.dataset.m2ArchiveCompleted || 0),
                                Number(message.completed || 0)
                            );
                            root.dataset.m2ArchiveState = 'checking';
                            root.dataset.m2ArchiveCompleted = String(completed);
                            root.dataset.m2ArchiveTotal = String(message.total);
                            window.dispatchEvent(new CustomEvent('m2archiveprogress', {
                                detail: { ...message, state: 'checking', completed }
                            }));
                            return;
                        }
                        if (message.type === 'M2_ARCHIVE_RECONCILE_REQUIRED') {
                            const state = installMissing ? 'reconciling' : 'incomplete';
                            root.dataset.m2ArchiveState = state;
                            root.dataset.m2ArchiveCompleted = String(message.completed || 0);
                            root.dataset.m2ArchiveTotal = String(message.total || manifest.length);
                            const result = {
                                ...message,
                                state,
                                complete: false,
                                persistent,
                                storageEstimate
                            };
                            window.dispatchEvent(new CustomEvent('m2archiveprogress', { detail: result }));
                            if (!installMissing) {
                                finish(result);
                                return;
                            }
                            if (reconciliationStarted) return;
                            reconciliationStarted = true;
                            worker.postMessage({
                                type: 'M2_ARCHIVE_INSTALL',
                                requestId,
                                version: M2_BROWSER_CACHE_VERSION,
                                assets,
                                documents: [documentUrl.href],
                                concurrency: 3
                            });
                            return;
                        }
                        if (message.type === 'M2_ARCHIVE_PROGRESS') {
                            root.dataset.m2ArchiveState = 'installing';
                            root.dataset.m2ArchiveCompleted = String(message.completed);
                            root.dataset.m2ArchiveTotal = String(message.total);
                            window.dispatchEvent(new CustomEvent('m2archiveprogress', { detail: message }));
                            return;
                        }
                        if (message.type === 'M2_ARCHIVE_COMPLETE') {
                            root.dataset.m2ArchiveState = 'complete';
                            root.dataset.m2ArchiveCompleted = String(message.total);
                            root.dataset.m2ArchiveTotal = String(message.total);
                            const result = { ...message, state: 'complete', complete: true, persistent, storageEstimate };
                            window.dispatchEvent(new CustomEvent('m2archivecomplete', { detail: result }));
                            finish(result);
                            return;
                        }
                        if (message.type === 'M2_ARCHIVE_ERROR') {
                            fail(new Error(message.error || 'Browser archive installation failed'));
                        }
                    };
                    navigator.serviceWorker.addEventListener('message', onMessage);
                    signal?.addEventListener('abort', onAbort, { once: true });
                    if (signal?.aborted) {
                        onAbort();
                        return;
                    }
                    worker.postMessage({
                        type: 'M2_ARCHIVE_CHECK',
                        requestId,
                        version: M2_BROWSER_CACHE_VERSION,
                        assets,
                        documents: [documentUrl.href]
                    });
                });
            })().catch(error => {
                if (error?.name === 'AbortError') throw error;
                window.dispatchEvent(new CustomEvent('m2archivefault', {
                    detail: {
                        state: document.documentElement.dataset.m2ArchiveState || 'fault',
                        completed: Number(document.documentElement.dataset.m2ArchiveCompleted || 0),
                        total: Number(document.documentElement.dataset.m2ArchiveTotal || 0),
                        error: String(error?.message || error)
                    }
                }));
                throw error;
            });
        }

        function checkM2BrowserArchive(signal = null) {
            return runM2BrowserArchive(false, signal);
        }

        function installM2BrowserArchive(signal = null) {
            return runM2BrowserArchive(true, signal);
        }

        window.m2Archive = Object.freeze({
            version: M2_BROWSER_CACHE_VERSION,
            manifest: collectM2ArchiveManifest,
            install: () => {
                const frame = avionicsBus.transmit('ARCHIVE.INSTALL', {}, 'M2 ARCHIVE API');
                if (!frame.handled) return Promise.reject(frame.errors[0] || new Error('ARCHIVE AVIONICS UNIT is not powered'));
                return Promise.resolve(frame.results[0]);
            }
        });

        function applyAssetBlobs() {
            document.querySelectorAll('[data-bg]').forEach(el => {
                el.style.backgroundImage = 'url(' + aimg(el.dataset.bg) + ')';
            });
            document.querySelectorAll('img[data-asset]').forEach(el => {
                el.src = aimg(el.dataset.asset);
            });
        }
        function bindUiSoundActivity(audio) {
            if (audio.__m2ActivityBound) return;
            audio.__m2ActivityBound = true;
            const inactive = () => soundCircuit.setActivity(audio, false);
            audio.addEventListener('pause', inactive);
            audio.addEventListener('ended', inactive);
            audio.addEventListener('error', inactive);
        }

        function driveMechanicalSound(name) {
            const a = uiAudio[name];
            if (!a || !MECHANICAL_SOUND_NAMES.has(name)) return false;
            try {
                a.loop = false;
                a.currentTime = 0;
                const result = a.play();
                if (result && typeof result.catch === 'function') {
                    result.catch(() => {});
                }
                return true;
            } catch (e) {
                return false;
            }
        }

        function playMechanicalSound(name) {
            return avionicsBus.transmit('PANEL.MECHANICAL.PLAY', { name }, 'PAGE PANEL').handled;
        }

        function driveElectricalSound(name) {
            const a = uiAudio[name];
            if (!a || !ELECTRICAL_SOUND_NAMES.has(name) || !soundCircuit.powerContact.closed) return false;
            bindUiSoundActivity(a);
            try {
                a.loop = false;
                a.volume = soundCircuit.volume.value;
                a.currentTime = 0;
                soundCircuit.setActivity(a, true);
                const result = a.play();
                if (result && typeof result.catch === 'function') {
                    result.catch(() => soundCircuit.setActivity(a, false));
                }
                return true;
            } catch (e) {
                soundCircuit.setActivity(a, false);
                return false;
            }
        }

        function playElectricalSound(name) {
            return avionicsBus.transmit('SOUND.UI.PLAY', { name }, 'PAGE PANEL').handled;
        }

        function driveStartAlarm() {
            const a = uiAudio.firealarm;
            if (!a || !soundCircuit.powerContact.closed) return false;
            bindUiSoundActivity(a);
            try {
                a.loop = true;
                a.volume = soundCircuit.volume.value;
                a.currentTime = 0;
                soundCircuit.setActivity(a, true);
                const result = a.play();
                if (result && typeof result.catch === 'function') {
                    result.catch(() => soundCircuit.setActivity(a, false));
                }
                return true;
            } catch (e) {
                soundCircuit.setActivity(a, false);
                return false;
            }
        }

        function startAlarm() {
            return avionicsBus.transmit('SOUND.ALARM.START', {}, 'PAGE PANEL').handled;
        }

        function driveStopAlarm() {
            const a = uiAudio.firealarm;
            if (!a) return false;
            try {
                a.pause();
                a.currentTime = 0;
                soundCircuit.setActivity(a, false);
                return true;
            } catch (e) {
                return false;
            }
        }

        function stopAlarm() {
            return avionicsBus.transmit('SOUND.ALARM.STOP', {}, 'PAGE PANEL').handled;
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



        if ('mediaSession' in navigator) {
            const ms = navigator.mediaSession;
            const h = (name, fn) => {
                try {
                    ms.setActionHandler(name, fn);
                } catch (e) {}
            };
            h('play', () => {
                if (currentAudio) playAudio(currentAudio, 'media session');
            });
            h('pause', () => {
                if (currentAudio) {
                    playbackCircuit?.dropPl();
                    currentAudio.pause();
                }
            });
            h('previoustrack', () => prevBtn.click());
            h('nexttrack', () => nextBtn.click());
            h('seekbackward', (d) => {
                if (currentAudio) queuePhysicalSeek(currentAudio, Math.max(0, playbackTime(currentAudio) - (d.seekOffset || 5)));
            });
            h('seekforward', (d) => {
                if (currentAudio) queuePhysicalSeek(currentAudio, Math.min(playbackDuration(currentAudio), playbackTime(currentAudio) + (d.seekOffset || 5)));
            });
            h('seekto', (d) => {
                if (currentAudio && d.seekTime != null) queuePhysicalSeek(currentAudio, d.seekTime);
            });
        }

        function updateReverbVisuals() {
            const revTime = document.getElementById('revTime');
            const revFill = document.getElementById('revFill');
            const revDot = document.getElementById('revDot');
            const reverb = soundCircuit.reverb.value;
            if (revDot) revDot.style.top = ((1 - reverb) * 100) + '%';
            if (revFill) {
                revFill.style.top = ((1 - reverb) * 100) + '%';
                revFill.style.height = (reverb * 100) + '%';
            }
            if (revTime) revTime.textContent = Math.round(reverb * 100) + '%';
            renderRevKnob();
            if (wetGain && dryGain && audioCtx && wetInputGain && convolver) {
                const time = audioCtx.currentTime;
                const retarget = (param, value) => {
                    if (typeof param.cancelAndHoldAtTime === 'function') param.cancelAndHoldAtTime(time);
                    else {
                        const current = param.value;
                        param.cancelScheduledValues(time);
                        param.setValueAtTime(current, time);
                    }
                    param.setTargetAtTime(value, time, 0.05);
                };
                if (reverb > 0) {
                    if (!convolverConnected) {
                        try {
                            wetInputGain.connect(convolver);
                            convolver.connect(wetGain);
                            convolverConnected = true;
                        } catch (e) {}
                    }
                    retarget(wetGain.gain, reverb * 1.5);
                    retarget(dryGain.gain, 1 - (reverb * 0.5));
                } else {
                    if (convolverConnected) {
                        try {
                            wetInputGain.disconnect(convolver);
                            convolver.disconnect(wetGain);
                            convolverConnected = false;
                        } catch (e) {}
                    }
                    retarget(wetGain.gain, 0);
                    retarget(dryGain.gain, 1);
                }
            }
        }

        function initAudioContext() {
            if (audioCtx) return;
            const AudioContext = window.AudioContext || window.webkitAudioContext;
            audioCtx = new AudioContext({ latencyHint: 'playback' });
            convolver = audioCtx.createConvolver();
            dryGain = audioCtx.createGain();
            wetGain = audioCtx.createGain();
            wetInputGain = audioCtx.createGain();
            masterGain = audioCtx.createGain();

            dryGain.connect(masterGain);
            wetGain.connect(masterGain);
            masterGain.connect(audioCtx.destination);
            masterGain.gain.value = soundCircuit.powerContact.closed ? 1 : 0;

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

        const audioRecovery = new WeakMap();

        function resetAudioRecovery(audio) {
            let state = audioRecovery.get(audio);
            if (!state) {
                state = { timer: null, attempts: 0, generation: 0 };
                audioRecovery.set(audio, state);
            }
            if (state.timer) clearTimeout(state.timer);
            state.timer = null;
            state.attempts = 0;
            state.generation++;
            return state;
        }

        function clearAudioRecovery(audio) {
            const state = audioRecovery.get(audio);
            if (state?.timer) clearTimeout(state.timer);
            if (state) state.timer = null;
        }

        function scheduleAudioRecovery(audio, reason) {
            if (!audio || audio !== currentAudio || audio.paused || audio.ended) return;
            let state = audioRecovery.get(audio);
            if (!state) state = resetAudioRecovery(audio);
            if (state.timer || state.attempts >= 1) return;
            const generation = state.generation;
            state.timer = setTimeout(() => {
                state.timer = null;
                if (generation !== state.generation || audio !== currentAudio || audio.paused ||
                    audio.ended || audio.readyState >= HTMLMediaElement.HAVE_FUTURE_DATA) return;
                state.attempts++;
                const resumeAt = audio.currentTime || 0;
                const resume = () => {
                    if (generation !== state.generation || audio !== currentAudio) return;
                    try {
                        audio.currentTime = Math.min(resumeAt, audio.duration || resumeAt);
                    } catch (error) {}
                    playAudio(audio, 'stall recovery');
                };
                audio.addEventListener('loadedmetadata', resume, { once: true });
                audio.preload = 'auto';
                audio.load();
            }, 6000);
        }

        function loadAudio(a) {
            if (krLoopAudio && krLoopAudio !== a) clearKrSectionLoop();
            resetAudioRecovery(a);
            initAudioContext();
            if (soundCircuit.powerContact.closed && audioCtx.state === 'suspended') audioCtx.resume();
            if (!a.getAttribute('src')) {
                a.setAttribute('src', m2VersionedAssetUrl(a.getAttribute('data-src')));
                a.load();
            }
            if (a.__virtualSong) {
                focusMetadataQueue(a.__virtualSong);
                ensureVirtualMetadata(a.__virtualSong, a).then(ready => {
                    if (ready) updateVirtualMemberTimes(a.__virtualSong);
                });
            } else focusMetadataQueue(null);
            const card = a.closest('.card');
            const media = activeMediaForCard(card);
            if (media && window.loadMediaEl) window.loadMediaEl(media);
            if (!sourceMap.has(a)) {
                try {
                    const source = audioCtx.createMediaElementSource(a);
                    source.connect(dryGain);
                    source.connect(wetInputGain);
                    sourceMap.set(a, source);
                } catch (e) {}
            }
            return true;
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
            const card = () => audio.closest('.cardWrap');
            audio.addEventListener('waiting', () => {
                if (audio !== currentAudio) return;
                startLoadingAnim(card());
                scheduleAudioRecovery(audio, 'waiting');
            });
            audio.addEventListener('stalled', () => {
                if (audio !== currentAudio) return;
                startLoadingAnim(card());
                scheduleAudioRecovery(audio, 'stalled');
            });
            audio.addEventListener('canplay', () => {
                clearAudioRecovery(audio);
                if (audio === currentAudio) stopLoadingAnim(card());
            });
            audio.addEventListener('playing', () => {
                clearAudioRecovery(audio);
                if (audio === currentAudio) stopLoadingAnim(card());
            });
            audio.addEventListener('pause', () => {
                clearAudioRecovery(audio);
                if (audio === currentAudio) stopLoadingAnim(card());
            });
            audio.addEventListener('error', () => {
                clearAudioRecovery(audio);
                if (audio === currentAudio) {
                    stopLoadingAnim(card());
                }
            });
        });
        let TOKEN = '';
        const zone = document.getElementById('entryZone');
        const progressZone = document.getElementById('progressZone');
        const uBox = document.querySelector('.uBox');
        const SALT = 'asfjaƕꜹacvkasjsajfashfasufghjgs';
        let currentAudio = null;
        const np = document.getElementById('npTitle');

        const virtualSongs = [];
        const METADATA_TIMEOUT_MS = 45000;
        const metadataQueue = [];
        const metadataJobs = new WeakMap();
        let metadataActive = 0;
        let metadataActiveJob = null;

        function mediaState(audio) {
            return {
                src: audio.currentSrc || audio.getAttribute('src') || audio.dataset.src || '',
                readyState: audio.readyState,
                networkState: audio.networkState,
                mediaError: audio.error ? { code: audio.error.code, message: audio.error.message || '' } : null
            };
        }

        function playAudio(audio, reason = 'play') {
            if (!audio) return Promise.resolve(false);
            if (playbackCircuit && isrAt('E') && !legsExecutor?.active) return Promise.resolve(false);
            if (!soundCircuit.powerContact.closed) {
                playbackCircuit?.dropPl();
                if (!audio.paused) audio.pause();
                return Promise.resolve(false);
            }
            document.dispatchEvent(new CustomEvent('m2songchange', { detail: audio }));
            playbackCircuit?.energizePl();
            try {
                const result = audio.play();
                if (!result || typeof result.catch !== 'function') return Promise.resolve(true);
                return result.then(() => true).catch(error => {
                    playbackCircuit?.dropPl();
                    return false;
                });
            } catch (error) {
                playbackCircuit?.dropPl();
                return Promise.resolve(false);
            }
        }

        function pumpMetadataQueue() {
            while (metadataActive < 1 && metadataQueue.length) {
                const job = metadataQueue.shift();
                const audio = job.audio;
                job.status = 'active';
                metadataActive++;
                metadataActiveJob = job;

                if (audio.duration && isFinite(audio.duration)) {
                    metadataJobs.delete(audio);
                    metadataActive--;
                    metadataActiveJob = null;
                    job.resolve(true);
                    updateVirtualMemberTimes(audio.__virtualSong);
                    continue;
                }

                let settled = false;
                const finish = (ok, reason, reportFailure = true) => {
                    if (settled) return;
                    settled = true;
                    clearTimeout(timer);
                    audio.removeEventListener('loadedmetadata', onMetadata);
                    audio.removeEventListener('error', onError);
                    audio.removeEventListener('abort', onAbort);
                    metadataJobs.delete(audio);
                    metadataActive--;
                    metadataActiveJob = null;
                    if (!ok && reportFailure) {
                        audio.__metadataRetryAfter = Date.now() + 30000;
                    } else if (ok) audio.__metadataRetryAfter = 0;
                    job.resolve(ok);
                    updateVirtualMemberTimes(audio.__virtualSong);
                    pumpMetadataQueue();
                };
                const onMetadata = () => finish(true, 'loaded');
                const onError = () => finish(false, 'error');
                const onAbort = () => finish(false, 'aborted');
                const timer = setTimeout(() => finish(false, 'timed out'), METADATA_TIMEOUT_MS);
                audio.addEventListener('loadedmetadata', onMetadata, { once: true });
                audio.addEventListener('error', onError, { once: true });
                audio.addEventListener('abort', onAbort, { once: true });
                job.cancel = () => finish(false, 'cancelled', false);
                if (!audio.getAttribute('src') && audio.dataset.src) {
                    audio.preload = 'metadata';
                    audio.setAttribute('src', audio.dataset.src);
                    audio.load();
                }
            }
        }

        function queueAudioMetadata(audio, priority = false) {
            if (!audio || (audio.duration && isFinite(audio.duration))) return Promise.resolve(true);
            const existing = metadataJobs.get(audio);
            if (existing) {
                if (priority) existing.priority = true;
                if (priority && existing.status === 'queued') {
                    const index = metadataQueue.indexOf(existing);
                    if (index >= 0) {
                        metadataQueue.splice(index, 1);
                        metadataQueue.unshift(existing);
                    }
                }
                return existing.promise;
            }
            if (!priority && audio.__metadataRetryAfter > Date.now()) return Promise.resolve(false);

            let resolve;
            const promise = new Promise(done => { resolve = done; });
            const job = { audio, resolve, promise, status: 'queued', priority };
            metadataJobs.set(audio, job);
            if (priority) metadataQueue.unshift(job);
            else metadataQueue.push(job);
            pumpMetadataQueue();
            return promise;
        }

        function focusMetadataQueue(info) {
            for (let i = metadataQueue.length - 1; i >= 0; i--) {
                const job = metadataQueue[i];
                if (info && job.audio.__virtualSong === info) continue;
                metadataQueue.splice(i, 1);
                metadataJobs.delete(job.audio);
                job.resolve(false);
            }
            if (metadataActiveJob && (!info || metadataActiveJob.audio.__virtualSong !== info)) {
                metadataActiveJob.cancel?.();
            }
        }

        function virtualTitleWords(title) {
            return (title || '')
                .normalize('NFKC')
                .replace(/[\u2010-\u2015\u2212]/g, '-')
                .replace(/\s+/g, ' ')
                .trim()
                .toLocaleLowerCase()
                .split(' ')
                .filter(Boolean);
        }

        function virtualSeriesPrefixLength(group) {
            if (!group.length) return 0;
            let prefixLength = group[0].words.length;
            for (let i = 1; i < group.length; i++) {
                prefixLength = Math.min(prefixLength, group[i].words.length);
                for (let word = 0; word < prefixLength; word++) {
                    if (group[i].words[word] !== group[0].words[word]) {
                        prefixLength = word;
                        break;
                    }
                }
            }

            const displayWords = group[0].title.replace(/\s+/g, ' ').trim().split(' ');
            while (prefixLength && /^(?:№|&|[\u2010-\u2015\u2212-]+)$/u.test(displayWords[prefixLength - 1])) {
                prefixLength--;
            }
            return prefixLength;
        }

        function virtualSeriesTitle(group, prefixLength) {
            if (!group.length) return 'series';
            const displayWords = group[0].title.replace(/\s+/g, ' ').trim().split(' ');
            return (displayWords.slice(0, prefixLength).join(' ') || group[0].title) + ' series';
        }

        function virtualMemberDisplayTitle(title, prefixLength) {
            const displayWords = title.replace(/\s+/g, ' ').trim().split(' ');
            const displayTitle = displayWords.slice(prefixLength).join(' ')
                .replace(/^[\u2010-\u2015\u2212-]+\s*/u, '');
            return displayTitle || title;
        }

        function collectVirtualGroups(items) {
            const root = { children: new Map(), terminal: [] };
            items.forEach(item => {
                let node = root;
                item.words.forEach(word => {
                    if (!node.children.has(word)) {
                        node.children.set(word, { children: new Map(), terminal: [] });
                    }
                    node = node.children.get(word);
                });
                node.terminal.push(item);
            });

            const visit = (node, depth) => {
                let loose = [...node.terminal];
                let groups = [];
                let childMadeGroup = false;
                node.children.forEach(child => {
                    const result = visit(child, depth + 1);
                    loose.push(...result.loose);
                    groups.push(...result.groups);
                    if (result.madeGroup) childMadeGroup = true;
                });
                if (depth > 0 && !childMadeGroup && loose.length > 1) {
                    return { loose: [], groups: [...groups, loose], madeGroup: true };
                }
                return { loose, groups, madeGroup: childMadeGroup };
            };

            return visit(root, 0).groups;
        }

        function ensureVirtualMetadata(info, priorityAudio = null) {
            if (!info) return Promise.resolve(false);
            const members = [...info.members];
            if (priorityAudio) {
                members.sort((a, b) => (b.audio === priorityAudio) - (a.audio === priorityAudio));
            }
            return Promise.all(members.map(member =>
                queueAudioMetadata(member.audio, member.audio === priorityAudio)
            )).then(() => updateVirtualMemberTimes(info));
        }

        function updateVirtualMemberTimes(info) {
            if (!info) return false;
            let start = 0;
            let ready = true;
            info.members.forEach(member => {
                member.virtualStart = ready ? start : null;
                if (member.line) member.line.dataset.t = ready ? String(start) : '';
                const duration = member.audio.duration;
                if (duration && isFinite(duration) && ready) start += duration;
                else ready = false;
            });
            info.timelineReady = ready;
            info.virtualDuration = ready ? start : 0;
            return ready;
        }

        function activeVirtualMember(info) {
            return info ? info.members[info.activeIndex] : null;
        }

        function synchronizeVirtualMemberControls(info, member) {
            const sourceEdit = member.wrap.querySelector('.editBtn');
            const editHref = sourceEdit?.getAttribute('href');
            if (editHref) {
                info.hostWrap.querySelectorAll('.editBtn').forEach(button => {
                    button.setAttribute('href', editHref);
                });
            }
            const sourceComment = member.wrap.querySelector('#comBtn, .mob-com-btn');
            const commentHref = sourceComment?.getAttribute('href');
            if (commentHref) {
                info.hostWrap.querySelectorAll('#comBtn, .mob-com-btn').forEach(button => {
                    button.setAttribute('href', commentHref);
                });
            }
        }

        function setActiveVirtualMember(audio) {
            const info = audio?.__virtualSong;
            if (!info) return null;
            const index = audio.__virtualIndex;
            if (index < 0 || index >= info.members.length) return null;
            info.activeIndex = index;
            const active = info.members[index];
            info.members.forEach(member => {
                if (!member.media) return;
                const on = member === active;
                member.media.style.display = on ? member.mediaDisplay : 'none';
                if (!on && member.media.tagName === 'VIDEO') member.media.pause();
            });
            info.members.forEach(member => {
                if (member.line) member.line.classList.toggle('lrcActive', member === active);
            });
            info.hostCard.dataset.art = active.art || '';
            synchronizeVirtualMemberControls(info, active);
            if (active.media && window.loadMediaEl) window.loadMediaEl(active.media);
            return active;
        }

        function activeMediaForCard(card) {
            const info = card?.__virtualSong;
            if (info) return activeVirtualMember(info)?.media || null;
            return card?.querySelector('.cImg img, .cImg video') || null;
        }

        function audioForCard(card) {
            if (!card) return null;
            if (card.__virtualSong) return activeVirtualMember(card.__virtualSong)?.audio || null;
            if (card.__virtualMember) return card.__virtualMember.audio;
            return card.querySelector('audio');
        }

        function visibleCardFor(card) {
            return card?.__virtualMember?.info?.hostCard || card;
        }

        function cardAudioIsCurrent(card) {
            return visibleCardFor(currentAudio?.closest('.card')) === card;
        }

        function selectCardAudio(audio, card) {
            if (playbackCircuit && isrAt('E') && !legsExecutor?.selecting) return false;
            const switchingCard = !cardAudioIsCurrent(card);
            if (switchingCard) playbackCircuit?.dropPl();
            document.querySelectorAll('audio').forEach(other => {
                if (other !== audio || switchingCard) {
                    other.pause();
                    other.loop = false;
                }
            });
            currentAudio = audio;
            setActiveVirtualMember(audio);
            loadAudio(audio);
            audio.volume = soundCircuit.volume.value;
            audio.playbackRate = soundCircuit.playbackRate;
            return switchingCard;
        }

        function activateCardAudio(audio, switchingCard, reason) {
            if (currentAudio !== audio) return;
            if (switchingCard) requestPlOnWithSound(audio);
            else if (audio.paused) {
                updateLoopState();
                playAudio(audio, reason);
            }
        }

        function playbackDuration(audio) {
            const info = audio?.__virtualSong;
            if (!info) return audio?.duration || 0;
            let total = 0;
            for (const member of info.members) {
                const duration = member.audio.duration;
                if (!duration || !isFinite(duration)) return 0;
                total += duration;
            }
            return total;
        }

        function playbackTime(audio) {
            const info = audio?.__virtualSong;
            if (!info) return audio?.currentTime || 0;
            let time = audio.currentTime || 0;
            for (let i = 0; i < audio.__virtualIndex; i++) {
                const duration = info.members[i].audio.duration;
                if (duration && isFinite(duration)) time += duration;
            }
            return time;
        }

        function playVirtualMember(info, index, localTime = 0, autoplay = true) {
            if (!info || !info.members[index]) return null;
            const playIntent = (info.playIntent || 0) + 1;
            info.playIntent = playIntent;
            const member = info.members[index];
            const audio = member.audio;
            if (currentAudio && currentAudio !== audio) currentAudio.pause();
            currentAudio = audio;
            setActiveVirtualMember(audio);
            loadAudio(audio);
            audio.volume = soundCircuit.volume.value;
            audio.playbackRate = soundCircuit.playbackRate;
            audio.loop = false;
            const apply = () => {
                if (info.playIntent !== playIntent || currentAudio !== audio) return;
                try {
                    audio.currentTime = Math.max(0, Math.min(localTime, audio.duration || localTime));
                } catch (e) {}
                syncKrSectionLoop(audio, playbackTime(audio));
                updateLoopState();
                if (autoplay) playAudio(audio, 'virtual member');
            };
            if (audio.readyState >= 1) apply();
            else audio.addEventListener('loadedmetadata', apply, { once: true });
            return audio;
        }

        function seekPlayback(audio, time, autoplay = null) {
            if (!audio) return null;
            const info = audio.__virtualSong;
            if (!info) {
                const targetTime = Math.max(0, Math.min(time, audio.duration || time));
                audio.currentTime = targetTime;
                if (audio === currentAudio) syncKrSectionLoop(audio, targetTime);
                return audio;
            }
            const wasPlaying = autoplay === null ? !audio.paused : autoplay;
            const total = playbackDuration(audio);
            if (!total) {
                const seekIntent = (info.seekIntent || 0) + 1;
                info.seekIntent = seekIntent;
                ensureVirtualMetadata(info, audio).then(ready => {
                    if (ready && info.seekIntent === seekIntent && currentAudio?.__virtualSong === info) {
                        seekPlayback(currentAudio, time, wasPlaying);
                    }
                });
                return audio;
            }
            let remaining = Math.max(0, Math.min(time, total));
            let index = info.members.length - 1;
            for (let i = 0; i < info.members.length; i++) {
                const duration = info.members[i].audio.duration;
                if (remaining < duration || i === info.members.length - 1) {
                    index = i;
                    break;
                }
                remaining -= duration;
            }
            if (info.members[index].audio === currentAudio && currentAudio.readyState >= 1) {
                currentAudio.currentTime = Math.max(0, Math.min(remaining, currentAudio.duration));
                syncKrSectionLoop(currentAudio, playbackTime(currentAudio));
                if (wasPlaying && currentAudio.paused) playAudio(currentAudio, 'virtual member');
                return currentAudio;
            }
            return playVirtualMember(info, index, remaining, wasPlaying);
        }

        function activeSongId(card) {
            const info = card?.__virtualSong || card?.__virtualMember?.info;
            return info ? activeVirtualMember(info).id : card?.id;
        }

        function advanceVirtualSong(audio) {
            const info = audio?.__virtualSong;
            if (!info) return false;
            const index = audio.__virtualIndex;
            if (index < info.members.length - 1) {
                playVirtualMember(info, index + 1, 0, true);
                return true;
            }
            return false;
        }

        function buildVirtualSongs() {
            const byCategory = new Map();
            [...document.querySelectorAll('.cardWrap')].forEach((wrap, order) => {
                const card = wrap.querySelector('.card');
                const audio = card?.querySelector('audio');
                const lyrics = card?.querySelector('.cLyr')?.textContent.trim() || '';
                const title = card?.querySelector('.cName')?.textContent.trim() || '';
                if (!card || !audio || lyrics !== '' || title === '') return;
                const item = { wrap, card, audio, title, words: virtualTitleWords(title), order };
                if (!item.words.length) return;
                const category = wrap.dataset.category || '';
                if (!byCategory.has(category)) byCategory.set(category, []);
                byCategory.get(category).push(item);
            });

            byCategory.forEach(items => {
                collectVirtualGroups(items).forEach(group => {
                    group.sort((a, b) => a.order - b.order);
                    const hostItem = group[0];
                    const displayPrefixLength = virtualSeriesPrefixLength(group);
                    const info = {
                        hostCard: hostItem.card,
                        hostWrap: hostItem.wrap,
                        seriesTitle: virtualSeriesTitle(group, displayPrefixLength),
                        activeIndex: 0,
                        members: []
                    };
                    group.forEach((item, index) => {
                        const media = item.card.querySelector('.cImg img, .cImg video');
                        const member = {
                            info,
                            card: item.card,
                            wrap: item.wrap,
                            audio: item.audio,
                            media,
                            mediaDisplay: media?.style.display || '',
                            id: item.card.id,
                            title: item.title,
                            displayTitle: virtualMemberDisplayTitle(item.title, displayPrefixLength),
                            art: item.card.dataset.art || '',
                            index
                        };
                        info.members.push(member);
                        item.card.__virtualMember = member;
                        item.audio.__virtualSong = info;
                        item.audio.__virtualIndex = index;
                        item.audio.dataset.songId = member.id;
                        item.audio.addEventListener('loadedmetadata', () => updateVirtualMemberTimes(info));
                        item.audio.addEventListener('durationchange', () => updateVirtualMemberTimes(info));
                        if (media) {
                            media.__virtualMember = member;
                            if (index > 0) {
                                info.hostCard.querySelector('.cImg').appendChild(media);
                            }
                        }
                        if (index > 0) {
                            info.hostCard.appendChild(item.audio);
                            item.wrap.classList.add('virtual-song-member');
                            item.wrap.style.display = 'none';
                        }
                    });
                    info.hostCard.__virtualSong = info;
                    info.hostWrap.__virtualSong = info;
                    info.hostWrap.dataset.virtualMembers = String(info.members.length);
                    info.hostCard.dataset.title = info.seriesTitle;
                    const titleEl = info.hostCard.querySelector('.cName');
                    if (titleEl) titleEl.textContent = info.seriesTitle;

                    const lyricEl = info.hostCard.querySelector('.cLyr');
                    if (lyricEl) {
                        lyricEl.textContent = '';
                        lyricEl.classList.add('virtual-song-lyrics');
                        info.members.forEach(member => {
                            const line = document.createElement('span');
                            line.className = 'lrcLine virtual-song-line';
                            line.dataset.t = '';
                            line.dataset.txt = member.displayTitle;
                            line.dataset.songId = member.id;
                            line.dataset.virtualMember = String(member.index);
                            line.textContent = member.displayTitle;
                            member.line = line;
                            lyricEl.appendChild(line);
                        });
                    }
                    virtualSongs.push(info);
                    setActiveVirtualMember(info.members[0].audio);
                    updateVirtualMemberTimes(info);
                });
            });
        }

        buildVirtualSongs();
        window.__npPlaybackDuration = playbackDuration;
        window.__npPlaybackTime = playbackTime;


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
        const seekRadialSvg = document.getElementById('seekRadial');
        const seekRadialNamespace = 'http://www.w3.org/2000/svg';
        const seekRadialNode = (tag, attributes = {}) => {
            const node = document.createElementNS(seekRadialNamespace, tag);
            Object.entries(attributes).forEach(([name, value]) => node.setAttribute(name, String(value)));
            return node;
        };
        const m2SegmentDisplays = new Map();

        class LTS2X01ALTD2000SERIESLTC2000 extends EventTarget {
            static DIGIT_HEIGHT = 7;
            static DIGIT_WIDTH = 4.85;
            static SEGMENT_THICKNESS = .85;
            static DIGIT_PITCH = 7.62;
            static COLON_ADVANCE = 2.77;
            static DECIMAL_X = 5.7;
            static INDICATOR_RADIUS = .425;
            static SLANT = Math.tan(10 * Math.PI / 180);
            static FORWARD_CURRENT = .001;
            static FORWARD_VOLTAGE = 1.6;
            static NOMINAL_VOLTAGE = 28;
            static EFFECTIVE_SEGMENT_RESISTANCE = 28000;
            static SEGMENTS = Object.freeze({
                '0': 'abcdef',
                '1': 'bc',
                '2': 'abdeg',
                '3': 'abcdg',
                '4': 'bcfg',
                '5': 'acdfg',
                '6': 'acdefg',
                '7': 'abc',
                '8': 'abcdefg',
                '9': 'abcdfg',
                '-': 'g',
                ' ': ''
            });

            static slantAt(y) {
                return (this.DIGIT_HEIGHT - y) * this.SLANT;
            }

            static leftAt(y) {
                return this.slantAt(y) + this.SEGMENT_THICKNESS / 2;
            }

            static rightAt(y) {
                return this.slantAt(y) + this.DIGIT_WIDTH - this.DIGIT_HEIGHT * this.SLANT -
                    this.SEGMENT_THICKNESS / 2;
            }

            static segmentPath(start, end) {
                const dx = end.x - start.x;
                const dy = end.y - start.y;
                const length = Math.hypot(dx, dy);
                const ux = dx / length;
                const uy = dy / length;
                const half = this.SEGMENT_THICKNESS / 2;
                const px = -uy * half;
                const py = ux * half;
                const points = [
                    [start.x, start.y],
                    [start.x + ux * half + px, start.y + uy * half + py],
                    [end.x - ux * half + px, end.y - uy * half + py],
                    [end.x, end.y],
                    [end.x - ux * half - px, end.y - uy * half - py],
                    [start.x + ux * half - px, start.y + uy * half - py]
                ];
                return points.map(([x, y], index) =>
                    `${index === 0 ? 'M' : 'L'}${x.toFixed(3)} ${y.toFixed(3)}`
                ).join('') + 'Z';
            }

            static segmentPaths() {
                const top = this.SEGMENT_THICKNESS / 2;
                const middle = this.DIGIT_HEIGHT / 2;
                const bottom = this.DIGIT_HEIGHT - this.SEGMENT_THICKNESS / 2;
                const upperStart = this.SEGMENT_THICKNESS;
                const upperEnd = middle - this.SEGMENT_THICKNESS / 2;
                const lowerStart = middle + this.SEGMENT_THICKNESS / 2;
                const lowerEnd = this.DIGIT_HEIGHT - this.SEGMENT_THICKNESS;
                return Object.freeze({
                    a: this.segmentPath(
                        { x: this.leftAt(top), y: top },
                        { x: this.rightAt(top), y: top }
                    ),
                    b: this.segmentPath(
                        { x: this.rightAt(upperStart), y: upperStart },
                        { x: this.rightAt(upperEnd), y: upperEnd }
                    ),
                    c: this.segmentPath(
                        { x: this.rightAt(lowerStart), y: lowerStart },
                        { x: this.rightAt(lowerEnd), y: lowerEnd }
                    ),
                    d: this.segmentPath(
                        { x: this.leftAt(bottom), y: bottom },
                        { x: this.rightAt(bottom), y: bottom }
                    ),
                    e: this.segmentPath(
                        { x: this.leftAt(lowerStart), y: lowerStart },
                        { x: this.leftAt(lowerEnd), y: lowerEnd }
                    ),
                    f: this.segmentPath(
                        { x: this.leftAt(upperStart), y: upperStart },
                        { x: this.leftAt(upperEnd), y: upperEnd }
                    ),
                    g: this.segmentPath(
                        { x: this.leftAt(middle), y: middle },
                        { x: this.rightAt(middle), y: middle }
                    )
                });
            }

            constructor(host, { powerContact = null, label = '' } = {}) {
                super();
                if (!host) throw new TypeError('LTS-2X01A/LTD-2000/LTC-2000 display host required');
                this.host = host;
                this.model = host.getAttribute('model-segment') || '';
                this.powerContact = powerContact;
                this.label = label || host.getAttribute('aria-label') || 'Segment display';
                this.value = '';
                this.accessibleText = this.label;
                this.digits = [];
                this.indicators = [];
                this.connections = new Map();
                this.conductingSegmentCount = -1;
                this.initialize();
                this.powerContact?.addEventListener('change', () => this.render());
                m2SegmentDisplays.set(host.id, this);
            }

            initialize() {
                if (!this.model || ![...this.model].every(character => '8:.'.includes(character))) {
                    throw new TypeError('Invalid model-segment: ' + this.model);
                }
                const svgHost = this.host.namespaceURI === seekRadialNamespace;
                if (svgHost) {
                    this.layer = this.host;
                } else {
                    this.svg = seekRadialNode('svg', {
                        class: 'lts2x01Display',
                        'aria-hidden': 'true',
                        focusable: 'false',
                        preserveAspectRatio: 'xMidYMid meet'
                    });
                    this.layer = seekRadialNode('g');
                    this.svg.append(this.layer);
                    this.host.replaceChildren(this.svg);
                }
                const paths = this.constructor.segmentPaths();
                let cursor = 0;
                let maximumX = 0;
                [...this.model].forEach((character, modelIndex) => {
                    if (character === '8') {
                        const digit = seekRadialNode('g', {
                            transform: `translate(${cursor} 0)`,
                            'data-digit-slot': this.digits.length
                        });
                        const segments = Object.fromEntries(Object.entries(paths).map(([name, path]) => {
                            const segment = seekRadialNode('path', {
                                class: 'lts2x01Segment is-off',
                                d: path,
                                'data-segment': name
                            });
                            digit.append(segment);
                            return [name, segment];
                        }));
                        const decimal = seekRadialNode('circle', {
                            class: 'lts2x01Indicator is-off',
                            cx: this.constructor.DECIMAL_X,
                            cy: this.constructor.DIGIT_HEIGHT - this.constructor.INDICATOR_RADIUS,
                            r: this.constructor.INDICATOR_RADIUS
                        });
                        digit.append(decimal);
                        this.layer.append(digit);
                        this.digits.push({ modelIndex, segments, decimal, decimalEnabled: false });
                        maximumX = Math.max(
                            maximumX,
                            cursor + this.constructor.DECIMAL_X + this.constructor.INDICATOR_RADIUS
                        );
                        cursor += this.constructor.DIGIT_PITCH;
                        return;
                    }
                    if (character === '.') {
                        const digit = this.digits[this.digits.length - 1];
                        if (!digit || digit.decimalEnabled) {
                            throw new TypeError('Invalid decimal placement in model-segment: ' + this.model);
                        }
                        digit.decimalEnabled = true;
                        return;
                    }
                    const x = cursor;
                    [2.25, 4.75].forEach(y => {
                        const indicator = seekRadialNode('circle', {
                            class: 'lts2x01Indicator is-off',
                            cx: x,
                            cy: y,
                            r: this.constructor.INDICATOR_RADIUS
                        });
                        this.layer.append(indicator);
                        this.indicators.push(indicator);
                    });
                    maximumX = Math.max(maximumX, x + this.constructor.INDICATOR_RADIUS);
                    cursor += this.constructor.COLON_ADVANCE;
                });
                if (this.svg) {
                    this.svg.setAttribute(
                        'viewBox',
                        `0 0 ${maximumX.toFixed(3)} ${this.constructor.DIGIT_HEIGHT}`
                    );
                }
                this.setValue(this.model.replaceAll('8', '0'));
            }

            setValue(value, accessibleText = null) {
                this.value = String(value ?? '');
                if (accessibleText !== null) this.accessibleText = String(accessibleText);
                else this.accessibleText = `${this.label} ${this.value.trim()}`.trim();
                this.render();
            }

            receive(signal) {
                this.setValue(signal?.value ?? '', `${this.label} ${signal?.value ?? ''}`.trim());
            }

            connectElectricalLoad(load, nominalVoltage = this.constructor.NOMINAL_VOLTAGE) {
                if (!load || typeof load.setResistance !== 'function') {
                    throw new TypeError('Segment display electrical load required');
                }
                if (this.connections.has(load)) return load;
                const synchronize = () => {
                    const count = this.conductingSegmentCount;
                    const resistance = count > 0 ?
                        nominalVoltage / (this.constructor.FORWARD_CURRENT * count) :
                        Number.MAX_SAFE_INTEGER;
                    load.setResistance(resistance);
                };
                this.connections.set(load, synchronize);
                this.addEventListener('loadchange', synchronize);
                synchronize();
                return load;
            }

            render() {
                const powered = !this.powerContact || this.powerContact.closed;
                const formatted = this.value.padStart(this.model.length, ' ').slice(-this.model.length);
                let conductingSegmentCount = 0;
                this.digits.forEach(digit => {
                    const lit = this.constructor.SEGMENTS[formatted[digit.modelIndex] ?? ' '] ?? '';
                    conductingSegmentCount += lit.length;
                    Object.entries(digit.segments).forEach(([name, segment]) => {
                        segment.classList.toggle('is-off', !powered || !lit.includes(name));
                    });
                    digit.decimal.classList.toggle('is-off', !powered || !digit.decimalEnabled);
                    if (digit.decimalEnabled) conductingSegmentCount++;
                });
                this.indicators.forEach(indicator => indicator.classList.toggle('is-off', !powered));
                conductingSegmentCount += this.indicators.length;
                this.host.dataset.segmentCount = String(conductingSegmentCount);
                this.host.dataset.timePowered = String(powered);
                this.host.setAttribute(
                    'aria-label',
                    powered ? this.accessibleText : `${this.label}, unpowered`
                );
                if (conductingSegmentCount !== this.conductingSegmentCount) {
                    this.conductingSegmentCount = conductingSegmentCount;
                    this.dispatchEvent(new CustomEvent('loadchange', {
                        detail: Object.freeze({
                            segments: conductingSegmentCount,
                            forwardCurrent: this.constructor.FORWARD_CURRENT,
                            forwardVoltage: this.constructor.FORWARD_VOLTAGE
                        })
                    }));
                }
            }
        }

        const SEEK_RADIAL_MAX_SECONDS = 999 * 60 - 1;
        const INNER_SEEK_INTRO_SECONDS = 78221 / 44100;
        const INNER_SEEK_CRUISE_SECONDS = (222247 - 78221) / 44100;
        const INNER_SEEK_OUTRO_SECONDS = (272078 - 222247) / 44100;
        const INNER_SEEK_MAX_VELOCITY = 270 / (INNER_SEEK_INTRO_SECONDS / 2 + INNER_SEEK_CRUISE_SECONDS + INNER_SEEK_OUTRO_SECONDS / 2);
        const INNER_SEEK_ACCELERATION = INNER_SEEK_MAX_VELOCITY / INNER_SEEK_INTRO_SECONDS;
        const OUTER_SEEK_MAX_VELOCITY = INNER_SEEK_MAX_VELOCITY * 2;
        const OUTER_SEEK_ACCELERATION = OUTER_SEEK_MAX_VELOCITY / INNER_SEEK_INTRO_SECONDS;
        const OUTER_SEEK_BRAKING = OUTER_SEEK_MAX_VELOCITY / INNER_SEEK_OUTRO_SECONDS;
        const seekRadialDisplayFields = seconds => {
            const elapsed = Math.max(0, Math.min(SEEK_RADIAL_MAX_SECONDS, Math.floor(seconds || 0)));
            return {
                minutes: Math.floor(elapsed / 60) + 1,
                seconds: elapsed % 60 + 1
            };
        };
        const seekRadialDisplayTime = seconds => {
            const fields = seekRadialDisplayFields(seconds);
            return `${fields.minutes}:${String(fields.seconds).padStart(2, '0')}`;
        };

        class SeekRadialReadout {
            constructor(group, label) {
                this.label = label;
                this.display = new LTS2X01ALTD2000SERIESLTC2000(group, {
                    powerContact: timeBusPower,
                    label
                });
            }

            render(section, seconds) {
                const safeSection = Math.max(0, Math.min(99, Math.trunc(section || 0)));
                const fields = seekRadialDisplayFields(seconds);
                const value = String(safeSection).padStart(2, ' ') +
                    ':' + String(fields.minutes).padStart(3, ' ') + ':' +
                    String(fields.seconds).padStart(2, '0');
                this.display.setValue(
                    value,
                    `${this.label}, section ${safeSection}, ${seekRadialDisplayTime(seconds)}`
                );
            }
        }

        const mDcAmpsSegmentDisplay = new LTS2X01ALTD2000SERIESLTC2000(
            document.getElementById('mDcAmpsDisplay'),
            { powerContact: timeBusPower, label: 'DC amps' }
        );

        class SeekRadialInstrument {
            constructor(svg, onTarget, onTraverse, onSet, onSectionAdjust, onTimeAdjust) {
                this.svg = svg;
                this.onTarget = onTarget;
                this.onTraverse = onTraverse;
                this.onSet = onSet;
                this.onSectionAdjust = onSectionAdjust;
                this.onTimeAdjust = onTimeAdjust;
                this.control = svg.querySelector('#seekRadialControl');
                this.setButton = svg.querySelector('#seekRadialSet');
                this.targetHand = svg.querySelector('#seekRadialTargetHand');
                this.sectionHand = svg.querySelector('#seekRadialSectionHand');
                this.sectionKnob = svg.querySelector('#seekRadialSectionKnob');
                this.timeKnob = svg.querySelector('#seekRadialTimeKnob');
                this.sectionRotor = svg.querySelector('#seekRadialSectionRotor');
                this.timeRotor = svg.querySelector('#seekRadialTimeRotor');
                this.activeReadout = new SeekRadialReadout(svg.querySelector('#seekRadialActiveDigits'), 'Active position');
                this.standbyReadout = new SeekRadialReadout(svg.querySelector('#seekRadialStandbyDigits'), 'Standby target');
                this.pointerId = null;
                this.pointerAngle = null;
                this.dragAngle = null;
                this.dragCycle = 0;
                this.handCycle = 0;
                this.manualHandAngle = null;
                this.renderedHandAngle = -135;
                this.renderedSectionAngle = -135;
                this.handVelocity = 0;
                this.handLastTime = performance.now();
                this.sectionMotion = null;
                this.releaseHandMotion = false;
                this.requestedHandMotion = false;
                this.powered = false;
                this.sectionMechanicalAngle = 0;
                this.timeMechanicalAngle = 0;
                this.lastOwner = null;
                this.lastActiveSection = 0;
                this.lastActivePercent = 0;
                this.lastSectionMode = false;
                this.hasRenderedModel = false;
                this.setPointerId = null;
                this.buildScales();
                this.bindControl();
                this.bindSet();
                bindM2DirectionalCursor(this.sectionKnob, true);
                bindM2DirectionalCursor(this.timeKnob, true);
                this.bindKnob(this.sectionKnob, this.onSectionAdjust, 'section');
                this.bindKnob(this.timeKnob, this.onTimeAdjust, 'time');
                this.render({
                    activePercent: 0,
                    activeSection: 0,
                    targetSection: 0,
                    activeSeconds: 0,
                    targetSeconds: 0,
                    sectionCount: 99,
                    targetLimitSeconds: SEEK_RADIAL_MAX_SECONDS,
                    sectionRotorAngle: 0,
                    timeRotorAngle: 0
                });
            }

            point(angle, radius) {
                const radians = angle * Math.PI / 180;
                return {
                    x: 195 + Math.sin(radians) * radius,
                    y: 190 - Math.cos(radians) * radius
                };
            }

            appendTick(layer, angle, innerRadius, outerRadius, className) {
                const inner = this.point(angle, innerRadius);
                const outer = this.point(angle, outerRadius);
                layer.append(seekRadialNode('line', {
                    class: className,
                    x1: inner.x,
                    y1: inner.y,
                    x2: outer.x,
                    y2: outer.y
                }));
            }

            appendLabel(layer, angle, radius, value, className) {
                const position = this.point(angle, radius);
                const label = seekRadialNode('text', {
                    class: className,
                    x: position.x,
                    y: position.y
                });
                label.textContent = String(value);
                layer.append(label);
            }

            sectionAngle(value) {
                const bounded = Math.max(0, Math.min(99, value));
                const ratio = bounded <= 7 ?
                    bounded / 7 * .55 :
                    .55 + Math.log(bounded / 7) / Math.log(99 / 7) * .45;
                return -135 + 270 * ratio;
            }

            buildScales() {
                const outerTicks = this.svg.querySelector('#seekRadialOuterTicks');
                const outerLabels = this.svg.querySelector('#seekRadialOuterLabels');
                for (let value = 0; value <= 100; value += 2) {
                    const major = value % 10 === 0;
                    const angle = -135 + 270 * value / 100;
                    this.appendTick(
                        outerTicks,
                        angle,
                        major ? 126 : 132,
                        145,
                        `seekRadialOuterTick ${major ? 'major' : 'minor'}`
                    );
                    if (major) this.appendLabel(outerLabels, angle, 111, value, 'seekRadialDialNumber');
                }
                const innerTicks = this.svg.querySelector('#seekRadialInnerTicks');
                const innerLabels = this.svg.querySelector('#seekRadialInnerLabels');
                [0, 1, 2, 3, 4, 5, 6, 7, 10, 20, 30, 50, 75, 99].forEach(value => {
                    const major = value === 0 || value === 7 || value >= 10;
                    const angle = this.sectionAngle(value);
                    this.appendTick(
                        innerTicks,
                        angle,
                        major ? 67 : 71,
                        82,
                        `seekRadialInnerTick ${major ? 'major' : ''}`
                    );
                    this.appendLabel(innerLabels, angle, 57, value, 'seekRadialDialNumber seekRadialInnerNumber');
                });
            }

            pointerRawAngle(event) {
                const point = this.svg.createSVGPoint();
                point.x = event.clientX;
                point.y = event.clientY;
                const local = point.matrixTransform(this.svg.getScreenCTM().inverse());
                const angle = Math.atan2(local.x - 195, 190 - local.y) * 180 / Math.PI;
                return (angle + 360) % 360;
            }

            pointerPercent(rawAngle) {
                const angle = rawAngle > 180 ? rawAngle - 360 : rawAngle;
                if (angle < -135 || angle > 135) return null;
                return Math.round((angle + 135) / 270 * 1000) / 10;
            }

            nearestTurnAngle(rawAngle, reference) {
                return rawAngle + 360 * Math.round((reference - rawAngle) / 360);
            }

            applyDragAngle() {
                if (!this.powered) {
                    this.manualHandAngle = this.dragAngle;
                    return;
                }
                let start = -135 + this.dragCycle * 360;
                let end = start + 270;
                const nextStart = start + 360;
                const previousEnd = start - 90;
                if (this.dragAngle >= nextStart) {
                    if (this.onTraverse(1)) {
                        this.dragCycle++;
                        this.handCycle = this.dragCycle;
                    } else {
                        this.dragAngle = nextStart - .001;
                    }
                    this.manualHandAngle = this.dragAngle;
                    return;
                }
                if (this.dragAngle <= previousEnd) {
                    if (this.onTraverse(-1)) {
                        this.dragCycle--;
                        this.handCycle = this.dragCycle;
                    } else {
                        this.dragAngle = previousEnd + .001;
                    }
                    this.manualHandAngle = this.dragAngle;
                    return;
                }
                start = -135 + this.dragCycle * 360;
                end = start + 270;
                this.manualHandAngle = this.dragAngle;
                if (this.dragAngle < start) this.onTarget(0);
                else if (this.dragAngle > end) this.onTarget(100);
                else this.onTarget(Math.round((this.dragAngle - start) / 270 * 1000) / 10);
            }

            bindControl() {
                this.control.addEventListener('pointerdown', event => {
                    event.preventDefault();
                    const rawAngle = this.pointerRawAngle(event);
                    const percent = this.pointerPercent(rawAngle);
                    if (percent === null && this.powered) return;
                    this.pointerId = event.pointerId;
                    this.control.setPointerCapture(this.pointerId);
                    this.pointerAngle = rawAngle;
                    this.dragCycle = this.handCycle;
                    this.dragAngle = percent === null ?
                        this.nearestTurnAngle(rawAngle, this.renderedHandAngle) :
                        -135 + percent / 100 * 270 + this.dragCycle * 360;
                    if (this.powered) this.onTarget(percent);
                    this.manualHandAngle = this.dragAngle;
                });
                this.control.addEventListener('pointermove', event => {
                    if (event.pointerId !== this.pointerId) return;
                    const rawAngle = this.pointerRawAngle(event);
                    let delta = rawAngle - this.pointerAngle;
                    if (delta > 180) delta -= 360;
                    if (delta < -180) delta += 360;
                    this.pointerAngle = rawAngle;
                    this.dragAngle += delta;
                    this.applyDragAngle();
                });
                const release = event => {
                    if (event.pointerId !== this.pointerId) return;
                    if (this.control.hasPointerCapture(this.pointerId)) this.control.releasePointerCapture(this.pointerId);
                    this.pointerId = null;
                    this.pointerAngle = null;
                    this.dragAngle = null;
                    if (this.powered) this.manualHandAngle = null;
                    this.releaseHandMotion = this.powered;
                };
                this.control.addEventListener('pointerup', release);
                this.control.addEventListener('pointercancel', release);
                this.control.addEventListener('keydown', event => {
                    const adjustments = {
                        ArrowLeft: -1,
                        ArrowDown: -1,
                        ArrowRight: 1,
                        ArrowUp: 1,
                        PageDown: -5,
                        PageUp: 5
                    };
                    const current = parseFloat(this.control.getAttribute('aria-valuenow')) || 0;
                    let next = current;
                    if (event.key === 'Home') next = 0;
                    else if (event.key === 'End') next = 100;
                    else if (adjustments[event.key]) next += adjustments[event.key];
                    else return;
                    event.preventDefault();
                    if (!this.powered) return;
                    if (next > 100) this.onTraverse(1);
                    else if (next < 0) this.onTraverse(-1);
                    else this.onTarget(next);
                });
            }

            setPressed(pressed) {
                this.setButton.classList.toggle('is-pressed', pressed);
                this.setButton.setAttribute('aria-pressed', String(pressed));
            }

            bindSet() {
                this.setButton.addEventListener('pointerdown', event => {
                    event.preventDefault();
                    panelSoundBank.play('terminalKey');
                    this.setPointerId = event.pointerId;
                    this.setButton.setPointerCapture(this.setPointerId);
                    this.setPressed(true);
                    setTimeBusSetClosed(true);
                });
                this.setButton.addEventListener('pointerup', event => {
                    if (event.pointerId !== this.setPointerId) return;
                    if (this.setButton.hasPointerCapture(this.setPointerId)) this.setButton.releasePointerCapture(this.setPointerId);
                    const transfer = timeBusSetIsActive();
                    this.setPointerId = null;
                    this.setPressed(false);
                    setTimeBusSetClosed(false);
                    if (transfer) this.onSet();
                });
                this.setButton.addEventListener('pointercancel', () => {
                    this.setPointerId = null;
                    this.setPressed(false);
                    setTimeBusSetClosed(false);
                });
                this.setButton.addEventListener('keydown', event => {
                    if (event.key !== 'Enter' && event.key !== ' ') return;
                    event.preventDefault();
                    if (!event.repeat) {
                        panelSoundBank.play('terminalKey');
                        this.setPressed(true);
                        setTimeBusSetClosed(true);
                    }
                });
                this.setButton.addEventListener('keyup', event => {
                    if (event.key !== 'Enter' && event.key !== ' ') return;
                    const transfer = timeBusSetIsActive();
                    this.setPressed(false);
                    setTimeBusSetClosed(false);
                    if (transfer) this.onSet();
                });
                this.setButton.addEventListener('blur', () => {
                    this.setPressed(false);
                    setTimeBusSetClosed(false);
                });
            }

            rotateKnob(kind, steps) {
                const increment = kind === 'section' ? 12 : 8;
                const property = kind === 'section' ? 'sectionMechanicalAngle' : 'timeMechanicalAngle';
                const rotor = kind === 'section' ? this.sectionRotor : this.timeRotor;
                this[property] = ((this[property] + steps * increment) % 360 + 360) % 360;
                rotor.setAttribute('transform', `rotate(${this[property]})`);
                return steps * increment;
            }

            driveStandbySection(target) {
                if (this.sectionGearMotion?.target === target) return;
                const from = seekRadialStandbySection;
                if (Math.abs(target - from) < 0.000001) return;
                const travel = Math.sign(target - from) * Math.min(305, Math.abs(target - from) * 12);
                const motion = {
                    from,
                    target,
                    travel,
                    travelled: 0,
                    start: performance.now(),
                    duration: Math.max(250, 2000 * Math.abs(travel) / 305)
                };
                this.sectionGearMotion = motion;
                const frame = now => {
                    if (this.sectionGearMotion !== motion) return;
                    const progress = Math.min(1, Math.max(0, (now - motion.start) / motion.duration));
                    const eased = progress * progress * (3 - 2 * progress);
                    const current = motion.from + (motion.target - motion.from) * eased;
                    const travelled = motion.travel * eased;
                    const degrees = travelled - motion.travelled;
                    motion.travelled = travelled;
                    seekRadialStandbySection = Math.round(current);
                    this.sectionMechanicalAngle = ((this.sectionMechanicalAngle + degrees) % 360 + 360) % 360;
                    panelSoundBank.driveRotary('seekOuter', 'seekSectionTerminal', degrees, 12);
                    if (currentAudio) renderSeekRadial(currentAudio);
                    if (progress < 1) requestAnimationFrame(frame);
                    else {
                        seekRadialStandbySection = target;
                        this.sectionGearMotion = null;
                        panelSoundBank.stopRotary('seekSectionTerminal');
                        if (currentAudio) renderSeekRadial(currentAudio);
                    }
                };
                requestAnimationFrame(frame);
            }

            driveStandbyTime(target) {
                if (this.timeGearMotion?.target === target) return;
                const from = seekRadialStandbySeconds;
                if (Math.abs(target - from) < 0.000001) return;
                const travel = Math.sign(target - from) * Math.min(305, Math.abs(target - from) * 8);
                const motion = {
                    from,
                    target,
                    travel,
                    travelled: 0,
                    start: performance.now(),
                    duration: Math.max(250, 2000 * Math.abs(travel) / 305)
                };
                this.timeGearMotion = motion;
                const frame = now => {
                    if (this.timeGearMotion !== motion) return;
                    const progress = Math.min(1, Math.max(0, (now - motion.start) / motion.duration));
                    const eased = progress * progress * (3 - 2 * progress);
                    const current = motion.from + (motion.target - motion.from) * eased;
                    const travelled = motion.travel * eased;
                    const degrees = travelled - motion.travelled;
                    motion.travelled = travelled;
                    seekRadialStandbySeconds = Math.round(current);
                    this.timeMechanicalAngle = ((this.timeMechanicalAngle + degrees) % 360 + 360) % 360;
                    panelSoundBank.driveRotary('seekInner', 'seekTimeTerminal', degrees, 8);
                    if (currentAudio) renderSeekRadial(currentAudio);
                    if (progress < 1) requestAnimationFrame(frame);
                    else {
                        seekRadialStandbySeconds = target;
                        this.timeGearMotion = null;
                        panelSoundBank.stopRotary('seekTimeTerminal');
                        if (currentAudio) renderSeekRadial(currentAudio);
                    }
                };
                requestAnimationFrame(frame);
            }

            bindKnob(element, adjust, kind) {
                let pointerId = null;
                let lastY = 0;
                const turn = steps => {
                    const degrees = this.rotateKnob(kind, steps);
                    adjust(steps);
                    panelSoundBank.driveRotary(kind === 'section' ? 'seekOuter' : 'seekInner',
                        kind === 'section' ? 'seekSectionKnob' : 'seekTimeKnob',
                        degrees, kind === 'section' ? 12 : 8);
                };
                element.addEventListener('pointerdown', event => {
                    event.preventDefault();
                    pointerId = event.pointerId;
                    lastY = event.clientY;
                    element.setPointerCapture(pointerId);
                });
                element.addEventListener('pointermove', event => {
                    if (event.pointerId !== pointerId) return;
                    const delta = lastY - event.clientY;
                    if (Math.abs(delta) < 2) return;
                    const steps = delta > 0 ?
                        Math.max(1, Math.round(delta / 2)) :
                        Math.min(-1, Math.round(delta / 2));
                    turn(steps);
                    lastY = event.clientY;
                });
                const release = event => {
                    if (event.pointerId !== pointerId) return;
                    if (element.hasPointerCapture(pointerId)) element.releasePointerCapture(pointerId);
                    pointerId = null;
                    panelSoundBank.stopRotary(kind === 'section' ? 'seekSectionKnob' : 'seekTimeKnob');
                };
                element.addEventListener('pointerup', release);
                element.addEventListener('pointercancel', () => {
                    pointerId = null;
                    panelSoundBank.stopRotary(kind === 'section' ? 'seekSectionKnob' : 'seekTimeKnob');
                });
                element.addEventListener('wheel', event => {
                    event.preventDefault();
                    turn(event.deltaY < 0 ? 1 : -1);
                }, { passive: false });
                element.addEventListener('keydown', event => {
                    if (!['ArrowUp', 'ArrowRight', 'ArrowDown', 'ArrowLeft'].includes(event.key)) return;
                    event.preventDefault();
                    turn(event.key === 'ArrowUp' || event.key === 'ArrowRight' ? 1 : -1);
                });
            }

            advanceOuterHand(target, now) {
                const elapsed = Math.max(0, Math.min(document.hidden ? 1 : 0.05, (now - this.handLastTime) / 1000));
                this.handLastTime = now;
                if (!elapsed) return;
                const distance = target - this.renderedHandAngle;
                if (Math.abs(distance) < 0.02 && Math.abs(this.handVelocity) < 0.1) {
                    this.renderedHandAngle = target;
                    this.handVelocity = 0;
                    return;
                }
                const direction = Math.sign(distance) || -Math.sign(this.handVelocity);
                const projectedVelocity = this.handVelocity * direction;
                const brakingDistance = Math.max(0, projectedVelocity) ** 2 / (2 * OUTER_SEEK_BRAKING);
                let nextVelocity;
                if (projectedVelocity >= 0 && Math.abs(distance) <= brakingDistance) {
                    nextVelocity = this.handVelocity - direction * OUTER_SEEK_BRAKING * elapsed;
                    if (nextVelocity * direction < 0) nextVelocity = 0;
                } else {
                    nextVelocity = this.handVelocity + direction * OUTER_SEEK_ACCELERATION * elapsed;
                    if (nextVelocity * direction > OUTER_SEEK_MAX_VELOCITY) nextVelocity = direction * OUTER_SEEK_MAX_VELOCITY;
                }
                const nextAngle = this.renderedHandAngle + (this.handVelocity + nextVelocity) * elapsed / 2;
                if ((direction > 0 && nextAngle >= target) || (direction < 0 && nextAngle <= target)) {
                    this.renderedHandAngle = target;
                    this.handVelocity = 0;
                } else {
                    this.renderedHandAngle = nextAngle;
                    this.handVelocity = nextVelocity;
                }
            }

            advanceSectionHand(target, now) {
                if (!this.sectionMotion && Math.abs(target - this.renderedSectionAngle) > 0.001) {
                    this.sectionMotion = {
                        target,
                        velocity: 0,
                        lastTime: now,
                        phase: 'accelerate',
                        brakeAcceleration: 0
                    };
                }
                const motion = this.sectionMotion;
                if (!motion) {
                    this.renderedSectionAngle = target;
                    panelSoundBank.driveInner('idle', 0, INNER_SEEK_MAX_VELOCITY, now);
                    return;
                }
                if (motion.target !== target) {
                    motion.target = target;
                    motion.brakeAcceleration = 0;
                }
                const elapsed = Math.max(0, Math.min(document.hidden ? 1 : 0.05, (now - motion.lastTime) / 1000));
                motion.lastTime = now;
                const distance = target - this.renderedSectionAngle;
                const direction = Math.sign(distance) || -Math.sign(motion.velocity);
                const projectedVelocity = motion.velocity * direction;
                let nextVelocity = motion.velocity;
                if (projectedVelocity < 0 || Math.abs(distance) > projectedVelocity * INNER_SEEK_OUTRO_SECONDS / 2 && !motion.brakeAcceleration) {
                    motion.brakeAcceleration = 0;
                    if (projectedVelocity < INNER_SEEK_MAX_VELOCITY) {
                        nextVelocity = motion.velocity + direction * INNER_SEEK_ACCELERATION * elapsed;
                        if (nextVelocity * direction > INNER_SEEK_MAX_VELOCITY) nextVelocity = direction * INNER_SEEK_MAX_VELOCITY;
                        motion.phase = 'accelerate';
                    } else {
                        motion.phase = 'cruise';
                    }
                } else {
                    if (!motion.brakeAcceleration) motion.brakeAcceleration = Math.max(0.001, projectedVelocity * projectedVelocity / (2 * Math.max(0.001, Math.abs(distance))));
                    nextVelocity = motion.velocity - direction * motion.brakeAcceleration * elapsed;
                    if (nextVelocity * direction < 0) nextVelocity = 0;
                    motion.phase = 'brake';
                }
                const nextAngle = this.renderedSectionAngle + (motion.velocity + nextVelocity) * elapsed / 2;
                if ((direction > 0 && nextAngle >= target) || (direction < 0 && nextAngle <= target) ||
                    (Math.abs(target - nextAngle) < 0.05 && Math.abs(nextVelocity) < 0.1)) {
                    this.renderedSectionAngle = target;
                    this.sectionMotion = null;
                    panelSoundBank.driveInner('idle', 0, INNER_SEEK_MAX_VELOCITY, now);
                    return;
                }
                this.renderedSectionAngle = nextAngle;
                motion.velocity = nextVelocity;
                panelSoundBank.driveInner(motion.phase, nextVelocity, INNER_SEEK_MAX_VELOCITY, now,
                    motion.phase === 'brake' ? Math.abs(nextVelocity) / motion.brakeAcceleration : 0);
            }

            easeNextHandChange() {
                this.requestedHandMotion = true;
            }

            crossNextHandBoundary(direction) {
                this.handCycle += direction < 0 ? -1 : 1;
                this.requestedHandMotion = true;
            }

            render(model) {
                const activePercent = Math.max(0, Math.min(100, model.activePercent || 0));
                const activeSection = Math.max(0, Math.min(99, model.activeSection || 0));
                const now = performance.now();
                const wasPowered = this.powered;
                this.powered = model.powered === true;
                const poweringUp = this.powered && !wasPowered;
                const requestedHandMotion = this.requestedHandMotion;
                const sectionMode = model.activeDomain?.sectionMode === true;
                const domainModeChanged = this.hasRenderedModel && sectionMode !== this.lastSectionMode;
                const ownerChanged = this.hasRenderedModel && model.owner !== this.lastOwner;
                const sectionChanged = this.hasRenderedModel &&
                    (ownerChanged || activeSection !== this.lastActiveSection);
                let boundaryDirection = 0;
                if (this.powered && this.pointerId === null &&
                    this.hasRenderedModel && sectionChanged && !ownerChanged) {
                    if (this.lastActivePercent >= 85 && activePercent <= 15) boundaryDirection = 1;
                    else if (this.lastActivePercent <= 15 && activePercent >= 85) boundaryDirection = -1;
                }
                if (boundaryDirection) this.handCycle += boundaryDirection;
                const handBase = -135 + 270 * activePercent / 100;
                if (poweringUp) this.handCycle = Math.round((this.renderedHandAngle - handBase) / 360);
                const handTarget = handBase + this.handCycle * 360;
                if (this.pointerId === null && this.manualHandAngle !== null &&
                    (this.powered || model.playing === true)) {
                    this.manualHandAngle = null;
                    this.handVelocity = 0;
                }
                const physicalTarget = this.manualHandAngle ?? handTarget;
                this.advanceOuterHand(physicalTarget, now);
                this.releaseHandMotion = false;
                this.requestedHandMotion = false;
                const sectionTarget = this.sectionAngle(activeSection);
                if (!this.powered) {
                    this.sectionMotion = null;
                    panelSoundBank.driveInner('idle', 0, INNER_SEEK_MAX_VELOCITY, now);
                } else {
                    this.advanceSectionHand(sectionTarget, now);
                }
                this.targetHand.setAttribute('transform', `rotate(${this.renderedHandAngle} 195 190)`);
                this.sectionHand.setAttribute('transform', `rotate(${this.renderedSectionAngle} 195 190)`);
                panelSoundBank.driveHand('outerHand', this.renderedHandAngle, now);
                this.activeReadout.render(activeSection, model.activeSeconds);
                this.standbyReadout.render(model.targetSection, model.targetSeconds);
                this.sectionRotor.setAttribute('transform', `rotate(${this.sectionMechanicalAngle})`);
                this.timeRotor.setAttribute('transform', `rotate(${this.timeMechanicalAngle})`);
                this.sectionKnob.setAttribute('aria-valuemax', String(model.sectionCount || 0));
                this.sectionKnob.setAttribute('aria-valuenow', String(model.targetSection || 0));
                this.timeKnob.setAttribute('aria-valuemax', String(Math.max(0, Math.floor(model.targetLimitSeconds || 0))));
                this.timeKnob.setAttribute('aria-valuenow', String(Math.max(0, Math.floor(model.targetSeconds || 0))));
                this.timeKnob.setAttribute('aria-valuetext', seekRadialDisplayTime(model.targetSeconds));
                this.control.setAttribute('aria-valuenow', String(activePercent));
                this.control.setAttribute('aria-valuetext', `${activePercent.toFixed(1)} percent, ${seekRadialDisplayTime(model.activeSeconds)}`);
                this.svg.dataset.activePercent = String(activePercent);
                this.svg.dataset.section = String(activeSection);
                this.svg.dataset.timePowered = String(this.powered);
                this.lastOwner = model.owner;
                this.lastActiveSection = activeSection;
                this.lastActivePercent = activePercent;
                this.lastSectionMode = sectionMode;
                this.hasRenderedModel = true;
            }
        }

        let playbackCircuit = null;
        let legsExecutor = null;
        let krLoopAudio = null;
        let krLoopStart = null;
        let krLoopEnd = null;
        let krLoopGeneration = 0;
        let krBoundaryPendingGeneration = null;
        const KR_BOUNDARY_LEAD_WALL_SECONDS = 0.05;
        const SECTION_WINDOW_MAX_SECONDS = 180;
        const kIsOn = () => playbackCircuit ? playbackCircuit.k.effectivePosition === 'ON' : true;
        const krIsOn = () => playbackCircuit ? playbackCircuit.kr.effectivePosition === 'ON' : false;
        const isrAt = position => playbackCircuit ? playbackCircuit.isr.effectivePosition === position : position === 'OFF';
        const krFeedEnergized = () => !!playbackCircuit?.sectionRepeatFeed.energized;
        const sectionWindowSeconds = () => playbackCircuit ?
            playbackCircuit.sectionWindowEffectiveValue * SECTION_WINDOW_MAX_SECONDS : 0;
        const sectionDurationEligible = duration => Number.isFinite(duration) &&
            duration > sectionWindowSeconds();
        window.__npK = kIsOn;
        window.__npKR = krIsOn;
        window.__npKrLooping = audio => krFeedEnergized() &&
            krLoopAudio === audio && currentAudio === audio;
        publishReadOnlyWindow('__npSectionEligible', sectionDurationEligible);
        let seekRadialStandbySection = 0;
        let seekRadialStandbySeconds = 0;
        let manualStandbyBaseline = { section: 0, seconds: 0 };
        const terminalStandbyValue = () => {
            const fields = seekRadialDisplayFields(seekRadialStandbySeconds);
            return `${String(Math.trunc(seekRadialStandbySection)).padStart(2, '0')}/${String(fields.minutes).padStart(3, '0')}.${String(fields.seconds).padStart(2, '0')}`;
        };
        window.__npTerminalLiveValues = () => ({
            speed: soundCircuit.playbackRate,
            sectionWindow: Math.round((playbackCircuit?.sectionWindowEffectiveValue || 0) * SECTION_WINDOW_MAX_SECONDS),
            standby: terminalStandbyValue(),
            volume: window.__npTerminalManualVolume?.() ?? Math.round(Math.cbrt(soundCircuit.volume.value) * 100)
        });
        const seekRadialInstrument = new SeekRadialInstrument(
            seekRadialSvg,
            percent => setSeekRadialTarget(percent),
            direction => traverseSeekRadialDomain(direction),
            () => commitSeekRadialTarget(),
            steps => adjustSeekRadialStandbySection(steps),
            steps => adjustSeekRadialStandbyTime(steps)
        );
        window.__npTerminalSetStandby = (section, seconds, source = 'terminal') => {
            const sectionTarget = Math.max(0, Math.min(99, Math.trunc(section)));
            const secondsTarget = Math.max(0, Math.min(SEEK_RADIAL_MAX_SECONDS, Math.trunc(seconds)));
            if (source === 'manual') manualStandbyBaseline = { section: sectionTarget, seconds: secondsTarget };
            seekRadialInstrument.driveStandbySection(sectionTarget);
            seekRadialInstrument.driveStandbyTime(secondsTarget);
            return true;
        };
        window.__npTerminalManualStandby = () => ({ ...manualStandbyBaseline });
        let renderSpeedKnob = () => {},
            renderRevKnob = () => {},
            renderVolKnob = () => {},
            paintKSwitch = () => {},
            paintKrSwitch = () => {},
            mSyncControls = () => {},
            triggerPl = () => {},
            requestPlOnWithSound = () => {};
        kBtn.style.color = 'white';

        function updateLoopState() {
            if (!currentAudio) return;
            currentAudio.loop = false;
        }

        function clearKrSectionLoop() {
            const audio = krLoopAudio;
            krLoopGeneration++;
            krBoundaryPendingGeneration = null;
            krLoopAudio = null;
            krLoopStart = null;
            krLoopEnd = null;
            if (audio && audio === currentAudio) updateLoopState();
        }

        function collageSegmentAt(audio, time) {
            const segs = getCollageSegments(audio);
            if (!segs) return null;
            for (const seg of segs) {
                if (time >= seg[0] && time < seg[1]) return seg;
            }
            return null;
        }

        function seekRadialOwner(audio) {
            return audio?.__virtualSong || audio || null;
        }

        function seekRadialSectionAt(segments, time) {
            for (let index = 0; index < segments.length; index++) {
                const segment = segments[index];
                if (time >= segment[0] && time < segment[1]) {
                    return {
                        number: index + 1,
                        start: segment[0],
                        end: segment[1]
                    };
                }
            }
            const last = segments[segments.length - 1];
            if (last && Math.abs(time - last[1]) < .01) {
                return {
                    number: segments.length,
                    start: last[0],
                    end: last[1]
                };
            }
            return null;
        }

        function seekRadialDomain(audio, time, segments, duration) {
            const section = seekRadialSectionAt(segments, time);
            if (kIsOn() && section) {
                return {
                    start: section.start,
                    end: section.end,
                    section,
                    sectionMode: true
                };
            }
            return {
                start: 0,
                end: duration,
                section,
                sectionMode: false
            };
        }

        function seekRadialModel(audio, time, duration) {
            const segments = getCollageSegments(audio) || [];
            const owner = seekRadialOwner(audio);
            const safeTime = Math.max(0, Math.min(time, duration));
            const activeDomain = seekRadialDomain(audio, safeTime, segments, duration);
            const activeSection = activeDomain.section?.number || 0;
            seekRadialStandbySection = Number.isFinite(seekRadialStandbySection) ?
                Math.max(0, Math.min(99, Math.trunc(seekRadialStandbySection))) : 0;
            seekRadialStandbySeconds = Number.isFinite(seekRadialStandbySeconds) ?
                Math.max(0, Math.min(SEEK_RADIAL_MAX_SECONDS, Math.trunc(seekRadialStandbySeconds))) : 0;
            const standbyIndex = seekRadialStandbySection && segments.length ?
                Math.min(seekRadialStandbySection, segments.length) - 1 : -1;
            const standbySegment = standbyIndex >= 0 ? segments[standbyIndex] : null;
            const standbyStart = standbySegment ? standbySegment[0] : 0;
            const standbyEnd = standbySegment ? standbySegment[1] : duration;
            const standbyDomainSeconds = Math.max(0, standbyEnd - standbyStart);
            const standbyEffectiveSeconds = Math.min(seekRadialStandbySeconds, standbyDomainSeconds);
            const activeSpan = activeDomain.end - activeDomain.start;
            const activeSeconds = Math.max(0, safeTime - activeDomain.start);
            const activePercent = activeSpan > 0 ? activeSeconds / activeSpan * 100 : 0;
            if (timeBusIsPowered()) setTimeBusInputValue('mainSeekSensor', activePercent / 100);
            return {
                activePercent,
                activeSection,
                targetSection: seekRadialStandbySection,
                activeSeconds,
                targetSeconds: seekRadialStandbySeconds,
                sectionCount: 99,
                targetLimitSeconds: SEEK_RADIAL_MAX_SECONDS,
                sectionRotorAngle: seekRadialStandbySection * 12 % 360,
                timeRotorAngle: seekRadialStandbySeconds * 8 % 360,
                segments,
                activeDomain,
                standbyAbsoluteTime: standbyStart + standbyEffectiveSeconds,
                standbyStart,
                standbyEnd,
                standbyDomainSeconds,
                owner,
                powered: timeBusIsPowered(),
                playing: !audio.paused
            };
        }

        function queuePhysicalSeek(audio, time, autoplay = null) {
            if (!audio) return null;
            if (isrAt('E')) {
                if (!legsExecutor?.active) return null;
                time = legsExecutor.clampSeek(audio, time);
            }
            const duration = playbackDuration(audio) || audio.duration;
            if (!timeBusIsPowered()) {
                return seekPlayback(audio, time, autoplay);
            }
            if (!Number.isFinite(duration) || duration <= 0) {
                if (audio.__virtualSong) {
                    ensureVirtualMetadata(audio.__virtualSong, audio).then(ready => {
                        if (ready && seekRadialOwner(currentAudio) === audio.__virtualSong) {
                            queuePhysicalSeek(currentAudio, time, autoplay);
                        }
                    });
                } else {
                    audio.addEventListener('loadedmetadata', () => {
                        if (currentAudio === audio) queuePhysicalSeek(audio, time, autoplay);
                    }, { once: true });
                    loadAudio(audio);
                }
                return audio;
            }
            const boundedTime = Math.max(0, Math.min(time, duration));
            lastTickTime = -1;
            return seekPlayback(audio, boundedTime, autoplay);
        }

        function renderSeekRadial(audio = currentAudio, time = playbackTime(audio), duration = playbackDuration(audio)) {
            if (!audio || !Number.isFinite(duration) || duration <= 0 || !Number.isFinite(time)) return null;
            const model = seekRadialModel(audio, time, duration);
            seekRadialInstrument.render(model);
            return model;
        }

        function setSeekRadialTarget(percent) {
            if (!timeBusIsPowered() || !currentAudio || (isrAt('E') && !legsExecutor?.active)) return false;
            const duration = playbackDuration(currentAudio) || currentAudio.duration;
            const time = playbackTime(currentAudio);
            if (!Number.isFinite(duration) || duration <= 0 || !Number.isFinite(time)) return false;
            const model = seekRadialModel(currentAudio, time, duration);
            const span = model.activeDomain.end - model.activeDomain.start;
            if (!(span > 0)) return false;
            const bounded = Math.max(0, Math.min(100, percent));
            setTimeBusInputValue('mainSeekSensor', bounded / 100);
            let target = model.activeDomain.start + span * bounded / 100;
            if (bounded >= 100) {
                target = Math.max(model.activeDomain.start, model.activeDomain.end - Math.min(.05, span));
            }
            queuePhysicalSeek(currentAudio, target);
            return true;
        }

        function traverseSeekRadialDomain(direction) {
            if (!timeBusIsPowered() || !currentAudio || (isrAt('E') && !legsExecutor?.active) || (direction !== 1 && direction !== -1)) return false;
            const duration = playbackDuration(currentAudio) || currentAudio.duration;
            const time = playbackTime(currentAudio);
            if (!Number.isFinite(duration) || duration <= 0 || !Number.isFinite(time)) return false;
            const model = seekRadialModel(currentAudio, time, duration);
            const sectionIndex = model.activeSection - 1;
            if (model.activeDomain.sectionMode && sectionIndex >= 0) {
                const adjacentIndex = sectionIndex + direction;
                if (adjacentIndex >= 0 && adjacentIndex < model.segments.length) {
                    const adjacent = model.segments[adjacentIndex];
                    const targetTime = direction > 0 ?
                        adjacent[0] : Math.max(adjacent[0], adjacent[1] - .001);
                    const nextCycle = seekRadialInstrument.handCycle + direction;
                    queuePhysicalSeek(currentAudio, targetTime);
                    seekRadialInstrument.handCycle = nextCycle;
                    return true;
                }
            }
            const activeSegment = sectionIndex >= 0 ? model.segments[sectionIndex] : null;
            const signal = playbackCircuit?.pulseBoundary({
                kind: model.activeDomain.sectionMode ? 'section' : 'card',
                audio: currentAudio,
                start: activeSegment?.[0],
                end: activeSegment?.[1],
                direction: direction > 0 ? 'forward' : 'reverse',
                source: 'seek-radial'
            });
            return !!signal?.handled && !(isrAt('R') && playbackCircuit.m.effectivePosition === 'C');
        }

        function commitSeekRadialTarget() {
            if (!timeBusIsPowered() || !currentAudio || (isrAt('E') && !legsExecutor?.active)) return false;
            const duration = playbackDuration(currentAudio) || currentAudio.duration;
            if (!Number.isFinite(duration) || duration <= 0) return false;
            const time = playbackTime(currentAudio);
            if (!Number.isFinite(time)) return false;
            const model = seekRadialModel(currentAudio, time, duration);
            let targetTime = Math.max(0, Math.min(model.standbyAbsoluteTime, duration));
            if (model.standbyDomainSeconds > 0 && model.targetSeconds >= model.standbyDomainSeconds) {
                targetTime = Math.max(model.standbyStart, model.standbyEnd - .001);
            }
            seekRadialInstrument.easeNextHandChange();
            queuePhysicalSeek(currentAudio, targetTime);
            return true;
        }

        function adjustSeekRadialStandbySection(steps) {
            if (!timeBusIsPowered() || !currentAudio || (isrAt('E') && !legsExecutor?.active) || !Number.isFinite(steps) || !steps) return false;
            const duration = playbackDuration(currentAudio) || currentAudio.duration;
            const time = playbackTime(currentAudio);
            if (!Number.isFinite(duration) || duration <= 0 || !Number.isFinite(time)) return false;
            const next = Math.max(0, Math.min(99, seekRadialStandbySection + Math.trunc(steps)));
            if (next === seekRadialStandbySection) return false;
            seekRadialStandbySection = next;
            manualStandbyBaseline = { section: seekRadialStandbySection, seconds: seekRadialStandbySeconds };
            setTimeBusInputValue('sectionSensor', seekRadialStandbySection / 99);
            renderSeekRadial(currentAudio, time, duration);
            return true;
        }

        function adjustSeekRadialStandbyTime(steps) {
            if (!timeBusIsPowered() || !currentAudio || (isrAt('E') && !legsExecutor?.active) || !Number.isFinite(steps) || !steps) return false;
            const duration = playbackDuration(currentAudio) || currentAudio.duration;
            const time = playbackTime(currentAudio);
            if (!Number.isFinite(duration) || duration <= 0 || !Number.isFinite(time)) return false;
            const next = Math.max(0, Math.min(SEEK_RADIAL_MAX_SECONDS, seekRadialStandbySeconds + Math.trunc(steps)));
            if (next === seekRadialStandbySeconds) return false;
            seekRadialStandbySeconds = next;
            manualStandbyBaseline = { section: seekRadialStandbySection, seconds: seekRadialStandbySeconds };
            setTimeBusInputValue('timeSensor', seekRadialStandbySeconds / SEEK_RADIAL_MAX_SECONDS);
            renderSeekRadial(currentAudio, time, duration);
            return true;
        }

        timeBusPower.addEventListener('change', () => renderSeekRadial());

        function syncKrSectionLoop(audio, time = playbackTime(audio)) {
            if (!krFeedEnergized() || !audio || !isFinite(time)) {
                clearKrSectionLoop();
                return null;
            }
            const seg = collageSegmentAt(audio, time);
            if (!seg) {
                clearKrSectionLoop();
                return null;
            }
            if (krLoopAudio !== audio || krLoopStart !== seg[0] || krLoopEnd !== seg[1]) {
                armKrSectionLoop(audio, seg[0], seg[1]);
            } else if (time < krLoopEnd - krBoundaryLeadSeconds(audio)) {
                krBoundaryPendingGeneration = null;
            }
            return seg;
        }

        function armKrSectionLoop(audio, start, end) {
            if (!krFeedEnergized() || !audio || !isFinite(start) || !isFinite(end) || end <= start) {
                clearKrSectionLoop();
                return;
            }
            krLoopGeneration++;
            krBoundaryPendingGeneration = null;
            krLoopAudio = audio;
            krLoopStart = start;
            krLoopEnd = end;
            updateLoopState();
        }

        function krBoundaryLeadSeconds(audio) {
            const rate = Number(audio?.playbackRate) || 1;
            return KR_BOUNDARY_LEAD_WALL_SECONDS * Math.max(0.1, rate);
        }

        function processKrSectionBoundary(audio, time = playbackTime(audio)) {
            if (!krFeedEnergized() || audio !== currentAudio || audio.paused || audio.seeking ||
                !Number.isFinite(time)) return false;
            syncKrSectionLoop(audio, time);
            if (krLoopAudio !== audio || krLoopStart === null || krLoopEnd === null) return false;
            const generation = krLoopGeneration;
            if (krBoundaryPendingGeneration === generation ||
                time < krLoopEnd - krBoundaryLeadSeconds(audio)) return false;
            krBoundaryPendingGeneration = generation;
            const signal = playbackCircuit.pulseBoundary({
                kind: 'section',
                audio,
                start: krLoopStart,
                end: krLoopEnd,
                generation
            });
            if (!signal.handled) {
                if (krBoundaryPendingGeneration === generation) krBoundaryPendingGeneration = null;
                return false;
            }
            lastTickTime = krLoopStart;
            return true;
        }

        function setKrMode(on) {
            const next = on ? 'ON' : 'OFF';
            if (!playbackCircuit || !playbackCircuit.kr.setPosition(next)) {
                paintKrSwitch();
                return false;
            }
            if (krFeedEnergized()) syncKrSectionLoop(currentAudio);
            else clearKrSectionLoop();
            paintKrSwitch();
            return true;
        }

        function setLrcMode(on) {
            const next = on ? 'ON' : 'OFF';
            if (!playbackCircuit || !playbackCircuit.k.setPosition(next)) {
                paintKSwitch();
                return false;
            }
            kBtn.style.color = kIsOn() ? 'white' : 'yellow';
            if (krFeedEnergized()) syncKrSectionLoop(currentAudio);
            else clearKrSectionLoop();
            paintKSwitch();
            return true;
        }

        function setIsrPosition(position) {
            const previous = playbackCircuit?.isr.effectivePosition;
            if (!playbackCircuit || !playbackCircuit.isr.setPosition(position)) return false;
            if (previous === 'E' && position !== 'E') legsExecutor?.cancel();
            if (position === 'E' && !legsExecutor?.active) {
                playbackCircuit.dropPl();
                if (currentAudio && !currentAudio.paused) currentAudio.pause();
            }
            iBtn.style.color = isrAt('I') ? 'yellow' : 'white';
            sBtn.style.color = isrAt('S') ? 'yellow' : 'white';
            loopBtn.style.color = isrAt('OFF') ? 'white' : 'yellow';
            updateLoopState();
            if (krFeedEnergized()) syncKrSectionLoop(currentAudio);
            else clearKrSectionLoop();
            return true;
        }

        iBtn.onclick = () => {
            const turningOn = !isrAt('I');
            setIsrPosition(turningOn ? 'I' : 'OFF');
            playElectricalSound(turningOn ? 'chime' : 'noava');
        };

        sBtn.onclick = () => {
            const turningOn = !isrAt('S');
            setIsrPosition(turningOn ? 'S' : 'OFF');
            playElectricalSound(turningOn ? 'chime' : 'noava');
        };

        kBtn.onclick = () => {
            setLrcMode(!kIsOn());
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

        const scheduleSeekUpdate = () => {
            if (document.hidden) setTimeout(updateSeek, 16);
            else requestAnimationFrame(updateSeek);
        };

        const resumePanelMotion = () => {
            panelSoundBank.unlock();
            if (!document.hidden && currentAudio) {
                renderSeekRadial(currentAudio, playbackTime(currentAudio), playbackDuration(currentAudio));
            }
        };
        document.addEventListener('visibilitychange', resumePanelMotion);
        window.addEventListener('focus', resumePanelMotion);

        function updateSeek() {
            if (currentAudio && currentAudio.duration) {
                const uiDuration = playbackDuration(currentAudio) || currentAudio.duration;
                const uiTime = playbackTime(currentAudio);
                if (currentAudio !== lastActiveAudio) {
                    lastActiveAudio = currentAudio;
                    lastActiveLine = null;
                    lastTickTime = -1;
                }
                const prevTick = lastTickTime;
                lastTickTime = uiTime;

                renderSeekRadial(currentAudio, uiTime, uiDuration);

                const playedThrough = prevTick >= 0 && uiTime >= prevTick &&
                    (uiTime - prevTick) < 1.0;
                if (processKrSectionBoundary(currentAudio, uiTime)) {
                    scheduleSeekUpdate();
                    return;
                }
                if (krFeedEnergized() &&
                    (krLoopAudio !== currentAudio || krLoopStart === null || krLoopEnd === null ||
                        uiTime < krLoopStart)) {
                    syncKrSectionLoop(currentAudio, uiTime);
                }

                if (kIsOn() && (isrAt('I') || isrAt('S')) &&
                    !currentAudio.paused) {
                    const t = uiTime;
                    if (collageAudio === currentAudio && collageSegEnd !== null && playedThrough) {
                        if (t >= collageSegEnd) {
                            const signal = playbackCircuit.pulseBoundary({
                                kind: 'section',
                                audio: currentAudio,
                                end: collageSegEnd
                            });
                            collageAudio = null;
                            collageSegEnd = null;
                            if (signal.handled) {
                                scheduleSeekUpdate();
                                return;
                            }
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
                    const lyr = card.querySelector('.cLyr');
                    if (lyr) {
                        const lines = [...lyr.querySelectorAll('.lrcLine')];
                        if (lines.length) {
                            const t = currentAudio.__virtualSong ? uiTime : currentAudio.currentTime;
                            let active = currentAudio.__virtualSong ?
                                activeVirtualMember(currentAudio.__virtualSong)?.line || null : null;

                            if (!currentAudio.__virtualSong) {
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
                            if (ph && uiDuration) {
                                const pct = uiTime / uiDuration;
                                ph.style.height = (pct * lyr.clientHeight) + 'px';
                                ph.style.transform = `translateY(${lyr.scrollTop}px)`;
                            }
                        }
                    }
                }
            }
            scheduleSeekUpdate();
        }
        updateSeek();

        function syncCardLyricsPresentation(lyr) {
            const playHead = lyr.querySelector('.playHead');
            lyr.style.color = playHead ? 'white' : '';
            lyr.classList.toggle('hasPlayHead', !!playHead);
        }

        function setCardLyricsMode(card, showTrans) {
            const lyrics = card?.querySelector('.cLyr');
            const button = card?.closest('.cardWrap')?.querySelector('.mLyricsToggle');
            if (!card || !lyrics || (showTrans && !button)) return false;
            if (card.__virtualSong) return false;
            const template = card.querySelector(showTrans ? '.mLyricsTrans' : '.mLyricsOriginal');
            if (!template) return false;
            lyrics.replaceChildren(template.content.cloneNode(true));
            lyrics.scrollTop = 0;
            button?.setAttribute('aria-pressed', String(showTrans));
            syncCardLyricsPresentation(lyrics);
            if (currentAudio?.closest('.card') === card) lastActiveLine = null;
            return true;
        }

        function toggleCardLyrics(event, button) {
            event.preventDefault();
            event.stopPropagation();
            const card = button.closest('.cardWrap')?.querySelector('.card');
            setCardLyricsMode(card, button.getAttribute('aria-pressed') !== 'true');
        }

        window.__npTerminalHasTranslation = songId => !!document.getElementById(songId)?.closest('.cardWrap')?.querySelector('.mLyricsToggle');
        window.__npTerminalSetLyrics = (songId, active) => setCardLyricsMode(document.getElementById(songId), !!active);

        document.querySelectorAll('.cLyr').forEach(lyr => {
            syncCardLyricsPresentation(lyr);
            lyr.addEventListener('scroll', () => {
                const playHead = lyr.querySelector('.playHead');
                if (playHead) playHead.style.transform = `translateY(${lyr.scrollTop}px)`;
            });
            lyr.addEventListener('click', e => {
                if (!lyr.querySelector('.playHead')) return;
                e.stopPropagation();
                const card = lyr.closest('.card');
                const cardAudio = audioForCard(card);
                if (!cardAudio) return;
                const rect = lyr.getBoundingClientRect();
                const clickY = e.clientY - rect.top;
                const pct = Math.max(0, Math.min(1, clickY / rect.height));
                const switchingCard = selectCardAudio(cardAudio, card);
                if (switchingCard) activateCardAudio(cardAudio, true, 'card lyrics');
                const activate = () => {
                    if (currentAudio !== cardAudio) return;
                    let selectedAudio = cardAudio;
                    const duration = playbackDuration(cardAudio);
                    if (duration) selectedAudio = queuePhysicalSeek(cardAudio, pct * duration) || cardAudio;
                    if (!switchingCard) {
                        const playSelected = () => activateCardAudio(selectedAudio, false, 'card lyrics');
                        if (selectedAudio.readyState >= 1) playSelected();
                        else selectedAudio.addEventListener('loadedmetadata', playSelected, { once: true });
                    }
                };
                if (cardAudio.readyState >= 1) activate();
                else cardAudio.addEventListener('loadedmetadata', activate, { once: true });
            });
        });
        function updateSpeedVisuals() {
            renderSpeedKnob();
            if (lIsOn()) {
                soundCircuit.reverb.setValue(Math.min(1, Math.pow(Math.max(0, 1 - soundCircuit.playbackRate), 2 / 3)));
                updateReverbVisuals();
            }
        }

        const propagateSpeed = () => {
            document.querySelectorAll('audio, video').forEach(media => {
                media.playbackRate = soundCircuit.playbackRate;
                media.preservesPitch = false;
                media.mozPreservesPitch = false;
                media.webkitPreservesPitch = false;
            });
        };

        updateSpeedVisuals();

        let beginLHold = () => false;
        let endLHold = () => false;
        let driveReverbWithGears = () => {};
        const lb = document.getElementById('lBtn');
        if (lb) {




            let lHolding = false;
            let lArmed = false;
            let lSuppressClick = false;
            let lHoldSource = null;
            let lPointerId = null;
            let lStartY = 0;
            let lPullHeight = 1;

            const syncLCursor = () => {
                lb.classList.toggle('lInactive', !lIsOn());
                lb.classList.toggle('lPopped', soundCircuit.coupling.position === 'OFF');
            };
            soundCircuit.coupling.addEventListener('change', syncLCursor);
            syncLCursor();

            const lCleanupHold = () => {
                panelSoundBank.stopHeld('starterHold', 'L');
                lb.classList.remove('lFlashing', 'lArmed');
                lHolding = false;
                lArmed = false;
                lHoldSource = null;
            };

            beginLHold = source => {
                if (!lIsOn() || lHolding) return false;
                lHolding = true;
                lHoldSource = source;
                lArmed = false;
                lb.style.color = '';
                lb.classList.add('lFlashing');
                panelSoundBank.startHeld('starterHold', 'L');
                return true;
            };

            lb.addEventListener('pointerdown', e => {
                if (e.button !== 0) return;
                if (!beginLHold('L')) return;
                lSuppressClick = false;
                lPointerId = e.pointerId;
                lStartY = e.clientY;
                lPullHeight = Math.max(1, lb.getBoundingClientRect().height);
                lb.classList.add('m2CursorGrabbing');
                lb.setPointerCapture(e.pointerId);
                e.preventDefault();
            });
            lb.addEventListener('pointermove', e => {
                if (!lHolding || lHoldSource !== 'L' || lPointerId !== e.pointerId) return;
                const pull = Math.min(lPullHeight, Math.max(0, lStartY - e.clientY));
                const progress = pull / lPullHeight;
                lb.style.transform = `scale(${1 + progress * 0.4})`;
                if (!lArmed && progress >= 0.65) {
                    lArmed = true;
                    panelSoundBank.stopHeld('starterHold', 'L');
                    lb.classList.remove('lFlashing');
                    lb.classList.add('lArmed');
                }
                e.preventDefault();
            });
            endLHold = (source, fromPointerUp = false) => {
                if (source === 'L') {
                    lb.classList.remove('m2CursorGrabbing');
                    lb.style.transform = '';
                    lPointerId = null;
                }
                if (!lHolding || lHoldSource !== source) return false;
                if (source === 'L' && fromPointerUp) {
                    lSuppressClick = true;
                    setTimeout(() => { lSuppressClick = false; }, 0);
                }
                const completed = source === 'L' && fromPointerUp && lArmed;
                lCleanupHold();
                if (completed) {
                    if (soundCircuit.coupling.setPosition('OFF')) {
                        panelSoundBank.play('starterReleaseMechanical');
                        panelSoundBank.play('starterDisconnect');
                    }
                    lb.style.color = 'white';
                } else {
                    lb.style.color = 'yellow';
                }
                return completed;
            };
            lb.addEventListener('pointerup', e => {
                if (lPointerId !== e.pointerId) return;
                endLHold('L', true);
                if (lb.hasPointerCapture(e.pointerId)) lb.releasePointerCapture(e.pointerId);
            });
            lb.addEventListener('pointercancel', e => {
                if (lPointerId === e.pointerId) endLHold('L');
            });
            lb.addEventListener('lostpointercapture', e => {
                if (lPointerId === e.pointerId) endLHold('L');
            });

            lb.addEventListener('click', () => {
                if (lSuppressClick) {
                    lSuppressClick = false;
                    return;
                }
                if (soundCircuit.coupling.position !== 'OFF') return;
                if (!soundCircuit.coupling.setPosition('ON')) return;
                lb.style.color = 'yellow';
                driveReverbWithGears(Math.max(0, (soundCircuit.speed.value - 0.5) * 2));
                panelSoundBank.play('starterEngage');
                panelSoundBank.play('confirmationChime', { electrical: true });
            });
        }

        function syncVideoToAudio(audio, playVideo = false) {
            const card = audio?.closest('.card');
            const media = card ? activeMediaForCard(card) : null;
            const video = media?.matches('video[data-sync="true"]') ? media : null;
            if (!video) return;

            video.preload = 'auto';
            if (window.loadMediaEl) window.loadMediaEl(video);

            const synchronize = () => {
                video.__m2SyncPending = false;
                if (currentAudio !== audio) return;
                const target = audio.currentTime;
                if (!video.seeking && Number.isFinite(target) && Math.abs(video.currentTime - target) > 0.25) {
                    try {
                        video.currentTime = Math.max(0, Math.min(target, video.duration || target));
                    } catch (error) {}
                }
                video.playbackRate = soundCircuit.playbackRate;
                if (playVideo && !audio.paused && soundCircuit.powerContact.closed) {
                    const videoPlay = video.play();
                    if (videoPlay?.catch) videoPlay.catch(() => {});
                }
            };

            if (video.readyState >= HTMLMediaElement.HAVE_METADATA) {
                synchronize();
            } else if (!video.__m2SyncPending) {
                video.__m2SyncPending = true;
                video.addEventListener('loadedmetadata', synchronize, { once: true });
                video.addEventListener('error', () => {
                    video.__m2SyncPending = false;
                }, { once: true });
                video.load();
            }
        }

        document.addEventListener('play', (e) => {
            if (e.target.tagName !== 'AUDIO') return;
            if (!soundCircuit.powerContact.closed) {
                e.target.pause();
                playbackCircuit?.dropPl();
                return;
            }
            playbackCircuit?.energizePl();
            playbackCircuit?.setActivity(e.target, true);
            soundCircuit.setActivity(e.target, true);
            e.target.playbackRate = soundCircuit.playbackRate;
            if (krLoopAudio && krLoopAudio !== e.target) clearKrSectionLoop();
            if (currentAudio && currentAudio !== e.target) currentAudio.pause();
            currentAudio = e.target;
            setActiveVirtualMember(currentAudio);
            syncKrSectionLoop(currentAudio);
            updateLoopState();
            const card = currentAudio.closest('.card');
            document.querySelectorAll('.card').forEach(c => c.classList.remove('playing'));
            document.querySelectorAll('.lrcLine.lrcActive').forEach(l => l.classList.remove('lrcActive'));


            lastActiveLine = null;
            if (card) {
                card.classList.add('playing');
                if (card.__virtualSong) np.textContent = card.__virtualSong.seriesTitle;
                else np.innerHTML = card.dataset.title;
                np.setAttribute('href', '#' + (currentAudio.dataset.songId || card.id));
                syncVideoToAudio(currentAudio, true);
                if ('mediaSession' in navigator) {
                    try {
                        const imgEl = activeMediaForCard(card);
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

        document.addEventListener('seeking', (e) => {
            if (e.target.tagName !== 'AUDIO') return;
            syncKrSectionLoop(e.target, playbackTime(e.target));
        }, true);

        document.addEventListener('seeked', (e) => {
            if (e.target.tagName !== 'AUDIO') return;
            if (e.target === currentAudio) syncKrSectionLoop(e.target, playbackTime(e.target));
            syncVideoToAudio(e.target, !e.target.paused);
        }, true);

        document.addEventListener('timeupdate', (e) => {
            if (e.target.tagName !== 'AUDIO' || e.target !== currentAudio) return;
            if (legsExecutor?.tick(e.target, playbackTime(e.target))) return;
            processKrSectionBoundary(e.target, playbackTime(e.target));
        }, true);

        document.addEventListener('pause', (e) => {
            if (e.target.tagName !== 'AUDIO') return;
            playbackCircuit?.setActivity(e.target, false);
            soundCircuit.setActivity(e.target, false);
            if (e.target === currentAudio && !e.target.ended) playbackCircuit?.dropPl();
            pBtn.textContent = '▶';
            const media = activeMediaForCard(e.target.closest('.card'));
            const v = media?.matches('video[data-sync="true"]') ? media : null;
            if (v) v.pause();
            if ('mediaSession' in navigator) navigator.mediaSession.playbackState = 'paused';
        }, true);


        function getCollageSegments(audio) {
            const card = audio.closest('.card');
            if (!card) return null;
            const lines = [...card.querySelectorAll('.lrcLine:not([data-l])')];
            if (!lines.length) return null;
            const dur = playbackDuration(audio) || audio.duration;
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
                if (sectionDurationEligible(end - start)) segs.push([start, end]);
            }
            return segs.length ? segs : null;
        }


        function currentCollageSegment(audio) {
            return collageSegmentAt(audio, playbackTime(audio));
        }





        function startCollageIfNeeded(audio) {
            collageAudio = null;
            collageSegEnd = null;
            if (!kIsOn()) return false;
            if (!isrAt('I') && !isrAt('S')) return false;
            const apply = () => {
                if (currentAudio !== audio) return;
                const segs = getCollageSegments(audio);
                if (!segs) {
                    playAudio(audio, 'shuffle without timed segment');
                    return;
                }
                const idx = crypto.getRandomValues(new Uint32Array(1))[0] % segs.length;
                const target = seekPlayback(audio, segs[idx][0], false) || audio;
                collageAudio = null;
                collageSegEnd = null;
                playAudio(target, 'shuffle timed segment');
            };
            if (audio.__virtualSong) {
                if (audio.__virtualSong.timelineReady) apply();
                else {
                    ensureVirtualMetadata(audio.__virtualSong, audio);
                    playAudio(audio, 'shuffle while virtual timeline loads');
                }
            }
            else if (audio.readyState >= 1 && audio.duration && isFinite(audio.duration)) apply();
            else audio.addEventListener('loadedmetadata', apply, { once: true });
            return true;
        }



        function advanceShuffle() {
            const cards = [...document.querySelectorAll('.cardWrap')]
                .filter(c => c.style.display !== 'none');
            let pool = cards;
            if (isrAt('I')) {
                const currentCat = currentAudio?.closest('.cardWrap')?.dataset.category;
                if (currentCat) pool = cards.filter(c => c.dataset.category === currentCat);
            }
            if (!pool.length) return;
            const randomUint = crypto.getRandomValues(new Uint32Array(1))[0];
            const nextCardWrap = pool[randomUint % pool.length];
            const a = audioForCard(nextCardWrap.querySelector('.card'));
            if (!a) return;
            if (currentAudio && currentAudio !== a) {
                currentAudio.pause();
                currentAudio.loop = false;
            }
            currentAudio = a;
            setActiveVirtualMember(a);
            loadAudio(a);
            a.volume = soundCircuit.volume.value;
            a.playbackRate = soundCircuit.playbackRate;
            a.loop = false;
            pBtn.textContent = '||';
            if (!startCollageIfNeeded(a)) playAudio(a, 'shuffle card');
        }

        function markBoundaryContinued(signal) {
            if (signal.event) signal.event.__virtualSongContinues = true;
        }

        function repeatWholeCard(signal) {
            const audio = signal.audio || currentAudio;
            if (!audio) return;
            markBoundaryContinued(signal);
            clearKrSectionLoop();
            seekRadialInstrument.crossNextHandBoundary(1);
            const target = seekPlayback(audio, 0, true) || audio;
            if (!audio.__virtualSong) playAudio(target, 'whole-card repeat coil');
        }

        function repeatEligibleSection(signal) {
            const audio = signal.audio || currentAudio;
            const eligible = signal.kind === 'section' && isFinite(signal.start) &&
                isFinite(signal.end) && signal.end > signal.start;
            if (!eligible) {
                repeatWholeCard(signal);
                return;
            }
            markBoundaryContinued(signal);
            seekRadialInstrument.crossNextHandBoundary(1);
            const target = seekPlayback(audio, signal.start, true) || audio;
            if (!audio.__virtualSong) playAudio(target, 'eligible-section repeat coil');
        }

        function stopAtBoundary(signal) {
            playbackCircuit.dropPl();
            if (signal.audio && !signal.audio.paused) signal.audio.pause();
        }

        function transferToPrevious(signal) {
            markBoundaryContinued(signal);
            prevBtn.click();
        }

        function transferToNext(signal) {
            markBoundaryContinued(signal);
            nextBtn.click();
        }

        function transferToRandom(signal) {
            markBoundaryContinued(signal);
            advanceShuffle();
        }

        playbackCircuit = new PlaybackAvionicsUnit({
            wholeRepeat: repeatWholeCard,
            sectionRepeat: repeatEligibleSection,
            previousCard: transferToPrevious,
            stop: stopAtBoundary,
            nextCard: transferToNext,
            categoryWhole: transferToRandom,
            categorySection: transferToRandom,
            visibleWhole: transferToRandom,
            visibleSection: transferToRandom
        }, playbackAvionicsPower);
        class LegsExecutor extends EventTarget {
            constructor() {
                super();
                this.active = false;
                this.selecting = false;
                this.plan = [];
                this.commands = [];
                this.commandIndex = 0;
                this.iteration = 0;
                this.generation = 0;
                this.transitioning = false;
                this.current = null;
            }

            compile(plan) {
                const commands = [];
                let playable = null;
                plan.forEach((leg, sourceIndex) => {
                    if (leg.type === 'hold') {
                        if (playable) commands.push({ sourceIndex, leg: { ...playable }, repeats: Number(leg.times) || 0, hold: true });
                        return;
                    }
                    playable = { ...leg };
                    commands.push({ sourceIndex, leg: { ...leg }, repeats: 1, hold: false });
                });
                return commands;
            }

            cardForCode(code) {
                return [...document.querySelectorAll('.card')].find(card => (card.dataset.songUrl || '').toUpperCase() === code);
            }

            async readyAudio(card, generation) {
                let audio = audioForCard(card);
                if (!audio) return null;
                if (card.__virtualSong) {
                    const ready = await ensureVirtualMetadata(card.__virtualSong, audio);
                    if (!ready || generation !== null && generation !== this.generation) return null;
                    audio = audioForCard(card);
                } else if (!(audio.readyState >= HTMLMediaElement.HAVE_METADATA && Number.isFinite(audio.duration))) {
                    loadAudio(audio);
                    await new Promise(resolve => {
                        audio.addEventListener('loadedmetadata', resolve, { once: true });
                        audio.addEventListener('error', resolve, { once: true });
                    });
                    if (generation !== null && generation !== this.generation) return null;
                }
                return audio;
            }

            bounds(audio, leg) {
                const duration = playbackDuration(audio) || audio.duration;
                if (!(duration > 0)) return null;
                if (audio.__virtualSong) {
                    const member = audio.__virtualSong.members[audio.__virtualIndex];
                    const memberStart = Number(member?.virtualStart);
                    const memberDuration = Number(member?.audio?.duration);
                    if (!Number.isFinite(memberStart) || !(memberDuration > 0)) return null;
                    const start = memberStart + Math.max(0, Number(leg.seconds) || 0);
                    const end = memberStart + memberDuration;
                    if (start >= end) return null;
                    return { start, end, duration };
                }
                const card = visibleCardFor(audio.closest('.card'));
                const lines = card ? [...card.querySelectorAll('.lrcLine:not([data-l])')] : [];
                const threshold = Number(leg.sectionWindow);
                const segments = lines.map((line, index) => {
                    const start = Number(line.dataset.t);
                    const next = lines[index + 1];
                    const end = next && Number.isFinite(Number(next.dataset.t)) ? Number(next.dataset.t) : duration;
                    return [start, end];
                }).filter(segment => Number.isFinite(segment[0]) && Number.isFinite(segment[1]) &&
                    segment[1] > segment[0] && (!Number.isFinite(threshold) || segment[1] - segment[0] > threshold));
                let start = Math.max(0, Number(leg.seconds) || 0);
                let end = duration;
                if (Number(leg.section) > 0) {
                    const segment = segments[Number(leg.section) - 1];
                    if (!segment) return null;
                    start = segment[0] + Math.max(0, Number(leg.seconds) || 0);
                    if (leg.advance === 'Y') end = segment[1];
                }
                if (start >= end) return null;
                return { start, end, duration };
            }

            async activate(plan) {
                const snapshot = JSON.parse(JSON.stringify(plan));
                const commands = this.compile(snapshot);
                if (!commands.length) return false;
                for (const command of commands) {
                    const card = this.cardForCode(command.leg.songCode);
                    if (!card) return false;
                    const audio = await this.readyAudio(card, null);
                    if (!audio || !this.bounds(audio, command.leg)) return false;
                }
                const previousAudio = this.current?.audio;
                this.cancel();
                playbackCircuit.dropPl();
                if (previousAudio && !previousAudio.paused) previousAudio.pause();
                this.plan = snapshot;
                this.commands = commands;
                this.commandIndex = 0;
                this.iteration = 0;
                this.active = true;
                const generation = ++this.generation;
                this.dispatchState();
                const positioned = await window.__npTerminalSetIsr?.('E');
                if (!positioned || !this.active || generation !== this.generation) {
                    this.cancel();
                    return false;
                }
                return this.runCurrent(generation);
            }

            update(plan) {
                if (!this.active) return false;
                const commands = this.compile(JSON.parse(JSON.stringify(plan)));
                if (!commands.length || this.commandIndex >= commands.length) return false;
                this.plan = JSON.parse(JSON.stringify(plan));
                this.commands = commands;
                this.dispatchState();
                return true;
            }

            async runCurrent(generation = this.generation) {
                if (!this.active || generation !== this.generation) return false;
                const command = this.commands[this.commandIndex];
                if (!command || command.repeats < 1) return this.advance();
                const card = this.cardForCode(command.leg.songCode);
                if (!card) return this.fail();
                this.transitioning = true;
                const audio = await this.readyAudio(card, generation);
                if (!audio || !this.active || generation !== this.generation) return false;
                const bounds = this.bounds(audio, command.leg);
                if (!bounds) return this.fail();
                this.current = { ...bounds, audio, command };
                this.selecting = true;
                selectCardAudio(audio, card);
                this.selecting = false;
                const target = queuePhysicalSeek(audio, bounds.start, true) || audio;
                this.current.audio = target;
                this.transitioning = false;
                await playAudio(target, 'legs');
                this.dispatchState();
                return true;
            }

            clampSeek(audio, time) {
                if (!this.active || !this.current) return time;
                const owner = seekRadialOwner(audio);
                const currentOwner = seekRadialOwner(this.current.audio);
                if (owner !== currentOwner) return this.current.start;
                return Math.max(this.current.start, Math.min(Number(time) || 0, Math.max(this.current.start, this.current.end - .001)));
            }

            tick(audio, time) {
                if (!this.active || this.transitioning || !this.current || seekRadialOwner(audio) !== seekRadialOwner(this.current.audio)) return false;
                this.current.audio = audio;
                if (time < this.current.end - .05 * Math.max(.1, Number(audio.playbackRate) || 1)) {
                    this.dispatchProgress();
                    return false;
                }
                this.advance();
                return true;
            }

            ended(audio) {
                if (!this.active || this.transitioning || !this.current || seekRadialOwner(audio) !== seekRadialOwner(this.current.audio)) return false;
                this.current.audio = audio;
                if (audio.__virtualSong && playbackTime(audio) < this.current.end - .001) {
                    this.dispatchProgress();
                    return false;
                }
                this.advance();
                return true;
            }

            advance() {
                if (!this.active || this.transitioning) return false;
                const command = this.commands[this.commandIndex];
                if (!command) return this.finish();
                if (this.iteration + 1 < command.repeats) this.iteration++;
                else {
                    this.commandIndex++;
                    this.iteration = 0;
                }
                if (this.commandIndex >= this.commands.length) return this.finish();
                const generation = this.generation;
                this.transitioning = true;
                queueMicrotask(() => this.runCurrent(generation));
                this.dispatchState();
                return true;
            }

            commandDuration(command) {
                const card = this.cardForCode(command.leg.songCode);
                const audio = card ? audioForCard(card) : null;
                if (!audio) return null;
                const bounds = this.bounds(audio, command.leg);
                return bounds ? (bounds.end - bounds.start) / Math.max(.05, Number(command.leg.rate) || soundCircuit.playbackRate) : null;
            }

            progress() {
                if (!this.active || !this.current) return { active: false };
                const rate = Math.max(.05, Number(this.current.audio?.playbackRate) || soundCircuit.playbackRate);
                const currentTime = playbackTime(this.current.audio);
                const currentRemaining = Math.max(0, this.current.end - currentTime) / rate;
                const duration = playbackDuration(this.current.audio) || this.current.audio.duration;
                const model = Number.isFinite(duration) && duration > 0 ? seekRadialModel(this.current.audio, currentTime, duration) : null;
                const next = this.commands[this.commandIndex + (this.iteration + 1 < this.commands[this.commandIndex].repeats ? 0 : 1)] || null;
                let total = currentRemaining;
                let known = true;
                for (let index = this.commandIndex; index < this.commands.length; index++) {
                    const command = this.commands[index];
                    const duration = this.commandDuration(command);
                    if (duration === null) {
                        known = false;
                        break;
                    }
                    const completed = index === this.commandIndex ? this.iteration + 1 : 0;
                    const remainingRepeats = Math.max(0, command.repeats - completed);
                    total += duration * remainingRepeats;
                }
                return {
                    active: true,
                    currentLeg: this.current.command.sourceIndex + 1,
                    currentSection: Number(this.current.command.leg.section) || 0,
                    currentRemaining,
                    nextLeg: next ? next.sourceIndex + 1 : null,
                    nextEta: currentRemaining,
                    totalRemaining: known ? total : null,
                    percent: model ? Math.max(0, Math.min(100, Math.round(model.activePercent))) : null
                };
            }

            dispatchProgress() {
                const progress = this.progress();
                const second = Math.floor(progress.currentRemaining || 0);
                if (second === this.lastProgressSecond) return;
                this.lastProgressSecond = second;
                document.dispatchEvent(new CustomEvent('m2legsprogress', { detail: progress }));
            }

            dispatchState() {
                this.lastProgressSecond = -1;
                document.dispatchEvent(new CustomEvent('m2legsstate', { detail: this.progress() }));
            }

            finish() {
                const audio = this.current?.audio;
                this.active = false;
                this.current = null;
                this.transitioning = false;
                this.generation++;
                playbackCircuit.dropPl();
                if (audio && !audio.paused) audio.pause();
                this.dispatchState();
                return true;
            }

            fail() {
                this.cancel();
                return false;
            }

            cancel() {
                if (!this.active && !this.current) return false;
                this.active = false;
                this.current = null;
                this.transitioning = false;
                this.selecting = false;
                this.generation++;
                this.dispatchState();
                return true;
            }
        }
        legsExecutor = new LegsExecutor();
        window.__npTerminalActivateLegs = plan => legsExecutor.activate(plan);
        window.__npTerminalUpdateActiveLegs = plan => legsExecutor.update(plan);
        window.__npTerminalLegsProgress = () => legsExecutor.progress();
        window.__npTerminalLegsActive = () => legsExecutor.active;
        window.__npTerminalIsrAt = position => isrAt(position);
        window.__npTerminalProgSnapshot = () => {
            const audio = currentAudio;
            if (!audio) return null;
            const duration = playbackDuration(audio) || audio.duration;
            const time = playbackTime(audio);
            if (!Number.isFinite(duration) || duration <= 0 || !Number.isFinite(time)) return null;
            const model = seekRadialModel(audio, time, duration);
            const rate = Math.max(.05, Number(audio.playbackRate) || 1);
            const sectionEnd = kIsOn() && model.activeDomain?.section ? model.activeDomain.end : duration;
            return {
                songId: visibleCardFor(audio.closest('.card'))?.id || '',
                currentRemaining: Math.max(0, sectionEnd - time) / rate,
                currentSection: Math.min(99, Math.max(0, model.activeSection || 0)),
                totalRemaining: kIsOn() && !krFeedEnergized() ? Math.max(0, duration - time) / rate : Math.max(0, sectionEnd - time) / rate,
                percent: Math.max(0, Math.min(100, Math.round(model.activePercent)))
            };
        };
        let playbackPowerRecovery = null;
        const applyPlaybackCircuitPower = () => {
            const powered = playbackAvionicsPower.closed;
            if (!powered) {
                legsExecutor.cancel();
                const audio = currentAudio;
                const media = audio ? activeMediaForCard(audio.closest('.card')) : null;
                const video = media?.matches('video[data-sync="true"]') ? media : null;
                playbackPowerRecovery = audio ? {
                    audio,
                    time: playbackTime(audio),
                    wasPlaying: !audio.paused
                } : null;
                playbackCircuit.dropPl();
                playbackCircuit.clearActivity();
                clearKrSectionLoop();
                if (audio && !audio.paused) audio.pause();
                if (video) {
                    video.pause();
                    video.__m2SyncPending = false;
                    video.load();
                }
                return;
            }
            const recovery = playbackPowerRecovery;
            playbackPowerRecovery = null;
            if (!recovery?.audio || recovery.audio !== currentAudio) return;
            const audio = recovery.audio;
            let restored = false;
            const restore = () => {
                if (restored || !playbackAvionicsPower.closed || audio !== currentAudio) return;
                restored = true;
                audio.removeEventListener('loadedmetadata', restore);
                seekPlayback(audio, recovery.time, false);
                syncVideoToAudio(audio, recovery.wasPlaying);
                if (recovery.wasPlaying && soundAvionicsPower.closed) {
                    playAudio(audio, 'A2 breaker reset');
                }
            };
            loadAudio(audio);
            audio.addEventListener('loadedmetadata', restore, { once: true });
            audio.preload = 'auto';
            audio.load();
            queueMicrotask(() => {
                if (audio.readyState >= HTMLMediaElement.HAVE_METADATA) restore();
            });
        };
        playbackAvionicsPower.addEventListener('change', applyPlaybackCircuitPower);
        applyPlaybackCircuitPower();
        const applySoundCircuitPower = () => {
            const powered = soundCircuit.powerContact.closed;
            if (masterGain && audioCtx) {
                masterGain.gain.cancelScheduledValues(audioCtx.currentTime);
                masterGain.gain.setValueAtTime(powered ? 1 : 0, audioCtx.currentTime);
            }
            if (powered) return;
            legsExecutor.cancel();
            panelSoundBank.stopElectrical();
            playbackCircuit.dropPl();
            playbackCircuit.clearActivity();
            clearKrSectionLoop();
            document.querySelectorAll('.card audio, .card video').forEach(media => {
                if (!media.paused) media.pause();
            });
            Object.values(uiAudio).forEach(audio => {
                if (!audio.paused) audio.pause();
            });
            driveStopAlarm();
            soundCircuit.clearActivity();
        };
        soundCircuit.powerContact.addEventListener('change', applySoundCircuitPower);
        applySoundCircuitPower();
        playbackCircuit.sectionRepeatFeed.addEventListener('change', () => {
            if (krFeedEnergized()) syncKrSectionLoop(currentAudio);
            else clearKrSectionLoop();
        });

        document.addEventListener('ended', (e) => {
            if (e.target.tagName !== 'AUDIO') return;
            if (legsExecutor.ended(e.target)) return;
            if (window.__npKrLooping(e.target) && krLoopStart !== null && krLoopEnd !== null) {
                const signal = playbackCircuit.pulseBoundary({
                    kind: 'section', audio: e.target, start: krLoopStart, end: krLoopEnd, event: e
                });
                if (signal.handled) return;
            }
            if (e.target.__virtualSong && kIsOn() && (isrAt('I') || isrAt('S'))) {
                const endedAt = playbackTime(e.target);
                const seg = collageSegmentAt(e.target, Math.max(0, endedAt - 0.001));
                if (seg) {
                    const signal = playbackCircuit.pulseBoundary({
                        kind: 'section', audio: e.target, start: seg[0], end: seg[1], event: e
                    });
                    if (signal.handled) return;
                }
            }
            if (advanceVirtualSong(e.target)) {
                e.__virtualSongContinues = true;
                return;
            }
            pBtn.textContent = '▶';
            const signal = playbackCircuit.pulseBoundary({
                kind: 'card', audio: e.target, event: e
            });
            if (!signal.handled) playbackCircuit.dropPl();
        }, true);

        pBtn.onclick = () => {
            if (!currentAudio) return;
            if (currentAudio.paused) {
                if (currentAudio.__virtualSong && currentAudio.ended) {
                    seekPlayback(currentAudio, 0, true);
                    return;
                }
                updateLoopState();
                playAudio(currentAudio, 'play button');
            } else {
                if (isrAt('E')) legsExecutor.cancel();
                playbackCircuit.dropPl();
                currentAudio.pause();
            }
        };

        loopBtn.onclick = () => {
            const turningOn = !isrAt('R');
            setIsrPosition(turningOn ? 'R' : 'OFF');
            playMechanicalSound(turningOn ? 'arm' : 'click');
        };
        nextBtn.onclick = () => {
            if (isrAt('E')) return;
            const cards = [...document.querySelectorAll('.card:not([style*="display: none"])')]
                .filter(c => c.closest('.cardWrap')?.style.display !== 'none');
            const idx = cards.indexOf(visibleCardFor(currentAudio?.closest('.card')));
            const next = cards[idx + 1] || cards[0];
            if (next) {
                const a = audioForCard(next);
                if (a) {
                    if (currentAudio && currentAudio !== a) currentAudio.pause();
                    currentAudio = a;
                    setActiveVirtualMember(a);
                    loadAudio(a);
                    a.volume = soundCircuit.volume.value;
                    a.playbackRate = soundCircuit.playbackRate;
                    pBtn.textContent = '||';
                    playAudio(a, 'next card');
                }
            }
        };

        prevBtn.onclick = () => {
            if (isrAt('E')) return;
            const cards = [...document.querySelectorAll('.card:not([style*="display: none"])')]
                .filter(c => c.closest('.cardWrap')?.style.display !== 'none');
            const idx = cards.indexOf(visibleCardFor(currentAudio?.closest('.card')));
            const prev = cards[idx - 1] || cards[cards.length - 1];
            if (prev) {
                const a = audioForCard(prev);
                if (a) {
                    if (currentAudio && currentAudio !== a) currentAudio.pause();
                    currentAudio = a;
                    setActiveVirtualMember(a);
                    loadAudio(a);
                    a.volume = soundCircuit.volume.value;
                    a.playbackRate = soundCircuit.playbackRate;
                    pBtn.textContent = '||';
                    playAudio(a, 'previous card');
                }
            }
        };





        (function() {
            const $ = id => document.getElementById(id);
            const click = () => playMechanicalSound('click');
            const arm = () => playMechanicalSound('arm');

            ['mSpd', 'mVol', 'mRev', 'mR3'].forEach(id => bindM2DirectionalCursor($(id), true));
            ['mISR', 'pageBusKnob'].forEach(id => bindM2DirectionalCursor($(id), false));


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
                    if (e.button === 0) el.classList.add('dragging');
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
                    panelSoundBank.stopRotary(el.id);
                    try {
                        el.releasePointerCapture(e.pointerId);
                    } catch (_) {}
                };
                el.addEventListener('pointerup', end);
                el.addEventListener('pointercancel', end);
            }


            const mVol = $('mVol');
            let volumeUpdateQueued = false;
            let manualVolumeBaseline = Math.cbrt(soundCircuit.volume.value);
            const renderVol = () => {
                if (mVol) mVol.style.transform = 'rotate(' + (Math.cbrt(soundCircuit.volume.value) * 305) + 'deg)';
            };
            const setVol = (lin, source = 'manual') => {
                lin = Math.max(0, Math.min(1, lin));
                if (!soundCircuit.volume.setValue(Math.pow(lin, 3))) return false;
                if (source === 'manual') manualVolumeBaseline = lin;
                if (!volumeUpdateQueued) {
                    volumeUpdateQueued = true;
                    requestAnimationFrame(() => {
                        volumeUpdateQueued = false;
                        propagateVolume();
                        renderVol();
                    });
                }
                return true;
            };
            let volumeGearMotion = null;
            window.__npTerminalSetVolume = (value, source = 'terminal') => {
                const numeric = Number(value);
                if (!Number.isFinite(numeric)) return false;
                const to = Math.max(0, Math.min(100, numeric)) / 100;
                if (volumeGearMotion?.to === to) return true;
                const from = Math.cbrt(soundCircuit.volume.value);
                if (Math.abs(to - from) < 0.000001) return true;
                const motion = {
                    from,
                    to,
                    previous: from,
                    start: performance.now(),
                    duration: Math.max(250, 2000 * Math.abs(to - from))
                };
                volumeGearMotion = motion;
                const frame = now => {
                    if (volumeGearMotion !== motion) return;
                    const progress = Math.min(1, Math.max(0, (now - motion.start) / motion.duration));
                    const eased = progress * progress * (3 - 2 * progress);
                    const next = motion.from + (motion.to - motion.from) * eased;
                    if (setVol(next, source)) {
                        panelSoundBank.driveRotary('rAndV', 'mVolTerminal', (next - motion.previous) * 305, 12.2);
                        motion.previous = next;
                    }
                    if (progress < 1) requestAnimationFrame(frame);
                    else {
                        volumeGearMotion = null;
                        panelSoundBank.stopRotary('mVolTerminal');
                    }
                };
                requestAnimationFrame(frame);
                return true;
            };
            window.__npTerminalManualVolume = () => Math.round(manualVolumeBaseline * 100);
            const turnVol = delta => {
                const before = Math.cbrt(soundCircuit.volume.value);
                if (!setVol(before + delta)) return false;
                panelSoundBank.driveRotary('rAndV', 'mVol',
                    (Math.cbrt(soundCircuit.volume.value) - before) * 305, 12.2);
                return true;
            };
            makeRotary(mVol, d => turnVol(d / 305));
            if (mVol) mVol.addEventListener('wheel', e => {
                e.preventDefault();
                e.stopPropagation();
                turnVol(e.deltaY < 0 ? 0.04 : -0.04);
            }, {
                passive: false
            });
            renderVol();


            const mRev = $('mRev');
            let reverbUpdateQueued = false;
            const renderRev = () => {
                if (mRev) mRev.style.transform = 'rotate(' + (soundCircuit.reverb.value * 305) + 'deg)';
            };
            const setRev = v => {
                if (!soundCircuit.reverb.setValue(v)) return false;
                renderRev();
                if (!reverbUpdateQueued) {
                    reverbUpdateQueued = true;
                    requestAnimationFrame(() => {
                        reverbUpdateQueued = false;
                        updateReverbVisuals();
                    });
                }
                return true;
            };


            const turnRev = delta => {
                if (lIsOn()) return false;
                const before = soundCircuit.reverb.value;
                if (!setRev(before + delta)) return false;
                panelSoundBank.driveRotary('rAndV', 'mRev',
                    (soundCircuit.reverb.value - before) * 305, 15.25);
                return true;
            };
            let gearMotion = null;
            driveReverbWithGears = target => {
                const from = soundCircuit.reverb.value;
                const to = Math.max(0, Math.min(1, target));
                if (Math.abs(to - from) < 0.000001) return;
                const motion = {
                    from,
                    to,
                    start: performance.now(),
                    duration: Math.max(250, 2000 * Math.abs(to - from))
                };
                gearMotion = motion;
                const frame = now => {
                    if (gearMotion !== motion) return;
                    if (!lIsOn()) {
                        gearMotion = null;
                        panelSoundBank.stopRotary('mRevGear');
                        return;
                    }
                    const progress = Math.min(1, Math.max(0, (now - motion.start) / motion.duration));
                    const eased = progress * progress * (3 - 2 * progress);
                    if (setRev(motion.from + (motion.to - motion.from) * eased)) {
                        panelSoundBank.driveRotary('rAndV', 'mRevGear',
                            (soundCircuit.reverb.value - (motion.previous ?? motion.from)) * 305, 15.25);
                        motion.previous = soundCircuit.reverb.value;
                    }
                    if (progress < 1) requestAnimationFrame(frame);
                    else {
                        gearMotion = null;
                        panelSoundBank.stopRotary('mRevGear');
                    }
                };
                requestAnimationFrame(frame);
            };

            makeRotary(mRev,
                d => turnRev(d / 305),
                () => beginLHold('R'),
                () => endLHold('R'));
            if (mRev) mRev.addEventListener('wheel', e => {
                e.preventDefault();
                if (lIsOn()) return;
                turnRev(e.deltaY < 0 ? 0.05 : -0.05);
            }, {
                passive: false
            });
            renderRev();


            const mSpd = $('mSpd');
            let speedUpdateQueued = false;
            const speedRateAt = control => {
                const y = (0.5 - control) * 100;
                const x = Math.abs(y);
                const delta = x <= 10 ? 0.01 * x : 0.1 + 0.01 * (x - 10) + Math.pow(x - 10, 2) / 900;
                return Math.max(0.05, y >= 0 ? 1 + delta : 1 - delta);
            };
            let manualSpeedBaseline = speedRateAt(soundCircuit.speed.value);
            const renderSpd = () => {
                if (mSpd) mSpd.style.transform = 'rotate(' + ((0.5 - soundCircuit.speed.value) * 360) + 'deg)';
            };
            const setSpd = (p, source = 'manual') => {
                if (!soundCircuit.speed.setValue(p)) return false;
                if (source === 'manual') manualSpeedBaseline = speedRateAt(p);
                if (!speedUpdateQueued) {
                    speedUpdateQueued = true;
                    requestAnimationFrame(() => {
                        speedUpdateQueued = false;
                        updateSpeedVisuals();
                        propagateSpeed();
                    });
                }
                return true;
            };
            let speedGearMotion = null;
            window.__npTerminalSetSpeed = (value, source = 'terminal') => {
                const target = Number(value);
                if (!Number.isFinite(target)) return false;
                const bounded = Math.max(speedRateAt(1), Math.min(speedRateAt(0), target));
                let lower = 0;
                let upper = 1;
                for (let i = 0; i < 32; i++) {
                    const middle = (lower + upper) / 2;
                    if (speedRateAt(middle) > bounded) lower = middle;
                    else upper = middle;
                }
                const to = (lower + upper) / 2;
                if (speedGearMotion?.to === to) return true;
                const from = soundCircuit.speed.value;
                if (Math.abs(to - from) < 0.000001) return true;
                const motion = {
                    from,
                    to,
                    previous: from,
                    start: performance.now(),
                    duration: Math.max(250, 2000 * Math.abs(to - from))
                };
                speedGearMotion = motion;
                const frame = now => {
                    if (speedGearMotion !== motion) return;
                    const progress = Math.min(1, Math.max(0, (now - motion.start) / motion.duration));
                    const eased = progress * progress * (3 - 2 * progress);
                    const next = motion.from + (motion.to - motion.from) * eased;
                    if (setSpd(next, source)) {
                        panelSoundBank.driveRotary('r3Section', 'mSpdTerminal',
                            (motion.previous - soundCircuit.speed.value) * 360, 3.6);
                        motion.previous = soundCircuit.speed.value;
                    }
                    if (progress < 1) requestAnimationFrame(frame);
                    else {
                        speedGearMotion = null;
                        panelSoundBank.stopRotary('mSpdTerminal');
                    }
                };
                requestAnimationFrame(frame);
                return true;
            };
            window.__npTerminalManualSpeed = () => manualSpeedBaseline;
            const turnSpd = delta => {
                const before = soundCircuit.speed.value;
                if (!setSpd(before + delta)) return false;
                panelSoundBank.driveRotary('r3Section', 'mSpd',
                    (before - soundCircuit.speed.value) * 360, 3.6);
                return true;
            };
            makeRotary(mSpd, d => turnSpd(-d / 360));
            if (mSpd) mSpd.addEventListener('wheel', e => {
                e.preventDefault();
                e.stopPropagation();
                turnSpd(e.deltaY < 0 ? -0.01 : 0.01);
            }, {
                passive: false
            });
            renderSpd();

            const mR3 = $('mR3');
            let sectionWindowUpdateQueued = false;
            let manualSectionWindowBaseline = Math.round(playbackCircuit.sectionWindow.value * SECTION_WINDOW_MAX_SECONDS);
            const renderR3 = () => {
                if (mR3) mR3.style.transform = 'rotate(' + ((playbackCircuit.sectionWindow.value * 305) - 152.5) + 'deg)';
            };
            const setR3 = (value, source = 'manual') => {
                if (!playbackCircuit.sectionWindow.setValue(value)) return false;
                if (source === 'manual') manualSectionWindowBaseline = Math.round(value * SECTION_WINDOW_MAX_SECONDS);
                if (!sectionWindowUpdateQueued) {
                    sectionWindowUpdateQueued = true;
                    requestAnimationFrame(() => {
                        sectionWindowUpdateQueued = false;
                        collageAudio = null;
                        collageSegEnd = null;
                        if (krFeedEnergized()) syncKrSectionLoop(currentAudio);
                        renderR3();
                    });
                }
                return true;
            };
            let sectionWindowGearMotion = null;
            window.__npTerminalSetSectionWindow = (seconds, source = 'terminal') => {
                const value = Number(seconds);
                if (!Number.isFinite(value)) return false;
                const to = Math.max(0, Math.min(SECTION_WINDOW_MAX_SECONDS, value)) / SECTION_WINDOW_MAX_SECONDS;
                if (sectionWindowGearMotion?.to === to) return true;
                const from = playbackCircuit.sectionWindow.value;
                if (Math.abs(to - from) < 0.000001) return true;
                const motion = {
                    from,
                    to,
                    previous: from,
                    start: performance.now(),
                    duration: Math.max(250, 2000 * Math.abs(to - from))
                };
                sectionWindowGearMotion = motion;
                const frame = now => {
                    if (sectionWindowGearMotion !== motion) return;
                    const progress = Math.min(1, Math.max(0, (now - motion.start) / motion.duration));
                    const eased = progress * progress * (3 - 2 * progress);
                    const next = motion.from + (motion.to - motion.from) * eased;
                    if (setR3(next, source)) {
                        panelSoundBank.driveRotary('r3Section', 'mR3Terminal',
                            (playbackCircuit.sectionWindow.value - motion.previous) * 305, 15.25);
                        motion.previous = playbackCircuit.sectionWindow.value;
                    }
                    if (progress < 1) requestAnimationFrame(frame);
                    else {
                        sectionWindowGearMotion = null;
                        panelSoundBank.stopRotary('mR3Terminal');
                    }
                };
                requestAnimationFrame(frame);
                return true;
            };
            window.__npTerminalManualSectionWindow = () => manualSectionWindowBaseline;
            const turnR3 = delta => {
                const before = playbackCircuit.sectionWindow.value;
                if (!setR3(before + delta)) return false;
                panelSoundBank.driveRotary('r3Section', 'mR3',
                    (playbackCircuit.sectionWindow.value - before) * 305, 15.25);
                return true;
            };
            makeRotary(mR3, delta => turnR3(delta / 305));
            if (mR3) mR3.addEventListener('wheel', event => {
                event.preventDefault();
                event.stopPropagation();
                turnR3(event.deltaY < 0 ? 0.05 : -0.05);
            }, {
                passive: false
            });
            renderR3();


            const mR2 = $('mR2');
            if (mR2) {
                mR2.addEventListener('pointerdown', e => {
                    e.preventDefault();
                    try {
                        mR2.setPointerCapture(e.pointerId);
                    } catch (_) {}
                    mR2.src = aimg('r2on.png');
                    soundCircuit.pulseR2();
                    updateSpeedVisuals();
                    propagateSpeed();
                    renderSpd();
                    window.__npTerminalResetGlobal?.();
                    panelSoundBank.play('resetControl');
                    panelSoundBank.play('confirmationChime', { electrical: true });
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
            let plActivationPending = false;
            let plActivationIntent = 0;
            const playing = () => !!(currentAudio && !currentAudio.paused);
            const paintPl = () => {
                if (mPl && !plBusy && !plActivationPending) mPl.src = aimg(playing() ? PL.on : PL.off);
            };
            const pressPl = () => {
                if (!mPl || plBusy) return;
                plActivationIntent++;
                plActivationPending = false;
                plBusy = true;
                mPl.src = aimg(playing() ? PL.toOff : PL.toOn);
                panelSoundBank.play('playControl');
                setTimeout(() => {
                    plBusy = false;
                    if (pBtn.onclick) pBtn.onclick();
                    paintPl();
                }, 130);
            };
            const activatePl = audio => {
                if (!mPl) return;
                const card = visibleCardFor(audio.closest('.card'));
                const intent = ++plActivationIntent;
                plActivationPending = true;
                mPl.src = aimg(PL.toOn);
                panelSoundBank.play('playControl');
                setTimeout(() => {
                    if (intent !== plActivationIntent) return;
                    plActivationPending = false;
                    if (cardAudioIsCurrent(card)) {
                        updateLoopState();
                        playAudio(currentAudio, 'card switch');
                    }
                    paintPl();
                }, 130);
            };
            if (mPl) mPl.addEventListener('click', pressPl);
            playbackCircuit.plContact.addEventListener('change', paintPl);
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
                        return true;
                    }
                    return false;
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
                    },
                    drive: combo => new Promise(resolve => {
                        const target = steps.findIndex(s => s.combo === combo);
                        if (target < 0 || target === i) {
                            resolve(target >= 0);
                            return;
                        }
                        const direction = target > i ? 1 : -1;
                        const advance = () => {
                            if (i === target || !step(direction)) {
                                resolve(i === target);
                                return;
                            }
                            window.setTimeout(advance, 120);
                        };
                        advance();
                    })
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
                {
                    deg: 315 - OFF_ANGLE,
                    combo: 'ER'
                },
            ];
            const curCombo = () => isrAt('I') ? 'IR' : isrAt('S') ? 'SR' : isrAt('E') ? 'ER' : isrAt('R') ? 'R' : '';
            const mISR = $('mISR');
            let isrCtl = null;
            if (mISR) isrCtl = knobs(mISR, ISR_STEPS, s => {
                const position = s.combo === 'IR' ? 'I' : s.combo === 'SR' ? 'S' : s.combo === 'ER' ? 'E' : s.combo === 'R' ? 'R' : 'OFF';
                setIsrPosition(position);
            }, () => panelSoundBank.play('isrRelease'));
            window.__npTerminalSetIsr = position => isrCtl?.drive(position === 'I' ? 'IR' : position === 'S' ? 'SR' : position === 'E' ? 'ER' : position === 'R' ? 'R' : '') || Promise.resolve(false);


            function switchControl(img, states, onChange, sound, reverseClicks) {
                let i = states.findIndex(s => s.def);
                if (i < 0) i = 0;
                const paint = () => {
                    img.src = aimg(states[i].f);
                    img.style.transform = 'translate(-49.2%, ' + states[i].ty + '%)';
                    img.dataset.m2SwitchCursor = i === 0 ? 'left' : i === states.length - 1 ? 'right' : 'both';
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
                return {
                    sync: name => {
                        const n = states.findIndex(state => state.name === name);
                        if (n >= 0) {
                            i = n;
                            paint();
                        }
                    }
                };
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



            const mNav = $('mNav');
            let navCtl = null;
            if (mNav) navCtl = switchControl(mNav, [{
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
                playbackCircuit.m.setPosition(s.name);
            }, () => panelSoundBank.play('modeSwitch'), true);


            const mK = $('mK');
            paintKSwitch = () => {
                if (!mK) return;
                const s = playbackCircuit?.k.position === 'ON' ? ST.D : ST.C;
                mK.src = aimg(s.f);
                mK.style.transform = 'translate(-49.2%, ' + s.ty + '%)';
                mK.dataset.m2SwitchCursor = s === ST.D ? 'left' : 'right';
            };
            if (mK) {
                mK.addEventListener('click', () => {
                    if (setLrcMode(false)) {
                        panelSoundBank.play('modeSwitch');
                    }
                });
                mK.addEventListener('contextmenu', e => {
                    e.preventDefault();
                    if (setLrcMode(true)) {
                        panelSoundBank.play('modeSwitch');
                    }
                });
                mK.addEventListener('wheel', e => {
                    e.preventDefault();
                    e.stopPropagation();
                    const goOn = e.deltaY < 0;
                    if (setLrcMode(goOn)) {
                        panelSoundBank.play('modeSwitch');
                    }
                }, {
                    passive: false
                });
                paintKSwitch();
            }

            const mKR = $('mKR');
            paintKrSwitch = () => {
                if (!mKR) return;
                const s = krIsOn() ? ST.D : ST.C;
                mKR.src = aimg(s.f);
                mKR.style.transform = 'translate(-49.2%, ' + s.ty + '%)';
                mKR.dataset.m2SwitchCursor = s === ST.D ? 'left' : 'right';
            };
            if (mKR) {
                mKR.addEventListener('click', () => {
                    if (setKrMode(false)) panelSoundBank.play('modeSwitch');
                });
                mKR.addEventListener('contextmenu', e => {
                    e.preventDefault();
                    if (setKrMode(true)) panelSoundBank.play('modeSwitch');
                });
                mKR.addEventListener('wheel', e => {
                    e.preventDefault();
                    e.stopPropagation();
                    if (setKrMode(e.deltaY < 0)) panelSoundBank.play('modeSwitch');
                }, {
                    passive: false
                });
                paintKrSwitch();
            }


            renderSpeedKnob = renderSpd;
            renderRevKnob = renderRev;
            renderVolKnob = renderVol;
            mSyncControls = () => {
                renderSpd();
                renderRev();
                renderVol();
                if (isrCtl) isrCtl.sync(curCombo());
                if (navCtl) navCtl.sync(playbackCircuit.m.position);
                paintKSwitch();
                paintKrSwitch();
                paintPl();
            };
            triggerPl = pressPl;
            requestPlOnWithSound = activatePl;
        })();

        const srchEl = document.getElementById('srch');
        srchEl.addEventListener('input', () => {
            const v = foldSearch(srchEl.textContent || '');
            document.querySelectorAll('.cardWrap').forEach(w => {
                if (w.classList.contains('virtual-song-member')) {
                    w.style.display = 'none';
                    return;
                }
                if (w.__search === undefined) {
                    const name = w.querySelector('.cName');
                    const lyrics = w.querySelector('.cLyr');
                    const memberNames = w.__virtualSong ? w.__virtualSong.members.map(m => m.title).join(' ') : '';
                    const memberSortKeys = w.__virtualSong ? w.__virtualSong.members.map(m => m.wrap?.dataset?.sortKey || '').join(' ') : '';
                    const sortKey = w.dataset.sortKey || '';
                    w.__search = foldSearch((name ? name.textContent : '') + ' ' + sortKey + ' ' + memberNames + ' ' + memberSortKeys + ' ' + (lyrics ? lyrics.textContent : ''));
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
                a.volume = soundCircuit.volume.value;
            });
            Object.entries(uiAudio).forEach(([name, audio]) => {
                if (ELECTRICAL_SOUND_NAMES.has(name) || name === 'firealarm') {
                    audio.volume = soundCircuit.volume.value;
                }
            });
            panelSoundBank.updateVolume();
        };

        window.addEventListener('DOMContentLoaded', () => {
            propagateVolume();
            propagateSpeed();
            const activateInitialHash = () => {
                const hash = location.hash.slice(1);
                if (!hash) return;
                const requestedCard = document.getElementById(hash);
                if (requestedCard && requestedCard.classList.contains('card')) {
                    const target = visibleCardFor(requestedCard);
                    const a = requestedCard.__virtualMember?.audio || audioForCard(target);
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
                        currentAudio = a;
                        setActiveVirtualMember(a);
                        loadAudio(a);
                        a.volume = soundCircuit.volume.value;
                        a.playbackRate = soundCircuit.playbackRate;
                        try { a.currentTime = 0; } catch (e) {}
                        updateLoopState();
                        playAudio(a, 'permalink');
                        target.scrollIntoView({
                            block: 'center'
                        });
                    }
                }
            };
            if (pageMainSystemsPower.closed) activateInitialHash();
            else mainSystemsEvents.addEventListener('ready', activateInitialHash, { once: true });
        });

        window.addEventListener('hashchange', () => {
            const requestedCard = document.getElementById(location.hash.slice(1));
            if (!requestedCard?.__virtualMember) return;
            requestedCard.__virtualMember.info.hostCard.scrollIntoView({ block: 'center' });
        });

        document.addEventListener('click', e => {
            if (e.target.classList.contains('delBtn') || e.target.classList.contains('editBtn')) return;

            const lrcLine = e.target.closest('.lrcLine');
            const cLyr = e.target.closest('.cLyr');

            const wrap = e.target.closest('.cardWrap');
            if (!wrap) return;
            const card = wrap.querySelector('.card');
            if (!card) return;
            const audio = audioForCard(card);
            if (!audio) return;

            if (lrcLine) {
                if (lrcLine.dataset.virtualMember !== undefined) {
                    const info = card.__virtualSong;
                    const index = parseInt(lrcLine.dataset.virtualMember, 10);
                    if (info && Number.isInteger(index) && info.members[index]) {
                        const switchingCard = !cardAudioIsCurrent(card);
                        if (switchingCard) playbackCircuit?.dropPl();
                        const memberAudio = switchingCard ?
                            playVirtualMember(info, 0, 0, false) : currentAudio;
                        if (!memberAudio) return;
                        if (switchingCard) activateCardAudio(memberAudio, true, 'virtual lyric');
                        const activate = () => {
                            if (seekRadialOwner(currentAudio) !== info) return;
                            if (!switchingCard) activateCardAudio(currentAudio, false, 'virtual lyric');
                            ensureVirtualMetadata(info, currentAudio).then(ready => {
                                if (!ready || seekRadialOwner(currentAudio) !== info) return;
                                queuePhysicalSeek(currentAudio, info.members[index].virtualStart);
                            });
                        };
                        if (memberAudio.readyState >= 1) activate();
                        else memberAudio.addEventListener('loadedmetadata', activate, { once: true });
                    }
                    return;
                }
                const time = parseFloat(lrcLine.dataset.t);
                const switchingCard = selectCardAudio(audio, card);
                if (switchingCard) activateCardAudio(audio, true, 'LRC line');
                const setT = () => {
                    if (currentAudio !== audio) return;
                    queuePhysicalSeek(audio, time);
                    updateLoopState();
                    if (!switchingCard) activateCardAudio(audio, false, 'LRC line');
                };
                if (audio.readyState >= 1) setT();
                else {
                    audio.addEventListener('loadedmetadata', setT, {
                        once: true
                    });
                    startLoadingAnim(wrap);
                }
                return;
            }

            if (cLyr && cLyr.querySelector('.lrcLine')) {
                if (card.__virtualSong) return;
                const rect = cLyr.getBoundingClientRect();
                const clickY = e.clientY - rect.top;
                const pct = Math.max(0, Math.min(1, clickY / rect.height));
                const switchingCard = selectCardAudio(audio, card);
                if (switchingCard) activateCardAudio(audio, true, 'lyrics proportional seek');
                const setT = () => {
                    if (currentAudio !== audio) return;
                    if (audio.duration) {
                        const time = pct * audio.duration;
                        queuePhysicalSeek(audio, time);
                        updateLoopState();
                    }
                    if (!switchingCard) activateCardAudio(audio, false, 'lyrics proportional seek');
                };
                if (audio.readyState >= 1) setT();
                else {
                    audio.addEventListener('loadedmetadata', setT, {
                        once: true
                    });
                    startLoadingAnim(wrap);
                }
                return;
            }

            const switchingCard = selectCardAudio(audio, card);
            if (!switchingCard && e.target.closest('.cMain')) triggerPl();
            else activateCardAudio(audio, switchingCard, 'card');
        });

        function applyDigitSeek(audio, num) {
            if (!audio || audio !== currentAudio) return false;
            const duration = playbackDuration(audio) || audio.duration;
            if (!isFinite(duration) || duration <= 0) return false;

            let handled = false;
            if (kIsOn()) {
                const card = audio.closest('.card');
                const partLines = card ? [...card.querySelectorAll('.lrcLine:not([data-l])')] : [];
                let activeLine = audio.__virtualSong ?
                    activeVirtualMember(audio.__virtualSong)?.line || null :
                    (card ? card.querySelector('.lrcLine.lrcActive') : null);
                if (activeLine?.dataset.l !== undefined) activeLine = null;
                if (!activeLine && card) {
                    const time = playbackTime(audio);
                    for (const line of partLines) {
                        const start = parseFloat(line.dataset.t);
                        if (isFinite(start) && start <= time) activeLine = line;
                        else if (isFinite(start) && start > time) break;
                    }
                }
                if (activeLine) {
                    const start = parseFloat(activeLine.dataset.t);
                    let end = duration;
                    const next = partLines[partLines.indexOf(activeLine) + 1];
                    if (next) {
                        end = parseFloat(next.dataset.t);
                    }
                    const lineDur = end - start;
                    if (isFinite(start) && sectionDurationEligible(lineDur)) {
                        const targetTime = start + (lineDur * num * 0.1);
                        const wasPlaying = !audio.paused;
                        seekRadialInstrument.easeNextHandChange();
                        queuePhysicalSeek(audio, targetTime, wasPlaying);
                        handled = true;
                    }
                }
            }
            if (!handled) {
                seekRadialInstrument.easeNextHandChange();
                queuePhysicalSeek(audio, duration * num * 0.1);
            }
            return true;
        }

        function requestDigitSeek(audio, num) {
            audio.__pendingDigitSeek = num;
            const applyPending = () => {
                if (audio !== currentAudio || audio.__pendingDigitSeek === undefined) return;
                const pending = audio.__pendingDigitSeek;
                if (applyDigitSeek(audio, pending)) delete audio.__pendingDigitSeek;
            };
            applyPending();
            if (audio.__pendingDigitSeek === undefined) return;

            if (!audio.getAttribute('src')) loadAudio(audio);
            if (audio.__virtualSong) {
                ensureVirtualMetadata(audio.__virtualSong, audio).then(applyPending);
            } else if (!audio.__digitMetadataPending) {
                audio.__digitMetadataPending = true;
                audio.addEventListener('loadedmetadata', () => {
                    audio.__digitMetadataPending = false;
                    applyPending();
                }, { once: true });
            }
        }

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
                requestDigitSeek(currentAudio, parseInt(key, 10));
                return;
            }
            if (e.key === 'Escape') {
                const frame = document.getElementById('comFrame');
                frame.classList.remove('open');
                frame.src = 'about:blank';
            }
            if (key === 'ArrowLeft') {
                e.preventDefault();
                queuePhysicalSeek(currentAudio, Math.max(0, playbackTime(currentAudio) - 5));
                return;
            }
            if (key === 'ArrowRight') {
                e.preventDefault();
                queuePhysicalSeek(currentAudio, Math.min(playbackDuration(currentAudio), playbackTime(currentAudio) + 5));
                return;
            }
            if (key === ' ' || key === 'Spacebar') {
                e.preventDefault();
                triggerPl();
            }
        });

        const rowTpl = () => `<div class="rowgrp entry">
        <input name="category[]" list="catList" placeholder="category" required>
        <input name="title[]" placeholder="title" required autocomplete="off">
        <input type="file" name="file[]" accept=".mp3" required>
        <input type="file" name="imgfile[]" accept="image/*,video/webm,video/mp4">
        <label><input type="checkbox" name="isyes[]"> sync</label>
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
            const c = row.querySelector('[name="category[]"]');
            const t = row.querySelector('[name="title[]"]');
            const f = row.querySelector('[name="file[]"]');
            const img = row.querySelector('[name="imgfile[]"]');
            const sync = row.querySelector('[name="isyes[]"]');
            let attempt = 0;
            return new Promise(resolve => {
                function tryUpload() {
                    attempt++;
                    const form = new FormData();
                    form.append('category[]', c.value.trim());
                    form.append('title[]', t.value.trim());
                    form.append('file[]', f.files[0]);
                    form.append('isyes[]', sync && sync.checked ? '1' : '0');
                    if (img && img.files[0]) form.append('imgfile[]', img.files[0]);
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

        const jsonBox = document.getElementById('jsonBox');
        const jsonBtn = document.getElementById('jsonBtn');
        jsonBtn.addEventListener('click', () => {
            const v = jsonBox.value.trim();
            if (v === '') {
                const data = [...document.querySelectorAll('.rowgrp.entry')].map(r => ({
                    category: r.querySelector('[name="category[]"]').value,
                    title: r.querySelector('[name="title[]"]').value,
                    isyes: r.querySelector('[name="isyes[]"]').checked
                }));
                const s = JSON.stringify(data);
                jsonBox.value = s;
                navigator.clipboard.writeText(s).catch(() => {});
                return;
            }
            let data;
            try { data = JSON.parse(v); } catch { return; }
            if (!Array.isArray(data)) return;
            [...document.querySelectorAll('.rowgrp.entry')].forEach(r => r.remove());
            data.slice().reverse().forEach(item => {
                addRow();
                const r = document.querySelector('.rowgrp.entry');
                r.querySelector('[name="category[]"]').value = item.category || '';
                r.querySelector('[name="title[]"]').value = item.title || '';
                r.querySelector('[name="isyes[]"]').checked = !!item.isyes;
            });
            refreshDel();
        });

        function enableAdds() {
            addBtn.style.display = 'inline';
        }

        function refreshDel() {
            const r = zone.querySelectorAll('.rowgrp.entry');
            r.forEach(el => el.querySelector('.delBtn').disabled = r.length === 1);
        }

        function copyLinkId(a) {
            const card = document.getElementById(a);
            const id = activeSongId(card) || a;
            navigator.clipboard.writeText(location.origin + location.pathname + '#' + id);
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
        document.querySelectorAll('.tJumpCat, .tJumpSub a').forEach(control => {
            control.addEventListener('click', event => {
                const hash = control.dataset.href || control.getAttribute('href');
                if (!hash?.startsWith('#')) return;
                const target = document.getElementById(hash.slice(1));
                if (!target) return;
                event.preventDefault();
                if (location.hash !== hash) location.hash = hash;
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        });

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
            const requestedCard = wrap.querySelector('.card');
            const audio = audioForCard(requestedCard);
            const visibleWrap = visibleCardFor(requestedCard)?.closest('.cardWrap') || wrap;

            if (audio) {
                if (currentAudio !== audio) {
                    document.querySelectorAll('audio').forEach(a => {
                        if (a !== audio) {
                            a.pause();
                            a.loop = false;
                        }
                    });
                    currentAudio = audio;
                    setActiveVirtualMember(audio);
                    loadAudio(currentAudio);
                    currentAudio.volume = soundCircuit.volume.value;
                    currentAudio.playbackRate = soundCircuit.playbackRate;
                }
                queuePhysicalSeek(currentAudio, time);
                if (currentAudio.paused) {
                    updateLoopState();
                    playAudio(currentAudio, 'playAndSeek');
                }
                visibleWrap.scrollIntoView({
                    behavior: 'smooth',
                    block: 'center'
                });
            }
        };

        (function() {
            class ElectricalTerminal {
                constructor(owner, designation) {
                    this.owner = owner;
                    this.designation = designation;
                    this.name = owner.name + ':' + designation;
                    Object.freeze(this);
                }
            }

            class DcSource {
                constructor(name, nominalVoltage = 28, currentLimit = 2) {
                    this.name = name;
                    this.nominalVoltage = nominalVoltage;
                    this.currentLimit = currentLimit;
                    this.positive = new ElectricalTerminal(this, 'L+');
                    this.negative = new ElectricalTerminal(this, 'L-');
                    this.current = 0;
                    this.tripped = false;
                    this.tripReason = null;
                }

                trip(reason) {
                    this.tripped = true;
                    this.tripReason = reason;
                    this.current = 0;
                }

                reset() {
                    this.tripped = false;
                    this.tripReason = null;
                    this.current = 0;
                }
            }

            class ElectricalConductor extends EventTarget {
                #connected = true;

                constructor(name, from, to) {
                    super();
                    if (!(from instanceof ElectricalTerminal) || !(to instanceof ElectricalTerminal)) {
                        throw new TypeError(name + ' requires two electrical terminals');
                    }
                    this.name = name;
                    this.from = from;
                    this.to = to;
                }

                get connected() {
                    return this.#connected;
                }

                get conductive() {
                    return this.#connected;
                }

                setConnected(connected) {
                    const next = !!connected;
                    if (next === this.#connected) return false;
                    this.#connected = next;
                    this.dispatchEvent(new Event('change'));
                    return true;
                }

                disconnect() {
                    return this.setConnected(false);
                }

                reconnect() {
                    return this.setConnected(true);
                }
            }

            class ElectricalContact extends EventTarget {
                #closed;
                #authority;

                constructor(name, closed = false, authority = null) {
                    super();
                    this.name = name;
                    this.line = new ElectricalTerminal(this, 'LINE');
                    this.load = new ElectricalTerminal(this, 'LOAD');
                    this.#closed = !!closed;
                    this.#authority = authority;
                    Object.defineProperties(this, {
                        closed: {
                            enumerable: true,
                            configurable: false,
                            get: () => this.#closed
                        },
                        conductive: {
                            enumerable: true,
                            configurable: false,
                            get: () => this.#closed
                        }
                    });
                    Object.preventExtensions(this);
                }

                setClosed(closed, authority = null) {
                    if (this.#authority !== null && authority !== this.#authority) {
                        throw new Error('Unauthorized operation of contact ' + this.name);
                    }
                    const next = !!closed;
                    if (next === this.#closed) return false;
                    this.#closed = next;
                    this.dispatchEvent(new Event('change'));
                    return true;
                }
            }

            Object.freeze(ElectricalContact.prototype);
            Object.freeze(ElectricalContact);

            const DC_SOLVER_AUTHORITY = Symbol('DC CONTROL CIRCUIT SOLVER');

            class DcRelayCoil extends EventTarget {
                #energized = false;
                #auxiliaryAuthority = Symbol('RELAY AUXILIARY CONTACTS');
                #auxiliaries = [];

                constructor(name, {
                    ratedVoltage = 28,
                    resistance = 280,
                    pickupVoltage = 18,
                    dropoutVoltage = 6
                } = {}) {
                    super();
                    this.name = name;
                    this.a1 = new ElectricalTerminal(this, 'A1');
                    this.a2 = new ElectricalTerminal(this, 'A2');
                    this.ratedVoltage = ratedVoltage;
                    this.resistance = resistance;
                    this.pickupVoltage = pickupVoltage;
                    this.dropoutVoltage = dropoutVoltage;
                    this.voltage = 0;
                    this.current = 0;
                }

                get energized() {
                    return this.#energized;
                }

                addNormallyOpenAuxiliary(name) {
                    const contact = new ElectricalContact(name, this.#energized, this.#auxiliaryAuthority);
                    this.#auxiliaries.push({ contact, normallyClosed: false });
                    return contact;
                }

                addNormallyClosedAuxiliary(name) {
                    const contact = new ElectricalContact(name, !this.#energized, this.#auxiliaryAuthority);
                    this.#auxiliaries.push({ contact, normallyClosed: true });
                    return contact;
                }

                applyVoltage(voltage, authority = null) {
                    if (authority !== DC_SOLVER_AUTHORITY) {
                        throw new Error(
                            'Coil voltage is determined by circuit continuity: ' + this.name
                        );
                    }
                    const nextVoltage = Number.isFinite(voltage) ? voltage : 0;
                    this.voltage = nextVoltage;
                    this.current = nextVoltage / this.resistance;
                    const magnitude = Math.abs(nextVoltage);
                    const nextEnergized = this.#energized
                        ? magnitude >= this.dropoutVoltage
                        : magnitude >= this.pickupVoltage;
                    if (nextEnergized === this.#energized) return false;

                    this.#energized = nextEnergized;
                    this.#auxiliaries.forEach(({ contact, normallyClosed }) => {
                        contact.setClosed(
                            normallyClosed ? !nextEnergized : nextEnergized,
                            this.#auxiliaryAuthority
                        );
                    });
                    this.dispatchEvent(new Event('statechange'));
                    return true;
                }
            }

            class DcPoweredLoad extends EventTarget {
                #energized = false;

                constructor(name, {
                    resistance = 280,
                    pickupVoltage = 18,
                    dropoutVoltage = 6
                } = {}) {
                    super();
                    this.name = name;
                    this.a1 = new ElectricalTerminal(this, 'A1');
                    this.a2 = new ElectricalTerminal(this, 'A2');
                    this.resistance = resistance;
                    this.pickupVoltage = pickupVoltage;
                    this.dropoutVoltage = dropoutVoltage;
                    this.voltage = 0;
                    this.current = 0;
                }

                get energized() {
                    return this.#energized;
                }

                setResistance(resistance) {
                    const next = Number(resistance);
                    if (!Number.isFinite(next) || next <= 0) {
                        throw new TypeError('Invalid load resistance: ' + this.name);
                    }
                    if (Math.abs(next - this.resistance) < 1e-9) return false;
                    this.resistance = next;
                    this.dispatchEvent(new Event('change'));
                    return true;
                }

                applyVoltage(voltage, authority = null) {
                    if (authority !== DC_SOLVER_AUTHORITY) {
                        throw new Error('Load voltage is determined by circuit continuity: ' + this.name);
                    }
                    const nextVoltage = Number.isFinite(voltage) ? voltage : 0;
                    this.voltage = nextVoltage;
                    this.current = nextVoltage / this.resistance;
                    const magnitude = Math.abs(nextVoltage);
                    const nextEnergized = this.#energized
                        ? magnitude >= this.dropoutVoltage
                        : magnitude >= this.pickupVoltage;
                    if (nextEnergized === this.#energized) return false;
                    this.#energized = nextEnergized;
                    this.dispatchEvent(new Event('statechange'));
                    return true;
                }
            }

            class DcControlCircuit extends EventTarget {
                #solving = false;
                #solveAgain = false;
                #transactionDepth = 0;
                #names = new Set();

                constructor(name, nominalVoltage = 28, currentLimit = 2) {
                    super();
                    this.name = name;
                    this.source = new DcSource(name + ' SOURCE', nominalVoltage, currentLimit);
                    this.conductors = [];
                    this.contacts = [];
                    this.coils = [];
                    this.loads = [];
                    this.fault = null;
                }

                register(device, collection) {
                    if (this.#names.has(device.name)) {
                        throw new Error('Duplicate circuit designation: ' + device.name);
                    }
                    this.#names.add(device.name);
                    collection.push(device);
                    device.addEventListener('change', () => this.solve());
                    this.solve();
                    return device;
                }

                addConductor(conductor) {
                    return this.register(conductor, this.conductors);
                }

                addContact(contact) {
                    return this.register(contact, this.contacts);
                }

                addCoil(coil) {
                    if (this.#names.has(coil.name)) {
                        throw new Error('Duplicate circuit designation: ' + coil.name);
                    }
                    this.#names.add(coil.name);
                    this.coils.push(coil);
                    this.loads.push(coil);
                    this.solve();
                    return coil;
                }

                addLoad(load) {
                    if (this.#names.has(load.name)) {
                        throw new Error('Duplicate circuit designation: ' + load.name);
                    }
                    this.#names.add(load.name);
                    this.loads.push(load);
                    load.addEventListener('change', () => this.solve());
                    this.solve();
                    return load;
                }

                wire(name, from, to) {
                    return this.addConductor(new ElectricalConductor(name, from, to));
                }

                installJumper(name, from, to) {
                    return this.wire('JUMPER ' + name, from, to);
                }

                transaction(work) {
                    this.#transactionDepth++;
                    try {
                        return work();
                    } finally {
                        this.#transactionDepth--;
                        if (this.#transactionDepth === 0 && this.#solveAgain) this.solve();
                    }
                }

                solve() {
                    if (this.#transactionDepth > 0 || this.#solving) {
                        this.#solveAgain = true;
                        return false;
                    }

                    this.#solving = true;
                    let passes = 0;
                    try {
                        do {
                            this.#solveAgain = false;
                            passes++;
                            if (passes > 32) {
                                throw new Error(this.name + ' failed to reach an electrical steady state');
                            }

                            const terminals = new Set([this.source.positive, this.source.negative]);
                            this.conductors.forEach(device => {
                                terminals.add(device.from);
                                terminals.add(device.to);
                            });
                            this.contacts.forEach(device => {
                                terminals.add(device.line);
                                terminals.add(device.load);
                            });
                            this.loads.forEach(load => {
                                terminals.add(load.a1);
                                terminals.add(load.a2);
                            });

                            const parent = new Map([...terminals].map(terminal => [terminal, terminal]));
                            const find = terminal => {
                                let root = terminal;
                                while (parent.get(root) !== root) root = parent.get(root);
                                let cursor = terminal;
                                while (parent.get(cursor) !== cursor) {
                                    const next = parent.get(cursor);
                                    parent.set(cursor, root);
                                    cursor = next;
                                }
                                return root;
                            };
                            const union = (left, right) => {
                                const leftRoot = find(left);
                                const rightRoot = find(right);
                                if (leftRoot !== rightRoot) parent.set(leftRoot, rightRoot);
                            };

                            this.conductors.forEach(conductor => {
                                if (conductor.conductive) union(conductor.from, conductor.to);
                            });
                            this.contacts.forEach(contact => {
                                if (contact.conductive) union(contact.line, contact.load);
                            });

                            const positiveRoot = find(this.source.positive);
                            const negativeRoot = find(this.source.negative);
                            if (positiveRoot === negativeRoot) {
                                this.source.trip('direct short circuit between L+ and L-');
                            }

                            if (this.source.tripped) {
                                this.fault = this.source.tripReason;
                                this.source.current = 0;
                                this.loads.forEach(load => load.applyVoltage(0, DC_SOLVER_AUTHORITY));
                                continue;
                            }

                            const potential = terminal => {
                                const root = find(terminal);
                                if (root === positiveRoot) return this.source.nominalVoltage;
                                if (root === negativeRoot) return 0;
                                return null;
                            };
                            let sourceCurrent = 0;
                            this.loads.forEach(load => {
                                const a1 = potential(load.a1);
                                const a2 = potential(load.a2);
                                const voltage = a1 === null || a2 === null ? 0 : a1 - a2;
                                load.applyVoltage(voltage, DC_SOLVER_AUTHORITY);
                                sourceCurrent += Math.abs(load.current);
                            });
                            this.source.current = sourceCurrent;

                            if (sourceCurrent > this.source.currentLimit) {
                                this.source.trip(
                                    'overcurrent: ' + sourceCurrent.toFixed(3) + ' A exceeds ' +
                                    this.source.currentLimit.toFixed(3) + ' A'
                                );
                                this.fault = this.source.tripReason;
                                this.loads.forEach(load => load.applyVoltage(0, DC_SOLVER_AUTHORITY));
                                this.#solveAgain = true;
                            } else {
                                this.fault = null;
                            }
                        } while (this.#solveAgain);
                    } finally {
                        this.#solving = false;
                    }
                    this.dispatchEvent(new Event('solved'));
                    return !this.source.tripped;
                }

                resetProtection() {
                    this.source.reset();
                    this.fault = null;
                    return this.solve();
                }

                validateTopology() {
                    const degree = new Map();
                    this.conductors.forEach(conductor => {
                        degree.set(conductor.from, (degree.get(conductor.from) || 0) + 1);
                        degree.set(conductor.to, (degree.get(conductor.to) || 0) + 1);
                    });
                    const faults = [];
                    this.contacts.forEach(contact => {
                        if (!(degree.get(contact.line) || 0)) {
                            faults.push({ device: contact.name, terminal: 'LINE', fault: 'UNWIRED' });
                        }
                        if (!(degree.get(contact.load) || 0)) {
                            faults.push({ device: contact.name, terminal: 'LOAD', fault: 'UNWIRED' });
                        }
                    });
                    this.loads.forEach(load => {
                        if (!(degree.get(load.a1) || 0)) {
                            faults.push({ device: load.name, terminal: 'A1', fault: 'UNWIRED' });
                        }
                        if (!(degree.get(load.a2) || 0)) {
                            faults.push({ device: load.name, terminal: 'A2', fault: 'UNWIRED' });
                        }
                        if (!Number.isFinite(load.resistance) || load.resistance <= 0) {
                            faults.push({ device: load.name, terminal: null, fault: 'INVALID_RESISTANCE' });
                        }
                    });
                    return faults;
                }

                snapshot() {
                    return {
                        source: {
                            voltage: this.source.nominalVoltage,
                            current: this.source.current,
                            currentLimit: this.source.currentLimit,
                            tripped: this.source.tripped,
                            tripReason: this.source.tripReason
                        },
                        contacts: Object.fromEntries(this.contacts.map(contact => [
                            contact.name,
                            contact.closed
                        ])),
                        coils: Object.fromEntries(this.coils.map(coil => [
                            coil.name,
                            {
                                voltage: coil.voltage,
                                current: coil.current,
                                energized: coil.energized
                            }
                        ])),
                        loads: Object.fromEntries(this.loads
                            .filter(load => !this.coils.includes(load))
                            .map(load => [
                                load.name,
                                {
                                    voltage: load.voltage,
                                    current: load.current,
                                    energized: load.energized
                                }
                            ])),
                        conductors: Object.fromEntries(this.conductors.map(conductor => [
                            conductor.name,
                            conductor.connected
                        ]))
                    };
                }
            }

            class DcDeviceRegistry {
                #factories = new Map();

                register(type, factory) {
                    if (this.#factories.has(type)) throw new Error('Duplicate DC device type: ' + type);
                    this.#factories.set(type, factory);
                    return this;
                }

                create(spec, context) {
                    const factory = this.#factories.get(spec.type);
                    if (!factory) throw new Error('Unknown DC device type: ' + spec.type);
                    return factory(spec, context);
                }
            }

            class DcSwitchedLoad extends EventTarget {
                #authority = Symbol('SWITCHED DC LOAD');

                constructor(name, circuit, supply, returnTerminal, resistance, wire) {
                    super();
                    this.name = name;
                    this.contact = circuit.addContact(new ElectricalContact(name + ' CONTACT', false, this.#authority));
                    this.load = circuit.addLoad(new DcPoweredLoad(name + ' LOAD', { resistance }));
                    wire(name + ' FEED', supply, this.contact.line);
                    wire(name + ' SWITCHED', this.contact.load, this.load.a1);
                    wire(name + ' RETURN', this.load.a2, returnTerminal);
                    this.load.addEventListener('statechange', () => this.dispatchEvent(new Event('change')));
                }

                get active() {
                    return this.load.energized;
                }

                setActive(active) {
                    return this.contact.setClosed(active, this.#authority);
                }
            }

            class DcDiscreteInputAdapter extends EventTarget {
                #authority = Symbol('DISCRETE SWITCH CONTACT');

                constructor(name, circuit, supply, returnTerminal, powerContact, {
                    resistance = 28000,
                    sense = 'power',
                    wire
                } = {}) {
                    super();
                    this.name = name;
                    this.powerContact = powerContact;
                    this.sense = sense;
                    this.contact = circuit.addContact(new ElectricalContact(
                        name + ' SWITCH CONTACT', false, this.#authority
                    ));
                    this.load = circuit.addLoad(new DcPoweredLoad(
                        name + ' INPUT LOAD', { resistance }
                    ));
                    if (sense === 'ground') {
                        wire(name + ' INPUT FEED', supply, this.load.a1);
                        wire(name + ' INPUT TO SWITCH', this.load.a2, this.contact.line);
                        wire(name + ' SWITCH RETURN', this.contact.load, returnTerminal);
                    } else {
                        wire(name + ' SWITCH FEED', supply, this.contact.line);
                        wire(name + ' SWITCH TO INPUT', this.contact.load, this.load.a1);
                        wire(name + ' INPUT RETURN', this.load.a2, returnTerminal);
                    }
                    this.load.addEventListener('statechange', () => this.dispatchEvent(new Event('change')));
                    powerContact?.addEventListener('change', () => this.dispatchEvent(new Event('change')));
                }

                get powered() {
                    return !this.powerContact || this.powerContact.closed;
                }

                get active() {
                    return this.powered && this.contact.closed && this.load.energized;
                }

                get state() {
                    if (!this.powered || !this.contact.closed) return 'OPEN';
                    if (!this.load.energized) return 'FAULT';
                    return this.sense === 'ground' ? 'GROUND' : 'POWERED';
                }

                get voltage() {
                    return this.load.voltage;
                }

                get current() {
                    return this.load.current;
                }

                setClosed(closed) {
                    return this.contact.setClosed(closed, this.#authority);
                }
            }

            class DcAnalogInputAdapter extends EventTarget {
                #value = 0;

                constructor(name, circuit, supply, returnTerminal, powerContact, {
                    resistance = 56000,
                    referenceVoltage = 5,
                    wire
                } = {}) {
                    super();
                    this.name = name;
                    this.powerContact = powerContact;
                    this.referenceVoltage = referenceVoltage;
                    this.load = circuit.addLoad(new DcPoweredLoad(
                        name + ' EXCITATION LOAD', { resistance }
                    ));
                    wire(name + ' EXCITATION FEED', supply, this.load.a1);
                    wire(name + ' EXCITATION RETURN', this.load.a2, returnTerminal);
                    this.load.addEventListener('statechange', () => this.dispatchEvent(new Event('change')));
                    powerContact?.addEventListener('change', () => this.dispatchEvent(new Event('change')));
                }

                get powered() {
                    return !this.powerContact || this.powerContact.closed;
                }

                get state() {
                    if (!this.powered) return 'OPEN';
                    return this.load.energized ? 'POWERED' : 'FAULT';
                }

                get signalVoltage() {
                    return this.state === 'POWERED' ? this.#value * this.referenceVoltage : 0;
                }

                get voltage() {
                    return this.load.voltage;
                }

                get current() {
                    return this.load.current;
                }

                setValue(value) {
                    const next = Math.max(0, Math.min(1, Number(value)));
                    if (!Number.isFinite(next) || next === this.#value) return false;
                    this.#value = next;
                    this.dispatchEvent(new Event('change'));
                    return true;
                }
            }

            class DiscreteBus extends EventTarget {
                #channels = new Map();

                register(id, channel) {
                    if (this.#channels.has(id)) throw new Error('Duplicate discrete channel: ' + id);
                    this.#channels.set(id, channel);
                    channel.addEventListener('change', () => this.dispatchEvent(new CustomEvent(
                        'change',
                        { detail: { id, state: channel.state, active: channel.active } }
                    )));
                    return channel;
                }

                get(id) {
                    return this.#channels.get(id) || null;
                }

                get states() {
                    return Object.fromEntries([...this.#channels].map(([id, channel]) => [id, {
                        state: channel.state,
                        active: channel.active,
                        voltage: channel.voltage,
                        current: channel.current
                    }]));
                }

                validate() {
                    return [...this.#channels]
                        .filter(([, channel]) => channel.state === 'FAULT')
                        .map(([id]) => ({ device: id, terminal: 'DISCRETE', fault: 'DISCRETE_FAULT' }));
                }
            }

            class DcNetlist {
                #devices = new Map();
                #specs = new Map();
                #avionicsDevices = new WeakSet();
                #wireNumber = 700;

                constructor(circuit, registry, { supplies = {}, returns = {}, protectedSupplies = [] } = {}) {
                    this.circuit = circuit;
                    this.registry = registry;
                    this.supplies = supplies;
                    this.returns = returns;
                    this.protectedSupplies = new Set(protectedSupplies);
                    this.discreteBus = new DiscreteBus();
                }

                wire(name, from, to) {
                    const number = String(this.#wireNumber++).padStart(3, '0');
                    return this.circuit.wire('W' + number + ' ' + name, from, to);
                }

                add(spec) {
                    if (!spec.id || this.#devices.has(spec.id)) throw new Error('Duplicate or missing DC device id: ' + spec.id);
                    const supply = spec.supply ? this.supplies[spec.supply] : null;
                    const returnTerminal = spec.return ? this.returns[spec.return] : null;
                    if (spec.supply && !supply) throw new Error('Unknown DC supply: ' + spec.supply);
                    if (spec.return && !returnTerminal) throw new Error('Unknown DC return: ' + spec.return);
                    const device = this.registry.create(spec, {
                        circuit: this.circuit,
                        supply,
                        returnTerminal,
                        wire: (name, from, to) => this.wire(name, from, to)
                    });
                    this.#devices.set(spec.id, device);
                    this.#specs.set(spec.id, { ...spec });
                    if (spec.type === 'discrete-input') this.discreteBus.register(spec.id, device);
                    return device;
                }

                install(specs) {
                    return Object.fromEntries(specs.map(spec => [spec.id, this.add(spec)]));
                }

                get(id) {
                    return this.#devices.get(id) || null;
                }

                attachAvionicsDevice(spec, device, options = {}) {
                    if (this.#avionicsDevices.has(device) || spec.electrical === false) return device;
                    const supply = spec.dcSupply || options.supply || 'main';
                    const returnName = spec.dcReturn || options.return || 'dc';
                    const sense = spec.sense || options.sense || 'power';
                    const resistance = spec.inputResistance || options.inputResistance || 28000;
                    const prefix = (options.prefix ? options.prefix + '_' : '') + spec.id;
                    if (device instanceof DiscreteInput) {
                        const input = this.add({
                            id: prefix,
                            type: 'discrete-input',
                            name: spec.name || spec.id,
                            supply,
                            return: returnName,
                            resistance,
                            sense,
                            powerContact: device.power
                        });
                        device.bindElectricalInput(input);
                    } else if (device instanceof AvionicsSelector) {
                        device.positions.forEach(position => {
                            const input = this.add({
                                id: prefix + '_' + position,
                                type: 'discrete-input',
                                name: (spec.name || spec.id) + ' ' + position,
                                supply,
                                return: returnName,
                                resistance,
                                sense,
                                powerContact: device.power
                            });
                            device.bindElectricalPosition(position, input);
                        });
                    } else if (device instanceof AnalogControl) {
                        const input = this.add({
                            id: prefix,
                            type: 'analog-input',
                            name: spec.name || spec.id,
                            supply,
                            return: returnName,
                            resistance,
                            referenceVoltage: device.referenceVoltage,
                            powerContact: device.power
                        });
                        device.bindElectricalInput(input);
                    }
                    this.#avionicsDevices.add(device);
                    return device;
                }

                attachSelector(id, selector, options = {}) {
                    return this.attachAvionicsDevice({
                        id,
                        type: 'selector',
                        name: selector.name,
                        dcSupply: options.supply,
                        dcReturn: options.return,
                        inputResistance: options.inputResistance,
                        sense: options.sense
                    }, selector, options);
                }

                attachAnalog(id, control, options = {}) {
                    if (this.#avionicsDevices.has(control)) return control;
                    const input = this.add({
                        id,
                        type: 'analog-input',
                        name: control.name,
                        supply: options.supply || 'main',
                        return: options.return || 'dc',
                        resistance: options.resistance || 56000,
                        referenceVoltage: control.referenceVoltage,
                        powerContact: control.power
                    });
                    control.bindElectricalInput(input);
                    this.#avionicsDevices.add(control);
                    return control;
                }

                validate() {
                    const faults = [
                        ...this.circuit.validateTopology(),
                        ...this.discreteBus.validate()
                    ];
                    this.#specs.forEach((spec, id) => {
                        if (spec.supply && !this.protectedSupplies.has(spec.supply)) {
                            faults.push({ device: id, terminal: 'A1', fault: 'UNPROTECTED_FEED' });
                        }
                        if (spec.supply && !spec.return) {
                            faults.push({ device: id, terminal: 'A2', fault: 'MISSING_RETURN' });
                        }
                    });
                    this.#devices.forEach((device, id) => {
                        if (device instanceof DcAnalogInputAdapter && device.state === 'FAULT') {
                            faults.push({ device: id, terminal: 'ANALOG', fault: 'ANALOG_FAULT' });
                        }
                    });
                    const maximumCurrent = this.circuit.loads.reduce((sum, load) =>
                        sum + this.circuit.source.nominalVoltage / load.resistance, 0);
                    if (maximumCurrent > this.circuit.source.currentLimit) {
                        faults.push({
                            device: this.circuit.source.name,
                            terminal: 'L+',
                            fault: 'LOAD_BUDGET_EXCEEDED',
                            current: maximumCurrent,
                            limit: this.circuit.source.currentLimit
                        });
                    }
                    return faults;
                }

                get devices() {
                    return Object.fromEntries(this.#devices);
                }
            }

            const dcDeviceRegistry = new DcDeviceRegistry()
                .register('resistive-load', (spec, context) => {
                    const load = context.circuit.addLoad(new DcPoweredLoad(
                        spec.name || spec.id,
                        {
                            resistance: spec.resistance,
                            pickupVoltage: spec.pickupVoltage,
                            dropoutVoltage: spec.dropoutVoltage
                        }
                    ));
                    context.wire((spec.name || spec.id) + ' FEED', context.supply, load.a1);
                    context.wire((spec.name || spec.id) + ' RETURN', load.a2, context.returnTerminal);
                    return load;
                })
                .register('switched-load', (spec, context) => new DcSwitchedLoad(
                    spec.name || spec.id,
                    context.circuit,
                    context.supply,
                    context.returnTerminal,
                    spec.resistance,
                    context.wire
                ))
                .register('discrete-input', (spec, context) => new DcDiscreteInputAdapter(
                    spec.name || spec.id,
                    context.circuit,
                    context.supply,
                    context.returnTerminal,
                    spec.powerContact,
                    {
                        resistance: spec.resistance,
                        sense: spec.sense,
                        wire: context.wire
                    }
                ))
                .register('discrete-adapter', (spec, context) => new DcDiscreteInputAdapter(
                    spec.name || spec.id,
                    context.circuit,
                    context.supply,
                    context.returnTerminal,
                    spec.powerContact,
                    {
                        resistance: spec.resistance,
                        sense: spec.sense,
                        wire: context.wire
                    }
                ))
                .register('analog-input', (spec, context) => new DcAnalogInputAdapter(
                    spec.name || spec.id,
                    context.circuit,
                    context.supply,
                    context.returnTerminal,
                    spec.powerContact,
                    {
                        resistance: spec.resistance,
                        referenceVoltage: spec.referenceVoltage,
                        wire: context.wire
                    }
                ))
                .register('lamp', (spec, context) => {
                    const lamp = context.circuit.addLoad(new DcPoweredLoad(
                        spec.name || spec.id,
                        { resistance: spec.resistance }
                    ));
                    context.wire((spec.name || spec.id) + ' FEED', context.supply, lamp.a1);
                    context.wire((spec.name || spec.id) + ' RETURN', lamp.a2, context.returnTerminal);
                    return lamp;
                })
                .register('avionics-unit', (spec, context) => {
                    const unit = context.circuit.addLoad(new DcPoweredLoad(
                        spec.name || spec.id,
                        { resistance: spec.resistance }
                    ));
                    context.wire((spec.name || spec.id) + ' FEED', context.supply, unit.a1);
                    context.wire((spec.name || spec.id) + ' RETURN', unit.a2, context.returnTerminal);
                    return unit;
                })
                .register('relay', (spec, context) => {
                    const relay = context.circuit.addCoil(new DcRelayCoil(
                        spec.name || spec.id,
                        { resistance: spec.resistance || 280 }
                    ));
                    context.wire((spec.name || spec.id) + ' A1 FEED', context.supply, relay.a1);
                    context.wire((spec.name || spec.id) + ' A2 RETURN', relay.a2, context.returnTerminal);
                    return relay;
                });

            class DcStrobeEmitter extends EventTarget {
                #illuminated = false;
                #offTimer = null;

                constructor(name, load) {
                    super();
                    this.name = name;
                    this.load = load;
                    load.addEventListener('statechange', () => {
                        if (!load.energized) this.extinguish();
                        this.dispatchEvent(new Event('powerchange'));
                    });
                }

                get powered() {
                    return this.load.energized;
                }

                get illuminated() {
                    return this.#illuminated;
                }

                flash(duration) {
                    if (!this.powered) return false;
                    if (this.#offTimer !== null) clearTimeout(this.#offTimer);
                    this.#offTimer = null;
                    this.#setIlluminated(true);
                    this.#offTimer = setTimeout(() => {
                        this.#offTimer = null;
                        this.#setIlluminated(false);
                    }, duration);
                    return true;
                }

                extinguish() {
                    if (this.#offTimer !== null) clearTimeout(this.#offTimer);
                    this.#offTimer = null;
                    return this.#setIlluminated(false);
                }

                #setIlluminated(illuminated) {
                    const next = this.powered && !!illuminated;
                    if (next === this.#illuminated) return false;
                    this.#illuminated = next;
                    this.dispatchEvent(new Event('change'));
                    return true;
                }
            }

            class LightingControlUnit extends EventTarget {
                #cycleTimer = null;
                #timers = new Set();
                #running = false;
            #panelWord = '000000000';
                #warning = false;
                #cycleNumber = 0;

                constructor(circuit, controlLoad, loads, readPanelState) {
                    super();
                    this.name = 'A5 LIGHTING CONTROL UNIT';
                    this.circuit = circuit;
                    this.controlLoad = controlLoad;
                    this.loads = loads;
                    this.readPanelState = readPanelState;
                    this.flashRate = 40;
                    this.cycleDuration = 60000 / this.flashRate;
                    this.safeCurrentLimit = 1.6;
                    this.pattern = Object.freeze({
                        bitOrder: Object.freeze(['PL', 'ISR2', 'ISR1', 'ISR0', 'K', 'KR', 'M1', 'M0', 'L']),
                        shortPulse: 24,
                        longPulse: 58,
                        slot: 100,
                        start: 400,
                        whiteDoubleGap: 140,
                        oddTopDelay: 260,
                        warningGap: 80
                    });
                    this.strobes = Object.freeze({
                        top: new DcStrobeEmitter('H2 TOP MAIN BUS RED STROBE', loads.top.load),
                        left: new DcStrobeEmitter('H3 LEFT PLAYBACK WHITE STROBE', loads.left.load),
                        right: new DcStrobeEmitter('H4 RIGHT PLAYBACK WHITE STROBE', loads.right.load),
                        bottomLeft: new DcStrobeEmitter('H5 BOTTOM LEFT CURRENT GREEN STROBE', loads.bottomLeft.load),
                        bottomRight: new DcStrobeEmitter('H6 BOTTOM RIGHT PANEL CODE RED STROBE', loads.bottomRight.load)
                    });
                    Object.values(this.strobes).forEach(strobe => {
                        strobe.addEventListener('change', () => this.dispatchEvent(new Event('change')));
                        strobe.addEventListener('powerchange', () => this.dispatchEvent(new Event('change')));
                    });
                    loads.top.addEventListener('change', () => this.#synchronizeRunState());
                    controlLoad.addEventListener('statechange', () => this.#synchronizeRunState());
                    circuit.addEventListener('solved', () => this.#synchronizeRunState());
                    circuit.transaction(() => {
                        loads.top.setActive(true);
                        loads.left.setActive(true);
                        loads.right.setActive(true);
                        loads.bottomLeft.setActive(true);
                        loads.bottomRight.setActive(true);
                    });
                    this.#synchronizeRunState();
                }

                get running() {
                    return this.#running;
                }

                get panelWord() {
                    return this.#panelWord;
                }

                get warning() {
                    return this.#warning;
                }

                #synchronizeRunState() {
                    if (this.controlLoad.energized && !this.circuit.source.tripped) this.start();
                    else this.stop();
                }

                #encodePanelWord(state) {
                    const isr = { OFF: 0, R: 1, I: 2, S: 3, E: 4 }[state.isr] ?? 0;
                    const m = { C: 0, U: 1, D: 2 }[state.m] ?? 0;
                    return [
                        state.pl ? '1' : '0',
                        isr.toString(2).padStart(3, '0'),
                        state.k === 'ON' ? '1' : '0',
                        state.kr === 'ON' ? '1' : '0',
                        m.toString(2).padStart(2, '0'),
                        state.l === 'ON' ? '1' : '0'
                    ].join('');
                }

                #schedule(delay, work) {
                    const timer = setTimeout(() => {
                        this.#timers.delete(timer);
                        if (this.#running) work();
                    }, delay);
                    this.#timers.add(timer);
                }

                #beginCycle() {
                    if (!this.#running || !this.controlLoad.energized || this.circuit.source.tripped) {
                        this.stop();
                        return;
                    }
                    this.#cycleNumber++;
                    const panelState = this.readPanelState();
                    this.#panelWord = this.#encodePanelWord(panelState);
                    this.#warning = this.circuit.source.current > this.safeCurrentLimit;
                    const evenCycle = this.#cycleNumber % 2 === 0;
                    this.#schedule(0, () => {
                        this.strobes.left.flash(44);
                        this.strobes.right.flash(44);
                        if (evenCycle) this.strobes.top.flash(this.pattern.longPulse);
                    });
                    if (panelState.pl) this.#schedule(this.pattern.whiteDoubleGap, () => {
                        this.strobes.left.flash(44);
                        this.strobes.right.flash(44);
                    });
                    if (!evenCycle) this.#schedule(
                        this.pattern.oddTopDelay,
                        () => this.strobes.top.flash(this.pattern.longPulse)
                    );
                    if (this.#warning) [1, 2].forEach(index => this.#schedule(
                        this.pattern.start + index * this.pattern.warningGap,
                        () => this.strobes.bottomLeft.flash(42)
                    ));
                    [...this.#panelWord].forEach((bit, index) => this.#schedule(
                        this.pattern.start + index * this.pattern.slot,
                        () => {
                            if (index === 0) this.strobes.bottomLeft.flash(42);
                            this.strobes.bottomRight.flash(
                                bit === '1' ? this.pattern.longPulse : this.pattern.shortPulse
                            );
                        }
                    ));
                    this.dispatchEvent(new Event('cycle'));
                    this.#cycleTimer = setTimeout(() => {
                        this.#cycleTimer = null;
                        this.#beginCycle();
                    }, this.cycleDuration);
                }

                start() {
                    if (this.#running) return false;
                    this.#running = true;
                    this.#cycleTimer = setTimeout(() => {
                        this.#cycleTimer = null;
                        this.#beginCycle();
                    }, 0);
                    this.dispatchEvent(new Event('statechange'));
                    return true;
                }

                stop() {
                    if (!this.#running && this.#cycleTimer === null && !this.#timers.size) return false;
                    this.#running = false;
                    if (this.#cycleTimer !== null) clearTimeout(this.#cycleTimer);
                    this.#cycleTimer = null;
                    this.#timers.forEach(timer => clearTimeout(timer));
                    this.#timers.clear();
                    Object.values(this.strobes).forEach(strobe => strobe.extinguish());
                    this.dispatchEvent(new Event('statechange'));
                    return true;
                }

                snapshot() {
                    return {
                        running: this.#running,
                        cycleNumber: this.#cycleNumber,
                        flashRate: this.flashRate,
                        cycleDuration: this.cycleDuration,
                        safeCurrentLimit: this.safeCurrentLimit,
                        powered: this.controlLoad.energized,
                        warning: this.#warning,
                        panelWord: this.#panelWord,
                        pattern: this.pattern,
                        strobes: Object.fromEntries(Object.entries(this.strobes).map(
                            ([id, strobe]) => [id, {
                                powered: strobe.powered,
                                illuminated: strobe.illuminated,
                                voltage: strobe.load.voltage,
                                current: strobe.load.current
                            }]
                        ))
                    };
                }
            }

            class ElectricallyStartedLoadStage extends EventTarget {
                #readyAuthority = Symbol('LOAD COMPLETION CONTACT');
                #runNumber = 0;

                constructor(name, coil, circuit, work) {
                    super();
                    this.name = name;
                    this.coil = coil;
                    this.work = work;
                    this.state = 'idle';
                    this.progress = 0;
                    this.error = null;
                    this.readyContact = circuit.addContact(new ElectricalContact(
                        name + ' COMPLETION CONTACT',
                        false,
                        this.#readyAuthority
                    ));
                    coil.addEventListener('statechange', () => this.onCoilStateChange());
                    this.onCoilStateChange();
                }

                onCoilStateChange() {
                    if (this.coil.energized) {
                        if (this.state === 'idle') this.start();
                        return;
                    }
                    if (this.state === 'idle' && !this.readyContact.closed) return;

                    this.#runNumber++;
                    this.state = 'idle';
                    this.progress = 0;
                    this.error = null;
                    this.readyContact.setClosed(false, this.#readyAuthority);
                    this.dispatchEvent(new Event('statechange'));
                }

                start() {
                    if (!this.coil.energized || this.state !== 'idle') return false;
                    const runNumber = ++this.#runNumber;
                    this.state = 'running';
                    this.error = null;
                    this.dispatchEvent(new Event('statechange'));
                    const report = (completed, total) => {
                        if (runNumber !== this.#runNumber) return;
                        this.progress = total > 0 ? completed / total : 1;
                        this.dispatchEvent(new Event('progress'));
                    };
                    Promise.resolve()
                        .then(() => this.work(report))
                        .then(() => {
                            if (runNumber !== this.#runNumber || !this.coil.energized) return;
                            this.state = 'ready';
                            this.progress = 1;
                            this.readyContact.setClosed(true, this.#readyAuthority);
                            this.dispatchEvent(new Event('statechange'));
                        })
                        .catch(error => {
                            if (runNumber !== this.#runNumber) return;
                            this.state = 'fault';
                            this.error = error;
                            this.readyContact.setClosed(false, this.#readyAuthority);
                            this.dispatchEvent(new Event('statechange'));
                        });
                    return true;
                }
            }

            class DcBranchCircuitBreaker extends EventTarget {
                #authority = Symbol('DC BRANCH CIRCUIT BREAKER');
                #conducting = false;

                constructor(name, circuit, poleIds, lampResistance = 1400) {
                    super();
                    this.name = name;
                    this.circuit = circuit;
                    this.primaryPole = poleIds[0];
                    this.poles = new Map(poleIds.map(id => [
                        id,
                        circuit.addContact(new ElectricalContact(
                            `${name} ${id.toUpperCase()} POLE`,
                            true,
                            this.#authority
                        ))
                    ]));
                    this.lamp = circuit.addLoad(new DcPoweredLoad(
                        `${name} INTERNAL THREE-BAR LAMP`,
                        { resistance: lampResistance }
                    ));
                    this.connectedPoles = new Set();
                    this.lampConnected = false;
                    this.lamp.addEventListener('statechange', () => this.synchronize());
                }

                get closed() {
                    return [...this.poles.values()].every(contact => contact.closed);
                }

                get conducting() {
                    return this.#conducting;
                }

                connectPole(id, supply, returnTerminal, wire) {
                    const contact = this.poles.get(id);
                    if (!contact) throw new Error(`Unknown breaker pole ${this.name}:${id}`);
                    if (this.connectedPoles.has(id)) throw new Error(`Breaker pole already connected ${this.name}:${id}`);
                    wire(`${this.name} ${id.toUpperCase()} FEED`, supply, contact.line);
                    this.connectedPoles.add(id);
                    if (id === this.primaryPole && !this.lampConnected) {
                        wire(`${this.name} INTERNAL LAMP FEED`, contact.load, this.lamp.a1);
                        wire(`${this.name} INTERNAL LAMP RETURN`, this.lamp.a2, returnTerminal);
                        this.lampConnected = true;
                    }
                    return contact.load;
                }

                setClosed(closed) {
                    const next = Boolean(closed);
                    if (next === this.closed) return false;
                    this.circuit.transaction(() => {
                        this.poles.forEach(contact => contact.setClosed(next, this.#authority));
                    });
                    this.synchronize();
                    this.dispatchEvent(new Event('change'));
                    return true;
                }

                synchronize() {
                    const next = this.closed && this.lamp.energized && this.lamp.current > 0;
                    if (next === this.#conducting) return false;
                    this.#conducting = next;
                    this.dispatchEvent(new Event('statechange'));
                    return true;
                }

                snapshot() {
                    return {
                        closed: this.closed,
                        conducting: this.conducting,
                        lampVoltage: this.lamp.voltage,
                        lampCurrent: this.lamp.current,
                        poles: Object.fromEntries([...this.poles].map(([id, contact]) => [id, contact.closed]))
                    };
                }
            }

            class RotarySelector extends EventTarget {
                #index = 0;
                #camAuthority = Symbol('ROTARY SELECTOR CAM');
                #cams = [];

                constructor(name, detents, circuit) {
                    super();
                    this.name = name;
                    this.detents = detents;
                    this.circuit = circuit;
                }

                get index() {
                    return this.#index;
                }

                get position() {
                    return this.detents[this.#index];
                }

                pulseForward() {
                    return this.step(1);
                }

                pulseBackward() {
                    return this.step(-1);
                }

                addCamContact(name, closedAtDetents) {
                    const closedAt = new Set(closedAtDetents);
                    for (const detent of closedAt) {
                        if (!this.detents.includes(detent)) {
                            throw new Error(this.name + ' cam references invalid detent ' + detent);
                        }
                    }
                    const contact = this.circuit.addContact(new ElectricalContact(
                        this.name + ':' + name,
                        closedAt.has(this.position),
                        this.#camAuthority
                    ));
                    this.#cams.push({ contact, closedAt });
                    return contact;
                }

                step(direction) {
                    const requestedSteps = Math.trunc(Number(direction));
                    if (!Number.isFinite(requestedSteps) || requestedSteps === 0) return false;

                    const stepDirection = Math.sign(requestedSteps);
                    let moved = false;
                    for (let count = 0; count < Math.abs(requestedSteps); count++) {
                        if (!this.#stepOneDetent(stepDirection)) break;
                        moved = true;
                    }
                    return moved;
                }

                #stepOneDetent(direction) {
                    const index = Math.max(
                        0,
                        Math.min(this.detents.length - 1, this.#index + Math.sign(direction))
                    );
                    if (index === this.#index) return false;
                    const from = this.#index;
                    this.#index = index;
                    this.circuit.transaction(() => {
                        this.#cams.forEach(({ contact, closedAt }) => {
                            contact.setClosed(closedAt.has(this.position), this.#camAuthority);
                        });
                    });
                    this.dispatchEvent(new CustomEvent('change', {
                        detail: { from, to: index, direction: Math.sign(direction) }
                    }));
                    return true;
                }
            }

            class PageControlBus {
                #pagePowerAuthority = Symbol('PAGE MASTER SWITCH');
                #kR3ContactAuthority = Symbol('K R3 ANALOG CONTACT');
                #timeBusContactAuthority = Symbol('TIME BUS PL AUXILIARY CONTACT');

                constructor(work) {
                    this.circuit = new DcControlCircuit('PAGE CONTROL BUS', 28, 2);
                    const circuit = this.circuit;

                    this.pagePower = circuit.addContact(new ElectricalContact(
                        'S0 PAGE MASTER SWITCH',
                        false,
                        this.#pagePowerAuthority
                    ));
                    this.selector = new RotarySelector(
                        'S1 PAGE MODE SELECTOR',
                        ['STR', 'L', 'OBS'],
                        circuit
                    );
                    this.loadCommand = this.selector.addCamContact(
                        'L PERMISSIVE CAM',
                        ['L', 'OBS']
                    );
                    this.observeCommand = this.selector.addCamContact(
                        'OBS PERMISSIVE CAM',
                        ['OBS']
                    );
                    const readieContacts = {
                        tier1: this.selector.addCamContact('STR READIE CAM', ['STR']),
                        tier2: this.selector.addCamContact('L READIE CAM', ['L']),
                        main: this.selector.addCamContact('OBS READIE CAM', ['OBS'])
                    };

                    const coils = {
                        tier1: circuit.addCoil(new DcRelayCoil('K1 STR LOAD RELAY')),
                        tier2: circuit.addCoil(new DcRelayCoil('K2 L LOAD RELAY')),
                        tier3: circuit.addCoil(new DcRelayCoil('K3 OBS LOAD RELAY')),
                        tier4: circuit.addCoil(new DcRelayCoil('K4 MAIN SYSTEMS RELAY'))
                    };
                    const sealInContacts = {
                        tier2: circuit.addContact(coils.tier2.addNormallyOpenAuxiliary(
                            'K2-A NO SEAL-IN CONTACT'
                        )),
                        tier3: circuit.addContact(coils.tier3.addNormallyOpenAuxiliary(
                            'K3-A NO SEAL-IN CONTACT'
                        ))
                    };
                    const lightingTierContacts = {
                        load: circuit.addContact(coils.tier2.addNormallyOpenAuxiliary(
                            'K2-B NO L LIGHTING BUS CONTACT'
                        )),
                        observe: circuit.addContact(coils.tier3.addNormallyOpenAuxiliary(
                            'K3-B NO OBS LIGHTING BUS CONTACT'
                        ))
                    };
                    const mainSystemsContact = circuit.addContact(
                        coils.tier4.addNormallyOpenAuxiliary(
                            'K4-A NO MAIN SYSTEMS POWER CONTACT'
                        )
                    );
                    const kR3Contact = circuit.addContact(new ElectricalContact(
                        'K-R3 ANALOG ENABLE CONTACT',
                        false,
                        this.#kR3ContactAuthority
                    ));
                    const timeBusContact = circuit.addContact(new ElectricalContact(
                        'PL-T TIME BUS AUXILIARY CONTACT',
                        false,
                        this.#timeBusContactAuthority
                    ));
                    const readieLamp = circuit.addLoad(new DcPoweredLoad(
                        'H1 READIE LAMP',
                        { resistance: 2800 }
                    ));

                    this.tier1 = new ElectricallyStartedLoadStage(
                        'TIER 1', coils.tier1, circuit, work.tier1
                    );
                    this.tier2 = new ElectricallyStartedLoadStage(
                        'TIER 2', coils.tier2, circuit, work.tier2
                    );
                    this.tier3 = new ElectricallyStartedLoadStage(
                        'OBS BACKGROUND', coils.tier3, circuit, work.tier3
                    );
                    this.stages = Object.freeze([
                        this.tier1,
                        this.tier2,
                        this.tier3
                    ]);

                    const wires = {};
                    const wire = (key, name, from, to) => {
                        const conductor = circuit.wire(name, from, to);
                        wires[key] = conductor;
                        return conductor;
                    };

                    let breakerWireNumber = 0;
                    const breakerWire = (name, from, to) => wire(
                        `breaker${++breakerWireNumber}`,
                        name,
                        from,
                        to
                    );
                    const branchBreakers = {
                        pageControl: new DcBranchCircuitBreaker(
                            'CB-P0 PAGE CONTROL', circuit, ['control']
                        ),
                        mainSense: new DcBranchCircuitBreaker(
                            'CB-A0 MAIN AVIONICS BUS SENSE', circuit, ['main']
                        ),
                        panel: new DcBranchCircuitBreaker(
                            'CB-A1 PANEL CONTROLLER', circuit, ['main']
                        ),
                        playback: new DcBranchCircuitBreaker(
                            'CB-A2 PLAYBACK CONTROLLER', circuit, ['main', 'r3']
                        ),
                        sound: new DcBranchCircuitBreaker(
                            'CB-A3 SOUND CONTROLLER', circuit, ['main']
                        ),
                        archive: new DcBranchCircuitBreaker(
                            'CB-A4 ARCHIVE CONTROLLER', circuit, ['control']
                        ),
                        lighting: new DcBranchCircuitBreaker(
                            'CB-A5 LIGHTING CONTROL UNIT', circuit, ['control', 'l', 'obs']
                        ),
                        time: new DcBranchCircuitBreaker(
                            'CB-TIME TIME BUS', circuit, ['time']
                        )
                    };
                    const breakerSupplies = {
                        pageControl: branchBreakers.pageControl.connectPole(
                            'control', circuit.source.positive, circuit.source.negative, breakerWire
                        ),
                        mainSense: branchBreakers.mainSense.connectPole(
                            'main', mainSystemsContact.load, circuit.source.negative, breakerWire
                        ),
                        panel: branchBreakers.panel.connectPole(
                            'main', mainSystemsContact.load, circuit.source.negative, breakerWire
                        ),
                        playback: branchBreakers.playback.connectPole(
                            'main', mainSystemsContact.load, circuit.source.negative, breakerWire
                        ),
                        playbackR3: branchBreakers.playback.connectPole(
                            'r3', kR3Contact.load, circuit.source.negative, breakerWire
                        ),
                        sound: branchBreakers.sound.connectPole(
                            'main', mainSystemsContact.load, circuit.source.negative, breakerWire
                        ),
                        archive: branchBreakers.archive.connectPole(
                            'control', this.pagePower.load, circuit.source.negative, breakerWire
                        ),
                        lightingControl: branchBreakers.lighting.connectPole(
                            'control', this.pagePower.load, circuit.source.negative, breakerWire
                        ),
                        lightingL: branchBreakers.lighting.connectPole(
                            'l', lightingTierContacts.load.load, circuit.source.negative, breakerWire
                        ),
                        lightingObs: branchBreakers.lighting.connectPole(
                            'obs', lightingTierContacts.observe.load, circuit.source.negative, breakerWire
                        ),
                        time: branchBreakers.time.connectPole(
                            'time', timeBusContact.load, circuit.source.negative, breakerWire
                        )
                    };

                    wire('sourceToMaster', 'W001 SOURCE L+ TO S0 LINE',
                        breakerSupplies.pageControl, this.pagePower.line);

                    wire('masterToTier1', 'W101 S0 LOAD TO K1 A1',
                        this.pagePower.load, coils.tier1.a1);
                    wire('tier1Return', 'W102 K1 A2 TO RETURN',
                        coils.tier1.a2, circuit.source.negative);

                    wire('masterToTier1Ready', 'W201 S0 LOAD TO K1 READY LINE',
                        this.pagePower.load, this.tier1.readyContact.line);
                    wire('tier1ReadyToLoadCam', 'W202 K1 READY LOAD TO S1 L CAM LINE',
                        this.tier1.readyContact.load, this.loadCommand.line);
                    wire('loadCamToTier2', 'W203 S1 L CAM LOAD TO K2 A1',
                        this.loadCommand.load, coils.tier2.a1);
                    wire('tier1ReadyToTier2Seal', 'W204 K1 READY LOAD TO K2-A LINE',
                        this.tier1.readyContact.load, sealInContacts.tier2.line);
                    wire('tier2SealToTier2', 'W205 K2-A LOAD TO K2 A1',
                        sealInContacts.tier2.load, coils.tier2.a1);
                    wire('tier2Return', 'W206 K2 A2 TO RETURN',
                        coils.tier2.a2, circuit.source.negative);

                    wire('masterToTier2Ready', 'W301 S0 LOAD TO K2 READY LINE',
                        this.pagePower.load, this.tier2.readyContact.line);
                    wire('tier2ReadyToObserveCam', 'W302 K2 READY LOAD TO S1 OBS CAM LINE',
                        this.tier2.readyContact.load, this.observeCommand.line);
                    wire('observeCamToTier3', 'W303 S1 OBS CAM LOAD TO K3 A1',
                        this.observeCommand.load, coils.tier3.a1);
                    wire('tier2ReadyToTier3Seal', 'W304 K2 READY LOAD TO K3-A LINE',
                        this.tier2.readyContact.load, sealInContacts.tier3.line);
                    wire('tier3SealToTier3', 'W305 K3-A LOAD TO K3 A1',
                        sealInContacts.tier3.load, coils.tier3.a1);
                    wire('tier3Return', 'W306 K3 A2 TO RETURN',
                        coils.tier3.a2, circuit.source.negative);

                    wire('masterToTier3Ready', 'W401 S0 LOAD TO K3 READY LINE',
                        this.pagePower.load, this.tier3.readyContact.line);
                    wire('tier3ReadyToTier4', 'W402 K3 READY LOAD TO K4 A1',
                        this.tier3.readyContact.load, coils.tier4.a1);
                    wire('tier4Return', 'W403 K4 A2 TO RETURN',
                        coils.tier4.a2, circuit.source.negative);
                    wire('sourceToMainSystemsContact', 'W501 SOURCE L+ TO K4-A LINE',
                        circuit.source.positive, mainSystemsContact.line);
                    wire('mainSystemsToKR3Contact', 'W502 MAIN BUS TO K-R3 LINE',
                        mainSystemsContact.load, kR3Contact.line);
                    wire('mainSystemsToTimeBusContact', 'W503 MAIN BUS TO PL-T TIME BUS LINE',
                        mainSystemsContact.load, timeBusContact.line);
                    wire('masterToLLightingContact', 'W510 S0 LOAD TO K2-B L LIGHTING LINE',
                        this.pagePower.load, lightingTierContacts.load.line);
                    wire('masterToObsLightingContact', 'W511 S0 LOAD TO K3-B OBS LIGHTING LINE',
                        this.pagePower.load, lightingTierContacts.observe.line);
                    wire('tier1ReadyToReadieCam', 'W601 TIER 1 READY TO STR READIE CAM',
                        this.tier1.readyContact.load, readieContacts.tier1.line);
                    wire('tier1ReadieCamToLamp', 'W602 STR READIE CAM TO H1',
                        readieContacts.tier1.load, readieLamp.a1);
                    wire('tier2ReadyToReadieCam', 'W603 TIER 2 READY TO L READIE CAM',
                        this.tier2.readyContact.load, readieContacts.tier2.line);
                    wire('tier2ReadieCamToLamp', 'W604 L READIE CAM TO H1',
                        readieContacts.tier2.load, readieLamp.a1);
                    wire('mainBusToReadieCam', 'W605 MAIN BUS TO OBS READIE CAM',
                        mainSystemsContact.load, readieContacts.main.line);
                    wire('mainReadieCamToLamp', 'W606 OBS READIE CAM TO H1',
                        readieContacts.main.load, readieLamp.a1);
                    wire('readieLampReturn', 'W607 H1 TO RETURN',
                        readieLamp.a2, circuit.source.negative);

                    const netlist = new DcNetlist(circuit, dcDeviceRegistry, {
                        supplies: {
                            mainSense: breakerSupplies.mainSense,
                            panel: breakerSupplies.panel,
                            playback: breakerSupplies.playback,
                            sound: breakerSupplies.sound,
                            archive: breakerSupplies.archive,
                            lightingControl: breakerSupplies.lightingControl,
                            lightingL: breakerSupplies.lightingL,
                            lightingObs: breakerSupplies.lightingObs,
                            k: breakerSupplies.playbackR3,
                            time: breakerSupplies.time
                        },
                        returns: { dc: circuit.source.negative },
                        protectedSupplies: [
                            'mainSense',
                            'panel',
                            'playback',
                            'sound',
                            'archive',
                            'lightingControl',
                            'lightingL',
                            'lightingObs',
                            'k',
                            'time'
                        ]
                    });
                    const equipmentLoads = netlist.install([
                        { id: 'mainSystemsLoad', type: 'avionics-unit', name: 'A0 MAIN AVIONICS BUS SENSE', supply: 'mainSense', return: 'dc', resistance: 28000 },
                        { id: 'panelSystemsLoad', type: 'avionics-unit', name: 'A1 PANEL CONTROLLER', supply: 'panel', return: 'dc', resistance: 560 },
                        { id: 'playbackSystemsLoad', type: 'avionics-unit', name: 'A2 PLAYBACK CONTROLLER', supply: 'playback', return: 'dc', resistance: 560 },
                        { id: 'soundSystemsLoad', type: 'avionics-unit', name: 'A3 SOUND CONTROLLER', supply: 'sound', return: 'dc', resistance: 280 },
                        { id: 'archiveSystemsLoad', type: 'avionics-unit', name: 'A4 ARCHIVE CONTROLLER', supply: 'archive', return: 'dc', resistance: 1120 },
                        { id: 'lightingSystemsLoad', type: 'avionics-unit', name: 'A5 LIGHTING CONTROL UNIT', supply: 'lightingControl', return: 'dc', resistance: 1120 }
                    ]);
                    const activityLoads = netlist.install([
                        { id: 'playbackActivityLoad', type: 'switched-load', name: 'A2-L PLAYBACK MEDIA LOAD', supply: 'playback', return: 'dc', resistance: 560 },
                        { id: 'soundActivityLoad', type: 'switched-load', name: 'A3-L SOUND OUTPUT LOAD', supply: 'sound', return: 'dc', resistance: 140 },
                        { id: 'archiveActivityLoad', type: 'switched-load', name: 'A4-L ARCHIVE WORK LOAD', supply: 'archive', return: 'dc', resistance: 280 / 3 }
                    ]);
                    const timeBusLoads = netlist.install([
                        { id: 'timeActiveDisplay', type: 'lamp', name: 'H7 TIME BUS ACTIVE DISPLAY', supply: 'time', return: 'dc', resistance: 28000 },
                        { id: 'timeStandbyDisplay', type: 'lamp', name: 'H8 TIME BUS STANDBY DISPLAY', supply: 'time', return: 'dc', resistance: 28000 },
                        { id: 'timeDcAmpsDisplay', type: 'lamp', name: 'H9 TIME BUS DC AMPS DISPLAY', supply: 'time', return: 'dc', resistance: 28000 },
                        { id: 'mainSeekSensor', type: 'analog-input', name: 'T1 TIME BUS MAIN SEEK SENSOR', supply: 'time', return: 'dc', resistance: 56000, powerContact: timeBusPower },
                        { id: 'sectionSensor', type: 'analog-input', name: 'T2 TIME BUS SECTION SENSOR', supply: 'time', return: 'dc', resistance: 56000, powerContact: timeBusPower },
                        { id: 'timeSensor', type: 'analog-input', name: 'T3 TIME BUS TIME SENSOR', supply: 'time', return: 'dc', resistance: 56000, powerContact: timeBusPower },
                        { id: 'setInput', type: 'discrete-input', name: 'S2 TIME BUS SET INPUT', supply: 'time', return: 'dc', resistance: 28000, powerContact: timeBusPower }
                    ]);
                    [
                        ['seekRadialActiveDigits', timeBusLoads.timeActiveDisplay],
                        ['seekRadialStandbyDigits', timeBusLoads.timeStandbyDisplay],
                        ['mDcAmpsDisplay', timeBusLoads.timeDcAmpsDisplay]
                    ].forEach(([id, load]) => {
                        const display = m2SegmentDisplays.get(id);
                        if (!display) throw new Error('Missing segment display: ' + id);
                        display.connectElectricalLoad(load, circuit.source.nominalVoltage);
                    });
                    timeBusInputs = Object.freeze({
                        mainSeekSensor: timeBusLoads.mainSeekSensor,
                        sectionSensor: timeBusLoads.sectionSensor,
                        timeSensor: timeBusLoads.timeSensor,
                        setInput: timeBusLoads.setInput
                    });
                    const lightingLoads = netlist.install([
                        { id: 'topMainBusStrobe', type: 'switched-load', name: 'H2 TOP MAIN BUS RED STROBE POWER SUPPLY', supply: 'lightingControl', return: 'dc', resistance: 5600 },
                        { id: 'leftPlaybackStrobe', type: 'switched-load', name: 'H3 LEFT PLAYBACK WHITE STROBE POWER SUPPLY', supply: 'lightingObs', return: 'dc', resistance: 5600 },
                        { id: 'rightPlaybackStrobe', type: 'switched-load', name: 'H4 RIGHT PLAYBACK WHITE STROBE POWER SUPPLY', supply: 'lightingObs', return: 'dc', resistance: 5600 },
                        { id: 'bottomLeftCurrentStrobe', type: 'switched-load', name: 'H5 BOTTOM LEFT CURRENT GREEN STROBE POWER SUPPLY', supply: 'lightingL', return: 'dc', resistance: 5600 },
                        { id: 'bottomRightPanelStrobe', type: 'switched-load', name: 'H6 BOTTOM RIGHT PANEL CODE RED STROBE POWER SUPPLY', supply: 'lightingObs', return: 'dc', resistance: 5600 }
                    ]);
                    const powerBridges = [
                        [equipmentLoads.mainSystemsLoad, pageMainSystemsPower],
                        [equipmentLoads.panelSystemsLoad, panelAvionicsPower],
                        [equipmentLoads.playbackSystemsLoad, playbackAvionicsPower],
                        [equipmentLoads.soundSystemsLoad, soundAvionicsPower],
                        [equipmentLoads.archiveSystemsLoad, archiveAvionicsPower]
                    ];
                    powerBridges.forEach(([load, contact]) => {
                        const synchronize = () => contact.setClosed(
                            load.energized,
                            PAGE_CONTROL_AUTHORITY
                        );
                        load.addEventListener('statechange', synchronize);
                        synchronize();
                    });
                    playbackCircuit.board.connectElectricalNetlist(netlist, {
                        prefix: 'PLAYBACK',
                        supply: 'playback',
                        return: 'dc',
                        inputResistance: 28000
                    });
                    const synchronizeTimeBusContact = () => timeBusContact.setClosed(
                        playbackCircuit.plContact.active,
                        this.#timeBusContactAuthority
                    );
                    playbackCircuit.plContact.addEventListener('change', synchronizeTimeBusContact);
                    playbackAvionicsPower.addEventListener('change', synchronizeTimeBusContact);
                    synchronizeTimeBusContact();
                    const synchronizeTimeBusPower = () => timeBusPower.setClosed(
                        timeBusLoads.timeActiveDisplay.energized && !circuit.source.tripped,
                        PAGE_CONTROL_AUTHORITY
                    );
                    timeBusLoads.timeActiveDisplay.addEventListener('statechange', synchronizeTimeBusPower);
                    circuit.addEventListener('solved', synchronizeTimeBusPower);
                    synchronizeTimeBusPower();
                    const synchronizeKR3Contact = () => kR3Contact.setClosed(
                        playbackCircuit.k.effectivePosition === 'ON',
                        this.#kR3ContactAuthority
                    );
                    playbackCircuit.k.addEventListener('change', synchronizeKR3Contact);
                    synchronizeKR3Contact();
                    m2ExpansionBoard.connectElectricalNetlist(netlist, {
                        prefix: 'ARCHIVE',
                        supply: 'archive',
                        return: 'dc',
                        inputResistance: 28000
                    });
                    netlist.attachSelector('SOUND_L_COUPLING', soundCircuit.coupling, {
                        prefix: 'SOUND',
                        supply: 'sound',
                        return: 'dc',
                        inputResistance: 28000
                    });
                    netlist.attachAnalog('SOUND_VOLUME', soundCircuit.volume, {
                        supply: 'sound', return: 'dc', resistance: 56000
                    });
                    netlist.attachAnalog('SOUND_SPEED', soundCircuit.speed, {
                        supply: 'sound', return: 'dc', resistance: 56000
                    });
                    netlist.attachAnalog('SOUND_REVERB', soundCircuit.reverb, {
                        supply: 'sound', return: 'dc', resistance: 56000
                    });
                    const synchronizeActivityLoads = () => {
                        activityLoads.playbackActivityLoad.setActive(playbackCircuit.active);
                        activityLoads.soundActivityLoad.setActive(soundCircuit.active);
                        activityLoads.archiveActivityLoad.setActive(archiveCircuit.active);
                    };
                    playbackCircuit.addEventListener('activitychange', synchronizeActivityLoads);
                    soundCircuit.addEventListener('activitychange', synchronizeActivityLoads);
                    archiveCircuit.addEventListener('activitychange', synchronizeActivityLoads);
                    synchronizeActivityLoads();
                    const lightingController = new LightingControlUnit(
                        circuit,
                        equipmentLoads.lightingSystemsLoad,
                        {
                            top: lightingLoads.topMainBusStrobe,
                            left: lightingLoads.leftPlaybackStrobe,
                            right: lightingLoads.rightPlaybackStrobe,
                            bottomLeft: lightingLoads.bottomLeftCurrentStrobe,
                            bottomRight: lightingLoads.bottomRightPanelStrobe
                        },
                        () => ({
                            pl: playbackCircuit.plContact.active,
                            isr: playbackCircuit.isr.effectivePosition,
                            k: playbackCircuit.k.effectivePosition,
                            kr: playbackCircuit.kr.effectivePosition,
                            m: playbackCircuit.m.effectivePosition,
                            l: soundCircuit.coupling.effectivePosition
                        })
                    );
                    this.lighting = lightingController;

                    const circuitBreakerBindings = Object.freeze({
                        pageControlBreaker: branchBreakers.pageControl,
                        mainSenseBreaker: branchBreakers.mainSense,
                        panelBreaker: branchBreakers.panel,
                        playbackBreaker: branchBreakers.playback,
                        soundBreaker: branchBreakers.sound,
                        archiveBreaker: branchBreakers.archive,
                        lightingBreaker: branchBreakers.lighting,
                        timeBreaker: branchBreakers.time
                    });
                    Object.entries(circuitBreakerBindings).forEach(([id, breaker]) => {
                        const control = m2CircuitBreakerPanel.getBreaker(id);
                        if (!control) throw new Error('Missing circuit breaker control: ' + id);
                        const synchronize = () => {
                            control.setClosed(breaker.closed, false);
                            control.setConducting(breaker.conducting);
                        };
                        control.host.addEventListener('breakercommand', event => {
                            if (event.detail.id !== id) return;
                            breaker.setClosed(event.detail.closed);
                            synchronize();
                        });
                        breaker.addEventListener('change', synchronize);
                        breaker.addEventListener('statechange', synchronize);
                        synchronize();
                    });

                    this.schematic = Object.freeze({
                        source: circuit.source,
                        masterSwitch: this.pagePower,
                        selector: this.selector,
                        commandContacts: Object.freeze({
                            load: this.loadCommand,
                            observe: this.observeCommand
                        }),
                        readieContacts: Object.freeze(readieContacts),
                        coils: Object.freeze(coils),
                        sealInContacts: Object.freeze(sealInContacts),
                        lightingTierContacts: Object.freeze(lightingTierContacts),
                        mainSystemsContact,
                        kR3Contact,
                        timeBus: Object.freeze({
                            contact: timeBusContact,
                            power: timeBusPower,
                            loads: Object.freeze(timeBusLoads)
                        }),
                        mainSystemsLoad: equipmentLoads.mainSystemsLoad,
                        equipmentLoads: Object.freeze(equipmentLoads),
                        activityLoads: Object.freeze(activityLoads),
                        lightingLoads: Object.freeze(lightingLoads),
                        lightingController,
                        branchBreakers: circuitBreakerBindings,
                        readieLamp,
                        wires: Object.freeze(wires),
                        netlist
                    });
                    const topologyFaults = boardValidator.validate({
                        boards: [playbackCircuit.board, m2ExpansionBoard],
                        netlists: [netlist]
                    });
                    if (topologyFaults.length) {
                        throw new Error('PAGE CONTROL BUS topology fault: ' + JSON.stringify(topologyFaults));
                    }
                    const electricalTelemetry = Object.freeze({
                        snapshot: () => freezeTelemetry(circuit.snapshot()),
                        get source() {
                            return freezeTelemetry({ ...circuit.snapshot().source });
                        },
                        get discreteStates() {
                            return freezeTelemetry(netlist.discreteBus.states);
                        },
                        get lighting() {
                            return freezeTelemetry(lightingController.snapshot());
                        },
                        get breakers() {
                            return freezeTelemetry(Object.fromEntries(
                                Object.entries(circuitBreakerBindings).map(
                                    ([id, breaker]) => [id, breaker.snapshot()]
                                )
                            ));
                        },
                        get timeBus() {
                            const loads = Object.fromEntries(Object.entries(timeBusLoads).map(([id, device]) => [id, {
                                state: device.state || (device.energized ? 'POWERED' : 'OPEN'),
                                voltage: device.voltage,
                                current: device.current,
                                active: device.active ?? device.energized
                            }]));
                            return freezeTelemetry({
                                powered: timeBusPower.closed,
                                contactClosed: timeBusContact.closed,
                                current: Object.values(loads).reduce((sum, load) => sum + Math.abs(load.current || 0), 0),
                                loads
                            });
                        },
                        validateTopology: () => freezeTelemetry(
                            circuit.validateTopology().map(fault => ({ ...fault }))
                        ),
                        validate: () => freezeTelemetry(
                            boardValidator.validate({
                                boards: [playbackCircuit.board, m2ExpansionBoard],
                                netlists: [netlist]
                            }).map(fault => ({ ...fault }))
                        )
                    });
                    this.telemetry = electricalTelemetry;
                    publishReadOnlyWindow('m2Electrical', electricalTelemetry);
                    circuit.solve();
                }

                powerOn() {
                    return this.pagePower.setClosed(true, this.#pagePowerAuthority);
                }

                powerOff() {
                    return this.pagePower.setClosed(false, this.#pagePowerAuthority);
                }

                resetProtection() {
                    return this.circuit.resetProtection();
                }
            }

            class InstrumentSignalCable {
                constructor(source, receiver) {
                    this.source = source;
                    this.receiver = receiver;
                    this.source.addEventListener('instrumentdata', event => this.receiver.receive(event.detail));
                }
            }

            class DcAmmeterShunt {
                constructor(circuit, bypassLoads = []) {
                    if (!circuit?.source || !Array.isArray(circuit.loads)) {
                        throw new TypeError('DC ammeter shunt circuit required');
                    }
                    if (!Array.isArray(bypassLoads) || bypassLoads.some(load => !circuit.loads.includes(load))) {
                        throw new TypeError('DC ammeter shunt bypass load is not in circuit');
                    }
                    this.circuit = circuit;
                    this.bypassLoads = Object.freeze([...bypassLoads]);
                }

                measure() {
                    const sourceCurrent = Math.abs(this.circuit.source.current);
                    const bypassCurrent = this.bypassLoads.reduce(
                        (sum, load) => sum + Math.abs(load.current || 0),
                        0
                    );
                    return Math.max(0, sourceCurrent - bypassCurrent);
                }
            }

            class DcElectricalEngine extends EventTarget {
                constructor(circuit, frequency = 4, currentShunt = null) {
                    super();
                    if (!circuit || typeof circuit.solve !== 'function') {
                        throw new TypeError('DC electrical circuit required');
                    }
                    if (!Number.isFinite(frequency) || frequency <= 0) {
                        throw new TypeError('DC electrical engine frequency must be positive');
                    }
                    if (currentShunt !== null && typeof currentShunt.measure !== 'function') {
                        throw new TypeError('DC electrical engine current shunt required');
                    }
                    this.circuit = circuit;
                    this.frequency = frequency;
                    this.period = 1000 / frequency;
                    this.currentShunt = currentShunt;
                    this.sequence = 0;
                    this.timer = null;
                }

                pulse() {
                    this.circuit.solve();
                    this.sequence++;
                    const sourceCurrent = this.circuit.source.current;
                    this.dispatchEvent(new CustomEvent('cycle', {
                        detail: Object.freeze({
                            sequence: this.sequence,
                            frequency: this.frequency,
                            sourceCurrent,
                            measuredCurrent: this.currentShunt?.measure() ?? sourceCurrent,
                            sourceVoltage: this.circuit.source.nominalVoltage,
                            tripped: this.circuit.source.tripped
                        })
                    }));
                }

                start() {
                    if (this.timer !== null) return false;
                    this.pulse();
                    this.timer = window.setInterval(() => this.pulse(), this.period);
                    return true;
                }

                stop() {
                    if (this.timer === null) return false;
                    window.clearInterval(this.timer);
                    this.timer = null;
                    return true;
                }
            }

            class PageBusPanel {
                constructor(bus, control, knob, light, currentShunt = null) {
                    this.bus = bus;
                    this.control = control;
                    this.knob = knob;
                    this.light = light;
                    this.currentShunt = currentShunt;
                    this.instrument = control.querySelector('#pageBusInstrument');
                    this.archiveButton = control.querySelector('#pageArchiveR2');
                    this.archive = {
                        state: document.documentElement.dataset.m2ArchiveState || 'standby',
                        completed: Number(document.documentElement.dataset.m2ArchiveCompleted || 0),
                        total: Number(document.documentElement.dataset.m2ArchiveTotal || 0) ||
                            collectM2ArchiveManifest().length + 1
                    };
                    document.documentElement.dataset.m2ArchiveTotal = String(this.archive.total);
                    this.readouts = Object.fromEntries(
                        [...control.querySelectorAll('[data-bus-meter]')].map(readout => [
                            readout.dataset.busMeter,
                            readout
                        ])
                    );
                    this.signalOutputs = Object.fromEntries(
                        [...control.querySelectorAll('[data-bus-signal]')].map(output => [
                            output.dataset.busSignal,
                            output
                        ])
                    );
                    this.angles = [0, 57, 102];
                    this.sampledSourceCurrent = currentShunt?.measure() ?? bus.circuit.source.current;

                    bus.selector.addEventListener('change', () => this.render());
                    [bus.tier1, bus.tier2, bus.tier3].forEach(stage => {
                        stage.addEventListener('statechange', () => this.render());
                    });
                    bus.pagePower.addEventListener('change', () => this.render());
                    window.addEventListener('m2archiveprogress', event => {
                        this.archive.state = event.detail?.state || 'installing';
                        this.archive.completed = Number(event.detail?.completed || 0);
                        this.archive.total = Number(event.detail?.total || 0);
                        this.render();
                    });
                    window.addEventListener('m2archivecomplete', event => {
                        this.archive.state = 'complete';
                        this.archive.completed = Number(event.detail?.total || 0);
                        this.archive.total = Number(event.detail?.total || 0);
                        this.render();
                    });
                    window.addEventListener('m2archivefault', event => {
                        this.archive.state = 'fault';
                        this.archive.completed = Number(event.detail?.completed || this.archive.completed || 0);
                        this.archive.total = Number(event.detail?.total || this.archive.total || 0);
                        this.render();
                    });

                    if (this.archiveButton) {
                        this.archiveButton.addEventListener('pointerdown', event => {
                            event.stopPropagation();
                            try {
                                this.archiveButton.setPointerCapture(event.pointerId);
                            } catch (_) {}
                            this.archiveButton.src = ASSET['r2on.png'] ||
                                m2VersionedAssetUrl('/m/img/r2on.png');
                        });
                        const releaseArchiveButton = () => {
                            this.archiveButton.src = ASSET['r2off.png'] ||
                                m2VersionedAssetUrl('/m/img/r2off.png');
                        };
                        this.archiveButton.addEventListener('pointerup', releaseArchiveButton);
                        this.archiveButton.addEventListener('pointercancel', releaseArchiveButton);
                    }

                    knob.addEventListener('click', event => {
                        if (event.button !== 0) return;
                        this.backward();
                    });
                    knob.addEventListener('contextmenu', event => {
                        event.preventDefault();
                        this.forward();
                    });
                    knob.addEventListener('wheel', event => {
                        event.preventDefault();
                        event.stopPropagation();
                        if (event.deltaY < 0) this.forward();
                        else this.backward();
                    }, { passive: false });
                    control.addEventListener('keydown', event => {
                        if (event.key === 'ArrowRight' || event.key === 'ArrowUp' || event.key === 'Enter' || event.key === ' ') {
                            event.preventDefault();
                            this.forward();
                        } else if (event.key === 'ArrowLeft' || event.key === 'ArrowDown') {
                            event.preventDefault();
                            this.backward();
                        }
                    });
                    this.render();
                }

                sampleElectricalCycle(event) {
                    this.sampledSourceCurrent = event.detail.measuredCurrent;
                    this.render();
                }

                forward() {
                    panelSoundBank.unlock();
                    if (this.bus.selector.pulseForward()) panelSoundBank.play('loadSelector');
                }

                backward() {
                    panelSoundBank.unlock();
                    if (this.bus.selector.pulseBackward()) panelSoundBank.play('loadSelector');
                }

                render() {
                    const selector = this.bus.selector;
                    const ready = this.bus.schematic.readieLamp.energized;
                    this.knob.style.transform = 'translate(-49.5%, -55.2%) rotate(' + this.angles[selector.index] + 'deg)';
                    this.light.src = m2VersionedAssetUrl(
                        ready ? '/m/img/lightforalignon.png' : '/m/img/lightforalignoff.png'
                    );
                    this.light.classList.toggle('is-on', ready);

                    const source = this.bus.circuit.source;
                    const controlPowerAvailable = this.bus.pagePower.closed && !source.tripped;
                    const archiveTotal = this.archive.total;
                    const archiveCompleted = Math.min(this.archive.completed, archiveTotal || this.archive.completed);
                    const archivePercent = archiveTotal > 0
                        ? Math.min(100, archiveCompleted / archiveTotal * 100).toFixed(1)
                        : (this.archive.state === 'complete' ? '100.0' : '0.0');
                    const values = {
                        dcAmps: controlPowerAvailable ? this.sampledSourceCurrent.toFixed(3) : '0.000',
                        archivePercent: this.archive.state === 'fault' ? 'FAULT' : archivePercent,
                        dcVolts: controlPowerAvailable ? source.nominalVoltage.toFixed(1) : '0.0',
                        archiveCompleted: String(archiveCompleted),
                        archiveTotal: String(archiveTotal)
                    };
                    Object.entries(values).forEach(([name, value]) => {
                        if (this.readouts[name]) this.readouts[name].textContent = value;
                        if (this.signalOutputs[name]) {
                            this.signalOutputs[name].dispatchEvent(new CustomEvent('instrumentdata', {
                                detail: Object.freeze({ name, value })
                            }));
                        }
                    });
                    if (this.instrument) {
                        this.instrument.classList.toggle(
                            'is-fault',
                            source.tripped || this.archive.state === 'fault'
                        );
                    }

                    this.control.dataset.detent = selector.position;
                    this.control.dataset.tier1 = this.bus.tier1.state;
                    this.control.dataset.tier2 = this.bus.tier2.state;
                    this.control.dataset.main = this.bus.tier3.state;
                    this.control.setAttribute('aria-busy', ready ? 'false' : 'true');
                }
            }

            class StrobeField {
                constructor(lighting, root) {
                    this.lighting = lighting;
                    this.root = root;
                    this.elements = Object.fromEntries(
                        [...root.querySelectorAll('[data-strobe]')].map(element => [
                            element.dataset.strobe,
                            element
                        ])
                    );
                    lighting.addEventListener('change', () => this.render());
                    lighting.addEventListener('statechange', () => this.render());
                    lighting.addEventListener('cycle', () => this.render());
                    this.render();
                }

                render() {
                    const state = this.lighting.snapshot();
                    Object.entries(this.elements).forEach(([id, element]) => {
                        const strobe = state.strobes[id];
                        element.classList.toggle('is-lit', !!strobe?.illuminated);
                        element.dataset.powered = strobe?.powered ? 'true' : 'false';
                    });
                    this.root.dataset.running = state.running ? 'true' : 'false';
                    this.root.dataset.warning = state.warning ? 'true' : 'false';
                    this.root.dataset.panelWord = state.panelWord;
                }
            }

            window.addEventListener('DOMContentLoaded', () => {
                const overlay = document.getElementById('loaderOverlay');
                const control = document.getElementById('pageBusControl');
                const knob = document.getElementById('pageBusKnob');
                const light = document.getElementById('pageBusLight');
                const strobeField = document.getElementById('m2StrobeField');
                if (!overlay || !control || !knob || !light || !strobeField) return;

                function getVersionedUrl(url) {
                    return m2VersionedAssetUrl(url);
                }

                function loadMediaEl(el) {
                    if (!el) return false;
                    if (!el.dataset?.src) return !!el.getAttribute('src');
                    el.src = getVersionedUrl(el.dataset.src);
                    el.removeAttribute('data-src');
                    return true;
                }
                window.loadMediaEl = loadMediaEl;

                function observeVideos(videos) {
                    if (!videos.length) return;
                    if (!('IntersectionObserver' in window)) {
                        videos.forEach(loadMediaEl);
                        return;
                    }
                    const observer = new IntersectionObserver((entries, obs) => {
                        entries.forEach(entry => {
                            if (!entry.isIntersecting) return;
                            loadMediaEl(entry.target);
                            obs.unobserve(entry.target);
                        });
                    }, { rootMargin: '800px 0px' });
                    videos.forEach(video => observer.observe(video));
                }

                function retryFailedImage(image, source) {
                    const retry = () => {
                        image.addEventListener('error', () => {
                            image.classList.add('media-load-failed');
                        }, { once: true });
                        image.src = getVersionedUrl(source);
                    };
                    if ('requestIdleCallback' in window) requestIdleCallback(retry, { timeout: 10000 });
                    else setTimeout(retry, 3000);
                }

                function loadPageImage(image, retryOnError = true) {
                    if (!image?.dataset?.src) return;
                    const source = image.dataset.src;
                    if (retryOnError) {
                        image.addEventListener('error', () => retryFailedImage(image, source), { once: true });
                    }
                    image.decoding = 'async';
                    image.src = getVersionedUrl(source);
                    image.removeAttribute('data-src');
                }

                function observePageImages(images) {
                    if (!images.length) return;
                    if (!('IntersectionObserver' in window)) {
                        images.forEach(image => {
                            image.loading = 'lazy';
                            loadPageImage(image);
                        });
                        return;
                    }
                    const observer = new IntersectionObserver((entries, obs) => {
                        entries.forEach(entry => {
                            if (!entry.isIntersecting) return;
                            loadPageImage(entry.target);
                            obs.unobserve(entry.target);
                        });
                    }, { rootMargin: '800px 0px' });
                    images.forEach(image => observer.observe(image));
                }

                function loadPageImages(report) {
                    const images = [...document.querySelectorAll('img[data-src]')];
                    report(0, 1);
                    observePageImages(images);
                    report(1, 1);
                    return Promise.resolve();
                }

                function isPresentationImage(image) {
                    if (!image?.isConnected) return false;
                    const style = getComputedStyle(image);
                    if (style.display === 'none' || style.visibility === 'hidden') return false;
                    const rect = image.getBoundingClientRect();
                    return rect.width > 0 &&
                        rect.height > 0 &&
                        rect.bottom > 0 &&
                        rect.right > 0 &&
                        rect.top < window.innerHeight &&
                        rect.left < window.innerWidth;
                }

                function waitForRequiredImage(image) {
                    return new Promise((resolve, reject) => {
                        let settled = false;
                        let watchdog = null;
                        const source = image.dataset.src || image.currentSrc || image.src || 'unknown image';
                        const cleanup = () => {
                            if (watchdog !== null) clearTimeout(watchdog);
                            image.removeEventListener('load', verify);
                            image.removeEventListener('error', fail);
                        };
                        const succeed = () => {
                            if (settled) return;
                            settled = true;
                            cleanup();
                            image.classList.remove('media-load-failed');
                            resolve();
                        };
                        const fail = () => {
                            if (settled) return;
                            settled = true;
                            cleanup();
                            image.classList.add('media-load-failed');
                            reject(new Error('OBS image sensing fault: ' + source));
                        };
                        const verify = () => {
                            if (settled || !image.complete) return;
                            if (image.naturalWidth <= 0 || image.naturalHeight <= 0) {
                                fail();
                                return;
                            }
                            const decoding = typeof image.decode === 'function'
                                ? image.decode()
                                : Promise.resolve();
                            decoding.then(succeed, fail);
                        };

                        watchdog = setTimeout(fail, 15000);
                        image.addEventListener('load', verify);
                        image.addEventListener('error', fail);
                        if (image.dataset.src) loadPageImage(image, false);
                        queueMicrotask(verify);
                    });
                }

                function waitForTwoPaintFrames() {
                    return new Promise(resolve => {
                        requestAnimationFrame(() => requestAnimationFrame(resolve));
                    });
                }

                async function observePagePresentation(report) {
                    const images = [...document.querySelectorAll('#songList .cImg img')]
                        .filter(isPresentationImage);
                    const fontLoads = [
                        document.fonts.load('1em "Junicode"'),
                        document.fonts.load('1em "nullpunktsenergiefont"')
                    ];
                    const tasks = [
                        ...fontLoads,
                        ...images.map(waitForRequiredImage)
                    ];
                    const total = tasks.length + 1;
                    let completed = 0;
                    const markReady = () => report(++completed, total);
                    report(0, total);
                    await Promise.all(tasks.map(task => task.then(markReady)));
                    await waitForTwoPaintFrames();
                    markReady();
                }

                function disconnectOverlay() {
                    overlay.style.display = 'none';
                    document.body.classList.remove('loading-overlay-active');
                }

                mainSystemsEvents.addEventListener('ready', () => {
                    if (!pageMainSystemsPower.closed) return;
                    const run = () => {
                        document.body.classList.add('babelstone-ready');
                        document.fonts.load('1em "BabelStone Han"').catch(() => {});
                    };
                    if ('requestIdleCallback' in window) requestIdleCallback(run, { timeout: 10000 });
                    else setTimeout(run, 2000);
                }, { once: true });

                const bus = new PageControlBus({
                    tier1: report => loadTierOneResources(report),
                    tier2: report => loadPageImages(report),
                    tier3: async report => {
                        await observePagePresentation(report);
                        observeVideos([...document.querySelectorAll('video[data-src]:not([data-sync])')]);
                        disconnectOverlay();
                    }
                });
                const syncAmbientSound = () => {
                    if (bus.pagePower.closed && (bus.selector.position === 'L' || bus.selector.position === 'OBS')) {
                        panelSoundBank.startHeld('starterAmbient');
                    } else if (!bus.pagePower.closed) {
                        panelSoundBank.stopAll();
                    }
                };
                bus.selector.addEventListener('change', syncAmbientSound);
                bus.pagePower.addEventListener('change', syncAmbientSound);
                panelSoundBank.load('177453744').then(syncAmbientSound).catch(() => {});

                const stageTelemetry = stage => freezeTelemetry({
                    name: stage.name,
                    state: stage.state,
                    progress: stage.progress,
                    error: stage.error ? String(stage.error.message || stage.error) : null
                });
                const selectorTelemetry = Object.freeze({
                    get index() {
                        return bus.selector.index;
                    },
                    get position() {
                        return bus.selector.position;
                    },
                    step: direction => bus.selector.step(direction),
                    pulseForward: () => bus.selector.pulseForward(),
                    pulseBackward: () => bus.selector.pulseBackward()
                });
                const pageControlFacade = Object.freeze({
                    powerOn: () => bus.powerOn(),
                    powerOff: () => bus.powerOff(),
                    resetProtection: () => bus.resetProtection(),
                    selector: selectorTelemetry,
                    circuit: bus.telemetry,
                    get pagePower() {
                        return freezeTelemetry({ closed: bus.pagePower.closed });
                    },
                    get tier1() {
                        return stageTelemetry(bus.tier1);
                    },
                    get tier2() {
                        return stageTelemetry(bus.tier2);
                    },
                    get tier3() {
                        return stageTelemetry(bus.tier3);
                    },
                    get schematic() {
                        return freezeTelemetry({
                            source: { ...bus.circuit.snapshot().source },
                            selector: bus.selector.position,
                            masterSwitch: bus.pagePower.closed,
                            mainSystemsContact: bus.schematic.mainSystemsContact.closed,
                            readieLamp: bus.schematic.readieLamp.energized,
                            lighting: bus.lighting.snapshot(),
                            coils: Object.fromEntries(Object.entries(bus.schematic.coils).map(
                                ([name, coil]) => [name, {
                                    energized: coil.energized,
                                    voltage: coil.voltage,
                                    current: coil.current
                                }]
                            ))
                        });
                    }
                });
                publishReadOnlyWindow('pageControlBus', pageControlFacade);
                publishReadOnlyWindow('pageControlCircuit', bus.telemetry);
                const dcAmpsSignal = control.querySelector('[data-bus-signal="dcAmps"]');
                const dcAmpsDisplay = document.getElementById('mDcAmpsDisplay');
                if (dcAmpsSignal && dcAmpsDisplay) {
                    new InstrumentSignalCable(dcAmpsSignal, mDcAmpsSegmentDisplay);
                }
                const dcAmpsShunt = new DcAmmeterShunt(
                    bus.circuit,
                    [bus.schematic.timeBus.loads.timeDcAmpsDisplay]
                );
                const panel = new PageBusPanel(bus, control, knob, light, dcAmpsShunt);
                const electricalEngine = new DcElectricalEngine(bus.circuit, 4, dcAmpsShunt);
                electricalEngine.addEventListener('cycle', event => panel.sampleElectricalCycle(event));
                new StrobeField(bus.lighting, strobeField);
                bus.powerOn();
                electricalEngine.start();
            });
        })();

        (function() {
            const rw = document.getElementById('rBarWrapper');
            if (!rw) return;
            rw.addEventListener('wheel', function(e) {
                if (!e.target.closest('.tJump')) e.preventDefault();
            }, { passive: false });
            rw.addEventListener('touchmove', function(e) {
                if (!e.target.closest('.tJump')) e.preventDefault();
            }, { passive: false });
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
                el && el.tagName === 'AUDIO' && el.closest('.card');

            function collageInfo(audio) {
                if (!(window.__npK && window.__npK())) return null;
                const card = audio.closest('.card');
                if (!card) return null;
                const dur = window.__npPlaybackDuration ? window.__npPlaybackDuration(audio) : audio.duration;
                if (!dur || !isFinite(dur)) return null;
                const lines = card.querySelectorAll('.lrcLine:not([data-l])');
                if (lines.length < 2) return null;
                const segs = [];
                for (let i = 0; i < lines.length; i++) {
                    const start = parseFloat(lines[i].dataset.t);
                    if (isNaN(start)) continue;
                    let end = dur;
                    const next = lines[i + 1];
                    if (next) { const ns = parseFloat(next.dataset.t); if (!isNaN(ns)) end = ns; }
                    if (window.__npSectionEligible(end - start)) segs.push([start, end, (lines[i].textContent || '').trim()]);
                }
                if (!segs.length) return null;
                const t = window.__npPlaybackTime ? window.__npPlaybackTime(audio) : audio.currentTime;
                let cur = segs[segs.length - 1];
                for (const s of segs) { if (t >= s[0] && t < s[1]) { cur = s; break; } }
                return { part: cur[2], start: cur[0], end: cur[1] };
            }

            function npMemberName(audio, title) {
                const info = audio.__virtualSong;
                if (!info) return title;
                const seriesName = (info.seriesTitle || '').trim();
                const seriesPrefix = seriesName.replace(/\s+series\s*$/iu, '').trim();
                if (!seriesPrefix || !title.startsWith(seriesPrefix)) return title;
                return title.slice(seriesPrefix.length)
                    .replace(/^[\s\u2010-\u2015\u2212:·]+/u, '')
                    .trim();
            }

            function buildState(audio, paused) {
                const card = audio.closest('.card');
                if (!card) return null;
                let name = (card.querySelector('.cName')?.textContent || '').trim();
                const category = (card.closest('.cardWrap')?.dataset.category || '').trim();
                let art = '';
                const raw = (card.dataset.art || '').trim();
                if (raw && !raw.startsWith('blob:')) {
                    try { art = new URL(raw, ART_BASE).href; } catch (e) { art = ''; }
                }
                let startMs = 0, endMs = 0;
                const dur = window.__npPlaybackDuration ? window.__npPlaybackDuration(audio) : audio.duration;
                const time = window.__npPlaybackTime ? window.__npPlaybackTime(audio) : audio.currentTime;
                const col = collageInfo(audio);
                if (col) {
                    const part = npMemberName(audio, col.part);
                    if (part) name = name ? (name + ' — ' + part) : part;
                    startMs = Math.round(Date.now() - (time - col.start) * 1000);
                    endMs = Math.round(startMs + (col.end - col.start) * 1000);
                } else if (dur && isFinite(dur)) {
                    startMs = Math.round(Date.now() - time * 1000);
                    endMs = Math.round(startMs + dur * 1000);
                }
                return { name, category, art, startMs, endMs, paused: !!paused };
            }

            function push(obj) {
                const body = JSON.stringify(obj);
                try {
                    if (navigator.sendBeacon && navigator.sendBeacon(ENDPOINT, new Blob([body], { type: 'text/plain' }))) return;
                    fetch(ENDPOINT, {
                        method: 'POST',
                        headers: { 'content-type': 'text/plain' },
                        body,
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
            document.addEventListener('ended', e => {
                if (e.__virtualSongContinues) return;
                if (isCardAudio(e.target) && !(window.__npKrLooping && window.__npKrLooping(e.target))) {
                    lastKey = '';
                    push({ clear: true });
                }
            }, true);
            setInterval(() => {
                document.querySelectorAll('audio').forEach(a => { if (isCardAudio(a) && !a.paused) send(a, false); });
            }, 10000);
            window.addEventListener('pagehide', () => { lastKey = ''; push({ clear: true }); });
        })();
    </script>
    <script>
        (() => {
            const terminal = document.getElementById('mTerminal');
            const terminalScreen = document.getElementById('mTerminalScreen');
            const terminalColumns = 24;
            const terminalRows = 14;
            const terminalCells = Array.from({ length: terminalColumns * terminalRows }, (_, index) => {
                const cell = document.createElement('span');
                cell.className = 'mTerminalCell';
                cell.dataset.terminalRow = String(Math.floor(index / terminalColumns) + 1);
                cell.dataset.terminalColumn = String(index % terminalColumns + 1);
                terminalScreen.append(cell);
                return cell;
            });
            const blankTerminalCell = () => ({ color: 'white', size: 'large', font: 'standard', inverted: false, flashing: false });
            const terminalCharacterBuffer = Array(terminalColumns * terminalRows).fill(' ');
            const terminalAttributeBuffer = Array.from({ length: terminalColumns * terminalRows }, blankTerminalCell);
            const terminalCellIndex = (row, column) => row * terminalColumns + column;
            const clearTerminalRow = row => {
                for (let column = 0; column < terminalColumns; column++) {
                    const index = terminalCellIndex(row, column);
                    terminalCharacterBuffer[index] = ' ';
                    terminalAttributeBuffer[index] = blankTerminalCell();
                }
            };
            const clearTerminalScreen = () => {
                for (let row = 0; row < terminalRows; row++) clearTerminalRow(row);
            };
            const writeTerminalText = (row, column, value, attributes = {}) => {
                [...String(value ?? '')].forEach((character, offset) => {
                    const targetColumn = column + offset;
                    if (row < 0 || row >= terminalRows || targetColumn < 0 || targetColumn >= terminalColumns) return;
                    const index = terminalCellIndex(row, targetColumn);
                    terminalCharacterBuffer[index] = character;
                    terminalAttributeBuffer[index] = { ...blankTerminalCell(), ...attributes };
                });
            };
            const renderTerminalRows = rows => {
                rows.forEach(row => {
                    for (let column = 0; column < terminalColumns; column++) {
                        const index = terminalCellIndex(row, column);
                        const cell = terminalCells[index];
                        const attributes = terminalAttributeBuffer[index];
                        cell.textContent = terminalCharacterBuffer[index];
                        cell.className = [
                            'mTerminalCell',
                            attributes.size === 'small' ? 'is-small' : '',
                            attributes.color !== 'white' ? `is-${attributes.color}` : '',
                            attributes.inverted ? 'is-inverted' : '',
                            attributes.flashing ? 'is-flashing' : ''
                        ].filter(Boolean).join(' ');
                    }
                });
            };
            const renderTerminalScreen = () => renderTerminalRows(Array.from({ length: terminalRows }, (_, row) => row));
            const writeTerminalAligned = (row, value, alignment, attributes = {}) => {
                const text = [...String(value ?? '')].slice(0, terminalColumns).join('');
                const column = alignment === 'right' ? terminalColumns - text.length : alignment === 'center' ? Math.floor((terminalColumns - text.length) / 2) : 0;
                writeTerminalText(row, column, text, attributes);
                return column;
            };
            const terminalFields = ['1L', '2L', '3L', '4L', '5L', '6L', '1R', '2R', '3R', '4R', '5R', '6R'];
            const entryFields = ['1L', '2L', '3L', '4L', '5L', '1R', '2R', '3R', '4R', '5R'];
            const maxTextLength = 15;
            const maxTitleLength = 24;
            const pageSize = entryFields.length;
            const terminalAlphabetGroups = <?= json_encode($fixþebrokenorderingofdefaultphpineedtomakeþisanklaßlatertobefairwellfornowweshallkeepusingþischangenotnameofþisvartwillbeanfunktionwiþtimejslaterwewillimportsotakeþisasantodoplease, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            const terminalCharacterRanks = new Map();
            const terminalAsciiCharacters = new Map();
            terminalAlphabetGroups.forEach((group, rank) => {
                const directLatin = group.match(/[0-9a-z]/i)?.[0];
                const foldedLatin = group[0]?.normalize('NFD').replace(/\p{M}/gu, '').match(/[0-9a-z]/i)?.[0];
                const terminalCharacter = (directLatin || foldedLatin || '').toLowerCase();
                [...group.toLowerCase()].forEach(character => {
                    terminalCharacterRanks.set(character, rank);
                    if (terminalCharacter) terminalAsciiCharacters.set(character, terminalCharacter);
                });
            });
            const asciiMaker = value => [...String(value ?? '').replace(/[\u00A0\u202F]/g, ' ').replace(/(\p{L})\u0308/gu, '$1$1')]
                .map(character => {
                    if (/^[\x20-\x7E]$/.test(character)) return character;
                    if (character === 'þ' || character === 'ð') return 'th';
                    if (character === 'Þ' || character === 'Ð') return 'TH';
                    const replacement = terminalAsciiCharacters.get(character.toLowerCase());
                    if (!replacement) return character;
                    return character === character.toUpperCase() && character !== character.toLowerCase() ? replacement.toUpperCase() : replacement;
                })
                .join('')
                .replace(/ß/g, 'ss')
                .normalize('NFKD')
                .replace(/\p{M}/gu, '')
                .replace(/[^\x20-\x7E]/g, '');
            const orderingText = (value, ignoreLeadingMarks = false) => {
                let text = String(value ?? '').replace(/[\u00A0\u202F]/g, ' ');
                if (ignoreLeadingMarks) text = text.replace(/^[\p{P}\p{Z}\s]+/u, '');
                text = text.normalize('NFD').replace(/(.)\u0308/gu, '$1$1').normalize('NFC');
                return text.replace(/№\s*(\d+)/gu, (_, number) => '№' + String(Number(number)).padStart(5, '0')).toLowerCase();
            };
            const compareTerminalText = (left, right, ignoreLeadingMarks = false) => {
                const first = [...orderingText(left, ignoreLeadingMarks)];
                const second = [...orderingText(right, ignoreLeadingMarks)];
                const length = Math.min(first.length, second.length);
                for (let index = 0; index < length; index++) {
                    if (first[index] === second[index]) continue;
                    const firstRank = terminalCharacterRanks.get(first[index]);
                    const secondRank = terminalCharacterRanks.get(second[index]);
                    if (firstRank !== undefined && secondRank !== undefined) {
                        if (firstRank !== secondRank) return firstRank - secondRank;
                        continue;
                    }
                    if (firstRank !== undefined && secondRank === undefined) return -1;
                    if (firstRank === undefined && secondRank !== undefined) return 1;
                    if (first[index] < second[index]) return -1;
                    if (first[index] > second[index]) return 1;
                }
                return first.length - second.length;
            };
            const sourceSongCards = [...document.querySelectorAll('.cardWrap')].map((wrap, index) => {
                const card = wrap.querySelector('.card');
                const virtualMember = card?.__virtualMember;
                return {
                    id: card?.id || String(index),
                    title: card?.querySelector('.cName')?.textContent.trim() || '',
                    sortTitle: wrap.dataset.sortKey?.trim() || card?.querySelector('.cName')?.textContent.trim() || '',
                    category: wrap.dataset.category?.trim() || '',
                    url: card?.dataset.songUrl?.trim() || '',
                    seriesTitle: virtualMember?.info?.seriesTitle || '',
                    memberTitle: virtualMember?.displayTitle || '',
                    hasTranslation: !!wrap.querySelector('.mLyricsToggle')
                };
            }).filter(song => song.title !== '');
            const songsByUrl = [...sourceSongCards].sort((a, b) => {
                const left = a.url.toUpperCase();
                const right = b.url.toUpperCase();
                return left.length - right.length || (left < right ? -1 : left > right ? 1 : 0);
            }).map(song => ({ ...song, code: song.url.toUpperCase() }));
            const songCards = songsByUrl;
            const categories = [...new Set(songCards.map(song => song.category).filter(Boolean))].sort(compareTerminalText);
            const songLetters = [...new Set(songCards.map(song =>
                (asciiMaker(song.sortTitle).match(/[A-Za-z0-9]/) || [''])[0].toUpperCase()).filter(Boolean))].sort(compareTerminalText);
            const fieldActions = new Map();
            let folio = { kind: 'index', page: 1 };
            let scratchpad = '';
            let selectedCode = '';
            let scratchpadMessage = '';
            let scratchpadMessageTimer = 0;
            let deleteArmed = false;
            let settingsDirty = false;
            const globalSettings = { speed: '', sectionWindow: '', standby: '' };
            const persistedGlobalSettings = { ...globalSettings };
            const songSettings = {};
            const persistedSongSettings = {};
            let legsDraft = [];
            let persistedLegsPlan = [];
            let legsDraftPages = 1;
            let persistedLegsPages = 1;
            let legsProgress = { active: false };
            const limitTerminalText = (value, maximum) => {
                const text = asciiMaker(value).toUpperCase();
                if (text.length <= maximum) return text;
                const clipped = text.slice(0, maximum);
                if (/\s/u.test(text[maximum])) return clipped.trimEnd();
                const wordBoundary = clipped.lastIndexOf(' ');
                return wordBoundary > 0 ? clipped.slice(0, wordBoundary) : clipped;
            };
            const limitText = value => limitTerminalText(value, maxTextLength);
            const limitTitleText = value => limitTerminalText(
                asciiMaker(value).toUpperCase().replace(/[^A-Z0-9 ]/gu, ''),
                maxTitleLength
            );
            const terminalCategoryName = value => asciiMaker(value)
                .replace(/\s*[;,]\s*/gu, ' ')
                .replace(/\s+/gu, ' ')
                .trim()
                .toUpperCase();
            const terminalSongTitle = song => {
                if (song.seriesTitle && song.memberTitle) {
                    const seriesCode = (asciiMaker(song.seriesTitle).toUpperCase().match(/[A-Z0-9]+/g) || [])
                        .map(word => word[0])
                        .join('');
                    const memberTitle = asciiMaker(song.memberTitle)
                        .replace(/[\u2010-\u2015\u2212-]+/gu, ' ')
                        .replace(/\s+/gu, ' ')
                        .trim()
                        .toUpperCase();
                    const available = Math.max(0, maxTextLength - seriesCode.length - (seriesCode && memberTitle ? 1 : 0));
                    return {
                        text: `${seriesCode}${seriesCode && memberTitle ? ' ' : ''}${memberTitle.slice(0, available)}`,
                        accent: seriesCode
                    };
                }
                const title = String(song.title || '');
                const hasUnsupportedLetters = [...title].some(character =>
                    /\p{L}/u.test(character) && !/[\p{Script=Latin}\p{Script=Cyrillic}]/u.test(character));
                return { text: hasUnsupportedLetters && song.sortTitle ? song.sortTitle : title, accent: '' };
            };
            const actionEntry = (title, value, action, field = '', titleAccent = '', titleLimit = maxTitleLength, titleLiteral = false) => ({ title, value, action, field, titleAccent, titleLimit, titleLiteral });
            const dashedTitleEntry = (label, value, action, field) => ({
                ...actionEntry('', value, action, field),
                titleRuns: [
                    { column: 0, text: label, size: 'small' },
                    { column: label.length, text: '-'.repeat(maxTitleLength - label.length), size: 'large' }
                ]
            });
            const pageNumber = (items, page) => Math.max(1, Math.min(Math.ceil(items.length / pageSize) || 1, page || 1));
            const pageItems = (items, page) => items.slice((page - 1) * pageSize, page * pageSize);
            const liveValues = () => window.__npTerminalLiveValues();
            const settingEntry = (title, key, values, scope, songId = '') => ({ title, value: values[key], setting: key, scope, songId });
            const globalSettingEntry = (title, key) => settingEntry(title, key, globalSettings, 'global');
            const emptySongSettings = () => ({ speed: '', sectionWindow: '', standby: '', volume: '', lyrMode: '' });
            const songSettingValues = songId => songSettings[songId] || emptySongSettings();
            const persistedSongSettingValues = songId => persistedSongSettings[songId] || emptySongSettings();
            const liveValueFor = key => {
                const values = liveValues();
                if (key === 'speed') return values.speed.toFixed(2);
                if (key === 'sectionWindow') return String(values.sectionWindow);
                if (key === 'volume') return String(values.volume);
                return values.standby;
            };
            const parseTerminalTime = (value, allowDirect = false) => {
                const source = asciiMaker(value).toUpperCase().trim();
                if (allowDirect && source === 'DCT') return { section: 0, seconds: 0, text: '00/001.01' };
                const match = /^(\d{0,2})\/(\d{1,3})\.(\d{1,2})$/.exec(source);
                if (!match) return null;
                const section = Number(match[1] || 0);
                const minute = Number(match[2]);
                const second = match[3].length === 1 ? Number(match[3]) * 10 : Number(match[3]);
                if (section > 99 || minute < 1 || minute > 999 || second < 1 || second > 60) return null;
                return {
                    section,
                    seconds: (minute - 1) * 60 + second - 1,
                    text: `${String(section).padStart(2, '0')}/${String(minute).padStart(3, '0')}.${String(second).padStart(2, '0')}`
                };
            };
            const normalizeGlobalSetting = (key, value) => {
                const text = limitText(value).trim();
                if (key === 'speed') {
                    const match = /^(\d*)(?:\.(\d*))?$/.exec(text);
                    if (!match || (!match[1] && !match[2])) return '';
                    const speed = Number((match[1] || '0') + '.' + (match[2] || '').padEnd(2, '0').slice(0, 2));
                    return speed >= .05 && speed <= 3.28 ? speed.toFixed(2) : '';
                }
                if (key === 'sectionWindow') {
                    const seconds = Number(text);
                    return /^\d{1,3}$/.test(text) && seconds <= 180 ? String(seconds) : '';
                }
                if (key === 'volume') {
                    return /^\d{1,3}$/.test(text) && Number(text) <= 100 ? String(Number(text)) : '';
                }
                if (key === 'lyrMode') return text === 'AKTIVATE' || text === 'DEAKTIV' ? text : '';
                return parseTerminalTime(text)?.text || '';
            };
            const formatTerminalTime = (section, seconds) => {
                const safeSeconds = Math.max(0, Math.trunc(seconds));
                return `${String(Math.max(0, Math.min(99, Math.trunc(section)))).padStart(2, '0')}/${String(Math.floor(safeSeconds / 60) + 1).padStart(3, '0')}.${String(safeSeconds % 60 + 1).padStart(2, '0')}`;
            };
            const manualSettings = () => {
                const standby = window.__npTerminalManualStandby?.() || { section: 0, seconds: 0 };
                return {
                    speed: Number(window.__npTerminalManualSpeed?.() || liveValues().speed).toFixed(2),
                    sectionWindow: String(window.__npTerminalManualSectionWindow?.() ?? liveValues().sectionWindow),
                    standby: formatTerminalTime(standby.section, standby.seconds),
                    volume: String(window.__npTerminalManualVolume?.() ?? liveValues().volume)
                };
            };
            const applySettings = (values, songId = '', source = 'terminal') => {
                if (values.speed) window.__npTerminalSetSpeed(values.speed, source);
                if (values.sectionWindow) window.__npTerminalSetSectionWindow(values.sectionWindow, source);
                if (values.standby) {
                    const standby = parseTerminalTime(values.standby);
                    if (standby) window.__npTerminalSetStandby(standby.section, standby.seconds, source);
                }
                window.__npTerminalSetVolume(values.volume || window.__npTerminalManualVolume?.() || 0, source);
                if (songId && Object.prototype.hasOwnProperty.call(values, 'lyrMode')) {
                    window.__npTerminalSetLyrics(songId, values.lyrMode === 'AKTIVATE');
                }
            };
            const effectiveSongSettings = songId => {
                const personal = persistedSongSettingValues(songId);
                const manual = manualSettings();
                return {
                    speed: personal.speed || manual.speed,
                    sectionWindow: personal.sectionWindow || manual.sectionWindow,
                    standby: personal.standby || manual.standby,
                    volume: personal.volume || manual.volume,
                    lyrMode: personal.lyrMode || 'DEAKTIV'
                };
            };
            const activeSongId = () => {
                const audio = [...document.querySelectorAll('.card audio')].find(candidate => !candidate.paused);
                return audio?.dataset.songId || visibleCardFor(audio?.closest('.card'))?.id || document.querySelector('.card.playing')?.id || '';
            };
            const applyCurrentSettings = () => {
                const songId = activeSongId();
                applySettings(songId ? effectiveSongSettings(songId) : persistedGlobalSettings, songId);
            };
            window.__npTerminalResetGlobal = () => applySettings(persistedGlobalSettings, '', 'manual');
            const globalDatabase = () => new Promise((resolve, reject) => {
                const request = indexedDB.open('mTerminal', 1);
                request.onupgradeneeded = () => request.result.createObjectStore('global');
                request.onerror = () => reject(request.error);
                request.onsuccess = () => resolve(request.result);
            });
            const loadTerminalSettings = async () => {
                const database = await globalDatabase();
                const transaction = database.transaction('global', 'readonly');
                const store = transaction.objectStore('global');
                const read = key => new Promise((resolve, reject) => {
                    const request = store.get(key);
                    request.onsuccess = () => resolve(request.result || {});
                    request.onerror = () => reject(request.error);
                });
                const [global, songs, legs] = await Promise.all([read('settings'), read('song-settings'), read('legs-plan')]);
                database.close();
                return { global, songs, legs };
            };
            const saveTerminalSettings = async () => {
                const database = await globalDatabase();
                const transaction = database.transaction('global', 'readwrite');
                const store = transaction.objectStore('global');
                store.put({ ...globalSettings }, 'settings');
                store.put(JSON.parse(JSON.stringify(songSettings)), 'song-settings');
                store.put({ legs: JSON.parse(JSON.stringify(legsDraft)), pages: legsDraftPages }, 'legs-plan');
                await new Promise((resolve, reject) => {
                    transaction.oncomplete = resolve;
                    transaction.onerror = () => reject(transaction.error);
                    transaction.onabort = () => reject(transaction.error);
                });
                database.close();
            };
            const globalFolio = () => ({
                name: 'GLOBAL',
                entries: [
                    globalSettingEntry('SPEED', 'speed'),
                    globalSettingEntry('SECTION WINDOW', 'sectionWindow'),
                    globalSettingEntry('STANDBY', 'standby')
                ]
            });
            const indexFolio = () => ({
                name: 'INDEX',
                entries: [
                    actionEntry('', '<GLOBAL', { kind: 'folio', folio: { kind: 'global', page: 1 } }, '1L'),
                    actionEntry('', '<PER SONG', { kind: 'folio', folio: { kind: 'per-song', page: 1 } }, '2L'),
                    actionEntry('', '<LEGS', { kind: 'folio', folio: { kind: 'legs', page: 1 } }, '3L'),
                    actionEntry('', 'PROG>', { kind: 'folio', folio: { kind: 'prog', page: 1 } }, '1R')
                ]
            });
            const perSongFolio = () => ({
                name: 'PER SONG',
                entries: [
                    dashedTitleEntry('TYPE', '< KATEGORIE', { kind: 'folio', folio: { kind: 'categories', page: 1 } }, '1L'),
                    actionEntry('', 'NAME>', { kind: 'folio', folio: { kind: 'songs', source: 'name', page: 1 } }, '1R'),
                    actionEntry('', '<1ST LETTER', { kind: 'folio', folio: { kind: 'letters', page: 1 } }, '2L'),
                    dashedTitleEntry('CODE', selectedCode || '----', { kind: 'code' }, '3L'),
                    ...(selectedCode ? [actionEntry('', 'SETTINGS>', { kind: 'folio', folio: { kind: 'song', songId: songsByUrl.find(song => song.code === selectedCode)?.id || '', code: selectedCode, page: 1 } }, '6R')] : [])
                ]
            });
            const legsSongSearchFolio = () => ({
                name: 'PER SONG',
                entries: [
                    dashedTitleEntry('TYPE', '< KATEGORIE', { kind: 'folio', folio: { kind: 'categories', page: 1, legIndex: folio.legIndex } }, '1L'),
                    actionEntry('', 'NAME>', { kind: 'folio', folio: { kind: 'songs', source: 'name', page: 1, legIndex: folio.legIndex } }, '1R'),
                    actionEntry('', '<1ST LETTER', { kind: 'folio', folio: { kind: 'letters', page: 1, legIndex: folio.legIndex } }, '2L')
                ]
            });
            const categoriesFolio = () => ({
                name: 'PER KATEGORIE',
                entries: pageItems(categories, pageNumber(categories, folio.page)).map(category =>
                    actionEntry('', terminalCategoryName(category), { kind: 'folio', folio: { kind: 'songs', source: 'category', category, page: 1, legIndex: folio.legIndex } }))
            });
            const lettersFolio = () => {
                return {
                    name: 'PER 1ST LETTER',
                    entries: pageItems(songLetters, pageNumber(songLetters, folio.page)).map(letter =>
                        actionEntry('', letter, { kind: 'folio', folio: { kind: 'songs', source: 'letter', letter, page: 1, legIndex: folio.legIndex } }))
                };
            };
            const songsForFolio = () => {
                if (folio.source === 'category') return songCards.filter(song => song.category === folio.category);
                if (folio.source === 'letter') return songCards.filter(song =>
                    (asciiMaker(song.sortTitle).match(/[A-Za-z0-9]/) || [''])[0].toUpperCase() === folio.letter);
                return songsByUrl;
            };
            const songsFolio = () => {
                const songs = songsForFolio();
                const page = pageNumber(songs, folio.page);
                return {
                    name: folio.source === 'category' ? terminalCategoryName(folio.category) : folio.source === 'letter' ? folio.letter : 'PER NAME',
                    entries: pageItems(songs, page).map(song => {
                        const title = terminalSongTitle(song);
                        return actionEntry(title.text, song.code, { kind: 'copy-code', code: song.code, legIndex: folio.legIndex }, '', title.accent);
                    })
                };
            };
            const songFolio = () => {
                const values = songSettingValues(folio.songId);
                const song = songsByUrl.find(candidate => candidate.id === folio.songId);
                const translationAvailable = !!song?.hasTranslation;
                const translationActive = translationAvailable && values.lyrMode === 'AKTIVATE';
                const lyricMode = {
                    title: 'LYR MODE',
                    value: '',
                    field: '4L',
                    lyrMode: true,
                    songId: folio.songId,
                    valueRuns: [
                        { column: 0, text: 'DEAKTIV', color: translationActive ? 'white' : 'green', size: translationActive ? 'small' : 'large' },
                        { column: 7, text: '<>', color: 'white', size: 'small' },
                        { column: 9, text: 'AKTIVATE', color: translationActive ? 'green' : translationAvailable ? 'white' : 'gray', size: translationActive ? 'large' : 'small' }
                    ]
                };
                return {
                    name: `${folio.code} SETTINGS`,
                    entries: [
                        { ...settingEntry('SPEED', 'speed', values, 'song', folio.songId), field: '1L' },
                        { ...settingEntry('VOLUME', 'volume', values, 'song', folio.songId), field: '1R' },
                        { ...settingEntry('SECTION WINDOW', 'sectionWindow', values, 'song', folio.songId), field: '2L' },
                        { ...settingEntry('STANDBY', 'standby', values, 'song', folio.songId), field: '3L' },
                        lyricMode
                    ]
                };
            };
            const legHasData = leg => !!leg && (leg.type === 'hold' ? true : !!(leg.songCode || leg.advance || leg.time));
            const legComplete = (leg, index) => {
                if (!legHasData(leg)) return false;
                if (leg.type === 'hold') return index > 0 && /^\d+$/.test(String(leg.times)) && Number(leg.times) > 0;
                return songsByUrl.some(song => song.code === leg.songCode) && (leg.advance === 'Y' || leg.advance === 'N') && !!parseTerminalTime(leg.time, true);
            };
            const trimmedLegs = () => {
                const copy = JSON.parse(JSON.stringify(legsDraft));
                while (copy.length && !legHasData(copy[copy.length - 1])) copy.pop();
                return copy;
            };
            const validateLegs = () => {
                const plan = trimmedLegs();
                if (!plan.length) return null;
                let playable = false;
                for (let index = 0; index < plan.length; index++) {
                    const leg = plan[index];
                    if (!legHasData(leg) || !legComplete(leg, index)) return null;
                    if (leg.type === 'hold') {
                        if (!playable) return null;
                    } else playable = true;
                }
                return plan;
            };
            const executableLegs = plan => plan.map(leg => {
                if (leg.type === 'hold') return { ...leg };
                const song = songsByUrl.find(candidate => candidate.code === leg.songCode);
                const settings = song ? effectiveSongSettings(song.id) : {};
                const rate = Number(settings.speed) || liveValues().speed;
                const sectionWindow = settings.sectionWindow !== '' ? Number(settings.sectionWindow) : liveValues().sectionWindow;
                return { ...leg, rate, sectionWindow };
            });
            const legEntry = (index, side) => {
                const leg = legsDraft[index] || { type: 'song', songCode: '', advance: '', time: '' };
                const row = index % 4 + 1;
                if (side === 'L') return {
                    title: `SONG ${index + 1}`,
                    value: leg.type === 'hold' ? 'HOLD' : leg.songCode || '----',
                    field: `${row}L`,
                    legSong: true,
                    legIndex: index
                };
                if (leg.type === 'hold') return {
                    title: '',
                    value: '',
                    field: `${row}R`,
                    legRight: true,
                    legIndex: index,
                    titleRuns: [{ column: 19, text: 'TIMES', color: 'white', size: 'large' }],
                    valueRuns: [{ column: Math.max(12, 24 - String(leg.times || '----').length), text: String(leg.times || '----'), color: 'white', size: 'large' }]
                };
                return {
                    title: '',
                    value: '',
                    field: `${row}R`,
                    legRight: true,
                    legIndex: index,
                    titleRuns: [
                        { column: 12, text: 'A', color: 'white', size: 'small' },
                        { column: 17, text: 'TIME', color: 'white', size: 'small' }
                    ],
                    valueRuns: [
                        { column: 12, text: leg.advance || ' ', color: leg.advance === 'Y' ? 'green' : leg.advance === 'N' ? 'red' : 'white', size: 'large' },
                        { column: 15, text: leg.time || '--/---.--', color: 'white', size: 'large' }
                    ]
                };
            };
            const legsFolio = () => {
                const page = Math.max(1, Math.min(legsDraftPages, folio.page || 1));
                const start = (page - 1) * 4;
                const entries = [];
                for (let offset = 0; offset < 4; offset++) {
                    entries.push(legEntry(start + offset, 'L'), legEntry(start + offset, 'R'));
                }
                const pageComplete = Array.from({ length: 4 }, (_, offset) => legComplete(legsDraft[start + offset], start + offset)).every(Boolean);
                if (page === legsDraftPages) {
                    if (pageComplete) entries.push(actionEntry('', 'PAGE>', { kind: 'legs-page', page: page + 1 }, '6R'));
                    else if (!window.__npTerminalLegsActive?.() && validateLegs()) entries.push(actionEntry('', 'AKTIVATE>', { kind: 'activate-legs' }, '6R'));
                }
                return { name: window.__npTerminalLegsActive?.() ? 'AKTV LEGS' : 'LEGS', entries };
            };
            const progressValue = value => value === null || value === undefined ? '□' : String(value);
            const progressTime = seconds => {
                if (!Number.isFinite(seconds)) return '□';
                const whole = Math.max(0, Math.ceil(seconds));
                return `${Math.floor(whole / 60)}.${String(whole % 60).padStart(2, '0')}`;
            };
            const passiveProg = () => {
                const snapshot = window.__npTerminalProgSnapshot?.();
                if (!snapshot) {
                    return {
                        currentLeg: '□□□□',
                        currentRemaining: null,
                        currentSection: '□□',
                        nextLeg: '----',
                        nextEta: '----',
                        totalRemaining: null,
                        percent: null
                    };
                }
                const song = songsByUrl.find(candidate => candidate.id === snapshot.songId);
                return {
                    currentLeg: song?.code || '□□□□',
                    currentRemaining: snapshot.currentRemaining,
                    currentSection: snapshot.currentSection,
                    nextLeg: '----',
                    nextEta: '----',
                    totalRemaining: snapshot.totalRemaining,
                    percent: snapshot.percent
                };
            };
            const progFolio = () => ({
                name: 'PROG',
                entries: (() => {
                    const progress = window.__npTerminalIsrAt?.('E') ? legsProgress : passiveProg();
                    const nextEta = progress.nextEta === '----' ? '----' : progressTime(progress.nextEta);
                    return [
                        actionEntry('CURR LEG', progressValue(progress.currentLeg), null, '1L'),
                        actionEntry('TIME REMAINING', progressTime(progress.currentRemaining), null, '2L'),
                        actionEntry('PC', progress.percent === null || progress.percent === undefined ? '□' : String(progress.percent), null, '1R'),
                        actionEntry('CURRENT SECTION', progressValue(progress.currentSection), null, '3L'),
                        actionEntry('NEXT LEG', progressValue(progress.nextLeg), null, '4L'),
                        actionEntry('ETA', nextEta, null, '5L'),
                        actionEntry('TOTAL TIME', progressTime(progress.totalRemaining), null, '5R')
                    ];
                })()
            });
            const folioRegistry = {
                index: indexFolio,
                global: globalFolio,
                'per-song': perSongFolio,
                'legs-song-search': legsSongSearchFolio,
                categories: categoriesFolio,
                letters: lettersFolio,
                songs: songsFolio,
                song: songFolio,
                legs: legsFolio,
                prog: progFolio
            };
            const currentFolio = () => (folioRegistry[folio.kind] || folioRegistry.index)();
            const folioTotal = () => {
                if (folio.kind === 'categories') return Math.ceil(categories.length / pageSize) || 1;
                if (folio.kind === 'letters') return Math.ceil(songLetters.length / pageSize) || 1;
                if (folio.kind === 'songs') return Math.ceil(songsForFolio().length / pageSize) || 1;
                if (folio.kind === 'legs') return legsDraftPages;
                return 1;
            };
            const renderScratchpad = () => {
                clearTerminalRow(13);
                writeTerminalAligned(13, scratchpadMessage || (deleteArmed ? 'DELETE' : scratchpad), 'left');
                renderTerminalRows([13]);
            };
            const showScratchpadMessage = message => {
                clearTimeout(scratchpadMessageTimer);
                scratchpadMessage = message;
                renderScratchpad();
                scratchpadMessageTimer = window.setTimeout(() => {
                    scratchpadMessage = '';
                    renderScratchpad();
                }, 2000);
            };
            const renderSaveState = () => {
                terminal.querySelector('[data-terminal-special="save"]')?.classList.toggle('is-saving', settingsDirty);
            };
            const refreshSaveState = () => {
                settingsDirty = Object.keys(globalSettings).some(key => globalSettings[key] !== persistedGlobalSettings[key]) ||
                    JSON.stringify(songSettings) !== JSON.stringify(persistedSongSettings) ||
                    JSON.stringify(legsDraft) !== JSON.stringify(persistedLegsPlan) ||
                    legsDraftPages !== persistedLegsPages;
                renderSaveState();
            };
            const renderFolio = () => {
                const page = currentFolio();
                const total = folioTotal();
                const currentPage = Math.max(1, Math.min(total, folio.page || 1));
                const entries = new Map();
                let nextField = 0;
                page.entries.forEach(entry => {
                    const field = entry.field || entryFields[nextField++];
                    entries.set(field, entry);
                });
                entries.set('6L', folio.kind === 'index' ? null : currentPage > 1 ?
                    actionEntry('', '<PAGE', { kind: 'page', folio: { ...folio, page: currentPage - 1 } }) : folio.returnTarget ?
                    actionEntry('', folio.returnLabel || '<RE-TURN', { kind: 'back', folio: folio.returnTarget }) : null);
                if (currentPage < total) {
                    entries.set('6R', actionEntry('', 'PAGE >', {
                        kind: 'page', folio: { ...folio, page: currentPage + 1 }
                    }));
                }
                terminalFields.forEach(field => fieldActions.set(field, entries.get(field) || null));
                clearTerminalScreen();
                writeTerminalAligned(0, limitText(page.name), 'center');
                if (total > 1) writeTerminalAligned(0, `${currentPage}/${total}`, 'right', { size: 'small' });
                const entryTitle = entry => entry?.titleLiteral ?
                    limitTerminalText(entry.title, entry.titleLimit || maxTitleLength) :
                    limitTitleText(entry?.title || '');
                const entryValue = entry => {
                    if (/^□+$/u.test(entry?.value || '')) return entry.value;
                    if (entry?.setting && !entry.value) return entry.setting === 'standby' ? '--/---.--' : '----';
                    return limitText(entry?.value || '');
                };
                const writeRuns = (row, runs) => {
                    if (!runs?.length) return false;
                    runs.forEach(run => writeTerminalText(row, run.column, run.text, {
                        color: run.color || 'white',
                        size: run.size || 'large',
                        inverted: !!run.inverted,
                        flashing: !!run.flashing
                    }));
                    return true;
                };
                const writePair = (row, leftEntry, rightEntry, part, size) => {
                    const runName = part === 'title' ? 'titleRuns' : 'valueRuns';
                    const leftRuns = writeRuns(row, leftEntry?.[runName]);
                    const rightRuns = writeRuns(row, rightEntry?.[runName]);
                    const leftText = part === 'title' ? entryTitle(leftEntry) : entryValue(leftEntry);
                    const rightText = part === 'title' ? entryTitle(rightEntry) : entryValue(rightEntry);
                    const maximum = (leftText || leftRuns) && (rightText || rightRuns) ? terminalColumns / 2 : terminalColumns;
                    const left = leftRuns ? '' : /^□+$/u.test(leftText) ? leftText : limitTerminalText(leftText, maximum);
                    const right = rightRuns ? '' : /^□+$/u.test(rightText) ? rightText : limitTerminalText(rightText, maximum);
                    if (left) writeTerminalText(row, 0, left, { size });
                    if (right) writeTerminalText(row, terminalColumns - right.length, right, { size });
                    if (left && part === 'title' && leftEntry?.titleAccent && left.startsWith(leftEntry.titleAccent)) {
                        writeTerminalText(row, 0, leftEntry.titleAccent, { size, color: 'amber' });
                    }
                    if (right && part === 'title' && rightEntry?.titleAccent && right.startsWith(rightEntry.titleAccent)) {
                        writeTerminalText(row, terminalColumns - right.length, rightEntry.titleAccent, { size, color: 'amber' });
                    }
                };
                for (let selector = 1; selector <= 5; selector++) {
                    const leftEntry = entries.get(`${selector}L`);
                    const rightEntry = entries.get(`${selector}R`);
                    writePair(selector * 2 - 1, leftEntry, rightEntry, 'title', 'small');
                    writePair(selector * 2, leftEntry, rightEntry, 'value', 'large');
                }
                writeTerminalText(11, 0, '-'.repeat(terminalColumns), { size: 'large' });
                writePair(12, entries.get('6L'), entries.get('6R'), 'value', 'large');
                renderTerminalScreen();
                renderScratchpad();
            };
            const dispatchAction = action => {
                if (action.kind === 'index') folio = { kind: 'index', page: 1 };
                if (action.kind === 'folio') {
                    if (action.folio.kind === 'song') selectedCode = '';
                    folio = { ...action.folio, returnTarget: { ...folio }, returnLabel: '< RE-TURN' };
                }
                if (action.kind === 'page') folio = { ...action.folio };
                if (action.kind === 'back') folio = action.folio;
                if (action.kind === 'legs-page') {
                    legsDraftPages = Math.max(legsDraftPages, action.page);
                    folio = { ...folio, page: action.page };
                    refreshSaveState();
                }
                if (action.kind === 'activate-legs') {
                    const plan = validateLegs();
                    if (!plan) {
                        showScratchpadMessage('IN VALID');
                        return;
                    }
                    window.__npTerminalActivateLegs(executableLegs(plan)).then(active => {
                        if (!active) showScratchpadMessage('IN VALID');
                        renderFolio();
                    });
                }
                renderFolio();
            };
            const dispatchSpecial = action => {
                if (action === 'first' && (folio.page || 1) !== 1) {
                    folio = { ...folio, page: 1 };
                    renderFolio();
                }
                if (action === 'last') {
                    const lastPage = folioTotal();
                    if ((folio.page || 1) !== lastPage) {
                        folio = { ...folio, page: lastPage };
                        renderFolio();
                    }
                }
                if (action === 'index') dispatchAction({ kind: 'index' });
                if (action === 'song') dispatchAction({ kind: 'folio', folio: { kind: 'per-song', page: 1 } });
                if (action === 'legs') dispatchAction({ kind: 'folio', folio: { kind: 'legs', page: 1 } });
                if (action === 'direct') {
                    scratchpad = limitText(scratchpad + 'DCT');
                    deleteArmed = false;
                    renderScratchpad();
                }
                if (action === 'clear') {
                    clearTimeout(scratchpadMessageTimer);
                    scratchpadMessage = '';
                    scratchpad = '';
                    deleteArmed = false;
                    renderScratchpad();
                }
                if (action === 'save') {
                    if (legsDraft.some(legHasData) && !validateLegs()) {
                        showScratchpadMessage('IN VALID');
                        return;
                    }
                    saveTerminalSettings().then(() => {
                        Object.assign(persistedGlobalSettings, globalSettings);
                        Object.keys(persistedSongSettings).forEach(songId => delete persistedSongSettings[songId]);
                        Object.assign(persistedSongSettings, JSON.parse(JSON.stringify(songSettings)));
                        persistedLegsPlan = JSON.parse(JSON.stringify(legsDraft));
                        persistedLegsPages = legsDraftPages;
                        if (folio.kind === 'song' && folio.songId === activeSongId()) {
                            applySettings(effectiveSongSettings(folio.songId), folio.songId);
                        }
                        refreshSaveState();
                    }).catch(() => {});
                }
            };
            const dispatchKey = value => {
                if (scratchpadMessage) return;
                if (value === 'DL') deleteArmed = true;
                else if (value === 'BS') scratchpad = scratchpad.slice(0, -1);
                else scratchpad = limitText(scratchpad + (value === 'SP' ? ' ' : value));
                renderScratchpad();
            };
            const setEntrySetting = (entry, value) => {
                if (entry.scope === 'song') {
                    const values = { ...songSettingValues(entry.songId), [entry.setting]: value };
                    if (Object.values(values).some(Boolean)) songSettings[entry.songId] = values;
                    else delete songSettings[entry.songId];
                    return;
                }
                globalSettings[entry.setting] = value;
            };
            const updateLeg = (index, value) => {
                while (legsDraft.length <= index) legsDraft.push({ type: 'song', songCode: '', advance: '', time: '' });
                legsDraft[index] = value;
                while (legsDraft.length && !legHasData(legsDraft[legsDraft.length - 1])) legsDraft.pop();
                const validPlan = validateLegs();
                if (validPlan && window.__npTerminalLegsActive?.()) window.__npTerminalUpdateActiveLegs(executableLegs(validPlan));
                refreshSaveState();
                renderFolio();
            };
            const writeLegSong = entry => {
                const text = scratchpad.toUpperCase().trim();
                if (text === 'HOLD') {
                    if (entry.legIndex < 1 || !legHasData(legsDraft[entry.legIndex - 1])) return false;
                    updateLeg(entry.legIndex, { type: 'hold', times: '' });
                    return true;
                }
                const song = songsByUrl.find(candidate => candidate.code === text);
                if (!song) return false;
                const existing = legsDraft[entry.legIndex];
                updateLeg(entry.legIndex, {
                    type: 'song',
                    songCode: song.code,
                    advance: existing?.type === 'song' ? existing.advance || '' : '',
                    time: existing?.type === 'song' ? existing.time || '' : ''
                });
                return true;
            };
            const writeLegRight = entry => {
                const leg = legsDraft[entry.legIndex] || { type: 'song', songCode: '', advance: '', time: '' };
                const text = scratchpad.toUpperCase().trim();
                if (leg.type === 'hold') {
                    if (!/^\d+$/.test(text) || Number(text) < 1) return false;
                    updateLeg(entry.legIndex, { type: 'hold', times: String(Number(text)) });
                    return true;
                }
                let advance = leg.advance || '';
                let time = leg.time || '';
                const combined = /^([YN])\s+(.+)$/.exec(text);
                if (combined) {
                    const parsed = parseTerminalTime(combined[2], true);
                    if (!parsed) return false;
                    advance = combined[1];
                    time = parsed.text;
                } else if (text === 'Y' || text === 'N') advance = text;
                else {
                    const parsed = parseTerminalTime(text, true);
                    if (!parsed) return false;
                    time = parsed.text;
                }
                updateLeg(entry.legIndex, { type: 'song', songCode: leg.songCode || '', advance, time });
                return true;
            };
            const dispatchSelector = field => {
                if (scratchpadMessage) return;
                const entry = fieldActions.get(field);
                if (!entry) return;
                if (entry.action?.kind === 'code') {
                    if (deleteArmed) {
                        selectedCode = '';
                        deleteArmed = false;
                        renderScratchpad();
                        renderFolio();
                        return;
                    }
                    if (!scratchpad) {
                        const song = selectedCode ? songsByUrl.find(candidate => candidate.code === selectedCode) :
                            songsByUrl.find(candidate => candidate.id === activeSongId());
                        if (!song) return;
                        scratchpad = song.code;
                        renderScratchpad();
                        return;
                    }
                    const code = scratchpad.toUpperCase();
                    const song = songsByUrl.find(candidate => candidate.code === code);
                    if (!song) {
                        showScratchpadMessage('IN VALID');
                        return;
                    }
                    selectedCode = song.code;
                    scratchpad = '';
                    renderScratchpad();
                    renderFolio();
                    return;
                }
                if (entry.action?.kind === 'copy-code') {
                    if (scratchpad || deleteArmed) {
                        showScratchpadMessage('IN VALID');
                        return;
                    }
                    scratchpad = entry.action.code;
                    if (Number.isInteger(entry.action.legIndex)) {
                        folio = { kind: 'legs', page: Math.floor(entry.action.legIndex / 4) + 1 };
                        renderFolio();
                    }
                    renderScratchpad();
                    return;
                }
                if (entry.action) {
                    dispatchAction(entry.action);
                    return;
                }
                if (entry.lyrMode) {
                    if (deleteArmed) {
                        setEntrySetting({ setting: 'lyrMode', scope: 'song', songId: entry.songId }, '');
                        deleteArmed = false;
                        refreshSaveState();
                        renderScratchpad();
                        renderFolio();
                        return;
                    }
                    if (scratchpad) {
                        showScratchpadMessage('IN VALID');
                        return;
                    }
                    const available = window.__npTerminalHasTranslation(entry.songId);
                    const values = songSettingValues(entry.songId);
                    const active = values.lyrMode === 'AKTIVATE';
                    if (!available && !active) {
                        showScratchpadMessage('IN VALID');
                        return;
                    }
                    setEntrySetting({ setting: 'lyrMode', scope: 'song', songId: entry.songId }, active ? 'DEAKTIV' : 'AKTIVATE');
                    deleteArmed = false;
                    refreshSaveState();
                    renderScratchpad();
                    renderFolio();
                    return;
                }
                if (entry.legSong || entry.legRight) {
                    if (deleteArmed) {
                        updateLeg(entry.legIndex, entry.legSong ? { type: 'song', songCode: '', advance: '', time: '' } :
                            legsDraft[entry.legIndex]?.type === 'hold' ? { type: 'hold', times: '' } :
                            { ...(legsDraft[entry.legIndex] || { type: 'song', songCode: '' }), advance: '', time: '' });
                        deleteArmed = false;
                        renderScratchpad();
                        return;
                    }
                    if (!scratchpad) {
                        const leg = legsDraft[entry.legIndex];
                        if (!legHasData(leg)) {
                            if (entry.legSong) {
                                dispatchAction({ kind: 'folio', folio: { kind: 'legs-song-search', page: 1, legIndex: entry.legIndex } });
                            }
                            return;
                        }
                        scratchpad = entry.legSong ? leg.type === 'hold' ? 'HOLD' : leg.songCode :
                            leg.type === 'hold' ? String(leg.times || '') : `${leg.advance || ''}${leg.advance && leg.time ? ' ' : ''}${leg.time || ''}`;
                        renderScratchpad();
                        return;
                    }
                    const written = entry.legSong ? writeLegSong(entry) : writeLegRight(entry);
                    if (!written) {
                        showScratchpadMessage('IN VALID');
                        return;
                    }
                    scratchpad = '';
                    renderScratchpad();
                    return;
                }
                if (deleteArmed) {
                    if (entry.setting) {
                        setEntrySetting(entry, '');
                        refreshSaveState();
                        renderFolio();
                    }
                    deleteArmed = false;
                    renderScratchpad();
                    return;
                }
                if (entry.setting) {
                    if (scratchpad) {
                        const value = normalizeGlobalSetting(entry.setting, scratchpad);
                        if (!value) {
                            showScratchpadMessage('IN VALID');
                            return;
                        }
                        setEntrySetting(entry, value);
                        refreshSaveState();
                        scratchpad = '';
                        renderScratchpad();
                        renderFolio();
                    } else {
                        scratchpad = limitText(entry.value || liveValueFor(entry.setting));
                        renderScratchpad();
                    }
                }
            };
            terminal.addEventListener('click', event => {
                const special = event.target.closest('[data-terminal-special]');
                if (special && terminal.contains(special)) {
                    document.dispatchEvent(new CustomEvent('m2terminalsound', { detail: 'terminalKey' }));
                    dispatchSpecial(special.dataset.terminalSpecial);
                    return;
                }
                const key = event.target.closest('.mTerminalKey');
                if (key && terminal.contains(key)) {
                    document.dispatchEvent(new CustomEvent('m2terminalsound', { detail: 'terminalKey' }));
                    dispatchKey(key.textContent.trim());
                    return;
                }
                const selector = event.target.closest('.mLSK[data-terminal-field]');
                if (!selector || !terminal.contains(selector)) return;
                document.dispatchEvent(new CustomEvent('m2terminalsound', { detail: 'terminalLsk' }));
                dispatchSelector(selector.dataset.terminalField);
            });
            document.addEventListener('m2songchange', event => {
                const songId = event.detail?.dataset.songId || visibleCardFor(event.detail?.closest('.card'))?.id;
                if (songId) applySettings(effectiveSongSettings(songId), songId);
            });
            document.addEventListener('m2legsprogress', event => {
                legsProgress = event.detail || { active: false };
                if (folio.kind === 'prog') renderFolio();
            });
            document.addEventListener('m2legsstate', event => {
                legsProgress = event.detail || { active: false };
                if (folio.kind === 'prog' || folio.kind === 'legs') renderFolio();
            });
            window.setInterval(() => {
                if (folio.kind === 'prog' && !window.__npTerminalIsrAt?.('E')) renderFolio();
            }, 1000);
            renderFolio();
            loadTerminalSettings().then(stored => {
                Object.keys(globalSettings).forEach(key => {
                    globalSettings[key] = normalizeGlobalSetting(key, stored.global?.[key] || '');
                });
                Object.entries(stored.songs || {}).forEach(([songId, values]) => {
                    const normalized = {
                        speed: normalizeGlobalSetting('speed', values?.speed || ''),
                        sectionWindow: normalizeGlobalSetting('sectionWindow', values?.sectionWindow || ''),
                        standby: normalizeGlobalSetting('standby', values?.standby || ''),
                        volume: normalizeGlobalSetting('volume', values?.volume || ''),
                        lyrMode: normalizeGlobalSetting('lyrMode', values?.lyrMode || '')
                    };
                    if (Object.values(normalized).some(Boolean)) songSettings[songId] = normalized;
                });
                legsDraft = Array.isArray(stored.legs?.legs) ? stored.legs.legs : [];
                legsDraftPages = Math.max(1, Number(stored.legs?.pages) || Math.ceil(legsDraft.length / 4) || 1);
                Object.assign(persistedGlobalSettings, globalSettings);
                Object.assign(persistedSongSettings, JSON.parse(JSON.stringify(songSettings)));
                persistedLegsPlan = JSON.parse(JSON.stringify(legsDraft));
                persistedLegsPages = legsDraftPages;
                applySettings(persistedGlobalSettings, '', 'manual');
                applyCurrentSettings();
                refreshSaveState();
                renderFolio();
            }).catch(() => {});
        })();
    </script>
</body>

</html>
