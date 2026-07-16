<?php
namespace Flyaction\ThinkRemoveWater\Parsers;

use Flyaction\ThinkRemoveWater\Core\HttpClient;

/**
 * 字节系分享页通用解析（抖音/西瓜/火山/头条等）
 */
abstract class BytedanceShareParser extends BaseParser
{
    abstract protected function getShareHost(): string;

    abstract protected function getReferer(): string;

    protected function getMediaReferer(): string
    {
        return $this->getReferer();
    }

    protected function allowOgFallback(): bool
    {
        return true;
    }

    public function parse(string $url): array
    {
        $url = $this->resolveUrl($url);
        $isNote = (bool) preg_match('#/note/#', $url);
        $videoId = $this->extractId($url, [
            '/share\/video\/(\d+)/',
            '/video\/(\d+)/',
            '/item\/(\d+)/',
            '/article\/(\d+)/',
            '/note\/(\d+)/',
            '/modal_id=(\d+)/',
        ]);

        if (!$videoId) {
            throw new \RuntimeException('无法解析视频ID');
        }

        $headers = $this->mobileHeaders($this->getReferer());
        $shareUrls = $this->getShareUrls($videoId, $isNote);
        $lastHtml = null;
        $lastItem = null;

        foreach ($shareUrls as $shareUrl) {
            $html = HttpClient::get($shareUrl, $headers);
            if (!$html) {
                continue;
            }
            $lastHtml = $html;

            $item = $this->parseByteShareHtml($html);
            if ($item) {
                $lastItem = $item;
                $result = $this->buildFromAwemeItem($item);
                $result = $this->attachVideoFromHtml($result, $html);
                if (!empty($result['video']) || ($result['type'] === 'image' && !empty($result['images']))) {
                    return $this->ensureCover($result, $html);
                }
            }

            $fallback = $this->buildResultFromHtmlFallback($html);
            if ($fallback && (!empty($fallback['video']) || ($fallback['type'] === 'image' && !empty($fallback['images'])))) {
                return $this->mergeItemMeta($fallback, $item ?? $lastItem, $html);
            }
        }

        if ($lastHtml) {
            $item = $lastItem ?? $this->parseByteShareHtml($lastHtml);
            if (is_array($item)) {
                $result = $this->buildFromAwemeItem($item);
                $result = $this->attachVideoFromHtml($result, $lastHtml);
                if (!empty($result['video']) || ($result['type'] === 'image' && !empty($result['images']))) {
                    return $this->ensureCover($result, $lastHtml);
                }
            }

            $fallback = $this->buildResultFromHtmlFallback($lastHtml);
            if ($fallback && (!empty($fallback['video']) || ($fallback['type'] === 'image' && !empty($fallback['images'])))) {
                return $this->mergeItemMeta($fallback, $item, $lastHtml);
            }

            if ($this->allowOgFallback()) {
                $og = $this->resultFromOg($lastHtml);
                if ($og) {
                    return $og;
                }
            }
        }

        throw new \RuntimeException('无法获取视频播放地址，请稍后重试或更换链接');
    }

    /** @return string[] */
    protected function getShareUrls(string $videoId, bool $isNote = false): array
    {
        if ($isNote) {
            return ['https://' . $this->getShareHost() . '/share/note/' . $videoId . '/'];
        }
        return ['https://' . $this->getShareHost() . '/share/video/' . $videoId . '/'];
    }

    private function mergeItemMeta(array $result, ?array $item, string $html): array
    {
        if (is_array($item)) {
            if (empty($result['author'])) {
                $result['author'] = $item['author']['nickname'] ?? $item['author_info']['nickname'] ?? '';
            }
            if (empty($result['caption']) && empty($result['title'])) {
                $result['caption'] = $item['desc'] ?? $item['title'] ?? '';
                $result['title'] = $result['caption'];
            }
            if (empty($result['cover'])) {
                $cover = $this->extractCoverFromVideo($item['video'] ?? [], $item);
                if ($cover) {
                    $result['cover'] = $cover;
                }
            }
        }
        return $this->ensureCover($result, $html);
    }

    protected function attachVideoFromHtml(array $result, string $html): array
    {
        if (!empty($result['video']) || (($result['type'] ?? '') === 'image' && !empty($result['images']))) {
            return $result;
        }

        $playRaw = $this->extractPlayUrlFromHtml($html);
        if (!$playRaw) {
            return $result;
        }

        $result['video'] = $this->normalizeMediaUrl($playRaw);
        $result['type'] = 'video';
        $result['video_direct'] = false;

        return $result;
    }
}
