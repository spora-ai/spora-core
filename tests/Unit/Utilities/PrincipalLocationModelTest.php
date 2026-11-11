<?php

declare(strict_types=1);

use Spora\Models\Principal;
use Spora\Models\PrincipalLocation;
use Spora\Models\User;

describe('PrincipalLocation', function (): void {

    beforeEach(function (): void {
        $this->authService = bootAuthLayer();
        $this->userId = bootAuth($this->authService, 'location@example.com', 'Password1!');
        simulateLoggedInSession($this->userId, 'location@example.com');
        $this->principalId = $this->createUserPrincipal($this->userId);
    });

    it('belongs to principal', function (): void {
        $loc = PrincipalLocation::create([
            'principal_id' => $this->principalId,
            'name'         => 'Home',
            'address'      => '123 Main St',
        ]);

        expect((int) $loc->principal->getKey())->toBe($this->principalId);
    });

    it('can create multiple locations for same principal', function (): void {
        PrincipalLocation::create(['principal_id' => $this->principalId, 'name' => 'Home', 'address' => '123 Main St']);
        PrincipalLocation::create(['principal_id' => $this->principalId, 'name' => 'Work', 'address' => '456 Office Blvd']);

        $locations = PrincipalLocation::where('principal_id', $this->principalId)->get();
        expect($locations)->toHaveCount(2);
    });

    it('casts is_default as boolean', function (): void {
        $loc = PrincipalLocation::create([
            'principal_id' => $this->principalId,
            'name'         => 'Default',
            'address'      => '123 Main St',
            'is_default'   => true,
        ]);

        expect($loc->is_default)->toBeTrue();

        $loc->is_default = false;
        $loc->save();
        $loc->refresh();

        expect($loc->is_default)->toBeFalse();
    });

    it('principal locations relationship returns all locations', function (): void {
        PrincipalLocation::create(['principal_id' => $this->principalId, 'name' => 'A', 'address' => 'A']);
        PrincipalLocation::create(['principal_id' => $this->principalId, 'name' => 'B', 'address' => 'B']);

        $principal = Principal::find($this->principalId);
        expect($principal->locations)->toHaveCount(2);
    });

    it('the user accessor reads the locations of the user principal', function (): void {
        PrincipalLocation::create(['principal_id' => $this->principalId, 'name' => 'A', 'address' => 'A']);

        $user = User::find($this->userId);
        expect($user->locations)->toHaveCount(1);
    });

    it('deletes locations when the principal is deleted', function (): void {
        PrincipalLocation::create(['principal_id' => $this->principalId, 'name' => 'Home', 'address' => '123 Main St']);

        Principal::where('id', $this->principalId)->delete();

        expect(PrincipalLocation::where('principal_id', $this->principalId)->count())->toBe(0);
    });
});
