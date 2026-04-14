<?php
/**
 * Gemeinsamer DB- und Utility-Helper für alle API-Endpoints.
 *
 * - Lädt api/config.php (wird beim Deploy aus den GitHub-Secrets erzeugt).
 * - Liefert eine PDO-Verbindung.
 * - Stellt JSON-Response-Helfer zur Verfügung.
 * - Startet Session mit sicheren Cookie-Einstellungen.
 * - Erstellt Tabellen beim allerersten Aufruf automatisch (idempotent).
 * - Legt den ersten Admin-User an, wenn die users-Tabelle leer ist.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate');

/* ---------- Konfiguration laden ---------- */

$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'config.php fehlt – Deploy nicht vollständig']);
    exit;
}
$CONFIG = require $configPath;

/* ---------- Session starten (vor jeder Ausgabe) ---------- */

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,                 // Cookie bis zum Browser-Schließen
        'path'     => '/',
        'domain'   => '',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('JAGDSID');
    session_start();
}

/* ---------- PDO-Verbindung ---------- */

function db(): PDO {
    static $pdo = null;
    global $CONFIG;
    if ($pdo === null) {
        // Sanity-Check: fehlt ein Wert völlig, melden wir das klar.
        foreach (['db_host','db_name','db_user','db_pass'] as $k) {
            if (empty($CONFIG[$k])) {
                http_response_code(500);
                echo json_encode([
                    'ok' => false,
                    'error' => "Config-Feld '$k' ist leer – GitHub-Secret prüfen.",
                ]);
                exit;
            }
        }
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $CONFIG['db_host'],
            $CONFIG['db_name']
        );
        try {
            $pdoOpts = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            // Seit PHP 8.1: integers NICHT als Strings zurückgeben, damit
            // JSON-Antworten "id": 42 (Zahl) liefern, nicht "id": "42".
            if (defined('PDO::ATTR_STRINGIFY_FETCHES')) {
                $pdoOpts[PDO::ATTR_STRINGIFY_FETCHES] = false;
            }
            $pdo = new PDO($dsn, $CONFIG['db_user'], $CONFIG['db_pass'], $pdoOpts);
        } catch (PDOException $ex) {
            // Der Fehler wird absichtlich NICHT an den Browser zurückgegeben.
            // Für Debug: in PHP-Error-Log schreiben und einen generischen
            // Fehler liefern.
            error_log('[jagdrevier] DB connect failed: ' . $ex->getMessage());
            http_response_code(500);
            echo json_encode([
                'ok'    => false,
                'error' => 'DB-Verbindung fehlgeschlagen',
            ]);
            exit;
        }
    }
    return $pdo;
}

/* ---------- Tabellen und Default-Admin einmalig anlegen ---------- */

function ensureSchema(): void {
    global $CONFIG;
    $pdo = db();

    // Die CREATE-Statements sind idempotent (IF NOT EXISTS).
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS eintraege (
          id          INT AUTO_INCREMENT PRIMARY KEY,
          name        VARCHAR(100) NOT NULL,
          wildart     VARCHAR(50),
          wild        VARCHAR(50),
          gewicht     INT,
          ort         VARCHAR(100),
          koord_x     DECIMAL(8,6) NULL,
          koord_y     DECIMAL(8,6) NULL,
          koord_lat   DECIMAL(10,7) NULL,
          koord_lng   DECIMAL(10,7) NULL,
          zeit        VARCHAR(5),
          datum       DATE,
          verwendung  VARCHAR(30),
          entnommen   DATE NULL,
          abfall      VARCHAR(10) NULL,
          gemeldet    TINYINT(1) NOT NULL DEFAULT 0,
          jahr        INT GENERATED ALWAYS AS (YEAR(datum)) STORED,
          created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX idx_jahr (jahr),
          INDEX idx_gemeldet (gemeldet)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Migration: Spalten nachträglich hinzufügen (idempotent per try/catch).
    try { $pdo->exec("ALTER TABLE eintraege ADD COLUMN koord_lat DECIMAL(10,7) NULL AFTER koord_y"); }
    catch (PDOException $e) { /* column existiert bereits */ }
    try { $pdo->exec("ALTER TABLE eintraege ADD COLUMN koord_lng DECIMAL(10,7) NULL AFTER koord_lat"); }
    catch (PDOException $e) { /* column existiert bereits */ }
    try { $pdo->exec("ALTER TABLE eintraege ADD COLUMN wetter VARCHAR(120) NULL AFTER koord_lng"); }
    catch (PDOException $e) { /* column existiert bereits */ }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS abschussplan (
          jahr        INT NOT NULL,
          wildart     VARCHAR(50) NOT NULL,
          klasse      VARCHAR(50) NOT NULL,
          plan_anzahl INT NOT NULL DEFAULT 0,
          enabled     TINYINT(1) NOT NULL DEFAULT 1,
          matches     VARCHAR(255) NULL,
          sort_order  INT NOT NULL DEFAULT 0,
          PRIMARY KEY (jahr, wildart, klasse)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Migration: Spalten zu bestehender Tabelle hinzufügen
    try { $pdo->exec("ALTER TABLE abschussplan ADD COLUMN enabled TINYINT(1) NOT NULL DEFAULT 1"); }
    catch (PDOException $e) { /* exists */ }
    try { $pdo->exec("ALTER TABLE abschussplan ADD COLUMN matches VARCHAR(255) NULL"); }
    catch (PDOException $e) { /* exists */ }
    try { $pdo->exec("ALTER TABLE abschussplan ADD COLUMN sort_order INT NOT NULL DEFAULT 0"); }
    catch (PDOException $e) { /* exists */ }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS config (
          k VARCHAR(50) PRIMARY KEY,
          v TEXT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
          id          INT AUTO_INCREMENT PRIMARY KEY,
          username    VARCHAR(50) UNIQUE NOT NULL,
          pass_hash   VARCHAR(255) NOT NULL,
          rolle       ENUM('admin','jaeger') NOT NULL DEFAULT 'jaeger',
          created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Default-Admin anlegen, falls users leer
    $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($count === 0) {
        $hash = $CONFIG['app_pass_hash'] ?? '';
        if ($hash !== '') {
            $stmt = $pdo->prepare(
                "INSERT INTO users (username, pass_hash, rolle) VALUES ('admin', :h, 'admin')"
            );
            $stmt->execute([':h' => $hash]);
        }
    }
}

/* ---------- Response-Helfer ---------- */

function jsonOk($data = null): void {
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonErr(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonInput(): array {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/* ---------- Auth-Helfer ---------- */

function currentUser(): ?array {
    if (empty($_SESSION['uid'])) {
        return null;
    }
    return [
        'id'       => $_SESSION['uid'],
        'username' => $_SESSION['username'] ?? '',
        'rolle'    => $_SESSION['rolle']    ?? 'jaeger',
    ];
}

function isAdmin(): bool {
    return ($_SESSION['rolle'] ?? '') === 'admin';
}

function requireLogin(): void {
    if (!currentUser()) {
        jsonErr('Nicht eingeloggt', 401);
    }
}

function requireAdmin(): void {
    requireLogin();
    if (($_SESSION['rolle'] ?? '') !== 'admin') {
        jsonErr('Nur für Admin', 403);
    }
}
