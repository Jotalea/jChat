<?php
// index.php

session_start();
include 'commands.php';
define('LOG_DIR', __DIR__ . '/logs');
if (!is_dir(LOG_DIR)) mkdir(LOG_DIR, 0777, true);
if (empty($_SESSION['nick'])) $_SESSION['nick'] = 'User' . rand(100, 999);
if (empty($_SESSION['channel'])) $_SESSION['channel'] = '#lobby';

function getLogFile($channel) { return LOG_DIR . '/' . preg_replace('/[^a-z0-9-_]/i', '', $channel) . '.log'; }
function writeLog($channel, $text) { file_put_contents(getLogFile($channel), $text . PHP_EOL, FILE_APPEND | LOCK_EX); }

function formatContent($text) {
    $text = htmlspecialchars($text, ENT_QUOTES);
    $text = preg_replace_callback('/!\[(.*?)\]\((.*?)\)/', function($m) {
        if (strpos($m[2], '/api/images.php') !== false || preg_match('/(tenor|giphy)/', $m[2])) {
            return "<img src='{$m[2]}' style='max-height:200px; border-radius:8px; display:block; margin:5px 0;'>";
        }
        return "<a href='{$m[2]}' target='_blank'>[🖼️ Image]</a>";
    }, $text);
    return preg_replace('/(?<![\'"])(https?:\/\/[^\s<]+)/i', '<a href="$1" target="_blank">$1</a>', $text);
}

function formatMsg($nick, $msg, $type = 'msg') {
    $time = date('Y-m-d H:i:s');
    $color = "hsl(" . (crc32($nick) % 360) . ", 70%, 75%)";
    if ($type === 'action') return "<div class='msg action'><span>$time</span> <strong style='color:$color'>* $nick</strong> " . formatContent($msg) . "</div>";
    if ($type === 'status') return "<div class='msg status'><span>$time</span> — $msg</div>";
    return "<div class='msg'><span>$time</span> <strong style='color:$color'>&lt;$nick&gt;</strong> " . formatContent($msg) . "</div>";
}

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    if ($_GET['ajax'] === 'poll') {
        $file = getLogFile($_SESSION['channel']);
        echo json_encode(['html' => file_exists($file) ? file_get_contents($file) : '', 'channel' => $_SESSION['channel']]);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = trim($_POST['cmd'] ?? '');
        if ($raw !== '') {
            if ($raw[0] === '/') {
                $res = processCommand($raw, $_SESSION);
                if (!empty($res['announcement']) && empty($res['local_only'])) writeLog($_SESSION['channel'], formatMsg($_SESSION['nick'], $res['announcement'], $res['type'] ?? 'status'));
                if (!empty($res['inject_msg'])) writeLog($_SESSION['channel'], formatMsg($_SESSION['nick'], $res['inject_msg']));
                echo json_encode($res);
            } else {
                writeLog($_SESSION['channel'], formatMsg($_SESSION['nick'], $raw));
                echo json_encode(['ok' => true]);
            }
        }
    }
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat</title>
    <style>
* { box-sizing: border-box; }
body {
    background: #1e1e2e;
    color: #cdd6f4;
    font-family: sans-serif;
    margin: 0;
    height: 100dvh;
    overflow: hidden;
    position: relative;
}
main {
    height: 100%;
    overflow-y: auto;
    padding-bottom: 80px; /* same as footer height + extra space */
    scroll-behavior: smooth;
}
footer {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    padding: 1rem;
    border-top: 1px solid #313244;
    background: #1e1e2e;
    z-index: 10;
}
.input-group {
    display: flex;
    gap: 10px;
    max-width: 900px;
    margin: 0 auto;
}
input[type="text"] {
    flex: 1;
    background: #313244;
    border: none;
    padding: 12px;
    color: #fff;
    border-radius: 8px;
    outline: none;
}
#uploadBtn {
    background: #45475a;
    border: none;
    color: #fff;
    padding: 0 15px;
    border-radius: 8px;
    cursor: pointer;
}
#scrollNotice {
    position: fixed;
    bottom: 90px; /* above footer */
    left: 50%;
    transform: translateX(-50%);
    background: #89b4fa;
    color: #1e1e2e;
    padding: 8px 16px;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: bold;
    cursor: pointer;
    display: none;
    box-shadow: 0 4px 15px rgba(0,0,0,0.3);
    z-index: 100;
}
@media (min-width: 768px) { #uploadBtn { display: none; } }
body.dragging main { outline: 2px dashed #89b4fa; outline-offset: -10px; background: rgba(137,180,250,0.1); }
a { color: #89b4fa; text-decoration: none; }
/* Manual scroll overrides for older mobile browsers */
body.manual-scroll {
    height: auto;
    overflow: visible;
}
body.manual-scroll main {
    height: auto;
    overflow-y: visible;
}
    </style>
</head>
<body>
    <div id="scrollNotice">⬇ New messages below</div>
    <main id="chat"></main>
    <footer>
        <form id="form" class="input-group">
            <button type="button" id="uploadBtn">📎</button>
            <input type="file" id="fileInput" accept="image/*" style="display:none">
            <input type="text" id="input" placeholder="Connecting..." autocomplete="off">
        </form>
    </footer>

<script>
    const chat = document.getElementById('chat');
    const input = document.getElementById('input');
    const scrollNotice = document.getElementById('scrollNotice');
    const fileInput = document.getElementById('fileInput');
    let lastHtml = '';
    let isAtBottom = true;
    let manualScroll = false; // Toggle for older devices

    // Check if user is at the bottom (with 50px buffer)
    chat.onscroll = () => {
        if (manualScroll) return; // Skip heavy calculations if disabled
        isAtBottom = (chat.scrollHeight - chat.scrollTop) <= (chat.clientHeight + 50);
        if (isAtBottom) scrollNotice.style.display = 'none';
    };

    const jumpToBottom = () => {
        chat.scrollTop = chat.scrollHeight;
        scrollNotice.style.display = 'none';
    };

    scrollNotice.onclick = jumpToBottom;

    // Keybind: Pressing "End" key goes to bottom
    window.addEventListener('keydown', (e) => {
        if (e.key === 'End') jumpToBottom();
    });

    // Handle Image Load Scrolling (Only if was at bottom and auto-scroll enabled)
    chat.addEventListener('load', (e) => { 
        if(e.target.tagName === 'IMG' && !manualScroll && isAtBottom) jumpToBottom(); 
    }, true);

    async function handleUpload(file) {
        if (!file?.type.startsWith('image/')) return;
        input.placeholder = "Uploading...";
        const fd = new FormData(); fd.append('image', file);
        const res = await fetch('/api/images.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            const cmdFd = new FormData(); cmdFd.append('cmd', `![img](/api/images.php?filename=${data.filename})`);
            await fetch('?ajax=cmd', { method: 'POST', body: cmdFd });
            poll();
        }
        input.placeholder = "Message...";
    }

    // Drag-Drop & Paste
    document.addEventListener('paste', e => {
        const item = e.clipboardData.items[0];
        if (item?.type.includes('image')) handleUpload(item.getAsFile());
    });
    document.body.addEventListener('dragover', e => { e.preventDefault(); document.body.classList.add('dragging'); });
    document.body.addEventListener('dragleave', () => document.body.classList.remove('dragging'));
    document.body.addEventListener('drop', e => { 
        e.preventDefault(); 
        document.body.classList.remove('dragging'); 
        handleUpload(e.dataTransfer.files[0]); 
    });

    document.getElementById('uploadBtn').onclick = () => fileInput.click();
    fileInput.onchange = () => handleUpload(fileInput.files[0]);

    document.getElementById('form').onsubmit = async e => {
        e.preventDefault();
        const v = input.value.trim();
        if (!v) return;
        
        // Client-side specific commands
        if (v === '/upload') { fileInput.click(); input.value = ''; return; }
        if (v === '/clear') { chat.innerHTML = ''; lastHtml = ''; input.value = ''; return; }
                if (v === '/scroll') {
            manualScroll = !manualScroll;
            input.value = '';
            
            if (manualScroll) {
                // Enable native body scrolling
                document.body.classList.add('manual-scroll');
                scrollNotice.style.display = 'none';
            } else {
                // Revert to inner scrolling
                document.body.classList.remove('manual-scroll');
                isAtBottom = true;
                jumpToBottom();
            }

            // Inject a temporary status notification directly into the DOM
            const time = new Date().toLocaleTimeString();
            const statusMsg = manualScroll ? "Manual body scrolling enabled. Auto-scroll OFF." : "Auto-scrolling enabled.";
            chat.innerHTML += `<div class='msg status'><strong>[System]</strong> ${statusMsg}</div>`;
            
            // If native scrolling was just enabled, scroll to bottom of the document
            if (manualScroll) {
                window.scrollTo(0, document.body.scrollHeight);
            }
            return;
        }

        input.value = '';
        const fd = new FormData(); fd.append('cmd', v);
        await fetch('?ajax=cmd', { method: 'POST', body: fd });
        poll();
    };

    async function poll() {
        const res = await fetch('?ajax=poll');
        const data = await res.json();
        if (data.html !== lastHtml) {
            const wasAtBottom = isAtBottom;
            chat.innerHTML = data.html;
            lastHtml = data.html;
            
            if (manualScroll) {
                // Do absolutely nothing; let the user manage their scroll
            } else if (wasAtBottom) {
                jumpToBottom();
            } else {
                scrollNotice.style.display = 'block';
            }
        }
        input.placeholder = `Message ${data.channel}...`;
    }

    input.addEventListener('focus', () => {
        // Small delay ensures the keyboard is fully open and the viewport has resized
        setTimeout(() => {
            if (!manualScroll) input.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }, 100);
    });

    setInterval(poll, 2000);
    poll();
</script>
</body>
</html>