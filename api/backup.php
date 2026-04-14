<?php
/**
 * Backup-API
 *
 * GET  /api/backup.php                → vollständiger JSON-Dump (admin only)
 *      Rückgabe ist direkt die Datei, nicht in {ok,data} verpackt, damit sie
 *      sich als application/json herunterladen lässt.
 *
 * POST /api/backup.php                → Backup einspielen (admin only)
 *      Body: { users?: [...], eintraege: [...], abschussplan: [...], config?: [...] }
 *      Ersetzt die Tabellen komplett. Die users-Tabelle wird NICHT überschrieben,
 *      wenn sie nicht im Backup enthalten ist.
 */

require_once __DIR__ . '/db.php';
ensureSchema();
requireAdmin();

$method = $_SERVER['REQUEST_METHOD'];
$pdo = db();

/* ================= GET ================= */
if ($method === 'GET') {
    // Als Datei-Download ausliefern, nicht in die {ok,data}-Hülle verpacken
    $eintraege = $pdo->query('SELECT * FROM eintraege ORDER BY id')->fetchAll();
    $plan      = $pdo->query('SELECT * FROM abschussplan')->fetchAll();
    $config    = $pdo->query('SELECT * FROM config')->fetchAll();
    // users NICHT dumpen – Passwort-Hashes sollen nicht exportiert werden

    $dump = [
        'app'       => 'jagdrevier-prad',
        'version'   => 1,
        'exported'  => date('c'),
        'eintraege' => $eintraege,
        'abschussplan' => $plan,
        'config'    => $config,
    ];

    // Rohes JSON ohne die ok/data-Hülle, damit es als Datei brauchbar ist
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="jagdrevier-backup-'
        . date('Y-m-d') . '.json"');
    header('Cache-Control: no-store');
    echo json_encode($dump, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/* ================= POST ================= */
if ($method === 'POST') {
    $in = jsonInput();
    if (!isset($in['eintraege']) || !is_array($in['eintraege'])) {
        jsonErr('Ungültiges Backup: eintraege fehlt');
    }

    $pdo->beginTransaction();
    try {
        // Tabellen leeren (users bleibt!)
        $pdo->exec('DELETE FROM eintraege');
        $pdo->exec('DELETE FROM abschussplan');
        if (!empty($in['config']) && is_array($in['config'])) {
            $pdo->exec('DELETE FROM config');
        }

        // Einträge wieder einspielen
        $cols = ['id','name','wildart','wild','gewicht','ort',
                 'koord_x','koord_y','koord_lat','koord_lng','wetter',
                 'zeit','datum','verwendung','entnommen','abfall','gemeldet','ist_park'];
        $sql  = 'INSERT INTO eintraege (' . implode(',', $cols) . ') VALUES (:'
              . implode(', :', $cols) . ')';
        $stmt = $pdo->prepare($sql);
        foreach ($in['eintraege'] as $row) {
            foreach ($cols as $c) {
                $stmt->bindValue(':' . $c, $row[$c] ?? null);
            }
            $stmt->execute();
        }

        // Plan wieder einspielen
        if (!empty($in['abschussplan']) && is_array($in['abschussplan'])) {
            $pCols = ['jahr','kontext','wildart','klasse','plan_anzahl','enabled','matches','sort_order'];
            $pSql  = 'INSERT INTO abschussplan (' . implode(',', $pCols) . ') VALUES (:'
                   . implode(', :', $pCols) . ')';
            $pStmt = $pdo->prepare($pSql);
            foreach ($in['abschussplan'] as $row) {
                // Fallback für alte Backups ohne kontext -> 'revier'
                if (!isset($row['kontext'])) $row['kontext'] = 'revier';
                foreach ($pCols as $c) {
                    $pStmt->bindValue(':' . $c, $row[$c] ?? (($c === 'enabled' || $c === 'sort_order') ? 0 : null));
                }
                $pStmt->execute();
            }
        }

        // Config wieder einspielen
        if (!empty($in['config']) && is_array($in['config'])) {
            $cStmt = $pdo->prepare('INSERT INTO config (k, v) VALUES (:k, :v)');
            foreach ($in['config'] as $row) {
                $cStmt->execute([':k' => $row['k'] ?? '', ':v' => $row['v'] ?? '']);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        jsonErr('Restore fehlgeschlagen: ' . $e->getMessage(), 500);
    }

    jsonOk([
        'eintraege' => count($in['eintraege']),
        'plan'      => isset($in['abschussplan']) ? count($in['abschussplan']) : 0,
    ]);
}

jsonErr('Methode nicht erlaubt', 405);
