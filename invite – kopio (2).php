<?php declare(strict_types=1);

/**
 * ENNEAGRAMMITESTI - PILOTTIKUTSU-SOVELLUS
 * =========================================
 * 
 * Tämä sovellus mahdollistaa enneagrammitestin pilottikutsujen lähettämisen
 * sähköpostitse. Ku    // URL-tarkistus - linkit eivät ole sallittuja
    $urlCount = 0;
    if ($note_clean !== '') {
        $urlCount += preg_match_all('/https?:\/\/[^\s]+/iu', $note_clean) ?: 0;
        $urlCount += preg_match_all('/\bwww\.[^\s]+/iu', $note_clean) ?: 0;
    }
    if ($urlCount > 0) {
        $errors[] = 'Saatesanoissa ei saa olla linkkejä.';
    }tää uniikin linkin testiin sekä varakoodin.
 * 
 * Ominaisuudet:
 * - Turvallinen lomakekäsittely (CSRF, honeypot, CAPTCHA)
 * - Sähköpostivalidointi DNS MX -tarkistuksella
 * - Kutsutietojen tallennus JSON-muodossa
 * - Lokitus kuukausittaisiin tiedostoihin
 * - Responsiivinen käyttöliittymä
 * 
 * @author Suomen Enneagrammiyhdistys
 * @version 1.0
 * @date 2025
 */

/* ========================================================================
   PERUSASETUKSET JA  <div class="header">
    <div>
      <h1>Lähetä kutsu enneagrammitestin pilottiin</h1>
      <p class="small" style="margin:6px 0 0">
        Tällä lomakkeella voit lähettää tutullesi kutsun osallistua enneagrammitestin pilottiin.
        Sinun nimesi kerrotaan kutsuttavalle, mutta ei sinun arviotasi hänen <strong>enneagrammityylistaän</strong>.
        Anna kutsuttavan tyyli ja kerro oma arviosi tyylin osuvuuden todennäköisyydestä.
      </p>
    </div>
  </div>US
   ======================================================================== */

error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('Europe/Helsinki');

/* Sessioevästeet */
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

/* Turvaotsikot */
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none';");
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: geolocation=(), camera=(), microphone=()');

/* ========================================================================
   KONFIGURAATIO JA VAKIOT
   ======================================================================== */
const FROM_EMAIL = 'no-reply@enneagrammitesti.fi'; // pidä lähetys tästä osoitteesta
const SUBJECT    = 'Tarvitsen apuasi testin rakentamisessa!';

const NOTE_MAX_LEN   = 500; // saatesanat max merkit
const MIN_FORM_SECS  = 5;   // min aika (sekuntia) lomakkeen avauksesta lähetykseen

// Token- ja koodipituudet
const TOKEN_LENGTH = 48;
const HUMAN_CODE_LENGTH = 8;

// CAPTCHA-asetukset
const CAPTCHA_MIN_NUM = 2;
const CAPTCHA_MAX_NUM = 9;

// Tiedostopolut
const DATA_DIR_NAME = 'data';
const INVITES_FILE = 'invites.json';
const LOG_FILE_PREFIX = 'invite_app_';

// Varmuustasot
const CERTAINTY_LEVELS = [
    'very_certain' => 'Olen täysin varma',
    'quite_certain' => 'Olen melko varma', 
    'not_certain' => 'En ole olleenkaan varma'
];

/* --- Lokitus --- */
/**
 * Palauttaa data-hakemiston polun ja luo sen tarvittaessa
 * 
 * Yrittää ensin parent-hakemiston data-kansiota, sitten nykyisen hakemiston data-kansiota.
 * Luo hakemiston automaattisesti jos sitä ei ole olemassa.
 * 
 * @return string Absoluuttinen polku data-hakemistoon
 */
function data_dir(): string {
    $pref = realpath(__DIR__ . '/../') !== false ? (__DIR__ . '/../' . DATA_DIR_NAME) : (__DIR__ . '/' . DATA_DIR_NAME);
    if (!is_dir($pref)) @mkdir($pref, 0700, true);
    return $pref;
}
/**
 * Palauttaa kuukausittaisen lokitiedoston polun
 * 
 * Lokitiedostot nimetään muotoon: invite_app_YYYY-MM.log
 * 
 * @return string Absoluuttinen polku lokitiedostoon
 */
function invite_log_path(): string {
    return data_dir() . '/' . LOG_FILE_PREFIX . date('Y-m') . '.log';
}
/**
 * Kirjoittaa timestampatun viestin lokitiedostoon
 * 
 * Lisää automaattisesti aikaleiman, IP-osoitteen ja rivinvaihdon.
 * Käyttää file locking -mekanismia turvalliseen kirjoittamiseen.
 * 
 * @param string $msg Lokiin kirjoitettava viesti
 * @return void
 */
function invite_log(string $msg): void {
    $ts = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    @file_put_contents(invite_log_path(), '['.$ts.']['.$ip.'] '.$msg.PHP_EOL, FILE_APPEND | LOCK_EX);
}

/* ========================================================================
   APUFUNKTIOT
   ======================================================================== */
/**
 * Turvallinen HTML-escape funktio
 * 
 * Muuntaa erikoismerkit HTML-entiteeteiksi XSS-hyökkäysten estämiseksi.
 * 
 * @param string $s Käsiteltävä merkkijono
 * @return string HTML-turvallinen merkkijono
 */
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/* --- Virheenkäsittely --- */
/**
 * Lisää virheen virhelistaan ja lokittaa sen tarvittaessa
 * 
 * @param array &$errors Viitelista virheisiin (muokataan suoraan)
 * @param string $message Käyttäjälle näytettävä virheviesti
 * @param string $log_message Valinnainen viesti lokitiedostoon
 * @return void
 */
function add_error(array &$errors, string $message, string $log_message = ''): void {
    $errors[] = $message;
    if ($log_message !== '') {
        invite_log($log_message);
    }
}

/**
 * Käsittelee lomakevirheet ja resetoi CAPTCHA:n
 * 
 * @param array $errors Virhelista (ei käytetä tällä hetkellä)
 * @param string $log_message Valinnainen viesti lokitiedostoon
 * @return void
 */
function handle_form_error(array $errors, string $log_message = ''): void {
    if ($log_message !== '') {
        invite_log($log_message);
    }
    reset_captcha();
}

/**
 * Käsittelee onnistuneen lomakkeen lähetyksen
 * 
 * Tyhjentää lomakekentät, resetoi session ja palauttaa onnistumisviestin.
 * 
 * @param string $message Käyttäjälle näytettävä onnistumisviesti
 * @param array $invite Kutsutietue (ei käytetä tällä hetkellä)
 * @param string $log_message Valinnainen viesti lokitiedostoon
 * @return array [onnistumisviesti, tyhjät kentät]
 */
function handle_success(string $message, array $invite, string $log_message = ''): array {
    $success_msg = $message;
    if ($log_message !== '') {
        invite_log($log_message);
    }
    
    // Tyhjennä kentät ja resetoi sessio
    $clean_fields = ['ref_name'=>'','nom_name'=>'','nom_email'=>'','guess_type'=>'','guess_prob'=>'','ref_note'=>'','captcha'=>''];
    $_SESSION['form_ts'] = time();
    reset_captcha();
    
    return [$success_msg, $clean_fields];
}
function validate_form_data(array $data): array {
    $errors = [];
    
    $ref_name   = trim($data['ref_name']);
    $nom_name   = trim($data['nom_name']);
    $nom_email  = trim($data['nom_email']);
    $guess_type = trim($data['guess_type']);
    $prob_s     = trim($data['guess_prob']);
    $ref_note   = $data['ref_note'];
    
    if ($ref_name === '') $errors[] = 'Anna kutsujan nimi.';
    if ($nom_name === '') $errors[] = 'Anna kutsuttavan nimi.';
    
    if (!filter_var($nom_email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Kutsuttavan sähköpostiosoite on virheellinen.';
    } else {
        if (preg_match('/^[^@\s]+@([^\s@]+)$/', $nom_email, $m)) {
            $domain = $m[1];
            if (!checkdnsrr($domain, 'MX')) {
                $errors[] = 'Kutsuttavan sähköpostin toimivuus epävarma (MX-tietuetta ei löytynyt).';
            }
        }
    }
    
    if ($guess_type === '' || !preg_match('/^[1-9]$/', $guess_type)) {
        $errors[] = 'Valitse tyyli 1–9.';
    }
    
    if (!array_key_exists($prob_s, CERTAINTY_LEVELS)) {
        $errors[] = 'Valitse varmuustaso.';
    }
    
    // Saatesanojen suodatus
    $note_clean = trim(strip_tags($ref_note));
    if (mb_strlen($note_clean, 'UTF-8') > NOTE_MAX_LEN) {
        $errors[] = 'Saatesanat ovat liian pitkät (max '.NOTE_MAX_LEN.' merkkiä).';
    }
    
    // URL-tarkistus - linkit eivät ole sallittuja
    $urlCount = 0;
    if ($note_clean !== '') {
        $urlCount += preg_match_all('/https?:\/\/[^\s]+/iu', $note_clean) ?: 0;
        $urlCount += preg_match_all('/\bwww\.[^\s]+/iu', $note_clean) ?: 0;
    }
    if ($urlCount > 0) {
        $errors[] = 'Saatesanoissa ei saa olla linkkejä.';
    }
    
    return $errors;
}

/**
 * Validoi CAPTCHA-vastauksen
 * 
 * Vertaa käyttäjän syöttämää vastausta sessiossa säilytettyyn oikeaan vastaukseen.
 * Käyttää hash_equals-funktiota timing attack -hyökkäysten ehkäisemiseksi.
 * 
 * @param string $input Käyttäjän syöttämä vastaus
 * @return bool True jos vastaus on oikein
 */
function validate_captcha(string $input): bool {
    $cap_in = trim($input);
    return ($cap_in !== '' && isset($_SESSION['captcha_ans']) && hash_equals($_SESSION['captcha_ans'], $cap_in));
}

/**
 * Luo uuden kutsutietueen
 * 
 * Generoi uniikit tunnisteet (UUID, token, koodi) ja luo täydellisen
 * kutsutietueen kaikilla tarvittavilla tiedoilla.
 * 
 * @param array $data Lomakkeen syötetiedot
 * @return array Täydellinen kutsutietue
 */
function create_invite_record(array $data): array {
    $id    = uuid4();
    $token = rand_token();
    $code  = human_code();
    $now   = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    
    return [
        'id'             => $id,
        'token'          => $token,
        'code'           => $code,
        'referrer_name'  => trim($data['ref_name']),
        'referrer_email' => '',
        'nominee_name'   => trim($data['nom_name']),
        'nominee_email'  => trim($data['nom_email']),
        'guess_type'     => trim($data['guess_type']),
        'guess_prob'     => trim($data['guess_prob']),
        'status'         => 'sent',
        'created_at'     => $now,
        'accepted_at'    => null,
        'completed_at'   => null,
        'expires_at'     => null
    ];
}

/**
 * Lähettää kutsusähköpostin
 * 
 * Rakentaa kutsuviestin joka sisältää:
 * - Henkiökohtaisen tervehdyksen
 * - Kutsujan nimen
 * - Valinnaiset saatesanat
 * - Suoran linkin testiin (token)
 * - Varakoodin manuaalista syöttöä varten
 * 
 * @param array $invite Kutsutietue
 * @param string $note_clean Siivotut saatesanat
 * @return bool True jos sähköposti lähetettiin onnistuneesti
 */
function send_invitation_email(array $invite, string $note_clean): bool {
    $testUrl = rtrim(base_url(), '/').'/index.php?invite='.rawurlencode($invite['token']);
    
    $body = "<!DOCTYPE html>\n".
            "<html lang='fi'>\n".
            "<head>\n".
            "<meta charset='UTF-8'>\n".
            "<meta name='viewport' content='width=device-width, initial-scale=1.0'>\n".
            "<title>Kutsu enneagrammitestiin</title>\n".
            "</head>\n".
            "<body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;'>\n".
            "<h2 style='color: #2c5aa0;'>Kutsu enneagrammitestin pilottiin</h2>\n".
            "<p>Hei <strong>{$invite['nominee_name']}</strong>,</p>\n".
            "<p><strong>{$invite['referrer_name']}</strong> kutsui sinut tekemään <strong>ENNEAGRAMMITESTI-pilotin</strong>.</p>\n";
    
    // Käytä aina käyttäjän syöttämää viestiä (joko muokattua tai alkuperäistä oletusarvoista)
    if ($note_clean !== '') {
        $body .= "<div style='background-color: #f9f9f9; padding: 15px; border-left: 4px solid #2c5aa0; margin: 20px 0;'>\n".
                 "<p style='margin: 0; font-style: italic;'>\"{$note_clean}\"</p>\n".
                 "<p style='margin: 5px 0 0 0; font-size: 0.9em; color: #666;'>– {$invite['referrer_name']}</p>\n".
                 "</div>\n";
    }
    
    $body .= "<div style='background-color: #e8f4fd; padding: 20px; border-radius: 8px; margin: 20px 0; text-align: center;'>\n".
             "<h3 style='margin: 0 0 15px 0; color: #2c5aa0;'>Aloita testi</h3>\n".
             "<p style='margin: 0 0 15px 0;'>Klikkaa alla olevaa nappia päästäksesi testiin:</p>\n".
             "<a href='{$testUrl}' style='display: inline-block; background-color: #2c5aa0; color: white; padding: 12px 25px; text-decoration: none; border-radius: 5px; font-weight: bold;'>Avaa enneagrammitesti</a>\n".
             "</div>\n".
             "<div style='background-color: #fff3cd; padding: 15px; border-radius: 5px; margin: 20px 0;'>\n".
             "<h4 style='margin: 0 0 10px 0; color: #856404;'>Vaihtoehtoinen tapa:</h4>\n".
             "<p style='margin: 0;'>Jos linkki ei toimi, voit siirtyä osoitteeseen <strong>".rtrim(base_url(), '/')."</strong> ja syöttää koodin:</p>\n".
             "<p style='font-size: 1.2em; font-weight: bold; color: #856404; margin: 10px 0;'>{$invite['code']}</p>\n".
             "</div>\n".
             "<hr style='border: none; border-top: 1px solid #ddd; margin: 30px 0;'>\n".
             "<p style='color: #666; font-size: 0.9em;'>\n".
             "Ystävällisin terveisin<br>\n".
             "<strong>Testitiimi</strong><br>\n".
             "Suomen Enneagrammiyhdistys\n".
             "</p>\n".
             "</body>\n".
             "</html>";
    
    return send_mail_with_from_name($invite['nominee_email'], $invite['referrer_name'], SUBJECT, $body);
}

/* --- Sähköposti --- */
/**
 * Lähettää sähköpostin määritetyllä lähettäjänimellä
 * 
 * Luo 'Lähettäjänimi via Enneagrammitesti' -muotoisen lähettäjän.
 * Käyttää UTF-8 enkoodausta ja BASE64-otsikkoja.
 * 
 * @param string $to Vastaanottajan sähköpostiosoite
 * @param string $fromName Lähettäjän nimi
 * @param string $subject Viestin otsikko
 * @param string $body Viestin sisältö (plain text)
 * @return bool True jos lähetys onnistui
 */
function send_mail_with_from_name(string $to, string $fromName, string $subject, string $body): bool {
    $headers = [];
    $fromDisplay = trim($fromName) !== '' ? ($fromName . ' via Enneagrammitesti') : 'Enneagrammitesti';
    $headers[] = 'From: '.$fromDisplay.' <'.FROM_EMAIL.'>';
    $headers[] = 'Reply-To: '.FROM_EMAIL;
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/html; charset=UTF-8';
    $headers_str = implode("\r\n", $headers);
    $subjectEnc = '=?UTF-8?B?'.base64_encode($subject).'?=';
    return @mail($to, $subjectEnc, $body, $headers_str);
}

/* --- Kutsuvarasto --- */
/**
 * Palauttaa kutsutiedoston polun
 * 
 * @return string Absoluuttinen polku invites.json-tiedostoon
 */
function invites_path(): string { return data_dir() . '/' . INVITES_FILE; }
/**
 * Lataa kaikki kutsut JSON-tiedostosta
 * 
 * Käyttää file locking -mekanismia turvalliseen lukemiseen.
 * Palauttaa tyhjän taulukon jos tiedostoa ei ole tai se on tyhjä.
 * 
 * @return array Lista kaikista kutsuista
 */
function load_invites(): array {
    $p = invites_path();
    if (!file_exists($p) || filesize($p) === 0) return [];
    $fp = fopen($p, 'r'); if (!$fp) return [];
    flock($fp, LOCK_SH);
    $json = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $arr = json_decode($json, true);
    return is_array($arr) ? $arr : [];
}
/**
 * Tallentaa kutsut JSON-tiedostoon
 * 
 * Käyttää file locking -mekanismia turvalliseen kirjoittamiseen.
 * JSON muotoillaan siististi (PRETTY_PRINT) ja UTF-8 merkeillä.
 * 
 * @param array $inv Lista kutsuista
 * @return bool True jos tallennus onnistui
 */
function save_invites(array $inv): bool {
    $p = invites_path();
    $fp = fopen($p, 'c+'); if (!$fp) return false;
    flock($fp, LOCK_EX);
    ftruncate($fp, 0); rewind($fp);
    $ok = fwrite($fp, json_encode($inv, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)) !== false;
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $ok;
}

/* --- Generointi --- */
/**
 * Generoi UUID v4 -tunnisteen
 * 
 * Luo kryptografisesti turvallisen 128-bittisen UUID:n joka on
 * RFC 4122 standardin mukainen versio 4 (random) tunniste.
 * 
 * @return string UUID v4 muodossa xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
 */
function uuid4(): string {
    $d = random_bytes(16);
    $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
    $d[8] = chr(ord($d[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}
/**
 * Generoi satunnaisen alfanumeerisen tokenin
 * 
 * Käyttää kryptografisesti turvallista random_int-funktiota.
 * Sisältää sekä isot että pienet kirjaimet sekä numerot.
 * 
 * @param int $len Tokenin pituus merkkeinä
 * @return string Satunnainen alfanumeerinen token
 */
function rand_token(int $len = TOKEN_LENGTH): string {
    $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $out=''; $n=strlen($alphabet);
    for ($i=0;$i<$len;$i++) $out .= $alphabet[random_int(0,$n-1)];
    return $out;
}
/**
 * Generoi ihmislukuisen koodin
 * 
 * Käyttää vain helposti erotettavia merkkejä (ei I, L, O, 0, 1).
 * Soveltuu käyttäjien manuaaliseen syöttöön.
 * 
 * @param int $len Koodin pituus merkkeinä
 * @return string Ihmislukuinen koodi
 */
function human_code(int $len = HUMAN_CODE_LENGTH): string {
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $out=''; $n=strlen($alphabet);
    for ($i=0;$i<$len;$i++) $out .= $alphabet[random_int(0, $n-1)];
    return $out;
}
/**
 * Rakentaa sovelluksen perus-URL:n
 * 
 * Havaitsee automaattisesti protokollan (HTTP/HTTPS), hostin ja
 * sovelluksen hakemiston server-muuttujista.
 * 
 * @return string Täydellinen perus-URL ilman loppukenoviivaa
 */
function base_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
    $root   = $dir;
    return $scheme.'://'.$host.$root;
}

/* --- CAPTCHA (kevyt) --- */
/**
 * Varmistaa että CAPTCHA-kysymys on asetettu
 * 
 * Jos kysymystä ei ole sessiossa, luo uuden yksinkertaisen
 * yhteenlaskutehtävän kahdesta satunnaisesta numerosta.
 * 
 * @return void
 */
function ensure_captcha(): void {
    if (!isset($_SESSION['captcha_q'], $_SESSION['captcha_ans'])) {
        $a = random_int(CAPTCHA_MIN_NUM, CAPTCHA_MAX_NUM);
        $b = random_int(CAPTCHA_MIN_NUM, CAPTCHA_MAX_NUM);
        $_SESSION['captcha_q'] = "$a + $b = ?";
        $_SESSION['captcha_ans'] = (string)($a + $b);
    }
}
/**
 * Nollaa CAPTCHA:n ja luo uuden kysymyksen
 * 
 * Käytetään epäonnistuneiden yrityksen jälkeen tai
 * onnistuneen lähetyksen jälkeen.
 * 
 * @return void
 */
function reset_captcha(): void {
    unset($_SESSION['captcha_q'], $_SESSION['captcha_ans']);
    ensure_captcha();
}

/* ========================================================================
   SOVELLUKSEN PÄÄLOGIIKKA
   ======================================================================== */
// Aseta form timestamp vain GET-pyynnössä tai jos sitä ei ole vielä asetettu
if (!isset($_SESSION['form_ts']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['form_ts'] = time();
}
// Alusta CAPTCHA-kysymys
ensure_captcha();

/* --- Tilamuuttujat käyttöliittymää varten --- */
$okMsg  = '';  // Onnistumisviestin säilytys
$errors = [];  // Virheviestien keruu
// Säilytetään lomakkeen arvot uudelleentäyttöä varten
$old = [
    'ref_name'   => (string)($_POST['ref_name']   ?? ''),
    'nom_name'   => (string)($_POST['nom_name']   ?? ''),
    'nom_email'  => (string)($_POST['nom_email']  ?? ''),
    'guess_type' => (string)($_POST['guess_type'] ?? ''),
    'guess_prob' => (string)($_POST['guess_prob'] ?? ''),
    'ref_note'   => (string)($_POST['ref_note']   ?? ''),
    'captcha'    => (string)($_POST['captcha']    ?? ''),
];

/* --- Lomakkeen lähetyksen käsittely --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_invite'])) {
    
    // 1. Honeypot-tarkistus (roskapostin esto)
    // Piilotettu kenttä joka pitäisi olla tyhjä - botit täyttävät sen usein
    $honeypot = trim((string)($_POST['homepage'] ?? ''));
    if ($honeypot !== '') {
        invite_log('Honeypot triggered (blocked silently)');
        // Näytä onnistumisviestiä mutta älä tee mitään (stealth blocking)
        $okMsg = 'Kutsu lähetetty onnistuneesti.';
        $old = ['ref_name'=>'','nom_name'=>'','nom_email'=>'','guess_type'=>'','guess_prob'=>'','ref_note'=>'','captcha'=>''];
        reset_captcha();
        goto display_form;
    }
    
    // 2. Aikaleima-tarkistus (liian nopea lähetys)
    // Estää automaattiset lähetykset vaatimalla minimum ajan lomakkeen täyttöön
    $ts = (int)($_SESSION['form_ts'] ?? 0);
    if ($ts === 0 || time() - $ts < MIN_FORM_SECS) {
        add_error($errors, 'Lähetys oli liian nopea. Yritä uudelleen.', 'Too fast submit (under '.MIN_FORM_SECS.'s)');
        handle_form_error($errors);
        goto display_form;
    }
    
    // 3. Lomakedata validointi
    $errors = validate_form_data($old);
    
    // 4. CAPTCHA-tarkistus
    if (!validate_captcha($old['captcha'])) {
        add_error($errors, 'Vastaa todennuskysymykseen oikein.', 'CAPTCHA fail');
    }
    
    // Jos validoinnissa virheitä, keskeytä käsittely
    if (!empty($errors)) {
        handle_form_error($errors);
        goto display_form;
    }
    
    // 5. Luo kutsutietue
    $invite = create_invite_record($old);
    $all_invites = load_invites();
    $all_invites[] = $invite;
    
    // 6. Tallenna kutsutiedot
    if (!save_invites($all_invites)) {
        add_error($errors, 'Kutsun tallennus epäonnistui.', 'Save invites FAILED');
        handle_form_error($errors);
        goto display_form;
    }
    
    // 7. Lähetä sähköpostikutsu
    $note_clean = trim(strip_tags($old['ref_note']));
    $sent = send_invitation_email($invite, $note_clean);
    
    if (!$sent) {
        add_error($errors, 'Sähköpostin lähetys epäonnistui (mail()). Kutsu on silti tallennettu.', 'MAIL send FAILED id='.$invite['id']);
        handle_form_error($errors);
        goto display_form;
    }
    
    // 8. Käsittele onnistunut lähetys
    [$okMsg, $old] = handle_success('Kutsu lähetetty onnistuneesti.', $invite, 'Invite sent OK id='.$invite['id'].' to='.$invite['nominee_email']);
}

// Lomakkeen näyttäminen (täällä goto:lla hypitään virhetilanteissa)
display_form:

/* ========================================================================
   HTML-KÄYTTÖLIITTYMÄ
   ======================================================================== */
?>
<!-- 
  ENNEAGRAMMITESTI PILOTTIKUTSU-LOMAKE
  Responsiivinen lomake kutsujen lähettämiseen turvallisin menetelmin
-->
<!doctype html>
<html lang="fi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Lähetä kutsu enneagrammitestin pilottiin</title>
<meta name="robots" content="noindex, nofollow">
<style>
/* === RESET & BASE === */
body {
    font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
    background: url('SEGRY_turkoositausta_1.png') repeat;
    background-color: #f6f7fb; /* fallback jos kuva ei lataudu */
    background-size: auto; /* säilyttää alkuperäisen koon, toistaa tarvittaessa */
    background-attachment: fixed; /* tausta pysyy paikallaan skrollatessa */
    color: #111;
    margin: 24px;
    min-height: 100vh; /* varmistaa että tausta peittää koko näytön */
}

/* === LAYOUT === */
.card {
    max-width: 980px;
    margin: 0 auto;
    background: rgba(255, 255, 255, 0.95); /* hieman läpinäkyvä valkoinen */
    border: 1px solid rgba(221, 221, 221, 0.8);
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0,0,0,.15); /* vahvempi varjo */
    backdrop-filter: blur(10px); /* sumentaa taustaa kortin takaa */
    padding: 20px;
}

.header {
    margin-bottom: 8px;
}

.row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}

/* === TYPOGRAPHY === */
h1 {
    margin: 0;
}

h2 {
    margin: 16px 20px 0 20px; /* sama marginaali sivuille */
}

.small {
    color: #555;
    font-size: 14px;
}

/* Lomakkeen pienet tekstit */
form .small {
    margin: 0 20px;
}

.credits {
    margin-top: 56px;
    text-align: center;
    color: #6b6b6b;
    font-size: 12px;
}

/* === FORM ELEMENTS === */
label {
    display: block;
    margin: 10px 20px 4px 20px; /* sama marginaali kuin syöttökentillä */
}

input, select, textarea {
    width: calc(100% - 40px); /* vähentää 20px molemmilta puolilta */
    max-width: 100%;
    padding: 10px;
    border: 1px solid #ccc;
    border-radius: 8px;
    box-sizing: border-box; /* sisällyttää padding ja border leveyteen */
    margin: 0 20px; /* keskittää kentät 20px marginaalilla */
}

textarea {
    min-height: 110px;
    resize: vertical;
}

.btn {
    margin: 12px 20px 0 20px; /* sama marginaali kuin syöttökentillä */
    padding: 12px 16px;
    border: 1px solid #ccc;
    border-radius: 10px;
    background: #fafafa;
    cursor: pointer;
    color: #000;
    -webkit-text-fill-color: #000;
}

.btn:hover {
    background: #f0f0f0;
}

/* === MESSAGES === */
.msg {
    padding: 10px;
    border-radius: 8px;
    margin: 8px 20px; /* sama marginaali sivuille */
}

.ok {
    background: #e7f7e7;
    border: 1px solid #a7d3a7;
}

.err {
    background: #fdeaea;
    border: 1px solid #e7b0b0;
}

/* === HIDDEN ELEMENTS === */
.hp {
    position: absolute;
    left: -10000px;
    top: auto;
    width: 1px;
    height: 1px;
    overflow: hidden;
}
</style>
</head>
<body>
<div class="card">
  <div class="header">
    <div>
      <h1>Lähetä kutsu enneagrammitestin pilottiin</h1>
      <p class="small" style="margin:6px 0 0">
        Tällä lomakkeella voit lähettää tutullesi kutsun osallistua enneagrammitestin pilottiin.
        Sinun nimesi kerrotaan kutsuttavalle, mutta ei sinun arviotasi hänen <strong>enneagrammityylistään</strong>.
        Anna kutsuttavan tyyli ja kerro oma arviosi tyylin osuvuuden todennäköisyydestä.
      </p>
    </div>
  </div>

  <?php if ($okMsg): ?><div class="msg ok"><?=h($okMsg)?></div><?php endif; ?>
  <?php if ($errors): ?>
    <div class="msg err">
      <strong>Korjaa seuraavat kohdat:</strong>
      <ul style="margin:6px 0 0 16px; padding:0">
        <?php foreach ($errors as $e): ?><li><?=h($e)?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" novalidate>
    <!-- Honeypot -->
    <div class="hp">
      <label>Jätä tämä tyhjäksi
        <input type="text" name="homepage" autocomplete="off">
      </label>
    </div>

    <h2>Kutsuja</h2>
    <label for="ref_name">Nimesi *</label>
    <input type="text" id="ref_name" name="ref_name" value="<?=h($old['ref_name'])?>">

    <h2 style="margin-top:16px">Kutsuttava</h2>
    <label for="nom_name">Nimi *</label>
    <input type="text" id="nom_name" name="nom_name" value="<?=h($old['nom_name'])?>">

    <label for="nom_email">Sähköposti *</label>
    <input type="email" id="nom_email" name="nom_email" value="<?=h($old['nom_email'])?>">

    <div class="row">
      <div>
        <label for="guess_type">Ehdotettu enneagrammityyli *</label>
        <select id="guess_type" name="guess_type">
          <option value="">Valitse...</option>
          <?php for($i=1;$i<=9;$i++): ?>
            <option value="<?=$i?>" <?=$old['guess_type']===(string)$i?'selected':''?>><?=$i?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div>
        <label for="guess_prob">Kuinka varma olet arviostasi? *</label>
        <select id="guess_prob" name="guess_prob">
          <option value="">Valitse...</option>
          <?php foreach(CERTAINTY_LEVELS as $key => $label): ?>
            <option value="<?=$key?>" <?=$old['guess_prob']===$key?'selected':''?>><?=h($label)?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <label for="ref_note" style="margin-top:12px">Saatesanat kutsuttavalle (liitetään sähköpostiin)</label>
    <textarea id="ref_note" name="ref_note" maxlength="<?=NOTE_MAX_LEN?>" spellcheck="false" placeholder="Muokkaa viestiä tarpeen mukaan..."><?php 
      // Näytä oletusarvoinen viesti jos käyttäjä ei ole syöttänyt omaa
      $default_message = "Tarvitsisin sun apua! Olen mukana kehittämässä suomenkielistä, tarkkaa enneagrammitestiä. Sen koeversioon tarvittaisiin paljon erilaisten ihmisten vastauksia. Tämä on tosi helppo juttu, eikä vaadi mitään pohjatietoa aiheesta. Kiitos jo etukäteen ja mukavaa syksyä!";
      echo h($old['ref_note'] !== '' ? $old['ref_note'] : $default_message);
    ?></textarea>
    <p class="small">Voit muokata viestiä haluamaksesi tai poistaa sen kokonaan. Enintään <?=NOTE_MAX_LEN?> merkkiä. Linkit eivät ole sallittuja.</p>

    <!-- Kevyt CAPTCHA -->
    <label for="captcha" style="margin-top:12px">Todennus — paljonko on <?=h($_SESSION['captcha_q'] ?? '')?></label>
    <input type="text" id="captcha" name="captcha" value="<?=h($old['captcha'])?>" inputmode="numeric" pattern="[0-9]*" placeholder="Kirjoita vastaus luvulla">
    <p class="small">Yksinkertainen laskutoimitus auttaa estämään automaattiset roskapostilähetykset ja pitämään palvelun turvallisena.</p>

    <button class="btn" type="submit" name="send_invite" value="1">Lähetä kutsu</button>
    <p class="small">Kutsu sisältää suoran linkin testiin sekä varakoodin. Kutsuttavalle kerrotaan kutsujan nimi.</p>
  </form>

  <div class="credits">Suomen Enneagrammiyhdistys, 2025</div>
</div>
</body>
</html>
