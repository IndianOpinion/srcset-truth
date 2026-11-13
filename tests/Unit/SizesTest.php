<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Tests\Unit;

use IndianOpinion\SrcsetTruth\Correction\Corrector;
use IndianOpinion\SrcsetTruth\Exception\InvalidSizesSpec;
use IndianOpinion\SrcsetTruth\Markup\BufferProcessor;
use IndianOpinion\SrcsetTruth\Markup\FilterMode;
use IndianOpinion\SrcsetTruth\Sizes\Compiler;
use IndianOpinion\SrcsetTruth\Sizes\CoreDefault;
use IndianOpinion\SrcsetTruth\Sizes\Grammar;
use IndianOpinion\SrcsetTruth\Tests\TestCase;

use function IndianOpinion\SrcsetTruth\compile_sizes;

/**
 * PRD 4.1, 4.4 and 4.5: acceptance tests 1 to 10 and 19 to 22.
 */
final class SizesTest extends TestCase
{
    private const CARD = '(max-width: 767px) 92vw, (max-width: 1024px) 46vw, 380px';

    /** @return array<string, array{0: string}> */
    public static function coreDefaults(): array
    {
        return [
            'plain' => ['(max-width: 1024px) 100vw, 1024px'],
            'auto' => ['auto, (max-width: 1024px) 100vw, 1024px'],
            'spaces' => ['  auto ,( max-width : 300px )  100vw ,  300px '],
            'upper' => ['(MAX-WIDTH: 768PX) 100VW, 768PX'],
        ];
    }

    /** @dataProvider coreDefaults */
    public function testT1CoreDefaultIsDetected(string $value): void
    {
        self::assertTrue(CoreDefault::is($value));
    }

    public function testT1NearMissesAreNotCoreDefault(): void
    {
        self::assertFalse(CoreDefault::is('(max-width: 1024px) 100vw, 768px'));
        self::assertFalse(CoreDefault::is('(max-width: 1024px) 50vw, 1024px'));
        self::assertFalse(CoreDefault::is(self::CARD));
    }

    private function ctx(int $width = 1024): array
    {
        return ['template' => 'default', 'size' => 'large', 'image_width' => $width, 'source' => 'content', 'class' => ''];
    }

    public function testT2AuthoredValueIsLeftAloneUnlessOverridden(): void
    {
        $this->cardMap();
        $authored = '(max-width: 600px) 50vw, 300px';
        $result = Corrector::correct($authored, $this->ctx(), false, true);
        self::assertSame($authored, $result['sizes']);
        self::assertSame('authored', $result['status']);

        $this->settings(['override' => true]);
        $this->cardMap();
        self::assertSame(self::CARD, Corrector::correct($authored, $this->ctx(), false, true)['sizes']);

        $this->settings([]);
        $this->cardMap();
        $this->filter('srcset_truth_should_correct', static fn (bool $v, string $s): bool => $s === '(max-width: 600px) 50vw, 300px', 2);
        self::assertSame(self::CARD, Corrector::correct($authored, $this->ctx(), false, true)['sizes']);
    }

    public function testT3FrontCardMapEntryReplacesCoreValue(): void
    {
        $this->on('front_page');
        $this->cardMap();
        $out = FilterMode::contentImage($this->cardTag(), 'the_content', 7);
        self::assertStringContainsString('sizes="auto, ' . self::CARD . '"', $out);
    }

    public function testT4NoSlotMatchKeepsCoreValueAndCountsUnmapped(): void
    {
        $result = Corrector::correct('(max-width: 1024px) 100vw, 1024px', $this->ctx(), false, true);
        self::assertSame('(max-width: 1024px) 100vw, 1024px', $result['sizes']);
        self::assertSame('unmapped', $result['status']);
        self::assertSame(1, Corrector::stats()['unmapped']);
    }

    public function testT5ImageWithoutSrcsetKeepsItsAttributes(): void
    {
        $this->cardMap();
        $tag = '<img src="https://example.test/x.jpg" sizes="(max-width: 1024px) 100vw, 1024px" class="size-large">';
        self::assertSame($tag, FilterMode::contentImage($tag, '', 0));
    }

    public function testT6LazyKeepsAutoAndEagerLosesIt(): void
    {
        $this->cardMap();
        $lazy = Corrector::correct('auto, (max-width: 1024px) 100vw, 1024px', $this->ctx(), true, true);
        self::assertSame('auto, ' . self::CARD, $lazy['sizes']);
        $eager = Corrector::correct('auto, (max-width: 1024px) 100vw, 1024px', $this->ctx(), false, true);
        self::assertSame(self::CARD, $eager['sizes']);
        // Case 64: core made the image eager, no map: only auto goes.
        self::assertSame('(max-width: 1024px) 100vw, 1024px', Corrector::autoFix('auto, (max-width: 1024px) 100vw, 1024px', false));
    }

    public function testT7SpecWiderThanImageIsCapped(): void
    {
        self::assertSame('(max-width: 767px) 92vw, (max-width: 1024px) 46vw, 300px', Compiler::compile(self::CARD, 300));
        self::assertSame('(max-width: 767px) 100vw, min(90vw, 600px)', Compiler::compile('(max-width: 767px) 100vw, 90vw', 600));
        self::assertSame(self::CARD, Compiler::compile(self::CARD, 1024));
    }

    public function testT8FilterThenBufferChangesTagOnce(): void
    {
        $this->cardMap();
        $once = FilterMode::contentImage($this->cardTag(), '', 7);
        $page = "<html><head></head><body>{$once}</body></html>";
        self::assertSame($page, BufferProcessor::process($page));
    }

    public function testT9BufferCorrectsHardCodedTagFromWidthAttribute(): void
    {
        $this->filter('srcset_truth_width_map', static fn (): array => ['default' => ['card' => self::CARD]]);
        $this->filter('srcset_truth_slot_rules', static fn (): array => [['slot' => 'card', 'class' => 'card-img']]);
        $tag = '<img src="/a.jpg" srcset="/a-300.jpg 300w, /a.jpg 1024w" sizes="100vw" width="300" class="card-img">';
        $out = BufferProcessor::process("<body>{$tag}</body>");
        // "100vw" is authored, so it stays; the core default is corrected and capped at width 300.
        self::assertSame("<body>{$tag}</body>", $out);
        $core = str_replace('sizes="100vw"', 'sizes="(max-width: 300px) 100vw, 300px"', $tag);
        $out = BufferProcessor::process("<body>{$core}</body>");
        self::assertStringContainsString('sizes="(max-width: 767px) 92vw, (max-width: 1024px) 46vw, 300px"', $out);
    }

    public function testT10AllThreePathsAgree(): void
    {
        $this->cardMap();
        \WPStub::$attachmentMeta[7] = [
            'file' => '2026/11/photo.jpg',
            'width' => 2048,
            'height' => 1152,
            'sizes' => ['large' => ['file' => 'photo-1024x576.jpg', 'width' => 1024, 'height' => 576]],
        ];
        $content = FilterMode::contentImage($this->cardTag(), '', 7);
        $thumb = FilterMode::thumbnail($this->cardTag(), 1, 7, 'large');
        $given = ['src' => 'x', 'srcset' => 'x 1024w', 'sizes' => 'auto, (max-width: 1024px) 100vw, 1024px', 'loading' => 'lazy'];
        $attr = FilterMode::attachmentAttributes($given, (object) ['ID' => 7], 'large');
        $expected = 'auto, ' . self::CARD;
        self::assertStringContainsString('sizes="' . $expected . '"', (string) $content);
        self::assertSame($content, $thumb);
        self::assertSame($expected, $attr['sizes']);
    }

    public function testT19BreakpointListsCompileSortedAndDeduplicated(): void
    {
        $spec = [[1024, '46vw'], [null, '380px'], [767, '50vw'], [767, '92vw']];
        self::assertSame(self::CARD, compile_sizes($spec));
        $this->filter('srcset_truth_width_map', static fn (): array => ['*' => ['card' => $spec]]);
        $this->filter('srcset_truth_slot_rules', static fn (): array => [['slot' => 'card']]);
        self::assertSame(self::CARD, Corrector::correct('(max-width: 1024px) 100vw, 1024px', $this->ctx(), false, true)['sizes']);
    }

    public function testT19BadListsThrow(): void
    {
        $this->expectException(InvalidSizesSpec::class);
        compile_sizes([[767, '92vw']]);
    }

    public function testT20CompilationIsCachedPerTemplateSlotWidth(): void
    {
        $this->cardMap();
        for ($i = 0; $i < 50; $i++) {
            Corrector::correct('(max-width: 1024px) 100vw, 1024px', $this->ctx(), false, true);
        }
        Corrector::correct('(max-width: 768px) 100vw, 768px', $this->ctx(768), false, true);
        self::assertSame(2, Corrector::stats()['compiles']);
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function grammar(): array
    {
        return [
            'card' => [self::CARD, true],
            'auto first' => ['auto, (max-width: 600px) 100vw, 50vw', true],
            'calc' => ['(min-width: 40em) calc(50vw - 2rem), 100vw', true],
            'min' => ['min(90vw, 1200px)', true],
            'and' => ['(min-width: 600px) and (max-width: 900px) 50vw, 100vw', true],
            'percent' => ['(max-width: 600px) 50%, 300px', false],
            'missing default' => ['(max-width: 600px) 50vw', false],
            'auto misplaced' => ['(max-width: 600px) 50vw, auto, 300px', false],
            'auto alone' => ['auto', false],
            'unbalanced calc' => ['calc(50vw - 10px, 300px', false],
            'quote' => ['300px"', false],
            'angle' => ['(max-width: 600px) 50vw, 300px<script>', false],
            'bad unit' => ['300pt', false],
            'empty' => ['', false],
        ];
    }

    /** @dataProvider grammar */
    public function testT21Grammar(string $value, bool $valid): void
    {
        self::assertSame($valid, Grammar::isValid($value), (string) Grammar::check($value));
    }

    public function testT22InvalidSpecKeepsCoreValueAndLogsOnce(): void
    {
        $this->settings(['debug' => true]);
        $this->filter('srcset_truth_width_map', static fn (): array => ['default' => ['card' => '50%']]);
        $this->filter('srcset_truth_slot_rules', static fn (): array => [['slot' => 'card']]);
        for ($i = 0; $i < 3; $i++) {
            $result = Corrector::correct('(max-width: 1024px) 100vw, 1024px', $this->ctx(), false, true);
            self::assertSame('(max-width: 1024px) 100vw, 1024px', $result['sizes']);
            self::assertSame('invalid-spec', $result['status']);
        }
        self::assertCount(1, $this->logged);
        self::assertStringContainsString('[srcset-truth] Template "default", slot "card"', $this->logged[0]);
    }

    public function testFilteredSizesAreValidatedAgain(): void
    {
        $this->cardMap();
        $this->filter('srcset_truth_sizes', static fn (): string => '50%');
        self::assertSame('(max-width: 1024px) 100vw, 1024px', Corrector::correct('(max-width: 1024px) 100vw, 1024px', $this->ctx(), false, true)['sizes']);
    }
}
