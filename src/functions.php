<?php

/**
 * Public slot API (PRD 5.2). Functions live in the plugin namespace.
 */

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth;

use IndianOpinion\SrcsetTruth\Exception\InvalidSizesSpec;
use IndianOpinion\SrcsetTruth\Exception\InvalidSlotName;
use IndianOpinion\SrcsetTruth\Files\Siblings;
use IndianOpinion\SrcsetTruth\Layout\SlotStack;
use IndianOpinion\SrcsetTruth\Markup\WebpPlanner;
use IndianOpinion\SrcsetTruth\Sizes\Compiler;

if (!function_exists(__NAMESPACE__ . '\\with_slot')) {
    /**
     * Push a slot, run $render, pop the slot even if $render throws.
     *
     * @template T
     * @param callable(): T $render
     * @return T
     * @throws InvalidSlotName
     */
    function with_slot(string $slot, callable $render): mixed
    {
        SlotStack::push($slot);
        try {
            return $render();
        } finally {
            SlotStack::pop();
        }
    }

    /**
     * @throws InvalidSlotName
     */
    function push_slot(string $slot): void
    {
        SlotStack::push($slot);
    }

    function pop_slot(): void
    {
        SlotStack::pop();
    }

    function current_slot(): ?string
    {
        return SlotStack::current();
    }

    function sibling_path(string $original): string
    {
        return Siblings::path($original);
    }

    /**
     * The sibling URL when a fresh sibling exists, else null.
     */
    function sibling_url(string $original_url): ?string
    {
        return WebpPlanner::siblingFor($original_url);
    }

    /**
     * Compile and validate a sizes spec.
     *
     * @param array<mixed>|string $spec
     * @throws InvalidSizesSpec
     */
    function compile_sizes(array|string $spec, ?int $image_width = null): string
    {
        return Compiler::compile($spec, $image_width);
    }
}
