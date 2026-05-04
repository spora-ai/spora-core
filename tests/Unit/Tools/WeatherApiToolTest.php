<?php

declare(strict_types=1);

use Psr\Log\LoggerInterface;
use Spora\Services\ToolConfigService;
use Spora\Tools\WeatherApiTool;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

describe('WeatherApiTool', function (): void {

    it('returns error if api key is missing', function (): void {
        $config = Mockery::mock(ToolConfigService::class);
        $config->allows('getEffectiveSettings')->with(WeatherApiTool::class, 1, null)->andReturn([]);

        $client = Mockery::mock(HttpClientInterface::class);
        $tool = new WeatherApiTool($config, $client);

        $result = $tool->execute(['action' => 'current', 'location' => 'Paris'], 1);
        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('WeatherAPI.com key is not configured');
    });

    it('returns error if location is missing for current weather', function (): void {
        $config = Mockery::mock(ToolConfigService::class);
        $config->allows('getEffectiveSettings')->with(WeatherApiTool::class, 1, null)->andReturn(['core.weatherapi.api_key' => 'key123']);

        $client = Mockery::mock(HttpClientInterface::class);
        $tool = new WeatherApiTool($config, $client);

        $result = $tool->execute(['action' => 'current', 'location' => ''], 1);
        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('Location is required');
    });

    it('returns error if query is missing for search', function (): void {
        $config = Mockery::mock(ToolConfigService::class);
        $config->allows('getEffectiveSettings')->with(WeatherApiTool::class, 1, null)->andReturn(['core.weatherapi.api_key' => 'key123']);

        $client = Mockery::mock(HttpClientInterface::class);
        $tool = new WeatherApiTool($config, $client);

        $result = $tool->execute(['action' => 'search', 'query' => ''], 1);
        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('Search query is required');
    });

    it('returns error if query is too short for search', function (): void {
        $config = Mockery::mock(ToolConfigService::class);
        $config->allows('getEffectiveSettings')->with(WeatherApiTool::class, 1, null)->andReturn(['core.weatherapi.api_key' => 'key123']);

        $client = Mockery::mock(HttpClientInterface::class);
        $tool = new WeatherApiTool($config, $client);

        $result = $tool->execute(['action' => 'search', 'query' => 'a'], 1);
        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('at least 2 characters');
    });

    it('returns error if location is missing for forecast', function (): void {
        $config = Mockery::mock(ToolConfigService::class);
        $config->allows('getEffectiveSettings')->with(WeatherApiTool::class, 1, null)->andReturn(['core.weatherapi.api_key' => 'key123']);

        $client = Mockery::mock(HttpClientInterface::class);
        $tool = new WeatherApiTool($config, $client);

        $result = $tool->execute(['action' => 'forecast', 'location' => ''], 1);
        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('Location is required');
    });

    it('returns error if location is missing for astronomy', function (): void {
        $config = Mockery::mock(ToolConfigService::class);
        $config->allows('getEffectiveSettings')->with(WeatherApiTool::class, 1, null)->andReturn(['core.weatherapi.api_key' => 'key123']);

        $client = Mockery::mock(HttpClientInterface::class);
        $tool = new WeatherApiTool($config, $client);

        $result = $tool->execute(['action' => 'astronomy', 'location' => ''], 1);
        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('Location is required');
    });

    it('current operation makes correct HTTP request and parses response', function (): void {
        $config = Mockery::mock(ToolConfigService::class);
        $config->allows('getEffectiveSettings')->with(WeatherApiTool::class, 1, null)->andReturn([
            'core.weatherapi.api_key' => 'wapi_test_key',
        ]);

        $client = Mockery::mock(HttpClientInterface::class);
        $response = Mockery::mock(ResponseInterface::class);
        $response->allows('getStatusCode')->andReturn(200);
        $response->allows('toArray')->andReturn([
            'location' => ['name' => 'Paris', 'country' => 'France', 'localtime' => '2026-04-23 14:00'],
            'current' => [
                'temp_c' => 18.5,
                'feelslike_c' => 17.0,
                'wind_kph' => 15.0,
                'humidity' => 65,
                'cloud' => 40,
                'uv' => 5,
                'precip_mm' => 0.0,
                'pressure_mb' => 1015,
                'is_day' => 1,
                'condition' => ['text' => 'Partly cloudy', 'code' => 1003],
            ],
        ]);

        $client->expects('request')->with('GET', 'https://api.weatherapi.com/v1/current.json', Mockery::on(function ($options) {
            return $options['query']['key'] === 'wapi_test_key'
                && $options['query']['q'] === 'Paris';
        }))->andReturn($response);

        $tool = new WeatherApiTool($config, $client);
        $result = $tool->execute(['action' => 'current', 'location' => 'Paris'], 1);

        expect($result->success)->toBeTrue()
            ->and($result->content)->toContain('Paris')
            ->and($result->content)->toContain('France')
            ->and($result->content)->toContain('Partly cloudy')
            ->and($result->content)->toContain('18.5')
            ->and($result->content)->toContain('65%')
            ->and($result->content)->toContain('UV Index: 5');
    });

    it('forecast operation makes correct HTTP request and parses response', function (): void {
        $config = Mockery::mock(ToolConfigService::class);
        $config->allows('getEffectiveSettings')->with(WeatherApiTool::class, 1, null)->andReturn([
            'core.weatherapi.api_key' => 'wapi_test_key',
            'core.weatherapi.default_days' => '3',
        ]);

        $client = Mockery::mock(HttpClientInterface::class);
        $response = Mockery::mock(ResponseInterface::class);
        $response->allows('getStatusCode')->andReturn(200);
        $response->allows('toArray')->andReturn([
            'location' => ['name' => 'Berlin', 'country' => 'Germany'],
            'forecast' => [
                'forecastday' => [
                    [
                        'date' => '2026-04-23',
                        'day' => [
                            'avgtemp_c' => 14.0,
                            'maxtemp_c' => 17.0,
                            'mintemp_c' => 9.0,
                            'maxwind_kph' => 20.0,
                            'daily_chance_of_rain' => 30,
                            'uv' => 4,
                            'condition' => ['text' => 'Light rain'],
                        ],
                    ],
                    [
                        'date' => '2026-04-24',
                        'day' => [
                            'avgtemp_c' => 12.0,
                            'maxtemp_c' => 15.0,
                            'mintemp_c' => 7.0,
                            'maxwind_kph' => 25.0,
                            'daily_chance_of_rain' => 60,
                            'uv' => 2,
                            'condition' => ['text' => 'Sunny'],
                        ],
                    ],
                ],
            ],
        ]);

        $client->expects('request')->with('GET', 'https://api.weatherapi.com/v1/forecast.json', Mockery::on(function ($options) {
            return $options['query']['key'] === 'wapi_test_key'
                && $options['query']['q'] === 'Berlin'
                && $options['query']['days'] === 3;
        }))->andReturn($response);

        $tool = new WeatherApiTool($config, $client);
        $result = $tool->execute(['action' => 'forecast', 'location' => 'Berlin', 'days' => 3], 1);

        expect($result->success)->toBeTrue()
            ->and($result->content)->toContain('Berlin')
            ->and($result->content)->toContain('Germany')
            ->and($result->content)->toContain('2026-04-23')
            ->and($result->content)->toContain('2026-04-24')
            ->and($result->content)->toContain('Light rain')
            ->and($result->content)->toContain('Sunny')
            ->and($result->content)->toContain('30%')
            ->and($result->content)->toContain('60%');
    });

    it('search operation makes correct HTTP request and parses response', function (): void {
        $config = Mockery::mock(ToolConfigService::class);
        $config->allows('getEffectiveSettings')->with(WeatherApiTool::class, 1, null)->andReturn([
            'core.weatherapi.api_key' => 'wapi_test_key',
        ]);

        $client = Mockery::mock(HttpClientInterface::class);
        $response = Mockery::mock(ResponseInterface::class);
        $response->allows('getStatusCode')->andReturn(200);
        $response->allows('toArray')->andReturn([
            [
                'name' => 'London',
                'region' => 'England',
                'country' => 'United Kingdom',
                'lat' => 51.5074,
                'lon' => -0.1278,
                'localtime' => '2026-04-23 14:00',
            ],
            [
                'name' => 'London, Ontario',
                'region' => 'Ontario',
                'country' => 'Canada',
                'lat' => 42.9849,
                'lon' => -81.2453,
                'localtime' => '2026-04-23 09:00',
            ],
        ]);

        $client->expects('request')->with('GET', 'https://api.weatherapi.com/v1/search.json', Mockery::on(function ($options) {
            return $options['query']['key'] === 'wapi_test_key'
                && $options['query']['q'] === 'London';
        }))->andReturn($response);

        $tool = new WeatherApiTool($config, $client);
        $result = $tool->execute(['action' => 'search', 'query' => 'London'], 1);

        expect($result->success)->toBeTrue()
            ->and($result->content)->toContain('London')
            ->and($result->content)->toContain('England')
            ->and($result->content)->toContain('Ontario')
            ->and($result->content)->toContain('United Kingdom')
            ->and($result->content)->toContain('Canada');
    });

    it('astronomy operation makes correct HTTP request and parses response', function (): void {
        $config = Mockery::mock(ToolConfigService::class);
        $config->allows('getEffectiveSettings')->with(WeatherApiTool::class, 1, null)->andReturn([
            'core.weatherapi.api_key' => 'wapi_test_key',
        ]);

        $client = Mockery::mock(HttpClientInterface::class);
        $response = Mockery::mock(ResponseInterface::class);
        $response->allows('getStatusCode')->andReturn(200);
        $response->allows('toArray')->andReturn([
            'location' => ['name' => 'Tokyo', 'country' => 'Japan'],
            'astronomy' => [
                'astro' => [
                    'sunrise' => '05:12 AM',
                    'sunset' => '06:22 PM',
                    'moonrise' => '07:45 PM',
                    'moonset' => '06:30 AM',
                    'moon_phase' => 'Waxing Gibbous',
                    'moon_illumination' => 78,
                ],
            ],
        ]);

        $client->expects('request')->with('GET', 'https://api.weatherapi.com/v1/astronomy.json', Mockery::on(function ($options) {
            return $options['query']['key'] === 'wapi_test_key'
                && $options['query']['q'] === 'Tokyo';
        }))->andReturn($response);

        $tool = new WeatherApiTool($config, $client);
        $result = $tool->execute(['action' => 'astronomy', 'location' => 'Tokyo'], 1);

        expect($result->success)->toBeTrue()
            ->and($result->content)->toContain('Tokyo')
            ->and($result->content)->toContain('Japan')
            ->and($result->content)->toContain('05:12 AM')
            ->and($result->content)->toContain('06:22 PM')
            ->and($result->content)->toContain('Waxing Gibbous')
            ->and($result->content)->toContain('78%');
    });

    it('astronomy operation includes date parameter when provided', function (): void {
        $config = Mockery::mock(ToolConfigService::class);
        $config->allows('getEffectiveSettings')->with(WeatherApiTool::class, 1, null)->andReturn([
            'core.weatherapi.api_key' => 'wapi_test_key',
        ]);

        $client = Mockery::mock(HttpClientInterface::class);
        $response = Mockery::mock(ResponseInterface::class);
        $response->allows('getStatusCode')->andReturn(200);
        $response->allows('toArray')->andReturn([
            'location' => ['name' => 'Tokyo', 'country' => 'Japan'],
            'astronomy' => [
                'astro' => [
                    'sunrise' => '05:12 AM',
                    'sunset' => '06:22 PM',
                    'moonrise' => '07:45 PM',
                    'moonset' => '06:30 AM',
                    'moon_phase' => 'Full Moon',
                    'moon_illumination' => 100,
                ],
            ],
        ]);

        $client->expects('request')->with('GET', 'https://api.weatherapi.com/v1/astronomy.json', Mockery::on(function ($options) {
            return $options['query']['dt'] === '2026-04-15';
        }))->andReturn($response);

        $tool = new WeatherApiTool($config, $client);
        $result = $tool->execute(['action' => 'astronomy', 'location' => 'Tokyo', 'date' => '2026-04-15'], 1);

        expect($result->success)->toBeTrue()
            ->and($result->content)->toContain('2026-04-15');
    });

    it('handles HTTP error codes gracefully', function (): void {
        $config = Mockery::mock(ToolConfigService::class);
        $config->allows('getEffectiveSettings')->with(WeatherApiTool::class, 1, null)->andReturn([
            'core.weatherapi.api_key' => 'wapi_test_key',
        ]);

        $client = Mockery::mock(HttpClientInterface::class);
        $response = Mockery::mock(ResponseInterface::class);
        $response->allows('getStatusCode')->andReturn(500);
        $response->allows('getContent')->andReturn('Internal Server Error');
        $client->expects('request')->andReturn($response);

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->allows('error');
        $logger->allows('debug');

        $tool = new WeatherApiTool($config, $client, $logger);
        $result = $tool->execute(['action' => 'current', 'location' => 'Paris'], 1);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('HTTP 500');
    });

    it('returns error for unknown action', function (): void {
        $config = Mockery::mock(ToolConfigService::class);
        $client = Mockery::mock(HttpClientInterface::class);
        $tool = new WeatherApiTool($config, $client);

        $result = $tool->execute(['action' => 'unknown_action'], 1);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('Unknown action');
    });

    it('forecast clamps days to 1-3 range', function (): void {
        $config = Mockery::mock(ToolConfigService::class);
        $config->allows('getEffectiveSettings')->with(WeatherApiTool::class, 1, null)->andReturn([
            'core.weatherapi.api_key' => 'wapi_test_key',
            'core.weatherapi.default_days' => '3',
        ]);

        $client = Mockery::mock(HttpClientInterface::class);
        $response = Mockery::mock(ResponseInterface::class);
        $response->allows('getStatusCode')->andReturn(200);
        $response->allows('toArray')->andReturn([
            'location' => ['name' => 'Berlin', 'country' => 'Germany'],
            'forecast' => ['forecastday' => []],
        ]);

        $client->expects('request')->with('GET', 'https://api.weatherapi.com/v1/forecast.json', Mockery::on(function ($options) {
            return $options['query']['days'] === 3;
        }))->andReturn($response);

        $tool = new WeatherApiTool($config, $client);
        $result = $tool->execute(['action' => 'forecast', 'location' => 'Berlin', 'days' => 99], 1);

        expect($result->success)->toBeTrue();
    });

    it('uses imperial units when configured', function (): void {
        $config = Mockery::mock(ToolConfigService::class);
        $config->allows('getEffectiveSettings')->with(WeatherApiTool::class, 1, null)->andReturn([
            'core.weatherapi.api_key' => 'wapi_test_key',
            'core.weatherapi.units' => 'imperial',
        ]);

        $client = Mockery::mock(HttpClientInterface::class);
        $response = Mockery::mock(ResponseInterface::class);
        $response->allows('getStatusCode')->andReturn(200);
        $response->allows('toArray')->andReturn([
            'location' => ['name' => 'New York', 'country' => 'USA', 'localtime' => '2026-04-23 10:00'],
            'current' => [
                'temp_f' => 68.0,
                'feelslike_f' => 66.0,
                'wind_mph' => 12.0,
                'humidity' => 55,
                'cloud' => 30,
                'uv' => 6,
                'is_day' => 1,
                'condition' => ['text' => 'Sunny', 'code' => 1000],
            ],
        ]);

        $client->expects('request')->andReturn($response);

        $tool = new WeatherApiTool($config, $client);
        $result = $tool->execute(['action' => 'current', 'location' => 'New York'], 1);

        expect($result->success)->toBeTrue()
            ->and($result->content)->toContain('68')
            ->and($result->content)->toContain('°F')
            ->and($result->content)->toContain('mph');
    });

    it('describeAction returns correct descriptions for each operation', function (): void {
        $config = Mockery::mock(ToolConfigService::class);
        $client = Mockery::mock(HttpClientInterface::class);
        $tool = new WeatherApiTool($config, $client);

        expect($tool->describeAction(['action' => 'current', 'location' => 'Paris']))->toContain('Paris');
        expect($tool->describeAction(['action' => 'forecast', 'location' => 'Berlin']))->toContain('Berlin');
        expect($tool->describeAction(['action' => 'search', 'query' => 'London']))->toContain('London');
        expect($tool->describeAction(['action' => 'astronomy', 'location' => 'Tokyo']))->toContain('Tokyo');
    });

    it('getParametersSchema returns valid schema', function (): void {
        $config = Mockery::mock(ToolConfigService::class);
        $client = Mockery::mock(HttpClientInterface::class);
        $tool = new WeatherApiTool($config, $client);

        $schema = $tool->getParametersSchema();

        expect($schema['type'])->toBe('object');
        expect($schema['properties']['action']['enum'])->toContain('current')
            ->toContain('forecast')
            ->toContain('search')
            ->toContain('astronomy');
        expect($schema['required'])->toContain('action');
    });
});
