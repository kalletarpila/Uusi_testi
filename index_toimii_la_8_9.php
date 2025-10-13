<?php declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('Europe/Helsinki');

/* Kovennettu sessioeväste */
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

/* --- SÄHKÖPOSTIN LÄHETYS --- */
const FROM_NAME  = 'Enneagrammitesti';
const FROM_EMAIL = 'no-reply@enneagrammitesti.fi'; // vaihda tarvittaessa

function send_mail(string $to, string $subject, string $body): bool {
    $headers = [];
    $headers[] = 'From: '.FROM_NAME.' <'.FROM_EMAIL.'>';
    $headers[] = 'Reply-To: '.FROM_EMAIL;
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers_str = implode("\r\n", $headers);
    // UTF-8 subject base64
    $subjectEnc = '=?UTF-8?B?'.base64_encode($subject).'?=';
    return @mail($to, $subjectEnc, $body, $headers_str);
}

/* --- Polut ja apurit --- */
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function repo_path(): string {
    // data-kansio pilotti-hakemiston yläpuolella, tai tämän vieressä
    $base = realpath(__DIR__.'/../') !== false ? (__DIR__.'/../data') : (__DIR__.'/data');
    if (!is_dir($base)) @mkdir($base, 0700, true);
    return $base.'/invites.json';
}
function load_invites(): array {
    $p = repo_path();
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
function save_invites(array $inv): bool {
    $p = repo_path();
    $fp = fopen($p, 'c+');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    $ok = fwrite($fp, json_encode($inv, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)) !== false;
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $ok;
}
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
    // Helppolukuinen koodi (ei 0/O, 1/I)
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $out=''; $n=strlen($alphabet);
    for ($i=0;$i<$len;$i++) $out .= $alphabet[random_int(0, $n-1)];
    return $out;
}
function base_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // esim. /pilotti/invite.php → peruspolku /pilotti
    $dir    = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
    $root   = $dir;
    return $scheme.'://'.$host.$root;
}

/* CSRF */
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
function csrf_input(): void { echo '<input type="hidden" name="csrf" value="'.h($_SESSION['csrf']).'">'; }
function csrf_check(): void {
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', (string)$_POST['csrf'])) {
            http_response_code(400);
            exit('Virheellinen pyyntö (CSRF).');
        }
    }
}

/* Käsittele lähetys */
$okMsg = $errMsg = '';
csrf_check();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_invite'])) {
    $ref_name  = trim((string)($_POST['ref_name'] ?? ''));
    $nom_name  = trim((string)($_POST['nom_name'] ?? ''));
    $nom_email = trim((string)($_POST['nom_email'] ?? ''));
    $guess     = trim((string)($_POST['guess_type'] ?? ''));
    $prob_s    = trim((string)($_POST['guess_prob'] ?? ''));
    $prob      = is_numeric($prob_s) ? (int)$prob_s : -1;

    if ($ref_name === '' || $nom_name === '' || $nom_email === '' || !preg_match('/^[1-9]$/', $guess) || $prob < 0 || $prob > 100) {
        $errMsg = 'Tarkista kentät: kutsujan nimi, kutsuttavan nimi ja sähköposti, tyyppi 1–9, todennäköisyys 0–100.';
    } else {
        // Luo kutsu
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
            'referrer_email' => '', // kutsujan sähköposti poistettu
            'nominee_name'   => $nom_name,
            'nominee_email'  => $nom_email,
            'guess_type'     => $guess,
            'guess_prob'     => $prob,
            'status'         => 'sent', // sent → accepted → completed
            'created_at'     => $now,
            'accepted_at'    => null,
            'completed_at'   => null,
            'expires_at'     => null
        ];
        $all[] = $invite;
        if (!save_invites($all)) {
            $errMsg = 'Kutsun tallennus epäonnistui.';
        } else {
            // Sähköposti kutsuttavalle (ilman tyyppi/pros.)
            $testUrl = rtrim(base_url(), '/').'/index.php?invite='.rawurlencode($token);
            $subject = 'Kutsu tekemään Enneagrammitesti (pilotti)';
            $body = "Hei {$nom_name},\n\n".
                    "{$ref_name} kutsui sinut tekemään ENNEGRAMMITESTI-pilotin.\n\n".
                    "Avaa testi tästä linkistä:\n{$testUrl}\n\n".
                    "Jos linkki ei toimi, voit siirtyä osoitteeseen ".rtrim(base_url(), '/')."/ ja syöttää koodin:\n{$code}\n\n".
                    "Toivottavasti teet testin, sillä se auttaa meitä paljon kehittämään testiä eteenpäin.\n\n".
                    "Ystävällisin terveisin,\n".
                    "Testitiimi,\n". "Suomen Enneagrammiyhdistys\n";

            if (!send_mail($nom_email, $subject, $body)) {
                $errMsg = 'Sähköpostin lähetys epäonnistui (mail()). Kutsu on silti tallennettu.';
            } else {
                $okMsg = 'Kutsu lähetetty onnistuneesti.';
            }
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
.row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.btn{margin-top:12px;padding:12px 16px;border:1px solid #ccc;border-radius:10px;background:#fafafa;cursor:pointer;color:#000;-webkit-text-fill-color:#000}
.btn:hover{background:#f0f0f0}
.msg{padding:10px;border-radius:8px;margin:8px 0}
.ok{background:#e7f7e7;border:1px solid #a7d3a7}
.err{background:#fdeaea;border:1px solid #e7b0b0}
.small{color:#555;font-size:14px}
.credits{margin-top:56px;text-align:center;color:#6b6b6b;font-size:12px}
</style>
</head>
<body>
<div class="card">
  <div class="header">
    <div>
      <h1>Lähetä kutsu enneagrammitestin pilottiin</h1>
      <p class="small" style="margin:6px 0 0">
        Tällä lomakkeella voit lähettää tutullesi kutsun osallistua enneagrammitestin pilottiin.
        Sinun nimesi kerrotaan kutsuttavalle, mutta ei sinun arviotasi hänen ennagrammityylistään.
        Anna kutsuttavan tyyli ja kerro oma arviosi tyylin osuvuuden todennäköisyydestä.
      </p>
    </div>
    <img src="logo.png" alt="Suomen Enneagrammiyhdistys – logo" class="logo">
  </div>

  <?php if ($okMsg): ?><div class="msg ok"><?=h($okMsg)?></div><?php endif; ?>
  <?php if ($errMsg): ?><div class="msg err"><?=h($errMsg)?></div><?php endif; ?>

  <form method="post" novalidate>
    <?php csrf_input(); ?>

    <h2>Kutsuja</h2>
    <label for="ref_name">Nimesi *</label>
    <input type="text" id="ref_name" name="ref_name" required>

    <h2 style="margin-top:16px">Kutsuttava</h2>
    <label for="nom_name">Nimi *</label>
    <input type="text" id="nom_name" name="nom_name" required>

    <label for="nom_email">Sähköposti *</label>
    <input type="email" id="nom_email" name="nom_email" required>

    <div class="row">
      <div>
        <label for="guess_type">Ehdotettu enneagrammityyli *</label>
        <select id="guess_type" name="guess_type" required>
          <option value="">Valitse...</option>
          <?php for($i=1;$i<=9;$i++): ?>
            <option value="<?=$i?>"><?=$i?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div>
        <label for="guess_prob">Todennäköisyys % (0–100) *</label>
        <input type="number" id="guess_prob" name="guess_prob" min="0" max="100" step="1" required>
      </div>
    </div>

    <button class="btn" type="submit" name="send_invite" value="1">Lähetä kutsu</button>
    <p class="small">Kutsu sisältää suoran linkin testiin sekä varakoodin. Kutsuttavalle kerrotaan kutsujan nimi.</p>
  </form>

  <div class="credits">© Suomen Enneagrammiyhdistys, 2025</div>
</div>
</body>
</html>
