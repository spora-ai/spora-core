<?php
$lines = file('tests/Unit/Http/KernelTest.php');
foreach ($lines as $line) {
    if (strpos($line, 'test(') !== false) echo $line;
}
