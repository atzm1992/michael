<?php
/**
 * Abschussplan-API
 *
 * GET  /api/plan.php?jahr=2026          → Plan für ein Jahr
 *                                         Rückgabe-Format wie im Frontend erwartet:
 *                                         { "Rehwild": {"T-Bock": 5, ...}, ... }
 * POST /api/plan.php                    → Plan für ein Jahr speichern  (Admin)
 *                                         Body: { jahr: 2026, plan: { "Rehwild": {...}, ... } }
 */

require_once __DIR__ . '/db.php';
ensureSchema();

$method = $_SERVER['REQUEST_METHOD'];
$pdo = db();

$DEFAULT_KLASSEN = [
    'Rehwild'  => ['T-Bock','J-Bock','Geiß','Schmalgeiß','Kitz'],
    'Rotwild'  => ['T-Hirsch','J-Hirsch','Tier','Schmaltier','Kalb'],
    'Gamswild' => ['Bock','Geiß','Jährling','Kitz'],
];

function emptyPlan(array $defaults): array {
    $out = [];
    foreach ($defaults as $art => $klassen) {
        $out[$art] = [];
        foreach ($klassen as $k) $out[$art][$k] = 0;
    }
    return $out;
}

/* ================= GET ================= */
if ($method === 'GET') {
    $jahr = (int) ($_GET['jahr'] ?? 0);
    if ($jahr <= 0) jsonErr('jahr fehlt');

    $result = emptyPlan($DEFAULT_KLASSEN);
    $stmt = $pdo->prepare('SELECT wildart, klasse, plan_anzahl FROM abschussplan WHERE jahr = :j');
    $stmt->execute([':j' => $jahr]);
    foreach ($stmt->fetchAll() as $row) {
        $art = $row['wildart'];
        $kls = $row['klasse'];
        if (!isset($result[$art])) $result[$art] = [];
        $result[$art][$kls] = (int) $row['plan_anzahl'];
    }
    jsonOk(['jahr' => $jahr, 'plan' => $result]);
}

/* ================= POST ================= */
if ($method === 'POST') {
    requireAdmin();
    $in = jsonInput();
    $jahr = (int) ($in['jahr'] ?? 0);
    $plan = $in['plan'] ?? null;
    if ($jahr <= 0)          jsonErr('jahr fehlt');
    if (!is_array($plan))    jsonErr('plan fehlt');

    $pdo->beginTransaction();
    try {
        // Alten Plan des Jahres löschen und frisch schreiben
        $pdo->prepare('DELETE FROM abschussplan WHERE jahr = :j')->execute([':j' => $jahr]);
        $ins = $pdo->prepare(
            'INSERT INTO abschussplan (jahr, wildart, klasse, plan_anzahl) VALUES (:j, :w, :k, :n)'
        );
        foreach ($plan as $art => $klassen) {
            if (!is_array($klassen)) continue;
            foreach ($klassen as $kls => $anzahl) {
                $ins->execute([
                    ':j' => $jahr,
                    ':w' => (string) $art,
                    ':k' => (string) $kls,
                    ':n' => (int) $anzahl,
                ]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        jsonErr('Speichern fehlgeschlagen', 500);
    }
    jsonOk();
}

jsonErr('Methode nicht erlaubt', 405);
