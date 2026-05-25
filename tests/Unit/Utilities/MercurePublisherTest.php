<?php

declare(strict_types=1);

namespace Tests\Unit\Utilities;

use Mockery;
use Spora\Services\MercurePublisher;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

describe('MercurePublisher', function (): void {
    it('publish() sends topic as user/{userId}/tasks', function (): void {
        $client = Mockery::mock(HttpClientInterface::class);
        $hubUrl = 'http://mercure/.well-known/mercure';
        $jwtKey = 'secret1234secret1234secret1234secret1234';

        $publisher = new MercurePublisher($client, $hubUrl, $jwtKey);

        $client->shouldReceive('request')
            ->once()
            ->with('POST', $hubUrl, Mockery::on(function (array $options) {
                // Topic is flat per-user: user/{userId}/tasks
                expect($options['body']['topic'])->toBe('user/42/tasks');

                $dataJson = $options['body']['data'];
                $parsed = json_decode($dataJson, true);

                expect($parsed['topic'])->toBe('user/42/tasks')
                    ->and($parsed['data'])->toBeArray()
                    ->and($parsed['data']['status'])->toBe('RUNNING');

                return true;
            }))
            ->andReturn(Mockery::mock(ResponseInterface::class));

        $result = $publisher->publish(99, 42, ['status' => 'RUNNING']);
        expect($result)->toBeTrue();
    });

    it('publish() JWT claim contains user/{userId}/tasks and user/{userId}/notifications', function (): void {
        $client = Mockery::mock(HttpClientInterface::class);
        $hubUrl = 'http://mercure/.well-known/mercure';
        $jwtKey = 'secret1234secret1234secret1234secret1234';

        $publisher = new MercurePublisher($client, $hubUrl, $jwtKey);

        $client->shouldReceive('request')
            ->once()
            ->with('POST', $hubUrl, Mockery::on(function (array $options) {
                $jwt = $options['auth_bearer'];
                $parts = explode('.', $jwt);
                $payloadJson = base64_decode(strtr($parts[1], '-_', '+/'));
                $payload = json_decode($payloadJson, true);

                // The JWT claim uses the actual userId from publish(99, 42)
                expect($payload['mercure']['publish'])->toContain('user/42/tasks');
                expect($payload['mercure']['publish'])->toContain('user/42/notifications');

                return true;
            }))
            ->andReturn(Mockery::mock(ResponseInterface::class));

        $publisher->publish(99, 42, ['status' => 'RUNNING']);
    });

    it('sends correct JSON shape to the hub', function (): void {
        $client = Mockery::mock(HttpClientInterface::class);
        $hubUrl = 'http://mercure/.well-known/mercure';
        $jwtKey = 'secret1234secret1234secret1234secret1234';

        $publisher = new MercurePublisher($client, $hubUrl, $jwtKey);

        $client->shouldReceive('request')
            ->once()
            ->with('POST', $hubUrl, Mockery::on(function (array $options) {
                $body = $options['body'];
                expect($body['topic'])->toBe('user/123/notifications');

                // the key 'data' in the body array is a JSON string.
                // It should decode to an array with 'topic' and 'data', where 'data' is the inner array
                $dataJson = $body['data'];
                $parsed = json_decode($dataJson, true);

                expect($parsed['topic'])->toBe('user/123/notifications')
                    ->and($parsed['data'])->toBeArray()
                    ->and($parsed['data']['event'])->toBe('notification')
                    ->and($parsed['data']['notification'])->toBe('test_value');

                return true;
            }))
            ->andReturn(Mockery::mock(ResponseInterface::class));

        $data = ['event' => 'notification', 'notification' => 'test_value'];
        $result = $publisher->publishToUser(123, $data);
        expect($result)->toBeTrue();
    });
});
