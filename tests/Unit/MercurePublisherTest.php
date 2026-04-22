<?php

declare(strict_types=1);

namespace Tests\Unit;

use Mockery;
use Spora\Services\MercurePublisher;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

describe('MercurePublisher', function (): void {
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
                    ->and($parsed['data']['payload'])->toBe('test_value');

                return true;
            }))
            ->andReturn(Mockery::mock(ResponseInterface::class));

        $data = ['event' => 'notification', 'payload' => 'test_value'];
        $result = $publisher->publishToUser(123, $data);
        expect($result)->toBeTrue();
    });
});
