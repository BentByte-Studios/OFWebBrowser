<?php
require_once __DIR__ . '/error_handler.php';

class OFGlobalDatabase {
    private $pdo;
    private $dbPath;

    public function __construct($path) {
        $this->dbPath = $path;
        $this->connect();
    }

    private function connect() {
        try {
            $dir = dirname($this->dbPath);
            if (!is_dir($dir)) mkdir($dir, 0777, true);

            $this->pdo = new PDO('sqlite:' . $this->dbPath);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            ErrorHandler::log("Global DB connection failed: " . $e->getMessage(), 'CRITICAL');
            ErrorHandler::showError('Database Error', 'Could not connect to the database. Please check the logs.', 500);
        }
    }

    public function getPdo() {
        return $this->pdo;
    }

    public function query($sql, $params = []) {
        if (!$this->pdo) return [];
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            ErrorHandler::log("Global DB query failed: " . $e->getMessage() . " | SQL: " . $sql, 'ERROR');
            return [];
        }
    }

    public function queryOne($sql, $params = []) {
        if (!$this->pdo) return null;
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetch();
        } catch (PDOException $e) {
            ErrorHandler::log("Global DB queryOne failed: " . $e->getMessage() . " | SQL: " . $sql, 'ERROR');
            return null;
        }
    }

    public function execute($sql, $params = []) {
        if (!$this->pdo) return false;
        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            ErrorHandler::log("Global DB execute failed: " . $e->getMessage() . " | SQL: " . $sql, 'ERROR');
            return false;
        }
    }

    public function initSchema() {
        $queries = [
            "CREATE TABLE IF NOT EXISTS creators (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE,
                folder_path TEXT,
                avatar_path TEXT,
                header_path TEXT,
                bio TEXT,
                scanned_at DATETIME
            )",
            "CREATE TABLE IF NOT EXISTS posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                post_id INTEGER,
                creator_id INTEGER,
                text TEXT,
                price INTEGER,
                paid INTEGER,
                archived INTEGER,
                created_at DATETIME,
                source_type TEXT DEFAULT 'posts',
                from_user INTEGER DEFAULT 0,
                UNIQUE(post_id, creator_id)
            )",
            "CREATE TABLE IF NOT EXISTS medias (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                media_id INTEGER,
                post_id INTEGER,
                creator_id INTEGER,
                filename TEXT,
                directory TEXT,
                size INTEGER,
                type TEXT,
                downloaded INTEGER,
                created_at DATETIME,
                UNIQUE(media_id, creator_id)
            )",
            // Indexes for common query patterns
            "CREATE INDEX IF NOT EXISTS idx_posts_creator ON posts(creator_id)",
            "CREATE INDEX IF NOT EXISTS idx_posts_creator_source ON posts(creator_id, source_type)",
            "CREATE INDEX IF NOT EXISTS idx_media_post ON medias(post_id)",
            "CREATE INDEX IF NOT EXISTS idx_media_creator ON medias(creator_id)",
            // Composite index for filtered media queries (performance optimization)
            "CREATE INDEX IF NOT EXISTS idx_media_creator_type ON medias(creator_id, type)",
            "CREATE TABLE IF NOT EXISTS meta (
                key TEXT PRIMARY KEY,
                value TEXT
            )",
            // Scan locking table to prevent concurrent scan corruption
            "CREATE TABLE IF NOT EXISTS scan_locks (
                id INTEGER PRIMARY KEY,
                started_at DATETIME,
                pid INTEGER
            )"
        ];

        foreach ($queries as $q) {
            try {
                $this->pdo->exec($q);
            } catch (PDOException $e) {
                ErrorHandler::log("Schema init failed: " . $e->getMessage() . " | SQL: " . $q, 'ERROR');
            }
        }

        // Migration: Add from_user column if it doesn't exist (for existing databases)
        $migrations = [
            "ALTER TABLE posts ADD COLUMN from_user INTEGER DEFAULT 0"
        ];

        foreach ($migrations as $q) {
            try {
                $this->pdo->exec($q);
            } catch (PDOException $e) {
                ErrorHandler::log("Schema init failed: " . $e->getMessage() . " | SQL: " . $q, 'ERROR');
            }
        }
    }

    public function updateMeta($key, $value) {
        if (!$this->pdo) return;
        try {
            $stmt = $this->pdo->prepare("INSERT OR REPLACE INTO meta (key, value) VALUES (?, ?)");
            $stmt->execute([$key, $value]);
        } catch (PDOException $e) {
            ErrorHandler::log("updateMeta failed: " . $e->getMessage(), 'ERROR');
        }
    }

    public function getMeta($key) {
        if (!$this->pdo) return null;
        $row = $this->queryOne("SELECT value FROM meta WHERE key = ?", [$key]);
        return $row ? $row['value'] : null;
    }

    /**
     * Acquire a scan lock to prevent concurrent scans
     * @return bool True if lock acquired, false if scan already in progress
     */
    public function acquireScanLock() {
        if (!$this->pdo) return false;

        try {
            // Clear stale locks (older than 10 minutes)
            $this->execute("DELETE FROM scan_locks WHERE started_at < datetime('now', '-10 minutes')");

            // Check for existing lock
            $existing = $this->queryOne("SELECT * FROM scan_locks LIMIT 1");
            if ($existing) {
                return false; // Lock held by another process
            }

            // Acquire lock
            return $this->execute("INSERT INTO scan_locks (started_at, pid) VALUES (datetime('now'), ?)", [getmypid()]);
        } catch (PDOException $e) {
            ErrorHandler::log("acquireScanLock failed: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }

    /**
     * Release the scan lock
     * @return bool True if released successfully
     */
    public function releaseScanLock() {
        if (!$this->pdo) return false;

        try {
            return $this->execute("DELETE FROM scan_locks WHERE pid = ?", [getmypid()]);
        } catch (PDOException $e) {
            ErrorHandler::log("releaseScanLock failed: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }
}
?>
