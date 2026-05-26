<?php
$code = file_get_contents('tests/Unit/Http/KernelTest.php');
preg_match_all('/test\(\'(.*?)\'/', $code, $matches);
foreach($matches[1] as $test) {
    $out="";
    // Pest uses phpunit underneath. Filter allows regex. We need to preg_quote.
    $filter = preg_quote($test, '/');
    echo "\n\n# Running: $test\n";
    $cmd = 'php ./vendor/bin/pest tests/Unit/Http/KernelTest.php --filter=' . escapeshellarg('/' . $filter . '/');
    exec($cmd . ' 2>&1', $out, $ret);
    // Check if it actually ran the test
    $output = implode("\n", $out);
    echo $output . "\n";
    if (strpos($output, 'No tests found') !== false) {
        echo "  -> NO TESTS FOUND\n";
    } elseif ($ret !== 0) {
        echo "  -> FAILED (Code: $ret)\n";
    } else {
        echo "  -> PASSED\n";
    }
}
