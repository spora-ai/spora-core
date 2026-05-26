#!/bin/bash
cp tests/Unit/Http/KernelTest.php tests/Unit/Http/KernelTest.php.bak
NUM_TESTS=$(grep -c "test(" tests/Unit/Http/KernelTest.php)
for i in $(seq 1 $NUM_TESTS); do
    awk -v var=$i '/test\(/ { count++ } { if (count != var) print $0 }' tests/Unit/Http/KernelTest.php.bak > tests/Unit/Http/KernelTest.php
    php -d display_errors=1 ./vendor/bin/pest tests/Unit/Http/KernelTest.php >/dev/null 2>&1
    RES=$?
    if [ $RES -eq 0 ]; then
        echo "Test $i is the culprit!"
    fi
done
mv tests/Unit/Http/KernelTest.php.bak tests/Unit/Http/KernelTest.php
