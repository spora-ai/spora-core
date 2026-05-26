<?php
echo "Current handler: " . (is_callable(set_error_handler(function(){})) ? 'set' : 'none') . "\n";
restore_error_handler();
