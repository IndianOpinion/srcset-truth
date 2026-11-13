<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Tests\Integration;

use IndianOpinion\SrcsetTruth\Audit\Auditor;
use IndianOpinion\SrcsetTruth\Audit\Picker;
use IndianOpinion\SrcsetTruth\Cli\Command;
use IndianOpinion\SrcsetTruth\Exception\AuditFailed;
use IndianOpinion\SrcsetTruth\Files\SkipLedger;
use IndianOpinion\SrcsetTruth\Markup\Srcset;
use IndianOpinion\SrcsetTruth\Plugin;
use IndianOpinion\SrcsetTruth\Sizes\Evaluator;
use IndianOpinion\SrcsetTruth\Tests\TestCase;

/**
 * PRD 4.12: acceptance tests 61 and 66 to 70.
 */
final class AuditTest extends TestCase
{
    private const CARD = '(max-width: 767px) 92vw, (max-width: 1024px) 46vw, 380px';

    private function page(string $body): string
    {
        return "<html><head></head><body>{$body}</body></html>";
    }

    /**
     * @param array<int, mixed> $flags
     */
    private function flagsOf(array $report, int $index): array
    {
        return $report['images'][$index]['flags'];
    }

    public function testT67WorkedExampleMatchesHandComputedPicks(): void
    {
        $this->attachment(7);
        $before = $this->cardTag();
        $after = str_replace('sizes="auto, (max-width: 1024px) 100vw, 1024px"', 'sizes="auto, ' . self::CARD . '"', $before);
        $report = (new Auditor())->analyse($this->page($before . $after));
        self::assertSame(['width' => 412.0, 'dpr' => 2.0], $report['viewport']);
        // 412 px at DPR 2 needs 824 device px: the core value picks 1024w.
        self::assertSame(1024, $report['images'][0]['before']['width']);
        self::assertSame(1024, $report['images'][0]['after']['width']);
        // The card needs 0.92 x 412 x 2 = 758 device px: 768w.
        self::assertSame(1024, $report['images'][1]['before']['width']);
        self::assertSame(768, $report['images'][1]['after']['width']);
        self::assertSame(filesize($this->uploads . '/2026/11/photo-768x432.jpg'), $report['images'][1]['after']['bytes']);
    }

    public function testT68Evaluator(): void
    {
        self::assertSame(412.0, Evaluator::slotWidth('(max-width: 1024px) 100vw, 1024px', 412));
        self::assertSame(1024.0, Evaluator::slotWidth('(max-width: 1024px) 100vw, 1024px', 1440));
        self::assertSame(200.0, Evaluator::slotWidth('(min-width: 600px) and (max-width: 900px) 200px, 50vw', 800));
        self::assertSame(250.0, Evaluator::slotWidth('(min-width: 600px) and (max-width: 900px) 200px, 50vw', 500));
        self::assertSame(320.0, Evaluator::slotWidth('(max-width: 40em) 20rem, 10em', 500));
        self::assertSame(160.0, Evaluator::slotWidth('(max-width: 40em) 20rem, 10em', 700));
        self::assertEqualsWithDelta(174.0, (float) Evaluator::slotWidth('calc(50vw - 2rem)', 412), 0.001);
        self::assertSame(300.0, Evaluator::slotWidth('auto, min(90vw, 300px)', 412));
        self::assertNull(Evaluator::slotWidth('(orientation: landscape) 50vw, 100vw', 412));
        self::assertNull(Evaluator::slotWidth('(max-width: 600px) 50vw, 10cm', 900));
    }

    public function testPickerRule(): void
    {
        $list = Srcset::parse('a.jpg 300w, b.jpg 768w, c.jpg 1024w');
        self::assertSame(1, Picker::pick($list, 379.04, 2));
        self::assertSame(2, Picker::pick($list, 900, 2), 'largest when none is dense enough');
        self::assertSame(1, Picker::pick(Srcset::parse('a.jpg, b.jpg 2x, c.jpg 3x'), 0, 2));
    }

    public function testT61AndT69EachFlagAppears(): void
    {
        $files = $this->attachment(7);
        $this->filter('srcset_truth_width_map', static fn (): array => ['default' => ['card' => self::CARD]]);
        $u = $this->url('2026/11/photo');
        $rows = [
            'unmapped' => $this->cardTag(),
            'authored' => str_replace('sizes="auto, (max-width: 1024px) 100vw, 1024px"', 'sizes="(max-width: 600px) 50vw, 300px"', $this->cardTag()),
            'invalid-spec' => str_replace('class=', 'data-srcset-truth="front|card|invalid-spec" class=', $this->cardTag()),
            // Small candidates filtered out by a theme: only 1024w for a 100px slot.
            'no-candidate-near-slot' => "<img src=\"{$u}-1024x576.jpg\" srcset=\"{$u}-1024x576.jpg 1024w\" sizes=\"100px\" width=\"100\" height=\"56\">",
            'unsized' => "<img src=\"{$u}-300x169.jpg\">",
            'not-beneficial' => "<img src=\"{$u}-150x150.jpg\" width=\"150\" height=\"150\">",
        ];
        SkipLedger::record($files['thumbnail'], 'not-beneficial');
        $report = (new Auditor())->analyse($this->page(implode('', $rows)));
        $i = 0;
        foreach (array_keys($rows) as $flag) {
            self::assertContains($flag, $this->flagsOf($report, $i), $flag . ': ' . implode(',', $this->flagsOf($report, $i)));
            $i++;
        }
        self::assertContains('no-sibling', $this->flagsOf($report, 0));
        // The first photo-like image is the LCP candidate, and it is lazy.
        self::assertContains('lazy-lcp', $this->flagsOf($report, 0));
        self::assertNotContains('lazy-lcp', $this->flagsOf($report, 1));
        self::assertSame(200, $report['images'][3]['missing_width']);
        // A value the map produced is neither authored nor unmapped.
        $ours = str_replace('sizes="auto, (max-width: 1024px) 100vw, 1024px"', 'sizes="' . self::CARD . '"', $this->cardTag());
        $report = (new Auditor())->analyse($this->page($ours));
        self::assertSame(['no-sibling', 'lazy-lcp'], $this->flagsOf($report, 0));
    }

    public function testT66AuditRefusesOtherHostsAndRedirects(): void
    {
        try {
            Auditor::resolveUrl('https://evil.test/');
            self::fail('other host accepted');
        } catch (AuditFailed $e) {
            self::assertStringContainsString('only fetches this site', $e->getMessage());
        }
        self::assertSame('https://example.test/news/', Auditor::resolveUrl('/news/'));
        \WPStub::respond('https://example.test/go', 302, '', ['Location' => 'https://evil.test/']);
        $this->expectException(AuditFailed::class);
        $this->expectExceptionMessage('redirect to another host refused');
        (new Auditor())->run('/go');
    }

    public function testFetchFollowsSameHostRedirectAndSendsToken(): void
    {
        \WPStub::respond('https://example.test/a', 301, '', ['Location' => '/b']);
        \WPStub::respond('https://example.test/b', 200, $this->page(''), ['Content-Type' => 'text/html']);
        $report = (new Auditor())->run('/a');
        self::assertSame('https://example.test/b', $report['url']);
        self::assertSame(Plugin::auditToken(), \WPStub::$httpLog[0]['args']['headers'][Plugin::AUDIT_HEADER]);
        self::assertSame(0, \WPStub::$httpLog[0]['args']['redirection']);
    }

    public function testT70JsonShapeTotalsAndExitCode(): void
    {
        $this->attachment(7);
        $after = str_replace('sizes="auto, (max-width: 1024px) 100vw, 1024px"', 'sizes="auto, ' . self::CARD . '"', $this->cardTag());
        $remote = '<img src="https://other.test/x.jpg" width="10" height="10">';
        \WPStub::respond('https://example.test/', 200, $this->page($after . $remote), ['Content-Type' => 'text/html; charset=UTF-8']);
        (new Command())->audit(['/'], ['format' => 'json']);
        $json = json_decode(implode("\n", \WP_CLI::$lines), true);
        self::assertIsArray($json);
        self::assertSame(['url', 'viewport', 'images', 'totals'], array_keys($json));
        self::assertSame(['index', 'src', 'template', 'slot', 'sizes', 'before', 'after', 'flags', 'missing_width', 'unknown'], array_keys($json['images'][0]));
        self::assertSame(['url', 'width', 'bytes', 'format'], array_keys($json['images'][0]['before']));
        $b = filesize($this->uploads . '/2026/11/photo-1024x576.jpg');
        $a = filesize($this->uploads . '/2026/11/photo-768x432.jpg');
        self::assertSame([
            'images' => 2,
            'counted' => 1,
            'before_bytes' => $b,
            'after_bytes' => $a,
            'saving_kib' => round(($b - $a) / 1024, 1),
            'saving_percent' => round(($b - $a) * 100 / $b, 1),
        ], $json['totals']);

        \WP_CLI::reset();
        (new Command())->audit(['/'], []);
        self::assertStringContainsString('Viewport: 412 px at DPR 2', implode("\n", \WP_CLI::$lines));
        self::assertStringStartsWith('Saving: ', (string) end(\WP_CLI::$lines));

        \WPStub::respond('https://example.test/gone', 404);
        try {
            (new Command())->audit(['/gone'], []);
            self::fail('no error');
        } catch (\WP_CLI_Halt $e) {
            self::assertSame(1, $e->getCode());
            self::assertStringContainsString('HTTP 404', $e->getMessage());
        }
        \WPStub::respond('https://example.test/feed.json', 200, '{}', ['Content-Type' => 'application/json']);
        $this->expectException(\WP_CLI_Halt::class);
        (new Command())->audit(['/feed.json'], []);
    }
}
