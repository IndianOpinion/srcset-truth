<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Tests\Integration;

use IndianOpinion\SrcsetTruth\Markup\BufferProcessor;
use IndianOpinion\SrcsetTruth\Tests\TestCase;

/**
 * PRD 4.9: acceptance tests 53 to 59.
 */
final class LcpTest extends TestCase
{
    private string $logo;
    private string $thumb;
    private string $photo;
    private string $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings(['mode' => 'buffer', 'lcp' => true]);
        $u = 'https://example.test/wp-content/uploads/2026/11/';
        $this->logo = '<img src="' . $u . 'site-logo.png" width="700" height="135" class="custom-logo" alt="Site" loading="lazy">';
        $this->thumb = '<img src="' . $u . 'ticker-150x150.jpg" width="150" height="150" loading="lazy">';
        $this->photo = '<img src="' . $u . 'photo-1024x576.jpg" srcset="' . $u . 'photo-1024x576.jpg 1024w, ' . $u . 'photo-768x432.jpg 768w" sizes="auto, (max-width: 1024px) 100vw, 1024px" width="1024" height="576" loading="lazy" loading="lazy" decoding="sync" alt="News photo">';
        $this->second = '<img src="' . $u . 'other-1024x576.jpg" width="1024" height="576" fetchpriority="high">';
    }

    private function page(string $body, bool $head = true): string
    {
        return $head ? "<html><head><title>t</title></head><body>{$body}</body></html>" : "<body>{$body}</body>";
    }

    /**
     * @return list<string>
     */
    private function imgs(string $html): array
    {
        preg_match_all('/<img[^>]*>/i', $html, $m);

        return $m[0];
    }

    public function testT53PicksFirstPhotoSkippingLogoAndThumb(): void
    {
        $out = BufferProcessor::process($this->page($this->logo . $this->thumb . $this->photo));
        $imgs = $this->imgs($out);
        self::assertStringContainsString('fetchpriority="high"', $imgs[2]);
        self::assertStringNotContainsString('fetchpriority', $imgs[0]);
    }

    public function testT53FilterCanForceOrVeto(): void
    {
        $this->filter('srcset_truth_lcp_candidate', static fn ($v, array $a, int $i): ?bool => $i === 1 ? true : null, 3);
        $imgs = $this->imgs(BufferProcessor::process($this->page($this->logo . $this->thumb . $this->photo)));
        self::assertStringContainsString('fetchpriority="high"', $imgs[1]);
        remove_all_filters('srcset_truth_lcp_candidate');
        $this->filter('srcset_truth_lcp_candidate', static fn ($v, array $a, int $i): ?bool => $i === 2 ? false : null, 3);
        $page = $this->page($this->logo . $this->thumb . $this->photo);
        self::assertSame($page, BufferProcessor::process($page), 'vetoed and nothing else qualifies');
    }

    public function testT54ChosenEagerOthersLazyDuplicatesCollapsed(): void
    {
        $imgs = $this->imgs(BufferProcessor::process($this->page($this->logo . $this->photo . $this->second)));
        self::assertStringContainsString('fetchpriority="high"', $imgs[1]);
        self::assertStringContainsString('decoding="async"', $imgs[1]);
        self::assertStringNotContainsString('loading=', $imgs[1]);
        self::assertSame(0, substr_count($imgs[1], 'decoding="sync"'));
        // auto goes with eager (case 6).
        self::assertStringContainsString('sizes="(max-width: 1024px) 100vw, 1024px"', $imgs[1]);
        foreach ([$imgs[0], $imgs[2]] as $img) {
            self::assertSame(1, substr_count($img, 'loading='));
            self::assertStringContainsString('loading="lazy"', $img);
            self::assertStringContainsString('decoding="async"', $img);
        }
    }

    public function testT55SecondHighPriorityIsDowngraded(): void
    {
        $out = BufferProcessor::process($this->page($this->photo . $this->second));
        self::assertSame(1, substr_count(implode('', $this->imgs($out)), 'fetchpriority="high"'), $out);
    }

    public function testT56PreloadMirrorsChosenImage(): void
    {
        $out = BufferProcessor::process($this->page($this->logo . $this->photo));
        self::assertMatchesRegularExpression('#<link rel="preload" as="image" fetchpriority="high" href="[^"]+photo-1024x576\.jpg" imagesrcset="[^"]+1024w, [^"]+768w" imagesizes="\(max-width: 1024px\) 100vw, 1024px">\n</head>#', $out);

        // Wrapped: WebP list and type.
        $this->filter('srcset_truth_picture_wrap', static fn (): bool => true);
        $this->jpeg('2026/11/photo-1024x576.jpg');
        $this->fakeSibling($this->uploads . '/2026/11/photo-1024x576.jpg');
        \IndianOpinion\SrcsetTruth\Plugin::resetRequest();
        $out = BufferProcessor::process($this->page($this->photo));
        self::assertMatchesRegularExpression('#<link [^>]*href="[^"]+photo-1024x576\.jpg\.webp" imagesrcset="[^"]+photo-1024x576\.jpg\.webp 1024w, [^"]+768x432\.jpg 768w"[^>]* type="image/webp">#', $out);

        $existing = '<link rel="preload" as="image" href="/hero.jpg">';
        $out = BufferProcessor::process("<html><head>{$existing}</head><body>{$this->photo}</body></html>");
        self::assertSame(1, substr_count($out, 'rel="preload"'));
        $noHead = BufferProcessor::process($this->page($this->photo, false));
        self::assertStringNotContainsString('preload', $noHead);
        self::assertStringContainsString('fetchpriority="high"', $noHead);
    }

    public function testT57NoQualifyingImageNoChanges(): void
    {
        $page = $this->page($this->logo . $this->thumb);
        self::assertSame($page, BufferProcessor::process($page));
    }

    public function testT58MissingDimensionsFromLocalFileOnly(): void
    {
        $this->jpeg('2026/11/nodims.jpg', 160, 90);
        $local = '<img src="https://example.test/wp-content/uploads/2026/11/nodims.jpg" alt="a">';
        $remote = '<img src="https://elsewhere.test/x.jpg" alt="b">';
        $imgs = $this->imgs(BufferProcessor::process($this->page($this->photo . $local . $remote)));
        self::assertStringContainsString('width="160"', $imgs[1]);
        self::assertStringContainsString('height="90"', $imgs[1]);
        self::assertStringNotContainsString('width=', $imgs[2]);
    }

    public function testT59LcpOffLeavesLoadingAlone(): void
    {
        $this->settings(['mode' => 'buffer', 'lcp' => false]);
        $photo = str_replace('sizes="auto, ', 'sizes="', $this->photo);
        $page = $this->page($this->logo . $photo . $this->second);
        self::assertSame($page, BufferProcessor::process($page));
    }

    public function testLcpNeedsBufferMode(): void
    {
        $this->settings(['mode' => 'filter', 'lcp' => true]);
        self::assertFalse(\IndianOpinion\SrcsetTruth\Plugin::lcpEnabled());
    }
}
