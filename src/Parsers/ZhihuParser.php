<?php
namespace Flyaction\ThinkRemoveWater\Parsers;

use Flyaction\ThinkRemoveWater\Core\HttpClient;

/** 知乎 */
class ZhihuParser extends BaseParser
{
    protected array $domains = ['zhihu.com'];

    public function getPlatform(): string
    {
        return 'zhihu';
    }

    public function parse(string $url): array
    {
        $url = $this->resolveUrl($url);
        $headers = $this->mobileHeaders('https://www.zhihu.com/');
        $html = HttpClient::get($url, $headers);

        if (!$html) {
            throw new \RuntimeException('无法获取知乎页面');
        }

        if (preg_match('/"playlist"\s*:\s*\{[^}]*"ld"\s*:\s*\{[^}]*"playUrl"\s*:\s*"([^"]+)"/s', $html, $m)) {
            $images = [];
            if (preg_match_all('/"original"\s*:\s*"([^"]+)"/', $html, $im)) {
                $images = array_slice(array_unique($im[1]), 0, 20);
            }
            return $this->buildResult([
                'title'  => $this->extractOgMeta($html)['title'],
                'video'  => stripslashes($m[1]),
                'images' => $images,
                'type'   => 'video',
            ]);
        }

        if (preg_match_all('/"original"\s*:\s*"(https?:[^"]+)"/', $html, $im)) {
            $images = array_values(array_unique($im[1]));
            if (!empty($images)) {
                return $this->buildResult([
                    'title'  => $this->extractOgMeta($html)['title'],
                    'images' => $images,
                    'cover'  => $images[0],
                    'type'   => 'image',
                ]);
            }
        }

        $og = $this->resultFromOg($html);
        if ($og) {
            return $og;
        }

        throw new \RuntimeException('知乎解析失败');
    }
}
