<?php
namespace Flyaction\ThinkRemoveWater\Parsers;

use Flyaction\ThinkRemoveWater\Core\HttpClient;

class InstagramParser extends BaseParser
{
    protected array $domains = ['instagram.com'];

    public function getPlatform(): string
    {
        return 'instagram';
    }

    public function parse(string $url): array
    {
        $url = $this->resolveUrl($url);

        $html = HttpClient::get($url . '?__a=1&__d=dis', [
            'Referer: https://www.instagram.com/',
            'X-IG-App-ID: 936619743392459',
        ]);

        if ($html) {
            $json = json_decode($html, true);
            if ($json) {
                return $this->parseFromJson($json);
            }
        }

        // 备用：从页面 og 标签提取
        $html = HttpClient::get($url, [
            'Referer: https://www.instagram.com/',
        ]);

        if (!$html) {
            throw new \RuntimeException('无法获取Instagram页面');
        }

        $videoUrl = null;
        $imageUrl = null;

        if (preg_match('/property="og:video" content="([^"]+)"/', $html, $m)) {
            $videoUrl = html_entity_decode($m[1]);
        }
        if (preg_match('/property="og:image" content="([^"]+)"/', $html, $m)) {
            $imageUrl = html_entity_decode($m[1]);
        }

        $title = '';
        if (preg_match('/property="og:title" content="([^"]+)"/', $html, $m)) {
            $title = html_entity_decode($m[1]);
        }

        if (!$videoUrl && !$imageUrl) {
            throw new \RuntimeException('无法解析Instagram内容');
        }

        return $this->buildResult([
            'title'  => $title,
            'cover'  => $imageUrl ?? '',
            'type'   => $videoUrl ? 'video' : 'image',
            'video'  => $videoUrl,
            'images' => $imageUrl ? [$imageUrl] : [],
        ]);
    }

    private function parseFromJson(array $json): array
    {
        $media = $json['graphql']['shortcode_media'] ?? $json['items'][0] ?? null;
        if (!$media) {
            throw new \RuntimeException('无法解析Instagram数据');
        }

        $isVideo = $media['is_video'] ?? $media['media_type'] === 2;
        $images = [];

        if (isset($media['edge_sidecar_to_children'])) {
            foreach ($media['edge_sidecar_to_children']['edges'] as $edge) {
                $node = $edge['node'];
                if ($node['is_video'] ?? false) {
                    $images[] = $node['video_url'] ?? '';
                } else {
                    $images[] = $node['display_url'] ?? '';
                }
            }
        }

        return $this->buildResult([
            'title'  => $media['edge_media_to_caption']['edges'][0]['node']['text'] ?? $media['caption']['text'] ?? '',
            'author' => $media['owner']['username'] ?? '',
            'cover'  => $media['display_url'] ?? $media['thumbnail_src'] ?? '',
            'type'   => $isVideo ? 'video' : 'image',
            'video'  => $media['video_url'] ?? null,
            'images' => $images ?: [$media['display_url'] ?? ''],
        ]);
    }
}
