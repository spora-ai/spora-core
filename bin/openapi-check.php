<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

exit(\Spora\Console\Commands\OpenApiGenerateCommand::checkAgainstFile($argv[1] ?? 'openapi.json'));
