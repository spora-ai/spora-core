<?php

declare(strict_types=1);

use Spora\Http\Middleware\AuthGuard;
use Spora\Http\UserProfileController;
use Spora\Models\User;
use Spora\Models\UserLocation;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeUserProfileController(): array
{
    $authService = bootAuthLayer();
    $controller = new UserProfileController($authService);

    return [$controller, $authService];
}

// ---------------------------------------------------------------------------
// getProfile
// ---------------------------------------------------------------------------

test('getProfile returns user profile data', function (): void {
    [$controller, $authService] = makeUserProfileController();
    $userId = bootAuth($authService, 'profile1@example.com', 'Password1!');
    simulateLoggedInSession($userId, 'profile1@example.com');

    User::where('id', $userId)->update([
        'name'         => 'Alice',
        'date_of_birth' => '1990-05-15',
        'about_me'     => 'Hello',
        'height_cm'    => 175.5,
        'weight_kg'    => 70.0,
    ]);

    $request = jsonRequest('GET', '/me/profile');
    $response = $controller->getProfile($request);

    expect($response->getStatusCode())->toBe(200);
    $data = json_decode($response->getContent(), true)['data'];
    expect($data['name'])->toBe('Alice');
    expect($data['date_of_birth'])->toBe('1990-05-15');
    expect($data['about_me'])->toBe('Hello');
    expect($data['height_cm'])->toEqual(175.5);
    expect($data['weight_kg'])->toEqual(70.0);
});

test('getProfile returns null fields as null', function (): void {
    [$controller, $authService] = makeUserProfileController();
    $userId = bootAuth($authService, 'profile2@example.com', 'Password1!');
    simulateLoggedInSession($userId, 'profile2@example.com');

    $request = jsonRequest('GET', '/me/profile');
    $response = $controller->getProfile($request);

    expect($response->getStatusCode())->toBe(200);
    $data = json_decode($response->getContent(), true)['data'];
    expect($data['name'])->toBeNull();
    expect($data['date_of_birth'])->toBeNull();
    expect($data['about_me'])->toBeNull();
    expect($data['height_cm'])->toBeNull();
    expect($data['weight_kg'])->toBeNull();
});

test('getProfile rejects unauthenticated requests', function (): void {
    [$controller] = makeUserProfileController();
    clearSession();

    $request = jsonRequest('GET', '/me/profile');

    expect(fn() => $controller->getProfile($request))
        ->toThrow(Spora\Http\Exceptions\UnauthenticatedException::class);
});

// ---------------------------------------------------------------------------
// putProfile
// ---------------------------------------------------------------------------

test('putProfile updates profile fields', function (): void {
    [$controller, $authService] = makeUserProfileController();
    $userId = bootAuth($authService, 'profile3@example.com', 'Password1!');
    simulateLoggedInSession($userId, 'profile3@example.com');

    $request = jsonRequest('PUT', '/me/profile', [
        'name'         => 'Bob',
        'date_of_birth' => '1985-03-20',
        'about_me'     => 'Updated bio',
        'height_cm'    => 180.0,
        'weight_kg'    => 75.5,
    ]);
    $response = $controller->putProfile($request);

    expect($response->getStatusCode())->toBe(200);
    $data = json_decode($response->getContent(), true)['data'];
    expect($data['name'])->toBe('Bob');
    expect($data['date_of_birth'])->toBe('1985-03-20');
    expect($data['about_me'])->toBe('Updated bio');
    expect($data['height_cm'])->toEqual(180.0);
    expect($data['weight_kg'])->toEqual(75.5);
});

test('putProfile clears fields when empty string sent', function (): void {
    [$controller, $authService] = makeUserProfileController();
    $userId = bootAuth($authService, 'profile4@example.com', 'Password1!');
    simulateLoggedInSession($userId, 'profile4@example.com');
    User::where('id', $userId)->update(['name' => 'Alice']);

    $request = jsonRequest('PUT', '/me/profile', ['name' => '']);
    $response = $controller->putProfile($request);

    expect($response->getStatusCode())->toBe(200);
    $data = json_decode($response->getContent(), true)['data'];
    expect($data['name'])->toBeNull();
});

test('putProfile rejects unauthenticated requests', function (): void {
    [$controller] = makeUserProfileController();
    clearSession();

    $request = jsonRequest('PUT', '/me/profile', ['name' => 'Bob']);

    expect(fn() => $controller->putProfile($request))
        ->toThrow(Spora\Http\Exceptions\UnauthenticatedException::class);
});

// ---------------------------------------------------------------------------
// getLocations
// ---------------------------------------------------------------------------

test('getLocations returns empty list when no locations', function (): void {
    [$controller, $authService] = makeUserProfileController();
    $userId = bootAuth($authService, 'profile5@example.com', 'Password1!');
    simulateLoggedInSession($userId, 'profile5@example.com');

    $request = jsonRequest('GET', '/me/locations');
    $response = $controller->getLocations($request);

    expect($response->getStatusCode())->toBe(200);
    $data = json_decode($response->getContent(), true)['data']['locations'];
    expect($data)->toHaveCount(0);
});

test('getLocations returns user locations', function (): void {
    [$controller, $authService] = makeUserProfileController();
    $userId = bootAuth($authService, 'profile6@example.com', 'Password1!');
    simulateLoggedInSession($userId, 'profile6@example.com');

    UserLocation::create([
        'user_id' => $userId,
        'name'   => 'Home',
        'address' => '123 Main St',
        'is_default' => true,
    ]);
    UserLocation::create([
        'user_id' => $userId,
        'name'   => 'Work',
        'address' => '456 Office Blvd',
        'is_default' => false,
    ]);

    $request = jsonRequest('GET', '/me/locations');
    $response = $controller->getLocations($request);

    expect($response->getStatusCode())->toBe(200);
    $data = json_decode($response->getContent(), true)['data']['locations'];
    expect($data)->toHaveCount(2);
    expect(collect($data)->pluck('name')->toArray())->toContain('Home', 'Work');
});

test('getLocations rejects unauthenticated requests', function (): void {
    [$controller] = makeUserProfileController();
    clearSession();

    $request = jsonRequest('GET', '/me/locations');

    expect(fn() => $controller->getLocations($request))
        ->toThrow(Spora\Http\Exceptions\UnauthenticatedException::class);
});

// ---------------------------------------------------------------------------
// postLocation
// ---------------------------------------------------------------------------

test('postLocation creates a new location', function (): void {
    [$controller, $authService] = makeUserProfileController();
    $userId = bootAuth($authService, 'profile7@example.com', 'Password1!');
    simulateLoggedInSession($userId, 'profile7@example.com');

    $request = jsonRequest('POST', '/me/locations', [
        'name'       => 'Beach House',
        'address'    => '100 Ocean Drive',
        'is_default' => false,
    ]);
    $response = $controller->postLocation($request);

    expect($response->getStatusCode())->toBe(201);
    $data = json_decode($response->getContent(), true)['data'];
    expect($data['name'])->toBe('Beach House');
    expect($data['address'])->toBe('100 Ocean Drive');
    expect($data['is_default'])->toBeFalse();
});

test('postLocation validates name is required', function (): void {
    [$controller, $authService] = makeUserProfileController();
    $userId = bootAuth($authService, 'profile8@example.com', 'Password1!');
    simulateLoggedInSession($userId, 'profile8@example.com');

    $request = jsonRequest('POST', '/me/locations', ['address' => '123 Main St']);
    $response = $controller->postLocation($request);

    expect($response->getStatusCode())->toBe(422);
    expect($response->getContent())->toContain('name is required');
});

test('postLocation validates address is required', function (): void {
    [$controller, $authService] = makeUserProfileController();
    $userId = bootAuth($authService, 'profile9@example.com', 'Password1!');
    simulateLoggedInSession($userId, 'profile9@example.com');

    $request = jsonRequest('POST', '/me/locations', ['name' => 'Home']);
    $response = $controller->postLocation($request);

    expect($response->getStatusCode())->toBe(422);
    expect($response->getContent())->toContain('address is required');
});

test('postLocation sets is_default and unsets other defaults', function (): void {
    [$controller, $authService] = makeUserProfileController();
    $userId = bootAuth($authService, 'profile10@example.com', 'Password1!');
    simulateLoggedInSession($userId, 'profile10@example.com');

    UserLocation::create([
        'user_id' => $userId,
        'name'   => 'Existing',
        'address' => '123 Main St',
        'is_default' => true,
    ]);

    $request = jsonRequest('POST', '/me/locations', [
        'name'       => 'New Default',
        'address'    => '456 Office Blvd',
        'is_default' => true,
    ]);
    $response = $controller->postLocation($request);

    expect($response->getStatusCode())->toBe(201);
    $locations = UserLocation::where('user_id', $userId)->get();
    expect($locations->where('is_default', true)->count())->toBe(1);
    expect($locations->where('name', 'New Default')->first()->is_default)->toBeTrue();
});

test('postLocation rejects unauthenticated requests', function (): void {
    [$controller] = makeUserProfileController();
    clearSession();

    $request = jsonRequest('POST', '/me/locations', ['name' => 'Home', 'address' => '123 Main St']);

    expect(fn() => $controller->postLocation($request))
        ->toThrow(Spora\Http\Exceptions\UnauthenticatedException::class);
});

// ---------------------------------------------------------------------------
// putLocation
// ---------------------------------------------------------------------------

test('putLocation updates own location', function (): void {
    [$controller, $authService] = makeUserProfileController();
    $userId = bootAuth($authService, 'profile11@example.com', 'Password1!');
    simulateLoggedInSession($userId, 'profile11@example.com');

    $loc = UserLocation::create([
        'user_id' => $userId,
        'name'   => 'Old Name',
        'address' => '123 Main St',
        'is_default' => false,
    ]);

    $request = jsonRequest('PUT', "/me/locations/{$loc->id}", [
        'name' => 'New Name',
        'address' => '456 Office Blvd',
    ]);
    $response = $controller->putLocation($request, $loc->id);

    expect($response->getStatusCode())->toBe(200);
    $data = json_decode($response->getContent(), true)['data'];
    expect($data['name'])->toBe('New Name');
    expect($data['address'])->toBe('456 Office Blvd');
});

test('putLocation returns 404 for another users location', function (): void {
    [$controller, $authService] = makeUserProfileController();
    $userId = bootAuth($authService, 'profile12@example.com', 'Password1!');
    $otherUserId = bootAuth($authService, 'other12@example.com', 'Password1!');
    simulateLoggedInSession($userId, 'profile12@example.com');

    $loc = UserLocation::create([
        'user_id' => $otherUserId,
        'name'   => 'Other Home',
        'address' => '123 Main St',
    ]);

    $request = jsonRequest('PUT', "/me/locations/{$loc->id}", ['name' => 'Hacked']);
    $response = $controller->putLocation($request, $loc->id);

    expect($response->getStatusCode())->toBe(404);
});

test('putLocation rejects unauthenticated requests', function (): void {
    [$controller] = makeUserProfileController();
    clearSession();

    $request = jsonRequest('PUT', '/me/locations/1', ['name' => 'Home']);

    expect(fn() => $controller->putLocation($request, 1))
        ->toThrow(Spora\Http\Exceptions\UnauthenticatedException::class);
});

// ---------------------------------------------------------------------------
// deleteLocation
// ---------------------------------------------------------------------------

test('deleteLocation deletes own location', function (): void {
    [$controller, $authService] = makeUserProfileController();
    $userId = bootAuth($authService, 'profile13@example.com', 'Password1!');
    simulateLoggedInSession($userId, 'profile13@example.com');

    $loc = UserLocation::create([
        'user_id' => $userId,
        'name'   => 'To Delete',
        'address' => '123 Main St',
    ]);

    $request = jsonRequest('DELETE', "/me/locations/{$loc->id}");
    $response = $controller->deleteLocation($request, $loc->id);

    expect($response->getStatusCode())->toBe(200);
    expect(UserLocation::find($loc->id))->toBeNull();
});

test('deleteLocation returns 404 for another users location', function (): void {
    [$controller, $authService] = makeUserProfileController();
    $userId = bootAuth($authService, 'profile14@example.com', 'Password1!');
    $otherUserId = bootAuth($authService, 'other14@example.com', 'Password1!');
    simulateLoggedInSession($userId, 'profile14@example.com');

    $loc = UserLocation::create([
        'user_id' => $otherUserId,
        'name'   => 'Other Location',
        'address' => '123 Main St',
    ]);

    $request = jsonRequest('DELETE', "/me/locations/{$loc->id}");
    $response = $controller->deleteLocation($request, $loc->id);

    expect($response->getStatusCode())->toBe(404);
    expect(UserLocation::find($loc->id))->not()->toBeNull();
});

test('deleteLocation rejects unauthenticated requests', function (): void {
    [$controller] = makeUserProfileController();
    clearSession();

    $request = jsonRequest('DELETE', '/me/locations/1');

    expect(fn() => $controller->deleteLocation($request, 1))
        ->toThrow(Spora\Http\Exceptions\UnauthenticatedException::class);
});
