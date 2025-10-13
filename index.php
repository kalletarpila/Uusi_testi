<?php declare(strict_types=1);
/**
 * Yksinkertainen sähköpostin lähetys palautteelle
 *
 * @param string $to Vastaanottajan sähköposti
 * @param string $subject Aihe
 * @param string $body Viesti
 * @return bool Onnistuiko lähetys
 */
function send_mail(string $to, string $subject, string $body): bool {
  $headers = 'From: noreply@enneagrammitesti.fi' . "\r\n" .
         'Reply-To: noreply@enneagrammitesti.fi' . "\r\n" .
         'Content-Type: text/plain; charset=UTF-8' . "\r\n" .
         'X-Mailer: PHP/' . phpversion();
  return mail($to, $subject, $body, $headers);
}
// Palauttaa kutsurekisterin polun
function invites_path(): string {
  // Always use data directory for invites.json
  $dataPath = data_dir() . '/invites.json';
  return file_exists($dataPath) ? $dataPath : csv_path('invites.json');
}

/**
 * ENNEAGRAMMITESTI-PILOTTI
 * 
 * Kaksivaiheinen enneagrammitest, joka määrittää käyttäjän persoonallisuustyypin
 * laajan kysymyssarjan ja algoritmin avulla. Integroituu kutsujärjestelmään.
 * 
 * OMINAISUUDET:
 * - Kaksivaiheinen testausjärjestelmä (vaihe 1: perustyyppien kartoitus, vaihe 2: tarkentava analyysi)
 * - Tiebreak-kierrokset tasatilanteissa
 * - CSV-pohjainen kysymystietokanta välimuistituksella
 * - Kutsulinkkien integraatio invite.php:n kanssa
 * - Kattava lokitus ja virheenkäsittely
 * - Responsiivinen käyttöliittymä ripple-efekteillä
 * - Turvallinen session-hallinta
 * 
 * ARKITEHTUURIPERIAATTEET:
 * - Single responsibility principle: Jokainen funktio tekee yhden asian
 * - DRY (Don't Repeat Yourself): Toisteisuus minimoitu HTML-generointifunktioilla
 * - Error handling: Keskitetty virheenkäsittely try-catch-lohkoilla
 * - Caching: CSV-data ladataan vain kerran session aikana
 * - Security: Session-validointi, input-sanitointi, CSRF-suojaus
 * 
 * TIEDOSTORAKENNE:
 * - /quiz1.csv: Vaiheen 1 kysymykset (A1, B1, C1, D1 kategoriat)
 * - /quiz_phase2_*.csv: Vaiheen 2 kysymykset rateittain (B2, C2, D2)
 * - /types.csv: Persoonallisuustyyppien kuvaukset
 * - /logs/: Tulosloki CSV-muodossa
 * 
 * TEKIJÄ: Refaktoroitu GitHub Copilotin toimesta (2024)
 * VERSIO: 2.0 (refaktoroitu)
 * PHP: 8.0+
 */

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
// Session aloitetaan session_initialize() funktiossa myöhemmin

/* Välimuistin esto */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

/* Kovennusotsikot */
$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'nonce-$nonce'; frame-src 'self' https://www.youtube.com; base-uri 'self'; form-action 'self'; frame-ancestors 'none';");
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: geolocation=(), camera=(), microphone=()');

/* ========= Konfiguraatio ========= */
const PHASE1_FILE_BASE   = 'SeparateBlocksQuestions';
const PHASE2_FILE_BASE   = 'InsideBlockQuestions';
const TYPEDESC_FILE_BASE = 'TypeDescriptions'; // tyyppikuvaukset

// Count only options 5 and 6 as a "yes" for tiebreak heuristics
const YES_MIN     = 5;
const YES_MAX     = 6;

const Q1_MAX      = 40;
const TB_MAX      = 40;
const VARPAIR_MAX = 40;
const TB2_MAX     = 40;

const INVITES_ENABLED = true;

/* Palautteen lähetys */
const FEEDBACK_TO  = 'eg-testi.palaute@enneagrammitesti.fi';
const FROM_NAME    = 'Enneagrammitesti';
const FROM_EMAIL   = 'no-reply@enneagrammitesti.fi';

/* ====== TIEDOSTOPOLUT JA LOKITUS ====== */

/**
 * Hakee tai luo data-hakemiston polun
 * Yrittää ensin hakemistoa ylätasolta, sitten nykyisestä hakemistosta
 * 
 * @return string Absoluuttinen polku data-hakemistoon
 */
function data_dir(): string {
    $pref = realpath(__DIR__ . '/../') !== false ? (__DIR__ . '/../data') : (__DIR__ . '/data');
    if (!is_dir($pref)) @mkdir($pref, 0700, true);
    return $pref;
}

/**
 * Hakee sovelluksen lokitiedoston polun kuukauden mukaan
 * 
 * @return string Lokitiedoston polku muodossa '/data/index_app_YYYY-MM.log'
 */
function index_app_log_path(): string { 
    return data_dir() . '/index_app_' . date('Y-m') . '.log'; 
}

/**
 * Kirjoittaa lokiviestin sovelluksen lokitiedostoon IP-osoitteella ja aikaleimalla
 * 
 * @param string $msg Lokitettava viesti
 * @return void
 */
function index_log(string $msg): void {
    $ts = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    @file_put_contents(index_app_log_path(), '['.$ts.']['.$ip.'] '.$msg.PHP_EOL, FILE_APPEND | LOCK_EX);
}

/* ====== SÄHKÖPOSTIFUNKTIOT ====== */

/**
 * Lähettää sähköpostiviestin käyttäen PHP:n mail()-funktiota
 * Enkoodaa aihekenttä UTF-8 BASE64-muodossa Unicode-yhteensopivuuteen

        <?php
        // Näytä "Seuraava (lisäkysymykset)" -painike vain viimeisen vaiheen 1 kysymyksen yhteydessä
        $canGoToTiebreak = false;
        if (
          $i === $total - 1 &&
          !empty($_SESSION['last_tiebreak_set']) &&
          !empty($_SESSION['last_tiebreak_answers']) &&
          check_tiebreak_needed()
        ) {
          // Varmista että tiebreak-luokat vastaavat nykyisiä pisteitä
          $pts = $_SESSION['points1'] ?? [];
          arsort($pts);
          $max = reset($pts);
          $tops = array_keys(array_filter($pts, function($v) use ($max) { return $v === $max; }));
          $last_classes = [];
          foreach ($_SESSION['last_tiebreak_set'] as $tbq) {
            if (isset($tbq['class']) && !in_array($tbq['class'], $last_classes)) {
              $last_classes[] = $tbq['class'];
            }
          }
          // Jos tiebreak-luokat vastaavat nykyisiä pisteitä, näytä nappi
          if (count(array_diff($tops, $last_classes)) === 0 && count($tops) === count($last_classes)) {
            $canGoToTiebreak = true;
          }
        }
        if ($canGoToTiebreak) {
          echo '<form method="post" style="margin: 0;">';
          echo '<input type="hidden" name="return_to_tiebreak" value="1">';
          echo '<button type="submit" class="btn" style="font-size: 14px; padding: 8px 12px; background: #e7f3ff; color: #0066cc; border: 1px solid #b3d7ff;">';
          echo 'Seuraava (lisäkysymykset)';
          echo '</button>';
          echo '</form>';
        }
        ?>
 * Lataa kutsut JSON-tiedostosta tiedostolukolla
 * 
 * @return array Lista kutsuista tai tyhjä array jos tiedosto puuttuu tai on virheellinen
 */
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

/**
 * Tallentaa kutsulistan JSON-tiedostoon tiedostolukolla
 * 
 * @param array $items Lista kutsuista tallennettavaksi
 * @return bool True jos tallennus onnistui, false muuten
 */
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

/**
 * Etsii kutsun token-merkkijonon perusteella
 * 
 * @param array $items Lista kutsuista
 * @param string $token Etsittävä token
 * @return array|null Kutsu tai null jos ei löydy
 */
function invite_find_by_token(array $items, string $token): ?array {
    foreach ($items as $it) if (($it['token'] ?? null) === $token) return $it;
    return null;
}

/**
 * Etsii kutsun koodin perusteella (case-insensitive)
 * 
 * @param array $items Lista kutsuista
 * @param string $code Etsittävä koodi
 * @return array|null Kutsu tai null jos ei löydy
 */
function invite_find_by_code(array $items, string $code): ?array {
    $code = strtoupper($code);
    foreach ($items as $it) if (strtoupper($it['code'] ?? '') === $code) return $it;
    return null;
}

/**
 * Päivittää kutsun statuksen ID:n perusteella
 * 
 * @param string $id Kutsun tunniste
 * @param string $status Uusi status (esim. 'sent', 'accepted', 'completed')
 * @return void
 */
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

/* ====== LOKITIEDOSTOJEN HALLINTA ====== */

/**
 * Hakee tuloslokin CSV-tiedoston polun
 * 
 * @return string Polku kuukausittaiseen tuloslokiin muodossa '/data/survey_log_YYYY-MM.csv'
 */
function log_path(): string {
    return data_dir() . '/survey_log_' . date('Y-m') . '.csv';
}

/* ====== TESTIMOODI JA KONFIGURAATIO ====== */

// Testimoodi: näyttää lisätietoja kehittäjälle
if (isset($_GET['test'])) {
    $_SESSION['test_mode'] = ($_GET['test'] === '1');
}
$TEST_MODE = !empty($_SESSION['test_mode']);
$SHOW_TEST_TOGGLE = isset($_GET['showtestmode']); // näytä kytkin vain jos parametri
$ADMIN_MODE = isset($_GET['admin']) && $_GET['admin'] == '1'; // admin-tarkistustila

/**
 * Performs a complete session reset while preserving specific test parameters
 * 
 * This function clears all session data and cookies while preserving the 'test'
 * and 'showtestmode' GET parameters for development and testing purposes.
 * 
 * @return void
 */
function hard_reset(): void {
    error_log("HARD_RESET: Function called");
    echo '<p>HARD_RESET: Starting...</p>';
    flush();
    
    $keep = [];
    if (isset($_GET['test'])) $keep['test'] = $_GET['test'];
    if (isset($_GET['showtestmode'])) $keep['showtestmode'] = $_GET['showtestmode'];
    if (isset($_GET['admin'])) $keep['admin'] = $_GET['admin'];
    
    error_log("HARD_RESET: Session status = " . session_status());
    if (session_status() === PHP_SESSION_ACTIVE) {
        error_log("HARD_RESET: Destroying active session");
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time()-42000, $p['path'], $p['domain'] ?? '', $p['secure'] ?? false, $p['httponly'] ?? true);
        }
        session_destroy();
        error_log("HARD_RESET: Session destroyed");
    }
    
    $base = strtok($_SERVER['REQUEST_URI'], '?');
    $qs   = $keep ? ('?' . http_build_query($keep)) : '';
    $redirectUrl = $base . $qs;
    
    error_log("HARD_RESET: Preparing redirect to: " . $redirectUrl);
    error_log("HARD_RESET: Headers sent status: " . (headers_sent() ? 'YES' : 'NO'));
    
    echo '<p>HARD_RESET: Redirecting to: ' . htmlspecialchars($redirectUrl) . '</p>';
    echo '<p>Headers sent: ' . (headers_sent() ? 'YES' : 'NO') . '</p>';
    flush();
    
    // Kokeillaan ensin HTTP header
    if (!headers_sent()) {
        error_log("HARD_RESET: Sending HTTP header redirect");
        header('Location: ' . $redirectUrl);
        echo '<p>HARD_RESET: HTTP header redirect sent</p>';
    } else {
        // Jos header ei onnistu, käytetään JavaScript redirect
        error_log("HARD_RESET: Headers already sent, using JavaScript redirect");
        echo '<p>HARD_RESET: Headers already sent, using JavaScript redirect</p>';
        echo '<script>
        console.log("JavaScript redirect to: ' . $redirectUrl . '");
        setTimeout(function() {
            window.location.href = ' . json_encode($redirectUrl) . ';
        }, 2000);
        </script>';
        echo '<noscript><meta http-equiv="refresh" content="3;url=' . htmlspecialchars($redirectUrl) . '"></noscript>';
        echo '<p>Redirecting in 2 seconds... <a href="' . htmlspecialchars($redirectUrl) . '">Click here if not redirected</a></p>';
    }
    
    error_log("HARD_RESET: About to call exit()");
    exit;
}

/* Aputyökalut */

/**
 * HTML escape function for secure output rendering
 * 
 * Safely escapes HTML special characters to prevent XSS attacks.
 * Converts input to string and applies comprehensive HTML entity encoding.
 * 
 * @param mixed $s The value to escape (will be converted to string)
 * @return string Safely escaped HTML string
 */
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/**
 * Resolves CSV file path with multiple extension support
 * 
 * Attempts to locate a CSV file by checking multiple possible extensions
 * in order: exact path, .csv extension, .cvc extension.
 * 
 * @param string $base Base file path without extension
 * @return string Full path to existing CSV file or empty string if not found
 */
function csv_path(string $base): string {
    if (file_exists($base)) return $base;
    if (file_exists($base . '.csv')) return $base . '.csv';
    if (file_exists($base . '.cvc')) return $base . '.cvc';
    return '';
}

/**
 * Universal CSV reader with robust parsing and error handling
 * 
 * Reads CSV files with flexible delimiter support (semicolon or comma),
 * automatic BOM removal, comment line filtering (lines starting with "/*"),
 * and comprehensive error handling with fallback to empty array.
 * 
 * @param string $base Base file path to CSV file (resolved via csv_path())
 * @return array<array{string, string}> Array of [tag, text] pairs from CSV rows
 */
function read_tag_text_rows(string $base): array {
    try {
        $path = csv_path($base);
        if ($path === '') { 
            handle_error('CSV_LOAD', "CSV not found: $base", null, false);
            return []; 
        }
        
        // Käytä file locking:ia myös lukemisessa varmuuden vuoksi
        $fp = @fopen($path, 'r');
        if ($fp === false) { 
            handle_error('CSV_LOAD', "CSV open fail: $path", null, false);
            return []; 
        }
        
        flock($fp, LOCK_SH); // Shared lock lukemiseen
        $content = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        
        if ($content === false) {
            handle_error('CSV_LOAD', "CSV read fail: $path", null, false);
            return [];
        }
        
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $content));
        $lines = array_filter($lines, function($line) { return trim($line) !== ''; });
        
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
        
    } catch (Exception $e) {
        handle_error('CSV_LOAD', "Exception in read_tag_text_rows for base: $base", $e, false);
        return [];
    }
}

/* ========= Datan luku ========= */

/**
 * Loads phase 1 questions from CSV data for enneagram test
 * 
 * Parses CSV rows to extract questions matching pattern [A-D]1 for the initial
 * personality assessment phase. Assigns sequential IDs and normalizes class names.
 * 
 * @param string $base Base file path to CSV file containing questions
 * @return array<array{class: string, text: string, id: int}> Phase 1 questions with metadata
 */
function load_phase1_questions(string $base): array {
    $rows = read_tag_text_rows($base);
    $out = []; $id = 1;
    foreach ($rows as $csv_row => [$tag, $text]) {
        if (!preg_match('/^([ABCD])1$/i', $tag, $m)) continue;
        // Extract question number from column 2 (assuming format like "156 Question text")
        $question_number = 'unknown';
        $clean_text = $text;
        if (preg_match('/^(\d+)\s+(.+)/', $text, $matches)) {
            $question_number = $matches[1];
            $clean_text = $matches[2]; // Remove number from display text
        }
        $out[] = ['class' => strtoupper($m[1].'1'), 'text' => $clean_text, 'id' => $id++, 'question_number' => $question_number];
    }
    return $out;
}

/**
 * Loads tiebreaker questions for phase 1 result resolution
 * 
 * Organizes CSV data into tiebreaker categories (A11, B11, C11, D11) for
 * resolving tied scores in the initial enneagram assessment phase.
 * 
 * @param string $base Base file path to CSV file containing tiebreaker questions
 * @return array<string, array<string>> Tiebreaker questions grouped by category
 */
function load_tiebreak_questions(string $base): array {
    $rows = read_tag_text_rows($base);
    $tb = ['A11'=>[], 'B11'=>[], 'C11'=>[], 'D11'=>[]];
    foreach ($rows as $csv_row => [$tag, $text]) {
        $u = strtoupper($tag);
        if (isset($tb[$u])) {
            // Extract question number from column 2 start (same as Phase 1)
            $question_number = 'unknown';
            $clean_text = $text;
            if (preg_match('/^(\d+)\s+(.+)/', $text, $matches)) {
                $question_number = $matches[1];
                $clean_text = $matches[2]; // Remove number from display text
            }
            $tb[$u][] = ['class' => substr($u, 0, 2), 'text' => $clean_text, 'question_number' => $question_number];
        }
    }
    // Shuffle kysymykset jokaisessa luokassa
    foreach ($tb as $key => $questions) {
        shuffle($tb[$key]);
    }
    return $tb;
}

/**
 * Loads phase 2 question blocks for detailed enneagram assessment
 * 
 * Parses CSV data into structured blocks for phase 2 testing, supporting
 * both simple blocks (B2, C2, D2) and comparison blocks with 'vs' syntax
 * for detailed personality differentiation.
 * 
 * @param string $base Base file path to CSV file containing phase 2 blocks
 * @return array<string, array<array{title: string, questions: array<string>}>> Phase 2 question blocks by category
 */
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
            
            // Extract question number from desc (column 2)
            $question_number = 'unknown';
            $clean_desc = $desc;
            if (preg_match('/^(\d+)\s+(.+)/', $desc, $matches)) {
                $question_number = $matches[1];
                $clean_desc = $matches[2]; // Remove number from display text
            }
            
            // Clean numbers from left and right texts too
            $clean_leftText = $leftText;
            $clean_rightText = $rightText;
            if (preg_match('/^(\d+)\s*(.*)/', $leftText, $matches) && !empty($matches[2])) {
                $clean_leftText = $matches[2];
            }
            if (preg_match('/^(\d+)\s*(.*)/', $rightText, $matches) && !empty($matches[2])) {
                $clean_rightText = $matches[2];
            }
            
            $cases[$letter.'2'][] = [
                'desc'       => $clean_desc,
                'left_text'  => $clean_leftText,
                'left_var'   => $leftVar,
                'right_text' => $clean_rightText,
                'right_var'  => $rightVar,
                'pair'       => [$pairL, $pairR],
                'question_number' => $question_number,
            ];
            $i += 2;
        }
    }
    return $cases;
}

/**
 * Loads phase 2 tiebreaker questions for detailed result resolution
 * 
 * Organizes CSV data into tiebreaker groups (TB2, TC2, TD2) with support
 * for both simple and 'vs' comparison formats for resolving phase 2 ties.
 * 
 * @param string $base Base file path to CSV file containing phase 2 tiebreakers
 * @return array<string, array<array{title: string, left: string, right: string, pair?: array{string, string}}>> Phase 2 tiebreaker groups
 */
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
            // Extract question number from description (column 2 start)
            $question_number = 'unknown';
            $clean_desc = $desc;
            if (preg_match('/^(\d+)\s+(.+)/', $desc, $matches)) {
                $question_number = $matches[1];
                $clean_desc = $matches[2]; // Remove number from display text
            }
            
            // Clean numbers from left and right texts too
            $clean_leftText = $leftText;
            $clean_rightText = $rightText;
            if (preg_match('/^(\d+)\s*(.*)/', $leftText, $matches) && !empty($matches[2])) {
                $clean_leftText = $matches[2];
            }
            if (preg_match('/^(\d+)\s*(.*)/', $rightText, $matches) && !empty($matches[2])) {
                $clean_rightText = $matches[2];
            }
            
            $out[$group][] = [
                'desc'       => $clean_desc,
                'left_text'  => $clean_leftText,
                'left_var'   => $leftVar,
                'right_text' => $clean_rightText,
                'right_var'  => $rightVar,
                'pair'       => [$pairL, $pairR],
                'question_number' => $question_number,
            ];
            $i += 2;
        }
    }
    return $out;
}

/**
 * Loads enneagram type descriptions for result presentation
 * 
 * Creates a mapping of type identifiers to their descriptive text
 * for displaying detailed personality type information to users.
 * 
 * @param string $base Base file path to CSV file containing type descriptions
 * @return array<string, string> Type identifier to description mapping
 */
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

/* ====== CSV-DATAN VÄLIMUISTITUS ====== */

/**
 * Loads and caches all required quiz data into session for performance
 * 
 * Preloads phase 1 questions, tiebreakers, phase 2 blocks, and type descriptions
 * into session cache to avoid repeated file I/O during quiz execution.
 * 
 * @return void
 */
function load_and_cache_quiz_data(): void {
    // Lataa vain jos ei ole vielä välimuistissa
    if (!isset($_SESSION['cached_quiz_data'])) {
        $_SESSION['cached_quiz_data'] = [
            'phase1_questions' => load_phase1_questions(PHASE1_FILE_BASE),
            'tiebreak_questions' => load_tiebreak_questions(PHASE1_FILE_BASE),
            'phase2_blocks' => load_phase2_blocks(PHASE2_FILE_BASE),
            'phase2_tiebreaks' => load_phase2_tiebreak_all(PHASE2_FILE_BASE),
            'type_descriptions' => load_type_descriptions(TYPEDESC_FILE_BASE),
            'loaded_at' => time()
        ];
        index_log('CSV_CACHE: All quiz data loaded and cached');
    }
}

/**
 * Retrieves cached phase 1 questions from session
 * 
 * Returns preloaded phase 1 questions for the enneagram test, ensuring
 * data is cached before access for optimal performance.
 * 
 * @return array<array{class: string, text: string, id: int}> Cached phase 1 questions
 */
function get_cached_phase1_questions(): array {
    load_and_cache_quiz_data();
    return $_SESSION['cached_quiz_data']['phase1_questions'] ?? [];
}

/**
 * Retrieves cached tiebreaker questions from session
 * 
 * Returns preloaded tiebreaker questions organized by category for
 * resolving phase 1 scoring ties.
 * 
 * @return array<string, array<string>> Cached tiebreaker questions grouped by type
 */
function get_cached_tiebreak_questions(): array {
    load_and_cache_quiz_data();
    return $_SESSION['cached_quiz_data']['tiebreak_questions'] ?? [];
}

/**
 * Hakee välimuistitetut phase2 kysymyslohkot
 * 
 * @return array Phase2 kysymyslohkot rateittain
 */
function get_cached_phase2_blocks(): array {
    load_and_cache_quiz_data();
    return $_SESSION['cached_quiz_data']['phase2_blocks'] ?? [];
}

/**
 * Hakee välimuistitetut phase2 tiebreak kysymykset
 * 
 * @return array Phase2 tiebreak kysymykset
 */
function get_cached_phase2_tiebreaks(): array {
    load_and_cache_quiz_data();
    return $_SESSION['cached_quiz_data']['phase2_tiebreaks'] ?? [];
}

/**
 * Hakee välimuistitetut tyyppi-kuvaukset
 * 
 * @return array Tyyppi-kuvaukset avain-arvo pareina
 */
function get_cached_type_descriptions(): array {
    load_and_cache_quiz_data();
    return $_SESSION['cached_quiz_data']['type_descriptions'] ?? [];
}

/**
 * Tyhjentää CSV-datan välimuistin (esim. debuggausta varten)
 * 
 * @return void
 */
function clear_quiz_data_cache(): void {
    unset($_SESSION['cached_quiz_data']);
    index_log('CSV_CACHE: Cache cleared');
}

/* ====== HTML-GENEROINTIFUNKTIOT ====== */

/**
 * Generoi progress bar -HTML:n
 * 
 * @param int $current Nykyinen sijainti
 * @param int $total Kokonaismäärä
 * @param string $label Aria-label teksti
 * @return string Progress bar HTML
 */
function generate_progress_bar(int $current, int $total, string $label = 'Eteneminen'): string {
    $percentage = $total > 0 ? round(($current / $total) * 100) : 0;
    return '<div class="progress" aria-label="' . htmlspecialchars($label) . '" title="' . $percentage . '%">' .
           '<div class="progress-bar" style="width:' . $percentage . '%"></div>' .
           '</div>';
}

/**
 * Generoi valintanapin phase1 ja tiebreak kysymyksille
 * 
 * @param int $value Napin arvo (1-6)
 * @param string $side Puoli ('left' tai 'right')
 * @param string $type Tyyppi ('choice' tai 'tb-choice')
 * @return string Nappi HTML
 */
function generate_phase1_button(int $value, string $side, string $type = 'choice', bool $selected = false): string {
    $cssClass = 'btn btn-p1-' . $side . ' ' . $type . '-btn ripple';
    if ($selected) {
        $cssClass .= ' selected';
    }
    $dataAttr = $type === 'tb-choice' ? 'data-choice' : 'data-choice';
    
    return '<button class="' . $cssClass . '" type="submit" ' .
           'aria-label="Valinta ' . $value . '" ' .
           $dataAttr . '="' . $value . '">' . $value . '</button>';
}

/**
 * Generoi valintanapin phase2 kysymyksille
 * 
 * @param int $value Napin arvo (-2, -1, 0, 1, 2)
 * @param string $side Puoli ('left', 'right' tai 'zero')
 * @param string $text Napin teksti
 * @param bool $isRipple Käytetäänkö ripple-efektiä
 * @return string Nappi HTML
 */
function generate_phase2_button(int $value, string $side, string $text, bool $isRipple = true): string {
    $cssClass = 'btn btn-' . $side;
    if ($isRipple && $side !== 'zero') {
        $cssClass .= ' choice2-btn ripple';
    }

    return '<button class="' . $cssClass . '" type="submit" ' .
           'aria-label="Valinta ' . $value . '" ' .
           'data-choice2="' . $value . '">' . htmlspecialchars($text) . '</button>';
}

/**
 * Generoi phase2 formin side+value järjestelmällä
 */
function generate_phase2_form(int $value, string $side, string $text, string $formType = 'choice2', bool $isRipple = true, string $customClass = ''): string {
    $cssClass = 'btn';
    if ($customClass !== '') {
        $cssClass .= ' ' . $customClass;
    } elseif ($side === 'neutral') {
        $cssClass .= ' btn-zero';
    } else {
        // Erilliset CSS-luokat vahvuuden mukaan
        if ($value === 2) {
            $cssClass .= ' btn-' . $side . '-strong'; // Selvästi samaa mieltä
        } else {
            $cssClass .= ' btn-' . $side . '-mild';   // Lievästi samaa mieltä
        }
    }
    
    if ($isRipple && $side !== 'zero') {
        $cssClass .= ' choice2-btn ripple';
    }
    
    $valueField = $formType . '_value';
    $sideField = $formType . '_side';
    $formClass = $formType . '-form';
    $formStyle = ($side === 'neutral') ? 'width:100%;' : 'display:inline-block';
    
    return '<form method="post" style="' . $formStyle . '" class="' . $formClass . '">' .
           '<input type="hidden" name="' . $valueField . '" value="' . $value . '">' .
           '<input type="hidden" name="' . $sideField . '" value="' . $side . '">' .
           '<button class="' . $cssClass . '" type="submit" aria-label="Valinta ' . $value . '">' . 
           htmlspecialchars($text) . '</button></form>';
}/**
 * Generoi kortti-divven sisällölle
 * 
 * @param string $content Kortin sisältö HTML:nä
 * @param string $additionalClasses Lisäluokat
 * @return string Kortti HTML
 */
function generate_card(string $content, string $additionalClasses = ''): string {
    $class = 'card' . ($additionalClasses ? ' ' . $additionalClasses : '');
    return '<div class="' . $class . '">' . $content . '</div>';
}

/**
 * Generoi yksinkertaisen submit-napin
 * 
 * @param string $name Napin name-attribuutti
 * @param string $value Napin value-attribuutti
 * @param string $text Napin teksti
 * @param string $cssClass CSS-luokka (oletus: 'btn')
 * @return string Nappi HTML
 */
function generate_submit_button(string $name, string $value, string $text, string $cssClass = 'btn'): string {
    return '<button class="' . $cssClass . '" type="submit" ' .
           'name="' . htmlspecialchars($name) . '" ' .
           'value="' . htmlspecialchars($value) . '">' . 
           htmlspecialchars($text) . '</button>';
}

/**
 * Generoi phase2 valintanapit yhdessä ryhmässä
 * 
 * @param bool $withRipple Käytetäänkö ripple-efektiä
 * @return array Assosiaatioarray napeista: ['left_strong' => '...', 'left_mild' => '...', jne]
 */
function generate_phase2_button_group(bool $withRipple = true): array {
    return [
        'left_mild' => generate_phase2_form(1, 'left', 'Lievästi samaa mieltä', 'choice2', $withRipple),
        'left_strong' => generate_phase2_form(2, 'left', 'Selvästi samaa mieltä', 'choice2', $withRipple),
        'neutral' => generate_phase2_form(0, 'neutral', 'En osaa sanoa', 'choice2', $withRipple, 'btn-eos'),
        'right_mild' => generate_phase2_form(1, 'right', 'Lievästi samaa mieltä', 'choice2', $withRipple),
        'right_strong' => generate_phase2_form(2, 'right', 'Selvästi samaa mieltä', 'choice2', $withRipple)
    ];
}

/**
 * Generoi phase2 tiebreak button groupin
 */
function generate_phase2_tiebreak_button_group(): array {
    return [
        'left_mild' => generate_phase2_form(1, 'left', 'Lievästi samaa mieltä', 'tb2', true),
        'left_strong' => generate_phase2_form(2, 'left', 'Selvästi samaa mieltä', 'tb2', true),
        'neutral' => generate_phase2_form(0, 'neutral', 'En osaa sanoa', 'tb2', true, 'btn-eos'),
        'right_mild' => generate_phase2_form(1, 'right', 'Lievästi samaa mieltä', 'tb2', true),
        'right_strong' => generate_phase2_form(2, 'right', 'Selvästi samaa mieltä', 'tb2', true)
    ];
}

/* ====== SESSION-HALLINTA ====== */

/**
 * Alustaa session-muuttujat turvallisesti
 * 
 * @return void
 */
function session_initialize(): void {
    try {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Alusta perustilat jos puuttuu
        if (!isset($_SESSION['state'])) {
            $_SESSION['state'] = 'intro';
        }
        
        // Validoi tila
        session_validate_state();
        
        // Alusta log_row jos puuttuu
        if (!isset($_SESSION['log_row'])) {
            $_SESSION['log_row'] = [
                'date' => date('Y-m-d'),
                'time' => date('H:i:s'),
                'nick' => '',
                'kutsuttu' => 'Ei',
                'ennea' => '',
                'confidence' => ''
            ];
        }
        
        
    } catch (Exception $e) {
        handle_error('SESSION', 'Failed to initialize session', $e);
        // Pakota uudelleenaloitus
        session_regenerate_id(true);
        $_SESSION = [];
        $_SESSION['state'] = 'intro';
    }
}

/**
 * Validoi session-tilan ja korjaa jos virheellinen
 * 
 * @return void
 */
function session_validate_state(): void {
  $validStates = ['intro', 'quiz1', 'tiebreak_intro', 'tiebreak', 'a1_result', 'phase2_intro', 'phase2', 'phase2_tb', 'done2'];
    $currentState = $_SESSION['state'] ?? 'intro';
    
    if (!in_array($currentState, $validStates, true)) {
        index_log('STATE: Invalid state "' . $currentState . '" reset to intro');
        $_SESSION['state'] = 'intro';
    }
}

/**
 * Puhdistaa session-tiedot testin päättyessä
 * 
 * @param bool $keepResults Säilytetäänkö tulokset (default: true)
 * @return void
 */
function session_cleanup(bool $keepResults = true): void {
    try {
        $preserveKeys = ['state', 'error_messages'];
        
        if ($keepResults) {
            $preserveKeys = array_merge($preserveKeys, [
                'log_row', 'topClass', 'RefGuessType', 'RefGuessProb', 'InviteId'
            ]);
        }
        
        $preservedData = [];
        foreach ($preserveKeys as $key) {
            if (isset($_SESSION[$key])) {
                $preservedData[$key] = $_SESSION[$key];
            }
        }
        
        // Tyhjennä session
        $_SESSION = [];
        
        // Palauta säilytettävät tiedot
        foreach ($preservedData as $key => $value) {
            $_SESSION[$key] = $value;
        }
        
        index_log('SESSION: Cleaned up (preserved: ' . implode(', ', array_keys($preservedData)) . ')');
        
    } catch (Exception $e) {
        handle_error('SESSION', 'Failed to cleanup session', $e);
    }
}

/**
 * Turvallinen session-avaimen asetus validoinnilla
 * 
 * @param string $key Session-avain
 * @param mixed $value Asetettava arvo
 * @param array $allowedKeys Lista sallituista avaimista (tyhjä = kaikki sallittu)
 * @return bool Onnistuiko asetus
 */
function session_set_safe(string $key, mixed $value, array $allowedKeys = []): bool {
    try {
        // Tarkista että avain on sallittu
        if (!empty($allowedKeys) && !in_array($key, $allowedKeys, true)) {
            handle_error('VALIDATION', "Session key '$key' not in allowed keys");
            return false;
        }
        
        $_SESSION[$key] = $value;
        return true;
        
    } catch (Exception $e) {
        handle_error('SESSION', "Failed to set session key: $key", $e);
        return false;
    }
}

/**
 * Turvallinen session-avaimen haku oletusarvolla ja tyypintarkistuksella
 * 
 * @param string $key Session-avain
 * @param mixed $default Oletusarvo jos avain puuttuu
 * @param string|null $expectedType Odotettu tyyppi ('string', 'int', 'array', jne)
 * @return mixed Session-arvo tai oletusarvo
 */
function session_get_safe(string $key, mixed $default = null, ?string $expectedType = null): mixed {
    $value = $_SESSION[$key] ?? $default;
    
    // Tyypintarkistus jos määritelty
    if ($expectedType !== null && $value !== $default) {
        $actualType = gettype($value);
        if ($actualType !== $expectedType) {
            index_log("SESSION: Type mismatch for key '$key': expected $expectedType, got $actualType");
            return $default;
        }
    }
    
    return $value;
}

/**
 * Tarkistaa onko session voimassa ja data ehyt
 * 
 * @return bool Onko session kunnossa
 */
function session_is_valid(): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    
    // Tarkista että perustiedot on olemassa
    $requiredKeys = ['state'];
    foreach ($requiredKeys as $key) {
        if (!isset($_SESSION[$key])) {
            return false;
        }
    }
    
    // Tarkista tilan validius
    $validStates = ['intro', 'quiz1', 'tiebreak', 'a1_result', 'phase2_intro', 'phase2', 'phase2_tb', 'done2'];
    return in_array($_SESSION['state'], $validStates, true);
}

/* ====== VIRHEENHALLINTA ====== */

/**
 * Keskitetty virheenkäsittelijä lokitukselle ja käyttäjäpalautteelle
 * 
 * @param string $errorType Virheen tyyppi (esim. 'CSV_LOAD', 'SESSION', 'DATABASE')
 * @param string $message Virheen kuvaus
 * @param Exception|null $exception Poikkeus jos käytettävissä
 * @param bool $showToUser Näytetäänkö virhe käyttäjälle
 * @return void
 */
function handle_error(string $errorType, string $message, ?Exception $exception = null, bool $showToUser = true): void {
    // Loki virhe
    $logMessage = $errorType . ': ' . $message;
    if ($exception) {
        $logMessage .= ' (' . $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine() . ')';
    }
    index_log('ERROR: ' . $logMessage);
    
    // Käyttäjälle näytettävä virheilmoitus
    if ($showToUser) {
        if (!isset($_SESSION['error_messages'])) {
            $_SESSION['error_messages'] = [];
        }
        
        $userMessage = match($errorType) {
            'CSV_LOAD' => 'Kysymystiedostojen lataus epäonnistui. Yritä uudelleen hetken kuluttua.',
            'SESSION' => 'Istunnon tietojen käsittelyssä tapahtui virhe. Yritä aloittaa uudelleen.',
            'DATABASE' => 'Tietojen tallennuksessa tapahtui virhe. Tietosi saattavat olla hävinneet.',
            'VALIDATION' => 'Syöttämissäsi tiedoissa on virhe. Tarkista ja yritä uudelleen.',
            'FILE_IO' => 'Tiedostojen käsittelyssä tapahtui virhe. Yritä uudelleen.',
            default => 'Tapahtui odottamaton virhe. Yritä uudelleen tai ota yhteyttä ylläpitoon.'
        };
        
        $_SESSION['error_messages'][] = $userMessage;
    }
}

/**
 * Turvallinen CSV-tiedoston lataus virheenkäsittelyllä
 * 
 * @param string $filePath Tiedoston polku
 * @param string $errorContext Konteksti virhelogiin
 * @return array|null CSV-data tai null virhetilanteessa
 */
function safe_load_csv(string $filePath, string $errorContext): ?array {
    try {
        if (!file_exists($filePath)) {
            handle_error('CSV_LOAD', "File not found: $filePath in context: $errorContext");
            return null;
        }
        
        if (!is_readable($filePath)) {
            handle_error('CSV_LOAD', "File not readable: $filePath in context: $errorContext");
            return null;
        }
        
        $lines = @file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            handle_error('CSV_LOAD', "Failed to read file: $filePath in context: $errorContext");
            return null;
        }
        
        $rows = [];
        foreach ($lines as $i => $line) {
            if ($i === 0) $line = preg_replace('/^\xEF\xBB\xBF/', '', $line); // BOM
            $trim = ltrim($line);
            if ($trim === '' || strpos($trim, '/*') === 0) continue; // kommenttirivi
            $parts = str_getcsv($line, ';', '"', '\\');
            if (count($parts) >= 2) {
                $tag = trim((string)$parts[0]);
                $text = trim((string)$parts[1]);
                $rows[] = [$tag, $text];
            }
        }
        
        return $rows;
        
    } catch (Exception $e) {
        handle_error('CSV_LOAD', "Exception loading $filePath in context: $errorContext", $e);
        return null;
    }
}

/**
 * Turvallinen session-avaimen haku oletusarvolla
 * 
 * @param string $key Session-avain
 * @param mixed $default Oletusarvo jos avain puuttuu
 * @return mixed Session-arvo tai oletusarvo
 */
function safe_session_get(string $key, mixed $default = null): mixed {
    return $_SESSION[$key] ?? $default;
}

/**
 * Turvallinen session-avaimen asetus virheenkäsittelyllä
 * 
 * @param string $key Session-avain
 * @param mixed $value Asetettava arvo
 * @return bool Onnistuiko asetus
 */
function safe_session_set(string $key, mixed $value): bool {
    try {
        $_SESSION[$key] = $value;
        return true;
    } catch (Exception $e) {
        handle_error('SESSION', "Failed to set session key: $key", $e);
        return false;
    }
}

/**
 * Näyttää virheviestit käyttäjälle ja tyhjentää ne
 * 
 * @return string HTML-koodi virheviesteille
 */
function display_error_messages(): string {
    $messages = $_SESSION['error_messages'] ?? [];
    if (empty($messages)) {
        return '';
    }
    
    // Tyhjennä viestit näyttämisen jälkeen
    unset($_SESSION['error_messages']);
    
    $html = '<div class="error-messages">';
    foreach ($messages as $message) {
        $html .= '<div class="error-message">';
        $html .= '<strong>Virhe:</strong> ' . htmlspecialchars($message);
        $html .= '</div>';
    }
    $html .= '</div>';
    
    return $html;
}

/* ========= Loki – vakiokolumnit ========= */
/*
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
*/

/**
 * Lähettää sähköpostin testin tuloksista
 * 
 * @param array $row Survey log -rivi
 * @return void
 */
function send_result_email(array $row): void {
    try {
        $to = 'kalle.tarpila@gmail.com';
        $subject = 'Uusi Enneagrammitesti tulos - ' . date('Y-m-d H:i:s');
        
    // 11 ensimmäistä saraketta
    $first11_columns = [
      'Päivämäärä: ' . ($row['date'] ?? date('Y-m-d')),
      'Aika: ' . ($row['time'] ?? date('H:i:s')),
      'Sessio ID: ' . ($_SESSION['sessionId'] ?? session_id()),
      'Nimimerkki: ' . ($row['nick'] ?? ''),
      'Käyttäjän arvaus: ' . ($row['ennea'] ?? ''),
      'Varmuustaso: ' . (confidence_label($row['confidence'] ?? '' ) ),
      'Kutsuttu: ' . ($row['kutsuttu'] ?? 'Ei'),
      'Ref arvaus: ' . ($_SESSION['RefGuessType'] ?? ''),
      'Ref todennäköisyys: ' . ($_SESSION['RefGuessProb'] ?? ''),
      'Kutsu ID: ' . ($_SESSION['InviteId'] ?? ''),
      'Lopullinen tyyppi: ' . ($row['finalVar'] ?? '')
    ];
        
        $message = "Uusi Enneagrammitesti on suoritettu.\n\n";
        $message .= "Tulokset:\n";
        $message .= "=========\n\n";
        $message .= implode("\n", $first11_columns);
        $message .= "\n\n---\n";
        $message .= "Tämä viesti on lähetetty automaattisesti Enneagrammitesti-järjestelmästä.";
        
        $headers = 'From: noreply@enneagrammitesti.fi' . "\r\n" .
                   'Reply-To: noreply@enneagrammitesti.fi' . "\r\n" .
                   'Content-Type: text/plain; charset=UTF-8' . "\r\n" .
                   'X-Mailer: PHP/' . phpversion();
        
        $success = mail($to, $subject, $message, $headers);
        
        if ($success) {
            index_log('EMAIL: Result notification sent successfully to ' . $to);
        } else {
            index_log('EMAIL ERROR: Failed to send result notification to ' . $to);
        }
        
    } catch (Exception $e) {
        index_log('EMAIL EXCEPTION: ' . $e->getMessage());
        handle_error('EMAIL', 'Failed to send result notification', $e);
    }
}

/* Uusi survey log: 25 kiinteää kolumnia + kysymyskohtaiset vastaukset */
function ensure_log_header(): void {
    $lp = log_path();
    if (!file_exists($lp)) {
    // Fixed columns (expanded): original 25 plus 4 tiebreak-only columns = 29
    $cols = [
      'date', 'time', 'session_id', 'nick', 'user_guess', 'user_confidence',
      'invited', 'ref_guess', 'ref_prob', 'invite_id', 'final_type',
      'top_variable_1', 'top_variable_1_points', 'top_variable_2', 'top_variable_2_points',
      'A1_points', 'B1_points', 'C1_points', 'D1_points',
      'A1_yes_count', 'B1_yes_count', 'C1_yes_count', 'D1_yes_count',
      // Per-class tiebreak-only point totals (new, reflect only tiebreak answers)
      'A1_tiebreak_points', 'B1_tiebreak_points', 'C1_tiebreak_points', 'D1_tiebreak_points',
      'Phase1_tb', 'Phase2_tb'
    ];
        
        // Lisää kysymyskolumnit järjestyksessä
        // Phase 1 kysymykset Q1-Q40 (kolumnit 26-65)
        for ($i = 1; $i <= 40; $i++) {
            $cols[] = "Q{$i}";
        }
        
        // Phase 1 tiebreak kysymykset TB1-TB10 (kolumnit 66-75)
        for ($i = 1; $i <= 10; $i++) {
            $cols[] = "TB{$i}";
        }
        
        // Phase 2 case kysymykset Case1-Case36 (kolumnit 76-111)
        for ($i = 1; $i <= 36; $i++) {
            $cols[] = "Case{$i}";
        }
        
        // Phase 2 tiebreak kysymykset TB2_1-TB2_10 (kolumnit 112-121)
        for ($i = 1; $i <= 10; $i++) {
            $cols[] = "TB2_{$i}";
        }
        
    // Use robust append with flock and retries to avoid 'Resource temporarily unavailable'
  // Prefix header with UTF-8 BOM so Excel/Windows recognizes UTF-8 encoding
  $headerLine = "\xEF\xBB\xBF" . implode(';', $cols) . PHP_EOL;
    if (!safe_file_append($lp, $headerLine)) {
      handle_error('FILE_IO', "Failed to write log header to: $lp");
    }
    }
}

/**
 * Append data to a file using fopen + flock with retries.
 * Returns true on success, false on failure.
 */
function safe_file_append(string $path, string $data, int $retries = 20, int $wait_ms = 200): bool {
  $attempt = 0;
  // Ensure directory exists
  $dir = dirname($path);
  if (!is_dir($dir)) {
    @mkdir($dir, 0700, true);
  }

  while ($attempt < $retries) {
    // Use 'c' mode: open for writing, create if not exists, do not truncate
    $fh = @fopen($path, 'c');
    if ($fh === false) {
      $attempt++;
      index_log('FILE_APPEND: fopen failed for ' . $path . ' (attempt ' . $attempt . ')');
      usleep($wait_ms * 1000);
      continue;
    }

    // Seek to end before locking/writing
    if (@fseek($fh, 0, SEEK_END) === -1) {
      // Non-fatal, but note it
      index_log('FILE_APPEND: fseek failed for ' . $path . ' (attempt ' . $attempt . ')');
    }

    // Try to acquire exclusive lock
    $locked = @flock($fh, LOCK_EX);
    if (!$locked) {
      @fclose($fh);
      $attempt++;
      index_log('FILE_APPEND: flock failed for ' . $path . ' (attempt ' . $attempt . ')');
      usleep($wait_ms * 1000);
      continue;
    }

    // Write and flush
    $written = @fwrite($fh, $data);
    @fflush($fh);
    // Release lock and close
    @flock($fh, LOCK_UN);
    @fclose($fh);

    if ($written === false) {
      $attempt++;
      index_log('FILE_APPEND: fwrite failed for ' . $path . ' (attempt ' . $attempt . ')');
      usleep($wait_ms * 1000);
      continue;
    }

    return true;
  }

  index_log('FILE_APPEND: giving up after ' . $retries . ' attempts for ' . $path);
  return false;
}
function write_full_log_row(array $row): void {
  // Varmista että kutsutiedot kopioidaan log_row:iin
  if (!empty($_SESSION['InviteId'])) {
    $row['invite_id'] = $_SESSION['InviteId'];
  }
  if (!empty($_SESSION['RefGuessType'])) {
    $row['ref_guess'] = $_SESSION['RefGuessType'];
  }
  if (!empty($_SESSION['RefGuessProb'])) {
    $row['ref_prob'] = $_SESSION['RefGuessProb'];
  }
  if (!empty($_SESSION['invite_claim_info'])) {
    $row['invited'] = 'Kyllä';
  }
    try {
        // 25 kiinteää kolumnia
    // Map numeric confidence to text for CSV logging
    $conf_text = confidence_label($row['confidence'] ?? '');

    $parts = [
            (string)($row['date'] ?? date('Y-m-d')),
            (string)($row['time'] ?? date('H:i:s')),
            (string)($_SESSION['sessionId'] ?? session_id()),
            (string)($row['nick'] ?? ''),
            (string)($row['ennea'] ?? ''),  // user_guess
      (string)$conf_text,  // user_confidence (text label)
            (string)($row['kutsuttu'] ?? 'Ei'),  // invited
            (string)($_SESSION['RefGuessType'] ?? ''),  // ref_guess
            (string)($_SESSION['RefGuessProb'] ?? ''),  // ref_prob
            (string)($_SESSION['InviteId'] ?? ''),  // invite_id
            (string)($row['finalVar'] ?? ''),  // final_type
            (string)($row['top_var_1'] ?? ''),  // top_variable_1
            (string)($row['top_var_1_pts'] ?? ''),  // top_variable_1_points
            (string)($row['top_var_2'] ?? ''),  // top_variable_2
            (string)($row['top_var_2_pts'] ?? ''),  // top_variable_2_points
      // Phase1 points and yes counts must reflect only Phase1 answers (not tiebreak)
      (string)($_SESSION['points1']['A1'] ?? (int)($row['A1'] ?? 0)),  // A1_points (Phase1)
      (string)($_SESSION['points1']['B1'] ?? (int)($row['B1'] ?? 0)),  // B1_points (Phase1)
      (string)($_SESSION['points1']['C1'] ?? (int)($row['C1'] ?? 0)),  // C1_points (Phase1)
      (string)($_SESSION['points1']['D1'] ?? (int)($row['D1'] ?? 0)),  // D1_points (Phase1)
      (string)($_SESSION['yesCounts1']['A1'] ?? (int)($row['A1_yes'] ?? 0)),  // A1_yes_count (Phase1)
      (string)($_SESSION['yesCounts1']['B1'] ?? (int)($row['B1_yes'] ?? 0)),  // B1_yes_count (Phase1)
      (string)($_SESSION['yesCounts1']['C1'] ?? (int)($row['C1_yes'] ?? 0)),  // C1_yes_count (Phase1)
      (string)($_SESSION['yesCounts1']['D1'] ?? (int)($row['D1_yes'] ?? 0)),  // D1_yes_count (Phase1)
      // New columns: tiebreak-only points per class (reflect only tiebreak answers)
      (string)(($_SESSION['tiebreak_points']['A1'] ?? $row['A1_tiebreak_points'] ?? 0)),
      (string)(($_SESSION['tiebreak_points']['B1'] ?? $row['B1_tiebreak_points'] ?? 0)),
      (string)(($_SESSION['tiebreak_points']['C1'] ?? $row['C1_tiebreak_points'] ?? 0)),
      (string)(($_SESSION['tiebreak_points']['D1'] ?? $row['D1_tiebreak_points'] ?? 0)),
      isset($_SESSION['tiebreak_ord']) && !empty($_SESSION['tiebreak_ord']) ? 'Kyllä' : 'Ei',  // Phase1_tb
      isset($_SESSION['tb2_pairs']) && !empty($_SESSION['tb2_pairs']) ? 'Kyllä' : 'Ei'  // Phase2_tb
        ];
        
        // Q1-Q40 (positiot 26-65) - Phase 1 kysymykset vastausjärjestyksessä
        $answers1 = $_SESSION['answers1'] ?? [];
        for ($i = 1; $i <= 40; $i++) {
            $answer = '';
            if (isset($answers1[$i-1])) {
                $q_data = $answers1[$i-1];
                if (isset($q_data['class']) && isset($q_data['question_number']) && isset($q_data['value'])) {
                    $answer = $q_data['class'] . '-' . $q_data['question_number'] . '-' . $q_data['value'];
                }
            }
            $parts[] = $answer;
        }
        index_log('DEBUG: After Phase1 Q1-Q40, parts count=' . count($parts) . ' (should be 65: 25 fixed + 40 Q)');
        index_log('DEBUG: Q1-Q4 samples: [' . ($parts[25] ?? 'empty') . ',' . ($parts[26] ?? 'empty') . ',' . ($parts[27] ?? 'empty') . ',' . ($parts[28] ?? 'empty') . '] (format: class-qnum-value)');
        
        // Phase 1 tiebreak vastaukset TB1-TB10 - vastausjärjestyksessä
        $tb_answers = $row['tiebreak_log'] ?? $_SESSION['tiebreak_log'] ?? [];
        for ($i = 0; $i < 10; $i++) {
            $answer = '';
            if (isset($tb_answers[$i])) {
                $log_entry = $tb_answers[$i];
                if (isset($log_entry['class']) && isset($log_entry['question_number']) && isset($log_entry['value'])) {
                    $answer = $log_entry['class'] . '-' . $log_entry['question_number'] . '-' . $log_entry['value'];
                }
            }
            $parts[] = $answer;
        }
        index_log('DEBUG: After Phase1 TB1-TB10, parts count=' . count($parts) . ' (should be 75: 25 fixed + 40 Q + 10 TB)');
        index_log('DEBUG: TB1-TB4 samples: [' . ($parts[65] ?? 'empty') . ',' . ($parts[66] ?? 'empty') . ',' . ($parts[67] ?? 'empty') . ',' . ($parts[68] ?? 'empty') . '] (format: class-qnum-value)');
        
        // Phase 2 case vastaukset Case1-Case36  
        $pairs2 = $_SESSION['pairs2'] ?? [];
        for ($i = 1; $i <= 36; $i++) {
            // $pairs2 sisältää stringejä muodossa "variable-questionnumber-value"
            $parts[] = isset($pairs2[$i-1]) ? (string)$pairs2[$i-1] : '';
        }
        index_log('DEBUG: After Phase2 Case1-Case36, parts count=' . count($parts) . ' (should be 111: 25+40+10+36)');
        index_log('DEBUG: Case1-Case4 samples: [' . ($parts[75] ?? 'empty') . ',' . ($parts[76] ?? 'empty') . ',' . ($parts[77] ?? 'empty') . ',' . ($parts[78] ?? 'empty') . '] (format: var-qnum-value)');
        
        // Phase 2 tiebreak vastaukset TB2_1-TB2_10
        $tb2_pairs = $_SESSION['tb2_pairs'] ?? [];
        for ($i = 1; $i <= 10; $i++) {
            // $tb2_pairs sisältää stringejä muodossa "variable-questionnumber-value"
            $parts[] = isset($tb2_pairs[$i-1]) ? (string)$tb2_pairs[$i-1] : '';
        }
        index_log('DEBUG: Final parts count=' . count($parts) . ' (should be 121: 25+40+10+36+10)');
        index_log('DEBUG: TB2_1-TB2_4 samples: [' . ($parts[111] ?? 'empty') . ',' . ($parts[112] ?? 'empty') . ',' . ($parts[113] ?? 'empty') . ',' . ($parts[114] ?? 'empty') . '] (format: var-qnum-value)');
        
        ensure_log_header();
        
  // Debug: show raw and mapped confidence values right before writing
  $raw_conf = $row['confidence'] ?? null;
  index_log('LOGGING: confidence raw=' . var_export($raw_conf, true) . ' mapped="' . $conf_text . '"');

  $logPath = log_path();
  // Ensure all parts are UTF-8 encoded strings (normalize to UTF-8)
  $parts = array_map(function($p) {
    $s = (string)$p;
    if (!mb_check_encoding($s, 'UTF-8')) {
      $s = mb_convert_encoding($s, 'UTF-8');
    }
    return $s;
  }, $parts);
  $csvLine = implode(';', $parts) . PHP_EOL;
        
    if (!safe_file_append($logPath, $csvLine)) {
      handle_error('FILE_IO', "Failed to write log row to: $logPath");
      return;
    }

    // Also write a debug copy into repository-local logs directory for inspection during development
    $debugDir = __DIR__ . '/logs';
    if (!is_dir($debugDir)) @mkdir($debugDir, 0700, true);
    $debugPath = $debugDir . '/last_survey_row_debug.csv';
    @file_put_contents($debugPath, $csvLine, FILE_APPEND | LOCK_EX);
        
        index_log('CSV: Row written (finalVar="' . (string)($row['finalVar'] ?? '') . '", nick="' . ($row['nick'] ?? '') . '")');
        
        // Lähetetään sähköposti tuloksista
        send_result_email($row);
        
    } catch (Exception $e) {
        handle_error('FILE_IO', "Exception in write_full_log_row", $e);
    }
}

/**
 * Map confidence numeric code to human-readable label (Finnish)
 * Accepts int, string or null. Returns empty string for unknown.
 */
function confidence_label($code): string {
  $c = $code === null ? '' : (string)$code;
  switch ($c) {
    case '1': return 'En ole lainkaan varma';
    case '2': return 'Olen melko varma';
    case '3': return 'Olen täysin varma';
    default: return '';
  }
}

/* ========= Tila ========= */
// Käsittele session resetointi
if (isset($_GET['reset']) || (isset($_POST['reset_session']))) {
    error_log("SESSION RESET: Request received");
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time()-42000, $p['path'] ?? '/', $p['domain'] ?? '', $p['secure'] ?? false, $p['httponly'] ?? true);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    error_log("SESSION RESET: Session destroyed, redirecting");
    
    // Ohjaa takaisin pääsivulle ilman parametreja
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?');
    header('Location: ' . $baseUrl);
    exit;
}

session_initialize();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$state = session_get_safe('state', 'intro', 'string');

index_log('REQUEST: ' . $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . ' (state=' . $state . ', session_id=' . session_id() . ')');

/* ========================================================================
   LOMAKEKÄSITTELY FUNKTIOT
   ======================================================================== */

/**
 * Käsittelee intro-lomakkeen lähetyksen
 * 
 * Validoi syötteet, käsittelee kutsukoodit ja alustaa testin
 * 
 * @return array|null Paluttaa error viestin tai null jos onnistui
 */
function handle_intro_form(): ?string {
    if (!isset($_POST['start'])) return null;
    
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
            index_log('INVITE: Claimed by code="' . $code . '" (id=' . $_SESSION['InviteId'] . ')');
        } else {
            return 'Kutsukoodi ei kelpaa tai on vanhentunut.';
        }
    }

    if ($ennea === '') $ennea = 'en tiedä'; // oletus
    
    index_log('INTRO: Form processing started');
    initialize_phase1_session($nick, $ennea);
    $_SESSION['state'] = 'quiz1';
    index_log('INTRO: Processing form (nick="' . $nick . '", ennea="' . $ennea . '", code="' . $code . '")');
    
    return null;
}

/**
 * Alustaa vaihe 1:n session-tiedot
 * 
 * @param string $nick Nimimerkki
 * @param string $ennea Enneagrammityyli tai "en tiedä"
 */
function initialize_phase1_session(string $nick, string $ennea): void {
    $phase1_all_preview = get_cached_phase1_questions();
    $phase1_index = [];
    foreach ($phase1_all_preview as $q) $phase1_index[(int)$q['id']] = $q['class'];
    
    // Rajoitetaan max 10 kysymystä per luokka (40 yhteensä)
    $q1_by_class = ['A1'=>[],'B1'=>[],'C1'=>[],'D1'=>[]];
    foreach ($phase1_all_preview as $q) {
        $q1_by_class[$q['class']][] = $q;
    }
    $q1 = [];
    foreach ($q1_by_class as $cls => $questions) {
        shuffle($questions);
        $q1 = array_merge($q1, array_slice($questions, 0, 10));
    }
    shuffle($q1);

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
    $confidence = ($_POST['confidence'] ?? null) !== null ? (int)$_POST['confidence'] : null;
    $isInvited = (!empty($_SESSION['InviteId']) || !empty($_SESSION['token'])) ? 'Kyllä' : 'Ei';
    
    $_SESSION['log_row'] = [
        'date'       => $now->format('Y-m-d'),
        'time'       => $now->format('H:i:s'),
        'nick'       => $nick,
        'kutsuttu'   => $isInvited,
        'ennea'      => $ennea,
        'confidence' => $confidence,
        'A1'      => 0, 'B1'=>0, 'C1'=>0, 'D1'=>0,
        'A1_yes'  => 0, 'B1_yes'=>0, 'C1_yes'=>0, 'D1_yes'=>0,
        'winner'  => '',
        'track'   => '',
        'finalVar'=> '',
        'top_var_1' => '',
        'top_var_1_pts' => 0,
        'top_var_2' => '',
        'top_var_2_pts' => 0,
        'finalVar'=> ''
    ];
}

/**
 * Käsittelee vaihe 1:n vastauksen
 * 
 * @return void
 */
function handle_quiz1_answer(): void {
    if (!isset($_POST['choice'])) return;
    
    $set = $_SESSION['phase1'] ?? []; 
    $total = count($set);
    index_log('QUIZ1: POST received (total_questions=' . $total . ', current_index=' . ($_SESSION['q1_index'] ?? 0) . ')');
    
    if ($total === 0 || !isset($_SESSION['ennea'])) {
        $_SESSION['state'] = 'intro';
        index_log('ERROR: quiz1 -> intro (missing session data)');
        return;
    }
    
    $n = (int)$_POST['choice'];
    if ($n < 1 || $n > 6) return;
    
    $i = (int)($_SESSION['q1_index'] ?? 0);
    if ($i < 0 || $i >= $total) { 
        $i = 0; 
        $_SESSION['q1_index'] = 0; 
    }
    
    $q = $set[$i];
    $cls = $q['class']; 
    $qid = (int)$q['id'];
    $question_number = $q['question_number'] ?? 'unknown';

    // Tarkista onko tähän kysymykseen jo vastattu (uudelleenvastaus)
    $previous_answer = $_SESSION['answers1_by_id'][$qid] ?? null;
    $is_revision = ($previous_answer !== null);

    if ($is_revision) {
        // Vähennetään vanha vastaus pisteistä
        $_SESSION['points1'][$cls] = ($_SESSION['points1'][$cls] ?? 0) - $previous_answer;
        if ($previous_answer >= YES_MIN) $_SESSION['yesCounts1'][$cls] = max(0, ($_SESSION['yesCounts1'][$cls] ?? 0) - 1);
        
        // Poista vanha vastaus answers1-listasta
        foreach (($_SESSION['answers1'] ?? []) as $idx => $answer) {
            if ($answer['class'] === $cls && $answer['question_number'] === $question_number) {
                unset($_SESSION['answers1'][$idx]);
                break;
            }
        }
        $_SESSION['answers1'] = array_values($_SESSION['answers1']); // Reindex array
    }

    // Tallenna uusi vastaus
    $_SESSION['answers1_by_id'][$qid] = $n;
    $_SESSION['points1'][$cls] = ($_SESSION['points1'][$cls] ?? 0) + $n;
    if ($n >= YES_MIN) $_SESSION['yesCounts1'][$cls] = ($_SESSION['yesCounts1'][$cls] ?? 0) + 1;

    // Store answer with class, question_number and value for logging in order
    $_SESSION['answers1'][] = [
        'class' => $cls,
        'question_number' => $question_number,
        'value' => $n
    ];

    // Siirry aina seuraavaan kysymykseen vastauksen jälkeen (myös uudelleenvastaus)
    $_SESSION['q1_index'] = $i + 1;
    
    index_log('QUIZ1 ANSWER: q' . ($i+1) . '/' . $total . ' class=' . $cls . ' choice=' . $n . 
              ($is_revision ? ' (REVISED)' : '') . ' (next_index=' . $_SESSION['q1_index'] . ')');

    if ($_SESSION['q1_index'] >= $total) {
        handle_quiz1_completion();
    }
}

/**
 * Käsittelee Phase 1:n navigoinnin (takaisin/eteenpäin)
 */
function handle_quiz1_navigation(): void {
    $direction = $_POST['navigate'] ?? '';
    $set = $_SESSION['phase1'] ?? [];
    $total = count($set);
    $current_index = (int)($_SESSION['q1_index'] ?? 0);
    
    if ($direction === 'prev' && $current_index > 0) {
        $_SESSION['q1_index'] = $current_index - 1;
        index_log('QUIZ1 NAVIGATE: Moving back to question ' . ($_SESSION['q1_index'] + 1));
    } elseif ($direction === 'next' && $current_index < $total) {
        $_SESSION['q1_index'] = $current_index + 1;
        index_log('QUIZ1 NAVIGATE: Moving forward to question ' . ($_SESSION['q1_index'] + 1));
    }
}

/**
 * Käsittelee tiebreak navigoinnin (takaisin/eteenpäin)
 */
function handle_tiebreak_navigation(): void {
    $direction = $_POST['tiebreak_navigate'] ?? '';
    $set = $_SESSION['tiebreak_set'] ?? [];
    $total = count($set);
    $current_index = (int)($_SESSION['tiebreak_index'] ?? 0);
    
    if ($direction === 'back' && $current_index > 0) {
        $_SESSION['tiebreak_index'] = $current_index - 1;
        index_log('TIEBREAK NAVIGATE: Moving back to question ' . ($_SESSION['tiebreak_index'] + 1));
    } elseif ($direction === 'forward' && $current_index < $total - 1) {
        $_SESSION['tiebreak_index'] = $current_index + 1;
        index_log('TIEBREAK NAVIGATE: Moving forward to question ' . ($_SESSION['tiebreak_index'] + 1));
    }
}

/**
 * Käsittelee paluun Phase 1:een tiebreakista
 */
function handle_return_to_phase1(): void {
    $target_index = (int)($_POST['return_to_phase1'] ?? 0);
    $phase1_set = $_SESSION['phase1'] ?? [];
    
  if ($target_index >= 0 && $target_index < count($phase1_set)) {
    // Palauta pisteet ja kyllä-laskurit varmuuskopiosta
    if (isset($_SESSION['phase1_points_backup'])) {
      $_SESSION['points1'] = $_SESSION['phase1_points_backup'];
      $_SESSION['yesCounts1'] = $_SESSION['phase1_yes_backup'] ?? [];
    }
    // Tallenna nykyinen tiebreak-setti ja vastaukset varmuuskopioon
    if (isset($_SESSION['tiebreak_set']) && isset($_SESSION['tiebreak_answers'])) {
      $_SESSION['last_tiebreak_set'] = $_SESSION['tiebreak_set'];
      $_SESSION['last_tiebreak_answers'] = $_SESSION['tiebreak_answers'];
    }
    $_SESSION['q1_index'] = $target_index;
    $_SESSION['state'] = 'quiz1';
    index_log('RETURN TO PHASE1: Going back to Phase 1 question ' . ($target_index + 1));
    // Kirjaa pisteet ja kyllä-laskurit lokiin
    $pts = $_SESSION['points1'] ?? [];
    $yes = $_SESSION['yesCounts1'] ?? [];
    index_log('PHASE1 STATUS: points=' . json_encode($pts) . ', yesCounts=' . json_encode($yes));
    $url = $_SERVER['PHP_SELF'] . '?' . http_build_query($_GET);
    // If headers already sent, set pending_redirect for JS fallback
    if (!headers_sent()) {
      header('Location: ' . $url);
      exit;
    } else {
      $_SESSION['pending_redirect'] = $url;
      index_log('RETURN TO PHASE1: Headers already sent, queued JS redirect to ' . $url);
    }
  }
}

/**
 * Tarkistaa onko tiebreak vielä tarpeen Phase 1:n muutosten jälkeen
 */
function check_tiebreak_needed(): bool {
    $pts = $_SESSION['points1'] ?? [];
    if (empty($pts)) return false;
    
    arsort($pts);
  $vals = array_values($pts);
  if (count($vals) < 2) return false;
  $top = (int)$vals[0];
  $second = (int)$vals[1];
  $diff = $top - $second;

  index_log('TIEBREAK CHECK: top=' . $top . ', second=' . $second . ', diff=' . $diff);

  // Trigger tiebreak when the difference between top-two is 0,1 or 2
  return ($diff <= 2);
}

/**
 * Käsittelee vaihe 1:n loppuun saattamisen
 */
function handle_quiz1_completion(): void {
    foreach (['A1','B1','C1','D1'] as $k) {
        $_SESSION['log_row'][$k]      = (int)($_SESSION['points1'][$k] ?? 0);
        $_SESSION['log_row'][$k.'_yes']= (int)($_SESSION['yesCounts1'][$k] ?? 0);
    }

  $pts = $_SESSION['points1']; 
  arsort($pts);
  $sorted_keys = array_keys($pts);
  $vals = array_values($pts);
  $tops = [];
  // If top-two are close (diff <=2) or tie, consider them candidates
  if (count($vals) >= 2) {
    $top = (int)$vals[0];
    $second = (int)$vals[1];
    $diff = $top - $second;
    if ($diff <= 2) {
      // take the top two keys as initial candidates
      $tops = array_slice($sorted_keys, 0, 2);
    } else {
      // clear winners: single winner only
      $tops = [ $sorted_keys[0] ];
    }
  } else {
    $tops = array_keys(array_filter($pts, function($v) use ($pts) { return $v === reset($pts); }));
  }

  // Tallenna top_var_1 ja top_var_1_pts
  $sorted = array_keys($pts);
  $_SESSION['log_row']['top_var_1'] = $sorted[0] ?? '';
  $_SESSION['log_row']['top_var_1_pts'] = $pts[$sorted[0]] ?? 0;
  // Etsi top_var_2, joka ei ole sama kuin top_var_1
  $top2 = '';
  $top2_pts = '';
  foreach ($sorted as $k) {
    if ($k !== $_SESSION['log_row']['top_var_1']) {
      $top2 = $k;
      $top2_pts = $pts[$k];
      break;
    }
  }
  $_SESSION['log_row']['top_var_2'] = $top2;
  $_SESSION['log_row']['top_var_2_pts'] = $top2_pts;

  index_log('QUIZ1: Phase1 complete (scores: A1=' . ($pts['A1'] ?? 0) . ', B1=' . ($pts['B1'] ?? 0) . ', C1=' . ($pts['C1'] ?? 0) . ', D1=' . ($pts['D1'] ?? 0) . ', candidates=' . implode(',', $tops) . ')');

  if (count($tops) === 1) {
    handle_single_winner($tops[0]);
  } else {
    handle_quiz1_tie($tops);
  }
}

/**
 * Käsittelee tilanteen kun yksi luokka voittaa selkeästi
 * 
 * @param string $winner Voittajaluokka
 */
function handle_single_winner(string $winner): void {
    $_SESSION['topClass'] = $winner;
    $_SESSION['log_row']['winner'] = $winner;

    if ($winner === 'A1') {
        $_SESSION['log_row']['finalVar'] = '4';
        if (!empty($_SESSION['InviteId'])) invite_update_status($_SESSION['InviteId'], 'completed');
        write_full_log_row($_SESSION['log_row']);
        $_SESSION['state'] = 'a1_result';
        index_log('STATE CHANGE: quiz1 -> a1_result (A1 winner, finalVar=4)');
    } else {
        initialize_phase2($winner);
    }
}

/**
 * Alustaa vaihe 2:n
 * 
 * @param string $winner Voittajaluokka vaiheesta 1
 */
function initialize_phase2(string $winner): void {
    $_SESSION['cases_all'] = get_cached_phase2_blocks();
    $track = $winner[0] . '2';
    $_SESSION['track']     = $track;
    $_SESSION['blocks2']   = $_SESSION['cases_all'][$track] ?? [];
    
    // Shuffle Phase 2 kysymykset satunnaiseen järjestykseen
    if (!empty($_SESSION['blocks2'])) {
        shuffle($_SESSION['blocks2']);
        
        
        // Shuffle myös vasemman ja oikean puolen vaihtoehdot
        $swapped_count = 0;
        foreach ($_SESSION['blocks2'] as &$block) {
            if (rand(0, 1)) {
                // Vaihda vasemman ja oikean puolen paikat
                $temp_var = $block['left_var'];
                $temp_text = $block['left_text'];
                
                $block['left_var'] = $block['right_var'];
                $block['left_text'] = $block['right_text'];
                
                $block['right_var'] = $temp_var;
                $block['right_text'] = $temp_text;
                
                $swapped_count++;
            }
        }
        unset($block); // Poista reference
        index_log('PHASE2: Left/right swapped for ' . $swapped_count . '/' . count($_SESSION['blocks2']) . ' questions');
        
        index_log('PHASE2: Questions shuffled (count=' . count($_SESSION['blocks2']) . ') with left/right randomization');
    }
    
    $_SESSION['q2_index']  = 0;
    $_SESSION['answers2']  = [];
    $_SESSION['varPoints'] = [];
    $_SESSION['pairs2']    = [];
    $_SESSION['tb2_pairs'] = [];
    $_SESSION['log_row']['track'] = $track;

    $codes = []; 
    foreach (($_SESSION['blocks2'] ?? []) as $b) { 
    // Normalize variable codes (trim + uppercase) to ensure consistent keys
    $lv = is_string($b['left_var'] ?? '') ? strtoupper(trim($b['left_var'])) : '';
    $rv = is_string($b['right_var'] ?? '') ? strtoupper(trim($b['right_var'])) : '';
    if ($lv !== '') $codes[$lv] = true;
    if ($rv !== '') $codes[$rv] = true;
    }
    $codes = array_values(array_unique(array_keys($codes))); 
    natsort($codes);
    $_SESSION['varCodes'] = array_slice(array_values($codes), 0, 3);

    $_SESSION['state'] = 'phase2_intro';
    index_log('STATE CHANGE: quiz1 -> phase2_intro (winner=' . $winner . ', track=' . $track . ', blocks=' . count($_SESSION['blocks2']) . ')');
    index_log('PHASE2: Initialized (varCodes=' . implode(',', $_SESSION['varCodes']) . ')');
    
    // Debug: näytä ensimmäiset kysymykset
    $preview = [];
    for ($i = 0; $i < min(3, count($_SESSION['blocks2'])); $i++) {
        if (isset($_SESSION['blocks2'][$i])) {
            $block = $_SESSION['blocks2'][$i];
            $preview[] = ($block['left_var'] ?? '?') . 'vs' . ($block['right_var'] ?? '?');
        }
    }
    index_log('PHASE2: First questions preview: ' . implode(', ', $preview));
}

/**
 * Käsittelee tasatilanteen vaiheessa 1
 * 
 * @param array $tops Tasapelissä olevat luokat
 */
function handle_quiz1_tie(array $tops): void {
  // If more than two candidate classes, pick the two with highest "kyllä" counts
  if (count($tops) > 2) {
    $yc = $_SESSION['yesCounts1'] ?? [];
    // Build array of [class => yesCount]
    $byYes = [];
    foreach ($tops as $c) {
      $byYes[$c] = (int)($yc[$c] ?? 0);
    }
    arsort($byYes);
    $selected = array_keys($byYes);
    // If still more than 2 after sorting by yes, take first two
    $tops = array_slice($selected, 0, 2);
    // Fallback: if less than 2 (edge), use ordering
    if (count($tops) < 2) {
      $order = ['A1','B1','C1','D1'];
      usort($tops, function($a,$b) use ($order) { 
        return array_search($a,$order,true) <=> array_search($b,$order,true); 
      });
      $tops = array_slice($tops, 0, 2);
    }
    index_log('TIEBREAK SELECT: more than 2 candidates, selected by yesCounts: ' . implode(',', $tops));
  }
    
  $_SESSION['tiebreak_classes'] = $tops;
  $tbAll = get_cached_tiebreak_questions();
  $tbPool = [];
  $tops_key = implode('-', $tops);
  // Jos luokat ovat samat kuin edellisellä tiebreak-kerralla, käytä alkuperäistä järjestystä
  $restored_answers = [];
  $last_answered = 0;
  if (isset($_SESSION['original_tiebreak_classes']) && $_SESSION['original_tiebreak_classes'] === $tops_key && isset($_SESSION['original_tiebreak_set'])) {
    $tbPool = $_SESSION['original_tiebreak_set'];
    index_log('TIEBREAK: Using original question order (no shuffle)');
    // Palauta vanhat vastaukset vain tyhjiin kohtiin
    if (isset($_SESSION['original_tiebreak_answers']) && is_array($_SESSION['original_tiebreak_answers'])) {
      foreach ($tbPool as $idx => $q) {
        if (isset($_SESSION['tiebreak_answers'][$idx]) && $_SESSION['tiebreak_answers'][$idx] !== null) {
          // Käyttäjän uusi vastaus, älä ylikirjoita
          $restored_answers[$idx] = $_SESSION['tiebreak_answers'][$idx];
        } elseif (isset($_SESSION['original_tiebreak_answers'][$idx])) {
          $restored_answers[$idx] = $_SESSION['original_tiebreak_answers'][$idx];
          if ($_SESSION['original_tiebreak_answers'][$idx] !== null) $last_answered = $idx + 1;
        } else {
          $restored_answers[$idx] = null;
        }
      }
    }
  } else {
    foreach ($tops as $clsTB) {
      $tbKey = $clsTB[0].'11';
      $arr = $tbAll[$tbKey] ?? [];
      if ($arr) { 
        foreach ($arr as $question_obj) {
          $tbPool[] = $question_obj;
        }
      }
    }
    shuffle($tbPool);
    // Tallenna alkuperäinen järjestys ja luokat
    $_SESSION['original_tiebreak_set'] = $tbPool;
    $_SESSION['original_tiebreak_classes'] = $tops_key;
    // Tallenna myös vastaukset, jos niitä on
    if (isset($_SESSION['tiebreak_answers']) && is_array($_SESSION['tiebreak_answers'])) {
      $_SESSION['original_tiebreak_answers'] = $_SESSION['tiebreak_answers'];
    } else {
      $_SESSION['original_tiebreak_answers'] = [];
    }
    index_log('TIEBREAK: Shuffled and saved original question order');
  }
    // Backup original Phase 1 points before tiebreak
    $_SESSION['phase1_points_backup'] = $_SESSION['points1'];
    $_SESSION['phase1_yes_backup'] = $_SESSION['yesCounts1'];
    $new_set_id = md5(json_encode($tbPool));
    $old_set_id = $_SESSION['tiebreak_set_id'] ?? null;
    $_SESSION['tiebreak_set']   = $tbPool;
    $_SESSION['tiebreak_set_id'] = $new_set_id;
    // Restore answers
    if (!empty($restored_answers)) {
        $_SESSION['tiebreak_answers'] = $restored_answers;
        $_SESSION['tiebreak_index'] = ($last_answered < count($tbPool)) ? $last_answered : 0;
    } else {
        $_SESSION['tiebreak_answers'] = [];
        $_SESSION['tiebreak_index'] = 0;
    }
    // Save current set for future restoration
    $_SESSION['prev_tiebreak_set'] = $tbPool;
    $_SESSION['tiebreak_log']   = [];
    $_SESSION['tiebreak_ord']   = [];
  // Enter tiebreak intro state (show instructions before actual tiebreak questions)
  $_SESSION['state'] = 'tiebreak_intro';
  index_log('STATE CHANGE: quiz1 -> tiebreak_intro (tied_classes=' . implode(',', $tops) . ', tb_questions=' . count($tbPool) . ')');
    index_log('TIEBREAK: Initialized (classes=' . implode(',', $tops) . ', questions=' . count($tbPool) . ')');
}

/* ========================================================================
   PALAUTTEEN KÄSITTELY
   ======================================================================== */

// Alusta palautemuuttujat
$feedbackMsg = '';
$feedbackErr = '';
$oldFeedbackText = '';

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

/* Lomakekäsittelijöiden kutsuminen */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_SESSION['state'] ?? '') === 'intro') {
        $error = handle_intro_form();
    } elseif (($_SESSION['state'] ?? '') === 'quiz1' && isset($_POST['choice'])) {
        handle_quiz1_answer();
    } elseif (($_SESSION['state'] ?? '') === 'quiz1' && isset($_POST['navigate'])) {
        handle_quiz1_navigation();
  } elseif (($_SESSION['state'] ?? '') === 'quiz1' && isset($_POST['return_to_tiebreak'])) {
    // Restore tiebreak state and go directly to tiebreak
    if (!empty($_SESSION['last_tiebreak_set']) && !empty($_SESSION['last_tiebreak_answers'])) {
      $_SESSION['tiebreak_set'] = $_SESSION['last_tiebreak_set'];
      $_SESSION['tiebreak_answers'] = $_SESSION['last_tiebreak_answers'];
      $_SESSION['tiebreak_set_id'] = md5(json_encode($_SESSION['tiebreak_set']));
      $_SESSION['tiebreak_index'] = 0;
      // Restore tiebreak classes
      $classes = [];
      foreach ($_SESSION['tiebreak_set'] as $tbq) {
        if (isset($tbq['class']) && !in_array($tbq['class'], $classes)) {
          $classes[] = $tbq['class'];
        }
      }
      $_SESSION['tiebreak_classes'] = $classes;
      $_SESSION['state'] = 'tiebreak';
      index_log('RETURN TO TIEBREAK: Navigated directly from Phase 1 to tiebreak (classes=' . implode(',', $classes) . ')');
      $url = $_SERVER['PHP_SELF'] . '?' . http_build_query($_GET);
      if (!headers_sent()) {
        header('Location: ' . $url);
        exit;
      } else {
        $_SESSION['pending_redirect'] = $url;
        index_log('RETURN TO TIEBREAK: Headers already sent, queued JS redirect to ' . $url);
      }
    }
    }
}

/**
 * Käsittelee tiebreak-vastauksen vaiheessa 1
 * 
 * @param int $choice Käyttäjän valinta (1-6)
 * @return void
 */
function handle_tiebreak_answer(int $choice): void {
    $n = $choice;
    if ($n < 1 || $n > 6) return;
    
    $set = $_SESSION['tiebreak_set'] ?? [];
    $i   = (int)($_SESSION['tiebreak_index'] ?? 0);
    
    // Initialize tiebreak_answers array if not exists
    if (!isset($_SESSION['tiebreak_answers'])) {
        $_SESSION['tiebreak_answers'] = [];
    }
    
  // Store answer for this index
  $_SESSION['tiebreak_answers'][$i] = $choice;
  // Päivitä myös original_tiebreak_answers
  $_SESSION['original_tiebreak_answers'][$i] = $choice;
    
    // Recalculate all points from tiebreak answers
    recalculate_tiebreak_points();
    
    index_log('TIEBREAK ANSWER: tb' . ($i+1) . '/' . count($set) . ' choice=' . $n . ' (index=' . $i . ')');
    
    // Auto-advance to next question if not last
    if ($i < count($set) - 1) {
        $_SESSION['tiebreak_index'] = $i + 1;
    } else {
        // Last question - check if all answered
        $allAnswered = true;
        for ($j = 0; $j < count($set); $j++) {
            if (!isset($_SESSION['tiebreak_answers'][$j])) {
                $allAnswered = false;
                break;
            }
        }
        
        if ($allAnswered) {
            handle_tiebreak_completion();
        }
    }
}

/**
 * Laskee tiebreak-pisteet uudelleen kaikista vastauksista
 */
function recalculate_tiebreak_points(): void {
    $set = $_SESSION['tiebreak_set'] ?? [];
    $answers = $_SESSION['tiebreak_answers'] ?? [];
    
  // Compute tiebreak-only aggregates (do NOT mix with original phase1 points)
  // Initialize per-class counters
  $classes = ['A1','B1','C1','D1'];
  $tbPoints = array_fill_keys($classes, 0);
  $tbYes = array_fill_keys($classes, 0);

  // Clear existing tiebreak data
  $_SESSION['tiebreak_log'] = [];
  $_SESSION['tiebreak_ord'] = [];

  // Recalculate from all tiebreak answers only
  foreach ($answers as $index => $choice) {
    $blk = $set[$index] ?? null;
    if ($blk && is_int($choice) && $choice >= 1 && $choice <= 6) {
      $cls = $blk['class'];
      $question_number = $blk['question_number'] ?? 'unknown';

      // Accumulate tiebreak-only points and yes counts (YES_MIN applies)
      $tbPoints[$cls] = ($tbPoints[$cls] ?? 0) + $choice;
      if ($choice >= YES_MIN) {
        $tbYes[$cls] = ($tbYes[$cls] ?? 0) + 1;
      }

      $ord = ($_SESSION['tiebreak_ord'][$cls] ?? 0) + 1;
      $_SESSION['tiebreak_ord'][$cls] = $ord;

      // Store answer for logging
      $_SESSION['tiebreak_log'][] = [
        'class' => $cls,
        'question_number' => $question_number,
        'value' => $choice
      ];
    }
  }

  // Save tiebreak-only aggregates in session for later decision-making
  $_SESSION['tiebreak_points'] = $tbPoints;
  $_SESSION['tiebreak_yes'] = $tbYes;
}

/**
 * Käsittelee tiebreak-vaiheen loppuun saattamisen
 */
function handle_tiebreak_completion(): void {
  // Use tiebreak-only aggregates to determine final path; do not mix with original phase1 points
  $tbPoints = $_SESSION['tiebreak_points'] ?? ['A1'=>0,'B1'=>0,'C1'=>0,'D1'=>0];
  $tbYes = $_SESSION['tiebreak_yes'] ?? ['A1'=>0,'B1'=>0,'C1'=>0,'D1'=>0];

  // Do NOT overwrite Phase1 log columns (A1, A1_yes, etc.).
  // Instead, store tiebreak-only aggregates in dedicated keys so Phase1 columns remain Phase1-only.
  foreach (['A1','B1','C1','D1'] as $k) {
    // Header contains A1_tiebreak_points etc. Ensure these keys are present for transparency.
    $_SESSION['log_row'][$k . '_tiebreak_points'] = (int)($tbPoints[$k] ?? 0);
    // Also keep tiebreak yes counts under a separate key for debugging (not part of the main header)
    $_SESSION['log_row'][$k . '_tiebreak_yes'] = (int)($tbYes[$k] ?? 0);
  }

  $pts = $tbPoints;
  arsort($pts);
  $max = reset($pts);
  $tops = array_keys(array_filter($pts, function($v) use ($max) { return $v === $max; }));

  if (count($tops) === 1) {
    $top = $tops[0];
  } else {
    // Break tie by tiebreak-specific yes counts
    $yc = $tbYes;
    $best = $tops[0];
    foreach ($tops as $c) if (($yc[$c]??0) > ($yc[$best]??0)) $best = $c;
    $eq = array_filter($tops, function($c) use ($yc, $best) { return ($yc[$c]??0)===($yc[$best]??0); });
    $top = !empty($eq) ? $eq[array_rand($eq)] : null;
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
        initialize_phase2($top);
        index_log('TIEBREAK: Complete -> PHASE2 (final_winner=' . $top . ', track=' . ($_SESSION['track'] ?? '') . ', blocks=' . count($_SESSION['blocks2'] ?? []) . ')');
    }
}

/**
 * Käsittelee vaiheen 2 (lopullinen tyypittely) vastausta
 * 
 * @param int $choice Käyttäjän valinta (-2 = vahvasti vasemmalle, 0 = neutraali, 2 = vahvasti oikealle)
 * @return void
 */
function handle_phase2_answer(int $value, string $side = ''): void {
    // Jos side on tyhjä, käsitellään vanha choice-järjestelmä (taaksepäin yhteensopivuus)
    if ($side === '' && ($value >= -2 && $value <= 2)) {
        $side = ($value < 0) ? 'left' : (($value > 0) ? 'right' : 'neutral');
        $value = abs($value);
    }
    
    if ($value < 0 || $value > 2 || !in_array($side, ['left', 'right', 'neutral'])) {
        index_log('PHASE2: Invalid choice value=' . $value . ' side=' . $side);
        return;
    }
    
    $index = (int)($_SESSION['q2_index'] ?? 0);
    $block = $_SESSION['blocks2'][$index] ?? null;
    
    if (!$block) {
        $_SESSION['log_row']['finalVar'] = '';
        if (!empty($_SESSION['InviteId'])) invite_update_status($_SESSION['InviteId'], 'completed');
        write_full_log_row($_SESSION['log_row']);
        $_SESSION['state'] = 'done2';
        index_log('STATE CHANGE: phase2 -> done2 (missing block, finalVar=empty)');
        return;
    } else {
        index_log('PHASE2 DISPLAY: Q' . ($index + 1) . '/' . count($_SESSION['blocks2']) . 
                 ' - Left:' . ($block['left_var'] ?? '?') . ' Right:' . ($block['right_var'] ?? '?'));
    }
    
  // Ensure varPoints array exists
  if (!isset($_SESSION['varPoints']) || !is_array($_SESSION['varPoints'])) {
    $_SESSION['varPoints'] = [];
  }

  // Tallennetaan vastaus
    $entry = '';
    $question_number = $block['question_number'] ?? 'unknown';
    
    if ($side !== 'neutral' && $value > 0) {
    $variable = ($side === 'left') ? ($block['left_var'] ?? '') : ($block['right_var'] ?? '');
    // Normalize variable key to avoid mismatches (trim, uppercase)
    $variable = is_string($variable) ? strtoupper(trim($variable)) : '';
    if ($variable === '') {
      index_log('PHASE2: WARNING - empty variable for block at index ' . $index . ' (question_number=' . $question_number . ')');
    } else {
      $_SESSION['varPoints'][$variable] = (int)(($_SESSION['varPoints'][$variable] ?? 0)) + (int)$value;
    }
    $entry = 'x' . $variable . '-' . $question_number . '-' . $value; // Format: xVARIABLE-questionnumber-value
    } elseif ($side === 'neutral') {
        // Neutral vastaukset tallennetaan myös
        $entry = 'xNEUTRAL-' . $question_number . '-0'; // Format: xNEUTRAL-questionnumber-0
    }
    
    $_SESSION['pairs2'][] = $entry;
    $_SESSION['answers2'][] = ['side' => $side, 'value' => $value];
    $_SESSION['q2_index'] = $index + 1;
    
    index_log('PHASE2 ANSWER: case' . ($index + 1) . '/' . count($_SESSION['blocks2']) . 
              ' side=' . $side . ' value=' . $value . ' left=' . $block['left_var'] . ' right=' . $block['right_var'] . 
              ' (next_index=' . $_SESSION['q2_index'] . ')');
  // Log current varPoints for debugging
  index_log('PHASE2 STATE: varPoints=' . json_encode($_SESSION['varPoints'] ?? []));
    
    // Tarkistetaan onko vaihe 2 valmis
    if ($_SESSION['q2_index'] >= count($_SESSION['blocks2'])) {
        handle_phase2_completion();
    }
}

/**
 * Käsittelee vaiheen 2 valmistumisen ja siirtymisen tulossivulle tai tiebreakiin
 * 
 * @return void
 */
function handle_phase2_completion(): void {
  $varPoints = $_SESSION['varPoints'] ?? [];
  // Ensure numeric values
  $varPoints = array_map('intval', $varPoints);
    $leaders = [];
    
    if (!empty($varPoints)) {
  arsort($varPoints);
        $maxPoints = reset($varPoints);
        $leaders = array_keys(array_filter($varPoints, function($points) use ($maxPoints) {
            return $points === $maxPoints;
        }));
        
        // Lisää top_variable tiedot log_row:hin
        $sortedVars = array_keys($varPoints);
        $_SESSION['log_row']['top_var_1'] = $sortedVars[0] ?? '';
        $_SESSION['log_row']['top_var_1_pts'] = isset($sortedVars[0]) ? ($varPoints[$sortedVars[0]] ?? 0) : 0;
        $_SESSION['log_row']['top_var_2'] = $sortedVars[1] ?? '';
        $_SESSION['log_row']['top_var_2_pts'] = isset($sortedVars[1]) ? ($varPoints[$sortedVars[1]] ?? 0) : 0;
    }
    
  // Log a compact representation
  index_log('PHASE2: Complete (varPoints=' . json_encode($varPoints) . ', leaders=' . implode(',', $leaders) . ')');
    
    if (count($leaders) <= 1) {
        // Selvä voittaja tai ei pisteitä
        $finalVar = $leaders ? $leaders[0] : '';
        $_SESSION['log_row']['finalVar'] = $finalVar;
        if (!empty($_SESSION['InviteId'])) invite_update_status($_SESSION['InviteId'], 'completed');
        write_full_log_row($_SESSION['log_row']);
        $_SESSION['state'] = 'done2';
        index_log('STATE CHANGE: phase2 -> done2 (finalVar=' . $finalVar . ', leaders=' . implode(',', $leaders) . ')');
    } else {
        // Tasatilanne - tiebreak
        initialize_phase2_tiebreak($leaders);
    }
}

/**
 * Alustaa vaiheen 2 tiebreak-kierroksen tasatilanteessa
 * 
 * @param array $leaders Lista tasatilanteessa olevista muuttujista
 * @return void
 */
function initialize_phase2_tiebreak(array $leaders): void {
    natsort($leaders);
    $pair = array_slice(array_values($leaders), 0, 2);
    $_SESSION['tb2_pair'] = $pair;
    
    $allTiebreaks = get_cached_phase2_tiebreaks();
    $track = $_SESSION['track'] ?? 'B2';
    $letter = strtoupper($track[0]);
    $group = 'T' . $letter . '2';
    
    $candidates = $allTiebreaks[$group] ?? [];
    $filtered = [];
    
    foreach ($candidates as $candidate) {
        $candidatePair = $candidate['pair'] ?? null;
        if (!$candidatePair || count($candidatePair) < 2) continue;
        
        if (((string)$candidatePair[0] === (string)$pair[0] && (string)$candidatePair[1] === (string)$pair[1]) ||
            ((string)$candidatePair[0] === (string)$pair[1] && (string)$candidatePair[1] === (string)$pair[0])) {
            $filtered[] = $candidate;
        }
    }
    
    if (!empty($filtered)) {
        shuffle($filtered);
        $_SESSION['tb2_set'] = $filtered; // Käytetään kaikki löytyneet tiebreak-kysymykset
        $_SESSION['tb2_pairs'] = [];
        $_SESSION['tb2_index'] = 0;
        $_SESSION['state'] = 'phase2_tb';
        index_log('STATE CHANGE: phase2 -> phase2_tb (tie between ' . implode(',', $pair) . 
                  ', tb_cases=' . count($_SESSION['tb2_set']) . ')');
    } else {
        // Ei tiebreak-kysymyksiä, arvotaan voittaja
        $finalVar = $pair[array_rand($pair)];
        $_SESSION['log_row']['finalVar'] = $finalVar;
        if (!empty($_SESSION['InviteId'])) invite_update_status($_SESSION['InviteId'], 'completed');
        write_full_log_row($_SESSION['log_row']);
        $_SESSION['state'] = 'done2';
        index_log('STATE CHANGE: phase2 -> done2 (tie resolved randomly, finalVar=' . $finalVar . ')');
    }
}

/**
 * Käsittelee vaiheen 2 tiebreak-vastausta
 * 
 * @param int $choice Käyttäjän valinta (-2 = vahvasti vasemmalle, 0 = neutraali, 2 = vahvasti oikealle)
 * @return void
 */
function handle_phase2_tiebreak_answer(int $value, string $side = ''): void {
    // Jos side on tyhjä, käsitellään vanha choice-järjestelmä (taaksepäin yhteensopivuus)
    if ($side === '' && ($value >= -2 && $value <= 2)) {
        $side = ($value < 0) ? 'left' : (($value > 0) ? 'right' : 'neutral');
        $value = abs($value);
    }
    
    if ($value < 0 || $value > 2 || !in_array($side, ['left', 'right', 'neutral'])) {
        index_log('PHASE2_TB: Invalid choice value=' . $value . ' side=' . $side);
        return;
    }
    
    $index = (int)($_SESSION['tb2_index'] ?? 0);
    $block = $_SESSION['tb2_set'][$index] ?? null;
    
    if (!$block) {
        index_log('PHASE2_TB: Missing block at index ' . $index);
        return;
    }
    
    index_log('PHASE2_TB DISPLAY: Q' . ($index + 1) . '/' . count($_SESSION['tb2_set']) . 
             ' - Left:' . ($block['left_var'] ?? '?') . ' Right:' . ($block['right_var'] ?? '?'));
    
    $question_number = $block['question_number'] ?? 'unknown';
    
    // Tallennetaan vastaus
    $entry = '';
    if ($side !== 'neutral' && $value > 0) {
    $variable = ($side === 'left') ? ($block['left_var'] ?? '') : ($block['right_var'] ?? '');
    $variable = is_string($variable) ? strtoupper(trim($variable)) : '';
    if ($variable === '') {
      index_log('PHASE2_TB: WARNING - empty variable for tb block at index ' . $index . ' (question_number=' . $question_number . ')');
    } else {
      $_SESSION['varPoints'][$variable] = (int)(($_SESSION['varPoints'][$variable] ?? 0)) + (int)$value;
    }
    $entry = 'x' . $variable . '-' . $question_number . '-' . $value; // Format: xVARIABLE-questionnumber-value
    } elseif ($side === 'neutral') {
        // Neutral vastaukset tallennetaan myös
        $entry = 'xNEUTRAL-' . $question_number . '-0'; // Format: xNEUTRAL-questionnumber-0
    }
    
    $_SESSION['tb2_pairs'][] = $entry;
    $_SESSION['tb2_index'] = $index + 1;
    
    index_log('PHASE2_TB ANSWER: tb' . ($index + 1) . '/' . count($_SESSION['tb2_set']) . 
              ' side=' . $side . ' value=' . $value . ' left=' . $block['left_var'] . ' right=' . $block['right_var'] . 
              ' (next_index=' . $_SESSION['tb2_index'] . ')');
    
    // Tarkistetaan onko tiebreak valmis
    if ($_SESSION['tb2_index'] >= count($_SESSION['tb2_set'])) {
        handle_phase2_tiebreak_completion();
    }
}

/**
 * Käsittelee vaiheen 2 tiebreak-kierroksen valmistumisen
 * 
 * @return void
 */
function handle_phase2_tiebreak_completion(): void {
    $varPoints = $_SESSION['varPoints'] ?? [];
    $leaders = [];
    
    if (!empty($varPoints)) {
        arsort($varPoints);
        $maxPoints = reset($varPoints);
        $leaders = array_keys(array_filter($varPoints, function($points) use ($maxPoints) {
            return $points === $maxPoints;
        }));
        
        // Lisää top_variable tiedot log_row:hin
        $sortedVars = array_keys($varPoints);
        $_SESSION['log_row']['top_var_1'] = $sortedVars[0] ?? '';
        $_SESSION['log_row']['top_var_1_pts'] = isset($sortedVars[0]) ? ($varPoints[$sortedVars[0]] ?? 0) : 0;
        $_SESSION['log_row']['top_var_2'] = $sortedVars[1] ?? '';
        $_SESSION['log_row']['top_var_2_pts'] = isset($sortedVars[1]) ? ($varPoints[$sortedVars[1]] ?? 0) : 0;
    }
    
    $pointsStr = '';
    foreach ($varPoints as $var => $points) {
        $pointsStr .= $var . '=' . $points . ' ';
    }
    index_log('PHASE2_TB: Complete (varPoints=' . trim($pointsStr) . ', leaders=' . implode(',', $leaders) . ')');
    
    // Valitaan voittaja
    $finalVar = '';
    if (count($leaders) === 1) {
        $finalVar = $leaders[0];
    } elseif (count($leaders) > 1) {
        $finalVar = $leaders[array_rand($leaders)];
    }
    
    $_SESSION['log_row']['finalVar'] = $finalVar;
    if (!empty($_SESSION['InviteId'])) invite_update_status($_SESSION['InviteId'], 'completed');
    write_full_log_row($_SESSION['log_row']);
    $_SESSION['state'] = 'done2';
    index_log('STATE CHANGE: phase2_tb -> done2 (finalVar=' . $finalVar . ' after tiebreak)');
}

/* Tiebreak vastauksen käsittely */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_SESSION['state'] ?? '') === 'tiebreak') && isset($_POST['tb_choice'])) {
  handle_tiebreak_answer((int)$_POST['tb_choice']);
} elseif (($_SESSION['state'] ?? '') === 'tiebreak' && isset($_POST['tiebreak_navigate'])) {
  handle_tiebreak_navigation();
}

// Handle tiebreak intro continuation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_SESSION['state'] ?? '') === 'tiebreak_intro') && isset($_POST['continue_tiebreak'])) {
  $_SESSION['state'] = 'tiebreak';
  index_log('STATE CHANGE: tiebreak_intro -> tiebreak');
  header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query($_GET));
  exit;
}

/* Vaihe 2 intro sivun käsittely */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_SESSION['state'] ?? '') === 'phase2_intro') && isset($_POST['continue_phase2'])) {
    $_SESSION['state'] = 'phase2';
    index_log('STATE CHANGE: phase2_intro -> phase2');
    index_log('PHASE2 START: Ready to show ' . count($_SESSION['blocks2']) . ' questions, starting with index ' . ($_SESSION['q2_index'] ?? 0));
    header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query($_GET));
    exit;
}

// JS redirect fallback: if a redirect was queued because headers were already sent
if (!empty($_SESSION['pending_redirect'])) {
  $redir = $_SESSION['pending_redirect'];
  unset($_SESSION['pending_redirect']);
  echo "<script>try{window.location.href='" . htmlspecialchars($redir, ENT_QUOTES) . "';}catch(e){}</script>";
  // also log it server-side
  index_log('JS REDIRECT: emitted fallback redirect to ' . $redir);
  // stop further rendering to avoid partial page
  exit;
}

/* Vaihe 2 vastauksen käsittely */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_SESSION['state'] ?? '') === 'phase2') && isset($_POST['choice2_value']) && isset($_POST['choice2_side'])) {
    handle_phase2_answer((int)$_POST['choice2_value'], $_POST['choice2_side']);
}

/* Vaihe 2 tiebreak vastauksen käsittely */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_SESSION['state'] ?? '') === 'phase2_tb') && isset($_POST['tb2_value']) && isset($_POST['tb2_side'])) {
    handle_phase2_tiebreak_answer((int)$_POST['tb2_value'], $_POST['tb2_side']);
}

/* ====== INVITE token auto-claim URL-parametrilla (intro-tilassa) ====== */
// Force invite token handling before rendering intro page
if (INVITES_ENABLED && ($_SESSION['state'] ?? '') === 'intro' && isset($_GET['invite'])) {
  $token = (string)$_GET['invite'];
  if ($token !== '') {
    $items = invites_load();
    $inv = invite_find_by_token($items, $token);
    if ($inv && (($inv['status'] ?? '') === 'sent' || ($inv['status'] ?? '') === 'accepted')) {
      $_SESSION['RefGuessType'] = (string)($inv['guess_type'] ?? '');
      $_SESSION['RefGuessProb'] = (string)($inv['guess_prob'] ?? '');
      $_SESSION['InviteId']     = (string)($inv['id'] ?? '');
      $_SESSION['invite_claim_info'] = 'Kutsu tunnistettu.';
      // Päivitä kutsun status invites.json-tiedostoon
      if (($inv['status'] ?? '') === 'sent') {
        invite_update_status($_SESSION['InviteId'], 'accepted');
      }
      // Päivitä items uudelleen, jotta status tallentuu
      $items = invites_load();
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
<meta name="viewport" content="width=device-width,initial-scale=1.0,user-scalable=yes">
<title>ENNEAGRAMMITESTI-pilotti</title>
<meta name="robots" content="noindex, nofollow">
<style>
:root{
  --card-w: 800px;
  --p1-left-bg:#ffe5ea; --p1-left-bd:#f5a8b5;
  --p1-right-bg:#e6f7e6; --p1-right-bd:#9cd39c;
  --left-bg:#fff7cc; --left-bd:#e6d88a;
  --right-bg:#e8f1ff; --right-bd:#a6c0f3;
  --left-mild-bg:#ede499; --left-strong-bg:#ede499;
  --right-mild-bg:#b8d1ff; --right-strong-bg:#b8d1ff;
}
.ripple {
  position: relative;
  overflow: hidden;
}
.ripple-effect {
  position: absolute;
  border-radius: 50%;
  transform: scale(0);
  animation: ripple-animation 1.1s cubic-bezier(.4,0,.2,1);
  background: rgba(220, 0, 60, 0.7);
  pointer-events: none;
  z-index: 2;
}
@keyframes ripple-animation {
  to {
    transform: scale(4.5);
    opacity: 0;
  }
}

/* === NAVIGATION CONTROLS === */
.navigation-controls {
  border: none;
  padding: 0;
  margin: 0;
  background: none;
}

@media (max-width: 520px) {
  .navigation-controls {
    flex-direction: column;
    gap: 10px;
    text-align: center;
  }
  
  .navigation-controls > div:nth-child(2) {
    order: -1;
  }
}

/* === MOBILE TOUCH OPTIMOINTI === */
@media (max-width: 768px) {
  .ripple-effect {
    background: rgba(220, 0, 60, 0.8); /* vahvempi väri mobiilissa */
    z-index: 10; /* varmistetaan, että näkyy */
  }
  
  /* Touch-laitteiden optimointi */
  .btn {
    -webkit-touch-callout: none;
    -webkit-user-select: none;
    -khtml-user-select: none;
    -moz-user-select: none;
    -ms-user-select: none;
    user-select: none;
    -webkit-tap-highlight-color: transparent;
    /* Vahvennetut fade-efektit mobiilissa */
    transition: all 0.3s cubic-bezier(.4,0,.2,1) !important;
  }
  
  .btn:active {
    transform: translateY(-1px) scale(0.98);
    transition: all 0.1s ease;
  }

  /* Vaihe 1 ja tiebreak valintanapit samanlevyisiksi mobiilissa */
  .choice-btn,
  .tb-choice-btn {
    width: auto !important;
    min-width: 120px !important;
    max-width: 100% !important;
    font-size: 20px !important;
    margin: 0 8px 16px 8px !important;
    padding: 0 !important;
    box-sizing: border-box !important;
    border-radius: 12px !important;
    display: inline-block !important;
    text-align: center !important;
    vertical-align: middle !important;
  }

  /* Keskitetään napit mobiilissa */
  .phase1-choices,
  .tb-choice-form {
    display: flex !important;
    justify-content: center !important;
    flex-wrap: wrap;
    gap: 0;
    width: 100%;
  }

  .btn.selected {
    transition: all 0.3s cubic-bezier(.4,0,.2,1) !important;
  }
}
/* === PERUSTYYLIT === */
*{box-sizing:border-box}
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:0;padding:40px 24px 24px 24px;background:url('SEGRY_turkoositausta_1.png') repeat;color:#111;line-height:1.5;min-height:100vh;display:flex;flex-direction:column;justify-content:flex-start;align-items:center}

/* === LAYOUT-KOMPONENTIT === */
.card{max-width:var(--card-w);width:100%;margin:0;padding:20px;border:1px solid #ddd;border-radius:14px;box-shadow:0 1px 6px rgba(0,0,0,.06);background:#fff}
.header{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:8px}
.block{padding:12px;border:1px solid #eee;border-radius:10px;background:#fcfcfc}
.row{display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:space-between}
.footer{margin-top:16px;display:flex;gap:12px;flex-wrap:wrap;justify-content:flex-end;align-items:center}

/* === PERUSTEKSTIT === */
.lead{font-size:22px;margin:16px 0}
.leadLarge{font-size:28px;margin:16px 0}
.muted{color:#444;font-size:15px}
.small{font-size:12px;color:#666}
.credits{margin-top:56px;text-align:center;color:#6b6b6b;font-size:12px}

/* === PAINIKKEET - PERUSTYYLIT === */
.btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 14px;border:2px solid #ccc;border-radius:10px;background:#fafafa;cursor:pointer;font-size:18px;min-width:44px;min-height:44px;text-align:center;color:#000;-webkit-text-fill-color:#000;-webkit-appearance:none;appearance:none;transition:all 0.2s ease;box-shadow:0 2px 4px rgba(0,0,0,0.1);margin:2px;}
.btn:hover{background:#e0e0e0;border-color:#999;transform:translateY(-2px);box-shadow:0 4px 8px rgba(0,0,0,0.15);}
.btn:active{transform:translateY(0);box-shadow:0 1px 2px rgba(0,0,0,0.1);}
.btn.selected{background:#4589e5 !important;border-color:#34508f !important;color:#fff !important;-webkit-text-fill-color:#fff !important;box-shadow:0 4px 16px rgba(69,137,229,0.25);transform:translateY(-2px) scale(1.08);transition:all 0.25s cubic-bezier(.4,0,.2,1);}

/* === LINKIT JA MUUT ELEMENTIT === */
.linkbtn{display:inline-block;padding:12px 16px;border:1px solid #ccc;border-radius:10px;background:#fafafa;text-decoration:none;color:#000;-webkit-text-fill-color:#000}
.linkbtn:hover{background:#f0f0f0}

/* === NAVIGOINTIPAINIKKEET === */
.navigation-controls button {
  background: none !important;
  border: none !important;
  color: #007cba !important;
  text-decoration: none !important;
  padding: 8px 12px !important;
  font-size: 18px !important;
  cursor: pointer !important;
  font-family: inherit !important;
}
.navigation-controls button:hover {
  background: none !important;
  color: #005a87 !important;
  text-decoration: none !important;
}
.badge{padding:2px 8px;border-radius:999px;background:#eef;border:1px solid #ccd;font-size:12px}
.badge-off{background:#fee;border-color:#fbb}
.sep{padding:0 6px;color:#999}
.logo{height:96px;width:auto;object-fit:contain}
.spacer{height:28px}

/* === PROGRESS BAR === */
.progress{width:100%;height:12px;background:#e9ecf3;border-radius:8px;overflow:hidden}
.progress-bar{height:100%;background:linear-gradient(90deg,#6b8cff,#3f6bff);border-radius:8px;transition:width .25s ease}

/* === BUTTON-VARIANTIT === */
/* Phase 1 buttons */
.btn-p1-left{background:var(--p1-left-bg);border-color:#f591a8;}
.btn-p1-left:hover{background:#ffc0cf;border-color:#f56b8b;}
.btn-p1-right{background:var(--p1-right-bg);border-color:#7ac47a;}
.btn-p1-right:hover{background:#c4e8c4;border-color:#5cb85c;}

/* Phase 2 buttons */
/* Vasemmanpuoleiset painonapit - lievästi */
.btn-left-mild{background:#f5f0c0;border-color:#e5e0a0;font-size:14px;padding:6px 8px;line-height:1.2;min-height:40px;max-width:120px;word-break:keep-all;hyphens:auto;}
.btn-left-mild:hover{background:#f8f3cc;border-color:#e8e3a6;}

/* Vasemmanpuoleiset painonapit - selvästi */
.btn-left-strong{background:#e6d555;border-color:#d4c04a;font-size:14px;padding:6px 8px;line-height:1.2;min-height:40px;max-width:120px;word-break:keep-all;hyphens:auto;}
.btn-left-strong:hover{background:#ead966;border-color:#d8c450;}

/* Oikeanpuoleiset painonapit - lievästi */
.btn-right-mild{background:#d8e5f8;border-color:#c0d0f0;font-size:14px;padding:6px 8px;line-height:1.2;min-height:40px;max-width:120px;word-break:keep-all;hyphens:auto;}
.btn-right-mild:hover{background:#e0ebfa;border-color:#c8d6f2;}

/* Oikeanpuoleiset painonapit - selvästi */
.btn-right-strong{background:#a5c2ff;border-color:#90b0eb;font-size:14px;padding:6px 8px;line-height:1.2;min-height:40px;max-width:120px;word-break:keep-all;hyphens:auto;}
.btn-right-strong:hover{background:#b5ccff;border-color:#a0baef;}

/* Automaattinen fontin pienennys pitkille teksteille */
@media (max-width: 1200px) {
  .btn-left-mild, .btn-left-strong, .btn-right-mild, .btn-right-strong {
    font-size: 12px;
  }
}

@media (max-width: 900px) {
  .btn-left-mild, .btn-left-strong, .btn-right-mild, .btn-right-strong {
    font-size: 11px;
  }
}

@media (max-width: 600px) {
  .btn-left-mild, .btn-left-strong, .btn-right-mild, .btn-right-strong {
    font-size: 10px;
    padding: 4px 6px;
  }
}

@media (max-width: 480px) {
  .btn-left-mild, .btn-left-strong, .btn-right-mild, .btn-right-strong {
    font-size: 9px;
    padding: 3px 4px;
    max-width: none;
    width: 100%;
  }
  .btn-eos {
    max-width: none;
    width: 100%;
  }
  .choice2-form, .tb2-form {
    width: 100% !important;
    display: block !important;
  }
}

.btn-zero{background:#f5f5f5;border-color:#cfcfcf;font-size:14px;padding:16px;line-height:1.5;min-height:80px;width:100%;max-width:100%;}
.btn-zero:hover{background:#e0e0e0;border-color:#999;}

.btn-eos{background:#f5f5f5;color:#333;border:2px solid #ccc;border-radius:10px;padding:6px 8px;font-size:14px;font-weight:normal;cursor:pointer;transition:all 0.2s ease;min-height:40px;max-width:120px;display:inline-flex;align-items:center;justify-content:center;box-shadow:0 2px 4px rgba(0,0,0,0.1);line-height:1.2;word-break:keep-all;hyphens:auto;}
.btn-eos:hover{background:#f8f8f8;border-color:#aaa;transform:translateY(-2px);box-shadow:0 4px 8px rgba(0,0,0,0.15);}
.btn-eos.selected{background:#ffe066 !important;border-color:#e6c200 !important;color:#222 !important;box-shadow:0 4px 16px rgba(255,224,102,0.4);transform:translateY(-2px) scale(1.08);transition:all 0.25s cubic-bezier(.4,0,.2,1);}

/* EOS-painikkeen teksti: desktop = "En osaa sanoa", mobile = "En osaa sanoa" */
.btn-eos::before{content:"En osaa sanoa";} /* Desktop-teksti */
.btn-eos{font-size:0;} /* Piilottaa alkuperäisen tekstin */
.btn-eos::before{font-size:14px;} /* Sama fonttikoko kuin muut painonapit desktopissa */

/* === LAYOUT-KOMPONENTIT === */
.case-wrap{display:grid;grid-template-columns:1fr 0.5fr 1fr;grid-auto-rows:auto;row-gap:20px;column-gap:7px;align-items:stretch;padding:16px 0;margin:0;} /* Keskimmäinen 0.5fr */
.opt-left{grid-column:1;display:flex;flex-direction:column;}
.opt-center{grid-column:2;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;} /* Painike alareunaan kuten muissa */
.mobile-eos-button{display:none;} /* Näkyy vain mobiilissa */
.mobile-options-container{display:block;} /* Näkyy kaikilla näytöillä */
.opt-right{grid-column:3;display:flex;flex-direction:column;}
.opt-box{flex:1;display:flex;flex-direction:column;border-radius:12px;border:1px solid transparent;padding:15px;font-size:18px;word-break:break-word;color:#000;min-height:120px;box-sizing:border-box;}
.opt-box-content{flex-grow:1;}
.opt-box.left .opt-box-content{font-style:italic;} /* Vasemman puolen kysymysteksti kursiivilla */
.opt-box.right .opt-box-content{font-style:italic;} /* Oikean puolen kysymysteksti kursiivilla */
.opt-box.center .opt-box-content{font-style:italic;display:none;} /* Piilotetaan "En osaa sanoa" teksti desktopissa */
.opt-box.left{background:var(--left-bg);border-color:var(--left-bd);text-align:center}
.opt-box.right{background:var(--right-bg);border-color:var(--right-bd);text-align:center}
.opt-box.center{background:#e0e0e0;border-color:#bbb;text-align:center;padding:15px;min-height:120px;display:flex;flex-direction:column;justify-content:flex-end;box-sizing:border-box;width:100%;} /* Painike alareunaan */
.opt-box .btn-group{margin-top:10px;}
.opt-left .btn-group, .opt-right .btn-group{display:flex;gap:8px;}
.opt-left .btn-group form, .opt-right .btn-group form{flex:1;}

/* Desktop/tablet: oikean puolen painikkeet tekstin alle */
.opt-box.right{display:flex;flex-direction:column;}
.opt-box.right .btn-group{order:2;margin-top:10px;}
.opt-box.right .opt-box-content{order:1;}
.case-desc{grid-column:1 / -1;font-size:18px;margin:0 0 5px 0;}
.mobile-hint{display:none;grid-column:1 / -1;text-align:center;color:#666;font-size:14px;margin-top:10px;}
/* === SCALE-KOMPONENTIT === */
.scale-row{display:flex;flex-wrap:wrap;justify-content:center;align-items:center;margin:10px 0 0;position:relative;min-height:60px;width:100%;gap:8px;}
.scale-left,.scale-right{display:flex;align-items:center;gap:4px;padding:0 4px;justify-content:center;flex-wrap:wrap;}
.scale-zero{display:flex;flex-direction:column;align-items:center;gap:4px;padding:0 4px;margin:0;position:relative;top:0;justify-content:center;flex:0 0 auto;}
.scale-zero .bar{font-weight:700;color:#999;line-height:1;}

/* === FORM-ELEMENTIT === */
.textarea{width:100%;min-height:120px;padding:10px;border:1px solid #ccc;border-radius:10px;font-size:16px;resize:vertical}
.center-row{display:flex;justify-content:center;gap:8px;flex-wrap:nowrap}
.alert{padding:10px;border-radius:8px;margin:10px 0}
.alert-ok{background:#e7f7e7;border:1px solid #a7d3a7}
.alert-err{background:#fdeaea;border:1px solid #e7b0b0}

/* === DEBUG JA APULUOKAT === */
.help{grid-column:1/-1;color:#555}
.debug{margin-top:10px;padding:10px;border:1px dashed #bbb;background:#fafcff;border-radius:10px;font-size:14px}

/* === UUDET CSS-LUOKAT INLINE-TYYLIEN KORVAAMISEEN === */
.error-messages{margin-bottom:20px}
.error-message{background-color:#ffebee;color:#c62828;padding:12px;border-radius:4px;margin-bottom:8px;border-left:4px solid #c62828}
.confidence-container{display:none;margin-top:12px}
.confidence-container.show{display:block}
.question-layout{display:flex;flex-direction:column;align-items:center}
.scale-container{margin:16px 0;align-items:center;gap:8px;width:100%;max-width:800px}
.scale-label{flex:1;font-size:14px;color:#555}
.scale-label.left{text-align:right;padding-right:10px}
.scale-label.right{text-align:left;padding-left:10px}
.scale-buttons{display:flex;gap:4px;align-items:center;justify-content:center}
.scale-divider{display:inline-flex;height:48px;align-items:center;margin:0 8px;color:#ccc}
.question-text{font-size:18px;margin:0 0 16px 0}
.scale-help{text-align:center;margin-top:8px}
.badge-off{background:#fee;border-color:#fbb}
.form-spacing{height:14px}
.form-spacing-small{height:8px}
.form-margin{margin-top:12px}
.input-margin{margin-bottom:6px}
.text-small{margin:4px 0 0 0;color:#666}
.text-required{color:#b00}
/* === RESPONSIIVISUUS === */
.mobile-scale-label{display:none}
.mobile-only{display:none}
.desktop-only{display:block}

/* Varmistetaan että orientaation vaihtaminen toimii - koko sivu scrollaa */
@media screen and (orientation: landscape) {
  .card{max-height:none;overflow-y:visible}
  body{overflow-y:auto;height:auto}
}

/* Varmistetaan että kaikissa koossa sivu voi scrollata normaalisti */
@media (max-width: 768px) {
  .card{max-height:none !important;overflow-y:visible !important}
  body{overflow-y:auto !important;height:auto !important}
}

/* Tablet-koko (769px - 1024px) */
@media (max-width:1024px){
  .card{max-width:95%;margin:0}
  body{padding:20px 10px 10px 10px}
}

/* Pieni tablet / suuri mobiili (521px - 768px) */
@media (max-width:480px){
  .card{padding:16px;max-width:98%;margin:0}
  body{padding:15px 5px 5px 5px}
  .logo{height:72px}
  .opt-box,.case-desc{font-size:17px}
  .scale-container{flex-direction:column;gap:12px}
  .scale-label{text-align:center;padding:0}
  .case-wrap{grid-template-columns:1fr;gap:15px}
  .linkbtn{padding:10px 12px;font-size:16px}
  
  /* Phase 2 Mobile optimointi */
  .opt-left,.opt-right,.opt-center{grid-column:1;width:100%;margin:0}
  .case-wrap{grid-template-columns:1fr;grid-template-rows:auto auto auto auto auto;gap:15px;}
  .opt-center{display:none !important;} /* Piilotettu mobiilissa */
  .opt-right{order:3;}
  .opt-left .btn-group, .opt-right .btn-group{flex-direction:column;gap:10px;}
  .mobile-hint{display:block !important;order:4;}
  .phase2-instructions{order:3;} /* Ohjeteksti näkyy kysymyslaatikoiden jälkeen mobiilissa */
  .opt-box{margin:0;padding:12px;min-height:auto}
  .opt-box .btn-group{margin-top:8px;justify-content:center;display:flex;flex-wrap:wrap;gap:4px}
  
  /* Lisää tilaa kysymystekstin ja painonappien väliin mobiilissa */
  .opt-left .opt-box-content{margin-bottom:24px;} /* Vasemman puolen kysymyksen alle extra tila */
  .opt-right .opt-box-content{margin-top:24px;} /* Oikean puolen kysymyksen ylle extra tila */
  
  .scale-row{gap:6px;margin:8px 0;min-height:auto;justify-content:center;width:100%}
  .scale-row[style*="grid-column"]{padding:0;margin:8px 0}
  .btn-group form{display:inline-block;margin:2px}
  
  /* Iso pyöreäreunaisuu kehys kaikkien vaihtoehtojen ympärille mobiilissa */
  .mobile-options-container{
    background:#ffffff;
    border:2px solid #ddd;
    border-radius:20px;
    padding:20px;
    margin:10px 0;
    box-shadow:0 4px 12px rgba(0,0,0,0.1);
    order:1;
  }
  
  /* Mobiilissa oikean puolen painikkeet palautuvat ylös */
  .opt-box.right .btn-group{order:1;margin-top:0;margin-bottom:8px;}
  .opt-box.right .opt-box-content{order:2;}
  
  /* En osaa sanoa -painike mobiilissa erillisenä */
  .mobile-eos-button{display:block !important;width:100%;margin:15px 0;order:2;}
  .mobile-eos-button form{width:100%;}
  .mobile-eos-button .btn-eos{width:100%;max-width:none;flex:none;background:#d0d0d0;color:#333;border-color:#bbb;font-size:14px;} /* Koko leveys mobiilissa */
  .mobile-eos-button .btn-eos:hover{background:#c0c0c0;border-color:#999;}
  .mobile-eos-button .btn-eos::before{display:none;} /* Piilottaa "EOS" mobiilissa */
  
  /* Mobiili asteikko-tekstit */
  .desktop-only{display:none}
  .mobile-scale-label{display:block;font-size:14px;margin:4px 0}
  .mobile-scale-label.top-left{color:#8B0000;text-align:left;margin-bottom:8px}
  .mobile-scale-label.bottom-right{color:#006400;text-align:right;margin-top:8px}
  
  /* Mobiili Phase 1 layout - pystysuora */
  .scale-buttons{flex-direction:column;align-items:center;gap:8px}
  .scale-buttons .choice-form{width:100%;max-width:200px}
  .scale-buttons .btn{width:100%;justify-content:center;padding:12px 16px;min-height:48px}
  .scale-divider{display:none} /* Piilottaa | merkki mobiilissa */
  
  .mobile-only{display:block}
  .desktop-only{display:none}
}

/* Mobiili (max 520px) */
@media (max-width:520px){
  .card{padding:12px;margin:0}
  body{padding:12px 2px 2px 2px}
  .logo{height:60px}
  .btn{font-size:16px;min-width:42px;min-height:42px;padding:8px 12px}
  .scale-buttons{flex-wrap:wrap;gap:4px;justify-content:flex-end}
  .linkbtn{padding:8px 10px;font-size:15px}
  h1{font-size:24px}
  h2{font-size:20px}
  .progress{height:10px}
  
  /* Phase 2 & Tiebreak optimointi pienille näytöille */
  .case-wrap{gap:12px}
  .opt-box{padding:10px;font-size:16px}
  .btn-group{margin-top:6px}
  .btn-group .btn{margin:2px;min-width:auto;padding:6px 8px;font-size:14px}
  .case-desc{font-size:16px;margin-bottom:8px}
  
  /* Keskimmäinen nappi optimointi - koko leveys */
  .scale-row[style*="grid-column"] .btn-zero{width:100%;max-width:none;margin:0;padding:10px}
}

/* Erittäin pieni mobiili (max 360px) */
@media (max-width:360px){
  .card{padding:8px;margin:0}
  body{padding:8px 1px 1px 1px}
  .btn{font-size:15px;min-width:40px;min-height:40px;padding:6px 10px;margin:1px}
  .linkbtn{padding:6px 8px;font-size:14px}
  h1{font-size:22px}
  .mobile-scale-label{font-size:13px}
  .scale-help{font-size:14px}
  
  /* Phase 2 pienimmille näytöille */
  .case-wrap{gap:10px;padding:8px 0}
  .opt-box{padding:8px;font-size:15px;min-height:auto}
  .case-desc{font-size:15px;margin-bottom:6px}
  .btn-left-mild,.btn-left-strong,.btn-right-mild,.btn-right-strong{font-size:12px;padding:4px 6px;min-height:36px;max-width:100px;margin:1px}
  .btn-zero{font-size:13px;padding:8px;min-height:auto;max-width:none;width:100%}
  .scale-row{gap:4px;margin:6px 0}
}

/* Desktop ja tablet (yli 480px) - piilota mobile container */
@media (min-width: 481px) {
  .mobile-options-container {
    display: contents; /* Tekee containerista "näkymätön" CSS Grid layoutille */
  }
}

/* === RIPPLE-EFEKTI === */
</style>
</head>
<body>
<div class="card">
  <?= display_error_messages() ?>
  <div class="header" role="banner">
    <div>
      <h1>ENNEAGRAMMITESTI-pilotti</h1>
      <?php if ($SHOW_TEST_TOGGLE): ?>
      <div class="small">
        Testimoodi:
        <?php if ($TEST_MODE): ?>
          <span class="badge" aria-live="polite">Päällä</span>
          <a href="?test=0<?= isset($_GET['showtestmode'])?'&showtestmode=1':'' ?>">Poista</a> •
          <a href="?test=1&amp;admin=1<?= isset($_GET['showtestmode'])?'&showtestmode=1':'' ?>">Admin-tarkistus</a>
        <?php else: ?>
          <span class="badge badge-off" aria-live="polite">Pois</span>
          <a href="?test=1<?= isset($_GET['showtestmode'])?'&showtestmode=1':'' ?>">Kytke päälle</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

<?php if ($ADMIN_MODE): ?>
  <div class="admin-debug" style="background: #f0f8ff; border: 2px solid #4169e1; border-radius: 8px; padding: 20px; margin: 20px 0; font-family: monospace; font-size: 13px;">
    <h2 style="color: #4169e1; margin-top: 0;">🔧 Admin-tarkistus</h2>
    
    <h3>Sessiotiedot:</h3>
    <pre style="background: #fff; padding: 10px; border-radius: 4px; overflow: auto; max-height: 300px;"><?= htmlspecialchars(print_r($_SESSION, true)) ?></pre>
    
    <h3>GET-parametrit:</h3>
    <pre style="background: #fff; padding: 10px; border-radius: 4px;"><?= htmlspecialchars(print_r($_GET, true)) ?></pre>
    
    <h3>POST-parametrit:</h3>
    <pre style="background: #fff; padding: 10px; border-radius: 4px;"><?= htmlspecialchars(print_r($_POST, true)) ?></pre>
    
    <h3>Nykyinen tila:</h3>
    <p><strong>State:</strong> <?= htmlspecialchars($_SESSION['state'] ?? 'ei määritelty') ?></p>
    <p><strong>Test Mode:</strong> <?= $TEST_MODE ? 'Päällä' : 'Pois' ?></p>
    <p><strong>Track:</strong> <?= htmlspecialchars($_SESSION['track'] ?? 'ei määritelty') ?></p>
    
    <?php if (isset($_SESSION['blocks2'])): ?>
    <p><strong>Phase 2 kysymyksiä:</strong> <?= count($_SESSION['blocks2']) ?></p>
    <?php endif; ?>
    
    <h3>📋 CSV-tiedostojen sisältö:</h3>
    <?php 
    // Lataa CSV-data välimuistista tai tiedostoista
    load_and_cache_quiz_data();
    $csvData = $_SESSION['cached_quiz_data'] ?? [];
    ?>
    
    <?php if (isset($csvData['phase1_questions'])): ?>
    <div style="background: #fff; padding: 10px; border-radius: 4px; margin: 5px 0;">
      <strong>Phase 1 kysymykset (SeparateBlocksQuestions.csv):</strong> <?= count($csvData['phase1_questions']) ?> kpl<br>
      <?php 
      $classes = [];
      foreach ($csvData['phase1_questions'] as $q) {
        $classes[$q['class']] = ($classes[$q['class']] ?? 0) + 1;
      }
      foreach ($classes as $class => $count) {
        echo htmlspecialchars($class) . ": {$count} kpl | ";
      }
      ?>
    </div>
    <?php endif; ?>
    
    <?php if (isset($csvData['tiebreak_questions'])): ?>
    <div style="background: #fff; padding: 10px; border-radius: 4px; margin: 5px 0;">
      <strong>Phase 1 tiebreak (SeparateBlocksQuestions.csv):</strong><br>
      <?php 
      foreach ($csvData['tiebreak_questions'] as $tbClass => $questions) {
        echo htmlspecialchars($tbClass) . ": " . count($questions) . " kpl | ";
      }
      ?>
    </div>
    <?php endif; ?>
    
    <?php if (isset($csvData['phase2_blocks'])): ?>
    <div style="background: #fff; padding: 10px; border-radius: 4px; margin: 5px 0;">
      <strong>Phase 2 peruskysymykset (InsideBlockQuestions.csv):</strong><br>
      <?php 
      foreach ($csvData['phase2_blocks'] as $track => $blocks) {
        echo htmlspecialchars($track) . ": " . count($blocks) . " kpl";
        if (!empty($blocks)) {
          $pairs = [];
          foreach ($blocks as $block) {
            $pair = $block['pair'] ?? [];
            if (count($pair) >= 2) {
              $pairStr = $pair[0] . 'vs' . $pair[1];
              $pairs[$pairStr] = ($pairs[$pairStr] ?? 0) + 1;
            }
          }
          echo " (";
          foreach ($pairs as $pair => $count) {
            echo htmlspecialchars($pair) . ":" . $count . " ";
          }
          echo ")";
        }
        echo "<br>";
      }
      ?>
    </div>
    <?php endif; ?>
    
    <?php if (isset($csvData['phase2_tiebreaks'])): ?>
    <div style="background: #fff; padding: 10px; border-radius: 4px; margin: 5px 0;">
      <strong>Phase 2 tiebreak (InsideBlockQuestions.csv):</strong><br>
      <?php 
      foreach ($csvData['phase2_tiebreaks'] as $track => $tiebreaks) {
        echo htmlspecialchars($track) . ": " . count($tiebreaks) . " kpl<br>";
      }
      ?>
    </div>
    <?php endif; ?>
    
    <?php if (isset($csvData['type_descriptions'])): ?>
    <div style="background: #fff; padding: 10px; border-radius: 4px; margin: 5px 0;">
      <strong>Tyyppikuvaukset (TypeDescriptions.csv):</strong> <?= count($csvData['type_descriptions']) ?> tyyppiä
    </div>
    <?php endif; ?>
    
    <div style="background: #e8f5e8; padding: 10px; border-radius: 4px; margin: 5px 0;">
      <strong>Välimuisti ladattu:</strong> <?= isset($csvData['loaded_at']) ? date('Y-m-d H:i:s', $csvData['loaded_at']) : 'Ei ladattu' ?>
    </div>
    
    <?php if (isset($_SESSION['varPoints'])): ?>
    <h3>Muuttujapisteet (Phase 2):</h3>
    <pre style="background: #fff; padding: 10px; border-radius: 4px;"><?= htmlspecialchars(print_r($_SESSION['varPoints'], true)) ?></pre>
    <?php endif; ?>
    
    <div style="margin-top: 20px; padding: 10px; background: #fffacd; border-radius: 4px;">
      <strong>Toiminnot:</strong>
      <a href="?" style="margin-left: 10px; color: #4169e1;">Takaisin normaaliin</a> |
      <a href="?test=1&amp;showtestmode=1" style="margin-left: 10px; color: #4169e1;">Testitila</a> |
      <a href="?reset=1" style="margin-left: 10px; color: #dc3545;">Reset sessio</a>
    </div>
  </div>
<?php endif; ?>

<?php if (($_SESSION['state'] ?? '') === 'intro'):
    $oldNick  = h($_POST['nickname'] ?? ($_SESSION['nickname'] ?? ''));
    $oldEnnea = isset($_POST['ennea']) ? (string)$_POST['ennea'] : '';
    $inviteInfo = $_SESSION['invite_claim_info'] ?? '';
    $haveToken = !empty($_SESSION['InviteId']);
    $oldCode = h($_POST['invite_code'] ?? '');
?>
  <h2>Tervetuloa</h2>
  <p class="lead">
    Katso ensimmäisenä ohjevideo alla, niin saat käsityksen mistä testissä on kyse ja kuinka tehdä testi. Varaa rauhallista aikaa n. 10-15 minuuttia testin tekemiseen.
  </p>
  <div class="block" role="region" aria-label="Esittelyvideo">
    <div style="position: relative; width: 100%; height: 0; padding-bottom: 56.25%; margin: 0 auto;">
      <div id="video-thumbnail" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; background-image: url('video-thumbnail.png'); background-size: cover; background-position: center; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center;">
        <div style="width: 80px; height: 80px; background: rgba(0,0,0,0.8); border-radius: 50%; display: flex; align-items: center; justify-content: center; transition: all 0.3s ease; box-shadow: 0 4px 12px rgba(0,0,0,0.3);">
          <div style="width: 0; height: 0; border-left: 25px solid #fff; border-top: 15px solid transparent; border-bottom: 15px solid transparent; margin-left: 5px;"></div>
        </div>
      </div>
      <div id="video-iframe" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; display: none;">
      </div>
    </div>
    <script nonce="<?= $nonce ?>">
    document.getElementById('video-thumbnail').addEventListener('click', function() {
      var thumbnail = document.getElementById('video-thumbnail');
      var iframeContainer = document.getElementById('video-iframe');
      
      // Luo iframe autoplay-parametrilla
      var iframe = document.createElement('iframe');
      iframe.style.cssText = 'position: absolute; top: 0; left: 0; width: 100%; height: 100%; border: 0; border-radius: 8px;';
      iframe.src = 'https://www.youtube.com/embed/X2fGUT1MzM0?autoplay=1';
      iframe.title = 'Enneagrammitesti esittelyvideo';
      iframe.allowFullscreen = true;
      
      // Piilota thumbnail ja näytä video
      thumbnail.style.display = 'none';
      iframeContainer.appendChild(iframe);
      iframeContainer.style.display = 'block';
    });
    
    // Hover-efekti play-napille
    document.getElementById('video-thumbnail').addEventListener('mouseenter', function() {
      var playBtn = this.querySelector('div');
      playBtn.style.transform = 'scale(1.1)';
      playBtn.style.background = 'rgba(255,255,255,0.2)';
    });
    
    document.getElementById('video-thumbnail').addEventListener('mouseleave', function() {
      var playBtn = this.querySelector('div');
      playBtn.style.transform = 'scale(1)';
      playBtn.style.background = 'rgba(0,0,0,0.8)';
    });
    </script>
  </div>

  <?php if ($TEST_MODE): 
    $phase1_all_preview = get_cached_phase1_questions();
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

  <?php 
  $formError = '';
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start'])) {
      if (empty($_POST['ennea'])) {
          $formError = 'Valitse enneagrammityyli (1-9) tai \'en tiedä\' jatkaaksesi';
      } elseif ($_POST['ennea'] !== 'en tiedä' && empty($_POST['confidence'])) {
          $formError = 'Valitse varmuustasosi arvioistasi';
      }
  }
  ?>
  <?php if ($formError): ?>
    <div class="alert alert-err" role="alert"><?= h($formError) ?></div>
  <?php endif; ?>
  <form id="startForm" method="post" class="form-margin" novalidate>
    <?php if (INVITES_ENABLED && empty($_SESSION['InviteId'])): ?>
      <label for="invite_code">Kutsukoodi (jos sait kutsun)</label>
      <input type="text" id="invite_code" name="invite_code" value="<?=$oldCode?>" placeholder="Esim. ABCD1234">
      <div class="form-spacing"></div>
    <?php endif; ?>

    <label for="nickname">Nimimerkki</label>
    <input type="text" id="nickname" name="nickname" value="<?=$oldNick?>" autocomplete="nickname" class="input-margin">
    <div class="form-spacing-small"></div>

    <label for="ennea">Mikä on sinun enneagrammityylisi? <span class="text-required">*</span></label>
    <select id="ennea" name="ennea" required>
      <option value="">-- Valitse --</option>
      <?php for ($i=1;$i<=9;$i++): ?>
        <option value="<?=$i?>" <?=$oldEnnea===(string)$i?'selected':''?>><?=$i?></option>
      <?php endfor; ?>
      <option value="en tiedä" <?=$oldEnnea==='en tiedä'?'selected':''?>>en tiedä</option>
    </select>
    <p class="small text-small">Valitse enneagrammityylisi. Jos et tiedä, valitse "en tiedä".</p>
    
    <div id="confidenceContainer" class="confidence-container">
      <label for="confidence">Kuinka varma olet arvioistasi omasta tyypistäsi? <span class="text-required">*</span></label>
      <select id="confidence" name="confidence" required class="form-control">
        <option value="">-- Valitse --</option>
        <option value="1" <?= ($_POST['confidence'] ?? '') === '1' ? 'selected' : '' ?>>En ole lainkaan varma</option>
        <option value="2" <?= ($_POST['confidence'] ?? '') === '2' ? 'selected' : '' ?>>Olen melko varma</option>
        <option value="3" <?= ($_POST['confidence'] ?? '') === '3' ? 'selected' : '' ?>>Olen täysin varma</option>
      </select>
    </div>
    <script nonce="<?= $nonce ?>">
    function toggleConfidence() {
        const ennea = document.getElementById('ennea');
        const container = document.getElementById('confidenceContainer');
        const confidence = document.getElementById('confidence');
        
        if (ennea?.value && ennea.value !== 'en tiedä') {
            container?.classList.add('show');
            if (confidence) confidence.required = true;
        } else {
            container?.classList.remove('show');
            if (confidence) confidence.required = false;
        }
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('startForm');
        const ennea = document.getElementById('ennea');
        const confidence = document.getElementById('confidence');
        
        ennea?.addEventListener('change', toggleConfidence);
        toggleConfidence(); // Initialize state
        
        form?.addEventListener('submit', function(event) {
            if (!ennea?.value) {
                event.preventDefault();
                alert('Valitse enneagrammityyli (1-9) tai "en tiedä" jatkaaksesi');
                ennea?.focus();
                return;
            }
            
            if (ennea.value !== 'en tiedä' && !confidence?.value) {
                event.preventDefault();
                alert('Valitse varmuustasosi');
                confidence?.focus();
            }
        });
    });
    </script>

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
        <?= generate_progress_bar($i + 1, count($set), 'Eteneminen vaihe 1') ?>
      </div>
    </div>
    <div style="height:16px"></div>
    <div class="block"><p style="font-size:18px;margin:0"><?= h($q['text']) ?></p></div>

    <div class="question-layout">
      <div class="center-row scale-container">
        <div class="scale-label left desktop-only">
          Täysin eri mieltä
        </div>
        
        <div class="scale-buttons">
            <div class="mobile-scale-label top-left">Täysin eri mieltä</div>
            
            <?php 
            $labels = [
              1 => 'Täysin eri mieltä',
              2 => 'Jokseenkin eri mieltä', 
              3 => 'Hieman eri mieltä',
              4 => 'Hieman samaa mieltä',
              5 => 'Jokseenkin samaa mieltä',
              6 => 'Täysin samaa mieltä'
            ];
            
            for ($n=1;$n<=6;$n++): 
              $side = ($n <= 3) ? 'left' : 'right';
              $current_answer = $_SESSION['answers1_by_id'][$q['id']] ?? null;
              $isSelected = ($current_answer == $n);
            ?>
              <form method="post" class="choice-form">
                <input type="hidden" name="choice" value="<?=$n?>">
                
                <!-- Desktop version with original styling -->
                <div class="desktop-only">
                  <?= generate_phase1_button($n, $side, 'choice', $isSelected) ?>
                </div>
                
                <!-- Mobile version - just the button -->
                <div class="mobile-only">
                  <?= generate_phase1_button($n, $side, 'choice', $isSelected) ?>
                </div>
              </form>
              
              <?php if ($n === 3): ?>
                <span class="scale-divider">|</span>
              <?php endif; ?>
            <?php endfor; ?>
            <div class="mobile-scale-label bottom-right">Täysin samaa mieltä</div>
        </div>
        
        <div class="scale-label right desktop-only">
          Täysin samaa mieltä
        </div>
      </div>
      <p class="muted scale-help">Valitse asteikolta mielipidettäsi tai asennettasi parhaiten kuvaava vaihtoehto.</p>
      
      <!-- Navigointipainikkeet -->
      <div class="navigation-controls" style="display: flex; justify-content: space-between; align-items: center; margin: 20px 0; gap: 10px;">
        <?php if ($i > 0): ?>
          <form method="post" style="margin: 0;">
            <input type="hidden" name="navigate" value="prev">
            <button type="submit" class="btn" style="font-size: 14px; padding: 8px 12px;">
              ← Edellinen
            </button>
          </form>
        <?php else: ?>
          <div></div>
        <?php endif; ?>
        
        <?php 
        // Näytä "Seuraava" -painike jos on jo vastattu tähän kysymykseen ja on lisää kysymyksiä
        $current_answer = $_SESSION['answers1_by_id'][$q['id']] ?? null;
        if ($current_answer !== null && $i < $total - 1): ?>
          <form method="post" style="margin: 0;">
            <input type="hidden" name="navigate" value="next">
            <button type="submit" class="btn" style="font-size: 14px; padding: 8px 12px;">
              Seuraava →
            </button>
          </form>
        <?php else: ?>
          <div></div>
        <?php endif; ?>

          <?php
          // Näytä "Seuraava (lisäkysymykset)" -painike vain viimeisen vaiheen 1 kysymyksen yhteydessä
          if (
            $i === $total - 1 &&
            // Do not show navigation to tiebreak any more
            false
          ) {
            // Varmista että tiebreak-luokat vastaavat nykyisiä pisteitä
            $pts = $_SESSION['points1'] ?? [];
            arsort($pts);
            $max = reset($pts);
            $tops = array_keys(array_filter($pts, function($v) use ($max) { return $v === $max; }));
            $last_classes = [];
            foreach ($_SESSION['last_tiebreak_set'] as $tbq) {
              if (isset($tbq['class']) && !in_array($tbq['class'], $last_classes)) {
                $last_classes[] = $tbq['class'];
              }
            }
            // Jos tiebreak-luokat vastaavat nykyisiä pisteitä, näytä nappi
            if (count(array_diff($tops, $last_classes)) === 0 && count($tops) === count($last_classes)) {
              ?>
              <!-- navigation to tiebreak disabled -->
              <?php
            }
          }
          ?>
      </div>
      
      <div class="mobile-hint">
        Vinkki: Jos haluat nähdä vaihtoehdot rinnakkain, käännä puhelin vaakasuoraan
      </div>
    </div>

      <script nonce="<?= $nonce ?>">
      // Vaihe 1 painonappien valinta-animaatio ja ripple-efekti
      document.addEventListener('DOMContentLoaded', function() {
        var forms = document.querySelectorAll('.choice-form');
        
        // Ripple-efekti funktio
        function createRipple(btn, event) {
          var rect = btn.getBoundingClientRect();
          var ripple = document.createElement('span');
          ripple.className = 'ripple-effect';
          var size = Math.max(rect.width, rect.height) * 1.6;
          ripple.style.width = ripple.style.height = size + 'px';
          
          // Sijoitetaan klikattuun kohtaan (touch tai click)
          var clientX = event.clientX || (event.touches && event.touches[0] && event.touches[0].clientX) || rect.left + rect.width/2;
          var clientY = event.clientY || (event.touches && event.touches[0] && event.touches[0].clientY) || rect.top + rect.height/2;
          var x = clientX - rect.left;
          var y = clientY - rect.top;
          
          ripple.style.left = (x - size/2) + 'px';
          ripple.style.top = (y - size/2) + 'px';
          btn.appendChild(ripple);
          
          setTimeout(function() {
            if (ripple.parentNode) ripple.remove();
          }, 1100);
        }
        
        forms.forEach(function(form) {
          // Etsitään kaikki painikkeet formista ja valitaan näkyvä
          var buttons = form.querySelectorAll('.choice-btn');
          var btn = null;
          
          // Valitaan näkyvä painike (mobile tai desktop)
          for (var i = 0; i < buttons.length; i++) {
            var buttonStyle = window.getComputedStyle(buttons[i].parentElement);
            if (buttonStyle.display !== 'none') {
              btn = buttons[i];
              break;
            }
          }
          

          if (btn) {
            var rippleTriggered = false;
            
            // Touch start - primaari mobiilille
            btn.addEventListener('touchstart', function(e) {
              rippleTriggered = true;
              createRipple(btn, e);
              setTimeout(function() { rippleTriggered = false; }, 100);
            }, { passive: true });
            
            // Click - fallback desktopille ja jos touch ei toimi
            btn.addEventListener('click', function(e) {
              if (!rippleTriggered) {
                createRipple(btn, e);
              }
            });
            
            // Mousedown - varmistus desktopille
            btn.addEventListener('mousedown', function(e) {
              if (!rippleTriggered && !('ontouchstart' in window)) {
                createRipple(btn, e);
              }
            });
          }
          
          form.addEventListener('submit', function(e) {
            if (btn) {
              btn.classList.add('selected');
            }
            // Viive ennen lomakkeen lähettämistä (350ms)
            e.preventDefault();
            setTimeout(function() {
              form.submit();
            }, 350);
          });
        });
      });
      </script>

    <?php if ($TEST_MODE): ?>
      <div class="debug"><strong>Luokka (piilossa):</strong> <?= h($q['class']) ?> — id: <?= h($q['id']) ?></div>
    <?php endif; ?>

    <?php endif; ?>

<?php elseif (($_SESSION['state'] ?? '') === 'tiebreak_intro'): ?>
  <div class="progress-wrap">
    <div class="progress-label">Vaihe 1, tarkennuksia</div>
    <div class="progress">
      <div class="progress-bar" style="width: 0%;"></div>
    </div>
  </div>

  <h2>Tarkentavia lisäkysymyksiä </h2>
  <div style="background: #fff7e6; border: 1px solid #f0e0c8; border-radius: 8px; padding: 20px; margin: 20px 0; line-height: 1.6;">
    <h3 style="margin-top: 0; color: #555;">Pikaohjeet</h3>
    <p>Olemme havainneet monipuolisuutesi ja haluamme kysyä sinulta vielä hieman lisää ennen vaihetta 2. Seuraavaksi näet lyhyitä lisäkysymyksiä, joiden tarkoitus on syventää ja varmistaa sinun tyyliäsi.</p>
    <ul style="margin: 10px 0; padding-left: 20px;">
      <li>Vastaa rehellisesti kullekin väittämälle.</li>
      <li>Käytä samaa asteikkoa kuin edellä, mutta pyri käyttämään rohkeasti ja laajasti koko asteikkoa, jotta erot tulevat paremmin esille (Täysin eri mieltä → Täysin samaa mieltä).</li>
      <li>Osa kysymyksistä on samoja kuin aikaisemmin, älä turhaan mieti mitä silloin vastasit, vaan vastaa nyt uudestaan kuin "tyhjältä pöydältä".</li>
    </ul>
    <div style="background:#e7f3ff;border-left:4px solid #0066cc;padding:12px;margin-top:12px;">
      <strong>Vinkki:</strong> Vastaa sujuvalla rytmillä ja älä yritä analysoida liikaa. Valitse se vaihtoehto, joka tuntuu aidosti sinulta.
    </div>
  </div>
  <div class="footer" style="text-align:right;">
    <form method="post" style="display:inline;">
      <button type="submit" name="continue_tiebreak" value="1" class="btn">Jatka kyselyä</button>
    </form>
  </div>

<?php elseif (($_SESSION['state'] ?? '') === 'tiebreak'):
    $set = $_SESSION['tiebreak_set'] ?? []; $total = count($set);
    $i   = (int)($_SESSION['tiebreak_index'] ?? 0);
    $pct = ($total>0) ? (int)floor(($i/$total)*100) : 0;
?>
  <div class="row"><h2>Vaihe 1</h2>
    <div style="flex:1 1 100%">
      <?= generate_progress_bar($i + 1, count($set), 'Lisäkysymysten eteneminen') ?>
    </div>
  </div>
  <div style="height:16px"></div>
  <?php if ($total === 0): ?>
    <p class="muted">Lisäkysymyksiä ei löytynyt. Jatketaan pisteillä.</p>
    <form method="post"><button class="btn" name="tb_choice" value="4">Jatka</button></form>
  <?php else:
      $q = $set[$i] ?? null;
      if ($q): 
        // Check if we have stored answer for current question
        $currentAnswer = $_SESSION['tiebreak_answers'][$i] ?? null;
      ?>
      <div class="block"><p class="question-text"><?= h($q['text']) ?></p></div>
      

      
      <div class="question-layout">
        <div class="center-row scale-container">
          <div class="scale-label left desktop-only">
            Täysin eri mieltä
          </div>
          
          <div class="scale-buttons">
            <div class="mobile-scale-label top-left">Täysin eri mieltä</div>
            
            <?php 
            $labels = [
              1 => 'Täysin eri mieltä',
              2 => 'Jokseenkin eri mieltä', 
              3 => 'Hieman eri mieltä',
              4 => 'Hieman samaa mieltä',
              5 => 'Jokseenkin samaa mieltä',
              6 => 'Täysin samaa mieltä'
            ];
            
            for ($n=1;$n<=6;$n++): 
              $side = ($n <= 3) ? 'left' : 'right';
              $isSelected = ($currentAnswer === $n);
            ?>
              <form method="post" class="tb-choice-form">
                <input type="hidden" name="tb_choice" value="<?=$n?>">
                
                <!-- Desktop version with original styling -->
                <div class="desktop-only">
                  <?= generate_phase1_button($n, $side, 'tb-choice', $isSelected) ?>
                </div>
                
                <!-- Mobile version - just the button -->
                <div class="mobile-only">
                  <?= generate_phase1_button($n, $side, 'tb-choice', $isSelected) ?>
                </div>
              </form>
              
              <?php if ($n === 3): ?>
                <span class="scale-divider">|</span>
              <?php endif; ?>
            <?php endfor; ?>
            <div class="mobile-scale-label bottom-right">Täysin samaa mieltä</div>
          </div>
          
          <div class="scale-label right desktop-only">
            Täysin samaa mieltä
          </div>
        </div>
        <p class="muted scale-help">Valitse asteikolta mielipidettäsi tai asennettasi parhaiten kuvaava vaihtoehto.</p>
        
        <!-- Navigointipainikkeet -->
        <div class="navigation-controls" style="display: flex; justify-content: space-between; align-items: center; margin: 20px 0; gap: 10px;">
          <?php if ($i > 0): ?>
            <form method="post" style="margin: 0;">
              <input type="hidden" name="tiebreak_navigate" value="back">
              <button type="submit" class="btn" style="font-size: 14px; padding: 8px 12px;">
                ← Edellinen
              </button>
            </form>
          <?php else: ?>
            <div></div>
          <?php endif; ?>
          
          <?php 
          // Näytä "Seuraava" -painike jos on jo vastattu tähän kysymykseen ja on lisää kysymyksiä
          if ($currentAnswer !== null && $i < $total - 1): ?>
            <form method="post" style="margin: 0;">
              <input type="hidden" name="tiebreak_navigate" value="forward">
              <button type="submit" class="btn" style="font-size: 14px; padding: 8px 12px;">
                Seuraava →
              </button>
            </form>
          <?php else: ?>
            <div></div>
          <?php endif; ?>
        </div>
        
        <div class="mobile-hint">
          Vinkki: Jos haluat nähdä vaihtoehdot rinnakkain, käännä puhelin vaakasuoraan
        </div>
      </div>
      <script nonce="<?= $nonce ?>">
      // Tiebreak painonappien ripple-efekti ja valinta-animaatio
      document.addEventListener('DOMContentLoaded', function() {
        var forms = document.querySelectorAll('.tb-choice-form');
        
        // Ripple-efekti funktio
        function createRipple(btn, event) {
          var rect = btn.getBoundingClientRect();
          var ripple = document.createElement('span');
          ripple.className = 'ripple-effect';
          var size = Math.max(rect.width, rect.height) * 1.6;
          ripple.style.width = ripple.style.height = size + 'px';
          
          // Sijoitetaan klikattuun kohtaan (touch tai click)
          var clientX = event.clientX || (event.touches && event.touches[0] && event.touches[0].clientX) || rect.left + rect.width/2;
          var clientY = event.clientY || (event.touches && event.touches[0] && event.touches[0].clientY) || rect.top + rect.height/2;
          var x = clientX - rect.left;
          var y = clientY - rect.top;
          
          ripple.style.left = (x - size/2) + 'px';
          ripple.style.top = (y - size/2) + 'px';
          btn.appendChild(ripple);
          
          setTimeout(function() {
            if (ripple.parentNode) ripple.remove();
          }, 1100);
        }
        
        forms.forEach(function(form) {
          // Etsitään kaikki painikkeet formista ja valitaan näkyvä
          var buttons = form.querySelectorAll('.tb-choice-btn');
          var btn = null;
          
          // Valitaan näkyvä painike (mobile tai desktop)
          for (var i = 0; i < buttons.length; i++) {
            var buttonStyle = window.getComputedStyle(buttons[i].parentElement);
            if (buttonStyle.display !== 'none') {
              btn = buttons[i];
              break;
            }
          }
          

          if (btn) {
            var rippleTriggered = false;
            
            // Touch start - primaari mobiilille
            btn.addEventListener('touchstart', function(e) {
              rippleTriggered = true;
              createRipple(btn, e);
              setTimeout(function() { rippleTriggered = false; }, 100);
            }, { passive: true });
            
            // Click - fallback desktopille ja jos touch ei toimi
            btn.addEventListener('click', function(e) {
              if (!rippleTriggered) {
                createRipple(btn, e);
              }
            });
            
            // Mousedown - varmistus desktopille
            btn.addEventListener('mousedown', function(e) {
              if (!rippleTriggered && !('ontouchstart' in window)) {
                createRipple(btn, e);
              }
            });
          }
          
          form.addEventListener('submit', function(e) {
            if (btn) {
              btn.classList.add('selected');
            }
            // Viive ennen lomakkeen lähettämistä (350ms)
            e.preventDefault();
            setTimeout(function() {
              form.submit();
            }, 350);
          });
        });
      });
      </script>
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
    $type_desc_map = get_cached_type_descriptions();
    $desc = $type_desc_map['4'] ?? ''; 
    if ($desc!==''): ?>
    <div class="block"><p style="margin:0"><?= h($desc) ?></p></div>
  <?php else: ?>
    <p class="muted">Kuvausta ei löytynyt tyypille 4.</p>
  <?php endif; ?>
  <div class="footer">
    <a class="linkbtn" href="?reset=1">Aloita alusta</a>
    <a class="linkbtn" href="https://www.enneagram.fi/tietoa-enneagrammista/" target="_blank" rel="noopener">Lue lisää enneagrammista</a>
    <?php if (empty($_SESSION['feedback_given'])): ?>
    <form method="post" style="display:inline">
      <input type="submit" class="linkbtn" name="show_feedback" value="Anna palautetta" style="border:1px solid #ccc;background:#fafafa;cursor:pointer">
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

<?php elseif (($_SESSION['state'] ?? '') === 'phase2_intro'): ?>
  <div class="progress-wrap">
    <div class="progress-label">Vaihe 2</div>
    <div class="progress">
      <div class="progress-bar" style="width: 0%;"></div>
    </div>
  </div>

  <h2>Syventävät kysymykset</h2>
  
  <div style="background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 8px; padding: 20px; margin: 20px 0; line-height: 1.6;">
    <h3 style="margin-top: 0; color: #495057;">Mitä seuraavaksi?</h3>

    <p><strong>Kysymystyypit:</strong> Seuraavaksi saat nähdä useita tilannekuvauksia, ja kaksi erilaista käyttäytymistapaa tai ajattelutapaa tällaisessa tilanteessa.</p>

    <p><strong>Vastaaminen:</strong> Jokaisessa tilannekuvauksessa:</p>
    <ul style="margin: 10px 0; padding-left: 20px;">
      <li>Lue tilannekuvaus ja sen alapuolella olevat molemmat vaihtoehdot huolellisesti</li>
      <li>Mieti kumpi kuvaa sinua enemmän</li>
      <li>Valitse kuinka vahvasti olet samaa mieltä: <strong>selvästi</strong> tai <strong>lievästi</strong></li>
      <li>Jos et osaa valita, käytä <strong>"En osaa sanoa"</strong> -vaihtoehtoa</li>
    </ul>
    
    <p><strong>Vinkki:</strong> Älä mieti liikaa – valitse se vaihtoehto, joka tuntuu luonnollisemmalta sinulle useimmiten.</p>
    
    <div style="background: #e7f3ff; border-left: 4px solid #0066cc; padding: 15px; margin: 15px 0;">
      <p style="margin: 0;"><strong>📱 Mobiililaitteella:</strong> Voit kääntää laitteen vaakasuoraan nähdäksesi molemmat vaihtoehdot rinnakkain.</p>
    </div>
  </div>

  <div class="footer" style="text-align: right;">
    <form method="post" style="display: inline;">
      <button type="submit" name="continue_phase2" value="1" class="btn">Jatka kyselyä</button>
    </form>
  </div>

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
    <div style="flex:1 1 100%"><?= generate_progress_bar($i + 1, count($_SESSION['blocks2'] ?? []), 'Eteneminen vaihe 2') ?></div>
  </div>
  <div style="height:16px"></div>

  <?php if ($blk): ?>
    <div class="case-wrap">
      <div class="case-desc"><?= h($blk['desc']) ?></div>
      <div class="mobile-options-container">
        <div class="opt-left">
          <div class="opt-box left">
            <div class="opt-box-content"><?= h($blk['left_text']) ?></div>
            <div class="btn-group scale-row" role="group" aria-label="Vasemmanpuoleiset valinnat">
              <?php 
              $buttons = generate_phase2_button_group();
              echo $buttons['left_strong'] . $buttons['left_mild'];
              ?>
            </div>
          </div>
        </div>
        <div class="mobile-eos-button">
          <?= $buttons['neutral'] ?>
        </div>
        <div class="opt-center">
        <div class="opt-box center">
          <div class="opt-box-content">En osaa sanoa</div>
          <div class="btn-group" role="group" aria-label="Keskimmäinen valinta">
            <?= $buttons['neutral'] ?>
          </div>
        </div>
      </div>
      <div class="opt-right">
        <div class="opt-box right mobile-buttons-top">
          <div class="btn-group scale-row" role="group" aria-label="Oikeanpuoleiset valinnat">
            <?php 
            echo $buttons['right_mild'] . $buttons['right_strong'];
            ?>
          </div>
          <div class="opt-box-content"><?= h($blk['right_text']) ?></div>
        </div>
      </div>
      </div> <!-- /mobile-options-container -->
      <div class="phase2-instructions" style="grid-column: 1 / -1; color: #555; font-size: 13px; line-height: 1.4; max-width: 100%; margin: 20px 0 10px 0; text-align: center;">
        Mieti kuvaako sinua vasemman vai oikean laidan kuvaus sinua enemmän ja sen jölkeen valitse oletko selvästi vai lievästi samaa mieltä kuvauksen kanssa. Jos et osaa valita, valitse En osaa sanoa -vaihtoehto.
      </div>
      <div class="mobile-hint">
        Vinkki: Jos haluat nähdä vaihtoehdot rinnakkain, käännä puhelin vaakasuoraan
      </div>
      <script nonce="<?= $nonce ?>">
      // Vaihe 2 painonappien ripple-efekti ja valinta-animaatio
      document.addEventListener('DOMContentLoaded', function() {
        var forms = document.querySelectorAll('.choice2-form, .tb2-form');
        
        // Ripple-efekti funktio
        function createRipple(btn, event) {
          var rect = btn.getBoundingClientRect();
          var ripple = document.createElement('span');
          ripple.className = 'ripple-effect';
          var size = Math.max(rect.width, rect.height) * 1.6;
          ripple.style.width = ripple.style.height = size + 'px';
          
          // Sijoitetaan klikattuun kohtaan (touch tai click)
          var clientX = event.clientX || (event.touches && event.touches[0] && event.touches[0].clientX) || rect.left + rect.width/2;
          var clientY = event.clientY || (event.touches && event.touches[0] && event.touches[0].clientY) || rect.top + rect.height/2;
          var x = clientX - rect.left;
          var y = clientY - rect.top;
          
          ripple.style.left = (x - size/2) + 'px';
          ripple.style.top = (y - size/2) + 'px';
          btn.appendChild(ripple);
          
          setTimeout(function() {
            if (ripple.parentNode) ripple.remove();
          }, 1100);
        }
        
        forms.forEach(function(form) {
          var btn = form.querySelector('.choice2-btn, .ripple');
          if (btn) {
            var rippleTriggered = false;
            
            // Touch start - primaari mobiilille
            btn.addEventListener('touchstart', function(e) {
              rippleTriggered = true;
              createRipple(btn, e);
              setTimeout(function() { rippleTriggered = false; }, 100);
            }, { passive: true });
            
            // Click - fallback desktopille ja jos touch ei toimi
            btn.addEventListener('click', function(e) {
              if (!rippleTriggered) {
                createRipple(btn, e);
              }
            });
            
            // Mousedown - varmistus desktopille
            btn.addEventListener('mousedown', function(e) {
              if (!rippleTriggered && !('ontouchstart' in window)) {
                createRipple(btn, e);
              }
            });
          }
          
          form.addEventListener('submit', function(e) {
            if (btn) {
              btn.classList.add('selected');
            }
            // Viive ennen lomakkeen lähettämistä (350ms)
            e.preventDefault();
            setTimeout(function() {
              form.submit();
            }, 350);
          });
        });
      });
      </script>
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
  <div style="height:16px"></div>

  <?php if ($TEST_MODE): ?>
    <div class="debug"><strong>TB2-pari:</strong> <?= h(implode(' vs ', $pair)) ?> — caset: <?= (int)$total ?></div>
  <?php endif; ?>

  <?php if ($blk): ?>
    <div class="case-wrap">
      <div class="case-desc"><?= h($blk['desc']) ?></div>
      <div class="mobile-options-container">
        <div class="opt-left">
          <div class="opt-box left">
            <div class="opt-box-content"><?= h($blk['left_text']) ?></div>
            <div class="btn-group scale-row" role="group" aria-label="Vasemmanpuoleiset valinnat">
              <?php 
              $buttons = generate_phase2_tiebreak_button_group();
              echo $buttons['left_strong'] . $buttons['left_mild'];
              ?>
            </div>
          </div>
        </div>
        <div class="mobile-eos-button">
          <?= $buttons['neutral'] ?>
        </div>
        <div class="opt-center">
          <div class="opt-box center">
            <div class="opt-box-content">En osaa sanoa</div>
            <div class="btn-group" role="group" aria-label="Keskimmäinen valinta">
              <?= $buttons['neutral'] ?>
            </div>
          </div>
        </div>
        <div class="opt-right">
          <div class="opt-box right">
            <div class="btn-group scale-row mobile-buttons-top" role="group" aria-label="Oikeanpuoleiset valinnat">
              <?php 
              echo $buttons['right_mild'] . $buttons['right_strong'];
              ?>
            </div>
            <div class="opt-box-content"><?= h($blk['right_text']) ?></div>
          </div>
        </div>
      </div>
      <div class="phase2-instructions" style="grid-column: 1 / -1; color: #555; font-size: 13px; line-height: 1.4; max-width: 100%; margin: 20px 0 10px 0; text-align: center;">
        Mieti kuvaako sinua vasemman vai oikean laidan kuvaus sinua enemmän ja sen jölkeen valitse oletko selvästi vai lievästi samaa mieltä kuvauksen kanssa. Jos et osaa valita, valitse En osaa sanoa -vaihtoehto.
      </div>
      <div class="mobile-hint">
        Vinkki: Jos haluat nähdä vaihtoehdot rinnakkain, käännä puhelin vaakasuoraan
      </div>
    </div>
  <?php else: ?>
    <p class="muted">Tiebreak-caseja ei löydy valitulle parille. Ratkaistaan satunnaisesti.</p>
  <?php endif; ?>

<?php elseif (($_SESSION['state'] ?? '') === 'done2'):
    $vars = $_SESSION['varPoints'] ?? []; arsort($vars);
    $nonzero = array_filter($vars, function($v) { return $v > 0; });
    $topVar  = $_SESSION['log_row']['finalVar'] ?? ( $nonzero ? key($nonzero) : '' );
    $type_desc_map = get_cached_type_descriptions();
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
    <a class="linkbtn" href="?reset=1">Aloita alusta</a>
    <a class="linkbtn" href="https://www.enneagram.fi/tietoa-enneagrammista/" target="_blank" rel="noopener">Lue lisää enneagrammista</a>
    <?php if (empty($_SESSION['feedback_given'])): ?>
    <form method="post" style="display:inline">
      <input type="submit" class="linkbtn" name="show_feedback" value="Anna palautetta" style="border:1px solid #ccc;background:#fafafa;cursor:pointer">
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

  <div class="credits">Suomen Enneagrammiyhdistys, 2025</div>
</div>

</body>
</html>

