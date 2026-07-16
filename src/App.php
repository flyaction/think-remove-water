<?php
namespace Flyaction\ThinkRemoveWater;

class App
{
    public static function getAppConfig(): array
    {
        /**
         * 应用配置文件
         */
        return [
            'name'        => '去水印鸭',
            'version'     => '2.0.0',
            'debug'       => false,
            'timezone'    => 'Asia/Shanghai',
            'api_prefix'  => '/api/v1',
            'rate_limit'  => 100,
            'parse_cache' => [
                'enabled' => true,
                'ttl'     => 900, // 同链接缓存 15 分钟，命中后通常 <200ms
            ],
            // 以下平台下载走 CDN 直链；播放/预览仍走本站 media 代理
            'direct_download_platforms' => [
                'douyin', 'xiaohongshu', 'doubao', 'kuaishou', 'unknown',
                'weibo', 'jimeng', 'bilibili', 'toutiao',
            ],
            'cors_origin' => '*',
            'security'    => [
                'register_max_attempts'  => 3,
                'register_window_minutes'=> 60,
                'login_max_attempts'     => 5,
                'login_window_minutes'   => 15,
                'remember_days'          => 30,
                'json_max_bytes'         => 65536,
            ],
            // 已安装后访问 migrate.php 升级数据库时使用，留空则不再要求密钥
            'migrate_key' => 'qsy_migrate_2026',
        ];

    }

}


