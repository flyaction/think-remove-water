<?php
namespace Flyaction\ThinkRemoveWater\Parsers;

use Flyaction\ThinkRemoveWater\Core\HttpClient;

class DoupaiParser extends BaseParser
{
    protected array $domains = ['doupai.cc', 'doupaiapp.com'];

    public function getPlatform(): string
    {
        return 'doupai';
    }

    public function parse(string $url): array
    {
        $url = $this->resolveUrl($url);
        $html = HttpClient::get($url, $this->mobileHeaders('https://www.doupai.cc/'));

        if (!$html) {
            throw new \RuntimeException('无法获取逗拍页面');
        }

        if (preg_match('/"videoUrl"\s*:\s*"([^"]+)"/', $html, $m)) {
            return $this->buildResult([
                'title' => $this->extractOgMeta($html)['title'],
                'video' => stripslashes($m[1]),
            ]);
        }

        $og = $this->resultFromOg($html);
        if ($og) {
            return $og;
        }

        throw new \RuntimeException('逗拍解析失败');
    }
}
