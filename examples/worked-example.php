<?php

/**
 * Runnable without WordPress: compiles a card spec and shows which srcset
 * candidate a 412 px phone at DPR 2 downloads before and after.
 *
 *   php examples/worked-example.php
 */

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use IndianOpinion\SrcsetTruth\Audit\Picker;
use IndianOpinion\SrcsetTruth\Markup\Srcset;
use IndianOpinion\SrcsetTruth\Sizes\Compiler;
use IndianOpinion\SrcsetTruth\Sizes\CoreDefault;
use IndianOpinion\SrcsetTruth\Sizes\Evaluator;

$candidates = Srcset::parse('photo-1024x576.jpg 1024w, photo-300x169.jpg 300w, photo-768x432.jpg 768w');
$viewport = 412.0;
$dpr = 2.0;

$before = CoreDefault::build(1024);
$after = Compiler::compile([[767, '92vw'], [1024, '46vw'], [null, '380px']], 1024);

foreach (['before' => $before, 'after' => $after] as $label => $sizes) {
    $slot = (float) Evaluator::slotWidth($sizes, $viewport);
    $pick = Picker::pick($candidates, $slot, $dpr);
    printf(
        "%-6s sizes=\"%s\"\n       slot %.1f px, needs %d device px, downloads %s\n",
        $label,
        $sizes,
        $slot,
        (int) ceil($slot * $dpr),
        $pick === null ? 'nothing' : $candidates[$pick]['url']
    );
}
