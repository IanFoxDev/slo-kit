<?php

declare(strict_types=1);

namespace IanFoxDev\SloKit;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The SLO file: the service, the metric names, and the objectives. The application measures
 * to it, `slo-kit check` checks /metrics against it, `slo-kit sloth` turns it into a Sloth
 * spec.
 *
 *     service: checkout
 *     metrics: { prefix: app }
 *     slos:
 *       - name: checkout-availability
 *         objective: 99.9
 *         routes: ['checkout_*']
 *       - name: checkout-latency
 *         objective: 95
 *         threshold_ms: 300
 *         routes: ['checkout_*']
 */
final readonly class SloFile
{
    /** Below and above the thresholds, so the histogram is still useful as a distribution. */
    private const array GRID = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0];

    /** @var list<int|string> */
    public const array DEFAULT_ERRORS = ['5xx', 429];

    /**
     * @param list<Slo> $slos
     */
    public function __construct(
        public string $service,
        public string $prefix,
        public array $slos,
    ) {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]*$/', $service) !== 1) {
            throw new InvalidSloFile(\sprintf('service "%s" must be letters, digits, _ and -, starting with a letter.', $service));
        }
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $prefix) !== 1) {
            throw new InvalidSloFile(\sprintf('metrics.prefix "%s" must be a valid Prometheus name: letters, digits and _.', $prefix));
        }
        if ($slos === []) {
            throw new InvalidSloFile('slos: at least one SLO is needed.');
        }
        $names = [];
        foreach ($slos as $slo) {
            if (isset($names[$slo->name])) {
                throw new InvalidSloFile(\sprintf('slos: "%s" appears twice.', $slo->name));
            }
            $names[$slo->name] = true;
        }
    }

    public static function fromYaml(string $path): self
    {
        $yaml = @file_get_contents($path);
        if ($yaml === false) {
            throw new InvalidSloFile(\sprintf('Cannot read %s.', $path));
        }
        try {
            $data = Yaml::parse($yaml);
        } catch (ParseException $e) {
            throw new InvalidSloFile(\sprintf('%s is not valid YAML: %s', $path, $e->getMessage()), 0, $e);
        }

        return self::fromArray(\is_array($data) ? $data : []);
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $service = $data['service'] ?? null;
        $metrics = $data['metrics'] ?? [];
        $prefix = \is_array($metrics) ? ($metrics['prefix'] ?? 'app') : null;
        $slos = $data['slos'] ?? null;
        if (!\is_string($service)) {
            throw new InvalidSloFile('service: a name is needed, such as "checkout".');
        }
        if (!\is_string($prefix)) {
            throw new InvalidSloFile('metrics.prefix must be a string.');
        }
        if (!\is_array($slos) || !array_is_list($slos)) {
            throw new InvalidSloFile('slos: a list of SLOs is needed.');
        }

        return new self($service, $prefix, array_map(self::slo(...), $slos, array_keys($slos)));
    }

    /**
     * The name of the request counter and of the duration histogram.
     *
     * @return array{requests: string, duration: string}
     */
    public function metricNames(): array
    {
        return ['requests' => $this->prefix . '_http_requests_total', 'duration' => $this->prefix . '_http_request_duration_seconds'];
    }

    /**
     * Histogram buckets: a fixed grid plus every latency threshold in the file, so the share
     * of requests under a threshold is read from a bucket and not interpolated.
     *
     * @return list<float>
     */
    public function buckets(): array
    {
        $buckets = self::GRID;
        foreach ($this->slos as $slo) {
            if ($slo->threshold !== null) {
                $buckets[] = $slo->threshold;
            }
        }
        $buckets = array_values(array_unique(array_map(static fn(float $b): float => round($b, 6), $buckets), \SORT_REGULAR));
        sort($buckets);

        return $buckets;
    }

    /**
     * The SLOs that cover a route.
     *
     * @return list<Slo>
     */
    public function covering(string $route): array
    {
        return array_values(array_filter($this->slos, static fn(Slo $s): bool => $s->covers($route)));
    }

    private static function slo(mixed $data, int $index): Slo
    {
        if (!\is_array($data)) {
            throw new InvalidSloFile(\sprintf('slos[%d] must be a map.', $index));
        }
        $name = $data['name'] ?? null;
        if (!\is_string($name) || preg_match('/^[a-z0-9][a-z0-9-]*$/', $name) !== 1) {
            throw new InvalidSloFile(\sprintf('slos[%d].name must be lower-case letters, digits and -, such as "checkout-latency".', $index));
        }
        $objective = $data['objective'] ?? null;
        if (!\is_int($objective) && !\is_float($objective) || $objective <= 0 || $objective >= 100) {
            throw new InvalidSloFile(\sprintf('%s: objective is a percentage between 0 and 100, such as 99.9.', $name));
        }
        $threshold = null;
        if (\array_key_exists('threshold_ms', $data)) {
            $ms = $data['threshold_ms'];
            if (!\is_int($ms) && !\is_float($ms) || $ms <= 0) {
                throw new InvalidSloFile(\sprintf('%s: threshold_ms is a positive number of milliseconds.', $name));
            }
            $threshold = $ms / 1000;
        }
        $routes = [];
        foreach (\is_array($data['routes'] ?? null) ? $data['routes'] : [] as $route) {
            if (!\is_string($route) || $route === '') {
                $routes = [];
                break;
            }
            $routes[] = $route;
        }
        if ($routes === []) {
            throw new InvalidSloFile(\sprintf('%s: routes is a list of route templates, such as ["checkout_*"].', $name));
        }
        $errors = $data['errors'] ?? self::DEFAULT_ERRORS;
        if (!\is_array($errors) || $errors === []) {
            throw new InvalidSloFile(\sprintf('%s: errors is a list of statuses or classes, such as ["5xx", 429].', $name));
        }
        $statuses = [];
        foreach ($errors as $error) {
            if (\is_int($error) && $error >= 100 && $error <= 599) {
                $statuses[] = $error;
            } elseif (\is_string($error) && preg_match('/^[1-5]xx$/i', $error) === 1) {
                $statuses[] = strtolower($error);
            } else {
                throw new InvalidSloFile(\sprintf('%s: "%s" in errors is neither a status code nor a class such as "5xx".', $name, \is_string($error) ? $error : get_debug_type($error)));
            }
        }
        if ($threshold !== null && \array_key_exists('errors', $data)) {
            throw new InvalidSloFile(\sprintf('%s: a latency SLO counts slow requests, not errors; remove errors or threshold_ms.', $name));
        }

        return new Slo($name, (float) $objective, $threshold, $routes, $statuses);
    }
}
