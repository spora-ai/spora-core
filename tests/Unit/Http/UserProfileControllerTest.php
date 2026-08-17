<?php

declare(strict_types=1);

const USERPROFILE_TEST_PASSWORD = 'Password1!';
const USERPROFILE_GET_PROFILE_PATH = '/me/profile';
const USERPROFILE_LOCATIONS_PATH = '/me/locations';
const USERPROFILE_ADDRESS_HOME = '123 Main St';
const USERPROFILE_ADDRESS_OFFICE = '456 Office Blvd';

use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
use Spora\Http\UserProfileController;
use Spora\Models\User;
use Spora\Models\UserLocation;
use Spora\Security\CsrfTokenService;
use Spora\Services\UserService;

function makeUserProfileController(): array
{
    $authService = bootAuthLayer();
    $userService = new UserService();
    $controller = new UserProfileController($authService, $userService);
    $authMiddleware = new AuthMiddleware($authService);
    $csrfService = new CsrfTokenService();
    $csrfMiddleware = new CsrfMiddleware($csrfService);

    return [$controller, $authService, $userService, $authMiddleware, $csrfMiddleware];
}

test('getProfile returns user profile data', function (): void {
    [$controller, $authService, , $authMiddleware] = makeUserProfileController();
    $userId = bootAuth($authService, 'profile1@example.com', USERPROFILE_TEST_PASSWORD);
    simulateLoggedInSession($userId, 'profile1@example.com');

    User::where('id', $userId)->update([
        'name'         => 'Alice',
        'date_of_birth' => '1990-05-15',
        'about_me'     => 'Hello',
        'height_cm'    => 175.5,
        'weight_kg'    => 70.0,
    ]);

    $request = jsonRequest('GET', USERPROFILE_GET_PROFILE_PATH);
    $response = callController($controller, 'getProfile', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $data = json_decode($response->getContent(), true)['data'];
    expect($data['name'])->toBe('Alice');
    expect($data['date_of_birth'])->toBe('1990-05-15');
    expect($data['about_me'])->toBe('Hello');
    expect($data['height_cm'])->toEqual(175.5);
    expect($data['weight_kg'])->toEqual(70.0);
});

test('getProfile returns null fields as null', function (): void {
    [$controller, $authService, , $authMiddleware] = makeUserProfileController();
    $userId = bootAuth($authService, 'profile2@example.com', USERPROFILE_TEST_PASSWORD);
    simulateLoggedInSession($userId, 'profile2@example.com');

    // Ensure user has no profile data set by bootAuth
    $user = User::find($userId);
    $user->name = null;
    $user->save();

    $request = jsonRequest('GET', USERPROFILE_GET_PROFILE_PATH);
    $response = callController($controller, 'getProfile', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $data = json_decode($response->getContent(), true)['data'];
    expect($data['name'])->toBeNull();
    expect($data['date_of_birth'])->toBeNull();
    expect($data['about_me'])->toBeNull();
    expect($data['height_cm'])->toBeNull();
    expect($data['weight_kg'])->toBeNull();
});

test('getProfile rejects unauthenticated requests', function (): void {
    [$controller, , , $authMiddleware] = makeUserProfileController();
    clearSession();

    $request = jsonRequest('GET', USERPROFILE_GET_PROFILE_PATH);

    expect(fn() => callController($controller, 'getProfile', $request, [$authMiddleware]))
        ->toThrow(Spora\Http\Exceptions\UnauthenticatedException::class);
});

test('putProfile updates profile fields', function (): void {
    [$controller, $authService, , $authMiddleware] = makeUserProfileController();
    $userId = bootAuth($authService, 'profile3@example.com', USERPROFILE_TEST_PASSWORD);
    simulateLoggedInSession($userId, 'profile3@example.com');

    $request = jsonRequest('PUT', USERPROFILE_GET_PROFILE_PATH, [
        'name'         => 'Bob',
        'date_of_birth' => '1985-03-20',
        'about_me'     => 'Updated bio',
        'height_cm'    => 180.0,
        'weight_kg'    => 75.5,
    ]);
    $response = callController($controller, 'putProfile', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $data = json_decode($response->getContent(), true)['data'];
    expect($data['name'])->toBe('Bob');
    expect($data['date_of_birth'])->toBe('1985-03-20');
    expect($data['about_me'])->toBe('Updated bio');
    expect($data['height_cm'])->toEqual(180.0);
    expect($data['weight_kg'])->toEqual(75.5);
});

test('putProfile clears fields when empty string sent', function (): void {
    [$controller, $authService, , $authMiddleware] = makeUserProfileController();
    $userId = bootAuth($authService, 'profile4@example.com', USERPROFILE_TEST_PASSWORD);
    simulateLoggedInSession($userId, 'profile4@example.com');
    User::where('id', $userId)->update(['name' => 'Alice']);

    $request = jsonRequest('PUT', USERPROFILE_GET_PROFILE_PATH, ['name' => '']);
    $response = callController($controller, 'putProfile', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $data = json_decode($response->getContent(), true)['data'];
    expect($data['name'])->toBeNull();
});

test('putProfile rejects unauthenticated requests', function (): void {
    [$controller, , , $authMiddleware] = makeUserProfileController();
    clearSession();

    $request = jsonRequest('PUT', USERPROFILE_GET_PROFILE_PATH, ['name' => 'Bob']);

    expect(fn() => callController($controller, 'putProfile', $request, [$authMiddleware]))
        ->toThrow(Spora\Http\Exceptions\UnauthenticatedException::class);
});

test('getLocations returns empty list when no locations', function (): void {
    [$controller, $authService, , $authMiddleware] = makeUserProfileController();
    $userId = bootAuth($authService, 'profile5@example.com', USERPROFILE_TEST_PASSWORD);
    simulateLoggedInSession($userId, 'profile5@example.com');

    $request = jsonRequest('GET', USERPROFILE_LOCATIONS_PATH);
    $response = callController($controller, 'getLocations', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $data = json_decode($response->getContent(), true)['data']['locations'];
    expect($data)->toHaveCount(0);
});

test('getLocations returns user locations', function (): void {
    [$controller, $authService, , $authMiddleware] = makeUserProfileController();
    $userId = bootAuth($authService, 'profile6@example.com', USERPROFILE_TEST_PASSWORD);
    simulateLoggedInSession($userId, 'profile6@example.com');

    UserLocation::create([
        'user_id' => $userId,
        'name'   => 'Home',
        'address' => USERPROFILE_ADDRESS_HOME,
        'is_default' => true,
    ]);
    UserLocation::create([
        'user_id' => $userId,
        'name'   => 'Work',
        'address' => USERPROFILE_ADDRESS_OFFICE,
        'is_default' => false,
    ]);

    $request = jsonRequest('GET', USERPROFILE_LOCATIONS_PATH);
    $response = callController($controller, 'getLocations', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $data = json_decode($response->getContent(), true)['data']['locations'];
    expect($data)->toHaveCount(2);
    expect(collect($data)->pluck('name')->toArray())->toContain('Home', 'Work');
});

test('getLocations rejects unauthenticated requests', function (): void {
    [$controller, , , $authMiddleware] = makeUserProfileController();
    clearSession();

    $request = jsonRequest('GET', USERPROFILE_LOCATIONS_PATH);

    expect(fn() => callController($controller, 'getLocations', $request, [$authMiddleware]))
        ->toThrow(Spora\Http\Exceptions\UnauthenticatedException::class);
});

test('postLocation creates a new location', function (): void {
    [$controller, $authService, , $authMiddleware] = makeUserProfileController();
    $userId = bootAuth($authService, 'profile7@example.com', USERPROFILE_TEST_PASSWORD);
    simulateLoggedInSession($userId, 'profile7@example.com');

    $request = jsonRequest('POST', USERPROFILE_LOCATIONS_PATH, [
        'name'       => 'Beach House',
        'address'    => '100 Ocean Drive',
        'is_default' => false,
    ]);
    $response = callController($controller, 'postLocation', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(201);
    $data = json_decode($response->getContent(), true)['data'];
    expect($data['name'])->toBe('Beach House');
    expect($data['address'])->toBe('100 Ocean Drive');
    expect($data['is_default'])->toBeFalse();
});

test('postLocation validates name is required', function (): void {
    [$controller, $authService, , $authMiddleware] = makeUserProfileController();
    $userId = bootAuth($authService, 'profile8@example.com', USERPROFILE_TEST_PASSWORD);
    simulateLoggedInSession($userId, 'profile8@example.com');

    $request = jsonRequest('POST', USERPROFILE_LOCATIONS_PATH, ['address' => USERPROFILE_ADDRESS_HOME]);
    $response = callController($controller, 'postLocation', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(422);
    expect($response->getContent())->toContain('name is required');
});

test('postLocation validates address is required', function (): void {
    [$controller, $authService, , $authMiddleware] = makeUserProfileController();
    $userId = bootAuth($authService, 'profile9@example.com', USERPROFILE_TEST_PASSWORD);
    simulateLoggedInSession($userId, 'profile9@example.com');

    $request = jsonRequest('POST', USERPROFILE_LOCATIONS_PATH, ['name' => 'Home']);
    $response = callController($controller, 'postLocation', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(422);
    expect($response->getContent())->toContain('address is required');
});

test('postLocation sets is_default and unsets other defaults', function (): void {
    [$controller, $authService, , $authMiddleware] = makeUserProfileController();
    $userId = bootAuth($authService, 'profile10@example.com', USERPROFILE_TEST_PASSWORD);
    simulateLoggedInSession($userId, 'profile10@example.com');

    UserLocation::create([
        'user_id' => $userId,
        'name'   => 'Existing',
        'address' => USERPROFILE_ADDRESS_HOME,
        'is_default' => true,
    ]);

    $request = jsonRequest('POST', USERPROFILE_LOCATIONS_PATH, [
        'name'       => 'New Default',
        'address'    => USERPROFILE_ADDRESS_OFFICE,
        'is_default' => true,
    ]);
    $response = callController($controller, 'postLocation', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(201);
    $locations = UserLocation::where('user_id', $userId)->get();
    expect($locations->where('is_default', true)->count())->toBe(1);
    expect($locations->where('name', 'New Default')->first()->is_default)->toBeTrue();
});

test('postLocation rejects unauthenticated requests', function (): void {
    [$controller, , , $authMiddleware] = makeUserProfileController();
    clearSession();

    $request = jsonRequest('POST', USERPROFILE_LOCATIONS_PATH, ['name' => 'Home', 'address' => USERPROFILE_ADDRESS_HOME]);

    expect(fn() => callController($controller, 'postLocation', $request, [$authMiddleware]))
        ->toThrow(Spora\Http\Exceptions\UnauthenticatedException::class);
});

test('putLocation updates own location', function (): void {
    [$controller, $authService, , $authMiddleware] = makeUserProfileController();
    $userId = bootAuth($authService, 'profile11@example.com', USERPROFILE_TEST_PASSWORD);
    simulateLoggedInSession($userId, 'profile11@example.com');

    $loc = UserLocation::create([
        'user_id' => $userId,
        'name'   => 'Old Name',
        'address' => USERPROFILE_ADDRESS_HOME,
        'is_default' => false,
    ]);

    $request = jsonRequest('PUT', "/me/locations/{$loc->id}", [
        'name' => 'New Name',
        'address' => USERPROFILE_ADDRESS_OFFICE,
    ]);
    $request->attributes->set('id', $loc->id);
    $response = callController($controller, 'putLocation', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $data = json_decode($response->getContent(), true)['data'];
    expect($data['name'])->toBe('New Name');
    expect($data['address'])->toBe(USERPROFILE_ADDRESS_OFFICE);
});

test('putLocation returns 404 for another users location', function (): void {
    [$controller, $authService, , $authMiddleware] = makeUserProfileController();
    $userId = bootAuth($authService, 'profile12@example.com', USERPROFILE_TEST_PASSWORD);
    $otherUserId = bootAuth($authService, 'other12@example.com', USERPROFILE_TEST_PASSWORD);
    simulateLoggedInSession($userId, 'profile12@example.com');

    $loc = UserLocation::create([
        'user_id' => $otherUserId,
        'name'   => 'Other Home',
        'address' => USERPROFILE_ADDRESS_HOME,
    ]);

    $request = jsonRequest('PUT', "/me/locations/{$loc->id}", ['name' => 'Hacked']);
    $request->attributes->set('id', $loc->id);
    $response = callController($controller, 'putLocation', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(404);
});

test('putLocation rejects unauthenticated requests', function (): void {
    [$controller, , , $authMiddleware] = makeUserProfileController();
    clearSession();

    $request = jsonRequest('PUT', '/me/locations/1', ['name' => 'Home']);
    $request->attributes->set('id', 1);

    expect(fn() => callController($controller, 'putLocation', $request, [$authMiddleware]))
        ->toThrow(Spora\Http\Exceptions\UnauthenticatedException::class);
});

test('deleteLocation deletes own location', function (): void {
    [$controller, $authService, , $authMiddleware] = makeUserProfileController();
    $userId = bootAuth($authService, 'profile13@example.com', USERPROFILE_TEST_PASSWORD);
    simulateLoggedInSession($userId, 'profile13@example.com');

    $loc = UserLocation::create([
        'user_id' => $userId,
        'name'   => 'To Delete',
        'address' => USERPROFILE_ADDRESS_HOME,
    ]);

    $request = jsonRequest('DELETE', "/me/locations/{$loc->id}");
    $request->attributes->set('id', $loc->id);
    $response = callController($controller, 'deleteLocation', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    expect(UserLocation::find($loc->id))->toBeNull();
});

test('deleteLocation returns 404 for another users location', function (): void {
    [$controller, $authService, , $authMiddleware] = makeUserProfileController();
    $userId = bootAuth($authService, 'profile14@example.com', USERPROFILE_TEST_PASSWORD);
    $otherUserId = bootAuth($authService, 'other14@example.com', USERPROFILE_TEST_PASSWORD);
    simulateLoggedInSession($userId, 'profile14@example.com');

    $loc = UserLocation::create([
        'user_id' => $otherUserId,
        'name'   => 'Other Location',
        'address' => USERPROFILE_ADDRESS_HOME,
    ]);

    $request = jsonRequest('DELETE', "/me/locations/{$loc->id}");
    $request->attributes->set('id', $loc->id);
    $response = callController($controller, 'deleteLocation', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(404);
    expect(UserLocation::find($loc->id))->not()->toBeNull();
});

test('deleteLocation rejects unauthenticated requests', function (): void {
    [$controller, , , $authMiddleware] = makeUserProfileController();
    clearSession();

    $request = jsonRequest('DELETE', '/me/locations/1');
    $request->attributes->set('id', 1);

    expect(fn() => callController($controller, 'deleteLocation', $request, [$authMiddleware]))
        ->toThrow(Spora\Http\Exceptions\UnauthenticatedException::class);
});

test('getProfile returns 404 when user no longer exists', function (): void {
    [$controller, $authService, , $authMiddleware] = makeUserProfileController();
    $userId = bootAuth($authService, 'missing@example.com', USERPROFILE_TEST_PASSWORD);
    simulateLoggedInSession($userId, 'missing@example.com');

    User::where('id', $userId)->delete();

    $request = jsonRequest('GET', USERPROFILE_GET_PROFILE_PATH);
    $response = callController($controller, 'getProfile', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(404);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('NOT_FOUND');
    expect($body['error']['message'])->toBe('User not found.');
});

test('putProfile returns 400 on invalid JSON', function (): void {
    [$controller, $authService, , $authMiddleware] = makeUserProfileController();
    $userId = bootAuth($authService, 'badjsonprofile@example.com', USERPROFILE_TEST_PASSWORD);
    simulateLoggedInSession($userId, 'badjsonprofile@example.com');

    $request = Symfony\Component\HttpFoundation\Request::create(
        USERPROFILE_GET_PROFILE_PATH,
        'PUT',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        '{ this is not valid json',
    );
    $response = callController($controller, 'putProfile', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(400);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('INVALID_JSON');
    expect($body['error']['message'])->toBe('Request body must be valid JSON.');
});

test('putProfile handles empty request body', function (): void {
    [$controller, $authService, , $authMiddleware] = makeUserProfileController();
    $userId = bootAuth($authService, 'emptybodyprofile@example.com', USERPROFILE_TEST_PASSWORD);
    simulateLoggedInSession($userId, 'emptybodyprofile@example.com');
    User::where('id', $userId)->update(['name' => 'Keep Me']);

    $request = jsonRequest('PUT', USERPROFILE_GET_PROFILE_PATH);
    $response = callController($controller, 'putProfile', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $data = json_decode($response->getContent(), true)['data'];
    expect($data['name'])->toBe('Keep Me');
});

test('postLocation returns 400 on invalid JSON', function (): void {
    [$controller, $authService, , $authMiddleware] = makeUserProfileController();
    $userId = bootAuth($authService, 'badjsonloc@example.com', USERPROFILE_TEST_PASSWORD);
    simulateLoggedInSession($userId, 'badjsonloc@example.com');

    $request = Symfony\Component\HttpFoundation\Request::create(
        USERPROFILE_LOCATIONS_PATH,
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        '{ not json',
    );
    $response = callController($controller, 'postLocation', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(400);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('INVALID_JSON');
    expect($body['error']['message'])->toBe('Request body must be valid JSON.');
});

test('postLocation returns 422 when name is empty string', function (): void {
    [$controller, $authService, , $authMiddleware] = makeUserProfileController();
    $userId = bootAuth($authService, 'emptynameloc@example.com', USERPROFILE_TEST_PASSWORD);
    simulateLoggedInSession($userId, 'emptynameloc@example.com');

    $request = jsonRequest('POST', USERPROFILE_LOCATIONS_PATH, [
        'name'    => '',
        'address' => USERPROFILE_ADDRESS_HOME,
    ]);
    $response = callController($controller, 'postLocation', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(422);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR');
    expect($body['error']['message'])->toBe('name is required.');
    expect(UserLocation::where('user_id', $userId)->count())->toBe(0);
});

test('postLocation returns 422 when name is whitespace only', function (): void {
    [$controller, $authService, , $authMiddleware] = makeUserProfileController();
    $userId = bootAuth($authService, 'spacename@example.com', USERPROFILE_TEST_PASSWORD);
    simulateLoggedInSession($userId, 'spacename@example.com');

    $request = jsonRequest('POST', USERPROFILE_LOCATIONS_PATH, [
        'name'    => '   ',
        'address' => USERPROFILE_ADDRESS_HOME,
    ]);
    $response = callController($controller, 'postLocation', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(422);
    expect($response->getContent())->toContain('name is required');
});

test('postLocation returns 422 when address is empty string', function (): void {
    [$controller, $authService, , $authMiddleware] = makeUserProfileController();
    $userId = bootAuth($authService, 'emptyaddrloc@example.com', USERPROFILE_TEST_PASSWORD);
    simulateLoggedInSession($userId, 'emptyaddrloc@example.com');

    $request = jsonRequest('POST', USERPROFILE_LOCATIONS_PATH, [
        'name'    => 'Home',
        'address' => '',
    ]);
    $response = callController($controller, 'postLocation', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(422);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR');
    expect($body['error']['message'])->toBe('address is required.');
    expect(UserLocation::where('user_id', $userId)->count())->toBe(0);
});

test('putLocation returns 400 on invalid JSON', function (): void {
    [$controller, $authService, , $authMiddleware] = makeUserProfileController();
    $userId = bootAuth($authService, 'badjsonputloc@example.com', USERPROFILE_TEST_PASSWORD);
    simulateLoggedInSession($userId, 'badjsonputloc@example.com');

    $loc = UserLocation::create([
        'user_id' => $userId,
        'name'    => 'Home',
        'address' => USERPROFILE_ADDRESS_HOME,
    ]);

    $request = Symfony\Component\HttpFoundation\Request::create(
        "/me/locations/{$loc->id}",
        'PUT',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        '{ broken',
    );
    $request->attributes->set('id', $loc->id);
    $response = callController($controller, 'putLocation', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(400);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('INVALID_JSON');
});

test('putLocation returns 422 when name is empty string', function (): void {
    [$controller, $authService, , $authMiddleware] = makeUserProfileController();
    $userId = bootAuth($authService, 'emptynameput@example.com', USERPROFILE_TEST_PASSWORD);
    simulateLoggedInSession($userId, 'emptynameput@example.com');

    $loc = UserLocation::create([
        'user_id' => $userId,
        'name'    => 'Original',
        'address' => USERPROFILE_ADDRESS_HOME,
    ]);

    $request = jsonRequest('PUT', "/me/locations/{$loc->id}", ['name' => '']);
    $request->attributes->set('id', $loc->id);
    $response = callController($controller, 'putLocation', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(422);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR');
    expect($body['error']['message'])->toBe('name is required and cannot be empty.');

    $loc->refresh();
    expect($loc->name)->toBe('Original');
});

test('putLocation returns 422 when address is empty string', function (): void {
    [$controller, $authService, , $authMiddleware] = makeUserProfileController();
    $userId = bootAuth($authService, 'emptyaddrput@example.com', USERPROFILE_TEST_PASSWORD);
    simulateLoggedInSession($userId, 'emptyaddrput@example.com');

    $loc = UserLocation::create([
        'user_id' => $userId,
        'name'    => 'Original',
        'address' => USERPROFILE_ADDRESS_HOME,
    ]);

    $request = jsonRequest('PUT', "/me/locations/{$loc->id}", ['address' => '']);
    $request->attributes->set('id', $loc->id);
    $response = callController($controller, 'putLocation', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(422);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR');
    expect($body['error']['message'])->toBe('address is required and cannot be empty.');

    $loc->refresh();
    expect($loc->address)->toBe(USERPROFILE_ADDRESS_HOME);
});
