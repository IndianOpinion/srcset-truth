<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Layout;

use IndianOpinion\SrcsetTruth\Exception\InvalidSlotName;
use IndianOpinion\SrcsetTruth\Logger;

/**
 * Explicit slot context set by the theme (PRD 15a, 17, 5.2). The innermost
 * slot wins.
 */
final class SlotStack
{
    /** @var list<string> */
    private static array $stack = [];

    public static function validate(string $slot): string
    {
        if (preg_match('/^[a-z0-9-]{1,40}$/', $slot) !== 1) {
            throw new InvalidSlotName(sprintf(
                'Invalid slot name "%s": use 1 to 40 lower case letters, digits or hyphens.',
                substr((string) preg_replace('/[^\x20-\x7e]/', '?', $slot), 0, 60)
            ));
        }

        return $slot;
    }

    public static function isValid(string $slot): bool
    {
        return preg_match('/^[a-z0-9-]{1,40}$/', $slot) === 1;
    }

    public static function push(string $slot): void
    {
        self::$stack[] = self::validate($slot);
    }

    public static function pop(): void
    {
        if (self::$stack === []) {
            Logger::log('pop_slot() called with no slot pushed.');

            return;
        }
        array_pop(self::$stack);
    }

    public static function current(): ?string
    {
        $count = count(self::$stack);

        return $count === 0 ? null : self::$stack[$count - 1];
    }

    public static function depth(): int
    {
        return count(self::$stack);
    }

    /**
     * Runs at shutdown: closes anything left open and says so in debug mode.
     */
    public static function closeAll(): void
    {
        if (self::$stack !== []) {
            Logger::log(sprintf('Unbalanced push_slot(): %s still open at the end of the request.', implode(' > ', self::$stack)));
        }
        self::$stack = [];
    }
}
