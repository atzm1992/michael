<?php
/**
 * Abschussplan-API
 *
 * Datenmodell pro Klassenzeile:
 *   {
 *     plan:    int,       // Sollzahl
 *     enabled: bool,      // ob die Zeile angezeigt/gezählt wird
 *     matches: string     // komma-separierte Liste: welche d.wild-Werte
 *                         // in diese Zeile als Ist-Wert einfließen.
 *                         // Leer/null -> nur der eigene Klassenname zählt.
 *   }
 *
 * GET  /api/plan.php?jahr=2026          → Plan für ein Jahr
 *                                         Format:
 *                                         {
 *                                           jahr: 2026,
 *                                           plan: {
 *                                             "Rehwild": {
 *                                               "T-Bock": { plan: 5, enabled: true, matches: "" },
 *                                               ...
 *                                             }
 *                                           }
 *                                         }
 * POST /api/plan.php                    → Plan für ein Jahr speichern (Admin)
 *                                         Body: { jahr, plan: { Wildart: { Klasse: {plan,enabled,matches} } } }
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
        foreach ($klassen as $k) {
            $out[$art][$k] = ['plan' => 0, 'enabled' => true, 'matches' => ''];
        }
    }
    return $out;
}

/* ================= GET ================= */
if ($method === 'GET') {
    $jahr = (int) ($_GET['jahr'] ?? 0);
    if ($jahr <= 0) jsonErr('jahr fehlt');

    $result = emptyPlan($DEFAULT_KLASSEN);

    // Defensive: wenn ALTER TABLE-Migrationen in db.php nicht durchliefen,
    // versuchen wir die "neuen" Spalten zwar zu lesen, fangen aber einen
    // eventuellen Fehler ab und selektieren stattdessen nur die alten
    // Spalten.
    try {
        $stmt = $pdo->prepare(
            'SELECT wildart, klasse, plan_anzahl, enabled, matches, sort_order
             FROM abschussplan WHERE jahr = :j
             ORDER BY sort_order, klasse'
        );
        $stmt->execute([':j' => $jahr]);
        $rows = $stmt->fetchAll();
    } catch (PDOException $ex) {
        // Fallback: nur die alten Spalten, ohne sort_order/enabled/matches
        $stmt = $pdo->prepare(
            'SELECT wildart, klasse, plan_anzahl
             FROM abschussplan WHERE jahr = :j
             ORDER BY klasse'
        );
        $stmt->execute([':j' => $jahr]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['enabled'] = 1;
            $r['matches'] = '';
        }
        unset($r);
    }

    foreach ($rows as $row) {
        $art = $row['wildart'];
        $kls = $row['klasse'];
        if (!isset($result[$art])) $result[$art] = [];
        $result[$art][$kls] = [
            'plan'    => (int) $row['plan_anzahl'],
            'enabled' => (int) $row['enabled'] === 1,
            'matches' => (string) ($row['matches'] ?? ''),
        ];
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
            'INSERT INTO abschussplan
             (jahr, wildart, klasse, plan_anzahl, enabled, matches, sort_order)
             VALUES (:j, :w, :k, :n, :e, :m, :s)'
        );
        foreach ($plan as $art => $klassen) {
            if (!is_array($klassen)) continue;
            $sort = 0;
            foreach ($klassen as $kls => $cfg) {
                // Rückwärtskompatibilität: Alt-Format war ein einzelner int
                if (is_int($cfg) || is_string($cfg)) {
                    $cfg = ['plan' => (int) $cfg, 'enabled' => true, 'matches' => ''];
                }
                $ins->execute([
                    ':j' => $jahr,
                    ':w' => (string) $art,
                    ':k' => (string) $kls,
                    ':n' => (int) ($cfg['plan'] ?? 0),
                    ':e' => !empty($cfg['enabled']) ? 1 : 0,
                    ':m' => (string) ($cfg['matches'] ?? ''),
                    ':s' => $sort++,
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
