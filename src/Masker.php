<?php

namespace FyxifyLogs;

// Mirrors fyxify-logs/src/mask.js — keep the two in sync.
class Masker
{
    public const MASK = '********';

    private const WORDS = 'password|passwd|token|secret|authorization|api_key|apikey|signature|cvv|ssn|card_number|birth_date|license';

    private const PATTERNS = [
        '/\beyJ[\w-]{5,}\.[\w-]{5,}\.[\w-]*/'                                      => self::MASK,          // JWT
        '/\b(Bearer|Basic)\s+[\w\-.~+\/=]{8,}/i'                                  => '$1 ' . self::MASK,  // auth header values
        '/((?:^|[?&;\s"\'[(,{])[\w-]*(?:' . self::WORDS . ')[\w-]*["\']?\s*=>?\s*["\']?)[^&\s"\']*/i' => '${1}' . self::MASK, // key=val, 'key' => 'val'
        '/("[^"]*(?:' . self::WORDS . ')[^"]*"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"/i'    => '${1}"' . self::MASK . '"', // truncated JSON
        '/(\[[^\]\n]*(?:' . self::WORDS . ')[^\]\n]*\] => )(?!Array\n)[^\n]*/i'   => '${1}' . self::MASK, // print_r()
    ];

    public function __construct(private array $keys, private array $exactKeys)
    {
        $this->keys      = array_map('strtolower', $keys);
        $this->exactKeys = array_map('strtolower', $exactKeys);
    }

    public function isSensitiveKey(string|int $key): bool
    {
        $k = strtolower((string) $key);
        if (in_array($k, $this->exactKeys, true)) {
            return true;
        }
        foreach ($this->keys as $s) {
            if (str_contains($k, $s)) {
                return true;
            }
        }
        return false;
    }

    public function mask(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->maskString($value);
        }
        if (is_object($value)) {
            $value = json_decode(json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR), true);
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->isSensitiveKey($k) && $v !== null && $v !== '' ? self::MASK : $this->mask($v);
            }
            return $out;
        }
        return $value;
    }

    public function maskString(string $s): string
    {
        $t = ltrim($s);
        if ($t !== '' && ($t[0] === '{' || $t[0] === '[')) {
            $decoded = json_decode($s, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return json_encode($this->mask($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            }
        }
        return preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $s) ?? $s;
    }
}
