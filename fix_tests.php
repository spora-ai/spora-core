<?php
$file = 'tests/Unit/Http/KernelTest.php';
$content = file_get_contents($file);

// Clean up echo "Create K2\n"; ... done K2
$content = preg_replace('/echo "Create K2\\\\n";\s*\$kernel = new Kernel\(\);\s*echo "Done K2\\\\n";/', '$kernel = new Kernel();', $content);

// In tests where Kernel creates its own error handler because we mocked the environment, we must manually trigger __destruct() because set_error_handler creates a circular reference.
$content = preg_replace('/return \$kernel->handle/', '$res = $kernel->handle', $content);
$content = preg_replace('/\$res = \$kernel->handle(.*?);\s*\}\);/', '$res = $kernel->handle$1;' . "\n            \$kernel->__destruct();\n            return \$res;\n        });", $content);

file_put_contents($file, $content);
