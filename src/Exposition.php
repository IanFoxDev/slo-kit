<?php

declare(strict_types=1);

namespace IanFoxDev\SloKit;

/**
 * Reads the Prometheus text exposition format: what /metrics returns, from PHP, Go or
 * anything else.
 */
final class Exposition
{
    /**
     * @return list<Sample>
     */
    public static function parse(string $text): array
    {
        $samples = [];
        foreach (preg_split('/\r?\n/', $text) ?: [] as $number => $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (preg_match('/^([a-zA-Z_:][a-zA-Z0-9_:]*)(\{(.*)\})?\s+(\S+)(\s+-?[0-9]+)?$/', $line, $m) !== 1) {
                throw new \UnexpectedValueException(\sprintf('Line %d is not in the Prometheus text format: %s', $number + 1, $line));
            }
            $samples[] = new Sample($m[1], $m[3] === '' ? [] : self::labels($m[3], $number + 1), self::value($m[4]));
        }

        return $samples;
    }

    /**
     * @return array<string, string>
     */
    private static function labels(string $text, int $line): array
    {
        $labels = [];
        $offset = 0;
        $length = \strlen($text);
        while ($offset < $length) {
            if (preg_match('/\G\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*"((?:[^"\\\\]|\\\\.)*)"\s*(,|$)/', $text, $m, 0, $offset) !== 1) {
                throw new \UnexpectedValueException(\sprintf('Line %d has labels that cannot be read: {%s}', $line, $text));
            }
            $labels[$m[1]] = strtr($m[2], ['\\"' => '"', '\\n' => "\n", '\\\\' => '\\']);
            $offset += \strlen($m[0]);
        }

        return $labels;
    }

    private static function value(string $value): float
    {
        return match (strtolower($value)) {
            '+inf', 'inf' => \INF,
            '-inf' => -\INF,
            'nan' => \NAN,
            default => is_numeric($value) ? (float) $value : throw new \UnexpectedValueException(\sprintf('"%s" is not a sample value.', $value)),
        };
    }
}
