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
    $trans  = $_POST['trans']         ?? '';
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

    $up = $pdo->prepare('UPDATE song_links SET title=?, category=?, lyrics=?, trans=?, is_yes=? WHERE link_id=?');
    $up->execute([$title, $cat, $lyrics, $trans, $isyes, $id]);
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
        <button type="button" id="recordModeBtn" class="ctl" aria-pressed="false" style="width:auto;padding:0 10px;margin-top:8px">AKTIVATE REKORD MODE</button>
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
            <label>trans<br><textarea name="trans" style="width:100%;height:200px"><?= htmlspecialchars($row['trans'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea></label>
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

        const lrcPlayer = document.getElementById('lrcPlayer');
        const speedSlider = document.getElementById('speedSlider');
        const speedDisplay = document.getElementById('speedDisplay');
        const lrcInput = document.getElementById('lrcInput');
        const lrcDisplay = document.getElementById('lrcDisplay');
        const lrcCopyBtn = document.getElementById('lrcCopyBtn');
        const recordModeBtn = document.getElementById('recordModeBtn');

        let generatedLrcString = '';
        let lrcActiveDiv = null;
        let recordMode = false;
        let recordSpaceDown = false;
        let recordSequenceActive = false;
        let recordIndex = 0;
        let recordSource = '';
        let recordLines = [];
        let recordScrollLock = null;

        function formatTime(seconds) {
            if (isNaN(seconds)) return '00:00.00';
            const units = Math.max(0, Math.round(seconds * 100));
            const m = Math.floor(units / 6000).toString().padStart(2, '0');
            const s = ((units % 6000) / 100).toFixed(2).padStart(5, '0');
            return `${m}:${s}`;
        }

        function keepInsidePanel(element) {
            if (!element) return;
            const panelRect = lrcDisplay.getBoundingClientRect();
            const elementRect = element.getBoundingClientRect();
            if (elementRect.top < panelRect.top) {
                lrcDisplay.scrollTop -= panelRect.top - elementRect.top;
            } else if (elementRect.bottom > panelRect.bottom) {
                lrcDisplay.scrollTop += elementRect.bottom - panelRect.bottom;
            }
        }

        lrcPlayer.addEventListener('timeupdate', () => {
            const t = lrcPlayer.currentTime;
            if (recordMode) {
                if (recordSequenceActive) return;
                let activeIndex = -1;
                recordLines.forEach((line, index) => {
                    if (Number.isFinite(line.start) && t >= line.start) activeIndex = index;
                });
                if (activeIndex >= 0 && activeIndex !== recordIndex) {
                    recordIndex = activeIndex;
                    renderRecordLines();
                }
                return;
            }
            let newActive = null;
            for (const el of document.querySelectorAll('.lyric-line[data-time]')) {
                if (t >= parseFloat(el.dataset.time)) newActive = el;
                else break;
            }
            if (newActive !== lrcActiveDiv) {
                if (lrcActiveDiv) lrcActiveDiv.classList.remove('active');
                if (newActive) {
                    newActive.classList.add('active');
                    keepInsidePanel(newActive);
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
            if (recordMode) return;
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

        function containsTimedLyrics(raw) {
            return /-->/.test(raw) || /\[(?:\d{1,3}:\d{2}(?:[.:]\d+)?|(?:ar|ti|al|by|offset|length|re):)/im.test(raw);
        }

        function renderRecordLines() {
            lrcDisplay.innerHTML = '';
            recordLines.forEach((line, index) => {
                const row = document.createElement('div');
                row.className = 'lyric-line';
                if (index === recordIndex) row.classList.add('active');
                row.textContent = line.text;
                if (Number.isFinite(line.start)) row.dataset.time = line.start;
                row.addEventListener('click', () => selectRecordLine(index, true));
                lrcDisplay.appendChild(row);
            });
            const selected = lrcDisplay.children[recordIndex];
            keepInsidePanel(selected);
        }

        function selectRecordLine(index, seek) {
            if (!recordMode || index < 0 || index >= recordLines.length || recordSpaceDown) return;
            recordSequenceActive = false;
            recordIndex = index;
            const start = recordLines[index].start;
            if (seek && Number.isFinite(start)) {
                try {
                    lrcPlayer.currentTime = start;
                } catch (e) {
                    lrcPlayer.addEventListener('loadedmetadata', () => {
                        lrcPlayer.currentTime = start;
                    }, { once: true });
                }
            }
            renderRecordLines();
        }

        function activateRecordMode() {
            const raw = lrcInput.value.trim();
            if (!raw) {
                alert('LEFT SIDE IS EMPTIE');
                lrcInput.focus();
                return;
            }
            if (containsTimedLyrics(raw)) {
                alert('LEFT SIDE MUST NOT HAVE LRC OR SRT');
                lrcInput.focus();
                return;
            }
            const lines = raw.replace(/\r\n?/g, '\n').split('\n').map(line => line.trim()).filter(Boolean);
            if (!lines.length) {
                alert('LEFT SIDE IS EMPTIE');
                lrcInput.focus();
                return;
            }
            if (recordSource !== raw) {
                recordSource = raw;
                recordLines = lines.map(text => ({ text, start: null, end: null }));
                recordIndex = 0;
            }
            recordMode = true;
            recordSpaceDown = false;
            recordSequenceActive = false;
            recordModeBtn.setAttribute('aria-pressed', 'true');
            recordModeBtn.textContent = 'DEAKTIVATE REKORD MODE';
            renderRecordLines();
            recordModeBtn.blur();
        }

        function deactivateRecordMode() {
            unlockRecordPage();
            recordMode = false;
            recordSpaceDown = false;
            recordSequenceActive = false;
            recordModeBtn.setAttribute('aria-pressed', 'false');
            recordModeBtn.textContent = 'AKTIVATE REKORD MODE';
            processLRC();
        }

        function isWritableTarget(target) {
            if (!(target instanceof Element)) return false;
            if (target.matches('textarea, [contenteditable]:not([contenteditable="false"])')) return true;
            if (!target.matches('input')) return false;
            return ['text', 'search', 'email', 'url', 'tel', 'password', 'number'].includes(target.type);
        }

        function isTextEditing() {
            return isWritableTarget(document.activeElement);
        }

        function isSpaceKey(event) {
            return event.code === 'Space' || event.key === ' ' || event.key === 'Spacebar' || event.keyCode === 32;
        }

        function syncRecordLinesFromInput() {
            const raw = lrcInput.value.trim();
            if (!raw) {
                return false;
            }
            if (containsTimedLyrics(raw)) {
                return false;
            }
            const texts = raw.replace(/\r\n?/g, '\n').split('\n').map(line => line.trim()).filter(Boolean);
            if (!texts.length) {
                return false;
            }
            const selected = recordLines[recordIndex];
            const available = new Map();
            recordLines.forEach(line => {
                if (!available.has(line.text)) available.set(line.text, []);
                available.get(line.text).push(line);
            });
            recordLines = texts.map(text => {
                const matches = available.get(text);
                if (matches && matches.length) return matches.shift();
                return { text, start: null, end: null };
            });
            const selectedIndex = selected ? recordLines.indexOf(selected) : -1;
            recordIndex = selectedIndex >= 0 ? selectedIndex : Math.min(recordIndex, recordLines.length - 1);
            recordSource = raw;
            renderRecordLines();
            return true;
        }

        function recordLrcText() {
            const scale = parseFloat(speedSlider.value) / 100;
            return recordLines
                .filter(line => Number.isFinite(line.start))
                .flatMap(line => {
                    const output = [`[${formatTime(line.start / scale)}]${line.text}`];
                    if (Number.isFinite(line.end)) output.push(`[${formatTime(line.end / scale)}]`);
                    return output;
                })
                .join('\n');
        }

        function releaseMediaFocus() {
            if (!recordMode || isTextEditing()) return;
            lrcPlayer.blur();
        }

        function lockRecordPage() {
            if (recordScrollLock) return;
            recordScrollLock = {
                x: window.scrollX,
                y: window.scrollY,
                htmlOverflow: document.documentElement.style.overflow,
                bodyOverflow: document.body.style.overflow
            };
            document.documentElement.style.overflow = 'hidden';
            document.body.style.overflow = 'hidden';
            window.scrollTo(recordScrollLock.x, recordScrollLock.y);
        }

        function unlockRecordPage() {
            if (!recordScrollLock) return;
            const lock = recordScrollLock;
            recordScrollLock = null;
            document.documentElement.style.overflow = lock.htmlOverflow;
            document.body.style.overflow = lock.bodyOverflow;
            window.scrollTo(lock.x, lock.y);
            requestAnimationFrame(() => window.scrollTo(lock.x, lock.y));
        }

        function processLRC() {
            const pct = parseFloat(speedSlider.value);
            speedDisplay.textContent = pct + '%';
            if (recordMode) {
                renderRecordLines();
                return;
            }
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
        lrcInput.addEventListener('input', () => {
            if (recordMode) syncRecordLinesFromInput();
            else processLRC();
        });

        recordModeBtn.addEventListener('click', () => {
            if (recordMode) deactivateRecordMode();
            else activateRecordMode();
        });

        window.addEventListener('keydown', event => {
            if (!recordMode || isWritableTarget(event.target) || isTextEditing()) return;
            if (event.code === 'Backspace' && !recordSpaceDown) {
                event.preventDefault();
                recordSequenceActive = true;
                const previous = Math.max(0, recordIndex - 1);
                const start = recordLines[previous].start;
                recordLines[previous].start = null;
                recordLines[previous].end = null;
                recordIndex = previous;
                if (Number.isFinite(start)) lrcPlayer.currentTime = start;
                renderRecordLines();
                return;
            }
            if (!isSpaceKey(event) || event.repeat || recordSpaceDown) return;
            event.preventDefault();
            if (!syncRecordLinesFromInput()) return;
            lockRecordPage();
            recordSpaceDown = true;
            recordSequenceActive = true;
            recordLines[recordIndex].start = lrcPlayer.currentTime;
            recordLines[recordIndex].end = null;
            const play = lrcPlayer.play();
            if (play) play.catch(() => {});
            renderRecordLines();
        }, true);

        window.addEventListener('keyup', event => {
            if (!recordMode || !isSpaceKey(event) || !recordSpaceDown) return;
            event.preventDefault();
            recordLines[recordIndex].end = lrcPlayer.currentTime;
            recordSpaceDown = false;
            if (recordIndex < recordLines.length - 1) {
                recordIndex++;
            } else {
                recordSequenceActive = false;
                lrcPlayer.pause();
            }
            renderRecordLines();
            unlockRecordPage();
        }, true);

        window.addEventListener('blur', unlockRecordPage);

        lrcPlayer.addEventListener('play', () => setTimeout(releaseMediaFocus, 0));
        lrcPlayer.addEventListener('seeked', () => setTimeout(releaseMediaFocus, 0));
        lrcPlayer.addEventListener('pointerup', () => setTimeout(releaseMediaFocus, 0), true);

        lrcCopyBtn.addEventListener('click', () => {
            let copyText = generatedLrcString;
            if (recordMode) {
                copyText = recordLrcText();
            }
            navigator.clipboard.writeText(copyText).then(() => {
                const t = lrcCopyBtn.textContent;
                lrcCopyBtn.textContent = '✔';
                setTimeout(() => lrcCopyBtn.textContent = t, 1200);
            });
        });

        processLRC();
    </script>
</body>

</html>
