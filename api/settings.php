<?php
/**
 * Config-API
 *
 * GET  /api/config.php          → liefert alle Config-Einträge als Map.
 *                                 Öffentlich lesbar (damit normale Nutzer
 *                                 Reviername, Sichtbarkeit etc. mitbekommen).
 * POST /api/config.php          → speichert mehrere Config-Einträge.
 *                                 Body: { values: { key1: val1, key2: val2, ... } }
 *                                 Nur Admin.
 */

require_once __DIR__ . '/db.php';
ensureSchema();

$method = $_SERVER['REQUEST_METHOD'];
$pdo = db();

if ($method === 'GET') {
    $rows = $pdo->query('SELECT k, v FROM config')->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[$r['k']] = $r['v'];
    }
    jsonOk($out);
}

if ($method === 'POST') {
    requireAdmin();
    $in = jsonInput();
    $values = $in['values'] ?? null;
    if (!is_array($values)) jsonErr('values fehlt oder ungültig');

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO config (k, v) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE v = VALUES(v)'
        );
        foreach ($values as $k => $v) {
            $stmt->execute([
                ':k' => (string) $k,
                ':v' => $v === null ? null : (string) $v,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        jsonErr('Speichern fehlgeschlagen: ' . $e->getMessage(), 500);
    }
    jsonOk();
}

jsonErr('Methode nicht erlaubt', 405);
