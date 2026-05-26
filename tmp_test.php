<?php
echo "START\n";
passthru('php ./vendor/bin/pest tests/Unit/Http/KernelTest.php', $ret);
echo "END: $ret\n";
