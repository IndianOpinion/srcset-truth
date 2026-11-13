<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Sizes;

/**
 * The accepted grammar for a sizes value (PRD case 22 and 24).
 *
 * value     := [ "auto" "," ] { condition length "," } length
 * condition := a media condition wrapped in parentheses, optionally "not"
 * length    := number ( px | vw | em | rem ) | calc() | min() | max() | clamp()
 *
 * Percentages are refused (they are invalid in sizes), and so is any quote,
 * angle bracket, semicolon, brace or backslash: those are never escaped
 * through, the whole spec is refused instead.
 */
final class Grammar
{
    private const LENGTH = '/^(?:\d+|\d*\.\d+)(?:px|vw|em|rem)$/i';

    private const FUNC = '/^(calc|min|max|clamp)\((.*)\)$/is';

    /**
     * Returns null when the value is valid, otherwise a short reason.
     */
    public static function check(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return 'empty';
        }
        if (preg_match('/["\'<>;{}\\\\%]|[\x00-\x1f]/', $value) === 1) {
            return 'forbidden character';
        }
        $entries = self::splitTopLevel($value);
        if ($entries === null) {
            return 'unbalanced parentheses';
        }
        $count = count($entries);
        foreach ($entries as $index => $entry) {
            if ($entry === '') {
                return 'empty entry';
            }
            if (strcasecmp($entry, 'auto') === 0) {
                if ($index !== 0) {
                    return 'auto not first';
                }
                if ($count === 1) {
                    return 'missing default length';
                }
                continue;
            }
            [$condition, $length] = self::splitEntry($entry);
            if ($length === null || !self::isLength($length)) {
                return 'invalid length';
            }
            $isLast = $index === $count - 1;
            if ($isLast && $condition !== '') {
                return 'missing default length';
            }
            if (!$isLast && $condition === '') {
                return 'length without media condition';
            }
            if ($condition !== '' && !self::isCondition($condition)) {
                return 'invalid media condition';
            }
        }

        return null;
    }

    public static function isValid(string $value): bool
    {
        return self::check($value) === null;
    }

    /**
     * Splits on commas that are not inside parentheses. Returns null when
     * parentheses do not balance.
     *
     * @return list<string>|null
     */
    public static function splitTopLevel(string $value): ?array
    {
        $parts = [];
        $depth = 0;
        $current = '';
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth < 0) {
                    return null;
                }
            } elseif ($char === ',' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $char;
        }
        if ($depth !== 0) {
            return null;
        }
        $parts[] = trim($current);

        return $parts;
    }

    /**
     * Splits one entry into its media condition (possibly empty) and its
     * trailing length.
     *
     * @return array{0: string, 1: string|null}
     */
    public static function splitEntry(string $entry): array
    {
        $entry = trim($entry);
        if ($entry === '') {
            return ['', null];
        }
        if (str_ends_with($entry, ')')) {
            $depth = 0;
            for ($i = strlen($entry) - 1; $i >= 0; $i--) {
                if ($entry[$i] === ')') {
                    $depth++;
                } elseif ($entry[$i] === '(') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
            }
            if ($i < 0) {
                return ['', null];
            }
            $start = $i;
            while ($start > 0 && ctype_alpha($entry[$start - 1])) {
                $start--;
            }
            if ($start === $i) {
                // A bare parenthesised group at the end: a condition with no length.
                return [$entry, null];
            }

            return [trim(substr($entry, 0, $start)), substr($entry, $start)];
        }
        $space = strrpos($entry, ' ');
        if ($space === false) {
            return ['', $entry];
        }

        return [trim(substr($entry, 0, $space)), substr($entry, $space + 1)];
    }

    public static function isLength(string $length): bool
    {
        $length = trim($length);
        if (preg_match(self::LENGTH, $length) === 1) {
            return true;
        }
        if (preg_match(self::FUNC, $length, $match) !== 1) {
            return false;
        }
        $inner = $match[2];
        if (preg_match('/^[0-9a-z.+\-*\/(),\s]*$/i', $inner) !== 1) {
            return false;
        }
        if (self::splitTopLevel($inner) === null) {
            return false;
        }
        // Every word inside must be a unit, a nested function or an operator.
        if (preg_match_all('/[a-z]+/i', $inner, $words) > 0) {
            foreach ($words[0] as $word) {
                if (!in_array(strtolower($word), ['px', 'vw', 'em', 'rem', 'calc', 'min', 'max', 'clamp'], true)) {
                    return false;
                }
            }
        }

        return trim($inner) !== '';
    }

    public static function isCondition(string $condition): bool
    {
        $condition = trim($condition);
        if (preg_match('/^(not\s+)?\(.*\)$/is', $condition) !== 1) {
            return false;
        }
        if (preg_match('/^[a-z0-9\s:().\-\/]*$/i', $condition) !== 1) {
            return false;
        }

        return self::splitTopLevel($condition) !== null;
    }
}
