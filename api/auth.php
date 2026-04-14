<?php
/**
 * Auth-Endpoint
 *
 * POST /api/auth.php            JSON { action: "login", username, password }
 * POST /api/auth.php            JSON { action: "logout" }
 * GET  /api/auth.php            → aktuelle Session-Info ({ logged_in, username, rolle })
 * POST /api/auth.php            JSON { action: "change_pw", old, new }  (eingeloggt)
 */

require_once __DIR__ . '/db.php';
ensureSchema();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* GET: Status abfragen */
if ($method === 'GET') {
    $u = currentUser();
    jsonOk([
        'logged_in' => $u !== null,
        'username'  => $u['username'] ?? null,
        'rolle'     => $u['rolle']    ?? null,
    ]);
}

if ($method !== 'POST') {
    jsonErr('Methode nicht erlaubt', 405);
}

$in = jsonInput();
$action = $in['action'] ?? '';

if ($action === 'login') {
    $username = trim((string) ($in['username'] ?? ''));
    $password = (string) ($in['password'] ?? '');
    if ($username === '' || $password === '') {
        jsonErr('Benutzer und Passwort erforderlich');
    }
    // Leichtes Rate-Limiting gegen Brute Force
    if (($_SESSION['login_fail'] ?? 0) >= 10) {
        jsonErr('Zu viele Fehlversuche – bitte später erneut versuchen.', 429);
    }
    $stmt = db()->prepare('SELECT id, username, pass_hash, rolle FROM users WHERE username = :u LIMIT 1');
    $stmt->execute([':u' => $username]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($password, $row['pass_hash'])) {
        $_SESSION['login_fail'] = ($_SESSION['login_fail'] ?? 0) + 1;
        jsonErr('Falsche Zugangsdaten', 401);
    }

    // Login erfolgreich: Session regenerieren, Daten setzen
    session_regenerate_id(true);
    $_SESSION['uid']        = (int) $row['id'];
    $_SESSION['username']   = $row['username'];
    $_SESSION['rolle']      = $row['rolle'];
    $_SESSION['login_fail'] = 0;

    // Optional: Hash-Rehash bei schwächerem Algorithmus
    if (password_needs_rehash($row['pass_hash'], PASSWORD_DEFAULT)) {
        $new = password_hash($password, PASSWORD_DEFAULT);
        db()->prepare('UPDATE users SET pass_hash = :h WHERE id = :id')
            ->execute([':h' => $new, ':id' => $row['id']]);
    }

    jsonOk([
        'username' => $row['username'],
        'rolle'    => $row['rolle'],
    ]);
}

if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    jsonOk();
}

if ($action === 'change_pw') {
    requireLogin();
    $old = (string) ($in['old'] ?? '');
    $new = (string) ($in['new'] ?? '');
    if (strlen($new) < 4) {
        jsonErr('Neues Passwort muss mindestens 4 Zeichen lang sein');
    }
    $stmt = db()->prepare('SELECT pass_hash FROM users WHERE id = :id');
    $stmt->execute([':id' => $_SESSION['uid']]);
    $hash = $stmt->fetchColumn();
    if (!$hash || !password_verify($old, $hash)) {
        jsonErr('Altes Passwort falsch', 403);
    }
    $newHash = password_hash($new, PASSWORD_DEFAULT);
    db()->prepare('UPDATE users SET pass_hash = :h WHERE id = :id')
        ->execute([':h' => $newHash, ':id' => $_SESSION['uid']]);
    jsonOk();
}

jsonErr('Unbekannte Action');
