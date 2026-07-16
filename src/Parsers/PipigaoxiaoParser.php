<?php
namespace Flyaction\ThinkRemoveWater\Parsers;

use Flyaction\ThinkRemoveWater\Core\HttpClient;

class PipigaoxiaoParser extends BaseParser
{
    protected array $domains = ['pipigx.com', 'ppgx.com'];

    public function getPlatform(): string
    {
        return 'pipigaoxiao';
    }

    public function parse(string $url): array
    {
        $url = $this->resolveUrl($url);
        $headers = $this->mobileHeaders('https://www.pipigx.com/');
        $html = HttpClient::get($url, $headers);

        if (!$html) {
            throw new \RuntimeException('无法获取皮皮搞笑页面');
        }

        if (preg_match('/window\.__NUXT__\s*=\s*(\{.*?\});/s', $html, $m)) {
            $data = json_decode($m[1], true);
            $post = $this->findNestedValue($data, 'post') ?? $this->findNestedValue($data, 'detail');
            if ($post) {
                return $this->buildResult([
                    'title'  => $post['content'] ?? $post['title'] ?? '',
                    'author' => $post['member']['nickname'] ?? '',
                    'video'  => $post['videos'][0]['url'] ?? $post['video']['url'] ?? null,
                    'images' => $post['imgs'] ?? [],
                    'cover'  => $post['imgs'][0] ?? '',
                    'type'   => !empty($post['videos']) ? 'video' : 'image',
                ]);
            }
        }

        if (preg_match('/"url_high"\s*:\s*"([^"]+)"/', $html, $m)) {
            return $this->buildResult([
                'title' => $this->extractOgMeta($html)['title'],
                'video' => stripslashes($m[1]),
            ]);
        }

        $og = $this->resultFromOg($html);
        if ($og) {
            return $og;
        }

        throw new \RuntimeException('皮皮搞笑解析失败');
    }
}
