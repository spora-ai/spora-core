#!/bin/bash
TESTS=$(grep -oP "(?<=test\(')[^']+" tests/Unit/Http/KernelTest.php)
while read -r test_name; do
    echo "Running: $test_name"
    php ./vendor/bin/pest tests/Unit/Http/KernelTest.php --filter="$test_name" >/dev/null 2>&1
    RES=$?
    if [ $RES -ne 0 ]; then
        echo "FAILED: $test_name (Code: $RES)"
    fi
done <<< "$TESTS"
