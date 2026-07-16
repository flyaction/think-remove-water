<?php
namespace Flyaction\ThinkRemoveWater\Parsers;

use Flyaction\ThinkRemoveWater\Core\HttpClient;

class BilibiliParser extends BaseParser
{
    protected array $domains = ['bilibili.com', 'b23.tv'];

    public function getPlatform(): string
    {
        return 'bilibili';
    }

    public function parse(string $url): array
    {
        $url = $this->resolveUrl($url);

        $bvid = null;
        $aid = null;

        if (preg_match('/BV[\w]+/', $url, $m)) {
            $bvid = $m[0];
        } elseif (preg_match('/av(\d+)/', $url, $m)) {
            $aid = $m[1];
        }

        if (!$bvid && !$aid) {
            throw new \RuntimeException('无法解析B站视频ID');
        }

        $infoUrl = $bvid
            ? "https://api.bilibili.com/x/web-interface/view?bvid={$bvid}"
            : "https://api.bilibili.com/x/web-interface/view?aid={$aid}";

        $infoResponse = HttpClient::get($infoUrl, [
            'Referer: https://www.bilibili.com/',
        ]);

        if (!$infoResponse) {
            throw new \RuntimeException('B站API请求失败');
        }

        $info = json_decode($infoResponse, true);
        if (($info['code'] ?? -1) !== 0) {
            throw new \RuntimeException($info['message'] ?? '获取视频信息失败');
        }

        $data = $info['data'];
        $cid = $data['cid'] ?? ($data['pages'][0]['cid'] ?? null);
        $aid = $data['aid'];
        $bvid = $data['bvid'];

        if (!$cid) {
            throw new \RuntimeException('无法获取视频分P信息');
        }

        $playMeta = $this->fetchPlayUrl((int) $aid, $bvid, (int) $cid);
        if (!$playMeta || empty($playMeta['video'])) {
            throw new \RuntimeException('无法获取B站视频播放地址，请稍后重试');
        }

        return $this->buildResult([
            'title'        => $data['title'] ?? '',
            'author'       => $data['owner']['name'] ?? '',
            'cover'        => $data['pic'] ?? '',
            'video'        => $playMeta['video'],
            'type'         => 'video',
            'video_direct' => $playMeta['direct'] ?? false,
            'extra'        => [
                'aid'          => $aid,
                'bvid'         => $bvid,
                'cid'          => $cid,
                'duration'     => $data['duration'] ?? 0,
                'quality'      => $playMeta['quality'] ?? 0,
                'format'       => $playMeta['format'] ?? 'mp4',
                'video_direct' => $playMeta['direct'] ?? false,
            ],
        ]);
    }

    private function fetchPlayUrl(int $aid, string $bvid, int $cid): ?array
    {
        $referer = "https://www.bilibili.com/video/{$bvid}";
        $headers = ['Referer: ' . $referer];

        $attempts = [
            ['platform' => 'html5', 'fnval' => 1, 'qn' => 80, 'high_quality' => 1, 'try_look' => 1, 'direct' => true],
            ['platform' => 'html5', 'fnval' => 1, 'qn' => 64, 'try_look' => 1, 'direct' => true],
            ['platform' => 'html5', 'fnval' => 1, 'qn' => 32, 'try_look' => 1, 'direct' => true],
            ['platform' => 'pc', 'fnval' => 1, 'qn' => 64, 'try_look' => 1, 'direct' => false],
            ['platform' => 'pc', 'fnval' => 1, 'qn' => 32, 'direct' => false],
        ];

        foreach ($attempts as $attempt) {
            $direct = $attempt['direct'];
            unset($attempt['direct']);

            $params = array_merge([
                'avid'   => $aid,
                'bvid'   => $bvid,
                'cid'    => $cid,
                'fnver'  => 0,
                'fourk'  => 0,
                'otype'  => 'json',
            ], $attempt);

            $result = $this->requestPlayUrl('https://api.bilibili.com/x/player/wbi/playurl', $params, $headers, $direct);
            if ($result) {
                return $result;
            }
        }

        // 旧接口兜底
        foreach ($attempts as $attempt) {
            $direct = $attempt['direct'];
            unset($attempt['direct']);

            $params = array_merge([
                'avid'  => $aid,
                'bvid'  => $bvid,
                'cid'   => $cid,
                'fnver' => 0,
                'fourk' => 0,
            ], $attempt);

            $query = http_build_query($params);
            $result = $this->requestPlayUrl(
                'https://api.bilibili.com/x/player/playurl?' . $query,
                [],
                $headers,
                $direct,
                false
            );
            if ($result) {
                return $result;
            }
        }

        return null;
    }

    private function requestPlayUrl(
        string $endpoint,
        array $params,
        array $headers,
        bool $direct,
        bool $sign = true
    ): ?array {
        try {
            if ($sign) {
                $params = BilibiliWbi::signParams($params);
            }
            $url = str_contains($endpoint, '?')
                ? $endpoint
                : $endpoint . '?' . http_build_query($params);

            $response = HttpClient::get($url, $headers);
            if (!$response) {
                return null;
            }

            $play = json_decode($response, true);
            return $this->extractVideoFromPlayData($play, $direct);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function extractVideoFromPlayData(?array $play, bool $direct): ?array
    {
        if (!$play || ($play['code'] ?? -1) !== 0) {
            return null;
        }

        $data = $play['data'] ?? [];

        if (!empty($data['durl']) && is_array($data['durl'])) {
            $segment = $data['durl'][0];
            $videoUrl = $segment['url'] ?? null;
            if (!$videoUrl && !empty($segment['backup_url'][0])) {
                $videoUrl = $segment['backup_url'][0];
            }
            if ($videoUrl) {
                return [
                    'video'   => $this->normalizeMediaUrl($videoUrl),
                    'format'  => $data['format'] ?? 'mp4',
                    'quality' => $data['quality'] ?? 0,
                    'direct'  => $direct,
                ];
            }
        }

        return null;
    }
}
