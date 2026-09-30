<?php
/**
 * One-time Hostmaster capability check (Phase 1).
 *
 * - Built by the "Hosting check" GitHub Actions workflow, which replaces the
 *   token placeholder with a random value and gives the file a random name.
 * - Access: https://<domain>/<file>.php?t=<token>
 * - Deletes itself after the first successful view, and refuses to run (and
 *   deletes itself) if it was uploaded more than 24 hours ago.
 * - Prints only capability information: no environment variables, no
 *   credentials, no full phpinfo().
 */

declare(strict_types=1);

const HOSTCHECK_TOKEN = '__HOSTCHECK_TOKEN__';
const HOSTCHECK_MAX_AGE_SEC = 86400;
const HOSTCHECK_VERSION = '1';

const REQUIRED_EXTENSIONS = [
    'pdo_mysql', 'openssl', 'mbstring', 'intl', 'fileinfo', 'sodium',
    'ctype', 'curl', 'dom', 'filter', 'hash', 'json', 'session', 'tokenizer',
    'xml', 'zip', 'bcmath',
];
const IMAGE_EXTENSIONS = ['gd', 'imagick'];
const OPTIONAL_EXTENSIONS = ['mysqli', 'pdo_pgsql', 'opcache', 'redis', 'exif', 'pcntl', 'posix'];

const INI_KEYS = [
    'memory_limit', 'max_execution_time', 'max_input_time', 'upload_max_filesize',
    'post_max_size', 'max_file_uploads', 'date.timezone', 'open_basedir',
    'disable_functions', 'allow_url_fopen', 'display_errors', 'expose_php',
    'session.save_handler', 'opcache.enable', 'realpath_cache_size',
];

const PROBE_BINARIES = ['php', 'mysql', 'mysqldump', 'git', 'composer', 'node', 'npm', 'python3', 'gzip', 'tar', 'openssl', 'lftp', 'rsync', 'crontab'];

const OUTBOUND_URLS = [
    'telegram' => 'https://api.telegram.org/',
    'github' => 'https://api.github.com/',
    'google_generate_204' => 'https://www.google.com/generate_204',
];

function hc_is_cli(): bool
{
    return PHP_SAPI === 'cli';
}

function hc_fail(int $code, string $message): void
{
    if (!hc_is_cli()) {
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow');
    }
    echo $message, "\n";
    exit(hc_is_cli() ? 1 : 0);
}

function hc_self_delete(): void
{
    if (getenv('HOSTCHECK_KEEP_FILE') === '1') {
        return;
    }
    @unlink(__FILE__);
}

function hc_authorize(): void
{
    if (HOSTCHECK_TOKEN === '__HOSTCHECK' . '_TOKEN__' || strlen(HOSTCHECK_TOKEN) < 32) {
        hc_fail(403, 'Not configured. Build this file with the "Hosting check" GitHub Actions workflow.');
    }

    $mtime = @filemtime(__FILE__);
    if ($mtime !== false && time() - $mtime > HOSTCHECK_MAX_AGE_SEC) {
        hc_self_delete();
        hc_fail(410, 'Expired and deleted. Build a new file with the workflow.');
    }

    $given = hc_is_cli() ? (string) getenv('HOSTCHECK_TOKEN') : (string) ($_GET['t'] ?? '');
    if (!hash_equals(HOSTCHECK_TOKEN, $given)) {
        hc_fail(404, 'Not found.');
    }
}

function hc_function_enabled(string $name): bool
{
    if (!function_exists($name)) {
        return false;
    }
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

    return !in_array($name, $disabled, true);
}

function hc_run(string $command): ?string
{
    if (!hc_function_enabled('shell_exec')) {
        return null;
    }
    $out = @shell_exec($command . ' 2>&1');
    if (!is_string($out)) {
        return null;
    }
    $out = trim($out);

    return $out === '' ? null : substr($out, 0, 300);
}

function hc_binaries(): array
{
    $result = [];
    foreach (PROBE_BINARIES as $bin) {
        $path = hc_run('command -v ' . escapeshellarg($bin));
        $version = null;
        if ($path !== null && $path[0] === '/') {
            $flag = $bin === 'openssl' ? 'version' : '--version';
            if ($bin !== 'crontab') {
                $version = strtok((string) hc_run(escapeshellarg($path) . ' ' . $flag), "\n") ?: null;
            }
        } else {
            $path = null;
        }
        $result[$bin] = ['path' => $path, 'version' => $version];
    }

    // cPanel / CloudLinux keep several PHP CLI binaries; the cron job needs the exact path.
    $candidates = array_merge(
        glob('/opt/cpanel/ea-php*/root/usr/bin/php') ?: [],
        glob('/opt/alt/php*/usr/bin/php') ?: [],
        array_filter(['/usr/local/bin/php', '/usr/bin/php'], 'is_file')
    );
    $result['php_cli_candidates'] = array_values(array_unique($candidates));

    return $result;
}

function hc_outbound(): array
{
    $result = [];
    foreach (OUTBOUND_URLS as $name => $url) {
        if (getenv('HOSTCHECK_SKIP_OUTBOUND') === '1') {
            $result[$name] = ['ok' => true, 'skipped' => true];
            continue;
        }
        if (!function_exists('curl_init')) {
            $result[$name] = ['ok' => false, 'error' => 'curl extension missing'];
            continue;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_USERAGENT => 'hostcheck/' . HOSTCHECK_VERSION,
        ]);
        $start = microtime(true);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        $result[$name] = [
            'ok' => $status > 0,
            'http_status' => $status,
            'ms' => (int) round((microtime(true) - $start) * 1000),
            'error' => $error !== '' ? $error : null,
        ];
    }

    return $result;
}

function hc_filesystem(): array
{
    $home = getenv('HOME') ?: null;
    if ($home === null && preg_match('#^(/home\d*/[^/]+)#', __DIR__, $m)) {
        $home = $m[1];
    }

    $tmp = sys_get_temp_dir();
    $probeDir = $tmp . '/hostcheck_' . bin2hex(random_bytes(4));
    $canWriteTmp = @mkdir($probeDir, 0700);
    $symlinkOk = false;
    if ($canWriteTmp) {
        @file_put_contents($probeDir . '/target', 'x');
        $symlinkOk = hc_function_enabled('symlink') && @symlink($probeDir . '/target', $probeDir . '/link') && is_link($probeDir . '/link');
        @unlink($probeDir . '/link');
        @unlink($probeDir . '/target');
        @rmdir($probeDir);
    }

    $parentOfDocroot = dirname($_SERVER['DOCUMENT_ROOT'] ?? __DIR__);

    return [
        'script_dir' => __DIR__,
        'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? null,
        'home' => $home,
        'home_writable' => $home !== null && is_writable($home),
        'parent_of_docroot_writable' => is_writable($parentOfDocroot),
        'tmp_dir' => $tmp,
        'tmp_writable' => $canWriteTmp,
        'symlink_works' => $symlinkOk,
        'disk_free_mb' => ($free = @disk_free_space($home ?? __DIR__)) !== false ? (int) ($free / 1048576) : null,
    ];
}

function hc_collect(): array
{
    $loaded = array_map('strtolower', get_loaded_extensions());
    sort($loaded);
    $ext = static fn (array $names): array => array_combine($names, array_map(static fn ($n) => in_array($n, $loaded, true), $names));

    $gd = function_exists('gd_info') ? gd_info() : [];
    $ini = [];
    foreach (INI_KEYS as $key) {
        $value = ini_get($key);
        $ini[$key] = $value === false ? null : $value;
    }

    $functions = [];
    foreach (['exec', 'shell_exec', 'proc_open', 'popen', 'symlink', 'putenv', 'set_time_limit', 'fsockopen'] as $fn) {
        $functions[$fn] = hc_function_enabled($fn);
    }

    return [
        'hostcheck_version' => HOSTCHECK_VERSION,
        'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'php' => [
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'binary' => PHP_BINARY,
            'int_size' => PHP_INT_SIZE,
            'ini_file' => php_ini_loaded_file() ?: null,
            'argon2id' => defined('PASSWORD_ARGON2ID'),
        ],
        'extensions' => [
            'required' => $ext(REQUIRED_EXTENSIONS),
            'image' => $ext(IMAGE_EXTENSIONS),
            'optional' => $ext(OPTIONAL_EXTENSIONS),
            'pdo_drivers' => class_exists('PDO') ? PDO::getAvailableDrivers() : [],
            'gd_jpeg' => (bool) ($gd['JPEG Support'] ?? false),
            'gd_webp' => (bool) ($gd['WebP Support'] ?? false),
            'all_loaded' => $loaded,
        ],
        'ini' => $ini,
        'functions' => $functions,
        'timezone_asia_tashkent' => in_array('Asia/Tashkent', timezone_identifiers_list(), true),
        'server' => [
            'software' => $_SERVER['SERVER_SOFTWARE'] ?? null,
            'protocol' => $_SERVER['SERVER_PROTOCOL'] ?? null,
            'https' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
            'os' => php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('m'),
            'cpu_count' => is_readable('/proc/cpuinfo') ? substr_count((string) @file_get_contents('/proc/cpuinfo'), 'processor') : null,
        ],
        'filesystem' => hc_filesystem(),
        'binaries' => hc_binaries(),
        'outbound_https' => hc_outbound(),
    ];
}

function hc_verdict(array $r): array
{
    $problems = [];
    if (version_compare($r['php']['version'], '8.2.0', '<')) {
        $problems[] = 'PHP < 8.2 (Laravel 11 needs 8.2+). Select a newer version in cPanel → Select PHP Version.';
    }
    foreach ($r['extensions']['required'] as $name => $ok) {
        if (!$ok) {
            $problems[] = "Missing extension: {$name}";
        }
    }
    if (!in_array(true, $r['extensions']['image'], true)) {
        $problems[] = 'No image extension (gd or imagick) — needed to re-encode photos.';
    }
    if (!$r['outbound_https']['telegram']['ok']) {
        $problems[] = 'Cannot reach api.telegram.org — Telegram reports would not work.';
    }
    if (!$r['filesystem']['parent_of_docroot_writable'] && !$r['filesystem']['home_writable']) {
        $problems[] = 'Cannot write outside the document root — private photo storage needs a non-public folder.';
    }

    return $problems;
}

function hc_html(array $report, array $problems): string
{
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $e = static fn ($v): string => htmlspecialchars(match (true) {
        $v === null => '-',
        is_bool($v) => $v ? 'yes' : 'no',
        is_scalar($v) => (string) $v,
        default => (string) json_encode($v),
    }, ENT_QUOTES, 'UTF-8');
    $rows = '';
    $missing = array_keys(array_filter($report['extensions']['required'], static fn ($ok) => !$ok));
    $bin = static fn (string $b): string => $report['binaries'][$b]['path'] !== null
        ? $report['binaries'][$b]['path'] . ' ' . ($report['binaries'][$b]['version'] ?? '')
        : 'no';
    $net = static fn (array $o): string => !empty($o['skipped']) ? 'skipped' : (($o['ok'] ? 'OK ' : 'FAIL ') . ($o['http_status'] ?? '') . ' ' . ($o['error'] ?? ''));
    $flat = [
        'PHP' => $report['php']['version'] . ' (' . $report['php']['sapi'] . ')',
        'Missing extensions' => $missing === [] ? 'none' : implode(', ', $missing),
        'gd / imagick' => ($report['extensions']['image']['gd'] ? 'gd ' : '') . ($report['extensions']['image']['imagick'] ? 'imagick' : ''),
        'argon2id' => $report['php']['argon2id'],
        'PDO drivers' => implode(', ', $report['extensions']['pdo_drivers']),
        'memory_limit' => $report['ini']['memory_limit'],
        'max_execution_time' => $report['ini']['max_execution_time'],
        'upload_max_filesize' => $report['ini']['upload_max_filesize'],
        'post_max_size' => $report['ini']['post_max_size'],
        'disable_functions' => $report['ini']['disable_functions'],
        'shell_exec / proc_open' => ($report['functions']['shell_exec'] ? 'yes' : 'no') . ' / ' . ($report['functions']['proc_open'] ? 'yes' : 'no'),
        'symlink' => $report['filesystem']['symlink_works'],
        'Write outside docroot' => $report['filesystem']['parent_of_docroot_writable'] || $report['filesystem']['home_writable'],
        'Home' => $report['filesystem']['home'],
        'Document root' => $report['filesystem']['document_root'],
        'Disk free MB' => $report['filesystem']['disk_free_mb'],
        'PHP CLI (cron)' => implode(' | ', $report['binaries']['php_cli_candidates']),
        'php in PATH' => $bin('php'),
        'mysql' => $bin('mysql'),
        'mysqldump' => $bin('mysqldump'),
        'git' => $bin('git'),
        'node' => $bin('node'),
        'python3' => $bin('python3'),
        'Telegram API' => $net($report['outbound_https']['telegram']),
        'GitHub API' => $net($report['outbound_https']['github']),
        'HTTPS' => $report['server']['https'],
        'Server' => $report['server']['software'],
        'OS' => $report['server']['os'],
    ];
    foreach ($flat as $k => $v) {
        $rows .= '<tr><th>' . htmlspecialchars($k, ENT_QUOTES, 'UTF-8') . '</th><td>' . $e($v) . '</td></tr>';
    }
    $problemHtml = $problems === []
        ? '<p class="ok">Asosiy talablar bajarilgan / Core requirements met.</p>'
        : '<ul class="bad"><li>' . implode('</li><li>', array_map(static fn ($p) => htmlspecialchars($p, ENT_QUOTES, 'UTF-8'), $problems)) . '</li></ul>';

    return '<!doctype html><html lang="uz"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1"><title>Hosting check</title>'
        . '<style>body{font:15px/1.4 system-ui,sans-serif;margin:16px;max-width:760px}table{border-collapse:collapse;width:100%}'
        . 'th,td{border:1px solid #ccc;padding:6px;text-align:left;word-break:break-all}textarea{width:100%;height:320px;font:12px monospace}'
        . '.ok{color:#0a7a2f}.bad{color:#b00020}.note{background:#fff4ce;padding:8px}</style></head><body>'
        . '<h1>Hosting check</h1>'
        . '<p class="note"><b>Diqqat:</b> bu sahifa faqat bir marta ochiladi — fayl o\'zini o\'chirdi. '
        . 'Butun sahifani skrinshot qiling (uzun skrinshot yoki bir nechta) va Claude\'ga yuboring.</p>'
        . $problemHtml . '<table>' . $rows . '</table>'
        . '<details><summary>JSON (ixtiyoriy)</summary><textarea readonly>' . htmlspecialchars((string) $json, ENT_QUOTES, 'UTF-8') . '</textarea></details>'
        . '</body></html>';
}

hc_authorize();
register_shutdown_function('hc_self_delete');

$report = hc_collect();
$problems = hc_verdict($report);
$report['problems'] = $problems;

if (hc_is_cli()) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    exit(0);
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
echo hc_html($report, $problems);
