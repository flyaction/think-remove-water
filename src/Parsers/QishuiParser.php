<?php
namespace Flyaction\ThinkRemoveWater\Parsers;

use Flyaction\ThinkRemoveWater\Core\HttpClient;

class QishuiParser extends BaseParser
{
    protected array $domains = ['qishui.douyin.com', 'music.douyin.com'];

    public function getPlatform(): string
    {
        return 'qishui';
    }

    public function parse(string $url): array
    {
        $url = $this->resolveUrl($url);
        $headers = $this->mobileHeaders('https://www.douyin.com/');
        $html = HttpClient::get($url, $headers);

        if (!$html) {
            throw new \RuntimeException('无法获取汽水音乐页面');
        }

        $item = $this->parseByteShareHtml($html);
        if ($item) {
            $result = $this->buildFromAwemeItem($item);
            if (!$result['video'] && $result['music']) {
                $result['video'] = $result['music'];
                $result['type'] = 'video';
            }
            return $result;
        }

        if (preg_match('/"play_url"\s*:\s*"([^"]+)"/', $html, $m)) {
            return $this->buildResult([
                'title' => $this->extractOgMeta($html)['title'],
                'video' => $this->normalizeMediaUrl(stripslashes($m[1])),
                'type'  => 'video',
            ]);
        }

        throw new \RuntimeException('汽水音乐解析失败');
    }
}
