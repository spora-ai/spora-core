<?php

declare(strict_types=1);

namespace Spora\Tools;

use Psr\Log\LoggerInterface;
use Spora\Services\ToolConfigService;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\ValueObjects\ToolResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Fetches weather data from WeatherAPI.com including current conditions,
 * multi-day forecasts, location search, and astronomy data (sunrise/sunset, moon phase).
 */
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
    required: true,
)]
#[ToolSetting(
    key: 'core.weatherapi.base_url',
    label: 'Base URL',
    type: 'text',
    description: 'API base URL (default: https://api.weatherapi.com/v1)',
)]
#[ToolSetting(
    key: 'core.weatherapi.default_days',
    label: 'Default Forecast Days',
    type: 'text',
    description: 'Number of forecast days 1-3 on free plan (default: 3)',
)]
#[ToolSetting(
    key: 'core.weatherapi.units',
    label: 'Units',
    type: 'select',
    description: 'Metric or Imperial units',
    options: ['metric' => 'Metric (°C, km/h)', 'imperial' => 'Imperial (°F, mph)'],
)]
#[ToolSetting(
    key: 'core.weatherapi.http_timeout',
    label: 'HTTP Timeout',
    type: 'text',
    description: 'Seconds before an HTTP request fails (default: 10)',
)]
#[ToolParameter(name: 'location', type: 'string', description: 'Location query (city name, lat/lon, zip, etc.)', required: false)]
#[ToolParameter(name: 'query', type: 'string', description: 'Search query for location autocomplete (min 2 chars)', required: false)]
#[ToolParameter(name: 'days', type: 'integer', description: 'Number of forecast days (1-3 on free plan)', required: false, minimum: 1, maximum: 3)]
#[ToolParameter(name: 'date', type: 'string', description: 'Date for astronomy data (yyyy-MM-dd, defaults to today)', required: false, format: 'date')]
final class WeatherApiTool extends AbstractTool
{
    private const DEFAULT_BASE_URL = 'https://api.weatherapi.com/v1';

    private const LOG_HTTP_REQUEST = 'WeatherApiTool: HTTP request';
    private const LOG_HTTP_RESPONSE = 'WeatherApiTool: HTTP response';
    private const ERR_MISSING_API_KEY = 'WeatherAPI.com key is not configured. Please add it in agent tool settings.';
    private const LOG_TOOL_ERROR_PREFIX = 'Weather tool error: ';

    public function __construct(
        private readonly ToolConfigService $configService,
        private readonly HttpClientInterface $httpClient,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
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

    private function current(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $location = trim((string) ($arguments['location'] ?? ''));
        if ($location === '') {
            return new ToolResult(false, 'Location is required for current weather.');
        }

        $data = $this->fetchWeatherData('current.json', $location, [], $agentId, $userId, 'current');
        if ($data instanceof ToolResult) {
            return $data;
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);
        $units = $this->effectiveUnits($settings);

        return new ToolResult(true, $this->formatCurrentWeatherOutput($data, $units));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function formatCurrentWeatherOutput(array $data, string $units): string
    {
        $current = $data['current'] ?? [];
        $condition = $current['condition'] ?? [];
        $locationData = $data['location'] ?? [];

        $tempKey = $units === 'imperial' ? 'temp_f' : 'temp_c';
        $feelslikeKey = $units === 'imperial' ? 'feelslike_f' : 'feelslike_c';
        $windKey = $units === 'imperial' ? 'wind_mph' : 'wind_kph';
        $unitLabel = $units === 'imperial' ? 'F' : 'C';
        $windLabel = $units === 'imperial' ? 'mph' : 'kph';

        $output = "Current Weather for {$locationData['name']}, {$locationData['country']}:\n";
        $output .= "Condition: {$condition['text']} ({$condition['code']})\n";
        $output .= "Temperature: {$current[$tempKey]}°{$unitLabel}\n";
        $output .= "Feels Like: {$current[$feelslikeKey]}°{$unitLabel}\n";
        $output .= "Wind: {$current[$windKey]} {$windLabel}\n";
        $output .= "Humidity: {$current['humidity']}%\n";
        $output .= "Cloud Cover: {$current['cloud']}%\n";
        $output .= "UV Index: {$current['uv']}\n";

        if (isset($current['precip_mm']) && $current['precip_mm'] > 0) {
            $output .= "Precipitation: {$current['precip_mm']} mm\n";
        }
        if (isset($current['gust_mph'])) {
            $gustKey = $units === 'imperial' ? 'gust_mph' : 'gust_kph';
            $output .= "Wind Gusts: {$current[$gustKey]} {$windLabel}\n";
        }
        if (isset($current['pressure_mb'])) {
            $output .= "Pressure: {$current['pressure_mb']} mb\n";
        }

        $isDay = ($current['is_day'] ?? 0) === 1 ? 'Day' : 'Night';
        $output .= "Time of Day: {$isDay}\n";
        $output .= "Local Time: {$locationData['localtime']}\n";

        return $output;
    }

    private function forecast(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $location = trim((string) ($arguments['location'] ?? ''));
        if ($location === '') {
            return new ToolResult(false, 'Location is required for forecast.');
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);
        $defaultDays = (int) ($settings['core.weatherapi.default_days'] ?? 3);
        $defaultDays = max(1, min(3, $defaultDays));
        $days = (int) ($arguments['days'] ?? $defaultDays);
        $days = max(1, min(3, $days));

        $data = $this->fetchWeatherData('forecast.json', $location, ['days' => $days], $agentId, $userId, 'forecast');
        if ($data instanceof ToolResult) {
            return $data;
        }

        $locationData = $data['location'] ?? [];
        $forecastDays = $data['forecast']['forecastday'] ?? [];

        $units = $this->effectiveUnits($settings);
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
    }

    private function search(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $query = trim((string) ($arguments['query'] ?? ''));
        $validation = $this->validateSearchQuery($query);
        if ($validation instanceof ToolResult) {
            return $validation;
        }

        $data = $this->fetchWeatherData('search.json', $query, [], $agentId, $userId, 'search');
        if ($data instanceof ToolResult) {
            return $data;
        }

        return new ToolResult(true, $this->formatSearchResults($query, $data));
    }

    private function validateSearchQuery(string $query): ?ToolResult
    {
        return match (true) {
            $query === ''     => new ToolResult(false, 'Search query is required (minimum 2 characters).'),
            strlen($query) < 2 => new ToolResult(false, 'Search query must be at least 2 characters.'),
            default            => null,
        };
    }

    /**
     * @param array<string, mixed> $results
     */
    private function formatSearchResults(string $query, array $results): string
    {
        if ($results === []) {
            return "No locations found for '{$query}'.";
        }

        $output = "Location Search Results for '{$query}':\n\n";
        foreach ($results as $i => $location) {
            $num = (int) $i + 1;
            $name = $location['name'] ?? 'Unknown';
            $region = $location['region'] ?? '';
            $country = $location['country'] ?? '';
            $lat = $location['lat'] ?? '';
            $lon = $location['lon'] ?? '';
            $localtime = $location['localtime'] ?? '';

            $output .= "[{$num}] {$name}";
            if ($region !== '') {
                $output .= ", {$region}";
            }
            $output .= ", {$country}\n";
            $output .= "    Coordinates: {$lat}, {$lon}\n";
            $output .= "    Local Time: {$localtime}\n\n";
        }

        return $output;
    }

    private function astronomy(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $location = trim((string) ($arguments['location'] ?? ''));
        if ($location === '') {
            return new ToolResult(false, 'Location is required for astronomy data.');
        }

        $date = trim((string) ($arguments['date'] ?? ''));
        $extraQuery = $date !== '' ? ['dt' => $date] : [];

        $data = $this->fetchWeatherData('astronomy.json', $location, $extraQuery, $agentId, $userId, 'astronomy');
        if ($data instanceof ToolResult) {
            return $data;
        }

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
    }

    /**
     * Resolve settings, perform the HTTP request, and parse the JSON response.
     *
     * @param array<string, scalar|null> $extraQuery Additional query parameters to merge (excluding the API key)
     * @return ToolResult|array<string, mixed> ToolResult on error, parsed response data on success
     */
    private function fetchWeatherData(
        string $endpoint,
        string $location,
        array $extraQuery,
        int $agentId,
        ?int $userId,
        string $logContext,
    ): ToolResult|array {
        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);
        $apiKey = $settings['core.weatherapi.api_key'] ?? '';
        if (empty($apiKey)) {
            return new ToolResult(false, self::ERR_MISSING_API_KEY);
        }

        return $this->executeWeatherRequest(
            $endpoint,
            $location,
            $extraQuery,
            $apiKey,
            $this->effectiveBaseUrl($settings),
            $this->effectiveTimeout($settings),
            $logContext,
        );
    }

    /**
     * @param array<string, scalar|null> $extraQuery
     * @return ToolResult|array<string, mixed>
     */
    private function executeWeatherRequest(
        string $endpoint,
        string $location,
        array $extraQuery,
        string $apiKey,
        string $baseUrl,
        int $timeout,
        string $logContext,
    ): ToolResult|array {
        $query = array_merge(['key' => $apiKey, 'q' => $location], $extraQuery);
        $logQuery = array_merge(['key' => '***', 'q' => $location], $extraQuery);
        $url = "{$baseUrl}/{$endpoint}";

        try {
            return $this->dispatchWeatherResponse(
                $this->httpClient->request('GET', $url, [
                    'query' => $query,
                    'timeout' => $timeout,
                ]),
                $url,
                $logQuery,
                $timeout,
                $logContext,
            );
        } catch (Throwable $e) {
            $this->logger?->error("WeatherAPI {$logContext} exception", ['exception' => $e]);
            return new ToolResult(false, self::LOG_TOOL_ERROR_PREFIX . $e->getMessage());
        }
    }

    /**
     * @param array<string, scalar|null> $logQuery
     * @return ToolResult|array<string, mixed>
     */
    private function dispatchWeatherResponse(
        \Symfony\Contracts\HttpClient\ResponseInterface $response,
        string $url,
        array $logQuery,
        int $timeout,
        string $logContext,
    ): ToolResult|array {
        $this->logger?->debug(self::LOG_HTTP_REQUEST, [
            'method' => 'GET',
            'url' => $url,
            'query' => $logQuery,
            'timeout' => $timeout,
        ]);

        $statusCode = $response->getStatusCode();
        $this->logger?->debug(self::LOG_HTTP_RESPONSE, [
            'status_code' => $statusCode,
            'url' => $url,
        ]);

        if ($statusCode >= 400) {
            $this->logger?->error("WeatherAPI {$logContext} error", [
                'status' => $statusCode,
                'body'   => $response->getContent(false),
            ]);
            return new ToolResult(false, "WeatherAPI error (HTTP {$statusCode})");
        }

        return $response->toArray(false);
    }

    private function effectiveBaseUrl(array $settings): string
    {
        $baseUrl = $settings['core.weatherapi.base_url'] ?? null;
        if ($baseUrl === null || $baseUrl === '') {
            return self::DEFAULT_BASE_URL;
        }
        return trim((string) $baseUrl);
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
