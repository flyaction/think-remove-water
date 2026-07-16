<?php
namespace Flyaction\ThinkRemoveWater\Parsers;

use Flyaction\ThinkRemoveWater\Core\HttpClient;

class WeixinChannelsParser extends BaseParser
{
    protected array $domains = ['channels.weixin.qq.com', 'finder.video.qq.com'];

    public function getPlatform(): string
    {
        return 'channels';
    }

    public function parse(string $url): array
    {
        $url = $this->resolveUrl($url);
        $headers = $this->mobileHeaders('https://channels.weixin.qq.com/');
        $html = HttpClient::get($url, $headers);

        if (!$html) {
            throw new \RuntimeException('无法获取视频号页面');
        }

        // 视频号 SSR 数据
        if (preg_match('/window\.__INITIAL_STATE__\s*=\s*(\{.*?\})\s*;/s', $html, $m)) {
            $data = json_decode($m[1], true);
            $feed = $this->findNestedValue($data, 'objectDesc')
                 ?? $this->findNestedValue($data, 'media');
            if ($feed) {
                $videoUrl = $feed['media'][0]['url'] ?? $feed['videoUrl'] ?? null;
                $images = [];
                if (!empty($feed['media'])) {
                    foreach ($feed['media'] as $m) {
                        if (($m['type'] ?? 0) == 2 && !empty($m['url'])) {
                            $images[] = $m['url'];
                        }
                    }
                }
                return $this->buildResult([
                    'title'  => $feed['description'] ?? $feed['title'] ?? '',
                    'author' => $feed['nickname'] ?? '',
                    'cover'  => $feed['thumbUrl'] ?? '',
                    'type'   => $videoUrl ? 'video' : 'image',
                    'video'  => $videoUrl,
                    'images' => $images,
                ]);
            }
        }

        if (preg_match('/"url"\s*:\s*"(https?:[^"]+\.mp4[^"]*)"/', $html, $m)) {
            return $this->buildResult([
                'title' => $this->extractOgMeta($html)['title'],
                'video' => stripslashes($m[1]),
            ]);
        }

        $og = $this->resultFromOg($html);
        if ($og) {
            return $og;
        }

        throw new \RuntimeException('视频号解析失败，请使用完整分享链接');
    }
}
