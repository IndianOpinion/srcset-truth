<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Tests\Integration;

use IndianOpinion\SrcsetTruth\Files\PathMapper;
use IndianOpinion\SrcsetTruth\Files\Siblings;
use IndianOpinion\SrcsetTruth\Markup\BufferProcessor;
use IndianOpinion\SrcsetTruth\Markup\FilterMode;
use IndianOpinion\SrcsetTruth\Markup\Srcset;
use IndianOpinion\SrcsetTruth\Plugin;
use IndianOpinion\SrcsetTruth\Tests\TestCase;

use function IndianOpinion\SrcsetTruth\sibling_path;
use function IndianOpinion\SrcsetTruth\sibling_url;

/**
 * PRD 4.8, 4.10, 4.11: acceptance tests 43 to 52, 60, 62 to 64, plus the
 * default type-aware srcset rewriting.
 */
final class ServingTest extends TestCase
{
    /** @var array<string, string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->files = $this->attachment(7);
    }

    private function siblings(string ...$sizes): void
    {
        foreach ($sizes as $size) {
            $this->fakeSibling($this->files[$size]);
        }
    }

    private function picture(): void
    {
        $this->filter('srcset_truth_picture_wrap', static fn (): bool => true);
    }

    public function testSiblingNaming(): void
    {
        self::assertSame('/u/photo.jpg.webp', sibling_path('/u/photo.jpg'));
        self::assertSame('https://x/photo.png.webp?ver=2', Siblings::url('https://x/photo.png?ver=2'));
        self::assertNull(sibling_url($this->url('2026/11/photo-768x432.jpg')));
        $this->siblings('medium_large');
        Plugin::resetRequest();
        self::assertSame($this->url('2026/11/photo-768x432.jpg.webp'), sibling_url($this->url('2026/11/photo-768x432.jpg')));
    }

    public function testDefaultRewritesCandidatesInPlace(): void
    {
        $this->siblings('large', 'medium_large', 'medium');
        $out = (string) FilterMode::contentImage($this->cardTag(), '', 7);
        self::assertStringNotContainsString('<picture', $out);
        self::assertStringContainsString('src="' . $this->url('2026/11/photo-1024x576.jpg.webp') . '"', $out);
        self::assertStringContainsString('photo-1024x576.jpg.webp 1024w, ' . $this->url('2026/11/photo-300x169.jpg.webp') . ' 300w', $out);
    }

    public function testT43WrappedMarkupKeepsTheImgUntouched(): void
    {
        $this->picture();
        $this->siblings('large', 'medium_large', 'medium');
        $tag = $this->cardTag();
        $out = (string) FilterMode::contentImage($tag, '', 7);
        self::assertStringStartsWith('<picture class="srcset-truth"><source type="image/webp" srcset="', $out);
        self::assertStringEndsWith($tag . '</picture>', $out);
    }

    public function testT44MixedCoverageKeepsOriginalsInPosition(): void
    {
        $this->picture();
        $this->siblings('large', 'medium');
        $out = (string) FilterMode::contentImage($this->cardTag(), '', 7);
        preg_match('/<source[^>]*srcset="([^"]+)"/', $out, $m);
        $list = Srcset::parse(html_entity_decode($m[1]));
        self::assertSame(['1024w', '300w', '768w'], array_column($list, 'descriptor'));
        self::assertStringEndsWith('photo-1024x576.jpg.webp', $list[0]['url']);
        self::assertStringEndsWith('photo-300x169.jpg.webp', $list[1]['url']);
        self::assertStringEndsWith('photo-768x432.jpg', $list[2]['url']);
    }

    public function testT45NoCoverageNoWrapAndSingleSrc(): void
    {
        $this->picture();
        $tag = $this->cardTag();
        self::assertSame($tag, FilterMode::contentImage($tag, '', 7));
        $this->siblings('main');
        $single = '<img src="' . $this->url('2026/11/photo.jpg') . '" alt="">';
        $out = (string) FilterMode::contentImage($single, '', 0);
        self::assertSame('<picture class="srcset-truth"><source type="image/webp" srcset="' . $this->url('2026/11/photo.jpg.webp') . '">' . $single . '</picture>', $out);
    }

    public function testT46SourceCarriesCorrectedSizes(): void
    {
        $this->picture();
        $this->cardMap();
        $this->siblings('large');
        $out = (string) FilterMode::contentImage($this->cardTag(), '', 7);
        $sizes = 'auto, (max-width: 767px) 92vw, (max-width: 1024px) 46vw, 380px';
        self::assertStringContainsString('<source type="image/webp" srcset="' . $this->url('2026/11/photo-1024x576.jpg.webp') . ' 1024w', $out);
        self::assertSame(2, substr_count($out, 'sizes="' . $sizes . '"'));
    }

    /** @return array<string, array{0: string}> */
    public static function skips(): array
    {
        $u = 'https://example.test/wp-content/uploads/2026/11/';

        return [
            'a picture' => ['<picture><source srcset="' . $u . 'photo.jpg.webp"><img src="' . $u . 'photo-1024x576.jpg" srcset="' . $u . 'photo-1024x576.jpg 1024w"></picture>'],
            'b data uri' => ['<img src="data:image/gif;base64,R0lGOD" srcset="' . $u . 'photo-1024x576.jpg 1024w">'],
            'b svg' => ['<img src="' . $u . 'logo.svg">'],
            'b webp' => ['<img src="' . $u . 'photo.webp">'],
            'c other host' => ['<img src="https://cdn.other.test/2026/11/photo-1024x576.jpg">'],
            'c resize query' => ['<img src="' . $u . 'photo-1024x576.jpg?w=300">'],
            'd noscript' => ['<noscript><img src="' . $u . 'photo-1024x576.jpg"></noscript>'],
            'd template' => ['<template><img src="' . $u . 'photo-1024x576.jpg"></template>'],
            'd textarea' => ['<textarea><img src="' . $u . 'photo-1024x576.jpg"></textarea>'],
            'd comment' => ['<!-- <img src="' . $u . 'photo-1024x576.jpg"> -->'],
            'e lazy loader' => ['<img src="data:image/gif;base64,R0" data-src="' . $u . 'photo-1024x576.jpg" class="lazyload">'],
            'e lazy loader real src' => ['<img src="' . $u . 'photo-1024x576.jpg" data-srcset="' . $u . 'photo-1024x576.jpg 1024w">'],
            'f skip class' => ['<img src="' . $u . 'photo-1024x576.jpg" class="no-webp">'],
        ];
    }

    /** @dataProvider skips */
    public function testT47SkipRulesLeaveTagsByteIdentical(string $html): void
    {
        $this->siblings('large', 'main');
        $this->filter('srcset_truth_skip_classes', static fn (): array => ['no-webp']);
        $page = '<html><head></head><body>' . $html . '</body></html>';
        self::assertSame($page, BufferProcessor::process($page));
        $this->picture();
        Plugin::resetRequest();
        self::assertSame($page, BufferProcessor::process($page));
    }

    public function testT47ShouldWrapFilterAndNonHtmlRequests(): void
    {
        $this->siblings('large', 'medium_large', 'medium');
        $this->filter('srcset_truth_should_wrap', static fn (): bool => false);
        self::assertSame($this->cardTag(), FilterMode::contentImage($this->cardTag(), '', 7));
        remove_all_filters('srcset_truth_should_wrap');
        foreach (['feed', 'embed', 'robots'] as $flag) {
            \WPStub::$conditionals = [$flag => true];
            self::assertSame($this->cardTag(), FilterMode::contentImage($this->cardTag(), '', 7), $flag);
        }
        \WPStub::$conditionals = [];
        \WPStub::$queryVars['sitemap'] = 'posts';
        self::assertSame($this->cardTag(), FilterMode::contentImage($this->cardTag(), '', 7));
        \WPStub::$queryVars = [];
        \WPStub::$isAdmin = true;
        self::assertSame($this->cardTag(), FilterMode::contentImage($this->cardTag(), '', 7));
        \WPStub::$isAdmin = false;
        \WPStub::$doingAjax = true;
        self::assertSame($this->cardTag(), FilterMode::contentImage($this->cardTag(), '', 7));
        \WPStub::$doingAjax = false;
        self::assertNotSame($this->cardTag(), FilterMode::contentImage($this->cardTag(), '', 7));
    }

    public function testT48UrlMapping(): void
    {
        $path = $this->uploads . '/2026/11/photo.jpg';
        self::assertSame($path, PathMapper::urlToPath('https://example.test/wp-content/uploads/2026/11/photo.jpg'));
        self::assertSame($path, PathMapper::urlToPath('http://example.test/wp-content/uploads/2026/11/photo.jpg'));
        self::assertSame($path, PathMapper::urlToPath('//example.test/wp-content/uploads/2026/11/photo.jpg'));
        self::assertSame($path, PathMapper::urlToPath('https://example.test/wp-content/uploads/2026/11/photo.jpg?ver=6.4'));
        self::assertNull(PathMapper::urlToPath('https://example.test/wp-content/uploads/2026/11/photo.jpg?resize=300,200'));
        self::assertNull(PathMapper::urlToPath('https://cdn.test/wp-content/uploads/2026/11/photo.jpg'));
        self::assertNull(PathMapper::urlToPath('https://example.test/wp-content/uploads/../wp-config.php'));
        self::assertNull(PathMapper::urlToPath('https://example.test/wp-content/uploads/2026/%2e%2e/%2e%2e/x.jpg'));
        $this->filter('srcset_truth_url_to_path', static function ($default, string $url) use ($path): ?string {
            return str_starts_with($url, 'https://cdn.test/') ? $path : $default;
        }, 2);
        Plugin::resetRequest();
        self::assertSame($path, PathMapper::urlToPath('https://cdn.test/anything/photo.jpg'));
        $this->filter('srcset_truth_url_to_path', static fn (): string => '/etc/passwd');
        Plugin::resetRequest();
        self::assertNull(PathMapper::urlToPath('https://cdn.test/anything/photo.jpg'), 'outside uploads refused');
    }

    public function testT49SameCandidateIsStatOnce(): void
    {
        $this->siblings('large');
        $tag = $this->cardTag();
        BufferProcessor::process('<body>' . $tag . $tag . $tag . '</body>');
        self::assertSame(3, Siblings::$stats, 'three distinct candidate files, each checked once');
    }

    public function testT50OffloadedUploadsLeavePageUnchangedAndFilterCanAnswer(): void
    {
        \WPStub::$basedir = $this->uploads . '/missing';
        Plugin::resetRequest();
        $page = '<body>' . $this->cardTag() . '</body>';
        self::assertSame($page, BufferProcessor::process($page));
        $this->filter('srcset_truth_has_sibling', static fn ($answer, string $path): bool => str_ends_with($path, '1024x576.jpg'), 2);
        Plugin::resetRequest();
        self::assertStringContainsString('photo-1024x576.jpg.webp 1024w', BufferProcessor::process($page));
    }

    public function testT51ModesAndResponseGuards(): void
    {
        $this->siblings('large');
        $hard = '<html><head></head><body>' . $this->cardTag() . '</body></html>';
        // Filter mode: no buffer is started.
        $level = ob_get_level();
        Plugin::startBuffer();
        self::assertSame($level, ob_get_level());

        $this->settings(['mode' => 'buffer']);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        Plugin::startBuffer();
        self::assertSame($level + 1, ob_get_level());
        echo $hard;
        $out = (string) ob_get_clean();
        // ob_get_clean discards without calling the handler in a way we can observe; call it directly.
        self::assertSame($hard, $out);
        self::assertStringContainsString('.jpg.webp 1024w', Plugin::bufferCallback($hard, PHP_OUTPUT_HANDLER_START | PHP_OUTPUT_HANDLER_FINAL));
        Plugin::resetRequest();
        self::assertSame($hard, Plugin::processResponse($hard, 404, 'text/html'));
        self::assertSame($hard, Plugin::processResponse($hard, 200, 'application/json'));
        self::assertNotSame($hard, Plugin::processResponse($hard, 200, 'text/html; charset=UTF-8'));
        $cut = substr($hard, 0, (int) strpos($hard, 'srcset='));
        self::assertSame($cut, Plugin::processResponse($cut, 200, 'text/html'));
        // A buffer flushed in pieces is passed through.
        Plugin::resetRequest();
        self::assertSame($hard, Plugin::bufferCallback($hard, PHP_OUTPUT_HANDLER_START | PHP_OUTPUT_HANDLER_FLUSH));
        self::assertSame($hard, Plugin::bufferCallback($hard, PHP_OUTPUT_HANDLER_FINAL));
    }

    public function testT52QuotingAndCaseVariants(): void
    {
        $this->siblings('large');
        $u = $this->url('2026/11/photo-1024x576.jpg');
        foreach (["<IMG SRC='{$u}' SRCSET='{$u} 1024w'>", "<img src={$u} srcset=\"{$u} 1024w\">"] as $tag) {
            $out = BufferProcessor::process('<body>' . $tag . '</body>');
            self::assertStringContainsString($u . '.webp 1024w', $out, $tag);
        }
    }

    public function testT60SrcsetCandidatesUnchangedInPictureMode(): void
    {
        $this->picture();
        $this->cardMap();
        $this->siblings('large', 'medium');
        $tag = $this->cardTag();
        $out = (string) FilterMode::contentImage($tag, '', 7);
        preg_match('/<img[^>]*srcset="([^"]+)"/', $out, $m);
        preg_match('/<img[^>]*srcset="([^"]+)"/', $tag, $orig);
        self::assertSame($orig[1], $m[1]);
    }

    public function testT62WebpMainFileGetsSizesButNoWrap(): void
    {
        $this->picture();
        $this->cardMap();
        $u = $this->url('2026/11/shot');
        $tag = "<img src=\"{$u}-1024x576.webp\" srcset=\"{$u}-1024x576.webp 1024w, {$u}-300x169.webp 300w\" sizes=\"(max-width: 1024px) 100vw, 1024px\" class=\"size-large\">";
        $out = (string) FilterMode::contentImage($tag, '', 0);
        self::assertStringNotContainsString('<picture', $out);
        self::assertStringContainsString('sizes="(max-width: 767px) 92vw', $out);
    }

    public function testT63IdenticalHtmlForEveryRenderAndNoVary(): void
    {
        $this->siblings('large', 'medium');
        $page = '<html><head></head><body>' . $this->cardTag() . '</body></html>';
        $_SERVER['HTTP_ACCEPT'] = 'image/avif,image/webp';
        $first = BufferProcessor::process($page);
        Plugin::resetRequest();
        $_SERVER['HTTP_ACCEPT'] = '*/*';
        $_SERVER['HTTP_USER_AGENT'] = 'Old browser';
        self::assertSame($first, BufferProcessor::process($page));
        Plugin::sendHeaders();
        foreach (\IndianOpinion\SrcsetTruth\Response::$sent as $header) {
            self::assertStringNotContainsStringIgnoringCase('vary', $header);
        }
        self::assertStringContainsString('.jpg.webp', $first, 'each format keeps its own URL');
    }

    public function testPictureCssIsOptIn(): void
    {
        ob_start();
        Plugin::printPictureCss();
        self::assertSame('', ob_get_clean());
        $this->picture();
        ob_start();
        Plugin::printPictureCss();
        self::assertStringContainsString('picture.srcset-truth{display:contents}', (string) ob_get_clean());
    }

    public function testAttachmentAttributesRewriteButNeverWrap(): void
    {
        $this->siblings('large');
        $attr = [
            'src' => $this->url('2026/11/photo-1024x576.jpg'),
            'srcset' => $this->url('2026/11/photo-1024x576.jpg') . ' 1024w',
            'class' => 'attachment-large',
        ];
        $out = FilterMode::attachmentAttributes($attr, (object) ['ID' => 7], 'large');
        self::assertStringEndsWith('.jpg.webp 1024w', (string) $out['srcset']);
        $this->picture();
        Plugin::resetRequest();
        self::assertSame($attr, FilterMode::attachmentAttributes($attr, (object) ['ID' => 7], 'large'));
    }
}
