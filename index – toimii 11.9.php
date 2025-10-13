<?php declare(strict_types=1);

/* ========= Perusasetukset ========= */
error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('Europe/Helsinki');

/* Sessioevästeet kovennettuina */
session_set_cookie_params([
  'lifetime' => 0,
  'path'     => '/',
  'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
  'httponly' => true,
  'samesite' => 'Lax',
]);
session_start();

/* Välimuistin esto */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

/* Kovennusotsikot */
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none';");
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: geolocation=(), camera=(), microphone=()');

/* ========= Konfiguraatio ========= */
const PHASE1_FILE_BASE   = 'SeparateBlocksQuestions';
const PHASE2_FILE_BASE   = 'InsideBlockQuestions';
const TYPEDESC_FILE_BASE = 'TypeDescriptions'; // tyyppikuvaukset

const YES_MIN     = 4;
const YES_MAX     = 6;

const Q1_MAX      = 32;
const TB_MAX      = 6;
const VARPAIR_MAX = 9;
const TB2_MAX     = 3;

const INVITES_ENABLED = true;

/* Palautteen lähetys */
const FEEDBACK_TO  = 'eg-testi.palaute@enneagrammitesti.fi';
const FROM_NAME    = 'Enneagrammitesti';
const FROM_EMAIL   = 'no-reply@enneagrammitesti.fi';

/* --- Lokitus --- */
function data_dir(): string {
    $pref = realpath(__DIR__ . '/../') !== false ? (__DIR__ . '/../data') : (__DIR__ . '/data');
    if (!is_dir($pref)) @mkdir($pref, 0700, true);
    return $pref;
}
function index_app_log_path(): string { return data_dir() . '/index_app_' . date('Y-m') . '.log'; }
function index_log(string $msg): void {
    $ts = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    @file_put_contents(index_app_log_path(), '['.$ts.']['.$ip.'] '.$msg.PHP_EOL, FILE_APPEND | LOCK_EX);
}

/* --- Sähköposti --- */
function send_mail(string $to, string $subject, string $body): bool {
    $headers = [];
    $headers[] = 'From: '.FROM_NAME.' <'.FROM_EMAIL.'>';
    $headers[] = 'Reply-To: '.FROM_EMAIL;
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers_str = implode("\r\n", $headers);
    $subjectEnc = '=?UTF-8?B?'.base64_encode($subject).'?=';
    return @mail($to, $subjectEnc, $body, $headers_str);
}

/* --- Kutsuvaraston polku (JSON) --- */
function invites_path(): string {
    return data_dir() . '/invites.json';
}
function invites_load(): array {
    $p = invites_path();
    if (!file_exists($p) || filesize($p) === 0) return [];
    $fp = fopen($p, 'r');
    if (!$fp) return [];
    flock($fp, LOCK_SH);
    $json = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}
function invites_save(array $items): bool {
    $p = invites_path();
    $fp = fopen($p, 'c+');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    $ok = fwrite($fp, json_encode($items, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)) !== false;
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $ok;
}
function invite_find_by_token(array $items, string $token): ?array {
    foreach ($items as $it) if (($it['token'] ?? null) === $token) return $it;
    return null;
}
function invite_find_by_code(array $items, string $code): ?array {
    $code = strtoupper($code);
    foreach ($items as $it) if (strtoupper($it['code'] ?? '') === $code) return $it;
    return null;
}
function invite_update_status(string $id, string $status): void {
    $items = invites_load();
    $ch = false;
    foreach ($items as &$it) {
        if (($it['id'] ?? '') === $id) {
            $it['status'] = $status;
            if ($status === 'accepted') $it['accepted_at']  = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
            if ($status === 'completed') $it['completed_at'] = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
            $ch = true;
            break;
        }
    }
    unset($it);
    if ($ch) invites_save($items);
}

/* Lokipolku CSV: vastaukset */
function log_path(): string {
    return data_dir() . '/survey_log_' . date('Y-m') . '.csv';
}

/* Testimoodi */
if (isset($_GET['test'])) {
    $_SESSION['test_mode'] = ($_GET['test'] === '1');
}
$TEST_MODE = !empty($_SESSION['test_mode']);
$SHOW_TEST_TOGGLE = isset($_GET['showtestmode']); // näytä kytkin vain jos parametri

/* Kovatyhjennys (?fresh=1) – säilytä test=1 ja showtestmode */
function hard_reset(): void {
    $keep = [];
    if (isset($_GET['test'])) $keep['test'] = $_GET['test'];
    if (isset($_GET['showtestmode'])) $keep['showtestmode'] = $_GET['showtestmode'];
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time()-42000, $p['path'], $p['domain'] ?? '', $p['secure'] ?? false, $p['httponly'] ?? true);
        }
        session_destroy();
    }
    $base = strtok($_SERVER['REQUEST_URI'], '?');
    $qs   = $keep ? ('?' . http_build_query($keep)) : '';
    header('Location: ' . $base . $qs);
    exit;
}
if (isset($_GET['fresh'])) hard_reset();

/* Aputyökalut */
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csv_path(string $base): string {
    if (file_exists($base)) return $base;
    if (file_exists($base . '.csv')) return $base . '.csv';
    if (file_exists($base . '.cvc')) return $base . '.cvc';
    return '';
}

/* Yleinen CSV-rivien lukija (tukee ; tai , erotinta, BOMin poisto, kommenttirivit "/*") */
function read_tag_text_rows(string $base): array {
    $path = csv_path($base);
    if ($path === '') { index_log("CSV not found: $base"); return []; }
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) { index_log("CSV read fail: $path"); return []; }
    $rows = [];
    foreach ($lines as $i => $line) {
        if ($i === 0) $line = preg_replace('/^\xEF\xBB\xBF/', '', $line); // BOM
        $trim = ltrim($line);
        if ($trim === '' || strpos($trim, '/*') === 0) continue; // kommenttirivi
        $parts = str_getcsv($line, ';', '"', '\\');
        if (count($parts) < 2) $parts = str_getcsv($line, ',', '"', '\\');
        if (count($parts) < 2) continue;
        $tag  = trim((string)$parts[0]);
        $text = trim(implode(' ', array_slice($parts, 1)));
        if ($tag === '' || $text === '') continue;
        $rows[] = [$tag, $text];
    }
    index_log("CSV loaded: $base rows=" . count($rows));
    return $rows;
}

/* ========= Datan luku ========= */
function load_phase1_questions(string $base): array {
    $rows = read_tag_text_rows($base);
    $out = []; $id = 1;
    foreach ($rows as [$tag, $text]) {
        if (!preg_match('/^([ABCD])1$/i', $tag, $m)) continue;
        $out[] = ['class' => strtoupper($m[1].'1'), 'text' => $text, 'id' => $id++];
    }
    return $out;
}
function load_tiebreak_questions(string $base): array {
    $rows = read_tag_text_rows($base);
    $tb = ['A11'=>[], 'B11'=>[], 'C11'=>[], 'D11'=>[]];
    foreach ($rows as [$tag, $text]) {
        $u = strtoupper($tag);
        if (isset($tb[$u])) $tb[$u][] = $text;
    }
    return $tb;
}
function load_phase2_blocks(string $base): array {
    $rows = read_tag_text_rows($base);
    $cases = ['B2'=>[], 'C2'=>[], 'D2'=>[]];
    $n = count($rows);
    for ($i=0; $i<$n; $i++) {
        [$tag, $desc] = $rows[$i];
        if (preg_match('/^([BCD])2(?:-([A-Za-z0-9]+)vs([A-Za-z0-9]+))?$/i', $tag, $m)) {
            $letter = strtoupper($m[1]);
            $pairL = $m[2] ?? null; $pairR = $m[3] ?? null;
            if (!isset($rows[$i+1], $rows[$i+2])) continue;
            [$tag2, $leftText]  = $rows[$i+1];
            [$tag3, $rightText] = $rows[$i+2];
            if (!preg_match('/^'.$letter.'2a-([A-Za-z0-9_\-]+)$/i', $tag2, $mA)) continue;
            if (!preg_match('/^'.$letter.'2b-([A-Za-z0-9_\-]+)$/i', $tag3, $mB)) continue;
            $leftVar  = (string)$mA[1];
            $rightVar = (string)$mB[1];
            if ($pairL === null || $pairR === null) { $pairL = $leftVar; $pairR = $rightVar; }
            $cases[$letter.'2'][] = [
                'desc'       => $desc,
                'left_text'  => $leftText,
                'left_var'   => $leftVar,
                'right_text' => $rightText,
                'right_var'  => $rightVar,
                'pair'       => [$pairL, $pairR],
            ];
            $i += 2;
        }
    }
    return $cases;
}
function load_phase2_tiebreak_all(string $base): array {
    $rows = read_tag_text_rows($base);
    $out = ['TB2'=>[], 'TC2'=>[], 'TD2'=>[]];
    $n = count($rows);
    for ($i=0; $i<$n; $i++) {
        [$tag, $desc] = $rows[$i];
        if (preg_match('/^T([BCD])2(?:-([A-Za-z0-9]+)vs([A-Za-z0-9]+))?$/i', $tag, $m)) {
            $letter = strtoupper($m[1]);
            $pairL = $m[2] ?? null; $pairR = $m[3] ?? null;
            $group = 'T'.$letter.'2';
            if (!isset($rows[$i+1], $rows[$i+2])) continue;
            [$tag2, $leftText]  = $rows[$i+1];
            [$tag3, $rightText] = $rows[$i+2];
            if (!preg_match('/^T'.$letter.'2a-([A-Za-z0-9_\-]+)$/i', $tag2, $mA)) continue;
            if (!preg_match('/^T'.$letter.'2b-([A-Za-z0-9_\-]+)$/i', $tag3, $mB)) continue;
            $leftVar  = (string)$mA[1];
            $rightVar = (string)$mB[1];
            if ($pairL === null || $pairR === null) { $pairL = $leftVar; $pairR = $rightVar; }
            $out[$group][] = [
                'desc'       => $desc,
                'left_text'  => $leftText,
                'left_var'   => $leftVar,
                'right_text' => $rightText,
                'right_var'  => $rightVar,
                'pair'       => [$pairL, $pairR],
            ];
            $i += 2;
        }
    }
    return $out;
}

/* Tyypin kuvaukset (TypeDescriptions) */
function load_type_descriptions(string $base): array {
    $rows = read_tag_text_rows($base);
    $map = [];
    foreach ($rows as [$tag, $text]) {
        $key = trim((string)$tag);
        if ($key === '') continue;
        $map[$key] = $text;
    }
    return $map;
}

/* ========= Loki – vakiokolumnit ========= */
function build_q1_fixed_values(): array {
    $answersById = $_SESSION['answers1_by_id'] ?? [];
    $vals = [];
    for ($id=1; $id<=Q1_MAX; $id++) $vals[] = isset($answersById[$id]) ? (string)$answersById[$id] : '';
    return $vals;
}
function tb_fixed_headers(): array { return ['TB1','TB2','TB3','TB4','TB5','TB6']; }
function tb_fixed_values(): array {
    $vals = array_fill(0, TB_MAX, '');
    $log  = $_SESSION['tiebreak_log'] ?? [];
    $i=0; foreach ($log as $item) { if ($i>=TB_MAX) break; $vals[$i] = (string)($item['value'] ?? ''); $i++; }
    return $vals;
}
function varcode_fixed_headers(): array {
    return ['VarCode_1','VarSum_1','VarCode_2','VarSum_2','VarCode_3','VarSum_3'];
}
function varcode_fixed_values(): array {
    $codes = $_SESSION['varCodes'] ?? [];
    $codes = array_values($codes);
    $codes = array_slice($codes, 0, 3);
    while (count($codes) < 3) $codes[] = '';
    $vp = $_SESSION['varPoints'] ?? [];
    $sums = [];
    foreach ($codes as $c) $sums[] = ($c === '') ? '0' : (string)(int)($vp[$c] ?? 0);
    return [$codes[0], $sums[0], $codes[1], $sums[1], $codes[2], $sums[2]];
}
function varpair_case_headers(): array { $hdrs=[]; for ($i=1; $i<=VARPAIR_MAX; $i++) $hdrs[]='VarPair'.$i; return $hdrs; }
function varpair_case_values(): array {
    $pairs = $_SESSION['pairs2'] ?? [];
    if (count($pairs) < VARPAIR_MAX) $pairs = array_merge($pairs, array_fill(0, VARPAIR_MAX - count($pairs), ''));
    else $pairs = array_slice($pairs, 0, VARPAIR_MAX);
    return $pairs;
}
function tb2_fixed_headers(): array { return ['TB2_1','TB2_2','TB2_3']; }
function tb2_fixed_values(): array {
    $vals = $_SESSION['tb2_pairs'] ?? [];
    if (count($vals) < TB2_MAX) $vals = array_merge($vals, array_fill(0, TB2_MAX - count($vals), ''));
    else $vals = array_slice($vals, 0, TB2_MAX);
    return $vals;
}

/* Uudet: ref-ehdotus lokin loppuun */
function ensure_log_header(): void {
    $lp = log_path();
    if (file_exists($lp) && filesize($lp) > 0) return;

    $base = [
        'Date','Time','Nickname','Ennea',
        'A1_pts','B1_pts','C1_pts','D1_pts',
        'A1_yes','B1_yes','C1_yes','D1_yes',
        'WinnerClass','Phase2Track'
    ];
    $q1 = []; for ($i=1; $i<=Q1_MAX; $i++) $q1[] = 'Q1_'.$i;

    $cols = array_merge(
        $base,
        $q1,
        tb_fixed_headers(),
        varcode_fixed_headers(),
        varpair_case_headers(),
        tb2_fixed_headers(),
        ['FinalVar', 'RefGuessType', 'RefGuessProb']
    );
    file_put_contents($lp, implode(';', $cols).PHP_EOL, FILE_APPEND | LOCK_EX);
}
function write_full_log_row(array $row): void {
    $parts = [
        $row['date'], $row['time'], $row['nick'], $row['ennea'],
        (string)$row['A1'], (string)$row['B1'], (string)$row['C1'], (string)$row['D1'],
        (string)($row['A1_yes'] ?? 0), (string)($row['B1_yes'] ?? 0),
        (string)($row['C1_yes'] ?? 0), (string)($row['D1_yes'] ?? 0),
        $row['winner'], $row['track']
    ];
    $parts = array_merge($parts, build_q1_fixed_values());
    $parts = array_merge($parts, tb_fixed_values());
    $parts = array_merge($parts, varcode_fixed_values());
    $parts = array_merge($parts, varpair_case_values());
    $parts = array_merge($parts, tb2_fixed_values());
    $parts[] = (string)($row['finalVar'] ?? '');

    $parts[] = (string)($_SESSION['RefGuessType'] ?? '');
    $parts[] = (string)($_SESSION['RefGuessProb'] ?? '');

    ensure_log_header();
    file_put_contents(log_path(), implode(';', $parts).PHP_EOL, FILE_APPEND | LOCK_EX);
    index_log('CSV: Row written (finalVar="' . (string)($row['finalVar'] ?? '') . '", nick="' . ($row['nick'] ?? '') . '")');
}

/* ========= Tila ========= */
if (!isset($_SESSION['state'])) $_SESSION['state'] = 'intro';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$state = $_SESSION['state'] ?? 'none';
$validStates = ['intro','quiz1','tiebreak','a1_result','phase2','phase2_tb','done2'];
if (!in_array($_SESSION['state'], $validStates, true)) {
    index_log('STATE: Invalid state "' . $state . '" reset to intro');
    $_SESSION['state'] = 'intro';
}

index_log('REQUEST: ' . $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . ' (state=' . ($_SESSION['state'] ?? 'none') . ', session_id=' . session_id() . ')');

/* Palautteen muuttujat */
$feedbackMsg = '';
$feedbackErr = '';
$oldFeedbackText = '';

/* Esikatselu adminiin - ladataan vasta testimoodissa tarvittaessa */

/* Tyyppikuvaukset ladataan vasta tarvittaessa optimoinnin vuoksi */

index_log('SYSTEM: Invites ' . (INVITES_ENABLED ? 'enabled' : 'disabled') . ', test_mode=' . ($TEST_MODE ? 'on' : 'off'));

/* Palautteen näyttö/lähetys */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_SESSION['state'] ?? '') === 'done2' || ($_SESSION['state'] ?? '') === 'a1_result')) {
    if (isset($_POST['show_feedback'])) {
        // Tarkista onko palaute jo annettu
        if (empty($_SESSION['feedback_given'])) {
            $_SESSION['show_feedback'] = true;
            index_log('FEEDBACK: Form opened (state=' . ($_SESSION['state'] ?? 'unknown') . ')');
        }
    } elseif (isset($_POST['send_feedback'])) {
        $txt = trim((string)($_POST['feedback_text'] ?? ''));
        $oldFeedbackText = $txt;
        if ($txt === '') {
            $feedbackErr = 'Kirjoita palautteesi.';
        } else {
            $nick   = (string)($_SESSION['nickname'] ?? '');
            $result = (string)($_SESSION['log_row']['finalVar'] ?? '');
            $body = "ENNEAGRAMMITESTI – palaute\n\n".
                    "Nimimerkki: ".($nick !== '' ? $nick : '(ei annettu)')."\n".
                    "Tyyppitulos: ".($result !== '' ? $result : '(ei saatavilla)')."\n\n".
                    "Palaute:\n".$txt."\n";
            $ok = send_mail(FEEDBACK_TO, 'Palautetta Enneagrammitestistä', $body);
            if ($ok) {
                $feedbackMsg = 'Kiitos palautteesta!';
                $_SESSION['show_feedback'] = false;
                $_SESSION['feedback_given'] = true; // Merkitse palaute annetuksi
                $oldFeedbackText = '';
                index_log('FEEDBACK: Sent successfully (nick="' . $nick . '", result="' . $result . '")');
            } else {
                $feedbackErr = 'Palautteen lähetys epäonnistui. Yritä hetken päästä uudelleen.';
                index_log('FEEDBACK: Send failed (nick="' . $nick . '", result="' . $result . '")');
            }
        }
    }
}

/* Intro: nimimerkki + oma tyyli + mahdollinen kutsukoodi */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_SESSION['state'] ?? '') === 'intro') && isset($_POST['start'])) {
    $nick  = trim((string)($_POST['nickname'] ?? ''));
    $ennea = trim((string)($_POST['ennea'] ?? ''));
    $code  = strtoupper(trim((string)($_POST['invite_code'] ?? '')));

    // Kutsukoodi validoidaan tässä, jos annettu ja ei jo token-claimia
    if (INVITES_ENABLED && $code !== '' && empty($_SESSION['InviteId'])) {
        $items = invites_load();
        $inv = invite_find_by_code($items, $code);
        if ($inv && (($inv['status'] ?? '') === 'sent' || ($inv['status'] ?? '') === 'accepted')) {
            $_SESSION['RefGuessType'] = (string)($inv['guess_type'] ?? '');
            $_SESSION['RefGuessProb'] = (string)($inv['guess_prob'] ?? '');
            $_SESSION['InviteId']     = (string)($inv['id'] ?? '');
            if (($inv['status'] ?? '') === 'sent') invite_update_status($_SESSION['InviteId'], 'accepted');
            $claim_ok = true;
            index_log('INVITE: Claimed by code="' . $code . '" (id=' . $_SESSION['InviteId'] . ')');
        } else {
            $error = 'Kutsukoodi ei kelpaa tai on vanhentunut.';
            index_log('INVITE: Claim failed for code="' . $code . '"');
        }
    }

    if ($error === null) {
        if ($ennea === '') $ennea = 'en tiedä'; // oletus
        // Ei pakoteta nimimerkkiä (saa olla tyhjä)
        index_log('INTRO: Form processing started');

        $phase1_all_preview = load_phase1_questions(PHASE1_FILE_BASE);
        $phase1_index = [];
        foreach ($phase1_all_preview as $q) $phase1_index[(int)$q['id']] = $q['class'];
        $q1 = $phase1_all_preview; shuffle($q1);

        $_SESSION['nickname'] = $nick;
        $_SESSION['ennea']    = $ennea;

        $_SESSION['phase1']        = $q1;
        $_SESSION['phase1_index']  = $phase1_index;
        $_SESSION['q1_index']      = 0;
        $_SESSION['answers1_by_id']= [];
        $_SESSION['yesCounts1']    = ['A1'=>0,'B1'=>0,'C1'=>0,'D1'=>0];
        $_SESSION['points1']       = ['A1'=>0,'B1'=>0,'C1'=>0,'D1'=>0];
        $_SESSION['tiebreak_log']  = [];
        $_SESSION['tiebreak_ord']  = [];

        $now = new DateTimeImmutable('now');
        $_SESSION['log_row'] = [
            'date'    => $now->format('Y-m-d'),
            'time'    => $now->format('H:i:s'),
            'nick'    => $nick,
            'ennea'   => $ennea,
            'A1'      => 0, 'B1'=>0, 'C1'=>0, 'D1'=>0,
            'A1_yes'  => 0, 'B1_yes'=>0, 'C1_yes'=>0, 'D1_yes'=>0,
            'winner'  => '',
            'track'   => '',
            'finalVar'=> ''
        ];

        $_SESSION['state'] = 'quiz1';
        index_log('INTRO: Processing form (nick="' . $nick . '", ennea="' . $ennea . '", code="' . $code . '")');
    }
}

/* Vaihe 1 vastaus */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_SESSION['state'] ?? '') === 'quiz1') && isset($_POST['choice'])) {
    $set = $_SESSION['phase1'] ?? []; $total = count($set);
    index_log('QUIZ1: POST received (total_questions=' . $total . ', current_index=' . ($_SESSION['q1_index'] ?? 0) . ')');
    if ($total === 0 || !isset($_SESSION['ennea'])) {
        $_SESSION['state'] = 'intro';
        index_log('ERROR: quiz1 -> intro (missing session data)');
    }
    else {
        $n = (int)$_POST['choice'];
        if ($n>=1 && $n<=6) {
            $i = (int)($_SESSION['q1_index'] ?? 0);
            if ($i < 0 || $i >= $total) { $i=0; $_SESSION['q1_index']=0; }
            $q   = $set[$i];
            $cls = $q['class']; $qid = (int)$q['id'];

            $_SESSION['answers1_by_id'][$qid] = $n;
            $_SESSION['points1'][$cls] = ($_SESSION['points1'][$cls] ?? 0) + $n;
            if ($n >= YES_MIN) $_SESSION['yesCounts1'][$cls] = ($_SESSION['yesCounts1'][$cls] ?? 0) + 1;

            $_SESSION['q1_index'] = $i+1;
            index_log('QUIZ1 ANSWER: q' . ($i+1) . '/' . $total . ' class=' . $cls . ' choice=' . $n . ' (next_index=' . $_SESSION['q1_index'] . ')');

            if ($_SESSION['q1_index'] >= $total) {
                foreach (['A1','B1','C1','D1'] as $k) {
                    $_SESSION['log_row'][$k]      = (int)($_SESSION['points1'][$k] ?? 0);
                    $_SESSION['log_row'][$k.'_yes']= (int)($_SESSION['yesCounts1'][$k] ?? 0);
                }

                $pts = $_SESSION['points1']; arsort($pts);
                $max = reset($pts);
                $tops = array_keys(array_filter($pts, function($v) use ($max) { return $v === $max; }));
                index_log('QUIZ1: Phase1 complete (scores: A1=' . ($pts['A1'] ?? 0) . ', B1=' . ($pts['B1'] ?? 0) . ', C1=' . ($pts['C1'] ?? 0) . ', D1=' . ($pts['D1'] ?? 0) . ', leaders=' . implode(',', $tops) . ')');

                if (count($tops) === 1) {
                    $top = $tops[0];
                    $_SESSION['topClass'] = $top;
                    $_SESSION['log_row']['winner'] = $top;

                    if ($top === 'A1') {
                        $_SESSION['log_row']['finalVar'] = '4';
                        if (!empty($_SESSION['InviteId'])) invite_update_status($_SESSION['InviteId'], 'completed');
                        write_full_log_row($_SESSION['log_row']);
                        $_SESSION['state'] = 'a1_result';
                        index_log('STATE CHANGE: quiz1 -> a1_result (A1 winner, finalVar=4)');
                    } else {
                        $_SESSION['cases_all'] = load_phase2_blocks(PHASE2_FILE_BASE);
                        $track = $top[0] . '2';
                        $_SESSION['track']     = $track;
                        $_SESSION['blocks2']   = $_SESSION['cases_all'][$track] ?? [];
                        $_SESSION['q2_index']  = 0;
                        $_SESSION['answers2']  = [];
                        $_SESSION['varPoints'] = [];
                        $_SESSION['pairs2']    = [];
                        $_SESSION['tb2_pairs'] = [];
                        $_SESSION['log_row']['track'] = $track;

                        $codes=[]; foreach (($_SESSION['blocks2'] ?? []) as $b){ $codes[$b['left_var']] = true; $codes[$b['right_var']] = true; }
                        $codes = array_values(array_unique(array_keys($codes))); natsort($codes);
                        $_SESSION['varCodes'] = array_slice(array_values($codes), 0, 3);

                        $_SESSION['state'] = 'phase2';
                        index_log('STATE CHANGE: quiz1 -> phase2 (winner=' . $top . ', track=' . $track . ', blocks=' . count($_SESSION['blocks2']) . ')');
                        index_log('PHASE2: Initialized (varCodes=' . implode(',', $_SESSION['varCodes']) . ')');
                    }
                } else {
                    if (count($tops) > 2) {
                        $order = ['A1','B1','C1','D1'];
                        usort($tops, function($a,$b) use ($order) { return array_search($a,$order,true) <=> array_search($b,$order,true); });
                        $tops = array_slice($tops, 0, 2);
                    }
                    $_SESSION['tiebreak_classes'] = $tops;
                    $tbAll = load_tiebreak_questions(PHASE1_FILE_BASE);
                    $tbPool = [];
                    foreach ($tops as $clsTB) {
                        $arr = $tbAll[$clsTB[0].'11'] ?? [];
                        if ($arr) { shuffle($arr); foreach (array_slice($arr,0,2) as $txt) $tbPool[] = ['class'=>$clsTB,'text'=>$txt]; }
                    }
                    shuffle($tbPool);
                    $_SESSION['tiebreak_set']   = $tbPool;
                    $_SESSION['tiebreak_index'] = 0;
                    $_SESSION['tiebreak_log']   = [];
                    $_SESSION['tiebreak_ord']   = [];
                    $_SESSION['state'] = 'tiebreak';
                    index_log('STATE CHANGE: quiz1 -> tiebreak (tied_classes=' . implode(',', $tops) . ', tb_questions=' . count($tbPool) . ')');
                    index_log('TIEBREAK: Initialized (classes=' . implode(',', $tops) . ', questions=' . count($tbPool) . ')');
                }
            }
        }
    }
}

/* Vaihe 1 tiebreak -vastaus */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_SESSION['state'] ?? '') === 'tiebreak') && isset($_POST['tb_choice'])) {
    $n = (int)$_POST['tb_choice'];
    if ($n>=1 && $n<=6) {
        $set = $_SESSION['tiebreak_set'] ?? [];
        $i   = (int)($_SESSION['tiebreak_index'] ?? 0);
        $blk = $set[$i] ?? null;
        if ($blk) {
            $cls = $blk['class'];
            $_SESSION['points1'][$cls] = ($_SESSION['points1'][$cls] ?? 0) + $n;
            if ($n >= YES_MIN) $_SESSION['yesCounts1'][$cls] = ($_SESSION['yesCounts1'][$cls] ?? 0) + 1;

            $ord = ($_SESSION['tiebreak_ord'][$cls] ?? 0) + 1;
            $_SESSION['tiebreak_ord'][$cls] = $ord;
            $_SESSION['tiebreak_log'][] = ['label'=>'TB-'.$cls.'-'.$ord, 'value'=>$n];

            $_SESSION['tiebreak_index'] = $i+1;
            index_log('TIEBREAK ANSWER: tb' . ($i+1) . '/' . count($set) . ' class=' . $cls . ' choice=' . $n . ' (next_index=' . $_SESSION['tiebreak_index'] . ')');

            if ($_SESSION['tiebreak_index'] >= count($set)) {
                foreach (['A1','B1','C1','D1'] as $k) {
                    $_SESSION['log_row'][$k]      = (int)($_SESSION['points1'][$k] ?? 0);
                    $_SESSION['log_row'][$k.'_yes']= (int)($_SESSION['yesCounts1'][$k] ?? 0);
                }
                $pts = $_SESSION['points1']; arsort($pts);
                $max = reset($pts);
                $tops = array_keys(array_filter($pts, function($v) use ($max) { return $v === $max; }));
                if (count($tops) === 1) $top = $tops[0];
                else {
                    $yc = $_SESSION['yesCounts1'];
                    $best = $tops[0];
                    foreach ($tops as $c) if (($yc[$c]??0) > ($yc[$best]??0)) $best = $c;
                    $eq = array_filter($tops, function($c) use ($yc, $best) { return ($yc[$c]??0)===($yc[$best]??0); });
                    $top = $eq[array_rand($eq)];
                }
                $_SESSION['topClass'] = $top;
                $_SESSION['log_row']['winner'] = $top;

                if ($top === 'A1') {
                    $_SESSION['log_row']['finalVar'] = '4';
                    if (!empty($_SESSION['InviteId'])) invite_update_status($_SESSION['InviteId'], 'completed');
                    write_full_log_row($_SESSION['log_row']);
                    $_SESSION['state'] = 'a1_result';
                    index_log('STATE CHANGE: tiebreak -> a1_result (A1 winner after tiebreak, finalVar=4)');
                    index_log('TIEBREAK: Complete (final_winner=' . $top . ', final_scores: A1=' . ($_SESSION['points1']['A1'] ?? 0) . ', B1=' . ($_SESSION['points1']['B1'] ?? 0) . ', C1=' . ($_SESSION['points1']['C1'] ?? 0) . ', D1=' . ($_SESSION['points1']['D1'] ?? 0) . ')');
                } else {
                    $_SESSION['cases_all'] = load_phase2_blocks(PHASE2_FILE_BASE);
                    $track = $top[0] . '2';
                    $_SESSION['track']     = $track;
                    $_SESSION['blocks2']   = $_SESSION['cases_all'][$track] ?? [];
                    $_SESSION['q2_index']  = 0;
                    $_SESSION['answers2']  = [];
                    $_SESSION['varPoints'] = [];
                    $_SESSION['pairs2']    = [];
                    $_SESSION['tb2_pairs'] = [];
                    $_SESSION['log_row']['track'] = $track;

                    $codes=[]; foreach (($_SESSION['blocks2'] ?? []) as $b){ $codes[$b['left_var']] = true; $codes[$b['right_var']] = true; }
                    $codes = array_values(array_unique(array_keys($codes))); natsort($codes);
                    $_SESSION['varCodes'] = array_slice(array_values($codes), 0, 3);

                    $_SESSION['state'] = 'phase2';
                    index_log('STATE CHANGE: tiebreak -> phase2 (winner=' . $top . ' after tiebreak, track=' . $track . ')');
                    index_log('TIEBREAK: Complete -> PHASE2 (final_winner=' . $top . ', track=' . $track . ', blocks=' . count($_SESSION['blocks2']) . ')');
                }
            }
        }
    }
}

/* Vaihe 2 vastaus */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_SESSION['state'] ?? '') === 'phase2') && isset($_POST['choice2'])) {
    $raw = (int)$_POST['choice2'];
    if ($raw >= -4 && $raw <= 4) {
        $i   = (int)$_SESSION['q2_index'] ?? 0;
        $blk = $_SESSION['blocks2'][$i] ?? null;
        if ($blk) {
            $entry = '';
            if ($raw !== 0) {
                $add = abs($raw);
                $var = ($raw < 0) ? $blk['left_var'] : $blk['right_var'];
                $_SESSION['varPoints'][$var] = ($_SESSION['varPoints'][$var] ?? 0) + $add;
                $entry = 'T'.$var.'-'.$add;
            }
            $_SESSION['pairs2'][]   = $entry;
            $_SESSION['answers2'][] = $raw;
            $_SESSION['q2_index']   = $i+1;
            index_log('PHASE2 ANSWER: case' . ($i+1) . '/' . count($_SESSION['blocks2']) . ' choice=' . $raw . ' left=' . $blk['left_var'] . ' right=' . $blk['right_var'] . ' (next_index=' . $_SESSION['q2_index'] . ')');

            if ($_SESSION['q2_index'] >= count($_SESSION['blocks2'])) {
                $vp = $_SESSION['varPoints'] ?? [];
                if (!empty($vp)) {
                    arsort($vp); $max = reset($vp);
                    $leaders = array_keys(array_filter($vp, function($v) use ($max) { return $v === $max; }));
                } else $leaders = [];
                $vpStr = '';
                foreach ($vp as $k => $v) $vpStr .= $k . '=' . $v . ' ';
                index_log('PHASE2: Complete (varPoints=' . trim($vpStr) . ', leaders=' . implode(',', $leaders) . ')');

                if (count($leaders) <= 1) {
                    $final = $leaders ? $leaders[0] : '';
                    $_SESSION['log_row']['finalVar'] = $final;
                    if (!empty($_SESSION['InviteId'])) invite_update_status($_SESSION['InviteId'], 'completed');
                    write_full_log_row($_SESSION['log_row']);
                    $_SESSION['state'] = 'done2';
                    index_log('STATE CHANGE: phase2 -> done2 (finalVar=' . $final . ', leaders=' . implode(',', $leaders) . ')');
                } else {
                    natsort($leaders);
                    $pair = array_slice(array_values($leaders), 0, 2);
                    $_SESSION['tb2_pair'] = $pair;

                    $allTB  = load_phase2_tiebreak_all(PHASE2_FILE_BASE);
                    $track  = $_SESSION['track'] ?? 'B2';
                    $letter = strtoupper($track[0]);
                    $group  = 'T'.$letter.'2';

                    $cand = $allTB[$group] ?? [];
                    $filtered = [];
                    foreach ($cand as $c) {
                        $p = $c['pair'] ?? null; if (!$p || count($p)<2) continue;
                        if ( ( (string)$p[0]===(string)$pair[0] && (string)$p[1]===(string)$pair[1] ) ||
                             ( (string)$p[0]===(string)$pair[1] && (string)$p[1]===(string)$pair[0] ) ) {
                            $filtered[] = $c;
                        }
                    }
                    if (!empty($filtered)) {
                        shuffle($filtered);
                        $_SESSION['tb2_set']   = array_slice($filtered, 0, TB2_MAX);
                        $_SESSION['tb2_pairs'] = [];
                        $_SESSION['tb2_index'] = 0;
                        $_SESSION['state']     = 'phase2_tb';
                        index_log('STATE CHANGE: phase2 -> phase2_tb (tie between ' . implode(',', $pair) . ', tb_cases=' . count($_SESSION['tb2_set']) . ')');
                    } else {
                        $pick = $pair[array_rand($pair)];
                        $_SESSION['log_row']['finalVar'] = $pick;
                        if (!empty($_SESSION['InviteId'])) invite_update_status($_SESSION['InviteId'], 'completed');
                        write_full_log_row($_SESSION['log_row']);
                        $_SESSION['state'] = 'done2';
                        index_log('STATE CHANGE: phase2 -> done2 (tie resolved randomly, finalVar=' . $pick . ')');
                    }
                }
            }
        } else {
            $_SESSION['log_row']['finalVar'] = '';
            if (!empty($_SESSION['InviteId'])) invite_update_status($_SESSION['InviteId'], 'completed');
            write_full_log_row($_SESSION['log_row']);
            $_SESSION['state'] = 'done2';
            index_log('STATE CHANGE: phase2 -> done2 (missing block, finalVar=empty)');
        }
    }
}

/* Vaihe 2 tiebreak */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_SESSION['state'] ?? '') === 'phase2_tb') && isset($_POST['tb2_choice'])) {
    $raw = (int)$_POST['tb2_choice'];
    if ($raw >= -4 && $raw <= 4) {
        $i   = (int)($_SESSION['tb2_index'] ?? 0);
        $blk = $_SESSION['tb2_set'][$i] ?? null;
        if ($blk) {
            $entry = '';
            if ($raw !== 0) {
                $add = abs($raw);
                $var = ($raw < 0) ? $blk['left_var'] : $blk['right_var'];
                $_SESSION['varPoints'][$var] = ($_SESSION['varPoints'][$var] ?? 0) + $add;
                $entry = 'T'.$var.'-'.$add;
            }
            $_SESSION['tb2_pairs'][] = $entry;
            $_SESSION['tb2_index']   = $i+1;
            index_log('PHASE2_TB ANSWER: tb' . ($i+1) . '/' . count($_SESSION['tb2_set']) . ' choice=' . $raw . ' left=' . $blk['left_var'] . ' right=' . $blk['right_var'] . ' (next_index=' . $_SESSION['tb2_index'] . ')');

            if ($_SESSION['tb2_index'] >= count($_SESSION['tb2_set'])) {
                $vp = $_SESSION['varPoints'] ?? [];
                if (!empty($vp)) {
                    arsort($vp); $max = reset($vp);
                    $leaders = array_keys(array_filter($vp, function($v) use ($max) { return $v === $max; }));
                } else $leaders = [];
                $vpStr = '';
                foreach ($vp as $k => $v) $vpStr .= $k . '=' . $v . ' ';
                index_log('PHASE2_TB: Complete (varPoints=' . trim($vpStr) . ', leaders=' . implode(',', $leaders) . ')');

                $final = '';
                if (count($leaders) === 1) $final = $leaders[0];
                elseif (count($leaders) > 1) $final = $leaders[array_rand($leaders)];

                $_SESSION['log_row']['finalVar'] = $final;
                if (!empty($_SESSION['InviteId'])) invite_update_status($_SESSION['InviteId'], 'completed');
                write_full_log_row($_SESSION['log_row']);
                $_SESSION['state'] = 'done2';
                index_log('STATE CHANGE: phase2_tb -> done2 (finalVar=' . $final . ' after tiebreak)');
            }
        }
    }
}

/* ====== INVITE token auto-claim URL-parametrilla (intro-tilassa) ====== */
if (INVITES_ENABLED && ($_SESSION['state'] ?? '') === 'intro' && isset($_GET['invite'])) {
    $token = (string)$_GET['invite'];
    if ($token !== '') {
        $items = invites_load();
        $inv = invite_find_by_token($items, $token);
        if ($inv && (($inv['status'] ?? '') === 'sent' || ($inv['status'] ?? '') === 'accepted')) {
            $_SESSION['RefGuessType'] = (string)($inv['guess_type'] ?? '');
            $_SESSION['RefGuessProb'] = (string)($inv['guess_prob'] ?? '');
            $_SESSION['InviteId']     = (string)($inv['id'] ?? '');
            if (($inv['status'] ?? '') === 'sent') invite_update_status($_SESSION['InviteId'], 'accepted');
            $_SESSION['invite_claim_info'] = 'Kutsu tunnistettu.';
            index_log('INVITE: Token accepted (id=' . $_SESSION['InviteId'] . ')');
        } else {
            $_SESSION['invite_claim_info'] = 'Kutsulinkki ei kelpaa tai on vanhentunut.';
            index_log('INVITE: Token invalid or expired');
        }
    }
}

?>
<!doctype html>
<html lang="fi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>ENNEGRAMMITESTI-pilotti</title>
<meta name="robots" content="noindex, nofollow">
<style>
:root{
  --card-w: 980px;
  --p1-left-bg:#ffe5ea; --p1-left-bd:#f5a8b5;
  --p1-right-bg:#e6f7e6; --p1-right-bd:#9cd39c;
  --left-bg:#fff7cc; --left-bd:#e6d88a;
  --right-bg:#e8f1ff; --right-bd:#a6c0f3;
}
*{box-sizing:border-box}
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:24px;background:#f2f2f2;color:#111;line-height:1.5}
.card{max-width:var(--card-w);margin:0 auto;padding:20px;border:1px solid #ddd;border-radius:14px;box-shadow:0 1px 6px rgba(0,0,0,.06);background:#fff}
.header{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:8px}
.logo{height:96px;width:auto;object-fit:contain}
.small{font-size:12px;color:#666}
.badge{padding:2px 8px;border-radius:999px;background:#eef;border:1px solid #ccd;font-size:12px}
.block{padding:12px;border:1px solid #eee;border-radius:10px;background:#fcfcfc}
.lead{font-size:22px;margin:16px 0}
.leadLarge{font-size:28px;margin:16px 0}
.row{display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:space-between}
.progress{width:100%;height:12px;background:#e9ecf3;border-radius:8px;overflow:hidden}
.progress-bar{height:100%;background:linear-gradient(90deg,#6b8cff,#3f6bff);border-radius:8px;transition:width .25s ease}
.spacer{height:28px}
.btn{display:inline-block;padding:12px 16px;border:1px solid #ccc;border-radius:10px;background:#fafafa;cursor:pointer;font-size:18px;min-width:48px;min-height:48px;text-align:center;color:#000;-webkit-text-fill-color:#000;-webkit-appearance:none;appearance:none}
.btn:hover{background:#f0f0f0}.btn:active{transform:translateY(1px)}
.linkbtn{display:inline-block;padding:12px 16px;border:1px solid #ccc;border-radius:10px;background:#fafafa;text-decoration:none;color:#000;-webkit-text-fill-color:#000}
.linkbtn:hover{background:#f0f0f0}
.muted{color:#444;font-size:15px}
.sep{padding:0 6px;color:#999}
.btn-p1-left{background:var(--p1-left-bg);border-color:var(--p1-left-bd);color:#000;-webkit-text-fill-color:#000}
.btn-p1-left:hover{background:#ffd3dc}
.btn-p1-right{background:var(--p1-right-bg);border-color:var(--p1-right-bd);color:#000;-webkit-text-fill-color:#000}
.btn-p1-right:hover{background:#dff3df}
.case-wrap{display:grid;grid-template-columns:1fr auto 1fr;grid-auto-rows:auto;gap:12px;align-items:center;justify-items:center}
.case-desc{grid-column:1 / -1;font-size:18px}
.scale-row{grid-column:1 / -1;display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:8px}
.scale-left,.scale-right{white-space:nowrap;overflow-x:auto;display:inline-flex;gap:8px;flex-wrap:nowrap}
.scale-zero{display:flex;align-items:center;gap:8px}
.scale-zero .bar{font-weight:700;color:#999}
.btn-left{background:var(--left-bg);border-color:var(--left-bd);color:#000;-webkit-text-fill-color:#000}
.btn-left:hover{background:#fff2a8}
.btn-right{background:var(--right-bg);border-color:var(--right-bd);color:#000;-webkit-text-fill-color:#000}
.btn-right:hover{background:#dfe9ff}
.btn-zero{background:#f5f5f5;border-color:#cfcfcf;color:#000;-webkit-text-fill-color:#000}
.opt-left{grid-column:1/2;justify-self:stretch}
.opt-right{grid-column:3/4;justify-self:stretch}
.opt-box{border-radius:12px;border:1px solid transparent;padding:10px;font-size:18px;word-break:break-word;color:#000}
.opt-box.left{background:var(--left-bg);border-color:var(--left-bd);text-align:left}
.opt-box.right{background:var(--right-bg);border-color:var(--right-bd);text-align:right}
.help{grid-column:1/-1;color:#555}
.debug{margin-top:10px;padding:10px;border:1px dashed #bbb;background:#fafcff;border-radius:10px;font-size:14px}
.footer{margin-top:16px;display:flex;gap:12px;flex-wrap:wrap;justify-content:flex-end;align-items:center}
.credits{margin-top:56px;text-align:center;color:#6b6b6b;font-size:12px}
.center-row{display:flex;justify-content:center;gap:8px;flex-wrap:nowrap}
.textarea{width:100%;min-height:120px;padding:10px;border:1px solid #ccc;border-radius:10px;font-size:16px;resize:vertical}
.alert{padding:10px;border-radius:8px;margin:10px 0}
.alert-ok{background:#e7f7e7;border:1px solid #a7d3a7}
.alert-err{background:#fdeaea;border:1px solid #e7b0b0}
@media (max-width:768px){.card{padding:16px}.logo{height:72px}.opt-box,.case-desc{font-size:17px}}
@media (max-width:520px){.logo{height:60px}.btn{font-size:17px;min-width:44px;min-height:44px}}
</style>
</head>
<body>
<div class="card">
  <div class="header" role="banner">
    <div>
      <h1>ENNEGRAMMITESTI-pilotti</h1>
      <?php if ($SHOW_TEST_TOGGLE): ?>
      <div class="small">
        Testimoodi:
        <?php if ($TEST_MODE): ?>
          <span class="badge" aria-live="polite">Päällä</span>
          <a href="?test=0<?= isset($_GET['showtestmode'])?'&showtestmode=1':'' ?>">Poista</a> •
          <a href="?test=1&amp;admin=1<?= isset($_GET['showtestmode'])?'&showtestmode=1':'' ?>">Admin-tarkistus</a>
        <?php else: ?>
          <span class="badge" style="background:#fee;border-color:#fbb;" aria-live="polite">Pois</span>
          <a href="?test=1<?= isset($_GET['showtestmode'])?'&showtestmode=1':'' ?>">Kytke päälle</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
    <img src="logo.png" alt="Suomen Enneagrammiyhdistys – logo" class="logo">
  </div>

<?php if (($_SESSION['state'] ?? '') === 'intro'):
    $oldNick  = h($_POST['nickname'] ?? ($_SESSION['nickname'] ?? ''));
    $oldEnnea = (string)($_POST['ennea'] ?? 'en tiedä');
    $inviteInfo = $_SESSION['invite_claim_info'] ?? '';
    $haveToken = !empty($_SESSION['InviteId']);
    $oldCode = h($_POST['invite_code'] ?? '');
?>
  <h2>Tervetuloa</h2>
  <p class="lead">
    Tämä pilottitesti auttaa hahmottamaan, mikä oma <strong>enneagrammityylisi</strong> voisi olla.
  </p>
  <div class="block" role="region" aria-label="Tietoa testistä">
    <p><strong>Mitä haemme?</strong><br>Tavoitteena on muodostaa alustava näkemys juuri sinulle sopivimmasta enneagrammityylistä.</p>
    <p><strong>Miten testi etenee?</strong><br>Ensin vastaat väittämiin asteikolla 1–6. Jos ykkösvaihe päätyy tasatilanteeseen, kysymme lisäkysymyksiä tasatilanteen luokista. Joissakin tapauksissa jatkat vaiheeseen 2, jossa arvioit tilanteita kahden vaihtoehdon välillä asteikolla 0 … 4.</p>
    <p><strong>GDPR (tiivistelmä)</strong><br>Talletamme nimimerkin, vastaukset ja lasketut pisteet testituloksen muodostamiseksi. Sähköpostia ei kerätä tässä vaiheessa. Tietoja ei luovuteta ulkopuolisille ja poistamme ne pyynnöstä.</p>
  </div>

  <?php if ($TEST_MODE): 
    $phase1_all_preview = load_phase1_questions(PHASE1_FILE_BASE);
    $phase1_preview_path = csv_path(PHASE1_FILE_BASE);
    $phase1_preview_count = count($phase1_all_preview);
    $type_desc_path = csv_path(TYPEDESC_FILE_BASE);
  ?>
    <p class="small" style="margin-top:8px">
      CSV (vaihe 1): <strong><?= $phase1_preview_path ? h($phase1_preview_path) : 'ei löytynyt' ?></strong> — A1/B1/C1/D1 yhteensä: <strong><?= h($phase1_preview_count) ?></strong><br>
      TypeDescriptions: <strong><?= $type_desc_path ? h($type_desc_path) : 'ei löytynyt' ?></strong><br>
      Loki: <strong><?= h(log_path()) ?></strong> — Sovellusloki: <strong><?= h(index_app_log_path()) ?></strong><br>
      Kutsurekisteri: <strong><?= h(invites_path()) ?></strong>
    </p>
  <?php endif; ?>

  <?php if (!empty($inviteInfo) && $TEST_MODE): ?>
    <p class="small" style="color:#055;"><?= h($inviteInfo) ?></p>
  <?php endif; ?>

  <?php if (!empty($error)): ?>
    <p style="color:#b00" role="alert"><strong><?= h($error) ?></strong></p>
  <?php endif; ?>

  <form method="post" novalidate style="margin-top:12px">
    <?php if (INVITES_ENABLED && !$haveToken): ?>
      <label for="invite_code">Kutsukoodi (jos sait kutsun)</label>
      <input type="text" id="invite_code" name="invite_code" value="<?=$oldCode?>" placeholder="Esim. ABCD1234">
      <div style="height:14px"></div>
    <?php endif; ?>

    <label for="nickname">Nimimerkki</label>
    <input type="text" id="nickname" name="nickname" value="<?=$oldNick?>" autocomplete="nickname">

    <label for="ennea" style="margin-top:12px">Mikä on sinun enneagrammityylisi?</label>
    <select id="ennea" name="ennea">
      <option value="en tiedä" <?=$oldEnnea===''||$oldEnnea==='en tiedä'?'selected':''?>>en tiedä</option>
      <?php for ($i=1;$i<=9;$i++): ?>
        <option value="<?=$i?>" <?=$oldEnnea===(string)$i?'selected':''?>><?=$i?></option>
      <?php endfor; ?>
    </select>

    <div class="footer">
      <button class="btn" type="submit" name="start" value="1">Aloita kysely</button>
    </div>
  </form>

<?php elseif (($_SESSION['state'] ?? '') === 'quiz1'):
    $set = $_SESSION['phase1'] ?? []; $total = count($set);
    $i   = (int)$_SESSION['q1_index'] ?? 0;
    $pct = ($total>0) ? (int)floor(($i/$total)*100) : 0;
    if ($total === 0): ?>
      <p style="color:#b00"><strong>Tiedostosta ei löytynyt kysymyksiä.</strong></p>
    <?php else:
      if ($i<0 || $i>=$total){$i=0;$_SESSION['q1_index']=0;}
      $q = $set[$i];
    ?>
    <div class="row"><h2>Vaihe 1</h2>
      <div style="flex:1 1 100%">
        <div class="progress" aria-label="Eteneminen vaihe 1" title="<?= $pct ?>%"><div class="progress-bar" style="width:<?= $pct ?>%"></div></div>
      </div>
    </div>
    <div class="spacer"></div>
    <div class="block"><p style="font-size:18px;margin:0"><?= h($q['text']) ?></p></div>

    <div class="center-row" style="margin-top:8px">
      <?php for ($n=1;$n<=6;$n++): ?>
        <?php if ($n===4): ?><span class="sep" aria-hidden="true">|</span><?php endif; ?>
        <form method="post" style="display:inline">
          <input type="hidden" name="choice" value="<?=$n?>">
          <?php $cls = ($n<=3)?'btn-p1-left':'btn-p1-right'; ?>
          <button class="btn <?=$cls?>" type="submit" aria-label="Valinta <?=$n?>"><?=$n?></button>
        </form>
      <?php endfor; ?>
    </div>
    <p class="muted">Valitse asteikolta mielipidettäsi tai asennettasi parhaiten kuvaava vaihtoehto. 1=täysin eri mieltä … 6=täysin samaa mieltä</p>

    <?php if ($TEST_MODE): ?>
      <div class="debug"><strong>Luokka (piilossa):</strong> <?= h($q['class']) ?> — id: <?= h($q['id']) ?></div>
    <?php endif; ?>

    <?php endif; ?>

<?php elseif (($_SESSION['state'] ?? '') === 'tiebreak'):
    $set = $_SESSION['tiebreak_set'] ?? []; $total = count($set);
    $i   = (int)($_SESSION['tiebreak_index'] ?? 0);
    $pct = ($total>0) ? (int)floor(($i/$total)*100) : 0;
?>
  <div class="row"><h2>Vaihe 1</h2>
    <div style="flex:1 1 100%">
      <div class="progress" aria-label="Lisäkysymysten eteneminen" title="<?= $pct ?>%"><div class="progress-bar" style="width:<?= $pct ?>%"></div></div>
    </div>
  </div>
  <div class="spacer"></div>
  <?php if ($total === 0): ?>
    <p class="muted">Lisäkysymyksiä ei löytynyt. Jatketaan pisteillä.</p>
    <form method="post"><button class="btn" name="tb_choice" value="4">Jatka</button></form>
  <?php else:
      $q = $set[$i] ?? null;
      if ($q): ?>
      <div class="block"><p style="font-size:18px;margin:0 0 8px 0"><?= h($q['text']) ?></p></div>
      <div class="center-row">
        <?php for ($n=1;$n<=6;$n++): ?>
          <?php if ($n===4): ?><span class="sep" aria-hidden="true">|</span><?php endif; ?>
          <form method="post" style="display:inline">
            <input type="hidden" name="tb_choice" value="<?=$n?>">
            <?php $cls = ($n<=3)?'btn-p1-left':'btn-p1-right'; ?>
            <button class="btn <?=$cls?>" type="submit" aria-label="Valinta <?=$n?>"><?=$n?></button>
          </form>
        <?php endfor; ?>
      </div>
      <p class="muted">Valitse asteikolta mielipidettäsi tai asennettasi parhaiten kuvaava vaihtoehto. 1=täysin eri mieltä … 6=täysin samaa mieltä</p>
    <?php endif; endif; ?>

<?php elseif (($_SESSION['state'] ?? '') === 'a1_result'): ?>
  <?php if ($TEST_MODE): ?>
    <div class="debug">
      <strong>Vaihe 1 pisteet:</strong>
      A1: <?= (int)($_SESSION['points1']['A1']??0) ?>,
      B1: <?= (int)($_SESSION['points1']['B1']??0) ?>,
      C1: <?= (int)($_SESSION['points1']['C1']??0) ?>,
      D1: <?= (int)($_SESSION['points1']['D1']??0) ?><br>
      <strong>“Kyllä” (4–6):</strong>
      A1: <?= (int)($_SESSION['yesCounts1']['A1']??0) ?>,
      B1: <?= (int)($_SESSION['yesCounts1']['B1']??0) ?>,
      C1: <?= (int)($_SESSION['yesCounts1']['C1']??0) ?>,
      D1: <?= (int)($_SESSION['yesCounts1']['D1']??0) ?>
    </div>
  <?php endif; ?>
  <p class="leadLarge">Enneagrammityylisi tämän testin perusteella vaikuttaisi olevan TYYLI 4</p>
  <?php 
    $type_desc_map = load_type_descriptions(TYPEDESC_FILE_BASE);
    $desc = $type_desc_map['4'] ?? ''; 
    if ($desc!==''): ?>
    <div class="block"><p style="margin:0"><?= h($desc) ?></p></div>
  <?php else: ?>
    <p class="muted">Kuvausta ei löytynyt tyypille 4.</p>
  <?php endif; ?>
  <div class="footer">
    <?php $freshHref = ($TEST_MODE ? '?fresh=1&amp;test=1' : '?fresh=1') . ($SHOW_TEST_TOGGLE?'&showtestmode=1':''); ?>
    <a class="linkbtn" href="<?= $freshHref ?>">Aloita täysin alusta</a>
    <a class="linkbtn" href="https://www.enneagram.fi/tietoa-enneagrammista/" target="_blank" rel="noopener">Lue lisää enneagrammista</a>
    <?php if (empty($_SESSION['feedback_given'])): ?>
    <form method="post" style="display:inline">
      <button class="linkbtn" type="submit" name="show_feedback" value="1">Anna palautetta</button>
    </form>
    <?php endif; ?>
  </div>

  <?php
    $feedback_show = !empty($_SESSION['show_feedback']) && empty($_SESSION['feedback_given']);
    if ($feedbackMsg !== '') echo '<div class="alert alert-ok">'.h($feedbackMsg).'</div>';
    if ($feedbackErr !== '') echo '<div class="alert alert-err">'.h($feedbackErr).'</div>';
    if ($feedback_show):
  ?>
    <form method="post" style="margin-top:12px">
      <label for="feedback_text"><strong>Palaute</strong></label>
      <textarea id="feedback_text" name="feedback_text" class="textarea" placeholder="Kirjoita palautteesi tähän..."><?= h($oldFeedbackText) ?></textarea>
      <div class="footer">
        <button class="linkbtn" type="submit" name="send_feedback" value="1">Lähetä</button>
      </div>
    </form>
  <?php endif; ?>

<?php elseif (($_SESSION['state'] ?? '') === 'phase2'):
    $i     = (int)($_SESSION['q2_index'] ?? 0);
    $blk   = $_SESSION['blocks2'][$i] ?? null;
    $track = $_SESSION['track'] ?? '';
    $total = count($_SESSION['blocks2'] ?? []);
    $pct   = ($total>0) ? (int)floor(($i/$total)*100) : 0;
?>
  <div class="row">
    <?php if ($TEST_MODE): ?><h2>Vaihe 2, tapaus <?= h($i+1) ?> (<?= h($track) ?>)</h2>
    <?php else: ?><h2>Vaihe 2</h2><?php endif; ?>
    <div style="flex:1 1 100%"><div class="progress" aria-label="Eteneminen vaihe 2" title="<?= $pct ?>%"><div class="progress-bar" style="width:<?= $pct ?>%"></div></div></div>
  </div>
  <div class="spacer"></div>

  <?php if ($blk): ?>
    <div class="case-wrap">
      <div class="case-desc"><?= h($blk['desc']) ?></div>
      <div class="scale-row" role="group" aria-label="Asteikko">
        <div class="scale-left">
          <?php for ($v=-4;$v<=-1;$v++): ?>
            <form method="post" style="display:inline-block">
              <input type="hidden" name="choice2" value="<?=$v?>">
              <button class="btn btn-left" type="submit" aria-label="Valinta <?=$v?>"><?= -$v ?></button>
            </form>
          <?php endfor; ?>
        </div>
        <div class="scale-zero"><span class="bar" aria-hidden="true">|</span>
          <form method="post" style="display:inline-block">
            <input type="hidden" name="choice2" value="0">
            <button class="btn btn-zero" type="submit" aria-label="Valinta 0">0</button>
          </form>
          <span class="bar" aria-hidden="true">|</span></div>
        <div class="scale-right">
          <?php for ($v=1;$v<=4;$v++): ?>
            <form method="post" style="display:inline-block">
              <input type="hidden" name="choice2" value="<?=$v?>">
              <button class="btn btn-right" type="submit" aria-label="Valinta <?=$v?>"><?= $v ?></button>
            </form>
          <?php endfor; ?>
        </div>
      </div>
      <div class="opt-left"><div class="opt-box left"><?= h($blk['left_text']) ?></div></div>
      <div class="opt-right"><div class="opt-box right"><?= h($blk['right_text']) ?></div></div>
      <div class="help"><p class="muted" style="margin:6px 0 0">Valitse kumpi laita kuvaa sinua ja kuinka hyvin asteikolla 0 … 4 (0 = kumpikaan ei kuvaa, 4 = täysin samaa mieltä).</p></div>
    </div>
  <?php else: ?>
    <p class="muted">Jatkolohkoja ei löytynyt (<?= h($track) ?>).</p>
  <?php endif; ?>

<?php elseif (($_SESSION['state'] ?? '') === 'phase2_tb'):
    $i     = (int)($_SESSION['tb2_index'] ?? 0);
    $blk   = $_SESSION['tb2_set'][$i] ?? null;
    $total = count($_SESSION['tb2_set'] ?? []);
    $pct   = ($total>0) ? (int)floor(($i/$total)*100) : 0;
    $pair  = $_SESSION['tb2_pair'] ?? [];
?>
  <div class="row"><h2>Vaihe 2</h2>
    <div style="flex:1 1 100%"><div class="progress" aria-label="Vaihe 2 eteneminen" title="<?= $pct ?>%"><div class="progress-bar" style="width:<?= $pct ?>%"></div></div></div>
  </div>
  <div class="spacer"></div>

  <?php if ($TEST_MODE): ?>
    <div class="debug"><strong>TB2-pari:</strong> <?= h(implode(' vs ', $pair)) ?> — caset: <?= (int)$total ?></div>
  <?php endif; ?>

  <?php if ($blk): ?>
    <div class="case-wrap">
      <div class="case-desc"><?= h($blk['desc']) ?></div>
      <div class="scale-row" role="group" aria-label="Tiebreak asteikko">
        <div class="scale-left">
          <?php for ($v=-4;$v<=-1;$v++): ?>
            <form method="post" style="display:inline-block">
              <input type="hidden" name="tb2_choice" value="<?=$v?>">
              <button class="btn btn-left" type="submit" aria-label="Valinta <?=$v?>"><?= -$v ?></button>
            </form>
          <?php endfor; ?>
        </div>
        <div class="scale-zero"><span class="bar" aria-hidden="true">|</span>
          <form method="post" style="display:inline-block">
            <input type="hidden" name="tb2_choice" value="0">
            <button class="btn btn-zero" type="submit" aria-label="Valinta 0">0</button>
          </form>
          <span class="bar" aria-hidden="true">|</span></div>
        <div class="scale-right">
          <?php for ($v=1;$v<=4;$v++): ?>
            <form method="post" style="display:inline-block">
              <input type="hidden" name="tb2_choice" value="<?=$v?>">
              <button class="btn btn-right" type="submit" aria-label="Valinta <?=$v?>"><?= $v ?></button>
            </form>
          <?php endfor; ?>
        </div>
      </div>
      <div class="opt-left"><div class="opt-box left"><?= h($blk['left_text']) ?></div></div>
      <div class="opt-right"><div class="opt-box right"><?= h($blk['right_text']) ?></div></div>
      <div class="help"><p class="muted" style="margin:6px 0 0">Valitse kumpi laita kuvaa sinua ja kuinka hyvin asteikolla 0 … 4 (0 = kumpikaan ei kuvaa, 4 = täysin samaa mieltä).</p></div>
    </div>
  <?php else: ?>
    <p class="muted">Tiebreak-caseja ei löydy valitulle parille. Ratkaistaan satunnaisesti.</p>
  <?php endif; ?>

<?php elseif (($_SESSION['state'] ?? '') === 'done2'):
    $vars = $_SESSION['varPoints'] ?? []; arsort($vars);
    $nonzero = array_filter($vars, function($v) { return $v > 0; });
    $topVar  = $_SESSION['log_row']['finalVar'] ?? ( $nonzero ? key($nonzero) : '' );
    $type_desc_map = load_type_descriptions(TYPEDESC_FILE_BASE);
    $desc    = ($topVar!=='') ? ($type_desc_map[(string)$topVar] ?? '') : '';
?>
  <?php if ($TEST_MODE): ?>
    <h2>Muuttujapisteet (> 0)</h2>
    <?php if ($nonzero): ?>
      <div class="row" style="gap:8px;flex-wrap:wrap">
        <?php foreach ($nonzero as $k=>$v): ?><div class="block" style="padding:8px 12px"><?= h($k) ?>: <?= h($v) ?></div><?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="muted">Ei kertynyt positiivisia muuttujapisteitä.</p>
    <?php endif; ?>
  <?php endif; ?>

  <p class="leadLarge">Enneagrammityylisi tämän testin perusteella vaikuttaisi olevan TYYLI <?= h($topVar !== '' ? $topVar : '—') ?></p>
  <?php if ($desc!==''): ?>
    <div class="block"><p style="margin:0"><?= h($desc) ?></p></div>
  <?php else: ?>
    <p class="muted">Kuvausta ei löytynyt tälle tyypille.</p>
  <?php endif; ?>
  <div class="footer">
    <?php $freshHref = ($TEST_MODE ? '?fresh=1&amp;test=1' : '?fresh=1') . ($SHOW_TEST_TOGGLE?'&showtestmode=1':''); ?>
    <a class="linkbtn" href="<?= $freshHref ?>">Aloita täysin alusta</a>
    <a class="linkbtn" href="https://www.enneagram.fi/tietoa-enneagrammista/" target="_blank" rel="noopener">Lue lisää enneagrammista</a>
    <?php if (empty($_SESSION['feedback_given'])): ?>
    <form method="post" style="display:inline">
      <button class="linkbtn" type="submit" name="show_feedback" value="1">Anna palautetta</button>
    </form>
    <?php endif; ?>
  </div>

  <?php
    $feedback_show = !empty($_SESSION['show_feedback']) && empty($_SESSION['feedback_given']);
    if ($feedbackMsg !== '') echo '<div class="alert alert-ok">'.h($feedbackMsg).'</div>';
    if ($feedbackErr !== '') echo '<div class="alert alert-err">'.h($feedbackErr).'</div>';
    if ($feedback_show):
      $prefill = $oldFeedbackText !== '' ? $oldFeedbackText : '';
  ?>
    <form method="post" style="margin-top:12px">
      <label for="feedback_text"><strong>Palaute</strong></label>
      <textarea id="feedback_text" name="feedback_text" class="textarea" placeholder="Kirjoita palautteesi tähän..."><?= h($prefill) ?></textarea>
      <div class="footer">
        <button class="linkbtn" type="submit" name="send_feedback" value="1">Lähetä</button>
      </div>
    </form>
  <?php endif; ?>

<?php endif; ?>

  <div class="credits">© Suomen Enneagrammiyhdistys, 2025</div>
</div>

<!-- Näppäimistöoikotiet poistettu pyynnöstä -->
</body>
</html>

