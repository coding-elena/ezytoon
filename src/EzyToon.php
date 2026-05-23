<?php
 
declare(strict_types=1);
 
/**
 * EzyToon — PHP >=8.4 encoder to TOON format
 * @author coding-elena
 * @link https://github.com/coding-elena/ezytoon
 *
 * Token-Oriented Object Notation (TOON) — spec v3.3
 * https://toonformat.dev/
 *
 * Supports:
 *  - Root objects, root arrays, root primitives
 *  - Nested objects (indentation-based)
 *  - Primitive arrays (inline, comma/tab/pipe delimited)
 *  - Tabular arrays (uniform objects → header + rows)
 *  - Mixed/non-uniform arrays (list form with "- " markers)
 *  - Arrays of arrays
 *  - Empty containers
 *  - Full quoting rules (§7)
 *  - Key folding (optional, safe mode)
 *  - DateTimeInterface → ISO 8601 quoted string
 *  - NaN/Infinity → null  |  -0 → 0
 *  - PHP 8.4 readonly constructor properties, named args
 */

namespace Toon;

final class EzyToon
{
    // ── Document delimiter (used for object field values) ──────────────────
    private const DOC_DELIMITER = ',';
 
    // ── Escape map for quoted strings ──────────────────────────────────────
    private const ESCAPE_MAP = [
        '\\' => '\\\\',
        '"'  => '\\"',
        "\n" => '\n',
        "\r" => '\r',
        "\t" => '\t',
    ];
 
    private string $indent;

    // ── Constructor / configuration ────────────────────────────────────────
 
    /**
     * @param int        $indentsize   Set indent size with spaces (default 2)
     * @param string     $delimiter    Default array delimiter: ',' | '\t' | '|'
     * @param bool       $keyFolding   Collapse single-key object chains into dotted paths
     * @param int        $flattenDepth Max segments to fold (0 = unlimited)
     */
    public function __construct(
        private readonly int    $indentsize  = 2,
        private readonly string $delimiter   = ',',
        private readonly bool   $keyFolding  = false,
        private readonly int    $flattenDepth = 0,
    ) {
        if (!in_array($this->delimiter, [',', "\t", '|'], true)) {
            throw new \InvalidArgumentException(
                'delimiter must be one of: comma, tab, pipe'
            );
        }
        $this->indent = str_repeat(' ', $this->indentsize);
    }
 
    // ── Public API ─────────────────────────────────────────────────────────
 
    /**
     * Encode any PHP value to a TOON string.
     */
    public function encode(mixed $value): string
    {
        $normalized = $this->normalize($value);
 
        if (is_array($normalized)) {
            if (array_is_list($normalized)) {
                // Root array
                return $this->encodeArray($normalized, '', 0);
            }
            // Root object
            return $this->encodeObject($normalized, 0);
        }
 
        // Root primitive
        return $this->encodePrimitive($normalized);
    }
 
    // ── Normalization ──────────────────────────────────────────────────────
 
    private function normalize(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM); // ISO 8601
        }
 
        if (is_object($value)) {
            if (method_exists($value, 'toJSON')) {
                return $this->normalize($value->toJSON());
            }
            if ($value instanceof \JsonSerializable) {
                return $this->normalize($value->jsonSerialize());
            }
            // Public properties
            return $this->normalize((array) $value);
        }
 
        if (is_array($value)) {
            $result = [];
            foreach ($value as $k => $v) {
                $result[$k] = $this->normalize($v);
            }
            return $result;
        }
 
        if (is_float($value)) {
            if (is_nan($value) || is_infinite($value)) return null;
            if ($value === -0.0) return 0.0;
            return $value;
        }
 
        // null, bool, int, string → pass through
        return $value;
    }
 
    // ── Object encoding ────────────────────────────────────────────────────
 
    private function encodeObject(array $obj, int $depth): string
    {
        if ($obj === []) {
            return ''; // empty object → empty output (spec §8)
        }
 
        // Optional key folding: collapse single-key chains
        if ($this->keyFolding) {
            $obj = $this->applyKeyFolding($obj, $depth);
        }
 
        $lines = [];
        foreach ($obj as $key => $value) {
            $encodedKey = $this->encodeKey((string) $key);
            $lines[]    = $this->encodeField($encodedKey, $value, $depth);
        }
 
        return implode("\n", $lines);
    }
 
    /**
     * Encode one object field.
     */
    private function encodeField(string $key, mixed $value, int $depth): string
    {
        $pad = str_repeat($this->indent, $depth);
 
        // Empty array
        if ($value === []) {
            return "{$pad}{$key}: []";
        }
 
        // Nested object
        if (is_array($value) && !array_is_list($value)) {
            $body = $this->encodeObject($value, $depth + 1);
            if ($body === '') {
                return "{$pad}{$key}:"; // empty nested object
            }
            return "{$pad}{$key}:\n{$body}";
        }
 
        // Array
        if (is_array($value) && array_is_list($value)) {
            return $this->encodeArray($value, $key, $depth);
        }
 
        // Primitive
        return "{$pad}{$key}: " . $this->encodeFieldValue($value);
    }
 
    // ── Array encoding ─────────────────────────────────────────────────────
 
    /**
     * Encode an array, choosing the best representation.
     *
     * @param list<mixed> $arr
     * @param string      $key   Empty string when root array
     * @param int         $depth Indentation depth
     */
    private function encodeArray(array $arr, string $key, int $depth): string
    {
        $pad = str_repeat($this->indent, $depth);
        $n   = count($arr);
 
        // Empty array (field)
        if ($n === 0) {
            return $key !== '' ? "{$pad}{$key}: []" : '[]';
        }
 
        $delim      = $this->delimiter;
        $delimToken = $this->delimToken($delim);
 
        // ── Primitive array (inline) ──────────────────────────────────────
        if ($this->isAllPrimitive($arr)) {
            $values  = array_map(fn($v) => $this->encodeArrayPrimitive($v, $delim), $arr);
            $inline  = implode($delim, $values);
            $header  = $key !== '' ? "{$key}[{$n}{$delimToken}]" : "[{$n}{$delimToken}]";
            return "{$pad}{$header}: {$inline}";
        }
 
        // ── Tabular array (uniform objects with primitive leaf values) ─────
        if ($this->isTabular($arr)) {
            return $this->encodeTabular($arr, $key, $depth, $delim, $delimToken);
        }
 
        // ── List form (mixed / non-uniform) ───────────────────────────────
        return $this->encodeList($arr, $key, $depth, $delimToken);
    }
 
    /**
     * Tabular array: objects sharing the same primitive-valued keys.
     */
    private function encodeTabular(
        array  $arr,
        string $key,
        int    $depth,
        string $delim,
        string $delimToken
    ): string {
        $pad    = str_repeat($this->indent, $depth);
        $rowPad = str_repeat($this->indent, $depth + 1);
        $n      = count($arr);
        $fields = array_keys($arr[0]);
 
        // Build field header string (fields themselves may contain delimiter → quote)
        $fieldStrs = array_map(
            fn($f) => $this->encodeKey((string) $f),
            $fields
        );
        $fieldList = implode($delim, $fieldStrs);
 
        $header = $key !== ''
            ? "{$pad}{$key}[{$n}{$delimToken}]{{$fieldList}}:"
            : "[{$n}{$delimToken}]{{$fieldList}}:";
 
        $rows = [];
        foreach ($arr as $item) {
            $cells = [];
            foreach ($fields as $f) {
                $cells[] = $this->encodeArrayPrimitive($item[$f], $delim);
            }
            $rows[] = $rowPad . implode($delim, $cells);
        }
 
        return $header . "\n" . implode("\n", $rows);
    }
 
    /**
     * List-form array: mixed elements, non-uniform objects, arrays of arrays.
     */
    private function encodeList(array $arr, string $key, int $depth, string $delimToken): string
    {
        $pad        = str_repeat($this->indent, $depth);
        $itemPad    = str_repeat($this->indent, $depth + 1);
        $n          = count($arr);
 
        $header = $key !== '' ? "{$pad}{$key}[{$n}{$delimToken}]:" : "[{$n}{$delimToken}]:";
        $lines  = [$header];
 
        foreach ($arr as $item) {
            $lines[] = $this->encodeListItem($item, $depth + 1);
        }
 
        return implode("\n", $lines);
    }
 
    /**
     * One list item (with "- " marker).
     */
    private function encodeListItem(mixed $item, int $depth): string
    {
        $pad     = str_repeat($this->indent, $depth);
        $childPad = str_repeat($this->indent, $depth + 1);
 
        // Primitive
        if ($this->isPrimitive($item)) {
            return "{$pad}- " . $this->encodeFieldValue($item);
        }
 
        // Nested array (array of arrays)
        if (is_array($item) && array_is_list($item)) {
            // Encode as inner array header on the hyphen line
            $inner = $this->encodeArray($item, '', $depth);
            // Trim the leading padding from encodeArray since we prepend "- "
            $inner = ltrim($inner);
            return "{$pad}- {$inner}";
        }
 
        // Object list item
        if (is_array($item) && !array_is_list($item)) {
            return $this->encodeObjectListItem($item, $depth);
        }
 
        // Fallback
        return "{$pad}- " . $this->encodeFieldValue($item);
    }
 
    /**
     * Object as a list item, with optional tabular-first-field pattern (spec §10).
     */
    private function encodeObjectListItem(array $obj, int $depth): string
    {
        $pad      = str_repeat($this->indent, $depth);
        $childPad = str_repeat($this->indent, $depth + 1);
 
        $keys = array_keys($obj);
        $firstKey = $keys[0];
        $firstVal = $obj[$firstKey];
 
        // Canonical pattern: first field is a tabular array
        if (
            is_array($firstVal) &&
            array_is_list($firstVal) &&
            count($firstVal) > 0 &&
            $this->isTabular($firstVal)
        ) {
            $delim      = $this->delimiter;
            $delimToken = $this->delimToken($delim);
            $n          = count($firstVal);
            $fields     = array_keys($firstVal[0]);
            $fieldList  = implode($delim, array_map(fn($f) => $this->encodeKey((string) $f), $fields));
            $encodedFirstKey = $this->encodeKey((string) $firstKey);
 
            // Header on the hyphen line
            $header = "{$pad}- {$encodedFirstKey}[{$n}{$delimToken}]{{$fieldList}}:";
 
            // Rows at depth+2
            $rowPad = str_repeat($this->indent, $depth + 2);
            $rows   = [];
            foreach ($firstVal as $row) {
                $cells = [];
                foreach ($fields as $f) {
                    $cells[] = $this->encodeArrayPrimitive($row[$f], $delim);
                }
                $rows[] = $rowPad . implode($delim, $cells);
            }
 
            $lines = [$header, ...$rows];
 
            // Sibling fields at depth+1
            foreach (array_slice($keys, 1) as $k) {
                $lines[] = $this->encodeField($this->encodeKey((string) $k), $obj[$k], $depth + 1);
            }
 
            return implode("\n", $lines);
        }
 
        // Normal object list item: first field on the hyphen line
        $lines = [];
        $firstEncKey = $this->encodeKey((string) $firstKey);
        $firstLine   = $this->encodeField($firstEncKey, $firstVal, $depth);
        // Replace leading pad with "- "
        $firstLine   = $pad . '- ' . ltrim($firstLine);
        $lines[]     = $firstLine;
 
        foreach (array_slice($keys, 1) as $k) {
            $lines[] = $this->encodeField($this->encodeKey((string) $k), $obj[$k], $depth + 1);
        }
 
        return implode("\n", $lines);
    }
 
    // ── Key folding ────────────────────────────────────────────────────────
 
    /**
     * Collapse single-key nested object chains into dotted keys (safe mode).
     */
    private function applyKeyFolding(array $obj, int $depth): array
    {
        $result = [];
        foreach ($obj as $key => $value) {
            [$foldedKey, $foldedValue] = $this->foldKey((string) $key, $value, 1);
            $result[$foldedKey] = $foldedValue;
        }
        return $result;
    }
 
    private function foldKey(string $key, mixed $value, int $segments): array
    {
        $maxSeg = $this->flattenDepth > 0 ? $this->flattenDepth : PHP_INT_MAX;
 
        if (
            $segments < $maxSeg &&
            is_array($value) &&
            !array_is_list($value) &&
            count($value) === 1
        ) {
            $childKey   = (string) array_key_first($value);
            $childValue = $value[$childKey];
 
            if ($this->isFoldableSegment($childKey)) {
                return $this->foldKey($key . '.' . $childKey, $childValue, $segments + 1);
            }
        }
 
        return [$key, $value];
    }
 
    private function isFoldableSegment(string $key): bool
    {
        return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key);
    }
 
    // ── Value encoding helpers ─────────────────────────────────────────────
 
    /**
     * Encode a primitive value for an object field (uses document delimiter).
     */
    private function encodeFieldValue(mixed $value): string
    {
        if ($value === null)  return 'null';
        if ($value === true)  return 'true';
        if ($value === false) return 'false';
        if (is_int($value))   return (string) $value;
        if (is_float($value)) return $this->formatFloat($value);
        if (is_string($value)) {
            return $this->needsQuoting($value, self::DOC_DELIMITER)
                ? $this->quoteString($value)
                : $value;
        }
        return 'null';
    }
 
    /**
     * Encode a primitive value inside an array (uses active delimiter).
     */
    private function encodeArrayPrimitive(mixed $value, string $delim): string
    {
        if ($value === null)  return 'null';
        if ($value === true)  return 'true';
        if ($value === false) return 'false';
        if (is_int($value))   return (string) $value;
        if (is_float($value)) return $this->formatFloat($value);
        if (is_string($value)) {
            return $this->needsQuoting($value, $delim)
                ? $this->quoteString($value)
                : $value;
        }
        return 'null';
    }
 
    private function encodePrimitive(mixed $value): string
    {
        return $this->encodeFieldValue($value);
    }
 
    private function formatFloat(float $value): string
    {
        // Canonical decimal for values in [1e-6, 1e21) or zero
        $abs = abs($value);
        if ($abs === 0.0 || ($abs >= 1e-6 && $abs < 1e21)) {
            $str = rtrim(number_format($value, 10, '.', ''), '0');
            return str_ends_with($str, '.') ? $str . '0' : $str;
        }
        // Exponent form outside that range
        return sprintf('%g', $value);
    }
 
    // ── Key encoding ───────────────────────────────────────────────────────
 
    private function encodeKey(string $key): string
    {
        // Keys follow the same quoting rules as strings (spec §7)
        return $this->needsQuoting($key, self::DOC_DELIMITER)
            ? $this->quoteString($key)
            : $key;
    }
 
    // ── Quoting rules (spec §7) ────────────────────────────────────────────
 
    /**
     * Determine whether a string needs quoting.
     *
     * @param string $delim  The relevant delimiter (document or active array delimiter)
     */
    private function needsQuoting(string $s, string $delim): bool
    {
        if ($s === '') return true;
 
        // Leading or trailing whitespace
        if ($s !== trim($s)) return true;
 
        // Literal keywords
        if (in_array($s, ['true', 'false', 'null'], true)) return true;
 
        // Looks like a number (including leading zeros like "05")
        if ($this->looksLikeNumber($s)) return true;
 
        // Equals "-" or starts with "-" followed by any char
        if ($s === '-' || (strlen($s) > 1 && $s[0] === '-')) return true;
 
        // Contains special characters
        foreach (str_split($s) as $ch) {
            $ord = ord($ch);
            if ($ord <= 0x1F) return true; // control characters
            if (in_array($ch, [':', '"', '\\', '[', ']', '{', '}'], true)) return true;
        }
 
        // Contains the relevant delimiter
        if (str_contains($s, $delim)) return true;
 
        return false;
    }
 
    private function looksLikeNumber(string $s): bool
    {
        // Numeric: integer, float, exponent, or leading-zero
        return (bool) preg_match(
            '/^-?(?:0|[1-9]\d*|\d+\.\d*|\.\d+)(?:[eE][+-]?\d+)?$/',
            $s
        );
    }
 
    private function quoteString(string $s): string
    {
        // Apply spec-valid escapes
        $escaped = str_replace(
            array_keys(self::ESCAPE_MAP),
            array_values(self::ESCAPE_MAP),
            $s
        );
 
        // Escape remaining U+0000–U+001F control characters
        $escaped = preg_replace_callback(
            '/[\x00-\x1F]/',
            fn($m) => sprintf('\u%04X', ord($m[0])),
            $escaped
        );
 
        return '"' . $escaped . '"';
    }
 
    // ── Type predicates ────────────────────────────────────────────────────
 
    private function isPrimitive(mixed $value): bool
    {
        return is_null($value) || is_bool($value) || is_int($value)
            || is_float($value) || is_string($value);
    }
 
    private function isAllPrimitive(array $arr): bool
    {
        foreach ($arr as $v) {
            if (!$this->isPrimitive($v)) return false;
        }
        return true;
    }
 
    /**
     * Tabular: uniform list of objects, all keys identical, all values primitive.
     */
    private function isTabular(array $arr): bool
    {
        if ($arr === []) return false;
 
        foreach ($arr as $item) {
            if (!is_array($item) || array_is_list($item) || $item === []) return false;
        }
 
        $refKeys = array_keys($arr[0]);
        if ($refKeys === []) return false;
 
        foreach ($arr as $item) {
            if (array_keys($item) !== $refKeys) return false;
            foreach ($item as $v) {
                if (!$this->isPrimitive($v)) return false;
            }
        }
 
        return true;
    }
 
    // ── Delimiter token ────────────────────────────────────────────────────
 
    /**
     * The delimiter symbol that goes inside brackets when non-default.
     * Comma = default → no token; tab and pipe → explicit token.
     */
    private function delimToken(string $delim): string
    {
        return match ($delim) {
            ','  => '',
            "\t" => "\t",
            '|'  => '|',
            default => '',
        };
    }
}
