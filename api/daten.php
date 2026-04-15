<?php
/**
 * Einträge-API
 *
 * GET    /api/daten.php                → alle Einträge
 * GET    /api/daten.php?jahr=2026      → Einträge eines Jahres
 * POST   /api/daten.php                → neuen Eintrag anlegen       (eingeloggt)
 * PUT    /api/daten.php?id=42          → Eintrag aktualisieren       (eingeloggt, wenn gemeldet: nur Admin)
 * DELETE /api/daten.php?id=42          → Eintrag löschen             (nur Admin)
 *
 * Zusätzliche Actions per POST body { action: "...", id: ..., value: ... }:
 *   action=entnommen   → value = "YYYY-MM-DD"    (eingeloggt, wenn gemeldet: nur Admin)
 *   action=abfall      → value = "ja"|"nein"     (eingeloggt, wenn gemeldet: nur Admin)
 *   action=gemeldet    → value = true|false      (nur Admin)
 */

require_once __DIR__ . '/db.php';
ensureSchema();

$method = $_SERVER['REQUEST_METHOD'];
$pdo = db();

/* Felder, die aus einem Eintrag-Body übernommen werden */
$ALLOWED = ['name','wildart','wild','gewicht','ort',
            'koord_x','koord_y','koord_lat','koord_lng',
            'zeit','datum','verwendung','entnommen','abfall','gemeldet','wetter','ist_park','ist_fallwild'];

function fromRequest(array $src, array $allowed): array {
    $out = [];
    foreach ($allowed as $k) {
        if (array_key_exists($k, $src)) {
            $v = $src[$k];
            // Leerstrings für Zahlen/Datum in NULL
            $nullable = ['gewicht','koord_x','koord_y','koord_lat','koord_lng','entnommen','abfall','wetter'];
            if ($v === '' && in_array($k, $nullable, true)) {
                $v = null;
            }
            $out[$k] = $v;
        }
    }
    return $out;
}

function loadEntry(PDO $pdo, int $id): ?array {
    $s = $pdo->prepare('SELECT * FROM eintraege WHERE id = :id');
    $s->execute([':id' => $id]);
    $row = $s->fetch();
    if (!$row) return null;
    // Typen erzwingen, damit JSON garantiert Zahlen statt Strings liefert
    $row['id']           = (int) $row['id'];
    $row['gemeldet']     = (int) $row['gemeldet'];
    $row['ist_park']     = (int) ($row['ist_park'] ?? 0);
    $row['ist_fallwild'] = (int) ($row['ist_fallwild'] ?? 0);
    if ($row['gewicht'] !== null) $row['gewicht'] = (int) $row['gewicht'];
    if ($row['jahr']    !== null) $row['jahr']    = (int) $row['jahr'];
    return $row;
}

function entryIsLocked(array $entry): bool {
    return (int) ($entry['gemeldet'] ?? 0) === 1;
}

/* ================= GET ================= */
if ($method === 'GET') {
    $jahr = isset($_GET['jahr']) ? (int) $_GET['jahr'] : 0;
    if ($jahr > 0) {
        $s = $pdo->prepare('SELECT * FROM eintraege WHERE jahr = :j ORDER BY datum DESC, zeit DESC, id DESC');
        $s->execute([':j' => $jahr]);
    } else {
        $s = $pdo->query('SELECT * FROM eintraege ORDER BY datum DESC, zeit DESC, id DESC');
    }
    $rows = $s->fetchAll();
    // Typen hart setzen (s. loadEntry)
    foreach ($rows as &$r) {
        $r['id']           = (int) $r['id'];
        $r['gemeldet']     = (int) $r['gemeldet'];
        $r['ist_park']     = (int) ($r['ist_park'] ?? 0);
        $r['ist_fallwild'] = (int) ($r['ist_fallwild'] ?? 0);
        if ($r['gewicht'] !== null) $r['gewicht'] = (int) $r['gewicht'];
        if ($r['jahr']    !== null) $r['jahr']    = (int) $r['jahr'];
    }
    unset($r);
    jsonOk($rows);
}

/* ================= POST =================
   Neue Einträge und die Teilaktionen Entnahme/Abfall dürfen alle
   Jäger ohne Login ausführen (wie in der alten App). Nur die
   Aktion 'gemeldet' bleibt Admin-only.
*/
if ($method === 'POST') {
    $in = jsonInput();
    $action = $in['action'] ?? '';

    /* --- Teilaktionen per action-Feld --- */
    if ($action !== '') {
        $id = (int) ($in['id'] ?? 0);
        if ($id <= 0) jsonErr('id fehlt');
        $entry = loadEntry($pdo, $id);
        if (!$entry) jsonErr('Eintrag nicht gefunden', 404);

        if ($action === 'entnommen') {
            if (entryIsLocked($entry) && !isAdmin()) {
                jsonErr('Eintrag ist als gemeldet gesperrt', 403);
            }
            $val = $in['value'] ?? null;
            if ($val !== null && $val !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                jsonErr('Ungültiges Datum');
            }
            $pdo->prepare('UPDATE eintraege SET entnommen = :v WHERE id = :id')
                ->execute([':v' => ($val ?: null), ':id' => $id]);
            jsonOk(loadEntry($pdo, $id));
        }

        if ($action === 'abfall') {
            if (entryIsLocked($entry) && !isAdmin()) {
                jsonErr('Eintrag ist als gemeldet gesperrt', 403);
            }
            $val = $in['value'] ?? null;
            if ($val !== null && !in_array($val, ['ja','nein'], true)) {
                jsonErr('Ungültiger Abfall-Wert');
            }
            $pdo->prepare('UPDATE eintraege SET abfall = :v WHERE id = :id')
                ->execute([':v' => $val, ':id' => $id]);
            jsonOk(loadEntry($pdo, $id));
        }

        if ($action === 'gemeldet') {
            requireAdmin();
            $val = !empty($in['value']) ? 1 : 0;
            $pdo->prepare('UPDATE eintraege SET gemeldet = :v WHERE id = :id')
                ->execute([':v' => $val, ':id' => $id]);
            jsonOk(loadEntry($pdo, $id));
        }

        if ($action === 'gewicht') {
            // Nachträgliches Gewicht setzen - einmalig, solange bisher leer.
            // Ohne Admin-Rechte erlaubt, aber gesperrt wenn gemeldet.
            if (entryIsLocked($entry) && !isAdmin()) {
                jsonErr('Eintrag ist als gemeldet gesperrt', 403);
            }
            $alt = $entry['gewicht'] ?? null;
            if ($alt !== null && $alt !== '' && !isAdmin()) {
                jsonErr('Gewicht ist bereits gesetzt und kann nur vom Admin geändert werden', 403);
            }
            $val = (int) ($in['value'] ?? 0);
            if ($val <= 0 || $val > 500) {
                jsonErr('Ungültiges Gewicht');
            }
            $pdo->prepare('UPDATE eintraege SET gewicht = :v WHERE id = :id')
                ->execute([':v' => $val, ':id' => $id]);
            jsonOk(loadEntry($pdo, $id));
        }

        jsonErr('Unbekannte action');
    }

    /* --- Normaler POST = neuer Eintrag --- */
    $data = fromRequest($in, $ALLOWED);
    if (empty($data['name'])) jsonErr('Name erforderlich');
    if (empty($data['datum'])) jsonErr('Datum erforderlich');
    // Neue Einträge sind per Default nicht gemeldet
    $data['gemeldet'] = !empty($data['gemeldet']) ? 1 : 0;

    $cols = array_keys($data);
    $sql = 'INSERT INTO eintraege (' . implode(',', $cols) . ') VALUES (:' . implode(', :', $cols) . ')';
    $stmt = $pdo->prepare($sql);
    foreach ($data as $k => $v) {
        $stmt->bindValue(':' . $k, $v);
    }
    $stmt->execute();
    $newId = (int) $pdo->lastInsertId();
    jsonOk(loadEntry($pdo, $newId));
}

/* ================= PUT =================
   Der Edit-Dialog in der Oberfläche wird nur Admins gezeigt, also
   setzen wir serverseitig requireAdmin().
*/
if ($method === 'PUT') {
    requireAdmin();
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) jsonErr('id fehlt');
    $entry = loadEntry($pdo, $id);
    if (!$entry) jsonErr('Eintrag nicht gefunden', 404);

    $in = jsonInput();
    $data = fromRequest($in, $ALLOWED);
    if (isset($data['gemeldet'])) {
        $data['gemeldet'] = !empty($data['gemeldet']) ? 1 : 0;
    }
    if (empty($data)) jsonErr('Keine Felder zum Aktualisieren');

    $set = [];
    foreach (array_keys($data) as $k) $set[] = "$k = :$k";
    $sql = 'UPDATE eintraege SET ' . implode(', ', $set) . ' WHERE id = :id';
    $stmt = $pdo->prepare($sql);
    foreach ($data as $k => $v) $stmt->bindValue(':' . $k, $v);
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();

    jsonOk(loadEntry($pdo, $id));
}

/* ================= DELETE ================= */
if ($method === 'DELETE') {
    requireAdmin();
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) jsonErr('id fehlt');
    $pdo->prepare('DELETE FROM eintraege WHERE id = :id')->execute([':id' => $id]);
    jsonOk();
}

jsonErr('Methode nicht erlaubt', 405);
