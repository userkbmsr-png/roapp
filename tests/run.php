<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/store.php';
require_once dirname(__DIR__) . '/src/documents.php';
require_once dirname(__DIR__) . '/src/app.php';

$passed = 0;
$failed = [];
$check = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
    } else {
        $failed[] = $message;
    }
};

$originalPassword = getenv('ROAPP_PASSWORD');
$originalEnvPassword = $_ENV['ROAPP_PASSWORD'] ?? null;
$originalServerPassword = $_SERVER['ROAPP_PASSWORD'] ?? null;
$envFixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'roapp-env-test-' . bin2hex(random_bytes(5)) . '.env';
$fixturePassword = bin2hex(random_bytes(24));
try {
    putenv('ROAPP_PASSWORD');
    unset($_ENV['ROAPP_PASSWORD'], $_SERVER['ROAPP_PASSWORD']);
    file_put_contents($envFixture, "\xEF\xBB\xBF# test fixture\r\nROAPP_PASSWORD=\"{$fixturePassword}\"\r\nOTHER_SETTING=ignored\r\n");
    $check(roapp_env_file_path() === dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env', 'Fișierul .env trebuie să fie în rădăcina aplicației, în afara public/.');
    $check(roapp_load_env_password($envFixture) && getenv('ROAPP_PASSWORD') === $fixturePassword, 'Parola trebuie citită din .env cu BOM/CRLF și ghilimele, fără evaluarea altor setări.');

    $overridePassword = bin2hex(random_bytes(16));
    putenv('ROAPP_PASSWORD=' . $overridePassword);
    file_put_contents($envFixture, "ROAPP_PASSWORD=ignored-from-file\n");
    $check(roapp_load_env_password($envFixture) && getenv('ROAPP_PASSWORD') === $overridePassword, 'Variabila de mediu explicită trebuie să aibă prioritate față de .env.');

    putenv('ROAPP_PASSWORD');
    unset($_ENV['ROAPP_PASSWORD'], $_SERVER['ROAPP_PASSWORD']);
    file_put_contents($envFixture, "ROAPP_PASSWORD=first\nROAPP_PASSWORD=second\n");
    $check(!roapp_load_env_password($envFixture) && getenv('ROAPP_PASSWORD') === false, 'Valorile duplicate din .env trebuie refuzate fără a configura o parolă parțială.');
} catch (Throwable $error) {
    $failed[] = 'Testul de configurare .env a eșuat: ' . $error->getMessage();
} finally {
    @unlink($envFixture);
    if ($originalPassword === false) {
        putenv('ROAPP_PASSWORD');
    } else {
        putenv('ROAPP_PASSWORD=' . $originalPassword);
    }
    if ($originalEnvPassword === null) {
        unset($_ENV['ROAPP_PASSWORD']);
    } else {
        $_ENV['ROAPP_PASSWORD'] = $originalEnvPassword;
    }
    if ($originalServerPassword === null) {
        unset($_SERVER['ROAPP_PASSWORD']);
    } else {
        $_SERVER['ROAPP_PASSWORD'] = $originalServerPassword;
    }
}

$originalRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
$originalForwardedFor = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
$_SERVER['REMOTE_ADDR'] = '192.0.2.25';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '127.0.0.1';
$check(!roapp_is_loopback(), 'Header-ul X-Forwarded-For nu trebuie să transforme conexiunea externă în loopback.');
unset($_SERVER['HTTP_X_FORWARDED_FOR']);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$check(roapp_is_loopback(), 'Conexiunea locală IPv4 trebuie să rămână identificată ca loopback.');
$_SERVER['REMOTE_ADDR'] = '::1';
$check(roapp_is_loopback(), 'Conexiunea locală IPv6 trebuie să rămână identificată ca loopback.');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '192.0.2.25';
$check(!roapp_is_loopback(), 'Un proxy cu header de forwarding nu trebuie să primească privilegiile conexiunii loopback.');
unset($_SERVER['REMOTE_ADDR']);
$check(!roapp_is_loopback(), 'Lipsa adresei peer trebuie să fie tratată ca acces non-local, nu privilegiat.');
if ($originalRemoteAddr !== null) {
    $_SERVER['REMOTE_ADDR'] = $originalRemoteAddr;
}
if ($originalForwardedFor === null) {
    unset($_SERVER['HTTP_X_FORWARDED_FOR']);
} else {
    $_SERVER['HTTP_X_FORWARDED_FOR'] = $originalForwardedFor;
}
$check(roapp_read_only_denial_status(true, 'GET', ['print' => '1']) === 0, 'Accesul extern GET pentru tipărire trebuie permis.');
$check(roapp_read_only_denial_status(true, 'HEAD', []) === 0, 'Cererea externă HEAD trebuie permisă ca metodă de citire.');
$check(roapp_read_only_denial_status(true, 'POST', []) === 405, 'POST-ul extern trebuie refuzat independent de acțiune.');
foreach (['PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $blockedMethod) {
    $check(roapp_read_only_denial_status(true, $blockedMethod, []) === 405, $blockedMethod . ' extern trebuie refuzat.');
}
$check(roapp_read_only_denial_status(true, 'GET', ['settings' => '1']) === 403, 'Setările trebuie refuzate din rețea.');
$check(roapp_read_only_denial_status(true, 'GET', ['new' => '1']) === 403, 'Formularul de creare trebuie refuzat din rețea.');
$check(roapp_read_only_denial_status(false, 'POST', []) === 0, 'Accesul local trebuie să păstreze metodele de scriere existente.');

try {
    $payload = roapp_seed_payload();
    $check(count($payload['records']['roDisp']) === 189, 'Import roDisp trebuie să aibă 189 de rânduri.');
    $check(count($payload['records']['roHot']) === 362, 'Import roHot trebuie să aibă 362 de rânduri.');
    $check(count($payload['records']['roDisp'][0]['values']) === 11, 'roDisp trebuie să păstreze 11 câmpuri.');
    $check(count($payload['records']['roHot'][0]['values']) === 28, 'roHot trebuie să păstreze 28 de câmpuri.');
    $check($payload['records']['roDisp'][0]['values'][1] === '22.01.2020', 'Data primei dispoziții trebuie păstrată exact.');
    $check(str_contains($payload['records']['roHot'][0]['values'][9], 'Legea 287/17.07.2009'), 'Diacriticele și textul istoric trebuie păstrate în roHot.');
    $check(str_contains($payload['records']['roHot'][0]['values'][9], '\\n'), 'Secvențele literale \\n din sursă trebuie păstrate la import.');
    $check(count($payload['settings']['commissions'] ?? []) === 4, 'Cele patru comisii configurate în config.ini trebuie disponibile la import.');
    $check(count($payload['settings']['councilors'] ?? []) === 7, 'Lista consilierilor din config.ini trebuie disponibilă la import.');
    $check(count($payload['settings']['departments'] ?? []) === 9, 'Compartimentele din config.ini trebuie disponibile la import.');
    $check(count($payload['settings']['commission_members'] ?? []) === 4, 'Componența fiecărei comisii din config.ini trebuie păstrată.');
    $check(count($payload['settings']['emails'] ?? []) === 7, 'Lista de adrese din config.ini trebuie disponibilă în Setări.');
    $check(isset($payload['settings']['legacy_config']['general'], $payload['settings']['legacy_config']['array']), 'Secțiunile suplimentare din config.ini trebuie păstrate pentru consultare.');
    $check(isset(roapp_document_options('roDisp')['dispoN_pr']) && isset(roapp_document_options('roHot')['hotN_pr']), 'Șabloanele de proiect trebuie oferite distinct în ambele module.');
    $settingsHtml = roapp_render_settings(roapp_blank_store(), 'csrf-test');
    foreach (['Compartimente', 'Consilieri', 'Comisii', 'Membrii comisiilor', 'commission-members', 'config.ini'] as $visibleSetting) {
        $check(str_contains($settingsHtml, $visibleSetting), 'Setările trebuie să afișeze lista/configurația „' . $visibleSetting . '”.');
    }
    $parsedInitiators = roapp_parse_settings_lines("Primar|Nume Test\nViceprimar|Alt Nume", true);
    $check(count($parsedInitiators) === 2 && $parsedInitiators[0]['name'] === 'Nume Test', 'Parserul Setărilor trebuie să valideze listele etichetă|nume.');
    $parsedMembers = roapp_parse_commission_members('{"Comisia test":["Consilier Test"]}');
    $check(($parsedMembers['Comisia test'][0] ?? '') === 'Consilier Test', 'Componența comisiilor trebuie să fie parsată din setările editate.');

    $store = roapp_blank_store();
    $store['records'] = $payload['records'];
    $store['settings'] = $payload['settings'];
    $store['source'] = ['archive' => 'v157.zip', 'counts' => ['roDisp' => 189, 'roHot' => 362]];
    $check(roapp_validate_store($store) === [], 'Structura importată trebuie să treacă validarea bazei locale.');

    $disp = $payload['records']['roDisp'][0];
    $dispMap = roapp_word_values('roDisp', $disp, $payload['settings']);
    $check(($dispMap['nr'] ?? '') === '3', 'Maparea DOCX roDisp pentru nr. trebuie să folosească câmpul 1.');
    $check(($dispMap['titlu'] ?? '') === $disp['values'][2], 'Maparea DOCX roDisp pentru titlu trebuie să fie exactă.');
    $check(($dispMap['compartiment'] ?? '') === 'Secretar general', 'Codul istoric al compartimentului trebuie rezolvat din setările v157.');

    $hot = $payload['records']['roHot'][349];
    $hotMap = roapp_word_values('roHot', $hot, $payload['settings']);
    $check(($hotMap['nrpr'] ?? '') === $hot['values'][3], 'Maparea DOCX roHot pentru numărul proiectului trebuie să fie exactă.');
    $check(($hotMap['nr'] ?? '') === $hot['values'][25], 'Maparea DOCX roHot pentru numărul hotărârii trebuie să fie exactă.');
    $check(($hotMap['sitvot'] ?? '') === $hot['values'][27], 'Situația agregată a votului trebuie păstrată.');
    $check(($hotMap['v1'] ?? 'not blank') === '', 'Voturile nominale nu trebuie inventate din totalul agregat.');

    $voteHot = $hot;
    $voteHot['values'][26] = 'Președinte Test';
    $voteHot['values'][27] = "5 voturi pentru\n2 abțineri";
    $voteMap = roapp_word_values('roHot', $voteHot, $payload['settings']);
    $check(($voteMap['pres'] ?? '') === 'Președinte Test', 'Semnătura hotărârii finale trebuie mapată din președintele de ședință.');
    $check(($voteMap['sitvot'] ?? '') === $voteHot['values'][27], 'Situația votului introdusă manual trebuie trimisă către template.');
    $hotHtml = roapp_html_document('roHot', $voteHot, $payload['settings'], 'hotN');
    $check(str_contains($hotHtml, 'PREȘEDINTE DE ȘEDINȚĂ') && str_contains($hotHtml, 'Președinte Test'), 'Hotărârea HTML trebuie să aibă semnatarul președintele de ședință.');
    $check(!str_contains($hotHtml, '<strong>PRIMAR</strong>'), 'Primarul nu trebuie afișat ca semnatar pentru hotărârea finală.');
    $check(str_contains($hotHtml, '5 voturi pentru') && str_contains($hotHtml, '2 abțineri'), 'Situația votului introdusă manual trebuie să apară în hotărârea HTML/PDF.');
    $dispProjectHtml = roapp_html_document('roDisp', $disp, $payload['settings'], 'dispoN_pr');
    $hotProjectHtml = roapp_html_document('roHot', $voteHot, $payload['settings'], 'hotN_pr');
    $check(str_contains($dispProjectHtml, 'PROIECT DE DISPOZIȚIE'), 'Proiectul de dispoziție trebuie să poată fi randat pentru tipărire.');
    $check(str_contains($hotProjectHtml, 'PROIECT DE HOTĂRÂRE'), 'Proiectul de hotărâre trebuie să poată fi randat pentru tipărire.');

    $individualDisp = $disp;
    $individualDisp['id'] = 'd-individual-test';
    $individualDisp['values'][6] = 'Individual';
    $rejectedDisp = $disp;
    $rejectedDisp['id'] = 'd-rejected-test';
    $rejectedDisp['values'][6] = 'Respinsă';
    $individualHot = $hot;
    $individualHot['id'] = 'h-individual-test';
    $individualHot['values'][10] = 'Individual';
    $rejectedHot = $hot;
    $rejectedHot['id'] = 'h-rejected-test';
    $rejectedHot['values'][10] = 'Respinsa';
    foreach ([['roDisp', $individualDisp], ['roHot', $individualHot]] as [$testModule, $testRecord]) {
        foreach (['view', 'final', 'project'] as $action) {
            $check(!roapp_readonly_action_allowed($testModule, $testRecord, $action), $testModule . ': Tip Act Individual nu trebuie să permită acțiunea ' . $action . '.');
        }
        $check(roapp_readonly_document_options($testModule, $testRecord) === [], $testModule . ': documentele pentru Tip Act Individual nu trebuie afișate în rețea.');
    }
    foreach ([['roDisp', $rejectedDisp, 'dispoN', 'dispoN_pr'], ['roHot', $rejectedHot, 'hotN', 'hotN_pr']] as [$testModule, $testRecord, $finalKey, $projectKey]) {
        $check(!roapp_readonly_action_allowed($testModule, $testRecord, 'view'), $testModule . ': Tip Act Respinsă nu trebuie să permită vizualizarea în rețea.');
        $check(!roapp_readonly_action_allowed($testModule, $testRecord, 'final'), $testModule . ': Tip Act Respinsă nu trebuie să permită actul final.');
        $check(roapp_readonly_action_allowed($testModule, $testRecord, 'project'), $testModule . ': Tip Act Respinsă trebuie să păstreze proiectul.');
        $check(!roapp_readonly_action_allowed($testModule, $testRecord, 'document', $finalKey), $testModule . ': tipărirea directă a actului final Respinsă trebuie refuzată.');
        $check(roapp_readonly_action_allowed($testModule, $testRecord, 'document', $projectKey), $testModule . ': proiectul Respinsă trebuie să rămână disponibil pentru tipărire.');
    }
    $dispReadonlyOptions = roapp_readonly_document_options('roDisp', $disp);
    $hotReadonlyOptions = roapp_readonly_document_options('roHot', $hot);
    $check(array_values($dispReadonlyOptions) === ['Dispoziție', 'Proiect dispoziție', 'Referat de specialitate'], 'Dispoziții în rețea trebuie să afișeze doar cele trei documente cerute, fără „normativă”.');
    $check(array_values($hotReadonlyOptions) === ['Hotărâre', 'Proiect hotărâre', 'Referat de specialitate'], 'Hotărâri în rețea trebuie să afișeze doar cele trei documente cerute, fără „normativă”.');
    $dispReadonlyPrint = roapp_html_document('roDisp', $disp, $payload['settings'], 'dispoN', true);
    $check(str_contains($dispReadonlyPrint, 'href="?module=roDisp&amp;id=' . $disp['id'] . '"') && !str_contains($dispReadonlyPrint, 'history.back'), 'Butonul Înapoi din tipărire trebuie să indice direct înregistrarea de origine, nu history.back().');
    $rejectedReadonlyPrint = roapp_html_document('roHot', $rejectedHot, $payload['settings'], 'hotN_pr', true);
    $check(str_contains($rejectedReadonlyPrint, 'href="?module=roHot"'), 'Din tipărirea unui proiect Respinsă, revenirea trebuie să ducă la registru, fără a ocoli blocarea vizualizării.');

    $check(roapp_display_text('linie\\nlinie') === "linie\nlinie", 'Secvențele \n istorice trebuie afișate pe linii separate.');
    $check(roapp_validate_new_record('roDisp', ['1', '02.05.2026', 'Test', '', '', '', 'Normativ', '', '', '', '']) === [], 'O dispoziție nouă validă trebuie acceptată.');
    $check(roapp_validate_new_record('roDisp', ['1', '31.02.2026', 'Test', '', '', '', 'Normativ', '', '', '', '']) !== [], 'O dată imposibilă trebuie respinsă.');
    $check(roapp_validate_new_record('roHot', ['03.05.2026', '16:00', 'ordinară', '1', '28.04.2026', 'Test', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '']) === [], 'Un proiect de hotărâre complet trebuie acceptat.');

    $oldDataDir = getenv('ROAPP_DATA_DIR');
    $testDataDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'roapp-test-' . bin2hex(random_bytes(5));
    putenv('ROAPP_DATA_DIR=' . $testDataDir);
    try {
        roapp_replace_store($store);
        $roundTrip = roapp_load_store();
        $check(count($roundTrip['records']['roDisp']) === 189 && count($roundTrip['records']['roHot']) === 362, 'Salvarea și reîncărcarea JSON trebuie să păstreze ambele registre.');
        roapp_mutate_store(static function (array &$testStore): void {
            $testStore['settings']['institution']['location'] = 'Test temporar';
        });
        $check(roapp_load_store()['settings']['institution']['location'] === 'Test temporar', 'Modificarea tranzacțională trebuie să fie vizibilă după reload.');
        roapp_mutate_store(static function (array &$testStore) use ($parsedInitiators, $parsedMembers): void {
            $testStore['settings']['initiators'] = $parsedInitiators;
            $testStore['settings']['commission_members'] = $parsedMembers;
        });
        $savedSettings = roapp_load_store()['settings'];
        $check(($savedSettings['initiators'][0]['name'] ?? '') === 'Nume Test' && ($savedSettings['commission_members']['Comisia test'][0] ?? '') === 'Consilier Test', 'Listele editate din Setări trebuie să se salveze și să poată fi reîncărcate.');
        roapp_mutate_store(static function (array &$testStore) use ($hot, $voteHot): void {
            foreach ($testStore['records']['roHot'] as &$record) {
                if (($record['id'] ?? '') === $hot['id']) {
                    $record = $voteHot;
                    $record['updated_at'] = date(DATE_ATOM);
                    break;
                }
            }
            unset($record);
        });
        $roundTripHot = roapp_lookup_record(roapp_load_store(), 'roHot', (string) $hot['id']);
        $check(($roundTripHot['values'][27] ?? '') === $voteHot['values'][27], 'Votul introdus manual trebuie persistat după salvarea și reîncărcarea înregistrării.');
        file_put_contents(roapp_store_path(), '{corrupt');
        roapp_replace_store($store);
        $check(roapp_validate_store(roapp_load_store()) === [], 'Restaurarea trebuie să poată înlocui o bază JSON coruptă.');
    } finally {
        if (is_dir($testDataDir)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testDataDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $entry) {
                $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            }
            @rmdir($testDataDir);
        }
        if ($oldDataDir === false) {
            putenv('ROAPP_DATA_DIR');
        } else {
            putenv('ROAPP_DATA_DIR=' . $oldDataDir);
        }
    }

    if (class_exists('DOMDocument') && class_exists('DOMXPath')) {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->loadXML('<w:root xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:sdt><w:sdtContent><w:p><w:r><w:t>placeholder</w:t></w:r></w:p></w:sdtContent></w:sdt></w:root>');
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $content = $xpath->query('//w:sdt/w:sdtContent')->item(0);
        $check(roapp_set_content_control_text($dom, $xpath, $content, "Primul rând\nAl doilea") === 1, 'Înlocuirea controlului Word trebuie să raporteze un control modificat.');
        $check(str_contains($dom->saveXML(), '<w:br'), 'Liniile multiple trebuie convertite în w:br în DOCX.');

        if (class_exists('ZipArchive')) {
            $previousDataDir = getenv('ROAPP_DATA_DIR');
            $wordTestDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'roapp-word-test-' . bin2hex(random_bytes(5));
            putenv('ROAPP_DATA_DIR=' . $wordTestDir);
            try {
                foreach ([
                    ['roDisp', $disp, 'dispoN', 'nr', '3'],
                    ['roDisp', $disp, 'dispoN_pr', 'nrpr', (string) $disp['values'][7]],
                    ['roHot', $hot, 'hotN_pr', 'nrpr', (string) $hot['values'][3]],
                    ['roHot', $voteHot, 'hotN', 'pres', 'Președinte Test'],
                    ['roHot', $voteHot, 'hotN', 'sitvot', $voteHot['values'][27]],
                ] as [$testModule, $testRecord, $testTemplate, $testTag, $expectedValue]) {
                    $generatedDocx = roapp_create_filled_docx($testModule, $testRecord, $payload['settings'], $testTemplate);
                    $generatedZip = new ZipArchive();
                    $opened = $generatedZip->open($generatedDocx);
                    $generatedXml = $opened === true ? $generatedZip->getFromName('word/document.xml') : false;
                    if ($opened === true) {
                        $generatedZip->close();
                    }
                    $check(is_string($generatedXml), $testModule . ': DOCX-ul generat trebuie să conțină document.xml.');
                    if (is_string($generatedXml)) {
                        $controlDom = new DOMDocument('1.0', 'UTF-8');
                        $controlDom->loadXML($generatedXml, LIBXML_NONET);
                        $controlXPath = new DOMXPath($controlDom);
                        $controlXPath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
                        $controlNodes = $controlXPath->query('//w:sdt[w:sdtPr/w:tag[@w:val="' . $testTag . '"]]/w:sdtContent');
                        $actualValue = $controlNodes && $controlNodes->length > 0 ? $controlNodes->item(0)->textContent : '';
                        $expectedInXml = str_contains((string) $expectedValue, "\n") ? str_replace("\n", '', (string) $expectedValue) : (string) $expectedValue;
                        $check($actualValue === $expectedInXml, $testModule . ': valoarea mapeată trebuie să fie scrisă în controlul Word original.');
                    }
                }
            } finally {
                if (is_dir($wordTestDir)) {
                    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($wordTestDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
                    foreach ($iterator as $entry) {
                        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
                    }
                    @rmdir($wordTestDir);
                }
                if ($previousDataDir === false) {
                    putenv('ROAPP_DATA_DIR');
                } else {
                    putenv('ROAPP_DATA_DIR=' . $previousDataDir);
                }
            }
        }
    }
} catch (Throwable $error) {
    $failed[] = 'Excepție în teste: ' . $error->getMessage();
}

foreach ($failed as $message) {
    fwrite(STDERR, "FAIL: {$message}\n");
}
echo sprintf("PHP tests: %d passed, %d failed\n", $passed, count($failed));
exit($failed === [] ? 0 : 1);
