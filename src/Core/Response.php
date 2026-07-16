<?php
namespace Flyaction\ThinkRemoveWater\Core;

class Response
{
    public static function json(array $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function success($data = null, string $message = 'success'): void
    {
        self::json([
            'code'    => 200,
            'message' => $message,
            'data'    => $data,
            'time'    => time(),
        ]);
    }

    public static function error(string $message, int $code = 400, $data = null): void
    {
        self::json([
            'code'    => $code,
            'message' => $message,
            'data'    => $data,
            'time'    => time(),
        ], $code >= 500 ? 500 : 200);
    }

    public static function fromException(\Throwable $e, int $code = 400, string $fallback = '操作失败，请稍后重试'): void
    {
        self::error(Security::safeErrorMessage($e, $fallback), $code);
    }
}
