<?php

declare(strict_types=1);

const SLOW_USER_EMAIL = 'slowuser@example.com';
const RETRY_USER_EMAIL = 'retryuser@example.com';
const CLEAR_USER_EMAIL = 'clearuser@example.com';
const PW_USER_EMAIL = 'pwuser@example.com';
const VALID_PASSWORD = 'Password1!';
const OLD_PASSWORD = 'OldPassword1!';
const NEW_PASSWORD = 'NewPassword1!';
const LOGIN_ENDPOINT = '/api/v1/auth/login';
const RESET_PASSWORD_ENDPOINT = '/api/v1/auth/reset-password';
const PASSWORD_ENDPOINT = '/api/v1/auth/password';

use Spora\Http\AuthController;
use Spora\Services\RateLimiter;
use Spora\Services\UserServiceInterface;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    RateLimiter::resetAll();
});

afterEach(function (): void {
    RateLimiter::resetAll();
});

function makeAuthControllerWithMocks(): array
{
    clearSession();
    $authService = bootAuthLayer();
    $userService = Mockery::mock(UserServiceInterface::class);
    $userService->allows('getUser')->andReturn(null)->byDefault();
    $csrfService = new Spora\Security\CsrfTokenService();
    $controller = new AuthController($authService, $userService, $csrfService, new Spora\Services\AuthValidator(), ['allow_registration' => true]);

    return [$controller, $authService];
}

function makeAuthControllerWithUserService(): array
{
    clearSession();
    $authService = bootAuthLayer();
    $userService = Mockery::mock(UserServiceInterface::class);
    $userService->allows('getUser')->andReturn(null)->byDefault();
    $userService->allows('updateUser')->andReturnUsing(function (int $userId, array $data): array {
        return ['user' => ['id' => $userId, 'username' => $data['username'] ?? null]];
    })->byDefault();
    $csrfService = new Spora\Security\CsrfTokenService();
    $controller = new AuthController($authService, $userService, $csrfService, new Spora\Services\AuthValidator(), ['allow_registration' => true]);

    return [$controller, $authService, $userService];
}

// Rate limiting

test('login returns 429 after exceeding rate limit', function (): void {
    [$controller] = makeAuthControllerWithMocks();

    $authService = bootAuthLayer();
    $authService->register(SLOW_USER_EMAIL, VALID_PASSWORD, 'Slow User');

    for ($i = 0; $i < 5; $i++) {
        $req = jsonRequest('POST', LOGIN_ENDPOINT, [
            'email' => SLOW_USER_EMAIL,
            'password' => 'wrongpassword',
        ]);
        $controller->login($req);
    }

    $req = jsonRequest('POST', LOGIN_ENDPOINT, [
        'email' => SLOW_USER_EMAIL,
        'password' => 'wrongpassword',
    ]);
    $response = $controller->login($req);

    expect($response->getStatusCode())->toBe(Response::HTTP_TOO_MANY_REQUESTS);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('TOO_MANY_REQUESTS');
});

test('login returns rate limit headers on success', function (): void {
    [$controller, $authService] = makeAuthControllerWithMocks();
    $authService->register('headeruser@example.com', VALID_PASSWORD, 'Header User');

    $req = jsonRequest('POST', LOGIN_ENDPOINT, [
        'email' => 'headeruser@example.com',
        'password' => VALID_PASSWORD,
    ]);
    $response = $controller->login($req);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    expect($response->headers->get('X-RateLimit-Limit'))->toBe('5');
    expect($response->headers->get('X-RateLimit-Remaining'))->toBe('5');
});

test('login includes Retry-After header when rate limited', function (): void {
    [$controller] = makeAuthControllerWithMocks();

    $authService = bootAuthLayer();
    $authService->register(RETRY_USER_EMAIL, VALID_PASSWORD, 'Retry User');

    for ($i = 0; $i < 5; $i++) {
        $req = jsonRequest('POST', LOGIN_ENDPOINT, [
            'email' => RETRY_USER_EMAIL,
            'password' => 'wrong',
        ]);
        $controller->login($req);
    }

    $req = jsonRequest('POST', LOGIN_ENDPOINT, [
        'email' => RETRY_USER_EMAIL,
        'password' => 'wrong',
    ]);
    $response = $controller->login($req);

    expect($response->headers->has('Retry-After'))->toBeTrue();
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
});

test('register returns 429 after exceeding rate limit', function (): void {
    [$controller] = makeAuthControllerWithMocks();

    for ($i = 0; $i < 5; $i++) {
        $req = jsonRequest('POST', '/api/v1/auth/register', [
            'email' => "ratelimit{$i}@example.com",
            'password' => VALID_PASSWORD,
            'display_name' => "Ratelimit{$i}",
        ]);
        $controller->register($req);
    }

    $req = jsonRequest('POST', '/api/v1/auth/register', [
        'email' => 'ratelimit6@example.com',
        'password' => VALID_PASSWORD,
        'display_name' => 'Ratelimit6',
    ]);
    $response = $controller->register($req);

    expect($response->getStatusCode())->toBe(Response::HTTP_TOO_MANY_REQUESTS);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('TOO_MANY_REQUESTS');
});

test('successful login clears rate limit bucket', function (): void {
    [$controller, $authService] = makeAuthControllerWithMocks();
    $authService->register(CLEAR_USER_EMAIL, VALID_PASSWORD, 'Clear User');

    for ($i = 0; $i < 3; $i++) {
        $req = jsonRequest('POST', LOGIN_ENDPOINT, [
            'email' => CLEAR_USER_EMAIL,
            'password' => 'wrong',
        ]);
        $controller->login($req);
    }

    $req = jsonRequest('POST', LOGIN_ENDPOINT, [
        'email' => CLEAR_USER_EMAIL,
        'password' => VALID_PASSWORD,
    ]);
    $response = $controller->login($req);
    expect($response->getStatusCode())->toBe(Response::HTTP_OK);

    for ($i = 0; $i < 3; $i++) {
        $req = jsonRequest('POST', LOGIN_ENDPOINT, [
            'email' => CLEAR_USER_EMAIL,
            'password' => 'wrong',
        ]);
        $response = $controller->login($req);
        expect($response->getStatusCode())->not()->toBe(Response::HTTP_TOO_MANY_REQUESTS);
    }
});

test('password endpoint changes password', function (): void {
    [$controller, $authService] = makeAuthControllerWithMocks();
    $authService->register(PW_USER_EMAIL, OLD_PASSWORD, 'Pw User');

    $req = jsonRequest('POST', LOGIN_ENDPOINT, [
        'email' => PW_USER_EMAIL,
        'password' => OLD_PASSWORD,
    ]);
    $controller->login($req);

    $req = jsonRequest('PATCH', PASSWORD_ENDPOINT, [
        'current_password' => OLD_PASSWORD,
        'new_password' => NEW_PASSWORD,
    ]);
    $response = $controller->password($req);
    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    $body = json_decode($response->getContent(), true);
    expect($body['message'])->toBe('Password updated');

    $req = jsonRequest('POST', LOGIN_ENDPOINT, [
        'email' => PW_USER_EMAIL,
        'password' => NEW_PASSWORD,
    ]);
    $response = $controller->login($req);
    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
});

test('password endpoint requires authentication', function (): void {
    [$controller] = makeAuthControllerWithMocks();

    $req = jsonRequest('PATCH', PASSWORD_ENDPOINT, [
        'current_password' => 'old',
        'new_password' => 'new',
    ]);
    $response = $controller->password($req);
    expect($response->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);
});

test('password endpoint validates required fields', function (): void {
    [$controller, $authService] = makeAuthControllerWithMocks();
    $authService->register('pwvalidate@example.com', VALID_PASSWORD, 'Pw Validate');

    $req = jsonRequest('POST', LOGIN_ENDPOINT, [
        'email' => 'pwvalidate@example.com',
        'password' => VALID_PASSWORD,
    ]);
    $controller->login($req);

    $req = jsonRequest('PATCH', PASSWORD_ENDPOINT, [
        'current_password' => 'old',
        // missing new_password
    ]);
    $response = $controller->password($req);
    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
});

test('account endpoint updates username', function (): void {
    [$controller, $authService] = makeAuthControllerWithUserService();
    $authService->register('accuser@example.com', VALID_PASSWORD, 'Acc User');

    $req = jsonRequest('POST', LOGIN_ENDPOINT, [
        'email' => 'accuser@example.com',
        'password' => VALID_PASSWORD,
    ]);
    $controller->login($req);

    $req = jsonRequest('PATCH', '/api/v1/auth/account', [
        'username' => 'Neuer Name',
    ]);
    $response = $controller->account($req);
    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['user']['username'])->toBe('Neuer Name');
});

test('account endpoint requires authentication', function (): void {
    [$controller] = makeAuthControllerWithUserService();

    $req = jsonRequest('PATCH', '/api/v1/auth/account', [
        'username' => 'AnyName',
    ]);
    $response = $controller->account($req);
    expect($response->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);
});

// Password reset

test('resetPassword resets password with valid selector and token', function (): void {
    [$controller, $authService] = makeAuthControllerWithMocks();

    $email = 'resetuser@example.com';
    $oldPassword = OLD_PASSWORD;
    $newPassword = NEW_PASSWORD;
    $authService->register($email, $oldPassword, 'Reset User');

    // Capture the reset URL by using the real mailer but intercepting via a proxy
    $captured = new ArrayObject(['url' => null]);
    $proxyMailer = new class ($captured) implements Spora\Services\MailerInterface {
        private Spora\Services\SystemMailer $inner;
        private ArrayObject $captured;
        public function __construct(ArrayObject $captured)
        {
            $this->inner = new Spora\Services\SystemMailer(['mail_driver' => 'log']);
            $this->captured = $captured;
        }
        public function sendPasswordResetEmail(string $email, string $resetUrl): bool
        {
            $this->captured['url'] = $resetUrl;
            return true;
        }
        public function sendVerificationEmail(string $email, string $verificationUrl): bool
        {
            return $this->inner->sendVerificationEmail($email, $verificationUrl);
        }
        public function sendWelcomeEmail(int $userId, string $email): bool
        {
            return $this->inner->sendWelcomeEmail($userId, $email);
        }
    };
    $authService->setSystemMailer($proxyMailer);

    // Initiate password reset to generate selector/token
    $authService->forgotPassword($email);

    // Parse selector and token from the captured URL
    $capturedUrl = (string) $captured['url'];
    preg_match('#/auth/reset-password/([^?]+)\?token=(.+)#', $capturedUrl, $matches);
    $selector = $matches[1];
    $token = urldecode($matches[2]);

    // Reset the password using captured selector and token
    $req = jsonRequest('POST', RESET_PASSWORD_ENDPOINT, [
        'selector' => $selector,
        'token' => $token,
        'password' => $newPassword,
    ]);
    $response = $controller->resetPassword($req);
    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    $body = json_decode($response->getContent(), true);
    expect($body['message'])->toBe('Password reset successfully.');

    // Login with new password should work
    $req = jsonRequest('POST', LOGIN_ENDPOINT, [
        'email' => $email,
        'password' => $newPassword,
    ]);
    $response = $controller->login($req);
    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
});

test('resetPassword returns error for invalid selector', function (): void {
    [$controller] = makeAuthControllerWithMocks();

    $req = jsonRequest('POST', RESET_PASSWORD_ENDPOINT, [
        'selector' => 'invalid_selector',
        'token' => 'invalid_token',
        'password' => NEW_PASSWORD,
    ]);
    $response = $controller->resetPassword($req);
    expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('INVALID_TOKEN');
});

test('resetPassword validates required fields', function (): void {
    [$controller] = makeAuthControllerWithMocks();

    // Missing selector
    $req = jsonRequest('POST', RESET_PASSWORD_ENDPOINT, [
        'token' => 'some_token',
        'password' => NEW_PASSWORD,
    ]);
    $response = $controller->resetPassword($req);
    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);

    // Missing token
    $req = jsonRequest('POST', RESET_PASSWORD_ENDPOINT, [
        'selector' => 'some_selector',
        'password' => NEW_PASSWORD,
    ]);
    $response = $controller->resetPassword($req);
    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);

    // Missing password
    $req = jsonRequest('POST', RESET_PASSWORD_ENDPOINT, [
        'selector' => 'some_selector',
        'token' => 'some_token',
    ]);
    $response = $controller->resetPassword($req);
    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
});
