<?php

declare(strict_types=1);

use Spora\Models\PrincipalLocation;
use Spora\Services\PrincipalLocationService;

describe('PrincipalLocationService', function (): void {

    beforeEach(function (): void {
        $this->service = new PrincipalLocationService();
        $this->authService = bootAuthLayer();
        $this->userId = bootAuth($this->authService, 'pl-service@example.com', 'Password1!');
        $this->otherUserId = bootAuth($this->authService, 'pl-other@example.com', 'Password1!');
        $this->principalId = $this->createUserPrincipal($this->userId);
        $this->otherPrincipalId = $this->createUserPrincipal($this->otherUserId);
    });

    it('lists the locations of a principal', function (): void {
        $this->service->create($this->principalId, ['name' => 'HQ', 'address' => 'Musterstraße 1']);

        $locations = $this->service->listFor($this->principalId);

        expect($locations)->toHaveCount(1);
        expect($locations[0]['name'])->toBe('HQ');
        expect($locations[0]['address'])->toBe('Musterstraße 1');
        expect($locations[0]['is_default'])->toBeFalse();
    });

    it('lists nothing for a principal without locations', function (): void {
        expect($this->service->listFor($this->principalId))->toBe([]);
    });

    it('does not list the locations of another principal', function (): void {
        $this->service->create($this->otherPrincipalId, ['name' => 'Theirs', 'address' => 'Elsewhere 2']);

        expect($this->service->listFor($this->principalId))->toBe([]);
    });

    it('creates a location under the principal', function (): void {
        $result = $this->service->create($this->principalId, [
            'name'       => 'HQ',
            'address'    => 'Musterstraße 1',
            'is_default' => true,
        ]);

        expect($result['location']['name'])->toBe('HQ');
        expect($result['location']['is_default'])->toBeTrue();

        $row = PrincipalLocation::find($result['location']['id']);
        expect((int) $row->principal_id)->toBe($this->principalId);
    });

    it('promoting a default demotes the other defaults of the same principal', function (): void {
        $first = $this->service->create($this->principalId, ['name' => 'First', 'address' => 'A', 'is_default' => true]);
        $this->service->create($this->principalId, ['name' => 'Second', 'address' => 'B']);

        $this->service->create($this->principalId, ['name' => 'Third', 'address' => 'C', 'is_default' => true]);

        expect(PrincipalLocation::find($first['location']['id'])->is_default)->toBeFalse();
        expect($this->service->listFor($this->principalId))->toHaveCount(3);
        expect((int) PrincipalLocation::where('principal_id', $this->principalId)->where('is_default', true)->count())->toBe(1);
    });

    it('promoting a default leaves another principal alone', function (): void {
        $theirs = $this->service->create($this->otherPrincipalId, ['name' => 'Theirs', 'address' => 'B', 'is_default' => true]);

        $this->service->create($this->principalId, ['name' => 'Mine', 'address' => 'A', 'is_default' => true]);

        expect(PrincipalLocation::find($theirs['location']['id'])->is_default)->toBeTrue();
    });

    it('updates the fields of a location', function (): void {
        $created = $this->service->create($this->principalId, ['name' => 'Old', 'address' => 'Old address']);

        $result = $this->service->update($created['location']['id'], $this->principalId, [
            'name'    => 'New',
            'address' => 'New address',
        ]);

        expect($result['location']['name'])->toBe('New');
        expect($result['location']['address'])->toBe('New address');
    });

    it('promoting a default on update demotes the others of the same principal', function (): void {
        $first = $this->service->create($this->principalId, ['name' => 'First', 'address' => 'A', 'is_default' => true]);
        $second = $this->service->create($this->principalId, ['name' => 'Second', 'address' => 'B']);

        $this->service->update($second['location']['id'], $this->principalId, ['is_default' => true]);

        expect(PrincipalLocation::find($first['location']['id'])->is_default)->toBeFalse();
        expect((int) PrincipalLocation::where('principal_id', $this->principalId)->where('is_default', true)->count())->toBe(1);
    });

    it('does not update a location that belongs to another principal', function (): void {
        $theirs = $this->service->create($this->otherPrincipalId, ['name' => 'Theirs', 'address' => 'B']);

        $result = $this->service->update($theirs['location']['id'], $this->principalId, ['name' => 'Hacked']);

        expect($result)->toBeNull();
        expect(PrincipalLocation::find($theirs['location']['id'])->name)->toBe('Theirs');
    });

    it('reads a null field as "not mentioned" rather than "set to nothing"', function (): void {
        $created = $this->service->create($this->principalId, [
            'name'       => 'HQ',
            'address'    => 'Musterstraße 1',
            'is_default' => true,
        ]);

        $result = $this->service->update($created['location']['id'], $this->principalId, [
            'name'       => null,
            'address'    => null,
            'is_default' => null,
        ]);

        expect($result['location']['name'])->toBe('HQ');
        expect($result['location']['address'])->toBe('Musterstraße 1');
        expect($result['location']['is_default'])->toBeTrue();
    });

    it('returns null when updating a missing location', function (): void {
        expect($this->service->update(999999, $this->principalId, ['name' => 'x']))->toBeNull();
    });

    it('deletes a location', function (): void {
        $created = $this->service->create($this->principalId, ['name' => 'X', 'address' => 'A']);

        expect($this->service->delete($created['location']['id'], $this->principalId))->toBeTrue();
        expect($this->service->listFor($this->principalId))->toBe([]);
    });

    it('does not delete a location that belongs to another principal', function (): void {
        $theirs = $this->service->create($this->otherPrincipalId, ['name' => 'Theirs', 'address' => 'B']);

        expect($this->service->delete($theirs['location']['id'], $this->principalId))->toBeFalse();
        expect(PrincipalLocation::find($theirs['location']['id']))->not->toBeNull();
    });

    it('returns false when deleting a missing location', function (): void {
        expect($this->service->delete(999999, $this->principalId))->toBeFalse();
    });

    it('counts the defaults of a principal', function (): void {
        $this->service->create($this->principalId, ['name' => 'A', 'address' => 'A', 'is_default' => true]);

        expect((int) PrincipalLocation::where('principal_id', $this->principalId)->where('is_default', true)->count())->toBe(1);
    });
});
