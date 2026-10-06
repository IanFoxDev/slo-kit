<?php

declare(strict_types=1);

namespace IanFoxDev\SloKit;

/**
 * One objective: a share of requests on some routes that must succeed, or must finish
 * within a threshold.
 */
final readonly class Slo
{
    /**
     * @param float $objective a percentage, such as 99.9
     * @param ?float $threshold seconds, for a latency SLO; null for availability
     * @param list<string> $routes route templates, * matches any characters
     * @param list<int|string> $errorStatuses status codes or classes ("5xx") that count as errors
     */
    public function __construct(
        public string $name,
        public float $objective,
        public ?float $threshold,
        public array $routes,
        public array $errorStatuses,
    ) {}

    public function isLatency(): bool
    {
        return $this->threshold !== null;
    }

    /**
     * The share of requests that may fail: 0.001 for 99.9%.
     */
    public function errorBudget(): float
    {
        return round((100 - $this->objective) / 100, 10);
    }

    public function covers(string $route): bool
    {
        foreach ($this->routes as $pattern) {
            if (fnmatch($pattern, $route, \FNM_NOESCAPE)) {
                return true;
            }
        }

        return false;
    }

    public function isError(int $status): bool
    {
        foreach ($this->errorStatuses as $error) {
            if ($error === $status || (\is_string($error) && $error[0] === (string) intdiv($status, 100) && strtolower(substr($error, 1)) === 'xx')) {
                return true;
            }
        }

        return false;
    }
}
