<?php

declare(strict_types=1);

use Spora\Models\User;
use Spora\Models\UserLocation;

describe('UserLocation', function (): void {

    beforeEach(function (): void {
        $this->authService = bootAuthLayer();
        $this->userId = bootAuth($this->authService, 'location@example.com', 'Password1!');
        simulateLoggedInSession($this->userId, 'location@example.com');
    });

    it('belongs to user', function (): void {
        $loc = UserLocation::create([
            'user_id' => $this->userId,
            'name'   => 'Home',
            'address' => '123 Main St',
        ]);

        expect($loc->user->id)->toBe($this->userId);
    });

    it('can create multiple locations for same user', function (): void {
        UserLocation::create(['user_id' => $this->userId, 'name' => 'Home', 'address' => '123 Main St']);
        UserLocation::create(['user_id' => $this->userId, 'name' => 'Work', 'address' => '456 Office Blvd']);

        $locations = UserLocation::where('user_id', $this->userId)->get();
        expect($locations)->toHaveCount(2);
    });

    it('casts is_default as boolean', function (): void {
        $loc = UserLocation::create([
            'user_id' => $this->userId,
            'name'   => 'Default',
            'address' => '123 Main St',
            'is_default' => true,
        ]);

        expect($loc->is_default)->toBeTrue();

        $loc->is_default = false;
        $loc->save();
        $loc->refresh();

        expect($loc->is_default)->toBeFalse();
    });

    it('user locations relationship returns all locations', function (): void {
        UserLocation::create(['user_id' => $this->userId, 'name' => 'A', 'address' => 'A']);
        UserLocation::create(['user_id' => $this->userId, 'name' => 'B', 'address' => 'B']);

        $user = User::find($this->userId);
        expect($user->locations)->toHaveCount(2);
    });

    it('deletes locations when user is deleted', function (): void {
        UserLocation::create(['user_id' => $this->userId, 'name' => 'Home', 'address' => '123 Main St']);

        User::destroy($this->userId);

        expect(UserLocation::where('user_id', $this->userId)->count())->toBe(0);
    });
});
