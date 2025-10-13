<?php declare(strict_types=1);

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

/* --- Konfiguraatio --- */
const FROM_EMAIL = 'no-reply@enneagrammitesti.fi'; // pidä lähetys tästä osoitteesta
const SUBJECT    = 'Kutsu tekemään Enneagrammitesti (pilotti)';

const NOTE_MAX_LEN   = 500; // saatesanat max merkit
const NOTE_MAX_URLS  = 2;   // saatesanoissa sallittujen linkkien maksimi
const MIN_FORM_SECS  = 5;   // min aika (sekuntia) lomakkeen avauksesta lähetykseen

/* --- Lokitus --- */
function data_dir(): string {
    $pref = realpath(__DIR__ . '/../') !== false ? (__DIR__ . '/../data') : (__DIR__ . '/data');
    if (!is_dir($pref)) @mkdir($pref, 0700, true);
    return $pref;
}
function invite_log_path(): string {
    return data_dir() . '/invite_app_' . date('Y-m') . '.log';
}
function invite_log(string $msg): void {
    $ts = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    @file_put_contents(invite_log_path(), '['.$ts.']['.$ip.'] '.$msg.PHP_EOL, FILE_APPEND | LOCK_EX);
}

/* --- Apuja --- */
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/* --- Sähköposti --- */
function send_mail_with_from_name(string $to, string $fromName, string $subject, string $body): bool {
    $headers = [];
    $fromDisplay = trim($fromName) !== '' ? ($fromName . ' via Enneagrammitesti') : 'Enneagrammitesti';
    $headers[] = 'From: '.$fromDisplay.' <'.FROM_EMAIL.'>';
    $headers[] = 'Reply-To: '.FROM_EMAIL;
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers_str = implode("\r\n", $headers);
    $subjectEnc = '=?UTF-8?B?'.base64_encode($subject).'?=';
    return @mail($to, $subjectEnc, $body, $headers_str);
}

/* --- Kutsuvarasto --- */
function invites_path(): string { return data_dir() . '/invites.json'; }
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
function uuid4(): string {
    $d = random_bytes(16);
    $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
    $d[8] = chr(ord($d[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}
function rand_token(int $len = 48): string {
    $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $out=''; $n=strlen($alphabet);
    for ($i=0;$i<$len;$i++) $out .= $alphabet[random_int(0,$n-1)];
    return $out;
}
function human_code(int $len = 8): string {
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $out=''; $n=strlen($alphabet);
    for ($i=0;$i<$len;$i++) $out .= $alphabet[random_int(0, $n-1)];
    return $out;
}
function base_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
    $root   = $dir;
    return $scheme.'://'.$host.$root;
}

/* --- CAPTCHA (kevyt) --- */
function ensure_captcha(): void {
    if (!isset($_SESSION['captcha_q'], $_SESSION['captcha_ans'])) {
        $a = random_int(2, 9);
        $b = random_int(2, 9);
        $_SESSION['captcha_q'] = "$a + $b = ?";
        $_SESSION['captcha_ans'] = (string)($a + $b);
    }
}
function reset_captcha(): void {
    unset($_SESSION['captcha_q'], $_SESSION['captcha_ans']);
    ensure_captcha();
}

/* --- Formin aikaleima --- */
if (!isset($_SESSION['form_ts']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['form_ts'] = time();
}
ensure_captcha();

/* --- Tilamuuttujat (UI) --- */
$okMsg  = '';
$errors = [];
$old = [
    'ref_name'   => (string)($_POST['ref_name']   ?? ''),
    'nom_name'   => (string)($_POST['nom_name']   ?? ''),
    'nom_email'  => (string)($_POST['nom_email']  ?? ''),
    'guess_type' => (string)($_POST['guess_type'] ?? ''),
    'guess_prob' => (string)($_POST['guess_prob'] ?? ''),
    'ref_note'   => (string)($_POST['ref_note']   ?? ''),
    'captcha'    => (string)($_POST['captcha']    ?? ''),
];

/* --- Lähetys --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_invite'])) {
    // 1) Honeypot
    $honeypot = trim((string)($_POST['homepage'] ?? ''));
    if ($honeypot !== '') {
        invite_log('Honeypot triggered (blocked silently)');
        // Älä lähetä/säilytä mitään; näytä neutraali OK
        $okMsg = 'Kutsu lähetetty onnistuneesti.';
        // tyhjennä kentät
        $old = ['ref_name'=>'','nom_name'=>'','nom_email'=>'','guess_type'=>'','guess_prob'=>'','ref_note'=>'','captcha'=>''];
        reset_captcha();
    } else {
        // 2) Minimi kirjoitusaika
        $ts = (int)($_SESSION['form_ts'] ?? 0);
        if ($ts === 0 || time() - $ts < MIN_FORM_SECS) {
            $errors[] = 'Lähetys oli liian nopea. Yritä uudelleen.';
            invite_log('Too fast submit (under '.MIN_FORM_SECS.'s)');
        }

        // Perusvalidoinnit
        $ref_name   = trim($old['ref_name']);
        $nom_name   = trim($old['nom_name']);
        $nom_email  = trim($old['nom_email']);
        $guess_type = trim($old['guess_type']);
        $prob_s     = trim($old['guess_prob']);
        $ref_note   = $old['ref_note'];

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
        $prob = is_numeric($prob_s) ? (int)$prob_s : -1;
        if ($prob < 0 || $prob > 100) {
            $errors[] = 'Todennäköisyys tulee olla välillä 0–100.';
        }

        // (5) Saatesanojen suodatus
        $note_clean = trim(strip_tags($ref_note));
        if (mb_strlen($note_clean, 'UTF-8') > NOTE_MAX_LEN) {
            $errors[] = 'Saatesanat ovat liian pitkät (max '.NOTE_MAX_LEN.' merkkiä).';
        }
        // URL-laskenta
        $urlCount = 0;
        if ($note_clean !== '') {
            $urlCount += preg_match_all('/https?:\/\/[^\s]+/iu', $note_clean) ?: 0;
            $urlCount += preg_match_all('/\bwww\.[^\s]+/iu', $note_clean) ?: 0;
        }
        if ($urlCount > NOTE_MAX_URLS) {
            $errors[] = 'Saatesanoissa on liikaa linkkejä (max '.NOTE_MAX_URLS.').';
        }

        // (7) Kevyt CAPTCHA
        $cap_in = trim($old['captcha']);
        $cap_ok = ($cap_in !== '' && isset($_SESSION['captcha_ans']) && hash_equals($_SESSION['captcha_ans'], $cap_in));
        if (!$cap_ok) {
            $errors[] = 'Vastaa todennuskysymykseen oikein.';
            invite_log('CAPTCHA fail');
        }

        if (empty($errors)) {
            // Luo kutsu & tallenna
            $all   = load_invites();
            $id    = uuid4();
            $token = rand_token(48);
            $code  = human_code(8);
            $now   = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

            $invite = [
                'id'             => $id,
                'token'          => $token,
                'code'           => $code,
                'referrer_name'  => $ref_name,
                'referrer_email' => '', // ei käytössä
                'nominee_name'   => $nom_name,
                'nominee_email'  => $nom_email,
                'guess_type'     => $guess_type,
                'guess_prob'     => $prob,
                'status'         => 'sent',
                'created_at'     => $now,
                'accepted_at'    => null,
                'completed_at'   => null,
                'expires_at'     => null
            ];
            $all[] = $invite;

            if (!save_invites($all)) {
                $errors[] = 'Kutsun tallennus epäonnistui.';
                invite_log('Save invites FAILED');
            } else {
                // Sähköposti kutsuttavalle
                $testUrl = rtrim(base_url(), '/').'/index.php?invite='.rawurlencode($token);
                $body = "Hei {$nom_name},\n\n".
                        "{$ref_name} kutsui sinut tekemään ENNEAGRAMMITESTI-pilotin.\n";
                if ($note_clean !== '') {
                    $body .= "Hän kertoo sinulle, että {$note_clean}\n";
                }
                $body .= "\nAvaa testi tästä linkistä:\n{$testUrl}\n\n".
                         "Jos linkki ei toimi, voit siirtyä osoitteeseen ".rtrim(base_url(), '/')."/ ja syöttää koodin:\n{$code}\n\n".
                         "Toivottavasti teet testin, sillä se auttaa meitä paljon kehittämään testiä eteenpäin.\n\n".
                         "Ystävällisin terveisin,\n".
                         "Testitiimi,\nSuomen Enneagrammiyhdistys\n";

                $sent = send_mail_with_from_name($nom_email, $ref_name, SUBJECT, $body);
                if (!$sent) {
                    $errors[] = 'Sähköpostin lähetys epäonnistui (mail()). Kutsu on silti tallennettu.';
                    invite_log('MAIL send FAILED id='.$id);
                } else {
                    $okMsg = 'Kutsu lähetetty onnistuneesti.';
                    invite_log('Invite sent OK id='.$id.' to='.$nom_email);
                    // Tyhjennä kentät vain onnistumisessa
                    $old = ['ref_name'=>'','nom_name'=>'','nom_email'=>'','guess_type'=>'','guess_prob'=>'','ref_note'=>'','captcha'=>''];
                    // Resetoi aikaleima & captcha
                    $_SESSION['form_ts'] = time();
                    reset_captcha();
                }
            }
        } else {
            // virheitä -> luo uusi captcha seuraavaa yritystä varten
            reset_captcha();
        }
    }
}

?>
<!doctype html>
<html lang="fi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Lähetä kutsu enneagrammitestin pilottiin</title>
<meta name="robots" content="noindex, nofollow">
<style>
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#f6f7fb;color:#111;margin:24px}
.card{max-width:980px;margin:0 auto;background:#fff;border:1px solid #ddd;border-radius:12px;box-shadow:0 1px 6px rgba(0,0,0,.06);padding:20px}
.header{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:8px}
.logo{height:96px;width:auto;object-fit:contain}
h1{margin:0}
label{display:block;margin:10px 0 4px}
input,select,textarea{width:100%;padding:10px;border:1px solid #ccc;border-radius:8px}
textarea{min-height:110px;resize:vertical}
.row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.btn{margin-top:12px;padding:12px 16px;border:1px solid #ccc;border-radius:10px;background:#fafafa;cursor:pointer;color:#000;-webkit-text-fill-color:#000}
.btn:hover{background:#f0f0f0}
.msg{padding:10px;border-radius:8px;margin:8px 0}
.ok{background:#e7f7e7;border:1px solid #a7d3a7}
.err{background:#fdeaea;border:1px solid #e7b0b0}
.small{color:#555;font-size:14px}
.credits{margin-top:56px;text-align:center;color:#6b6b6b;font-size:12px}
.hp{position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden}
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
    <img src="logo.png" alt="Suomen Enneagrammiyhdistys – logo" class="logo">
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
        <label for="guess_prob">Todennäköisyys % (0–100) *</label>
        <input type="number" id="guess_prob" name="guess_prob" min="0" max="100" step="1" value="<?=h($old['guess_prob'])?>">
      </div>
    </div>

    <label for="ref_note" style="margin-top:12px">Omat saatesanat kutsuttavalle (liitetään sähköpostiin, vapaaehtoinen)</label>
    <textarea id="ref_note" name="ref_note" maxlength="<?=NOTE_MAX_LEN?>" placeholder="Esim. miksi kutsut, mihin toivoisit kiinnittävän huomiota…"><?=h($old['ref_note'])?></textarea>
    <p class="small">Enintään <?=NOTE_MAX_LEN?> merkkiä, enintään <?=NOTE_MAX_URLS?> linkkiä.</p>

    <!-- Kevyt CAPTCHA -->
    <label for="captcha" style="margin-top:12px">Todennus — paljonko on <?=h($_SESSION['captcha_q'] ?? '')?></label>
    <input type="text" id="captcha" name="captcha" value="<?=h($old['captcha'])?>" inputmode="numeric" pattern="[0-9]*" placeholder="Kirjoita vastaus luvulla">

    <button class="btn" type="submit" name="send_invite" value="1">Lähetä kutsu</button>
    <p class="small">Kutsu sisältää suoran linkin testiin sekä varakoodin. Kutsuttavalle kerrotaan kutsujan nimi.</p>
  </form>

  <div class="credits">© Suomen Enneagrammiyhdistys, 2025</div>
</div>
</body>
</html>
