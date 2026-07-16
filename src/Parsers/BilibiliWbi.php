<?php
namespace Flyaction\ThinkRemoveWater\Parsers;

use Flyaction\ThinkRemoveWater\Core\HttpClient;

class BilibiliWbi
{
    private const ENC_TAB = [
        46, 47, 18, 2, 53, 8, 23, 32, 15, 50, 10, 31, 58, 3, 45, 35, 27, 43, 5, 49,
        33, 9, 42, 19, 29, 28, 14, 39, 12, 38, 41, 13, 37, 48, 7, 16, 24, 55, 40,
        61, 26, 17, 0, 1, 60, 51, 30, 4, 22, 25, 54, 21, 56, 59, 6, 63, 57, 62, 11,
        36, 20, 34, 44, 52,
    ];

    public static function signParams(array $params): array
    {
        [$imgKey, $subKey] = self::getKeys();
        $wts = time();
        $signParams = array_merge($params, ['wts' => $wts]);
        ksort($signParams);

        $pairs = [];
        foreach ($signParams as $key => $value) {
            $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }

        $params['wts'] = $wts;
        $params['w_rid'] = md5(implode('&', $pairs) . self::mixinKey($imgKey . $subKey));

        return $params;
    }

    private static function mixinKey(string $raw): string
    {
        $out = '';
        for ($i = 0; $i < 32; $i++) {
            $out .= $raw[self::ENC_TAB[$i]];
        }
        return $out;
    }

    private static function getKeys(): array
    {
        $res = HttpClient::get('https://api.bilibili.com/x/web-interface/nav', [
            'Referer: https://www.bilibili.com/',
        ]);
        $json = json_decode($res ?? '', true);
        $wbi = $json['data']['wbi_img'] ?? [];
        $imgKey = basename(parse_url($wbi['img_url'] ?? '', PHP_URL_PATH) ?: '', '.png');
        $subKey = basename(parse_url($wbi['sub_url'] ?? '', PHP_URL_PATH) ?: '', '.png');

        if ($imgKey === '' || $subKey === '') {
            throw new \RuntimeException('无法获取B站签名密钥');
        }

        return [$imgKey, $subKey];
    }
}
