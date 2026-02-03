<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/global_db.php';
require_once 'includes/functions.php';
require_once 'includes/error_handler.php';

// Initialize error handler
ErrorHandler::init();

// Require authentication
requireAuth();

// Initialize Global DB
$globalDbPath = __DIR__ . '/db/global.db';
$globalDb = new OFGlobalDatabase($globalDbPath);

// Initial check to create schema if missing (silent)
$globalDb->initSchema();

// Fetch Profiles from Global DB
// Use pre-computed counts for better performance (falls back to subquery if counts are 0)
$creators = $globalDb->query("
    SELECT c.*,
           CASE WHEN c.media_count > 0 THEN c.media_count
                ELSE (SELECT COUNT(*) FROM medias m WHERE m.creator_id = c.id) END as media_count,
           CASE WHEN c.post_count > 0 THEN c.post_count
                ELSE (SELECT COUNT(*) FROM posts p WHERE p.creator_id = c.id) END as post_count
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

        .search-input {
            width: 200px;
            padding: 8px 12px;
            padding-left: 36px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 50px;
            color: var(--text-primary);
            font-size: 0.9rem;
            outline: none;
            transition: border-color 0.2s, width 0.2s;
        }
        .search-input:focus {
            border-color: var(--accent);
            width: 280px;
        }
        .search-input::placeholder {
            color: var(--text-secondary);
        }
        .search-wrapper {
            position: relative;
        }
        .search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
            pointer-events: none;
        }
        .no-results {
            text-align: center;
            padding: 50px;
            color: var(--text-secondary);
            display: none;
        }

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
            <div style="display: flex; gap: 10px; align-items: center;">
                <?php if (!empty($creators)): ?>
                <div class="search-wrapper">
                    <svg class="search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8"></circle>
                        <path d="M21 21l-4.35-4.35"></path>
                    </svg>
                    <input type="text" class="search-input" id="searchInput" placeholder="Search..." autocomplete="off">
                </div>
                <?php endif; ?>
                <button class="btn" onclick="startScan()">Rescan Library</button>
                <a href="logout.php" class="btn" style="background: transparent; border: 1px solid var(--border-color);">Logout</a>
            </div>
        </div>

        <?php if (empty($creators)): ?>
            <div style="text-align:center; padding: 50px; color: var(--text-secondary);">
                <h2>Library is Empty</h2>
                <p>Click "Rescan Library" to import your content.</p>
            </div>
        <?php else: ?>
            <div class="no-results" id="noResults">
                <h3>No creators found</h3>
                <p>Try a different search term</p>
            </div>
            <div class="grid" id="creatorsGrid">
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

        <footer class="site-footer">
            <a href="https://github.com/BentByte-Studios/OFWebBrowser" target="_blank" rel="noopener">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"/></svg>
                GitHub
            </a>
            <a href="https://discord.gg/k86x44ubJR" target="_blank" rel="noopener">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor"><path d="M13.545 2.907a13.227 13.227 0 0 0-3.257-1.011.05.05 0 0 0-.052.025c-.141.25-.297.577-.406.833a12.19 12.19 0 0 0-3.658 0 8.258 8.258 0 0 0-.412-.833.051.051 0 0 0-.052-.025c-1.125.194-2.22.534-3.257 1.011a.041.041 0 0 0-.021.018C.356 6.024-.213 9.047.066 12.032c.001.014.01.028.021.037a13.276 13.276 0 0 0 3.995 2.02.05.05 0 0 0 .056-.019c.308-.42.582-.863.818-1.329a.05.05 0 0 0-.027-.07 8.735 8.735 0 0 1-1.248-.595.05.05 0 0 1-.005-.083c.084-.063.168-.129.248-.195a.05.05 0 0 1 .051-.007c2.619 1.196 5.454 1.196 8.041 0a.052.052 0 0 1 .053.007c.08.066.164.132.248.195a.051.051 0 0 1-.004.085c-.399.233-.813.43-1.249.594a.05.05 0 0 0-.027.07c.24.465.515.909.817 1.329a.05.05 0 0 0 .056.019 13.235 13.235 0 0 0 4.001-2.02.049.049 0 0 0 .021-.037c.334-3.451-.559-6.449-2.366-9.106a.034.034 0 0 0-.02-.019zm-8.198 7.307c-.789 0-1.438-.724-1.438-1.612 0-.889.637-1.613 1.438-1.613.807 0 1.45.73 1.438 1.613 0 .888-.637 1.612-1.438 1.612zm5.316 0c-.788 0-1.438-.724-1.438-1.612 0-.889.637-1.613 1.438-1.613.807 0 1.451.73 1.438 1.613 0 .888-.631 1.612-1.438 1.612z"/></svg>
                Discord
            </a>
            <a href="https://asa.wowemu.forum/" target="_blank" rel="noopener">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor"><path d="M8 0a8 8 0 1 0 0 16A8 8 0 0 0 8 0zm3.5 4.5l-5 7h-1l-2-3h1.5l1 1.5 4-5.5h1.5z"/></svg>
                StreamGet
            </a>
        </footer>
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

    <style>
        @keyframes spin { to {transform: rotate(360deg);} }

        .site-footer {
            text-align: center;
            padding: 30px 20px;
            margin-top: 40px;
            border-top: 1px solid var(--border-color);
            color: var(--text-secondary);
            font-size: 0.85rem;
        }
        .site-footer a {
            color: var(--text-secondary);
            text-decoration: none;
            margin: 0 12px;
            transition: color 0.2s;
        }
        .site-footer a:hover { color: var(--accent); }
        .site-footer svg { vertical-align: middle; margin-right: 5px; }
    </style>

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

        // Debounce utility
        function debounce(fn, delay) {
            let timeout;
            return function(...args) {
                clearTimeout(timeout);
                timeout = setTimeout(() => fn.apply(this, args), delay);
            };
        }

        // Search functionality
        const searchInput = document.getElementById('searchInput');
        const creatorsGrid = document.getElementById('creatorsGrid');
        const noResults = document.getElementById('noResults');

        if (searchInput && creatorsGrid) {
            const cards = creatorsGrid.querySelectorAll('.card');
            // Pre-cache card data for faster filtering
            const cardData = Array.from(cards).map(card => ({
                el: card,
                name: (card.querySelector('.name')?.textContent || '').toLowerCase(),
                bio: (card.querySelector('.bio')?.textContent || '').toLowerCase()
            }));

            const filterCards = debounce(function() {
                const query = searchInput.value.toLowerCase().trim();
                let visibleCount = 0;

                cardData.forEach(({ el, name, bio }) => {
                    const matches = name.includes(query) || bio.includes(query);
                    el.style.display = matches ? '' : 'none';
                    if (matches) visibleCount++;
                });

                if (noResults) {
                    noResults.style.display = (visibleCount === 0 && query !== '') ? 'block' : 'none';
                }
            }, 150);

            searchInput.addEventListener('input', filterCards);
        }

        // Check for Auto-Scan on Load
        window.addEventListener('load', async () => {
            try {
                const res = await fetch('scan.php?action=status');
                const data = await res.json();
                if (data.status === 'ok' && data.auto_sync_enabled !== false) {
                    const now = Math.floor(Date.now() / 1000);
                    // Use configured interval, or immediate if library is empty
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
