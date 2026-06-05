<?php

declare(strict_types=1);
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/api/db.php';

$no = $_GET['no'] ?? '';
if (!preg_match('/№\s*(\d+)/u', $no, $m)) {
    die('Invalid');
}
$mid = (int)$m[1];


$stmt = $pdo->prepare('SELECT * FROM m_com WHERE mid = ? ORDER BY mtime ASC, time ASC');
$stmt->execute([$mid]);
$all_comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

function renderComments(array $comments, ?int $parentId = null): string
{
    $html = '';
    $children = array_filter($comments, fn($c) => $c['reply_to'] === $parentId);
    if (!$children) return '';

    $html .= '<ol>';
    foreach ($children as $c) {
        $html .= '<li>';

        if ($c['username'] === 'EMPTY' && $c['string'] === 'EMPTY') {
            $html .= '<div class="ch">';
            $html .= '<img src="/m/serv/npfp.png" class="pfp">';
            $html .= '<span class="username" style="color:gray; cursor:default; text-decoration:none;">Un‑be‑knownen commenter</span>';
            $html .= '</div>';
            $html .= '<div class="cb indent" style="color:gray;"><i>Deleten comment.</i></div>';
        } else {
            $mtime_fmt = sprintf('%d:%02d', floor((float)$c['mtime'] / 60), (float)$c['mtime'] % 60);
            $pfp = empty($c['userpfp']) ? '/m/serv/npfp.png' : $c['userpfp'];

            $html .= '<div class="ch">';
            $html .= '<img src="' . htmlspecialchars($pfp, ENT_QUOTES) . '" class="pfp">';
            $html .= '<span class="username">' . htmlspecialchars($c['username'], ENT_QUOTES) . '</span>';
            $html .= ' <span class="mtime-link" onclick="jumpTo(' . (float)$c['mtime'] . ')">[' . $mtime_fmt . ']</span>';
            $html .= ' <a href="javascript:void(0)" onclick="setReply(' . $c['id'] . ', \'' . addslashes(htmlspecialchars($c['username'], ENT_QUOTES)) . '\')">↵</a>';
            $html .= ' <a href="javascript:void(0)" onclick="editCom(' . $c['id'] . ')">E</a>';
            $html .= ' <a href="javascript:void(0)" onclick="deleteCom(' . $c['id'] . ')">D</a>';
            $html .= '</div>';
            $html .= '<div id="raw-' . $c['id'] . '" style="display:none;">' . htmlspecialchars($c['raw_string'] ?? '', ENT_QUOTES) . '</div>';
            $html .= '<div id="com-body-' . $c['id'] . '" class="cb indent">' . $c['string'] . '</div>';
        }

        $html .= renderComments($comments, $c['id']);
        $html .= '</li>';
    }
    $html .= '</ol>';
    return $html;
}
$op = rand(0, 1) ? '+' : '*';
$num1 = rand(1, 9);
$num2 = rand(1, 9);
$correct_ans = $op === '+' ? ($num1 + $num2) : ($num1 * $num2);
$_SESSION['captcha_ans'] = $correct_ans;

$options = [$correct_ans];
while (count($options) < 4) {
    $wrong = $correct_ans + rand(-10, 10);
    if ($wrong !== $correct_ans && $wrong > 0 && !in_array($wrong, $options)) {
        $options[] = $wrong;
    }
}
shuffle($options);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Comments</title>
    <style>
        *:not(.katex):not(.katex *) {
            font-family: 'Junicode', 'nullpunktsenergiefont', 'BabelStone Han', 'Amiri';
            box-sizing: border-box;
            letter-spacing: 0;
            text-rendering: auto;
            image-rendering: optimizeQuality !important;
            font-variant-ligatures: discretionary-ligatures contextual common-ligatures;
            font-feature-settings: "ss17", "cv01", "cv02", "cv22" 2, "cv48", "cv57" 9, "cv33" 4;
            font-variant-numeric: lining-nums;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            height: 100vh;
            background: transparent;
            display: flex;
            align-items: flex-end;
        }

        .bh {
            width: 100vw;
            height: 100%;
            background: black;
            color: white;
            padding-right: 107px;
            padding-left: 36px;
            overflow-y: auto;
            border-top: 1px solid white;
        }

        .fw {
            width: 100%;
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 20px;
            margin-top: 10px;
        }

        .f-r {
            display: flex;
            gap: 8px;
            width: 100%;
        }

        .f-r input {
            flex: 1;
            background: transparent;
            color: white;
            border: 1px solid white;
            padding: 5px;
        }

        textarea {
            width: 100%;
            height: 80px;
            background: transparent;
            color: white;
            border: 1px solid white;
            padding: 5px;
            resize: vertical;
        }

        button {
            background: transparent;
            color: white;
            border: 1px solid white;
            padding: 5px 15px;
            cursor: pointer;
        }

        .username {
            font-weight: bolder;

        }

        .username:hover {
            cursor: pointer;
            text-decoration: underline;
        }

        ol {
            list-style-type: none;
        }

        ol,
        ol ol {
            list-style-position: inside;
        }

        ol li {
            margin: 5px 0;
        }

        ol li ol li {
            margin: 0;
        }

        ol ol,
        ul ul,
        ol ul,
        ul ol {
            padding-left: 2em;
            border-left: 1px dotted hsla(0, 0%, 50%, .5);
        }

        .indent {
            margin-left: 2em;
            font-size: .84em;
        }

        .pfp {
            width: 24px;
            height: 24px;
            border-radius: 100%;
            object-fit: cover;
            vertical-align: middle;
        }

        .ch {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .ch a {
            color: white;
            text-decoration: none;
        }

        .cb {
            text-align: justify;
            margin-top: 4px;
        }

        .spoiler {
            background: white;
            color: white;
            cursor: pointer;
        }

        .spoiler.rev {
            background: transparent;
            color: white;
        }

        .c {
            text-align: center;
        }

        .l {
            text-align: left;
        }

        .r {
            text-align: right;
        }

        .j {
            text-align: justify;
        }

        .i-c {
            display: block;
            margin: 0 auto;
        }

        .i-l {
            display: block;
            margin-right: auto;
        }

        .i-r {
            display: block;
            margin-left: auto;
        }

        #rp-msg {
            font-size: 0.8em;
            color: yellow;
            display: none;
            margin-bottom: 5px;
        }

        .f-bot {
            display: flex;
            gap: 8px;
            width: 100%;
            align-items: center;
        }

        .captcha-wrap {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .captcha-wrap label {
            cursor: pointer;
        }

        .post-btn {
            width: 20%;
            height: 100%;
        }

        .mtime-link {
            color: #a7a7a7;
            font-size: 0.85em;
            cursor: pointer;
        }

        .mtime-link:hover {
            color: white;
            text-decoration: underline;
        }
    </style>
    <script>
let dbInstance = null;
const dbName = "M2ComDB";
const mid = <?= $mid ?>;

function getDraftKey(replyId) {
    return mid + '_' + (replyId || 'main');
}

function saveCurrentDraft() {
    if (!dbInstance || document.getElementById('edit_id').value) return;
    const replyId = document.getElementById('reply_to').value;
    const str = document.querySelector('textarea[name="string"]').value;
    const tx = dbInstance.transaction("drafts", "readwrite");
    tx.objectStore("drafts").put({ mid: getDraftKey(replyId), string: str });
}

function loadDraftFor(replyId) {
    if (!dbInstance || document.getElementById('edit_id').value) return;
    const tx = dbInstance.transaction("drafts", "readonly");
    tx.objectStore("drafts").get(getDraftKey(replyId)).onsuccess = (ev) => {
        document.querySelector('textarea[name="string"]').value = ev.target.result ? ev.target.result.string : '';
    };
}

function setReply(id, uname) {
    saveCurrentDraft();
    document.getElementById('reply_to').value = id;
    
    const targetBody = document.getElementById('com-body-' + id);
    const formEl = document.getElementById('cForm');
    targetBody.after(formEl);
    
    let msg = document.getElementById('rp-msg');
    msg.style.display = 'block';
    msg.innerHTML = 'Replying to ' + uname + ' <a href="javascript:void(0)" onclick="clearReply()">[x]</a>';
    
    loadDraftFor(id);
    formEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function clearReply() {
    saveCurrentDraft();
    document.getElementById('reply_to').value = '';
    
    const wrapper = document.getElementById('form-placeholder');
    const formEl = document.getElementById('cForm');
    wrapper.appendChild(formEl);
    
    document.getElementById('rp-msg').style.display = 'none';
    
    loadDraftFor('');
}

window.addEventListener('DOMContentLoaded', () => {
    const ta = document.querySelector('textarea[name="string"]');
    if (ta) ta.addEventListener('input', saveCurrentDraft);
    
    if (location.hash) {
        const el = document.querySelector(location.hash);
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    document.getElementById('cForm').addEventListener('submit', () => {
    if (dbInstance) {
        const username = document.querySelector('input[name="username"]').value;
        const userpfp = document.querySelector('input[name="userpfp"]').value;
        dbInstance.transaction("user", "readwrite").objectStore("user").put({ 
            id: 1, 
            username: username, 
            userpfp: userpfp 
        });
    }
});
});

window.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        if (window.parent && window.parent.document) {
            const frame = window.parent.document.getElementById('comFrame');
            if (frame) {
                frame.classList.remove('open');
                frame.src = 'about:blank';
            }
        }
    }
});

function jumpTo(time) {
    if (window.parent && typeof window.parent.playAndSeek === 'function') {
        window.parent.playAndSeek(mid, time);
    }
}

function syncTime() {
    if (window.parent && window.parent.document) {
        const btn = window.parent.document.querySelector(`a.editBtn[href="editm.php?id=${mid}"]`);
        if (btn) {
            const audio = btn.closest('.cardWrap').querySelector('audio');
            if (audio) {
                document.getElementById('mtime').value = audio.currentTime || 0;
            }
        }
    }
}
setInterval(syncTime, 1000);

const req = indexedDB.open(dbName, 2);
req.onupgradeneeded = (e) => {
    const db = e.target.result;
    if (!db.objectStoreNames.contains("user")) db.createObjectStore("user", { keyPath: "id" });
    if (!db.objectStoreNames.contains("drafts")) db.createObjectStore("drafts", { keyPath: "mid" });
};
req.onsuccess = (e) => {
    dbInstance = e.target.result;
    
    const uStore = dbInstance.transaction("user", "readonly").objectStore("user");
    uStore.get(1).onsuccess = (ev) => {
        if (ev.target.result) {
            document.querySelector('input[name="username"]').value = ev.target.result.username || '';
            document.querySelector('input[name="userpfp"]').value = ev.target.result.userpfp || '';
        }
    };
    
    const dStore = dbInstance.transaction("drafts", "readonly").objectStore("drafts");
    dStore.get(getDraftKey('')).onsuccess = (ev) => {
        if (ev.target.result && !document.getElementById('edit_id').value) {
            document.querySelector('textarea[name="string"]').value = ev.target.result.string || '';
        }
    };
};

async function editCom(id) {
    let p = prompt('Enter paß-word for þis comment:');
    if (!p) return;

    let fd = new FormData();
    fd.append('action', 'verify');
    fd.append('id', id);
    fd.append('pasw', p);

    let res = await fetch('pasw.php?no=<?= urlencode($no) ?>', { method: 'POST', body: fd });
    let data = await res.json();
    
    if (!data.ok) {
        alert('Wrong paß-word!');
        return;
    }

    let bodyEl = document.getElementById('com-body-' + id);
    let rawEl = document.getElementById('raw-' + id);
    let origHTML = bodyEl.innerHTML;

    let form = document.createElement('form');
    form.method = 'POST';
    form.action = 'pasw.php?no=<?= urlencode($no) ?>';

    let act = document.createElement('input'); act.type = 'hidden'; act.name = 'action'; act.value = 'edit';
    let eid = document.createElement('input'); eid.type = 'hidden'; eid.name = 'edit_id'; eid.value = id;
    let psw = document.createElement('input'); psw.type = 'hidden'; psw.name = 'pasw'; psw.value = p;

    let ta = document.createElement('textarea');
    ta.name = 'string';
    ta.value = rawEl.innerText;
    ta.style.width = '100%';
    ta.style.height = '60px';

    let btn = document.createElement('button');
    btn.type = 'submit';
    btn.textContent = 'Finish edit.';

    let cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.textContent = 'Cancel';
    cancelBtn.onclick = () => { bodyEl.innerHTML = origHTML; };

    form.append(act, eid, psw, ta, btn, cancelBtn);
    bodyEl.innerHTML = '';
    bodyEl.appendChild(form);
}

async function deleteCom(id) {
    let p = prompt('Enter paß-word to delete:');
    if (!p) return;
    if (!confirm('Are you sure you want to delete þis comment?')) return;

    let fd = new FormData();
    fd.append('action', 'delete');
    fd.append('id', id);
    fd.append('pasw', p);

    let res = await fetch('pasw.php?no=<?= urlencode($no) ?>', { method: 'POST', body: fd });
    let data = await res.json();
    
    if (!data.ok) {
        alert('Wrong paß-word!');
        return;
    }
    location.reload();
}
</script>
</head>

<body>
    <div class="bh">
        <div id="form-placeholder">
            <form class="fw" method="POST" action="pasw.php?no=<?= urlencode($no) ?>" id="cForm">
                <div id="rp-msg"></div>
                <input type="hidden" name="reply_to" id="reply_to" value="">
                <input type="hidden" name="edit_id" id="edit_id" value="">
                <input type="hidden" name="mtime" id="mtime" value="0">
                <div class="f-r">
                    <input type="text" name="username" placeholder="Name" required>
                    <input type="url" name="userpfp" placeholder="Image link">
                    <input type="password" name="pasw" placeholder="Password" required>
                </div>
                <textarea name="string" placeholder="Comment...&#10;**bold** *italic* __underline__ --strike-- ||spoiler||&#10;<c>center</c> <l>left</l> <r>right</r> <j>justify</j>&#10;<img src=&quot;url&quot; width x height x c>" required></textarea>
                <div class="f-bot">
                    <div class="captcha-wrap">
                        <span><?= $num1 ?> <?= $op ?> <?= $num2 ?> = ?</span>
                        <?php foreach ($options as $opt): ?>
                            <label><input type="radio" name="captcha_ans" value="<?= $opt ?>" required> <?= $opt ?></label>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" class="post-btn">Post</button>
                </div>
            </form>
        </div>
        <div>
            <?= renderComments($all_comments, null) ?>
        </div>
        <div style="height: 10lh;"></div>
    </div>
</body>

</html>