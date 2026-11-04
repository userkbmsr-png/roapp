<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/store.php';

if (getenv('ROAPP_DATA_DIR') === false || trim((string) getenv('ROAPP_DATA_DIR')) === '') {
    fwrite(STDERR, "ROAPP_DATA_DIR must point to a disposable temporary directory.\n");
    exit(2);
}
$payload = roapp_seed_payload();
$store = roapp_blank_store();
$store['records'] = $payload['records'];
$store['settings'] = $payload['settings'];
$store['source'] = $payload['source'];
foreach ([
    ['roDisp', 'd_individual', 6, 2, 'Individual'],
    ['roDisp', 'd_rejected', 6, 2, 'Respinsa'],
    ['roHot', 'h_individual', 10, 5, 'Individual'],
    ['roHot', 'h_rejected', 10, 5, 'Respinsa'],
] as [$module, $id, $typeIndex, $titleIndex, $type]) {
    $fixture = $store['records'][$module][0];
    $fixture['id'] = $id;
    $fixture['values'][$typeIndex] = $type;
    $fixture['values'][$titleIndex] = 'HTTP fixture ' . $type;
    $store['records'][$module][] = $fixture;
}
roapp_replace_store($store);
