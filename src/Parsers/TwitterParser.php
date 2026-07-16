<?php
namespace Flyaction\ThinkRemoveWater\Parsers;

use Flyaction\ThinkRemoveWater\Core\HttpClient;

class TwitterParser extends BaseParser
{
    protected array $domains = ['twitter.com', 'x.com'];

    public function getPlatform(): string
    {
        return 'twitter';
    }

    public function parse(string $url): array
    {
        $url = $this->resolveUrl($url);

        // 使用 syndication API（无需认证）
        $tweetId = null;
        if (preg_match('/status\/(\d+)/', $url, $m)) {
            $tweetId = $m[1];
        }

        if (!$tweetId) {
            throw new \RuntimeException('无法解析推文ID');
        }

        $apiUrl = "https://cdn.syndication.twimg.com/tweet-result?id={$tweetId}&lang=zh&token=0";
        $response = HttpClient::get($apiUrl);

        if ($response) {
            $json = json_decode($response, true);
            if ($json) {
                $videoUrl = null;
                $images = [];

                if (isset($json['mediaDetails'])) {
                    foreach ($json['mediaDetails'] as $media) {
                        if ($media['type'] === 'video' || $media['type'] === 'animated_gif') {
                            $variants = $media['video_info']['variants'] ?? [];
                            usort($variants, fn($a, $b) => ($b['bitrate'] ?? 0) - ($a['bitrate'] ?? 0));
                            $videoUrl = $variants[0]['url'] ?? null;
                        } elseif ($media['type'] === 'photo') {
                            $images[] = $media['media_url_https'] ?? '';
                        }
                    }
                }

                return $this->buildResult([
                    'title'  => $json['text'] ?? '',
                    'author' => $json['user']['name'] ?? '',
                    'cover'  => $images[0] ?? '',
                    'type'   => $videoUrl ? 'video' : 'image',
                    'video'  => $videoUrl,
                    'images' => $images,
                ]);
            }
        }

        throw new \RuntimeException('无法解析Twitter/X内容');
    }
}
