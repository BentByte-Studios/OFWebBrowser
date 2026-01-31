<?php
require_once __DIR__ . '/error_handler.php';

class OFDatabase {
    private $pdo;
    private $dbPath;

    public function __construct($path) {
        $this->dbPath = $path;
        $this->connect();
    }

    private function connect() {
        if (!file_exists($this->dbPath)) {
            // Database might not exist if only files are present
            return;
        }

        try {
            $this->pdo = new PDO('sqlite:' . $this->dbPath);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            ErrorHandler::log("Database connection failed for {$this->dbPath}: " . $e->getMessage(), 'ERROR');
            // Don't die - allow graceful degradation for source DBs
            $this->pdo = null;
        }
    }

    public function query($sql, $params = []) {
        if (!$this->pdo) return [];

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            // Log the error for debugging
            ErrorHandler::log("Query failed: " . $e->getMessage() . " | SQL: " . $sql, 'WARNING');
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
            ErrorHandler::log("QueryOne failed: " . $e->getMessage() . " | SQL: " . $sql, 'WARNING');
            return null;
        }
    }

    public function isValid() {
        return $this->pdo !== null;
    }
}
