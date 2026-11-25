<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MediaArchive;

use Spora\Services\MediaArchive\ImageRequestBudget;

describe('ImageRequestBudget', function (): void {
    it('refuses an image past the count cap, whatever its size', function (): void {
        // The count is the other half of the ceiling: a request with more
        // images than the endpoint takes is refused whole, and for Claude
        // the count is also what keeps the request out of the many-image
        // rule.
        $budget = new ImageRequestBudget(1_000_000, 2);

        expect($budget->accepts(10))->toBeTrue()
            ->and($budget->accepts(10))->toBeTrue()
            ->and($budget->accepts(10))->toBeFalse();
    });

    it('refuses an image over the byte ceiling without spending the count', function (): void {
        // The encoded size is the one the provider measures, and an image
        // that does not fit takes no room in the request either.
        $budget = new ImageRequestBudget(100, 2);

        expect($budget->accepts(101))->toBeFalse()
            ->and($budget->accepts(100))->toBeTrue();
    });

    it('starts the count again when the budget is reset', function (): void {
        // The budget is shared by the rows of one request. The next
        // request is built from the same rows, and it must not be starved
        // by the count the previous one spent.
        $budget = new ImageRequestBudget(1_000_000, 1);

        expect($budget->accepts(10))->toBeTrue()
            ->and($budget->accepts(10))->toBeFalse();

        $budget->reset();

        expect($budget->accepts(10))->toBeTrue();
    });
});
