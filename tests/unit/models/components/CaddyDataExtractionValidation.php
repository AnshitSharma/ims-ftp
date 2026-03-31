<?php

require_once __DIR__ . '/../../../../core/models/components/ComponentSpecPaths.php';
require_once __DIR__ . '/../../../../core/models/shared/DataExtractionUtilities.php';

function fail($message) {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertTrue($condition, $message) {
    if (!$condition) {
        fail($message);
    }
}

$paths = ComponentSpecPaths::getAll();
$caddyPath = $paths['caddy'] ?? null;
assertTrue(!empty($caddyPath) && file_exists($caddyPath), 'Missing caddy specification JSON');

$jsonData = json_decode(file_get_contents($caddyPath), true);
assertTrue(is_array($jsonData), 'Invalid caddy specification JSON');

$sampleCaddy = $jsonData['caddies'][0] ?? null;
assertTrue(!empty($sampleCaddy['uuid']), 'Could not find sample caddy UUID');

$dataUtils = new DataExtractionUtilities();
$resolvedCaddy = $dataUtils->getCaddyByUUID($sampleCaddy['uuid']);

assertTrue(!empty($resolvedCaddy), 'Caddy lookup failed through DataExtractionUtilities');
assertTrue(
    !empty($resolvedCaddy['compatibility']['size']) || !empty($resolvedCaddy['form_factor']) || !empty($resolvedCaddy['type']),
    'Resolved caddy is missing size/form factor metadata'
);

fwrite(STDOUT, "Caddy data extraction validation passed." . PHP_EOL);
?>
