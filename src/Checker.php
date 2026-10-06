<?php

declare(strict_types=1);

namespace IanFoxDev\SloKit;

/**
 * Compares what a service exports on /metrics with its SLO file, before anything alerts on
 * those metrics.
 */
final readonly class Checker
{
    public function __construct(
        private int $maxRoutes = 100,
        private float $maxUnmatchedShare = 0.05,
    ) {}

    /**
     * @param list<Sample> $samples
     * @return list<Finding>
     */
    public function check(SloFile $file, array $samples): array
    {
        $names = $file->metricNames();
        $requests = array_values(array_filter($samples, static fn(Sample $s): bool => $s->name === $names['requests']));
        $buckets = array_values(array_filter($samples, static fn(Sample $s): bool => $s->name === $names['duration'] . '_bucket'));
        $findings = [];

        if ($requests === []) {
            $similar = self::similar($samples, '_requests_total');

            return [new Finding(true, \sprintf(
                'No %s in /metrics%s. Every SLO query reads it.',
                $names['requests'],
                $similar === [] ? '' : '; found ' . implode(', ', $similar) . ' (is metrics.prefix right?)',
            ))];
        }
        foreach (['route', 'code'] as $label) {
            if (!\array_key_exists($label, $requests[0]->labels)) {
                $findings[] = new Finding(true, \sprintf('%s has no "%s" label; the SLO queries select by it.', $names['requests'], $label));
            }
        }

        $routes = [];
        $total = 0.0;
        $unmatched = 0.0;
        foreach ($requests as $sample) {
            $route = $sample->labels['route'] ?? '';
            $routes[$route] = ($routes[$route] ?? 0.0) + $sample->value;
            $total += $sample->value;
            if ($route === Recorder::UNMATCHED) {
                $unmatched += $sample->value;
            }
        }
        if (\count($routes) > $this->maxRoutes) {
            $findings[] = new Finding(true, \sprintf('%d distinct routes, more than %d: a label with ids or URLs makes one series per value.', \count($routes), $this->maxRoutes));
        }
        foreach (array_keys($routes) as $route) {
            if (preg_match('#/[0-9]{2,}(/|$)|[0-9a-f]{8}-[0-9a-f]{4}-#i', (string) $route) === 1) {
                $findings[] = new Finding(true, \sprintf('Route "%s" looks like a URL; record the route template instead.', $route));
            }
        }
        if ($total > 0 && $unmatched / $total > $this->maxUnmatchedShare) {
            $findings[] = new Finding(false, \sprintf('%.1f%% of requests have no route ("%s"); SLOs that select routes do not see them.', 100 * $unmatched / $total, Recorder::UNMATCHED));
        }

        foreach ($file->slos as $slo) {
            $covered = array_filter(array_keys($routes), static fn(int|string $r): bool => $slo->covers((string) $r));
            if ($covered === []) {
                $findings[] = new Finding(false, \sprintf('No traffic on routes matching %s; the SLO cannot be evaluated yet, or the patterns do not match the route names (seen: %s).', implode(', ', $slo->routes), implode(', ', \array_slice(array_map(strval(...), array_keys($routes)), 0, 5))), $slo->name);
            }
            if ($slo->threshold !== null) {
                $findings = [...$findings, ...$this->checkThreshold($slo, $buckets, $names['duration'])];
            }
        }

        return $findings;
    }

    /**
     * @param list<Sample> $buckets
     * @return list<Finding>
     */
    private function checkThreshold(Slo $slo, array $buckets, string $histogram): array
    {
        if ($buckets === []) {
            return [new Finding(true, \sprintf('No %s histogram in /metrics; a latency SLO is read from its buckets.', $histogram), $slo->name)];
        }
        $bounds = [];
        foreach ($buckets as $bucket) {
            $le = $bucket->labels['le'] ?? null;
            if ($le !== null && is_numeric($le)) {
                $bounds[(string) (float) $le] = (float) $le;
            }
        }
        foreach ($bounds as $bound) {
            if (abs($bound - (float) $slo->threshold) < 1e-9) {
                return [];
            }
        }
        sort($bounds);
        $below = array_filter($bounds, static fn(float $b): bool => $b < $slo->threshold);
        $above = array_filter($bounds, static fn(float $b): bool => $b > $slo->threshold);
        $lower = $below === [] ? null : max($below);
        $upper = $above === [] ? null : min($above);

        return [new Finding(true, \sprintf(
            'Threshold %s s has no bucket (nearest: %s). The SLI can only count requests under %s, not under %s: the dashboard is green or red for a threshold nobody chose. Add le="%s".',
            self::seconds((float) $slo->threshold),
            implode(' and ', array_map(self::seconds(...), array_filter([$lower, $upper], static fn(?float $b): bool => $b !== null))),
            implode(' or ', array_map(static fn(float $b): string => self::seconds($b) . ' s', array_filter([$lower, $upper], static fn(?float $b): bool => $b !== null))),
            self::seconds((float) $slo->threshold) . ' s',
            self::seconds((float) $slo->threshold),
        ), $slo->name)];
    }

    /**
     * @param list<Sample> $samples
     * @return list<string>
     */
    private static function similar(array $samples, string $suffix): array
    {
        $names = [];
        foreach ($samples as $sample) {
            if (str_ends_with($sample->name, $suffix)) {
                $names[$sample->name] = true;
            }
        }

        return \array_slice(array_keys($names), 0, 3);
    }

    private static function seconds(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }
}
