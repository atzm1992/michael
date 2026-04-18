<?php
/**
 * Auth-Endpoint
 *
 * GET  /api/auth.php                               → aktuelle Session-Info
 * POST /api/auth.php  { action: "login", ... }     → Login
 * POST /api/auth.php  { action: "logout" }          → Logout
 * POST /api/auth.php  { action: "register", ... }   → Registrierung
 * POST /api/auth.php  { action: "change_pw", ... }  → Passwort ändern (eingeloggt)
 */

require_once __DIR__ . '/db.php';
ensureSchema();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* GET: Status abfragen */
if ($method === 'GET') {
    $u = currentUser();
    if (!$u) {
        jsonOk(['logged_in' => false]);
    }
    // Frische Daten aus DB laden (Rechte könnten sich geändert haben)
    $stmt = db()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $u['id']]);
    $fresh = $stmt->fetch();
    if (!$fresh || !(int)($fresh['aktiv'] ?? 1)) {
        // User gelöscht oder deaktiviert
        $_SESSION = [];
        jsonOk(['logged_in' => false]);
    }
    setSessionFromRow($fresh);
    $data = userResponseData($fresh);
    $data['logged_in'] = true;
    jsonOk($data);
}

if ($method !== 'POST') {
    jsonErr('Methode nicht erlaubt', 405);
}

$in = jsonInput();
$action = $in['action'] ?? '';

/* ================= LOGIN ================= */
if ($action === 'login') {
    $username = trim((string) ($in['username'] ?? ''));
    $password = (string) ($in['password'] ?? '');
    if ($username === '' || $password === '') {
        jsonErr('Benutzer und Passwort erforderlich');
    }
    if (($_SESSION['login_fail'] ?? 0) >= 10) {
        jsonErr('Zu viele Fehlversuche – bitte später erneut versuchen.', 429);
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE username = :u LIMIT 1');
    $stmt->execute([':u' => $username]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($password, $row['pass_hash'])) {
        $_SESSION['login_fail'] = ($_SESSION['login_fail'] ?? 0) + 1;
        jsonErr('Falsche Zugangsdaten', 401);
    }

    if (!(int)($row['aktiv'] ?? 1)) {
        jsonErr('Konto ist deaktiviert. Bitte den Admin kontaktieren.', 403);
    }

    session_regenerate_id(true);
    setSessionFromRow($row);
    $_SESSION['login_fail'] = 0;

    if (password_needs_rehash($row['pass_hash'], PASSWORD_DEFAULT)) {
        $new = password_hash($password, PASSWORD_DEFAULT);
        db()->prepare('UPDATE users SET pass_hash = :h WHERE id = :id')
            ->execute([':h' => $new, ':id' => $row['id']]);
    }

    $data = userResponseData($row);
    $data['logged_in'] = true;
    jsonOk($data);
}

/* ================= LOGOUT ================= */
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

/* ================= REGISTER ================= */
if ($action === 'register') {
    $username = trim((string) ($in['username'] ?? ''));
    $password = (string) ($in['password'] ?? '');
    $vorname  = trim((string) ($in['vorname'] ?? ''));
    $nachname = trim((string) ($in['nachname'] ?? ''));
    $email    = trim((string) ($in['email'] ?? ''));
    $telefon  = trim((string) ($in['telefon'] ?? ''));
    $consent  = !empty($in['consent']);

    if ($username === '') jsonErr('Benutzername erforderlich');
    if (strlen($username) < 3) jsonErr('Benutzername muss mindestens 3 Zeichen lang sein');
    if (!preg_match('/^[a-zA-Z0-9._-]+$/', $username)) {
        jsonErr('Benutzername darf nur Buchstaben, Zahlen, Punkt, Bindestrich und Unterstrich enthalten');
    }
    // Reservierte Usernames schützen
    $reserved = ['admin', 'administrator', 'root', 'system'];
    if (in_array(strtolower($username), $reserved, true)) {
        jsonErr('Dieser Benutzername ist reserviert');
    }
    if ($password === '') jsonErr('Passwort erforderlich');
    if (strlen($password) < 6) jsonErr('Passwort muss mindestens 6 Zeichen lang sein');
    if ($vorname === '') jsonErr('Vorname erforderlich');
    if ($nachname === '') jsonErr('Nachname erforderlich');
    if ($email === '') jsonErr('E-Mail erforderlich');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonErr('Ungültige E-Mail-Adresse');
    if ($telefon === '') jsonErr('Telefon erforderlich');
    if (!preg_match('/^[0-9 +()\/\-]{5,}$/', $telefon)) jsonErr('Ungültige Telefonnummer');
    if (!$consent) jsonErr('Zustimmung zur Datenschutzerklärung erforderlich');

    // Prüfen ob Username schon vergeben
    $check = db()->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
    $check->execute([':u' => $username]);
    if ($check->fetch()) {
        jsonErr('Benutzername ist bereits vergeben');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if (str_contains($ip, ',')) $ip = trim(explode(',', $ip)[0]);
    $ip = substr($ip, 0, 45);

    // Default-Rechte aus Config-Tabelle laden (Admin kann diese einstellen)
    $defaults = getDefaultRechte();

    $stmt = db()->prepare(
        'INSERT INTO users (username, pass_hash, vorname, nachname, email, telefon, rolle,
         recht_revier, recht_park, recht_name_sichtbar, recht_name_verbergen,
         recht_plan_revier, recht_plan_park, recht_lesen,
         aktiv, consent_at, consent_ip)
         VALUES (:u, :h, :vn, :nn, :em, :tel, :rolle,
         :r_rev, :r_park, :r_ns, :r_nv, :r_plr, :r_plp, :r_les,
         1, NOW(), :ip)'
    );
    $stmt->execute([
        ':u'     => $username,
        ':h'     => $hash,
        ':vn'    => $vorname,
        ':nn'    => $nachname,
        ':em'    => $email,
        ':tel'   => $telefon,
        ':rolle' => 'jaeger',
        ':r_rev' => $defaults['revier'] ? 1 : 0,
        ':r_park'=> $defaults['park'] ? 1 : 0,
        ':r_ns'  => $defaults['name_sichtbar'] ? 1 : 0,
        ':r_nv'  => $defaults['name_verbergen'] ? 1 : 0,
        ':r_plr' => $defaults['plan_revier'] ? 1 : 0,
        ':r_plp' => $defaults['plan_park'] ? 1 : 0,
        ':r_les' => $defaults['lesen'] ? 1 : 0,
        ':ip'    => $ip,
    ]);
    $newId = (int) db()->lastInsertId();

    // Direkt einloggen
    $row = db()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $row->execute([':id' => $newId]);
    $newUser = $row->fetch();

    session_regenerate_id(true);
    setSessionFromRow($newUser);
    $_SESSION['login_fail'] = 0;

    $data = userResponseData($newUser);
    $data['logged_in'] = true;
    jsonOk($data);
}

/* ================= CHANGE PASSWORD ================= */
if ($action === 'change_pw') {
    requireLogin();
    $old = (string) ($in['old'] ?? '');
    $new = (string) ($in['new'] ?? '');
    if (strlen($new) < 6) {
        jsonErr('Neues Passwort muss mindestens 6 Zeichen lang sein');
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
