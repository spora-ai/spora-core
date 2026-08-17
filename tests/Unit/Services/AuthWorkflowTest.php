<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Spora\Auth\AuthService;
use Spora\Security\CsrfTokenService;
use Spora\Services\AuthValidator;
use Spora\Services\AuthWorkflow;
use Spora\Services\MailerInterface;
use Spora\Services\RateLimiter;
use Spora\Services\UserService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportException;

beforeEach(function (): void {
    RateLimiter::resetAll();
    clearSession();
});

/**
 * Build a minimal AuthWorkflow for direct unit testing.
 *
 * @return array{0: AuthWorkflow, 1: AuthService, 2: AuthValidator}
 */
function makeWorkflow(): array
{
    $authService = bootAuthLayer();
    $userService = new UserService();
    $csrfService = new CsrfTokenService();
    $validator = new AuthValidator();

    return [
        new AuthWorkflow($authService, $userService, $csrfService, $validator),
        $authService,
        $validator,
    ];
}

/**
 * Stub mailer that throws a TransportException from sendVerificationEmail so
 * the AuthWorkflow's catch arm runs against the orphan row delight-im wrote.
 */
final class TransportThrowingMailerStub implements MailerInterface
{
    public int $sendCalls = 0;

    public function sendVerificationEmail(string $email, string $verificationUrl): bool
    {
        $this->sendCalls++;
        throw new TransportException('smtp transport refused: ' . $email);
    }

    public function sendEmailChangeVerificationEmail(string $email, string $verificationUrl): bool
    {
        $this->sendCalls++;
        throw new TransportException('smtp transport refused: ' . $email);
    }

    public function sendPasswordResetEmail(string $email, string $resetUrl): bool
    {
        $this->sendCalls++;
        throw new TransportException('smtp transport refused: ' . $email);
    }

    public function sendWelcomeEmail(int $userId, string $email): bool
    {
        return true;
    }
}

test('performEmailChangeRequest returns 502 EMAIL_SEND_FAILED when the SMTP callback throws', function (): void {
    [$workflow, $authService] = makeWorkflow();

    // Register the user as already-verified so the initial register() callback
    // never runs — only the changeEmail() callback should fire and throw.
    $userId = $authService->register('wf-chg@example.com', 'Password1!', 'WF Chg', true);
    simulateLoggedInSession($userId, 'wf-chg@example.com');

    // Now wire the throwing mailer in for the changeEmail path.
    $mailer = new TransportThrowingMailerStub();
    $authService->setSystemMailer($mailer);

    $request = jsonRequest('POST', '/api/v1/auth/email/change-request', [
        'email' => 'wf-chg-target@example.com',
    ]);

    $response = $workflow->handleEmailChangeRequest($request);

    expect($response)->toBeInstanceOf(JsonResponse::class);
    expect($response->getStatusCode())->toBe(Response::HTTP_BAD_GATEWAY);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('EMAIL_SEND_FAILED');
    expect($body['error']['message'])->toContain('smtp transport refused');
    expect($mailer->sendCalls)->toBe(1);

    // The orphan confirmation row must be cleaned up so the user is not stuck
    // behind the 24h throttle on the next attempt.
    $orphans = DB::table('users_confirmations')->where('email', 'wf-chg-target@example.com')->count();
    expect($orphans)->toBe(0);
});

test('performEmailChangeRequest does not leak the orphan row when the SMTP callback throws', function (): void {
    [$workflow, $authService] = makeWorkflow();

    // Pre-seed an unrelated confirmation row that must survive the cleanup —
    // only the row matching the failed SMTP send should be removed.
    $unrelatedUserId = DB::table('users')->insertGetId([
        'email'      => 'unrelated-user@example.com',
        'username'   => 'unrelated-user',
        'name'       => 'Unrelated User',
        'password'   => password_hash('Password1!', PASSWORD_BCRYPT),
        'verified'   => 1,
        'registered' => time(),
    ]);
    DB::table('users_confirmations')->insert([
        'principal_id' => createUserPrincipalPublic($unrelatedUserId),
        'email'    => 'unrelated@example.com',
        'selector' => 'unrelated-sel',
        'token'    => 'unrelated-token',
        'expires'  => time() + 86400,
    ]);

    $userId = $authService->register('wf-chg-cleanup@example.com', 'Password1!', 'WF Chg Cleanup', true);
    simulateLoggedInSession($userId, 'wf-chg-cleanup@example.com');

    $mailer = new TransportThrowingMailerStub();
    $authService->setSystemMailer($mailer);

    $request = jsonRequest('POST', '/api/v1/auth/email/change-request', [
        'email' => 'wf-chg-cleanup-target@example.com',
    ]);

    $response = $workflow->handleEmailChangeRequest($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_BAD_GATEWAY);

    // The failed target row is gone; the unrelated row survives.
    $target = DB::table('users_confirmations')->where('email', 'wf-chg-cleanup-target@example.com')->count();
    $unrelated = DB::table('users_confirmations')->where('email', 'unrelated@example.com')->count();
    expect($target)->toBe(0);
    expect($unrelated)->toBe(1);
});

test('performEmailChangeRequest succeeds when SMTP is healthy', function (): void {
    [$workflow, $authService] = makeWorkflow();

    $userId = $authService->register('wf-chg-ok@example.com', 'Password1!', 'WF Chg OK', true);
    simulateLoggedInSession($userId, 'wf-chg-ok@example.com');

    // Seed the email templates the SystemMailer (log driver) needs to render.
    // Change-email now uses email_change_verification since PR #N split the
    // confirmation flow into signup / change templates.
    \Spora\Models\MailTemplate::create([
        'name'      => 'email_verification',
        'subject'   => 'Verify your email',
        'body' => 'Click: {{verification_link}}',
        'body_html' => '<p>Click: {{verification_link}}</p>',
    ]);
    \Spora\Models\MailTemplate::create([
        'name'      => 'email_change_verification',
        'subject'   => 'Confirm your new email',
        'body' => 'Click: {{verification_link}}',
        'body_html' => '<p>Click: {{verification_link}}</p>',
    ]);

    // Use the real SystemMailer pointed at the log driver — no real network,
    // and delight-im's confirmation row stays in place.
    $systemMailer = new \Spora\Services\SystemMailer(['mail_driver' => 'log']);
    $authService->setSystemMailer($systemMailer);

    $request = jsonRequest('POST', '/api/v1/auth/email/change-request', [
        'email' => 'wf-chg-ok-target@example.com',
    ]);

    $response = $workflow->handleEmailChangeRequest($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    $body = json_decode($response->getContent(), true);
    expect($body['message'])->toBe('A confirmation email has been sent to your new email address.');
});

test('cleanup is scoped to the failing user when two users target the same email', function (): void {
    [$workflow, $authService] = makeWorkflow();

    // Two verified users exist; both will target the same new email.
    $aliceId = $authService->register('alice-shared@example.com', 'Password1!', 'Alice', true);
    $bobId   = $authService->register('bob-shared@example.com', 'Password1!', 'Bob', true);

    // Bob already has a pending confirmation row for the shared target email
    // (e.g. he requested the change earlier and is still inside his 24h
    // throttle window). His row must survive Alice's failed request.
    $sharedEmail = 'shared-target@example.com';
    DB::table('users_confirmations')->insert([
        'principal_id' => createUserPrincipalPublic($bobId),
        'email'    => $sharedEmail,
        'selector' => 'bob-sel-shared',
        'token'    => 'bob-token-shared',
        'expires'  => time() + 86400,
    ]);

    // Alice attempts the change request with a throwing mailer.
    simulateLoggedInSession($aliceId, 'alice-shared@example.com');
    $authService->setSystemMailer(new TransportThrowingMailerStub());

    $request = jsonRequest('POST', '/api/v1/auth/email/change-request', [
        'email' => $sharedEmail,
    ]);
    $response = $workflow->handleEmailChangeRequest($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_BAD_GATEWAY);

    // Alice's row (the orphan) is dropped; Bob's pending confirmation
    // survives — the cleanup must be scoped by user_id, not by email.
    $aliceRows = DB::table('users_confirmations')
        ->where('email', $sharedEmail)
        ->where('user_id', $aliceId)
        ->count();
    $bobRows = DB::table('users_confirmations')
        ->where('email', $sharedEmail)
        ->where('user_id', $bobId)
        ->count();

    expect($aliceRows)->toBe(0);
    expect($bobRows)->toBe(1);
});
