<?php

declare(strict_types=1);

namespace Tests\Unit\Agents;

use Spora\Agents\ErrorClassifier;
use Spora\Agents\RetryScheduler;
use Spora\Metering\Exceptions\UsageLimitExceededException;
use Spora\Metering\UsageDecision;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;

it('maps a hard usage decision to USAGE_LIMIT_REACHED', function (): void {
    $classifier = new ErrorClassifier();

    $decision = new UsageLimitExceededException(new UsageDecision('Quota exhausted.'));

    expect($classifier->classifyError($decision))->toBe('USAGE_LIMIT_REACHED');
});

it('never retries a run that hit a usage limit', function (): void {
    expect(ErrorClassifier::RETRYABLE_ERROR_CODES)->not->toContain('USAGE_LIMIT_REACHED');
});

it('gives the operator a message for a usage limit', function (): void {
    $classifier = new ErrorClassifier();

    $decision = new UsageLimitExceededException(new UsageDecision('Quota exhausted.'));

    expect($classifier->friendlyMessageForError($decision, 'USAGE_LIMIT_REACHED'))
        ->not->toBe($classifier->friendlyMessages()['UNKNOWN']);
});

it('maps a provider that cannot be reached to a retryable code', function (): void {
    $classifier = new ErrorClassifier();

    // The request never got an answer — DNS failure, connection reset, a
    // refused TLS handshake. As transient as a 5xx.
    $transport = new TransportException('Could not resolve host: api.minimax.io');

    expect($classifier->classifyError($transport))->toBe('GATEWAY_ERROR');
});

it('the auto-retry policy accepts a provider that cannot be reached', function (): void {
    $classifier = new ErrorClassifier();

    expect(RetryScheduler::RETRYABLE_ERROR_CODES)
        ->toContain($classifier->classifyError(new TransportException('connection reset')));
});

it('maps a request that timed out to LLM_TIMEOUT', function (): void {
    $classifier = new ErrorClassifier();

    // A timeout is a transport failure too, but it has its own code — the
    // timeout branch has to come first, or every timeout would be
    // reported as a generic gateway failure.
    $timeout = new TimeoutException('Request timed out after 300 seconds.');

    expect($classifier->classifyError($timeout))->toBe('LLM_TIMEOUT');
});
