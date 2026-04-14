<?php
/**
 * Abschussplan-API (mit kontext 'revier' oder 'park')
 *
 * GET  /api/plan.php?jahr=2026&kontext=revier   → Plan für ein Jahr + Kontext
 * POST /api/plan.php                            → Plan speichern (Admin)
 *      Body: { jahr, kontext, plan: { Wildart: { Klasse: {plan,enabled,matches} } } }
 */

require_once __DIR__ . '/db.php';
ensureSchema();

$method = $_SERVER['REQUEST_METHOD'];
$pdo = db();

$DEFAULT_KLASSEN_REVIER = [
    'Rehwild'  => ['T-Bock','J-Bock','Geiß','Schmalgeiß','Kitz'],
    'Rotwild'  => ['T-Hirsch','J-Hirsch','Tier','Schmaltier','Kalb'],
    'Gamswild' => ['Bock','Geiß','Jährling','Kitz'],
];
$DEFAULT_KLASSEN_PARK = [
    'Rotwild'  => ['T-Hirsch','J-Hirsch','Tier','Schmaltier','Kalb'],
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

function normalizeKontext($k) {
    return ($k === 'park') ? 'park' : 'revier';
}

/* ================= GET ================= */
if ($method === 'GET') {
    $jahr    = (int) ($_GET['jahr'] ?? 0);
    $kontext = normalizeKontext($_GET['kontext'] ?? 'revier');
    if ($jahr <= 0) jsonErr('jahr fehlt');

    $defaults = ($kontext === 'park') ? $GLOBALS['DEFAULT_KLASSEN_PARK'] : $GLOBALS['DEFAULT_KLASSEN_REVIER'];
    $result   = emptyPlan($defaults);

    try {
        $stmt = $pdo->prepare(
            'SELECT wildart, klasse, plan_anzahl, enabled, matches, sort_order
             FROM abschussplan WHERE jahr = :j AND kontext = :kx
             ORDER BY sort_order, klasse'
        );
        $stmt->execute([':j' => $jahr, ':kx' => $kontext]);
        $rows = $stmt->fetchAll();
    } catch (PDOException $ex) {
        // Fallback: ohne kontext-Filter (falls Migration nicht durchgelaufen ist
        // und es noch keine Kontext-Spalte gibt). Dann gelten die Zeilen als
        // Revier-Zeilen. Für den Park-Fall geben wir dann keine Ergebnisse.
        if ($kontext === 'park') {
            $rows = [];
        } else {
            try {
                $stmt = $pdo->prepare(
                    'SELECT wildart, klasse, plan_anzahl, enabled, matches
                     FROM abschussplan WHERE jahr = :j
                     ORDER BY klasse'
                );
                $stmt->execute([':j' => $jahr]);
                $rows = $stmt->fetchAll();
            } catch (PDOException $ex2) {
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
        }
    }

    foreach ($rows as $row) {
        $art = $row['wildart'];
        $kls = $row['klasse'];
        if (!isset($result[$art])) $result[$art] = [];
        $result[$art][$kls] = [
            'plan'    => (int) $row['plan_anzahl'],
            'enabled' => (int) ($row['enabled'] ?? 1) === 1,
            'matches' => (string) ($row['matches'] ?? ''),
        ];
    }
    jsonOk(['jahr' => $jahr, 'kontext' => $kontext, 'plan' => $result]);
}

/* ================= POST ================= */
if ($method === 'POST') {
    requireAdmin();
    $in      = jsonInput();
    $jahr    = (int) ($in['jahr'] ?? 0);
    $kontext = normalizeKontext($in['kontext'] ?? 'revier');
    $plan    = $in['plan'] ?? null;
    if ($jahr <= 0)       jsonErr('jahr fehlt');
    if (!is_array($plan)) jsonErr('plan fehlt');

    $pdo->beginTransaction();
    try {
        // Alten Plan dieses Jahrs + Kontexts löschen und frisch schreiben
        $pdo->prepare('DELETE FROM abschussplan WHERE jahr = :j AND kontext = :kx')
            ->execute([':j' => $jahr, ':kx' => $kontext]);
        $ins = $pdo->prepare(
            'INSERT INTO abschussplan
             (jahr, kontext, wildart, klasse, plan_anzahl, enabled, matches, sort_order)
             VALUES (:j, :kx, :w, :k, :n, :e, :m, :s)'
        );
        foreach ($plan as $art => $klassen) {
            if (!is_array($klassen)) continue;
            $sort = 0;
            foreach ($klassen as $kls => $cfg) {
                if (is_int($cfg) || is_string($cfg)) {
                    $cfg = ['plan' => (int) $cfg, 'enabled' => true, 'matches' => ''];
                }
                $ins->execute([
                    ':j'  => $jahr,
                    ':kx' => $kontext,
                    ':w'  => (string) $art,
                    ':k'  => (string) $kls,
                    ':n'  => (int) ($cfg['plan'] ?? 0),
                    ':e'  => !empty($cfg['enabled']) ? 1 : 0,
                    ':m'  => (string) ($cfg['matches'] ?? ''),
                    ':s'  => $sort++,
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
