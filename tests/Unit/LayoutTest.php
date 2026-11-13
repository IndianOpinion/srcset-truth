<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Tests\Unit;

use IndianOpinion\SrcsetTruth\Correction\Corrector;
use IndianOpinion\SrcsetTruth\Exception\InvalidSlotName;
use IndianOpinion\SrcsetTruth\Layout\Presets;
use IndianOpinion\SrcsetTruth\Layout\SlotResolver;
use IndianOpinion\SrcsetTruth\Layout\SlotStack;
use IndianOpinion\SrcsetTruth\Layout\TemplateResolver;
use IndianOpinion\SrcsetTruth\Layout\WidthMap;
use IndianOpinion\SrcsetTruth\Plugin;
use IndianOpinion\SrcsetTruth\Tests\TestCase;

use function IndianOpinion\SrcsetTruth\current_slot;
use function IndianOpinion\SrcsetTruth\pop_slot;
use function IndianOpinion\SrcsetTruth\push_slot;
use function IndianOpinion\SrcsetTruth\with_slot;

/**
 * PRD 4.2 to 4.4: acceptance tests 11 to 18, and the grid preset.
 */
final class LayoutTest extends TestCase
{
    /** @return array<string, array{0: list<string>, 1: string}> */
    public static function conditionals(): array
    {
        return [
            'front' => [['front_page', 'home'], 'front'],
            'home' => [['home'], 'home'],
            'single' => [['singular'], 'single-post'],
            'page' => [['page', 'singular'], 'page'],
            'attachment' => [['attachment', 'singular'], 'attachment'],
            'category' => [['category'], 'taxonomy-category'],
            'tag' => [['tag'], 'taxonomy-post_tag'],
            'author' => [['author'], 'author'],
            'date' => [['date'], 'date'],
            'search' => [['search'], 'search'],
            '404' => [['404'], '404'],
            'nothing' => [[], 'default'],
        ];
    }

    /**
     * @dataProvider conditionals
     * @param list<string> $flags
     */
    public function testT11ConditionalsMapToTemplateKeys(array $flags, string $expected): void
    {
        foreach ($flags as $flag) {
            $this->on($flag);
        }
        self::assertSame($expected, TemplateResolver::current());
    }

    public function testT11CustomTaxonomyAndPostTypeArchive(): void
    {
        $this->on('tax');
        \WPStub::$queriedObject = (object) ['taxonomy' => 'region'];
        self::assertSame('taxonomy-region', TemplateResolver::compute());
        \WPStub::$conditionals = ['post_type_archive' => true];
        \WPStub::$queryVars['post_type'] = 'event';
        self::assertSame('archive-event', TemplateResolver::compute());
        \WPStub::$conditionals = ['singular' => true];
        \WPStub::$postType = 'event';
        self::assertSame('single-event', TemplateResolver::compute());
    }

    public function testT14BeforeMainQueryKeyIsDefault(): void
    {
        \WPStub::$actionsDone = [];
        $this->on('front_page');
        self::assertSame('default', TemplateResolver::current());
        \WPStub::$actionsDone['wp'] = 1;
        self::assertSame('front', TemplateResolver::current());
    }

    public function testT12FallbackChains(): void
    {
        self::assertSame(['single-post', 'single', 'default'], TemplateResolver::chain('single-post'));
        self::assertSame(['taxonomy-category', 'taxonomy', 'archive', 'default'], TemplateResolver::chain('taxonomy-category'));
        self::assertSame(['archive-event', 'archive', 'default'], TemplateResolver::chain('archive-event'));
        self::assertSame(['search', 'default'], TemplateResolver::chain('search'));

        $this->filter('srcset_truth_width_map', static fn (): array => [
            'default' => ['card' => '300px'],
            'single' => ['card' => '400px'],
            'archive' => ['card' => '500px'],
        ]);
        self::assertSame('400px', WidthMap::lookup('single-post', 'card')['spec'] ?? null);
        self::assertSame('500px', WidthMap::lookup('taxonomy-category', 'card')['spec'] ?? null);
        self::assertSame('300px', WidthMap::lookup('search', 'card')['spec'] ?? null);
        self::assertNull(WidthMap::lookup('search', 'hero'));
    }

    public function testT13TemplateFilterOverridesAndEmptyFallsBack(): void
    {
        $this->on('page');
        $this->filter('srcset_truth_template', static fn (): string => 'landing');
        self::assertSame('landing', TemplateResolver::current());
        Plugin::resetRequest();
        remove_all_filters('srcset_truth_template');
        $this->filter('srcset_truth_template', static fn (): string => '');
        self::assertSame('page', TemplateResolver::current());
        Plugin::resetRequest();
        remove_all_filters('srcset_truth_template');
        $this->filter('srcset_truth_template', static fn (): int => 5);
        self::assertSame('page', TemplateResolver::current());
    }

    public function testT14NestedWithSlotRestoresOuterAlsoOnThrow(): void
    {
        $seen = with_slot('grid', function (): array {
            $inner = with_slot('card', static fn (): ?string => current_slot());
            try {
                with_slot('hero', static function (): void {
                    throw new \RuntimeException('boom');
                });
            } catch (\RuntimeException) {
            }

            return [$inner, current_slot()];
        });
        self::assertSame(['card', 'grid'], $seen);
        self::assertNull(current_slot());
        $this->expectException(InvalidSlotName::class);
        push_slot('Bad Slot!');
    }

    public function testT15SlotRules(): void
    {
        $rules = [
            ['slot' => 'hero', 'min_width' => 1200],
            ['slot' => 'thumb', 'class' => ['is-thumb', 'mini'], 'max_width' => 300],
            ['slot' => 'card', 'size' => ['medium', 'large']],
        ];
        $this->filter('srcset_truth_slot_rules', static fn (): array => $rules);
        self::assertSame('hero', SlotResolver::resolve(['image_width' => 1600, 'size' => 'large']));
        self::assertSame('thumb', SlotResolver::resolve(['image_width' => 150, 'class' => 'x mini']));
        // Class matches but width bound fails: all conditions must hold.
        self::assertNull(SlotResolver::resolve(['image_width' => 400, 'class' => 'is-thumb']));
        self::assertSame('card', SlotResolver::resolve(['image_width' => 1024, 'size' => 'large']));
        self::assertNull(SlotResolver::resolve(['image_width' => 1024, 'size' => 'full']));
    }

    public function testT15ExplicitSlotBeatsRules(): void
    {
        $this->filter('srcset_truth_slot_rules', static fn (): array => [['slot' => 'card']]);
        push_slot('hero');
        self::assertSame('hero', SlotResolver::resolve([]));
        pop_slot();
        self::assertSame('card', SlotResolver::resolve([]));
    }

    public function testT16SlotFilterIsConsultedAfterRules(): void
    {
        $this->filter('srcset_truth_slot', static fn ($slot, array $ctx): ?string => ($ctx['source'] ?? '') === 'thumbnail' ? 'thumb' : null, 2);
        self::assertSame('thumb', SlotResolver::resolve(['source' => 'thumbnail']));
        self::assertNull(SlotResolver::resolve(['source' => 'content']));
        Plugin::resetRequest();
        $this->filter('srcset_truth_slot_rules', static fn (): array => [['slot' => 'card']]);
        self::assertSame('card', SlotResolver::resolve(['source' => 'thumbnail']));
    }

    public function testT17UnknownSlotIsUnmappedAndLoggedOnce(): void
    {
        $this->settings(['debug' => true]);
        $this->filter('srcset_truth_width_map', static fn (): array => ['default' => ['card' => '300px']]);
        $this->filter('srcset_truth_slot_rules', static fn (): array => [['slot' => 'gallery']]);
        for ($i = 0; $i < 3; $i++) {
            $r = Corrector::correct('(max-width: 1024px) 100vw, 1024px', ['template' => 'default', 'image_width' => 1024], false, true);
            self::assertSame('unmapped', $r['status']);
        }
        self::assertCount(1, $this->logged);
        self::assertStringContainsString('Unknown slot "gallery"', $this->logged[0]);
    }

    public function testT18UnbalancedPushIsClosedAtShutdownAndLogged(): void
    {
        $this->settings(['debug' => true]);
        push_slot('card');
        SlotStack::closeAll();
        self::assertNull(current_slot());
        self::assertStringContainsString('Unbalanced push_slot(): card', $this->logged[0] ?? '');
        pop_slot();
        self::assertStringContainsString('pop_slot() called with no slot pushed', $this->logged[1] ?? '');
    }

    public function testGridPresetUsesContentWidthOrTwelveHundred(): void
    {
        self::assertSame(1200, Presets::contentWidth());
        $GLOBALS['content_width'] = 760;
        self::assertSame(760, Presets::contentWidth());
        $map = Presets::widthMap('grid');
        self::assertSame('(max-width: 760px) 100vw, 760px', $map['single']['content']);
        self::assertStringEndsWith('380px', (string) $map['default']['card']);
        self::assertSame([], Presets::widthMap('none'));
    }

    public function testGridPresetEndToEnd(): void
    {
        $this->settings(['preset' => 'grid']);
        $hero = Corrector::correct('(max-width: 2048px) 100vw, 2048px', ['template' => 'front', 'image_width' => 2048, 'source' => 'thumbnail'], false, true);
        self::assertSame('(max-width: 767px) 100vw, (max-width: 1200px) 90vw, 1200px', $hero['sizes']);
        $card = Corrector::correct('(max-width: 768px) 100vw, 768px', ['template' => 'front', 'image_width' => 768, 'source' => 'thumbnail'], false, true);
        self::assertSame('(max-width: 767px) 92vw, (max-width: 1024px) 46vw, 380px', $card['sizes']);
        $context = ['template' => 'single-post', 'image_width' => 1024, 'source' => 'content'];
        $content = Corrector::correct('(max-width: 1024px) 100vw, 1024px', $context, false, true);
        // Content width 1200, capped at the image's own 1024.
        self::assertSame('(max-width: 1200px) 100vw, 1024px', $content['sizes']);
        self::assertSame('content', $content['context']['slot']);
    }
}
