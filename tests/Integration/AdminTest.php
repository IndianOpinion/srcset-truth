<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Tests\Integration;

use IndianOpinion\SrcsetTruth\Admin\Probe;
use IndianOpinion\SrcsetTruth\Admin\SettingsPage;
use IndianOpinion\SrcsetTruth\Admin\SiteHealth;
use IndianOpinion\SrcsetTruth\Backfill\Backfill;
use IndianOpinion\SrcsetTruth\Cli\Command;
use IndianOpinion\SrcsetTruth\Encoding\EncoderFactory;
use IndianOpinion\SrcsetTruth\Files\SkipLedger;
use IndianOpinion\SrcsetTruth\Lifecycle;
use IndianOpinion\SrcsetTruth\Markup\FilterMode;
use IndianOpinion\SrcsetTruth\Plugin;
use IndianOpinion\SrcsetTruth\Response;
use IndianOpinion\SrcsetTruth\Settings;
use IndianOpinion\SrcsetTruth\Tests\FakeEncoder;
use IndianOpinion\SrcsetTruth\Tests\TestCase;

/**
 * Site Health, probe, switch, uninstall, settings, CLI and README:
 * acceptance tests 65 and 71 to 75.
 */
final class AdminTest extends TestCase
{
    public function testT65WrongContentTypeIsCritical(): void
    {
        $url = $this->url('2026/11/photo.jpg.webp');
        \WPStub::respond($url, 200, '', ['Content-Type' => 'application/octet-stream']);
        self::assertSame('critical', SiteHealth::contentType($url)['status']);
        \WPStub::respond($url, 200, '', ['Content-Type' => 'image/webp']);
        self::assertSame('good', SiteHealth::contentType($url)['status']);
        self::assertSame(5, \WPStub::$httpLog[0]['args']['timeout']);
    }

    public function testSiteHealthFindsASiblingAndReportsBackfillAndCoverage(): void
    {
        $files = $this->attachment(7);
        $this->fakeSibling($files['large']);
        Backfill::setSource($this->source([7]));
        $url = $this->url('2026/11/photo-1024x576.jpg.webp');
        \WPStub::respond($url, 200, '', ['Content-Type' => 'image/webp']);
        self::assertSame('good', SiteHealth::contentType()['status']);
        self::assertSame($url, \WPStub::$httpLog[0]['url']);
        self::assertSame('recommended', SiteHealth::backfill()['status'], 'never ran');
        update_option(Backfill::STATE_OPTION, ['last_run' => time()]);
        self::assertSame('good', SiteHealth::backfill()['status']);
        self::assertStringStartsWith('25%', (string) SiteHealth::coverage()['label']);
        $tests = SiteHealth::tests(['direct' => []]);
        self::assertCount(4, $tests['direct']);
    }

    public function testT71ProbeOnlyForAdminsWithNonce(): void
    {
        $_GET = ['srcset-truth-probe' => '1', '_wpnonce' => wp_create_nonce(Probe::NONCE)];
        Probe::maybeStart();
        self::assertFalse(Probe::active(), 'no capability');
        self::assertSame([], Response::$sent);

        \WPStub::$caps['manage_options'] = true;
        $_GET['_wpnonce'] = 'forged';
        Probe::maybeStart();
        self::assertFalse(Probe::active(), 'bad nonce');

        $_GET['_wpnonce'] = wp_create_nonce(Probe::NONCE);
        Probe::maybeStart();
        self::assertTrue(Probe::active());
        self::assertContains('Cache-Control: private, no-store, max-age=0', Response::$sent);
        ob_start();
        do_action('wp_footer');
        $footer = (string) ob_get_clean();
        self::assertStringContainsString('<script id="srcset-truth-probe">', $footer);
        self::assertStringContainsString('console.table', $footer);
        self::assertStringContainsString('naturalWidth', $footer);
    }

    public function testProbeAdminBarNode(): void
    {
        $bar = new class () {
            /** @var list<array<string, string>> */
            public array $nodes = [];

            /** @param array<string, string> $node */
            public function add_node(array $node): void
            {
                $this->nodes[] = $node;
            }
        };
        Probe::adminBar($bar);
        self::assertSame([], $bar->nodes);
        \WPStub::$caps['manage_options'] = true;
        Probe::adminBar($bar);
        self::assertStringContainsString('srcset-truth-probe=1', $bar->nodes[0]['href']);
    }

    public function testBootRegistersHooks(): void
    {
        Plugin::boot();
        self::assertTrue(has_filter('wp_content_img_tag'));
        self::assertTrue(has_filter('wp_get_attachment_image_attributes'));
        self::assertTrue(has_filter('post_thumbnail_html'));
        self::assertTrue(has_filter('wp_generate_attachment_metadata'));
        self::assertTrue(has_filter('delete_attachment'));
        self::assertTrue(has_filter(Backfill::HOOK));
    }

    public function testT72DisableConstantRegistersNoHooks(): void
    {
        $script = sprintf(
            '<?php require %s; define("SRCSET_TRUTH_DISABLE", true); require %s; \IndianOpinion\SrcsetTruth\Plugin::boot(); echo WPStub::hookCount();',
            var_export(dirname(__DIR__) . '/bootstrap.php', true),
            var_export(dirname(__DIR__, 2) . '/srcset-truth.php', true)
        );
        $file = $this->uploads . '/disable.php';
        file_put_contents($file, $script);
        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file), $output, $code);
        self::assertSame(0, $code, implode("\n", $output));
        self::assertSame('0', trim(implode('', $output)));
    }

    public function testT73UninstallAndRemoveSiblings(): void
    {
        $files = $this->attachment(7);
        $stray = $this->jpeg('2026/11/not-an-attachment.jpg');
        $this->fakeSibling($stray);
        $this->fakeSibling($files['large']);
        $this->fakeSibling($files['medium']);
        Backfill::setSource($this->source([7]));

        \WP_CLI::reset();
        try {
            (new Command())->removeSiblings([], []);
            self::fail('needs --yes');
        } catch (\WP_CLI_Halt) {
        }
        (new Command())->removeSiblings([], ['yes' => true]);
        self::assertFileDoesNotExist($files['large'] . '.webp');
        self::assertFileDoesNotExist($files['medium'] . '.webp');
        self::assertFileExists($stray . '.webp', 'only siblings of known attachment files');
        foreach ($files as $file) {
            self::assertFileExists($file);
        }

        $this->fakeSibling($files['large']);
        update_option(Settings::OPTION, Settings::defaults());
        update_option(Backfill::STATE_OPTION, ['cursor' => 3]);
        update_option(Backfill::LOCK_OPTION, time() + 60);
        update_option(SkipLedger::OPTION, ['a' => ['m' => 1, 's' => 1, 'r' => 'x', 't' => 1]]);
        \WPStub::$cron[Backfill::HOOK] = ['time' => time(), 'schedule' => Backfill::SCHEDULE];
        \WPStub::$postMeta[7]['_srcset_truth'] = ['old'];
        define('WP_UNINSTALL_PLUGIN', 'srcset-truth/srcset-truth.php');
        require dirname(__DIR__, 2) . '/uninstall.php';
        $ours = [Settings::OPTION, Backfill::STATE_OPTION, Backfill::LOCK_OPTION, SkipLedger::OPTION];
        self::assertSame([], array_intersect(array_keys(\WPStub::$options), $ours));
        self::assertSame([], \WPStub::$cron);
        self::assertArrayNotHasKey('_srcset_truth', \WPStub::$postMeta[7]);
        self::assertFileExists($files['large'] . '.webp', 'uninstall keeps sibling files');
    }

    public function testT74SettingsSanitisation(): void
    {
        $clean = Settings::sanitize(['quality' => '150', 'mode' => 'turbo', 'preset' => 'grid', 'webp' => '1']);
        self::assertSame(82, $clean['quality']);
        self::assertSame('filter', $clean['mode']);
        self::assertSame('grid', $clean['preset']);
        self::assertTrue($clean['webp']);
        self::assertFalse($clean['lcp']);
        self::assertSame(['srcset-truth-quality', 'srcset-truth-mode'], \WPStub::$settingsErrors);
        self::assertSame(60, Settings::sanitize(['quality' => '60'])['quality']);
        self::assertSame(82, Settings::sanitize(['quality' => '0'])['quality']);
        self::assertSame(82, Settings::sanitize(['quality' => 'abc'])['quality']);
        self::assertSame(Settings::defaults(), Settings::normalise(['quality' => 500, 'mode' => ['x'], 'preset' => 'odd']));
        SettingsPage::register();
        self::assertSame([Settings::class, 'sanitize'], \WPStub::$options['__registered'][Settings::OPTION]['sanitize_callback']);
    }

    public function testSettingsFieldsEscapeOutput(): void
    {
        ob_start();
        SettingsPage::field(['key' => 'mode']);
        SettingsPage::field(['key' => 'quality']);
        SettingsPage::field(['key' => 'webp']);
        $html = (string) ob_get_clean();
        self::assertStringContainsString('name="srcset_truth_settings[mode]"', $html);
        self::assertStringContainsString('value="82"', $html);
        self::assertStringContainsString("checked='checked'", $html);
    }

    public function testT75ReadmeWarnsAboutSyncAndImageCdns(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__, 2) . '/README.md');
        self::assertMatchesRegularExpression('/\*\*[^*]*sync[^*]*\.webp[^*]*\*\*/i', $readme);
        self::assertMatchesRegularExpression('/(CDN|edge)[^.]*(convert|optimi[sz])[^.]*turn off WebP serving/i', $readme);
    }

    public function testStatusAndMapCommands(): void
    {
        $fake = new FakeEncoder(static function (string $s, string $d): void {
            file_put_contents($d, 'x');
        });
        EncoderFactory::override([$fake]);
        $files = $this->attachment(7);
        Lifecycle::onGenerate(\WPStub::$attachmentMeta[7], 7);
        $this->touchAt($files['large'], time() + 100);
        SkipLedger::record($files['thumbnail'], 'not-beneficial');
        Backfill::setSource($this->source([7]));
        (new Command())->status([], []);
        $out = implode("\n", \WP_CLI::$lines);
        self::assertStringContainsString('Encoder: fake', $out);
        self::assertStringContainsString('Coverage: 4 originals in scope, 3 fresh siblings, 1 stale, 1 not beneficial, 0 failed', $out);
        self::assertStringContainsString('Lock: free', $out);

        \WP_CLI::reset();
        $this->filter('srcset_truth_width_map', static fn (): array => ['default' => ['card' => [[767, '92vw'], [null, '380px']], 'bad' => '50%']]);
        (new Command())->map([], ['template' => 'single-post']);
        $out = implode("\n", \WP_CLI::$lines);
        self::assertStringContainsString('single-post, single, default', $out);
        self::assertStringContainsString('default | card | (max-width: 767px) 92vw, 380px', $out);
        self::assertStringContainsString('Warning: 1 invalid spec(s)', $out);
    }

    public function testGenerateDryRunAndSelection(): void
    {
        $this->attachment(7);
        Backfill::setSource($this->source([7]));
        (new Command())->generate([], ['all' => true, 'dry-run' => true]);
        self::assertStringContainsString('would-create 4', (string) end(\WP_CLI::$lines));
        $this->expectException(\WP_CLI_Halt::class);
        (new Command())->generate([], []);
    }

    public function testAuditModeAnnotatesAndIsPrivate(): void
    {
        $this->cardMap();
        $_SERVER['HTTP_X_SRCSET_TRUTH_AUDIT'] = Plugin::auditToken();
        $out = (string) FilterMode::contentImage($this->cardTag(), '', 7);
        self::assertStringContainsString('data-srcset-truth="default|card|corrected"', $out);
        Plugin::sendHeaders();
        self::assertContains('Cache-Control: private, no-store, max-age=0', Response::$sent);
        Plugin::resetRequest();
        $_SERVER['HTTP_X_SRCSET_TRUTH_AUDIT'] = str_repeat('a', 32);
        $this->cardMap();
        self::assertStringNotContainsString('data-srcset-truth', (string) FilterMode::contentImage($this->cardTag(), '', 7));
    }

    public function testAuditTokenWindowsAndCacheBypass(): void
    {
        $now = time();
        $cases = [
            [Plugin::auditToken($now), true],
            [Plugin::auditToken($now - 300), true],
            [Plugin::auditToken($now - 600), false],
            [Plugin::auditToken($now + 600), false],
            [strtoupper(Plugin::auditToken($now)), false],
            [substr(Plugin::auditToken($now), 0, 31), false],
        ];
        foreach ($cases as [$token, $accepted]) {
            Plugin::resetRequest();
            $_SERVER['HTTP_X_SRCSET_TRUTH_AUDIT'] = $token;
            self::assertSame($accepted, Plugin::auditMode(), $token);
        }
        // The token is not a core nonce over the same salt.
        self::assertNotSame(substr(hash_hmac('sha256', 'srcset-truth-audit|' . intdiv($now, 300), wp_salt('nonce')), 0, 32), Plugin::auditToken($now));
        unset($_SERVER['HTTP_X_SRCSET_TRUTH_AUDIT']);
        Plugin::resetRequest();
        Plugin::sendHeaders();
        self::assertSame([], Response::$sent, 'no token, no headers');
        $_SERVER['HTTP_X_SRCSET_TRUTH_AUDIT'] = Plugin::auditToken();
        Plugin::resetRequest();
        Plugin::sendHeaders();
        self::assertTrue(defined('DONOTCACHEPAGE') && constant('DONOTCACHEPAGE') === true);
    }
}
