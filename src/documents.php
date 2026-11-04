<?php
declare(strict_types=1);

require_once __DIR__ . '/store.php';

function roapp_document_options(string $module): array
{
    if ($module === 'roDisp') {
        return [
            'dispoN' => 'Dispoziție normativă',
            'dispoI' => 'Dispoziție individuală',
            'dispoN_pr' => 'Proiect de dispoziție normativă',
            'dispoI_pr' => 'Proiect de dispoziție individuală',
            'dispoRef' => 'Referat de specialitate',
            'dispoAdr' => 'Adresă către Prefectură',
        ];
    }
    return [
        'hotN' => 'Hotărâre normativă',
        'hotI' => 'Hotărâre individuală',
        'hotN_pr' => 'Proiect de hotărâre normativă',
        'hotI_pr' => 'Proiect de hotărâre individuală',
        'hotRef' => 'Referat de specialitate',
        'conv' => 'Convocator / ordine de zi',
        'anunt_hot' => 'Anunț public',
        'adresa_hot' => 'Adresă către Prefectură',
    ];
}

function roapp_safe_text(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function roapp_upper(string $value): string
{
    return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
}

function roapp_display_text(string $value): string
{
    return str_replace(['\\r\\n', '\\n', '\\r'], "\n", $value);
}

function roapp_split_lines(string $value): array
{
    $normalized = str_replace(["\r\n", "\r", '\\r\\n', '\\n', '\\r'], "\n", $value);
    return explode("\n", $normalized);
}

function roapp_print_paragraphs(string $value, string $class = ''): string
{
    $parts = roapp_split_lines($value);
    $html = '';
    foreach ($parts as $part) {
        if (trim($part) === '') {
            $html .= '<p class="blank-line" aria-hidden="true">&nbsp;</p>';
        } else {
            $html .= '<p' . ($class !== '' ? ' class="' . roapp_safe_text($class) . '"' : '') . '>' . roapp_safe_text($part) . '</p>';
        }
    }
    return $html;
}

function roapp_setting(array $settings, string $name, string $fallback = ''): string
{
    $value = $settings['institution'][$name] ?? $fallback;
    return is_string($value) ? $value : $fallback;
}

function roapp_list_entry(array $settings, string $group, string $index): array
{
    $numeric = filter_var(trim($index), FILTER_VALIDATE_INT);
    if ($numeric === false || $numeric < 0) {
        return ['label' => '', 'name' => ''];
    }
    $list = $settings[$group] ?? [];
    $entry = is_array($list) ? ($list[$numeric] ?? null) : null;
    if (is_array($entry)) {
        return ['label' => (string) ($entry['label'] ?? ''), 'name' => (string) ($entry['name'] ?? '')];
    }
    return ['label' => is_string($entry) ? $entry : '', 'name' => ''];
}

function roapp_word_values(string $module, array $record, array $settings): array
{
    $v = $record['values'] ?? [];
    $blank = static fn(int $i): string => is_string($v[$i] ?? null) ? roapp_display_text($v[$i]) : '';
    $loc = roapp_setting($settings, 'location', 'Beba Veche');
    $map = [
        'loc' => $loc,
        'primar' => roapp_setting($settings, 'mayor'),
        'secretar' => roapp_setting($settings, 'secretary'),
        'emol' => roapp_setting($settings, 'monitorUrl'),
        'adresaPJT' => '',
        'anexe' => '',
    ];
    if ($module === 'roDisp') {
        $department = roapp_list_entry($settings, 'departments', $blank(8));
        $map += [
            'nr' => $blank(0),
            'data' => $blank(1),
            'titlu' => $blank(2),
            'legislatie' => $blank(3),
            'articole' => $blank(4),
            'articole_GRPD' => $blank(5),
            'tip' => $blank(6),
            'nrpr' => $blank(7),
            'compartiment' => $department['label'],
            'numecomp' => $department['name'],
            'nrdataref' => $blank(9),
            'referat' => '', // Câmpul 11 este un indicator istoric, nu text de referat.
        ];
        return $map;
    }

    $initiator = roapp_list_entry($settings, 'initiators', $blank(6));
    $reporter = roapp_list_entry($settings, 'reporters', $blank(12));
    $department = roapp_list_entry($settings, 'departments', $blank(15));
    $commission = roapp_list_entry($settings, 'commissions', $blank(20));
    $committee = $blank(21);
    $committeePresident = preg_replace('/^\s*presedinte\s+/iu', '', $committee) ?? $committee;
    $map += [
        'data' => $blank(0),
        'ora' => $blank(1),
        'tipsed' => $blank(2),
        'nrpr' => $blank(3),
        'datapr' => $blank(4),
        'titlu' => $blank(5),
        'init' => $initiator['label'],
        'numeinit' => $blank(7) !== '' ? $blank(7) : $initiator['name'],
        'legislatie' => $blank(8),
        'articole' => $blank(9),
        'tip' => $blank(10),
        'grpd' => $blank(11),
        'raportor' => $reporter['label'],
        'numerap' => $blank(13) !== '' ? $blank(13) : $reporter['name'],
        'nrrap' => $blank(14),
        'compartiment' => $department['label'],
        'numecomp' => $blank(16) !== '' ? $blank(16) : $department['name'],
        'nrdataref' => $blank(17),
        'referat' => $blank(18),
        'comisie' => $commission['label'] !== '' ? $commission['label'] : $committee,
        'prescom' => $committeePresident,
        'majoritate' => $blank(22),
        'nrdisp' => $blank(23),
        'convoc' => $blank(24),
        'nr' => $blank(25),
        'pres' => $blank(26),
        'sitvot' => $blank(27),
        'nrdatarap' => $blank(14),
    ];
    // În arhiva v157 există doar totalul votului, nu și votul individual al fiecărui consilier.
    for ($i = 1; $i <= 9; $i++) {
        $map['v' . $i] = '';
    }
    return $map;
}

function roapp_template_path(string $module, string $template): string
{
    $allowed = roapp_document_options($module);
    if (!isset($allowed[$template])) {
        throw new RuntimeException('Tipul de document selectat nu este acceptat.');
    }
    return roapp_root() . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . $template . '.docx';
}

function roapp_set_content_control_text(DOMDocument $dom, DOMXPath $xpath, DOMElement $content, string $value): int
{
    $textNodes = $xpath->query('.//w:t', $content);
    if (!$textNodes || $textNodes->length === 0) {
        if ($value === '') {
            return 0;
        }
        $paragraphs = $xpath->query('.//w:p', $content);
        if (!$paragraphs || $paragraphs->length === 0) {
            return 0;
        }
        $run = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:r');
        $paragraphs->item(0)->appendChild($run);
        $firstText = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:t');
        $firstText->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
        $run->appendChild($firstText);
        $textNodes = $xpath->query('.//w:t', $content);
        if (!$textNodes || $textNodes->length === 0) {
            return 0;
        }
    }
    $first = $textNodes->item(0);
    $parts = roapp_split_lines($value);
    $first->nodeValue = array_shift($parts) ?? '';
    for ($i = 1; $i < $textNodes->length; $i++) {
        $textNodes->item($i)->nodeValue = '';
    }
    $anchor = $first;
    foreach ($parts as $part) {
        $parent = $anchor->parentNode;
        if (!$parent) {
            break;
        }
        $break = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:br');
        $parent->insertBefore($break, $anchor->nextSibling);
        $text = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:t');
        $text->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
        $text->appendChild($dom->createTextNode($part));
        $parent->insertBefore($text, $break->nextSibling);
        $anchor = $text;
    }
    return 1;
}

function roapp_create_filled_docx(string $module, array $record, array $settings, string $template): string
{
    if (!class_exists('ZipArchive') || !class_exists('DOMDocument') || !class_exists('DOMXPath')) {
        throw new RuntimeException('Generarea din template-ul Word necesită extensiile PHP zip și dom. Folosiți tipărirea din browser sau activați extensiile.');
    }
    $source = roapp_template_path($module, $template);
    if (!is_file($source)) {
        throw new RuntimeException('Template-ul Word original lipsește: ' . $template . '.docx');
    }
    roapp_ensure_directories();
    $base = $module . '_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $record['id']) . '_' . $template . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
    $docxPath = roapp_generated_dir() . DIRECTORY_SEPARATOR . $base . '.docx';
    if (!@copy($source, $docxPath)) {
        throw new RuntimeException('Nu s-a putut copia template-ul Word în zona de lucru privată.');
    }
    $zip = new ZipArchive();
    if ($zip->open($docxPath) !== true) {
        @unlink($docxPath);
        throw new RuntimeException('Template-ul Word nu este un DOCX valid.');
    }
    try {
        $xml = $zip->getFromName('word/document.xml');
        if (!is_string($xml)) {
            throw new RuntimeException('Template-ul Word nu conține document.xml.');
        }
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = true;
        $oldErrors = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($oldErrors);
        if (!$loaded) {
            throw new RuntimeException('Structura XML a template-ului Word nu poate fi citită.');
        }
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $map = roapp_word_values($module, $record, $settings);
        $matched = 0;
        foreach ($map as $tag => $value) {
            $literal = "'" . str_replace("'", "&apos;", $tag) . "'";
            $nodes = $xpath->query('//w:sdt[w:sdtPr/w:tag[@w:val=' . $literal . ']]/w:sdtContent');
            if (!$nodes) {
                continue;
            }
            foreach ($nodes as $node) {
                if ($node instanceof DOMElement) {
                    $matched += roapp_set_content_control_text($dom, $xpath, $node, (string) $value);
                }
            }
        }
        if ($matched < 1) {
            throw new RuntimeException('Template-ul nu are controale Word compatibile cu câmpurile acestui modul.');
        }
        $updated = $dom->saveXML();
        if (!is_string($updated) || !$zip->addFromString('word/document.xml', $updated)) {
            throw new RuntimeException('Nu s-au putut aplica valorile în template-ul Word.');
        }
        if (!$zip->close()) {
            throw new RuntimeException('Nu s-a putut finaliza documentul DOCX completat.');
        }
    } catch (Throwable $error) {
        @$zip->close();
        @unlink($docxPath);
        throw $error;
    }
    return $docxPath;
}

function roapp_create_word_pdf(string $module, array $record, array $settings, string $template): array
{
    if (PHP_OS_FAMILY !== 'Windows') {
        throw new RuntimeException('Randarea PDF prin Microsoft Word este disponibilă numai pe Windows. Puteți folosi în continuare „Tipărire / PDF” din browser.');
    }
    if (!function_exists('proc_open')) {
        throw new RuntimeException('proc_open este dezactivat în PHP; helperul Word nu poate fi lansat.');
    }
    $script = roapp_root() . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'render-word.ps1';
    if (!is_file($script)) {
        throw new RuntimeException('Lipsește helperul local pentru randarea Microsoft Word.');
    }
    $docxPath = roapp_create_filled_docx($module, $record, $settings, $template);
    $pdfPath = preg_replace('/\.docx$/i', '.pdf', $docxPath);
    $command = [
        'powershell.exe', '-NoLogo', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass',
        '-File', $script, '-InputPath', $docxPath, '-OutputPath', $pdfPath,
    ];
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = @proc_open($command, $descriptors, $pipes, roapp_root());
    if (!is_resource($process)) {
        @unlink($docxPath);
        throw new RuntimeException('Nu s-a putut porni PowerShell pentru conversia Word → PDF.');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $start = microtime(true);
    $exitCode = null;
    while (true) {
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (!$status['running']) {
            $exitCode = $status['exitcode'];
            break;
        }
        if (microtime(true) - $start > 120) {
            proc_terminate($process);
            $exitCode = 124;
            break;
        }
        usleep(200000);
    }
    $stdout .= (string) stream_get_contents($pipes[1]);
    $stderr .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closedCode = proc_close($process);
    if ($exitCode === null || $exitCode < 0) {
        $exitCode = $closedCode;
    }
    if ($exitCode !== 0 || !is_file($pdfPath) || filesize($pdfPath) < 100) {
        error_log('roapp Word PDF failed: ' . trim($stderr . ' ' . $stdout));
        throw new RuntimeException('Microsoft Word nu a generat PDF-ul. Verificați că Word este instalat, că sesiunea Windows îl poate automatiza și că nu există o fereastră de dialog blocată. Folosiți temporar tipărirea din browser.');
    }
    return ['pdf' => $pdfPath, 'docx' => $docxPath];
}

function roapp_html_document(string $module, array $record, array $settings, string $template, bool $readOnly = false): string
{
    $options = roapp_document_options($module);
    if (!isset($options[$template])) {
        throw new RuntimeException('Tipul de document selectat nu este acceptat.');
    }
    $v = $record['values'];
    $at = static fn(int $i): string => roapp_display_text((string) ($v[$i] ?? ''));
    $institution = roapp_setting($settings, 'name', 'Comuna Beba Veche');
    $county = roapp_setting($settings, 'county', 'Timiș');
    $location = roapp_setting($settings, 'location', 'Beba Veche');
    $docTitle = $options[$template];
    $body = '';

    if ($module === 'roDisp') {
        $draft = str_ends_with($template, '_pr');
        $reference = $template === 'dispoRef';
        $address = $template === 'dispoAdr';
        $number = $draft ? $at(7) : $at(0);
        $date = $at(1);
        if ($reference) {
            $body .= '<h2>REFERAT DE SPECIALITATE</h2>';
            $body .= '<p class="center">Nr. / dată referat: ' . roapp_safe_text($at(9)) . '</p>';
            $body .= '<h3 class="center">' . roapp_safe_text($at(2)) . '</h3>';
            $body .= '<p class="section-title">Temeiuri asociate:</p>' . roapp_print_paragraphs($at(3));
            $body .= '<p class="section-title">Articole / conținut asociat:</p>' . roapp_print_paragraphs($at(4));
            if ($at(10) !== '') {
                $body .= '<p class="small">Indicator legacy păstrat din arhivă: ' . roapp_safe_text($at(10)) . '</p>';
            }
        } elseif ($address) {
            $body .= '<h2>ADRESĂ</h2>';
            $body .= '<p class="center">Către Instituția Prefectului – Județul ' . roapp_safe_text($county) . '</p>';
            $body .= '<p>Vă înaintăm documentele aferente dispoziției nr. ' . roapp_safe_text($at(0)) . ' din ' . roapp_safe_text($date) . '.</p>';
            $body .= '<h3>' . roapp_safe_text($at(2)) . '</h3>';
        } else {
            $body .= '<h2>' . ($draft ? 'PROIECT DE DISPOZIȚIE' : 'DISPOZIȚIA NR. ' . roapp_safe_text($number)) . '</h2>';
            if (!$draft) {
                $body .= '<p class="center">din ' . roapp_safe_text($date) . '</p>';
            } else {
                $body .= '<p class="center">Nr. proiect ' . roapp_safe_text($number) . ' din ' . roapp_safe_text($date) . '</p>';
            }
            $body .= '<h3 class="center">privind ' . roapp_safe_text($at(2)) . '</h3>';
            $body .= '<p>Primarul ' . roapp_safe_text($institution) . ', județul ' . roapp_safe_text($county) .';</p>';
            $body .= '<p>Având în vedere:</p>' . roapp_print_paragraphs($at(3));
            $body .= '<p>În temeiul prevederilor legale aplicabile,</p>';
            $body .= '<p class="center strong">' . ($draft ? 'PROPUNE:' : 'DISPUNE:') . '</p>';
            $body .= roapp_print_paragraphs($at(4));
            if ($at(5) !== '') {
                $body .= '<p class="small">' . roapp_safe_text($at(5)) . '</p>';
            }
        }
    } else {
        $draft = str_ends_with($template, '_pr');
        $reference = $template === 'hotRef';
        $convocation = $template === 'conv';
        $announcement = $template === 'anunt_hot';
        $address = $template === 'adresa_hot';
        if ($reference) {
            $body .= '<h2>REFERAT DE SPECIALITATE</h2>';
            $body .= '<p class="center">' . roapp_safe_text($at(14)) . '</p>';
            $body .= '<h3 class="center">' . roapp_safe_text($at(5)) . '</h3>';
            $body .= roapp_print_paragraphs($at(8));
            $body .= roapp_print_paragraphs($at(18));
        } elseif ($convocation) {
            $body .= '<h2>CONVOCATOR / ORDINE DE ZI</h2>';
            $body .= '<p>Ședință ' . roapp_safe_text($at(2)) . ' din ' . roapp_safe_text($at(0)) . ', ora ' . roapp_safe_text($at(1)) . '.</p>';
            $body .= roapp_print_paragraphs($at(24));
        } elseif ($announcement) {
            $body .= '<h2>ANUNȚ PUBLIC</h2><h3 class="center">' . roapp_safe_text($at(5)) . '</h3>';
            $body .= '<p>Proiect de hotărâre supus transparenței decizionale în cadrul ' . roapp_safe_text($institution) . '.</p>';
            $body .= '<p>Data ședinței: ' . roapp_safe_text($at(0)) . '</p>';
            $body .= '<p>' . roapp_safe_text(roapp_setting($settings, 'monitorUrl')) . '</p>';
        } elseif ($address) {
            $body .= '<h2>ADRESĂ</h2><p>Către Instituția Prefectului – Județul ' . roapp_safe_text($county) . '.</p>';
            $body .= '<p>Vă înaintăm Hotărârea nr. ' . roapp_safe_text($at(25)) . ' din ' . roapp_safe_text($at(0)) . '.</p>';
            $body .= '<h3>' . roapp_safe_text($at(5)) . '</h3>';
        } else {
            $number = $draft ? $at(3) : $at(25);
            $date = $draft ? $at(4) : $at(0);
            $body .= '<h2>' . ($draft ? 'PROIECT DE HOTĂRÂRE' : 'HOTĂRÂREA NR. ' . roapp_safe_text($number)) . '</h2>';
            $body .= '<p class="center">' . ($draft ? 'Proiect nr. ' . roapp_safe_text($number) . ' din ' . roapp_safe_text($date) : 'din ' . roapp_safe_text($date)) . '</p>';
            $body .= '<h3 class="center">privind ' . roapp_safe_text($at(5)) . '</h3>';
            $body .= '<p>Consiliul Local al ' . roapp_safe_text($institution) . ', județul ' . roapp_safe_text($county) . ', întrunit în ședință ' . roapp_safe_text($at(2)) . ' din ' . roapp_safe_text($at(0)) . ';</p>';
            $body .= '<p>Având în vedere:</p>' . roapp_print_paragraphs($at(8));
            $body .= roapp_print_paragraphs($at(9));
            if ($at(18) !== '') {
                $body .= '<p class="section-title">Referat:</p>' . roapp_print_paragraphs($at(18));
            }
            if ($at(27) !== '') {
                $body .= '<p class="section-title">Situația votului</p>' . roapp_print_paragraphs($at(27));
            }
        }
    }

    if ($module === 'roHot' && in_array($template, ['hotN_pr', 'hotI_pr'], true)) {
            $initiator = roapp_list_entry($settings, 'initiators', $at(6));
            $leftRole = 'INIȚIATOR';
            $leftName = $at(7) !== '' ? $at(7) : $initiator['name'];
    } elseif ($module === 'roHot' && in_array($template, ['hotN', 'hotI'], true)) {
            $leftRole = 'PREȘEDINTE DE ȘEDINȚĂ';
            $leftName = trim($at(26)) !== '' ? $at(26) : 'NECOMPLETAT — verificați președintele de ședință';
    } else {
        $leftRole = 'PRIMAR';
        $leftName = roapp_setting($settings, 'mayor');
    }
    $footer = '<div class="signatures"><div><strong>' . roapp_safe_text($leftRole) . '</strong><br>' . roapp_safe_text($leftName) . '</div><div><strong>SECRETAR GENERAL</strong><br>' . roapp_safe_text(roapp_setting($settings, 'secretary')) . '</div></div>';
    $returnUrl = '?module=' . rawurlencode($module);
    if (!$readOnly || roapp_readonly_action_allowed($module, $record, 'view')) {
        $returnUrl .= '&id=' . rawurlencode((string) ($record['id'] ?? ''));
    }
    return '<!doctype html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . roapp_safe_text($docTitle) . '</title><style>'
        . '@page{size:A4;margin:22mm 24mm 20mm}*{box-sizing:border-box}body{margin:0;background:#e9edf0;color:#171717;font-family:"Times New Roman",serif;font-size:12pt;line-height:1.35}.toolbar{position:sticky;top:0;background:#142a3b;color:white;padding:12px 20px;display:flex;justify-content:center;gap:12px;font:14px Arial,sans-serif}.toolbar button,.toolbar a{border:0;border-radius:6px;padding:10px 14px;font:inherit;text-decoration:none;cursor:pointer}.toolbar button{background:#38b8a2;color:#06251f;font-weight:bold}.toolbar a{background:#fff;color:#142a3b}.sheet{width:210mm;min-height:297mm;margin:22px auto;padding:22mm 24mm 20mm;background:white;box-shadow:0 8px 28px #172b3b22}.heading{text-align:center;font-weight:bold;line-height:1.45;margin-bottom:32px}.heading .org{font-size:12pt}.heading .country{font-size:12pt}.heading .name{font-size:13pt;text-transform:uppercase}.heading .rule{border-top:1px solid #222;width:38%;margin:7px auto}.content p{margin:0 0 10px;text-align:justify;white-space:pre-wrap}.content .center{text-align:center}.content h2{font-size:14pt;text-align:center;margin:22px 0 8px}.content h3{font-size:12pt;margin:18px 0 12px}.content .strong{font-weight:bold}.content .small{font-size:10pt}.content .section-title{font-weight:bold;margin-top:14px}.content .blank-line{height:8px;margin:0}.signatures{display:flex;justify-content:space-between;gap:30px;margin-top:44px;text-align:center;font-size:11pt;break-inside:avoid}.signatures>div{width:45%}.notice{margin:10px auto;text-align:center;color:#445;font:12px Arial,sans-serif;max-width:700px}@media print{body{background:white}.toolbar,.notice{display:none!important}.sheet{width:auto;min-height:0;margin:0;padding:0;box-shadow:none}}@media screen and (max-width:800px){.sheet{width:100%;min-height:0;margin:0;padding:16mm 14mm;box-shadow:none}.toolbar{position:static;flex-wrap:wrap}}@media(prefers-reduced-motion:reduce){*{scroll-behavior:auto!important;transition:none!important}}</style></head><body><div class="toolbar"><button type="button" onclick="window.print()">Imprimă / salvează ca PDF</button><a href="' . roapp_safe_text($returnUrl) . '">Înapoi la înregistrare</a></div><p class="notice">În fereastra de tipărire a browserului selectați destinația „Salvare ca PDF”. Această randare HTML este un fallback și nu este o comparație de fidelitate cu Word.</p><main class="sheet"><header class="heading"><div class="org">ROMÂNIA</div><div class="country">JUDEȚUL ' . roapp_safe_text(roapp_upper($county)) . '</div><div class="name">' . roapp_safe_text($institution) . '</div><div class="rule"></div><div>' . roapp_safe_text($location) . '</div></header><article class="content">' . $body . '</article>' . $footer . '</main></body></html>';
}
