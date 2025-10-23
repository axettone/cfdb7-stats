<?php

/**
 * CFDB7 Stats – storico compilazioni Contact Form 7
 *
 * Copyright (C) 2025  Paolo Niccolò Giubelli <paoloniccolo.giubelli@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

/* ===== 1) Bootstrap WordPress in modo robusto ===== */
function cfdb7_stats_find_wp_load(): ?string {
    // a) risali le directory fino a 6 livelli cercando wp-load.php
    $dir = __DIR__;
    for ($i=0; $i<6; $i++) {
        $candidate = $dir . '/wp-load.php';
        if (is_file($candidate)) return $candidate;
        $dir = dirname($dir);
    }
    // b) classico DOCUMENT_ROOT
    if (isset($_SERVER['DOCUMENT_ROOT'])) {
        $cand = rtrim($_SERVER['DOCUMENT_ROOT'], '/').'/wp-load.php';
        if (is_file($cand)) return $cand;
    }
    // c) Bedrock (web/wp/wp-load.php)
    $dir = __DIR__;
    for ($i=0; $i<6; $i++) {
        $candidate = $dir . '/web/wp/wp-load.php';
        if (is_file($candidate)) return $candidate;
        $dir = dirname($dir);
    }
    return null;
}

$wp_load = cfdb7_stats_find_wp_load();
if (!$wp_load) {
    http_response_code(500);
    echo 'Impossibile individuare wp-load.php (root classica o Bedrock).';
    exit;
}
require_once $wp_load;

/* ===== 2) Allineamento host/scheme ai cookie di WP ===== */
nocache_headers();

// Ricava host/scheme "canonici" da siteurl/home
$home = get_option('home') ?: get_option('siteurl');
$home_parts = wp_parse_url($home);
$home_scheme = ($home_parts['scheme'] ?? 'https');
$home_host   = ($home_parts['host']   ?? $_SERVER['HTTP_HOST'] ?? '');
$home_path   = rtrim($home_parts['path'] ?? '/', '/'); // in multisite subdirectory può non essere "/"

$current_scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$current_host   = $_SERVER['HTTP_HOST'] ?? '';
$current_uri    = $_SERVER['REQUEST_URI'] ?? '/';

// Se host o scheme differiscono, reindirizza preservando path e query
if ($home_host && (strcasecmp($current_host, $home_host) !== 0 || $current_scheme !== $home_scheme)) {
    // mantieni path relativo allo script
    $target = $home_scheme . '://' . $home_host . $current_uri;
    wp_safe_redirect($target, 302);
    exit;
}

/* ===== 3) Autenticazione e permessi ===== */
if ( ! is_user_logged_in() ) {
    auth_redirect(); // manda a wp-login.php con redirect_to corretto
}

$cap = 'manage_options'; // oppure 'read' se vuoi essere permissivo
if ( ! current_user_can($cap) ) {
    wp_die('Accesso negato: permessi insufficienti per visualizzare questo report.');
}

// Da qui in poi il tuo script esistente (query CFDB7, grafico, ecc.)
global $wpdb;

// --- Config e input ---
$ranges = [7, 15, 28, 90];
$range  = isset($_GET['range']) ? (int) $_GET['range'] : 28;
if (!in_array($range, $ranges, true)) $range = 28;

$formFilter = isset($_GET['form']) ? trim($_GET['form']) : 'all'; // 'all' o id numerico
$tz   = wp_timezone_string(); // es. Europe/Rome
$tzObj = wp_timezone();

// --- Individua tabella CFDB7 ---
$prefix = $wpdb->prefix;
$candidateTables = [
    $prefix . 'db7_forms',            // CFDB7 classico
    $prefix . 'cf7dbplugin_submits',  // vecchio CF7DB plugin
];
$table = null;
foreach ($candidateTables as $t) {
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t));
    if ($exists === $t) { $table = $t; break; }
}
if (!$table) {
    wp_die('Tabella CFDB7 non trovata (atteso wp_db7_forms o wp_cf7dbplugin_submits).');
}

// --- Scopri colonne disponibili ---
$cols = $wpdb->get_col($wpdb->prepare(
    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
    $table
));
$cols = array_map('strval', $cols);

// candidati per timestamp
$dateCandidates = ['form_date','created_on','created_at','submit_time','submitted_at','time','date'];
$dateCol = null;
foreach ($dateCandidates as $c) {
    if (in_array($c, $cols, true)) { $dateCol = $c; break; }
}
if (!$dateCol) {
    wp_die('Colonna data/ora non trovata nella tabella CFDB7.');
}

// candidati per form id
$formIdCol = null;
foreach (['form_id','form_post_id'] as $c) {
    if (in_array($c, $cols, true)) { $formIdCol = $c; break; }
}

// campo payload per estrarre _wpcf7 se manca una colonna id
$payloadCol = null;
foreach (['form_value','data','submitted_data','meta_data'] as $c) {
    if (in_array($c, $cols, true)) { $payloadCol = $c; break; }
}

// alcune installazioni CFDB7 salvano millisecondi in created_on
$isMillis = false;
if ($dateCol && in_array($dateCol, ['created_on','submit_time'], true)) {
    // Heuristica: se max(created_on) è un numero molto grande → millisecondi
    $val = $wpdb->get_var("SELECT MAX($dateCol) FROM $table");
    if ($val !== null && is_numeric($val) && (float)$val > 2000000000) {
        $isMillis = true;
    }
}

// --- Intervallo temporale ---
$nowUtc   = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$fromUtc  = $nowUtc->sub(new DateInterval('P' . $range . 'D'));

// --- Carica mappa Form ID → Titolo (da post_type wpcf7_contact_form) ---
$formsMap = [];
$posts = $wpdb->get_results("
    SELECT ID, post_title
    FROM {$wpdb->posts}
    WHERE post_type='wpcf7_contact_form' AND post_status IN('publish','draft','private')
", ARRAY_A);
foreach ($posts as $p) {
    $formsMap[(int)$p['ID']] = $p['post_title'];
}

// --- Query grezza del periodo scelto ---
// Recuperiamo solo le colonne minime per performance
$selectCols = [$dateCol];
if ($formIdCol) $selectCols[] = $formIdCol;
if ($payloadCol) $selectCols[] = $payloadCol;

$sqlCols = implode(',', array_map(function($c){ return "`$c`"; }, $selectCols));
$q = $wpdb->prepare(
    "SELECT $sqlCols FROM $table WHERE 
     " . ( $isMillis
            ? "$dateCol BETWEEN %f AND %f" // epoch millis
            : "$dateCol BETWEEN %s AND %s"
        ),
    ( $isMillis ? ((float)$fromUtc->format('U')*1000) : $fromUtc->format('Y-m-d H:i:s') ),
    ( $isMillis ? ((float)$nowUtc->format('U')*1000)  : $nowUtc->format('Y-m-d H:i:s') )
);

$rows = $wpdb->get_results($q, ARRAY_A);

// --- Normalizza righe: estrai timestamp (UTC) e form_id ---
$data = [];
foreach ($rows as $r) {
    // timestamp
    $t = $r[$dateCol];
    if ($isMillis) {
        $ts = (int) round(((float)$t)/1000);
        $dtUtc = (new DateTimeImmutable('@'.$ts))->setTimezone(new DateTimeZone('UTC'));
    } else {
        // prova a creare DateTime, altrimenti tenta come epoch secondi
        if (is_numeric($t)) {
            $dtUtc = (new DateTimeImmutable('@'.((int)$t)))->setTimezone(new DateTimeZone('UTC'));
        } else {
            try {
                $dtUtc = new DateTimeImmutable((string)$t, new DateTimeZone('UTC'));
            } catch (\Throwable $e) { continue; }
        }
    }

    // form id
    $fid = null;
    if ($formIdCol && isset($r[$formIdCol]) && is_numeric($r[$formIdCol])) {
        $fid = (int)$r[$formIdCol];
    } elseif ($payloadCol && isset($r[$payloadCol])) {
        // estrai _wpcf7 da payload (JSON o serializzato)
        $raw = $r[$payloadCol];
        $arr = null;

        // JSON?
        if (is_string($raw)) {
            $j = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($j)) $arr = $j;
        }
        // serializzato PHP?
        if (!$arr && is_string($raw) && str_starts_with($raw, 'a:')) {
            $maybe = @unserialize($raw);
            if (is_array($maybe)) $arr = $maybe;
        }
        if (is_array($arr)) {
            if (isset($arr['_wpcf7']) && is_numeric($arr['_wpcf7'])) {
                $fid = (int)$arr['_wpcf7'];
            } elseif (isset($arr['form_id']) && is_numeric($arr['form_id'])) {
                $fid = (int)$arr['form_id'];
            }
        }
    }

    // se manca l'id, marca 0 (sconosciuto)
    if ($fid === null) $fid = 0;

    $data[] = ['dtUtc'=>$dtUtc, 'form_id'=>$fid];
}

// --- Costruisci bucket giornalieri in timezone WP ---
$labels = [];
$bucketsByForm = []; // form_id => label => count
$idx = [];
$cursor = (new DateTimeImmutable('now', $tzObj))->setTime(0,0)->sub(new DateInterval('P'.($range-1).'D')); // inizio a mezzanotte locale
for ($i=0; $i<$range; $i++) {
    $k = $cursor->format('Y-m-d');
    $labels[] = $k;
    $idx[$k] = $i;
    $cursor = $cursor->add(new DateInterval('P1D'));
}

// popola
foreach ($data as $row) {
    $local = $row['dtUtc']->setTimezone($tzObj);
    $k = $local->format('Y-m-d');
    if (!isset($idx[$k])) continue; // fuori range
    $fid = (int)$row['form_id'];
    if (!isset($bucketsByForm[$fid])) {
        $bucketsByForm[$fid] = array_fill(0, count($labels), 0);
    }
    $bucketsByForm[$fid][$idx[$k]]++;
}

// --- Costruisci elenco form disponibili (solo quelli presenti nel periodo) ---
$availableForms = array_keys($bucketsByForm);
sort($availableForms);

// --- Prepara dataset per Chart.js ---
$datasets = [];
function makeColor(int $i, int $n): string {
    $h = (int) round(($i * 360) / max(1,$n));
    return "hsl($h 70% 45%)";
}

if ($formFilter === 'all') {
    // totale su tutti i form
    $tot = array_fill(0, count($labels), 0);
    $i=0;
    foreach ($bucketsByForm as $fid => $arr) {
        foreach ($arr as $j=>$v) $tot[$j] += $v;
        $i++;
    }
    $datasets[] = [
        'label' => 'Totale invii',
        'data'  => $tot,
        'color' => makeColor(0,1),
    ];
} else {
    $fidReq = (int) $formFilter;
    $arr = $bucketsByForm[$fidReq] ?? array_fill(0, count($labels), 0);
    $title = $formsMap[$fidReq] ?? "Form #$fidReq";
    $datasets[] = [
        'label' => $title,
        'data'  => $arr,
        'color' => makeColor(0,1),
    ];
}

// --- HTML ---
?><!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CF7 / CFDB7 - Statistiche invii (storico)</title>
<style>
body{font:14px/1.4 system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:20px;}
.container{max-width:1100px;margin:0 auto;}
.controls{display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:16px;}
label{font-weight:600;}
select{padding:6px 8px;}
.card{border:1px solid #e3e3e7;border-radius:12px;padding:16px;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.03);}
.canvas-wrap{position:relative;height:360px;}
.note{color:#666;font-size:12px;margin-top:8px;}
</style>
</head>
<body>
<div class="container">
  <h1>Storico compilazioni CF7 (via CFDB7)</h1>
  <form method="get" class="controls card">
    <div>
      <label for="range">Intervallo:</label>
      <select name="range" id="range">
        <?php foreach ($ranges as $r): ?>
          <option value="<?php echo (int)$r; ?>" <?php selected($range, $r); ?>>Ultimi <?php echo (int)$r; ?> giorni</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="form">Form:</label>
      <select name="form" id="form">
        <option value="all" <?php selected($formFilter,'all'); ?>>Tutti i form (totale)</option>
        <?php foreach ($availableForms as $fid): if ($fid===0) continue;
            $title = $formsMap[$fid] ?? "Form #$fid"; ?>
          <option value="<?php echo (int)$fid; ?>" <?php selected((string)$fid, $formFilter); ?>>
            #<?php echo (int)$fid; ?> — <?php echo esc_html($title); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <button type="submit">Aggiorna</button>
    </div>
  </form>

  <div class="card">
    <div class="canvas-wrap">
      <canvas id="chart" height="360"></canvas>
    </div>
    <div class="note">
      Fuso orario: <?php echo esc_html($tz); ?>. Dati aggregati al giorno (00:00–23:59). Fonte: tabella <code><?php echo esc_html($table); ?></code>.
    </div>
  </div>
</div>

<script>
(function(){
  const labels  = <?php echo wp_json_encode($labels); ?>;
  const datasets = <?php
    echo wp_json_encode(array_map(function($d){
      return ['label'=>$d['label'], 'data'=>$d['data'], 'borderColor'=>$d['color'], 'backgroundColor'=>$d['color']];
    }, $datasets));
  ?>;

  function loadScript(src){return new Promise(function(res, rej){
    var s=document.createElement('script'); s.src=src; s.onload=res; s.onerror=rej; document.head.appendChild(s);
  });}

  (async function(){
    if (!window.Chart) { await loadScript('https://cdn.jsdelivr.net/npm/chart.js'); }
    const ctx = document.getElementById('chart').getContext('2d');
    new Chart(ctx, {
      type: 'line',
      data: { labels, datasets },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { position: 'bottom' } },
        scales: {
          x: { title: { display: true, text: 'Giorno' } },
          y: { beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'Invii' } }
        },
        elements: { point: { radius: 2 }, line: { tension: 0.2 } }
      }
    });
  })();
})();
</script>
</body>
</html>
