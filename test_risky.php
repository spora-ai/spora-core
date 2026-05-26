<?php
$content = file_get_contents('tests/Unit/Http/KernelTest.php');
if (strpos($content, 'unset($kernel)') === false) {
    $content = str_replace(
        "expect(\$logContents)->toContain('16384');",
        "expect(\$logContents)->toContain('16384');\n        unset(\$kernel);",
        $content
    );
    file_put_contents('tests/Unit/Http/KernelTest.php', $content);
}
