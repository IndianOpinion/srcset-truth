<?php

/**
 * State behind the WordPress stub layer. Tests set it; stub functions read it.
 */

declare(strict_types=1);

final class WPStub
{
    /** @var array<string, array<int, list<array{0: callable, 1: int}>>> */
    public static array $filters = [];
    /** @var array<string, int> */
    public static array $actionsDone = [];
    /** @var array<string, mixed> */
    public static array $options = [];
    /** @var array<string, bool> */
    public static array $autoload = [];
    /** @var array<string, bool> */
    public static array $conditionals = [];
    /** @var array<string, mixed> */
    public static array $queryVars = [];
    public static ?object $queriedObject = null;
    public static string $postType = 'post';
    /** @var array<int, array<string, mixed>> */
    public static array $attachmentMeta = [];
    /** @var array<int, array<string, mixed>> */
    public static array $postMeta = [];
    /** @var array<int, string> */
    public static array $attachedFiles = [];
    public static string $basedir = '';
    public static string $baseurl = 'https://example.test/wp-content/uploads';
    public static string $home = 'https://example.test';
    /** @var array<string, bool> */
    public static array $caps = [];
    /** @var array<string, array{time: int, schedule: string}> */
    public static array $cron = [];
    /** @var list<array{method: string, url: string, args: array<string, mixed>}> */
    public static array $httpLog = [];
    /** @var array<string, array<string, mixed>|WP_Error> url => response */
    public static array $httpResponses = [];
    /** @var list<string> */
    public static array $settingsErrors = [];
    public static bool $isAdmin = false;
    public static bool $doingAjax = false;
    public static bool $doingCron = false;

    public static function reset(): void
    {
        self::$filters = [];
        self::$actionsDone = [];
        self::$options = [];
        self::$autoload = [];
        self::$conditionals = [];
        self::$queryVars = [];
        self::$queriedObject = null;
        self::$postType = 'post';
        self::$attachmentMeta = [];
        self::$postMeta = [];
        self::$attachedFiles = [];
        self::$basedir = '';
        self::$baseurl = 'https://example.test/wp-content/uploads';
        self::$home = 'https://example.test';
        self::$caps = [];
        self::$cron = [];
        self::$httpLog = [];
        self::$httpResponses = [];
        self::$settingsErrors = [];
        self::$isAdmin = false;
        self::$doingAjax = false;
        self::$doingCron = false;
        $GLOBALS['content_width'] = null;
        $_GET = [];
        unset($_SERVER['HTTP_X_SRCSET_TRUTH_AUDIT']);
    }

    public static function hookCount(): int
    {
        $count = 0;
        foreach (self::$filters as $byPriority) {
            foreach ($byPriority as $list) {
                $count += count($list);
            }
        }

        return $count;
    }

    /**
     * @param array<string, string> $headers
     */
    public static function respond(string $url, int $code, string $body = '', array $headers = []): void
    {
        self::$httpResponses[$url] = [
            'response' => ['code' => $code],
            'headers' => array_change_key_case($headers, CASE_LOWER),
            'body' => $body,
        ];
    }
}

class WP_Error
{
    public function __construct(private string $code = '', private string $message = '')
    {
    }

    public function get_error_message(): string
    {
        return $this->message;
    }

    public function get_error_code(): string
    {
        return $this->code;
    }
}
