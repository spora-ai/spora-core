<?php

declare(strict_types=1);

namespace Spora\Tools;

use Psr\Log\LoggerInterface;
use Spora\Services\ToolConfigService;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\Traits\HasOperations;
use Spora\Tools\ValueObjects\ToolResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

#[Tool(
    name: 'weather_api',
    description: 'Fetch weather data from WeatherAPI.com. Use this to get current conditions, multi-day forecasts, location search, or astronomy (sunrise/sunset, moon phase) for any location worldwide.',
    displayName: 'Weather API',
    category: 'information',
)]
#[ToolOperation(name: 'current', description: 'Get current weather conditions for a location', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'forecast', description: 'Get multi-day weather forecast for a location', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'search', description: 'Search for a location by name (autocomplete)', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'astronomy', description: 'Get sunrise, sunset, and moon phase data for a location', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolSetting(
    key: 'core.weatherapi.api_key',
    label: 'WeatherAPI.com Key',
    type: 'password',
    description: 'API key from weatherapi.com (free plan: 100k calls/month)',
    scope: 'agent',
    required: true,
)]
#[ToolSetting(
    key: 'core.weatherapi.base_url',
    label: 'Base URL',
    type: 'text',
    description: 'API base URL (default: https://api.weatherapi.com/v1)',
    scope: 'agent',
)]
#[ToolSetting(
    key: 'core.weatherapi.default_days',
    label: 'Default Forecast Days',
    type: 'text',
    description: 'Number of forecast days 1-3 on free plan (default: 3)',
    scope: 'agent',
)]
#[ToolSetting(
    key: 'core.weatherapi.units',
    label: 'Units',
    type: 'select',
    description: 'Metric or Imperial units',
    scope: 'agent',
    options: ['metric' => 'Metric (°C, km/h)', 'imperial' => 'Imperial (°F, mph)'],
)]
#[ToolSetting(
    key: 'core.weatherapi.http_timeout',
    label: 'HTTP Timeout',
    type: 'text',
    description: 'Seconds before an HTTP request fails (default: 10)',
    scope: 'agent',
)]
final class WeatherApiTool implements ToolInterface
{
    use HasOperations;

    private const DEFAULT_BASE_URL = 'https://api.weatherapi.com/v1';

    public function __construct(
        private readonly ToolConfigService $configService,
        private readonly HttpClientInterface $httpClient,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function execute(array $arguments, int $agentId, ?int $userId = null): ToolResult
    {
        $action = $this->getOperationName($arguments);

        return match ($action) {
            'current'    => $this->current($arguments, $agentId, $userId),
            'forecast'   => $this->forecast($arguments, $agentId, $userId),
            'search'     => $this->search($arguments, $agentId, $userId),
            'astronomy'  => $this->astronomy($arguments, $agentId, $userId),
            default      => new ToolResult(false, "Unknown action '{$action}'. Use one of: current, forecast, search, astronomy."),
        };
    }

    public function describeAction(array $arguments): string
    {
        $action = $this->getOperationName($arguments);
        $location = trim((string) ($arguments['location'] ?? $arguments['query'] ?? ''));

        return match ($action) {
            'current'    => "Get current weather for '{$location}'",
            'forecast'   => "Get weather forecast for '{$location}'",
            'search'     => "Search for location: '{$location}'",
            'astronomy'  => "Get astronomy data for '{$location}'",
            default      => "Fetch weather data",
        };
    }

    public function getParametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'action' => [
                    'type'        => 'string',
                    'description' => 'The weather action to perform',
                    'enum'        => ['current', 'forecast', 'search', 'astronomy'],
                ],
                'location' => [
                    'type'        => 'string',
                    'description' => 'Location query (city name, lat/lon, zip, etc.)',
                ],
                'query' => [
                    'type'        => 'string',
                    'description' => 'Search query for location autocomplete (min 2 chars)',
                ],
                'days' => [
                    'type'        => 'integer',
                    'description' => 'Number of forecast days (1-3 on free plan)',
                    'minimum'     => 1,
                    'maximum'     => 3,
                ],
                'date' => [
                    'type'        => 'string',
                    'description' => 'Date for astronomy data (yyyy-MM-dd, defaults to today)',
                    'format'      => 'date',
                ],
            ],
            'required' => ['action'],
        ];
    }

    private function current(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $location = trim((string) ($arguments['location'] ?? ''));
        if ($location === '') {
            return new ToolResult(false, 'Location is required for current weather.');
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);
        $apiKey = $settings['core.weatherapi.api_key'] ?? '';
        if (empty($apiKey)) {
            return new ToolResult(false, 'WeatherAPI.com key is not configured. Please add it in agent tool settings.');
        }

        $baseUrl = $this->effectiveBaseUrl($settings);
        $timeout = $this->effectiveTimeout($settings);

        try {
            $this->logger?->debug('WeatherApiTool: HTTP request', [
                'method' => 'GET',
                'url' => "{$baseUrl}/current.json",
                'query' => ['key' => '***', 'q' => $location],
                'timeout' => $timeout,
            ]);

            $response = $this->httpClient->request('GET', "{$baseUrl}/current.json", [
                'query' => [
                    'key' => $apiKey,
                    'q'   => $location,
                ],
                'timeout' => $timeout,
            ]);

            $statusCode = $response->getStatusCode();
            $this->logger?->debug('WeatherApiTool: HTTP response', [
                'status_code' => $statusCode,
                'url' => "{$baseUrl}/current.json",
            ]);

            if ($statusCode >= 400) {
                $errorBody = $response->getContent(false);
                $this->logger?->error('WeatherAPI current error', [
                    'status' => $statusCode,
                    'body'   => $errorBody,
                ]);
                return new ToolResult(false, "WeatherAPI error (HTTP {$statusCode})");
            }

            $data = $response->toArray(false);
            $current = $data['current'] ?? [];
            $condition = $current['condition'] ?? [];
            $locationData = $data['location'] ?? [];

            $units = $this->effectiveUnits($settings);
            $tempKey = $units === 'imperial' ? 'temp_f' : 'temp_c';
            $feelslikeKey = $units === 'imperial' ? 'feelslike_f' : 'feelslike_c';
            $windKey = $units === 'imperial' ? 'wind_mph' : 'wind_kph';

            $output = "Current Weather for {$locationData['name']}, {$locationData['country']}:\n";
            $output .= "Condition: {$condition['text']} ({$condition['code']})\n";
            $output .= "Temperature: {$current[$tempKey]}°" . ($units === 'imperial' ? 'F' : 'C') . "\n";
            $output .= "Feels Like: {$current[$feelslikeKey]}°" . ($units === 'imperial' ? 'F' : 'C') . "\n";
            $output .= "Wind: {$current[$windKey]} " . ($units === 'imperial' ? 'mph' : 'kph') . "\n";
            $output .= "Humidity: {$current['humidity']}%\n";
            $output .= "Cloud Cover: {$current['cloud']}%\n";
            $output .= "UV Index: {$current['uv']}\n";

            if (isset($current['precip_mm']) && $current['precip_mm'] > 0) {
                $output .= "Precipitation: {$current['precip_mm']} mm\n";
            }
            if (isset($current['gust_mph'])) {
                $gustKey = $units === 'imperial' ? 'gust_mph' : 'gust_kph';
                $output .= "Wind Gusts: {$current[$gustKey]} " . ($units === 'imperial' ? 'mph' : 'kph') . "\n";
            }
            if (isset($current['pressure_mb'])) {
                $output .= "Pressure: {$current['pressure_mb']} mb\n";
            }

            $isDay = ($current['is_day'] ?? 0) === 1 ? 'Day' : 'Night';
            $output .= "Time of Day: {$isDay}\n";
            $output .= "Local Time: {$locationData['localtime']}\n";

            return new ToolResult(true, $output);
        } catch (Throwable $e) {
            $this->logger?->error('WeatherAPI current exception', ['exception' => $e]);
            return new ToolResult(false, "Weather tool error: " . $e->getMessage());
        }
    }

    private function forecast(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $location = trim((string) ($arguments['location'] ?? ''));
        if ($location === '') {
            return new ToolResult(false, 'Location is required for forecast.');
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);
        $apiKey = $settings['core.weatherapi.api_key'] ?? '';
        if (empty($apiKey)) {
            return new ToolResult(false, 'WeatherAPI.com key is not configured. Please add it in agent tool settings.');
        }

        $baseUrl = $this->effectiveBaseUrl($settings);
        $timeout = $this->effectiveTimeout($settings);
        $defaultDays = (int) ($settings['core.weatherapi.default_days'] ?? 3);
        $defaultDays = max(1, min(3, $defaultDays));
        $days = (int) ($arguments['days'] ?? $defaultDays);
        $days = max(1, min(3, $days));

        $units = $this->effectiveUnits($settings);

        try {
            $this->logger?->debug('WeatherApiTool: HTTP request', [
                'method' => 'GET',
                'url' => "{$baseUrl}/forecast.json",
                'query' => ['key' => '***', 'q' => $location, 'days' => $days],
                'timeout' => $timeout,
            ]);

            $response = $this->httpClient->request('GET', "{$baseUrl}/forecast.json", [
                'query' => [
                    'key' => $apiKey,
                    'q'   => $location,
                    'days' => $days,
                ],
                'timeout' => $timeout,
            ]);

            $statusCode = $response->getStatusCode();
            $this->logger?->debug('WeatherApiTool: HTTP response', [
                'status_code' => $statusCode,
                'url' => "{$baseUrl}/forecast.json",
            ]);

            if ($statusCode >= 400) {
                $errorBody = $response->getContent(false);
                $this->logger?->error('WeatherAPI forecast error', [
                    'status' => $statusCode,
                    'body'   => $errorBody,
                ]);
                return new ToolResult(false, "WeatherAPI error (HTTP {$statusCode})");
            }

            $data = $response->toArray(false);
            $locationData = $data['location'] ?? [];
            $forecastDays = $data['forecast']['forecastday'] ?? [];

            $tempKey = $units === 'imperial' ? 'avgtemp_f' : 'avgtemp_c';
            $maxtempKey = $units === 'imperial' ? 'maxtemp_f' : 'maxtemp_c';
            $mintempKey = $units === 'imperial' ? 'mintemp_f' : 'mintemp_c';
            $windKey = $units === 'imperial' ? 'maxwind_mph' : 'maxwind_kph';
            $unitLabel = $units === 'imperial' ? 'F' : 'C';
            $windLabel = $units === 'imperial' ? 'mph' : 'kph';

            $output = "{$days}-Day Weather Forecast for {$locationData['name']}, {$locationData['country']}:\n\n";

            foreach ($forecastDays as $day) {
                $date = $day['date'] ?? '';
                $dayData = $day['day'] ?? [];
                $condition = $dayData['condition'] ?? [];

                $output .= "📅 {$date}\n";
                $output .= "   Condition: {$condition['text']}\n";
                $output .= "   Avg Temp: {$dayData[$tempKey]}°{$unitLabel}\n";
                $output .= "   High/Low: {$dayData[$maxtempKey]}°{$unitLabel} / {$dayData[$mintempKey]}°{$unitLabel}\n";
                $output .= "   Max Wind: {$dayData[$windKey]} {$windLabel}\n";
                $output .= "   Chance of Rain: {$dayData['daily_chance_of_rain']}%\n";
                $output .= "   UV Index: {$dayData['uv']}\n\n";
            }

            return new ToolResult(true, $output);
        } catch (Throwable $e) {
            $this->logger?->error('WeatherAPI forecast exception', ['exception' => $e]);
            return new ToolResult(false, "Weather tool error: " . $e->getMessage());
        }
    }

    private function search(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $query = trim((string) ($arguments['query'] ?? ''));
        if ($query === '') {
            return new ToolResult(false, 'Search query is required (minimum 2 characters).');
        }
        if (strlen($query) < 2) {
            return new ToolResult(false, 'Search query must be at least 2 characters.');
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);
        $apiKey = $settings['core.weatherapi.api_key'] ?? '';
        if (empty($apiKey)) {
            return new ToolResult(false, 'WeatherAPI.com key is not configured. Please add it in agent tool settings.');
        }

        $baseUrl = $this->effectiveBaseUrl($settings);
        $timeout = $this->effectiveTimeout($settings);

        try {
            $this->logger?->debug('WeatherApiTool: HTTP request', [
                'method' => 'GET',
                'url' => "{$baseUrl}/search.json",
                'query' => ['key' => '***', 'q' => $query],
                'timeout' => $timeout,
            ]);

            $response = $this->httpClient->request('GET', "{$baseUrl}/search.json", [
                'query' => [
                    'key' => $apiKey,
                    'q'   => $query,
                ],
                'timeout' => $timeout,
            ]);

            $statusCode = $response->getStatusCode();
            $this->logger?->debug('WeatherApiTool: HTTP response', [
                'status_code' => $statusCode,
                'url' => "{$baseUrl}/search.json",
            ]);

            if ($statusCode >= 400) {
                $errorBody = $response->getContent(false);
                $this->logger?->error('WeatherAPI search error', [
                    'status' => $statusCode,
                    'body'   => $errorBody,
                ]);
                return new ToolResult(false, "WeatherAPI error (HTTP {$statusCode})");
            }

            $results = $response->toArray(false);

            if (empty($results)) {
                return new ToolResult(true, "No locations found for '{$query}'.");
            }

            $output = "Location Search Results for '{$query}':\n\n";
            foreach ($results as $i => $location) {
                $num = $i + 1;
                $name = $location['name'] ?? 'Unknown';
                $region = $location['region'] ?? '';
                $country = $location['country'] ?? '';
                $lat = $location['lat'] ?? '';
                $lon = $location['lon'] ?? '';
                $localtime = $location['localtime'] ?? '';

                $output .= "[{$num}] {$name}";
                if ($region) {
                    $output .= ", {$region}";
                }
                $output .= ", {$country}\n";
                $output .= "    Coordinates: {$lat}, {$lon}\n";
                $output .= "    Local Time: {$localtime}\n\n";
            }

            return new ToolResult(true, $output);
        } catch (Throwable $e) {
            $this->logger?->error('WeatherAPI search exception', ['exception' => $e]);
            return new ToolResult(false, "Weather tool error: " . $e->getMessage());
        }
    }

    private function astronomy(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $location = trim((string) ($arguments['location'] ?? ''));
        if ($location === '') {
            return new ToolResult(false, 'Location is required for astronomy data.');
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);
        $apiKey = $settings['core.weatherapi.api_key'] ?? '';
        if (empty($apiKey)) {
            return new ToolResult(false, 'WeatherAPI.com key is not configured. Please add it in agent tool settings.');
        }

        $baseUrl = $this->effectiveBaseUrl($settings);
        $timeout = $this->effectiveTimeout($settings);
        $date = trim((string) ($arguments['date'] ?? ''));

        $queryParams = [
            'key' => $apiKey,
            'q'   => $location,
        ];
        if ($date !== '') {
            $queryParams['dt'] = $date;
        }

        try {
            $this->logger?->debug('WeatherApiTool: HTTP request', [
                'method' => 'GET',
                'url' => "{$baseUrl}/astronomy.json",
                'query' => ['key' => '***', 'q' => $location, 'dt' => $date ?: null],
                'timeout' => $timeout,
            ]);

            $response = $this->httpClient->request('GET', "{$baseUrl}/astronomy.json", [
                'query' => $queryParams,
                'timeout' => $timeout,
            ]);

            $statusCode = $response->getStatusCode();
            $this->logger?->debug('WeatherApiTool: HTTP response', [
                'status_code' => $statusCode,
                'url' => "{$baseUrl}/astronomy.json",
            ]);

            if ($statusCode >= 400) {
                $errorBody = $response->getContent(false);
                $this->logger?->error('WeatherAPI astronomy error', [
                    'status' => $statusCode,
                    'body'   => $errorBody,
                ]);
                return new ToolResult(false, "WeatherAPI error (HTTP {$statusCode})");
            }

            $data = $response->toArray(false);
            $locationData = $data['location'] ?? [];
            $astro = $data['astronomy']['astro'] ?? [];

            $output = "Astronomy Data for {$locationData['name']}, {$locationData['country']}";
            if ($date) {
                $output .= " on {$date}";
            } else {
                $output .= " (today)";
            }
            $output .= "\n\n";
            $output .= "🌅 Sunrise: {$astro['sunrise']}\n";
            $output .= "🌇 Sunset: {$astro['sunset']}\n";
            $output .= "🌙 Moonrise: {$astro['moonrise']}\n";
            $output .= "🌒 Moonset: {$astro['moonset']}\n";
            $output .= "🌝 Moon Phase: {$astro['moon_phase']}\n";
            $output .= "💡 Moon Illumination: {$astro['moon_illumination']}%\n";

            return new ToolResult(true, $output);
        } catch (Throwable $e) {
            $this->logger?->error('WeatherAPI astronomy exception', ['exception' => $e]);
            return new ToolResult(false, "Weather tool error: " . $e->getMessage());
        }
    }

    private function effectiveBaseUrl(array $settings): string
    {
        return trim((string) ($settings['core.weatherapi.base_url'] ?? self::DEFAULT_BASE_URL));
    }

    private function effectiveTimeout(array $settings): int
    {
        if (isset($settings['core.weatherapi.http_timeout']) && (int) $settings['core.weatherapi.http_timeout'] > 0) {
            return (int) $settings['core.weatherapi.http_timeout'];
        }
        $envTimeout = (int) ($_ENV['SPORA_TOOL_HTTP_TIMEOUT'] ?? getenv('SPORA_TOOL_HTTP_TIMEOUT') ?: 0);
        return $envTimeout > 0 ? $envTimeout : 10;
    }

    private function effectiveUnits(array $settings): string
    {
        $units = strtolower(trim((string) ($settings['core.weatherapi.units'] ?? 'metric')));
        return $units === 'imperial' ? 'imperial' : 'metric';
    }
}
