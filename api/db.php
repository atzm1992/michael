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
          gemeldet     TINYINT(1) NOT NULL DEFAULT 0,
          ist_park     TINYINT(1) NOT NULL DEFAULT 0,
          ist_fallwild TINYINT(1) NOT NULL DEFAULT 0,
          jahr         INT GENERATED ALWAYS AS (YEAR(datum)) STORED,
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
    // Park-Feature: unterscheidet Revier- und Park-Einträge
    try { $pdo->exec("ALTER TABLE eintraege ADD COLUMN ist_park TINYINT(1) NOT NULL DEFAULT 0"); }
    catch (PDOException $e) { /* column existiert bereits */ }
    // Fallwild-Feature: wird NICHT im Abschussplan gezählt
    try { $pdo->exec("ALTER TABLE eintraege ADD COLUMN ist_fallwild TINYINT(1) NOT NULL DEFAULT 0"); }
    catch (PDOException $e) { /* column existiert bereits */ }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS abschussplan (
          jahr        INT NOT NULL,
          kontext     VARCHAR(10) NOT NULL DEFAULT 'revier',
          wildart     VARCHAR(50) NOT NULL,
          klasse      VARCHAR(50) NOT NULL,
          plan_anzahl INT NOT NULL DEFAULT 0,
          extern      INT NOT NULL DEFAULT 0,
          enabled     TINYINT(1) NOT NULL DEFAULT 1,
          matches     VARCHAR(255) NULL,
          sort_order  INT NOT NULL DEFAULT 0,
          PRIMARY KEY (jahr, kontext, wildart, klasse)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Migration: Spalten zu bestehender Tabelle hinzufügen
    try { $pdo->exec("ALTER TABLE abschussplan ADD COLUMN enabled TINYINT(1) NOT NULL DEFAULT 1"); }
    catch (PDOException $e) { /* exists */ }
    try { $pdo->exec("ALTER TABLE abschussplan ADD COLUMN matches VARCHAR(255) NULL"); }
    catch (PDOException $e) { /* exists */ }
    try { $pdo->exec("ALTER TABLE abschussplan ADD COLUMN sort_order INT NOT NULL DEFAULT 0"); }
    catch (PDOException $e) { /* exists */ }
    try { $pdo->exec("ALTER TABLE abschussplan ADD COLUMN kontext VARCHAR(10) NOT NULL DEFAULT 'revier'"); }
    catch (PDOException $e) { /* exists */ }
    try { $pdo->exec("ALTER TABLE abschussplan ADD COLUMN extern INT NOT NULL DEFAULT 0"); }
    catch (PDOException $e) { /* exists */ }
    // Primary Key erweitern um kontext (atomar via MySQL)
    try { $pdo->exec("ALTER TABLE abschussplan DROP PRIMARY KEY, ADD PRIMARY KEY (jahr, kontext, wildart, klasse)"); }
    catch (PDOException $e) { /* PK bereits korrekt */ }
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
          vorname     VARCHAR(100) NOT NULL DEFAULT '',
          nachname    VARCHAR(100) NOT NULL DEFAULT '',
          email       VARCHAR(255) NOT NULL DEFAULT '',
          telefon     VARCHAR(50) NOT NULL DEFAULT '',
          rolle       ENUM('admin','jaeger') NOT NULL DEFAULT 'jaeger',
          recht_revier        TINYINT(1) NOT NULL DEFAULT 0,
          recht_park          TINYINT(1) NOT NULL DEFAULT 0,
          recht_name_sichtbar TINYINT(1) NOT NULL DEFAULT 0,
          recht_name_verbergen TINYINT(1) NOT NULL DEFAULT 0,
          recht_plan_revier   TINYINT(1) NOT NULL DEFAULT 0,
          recht_plan_park     TINYINT(1) NOT NULL DEFAULT 0,
          recht_lesen         TINYINT(1) NOT NULL DEFAULT 0,
          recht_admin         TINYINT(1) NOT NULL DEFAULT 0,
          aktiv       TINYINT(1) NOT NULL DEFAULT 1,
          consent_at  DATETIME NULL,
          consent_ip  VARCHAR(45) NULL,
          created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Migration: neue Spalten zu bestehender users-Tabelle hinzufügen
    $userCols = [
        "ALTER TABLE users ADD COLUMN vorname VARCHAR(100) NOT NULL DEFAULT '' AFTER pass_hash",
        "ALTER TABLE users ADD COLUMN nachname VARCHAR(100) NOT NULL DEFAULT '' AFTER vorname",
        "ALTER TABLE users ADD COLUMN email VARCHAR(255) NOT NULL DEFAULT '' AFTER nachname",
        "ALTER TABLE users ADD COLUMN telefon VARCHAR(50) NOT NULL DEFAULT '' AFTER email",
        "ALTER TABLE users ADD COLUMN recht_revier TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE users ADD COLUMN recht_park TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE users ADD COLUMN recht_name_sichtbar TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE users ADD COLUMN recht_name_verbergen TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE users ADD COLUMN recht_plan_revier TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE users ADD COLUMN recht_plan_park TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE users ADD COLUMN recht_lesen TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE users ADD COLUMN recht_admin TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE users ADD COLUMN aktiv TINYINT(1) NOT NULL DEFAULT 1",
        "ALTER TABLE users ADD COLUMN consent_at DATETIME NULL",
        "ALTER TABLE users ADD COLUMN consent_ip VARCHAR(45) NULL",
    ];
    foreach ($userCols as $sql) {
        try { $pdo->exec($sql); } catch (PDOException $e) { /* exists */ }
    }

    // Migration: user_id in eintraege (verknüpft Eintrag mit Ersteller)
    try { $pdo->exec("ALTER TABLE eintraege ADD COLUMN user_id INT NULL AFTER name"); }
    catch (PDOException $e) { /* exists */ }

    // Self-Heal: Der User mit username='admin' muss IMMER Admin sein.
    // (Falls die Rolle versehentlich durch eine frühere Aktion verloren
    // ging, wird sie hier wiederhergestellt.)
    try {
        $pdo->exec("UPDATE users SET rolle = 'admin', aktiv = 1
          WHERE username = 'admin'");
    } catch (PDOException $e) { /* ignore */ }

    // Self-Heal: Wenn es aktuell keinen aktiven Admin gibt, wird der
    // älteste User zum Admin befördert - damit die App nie "verwaist"
    // ist und niemand mehr administrieren kann.
    try {
        $hasAdmin = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE rolle = 'admin' AND aktiv = 1")->fetchColumn();
        if ($hasAdmin === 0) {
            $pdo->exec("UPDATE users SET rolle = 'admin', aktiv = 1
              WHERE id = (SELECT id FROM (SELECT id FROM users ORDER BY id ASC LIMIT 1) AS x)");
        }
    } catch (PDOException $e) { /* ignore */ }

    // Migration: alle Admin-Accounts (rolle='admin') bekommen IMMER alle
    // Rechte gesetzt. Doppelte Absicherung zur Laufzeit-Logik in
    // loadUserRechte().
    try {
        $pdo->exec("UPDATE users SET
            recht_admin = 1,
            recht_revier = 1,
            recht_park = 1,
            recht_lesen = 1,
            recht_name_sichtbar = 1,
            recht_name_verbergen = 0,
            recht_plan_revier = 1,
            recht_plan_park = 1,
            aktiv = 1
          WHERE rolle = 'admin'");
    } catch (PDOException $e) { /* Spalten evtl. noch nicht da */ }

    // Default-Admin anlegen, falls users leer
    $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($count === 0) {
        $hash = $CONFIG['app_pass_hash'] ?? '';
        if ($hash !== '') {
            $stmt = $pdo->prepare(
                "INSERT INTO users (username, pass_hash, vorname, nachname, rolle, recht_admin, recht_revier, recht_park, recht_lesen, recht_name_sichtbar, aktiv)
                 VALUES ('admin', :h, 'Admin', '', 'admin', 1, 1, 1, 1, 1, 1)"
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
        'vorname'  => $_SESSION['vorname']  ?? '',
        'nachname' => $_SESSION['nachname'] ?? '',
        'rolle'    => $_SESSION['rolle']    ?? 'jaeger',
        'rechte'   => $_SESSION['rechte']   ?? [],
    ];
}

function isAdmin(): bool {
    return ($_SESSION['rolle'] ?? '') === 'admin'
        || !empty($_SESSION['rechte']['admin']);
}

function hasRecht(string $key): bool {
    if (isAdmin()) return true;
    return !empty($_SESSION['rechte'][$key]);
}

function requireLogin(): void {
    checkSessionTimeout();
    if (!currentUser()) {
        jsonErr('Nicht eingeloggt', 401);
    }
}

function requireAdmin(): void {
    requireLogin();
    if (!isAdmin()) {
        jsonErr('Nur für Admin', 403);
    }
}

/**
 * Lädt die Default-Rechte für neu registrierte Benutzer aus der
 * config-Tabelle. Fallback: nur Revier + Namen sichtbar.
 */
function getDefaultRechte(): array {
    $defaults = [
        'revier'         => true,
        'park'           => false,
        'name_sichtbar'  => true,
        'name_verbergen' => false,
        'plan_revier'    => false,
        'plan_park'      => false,
        'lesen'          => false,
    ];
    try {
        $rows = db()->query("SELECT k, v FROM config WHERE k LIKE 'default_recht_%'")->fetchAll();
        foreach ($rows as $row) {
            $key = substr($row['k'], strlen('default_recht_'));
            if (array_key_exists($key, $defaults)) {
                $defaults[$key] = ($row['v'] === '1' || $row['v'] === 'true');
            }
        }
    } catch (Throwable $e) { /* Tabelle fehlt? Defaults bleiben */ }
    return $defaults;
}

/**
 * Session-Idle-Timeout: Wenn seit der letzten Aktivität mehr als
 * SESSION_IDLE_MAX Sekunden vergangen sind, wird die Session
 * invalidiert. Default: 4 Stunden, konfigurierbar per config-key
 * 'session_idle_max' (in Sekunden).
 */
function checkSessionTimeout(): void {
    if (empty($_SESSION['uid'])) return;
    $max = 4 * 3600; // 4h default
    try {
        $row = db()->prepare("SELECT v FROM config WHERE k = 'session_idle_max'");
        $row->execute();
        $v = (int) ($row->fetchColumn() ?: 0);
        if ($v > 0) $max = $v;
    } catch (Throwable $e) { /* ignore */ }

    $now = time();
    $last = (int) ($_SESSION['last_activity'] ?? $now);
    if ($now - $last > $max) {
        // Session abgelaufen
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        jsonErr('Sitzung abgelaufen - bitte neu anmelden', 401);
    }
    $_SESSION['last_activity'] = $now;
}

function loadUserRechte(array $row): array {
    // Admins haben IMMER alle Rechte - unabhängig von den DB-Flags. So
    // kann der Admin sich nicht versehentlich aussperren und hat in Plan,
    // Karte und Listen automatisch Vollzugriff.
    if (($row['rolle'] ?? '') === 'admin') {
        return [
            'revier'          => true,
            'park'            => true,
            'name_sichtbar'   => true,
            'name_verbergen'  => false, // "Verbergen" für Admin sinnlos
            'plan_revier'     => true,
            'plan_park'       => true,
            'lesen'           => true,
            'admin'           => true,
        ];
    }
    return [
        'revier'          => (int) ($row['recht_revier'] ?? 0) === 1,
        'park'            => (int) ($row['recht_park'] ?? 0) === 1,
        'name_sichtbar'   => (int) ($row['recht_name_sichtbar'] ?? 0) === 1,
        'name_verbergen'  => (int) ($row['recht_name_verbergen'] ?? 0) === 1,
        'plan_revier'     => (int) ($row['recht_plan_revier'] ?? 0) === 1,
        'plan_park'       => (int) ($row['recht_plan_park'] ?? 0) === 1,
        'lesen'           => (int) ($row['recht_lesen'] ?? 0) === 1,
        'admin'           => (int) ($row['recht_admin'] ?? 0) === 1,
    ];
}

function setSessionFromRow(array $row): void {
    $_SESSION['uid']      = (int) $row['id'];
    $_SESSION['username'] = $row['username'];
    $_SESSION['vorname']  = $row['vorname'] ?? '';
    $_SESSION['nachname'] = $row['nachname'] ?? '';
    $_SESSION['rolle']    = $row['rolle'];
    $_SESSION['rechte']   = loadUserRechte($row);
}

function userResponseData(array $row): array {
    return [
        'id'       => (int) $row['id'],
        'username' => $row['username'],
        'vorname'  => $row['vorname'] ?? '',
        'nachname' => $row['nachname'] ?? '',
        'rolle'    => $row['rolle'],
        'rechte'   => loadUserRechte($row),
    ];
}
