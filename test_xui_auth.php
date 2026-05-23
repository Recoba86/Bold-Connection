<?php

require_once __DIR__ . '/x-ui_auth.php';

function assertSameValue($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . PHP_EOL);
        fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
        fwrite(STDERR, 'Actual: ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

assertSameValue('token-123', xuiPanelApiToken(['api_token' => ' token-123 ']), 'trims a configured API token');
assertSameValue(null, xuiPanelApiToken(['api_token' => '   ']), 'blank API token falls back to cookie auth');
assertSameValue(null, xuiPanelApiToken([]), 'missing API token falls back to cookie auth');
assertSameValue(true, xuiUsesBearerAuth(['api_token' => 'token-123']), 'non-empty API token uses bearer auth');
assertSameValue(false, xuiUsesBearerAuth(['api_token' => '']), 'empty API token does not use bearer auth');

echo "x-ui auth tests passed\n";
