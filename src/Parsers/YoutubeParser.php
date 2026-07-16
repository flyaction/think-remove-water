<?php
namespace Flyaction\ThinkRemoveWater\Parsers;

use Flyaction\ThinkRemoveWater\Core\HttpClient;

class YoutubeParser extends BaseParser
{
    protected array $domains = ['youtube.com', 'youtu.be'];

    public function getPlatform(): string
    {
        return 'youtube';
    }

    public function parse(string $url): array
    {
        $url = $this->resolveUrl($url);

        $videoId = null;
        if (preg_match('/(?:v=|\/embed\/|youtu\.be\/)([\w-]{11})/', $url, $m)) {
            $videoId = $m[1];
        }

        if (!$videoId) {
            throw new \RuntimeException('无法解析YouTube视频ID');
        }

        $html = HttpClient::get("https://www.youtube.com/watch?v={$videoId}", [
            'Referer: https://www.youtube.com/',
        ]);

        if (!$html) {
            throw new \RuntimeException('无法获取YouTube页面');
        }

        // 提取 ytInitialPlayerResponse
        $playerData = $this->extractJson($html, '/var ytInitialPlayerResponse\s*=\s*(\{.*?\});/s')
                   ?? $this->extractJson($html, '/ytInitialPlayerResponse\s*=\s*(\{.*?\});/s');

        if ($playerData) {
            $details = $playerData['videoDetails'] ?? [];
            $streaming = $playerData['streamingData'] ?? [];

            $videoUrl = null;
            $formats = $streaming['formats'] ?? [];
            $adaptive = $streaming['adaptiveFormats'] ?? [];

            foreach (array_merge($formats, $adaptive) as $fmt) {
                if (isset($fmt['mimeType']) && str_contains($fmt['mimeType'], 'video') && isset($fmt['url'])) {
                    $videoUrl = $fmt['url'];
                    break;
                }
            }

            return $this->buildResult([
                'title'  => $details['title'] ?? '',
                'author' => $details['author'] ?? '',
                'cover'  => $details['thumbnail']['thumbnails'][0]['url'] ?? "https://i.ytimg.com/vi/{$videoId}/maxresdefault.jpg",
                'video'  => $videoUrl,
                'extra'  => [
                    'video_id' => $videoId,
                    'duration' => $details['lengthSeconds'] ?? 0,
                ],
            ]);
        }

        return $this->buildResult([
            'title'  => '',
            'author' => '',
            'cover'  => "https://i.ytimg.com/vi/{$videoId}/maxresdefault.jpg",
            'video'  => null,
            'extra'  => ['video_id' => $videoId, 'note' => '需要额外解析服务获取下载链接'],
        ]);
    }
}
