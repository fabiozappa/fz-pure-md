<?php
/**
 * FZPureMD 1.0.0 — visualizzatore ed editor di file Markdown in un unico file.
 * https://github.com/fabiozappa/fz-pure-md · Licenza MIT · © 2026 Fabio Zappa
 *
 * - Albero a sinistra con tutti i .md della cartella e delle sottocartelle
 * - Lettura formattata, modifica in testo semplice o WYSIWYG
 * - Nuovo file, spostamento/rinomina tra cartelle
 * - Due password: completa e sola lettura
 *
 * Compatibile con PHP 5.3 → 8.x. Nessun file esterno, nessuna libreria.
 */

// ============================== CONFIGURAZIONE ==============================

// Password in chiaro. CAMBIALE prima dell'uso: finché restano queste l'accesso è bloccato.
// Almeno 8 caratteri e diverse tra loro.
define('MD_PASS_ADMIN', 'cambiami-admin');    // tutto: lettura, modifica, nuovo, sposta
define('MD_PASS_READ',  'cambiami-lettura');  // sola lettura

// Cartella da esplorare (default: quella in cui si trova questo file)
define('MD_ROOT', dirname(__FILE__));

// Cartelle ignorate (quelle che iniziano con il punto sono sempre ignorate)
$MD_SKIP = array('node_modules', 'vendor', 'bower_components');

define('MD_MAX_DEPTH', 12);              // profondità massima di scansione
define('MD_IDLE', 4 * 3600);             // disconnessione dopo 4 ore di inattività
define('MD_MAX_SIZE', 5 * 1024 * 1024);  // dimensione massima di un file aperto

// ============================================================================

error_reporting(E_ALL & ~E_NOTICE);

if (!function_exists('hash_equals')) {
    function hash_equals($a, $b)
    {
        if (!is_string($a) || !is_string($b) || strlen($a) !== strlen($b)) return false;
        $r = 0;
        for ($i = 0, $n = strlen($a); $i < $n; $i++) $r |= ord($a[$i]) ^ ord($b[$i]);
        return $r === 0;
    }
}

function md_h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function md_unquote($v)
{
    if (function_exists('get_magic_quotes_gpc') && @get_magic_quotes_gpc()) $v = stripslashes($v);
    return $v;
}

function md_post($k, $d = '')
{
    return isset($_POST[$k]) && is_string($_POST[$k]) ? md_unquote($_POST[$k]) : $d;
}

function md_get($k)
{
    return isset($_GET[$k]) && is_string($_GET[$k]) ? md_unquote($_GET[$k]) : '';
}

function md_token()
{
    if (function_exists('random_bytes')) return bin2hex(random_bytes(16));
    if (function_exists('openssl_random_pseudo_bytes')) return bin2hex(openssl_random_pseudo_bytes(16));
    return sha1(uniqid(mt_rand(), true) . microtime());
}

function md_same($a, $b)
{
    return hash_equals(sha1((string)$a), sha1((string)$b));
}

function md_self()
{
    return basename($_SERVER['SCRIPT_NAME']);
}

function md_url($rel)
{
    return md_self() . '?f=' . implode('/', array_map('rawurlencode', explode('/', $rel)));
}

function md_redirect($url)
{
    header('Location: ' . $url);
    exit;
}

function md_json($data)
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function md_flash($type, $msg)
{
    $_SESSION['md_flash'] = array('type' => $type, 'msg' => $msg);
}

// Normalizza un percorso relativo; false se contiene .., segmenti nascosti o byte nulli
function md_clean($rel)
{
    $rel = str_replace('\\', '/', (string)$rel);
    if (strpos($rel, "\0") !== false) return false;
    $out = array();
    foreach (explode('/', $rel) as $seg) {
        if ($seg === '') continue;
        if ($seg[0] === '.') return false;
        $out[] = $seg;
    }
    return implode('/', $out);
}

// Percorso reale se si trova dentro MD_ROOT, altrimenti false
function md_inside($path)
{
    $real = realpath($path);
    $root = realpath(MD_ROOT);
    if ($real === false || $root === false) return false;
    if ($real === $root || strpos($real, rtrim($root, '/') . '/') === 0) return $real;
    return false;
}

function md_file($rel)
{
    $rel = md_clean($rel);
    if ($rel === false || $rel === '' || !preg_match('/\.md$/i', $rel)) return false;
    $real = md_inside(MD_ROOT . '/' . $rel);
    return ($real !== false && is_file($real)) ? $real : false;
}

function md_valid_name($s)
{
    return $s !== '' && $s[0] !== '.' && strlen($s) <= 200
        && !preg_match('/[\x00-\x1f\/\\\\:*?"<>|]/', $s);
}

// Restituisce il percorso reale della cartella, creandola se manca
function md_ensure_dir($dir)
{
    $dir = md_clean($dir);
    if ($dir === false) return false;
    $p = MD_ROOT;
    if ($dir !== '') {
        foreach (explode('/', $dir) as $seg) {
            if (!md_valid_name($seg)) return false;
            $p .= '/' . $seg;
            if (is_link($p)) return false;
            if (file_exists($p) && !is_dir($p)) return false;
        }
        if (!is_dir($p) && !@mkdir($p, 0775, true)) return false;
    }
    return md_inside($p);
}

function md_scan($abs, $rel, $depth, &$dirs)
{
    global $MD_SKIP;
    $node = array('dirs' => array(), 'files' => array());
    if ($depth > MD_MAX_DEPTH) return $node;
    $items = @scandir($abs);
    if ($items === false) return $node;
    foreach ($items as $it) {
        if ($it === '' || $it[0] === '.') continue;
        $p = $abs . '/' . $it;
        if (is_link($p)) continue;
        $r = $rel === '' ? $it : $rel . '/' . $it;
        if (is_dir($p)) {
            if (in_array(strtolower($it), $MD_SKIP)) continue;
            if (count($dirs) < 3000) $dirs[] = $r;
            $sub = md_scan($p, $r, $depth + 1, $dirs);
            if ($sub['dirs'] || $sub['files']) $node['dirs'][$it] = $sub;
        } elseif (preg_match('/\.md$/i', $it) && is_file($p)) {
            $node['files'][] = $it;
        }
    }
    uksort($node['dirs'], 'strnatcasecmp');
    usort($node['files'], 'strnatcasecmp');
    return $node;
}

function md_count($node)
{
    $n = count($node['files']);
    foreach ($node['dirs'] as $sub) $n += md_count($sub);
    return $n;
}

function md_tree_html($node, $rel, $cur)
{
    $h = '<ul>';
    foreach ($node['dirs'] as $name => $sub) {
        $r = $rel === '' ? $name : $rel . '/' . $name;
        $open = ($cur !== '' && strpos($cur, $r . '/') === 0) ? ' open data-open="1"' : '';
        $h .= '<li class="d"><details' . $open . '><summary title="' . md_h($r) . '">' . md_h($name) . '</summary>'
            . md_tree_html($sub, $r, $cur) . '</details></li>';
    }
    foreach ($node['files'] as $f) {
        $r = $rel === '' ? $f : $rel . '/' . $f;
        $cls = $r === $cur ? ' class="cur"' : '';
        $h .= '<li class="f"><a' . $cls . ' href="' . md_h(md_url($r)) . '" title="' . md_h($r) . '">'
            . md_h(preg_replace('/\.md$/i', '', $f)) . '</a></li>';
    }
    return $h . '</ul>';
}

function md_pass($role)
{
    return $role === 'admin' ? MD_PASS_ADMIN : MD_PASS_READ;
}

// Firma di sessione: se cambi la password nel file, le sessioni aperte decadono
function md_sig($role)
{
    return sha1($role . '|' . md_pass($role) . '|' . MD_ROOT);
}

// ------------------------------------------------------------------ sessione

$cookiePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/';
$secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_name('MDVIEW');
if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params(array('lifetime' => 0, 'path' => $cookiePath, 'secure' => $secure,
        'httponly' => true, 'samesite' => 'Lax'));
} else {
    session_set_cookie_params(0, $cookiePath . '; samesite=Lax', '', $secure, true);
}
session_start();

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; "
    . "img-src * data:; connect-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
header('Cache-Control: no-store');

$role = '';
if (isset($_SESSION['md_role'], $_SESSION['md_sig'], $_SESSION['md_t'])
    && ($_SESSION['md_role'] === 'admin' || $_SESSION['md_role'] === 'read')
    && hash_equals((string)$_SESSION['md_sig'], md_sig($_SESSION['md_role']))
    && time() - $_SESSION['md_t'] < MD_IDLE) {
    $role = $_SESSION['md_role'];
    $_SESSION['md_t'] = time();
} elseif (isset($_SESSION['md_role'])) {
    $_SESSION = array();
}

$action = md_post('action');
$loginError = '';

// Configurazione insicura: nessuno può entrare finché le password non vengono cambiate
$confError = '';
if (MD_PASS_ADMIN === 'cambiami-admin' || MD_PASS_READ === 'cambiami-lettura') {
    $confError = 'Cambia le password in cima a ' . md_self() . ' prima di usarlo.';
} elseif (strlen(MD_PASS_ADMIN) < 8 || strlen(MD_PASS_READ) < 8) {
    $confError = 'Le password in ' . md_self() . ' devono avere almeno 8 caratteri.';
} elseif (MD_PASS_ADMIN === MD_PASS_READ) {
    $confError = 'Le due password in ' . md_self() . ' devono essere diverse.';
}
if ($confError !== '') {
    $role = '';
    $_SESSION = array();
}

if ($action === 'login' && $confError === '') {
    $pw = md_post('password');
    $new = '';
    if ($pw !== '' && md_same(MD_PASS_ADMIN, $pw)) $new = 'admin';
    elseif ($pw !== '' && md_same(MD_PASS_READ, $pw)) $new = 'read';
    if ($new !== '') {
        session_regenerate_id(true);
        $_SESSION['md_role'] = $new;
        $_SESSION['md_sig'] = md_sig($new);
        $_SESSION['md_t'] = time();
        $_SESSION['md_csrf'] = md_token();
        $f = md_get('f');
        md_redirect($f !== '' ? md_url($f) : md_self());
    }
    sleep(1);
    $loginError = 'Password errata.';
}

if ($role === '') {
    md_login_page($confError !== '' ? $confError : $loginError);
    exit;
}

$canEdit = $role === 'admin';

// ------------------------------------------------------------------ azioni

if ($action !== '' && $action !== 'login') {
    $csrf = isset($_SESSION['md_csrf']) ? (string)$_SESSION['md_csrf'] : '';
    if ($csrf === '' || !hash_equals($csrf, md_post('csrf'))) {
        if ($action === 'save') md_json(array('ok' => false, 'error' => 'Sessione scaduta: ricarica la pagina.'));
        md_flash('err', 'Richiesta non valida, riprova.');
        md_redirect(md_self());
    }

    if ($action === 'logout') {
        $_SESSION = array();
        session_destroy();
        md_redirect(md_self());
    }

    if (!$canEdit) {
        if ($action === 'save') md_json(array('ok' => false, 'error' => 'Accesso in sola lettura.'));
        md_flash('err', 'Accesso in sola lettura.');
        md_redirect(md_self());
    }

    if ($action === 'save') {
        $abs = md_file(md_post('f'));
        if ($abs === false) md_json(array('ok' => false, 'error' => 'File non trovato.'));
        if (!is_writable($abs)) md_json(array('ok' => false, 'error' => 'File non scrivibile (permessi).'));
        $cur = (string)@file_get_contents($abs);
        if (md_post('force') !== '1' && md5($cur) !== md_post('hash')) {
            md_json(array('ok' => false, 'conflict' => true,
                'error' => 'Il file è stato modificato da qualcun altro dopo che l\'hai aperto.'));
        }
        // Mantiene fine riga e BOM del file originale
        $new = str_replace(array("\r\n", "\r"), "\n", md_post('content'));
        if (strpos($cur, "\r\n") !== false) $new = str_replace("\n", "\r\n", $new);
        if (substr($cur, 0, 3) === "\xEF\xBB\xBF" && substr($new, 0, 3) !== "\xEF\xBB\xBF") $new = "\xEF\xBB\xBF" . $new;
        if (@file_put_contents($abs, $new, LOCK_EX) === false) {
            md_json(array('ok' => false, 'error' => 'Scrittura non riuscita.'));
        }
        md_json(array('ok' => true, 'hash' => md5($new)));
    }

    if ($action === 'create' || $action === 'move') {
        $back = $action === 'move' ? md_url(md_post('f')) : md_self();
        $name = trim(md_post('name'));
        if ($name !== '' && !preg_match('/\.md$/i', $name)) $name .= '.md';
        $dirRel = md_clean(trim(md_post('dir')));
        if (!md_valid_name($name) || $dirRel === false) {
            md_flash('err', 'Nome file o cartella non validi.');
            md_redirect($back);
        }
        $dirAbs = md_ensure_dir($dirRel);
        if ($dirAbs === false) {
            md_flash('err', 'Impossibile usare o creare la cartella «' . $dirRel . '».');
            md_redirect($back);
        }
        $target = $dirAbs . '/' . $name;
        $targetRel = ($dirRel === '' ? '' : $dirRel . '/') . $name;

        if ($action === 'create') {
            if (file_exists($target)) {
                md_flash('err', 'Esiste già un file con questo nome.');
                md_redirect($back);
            }
            if (@file_put_contents($target, '# ' . preg_replace('/\.md$/i', '', $name) . "\n\n", LOCK_EX) === false) {
                md_flash('err', 'Creazione non riuscita (permessi?).');
                md_redirect($back);
            }
            md_flash('ok', 'File creato.');
            md_redirect(md_url($targetRel));
        }

        $src = md_file(md_post('f'));
        if ($src === false) {
            md_flash('err', 'File di origine non trovato.');
            md_redirect(md_self());
        }
        if ($target === $src) md_redirect($back);
        // realpath uguale ma nome diverso = cambio di maiuscole su filesystem case-insensitive
        if (file_exists($target) && realpath($target) !== $src) {
            md_flash('err', 'Nella destinazione esiste già un file con questo nome.');
            md_redirect($back);
        }
        if (!@rename($src, $target)) {
            md_flash('err', 'Spostamento non riuscito (permessi?).');
            md_redirect($back);
        }
        md_flash('ok', 'File spostato in «' . $targetRel . '».');
        md_redirect(md_url($targetRel));
    }

    md_redirect(md_self());
}

// ------------------------------------------------------------------ immagini

$img = md_get('img');
if ($img !== '') {
    $types = array('png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'avif' => 'image/avif', 'bmp' => 'image/bmp',
        'ico' => 'image/x-icon');
    $rel = md_clean($img);
    $ext = $rel === false ? '' : strtolower(pathinfo($rel, PATHINFO_EXTENSION));
    $abs = ($rel !== false && isset($types[$ext])) ? md_inside(MD_ROOT . '/' . $rel) : false;
    if ($abs === false || !is_file($abs)) {
        header('HTTP/1.0 404 Not Found');
        exit;
    }
    header('Content-Type: ' . $types[$ext]);
    header('Content-Length: ' . filesize($abs));
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
    header('Cache-Control: private, max-age=300');
    readfile($abs);
    exit;
}

// ------------------------------------------------------------------ pagina

$allDirs = array();
$tree = md_scan(MD_ROOT, '', 0, $allDirs);
$total = md_count($tree);

$curRel = '';
$content = '';
$hash = '';
$notice = '';
$missing = false;
$f = md_get('f');
if ($f !== '') {
    $abs = md_file($f);
    if ($abs === false) {
        $missing = true;
    } elseif (filesize($abs) > MD_MAX_SIZE) {
        $missing = true;
        $notice = 'File troppo grande per essere aperto.';
    } else {
        $curRel = md_clean($f);
        $raw = (string)file_get_contents($abs);
        $hash = md5($raw);
        $content = $raw;
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") $content = substr($content, 3);
        if (!preg_match('//u', $content)) {
            $content = function_exists('mb_convert_encoding')
                ? mb_convert_encoding($content, 'UTF-8', 'Windows-1252')
                : (string)@iconv('Windows-1252', 'UTF-8//IGNORE', $content);
            $notice = 'Il file non era in UTF-8: è stato convertito, e se lo salvi verrà riscritto in UTF-8.';
        }
        $content = str_replace(array("\r\n", "\r"), "\n", $content);
    }
}
$curDir = ($curRel !== '' && strpos($curRel, '/') !== false) ? substr($curRel, 0, strrpos($curRel, '/')) : '';
$curName = $curRel !== '' ? basename($curRel) : '';

$flash = isset($_SESSION['md_flash']) ? $_SESSION['md_flash'] : null;
unset($_SESSION['md_flash']);
if (!$flash && $notice !== '') $flash = array('type' => 'warn', 'msg' => $notice);


$jsData = array(
    'file' => $curRel,
    'dir' => $curDir,
    'content' => $content,
    'hash' => $hash,
    'canEdit' => $canEdit,
    'csrf' => isset($_SESSION['md_csrf']) ? $_SESSION['md_csrf'] : '',
    'self' => md_self(),
);

function md_css()
{
    ?>
<style>
    :root {
        --bg: #f4f5f7;
        --side: #eceef2;
        --card: #ffffff;
        --text: #1c1f26;
        --muted: #6b7280;
        --border: #e2e5ea;
        --accent: #2563eb;
        --on-accent: #ffffff;
        --code: #f1f3f6;
        --hover: rgba(37, 99, 235, .10);
        --err: #dc2626;
        --ok: #16a34a;
        --warn: #b45309;
    }
    @media (prefers-color-scheme: dark) {
        :root {
            --bg: #0f1218;
            --side: #12161d;
            --card: #181c25;
            --text: #e6e8ee;
            --muted: #8b93a3;
            --border: #262c38;
            --accent: #6ea8ff;
            --on-accent: #0f1218;
            --code: #1f2430;
            --hover: rgba(110, 168, 255, .12);
            --err: #f87171;
            --ok: #4ade80;
            --warn: #fbbf24;
        }
    }
    * { box-sizing: border-box; }
    [hidden] { display: none !important; }
    html, body { height: 100%; }
    body {
        margin: 0;
        background: var(--bg);
        color: var(--text);
        font: 15px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    }
    button, .btn {
        font: inherit;
        font-size: 13px;
        padding: 6px 12px;
        border: 1px solid var(--border);
        border-radius: 8px;
        background: var(--card);
        color: var(--text);
        cursor: pointer;
        white-space: nowrap;
    }
    button:hover:not(:disabled) { border-color: var(--accent); }
    button.primary { background: var(--accent); border-color: var(--accent); color: var(--on-accent); }
    button:disabled { opacity: .45; cursor: default; }
    input[type=text], input[type=password], input[type=search] {
        width: 100%;
        padding: 9px 11px;
        border: 1px solid var(--border);
        border-radius: 8px;
        background: var(--card);
        color: var(--text);
        font: inherit;
        outline: none;
    }
    input:focus { border-color: var(--accent); }
    .err { color: var(--err); }
</style>
    <?php
}

function md_login_page($err)
{
    ?><!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>FZPureMD · Accesso</title>
<?php md_css(); ?>
<style>
    body { display: flex; align-items: center; justify-content: center; padding: 16px; }
    form {
        width: 100%;
        max-width: 340px;
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 28px 24px;
    }
    h1 { margin: 0 0 18px; font-size: 18px; letter-spacing: .12em; }
    button { width: 100%; margin-top: 12px; padding: 10px; font-size: 15px; }
    p { margin: 12px 0 0; font-size: 14px; }
</style>
</head>
<body>
<form method="post" action="">
    <h1>FZPureMD</h1>
    <input type="hidden" name="action" value="login">
    <input type="password" name="password" placeholder="Password" autofocus autocomplete="current-password" required>
    <button class="primary">Entra</button>
    <?php if ($err !== ''): ?><p class="err"><?php echo md_h($err); ?></p><?php endif; ?>
</form>
</body>
</html>
<?php
}
?><!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo $curName !== '' ? md_h($curName) . ' · ' : ''; ?>FZPureMD</title>
<?php md_css(); ?>
<style>
    .app { display: grid; grid-template-columns: 300px minmax(0, 1fr); height: 100vh; }

    /* ---- barra laterale ---- */
    .side {
        display: flex;
        flex-direction: column;
        min-height: 0;
        background: var(--side);
        border-right: 1px solid var(--border);
    }
    .side-head {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        padding: 16px 16px 10px;
    }
    .side-head h1 { margin: 0; font-size: 16px; letter-spacing: .12em; }
    .side-head span { color: var(--muted); font-size: 12px; }
    .side-search { padding: 0 12px 10px; }
    .side-search input { padding: 7px 10px; font-size: 14px; }
    .tree { flex: 1; overflow: auto; padding: 0 8px 16px; font-size: 14px; }
    .tree ul { list-style: none; margin: 0; padding: 0 0 0 12px; }
    .tree > ul { padding-left: 0; }
    .tree summary {
        display: block;
        cursor: pointer;
        padding: 3px 6px;
        border-radius: 6px;
        color: var(--muted);
        font-weight: 600;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        user-select: none;
    }
    .tree summary::-webkit-details-marker { display: none; }
    .tree summary::before {
        content: "▸";
        display: inline-block;
        width: 14px;
        transition: transform .15s;
    }
    .tree details[open] > summary::before { transform: rotate(90deg); }
    .tree summary:hover { color: var(--text); }
    .tree a {
        display: block;
        padding: 3px 8px 3px 20px;
        border-radius: 6px;
        color: var(--text);
        text-decoration: none;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .tree a:hover { background: var(--hover); }
    .tree a.cur { background: var(--accent); color: var(--on-accent); }
    .tree .none { color: var(--muted); padding: 6px 8px; }
    .side-foot {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 8px;
        padding: 10px 12px;
        border-top: 1px solid var(--border);
        font-size: 13px;
    }
    .side-foot form { margin: 0 0 0 auto; }
    .role { color: var(--muted); }
    .role b { color: var(--text); }

    /* ---- area principale ---- */
    .main { display: flex; flex-direction: column; min-width: 0; min-height: 0; background: var(--card); }
    .bar {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 10px;
        padding: 10px 24px;
        border-bottom: 1px solid var(--border);
    }
    .path {
        flex: 1;
        min-width: 160px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        color: var(--muted);
        font: 13px ui-monospace, SFMono-Regular, Menlo, monospace;
    }
    .path b { color: var(--text); font-weight: 600; }
    .seg { display: inline-flex; border: 1px solid var(--border); border-radius: 8px; overflow: hidden; }
    .seg button { border: 0; border-radius: 0; background: transparent; }
    .seg button + button { border-left: 1px solid var(--border); }
    .seg button[aria-pressed="true"] { background: var(--accent); color: var(--on-accent); }
    #status { font-size: 12px; color: var(--muted); min-width: 0; }
    #status.err { color: var(--err); }
    .flash { margin: 0; padding: 8px 24px; font-size: 14px; border-bottom: 1px solid var(--border); }
    .flash.ok { color: var(--ok); }
    .flash.err { color: var(--err); }
    .flash.warn { color: var(--warn); }
    .tb {
        display: flex;
        flex-wrap: wrap;
        gap: 4px;
        padding: 6px 24px;
        border-bottom: 1px solid var(--border);
    }
    .tb button { min-width: 34px; padding: 4px 9px; }
    .tb .sep { width: 1px; margin: 2px 4px; background: var(--border); }
    .content { flex: 1; min-height: 0; overflow: auto; display: flex; flex-direction: column; }
    #src {
        flex: 1;
        width: 100%;
        min-height: 300px;
        margin: 0;
        padding: 24px 40px;
        border: 0;
        outline: none;
        resize: none;
        background: var(--card);
        color: var(--text);
        font: 14px/1.6 ui-monospace, SFMono-Regular, Menlo, monospace;
        tab-size: 4;
    }
    #wys { flex: 1; outline: none; }
    #wys:empty::before { content: "Scrivi qui…"; color: var(--muted); }
    .welcome { padding: 48px 40px; color: var(--muted); }
    .welcome h2 { color: var(--text); margin: 0 0 8px; }

    /* ---- markdown ---- */
    .md { padding: 28px 40px 80px; overflow-wrap: break-word; line-height: 1.65; }
    .md > :first-child { margin-top: 0; }
    .md h1, .md h2, .md h3, .md h4, .md h5, .md h6 { margin: 1.6em 0 .6em; line-height: 1.25; }
    .md h1 { font-size: 2em; padding-bottom: .3em; border-bottom: 1px solid var(--border); }
    .md h2 { font-size: 1.5em; padding-bottom: .25em; border-bottom: 1px solid var(--border); }
    .md h3 { font-size: 1.25em; }
    .md h4 { font-size: 1em; }
    .md h5, .md h6 { font-size: .9em; color: var(--muted); }
    .md p, .md ul, .md ol, .md blockquote, .md pre, .md .tw { margin: 0 0 1em; }
    .md a { color: var(--accent); }
    .md ul, .md ol { padding-left: 2em; }
    .md li + li { margin-top: .2em; }
    .md li > ul, .md li > ol { margin: .2em 0 0; }
    .md li.task { list-style: none; }
    .md li.task input { margin: 0 .4em 0 -1.4em; vertical-align: middle; }
    .md code {
        padding: .15em .35em;
        border-radius: 5px;
        background: var(--code);
        font: .9em ui-monospace, SFMono-Regular, Menlo, monospace;
    }
    .md pre {
        padding: 14px 16px;
        border-radius: 8px;
        background: var(--code);
        overflow: auto;
        line-height: 1.45;
        font: 13px/1.45 ui-monospace, SFMono-Regular, Menlo, monospace;
    }
    .md pre code { padding: 0; background: none; font-size: inherit; }
    .md blockquote { margin-left: 0; padding: 0 1em; border-left: 4px solid var(--border); color: var(--muted); }
    .md hr { height: 2px; margin: 1.8em 0; border: 0; background: var(--border); }
    .md img { max-width: 100%; }
    .md .tw { overflow-x: auto; }
    .md table { border-collapse: collapse; }
    .md th, .md td { padding: 6px 12px; border: 1px solid var(--border); }
    .md th { background: var(--code); }

    /* ---- finestre ---- */
    .modal {
        position: fixed;
        inset: 0;
        z-index: 10;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 16px;
        background: rgba(0, 0, 0, .45);
    }
    .modal form {
        width: 100%;
        max-width: 460px;
        padding: 22px;
        border: 1px solid var(--border);
        border-radius: 12px;
        background: var(--card);
    }
    .modal h2 { margin: 0 0 14px; font-size: 17px; }
    .modal label { display: block; margin: 12px 0 4px; font-size: 13px; color: var(--muted); }
    .modal .hint { margin: 6px 0 0; font-size: 12px; color: var(--muted); }
    .modal .actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 18px; }

    @media (max-width: 800px) {
        .app { display: block; height: auto; }
        .side { max-height: 45vh; border-right: 0; border-bottom: 1px solid var(--border); }
        .main { min-height: 100vh; }
        .content { overflow: visible; }
        .bar, .tb, .flash { padding-left: 16px; padding-right: 16px; }
        .md, #src, .welcome { padding-left: 16px; padding-right: 16px; }
        #src { min-height: 70vh; }
    }

    /* ---- stampa: solo il documento formattato, sempre su fondo chiaro ---- */
    @media print {
        :root {
            --bg: #fff;
            --card: #fff;
            --text: #000;
            --muted: #555;
            --border: #ccc;
            --accent: #1a4fd6;
            --code: #f3f3f3;
        }
        @page { margin: 18mm 16mm; }
        html, body { height: auto; background: #fff; }
        body { font-size: 11pt; }
        .side, .bar, .tb, .flash, .modal, #wys, #src { display: none !important; }
        .app, .main, .content { display: block; height: auto; min-height: 0; overflow: visible; }
        #view { display: block !important; padding: 0; }
        .md a { color: inherit; }
        .md pre { white-space: pre-wrap; border: 1px solid var(--border); }
        .md pre, .md blockquote, .md table, .md img, .md tr { break-inside: avoid; }
        .md h1, .md h2, .md h3, .md h4, .md h5, .md h6 { break-after: avoid; }
        .md .tw { overflow: visible; }
    }
</style>
</head>
<body>
<div class="app">
    <aside class="side">
        <div class="side-head">
            <h1>FZPureMD</h1>
            <span><?php echo $total; ?> file</span>
        </div>
        <div class="side-search">
            <input type="search" id="tf" placeholder="Filtra file…" autocomplete="off">
        </div>
        <nav class="tree" id="tree">
            <?php echo $total ? md_tree_html($tree, '', $curRel) : '<p class="none">Nessun file .md trovato.</p>'; ?>
            <p class="none" id="tnone" hidden>Nessun risultato.</p>
        </nav>
        <div class="side-foot">
            <span class="role">Accesso: <b><?php echo $canEdit ? 'completo' : 'sola lettura'; ?></b></span>
            <?php if ($canEdit): ?><button type="button" data-open="mNew">+ Nuovo</button><?php endif; ?>
            <form method="post" action="<?php echo md_h(md_self()); ?>">
                <input type="hidden" name="action" value="logout">
                <input type="hidden" name="csrf" value="<?php echo md_h($jsData['csrf']); ?>">
                <button>Esci</button>
            </form>
        </div>
    </aside>

    <main class="main">
        <?php if ($curRel !== ''): ?>
            <div class="bar">
                <div class="path" title="<?php echo md_h($curRel); ?>">
                    <?php echo $curDir !== '' ? md_h($curDir) . ' / ' : ''; ?><b><?php echo md_h($curName); ?></b>
                </div>
                <div class="seg" id="modes">
                    <button type="button" data-mode="view">Lettura</button>
                    <?php if ($canEdit): ?><button type="button" data-mode="wysiwyg">WYSIWYG</button><?php endif; ?>
                    <button type="button" data-mode="text">Testo</button>
                </div>
                <span id="status"></span>
                <button type="button" id="print" title="Stampa o salva come PDF">Stampa</button>
                <?php if ($canEdit): ?>
                    <button type="button" data-open="mMove">Sposta</button>
                    <button type="button" class="primary" id="save" disabled>Salva</button>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($flash): ?>
            <p class="flash <?php echo md_h($flash['type']); ?>"><?php echo md_h($flash['msg']); ?></p>
        <?php endif; ?>

        <?php if ($curRel !== '' && $canEdit): ?>
            <div class="tb" id="tb" hidden>
                <button type="button" data-cmd="bold" title="Grassetto"><b>B</b></button>
                <button type="button" data-cmd="italic" title="Corsivo"><i>I</i></button>
                <button type="button" data-cmd="strikeThrough" title="Barrato"><s>S</s></button>
                <button type="button" data-cmd="code" title="Codice in linea">&lt;/&gt;</button>
                <span class="sep"></span>
                <button type="button" data-cmd="formatBlock" data-val="h1">H1</button>
                <button type="button" data-cmd="formatBlock" data-val="h2">H2</button>
                <button type="button" data-cmd="formatBlock" data-val="h3">H3</button>
                <button type="button" data-cmd="formatBlock" data-val="p" title="Paragrafo">¶</button>
                <span class="sep"></span>
                <button type="button" data-cmd="insertUnorderedList" title="Elenco puntato">• Elenco</button>
                <button type="button" data-cmd="insertOrderedList" title="Elenco numerato">1. Elenco</button>
                <button type="button" data-cmd="formatBlock" data-val="blockquote" title="Citazione">❝</button>
                <button type="button" data-cmd="formatBlock" data-val="pre" title="Blocco di codice">{ }</button>
                <button type="button" data-cmd="insertHorizontalRule" title="Linea">―</button>
                <span class="sep"></span>
                <button type="button" data-cmd="link" title="Link">Link</button>
                <button type="button" data-cmd="image" title="Immagine">Img</button>
                <button type="button" data-cmd="removeFormat" title="Rimuovi formattazione">⌫</button>
            </div>
        <?php endif; ?>

        <div class="content">
            <?php if ($curRel !== ''): ?>
                <article id="view" class="md"></article>
                <div id="wys" class="md" hidden<?php echo $canEdit ? ' contenteditable="true"' : ''; ?>></div>
                <textarea id="src" hidden spellcheck="false"<?php echo $canEdit ? '' : ' readonly'; ?>></textarea>
            <?php else: ?>
                <div class="welcome">
                    <?php if ($missing): ?>
                        <h2>File non trovato</h2>
                        <p>Il file richiesto non esiste o non è accessibile.</p>
                    <?php else: ?>
                        <h2>Scegli un file</h2>
                        <p><?php echo $total; ?> file Markdown trovati. Selezionane uno dall'elenco a sinistra.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<?php if ($canEdit): ?>
    <datalist id="dirs">
        <?php foreach ($allDirs as $d): ?><option value="<?php echo md_h($d); ?>"><?php endforeach; ?>
    </datalist>

    <div class="modal" id="mNew" hidden>
        <form method="post" action="<?php echo md_h(md_self()); ?>">
            <h2>Nuovo file</h2>
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="csrf" value="<?php echo md_h($jsData['csrf']); ?>">
            <label for="nDir">Cartella</label>
            <input type="text" id="nDir" name="dir" list="dirs" value="<?php echo md_h($curDir); ?>" placeholder="(radice)" autocomplete="off">
            <label for="nName">Nome file</label>
            <input type="text" id="nName" name="name" placeholder="appunti.md" required autocomplete="off">
            <p class="hint">Le cartelle che non esistono vengono create.</p>
            <div class="actions">
                <button type="button" data-close>Annulla</button>
                <button class="primary">Crea</button>
            </div>
        </form>
    </div>

    <?php if ($curRel !== ''): ?>
        <div class="modal" id="mMove" hidden>
            <form method="post" action="<?php echo md_h(md_self()); ?>" id="moveForm">
                <h2>Sposta o rinomina</h2>
                <input type="hidden" name="action" value="move">
                <input type="hidden" name="csrf" value="<?php echo md_h($jsData['csrf']); ?>">
                <input type="hidden" name="f" value="<?php echo md_h($curRel); ?>">
                <label for="mDir">Cartella di destinazione</label>
                <input type="text" id="mDir" name="dir" list="dirs" value="<?php echo md_h($curDir); ?>" placeholder="(radice)" autocomplete="off">
                <label for="mName">Nome file</label>
                <input type="text" id="mName" name="name" value="<?php echo md_h($curName); ?>" required autocomplete="off">
                <p class="hint">Le cartelle che non esistono vengono create. I link relativi negli altri file non vengono aggiornati.</p>
                <div class="actions">
                    <button type="button" data-close>Annulla</button>
                    <button class="primary">Sposta</button>
                </div>
            </form>
        </div>
    <?php endif; ?>
<?php endif; ?>

<script>
var D = <?php echo json_encode($jsData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

/* ===================== percorsi ===================== */

function encPath(p) {
    return p.split('/').map(encodeURIComponent).join('/');
}

// Risolve un percorso relativo rispetto a una cartella; null se esce dalla radice
function resolvePath(base, rel) {
    var segs = base ? base.split('/') : [];
    var parts = rel.split('/');
    for (var i = 0; i < parts.length; i++) {
        var s = parts[i];
        if (s === '' || s === '.') continue;
        if (s === '..') {
            if (!segs.length) return null;
            segs.pop();
        } else {
            segs.push(s);
        }
    }
    return segs.join('/');
}

/* ===================== Markdown → HTML ===================== */

var MD = (function () {
    var ids = {};

    function esc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function unent(s) {
        return s.replace(/&quot;/g, '"').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
    }
    function isAbs(u) {
        return /^[a-z][a-z0-9+.\-]*:/i.test(u) || u.charAt(0) === '/' || u.charAt(0) === '#';
    }
    function safeUrl(u) {
        var t = unent(u).replace(/[\s\x00-\x1f]/g, '').toLowerCase();
        return /^(javascript|vbscript|data|file):/.test(t) ? '#' : u;
    }
    // Immagini relative: servite da questo script, rispetto alla cartella del file
    function imgSrc(u) {
        var raw = unent(u);
        if (u === '#' || isAbs(raw)) return u;
        var p = raw.split(/[?#]/)[0];
        try { p = decodeURIComponent(p); } catch (e) {}
        var r = resolvePath(D.dir, p);
        return r === null ? u : esc('?img=' + encPath(r));
    }
    function link(u, txt, title) {
        var ext = /^https?:/i.test(unent(u));
        return '<a href="' + u + '"' + (title ? ' title="' + title + '"' : '')
            + (ext ? ' target="_blank" rel="noopener noreferrer"' : '') + '>' + txt + '</a>';
    }
    function emph(s) {
        return s
            .replace(/\*\*\*(?=\S)([\s\S]*?\S)\*\*\*/g, '<strong><em>$1</em></strong>')
            .replace(/\*\*(?=\S)([\s\S]*?\S)\*\*/g, '<strong>$1</strong>')
            .replace(/(^|[^\w])__(?=\S)([\s\S]*?\S)__(?!\w)/g, '$1<strong>$2</strong>')
            .replace(/\*(?=[^\s*])([\s\S]*?[^\s*])\*/g, '<em>$1</em>')
            .replace(/(^|[^\w])_(?=[^\s_])([\s\S]*?[^\s_])_(?!\w)/g, '$1<em>$2</em>')
            .replace(/~~(?=\S)([\s\S]*?\S)~~/g, '<del>$1</del>');
    }
    function inline(s) {
        var ph = [];
        function put(h) { ph.push(h); return '\u0000' + (ph.length - 1) + '\u0000'; }
        s = s.replace(/(`+)([^`]|[^`][\s\S]*?[^`])\1(?!`)/g, function (m, t, c) {
            if (/^ [\s\S]* $/.test(c) && /\S/.test(c)) c = c.slice(1, -1);
            return put('<code>' + esc(c) + '</code>');
        });
        s = s.replace(/\\([\\`*_{}\[\]()#+\-.!|>~=<])/g, function (m, c) { return put(esc(c)); });
        s = s.replace(/<(https?:\/\/[^\s<>]+)>/g, function (m, u) { return put(link(esc(u), esc(u))); });
        s = esc(s);
        s = s.replace(/!\[([^\]]*)\]\(\s*([^\s)]+)(?:\s+&quot;([\s\S]*?)&quot;)?\s*\)/g, function (m, alt, u, t) {
            return put('<img src="' + imgSrc(safeUrl(u)) + '" data-md-src="' + u + '" alt="' + alt + '"'
                + (t ? ' title="' + t + '"' : '') + '>');
        });
        s = s.replace(/\[((?:[^\[\]]|\[[^\]]*\])*)\]\(\s*([^\s)]+)(?:\s+&quot;([\s\S]*?)&quot;)?\s*\)/g, function (m, txt, u, t) {
            return put(link(safeUrl(u), emph(txt), t));
        });
        s = s.replace(/(^|[\s(])(https?:\/\/[^\s<\u0000]*[^\s<\u0000.,;:!?)\]'*_])/g, function (m, pre, u) {
            return pre + put(link(u, u));
        });
        s = emph(s);
        while (/\u0000\d+\u0000/.test(s)) {
            s = s.replace(/\u0000(\d+)\u0000/g, function (m, i) { return ph[+i]; });
        }
        return s;
    }
    function slug(h) {
        var t = h.replace(/<[^>]+>/g, '').replace(/&[a-z0-9#]+;/gi, '').toLowerCase().trim()
            .replace(/[^\wÀ-￿\- ]+/g, '').replace(/\s+/g, '-') || 'sezione';
        if (ids[t]) return t + '-' + (ids[t]++);
        ids[t] = 1;
        return t;
    }
    function heading(level, text) {
        var h = inline(text.trim());
        return '<h' + level + ' id="' + slug(h) + '">' + h + '</h' + level + '>';
    }
    function para(p) {
        var s = '';
        for (var k = 0; k < p.length; k++) {
            var x = p[k].replace(/^\s+/, '');
            if (k < p.length - 1) {
                if (/( {2,}|\\)$/.test(x)) s += x.replace(/( {2,}|\\)$/, '') + '\u0001';
                else s += x + '\n';
            } else {
                s += x.replace(/\s+$/, '');
            }
        }
        return inline(s).replace(/\u0001/g, '<br>\n');
    }

    var reFence = /^ {0,3}(`{3,}|~{3,})[ \t]*([^`\s]*)[^`]*$/;
    var reHead = /^ {0,3}(#{1,6})(?:[ \t]+(.*?))?(?:[ \t]+#+)?[ \t]*$/;
    var reHr = /^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/;
    var reQuote = /^ {0,3}>/;
    var reBlank = /^\s*$/;

    function item(l) {
        var m = /^( *)([-*+]|(\d{1,9})[.)])([ \t]+|$)(.*)$/.exec(l);
        if (!m) return null;
        var sp = m[4].length;
        if (sp > 4 || !m[5]) sp = 1;
        return {
            ind: m[1].length, ord: !!m[3], num: m[3] ? parseInt(m[3], 10) : 1,
            mark: m[2].slice(-1), text: m[5], ci: m[1].length + m[2].length + sp
        };
    }
    function isStart(l) {
        var it;
        return reFence.test(l) || reHead.test(l) || reHr.test(l) || reQuote.test(l)
            || ((it = item(l)) && it.ind <= 3);
    }
    function cells(l) {
        l = l.trim();
        if (l.charAt(0) === '|') l = l.slice(1);
        if (/[^\\]\|$/.test(l) || l === '|') l = l.slice(0, -1);
        var r = [], c = '';
        for (var k = 0; k < l.length; k++) {
            var ch = l.charAt(k);
            if (ch === '\\' && l.charAt(k + 1) === '|') { c += '|'; k++; }
            else if (ch === '|') { r.push(c.trim()); c = ''; }
            else c += ch;
        }
        r.push(c.trim());
        return r;
    }
    function isTable(lines, i) {
        return i + 1 < lines.length && lines[i].indexOf('|') !== -1 && lines[i + 1].indexOf('|') !== -1
            && /^ {0,3}\|?[ \t]*:?-+:?[ \t]*(\|[ \t]*:?-+:?[ \t]*)*\|?[ \t]*$/.test(lines[i + 1]);
    }
    function table(lines, i) {
        var head = cells(lines[i]);
        var al = cells(lines[i + 1]).map(function (c) {
            var l = c.charAt(0) === ':', r = c.slice(-1) === ':';
            return l && r ? 'center' : r ? 'right' : l ? 'left' : '';
        });
        var rows = [];
        i += 2;
        while (i < lines.length && lines[i].indexOf('|') !== -1 && !reBlank.test(lines[i])) {
            rows.push(cells(lines[i]));
            i++;
        }
        function row(c, t) {
            var s = '<tr>';
            for (var k = 0; k < head.length; k++) {
                s += '<' + t + (al[k] ? ' style="text-align:' + al[k] + '"' : '') + '>' + inline(c[k] || '') + '</' + t + '>';
            }
            return s + '</tr>';
        }
        var h = '<div class="tw"><table><thead>' + row(head, 'th') + '</thead>';
        if (rows.length) h += '<tbody>' + rows.map(function (r) { return row(r, 'td'); }).join('') + '</tbody>';
        return { html: h + '</table></div>', i: i };
    }
    function list(lines, i) {
        var n = lines.length, first = item(lines[i]), items = [], loose = false;
        while (i < n) {
            var it = item(lines[i]);
            if (!it || it.ord !== first.ord || it.mark !== first.mark) break;
            var buf = [it.text], blank = false;
            i++;
            while (i < n) {
                var x = lines[i];
                if (reBlank.test(x)) { buf.push(''); blank = true; i++; continue; }
                var ind = x.match(/^ */)[0].length;
                if (ind >= it.ci) { buf.push(x.slice(it.ci)); blank = false; i++; continue; }
                var sub = item(x);
                if (sub && ind >= 2 && ind > it.ind) { buf.push(x.slice(ind)); blank = false; i++; continue; }
                if (blank || sub || isStart(x)) break;
                buf.push(x);
                i++;
            }
            var trail = false;
            while (buf.length && buf[buf.length - 1] === '') { buf.pop(); trail = true; }
            if (buf.indexOf('') !== -1) loose = true;
            if (trail && i < n) {
                var nx = item(lines[i]);
                if (nx && nx.ord === first.ord && nx.mark === first.mark) loose = true;
            }
            items.push(buf);
        }
        var tag = first.ord ? 'ol' : 'ul';
        var h = '<' + tag + (first.ord && first.num !== 1 ? ' start="' + first.num + '"' : '') + '>';
        items.forEach(function (b) {
            var task = /^\[([ xX])\][ \t]+/.exec(b[0]);
            if (task) b[0] = b[0].slice(task[0].length);
            var inner = blocks(b);
            if (!loose) inner = inner.replace(/^<p>([\s\S]*?)<\/p>/, '$1');
            if (task) inner = '<input type="checkbox" disabled' + (task[1] !== ' ' ? ' checked' : '') + '> ' + inner;
            h += '<li' + (task ? ' class="task"' : '') + '>' + inner + '</li>';
        });
        return { html: h + '</' + tag + '>', i: i };
    }
    function blocks(lines) {
        var out = [], i = 0, n = lines.length, m, r;
        while (i < n) {
            var l = lines[i];
            if (reBlank.test(l)) { i++; continue; }
            if ((m = reFence.exec(l))) {
                var fence = m[1], lang = m[2], ind = l.match(/^ */)[0].length, code = [];
                var close = new RegExp('^ {0,3}' + (fence.charAt(0) === '`' ? '`' : '~') + '{' + fence.length + ',}[ \\t]*$');
                var strip = new RegExp('^ {0,' + ind + '}');
                i++;
                while (i < n && !close.test(lines[i])) { code.push(lines[i].replace(strip, '')); i++; }
                i++;
                out.push('<pre><code' + (lang ? ' class="language-' + esc(lang) + '"' : '') + '>' + esc(code.join('\n')) + '</code></pre>');
                continue;
            }
            if (/^( {4}|\t)/.test(l)) {
                var ic = [];
                while (i < n && (/^( {4}|\t)/.test(lines[i]) || reBlank.test(lines[i]))) {
                    ic.push(lines[i].replace(/^( {4}|\t)/, ''));
                    i++;
                }
                while (ic.length && reBlank.test(ic[ic.length - 1])) ic.pop();
                out.push('<pre><code>' + esc(ic.join('\n')) + '</code></pre>');
                continue;
            }
            if ((m = reHead.exec(l))) { out.push(heading(m[1].length, m[2] || '')); i++; continue; }
            if (reHr.test(l)) { out.push('<hr>'); i++; continue; }
            if (reQuote.test(l)) {
                var q = [];
                while (i < n && !reBlank.test(lines[i]) && (reQuote.test(lines[i]) || !isStart(lines[i]))) {
                    q.push(lines[i].replace(/^ {0,3}> ?/, ''));
                    i++;
                }
                out.push('<blockquote>' + blocks(q) + '</blockquote>');
                continue;
            }
            var it = item(l);
            if (it && it.ind <= 3) { r = list(lines, i); out.push(r.html); i = r.i; continue; }
            if (isTable(lines, i)) { r = table(lines, i); out.push(r.html); i = r.i; continue; }

            var p = [l], setext = 0;
            i++;
            while (i < n) {
                var x = lines[i];
                if (reBlank.test(x)) break;
                if (/^ {0,3}=+[ \t]*$/.test(x)) { setext = 1; i++; break; }
                if (/^ {0,3}-+[ \t]*$/.test(x)) { setext = 2; i++; break; }
                if (isStart(x) || isTable(lines, i)) break;
                p.push(x);
                i++;
            }
            out.push(setext ? heading(setext, p.join(' ')) : '<p>' + para(p) + '</p>');
        }
        return out.join('\n');
    }

    return function (text) {
        ids = {};
        var lines = String(text).replace(/\r\n?/g, '\n').replace(/[\u0000\u0001]/g, '�').split('\n');
        // Tab iniziali → spazi, tranne dentro i blocchi di codice delimitati
        var inFence = false;
        for (var k = 0; k < lines.length; k++) {
            if (reFence.test(lines[k])) inFence = !inFence;
            else if (!inFence) lines[k] = lines[k].replace(/^[ \t]+/, function (w) { return w.replace(/\t/g, '    '); });
        }
        return blocks(lines);
    };
})();

/* ===================== HTML → Markdown (per il WYSIWYG) ===================== */

var toMarkdown = (function () {
    var BLOCK = /^(ADDRESS|ARTICLE|ASIDE|BLOCKQUOTE|DD|DETAILS|DIV|DL|DT|FIELDSET|FIGCAPTION|FIGURE|FOOTER|FORM|H[1-6]|HEADER|HR|LI|MAIN|NAV|OL|P|PRE|SECTION|SUMMARY|TABLE|TBODY|TD|TFOOT|TH|THEAD|TR|UL)$/;

    function textMd(s) {
        return s
            .replace(/[\\`*\[\]]/g, '\\$&')
            .replace(/~~/g, '\\~\\~')
            .replace(/<(?=https?:)/g, '\\<')
            .replace(/_/g, function (m, o, str) {
                return (/\w/.test(str.charAt(o - 1)) && /\w/.test(str.charAt(o + 1))) ? '_' : '\\_';
            });
    }
    // \u0001 = a capo forzato
    function cleanInline(t) {
        t = t.replace(/[ \t\n\r ]+/g, ' ');
        t = t.replace(/ ?\u0001 ?/g, '\u0001').replace(/^[ \u0001]+|[ \u0001]+$/g, '');
        return t.replace(/\u0001/g, '  \n');
    }
    function escStarts(t) {
        return t.split('\n').map(function (l) {
            return l
                .replace(/^(\d+)([.)])(?= |$)/, '$1\\$2')
                .replace(/^([#>]|[-+](?= |$))/, '\\$1')
                .replace(/^([=\-]+)$/, '\\$1');
        }).join('\n');
    }
    function wrap(inner, mark) {
        var m = inner.match(/^(\s*)([\s\S]*?)(\s*)$/);
        return m[2] ? m[1] + mark + m[2] + mark + m[3] : inner;
    }
    function codeSpan(t) {
        t = t.replace(/ /g, ' ');
        if (!t) return '';
        var f = '`';
        while (t.indexOf(f) !== -1) f += '`';
        var pad = (t.charAt(0) === '`' || t.slice(-1) === '`') ? ' ' : '';
        return f + pad + t + pad + f;
    }
    function urlMd(u) {
        return u.replace(/ /g, '%20').replace(/\(/g, '%28').replace(/\)/g, '%29');
    }
    function inlineChildren(el) {
        var s = '';
        for (var c = el.firstChild; c; c = c.nextSibling) {
            if (c.nodeType === 3) s += textMd(c.nodeValue);
            else if (c.nodeType === 1) s += BLOCK.test(c.nodeName) ? '\u0001' + parts(c).join('\u0001') + '\u0001' : inlineMd(c);
        }
        return s;
    }
    function inlineMd(el) {
        var tag = el.nodeName, t, st;
        switch (tag) {
            case 'BR': return '\u0001';
            case 'INPUT': return '';
            case 'STRONG': case 'B': return wrap(inlineChildren(el), '**');
            case 'EM': case 'I': return wrap(inlineChildren(el), '*');
            case 'DEL': case 'S': case 'STRIKE': return wrap(inlineChildren(el), '~~');
            case 'CODE': case 'KBD': case 'SAMP': return codeSpan(el.textContent);
            case 'A':
                var href = el.getAttribute('href') || '';
                t = inlineChildren(el);
                if (!href || href === '#') return t;
                if (el.textContent === href && /^https?:\/\//.test(href)) return '<' + href + '>';
                var ti = el.getAttribute('title');
                return '[' + t + '](' + urlMd(href) + (ti ? ' "' + ti.replace(/"/g, "'") + '"' : '') + ')';
            case 'IMG':
                var src = el.getAttribute('data-md-src') || el.getAttribute('src') || '';
                var alt = (el.getAttribute('alt') || '').replace(/[\[\]]/g, '\\$&');
                var it = el.getAttribute('title');
                return '![' + alt + '](' + urlMd(src) + (it ? ' "' + it.replace(/"/g, "'") + '"' : '') + ')';
            case 'SPAN': case 'FONT':
                t = inlineChildren(el);
                st = el.style || {};
                if (/bold|[6-9]00/.test(st.fontWeight || '')) t = wrap(t, '**');
                if (st.fontStyle === 'italic') t = wrap(t, '*');
                if (/line-through/.test((st.textDecoration || '') + (st.textDecorationLine || ''))) t = wrap(t, '~~');
                return t;
            default:
                return inlineChildren(el);
        }
    }
    function preText(n) {
        var s = '';
        for (var c = n.firstChild; c; c = c.nextSibling) {
            if (c.nodeType === 3) s += c.nodeValue;
            else if (c.nodeName === 'BR') s += '\n';
            else if (/^(DIV|P)$/.test(c.nodeName)) {
                if (s && s.slice(-1) !== '\n') s += '\n';
                s += preText(c) + '\n';
            } else s += preText(c);
        }
        return s;
    }
    function pad(w) { return new Array(w + 1).join(' '); }
    function indent(s, w) {
        var p = pad(w);
        return s.split('\n').map(function (l) { return l ? p + l : l; }).join('\n');
    }
    function listMd(el, ord) {
        var n = parseInt(el.getAttribute('start'), 10), out = [], loose = false, last = 2;
        if (isNaN(n)) n = 1;
        for (var c = el.firstChild; c; c = c.nextSibling) {
            if (c.nodeType !== 1) continue;
            if (c.nodeName === 'LI') {
                var hasP = false, fe = c.firstElementChild, cb = null;
                for (var k = c.firstChild; k; k = k.nextSibling) if (k.nodeName === 'P') hasP = true;
                if (fe && fe.nodeName === 'INPUT' && fe.type === 'checkbox') cb = fe;
                if (hasP) loose = true;
                var body = parts(c).join(hasP ? '\n\n' : '\n');
                if (cb) body = '[' + (cb.checked ? 'x' : ' ') + '] ' + body;
                var mk = ord ? (n++) + '.' : '-';
                var lines = body.split('\n');
                last = mk.length + 1;
                out.push(mk + ' ' + lines[0] + (lines.length > 1 ? '\n' + indent(lines.slice(1).join('\n'), last) : ''));
            } else if ((c.nodeName === 'UL' || c.nodeName === 'OL') && out.length) {
                out[out.length - 1] += '\n' + indent(listMd(c, c.nodeName === 'OL'), last);
            } else {
                var b = blockMd(c);
                if (b) out.push(b);
            }
        }
        return out.join(loose ? '\n\n' : '\n');
    }
    function tableMd(t) {
        var rows = [], al = [], trs = t.querySelectorAll('tr');
        for (var r = 0; r < trs.length; r++) {
            var cs = [];
            for (var c = trs[r].firstChild; c; c = c.nextSibling) {
                if (c.nodeName !== 'TD' && c.nodeName !== 'TH') continue;
                cs.push(cleanInline(inlineChildren(c)).replace(/ {2}\n/g, ' ').replace(/\|/g, '\\|'));
                if (r === 0) al.push(c.style.textAlign || c.getAttribute('align') || '');
            }
            rows.push(cs);
        }
        if (!rows.length) return '';
        var w = Math.max.apply(null, rows.map(function (x) { return x.length; }));
        function line(c) {
            var a = [];
            for (var k = 0; k < w; k++) a.push(c[k] || '');
            return '| ' + a.join(' | ') + ' |';
        }
        var sep = [];
        for (var k = 0; k < w; k++) {
            sep.push(al[k] === 'center' ? ':---:' : al[k] === 'right' ? '---:' : al[k] === 'left' ? ':---' : '---');
        }
        return [line(rows[0]), '| ' + sep.join(' | ') + ' |'].concat(rows.slice(1).map(line)).join('\n');
    }
    function blockMd(el) {
        var tag = el.nodeName, m;
        if ((m = /^H([1-6])$/.exec(tag))) {
            var t = cleanInline(inlineChildren(el)).replace(/ {2}\n/g, ' ');
            return t ? new Array(+m[1] + 1).join('#') + ' ' + t : '';
        }
        switch (tag) {
            case 'HR': return '---';
            case 'UL': return listMd(el, false);
            case 'OL': return listMd(el, true);
            case 'TABLE': return tableMd(el);
            case 'PRE':
                var code = preText(el).replace(/ /g, ' ').replace(/\n+$/, '');
                var ce = el.querySelector('code');
                var lang = ce && /language-(\S+)/.exec(ce.className || '');
                var f = '```';
                while (code.indexOf(f) !== -1) f += '`';
                return f + (lang ? lang[1] : '') + '\n' + code + '\n' + f;
            case 'BLOCKQUOTE':
                var q = parts(el).join('\n\n');
                return q ? q.split('\n').map(function (l) { return l ? '> ' + l : '>'; }).join('\n') : '';
            case 'LI':
                return '- ' + parts(el).join('\n');
            default:
                return parts(el).join('\n\n');
        }
    }
    function parts(node) {
        var out = [], buf = '';
        function flush() {
            var t = cleanInline(buf);
            if (t) out.push(escStarts(t));
            buf = '';
        }
        for (var c = node.firstChild; c; c = c.nextSibling) {
            if (c.nodeType === 3) buf += textMd(c.nodeValue);
            else if (c.nodeType === 1) {
                if (c.nodeName !== 'BR' && BLOCK.test(c.nodeName)) {
                    flush();
                    var b = blockMd(c);
                    if (b) out.push(b);
                } else {
                    buf += inlineMd(c);
                }
            }
        }
        flush();
        return out;
    }
    return function (root) {
        var s = parts(root).join('\n\n').replace(/\s+$/, '');
        return s ? s + '\n' : '';
    };
})();

/* ===================== interfaccia ===================== */

(function () {
    function $(id) { return document.getElementById(id); }

    // ---- filtro albero
    var tf = $('tf'), tree = $('tree');
    if (tf && tree) {
        tf.addEventListener('input', function () {
            var q = tf.value.trim().toLowerCase();
            var files = tree.querySelectorAll('li.f'), shown = 0;
            Array.prototype.forEach.call(files, function (li) {
                var hit = !q || li.querySelector('a').title.toLowerCase().indexOf(q) !== -1;
                li.hidden = !hit;
                if (hit) shown++;
            });
            var dirs = tree.querySelectorAll('li.d');
            for (var k = dirs.length - 1; k >= 0; k--) {
                var li = dirs[k], det = li.firstElementChild;
                var any = !!li.querySelector('li.f:not([hidden])');
                li.hidden = !!q && !any;
                det.open = q ? any : det.hasAttribute('data-open');
            }
            $('tnone').hidden = !q || shown > 0;
        });
    }

    // ---- finestre
    function openModal(id) {
        var m = $(id);
        if (!m) return;
        m.hidden = false;
        var inp = m.querySelector('input[type=text]:not([value=""]), input[type=text]');
        if (inp) { inp.focus(); inp.select(); }
    }
    document.addEventListener('click', function (e) {
        var o = e.target.closest('[data-open]');
        if (o) { openModal(o.getAttribute('data-open')); return; }
        var m = e.target.closest('.modal');
        if (m && (e.target === m || e.target.closest('[data-close]'))) m.hidden = true;
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            Array.prototype.forEach.call(document.querySelectorAll('.modal'), function (m) { m.hidden = true; });
        }
    });

    if (!D.file) return;

    // ---- editor
    var view = $('view'), wys = $('wys'), ta = $('src'), tb = $('tb');
    var saveBtn = $('save'), status = $('status');
    var src = D.content, mode = '', dirty = false, wysDirty = false, rev = 0, saving = false;

    function setStatus(t, err) {
        status.textContent = t;
        status.className = err ? 'err' : '';
    }
    function markDirty() {
        rev++;
        if (!dirty) {
            dirty = true;
            if (saveBtn) saveBtn.disabled = false;
            setStatus('Modifiche non salvate');
        }
    }
    function syncFromWys() {
        if (mode === 'wysiwyg' && wysDirty) {
            src = toMarkdown(wys);
            wysDirty = false;
        }
    }
    function scrollToHash() {
        if (!location.hash) return;
        var t = document.getElementById(decodeURIComponent(location.hash.slice(1)));
        if (t) t.scrollIntoView();
    }
    function setMode(m) {
        if (m === 'wysiwyg' && !D.canEdit) m = 'view';
        syncFromWys();
        mode = m;
        view.hidden = m !== 'view';
        wys.hidden = m !== 'wysiwyg';
        ta.hidden = m !== 'text';
        if (tb) tb.hidden = m !== 'wysiwyg';
        if (m === 'view') { view.innerHTML = MD(src); scrollToHash(); }
        if (m === 'wysiwyg') { wys.innerHTML = MD(src); wysDirty = false; }
        if (m === 'text') ta.value = src;
        Array.prototype.forEach.call(document.querySelectorAll('#modes button'), function (b) {
            b.setAttribute('aria-pressed', b.getAttribute('data-mode') === m ? 'true' : 'false');
        });
        try { localStorage.setItem('md-mode', m); } catch (e) {}
    }

    $('modes').addEventListener('click', function (e) {
        var b = e.target.closest('button[data-mode]');
        if (b) setMode(b.getAttribute('data-mode'));
    });

    ta.addEventListener('input', function () { src = ta.value; markDirty(); });
    ta.addEventListener('keydown', function (e) {
        if (e.key === 'Tab' && !ta.readOnly && !e.metaKey && !e.ctrlKey && !e.altKey) {
            e.preventDefault();
            document.execCommand('insertText', false, '    ');
        }
    });

    if (D.canEdit) {
        try {
            document.execCommand('defaultParagraphSeparator', false, 'p');
            document.execCommand('styleWithCSS', false, false);
        } catch (e) {}
        wys.addEventListener('input', function () { wysDirty = true; markDirty(); });
        // Incolla e trascina solo come testo semplice
        wys.addEventListener('paste', function (e) {
            e.preventDefault();
            var t = (e.clipboardData || window.clipboardData).getData('text/plain');
            document.execCommand('insertText', false, t);
        });
        wys.addEventListener('drop', function (e) {
            e.preventDefault();
        });

        tb.addEventListener('mousedown', function (e) {
            if (e.target.closest('button')) e.preventDefault();
        });
        tb.addEventListener('click', function (e) {
            var b = e.target.closest('button[data-cmd]');
            if (!b) return;
            wys.focus();
            var c = b.getAttribute('data-cmd'), v = b.getAttribute('data-val'), sel = window.getSelection();
            if (c === 'formatBlock') {
                document.execCommand('formatBlock', false, '<' + v + '>');
            } else if (c === 'code') {
                var t = sel.toString();
                if (t) document.execCommand('insertHTML', false, '<code>' + t.replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</code>&#8203;');
            } else if (c === 'link') {
                var u = prompt('Indirizzo del link:', 'https://');
                if (!u) return;
                if (sel.isCollapsed) {
                    var a = document.createElement('a');
                    a.href = u;
                    a.textContent = u;
                    document.execCommand('insertHTML', false, a.outerHTML);
                } else {
                    document.execCommand('createLink', false, u);
                }
            } else if (c === 'image') {
                var s = prompt('Indirizzo dell\'immagine (URL o percorso relativo):', '');
                if (!s) return;
                var alt = prompt('Testo alternativo:', '') || '';
                var img = document.createElement('img');
                img.setAttribute('data-md-src', s);
                img.setAttribute('src', /^[a-z]+:|^\//i.test(s) ? s : '?img=' + encPath(resolvePath(D.dir, s) || s));
                img.setAttribute('alt', alt);
                document.execCommand('insertHTML', false, img.outerHTML);
            } else {
                document.execCommand(c, false, null);
            }
            wysDirty = true;
            markDirty();
        });
    }

    // Link relativi ad altri .md: si aprono qui
    view.addEventListener('click', function (e) {
        var a = e.target.closest('a');
        if (!a) return;
        var href = a.getAttribute('href') || '';
        if (href.charAt(0) === '#') {
            e.preventDefault();
            var t = document.getElementById(decodeURIComponent(href.slice(1)));
            if (t) t.scrollIntoView({ behavior: 'smooth' });
            return;
        }
        if (/^[a-z][a-z0-9+.\-]*:/i.test(href) || href.charAt(0) === '/') return;
        var parts = href.split('#'), p = parts[0];
        try { p = decodeURIComponent(p); } catch (err) {}
        if (!/\.md$/i.test(p)) return;
        var r = resolvePath(D.dir, p);
        if (r === null) return;
        e.preventDefault();
        location.href = D.self + '?f=' + encPath(r) + (parts[1] ? '#' + parts[1] : '');
    });

    // ---- salvataggio
    function save(force) {
        if (!D.canEdit || saving) return;
        syncFromWys();
        saving = true;
        var sentRev = rev;
        setStatus('Salvataggio…');
        var body = new URLSearchParams();
        body.append('action', 'save');
        body.append('csrf', D.csrf);
        body.append('f', D.file);
        body.append('hash', D.hash);
        body.append('content', src);
        if (force) body.append('force', '1');
        fetch(D.self, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (r) {
                saving = false;
                if (r.ok) {
                    D.hash = r.hash;
                    if (rev === sentRev) {
                        dirty = false;
                        saveBtn.disabled = true;
                        var d = new Date();
                        setStatus('Salvato alle ' + ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2));
                    } else {
                        setStatus('Modifiche non salvate');
                    }
                } else if (r.conflict) {
                    if (confirm(r.error + '\n\nVuoi sovrascriverlo con la tua versione?')) save(true);
                    else setStatus('Non salvato: ricarica la pagina per vedere la versione attuale.', true);
                } else {
                    setStatus(r.error || 'Errore durante il salvataggio.', true);
                }
            })
            .catch(function () {
                saving = false;
                setStatus('Errore di rete: non salvato.', true);
            });
    }
    if (saveBtn) saveBtn.addEventListener('click', function () { save(false); });

    // ---- stampa: si stampa sempre la versione formattata, anche da WYSIWYG o Testo
    function preparePrint() {
        syncFromWys();
        view.innerHTML = MD(src);
    }
    window.addEventListener('beforeprint', preparePrint);
    $('print').addEventListener('click', function () {
        preparePrint();
        window.print();
    });

    document.addEventListener('keydown', function (e) {
        if ((e.metaKey || e.ctrlKey) && !e.shiftKey && !e.altKey && (e.key === 's' || e.key === 'S')) {
            e.preventDefault();
            if (dirty) save(false);
        }
    });

    window.addEventListener('beforeunload', function (e) {
        if (dirty) {
            e.preventDefault();
            e.returnValue = '';
            return '';
        }
    });

    var mf = $('moveForm');
    if (mf) {
        mf.addEventListener('submit', function (e) {
            if (dirty && !confirm('Ci sono modifiche non salvate che andranno perse. Continuare?')) {
                e.preventDefault();
                return;
            }
            dirty = false;
        });
    }

    var start = 'view';
    try { start = localStorage.getItem('md-mode') || 'view'; } catch (e) {}
    setMode(start);
})();
</script>
</body>
</html>
