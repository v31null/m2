<?php
declare(strict_types=1);
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/api/db.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); exit('missing id'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth = $_POST['auth'] ?? '';
    if (!isset($_SESSION['del_token']) || !hash_equals($_SESSION['del_token'], $auth)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'err' => 'auth']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT url FROM song_links WHERE link_id=? LIMIT 1');
    $stmt->execute([$id]);
    $orig = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$orig) { http_response_code(404); exit; }

    $url    = $orig['url'];
    $title  = trim($_POST['title']    ?? '');
    $cat    = trim($_POST['category'] ?? '');
    $lyrics = $_POST['lyrics']        ?? '';
    $isyes  = isset($_POST['isyes']) ? 1 : 0;

    if (isset($_FILES['img_file']) && $_FILES['img_file']['error'] === UPLOAD_ERR_OK) {
        $tmp   = $_FILES['img_file']['tmp_name'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($tmp);
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            'video/webm' => 'webm',
            'video/mp4'  => 'mp4',
        ];
        if (isset($allowed[$mime])) {
            $ext    = $allowed[$mime];
            $imgDir = $_SERVER['DOCUMENT_ROOT'] . '/m/m/img/';
            @mkdir($imgDir, 0775, true);
            array_map('unlink', glob($imgDir . rawurlencode($url) . '.*') ?: []);
            move_uploaded_file($tmp, $imgDir . rawurlencode($url) . '.' . $ext);
        }
    }

    $up = $pdo->prepare('UPDATE song_links SET title=?, category=?, lyrics=?, is_yes=? WHERE link_id=?');
    $up->execute([$title, $cat, $lyrics, $isyes, $id]);
    echo json_encode(['ok' => true]);
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM song_links WHERE link_id=? LIMIT 1');
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) { http_response_code(404); exit('not found'); }

$cats = $pdo->query('SELECT DISTINCT category FROM song_links ORDER BY category')->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>edit</title>
    <style>
        .ctl {
            width: 40px;
            height: 40px;
            padding: 0;
            margin: 0;
            text-align: center
        }

        *:not(.katex):not(.katex *) {
            font-family: 'Junicode', 'nullpunktsenergie';
            letter-spacing: 0;
            text-rendering: auto;
            image-rendering: optimizeQuality !important;
            font-variant-ligatures: discretionary-ligatures contextual common-ligatures;
            font-feature-settings: "ss17", "cv01", "cv02";
            font-variant-numeric: lining-nums;
        }

        @font-face {
            font-family: nullpunktsenergie;
            src: url(/css/fonts/nullpunktsenergiefont-Regular.ttf);
        }

        /* ── LRC tuner ── */
        #lrcTuner {
            display: none;
            margin-bottom: 2em;
            border: 1px solid #444;
            padding: 1em
        }

        #lrcTuner .panels {
            display: flex;
            gap: 1em;
            height: 340px;
            margin-top: 1em
        }

        #lrcTuner .panel {
            flex: 1;
            display: flex;
            flex-direction: column;
            border: 1px solid #444;
            overflow: hidden
        }

        #lrcTuner .panel-header {
            padding: 6px 10px;
            border-bottom: 1px solid #444;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: .85em
        }

        #lrcTuner textarea {
            flex: 1;
            background: transparent;
            color: #aaa;
            border: none;
            padding: 10px;
            font-family: monospace;
            resize: none;
            outline: none;
            box-sizing: border-box;
            font-size: .85em
        }

        #lrcDisplay {
            flex: 1;
            overflow-y: auto;
            padding: 10px;
            color: #666;
            font-family: monospace;
            font-size: .85em
        }

        .lyric-line { padding: 2px 0 }

        .lyric-line.active {
            color: black;
            font-weight: bold
        }

        #lrcTuner input[type=range] {
            width: 100%;
            margin: 6px 0;
            cursor: pointer
        }

        #lrcTuner audio { width: 100%; margin-top: 8px }

        #lrcTuner label { font-size: .85em }
    </style>
</head>

<body>
    <button id="spawn" class="ctl" style="position:fixed;right:2vw;top:50%;transform:translateY(-50%)">J</button>
    <div id="codeRow"></div>

    <div id="lrcTuner">
        <audio id="lrcPlayer" src="/m/m/<?= rawurlencode($row['url']) ?>.mp3" controls></audio>
        <div>
            <label>speed: <span id="speedDisplay">100%</span></label>
            <input type="range" id="speedSlider" min="50" max="200" value="100" step="1">
        </div>
        <div class="panels">
            <div class="panel">
                <textarea id="lrcInput" ></textarea>
            </div>
            <div class="panel">
                <div class="panel-header">
                    <button id="lrcCopyBtn" class="ctl" style="width:auto;padding:0 8px">copy</button>
                </div>
                <div id="lrcDisplay"></div>
            </div>
        </div>
    </div>

    <form id="f" style="display:none">
        <input type="hidden" name="auth" id="authVal">

        <div style="margin-top:1em">
            <label>title <input name="title" value="<?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?>"></label><br>
            <label>category <input name="category" list="catDl" value="<?= htmlspecialchars($row['category'], ENT_QUOTES, 'UTF-8') ?>"></label>
            <datalist id="catDl">
                <?php foreach ($cats as $c) echo '<option value="'.htmlspecialchars($c, ENT_QUOTES, 'UTF-8').'">'; ?>
            </datalist><br>
            <label>file <input type="file" name="img_file" accept="image/*,video/webm,video/mp4"></label> <input type="checkbox" name="isyes" id="isyes" <?php if (($row['is_yes'] ?? 0) == 1) echo 'checked'; ?> ><label for="isyes">is yes</label><br>
            <label>lyrics<br><textarea name="lyrics" style="width:100%;height:200px"><?= htmlspecialchars($row['lyrics'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea></label>
            <input type="hidden" name="dummy">
        </div>

        <button type="button" id="save" class="ctl">S</button>
    </form>

    <hr>
    <pre style="word-wrap: break-word;white-space: pre-wrap;"><?= htmlspecialchars(print_r($row, true), ENT_QUOTES, 'UTF-8') ?></pre>

    <script>
        const SALT = 'asfjaƕꜹacvkasjsajfashfasufghjgs';
        let TOKEN = '';
        const sha = m => crypto.subtle.digest('SHA-256', new TextEncoder().encode(m))
            .then(b => [...new Uint8Array(b)].map(x => x.toString(16).padStart(2, '0')).join(''));

        spawn.onclick = () => {
            if (TOKEN) {
                document.getElementById('f').style.display = 'block';
                document.getElementById('lrcTuner').style.display = 'block';
                return;
            }
            if (!document.getElementById('codeInput')) {
                codeRow.innerHTML = '<input id="codeInput" type="password"><button id="chk" class="ctl">✔</button>';
                chk.onclick = async () => {
                    const h = await sha(SALT + codeInput.value);
                    fetch('ꜷth.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'hash=' + h
                        })
                        .then(r => r.json()).then(j => {
                            if (j.ok) {
                                TOKEN = h;
                                authVal.value = h;
                                f.style.display = 'block';
                                document.getElementById('lrcTuner').style.display = 'block';
                                codeRow.innerHTML = '';
                            }
                        });
                };
            }
        };

        save.onclick = () => {
            fetch(location.pathname + '?id=<?= $id ?>', {
                    method: 'POST',
                    body: new FormData(f),
                    credentials: 'same-origin'
                })
                .then(r => r.json())
                .then(j => alert(j.ok ? 'saved' : 'err'));
        };

        /* ── LRC tuner (from a.html, stripped) ── */
        const lrcPlayer   = document.getElementById('lrcPlayer');
        const speedSlider = document.getElementById('speedSlider');
        const speedDisplay = document.getElementById('speedDisplay');
        const lrcInput    = document.getElementById('lrcInput');
        const lrcDisplay  = document.getElementById('lrcDisplay');
        const lrcCopyBtn  = document.getElementById('lrcCopyBtn');

        let generatedLrcString = '';
        let lrcActiveDiv = null;

        function formatTime(seconds) {
            if (isNaN(seconds)) return '00:00.00';
            const m  = Math.floor(seconds / 60).toString().padStart(2, '0');
            const s  = (seconds % 60).toFixed(2).padStart(5, '0');
            return `${m}:${s}`;
        }


        lrcPlayer.addEventListener('timeupdate', () => {
            const t = lrcPlayer.currentTime;
            let newActive = null;
            for (const el of document.querySelectorAll('.lyric-line[data-time]')) {
                if (t >= parseFloat(el.dataset.time)) newActive = el;
                else break;
            }
            if (newActive !== lrcActiveDiv) {
                if (lrcActiveDiv) lrcActiveDiv.classList.remove('active');
                if (newActive) {
                    newActive.classList.add('active');
                    newActive.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                lrcActiveDiv = newActive;
            }
        });

        function formatSrtTime(seconds) {
            const h = Math.floor(seconds / 3600).toString().padStart(2, '0');
            const m = Math.floor((seconds % 3600) / 60).toString().padStart(2, '0');
            const s = Math.floor(seconds % 60).toString().padStart(2, '0');
            const ms = Math.floor((seconds % 1) * 1000).toString().padStart(3, '0');
            return `${h}:${m}:${s},${ms}`;
        }

        function srtToSec(ts) {
            const p = ts.trim().split(/[:.,]/);
            if (p.length < 4) return 0;
            return parseInt(p[0]) * 3600 + parseInt(p[1]) * 60 + parseInt(p[2]) + parseFloat('0.' + p[3]);
        }

        function autoNormalize() {
            let val = lrcInput.value.trim();
            if (/\d{2}:\d{2}:\d{2}[,.]\d+\s*-->\s*\d{2}:\d{2}:\d{2}[,.]\d+/.test(val)) {
                const regex = /(?:(\d+)\s*\r?\n)?(\d{2}:\d{2}:\d{2}[,.]\d+\s*-->\s*\d{2}:\d{2}:\d{2}[,.]\d+)\r?\n([\s\S]*?)(?=\r?\n\s*\r?\n|\r?\n\d+\s*\r?\n\d{2}:\d{2}:\d{2}|$)/g;
                const blocks = [];
                let m, i = 1;
                while ((m = regex.exec(val)) !== null) {
                    blocks.push(`${i++}\n${m[2].trim()}\n${m[3].trim()}`);
                }
                if (blocks.length) lrcInput.value = blocks.join('\n\n');
            }
            processLRC();
        }

        lrcInput.addEventListener('blur', autoNormalize);
        lrcInput.addEventListener('paste', () => setTimeout(autoNormalize, 10));

        function processLRC() {
            const pct = parseFloat(speedSlider.value);
            speedDisplay.textContent = pct + '%';
            const scale = pct / 100;
            const raw = lrcInput.value.trim();
            lrcDisplay.innerHTML = '';
            lrcActiveDiv = null;

            const isSRT = /\d{2}:\d{2}:\d{2}[,.]\d+\s*-->\s*\d{2}:\d{2}:\d{2}[,.]\d+/.test(raw);

            if (isSRT) {
                const regex = /(?:(\d+)\s*\r?\n)?(\d{2}:\d{2}:\d{2}[,.]\d+\s*-->\s*\d{2}:\d{2}:\d{2}[,.]\d+)\r?\n([\s\S]*?)(?=\r?\n\s*\r?\n|\r?\n\d+\s*\r?\n\d{2}:\d{2}:\d{2}|$)/g;
                const outBlocks = [];
                let m, i = 1;
                while ((m = regex.exec(raw)) !== null) {
                    const tsMatch = m[2].match(/(\d{2}:\d{2}:\d{2}[,.]\d+)\s*-->\s*(\d{2}:\d{2}:\d{2}[,.]\d+)/);
                    if (!tsMatch) continue;

                    const start = srtToSec(tsMatch[1]) / scale;
                    const end = srtToSec(tsMatch[2]) / scale;
                    const newTsLine = `${formatSrtTime(start)} --> ${formatSrtTime(end)}`;
                    const text = m[3].trim();
                    const fullBlock = `${i++}\n${newTsLine}\n${text}`;

                    outBlocks.push(fullBlock);

                    const div = document.createElement('div');
                    div.className = 'lyric-line';
                    div.style.whiteSpace = 'pre-wrap';
                    div.style.marginBottom = '1.2em';
                    div.textContent = fullBlock;
                    div.dataset.time = start;
                    lrcDisplay.appendChild(div);
                }
                generatedLrcString = outBlocks.join('\n\n');
            } else {
                const lines = raw.split('\n');
                const out = [];
                lines.forEach(line => {
                    let syncTime = -1;
                    const newLine = line.replace(/\[(\d{2}):(\d{2}\.\d{2,3})\]/g, (_, min, sec) => {
                        const orig = parseInt(min) * 60 + parseFloat(sec);
                        const adjusted = orig / scale;
                        if (syncTime === -1) syncTime = adjusted;
                        return `[${formatTime(adjusted)}]`;
                    });
                    out.push(newLine);
                    const div = document.createElement('div');
                    div.className = 'lyric-line';
                    div.textContent = newLine;
                    if (syncTime !== -1) div.dataset.time = syncTime;
                    lrcDisplay.appendChild(div);
                });
                generatedLrcString = out.join('\n');
            }
            lrcPlayer.dispatchEvent(new Event('timeupdate'));
        }

        speedSlider.addEventListener('input', processLRC);
        lrcInput.addEventListener('input', processLRC);

        lrcCopyBtn.addEventListener('click', () => {
            navigator.clipboard.writeText(generatedLrcString).then(() => {
                const t = lrcCopyBtn.textContent;
                lrcCopyBtn.textContent = '✔';
                setTimeout(() => lrcCopyBtn.textContent = t, 1200);
            });
        });

        processLRC();
    </script>
</body>

</html>
