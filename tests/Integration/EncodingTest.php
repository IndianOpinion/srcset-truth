<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Tests\Integration;

use IndianOpinion\SrcsetTruth\Admin\SiteHealth;
use IndianOpinion\SrcsetTruth\Cli\Command;
use IndianOpinion\SrcsetTruth\Encoding\EncoderFactory;
use IndianOpinion\SrcsetTruth\Encoding\GdEncoder;
use IndianOpinion\SrcsetTruth\Encoding\ImagickEncoder;
use IndianOpinion\SrcsetTruth\Encoding\SiblingCreator;
use IndianOpinion\SrcsetTruth\Exception\EncodeFailed;
use IndianOpinion\SrcsetTruth\Files\Siblings;
use IndianOpinion\SrcsetTruth\Files\SkipLedger;
use IndianOpinion\SrcsetTruth\Lifecycle;
use IndianOpinion\SrcsetTruth\Markup\FilterMode;
use IndianOpinion\SrcsetTruth\Plugin;
use IndianOpinion\SrcsetTruth\Tests\FakeEncoder;
use IndianOpinion\SrcsetTruth\Tests\TestCase;

/**
 * PRD 4.6: acceptance tests 23 to 36. Real encoder tests are skipped with a
 * message when the encoder is missing.
 */
final class EncodingTest extends TestCase
{
    private function requireImagick(): void
    {
        if (!ImagickEncoder::available()) {
            self::markTestSkipped('Imagick with WebP support is not installed.');
        }
    }

    private function requireGd(): void
    {
        if (!GdEncoder::available()) {
            self::markTestSkipped('GD with WebP support is not installed.');
        }
    }

    private function anyEncoder(): void
    {
        if (EncoderFactory::available() === []) {
            self::markTestSkipped('No WebP encoder is installed.');
        }
    }

    public function testT23UploadCreatesSiblingsForConfiguredSizesAndMainOnly(): void
    {
        $this->anyEncoder();
        $files = $this->attachment(7);
        Lifecycle::onGenerate(\WPStub::$attachmentMeta[7], 7);
        foreach (['main', 'medium', 'medium_large', 'large'] as $size) {
            self::assertFileExists($files[$size] . '.webp', $size);
            self::assertSame('RIFF', substr((string) file_get_contents($files[$size] . '.webp'), 0, 4));
        }
        self::assertFileDoesNotExist($files['thumbnail'] . '.webp');
    }

    public function testT23SizesListIsFilterable(): void
    {
        $this->anyEncoder();
        $files = $this->attachment(7);
        $this->filter('srcset_truth_webp_sizes', static fn (): array => ['thumbnail']);
        Lifecycle::onGenerate(\WPStub::$attachmentMeta[7], 7);
        self::assertFileExists($files['thumbnail'] . '.webp');
        self::assertFileDoesNotExist($files['large'] . '.webp');
    }

    public function testT24SkipsByRealType(): void
    {
        $fake = new FakeEncoder(static fn () => null);
        EncoderFactory::override([$fake]);
        $svg = $this->uploads . '/2026/11/vector.jpg';
        file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
        $webp = $this->uploads . '/2026/11/already.jpg';
        file_put_contents($webp, "RIFF\x10\x00\x00\x00WEBPVP8 ");
        $text = $this->uploads . '/2026/11/notes.png';
        file_put_contents($text, 'just text');
        foreach ([$svg, $webp, $text] as $path) {
            self::assertSame('unsupported-type', SiblingCreator::create($path)['reason']);
        }
        self::assertSame(0, $fake->calls);

        // A PNG named .jpg is converted as a PNG.
        $png = $this->png('2026/11/misnamed.png');
        rename($png, $this->uploads . '/2026/11/misnamed.jpg');
        $fake2 = new FakeEncoder(static function (string $s, string $d): void {
            file_put_contents($d, 'x');
        });
        EncoderFactory::override([$fake2]);
        SiblingCreator::create($this->uploads . '/2026/11/misnamed.jpg');
        self::assertSame('image/png', $fake2->seen[0]['mime']);
    }

    public function testT25EncoderSelection(): void
    {
        $this->requireImagick();
        $this->requireGd();
        self::assertSame('imagick', EncoderFactory::pick(false)?->name());
        EncoderFactory::override([new GdEncoder()]);
        self::assertSame('gd', EncoderFactory::pick(false)?->name());
        self::assertNull(EncoderFactory::pick(true), 'GD cannot write animation');
        EncoderFactory::override([]);
        self::assertNull(EncoderFactory::pick(false));
        self::assertSame('none', EncoderFactory::describe());
        $health = SiteHealth::encoder();
        self::assertSame('recommended', $health['status']);
        $path = $this->jpeg('2026/11/a.jpg');
        $result = SiblingCreator::create($path);
        self::assertSame('no-encoder', $result['reason']);
        // Not recorded: an encoder installed later must get another go.
        self::assertNull(SkipLedger::reason($path));
    }

    public function testT26QualityFilterAndAlpha(): void
    {
        $fake = new FakeEncoder(static function (string $s, string $d): void {
            file_put_contents($d, 'x');
        });
        EncoderFactory::override([$fake]);
        $args = [];
        $this->filter('srcset_truth_webp_quality', static function (int $q, string $kind, string $mime) use (&$args): int {
            $args[] = [$q, $kind, $mime];

            return $kind === 'photo' ? 70 : $q;
        }, 3);
        SiblingCreator::create($this->jpeg('2026/11/q.jpg'));
        self::assertSame([[82, 'photo', 'image/jpeg']], $args);
        self::assertSame(70, $fake->seen[0]['quality']);

        foreach ([new ImagickEncoder(), new GdEncoder()] as $encoder) {
            if (($encoder instanceof ImagickEncoder && !ImagickEncoder::available()) || ($encoder instanceof GdEncoder && !GdEncoder::available())) {
                continue;
            }
            EncoderFactory::override([$encoder]);
            $png = $this->png('2026/11/alpha-' . $encoder->name() . '.png', 40, 40);
            $result = SiblingCreator::create($png);
            self::assertSame('created', $result['status'], $encoder->name() . ' ' . (string) $result['reason']);
            $image = imagecreatefromwebp($png . '.webp');
            self::assertNotFalse($image);
            $bottom = imagecolorat($image, 5, 35);
            self::assertSame(127, ($bottom >> 24) & 0x7F, $encoder->name() . ' kept alpha');
        }
    }

    private function iccProfile(string $description): string
    {
        $desc = 'desc' . "\0\0\0\0" . pack('N', strlen($description) + 1) . $description . "\0" . str_repeat("\0", 79);
        $tagTable = pack('N', 1) . 'desc' . pack('N', 144) . pack('N', strlen($desc));
        $size = 128 + strlen($tagTable) + strlen($desc);
        $header = pack('N', $size) . 'lcms' . pack('N', 0x02100000) . 'mntr' . 'RGB ' . 'XYZ '
            . str_repeat("\0", 12) . 'acsp' . 'APPL' . str_repeat("\0", 24) . pack('N', 0) . str_repeat("\0", 12) . str_repeat("\0", 4) . str_repeat("\0", 44);

        return substr(str_pad($header, 128, "\0"), 0, 128) . $tagTable . $desc;
    }

    private function jpegWithProfile(string $relative, string $description): string
    {
        $path = $this->jpeg($relative, 64, 36);
        $data = (string) file_get_contents($path);
        $icc = $this->iccProfile($description);
        $segment = "\xFF\xE2" . pack('n', strlen($icc) + 16) . "ICC_PROFILE\0\x01\x01" . $icc;
        file_put_contents($path, substr($data, 0, 2) . $segment . substr($data, 2));

        return $path;
    }

    public function testT27WideGamutJpegKeepsItsProfileOrIsSkipped(): void
    {
        $path = $this->jpegWithProfile('2026/11/p3.jpg', 'Display P3');
        if (ImagickEncoder::available()) {
            EncoderFactory::override([new ImagickEncoder()]);
            $result = SiblingCreator::create($path);
            self::assertSame('created', $result['status'], (string) $result['reason']);
            $webp = (string) file_get_contents($path . '.webp');
            self::assertStringContainsString('ICCP', $webp, 'profile kept in the WebP');
            self::assertStringContainsString('Display P3', $webp);
            unlink($path . '.webp');
        }
        $this->requireGd();
        EncoderFactory::override([new GdEncoder()]);
        SkipLedger::forget($path);
        $result = SiblingCreator::create($path);
        self::assertSame('colour-profile', $result['reason']);
        self::assertFileDoesNotExist($path . '.webp');

        // An sRGB profile is safe to drop.
        $srgb = $this->jpegWithProfile('2026/11/srgb.jpg', 'sRGB IEC61966-2.1');
        $result = SiblingCreator::create($srgb);
        self::assertSame('created', $result['status'], (string) $result['reason']);
    }

    private function animatedGif(string $relative, int $frames): string
    {
        $path = $this->uploads . '/' . $relative;
        $gif = new \Imagick();
        for ($i = 0; $i < $frames; $i++) {
            $frame = new \Imagick();
            $frame->newPseudoImage(120, 80, 'plasma:fractal');
            $frame->setImageFormat('gif');
            $frame->setImageDelay(10 + $i);
            $gif->addImage($frame);
        }
        $gif->setImageIterations(0);
        $gif->writeImages($path, true);

        return $path;
    }

    public function testT28AnimatedGifKeepsFramesAndFrameLimit(): void
    {
        $this->requireImagick();
        EncoderFactory::override([new ImagickEncoder()]);
        $gif = $this->animatedGif('2026/11/anim.gif', 4);
        $result = SiblingCreator::create($gif);
        self::assertSame('created', $result['status'], (string) $result['reason']);
        $webp = new \Imagick($gif . '.webp');
        self::assertSame(4, $webp->getNumberImages());

        $this->filter('srcset_truth_limits', static fn (array $l): array => ['frames' => 3] + $l);
        $big = $this->animatedGif('2026/11/long.gif', 4);
        self::assertSame('too-many-frames', SiblingCreator::create($big)['reason']);
    }

    public function testT28GdOnlySkipsAnimated(): void
    {
        $this->requireImagick();
        $gif = $this->animatedGif('2026/11/anim.gif', 3);
        EncoderFactory::override([new GdEncoder()]);
        self::assertSame('no-encoder', SiblingCreator::create($gif)['reason']);
    }

    public function testT29LargerSiblingIsRemovedRecordedAndNotRetried(): void
    {
        $fake = new FakeEncoder(static function (string $s, string $d): void {
            file_put_contents($d, str_repeat('x', (int) filesize($s) + 10));
        });
        EncoderFactory::override([$fake]);
        $path = $this->jpeg('2026/11/tiny.jpg');
        $result = SiblingCreator::create($path);
        self::assertSame('not-beneficial', $result['reason']);
        self::assertFileDoesNotExist($path . '.webp');
        self::assertSame('not-beneficial', SkipLedger::reason($path));
        SiblingCreator::create($path);
        self::assertSame(1, $fake->calls);
        // A changed original is evaluated again.
        $this->touchAt($path, time() + 100);
        SiblingCreator::create($path);
        self::assertSame(2, $fake->calls);
    }

    public function testT30NoPartialFileOnWriteFailure(): void
    {
        $partial = null;
        $fake = new FakeEncoder(function (string $s, string $d) use (&$partial): void {
            file_put_contents($d, 'half a fi');
            $partial = $d;
            self::assertFileDoesNotExist($s . '.webp');
            throw new EncodeFailed('write-error', 'disk full');
        });
        EncoderFactory::override([$fake]);
        $path = $this->jpeg('2026/11/w.jpg');
        $result = SiblingCreator::create($path);
        self::assertSame('write-error', $result['reason']);
        self::assertNotNull($partial);
        self::assertStringEndsWith('.tmp', (string) $partial);
        self::assertSame(dirname($path), dirname((string) $partial));
        self::assertFileDoesNotExist((string) $partial);
        self::assertFileDoesNotExist($path . '.webp');
        self::assertSame(['.', '..', 'w.jpg'], array_values(array_filter(scandir(dirname($path)) ?: [], static fn ($f) => true)));
    }

    public function testT31FileModeDefaultsTo0644(): void
    {
        $this->anyEncoder();
        if (defined('FS_CHMOD_FILE')) {
            self::markTestSkipped('FS_CHMOD_FILE is defined in this process.');
        }
        umask(0077);
        $path = $this->jpeg('2026/11/m.jpg');
        SiblingCreator::create($path);
        self::assertSame(0644, fileperms($path . '.webp') & 0777);
        umask(0022);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testT31FileModeFollowsFsChmodFile(): void
    {
        define('FS_CHMOD_FILE', 0640);
        $fake = new FakeEncoder(static function (string $s, string $d): void {
            file_put_contents($d, 'x');
        });
        EncoderFactory::override([$fake]);
        $path = $this->jpeg('2026/11/m.jpg');
        SiblingCreator::create($path);
        self::assertSame(0640, fileperms($path . '.webp') & 0777);
    }

    public function testT32OverLimitSourceIsSkipped(): void
    {
        $fake = new FakeEncoder(static fn () => null);
        EncoderFactory::override([$fake]);
        $this->filter('srcset_truth_limits', static fn (array $l): array => ['megapixels' => 0.001] + $l);
        self::assertSame('too-large', SiblingCreator::create($this->jpeg('2026/11/big.jpg'))['reason']);
        Plugin::resetRequest();
        remove_all_filters('srcset_truth_limits');
        $this->filter('srcset_truth_limits', static fn (array $l): array => ['bytes' => 10] + $l);
        self::assertSame('too-large', SiblingCreator::create($this->jpeg('2026/11/heavy.jpg'))['reason']);
        self::assertSame(0, $fake->calls);
    }

    public function testT33SecondCallWithFreshSiblingDoesNothing(): void
    {
        $fake = new FakeEncoder(static function (string $s, string $d): void {
            file_put_contents($d, 'x');
        });
        EncoderFactory::override([$fake]);
        $path = $this->jpeg('2026/11/f.jpg');
        self::assertSame('created', SiblingCreator::create($path)['status']);
        self::assertSame('fresh', SiblingCreator::create($path)['status']);
        self::assertSame(1, $fake->calls);
    }

    public function testT34StaleSiblingIsNotServedAndGenerateRecreatesIt(): void
    {
        $this->anyEncoder();
        $files = $this->attachment(7);
        Lifecycle::onGenerate(\WPStub::$attachmentMeta[7], 7);
        $large = $files['large'];
        $this->touchAt($large . '.webp', time() - 1000);
        $this->touchAt($large, time());
        Siblings::reset();
        self::assertFalse(Siblings::isFresh($large));
        $out = FilterMode::contentImage($this->cardTag(), '', 7);
        self::assertStringContainsString('photo-1024x576.jpg 1024w', (string) $out);
        self::assertStringContainsString('photo-768x432.jpg.webp 768w', (string) $out);

        (new Command())->generate([], ['attachment' => '7']);
        self::assertSame('fresh', Siblings::state($large));
    }

    public function testT35EncoderExceptionLeavesOriginalAndFiresAction(): void
    {
        $fake = new FakeEncoder(static function (): void {
            throw new \RuntimeException('codec exploded');
        });
        EncoderFactory::override([$fake]);
        $path = $this->jpeg('2026/11/e.jpg');
        $before = md5_file($path);
        $failed = [];
        add_action('srcset_truth_sibling_failed', static function (string $p, string $reason) use (&$failed): void {
            $failed[] = [$p, $reason];
        }, 10, 2);
        $result = SiblingCreator::create($path);
        self::assertSame('encode-error', $result['reason']);
        self::assertSame($before, md5_file($path));
        self::assertSame([[$path, 'encode-error']], $failed);
    }

    public function testT36PageRequestNeverEncodes(): void
    {
        $fake = new FakeEncoder(static function (string $s, string $d): void {
            file_put_contents($d, 'x');
        });
        EncoderFactory::override([$fake]);
        $this->attachment(7);
        $this->cardMap();
        \WPStub::$caps['manage_options'] = true;
        $_GET['srcset-truth-probe'] = '1';
        FilterMode::contentImage($this->cardTag(), '', 7);
        FilterMode::thumbnail($this->cardTag(), 1, 7, 'large');
        self::assertSame(0, $fake->calls);
        self::assertSame(0, SiblingCreator::$encodes);
    }

    public function testCreatedActionFiresAndNoPurge(): void
    {
        $this->anyEncoder();
        $created = [];
        add_action('srcset_truth_sibling_created', static function (string $o, string $s, int $ob, int $sb) use (&$created): void {
            $created[] = [$o, $s, $ob > $sb];
        }, 10, 4);
        $path = $this->jpeg('2026/11/c.jpg', 200, 120);
        SiblingCreator::create($path);
        self::assertSame([[$path, $path . '.webp', true]], $created);
        self::assertSame([], \WPStub::$httpLog, 'test 64: no purge request');
    }
}
