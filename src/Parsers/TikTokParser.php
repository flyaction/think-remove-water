<?php
namespace Flyaction\ThinkRemoveWater\Parsers;

use Flyaction\ThinkRemoveWater\Core\HttpClient;

class TikTokParser extends BaseParser
{
    protected array $domains = ['tiktok.com'];

    public function getPlatform(): string
    {
        return 'tiktok';
    }

    public function parse(string $url): array
    {
        $url = $this->resolveUrl($url);

        $html = HttpClient::get($url, [
            'Referer: https://www.tiktok.com/',
        ]);

        if (!$html) {
            throw new \RuntimeException('无法获取TikTok页面');
        }

        // 从 __UNIVERSAL_DATA_FOR_REHYDRATION__ 提取
        $data = $this->extractJson($html, '/<script id="__UNIVERSAL_DATA_FOR_REHYDRATION__" type="application\/json">(.*?)<\/script>/s');

        if ($data) {
            $item = $this->findVideoDetail($data);
            if ($item) {
                $videoUrl = $item['video']['playAddr'] ?? $item['video']['downloadAddr'] ?? null;
                return $this->buildResult([
                    'title'  => $item['desc'] ?? '',
                    'author' => $item['author']['nickname'] ?? '',
                    'cover'  => $item['video']['cover'] ?? $item['video']['originCover'] ?? '',
                    'video'  => $videoUrl,
                    'music'  => $item['music']['playUrl'] ?? null,
                ]);
            }
        }

        // 备用：SIGI_STATE
        $sigi = $this->extractJson($html, '/<script id="SIGI_STATE" type="application\/json">(.*?)<\/script>/s');
        if ($sigi && isset($sigi['ItemModule'])) {
            $item = reset($sigi['ItemModule']);
            return $this->buildResult([
                'title'  => $item['desc'] ?? '',
                'author' => $sigi['UserModule']['users'][$item['author']]['nickname'] ?? '',
                'cover'  => $item['video']['cover'] ?? '',
                'video'  => $item['video']['downloadAddr'] ?? $item['video']['playAddr'] ?? null,
                'music'  => $item['music']['playUrl'] ?? null,
            ]);
        }

        throw new \RuntimeException('无法解析TikTok视频');
    }

    private function findVideoDetail(array $data): ?array
    {
        foreach (['itemStruct', 'videoDetail'] as $key) {
            $detail = $this->findNestedValue($data, $key);
            if (is_array($detail)) {
                return $detail;
            }
        }
        return null;
    }
}
