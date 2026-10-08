<?php

namespace AdeildoJr\HttpLogs\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use JsonException;
use OverflowException;

class LogSanitizer
{
    private const REDACTED = '[REDACTED]';

    private const TRUNCATED = '[TRUNCATED]';

    private const UNSUPPORTED = '[UNSUPPORTED]';

    /**
     * @param  array<array-key, mixed>  $headers
     * @return array<array-key, mixed>
     */
    public function headers(array $headers): array
    {
        return $this->boundedPayload($headers, $this->redactedKeys('redact_headers'));
    }

    /**
     * Accept decoded request data and JSON response values, never a raw body.
     *
     * @return array<array-key, mixed>
     */
    public function body(mixed $data): array
    {
        $payload = match (true) {
            $data === null => ['empty'],
            is_array($data) => $data,
            default => ['value' => $data],
        };

        return $this->boundedPayload($payload, $this->redactedKeys('redact_fields'));
    }

    /**
     * Multipart credentials are identified by each part's name, rather than
     * by the structural "contents" key used by the HTTP client.
     *
     * @param  array<array-key, mixed>  $parts
     * @return array<array-key, mixed>
     */
    public function multipart(array $parts): array
    {
        $keys = $this->redactedKeys('redact_fields');
        $payload = Arr::map($parts, fn (mixed $part): array|string => $this->sanitizePart($part, $keys));

        return $this->boundedPayload($payload, $keys);
    }

    public function url(string $url): string
    {
        $limit = $this->limit('max_url_bytes', 2048);

        if (strlen($url) > $limit) {
            return self::TRUNCATED;
        }

        // HTTP client URLs must be absolute. Malformed input is discarded,
        // rather than returning a potentially credential-bearing original.
        if (Str::isMatch('/[\x00-\x20\x7f]/', $url)) {
            return self::REDACTED;
        }

        try {
            $parts = parse_url($url);
        } catch (\ValueError) {
            return self::REDACTED;
        }

        if ($parts === false
            || ! isset($parts['scheme'], $parts['host'])
            || ! in_array(Str::lower($parts['scheme']), ['http', 'https'], true)
            || $parts['host'] === '') {
            return self::REDACTED;
        }

        $result = $parts['scheme'].'://'.$parts['host'];

        if (isset($parts['port'])) {
            $result .= ':'.$parts['port'];
        }

        $result .= $parts['path'] ?? '';

        if (isset($parts['query'])) {
            $query = $this->query($parts['query'], $this->redactedKeys('redact_fields'));

            if ($query === null) {
                return self::REDACTED;
            }

            $result .= '?'.$query;
        }

        return strlen($result) > $limit ? self::TRUNCATED : $result;
    }

    /**
     * @param  array<string, true>  $keys
     * @return array<string, mixed>|string
     */
    private function sanitizePart(mixed $part, array $keys): array|string
    {
        if (! is_array($part) || ! isset($part['name']) || ! is_string($part['name'])) {
            return self::UNSUPPORTED;
        }

        $name = $part['name'];
        $contents = $part['contents'] ?? null;

        if (Str::isMatch('/[\x00-\x1f\x7f]/', $name)) {
            $name = self::REDACTED;
            $contents = self::REDACTED;
        } elseif (Arr::exists($part, 'filename') || is_object($contents) || is_resource($contents)) {
            $contents = self::UNSUPPORTED;
        } elseif ($this->sensitiveFieldName($name, $keys)
            || $this->sensitiveFieldName(urldecode($name), $keys)) {
            $contents = self::REDACTED;
        }

        $result = ['name' => $name, 'contents' => $contents];

        if (Arr::exists($part, 'headers')) {
            $result['headers'] = is_array($part['headers'])
                ? $this->headers($part['headers'])
                : self::UNSUPPORTED;
        }

        return $result;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @param  array<string, true>  $keys
     * @return array<array-key, mixed>
     */
    private function boundedPayload(array $payload, array $keys): array
    {
        $remaining = $this->limit('max_payload_bytes', 16384);

        try {
            $sanitized = $this->sanitizeArray(
                $payload,
                $keys,
                0,
                min($this->limit('max_depth', 20), 100),
                $remaining,
            );
        } catch (JsonException|OverflowException) {
            return ['_truncated' => true];
        }

        return $sanitized;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  array<string, true>  $keys
     * @return array<array-key, mixed>
     */
    private function sanitizeArray(array $data, array $keys, int $depth, int $maxDepth, int &$remaining): array
    {
        $this->consume($remaining, 2);
        $isList = array_is_list($data);
        $result = [];

        foreach ($data as $key => $value) {
            if ($result !== []) {
                $this->consume($remaining, 1);
            }

            if (! $isList) {
                $this->consumeValue($remaining, (string) $key);
                $this->consume($remaining, 1);
            }

            if (is_string($key) && isset($keys[$this->normalizeKey($key)])) {
                $result[$key] = self::REDACTED;
                $this->consumeValue($remaining, self::REDACTED);

                continue;
            }

            $result[$key] = match (true) {
                $depth >= $maxDepth => self::TRUNCATED,
                is_array($value) => $this->sanitizeArray($value, $keys, $depth + 1, $maxDepth, $remaining),
                is_scalar($value), $value === null => $value,
                default => self::UNSUPPORTED,
            };

            if (! is_array($result[$key])) {
                $this->consumeValue($remaining, $result[$key]);
            }
        }

        return $result;
    }

    /**
     * @param  array<string, true>  $keys
     */
    private function query(string $query, array $keys): ?string
    {
        // Keep the original spelling, order, separators and duplicate keys.
        $pieces = preg_split('/([&;])/', $query, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($pieces === false) {
            return null;
        }

        foreach ($pieces as $index => $piece) {
            if ($piece === '&' || $piece === ';') {
                continue;
            }

            $name = Str::before($piece, '=');
            $decodedName = urldecode($name);

            // PHP can truncate parsed names at NUL bytes. Discard ambiguous
            // names rather than leaving a sensitive value in the URL.
            if (Str::isMatch('/[\x00-\x1f\x7f]/', $decodedName)) {
                return null;
            }

            if ($this->sensitiveFieldName($decodedName, $keys)) {
                $pieces[$index] = $name.'='.rawurlencode(self::REDACTED);
            }
        }

        return implode('', $pieces);
    }

    /**
     * @return array<string, true>
     */
    private function redactedKeys(string $option): array
    {
        $configured = config('http-logs-laravel.'.$option, []);

        if (! is_array($configured)) {
            return [];
        }

        return collect($configured)
            ->filter(fn (mixed $key): bool => is_string($key) && $key !== '')
            ->mapWithKeys(fn (string $key): array => [$this->normalizeKey($key) => true])
            ->all();
    }

    private function normalizeKey(string $key): string
    {
        return strtolower(Str::replace('_', '-', $key));
    }

    /**
     * @param  array<string, true>  $keys
     */
    private function sensitiveFieldName(string $name, array $keys): bool
    {
        $segments = preg_split('/[\[\]]/', $name, -1, PREG_SPLIT_NO_EMPTY);

        if ($segments === false) {
            return true;
        }

        foreach ($segments as $index => $segment) {
            // PHP rewrites dots and spaces in base form/query names.
            $parsedSegment = $index === 0
                ? Str::replace(['.', ' '], '_', Str::ltrim($segment, ' '))
                : $segment;

            if (isset($keys[$this->normalizeKey($segment)])
                || isset($keys[$this->normalizeKey($parsedSegment)])) {
                return true;
            }
        }

        return false;
    }

    private function consumeValue(int &$remaining, mixed $value): void
    {
        if (is_string($value) && strlen($value) > $remaining) {
            throw new OverflowException;
        }

        $this->consume($remaining, strlen(json_encode($value, JSON_THROW_ON_ERROR)));
    }

    private function consume(int &$remaining, int $bytes): void
    {
        $remaining -= $bytes;

        if ($remaining < 0) {
            throw new OverflowException;
        }
    }

    private function limit(string $option, int $default): int
    {
        $configured = config('http-logs-laravel.'.$option, $default);

        $limit = is_int($configured) ? max(0, $configured) : $default;

        return match ($option) {
            'max_payload_bytes' => max(19, $limit),
            'max_url_bytes' => max(strlen(self::TRUNCATED), $limit),
            default => $limit,
        };
    }
}
