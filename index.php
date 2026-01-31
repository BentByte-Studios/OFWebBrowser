<?php
require_once 'config.php';
require_once 'includes/db.php';
require_once 'includes/global_db.php';
require_once 'includes/functions.php';
require_once 'includes/error_handler.php';

// Initialize error handler
ErrorHandler::init();

// Initialize Global DB
$globalDbPath = __DIR__ . '/db/global.db';
$globalDb = new OFGlobalDatabase($globalDbPath);

// Initial check to create schema if missing (silent)
$globalDb->initSchema();

// Fetch Profiles from Global DB
// We use a LEFT JOIN to count media efficiently
$creators = $globalDb->query("
    SELECT c.*, 
           (SELECT COUNT(*) FROM medias m WHERE m.creator_id = c.id) as media_count,
           (SELECT COUNT(*) FROM posts p WHERE p.creator_id = c.id) as post_count
    FROM creators c 
    ORDER BY c.username ASC
");

// Fallback: If DB is empty, maybe show a "Welcome/Scan" message instead of old folder scan?
// User explicitly wants "single db file", so we should rely on it.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= SITE_TITLE ?></title>
    <style>
        :root {
            /* OF Dark Theme Colors */
            --bg-body: #0b0e11; 
            --bg-card: #191f27;
            --text-primary: #e4e7eb;
            --text-secondary: #8a96a3;
            --accent: #00aff0;
            --accent-hover: #0091c9;
            --border-color: #242c37;
        }
        body {
            background-color: var(--bg-body);
            color: var(--text-primary);
            font-family: 'Segoe UI', Helvetica, Arial, sans-serif;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .header-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-color);
        }
        h1 { margin: 0; font-weight: 700; font-size: 1.5rem; }
        
        .btn {
            background: var(--accent);
            color: #fff;
            border: none;
            padding: 8px 25px;
            border-radius: 50px; /* Pill Shape */
            cursor: pointer;
            font-weight: 700;
            font-size: 0.9rem;
            text-transform: uppercase;
            text-decoration: none;
            transition: background 0.2s;
        }
        .btn:hover { background: var(--accent-hover); }
        
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
        }
        .card {
            background: var(--bg-card);
            border: 1px solid transparent; /* Prepare for border */
            border-radius: 6px; /* Slightly tighter radius than before */
            overflow: hidden;
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            transition: border-color 0.2s;
        }
        .card:hover { border-color: var(--text-secondary); }
        .card-header {
            height: 100px;
            background: #334155;
            background-size: cover;
            background-position: center;
        }
        .card-body {
            padding: 15px;
            position: relative;
            flex-grow: 1;
        }
        .avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            border: 3px solid var(--bg-card);
            margin-top: -55px;
            background: #475569;
            object-fit: cover;
            position: relative; z-index: 2;
        }
        .name {
            font-size: 1.1rem;
            font-weight: 700;
            margin: 10px 0 2px;
            color: var(--text-primary);
        }
        .stats {
            color: var(--text-secondary);
            font-size: 0.85rem;
            margin-bottom: 10px;
        }
        .bio {
            font-size: 0.9rem;
            color: var(--text-primary);
            line-height: 1.4;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        /* Modal */
        .modal-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.8);
            display: none; justify-content: center; align-items: center;
            z-index: 1000;
        }
        .modal {
            background: var(--bg-card);
            color: var(--text-primary);
            padding: 30px;
            border-radius: 10px;
            width: 90%; max-width: 500px;
            text-align: center;
            border: 1px solid var(--border-color);
        }
        .progress-bar {
            width: 100%; height: 10px; background: #242c37;
            border-radius: 5px; margin: 20px 0; overflow: hidden;
        }
        .progress-fill {
            height: 100%; background: var(--accent); width: 0%;
            transition: width 0.3s;
        }
        .log-box {
            height: 100px; overflow-y: auto;
            background: #0b0e11; padding: 10px;
            text-align: left; font-family: monospace; font-size: 0.8rem;
            color: var(--text-secondary); border-radius: 6px;
            border: 1px solid var(--border-color);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-bar">
            <h1><?= SITE_TITLE ?></h1>
            <button class="btn" onclick="startScan()">Rescan Library</button>
        </div>
        
        <?php if (empty($creators)): ?>
            <div style="text-align:center; padding: 50px; color: var(--text-secondary);">
                <h2>Library is Empty</h2>
                <p>Click "Rescan Library" to import your content.</p>
            </div>
        <?php else: ?>
            <div class="grid">
                <?php foreach ($creators as $p): ?>
                <a href="profile.php?id=<?= $p['id'] ?>" class="card">
                    <div class="card-header" style="<?= $p['header_path'] ? "background-image: url('view.php?path=".urlencode($p['header_path'])."');" : '' ?>"></div>
                    <div class="card-body">
                        <?php if ($p['avatar_path']): ?>
                            <img src="view.php?path=<?= urlencode($p['avatar_path']) ?>" class="avatar" alt="Avatar">
                        <?php else: ?>
                            <div class="avatar" style="display:flex;align-items:center;justify-content:center;color:#fff;">
                                <?= strtoupper(substr($p['username'], 0, 1)) ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="name"><?= htmlspecialchars($p['username']) ?></div>
                        <div class="stats">
                            <?= $p['post_count'] ?> posts • <?= $p['media_count'] ?> media
                        </div>
                        <?php if ($p['bio']): ?>
                            <div class="bio"><?= nl2br(htmlspecialchars(cleanText($p['bio']))) ?></div>
                        <?php endif; ?>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Scan Modal -->
    <div class="modal-overlay" id="scanModal">
        <div class="modal">
            <h2 id="scanTitle">Scanning Library...</h2>
            <div class="progress-bar"><div class="progress-fill" id="scanProgress"></div></div>
            <div class="log-box" id="scanLog"></div>
        </div>
    </div>

    <div id="silentScanBadge" style="position:fixed;bottom:20px;right:20px;background:#1e293b;padding:10px 15px;border-radius:30px;border:1px solid #334155;display:none;align-items:center;gap:10px;box-shadow:0 4px 12px rgba(0,0,0,0.5);z-index:900;">
        <div class="spinner" style="width:16px;height:16px;border:2px solid #8b5cf6;border-top-color:transparent;border-radius:50%;animation:spin 1s linear infinite;"></div>
        <span style="font-size:0.9rem;color:#cbd5e1;" id="silentscanText">Syncing...</span>
    </div>

    <!-- Initial Scan Overlay (shown when library is empty) -->
    <div id="initialScanOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.9);align-items:center;justify-content:center;z-index:1001;flex-direction:column;gap:20px;">
        <div class="spinner" style="width:50px;height:50px;border:4px solid #333;border-top-color:#00aff0;border-radius:50%;animation:spin 1s linear infinite;"></div>
        <div style="color:#fff;font-size:1.25rem;font-weight:500;" id="initialScanText">Scanning Library...</div>
        <div style="color:#888;font-size:0.9rem;" id="initialScanStatus">Preparing...</div>
    </div>

    <style>@keyframes spin { to {transform: rotate(360deg);} }</style>

    <script>
        const modal = document.getElementById('scanModal');
        const progressFill = document.getElementById('scanProgress');
        const logBox = document.getElementById('scanLog');
        const title = document.getElementById('scanTitle');
        const silentBadge = document.getElementById('silentScanBadge');
        const silentText = document.getElementById('silentscanText');
        const initialOverlay = document.getElementById('initialScanOverlay');
        const initialText = document.getElementById('initialScanText');
        const initialStatus = document.getElementById('initialScanStatus');
        const isLibraryEmpty = <?= empty($creators) ? 'true' : 'false' ?>;

        // Check for Auto-Scan on Load
        window.addEventListener('load', async () => {
            try {
                const res = await fetch('scan.php?action=status');
                const data = await res.json();
                if (data.status === 'ok') {
                    const now = Math.floor(Date.now() / 1000);
                    // Default 1 hour interval, or immediate if library is empty
                    if (isLibraryEmpty || now - data.last_scan > data.interval) {
                        console.log("Auto-scanning...");
                        startScan(true);
                    }
                }
            } catch(e) { console.error("Auto-scan check failed", e); }
        });

        async function startScan(isSilent = false) {
            if (!isSilent && !confirm("This will scan all creator folders and rebuild the global database. Continue?")) return;

            const useInitialOverlay = isSilent && isLibraryEmpty;

            if (useInitialOverlay) {
                initialOverlay.style.display = 'flex';
                initialText.innerText = 'Scanning Library...';
                initialStatus.innerText = 'Preparing...';
            } else if (isSilent) {
                silentBadge.style.display = 'flex';
                silentText.innerText = 'Syncing Content...';
            } else {
                modal.style.display = 'flex';
                log("Starting scan...");
                progressFill.style.width = '0%';
            }
            
            try {
                // 1. Init & Get List
                const initRes = await fetch('scan.php?action=init');
                const initData = await initRes.json();
                
                if (initData.status !== 'ok') throw new Error(initData.message || "Init failed");
                
                const creators = initData.creators;
                const total = creators.length;
                if (!isSilent) log(`Found ${total} creators.`);
                if (useInitialOverlay) initialStatus.innerText = `Found ${total} creators`;

                // 2. Process in parallel batches for speed
                const CONCURRENCY = 3;
                let completed = 0;

                const processCreator = async (folder) => {
                    const res = await fetch(`scan.php?action=process&folder=${encodeURIComponent(folder)}`);
                    const data = await res.json();
                    completed++;

                    if (data.status === 'skipped') {
                        if (!isSilent) log(`Skipped ${folder}: ${data.message}`);
                    } else if (data.status !== 'ok') {
                        if (!isSilent) log(`ERROR processing ${folder}: ${data.message}`);
                    } else if (!isSilent) {
                        log(`Processed ${folder} (${completed}/${total})`);
                    }

                    // Update progress UI
                    if (!isSilent) {
                        title.innerText = `Scanning ${completed}/${total}`;
                        progressFill.style.width = `${(completed/total)*100}%`;
                    } else if (useInitialOverlay) {
                        initialText.innerText = `Scanning ${completed}/${total}`;
                        initialStatus.innerText = folder;
                    } else {
                        silentText.innerText = `Syncing ${completed}/${total}...`;
                    }
                };

                // Process in batches of CONCURRENCY
                for (let i = 0; i < total; i += CONCURRENCY) {
                    const batch = creators.slice(i, i + CONCURRENCY);
                    await Promise.all(batch.map(processCreator));
                }
                
                // 3. Mark Complete
                await fetch('scan.php?action=complete');
                
                if (!isSilent) {
                    log("Scan complete! Reloading...");
                    setTimeout(() => location.reload(), 1000);
                } else if (useInitialOverlay) {
                    initialText.innerText = 'Scan Complete!';
                    initialStatus.innerText = 'Loading library...';
                    setTimeout(() => location.reload(), 1000);
                } else {
                    silentText.innerText = 'Sync Complete';
                    setTimeout(() => {
                        silentBadge.style.display = 'none';
                        location.reload();
                    }, 2000);
                }

            } catch (e) {
                console.error(e);
                if (!isSilent) {
                    log("CRITICAL ERROR: " + e.message);
                    alert("Scan failed: " + e.message);
                    modal.style.display = 'none';
                } else if (useInitialOverlay) {
                    initialText.innerText = 'Scan Failed';
                    initialStatus.innerText = e.message;
                    setTimeout(() => initialOverlay.style.display = 'none', 3000);
                } else {
                    silentText.innerText = 'Sync Failed';
                    setTimeout(() => silentBadge.style.display = 'none', 3000);
                }
            }
        }
        
        function log(msg) {
            const line = document.createElement('div');
            line.innerText = msg;
            logBox.appendChild(line);
            logBox.scrollTop = logBox.scrollHeight;
        }
    </script>
</body>
</html>
