<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Sizes;

/**
 * Evaluates a sizes value at a viewport width, as a browser would, for the
 * audit (PRD case 78).
 *
 * Supported: max-width and min-width in px or em (16 px), joined by "and";
 * lengths in px, vw, em and rem; calc(), min(), max() and clamp() with
 * + - * /. Anything else returns null ("unknown") rather than a guess.
 * A leading "auto" is skipped: the audit cannot know the laid-out width.
 */
final class Evaluator
{
    private const ROOT_FONT_PX = 16.0;

    /** @var list<array{0: string, 1: string}> */
    private array $tokens = [];

    private int $pos = 0;

    public function __construct(private readonly float $viewport)
    {
    }

    /**
     * The slot width in CSS px, or null when the value cannot be evaluated.
     */
    public static function slotWidth(string $sizes, float $viewport): ?float
    {
        return (new self($viewport))->evaluate($sizes);
    }

    public function evaluate(string $sizes): ?float
    {
        $entries = Grammar::splitTopLevel(trim($sizes));
        if ($entries === null || $entries === []) {
            return null;
        }
        if (strcasecmp($entries[0], 'auto') === 0) {
            array_shift($entries);
        }
        $last = count($entries) - 1;
        foreach ($entries as $index => $entry) {
            [$condition, $length] = Grammar::splitEntry($entry);
            if ($length === null) {
                return null;
            }
            if ($condition === '' || $index === $last) {
                return $condition === '' ? $this->length($length) : null;
            }
            $matches = $this->condition($condition);
            if ($matches === null) {
                return null;
            }
            if ($matches) {
                return $this->length($length);
            }
        }

        return null;
    }

    public function condition(string $condition): ?bool
    {
        $parts = preg_split('/\s+and\s+/i', trim($condition));
        if ($parts === false || $parts === []) {
            return null;
        }
        $result = true;
        foreach ($parts as $part) {
            if (preg_match('/^\(\s*(max|min)-width\s*:\s*(\d+(?:\.\d+)?)\s*(px|em)\s*\)$/i', trim($part), $m) !== 1) {
                return null;
            }
            $value = (float) $m[2] * (strtolower($m[3]) === 'em' ? self::ROOT_FONT_PX : 1.0);
            $holds = strtolower($m[1]) === 'max' ? $this->viewport <= $value : $this->viewport >= $value;
            $result = $result && $holds;
        }

        return $result;
    }

    public function length(string $length): ?float
    {
        $tokens = $this->tokenize(trim($length));
        if ($tokens === null) {
            return null;
        }
        $this->tokens = $tokens;
        $this->pos = 0;
        $value = $this->expression();
        if ($value === null || $this->pos !== count($this->tokens) || $value[1] !== 'len') {
            return null;
        }

        return max(0.0, $value[0]);
    }

    /**
     * @return list<array{0: string, 1: string}>|null
     */
    private function tokenize(string $input): ?array
    {
        $tokens = [];
        $offset = 0;
        $length = strlen($input);
        while ($offset < $length) {
            $rest = substr($input, $offset);
            if (preg_match('/^\s+/', $rest, $m) === 1) {
                $offset += strlen($m[0]);
                continue;
            }
            if (preg_match('/^(\d+(?:\.\d+)?|\.\d+)(px|vw|em|rem)?/i', $rest, $m) === 1) {
                $tokens[] = ['num', $m[0]];
                $offset += strlen($m[0]);
                continue;
            }
            if (preg_match('/^(calc|min|max|clamp)\(/i', $rest, $m) === 1) {
                $tokens[] = ['fn', strtolower($m[1])];
                $offset += strlen($m[0]);
                continue;
            }
            $char = $rest[0];
            if (strpbrk($char, '+-*/(),') !== false) {
                $tokens[] = ['op', $char];
                $offset++;
                continue;
            }

            return null;
        }

        return $tokens;
    }

    /**
     * @return array{0: float, 1: string}|null [value, 'len'|'num']
     */
    private function expression(): ?array
    {
        $left = $this->term();
        while ($left !== null && $this->peekOp(['+', '-'])) {
            $op = $this->tokens[$this->pos++][1];
            $right = $this->term();
            if ($right === null || $right[1] !== $left[1]) {
                return null;
            }
            $left = [$op === '+' ? $left[0] + $right[0] : $left[0] - $right[0], $left[1]];
        }

        return $left;
    }

    /**
     * @return array{0: float, 1: string}|null
     */
    private function term(): ?array
    {
        $left = $this->factor();
        while ($left !== null && $this->peekOp(['*', '/'])) {
            $op = $this->tokens[$this->pos++][1];
            $right = $this->factor();
            if ($right === null) {
                return null;
            }
            if ($op === '*') {
                if ($left[1] === 'len' && $right[1] === 'len') {
                    return null;
                }
                $left = [$left[0] * $right[0], $left[1] === 'len' || $right[1] === 'len' ? 'len' : 'num'];
            } else {
                if ($right[1] !== 'num' || $right[0] == 0.0) {
                    return null;
                }
                $left = [$left[0] / $right[0], $left[1]];
            }
        }

        return $left;
    }

    /**
     * @return array{0: float, 1: string}|null
     */
    private function factor(): ?array
    {
        $token = $this->tokens[$this->pos] ?? null;
        if ($token === null) {
            return null;
        }
        if ($token[0] === 'op' && $token[1] === '-') {
            $this->pos++;
            $inner = $this->factor();

            return $inner === null ? null : [-$inner[0], $inner[1]];
        }
        if ($token[0] === 'op' && $token[1] === '(') {
            $this->pos++;
            $inner = $this->expression();
            if (!$this->peekOp([')'])) {
                return null;
            }
            $this->pos++;

            return $inner;
        }
        if ($token[0] === 'num') {
            $this->pos++;

            return $this->number($token[1]);
        }
        if ($token[0] === 'fn') {
            $this->pos++;
            $args = [];
            do {
                $arg = $this->expression();
                if ($arg === null) {
                    return null;
                }
                $args[] = $arg;
                $more = $this->peekOp([',']);
                if ($more) {
                    $this->pos++;
                }
            } while ($more);
            if (!$this->peekOp([')'])) {
                return null;
            }
            $this->pos++;

            return $this->apply($token[1], $args);
        }

        return null;
    }

    /**
     * @param list<array{0: float, 1: string}> $args
     * @return array{0: float, 1: string}|null
     */
    private function apply(string $function, array $args): ?array
    {
        $kind = $args[0][1];
        foreach ($args as $arg) {
            if ($arg[1] !== $kind) {
                return null;
            }
        }
        $values = array_map(static fn (array $arg): float => $arg[0], $args);
        if ($values === []) {
            return null;
        }
        switch ($function) {
            case 'calc':
                return count($args) === 1 ? $args[0] : null;
            case 'min':
                return [min($values), $kind];
            case 'max':
                return [max($values), $kind];
            case 'clamp':
                return count($values) === 3 ? [max($values[0], min($values[1], $values[2])), $kind] : null;
        }

        return null;
    }

    /**
     * @return array{0: float, 1: string}|null
     */
    private function number(string $token): ?array
    {
        if (preg_match('/^(\d+(?:\.\d+)?|\.\d+)(px|vw|em|rem)?$/i', $token, $m) !== 1) {
            return null;
        }
        $value = (float) $m[1];
        $unit = strtolower($m[2] ?? '');
        switch ($unit) {
            case '':
                return [$value, 'num'];
            case 'px':
                return [$value, 'len'];
            case 'vw':
                return [$value * $this->viewport / 100.0, 'len'];
            default:
                return [$value * self::ROOT_FONT_PX, 'len'];
        }
    }

    /**
     * @param list<string> $ops
     */
    private function peekOp(array $ops): bool
    {
        $token = $this->tokens[$this->pos] ?? null;

        return $token !== null && $token[0] === 'op' && in_array($token[1], $ops, true);
    }
}
