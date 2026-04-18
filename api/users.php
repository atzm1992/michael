<?php
/**
 * User-Management-API (Admin only)
 *
 * GET    /api/users.php              → alle Benutzer (ohne Passwort-Hashes)
 * POST   /api/users.php              → Benutzer aktualisieren (Rechte, Profil, Passwort-Reset)
 *        { action: "update", id, rechte: {...}, vorname, nachname, email, telefon, aktiv }
 *        { action: "reset_pw", id, password }
 *        { action: "delete", id }
 *        { action: "list_names" }     → alle aktiven Benutzernamen (für Admin-Dropdown)
 */

require_once __DIR__ . '/db.php';
ensureSchema();

$method = $_SERVER['REQUEST_METHOD'];
$pdo = db();

/* ================= GET: Liste aller Benutzer ================= */
if ($method === 'GET') {
    requireAdmin();
    $rows = $pdo->query('SELECT * FROM users ORDER BY nachname, vorname, username')->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'       => (int) $r['id'],
            'username' => $r['username'],
            'vorname'  => $r['vorname'] ?? '',
            'nachname' => $r['nachname'] ?? '',
            'email'    => $r['email'] ?? '',
            'telefon'  => $r['telefon'] ?? '',
            'rolle'    => $r['rolle'],
            'aktiv'    => (int) ($r['aktiv'] ?? 1),
            'rechte'   => loadUserRechte($r),
            'created_at' => $r['created_at'] ?? '',
        ];
    }
    jsonOk($out);
}

if ($method !== 'POST') {
    jsonErr('Methode nicht erlaubt', 405);
}

$in = jsonInput();
$action = $in['action'] ?? '';

/* ================= list_names: aktive Benutzer-Namen (für Dropdown) ================= */
if ($action === 'list_names') {
    requireLogin();
    $rows = $pdo->query(
        "SELECT id, vorname, nachname, username FROM users WHERE aktiv = 1 ORDER BY nachname, vorname"
    )->fetchAll();
    $names = [];
    foreach ($rows as $r) {
        $full = trim(($r['vorname'] ?? '') . ' ' . ($r['nachname'] ?? ''));
        $names[] = [
            'id'       => (int) $r['id'],
            'vorname'  => $r['vorname'] ?? '',
            'nachname' => $r['nachname'] ?? '',
            'username' => $r['username'] ?? '',
            'name'     => $full ?: $r['username'],
        ];
    }
    jsonOk($names);
}

/* ================= create: Neuen Benutzer anlegen (Admin) ================= */
if ($action === 'create') {
    requireAdmin();
    $username = trim((string) ($in['username'] ?? ''));
    $password = (string) ($in['password'] ?? '');
    $vorname  = trim((string) ($in['vorname'] ?? ''));
    $nachname = trim((string) ($in['nachname'] ?? ''));
    $email    = trim((string) ($in['email'] ?? ''));
    $telefon  = trim((string) ($in['telefon'] ?? ''));
    $rechte   = is_array($in['rechte'] ?? null) ? $in['rechte'] : [];

    if ($username === '' || strlen($username) < 3) jsonErr('Benutzername (min. 3 Zeichen) erforderlich');
    if (!preg_match('/^[a-zA-Z0-9._-]+$/', $username)) jsonErr('Ungültiger Benutzername');
    $reserved = ['admin', 'administrator', 'root', 'system'];
    if (in_array(strtolower($username), $reserved, true)) {
        // Ausnahme: Wenn noch kein Admin-Account existiert, darf "admin" angelegt werden
        $hasAdmin = (int) db()->query("SELECT COUNT(*) FROM users WHERE username = 'admin'")->fetchColumn();
        if ($hasAdmin > 0 || strtolower($username) !== 'admin') {
            jsonErr('Dieser Benutzername ist reserviert');
        }
    }
    if (strlen($password) < 6) jsonErr('Passwort mind. 6 Zeichen');
    if ($vorname === '' || $nachname === '') jsonErr('Vor- und Nachname erforderlich');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonErr('Ungültige E-Mail-Adresse');
    if ($telefon === '') jsonErr('Telefon erforderlich');

    // Username-Kollision prüfen
    $chk = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
    $chk->execute([':u' => $username]);
    if ($chk->fetch()) jsonErr('Benutzername bereits vergeben');

    $isAdminFlag = !empty($rechte['admin']);
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if (str_contains($ip, ',')) $ip = trim(explode(',', $ip)[0]);
    $ip = substr($ip, 0, 45);

    $sql = 'INSERT INTO users
        (username, pass_hash, vorname, nachname, email, telefon, rolle, aktiv,
         recht_revier, recht_park, recht_name_sichtbar, recht_name_verbergen,
         recht_plan_revier, recht_plan_park, recht_lesen, recht_admin,
         consent_at, consent_ip)
        VALUES (:u, :h, :vn, :nn, :em, :tel, :rolle, 1,
                :r_rev, :r_park, :r_ns, :r_nv, :r_plr, :r_plp, :r_les, :r_adm,
                NOW(), :ip)';
    $pdo->prepare($sql)->execute([
        ':u'     => $username,
        ':h'     => $hash,
        ':vn'    => $vorname,
        ':nn'    => $nachname,
        ':em'    => $email,
        ':tel'   => $telefon,
        ':rolle' => $isAdminFlag ? 'admin' : 'jaeger',
        ':r_rev' => !empty($rechte['revier']) ? 1 : 0,
        ':r_park'=> !empty($rechte['park']) ? 1 : 0,
        ':r_ns'  => !empty($rechte['name_sichtbar']) ? 1 : 0,
        ':r_nv'  => !empty($rechte['name_verbergen']) ? 1 : 0,
        ':r_plr' => !empty($rechte['plan_revier']) ? 1 : 0,
        ':r_plp' => !empty($rechte['plan_park']) ? 1 : 0,
        ':r_les' => !empty($rechte['lesen']) ? 1 : 0,
        ':r_adm' => $isAdminFlag ? 1 : 0,
        ':ip'    => $ip,
    ]);
    $newId = (int) $pdo->lastInsertId();
    $row = $pdo->prepare('SELECT * FROM users WHERE id = :id');
    $row->execute([':id' => $newId]);
    $u = $row->fetch();
    jsonOk([
        'id'       => $newId,
        'username' => $u['username'],
        'vorname'  => $u['vorname'] ?? '',
        'nachname' => $u['nachname'] ?? '',
        'email'    => $u['email'] ?? '',
        'telefon'  => $u['telefon'] ?? '',
        'rolle'    => $u['rolle'],
        'aktiv'    => 1,
        'rechte'   => loadUserRechte($u),
    ]);
}

/* ================= update: Rechte/Profil ändern ================= */
if ($action === 'update') {
    requireAdmin();
    $id = (int) ($in['id'] ?? 0);
    if ($id <= 0) jsonErr('id fehlt');

    // Schutz: Admin darf weder eigene Rechte ändern noch sich selbst
    // deaktivieren - sonst könnte er sich selbst aussperren.
    $selfId = (int) ($_SESSION['uid'] ?? 0);
    if ($id === $selfId) {
        if (isset($in['rechte']) || array_key_exists('aktiv', $in)) {
            jsonErr('Eigene Rechte und Status können nicht geändert werden. Bitte einen anderen Admin damit beauftragen.', 403);
        }
    }

    $user = $pdo->prepare('SELECT * FROM users WHERE id = :id');
    $user->execute([':id' => $id]);
    $user = $user->fetch();
    if (!$user) jsonErr('Benutzer nicht gefunden', 404);

    $sets = [];
    $params = [':id' => $id];

    // Profil-Felder
    foreach (['vorname', 'nachname', 'email', 'telefon'] as $f) {
        if (isset($in[$f])) {
            $sets[] = "$f = :$f";
            $params[":$f"] = trim((string) $in[$f]);
        }
    }

    // Aktiv-Status
    if (isset($in['aktiv'])) {
        $sets[] = 'aktiv = :aktiv';
        $params[':aktiv'] = !empty($in['aktiv']) ? 1 : 0;
    }

    // Rechte
    if (isset($in['rechte']) && is_array($in['rechte'])) {
        $rechteFields = [
            'revier', 'park', 'name_sichtbar', 'name_verbergen',
            'plan_revier', 'plan_park', 'lesen', 'admin'
        ];
        foreach ($rechteFields as $r) {
            if (isset($in['rechte'][$r])) {
                $col = "recht_$r";
                $sets[] = "$col = :$col";
                $params[":$col"] = !empty($in['rechte'][$r]) ? 1 : 0;
            }
        }
        // Admin-Recht setzt auch rolle
        if (isset($in['rechte']['admin'])) {
            $newRolle = !empty($in['rechte']['admin']) ? 'admin' : 'jaeger';
            $sets[] = 'rolle = :rolle';
            $params[':rolle'] = $newRolle;
        }
    }

    if (empty($sets)) jsonErr('Keine Felder zum Aktualisieren');

    $sql = 'UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = :id';
    $pdo->prepare($sql)->execute($params);

    // Aktualisierte Daten zurückgeben
    $fresh = $pdo->prepare('SELECT * FROM users WHERE id = :id');
    $fresh->execute([':id' => $id]);
    $row = $fresh->fetch();
    jsonOk([
        'id'       => (int) $row['id'],
        'username' => $row['username'],
        'vorname'  => $row['vorname'] ?? '',
        'nachname' => $row['nachname'] ?? '',
        'email'    => $row['email'] ?? '',
        'telefon'  => $row['telefon'] ?? '',
        'rolle'    => $row['rolle'],
        'aktiv'    => (int) ($row['aktiv'] ?? 1),
        'rechte'   => loadUserRechte($row),
    ]);
}

/* ================= reset_pw: Passwort zurücksetzen ================= */
if ($action === 'reset_pw') {
    requireAdmin();
    $id  = (int) ($in['id'] ?? 0);
    $pw  = (string) ($in['password'] ?? '');
    if ($id <= 0) jsonErr('id fehlt');
    if (strlen($pw) < 6) jsonErr('Passwort muss mindestens 6 Zeichen lang sein');

    $hash = password_hash($pw, PASSWORD_DEFAULT);
    $pdo->prepare('UPDATE users SET pass_hash = :h WHERE id = :id')
        ->execute([':h' => $hash, ':id' => $id]);
    jsonOk();
}

/* ================= delete: Benutzer löschen ================= */
if ($action === 'delete') {
    requireAdmin();
    $id = (int) ($in['id'] ?? 0);
    if ($id <= 0) jsonErr('id fehlt');
    // Eigenen Account nicht löschen
    if ($id === (int) ($_SESSION['uid'] ?? 0)) {
        jsonErr('Du kannst deinen eigenen Account nicht löschen');
    }
    $pdo->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $id]);
    jsonOk();
}

jsonErr('Unbekannte Action');
