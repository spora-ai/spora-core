<?php

declare(strict_types=1);

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Spora\Mailer\LogTransport;
use Symfony\Component\Mime\Email;

test('__toString() returns log://', function (): void {
    $transport = new LogTransport();

    expect((string) $transport)->toBe('log://');
});

test('send() logs to, from, subject, text, and html via injected PSR-3 logger', function (): void {
    $handler = new TestHandler();
    $logger = new Logger('mail-test', [$handler]);
    $transport = new LogTransport(null, $logger);

    $email = (new Email())
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Test Subject')
        ->text('hello world')
        ->html('<p>hello world</p>');

    $transport->send($email);

    expect($handler->hasInfoRecords())->toBeTrue();

    $records = $handler->getRecords();
    $record = $records[0];
    expect($record['message'])->toBe('[Spora] Mail sent via log driver');
    expect($record['context']['to'])->toBe('recipient@example.com');
    expect($record['context']['from'])->toBe('sender@example.com');
    expect($record['context']['subject'])->toBe('Test Subject');
    expect($record['context']['text'])->toBe('hello world');
    expect($record['context']['html'])->toBe('<p>hello world</p>');
});

test('send() logs multiple recipients joined by comma', function (): void {
    $handler = new TestHandler();
    $logger = new Logger('mail-test', [$handler]);
    $transport = new LogTransport(null, $logger);

    $email = (new Email())
        ->from('sender@example.com')
        ->to('one@example.com', 'two@example.com')
        ->subject('Multi')
        ->text('hi');

    $transport->send($email);

    $records = $handler->getRecords();
    expect($records[0]['context']['to'])->toBe('one@example.com, two@example.com');
});

test('send() omits text key when the email has no text body', function (): void {
    $handler = new TestHandler();
    $logger = new Logger('mail-test', [$handler]);
    $transport = new LogTransport(null, $logger);

    $email = (new Email())
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('HTML only')
        ->html('<p>only html</p>');

    $transport->send($email);

    $context = $handler->getRecords()[0]['context'];
    expect($context)->not->toHaveKey('text');
    expect($context['html'])->toBe('<p>only html</p>');
});

test('send() omits html key when the email has no html body', function (): void {
    $handler = new TestHandler();
    $logger = new Logger('mail-test', [$handler]);
    $transport = new LogTransport(null, $logger);

    $email = (new Email())
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Text only')
        ->text('only text');

    $transport->send($email);

    $context = $handler->getRecords()[0]['context'];
    expect($context)->not->toHaveKey('html');
    expect($context['text'])->toBe('only text');
});
