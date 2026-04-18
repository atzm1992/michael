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
            'id'   => (int) $r['id'],
            'name' => $full ?: $r['username'],
        ];
    }
    jsonOk($names);
}

/* ================= update: Rechte/Profil ändern ================= */
if ($action === 'update') {
    requireAdmin();
    $id = (int) ($in['id'] ?? 0);
    if ($id <= 0) jsonErr('id fehlt');

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
