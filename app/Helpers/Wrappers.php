<?php
namespace App\Helpers;

use App\Cache\ApcuCache;
use App\Cache\JSONCache;
use App\Cache\RedisCache;
use App\Constants\CacheMethods;
use App\Constants\TextExtras;
use App\Models\BaseTemplate;

class Wrappers {
    /**
     * Setup of Latte template engine
     */
    public static function latte(string $template, BaseTemplate $base) {
        $latte = new \Latte\Engine;
        $cache_path = Misc::env('LATTE_CACHE', __DIR__ . '/../../cache/latte');
        $latte->setTempDirectory($cache_path);

        // -- CUSTOM FUNCTIONS -- //
        // Get URL with optional endpoint
        $latte->addFunction('path', function (string $endpoint = ''): string {
            return Misc::url($endpoint);
        });

        // Static assets
        $latte->addFunction('static', function (string $type, string $file, bool $isVendor = false): string {
            $endpoint = '';
            switch ($type) {
                case 'js':
                    $endpoint .= '/scripts';
                    break;
                case 'css':
                    $endpoint .= '/styles';
                    break;
                default:
                    throw new \Exception('Invalid static asset type');
            }

            if ($isVendor) $endpoint .= '/vendor';

            $endpoint .= '/' . $file;

            return Misc::url($endpoint);
        });

        $latte->addFunction('theme', function(): string {
            return Cookies::theme();
        });

        // Version being used
        $latte->addFunction('version_frontend', function (): string {
            return \Composer\InstalledVersions::getVersion('pablouser1/proxitok');
        });
        $latte->addFunction('version_scraper', function (): string {
            return \Composer\InstalledVersions::getVersion('pablouser1/tikscraper');
        });

        // https://stackoverflow.com/a/36365553
        $latte->addFunction('number', function (float $x) {
            if($x > 1000) {
                $x_number_format = number_format($x);
                $x_array = explode(',', $x_number_format);
                $x_parts = array('K', 'M', 'B', 'T');
                $x_count_parts = count($x_array) - 1;
                $x_display = $x;
                $x_display = $x_array[0] . ((int) $x_array[1][0] !== 0 ? '.' . $x_array[1][0] : '');
                $x_display .= $x_parts[$x_count_parts - 1];
                return $x_display;
            }
            return $x;
        });

        // UrlBuilder
        $latte->addFunction('url_stream', function (string $url): string {
            return UrlBuilder::stream($url);
        });
        $latte->addFunction('url_user', function (string $username): string {
            return UrlBuilder::user($username);
        });
        $latte->addFunction('url_tag', function (string $tag): string {
            return UrlBuilder::tag($tag);
        });
        $latte->addFunction('url_video_internal', function (string $username, string $id): string {
            return UrlBuilder::video_internal($username, $id);
        });
        $latte->addFunction('url_video_external', function (string $username, string $id): string {
            return UrlBuilder::video_external($username, $id);
        });
        $latte->addFunction('url_download', function (string $url, string $username, string $id, bool $watermark): string {
            return UrlBuilder::download($url, $username, $id, $watermark);
        });

        $latte->addFunction('bool_to_str', function (bool $cond): string {
            return $cond ? 'yes' : 'no';
        });

        // Add URLs to video descriptions
        // TODO: Make it work with unicode characters such as emojis
        $latte->addFunction('render_desc', function (string $desc, array $textExtras = []): string {
            $bytesCount = strlen($desc);
            $charsCount = mb_strlen($desc);
            // Skip urlify for now if there is a special char like an emoji to avoid issues
            if ($bytesCount !== $charsCount) {
                return $desc;
            }
            $sanitizedDesc = htmlspecialchars($desc);
            $out = $sanitizedDesc;
            foreach ($textExtras as $extra) {
                $url = '';
                $text = mb_substr($desc, $extra->start, $extra->end - $extra->start, 'UTF-8');
                switch ($extra->type) {
                    // User URL
                    case TextExtras::USER:
                        $url = UrlBuilder::user(htmlspecialchars($extra->userUniqueId));
                        break;
                    // Hashtag URL
                    case TextExtras::HASHTAG:
                        $url = UrlBuilder::tag(htmlspecialchars($extra->hashtagName));
                        break;
                }

                $out = str_replace($text, "<a href=\"$url\">$text</a>", $out);
            }
            return $out;
        });

        $latte->render(Misc::getView($template), $base);
    }

    /**
     * Setup of TikTok Api wrapper
     */
    public static function api(): \TikScraper\Api {
        $method = Misc::env('API_SIGNER', '');
        $url = Misc::env('API_SIGNER_URL', '');
        if (!$method) {
            // Legacy support
            $browser_url = Misc::env('API_BROWSER_URL', '');
            if ($url) {
                $method = 'remote';
            } elseif ($browser_url) {
                $url = $browser_url;
                $method = 'browser';
            }
        }

        $options = [
            'use_test_endpoints' => Misc::env('API_TEST_ENDPOINTS', false) || isset($_COOKIE['api-test_endpoints']) && $_COOKIE['api-test_endpoints'] === 'yes',
            'signer' => [
                'method' => $method,
                'url' => $url,
                'close_when_done' => false
            ]
        ];

        // -- PROXY CONFIG -- //
        $proxy_host = Misc::env('PROXY_HOST', '');
        $proxy_port = Misc::env('PROXY_PORT', '');

        if ($proxy_host && $proxy_port) {
            $options['proxy'] = [
                'host' => $proxy_host,
                'port' => $proxy_port,
                'username' => Misc::env('PROXY_USERNAME', null),
                'password' => Misc::env('PROXY_PASSWORD', null)
            ];
        }

        // Cache config
        $cacheEngine = null;
        if (isset($_ENV['API_CACHE'])) {
            switch ($_ENV['API_CACHE']) {
                case CacheMethods::JSON:
                    $cacheEngine = new JSONCache();
                    break;
                case CacheMethods::APCU:
                    $cacheEngine = new ApcuCache();
                    break;
                case CacheMethods::REDIS:
                    if (!(isset($_ENV['REDIS_URL']) || isset($_ENV['REDIS_HOST'], $_ENV['REDIS_PORT']))) {
                        throw new \Exception('You need to set REDIS_URL or REDIS_HOST and REDIS_PORT to use Redis Cache!');
                    }

                    if (isset($_ENV['REDIS_URL'])) {
                        $url = parse_url($_ENV['REDIS_URL']);
                        $host = $url['host'];
                        $port = $url['port'];
                        $password = $url['pass'] ?? null;
                    } else {
                        $host = $_ENV['REDIS_HOST'];
                        $port = (int) $_ENV['REDIS_PORT'];
                        $password = isset($_ENV['REDIS_PASSWORD']) ? $_ENV['REDIS_PASSWORD'] : null;
                    }
                    $cacheEngine = new RedisCache($host, $port, $password);
                    break;
            }
        }

        $customUa = Misc::env("USER_AGENT", '');

        if ($customUa) {
            $options['user_agent'] = $customUa;
        }

        return new \TikScraper\Api($options, $cacheEngine);
    }
}
