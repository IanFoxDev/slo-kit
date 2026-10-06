<?php

declare(strict_types=1);

namespace IanFoxDev\SloKit;

/**
 * Something `check` found. An error means the SLI would be computed wrong or not at all; a
 * warning means it may be.
 */
final readonly class Finding
{
    public function __construct(
        public bool $error,
        public string $message,
        public ?string $slo = null,
    ) {}
}
