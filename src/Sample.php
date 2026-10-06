<?php

declare(strict_types=1);

namespace IanFoxDev\SloKit;

/**
 * One line of the Prometheus text format: a name, its labels and a value.
 */
final readonly class Sample
{
    /**
     * @param array<string, string> $labels
     */
    public function __construct(
        public string $name,
        public array $labels,
        public float $value,
    ) {}
}
