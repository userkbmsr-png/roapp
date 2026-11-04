<?php
declare(strict_types=1);

require_once __DIR__ . '/fields.php';

function roapp_root(): string
{
    return dirname(__DIR__);
}

function roapp_data_dir(): string
{
    $configured = getenv('ROAPP_DATA_DIR');
    return $configured !== false && trim($configured) !== '' ? rtrim($configured, "\\/") : roapp_root() . DIRECTORY_SEPARATOR . 'var';
}

function roapp_generated_dir(): string
{
    return roapp_data_dir() . DIRECTORY_SEPARATOR . 'generated';
}

function roapp_ensure_directories(): void
{
    foreach ([roapp_data_dir(), roapp_generated_dir()] as $directory) {
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Nu se poate crea directorul privat de date al aplicației. Verificați drepturile de scriere.');
        }
    }
}

function roapp_store_path(): string
{
    return roapp_data_dir() . DIRECTORY_SEPARATOR . 'database.json';
}

function roapp_blank_store(): array
{
    return [
        'schema_version' => 1,
        'created_at' => date(DATE_ATOM),
        'records' => ['roDisp' => [], 'roHot' => []],
        'settings' => roapp_default_settings(),
        'source' => null,
    ];
}

function roapp_default_settings(): array
{
    $file = roapp_root() . DIRECTORY_SEPARATOR . 'seed' . DIRECTORY_SEPARATOR . 'settings.json';
    $raw = is_file($file) ? file_get_contents($file) : false;
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (is_array($decoded)) {
        $configFile = roapp_root() . DIRECTORY_SEPARATOR . 'seed' . DIRECTORY_SEPARATOR . 'config.json';
        $configRaw = is_file($configFile) ? file_get_contents($configFile) : false;
        $legacyConfig = is_string($configRaw) ? json_decode($configRaw, true) : null;
        if (is_array($legacyConfig)) {
            $decoded['legacy_config'] = $legacyConfig;
            $decoded['emails'] = array_values(array_filter(array_map('trim', explode(';', (string) ($legacyConfig['array']['email'] ?? ''))), static fn(string $v): bool => $v !== ''));
            $decoded['commission_members'] = is_array($legacyConfig['comisii'] ?? null) ? $legacyConfig['comisii'] : [];
        }
        return $decoded;
    }
    return [
        'institution' => [
            'name' => 'Comuna Beba Veche', 'county' => 'Timiș', 'location' => 'Beba Veche',
            'mayor' => '', 'secretary' => '', 'monitorUrl' => '',
        ],
        'initiators' => [], 'departments' => [], 'reporters' => [], 'commissions' => [], 'councilors' => [],
    ];
}

function roapp_load_store_unlocked(): array
{
    $path = roapp_store_path();
    if (!is_file($path)) {
        return roapp_blank_store();
    }
    $json = file_get_contents($path);
    if (!is_string($json) || strlen($json) > 100 * 1024 * 1024) {
        throw new RuntimeException('Fișierul bazei de date lipsește sau depășește limita acceptată.');
    }
    $store = json_decode($json, true);
    $errors = json_last_error() === JSON_ERROR_NONE ? roapp_validate_store($store) : ['JSON invalid: ' . json_last_error_msg()];
    if ($errors !== []) {
        throw new RuntimeException('Baza locală nu a putut fi citită: ' . implode(' ', $errors));
    }
    return $store;
}

function roapp_load_store(): array
{
    roapp_ensure_directories();
    $lock = @fopen(roapp_data_dir() . DIRECTORY_SEPARATOR . 'database.lock', 'c');
    if ($lock === false) {
        throw new RuntimeException('Nu se poate bloca baza locală pentru citire.');
    }
    try {
        if (!flock($lock, LOCK_SH)) {
            throw new RuntimeException('Nu se poate bloca baza locală pentru citire.');
        }
        return roapp_load_store_unlocked();
    } finally {
        @flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function roapp_store_write_unlocked(array $store): void
{
    $errors = roapp_validate_store($store);
    if ($errors !== []) {
        throw new RuntimeException('Datele nu au putut fi salvate: ' . implode(' ', $errors));
    }
    $json = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $path = roapp_store_path();
    $temp = $path . '.tmp.' . bin2hex(random_bytes(6));
    if (file_put_contents($temp, $json, LOCK_EX) === false) {
        throw new RuntimeException('Nu s-a putut scrie fișierul temporar al bazei de date.');
    }
    if (DIRECTORY_SEPARATOR === '\\' && is_file($path)) {
        $old = $path . '.old';
        @unlink($old);
        if (!@rename($path, $old)) {
            @unlink($temp);
            throw new RuntimeException('Nu s-a putut roti baza locală pe Windows.');
        }
        if (!@rename($temp, $path)) {
            @rename($old, $path);
            @unlink($temp);
            throw new RuntimeException('Nu s-a putut înlocui baza locală pe Windows.');
        }
        @unlink($old);
        return;
    }
    if (!@rename($temp, $path)) {
        @unlink($temp);
        throw new RuntimeException('Nu s-a putut înlocui baza locală.');
    }
}

function roapp_mutate_store(callable $operation): mixed
{
    roapp_ensure_directories();
    $lock = @fopen(roapp_data_dir() . DIRECTORY_SEPARATOR . 'database.lock', 'c');
    if ($lock === false) {
        throw new RuntimeException('Nu se poate bloca baza locală pentru scriere.');
    }
    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('Nu se poate bloca baza locală pentru scriere.');
        }
        $store = roapp_load_store_unlocked();
        $result = $operation($store);
        roapp_store_write_unlocked($store);
        return $result;
    } finally {
        @flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function roapp_replace_store(array $store): void
{
    roapp_ensure_directories();
    $lock = @fopen(roapp_data_dir() . DIRECTORY_SEPARATOR . 'database.lock', 'c');
    if ($lock === false) {
        throw new RuntimeException('Nu se poate bloca baza locală pentru restaurare.');
    }
    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('Nu se poate bloca baza locală pentru restaurare.');
        }
        roapp_store_write_unlocked($store);
    } finally {
        @flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function roapp_parse_legacy_file(string $path, int $expectedFields): array
{
    $raw = @file_get_contents($path);
    if (!is_string($raw)) {
        throw new RuntimeException('Lipsește fișierul sursă pentru import: ' . basename($path));
    }
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }
    $lines = preg_split('/\n/', $raw);
    if (!is_array($lines)) {
        throw new RuntimeException('Nu se poate citi fișierul sursă: ' . basename($path));
    }
    $rows = [];
    foreach ($lines as $lineNumber => $line) {
        $line = rtrim($line, "\r");
        if ($line === '' && $lineNumber === count($lines) - 1) {
            continue;
        }
        $columns = explode('#', $line);
        if (count($columns) !== $expectedFields + 2 || $columns[0] !== '' || end($columns) !== '') {
            throw new RuntimeException(sprintf(
                'Rândul %d din %s are structură neașteptată (așteptate %d câmpuri între delimitatori).',
                $lineNumber + 1,
                basename($path),
                $expectedFields
            ));
        }
        $values = array_slice($columns, 1, $expectedFields);
        foreach ($values as $value) {
            if (strlen($value) > 100000) {
                throw new RuntimeException('Un câmp depășește limita de siguranță la rândul ' . ($lineNumber + 1) . '.');
            }
        }
        $rows[] = $values;
    }
    return $rows;
}

function roapp_seed_payload(): array
{
    $dispPath = roapp_root() . DIRECTORY_SEPARATOR . 'seed' . DIRECTORY_SEPARATOR . 'v157' . DIRECTORY_SEPARATOR . 'dispozitii.txt';
    $hotPath = roapp_root() . DIRECTORY_SEPARATOR . 'seed' . DIRECTORY_SEPARATOR . 'v157' . DIRECTORY_SEPARATOR . 'hotarari.txt';
    $dispRows = roapp_parse_legacy_file($dispPath, 11);
    $hotRows = roapp_parse_legacy_file($hotPath, 28);
    $settingsPath = roapp_root() . DIRECTORY_SEPARATOR . 'seed' . DIRECTORY_SEPARATOR . 'settings.json';
    $settings = roapp_default_settings();
    if (!is_array($settings)) {
        throw new RuntimeException('Setările inițiale din arhiva v157 nu sunt valide.');
    }
    $records = ['roDisp' => [], 'roHot' => []];
    foreach (['roDisp' => $dispRows, 'roHot' => $hotRows] as $module => $rows) {
        $prefix = $module === 'roDisp' ? 'd' : 'h';
        foreach ($rows as $index => $values) {
            $records[$module][] = [
                'id' => $prefix . str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                'values' => $values,
                'updated_at' => null,
                'source_row' => $index + 1,
            ];
        }
    }
    $source = [
        'archive' => 'v157.zip',
        'imported_at' => date(DATE_ATOM),
        'counts' => ['roDisp' => count($dispRows), 'roHot' => count($hotRows)],
        'hashes' => [
            'dispozitii.txt' => hash_file('sha256', $dispPath),
            'hotarari.txt' => hash_file('sha256', $hotPath),
            'settings.json' => hash_file('sha256', $settingsPath),
            'config.json' => hash_file('sha256', roapp_root() . DIRECTORY_SEPARATOR . 'seed' . DIRECTORY_SEPARATOR . 'config.json'),
        ],
    ];
    return ['records' => $records, 'settings' => $settings, 'source' => $source];
}

function roapp_validate_store(mixed $store): array
{
    $errors = [];
    if (!is_array($store) || ($store['schema_version'] ?? null) !== 1 || !isset($store['records'], $store['settings']) || !is_array($store['records']) || !is_array($store['settings'])) {
        return ['Structura bazei locale nu este compatibilă cu versiunea 1.'];
    }
    $definitions = roapp_field_definitions();
    foreach (['roDisp', 'roHot'] as $module) {
        if (!isset($store['records'][$module]) || !is_array($store['records'][$module])) {
            $errors[] = 'Lipsește lista ' . $module . '.';
            continue;
        }
        $seen = [];
        $fieldCount = count($definitions[$module]);
        foreach ($store['records'][$module] as $rowNumber => $record) {
            if (!is_array($record) || !is_string($record['id'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $record['id'])) {
                $errors[] = $module . ': identificator invalid la rândul ' . ($rowNumber + 1) . '.';
                continue;
            }
            if (isset($seen[$record['id']])) {
                $errors[] = $module . ': identificator duplicat ' . $record['id'] . '.';
            }
            $seen[$record['id']] = true;
            if (!isset($record['values']) || !is_array($record['values']) || count($record['values']) !== $fieldCount) {
                $errors[] = $module . ': număr de câmpuri invalid pentru ' . $record['id'] . '.';
                continue;
            }
            foreach ($record['values'] as $value) {
                if (!is_string($value) || strlen($value) > 100000) {
                    $errors[] = $module . ': valoare invalidă sau prea mare pentru ' . $record['id'] . '.';
                    break;
                }
            }
        }
    }
    $institution = $store['settings']['institution'] ?? null;
    if (!is_array($institution)) {
        $errors[] = 'Setările instituției lipsesc.';
    } else {
        foreach (['name', 'county', 'location', 'mayor', 'secretary', 'monitorUrl'] as $key) {
            if (isset($institution[$key]) && (!is_string($institution[$key]) || strlen($institution[$key]) > 1000)) {
                $errors[] = 'Valoare invalidă în setarea ' . $key . '.';
            }
        }
    }
    foreach (['initiators', 'departments', 'reporters', 'commissions', 'councilors', 'emails', 'commission_members'] as $key) {
        if (isset($store['settings'][$key]) && !is_array($store['settings'][$key])) {
            $errors[] = 'Lista de configurare „' . $key . '” nu este validă.';
        }
    }
    foreach (['initiators', 'departments', 'reporters'] as $key) {
        $entries = $store['settings'][$key] ?? [];
        if (!is_array($entries)) {
            continue;
        }
        foreach ($entries as $index => $entry) {
            if (!is_array($entry) || !is_string($entry['label'] ?? null) || !is_string($entry['name'] ?? null)) {
                $errors[] = 'Element invalid în lista „' . $key . '”, poziția ' . ($index + 1) . '.';
                break;
            }
        }
    }
    return $errors;
}

function roapp_lookup_record(array $store, string $module, string $id): ?array
{
    if (!in_array($module, ['roDisp', 'roHot'], true)) {
        return null;
    }
    foreach ($store['records'][$module] as $record) {
        if (($record['id'] ?? '') === $id) {
            return $record;
        }
    }
    return null;
}

function roapp_normalize_value_for_form(string $value): string
{
    return str_replace(['\\r\\n', '\\n', '\\r'], "\n", $value);
}

function roapp_value_for_storage(string $value): string
{
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    if (strlen($value) > 100000) {
        throw new RuntimeException('Un câmp depășește limita de 100.000 de octeți.');
    }
    return $value;
}

function roapp_validate_new_record(string $module, array $values): array
{
    $errors = [];
    $requiredIndices = $module === 'roDisp' ? [0, 1, 2] : [0, 3, 4, 5];
    foreach ($requiredIndices as $index) {
        if (trim($values[$index] ?? '') === '') {
            $errors[] = 'Completați câmpul „' . roapp_field_definitions()[$module][$index]['label'] . '”.';
        }
    }
    foreach ($module === 'roDisp' ? [1] : [0, 4] as $index) {
        $date = trim($values[$index] ?? '');
        if ($date !== '') {
            $parsed = DateTime::createFromFormat('!d.m.Y', $date);
            if (!$parsed || $parsed->format('d.m.Y') !== $date) {
                $errors[] = 'Data „' . roapp_field_definitions()[$module][$index]['label'] . '” trebuie introdusă în formatul zz.ll.aaaa.';
            }
        }
    }
    return $errors;
}
