<?php

/**
 * Naming the slot where a template renders. The innermost slot wins.
 */

declare(strict_types=1);

use function IndianOpinion\SrcsetTruth\pop_slot;
use function IndianOpinion\SrcsetTruth\push_slot;
use function IndianOpinion\SrcsetTruth\with_slot;

// Wrap a callable: the slot is popped even if rendering throws.
echo with_slot('hero', static fn (): string => (string) get_the_post_thumbnail(null, 'full'));

// Or bracket a template part that echoes.
push_slot('card');
get_template_part('parts/card');
pop_slot();
