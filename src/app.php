<?php
declare(strict_types=1);

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/documents.php';

function roapp_env_file_path(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
}

/**
 * Load only ROAPP_PASSWORD from the app-root .env file. This intentionally does
 * not evaluate PHP, expand variables, or parse arbitrary dotenv directives.
 */
function roapp_load_env_password(?string $path = null): bool
{
    $configured = getenv('ROAPP_PASSWORD');
    if ($configured !== false && $configured !== '') {
        return true;
    }

    $path ??= roapp_env_file_path();
    if (!is_file($path) || !is_readable($path)) {
        return false;
    }
    $contents = @file_get_contents($path);
    if (!is_string($contents) || strlen($contents) > 16384) {
        return false;
    }

    $password = null;
    $lines = preg_split('/\r\n|\n|\r/', $contents);
    if (!is_array($lines)) {
        return false;
    }
    foreach ($lines as $index => $line) {
        if ($index === 0) {
            $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;
        }
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!preg_match('/^ROAPP_PASSWORD\s*=\s*(.*)$/D', $line, $matches)) {
            if (preg_match('/^ROAPP_PASSWORD(?:\s|$)/', $line)) {
                return false;
            }
            continue;
        }
        if ($password !== null) {
            return false;
        }

        $value = trim($matches[1]);
        if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
            $quote = $value[0];
            if (strlen($value) < 2 || substr($value, -1) !== $quote) {
                return false;
            }
            $value = substr($value, 1, -1);
        } elseif ($value !== '' && ($value[0] === '"' || $value[0] === "'" || str_ends_with($value, '"') || str_ends_with($value, "'"))) {
            return false;
        }

        if ($value === '' || strlen($value) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            return false;
        }
        $password = $value;
    }

    if ($password === null || !putenv('ROAPP_PASSWORD=' . $password)) {
        return false;
    }
    $_ENV['ROAPP_PASSWORD'] = $password;
    $_SERVER['ROAPP_PASSWORD'] = $password;
    return true;
}

function roapp_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('roapp_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    if (!isset($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

function roapp_is_loopback(): bool
{
    // Trust only the connected peer address supplied by the web server, never
    // client-controlled forwarding headers. If a proxy-style header is present,
    // conservatively remove local write privileges rather than interpreting it.
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_FORWARDED', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP'] as $header) {
        if (isset($_SERVER[$header]) && trim((string) $_SERVER[$header]) !== '') {
            return false;
        }
    }
    return in_array($ip, ['127.0.0.1', '::1'], true);
}

function roapp_read_only_denial_status(bool $readOnly, string $method, array $query): int
{
    if (!$readOnly) {
        return 0;
    }
    if (!in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
        return 405;
    }
    if (array_key_exists('settings', $query) || array_key_exists('new', $query)) {
        return 403;
    }
    return 0;
}

function roapp_enforce_read_only_access(bool $readOnly): void
{
    $status = roapp_read_only_denial_status(
        $readOnly,
        (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        $_GET
    );
    if ($status === 0) {
        return;
    }
    http_response_code($status);
    if ($status === 405) {
        header('Allow: GET, HEAD');
    }
    header('Content-Type: text/html; charset=utf-8');
    $title = $status === 405 ? 'Acces doar pentru vizualizare și tipărire' : 'Acces indisponibil';
    $message = $status === 405
        ? 'Accesul din rețea permite numai vizualizarea și tipărirea. Operațiile de modificare nu sunt permise.'
        : 'Această pagină este disponibilă numai pentru administrarea locală.';
    echo '<!doctype html><html lang="ro"><meta charset="utf-8"><title>' . roapp_safe_text($title) . '</title><body><h1>' . roapp_safe_text($title) . '</h1><p>' . roapp_safe_text($message) . '</p></body></html>';
    exit;
}

function roapp_csrf_input(): string
{
    return '<input type="hidden" name="csrf" value="' . roapp_safe_text((string) $_SESSION['csrf']) . '">';
}

function roapp_check_csrf(): void
{
    $received = (string) ($_POST['csrf'] ?? '');
    if ($received === '' || !hash_equals((string) $_SESSION['csrf'], $received)) {
        throw new RuntimeException('Sesiunea formularului a expirat sau tokenul de securitate nu este valid. Reîncărcați pagina și încercați din nou.');
    }
}

function roapp_redirect(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

function roapp_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function roapp_take_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

function roapp_login_page(string $error = ''): never
{
    http_response_code($error === '' ? 200 : 401);
    $token = roapp_csrf_input();
    echo '<!doctype html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Autentificare roAdmin</title><link rel="stylesheet" href="app.css"></head><body class="login-page"><main class="login-card"><div class="brand-mark">BV</div><p class="eyebrow">DOCUMENTE ADMINISTRATIVE</p><h1>Acces la roAdmin</h1><p class="muted">Introduceți parola configurată în fișierul <code>.env</code> sau în variabila de mediu <code>ROAPP_PASSWORD</code>.</p>' . ($error !== '' ? '<div class="alert error">' . roapp_safe_text($error) . '</div>' : '') . '<form method="post"><input type="hidden" name="action" value="login">' . $token . '<label for="password">Parolă</label><input id="password" type="password" name="password" autocomplete="current-password" required autofocus><button class="button primary full" type="submit">Autentificare</button></form></main></body></html>';
    exit;
}

function roapp_require_access(): void
{
    roapp_load_env_password();
    $configured = getenv('ROAPP_PASSWORD');
    $password = $configured === false ? '' : (string) $configured;
    if ($password === '' && !roapp_is_loopback()) {
        http_response_code(403);
        echo '<!doctype html><html lang="ro"><meta charset="utf-8"><title>Acces refuzat</title><body><h1>Acces refuzat</h1><p>Fără ROAPP_PASSWORD, aplicația acceptă numai conexiuni locale. Folosiți 127.0.0.1 sau configurați autentificarea înainte de accesul din rețea.</p></body></html>';
        exit;
    }
    if ($password !== '' && empty($_SESSION['authenticated'])) {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'login') {
            roapp_check_csrf();
            if (hash_equals($password, (string) ($_POST['password'] ?? ''))) {
                session_regenerate_id(true);
                $_SESSION['authenticated'] = true;
                roapp_redirect('./');
            }
            roapp_login_page('Parola nu este corectă.');
        }
        roapp_login_page();
    }
}

function roapp_make_backup_json(array $store): string
{
    return json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function roapp_send_json_backup(array $store): never
{
    $json = roapp_make_backup_json($store);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="roadmin-backup-' . date('Ymd-His') . '.json"');
    header('Content-Length: ' . strlen($json));
    echo $json;
    exit;
}

function roapp_send_zip_backup(array $store): never
{
    if (!class_exists('ZipArchive')) {
        roapp_send_json_backup($store);
    }
    roapp_ensure_directories();
    $tmp = roapp_data_dir() . DIRECTORY_SEPARATOR . 'backup-' . bin2hex(random_bytes(8)) . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Nu s-a putut crea copia ZIP. Folosiți exportul JSON sau verificați spațiul de stocare.');
    }
    try {
        $zip->addFromString('database.json', roapp_make_backup_json($store));
        $zip->addFromString('BACKUP.txt', "Backup roAdmin. Baza este database.json; PDF-urile/DOCX-urile generate sunt în generated/. Fișierul ZIP poate fi restaurat din interfața aplicației.\n");
        $generated = roapp_generated_dir();
        if (is_dir($generated)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($generated, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $extension = strtolower($file->getExtension());
                if (!in_array($extension, ['pdf', 'docx'], true)) {
                    continue;
                }
                $name = $file->getFilename();
                if (!preg_match('/^[A-Za-z0-9_.-]{1,180}$/', $name)) {
                    continue;
                }
                $zip->addFile($file->getPathname(), 'generated/' . $name);
            }
        }
        if (!$zip->close()) {
            throw new RuntimeException('Arhiva ZIP nu s-a putut finaliza.');
        }
    } catch (Throwable $error) {
        @$zip->close();
        @unlink($tmp);
        throw $error;
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="roadmin-backup-' . date('Ymd-His') . '.zip"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
}

function roapp_decode_restore_upload(array $upload): array
{
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($upload['tmp_name'] ?? ''))) {
        throw new RuntimeException('Încărcarea copiei de siguranță a eșuat. Selectați un fișier JSON sau ZIP valid.');
    }
    $size = (int) ($upload['size'] ?? 0);
    if ($size < 2 || $size > 100 * 1024 * 1024) {
        throw new RuntimeException('Copia de siguranță trebuie să aibă între 2 octeți și 100 MB.');
    }
    $tmp = (string) $upload['tmp_name'];
    $name = strtolower((string) ($upload['name'] ?? ''));
    $generatedFiles = [];
    if (str_ends_with($name, '.zip')) {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Restaurarea ZIP necesită extensia PHP zip. Puteți restaura un export JSON.');
        }
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('Fișierul ZIP nu este valid.');
        }
        try {
            $json = $zip->getFromName('database.json');
            if (!is_string($json) || strlen($json) > 100 * 1024 * 1024) {
                throw new RuntimeException('Arhiva ZIP nu conține database.json valid.');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $entry = is_array($stat) ? (string) ($stat['name'] ?? '') : '';
                if (!preg_match('#^generated/([A-Za-z0-9_.-]{1,180}\.(?:pdf|docx))$#i', $entry, $match)) {
                    continue;
                }
                if ((int) ($stat['size'] ?? 0) > 25 * 1024 * 1024) {
                    continue;
                }
                $bytes = $zip->getFromIndex($i);
                if (is_string($bytes)) {
                    $generatedFiles[$match[1]] = $bytes;
                }
            }
        } finally {
            $zip->close();
        }
    } else {
        $json = @file_get_contents($tmp);
        if (!is_string($json) || strlen($json) > 100 * 1024 * 1024) {
            throw new RuntimeException('Fișierul JSON nu a putut fi citit.');
        }
    }
    $store = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Copia JSON nu este validă: ' . json_last_error_msg());
    }
    $errors = roapp_validate_store($store);
    if ($errors !== []) {
        throw new RuntimeException('Copia nu este compatibilă: ' . implode(' ', array_slice($errors, 0, 4)));
    }
    return ['store' => $store, 'generated' => $generatedFiles];
}

function roapp_save_pre_restore_backup(array $store): void
{
    roapp_ensure_directories();
    $path = roapp_data_dir() . DIRECTORY_SEPARATOR . 'pre-restore-' . date('Ymd-His') . '.json';
    file_put_contents($path, roapp_make_backup_json($store), LOCK_EX);
}

function roapp_html_header(string $title, string $module = 'roDisp', bool $settings = false, bool $readOnly = false): string
{
    $dispActive = $module === 'roDisp' && !$settings ? ' aria-current="page"' : '';
    $hotActive = $module === 'roHot' && !$settings ? ' aria-current="page"' : '';
    $settingsActive = $settings ? ' aria-current="page"' : '';
    $settingsLink = $readOnly ? '' : '<a href="?settings=1"' . $settingsActive . '>Setări & backup</a>';
    $status = $readOnly ? 'Rețea · doar vizualizare și tipărire' : 'Local · un utilizator';
    return '<!doctype html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><title>' . roapp_safe_text($title) . ' · roAdmin</title><link rel="stylesheet" href="app.css"></head><body><a class="skip-link" href="#main">Sari la conținut</a><header class="topbar"><a class="brand" href="./"><span class="brand-mark">BV</span><span><strong>roAdmin</strong><small>Registru local de acte</small></span></a><nav class="main-nav" aria-label="Module"><a href="?module=roDisp"' . $dispActive . '>Dispoziții</a><a href="?module=roHot"' . $hotActive . '>Hotărâri</a>' . $settingsLink . '</nav><div class="top-meta"><span class="status-dot"></span>' . roapp_safe_text($status) . '</div></header><main id="main" class="app-shell">';
}

function roapp_html_footer(): string
{
    return '</main><footer class="app-footer"><span>roAdmin MVP · date păstrate local pe acest server</span><span>PDF Word: Windows + Microsoft Word · fallback: browser</span></footer></body></html>';
}

function roapp_alert_html(?array $flash, string $error = ''): string
{
    if ($error !== '') {
        return '<div class="alert error" role="alert"><strong>Acțiunea nu a fost finalizată.</strong> ' . roapp_safe_text($error) . '</div>';
    }
    if (!$flash) {
        return '';
    }
    $type = ($flash['type'] ?? '') === 'success' ? 'success' : 'info';
    return '<div class="alert ' . $type . '" role="status">' . roapp_safe_text((string) ($flash['message'] ?? '')) . '</div>';
}

function roapp_contains_ci(string $haystack, string $needle): bool
{
    if (function_exists('mb_stripos')) {
        return mb_stripos($haystack, $needle, 0, 'UTF-8') !== false;
    }
    return stripos($haystack, $needle) !== false;
}

function roapp_record_title(string $module, array $record): string
{
    $idx = $module === 'roDisp' ? 2 : 5;
    $title = trim((string) ($record['values'][$idx] ?? ''));
    return $title !== '' ? $title : '(fără titlu)';
}

function roapp_record_number(string $module, array $record): string
{
    $v = $record['values'];
    if ($module === 'roDisp') {
        return trim((string) ($v[0] ?? ''));
    }
    $number = trim((string) ($v[25] ?? ''));
    return $number !== '' && $number !== '0' ? $number : 'proiect ' . trim((string) ($v[3] ?? ''));
}

function roapp_record_date(string $module, array $record): string
{
    return trim((string) ($record['values'][$module === 'roDisp' ? 1 : 0] ?? ''));
}

function roapp_normalize_act_type(string $type): string
{
    $type = function_exists('mb_strtolower') ? mb_strtolower(trim($type), 'UTF-8') : strtolower(trim($type));
    $type = strtr($type, ['Ă' => 'a', 'ă' => 'a', 'Â' => 'a', 'â' => 'a', 'Î' => 'i', 'î' => 'i', 'Ș' => 's', 'ș' => 's', 'Ş' => 's', 'ş' => 's', 'Ț' => 't', 'ț' => 't', 'Ţ' => 't', 'ţ' => 't']);
    return preg_replace('/\s+/u', ' ', $type) ?? $type;
}

function roapp_readonly_document_options(string $module, array $record): array
{
    $options = $module === 'roDisp'
        ? ['dispoN' => 'Dispoziție', 'dispoN_pr' => 'Proiect dispoziție', 'dispoRef' => 'Referat de specialitate']
        : ['hotN' => 'Hotărâre', 'hotN_pr' => 'Proiect hotărâre', 'hotRef' => 'Referat de specialitate'];
    $typeIndex = $module === 'roDisp' ? 6 : 10;
    $type = roapp_normalize_act_type((string) ($record['values'][$typeIndex] ?? ''));
    if ($type === 'individual') {
        return [];
    }
    if ($type === 'respinsa') {
        unset($options[$module === 'roDisp' ? 'dispoN' : 'hotN']);
    }
    return $options;
}

function roapp_readonly_action_allowed(string $module, array $record, string $action, string $document = ''): bool
{
    $typeIndex = $module === 'roDisp' ? 6 : 10;
    $type = roapp_normalize_act_type((string) ($record['values'][$typeIndex] ?? ''));
    $individual = $type === 'individual';
    $rejected = $type === 'respinsa';
    return match ($action) {
        'view', 'final' => !$individual && !$rejected,
        'project' => !$individual,
        'document' => array_key_exists($document, roapp_readonly_document_options($module, $record)),
        default => false,
    };
}

function roapp_filter_records(array $records, string $query): array
{
    if ($query === '') {
        return $records;
    }
    return array_values(array_filter($records, static function (array $record) use ($query): bool {
        $haystack = implode("\n", array_map(static fn($v): string => is_string($v) ? roapp_display_text($v) : '', $record['values'] ?? []));
        return roapp_contains_ci($haystack, $query);
    }));
}

function roapp_render_editor(string $module, array $record, bool $isNew, string $csrf, string $error = ''): string
{
    $definitions = roapp_field_definitions()[$module];
    $groups = roapp_field_groups($module);
    $html = '<section class="panel editor-panel"><div class="panel-heading"><div><p class="eyebrow">' . ($isNew ? 'ÎNREGISTRARE NOUĂ' : 'EDITARE ÎNREGISTRARE') . '</p><h2>' . roapp_safe_text($isNew ? 'Adaugă un act' : roapp_record_title($module, $record)) . '</h2><p class="muted">Valorile istorice sunt păstrate în ordinea câmpurilor din fișierul sursă; câmpurile codificate sunt marcate explicit.</p></div><a class="button quiet" href="?module=' . $module . '">Închide</a></div>';
    if ($error !== '') {
        $html .= '<div class="alert error" role="alert">' . roapp_safe_text($error) . '</div>';
    }
    $html .= '<form method="post" class="record-form"><input type="hidden" name="action" value="save"><input type="hidden" name="module" value="' . $module . '"><input type="hidden" name="id" value="' . roapp_safe_text((string) $record['id']) . '"><input type="hidden" name="csrf" value="' . roapp_safe_text($csrf) . '">';
    foreach ($groups as $groupName => $indices) {
        $html .= '<fieldset class="field-group"><legend>' . roapp_safe_text($groupName) . '</legend><div class="field-grid">';
        foreach ($indices as $index) {
            $definition = $definitions[$index];
            $id = 'field-' . $module . '-' . $index;
            $value = roapp_normalize_value_for_form((string) ($record['values'][$index] ?? ''));
            $large = !empty($definition['large']) || strlen($value) > 140;
            $required = $isNew && in_array($index, $module === 'roDisp' ? [0, 1, 2] : [0, 3, 4, 5], true);
            $html .= '<div class="field' . ($large ? ' field-wide' : '') . '"><label for="' . $id . '">' . roapp_safe_text($definition['label']) . ($required ? ' <span class="required">*</span>' : '') . '</label>';
            if ($large) {
                $html .= '<textarea id="' . $id . '" name="values[' . $index . ']" rows="' . (strlen($value) > 500 ? '7' : '4') . '" maxlength="100000"' . ($required ? ' required' : '') . '>' . roapp_safe_text($value) . '</textarea>';
            } else {
                $html .= '<input id="' . $id . '" name="values[' . $index . ']" value="' . roapp_safe_text($value) . '" maxlength="100000"' . ($required ? ' required' : '') . '>';
            }
            $html .= '</div>';
        }
        $html .= '</div></fieldset>';
    }
    $html .= '<div class="form-actions"><button class="button primary" type="submit">Salvează înregistrarea</button><a class="button quiet" href="?module=' . $module . '">Renunță</a></div></form>';
    if (!$isNew) {
        $html .= '<section class="document-actions"><div><p class="eyebrow">DOCUMENTE</p><h3>Generează pentru verificare</h3><p class="muted">Tipărirea browserului este disponibilă imediat. Randarea Word folosește DOCX-ul original, dacă PHP rulează pe Windows cu extensiile zip/dom și Microsoft Word instalat.</p></div><form method="get" class="print-form"><input type="hidden" name="module" value="' . $module . '"><input type="hidden" name="id" value="' . roapp_safe_text((string) $record['id']) . '"><input type="hidden" name="print" value="1"><label for="doc-type">Tip document</label><select id="doc-type" name="doc" required>';
        foreach (roapp_document_options($module) as $key => $label) {
            $html .= '<option value="' . roapp_safe_text($key) . '">' . roapp_safe_text($label) . '</option>';
        }
        $html .= '</select><button class="button secondary" type="submit" formtarget="_blank" formaction="./">Deschide versiunea de tipărire</button></form><form method="post" class="word-form"><input type="hidden" name="action" value="word_pdf"><input type="hidden" name="module" value="' . $module . '"><input type="hidden" name="id" value="' . roapp_safe_text((string) $record['id']) . '"><input type="hidden" name="csrf" value="' . roapp_safe_text($csrf) . '"><label for="word-template">Template Word original</label><select id="word-template" name="template">';
        foreach (roapp_document_options($module) as $key => $label) {
            $html .= '<option value="' . roapp_safe_text($key) . '">' . roapp_safe_text($label) . '</option>';
        }
        $html .= '</select><button class="button primary" type="submit">Generează PDF cu Word</button></form></section>';
    }
    $html .= '</section>';
    return $html;
}

function roapp_render_readonly_record(string $module, array $record): string
{
    $definitions = roapp_field_definitions()[$module];
    $groups = roapp_field_groups($module);
    $id = (string) ($record['id'] ?? '');
    $html = '<section class="panel editor-panel"><div class="panel-heading"><div><p class="eyebrow">VIZUALIZARE · FĂRĂ MODIFICARE</p><h2>' . roapp_safe_text(roapp_record_title($module, $record)) . '</h2><p class="muted">Accesul din rețea permite numai consultarea înregistrării și deschiderea documentelor pentru tipărire.</p></div><a class="button quiet" href="?module=' . $module . '">Înapoi la registru</a></div>';
    foreach ($groups as $groupName => $indices) {
        $html .= '<fieldset class="field-group readonly-group"><legend>' . roapp_safe_text($groupName) . '</legend><dl class="readonly-fields">';
        foreach ($indices as $index) {
            $value = roapp_display_text((string) ($record['values'][$index] ?? ''));
            $html .= '<div class="readonly-field"><dt>' . roapp_safe_text($definitions[$index]['label']) . '</dt><dd>' . ($value !== '' ? nl2br(roapp_safe_text($value), false) : '<span class="muted">—</span>') . '</dd></div>';
        }
        $html .= '</dl></fieldset>';
    }
    $html .= '<section class="document-actions readonly-documents"><div><p class="eyebrow">TIPĂRIRE</p><h3>Deschideți documentul</h3><p class="muted">Se folosește randarea HTML pentru tipărire din browser; generarea Word pe server nu este disponibilă din accesul de rețea.</p></div><div class="readonly-document-links">';
    foreach (roapp_readonly_document_options($module, $record) as $key => $label) {
        $url = '?print=1&amp;module=' . rawurlencode($module) . '&amp;id=' . rawurlencode($id) . '&amp;doc=' . rawurlencode($key);
        $html .= '<a class="button secondary" target="_blank" rel="noopener" href="' . $url . '">' . roapp_safe_text($label) . '</a>';
    }
    $html .= '</div></section></section>';
    return $html;
}

function roapp_settings_list_text(array $items, bool $pairs = false): string
{
    $lines = [];
    foreach ($items as $item) {
        if ($pairs && is_array($item)) {
            $lines[] = trim((string) ($item['label'] ?? '')) . '|' . trim((string) ($item['name'] ?? ''));
        } elseif (is_string($item)) {
            $lines[] = $item;
        }
    }
    return implode("\n", $lines);
}

function roapp_parse_settings_lines(mixed $raw, bool $pairs = false): array
{
    if (!is_string($raw) || strlen($raw) > 100000) {
        throw new RuntimeException('O listă de configurare este invalidă sau prea lungă.');
    }
    $items = [];
    foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $lineNumber => $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (strlen($line) > 2000) {
            throw new RuntimeException('Un element din listă depășește 2.000 de octeți (linia ' . ($lineNumber + 1) . ').');
        }
        if ($pairs) {
            $parts = explode('|', $line, 2);
            if (count($parts) !== 2 || trim($parts[0]) === '' || trim($parts[1]) === '') {
                throw new RuntimeException('Folosiți formatul „etichetă|nume” pentru fiecare element al listei.');
            }
            $items[] = ['label' => trim($parts[0]), 'name' => trim($parts[1])];
        } else {
            $items[] = $line;
        }
        if (count($items) > 1000) {
            throw new RuntimeException('O listă de configurare nu poate avea mai mult de 1.000 de elemente.');
        }
    }
    return $items;
}

function roapp_parse_commission_members(mixed $raw): array
{
    if (!is_string($raw) || strlen($raw) > 100000) {
        throw new RuntimeException('Lista membrilor comisiilor este invalidă sau prea lungă.');
    }
    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        throw new RuntimeException('JSON-ul membrilor comisiilor nu este valid.');
    }
    foreach ($decoded as $commission => $members) {
        if (!is_string($commission) || strlen($commission) > 2000 || !is_array($members)) {
            throw new RuntimeException('Structura membrilor comisiilor trebuie să fie obiect comisie → listă de nume.');
        }
        foreach ($members as $member) {
            if (!is_string($member) || trim($member) === '' || strlen($member) > 500) {
                throw new RuntimeException('Fiecare membru al comisiei trebuie să fie un nume text valid.');
            }
        }
    }
    return $decoded;
}

function roapp_render_settings(array $store, string $csrf): string
{
    $defaults = roapp_default_settings();
    $settings = array_merge($defaults, $store['settings']);
    $settings['institution'] = array_merge($defaults['institution'] ?? [], $store['settings']['institution'] ?? []);
    $institution = $settings['institution'] ?? [];
    $html = '<section class="settings-grid"><article class="panel"><div class="panel-heading"><div><p class="eyebrow">CONFIGURARE INSTITUȚIE</p><h2>Setări documente</h2><p class="muted">Aceste valori completează controalele comune din template-urile Word și din randarea pentru tipărire.</p></div></div><form method="post" class="settings-form"><input type="hidden" name="action" value="settings"><input type="hidden" name="csrf" value="' . roapp_safe_text($csrf) . '">';
    $fields = [
        'name' => 'Denumirea instituției', 'county' => 'Județ', 'location' => 'Localitate', 'mayor' => 'Primar', 'secretary' => 'Secretar general', 'monitorUrl' => 'URL monitor oficial local',
    ];
    foreach ($fields as $key => $label) {
        $value = (string) ($institution[$key] ?? '');
        $html .= '<div class="field"><label for="setting-' . $key . '">' . roapp_safe_text($label) . '</label><input id="setting-' . $key . '" name="institution[' . $key . ']" value="' . roapp_safe_text($value) . '" maxlength="1000"></div>';
    }
    $html .= '<hr><h3>Liste folosite la completarea actelor</h3><p class="small-note">Păstrați ordinea listelor. Unele înregistrări istorice conțin indici spre aceste liste; ștergerea sau reordonarea poate schimba interpretarea lor. Adăugați elemente la final când este posibil.</p>';
    $listFields = [
        'initiators' => ['Inițiatori (format: etichetă|nume)', true],
        'departments' => ['Compartimente (format: etichetă|nume)', true],
        'reporters' => ['Raportori (format: etichetă|nume)', true],
        'commissions' => ['Comisii (formatul original din config.ini)', false],
        'councilors' => ['Consilieri', false],
        'emails' => ['Adrese email (una pe linie)', false],
    ];
    foreach ($listFields as $key => [$label, $pairs]) {
        $items = $settings[$key] ?? [];
        if (!is_array($items)) {
            $items = [];
        }
        $html .= '<div class="field field-wide"><label for="list-' . $key . '">' . roapp_safe_text($label) . '</label><textarea id="list-' . $key . '" name="lists[' . $key . ']" rows="' . ($key === 'commissions' ? '5' : '4') . '" spellcheck="false">' . roapp_safe_text(roapp_settings_list_text($items, $pairs)) . '</textarea></div>';
    }
    $membersJson = json_encode($settings['commission_members'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $html .= '<div class="field field-wide"><label for="commission-members">Membrii comisiilor (JSON: numele comisiei → listă de consilieri)</label><textarea id="commission-members" name="commission_members" rows="8" spellcheck="false">' . roapp_safe_text(is_string($membersJson) ? $membersJson : '{}') . '</textarea></div>';
    $html .= '<button class="button primary" type="submit">Salvează setările și listele</button></form></article><article class="panel"><p class="eyebrow">SURSĂ DE DATE</p><h2>Import și proveniență</h2>';
    $source = $store['source'] ?? null;
    if (is_array($source)) {
        $counts = $source['counts'] ?? [];
        $html .= '<p>Import inițial: <strong>' . (int) ($counts['roDisp'] ?? 0) . '</strong> dispoziții și <strong>' . (int) ($counts['roHot'] ?? 0) . '</strong> hotărâri, din ' . roapp_safe_text((string) ($source['archive'] ?? 'v157.zip')) . '.</p><p class="muted">Timp import: ' . roapp_safe_text((string) ($source['imported_at'] ?? '')) . '</p>';
    } else {
        $html .= '<p>Datele inițiale nu au fost încă importate.</p>';
    }
    $html .= '<p class="muted">Fișierele TXT, configurația config.ini și template-urile DOCX originale sunt incluse în distribuție. Importul este explicit și nu rulează automat la pornire.</p><details><summary>Configurația originală config.ini (referință)</summary><p class="small-note">Sunt păstrate aici valorile generale, text, array și componența comisiilor din arhivă. Listele active pot fi editate în formularul alăturat.</p><pre class="config-json">' . roapp_safe_text(json_encode($settings['legacy_config'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}') . '</pre></details></article><article class="panel"><p class="eyebrow">BACKUP & RESTAURARE</p><h2>Copii de siguranță</h2><p>Backup-ul ZIP include baza locală și documentele PDF/DOCX generate. Dacă extensia PHP zip nu este disponibilă, aplicația oferă export JSON al datelor (fără fișierele generate).</p><form method="post"><input type="hidden" name="action" value="backup"><input type="hidden" name="csrf" value="' . roapp_safe_text($csrf) . '"><button class="button secondary" type="submit">Descarcă backup acum</button></form><hr><form method="post" enctype="multipart/form-data" class="restore-form" onsubmit="return confirm(\'Restaurarea înlocuiește baza de date curentă. Aplicația va salva o copie pre-restaurare înainte de înlocuire. Continuați?\')"><input type="hidden" name="action" value="restore"><input type="hidden" name="csrf" value="' . roapp_safe_text($csrf) . '"><label for="backup-file">Fișier backup JSON sau ZIP</label><input id="backup-file" type="file" name="backup" accept=".json,.zip,application/json,application/zip" required><label class="check-label"><input type="checkbox" name="confirm_restore" value="1" required> Confirm că restaurarea înlocuiește datele curente</label><button class="button danger" type="submit">Restaurează backup</button></form><p class="small-note">Înainte de restaurare se creează automat <code>pre-restore-*.json</code> în directorul privat de date. Copiile generate rămân în <code>var/generated</code>.</p></article></section>';
    return $html;
}

function roapp_render_dashboard(array $store, string $module, string $query, int $page, string $csrf, ?array $flash, string $error = '', ?array $formOverride = null, bool $readOnly = false): string
{
    $records = $store['records'][$module];
    $filtered = roapp_filter_records($records, $query);
    $total = count($filtered);
    $perPage = 25;
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min(max(1, $page), $pages);
    $visible = array_slice(array_reverse($filtered), ($page - 1) * $perPage, $perPage);
    $definitions = roapp_field_definitions()[$module];
    $html = roapp_html_header(roapp_module_label($module), $module, false, $readOnly) . roapp_alert_html($flash, $error);
    $introText = $readOnly ? 'Caută, consultă înregistrările și deschide documentele pentru tipărire.' : 'Caută, editează și pregătește documentul pentru tipărire sau randare Word.';
    $newAction = $readOnly ? '' : '<div class="intro-actions"><a class="button primary" href="?module=' . $module . '&new=1">＋ Înregistrare nouă</a></div>';
    $html .= '<section class="page-intro"><div><p class="eyebrow">REGISTRE · ' . ($module === 'roDisp' ? 'RODISP' : 'ROHOT') . '</p><h1>' . roapp_safe_text(roapp_module_label($module)) . '</h1><p class="muted">' . roapp_safe_text($introText) . '</p></div>' . $newAction . '</section>';
    $html .= '<section class="metrics"><article class="metric-card"><span>Înregistrări totale</span><strong>' . count($records) . '</strong><small>modul ' . ($module === 'roDisp' ? 'roDisp' : 'roHot') . '</small></article><article class="metric-card"><span>Rezultate filtrate</span><strong>' . $total . '</strong><small>' . ($query !== '' ? 'pentru căutare' : 'în registrul curent') . '</small></article><article class="metric-card"><span>Dosare păstrate</span><strong>' . count($store['records']['roDisp']) . ' / ' . count($store['records']['roHot']) . '</strong><small>roDisp / roHot</small></article></section>';
    if (!$readOnly && !$store['source']) {
        $html .= '<section class="panel import-panel"><div><p class="eyebrow">IMPORTUL INIȚIAL</p><h2>Importați datele primare din v157</h2><p class="muted">Previzualizați și validați fișierele sursă înainte de import. Se așteaptă 189 dispoziții cu 11 câmpuri și 362 hotărâri cu 28 câmpuri. Importul este permis numai într-o bază goală.</p><form method="post" class="inline-form"><input type="hidden" name="action" value="import"><input type="hidden" name="csrf" value="' . roapp_safe_text($csrf) . '"><label class="check-label"><input type="checkbox" name="confirm_import" value="1" required> Confirm importul inițial în baza locală goală</label><button class="button primary" type="submit">Verifică și importă v157</button></form></div></section>';
    }
    if ($formOverride !== null || isset($_GET['new']) || isset($_GET['id'])) {
        $isNew = isset($_GET['new']) && $formOverride === null;
        if ($formOverride !== null) {
            $record = $formOverride;
            $isNew = !empty($formOverride['_is_new']);
            unset($record['_is_new']);
        } elseif ($isNew) {
            $prefix = $module === 'roDisp' ? 'd' : 'h';
            $record = ['id' => $prefix . 'new', 'values' => array_fill(0, count($definitions), '')];
        } else {
            $record = roapp_lookup_record($store, $module, (string) ($_GET['id'] ?? ''));
            if (!$record) {
                $html .= '<div class="alert error" role="alert">Înregistrarea cerută nu există.</div>';
                $record = null;
            }
        }
        if ($record) {
            $html .= $readOnly
                ? roapp_render_readonly_record($module, $record)
                : roapp_render_editor($module, $record, $isNew, $csrf, '');
            $ready = (string) ($_GET['wordready'] ?? '');
            if ($ready !== '' && preg_match('/^[A-Za-z0-9_.-]{1,180}\.pdf$/i', $ready)) {
                $html .= '<div class="alert success">PDF-ul randat de Microsoft Word este gata: <a href="?download=' . rawurlencode($ready) . '">Descarcă PDF</a>.</div>';
            }
        }
    }
    $html .= '<section class="panel register-panel"><div class="panel-heading"><div><p class="eyebrow">REGISTRU</p><h2>Înregistrări</h2></div><form method="get" class="search-form"><input type="hidden" name="module" value="' . $module . '"><label class="visually-hidden" for="record-search">Caută în toate câmpurile</label><input id="record-search" name="q" value="' . roapp_safe_text($query) . '" placeholder="Caută număr, titlu, text…"><button class="button secondary" type="submit">Caută</button>' . ($query !== '' ? '<a class="button quiet" href="?module=' . $module . '">Șterge filtrul</a>' : '') . '</form></div>';
    if ($total === 0) {
        $html .= '<div class="empty-state"><span class="empty-icon">⌕</span><h3>' . ($records === [] ? 'Registrul este gol' : 'Nu există rezultate') . '</h3><p>' . ($records === [] ? 'Importați datele primare sau creați o înregistrare nouă.' : 'Încercați un alt termen de căutare.') . '</p></div>';
    } else {
        $html .= '<div class="table-wrap"><table><thead><tr><th>Nr.</th><th>Data</th><th>Titlu / obiect</th><th>Tip act</th><th>Acțiuni</th></tr></thead><tbody>';
        foreach ($visible as $record) {
            $v = $record['values'];
            $type = $module === 'roDisp' ? ($v[6] ?? '') : ($v[10] ?? '');
            $id = (string) $record['id'];
            $detailLabel = $readOnly ? 'Vizualizează' : 'Editează';
            $recordUrl = '?module=' . $module . '&id=' . rawurlencode($id);
            $finalDoc = $module === 'roDisp' ? 'dispoN' : 'hotN';
            $projectDoc = $module === 'roDisp' ? 'dispoN_pr' : 'hotN_pr';
            $viewAllowed = !$readOnly || roapp_readonly_action_allowed($module, $record, 'view');
            $finalAllowed = !$readOnly || roapp_readonly_action_allowed($module, $record, 'final');
            $projectAllowed = !$readOnly || roapp_readonly_action_allowed($module, $record, 'project');
            $titleLink = $viewAllowed
                ? '<a class="record-title" href="' . $recordUrl . '">' . roapp_safe_text(roapp_record_title($module, $record)) . '</a>'
                : '<span class="record-title action-disabled-title" aria-disabled="true">' . roapp_safe_text(roapp_record_title($module, $record)) . '</span>';
            $viewAction = $viewAllowed
                ? '<a href="' . $recordUrl . '">' . $detailLabel . '</a>'
                : '<span class="action-disabled" aria-disabled="true">' . $detailLabel . '</span>';
            $finalAction = $finalAllowed
                ? '<a target="_blank" rel="noopener" href="?print=1&amp;module=' . $module . '&amp;id=' . rawurlencode($id) . '&amp;doc=' . $finalDoc . '">Act final</a>'
                : '<span class="action-disabled" aria-disabled="true">Act final</span>';
            $projectAction = $projectAllowed
                ? '<a target="_blank" rel="noopener" href="?print=1&amp;module=' . $module . '&amp;id=' . rawurlencode($id) . '&amp;doc=' . $projectDoc . '">Proiect</a>'
                : '<span class="action-disabled" aria-disabled="true">Proiect</span>';
            $html .= '<tr><td class="number-cell">' . roapp_safe_text(roapp_record_number($module, $record)) . '</td><td class="date-cell">' . roapp_safe_text(roapp_record_date($module, $record)) . '</td><td>' . $titleLink . '<small class="record-id">' . roapp_safe_text($id) . '</small></td><td><span class="type-chip">' . roapp_safe_text((string) $type) . '</span></td><td class="row-actions">' . $viewAction . $finalAction . $projectAction . '</td></tr>';
        }
        $html .= '</tbody></table></div><div class="table-footer"><span>Pagina ' . $page . ' din ' . $pages . ' · ' . $total . ' rezultat(e)</span><div class="pagination">';
        if ($page > 1) {
            $html .= '<a href="?module=' . $module . '&q=' . rawurlencode($query) . '&page=' . ($page - 1) . '" aria-label="Pagina precedentă">← Înapoi</a>';
        }
        if ($page < $pages) {
            $html .= '<a href="?module=' . $module . '&q=' . rawurlencode($query) . '&page=' . ($page + 1) . '" aria-label="Pagina următoare">Înainte →</a>';
        }
        $html .= '</div></div>';
    }
    $html .= '</section>';
    if ($flash || $error !== '') {
        // Alertul este afișat sus; acest bloc păstrează structura de pagină pentru flash-uri.
    }
    return $html . roapp_html_footer();
}

function roapp_render_settings_page(array $store, string $csrf, ?array $flash, string $error = ''): string
{
    return roapp_html_header('Setări și backup', 'roDisp', true)
        . roapp_alert_html($flash, $error)
        . '<section class="page-intro"><div><p class="eyebrow">ADMINISTRARE LOCALĂ</p><h1>Setări & backup</h1><p class="muted">O singură instanță locală, fără servicii cloud obligatorii.</p></div></section>'
        . roapp_render_settings($store, $csrf)
        . roapp_html_footer();
}

function roapp_run(): void
{
    roapp_start_session();
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: DENY');
    header('Cache-Control: no-store');
    roapp_require_access();
    $readOnly = !roapp_is_loopback();
    roapp_enforce_read_only_access($readOnly);

    $flash = roapp_take_flash();
    $error = '';
    $formOverride = null;
    $requestedModule = (string) ($_GET['module'] ?? $_POST['module'] ?? 'roDisp');
    $module = in_array($requestedModule, ['roDisp', 'roHot'], true) ? $requestedModule : 'roDisp';

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        try {
            roapp_check_csrf();
            $action = (string) ($_POST['action'] ?? '');
            if ($action === 'import') {
                if (($_POST['confirm_import'] ?? '') !== '1') {
                    throw new RuntimeException('Bifați confirmarea importului inițial.');
                }
                $payload = roapp_seed_payload();
                roapp_mutate_store(static function (array &$store) use ($payload): void {
                    if (!empty($store['records']['roDisp']) || !empty($store['records']['roHot']) || !empty($store['source'])) {
                        throw new RuntimeException('Importul inițial este disponibil numai când baza este goală. Nu s-au modificat date.');
                    }
                    $institutionOverrides = $store['settings']['institution'] ?? [];
                    $store['records'] = $payload['records'];
                    $store['settings'] = $payload['settings'];
                    if (is_array($institutionOverrides)) {
                        $store['settings']['institution'] = array_merge($store['settings']['institution'] ?? [], $institutionOverrides);
                    }
                    $store['source'] = $payload['source'];
                });
                roapp_flash('success', 'Import verificat și finalizat: 189 dispoziții și 362 hotărâri din v157.');
                roapp_redirect('?module=' . $module);
            }
            if ($action === 'save') {
                $module = in_array((string) ($_POST['module'] ?? ''), ['roDisp', 'roHot'], true) ? (string) $_POST['module'] : 'roDisp';
                $id = trim((string) ($_POST['id'] ?? ''));
                $definition = roapp_field_definitions()[$module];
                $incoming = $_POST['values'] ?? [];
                if (!is_array($incoming)) {
                    throw new RuntimeException('Formularul nu conține lista de valori așteptată.');
                }
                $values = [];
                foreach ($definition as $index => $_field) {
                    $raw = $incoming[$index] ?? '';
                    if (!is_string($raw)) {
                        throw new RuntimeException('Un câmp de formular are tip invalid.');
                    }
                    $values[] = roapp_value_for_storage(str_replace("\0", '', $raw));
                }
                $isNew = $id === '' || str_ends_with($id, 'new');
                if ($isNew) {
                    $validation = roapp_validate_new_record($module, $values);
                    if ($validation !== []) {
                        throw new RuntimeException(implode(' ', $validation));
                    }
                    $prefix = $module === 'roDisp' ? 'd' : 'h';
                    $id = $prefix . bin2hex(random_bytes(10));
                }
                $timestamp = date(DATE_ATOM);
                roapp_mutate_store(static function (array &$store) use ($module, $id, $values, $timestamp, $isNew): void {
                    $found = false;
                    foreach ($store['records'][$module] as $index => $record) {
                        if (($record['id'] ?? '') === $id) {
                            $store['records'][$module][$index] = ['id' => $id, 'values' => $values, 'updated_at' => $timestamp, 'source_row' => $record['source_row'] ?? null];
                            $found = true;
                            break;
                        }
                    }
                    if (!$found && !$isNew) {
                        throw new RuntimeException('Înregistrarea nu mai există. Reîncărcați registrul înainte de a salva.');
                    }
                    if (!$found) {
                        $store['records'][$module][] = ['id' => $id, 'values' => $values, 'updated_at' => $timestamp, 'source_row' => null];
                    }
                });
                roapp_flash('success', 'Înregistrarea a fost salvată local.');
                roapp_redirect('?module=' . $module . '&id=' . rawurlencode($id));
            }
            if ($action === 'settings') {
                $incoming = $_POST['institution'] ?? [];
                if (!is_array($incoming)) {
                    throw new RuntimeException('Setările primite nu sunt valide.');
                }
                $new = [];
                foreach (['name', 'county', 'location', 'mayor', 'secretary', 'monitorUrl'] as $key) {
                    $value = $incoming[$key] ?? '';
                    if (!is_string($value) || strlen($value) > 1000) {
                        throw new RuntimeException('Setarea „' . $key . '” este invalidă sau prea lungă.');
                    }
                    $new[$key] = trim($value);
                }
                if ($new['monitorUrl'] !== '') {
                    $parts = parse_url($new['monitorUrl']);
                    if (!filter_var($new['monitorUrl'], FILTER_VALIDATE_URL) || !is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
                        throw new RuntimeException('URL-ul Monitorului Oficial Local trebuie să înceapă cu http:// sau https://.');
                    }
                }
                $incomingLists = $_POST['lists'] ?? [];
                if (!is_array($incomingLists)) {
                    throw new RuntimeException('Listele de configurare primite nu sunt valide.');
                }
                $newLists = [
                    'initiators' => roapp_parse_settings_lines($incomingLists['initiators'] ?? '', true),
                    'departments' => roapp_parse_settings_lines($incomingLists['departments'] ?? '', true),
                    'reporters' => roapp_parse_settings_lines($incomingLists['reporters'] ?? '', true),
                    'commissions' => roapp_parse_settings_lines($incomingLists['commissions'] ?? ''),
                    'councilors' => roapp_parse_settings_lines($incomingLists['councilors'] ?? ''),
                    'emails' => roapp_parse_settings_lines($incomingLists['emails'] ?? ''),
                ];
                foreach ($newLists['emails'] as $address) {
                    if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                        throw new RuntimeException('Adresa de email „' . $address . '” nu este validă.');
                    }
                }
                $commissionMembers = roapp_parse_commission_members($_POST['commission_members'] ?? '');
                roapp_mutate_store(static function (array &$store) use ($new, $newLists, $commissionMembers): void {
                    $store['settings']['institution'] = array_merge($store['settings']['institution'] ?? [], $new);
                    foreach ($newLists as $key => $value) {
                        $store['settings'][$key] = $value;
                    }
                    $store['settings']['commission_members'] = $commissionMembers;
                });
                roapp_flash('success', 'Setările instituției, listele și componența comisiilor au fost salvate.');
                roapp_redirect('?settings=1');
            }
            if ($action === 'backup') {
                $store = roapp_load_store();
                roapp_send_zip_backup($store);
            }
            if ($action === 'restore') {
                if (($_POST['confirm_restore'] ?? '') !== '1') {
                    throw new RuntimeException('Bifați confirmarea restaurării.');
                }
                $restored = roapp_decode_restore_upload($_FILES['backup'] ?? []);
                try {
                    $current = roapp_load_store();
                    roapp_save_pre_restore_backup($current);
                } catch (Throwable $readError) {
                    $currentPath = roapp_store_path();
                    if (is_file($currentPath)) {
                        $preserved = roapp_data_dir() . DIRECTORY_SEPARATOR . 'pre-restore-corrupt-' . date('Ymd-His') . '.json';
                        if (!@copy($currentPath, $preserved)) {
                            throw new RuntimeException('Baza curentă este invalidă și nu a putut fi păstrată înainte de restaurare. Restaurarea a fost oprită.');
                        }
                    }
                }
                roapp_replace_store($restored['store']);
                foreach ($restored['generated'] as $filename => $bytes) {
                    if (strlen($bytes) <= 25 * 1024 * 1024) {
                        file_put_contents(roapp_generated_dir() . DIRECTORY_SEPARATOR . $filename, $bytes, LOCK_EX);
                    }
                }
                roapp_flash('success', 'Backup restaurat. A fost păstrată automat o copie JSON pre-restaurare.');
                roapp_redirect('?settings=1');
            }
            if ($action === 'word_pdf') {
                $module = in_array((string) ($_POST['module'] ?? ''), ['roDisp', 'roHot'], true) ? (string) $_POST['module'] : 'roDisp';
                $id = (string) ($_POST['id'] ?? '');
                $template = (string) ($_POST['template'] ?? '');
                $store = roapp_load_store();
                $record = roapp_lookup_record($store, $module, $id);
                if (!$record) {
                    throw new RuntimeException('Înregistrarea solicitată nu a fost găsită.');
                }
                $files = roapp_create_word_pdf($module, $record, $store['settings'], $template);
                roapp_redirect('?module=' . $module . '&id=' . rawurlencode($id) . '&wordready=' . rawurlencode(basename($files['pdf'])));
            }
            throw new RuntimeException('Acțiunea solicitată nu este cunoscută.');
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
            if (!($exception instanceof RuntimeException)) {
                error_log('roapp request failed: ' . $exception);
                $error = 'A apărut o eroare internă. Detaliile au fost înregistrate în jurnalul PHP.';
            }
            if (($_POST['action'] ?? '') === 'save') {
                $postedModule = in_array((string) ($_POST['module'] ?? ''), ['roDisp', 'roHot'], true) ? (string) $_POST['module'] : $module;
                $incoming = is_array($_POST['values'] ?? null) ? $_POST['values'] : [];
                $values = [];
                foreach (roapp_field_definitions()[$postedModule] as $index => $_field) {
                    $rawValue = is_string($incoming[$index] ?? null) ? str_replace("\0", '', (string) $incoming[$index]) : '';
                    $rawValue = strlen($rawValue) > 100000 ? substr($rawValue, 0, 100000) : $rawValue;
                    $values[] = str_replace(["\r\n", "\r"], "\n", $rawValue);
                }
                $postedId = trim((string) ($_POST['id'] ?? ''));
                $isNew = $postedId === '' || str_ends_with($postedId, 'new');
                $formOverride = ['id' => $postedId !== '' ? $postedId : ($postedModule === 'roDisp' ? 'dnew' : 'hnew'), 'values' => $values, '_is_new' => $isNew];
                $module = $postedModule;
            }
        }
    }

    try {
        $store = roapp_load_store();
    } catch (Throwable $storeError) {
        error_log('roapp database load failed: ' . $storeError->getMessage());
        http_response_code(500);
        $csrf = (string) $_SESSION['csrf'];
        $recovery = $readOnly
            ? '<div class="alert error" role="alert"><strong>Baza locală nu poate fi citită.</strong> Administrarea și restaurarea sunt disponibile numai local.</div>'
            : '<div class="alert error" role="alert"><strong>Baza locală nu poate fi citită.</strong> Încărcați un backup JSON/ZIP valid. Fișierul invalid nu este suprascris; dacă restaurarea reușește, se păstrează automat înainte într-un fișier separat.</div><section class="panel"><h2>Restaurare din backup</h2><form method="post" enctype="multipart/form-data" class="restore-form"><input type="hidden" name="action" value="restore"><input type="hidden" name="csrf" value="' . roapp_safe_text($csrf) . '"><label for="backup-file">Fișier backup JSON sau ZIP</label><input id="backup-file" type="file" name="backup" accept=".json,.zip,application/json,application/zip" required><label class="check-label"><input type="checkbox" name="confirm_restore" value="1" required> Confirm că restaurarea înlocuiește baza locală invalidă</label><button class="button danger" type="submit">Restaurează backup</button></form></section>';
        echo roapp_html_header('Restaurare necesară', 'roDisp', !$readOnly, $readOnly) . $recovery . roapp_html_footer();
        return;
    }
    if (isset($_GET['download'])) {
        $name = (string) $_GET['download'];
        if (!preg_match('/^[A-Za-z0-9_.-]{1,180}\.(?:pdf|docx)$/i', $name)) {
            http_response_code(400);
            echo 'Nume de fișier invalid.';
            return;
        }
        if ($readOnly && str_ends_with(strtolower($name), '.docx')) {
            http_response_code(403);
            echo 'Descărcarea DOCX nu este disponibilă pentru accesul din rețea.';
            return;
        }
        $path = roapp_generated_dir() . DIRECTORY_SEPARATOR . basename($name);
        if (!is_file($path)) {
            http_response_code(404);
            echo 'Fișierul solicitat nu există.';
            return;
        }
        header('Content-Type: ' . (str_ends_with(strtolower($name), '.pdf') ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
        header('Content-Disposition: attachment; filename="' . basename($name) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        return;
    }
    if (isset($_GET['print'])) {
        $printModule = in_array((string) ($_GET['module'] ?? ''), ['roDisp', 'roHot'], true) ? (string) $_GET['module'] : 'roDisp';
        $record = roapp_lookup_record($store, $printModule, (string) ($_GET['id'] ?? ''));
        $template = (string) ($_GET['doc'] ?? '');
        if (!$record) {
            http_response_code(404);
            echo 'Înregistrarea nu există.';
            return;
        }
        if ($readOnly && !roapp_readonly_action_allowed($printModule, $record, 'document', $template)) {
            http_response_code(404);
            echo 'Documentul solicitat nu este disponibil pentru acest Tip Act.';
            return;
        }
        try {
            echo roapp_html_document($printModule, $record, $store['settings'], $template, $readOnly);
        } catch (Throwable $exception) {
            http_response_code(400);
            echo 'Tip de document invalid.';
        }
        return;
    }

    $csrf = (string) $_SESSION['csrf'];
    if (isset($_GET['settings'])) {
        echo roapp_render_settings_page($store, $csrf, $flash, $error);
        return;
    }
    if ($readOnly && isset($_GET['id'])) {
        $detailModule = in_array((string) ($_GET['module'] ?? ''), ['roDisp', 'roHot'], true) ? (string) $_GET['module'] : 'roDisp';
        $detailRecord = roapp_lookup_record($store, $detailModule, (string) $_GET['id']);
        if (!$detailRecord || !roapp_readonly_action_allowed($detailModule, $detailRecord, 'view')) {
            http_response_code(404);
            echo 'Înregistrarea nu este disponibilă pentru vizualizare externă.';
            return;
        }
    }
    $query = trim((string) ($_GET['q'] ?? ''));
    if (strlen($query) > 300) {
        $query = substr($query, 0, 300);
    }
    $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
    $page = $page === false ? 1 : (int) $page;
    if ($formOverride !== null) {
        $formOverride['_is_new'] = $formOverride['_is_new'] ?? false;
    }
    echo roapp_render_dashboard($store, $module, $query, $page, $csrf, $flash, $error, $formOverride, $readOnly);
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php')) {
    roapp_run();
}
