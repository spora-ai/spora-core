<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Delight\Auth\Role;
use Illuminate\Database\Capsule\Manager as DB;
use Mockery;
use Spora\Auth\AuthService;
use Spora\Http\AuthController;
use Spora\Models\User;
use Spora\Security\CsrfTokenService;
use Spora\Services\AuthValidator;
use Spora\Services\AuthWorkflow;
use Spora\Services\RateLimiter;
use Spora\Services\UserService;
use Spora\Services\UserServiceInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

const AUTHCTL_TEST_PASSWORD = 'Password1!';

/**
 * Build a controller with a real AuthService/UserService/CsrfTokenService by default,
 * but allow any of the three collaborators to be replaced with a Mockery mock.
 *
 * @return array{0: AuthController, 1: AuthService, 2: UserServiceInterface, 3: CsrfTokenService}
 */
function makeAuthController(
    ?AuthService $authService = null,
    ?UserServiceInterface $userService = null,
    ?CsrfTokenService $csrfService = null,
    ?AuthValidator $validator = null,
    array $config = [],
): array {
    $authService ??= bootAuthLayer();
    $userService ??= new UserService();
    $csrfService ??= new CsrfTokenService();
    $validator ??= new AuthValidator();
    $workflow = new AuthWorkflow($authService, $userService, $csrfService, $validator);

    $controller = new AuthController($authService, $csrfService, $validator, $workflow, $config);

    return [$controller, $authService, $userService, $csrfService];
}

/**
 * Build a mock UserServiceInterface that the controller accepts.
 * (The controller type-hints on UserServiceInterface, so a Mockery mock passes.)
 */
function mockUserService(): UserServiceInterface
{
    return Mockery::mock(UserServiceInterface::class);
}

beforeEach(function (): void {
    RateLimiter::resetAll();
    clearSession();
});

afterEach(function (): void {
    Mockery::close();
});

// ---------------------------------------------------------------------------
// register()
// ---------------------------------------------------------------------------

describe('AuthController::register', function (): void {
    test('returns 201 with user data on successful registration', function (): void {
        [$controller] = makeAuthController();

        $request = jsonRequest('POST', '/api/v1/auth/register', [
            'email'            => 'newuser@example.com',
            'password'         => AUTHCTL_TEST_PASSWORD,
            'display_name'     => 'New User',
            'confirm_password' => AUTHCTL_TEST_PASSWORD,
        ]);

        $response = $controller->register($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_CREATED);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['user']['email'])->toBe('newuser@example.com');
        expect($body['data']['user']['id'])->toBeInt();
        expect($body['data'])->toHaveKey('csrf_token');
    });

    test('returns 201 with rate limit headers on success', function (): void {
        [$controller] = makeAuthController();

        $request = jsonRequest('POST', '/api/v1/auth/register', [
            'email'            => 'rl-success@example.com',
            'password'         => AUTHCTL_TEST_PASSWORD,
            'display_name'     => 'RL Success',
            'confirm_password' => AUTHCTL_TEST_PASSWORD,
        ]);

        $response = $controller->register($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_CREATED);
        expect($response->headers->get('X-RateLimit-Limit'))->toBe('5');
        expect($response->headers->get('X-RateLimit-Remaining'))->toBe('4');
    });

    test('returns 429 when rate limit is exceeded', function (): void {
        [$controller] = makeAuthController();

        for ($i = 0; $i < 5; $i++) {
            $req = jsonRequest('POST', '/api/v1/auth/register', [
                'email'            => "flood{$i}@example.com",
                'password'         => AUTHCTL_TEST_PASSWORD,
                'display_name'     => "Flood {$i}",
                'confirm_password' => AUTHCTL_TEST_PASSWORD,
            ]);
            $controller->register($req);
        }

        $request = jsonRequest('POST', '/api/v1/auth/register', [
            'email'            => 'flood5@example.com',
            'password'         => AUTHCTL_TEST_PASSWORD,
            'display_name'     => 'Flood 5',
            'confirm_password' => AUTHCTL_TEST_PASSWORD,
        ]);
        $response = $controller->register($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_TOO_MANY_REQUESTS);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('TOO_MANY_REQUESTS');
        expect($response->headers->get('Retry-After'))->toBeString();
        expect($response->headers->get('X-RateLimit-Remaining'))->toBe('0');
    });

    test('uses the first IP from X-Forwarded-For when present (different bucket than REMOTE_ADDR)', function (): void {
        [$controller] = makeAuthController();

        // Pre-fill the bucket for the X-Forwarded-For IP but not for 127.0.0.1
        for ($i = 0; $i < 5; $i++) {
            RateLimiter::attempt('203.0.113.1', 5, 60);
        }

        $request = jsonRequest('POST', '/api/v1/auth/register', [
            'email'            => 'xff@example.com',
            'password'         => AUTHCTL_TEST_PASSWORD,
            'display_name'     => 'XFF',
            'confirm_password' => AUTHCTL_TEST_PASSWORD,
        ]);
        $request->server->set('HTTP_X_FORWARDED_FOR', '203.0.113.1, 10.0.0.1');

        $response = $controller->register($request);

        // The forwarded IP is rate limited, so the controller should return 429
        expect($response->getStatusCode())->toBe(Response::HTTP_TOO_MANY_REQUESTS);
    });

    test('returns 403 when registration is disabled by config', function (): void {
        [$controller] = makeAuthController(config: ['allow_registration' => false]);

        $request = jsonRequest('POST', '/api/v1/auth/register', [
            'email'            => 'blocked@example.com',
            'password'         => AUTHCTL_TEST_PASSWORD,
            'display_name'     => 'Blocked',
            'confirm_password' => AUTHCTL_TEST_PASSWORD,
        ]);
        $response = $controller->register($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_FORBIDDEN);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('REGISTRATION_DISABLED');
    });

    test('returns 400 on invalid JSON', function (): void {
        [$controller] = makeAuthController();

        $request = Request::create('/api/v1/auth/register', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], 'this is not json');

        $response = $controller->register($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('INVALID_JSON');
    });

    test('returns 422 when required fields are missing', function (): void {
        [$controller] = makeAuthController();

        $request = jsonRequest('POST', '/api/v1/auth/register', [
            'email' => 'partial@example.com',
        ]);
        $response = $controller->register($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('VALIDATION_ERROR');
    });

    test('returns 422 when passwords do not match', function (): void {
        [$controller] = makeAuthController();

        $request = jsonRequest('POST', '/api/v1/auth/register', [
            'email'            => 'mismatch@example.com',
            'password'         => AUTHCTL_TEST_PASSWORD,
            'display_name'     => 'Mismatch',
            'confirm_password' => 'DifferentPassword1!',
        ]);
        $response = $controller->register($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('VALIDATION_ERROR');
        expect($body['error']['message'])->toBe('Passwords do not match.');
    });

    test('returns 409 when email is already taken', function (): void {
        [$controller, $authService] = makeAuthController();
        $authService->register('taken@example.com', AUTHCTL_TEST_PASSWORD, 'Existing User');

        $request = jsonRequest('POST', '/api/v1/auth/register', [
            'email'            => 'taken@example.com',
            'password'         => AUTHCTL_TEST_PASSWORD,
            'display_name'     => 'Duplicate',
            'confirm_password' => AUTHCTL_TEST_PASSWORD,
        ]);
        $response = $controller->register($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_CONFLICT);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('EMAIL_TAKEN');
    });

    test('returns 422 when email is invalid', function (): void {
        [$controller] = makeAuthController();

        $request = jsonRequest('POST', '/api/v1/auth/register', [
            'email'            => 'not-an-email',
            'password'         => AUTHCTL_TEST_PASSWORD,
            'display_name'     => 'Bad Email',
            'confirm_password' => AUTHCTL_TEST_PASSWORD,
        ]);
        $response = $controller->register($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('VALIDATION_ERROR');
    });

    test('clears the rate limit bucket on duplicate email', function (): void {
        [$controller, $authService] = makeAuthController();
        $authService->register('dup@example.com', AUTHCTL_TEST_PASSWORD, 'Existing');

        $req1 = jsonRequest('POST', '/api/v1/auth/register', [
            'email'            => 'dup@example.com',
            'password'         => AUTHCTL_TEST_PASSWORD,
            'display_name'     => 'Dup',
            'confirm_password' => AUTHCTL_TEST_PASSWORD,
        ]);
        $resp1 = $controller->register($req1);
        expect($resp1->getStatusCode())->toBe(Response::HTTP_CONFLICT);

        $req2 = jsonRequest('POST', '/api/v1/auth/register', [
            'email'            => 'dup@example.com',
            'password'         => AUTHCTL_TEST_PASSWORD,
            'display_name'     => 'Dup 2',
            'confirm_password' => AUTHCTL_TEST_PASSWORD,
        ]);
        $resp2 = $controller->register($req2);
        expect($resp2->getStatusCode())->toBe(Response::HTTP_CONFLICT);
    });
});

// ---------------------------------------------------------------------------
// login()
// ---------------------------------------------------------------------------

describe('AuthController::login', function (): void {
    test('returns 200 with user data on successful login', function (): void {
        [$controller, $authService] = makeAuthController();
        $authService->register('loginok@example.com', AUTHCTL_TEST_PASSWORD, 'Login OK');
        // Manually verify so the login succeeds
        User::where('email', 'loginok@example.com')->update(['verified' => 1]);

        $request = jsonRequest('POST', '/api/v1/auth/login', [
            'email'    => 'loginok@example.com',
            'password' => AUTHCTL_TEST_PASSWORD,
        ]);
        $response = $controller->login($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['user']['email'])->toBe('loginok@example.com');
        expect($body['data'])->toHaveKey('csrf_token');
    });

    test('returns 200 and accepts remember_me flag', function (): void {
        [$controller, $authService] = makeAuthController();
        $authService->register('remember@example.com', AUTHCTL_TEST_PASSWORD, 'Remember');
        User::where('email', 'remember@example.com')->update(['verified' => 1]);

        $request = jsonRequest('POST', '/api/v1/auth/login', [
            'email'       => 'remember@example.com',
            'password'    => AUTHCTL_TEST_PASSWORD,
            'remember_me' => true,
        ]);
        $response = $controller->login($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    });

    test('returns 200 with default user shape when UserService returns null', function (): void {
        [$controller, $authService] = makeAuthController();
        $authService->register('no-profile@example.com', AUTHCTL_TEST_PASSWORD, 'No Profile');
        User::where('email', 'no-profile@example.com')->update(['verified' => 1]);

        $userService = Mockery::mock(UserServiceInterface::class);
        $userService->shouldReceive('getUser')->andReturn(null);
        $csrfService = new CsrfTokenService();
        $validator = new AuthValidator();
        $workflow = new AuthWorkflow($authService, $userService, $csrfService, $validator);
        $controller = new AuthController($authService, $csrfService, $validator, $workflow);

        $request = jsonRequest('POST', '/api/v1/auth/login', [
            'email'    => 'no-profile@example.com',
            'password' => AUTHCTL_TEST_PASSWORD,
        ]);
        $response = $controller->login($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['user']['email'])->toBe('no-profile@example.com');
        expect($body['data']['user']['username'])->toBeNull();
    });

    test('returns 429 when rate limit is exceeded on login', function (): void {
        [$controller] = makeAuthController();

        for ($i = 0; $i < 5; $i++) {
            $req = jsonRequest('POST', '/api/v1/auth/login', [
                'email'    => "flood{$i}@example.com",
                'password' => 'wrong',
            ]);
            $controller->login($req);
        }

        $request = jsonRequest('POST', '/api/v1/auth/login', [
            'email'    => 'flood5@example.com',
            'password' => 'wrong',
        ]);
        $response = $controller->login($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_TOO_MANY_REQUESTS);
    });

    test('returns 400 on invalid JSON', function (): void {
        [$controller] = makeAuthController();

        $request = Request::create('/api/v1/auth/login', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '{not json}');

        $response = $controller->login($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('INVALID_JSON');
    });

    test('returns 422 when email or password is missing', function (): void {
        [$controller] = makeAuthController();

        $request = jsonRequest('POST', '/api/v1/auth/login', ['email' => 'x@example.com']);
        $response = $controller->login($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('VALIDATION_ERROR');
    });

    test('returns 401 on invalid credentials', function (): void {
        [$controller, $authService] = makeAuthController();
        $authService->register('real@example.com', AUTHCTL_TEST_PASSWORD, 'Real');
        User::where('email', 'real@example.com')->update(['verified' => 1]);

        $request = jsonRequest('POST', '/api/v1/auth/login', [
            'email'    => 'real@example.com',
            'password' => 'WrongPassword1!',
        ]);
        $response = $controller->login($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('INVALID_CREDENTIALS');
    });

    test('returns 403 when account is unverified', function (): void {
        [$controller, $authService] = makeAuthController();
        $authService->register('unverified@example.com', AUTHCTL_TEST_PASSWORD, 'Unverified');
        // AuthService auto-verifies when no system mailer is set; force-unverify here
        User::where('email', 'unverified@example.com')->update(['verified' => 0]);

        $request = jsonRequest('POST', '/api/v1/auth/login', [
            'email'    => 'unverified@example.com',
            'password' => AUTHCTL_TEST_PASSWORD,
        ]);
        $response = $controller->login($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_FORBIDDEN);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('ACCOUNT_UNVERIFIED');
    });

    test('clears the rate limit bucket on successful login', function (): void {
        [$controller, $authService] = makeAuthController();
        $authService->register('clear@example.com', AUTHCTL_TEST_PASSWORD, 'Clear');
        User::where('email', 'clear@example.com')->update(['verified' => 1]);

        // 4 failed attempts (under limit)
        for ($i = 0; $i < 4; $i++) {
            $req = jsonRequest('POST', '/api/v1/auth/login', [
                'email'    => 'clear@example.com',
                'password' => 'wrong',
            ]);
            $controller->login($req);
        }

        $okReq = jsonRequest('POST', '/api/v1/auth/login', [
            'email'    => 'clear@example.com',
            'password' => AUTHCTL_TEST_PASSWORD,
        ]);
        $okResp = $controller->login($okReq);
        expect($okResp->getStatusCode())->toBe(Response::HTTP_OK);

        // After successful login the bucket should be cleared (rate limit reset)
        $statusReq = jsonRequest('POST', '/api/v1/auth/login', [
            'email'    => 'clear@example.com',
            'password' => 'wrong',
        ]);
        $statusResp = $controller->login($statusReq);
        expect($statusResp->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);
    });
});

// ---------------------------------------------------------------------------
// logout()
// ---------------------------------------------------------------------------

describe('AuthController::logout', function (): void {
    test('returns 204 with empty body', function (): void {
        [$controller] = makeAuthController();

        $response = $controller->logout();

        expect($response->getStatusCode())->toBe(Response::HTTP_NO_CONTENT);
        expect($response->getContent())->toBe('');
    });
});

// ---------------------------------------------------------------------------
// me()
// ---------------------------------------------------------------------------

describe('AuthController::me', function (): void {
    test('returns 401 when not authenticated', function (): void {
        [$controller] = makeAuthController();

        $response = $controller->me();

        expect($response->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('UNAUTHENTICATED');
    });

    test('returns 404 when user record does not exist', function (): void {
        [$controller, $authService] = makeAuthController();
        $userId = $authService->register('ghost@example.com', AUTHCTL_TEST_PASSWORD, 'Ghost');
        simulateLoggedInSession($userId, 'ghost@example.com');
        // Wipe the user so getUser() returns null
        User::where('id', $userId)->delete();

        $response = $controller->me();

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('NOT_FOUND');
    });

    test('returns 200 with user data and roles when authenticated', function (): void {
        [$controller, $authService] = makeAuthController();
        $userId = $authService->register('me@example.com', AUTHCTL_TEST_PASSWORD, 'Me User');
        simulateLoggedInSession($userId, 'me@example.com');

        $response = $controller->me();

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['user']['email'])->toBe('me@example.com');
        expect($body['data']['user']['name'])->toBe('Me User');
        expect($body['data']['user']['is_admin'])->toBeFalse();
        expect($body['data']['user']['roles'])->toBe([]);
        expect($body['data']['user']['registered'])->toBeString();
        expect($body['data'])->toHaveKey('csrf_token');
    });

    test('returns 200 with is_admin true when user has ADMIN role', function (): void {
        [$controller, $authService] = makeAuthController();
        $userId = $authService->register('admin@example.com', AUTHCTL_TEST_PASSWORD, 'Admin User');
        simulateLoggedInSession($userId, 'admin@example.com');
        $authService->grantRole($userId, Role::ADMIN);

        $response = $controller->me();

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['user']['is_admin'])->toBeTrue();
        expect($body['data']['user']['roles'])->toContain('ADMIN');
    });
});

// ---------------------------------------------------------------------------
// password()
// ---------------------------------------------------------------------------

describe('AuthController::password', function (): void {
    test('returns 401 when not authenticated', function (): void {
        [$controller] = makeAuthController();

        $request = jsonRequest('PATCH', '/api/v1/auth/password', [
            'current_password' => 'old',
            'new_password'     => 'NewPassword1!',
        ]);
        $response = $controller->password($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);
    });

    test('returns 400 on invalid JSON', function (): void {
        [$controller, $authService] = makeAuthController();
        $userId = $authService->register('pw@example.com', AUTHCTL_TEST_PASSWORD, 'PW User');
        simulateLoggedInSession($userId, 'pw@example.com');

        $request = Request::create('/api/v1/auth/password', 'PATCH', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], 'garbage');

        $response = $controller->password($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
    });

    test('returns 422 when required fields are missing', function (): void {
        [$controller, $authService] = makeAuthController();
        $userId = $authService->register('pwm@example.com', AUTHCTL_TEST_PASSWORD, 'PWM User');
        simulateLoggedInSession($userId, 'pwm@example.com');

        $request = jsonRequest('PATCH', '/api/v1/auth/password', []);
        $response = $controller->password($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('VALIDATION_ERROR');
    });

    test('returns 200 on successful password change', function (): void {
        [$controller, $authService] = makeAuthController();
        $userId = $authService->register('pwc@example.com', AUTHCTL_TEST_PASSWORD, 'PWC User');
        simulateLoggedInSession($userId, 'pwc@example.com');

        $request = jsonRequest('PATCH', '/api/v1/auth/password', [
            'current_password' => AUTHCTL_TEST_PASSWORD,
            'new_password'     => 'NewPassword2!',
        ]);
        $response = $controller->password($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['message'])->toBe('Password updated');
    });

    test('returns 422 when current password is wrong (InvalidPasswordException from delight-im)', function (): void {
        // delight-im's changePassword throws InvalidPasswordException for both
        // "wrong old password" and "invalid new password". The controller catches
        // that and returns 422 INVALID_PASSWORD. This test documents that
        // current (slightly inaccurate) behavior.
        [$controller, $authService] = makeAuthController();
        $userId = $authService->register('pwx@example.com', AUTHCTL_TEST_PASSWORD, 'PWX User');
        simulateLoggedInSession($userId, 'pwx@example.com');

        $request = jsonRequest('PATCH', '/api/v1/auth/password', [
            'current_password' => 'Definitely-Wrong-Password-123!',
            'new_password'     => 'NewPassword3!',
        ]);
        $response = $controller->password($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('INVALID_PASSWORD');
    });
});

// ---------------------------------------------------------------------------
// account()
// ---------------------------------------------------------------------------

describe('AuthController::account', function (): void {
    test('returns 401 when not authenticated', function (): void {
        [$controller] = makeAuthController();

        $request = jsonRequest('PATCH', '/api/v1/auth/account', ['name' => 'New Name']);
        $response = $controller->account($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);
    });

    test('returns 400 on invalid JSON', function (): void {
        [$controller, $authService] = makeAuthController();
        $userId = $authService->register('acc@example.com', AUTHCTL_TEST_PASSWORD, 'Acc User');
        simulateLoggedInSession($userId, 'acc@example.com');

        $request = Request::create('/api/v1/auth/account', 'PATCH', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], 'not json');

        $response = $controller->account($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
    });

    test('returns 404 when user does not exist', function (): void {
        [$controller, $authService] = makeAuthController();
        $userId = $authService->register('accg@example.com', AUTHCTL_TEST_PASSWORD, 'Acc Gone');
        simulateLoggedInSession($userId, 'accg@example.com');
        User::where('id', $userId)->delete();

        $request = jsonRequest('PATCH', '/api/v1/auth/account', ['name' => 'X']);
        $response = $controller->account($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('NOT_FOUND');
    });

    test('returns 200 with updated user data', function (): void {
        [$controller, $authService] = makeAuthController();
        $userId = $authService->register('acc2@example.com', AUTHCTL_TEST_PASSWORD, 'Acc Two');
        simulateLoggedInSession($userId, 'acc2@example.com');

        $request = jsonRequest('PATCH', '/api/v1/auth/account', ['name' => 'Renamed']);
        $response = $controller->account($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['user']['name'])->toBe('Renamed');
    });
});

// ---------------------------------------------------------------------------
// verify()
// ---------------------------------------------------------------------------

describe('AuthController::verify', function (): void {
    test('returns 422 when selector is empty', function (): void {
        [$controller] = makeAuthController();

        $request = Request::create('/api/v1/auth/verify/', 'GET');
        $response = $controller->verify($request, '');

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('VALIDATION_ERROR');
    });

    test('returns 422 when token query is missing', function (): void {
        [$controller] = makeAuthController();

        $request = Request::create('/api/v1/auth/verify/abc', 'GET');
        $response = $controller->verify($request, 'abc');

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    });

    test('returns 400 on invalid selector/token pair', function (): void {
        [$controller] = makeAuthController();

        $request = Request::create('/api/v1/auth/verify/nonexistent?token=fake', 'GET');
        $response = $controller->verify($request, 'nonexistent');

        expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('INVALID_TOKEN');
    });

    test('returns 200 on successful verification with valid selector/token', function (): void {
        [$controller, $authService] = makeAuthController();
        $authService->register('verify-ok@example.com', AUTHCTL_TEST_PASSWORD, 'Verify OK');

        // Manually insert a valid confirmation row for the user
        $user = User::where('email', 'verify-ok@example.com')->first();
        $selector = 'sel' . bin2hex(random_bytes(8));
        $rawToken = 'tok' . bin2hex(random_bytes(8));
        $hashedToken = \Delight\Auth\TokenHash::from($rawToken);

        DB::table('users_confirmations')->insert([
            'user_id'  => (int) $user->id,
            'email'    => 'verify-ok@example.com',
            'selector' => $selector,
            'token'    => $hashedToken,
            'expires'  => time() + 86400,
        ]);

        $request = Request::create("/api/v1/auth/verify/{$selector}?token=" . urlencode($rawToken), 'GET');
        $response = $controller->verify($request, $selector);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['message'])->toBe('Email verified successfully.');
    });

    test('returns 400 when confirmation token is expired', function (): void {
        [$controller, $authService] = makeAuthController();
        $authService->register('verify-exp@example.com', AUTHCTL_TEST_PASSWORD, 'Verify Expired');

        $user = User::where('email', 'verify-exp@example.com')->first();
        $selector = 'exp' . bin2hex(random_bytes(8));
        $rawToken = 'tok' . bin2hex(random_bytes(8));
        $hashedToken = \Delight\Auth\TokenHash::from($rawToken);

        DB::table('users_confirmations')->insert([
            'user_id'  => (int) $user->id,
            'email'    => 'verify-exp@example.com',
            'selector' => $selector,
            'token'    => $hashedToken,
            'expires'  => time() - 1, // already expired
        ]);

        $request = Request::create("/api/v1/auth/verify/{$selector}?token=" . urlencode($rawToken), 'GET');
        $response = $controller->verify($request, $selector);

        expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('TOKEN_EXPIRED');
    });

    test('returns 400 when token value does not match the stored hash', function (): void {
        [$controller, $authService] = makeAuthController();
        $authService->register('verify-bad-tok@example.com', AUTHCTL_TEST_PASSWORD, 'Verify Bad Tok');

        $user = User::where('email', 'verify-bad-tok@example.com')->first();
        $selector = 'btk' . bin2hex(random_bytes(8));
        $hashedToken = \Delight\Auth\TokenHash::from('correct-token');

        DB::table('users_confirmations')->insert([
            'user_id'  => (int) $user->id,
            'email'    => 'verify-bad-tok@example.com',
            'selector' => $selector,
            'token'    => $hashedToken,
            'expires'  => time() + 86400,
        ]);

        $request = Request::create("/api/v1/auth/verify/{$selector}?token=wrong-token", 'GET');
        $response = $controller->verify($request, $selector);

        expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('INVALID_TOKEN');
    });

    test('returns 409 when the new email is already in use by another account', function (): void {
        // Register two users: a target whose email will collide, and a subject
        // whose pending confirmation tries to switch to the target's email.
        [$controller, $authService] = makeAuthController();
        $authService->register('verify-target@example.com', AUTHCTL_TEST_PASSWORD, 'Verify Target');
        $subjectId = $authService->register('verify-subject@example.com', AUTHCTL_TEST_PASSWORD, 'Verify Subject');

        $selector = 'vcol' . bin2hex(random_bytes(8));
        $rawToken = 'tok' . bin2hex(random_bytes(8));
        $hashedToken = \Delight\Auth\TokenHash::from($rawToken);

        // The subject's pending confirmation points at the target's email
        DB::table('users_confirmations')->insert([
            'user_id'  => $subjectId,
            'email'    => 'verify-target@example.com',
            'selector' => $selector,
            'token'    => $hashedToken,
            'expires'  => time() + 86400,
        ]);

        $request = Request::create("/api/v1/auth/verify/{$selector}?token=" . urlencode($rawToken), 'GET');
        $response = $controller->verify($request, $selector);

        expect($response->getStatusCode())->toBe(Response::HTTP_CONFLICT);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('EMAIL_TAKEN');
    });
});

// ---------------------------------------------------------------------------
// forgotPassword()
// ---------------------------------------------------------------------------

describe('AuthController::forgotPassword', function (): void {
    test('returns 200 when email exists and user is verified', function (): void {
        [$controller, $authService] = makeAuthController();
        $authService->register('forgot@example.com', AUTHCTL_TEST_PASSWORD, 'Forgot');

        $request = jsonRequest('POST', '/api/v1/auth/forgot-password', [
            'email' => 'forgot@example.com',
        ]);
        $response = $controller->forgotPassword($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['message'])->toBe('If an account with that email exists, a password reset email has been sent.');
    });

    test('returns 429 when rate limit is exceeded', function (): void {
        [$controller] = makeAuthController();

        // Pre-fill the rate limiter bucket (5 attempts) so the next call returns 429
        for ($i = 0; $i < 5; $i++) {
            RateLimiter::attempt('127.0.0.1', 5, 60);
        }

        $request = jsonRequest('POST', '/api/v1/auth/forgot-password', [
            'email' => 'sixth@example.com',
        ]);
        $response = $controller->forgotPassword($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_TOO_MANY_REQUESTS);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('TOO_MANY_REQUESTS');
        expect($response->headers->get('Retry-After'))->toBeString();
    });

    test('returns 400 on invalid JSON', function (): void {
        [$controller] = makeAuthController();

        $request = Request::create('/api/v1/auth/forgot-password', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], 'oops');
        $response = $controller->forgotPassword($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
    });

    test('returns 422 when email is missing', function (): void {
        [$controller] = makeAuthController();

        $request = jsonRequest('POST', '/api/v1/auth/forgot-password', []);
        $response = $controller->forgotPassword($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('VALIDATION_ERROR');
    });
});

// ---------------------------------------------------------------------------
// resetPassword()
// ---------------------------------------------------------------------------

describe('AuthController::resetPassword', function (): void {
    test('returns 400 on invalid JSON', function (): void {
        [$controller] = makeAuthController();

        $request = Request::create('/api/v1/auth/reset-password', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], 'not json');
        $response = $controller->resetPassword($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('INVALID_JSON');
    });

    test('returns 422 when required fields are missing', function (): void {
        [$controller] = makeAuthController();

        $request = jsonRequest('POST', '/api/v1/auth/reset-password', ['selector' => 's']);
        $response = $controller->resetPassword($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    });

    test('returns 400 on invalid selector', function (): void {
        [$controller] = makeAuthController();

        $request = jsonRequest('POST', '/api/v1/auth/reset-password', [
            'selector' => 'bad',
            'token'    => 'bad',
            'password' => 'NewPassword1!',
        ]);
        $response = $controller->resetPassword($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('INVALID_TOKEN');
    });

    test('returns 422 when token is valid but password is technically invalid (InvalidPasswordException)', function (): void {
        // delight-im's resetPassword calls validatePassword which throws
        // InvalidPasswordException for empty/oversized passwords only. The
        // controller maps that to 422 VALIDATION_ERROR. This test confirms
        // the error envelope for that edge case.
        [$controller, $authService] = makeAuthController();
        $authService->register('reset-weak@example.com', AUTHCTL_TEST_PASSWORD, 'Reset Weak');

        $user = User::where('email', 'reset-weak@example.com')->first();
        $selector = 'rwk' . bin2hex(random_bytes(8));
        $rawToken = 'tok' . bin2hex(random_bytes(8));
        $hashedToken = \Delight\Auth\TokenHash::from($rawToken);

        DB::table('users_resets')->insert([
            'user'     => (int) $user->id,
            'selector' => $selector,
            'token'    => $hashedToken,
            'expires'  => time() + 86400,
        ]);

        $request = jsonRequest('POST', '/api/v1/auth/reset-password', [
            'selector' => $selector,
            'token'    => $rawToken,
            'password' => '',
        ]);
        $response = $controller->resetPassword($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('VALIDATION_ERROR');
    });

    test('returns 200 on successful reset', function (): void {
        [$controller, $authService] = makeAuthController();
        $authService->register('reset-ok@example.com', AUTHCTL_TEST_PASSWORD, 'Reset OK');

        $user = User::where('email', 'reset-ok@example.com')->first();
        $selector = 'rok' . bin2hex(random_bytes(8));
        $rawToken = 'tok' . bin2hex(random_bytes(8));
        $hashedToken = \Delight\Auth\TokenHash::from($rawToken);

        DB::table('users_resets')->insert([
            'user'     => (int) $user->id,
            'selector' => $selector,
            'token'    => $hashedToken,
            'expires'  => time() + 86400,
        ]);

        $request = jsonRequest('POST', '/api/v1/auth/reset-password', [
            'selector' => $selector,
            'token'    => $rawToken,
            'password' => 'NewPassword1!',
        ]);
        $response = $controller->resetPassword($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['message'])->toBe('Password reset successfully.');
    });

    test('returns 400 when reset token is expired', function (): void {
        [$controller, $authService] = makeAuthController();
        $authService->register('reset-exp@example.com', AUTHCTL_TEST_PASSWORD, 'Reset Expired');

        $user = User::where('email', 'reset-exp@example.com')->first();
        $selector = 'rexp' . bin2hex(random_bytes(8));
        $rawToken = 'tok' . bin2hex(random_bytes(8));
        $hashedToken = \Delight\Auth\TokenHash::from($rawToken);

        DB::table('users_resets')->insert([
            'user'     => (int) $user->id,
            'selector' => $selector,
            'token'    => $hashedToken,
            'expires'  => time() - 1,
        ]);

        $request = jsonRequest('POST', '/api/v1/auth/reset-password', [
            'selector' => $selector,
            'token'    => $rawToken,
            'password' => 'NewPassword1!',
        ]);
        $response = $controller->resetPassword($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('INVALID_TOKEN');
    });

    test('returns 403 when password reset is disabled for the user', function (): void {
        [$controller, $authService] = makeAuthController();
        $authService->register('reset-dis@example.com', AUTHCTL_TEST_PASSWORD, 'Reset Disabled');
        $user = User::where('email', 'reset-dis@example.com')->first();
        // Disable password reset for this user
        User::where('id', $user->id)->update(['resettable' => 0]);

        $selector = 'rdis' . bin2hex(random_bytes(8));
        $rawToken = 'tok' . bin2hex(random_bytes(8));
        $hashedToken = \Delight\Auth\TokenHash::from($rawToken);

        DB::table('users_resets')->insert([
            'user'     => (int) $user->id,
            'selector' => $selector,
            'token'    => $hashedToken,
            'expires'  => time() + 86400,
        ]);

        $request = jsonRequest('POST', '/api/v1/auth/reset-password', [
            'selector' => $selector,
            'token'    => $rawToken,
            'password' => 'NewPassword1!',
        ]);
        $response = $controller->resetPassword($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_FORBIDDEN);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('RESET_DISABLED');
    });
});

// ---------------------------------------------------------------------------
// resendVerification()
// ---------------------------------------------------------------------------

describe('AuthController::resendVerification', function (): void {
    test('returns 200 even when email is unknown (no enumeration)', function (): void {
        [$controller] = makeAuthController();

        $request = jsonRequest('POST', '/api/v1/auth/verification/resend', [
            'email' => 'unknown@example.com',
        ]);
        $response = $controller->resendVerification($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['message'])->toContain('verification email has been sent');
    });

    test('returns 200 when email belongs to an existing user', function (): void {
        [$controller, $authService] = makeAuthController();
        $authService->register('resend@example.com', AUTHCTL_TEST_PASSWORD, 'Resend User');

        $request = jsonRequest('POST', '/api/v1/auth/verification/resend', [
            'email' => 'resend@example.com',
        ]);
        $response = $controller->resendVerification($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    });

    test('returns 429 when rate limit is exceeded', function (): void {
        [$controller] = makeAuthController();

        // Pre-fill the rate limiter bucket so the next call returns 429
        for ($i = 0; $i < 5; $i++) {
            RateLimiter::attempt('127.0.0.1', 5, 60);
        }

        $request = jsonRequest('POST', '/api/v1/auth/verification/resend', [
            'email' => 'sixth@example.com',
        ]);
        $response = $controller->resendVerification($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_TOO_MANY_REQUESTS);
    });

    test('returns 400 on invalid JSON', function (): void {
        [$controller] = makeAuthController();

        $request = Request::create('/api/v1/auth/verification/resend', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], 'oops');
        $response = $controller->resendVerification($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
    });

    test('returns 422 when email is missing', function (): void {
        [$controller] = makeAuthController();

        $request = jsonRequest('POST', '/api/v1/auth/verification/resend', []);
        $response = $controller->resendVerification($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('VALIDATION_ERROR');
    });
});

// ---------------------------------------------------------------------------
// requestEmailChange()
// ---------------------------------------------------------------------------

describe('AuthController::requestEmailChange', function (): void {
    test('returns 401 when not authenticated', function (): void {
        [$controller] = makeAuthController();

        $request = jsonRequest('POST', '/api/v1/auth/email/change-request', [
            'email' => 'new@example.com',
        ]);
        $response = $controller->requestEmailChange($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);
    });

    test('returns 400 on invalid JSON', function (): void {
        [$controller, $authService] = makeAuthController();
        $userId = $authService->register('chg@example.com', AUTHCTL_TEST_PASSWORD, 'Chg User');
        simulateLoggedInSession($userId, 'chg@example.com');

        $request = Request::create('/api/v1/auth/email/change-request', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], 'oops');

        $response = $controller->requestEmailChange($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
    });

    test('returns 422 when email is missing', function (): void {
        [$controller, $authService] = makeAuthController();
        $userId = $authService->register('chgm@example.com', AUTHCTL_TEST_PASSWORD, 'ChgM');
        simulateLoggedInSession($userId, 'chgm@example.com');

        $request = jsonRequest('POST', '/api/v1/auth/email/change-request', []);
        $response = $controller->requestEmailChange($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    });

    test('returns 403 when current email is not verified', function (): void {
        [$controller, $authService] = makeAuthController();
        $userId = $authService->register('chg-uv@example.com', AUTHCTL_TEST_PASSWORD, 'Chg UV');
        // AuthService auto-verifies when no system mailer is set; force-unverify here
        User::where('id', $userId)->update(['verified' => 0]);
        simulateLoggedInSession($userId, 'chg-uv@example.com');

        $request = jsonRequest('POST', '/api/v1/auth/email/change-request', [
            'email' => 'newemail@example.com',
        ]);
        $response = $controller->requestEmailChange($request);

        // The current user is unverified, so the request fails with EMAIL_NOT_VERIFIED
        expect($response->getStatusCode())->toBe(Response::HTTP_FORBIDDEN);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('EMAIL_NOT_VERIFIED');
    });

    test('returns 200 on successful change request when current email is verified', function (): void {
        [$controller, $authService] = makeAuthController();
        $userId = $authService->register('chg-ok@example.com', AUTHCTL_TEST_PASSWORD, 'Chg OK');
        User::where('id', $userId)->update(['verified' => 1]);
        simulateLoggedInSession($userId, 'chg-ok@example.com');

        $request = jsonRequest('POST', '/api/v1/auth/email/change-request', [
            'email' => 'newemail@example.com',
        ]);
        $response = $controller->requestEmailChange($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['message'])->toBe('A confirmation email has been sent to your new email address.');
    });

    test('returns 422 when email is invalid', function (): void {
        [$controller, $authService] = makeAuthController();
        $userId = $authService->register('chg-ie@example.com', AUTHCTL_TEST_PASSWORD, 'Chg IE');
        User::where('id', $userId)->update(['verified' => 1]);
        simulateLoggedInSession($userId, 'chg-ie@example.com');

        $request = jsonRequest('POST', '/api/v1/auth/email/change-request', [
            'email' => 'not-valid',
        ]);
        $response = $controller->requestEmailChange($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('VALIDATION_ERROR');
    });

    test('returns 409 when new email is already taken', function (): void {
        [$controller, $authService] = makeAuthController();
        $userId = $authService->register('chg-take@example.com', AUTHCTL_TEST_PASSWORD, 'Chg Take');
        User::where('id', $userId)->update(['verified' => 1]);
        simulateLoggedInSession($userId, 'chg-take@example.com');

        // Another user already owns the target email
        $authService->register('taken-target@example.com', AUTHCTL_TEST_PASSWORD, 'Taken Target');

        $request = jsonRequest('POST', '/api/v1/auth/email/change-request', [
            'email' => 'taken-target@example.com',
        ]);
        $response = $controller->requestEmailChange($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_CONFLICT);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('EMAIL_TAKEN');
    });
});

// ---------------------------------------------------------------------------
// confirmEmailChange()
// ---------------------------------------------------------------------------

describe('AuthController::confirmEmailChange', function (): void {
    test('returns 400 on invalid JSON', function (): void {
        [$controller] = makeAuthController();

        $request = Request::create('/api/v1/auth/email/confirm', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], 'oops');
        $response = $controller->confirmEmailChange($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
    });

    test('returns 422 when required fields are missing', function (): void {
        [$controller] = makeAuthController();

        $request = jsonRequest('POST', '/api/v1/auth/email/confirm', ['selector' => 's']);
        $response = $controller->confirmEmailChange($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    });

    test('returns 400 on invalid selector/token pair', function (): void {
        [$controller] = makeAuthController();

        $request = jsonRequest('POST', '/api/v1/auth/email/confirm', [
            'selector' => 'nonexistent-selector',
            'token'    => 'nonexistent-token',
        ]);
        $response = $controller->confirmEmailChange($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('INVALID_TOKEN');
    });

    test('returns 200 on successful email change confirmation', function (): void {
        [$controller, $authService] = makeAuthController();
        $authService->register('confirm-ok@example.com', AUTHCTL_TEST_PASSWORD, 'Confirm OK');

        $user = User::where('email', 'confirm-ok@example.com')->first();
        $selector = 'cok' . bin2hex(random_bytes(8));
        $rawToken = 'tok' . bin2hex(random_bytes(8));
        $hashedToken = \Delight\Auth\TokenHash::from($rawToken);

        // Insert a confirmation row where the new email differs from the old
        DB::table('users_confirmations')->insert([
            'user_id'  => (int) $user->id,
            'email'    => 'confirm-new@example.com',
            'selector' => $selector,
            'token'    => $hashedToken,
            'expires'  => time() + 86400,
        ]);

        $request = jsonRequest('POST', '/api/v1/auth/email/confirm', [
            'selector' => $selector,
            'token'    => $rawToken,
        ]);
        $response = $controller->confirmEmailChange($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['message'])->toBe('Email address changed successfully.');
    });

    test('returns 400 when confirmation token is expired', function (): void {
        [$controller, $authService] = makeAuthController();
        $authService->register('confirm-exp@example.com', AUTHCTL_TEST_PASSWORD, 'Confirm Exp');

        $user = User::where('email', 'confirm-exp@example.com')->first();
        $selector = 'cexp' . bin2hex(random_bytes(8));
        $rawToken = 'tok' . bin2hex(random_bytes(8));
        $hashedToken = \Delight\Auth\TokenHash::from($rawToken);

        DB::table('users_confirmations')->insert([
            'user_id'  => (int) $user->id,
            'email'    => 'confirm-exp@example.com',
            'selector' => $selector,
            'token'    => $hashedToken,
            'expires'  => time() - 1,
        ]);

        $request = jsonRequest('POST', '/api/v1/auth/email/confirm', [
            'selector' => $selector,
            'token'    => $rawToken,
        ]);
        $response = $controller->confirmEmailChange($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('TOKEN_EXPIRED');
    });
});
