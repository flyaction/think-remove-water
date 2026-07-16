<?php
namespace Flyaction\ThinkRemoveWater\Services;

use Flyaction\ThinkRemoveWater\Core\Database;
use Flyaction\ThinkRemoveWater\Core\Security;

class AuthService
{
    public static function validateApiKey(?string $apiKey): ?array
    {
        $apiKey = Security::validateApiKey($apiKey);
        if ($apiKey === null) {
            return null;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM api_keys WHERE api_key = ? AND status = 1');
        $stmt->execute([$apiKey]);
        $key = $stmt->fetch();

        if (!$key) {
            return null;
        }

        if ($key['expires_at'] && strtotime($key['expires_at']) < time()) {
            return null;
        }

        return UserService::applyEffectivePlanToApiKey($key);
    }

    public static function checkRateLimit(array $apiKeyData): bool
    {
        $limit = (int) ($apiKeyData['daily_limit'] ?? 0);

        if ($limit === 0) {
            return true;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM request_logs
             WHERE api_key_id = ? AND created_at >= CURDATE() AND status = "success"'
        );
        $stmt->execute([$apiKeyData['id']]);
        $todayCount = (int) $stmt->fetchColumn();

        return $todayCount < $limit;
    }

    public static function getUsageInfo(array $apiKeyData): array
    {
        $limit = (int) ($apiKeyData['daily_limit'] ?? 0);
        $unlimited = SettingsService::isUnlimited($limit);

        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM request_logs
             WHERE api_key_id = ? AND created_at >= CURDATE() AND status = "success"'
        );
        $stmt->execute([$apiKeyData['id']]);
        $todayUsed = (int) $stmt->fetchColumn();

        return [
            'plan'         => $apiKeyData['plan'] ?? 'regular',
            'plan_label'   => SettingsService::planLabel($apiKeyData['plan'] ?? 'regular'),
            'daily_limit'  => $limit,
            'unlimited'    => $unlimited,
            'today_used'   => $todayUsed,
            'today_remain' => $unlimited ? null : max(0, $limit - $todayUsed),
        ];
    }

    public static function generateApiKey(): string
    {
        return 'wm_' . bin2hex(random_bytes(24));
    }

    public static function createApiKeyForUser(int $userId, string $name, string $email, string $plan = 'regular'): int
    {
        $db = Database::getInstance();
        $apiKey = self::generateApiKey();
        $limit = SettingsService::getDailyLimitForPlan($plan);

        $stmt = $db->prepare(
            'INSERT INTO api_keys (user_id, api_key, name, email, plan, daily_limit) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $apiKey, $name, $email, $plan, $limit]);

        return (int) $db->lastInsertId();
    }

    public static function createApiKey(string $name, string $email = '', string $plan = 'regular'): ?string
    {
        $db = Database::getInstance();
        $apiKey = self::generateApiKey();
        $limit = SettingsService::getDailyLimitForPlan($plan);

        $stmt = $db->prepare(
            'INSERT INTO api_keys (api_key, name, email, plan, daily_limit) VALUES (?, ?, ?, ?, ?)'
        );
        $result = $stmt->execute([$apiKey, $name, $email, $plan, $limit]);

        return $result ? $apiKey : null;
    }

    /**
     * 解析请求鉴权：API Key 或已登录用户 Session
     */
    public static function resolveRequestAuth(?string $apiKey = null): ?array
    {
        if (!empty($apiKey)) {
            $keyData = self::validateApiKey($apiKey);
            return $keyData ?: null;
        }

        $user = UserService::getCurrentUser();
        if (!$user) {
            return null;
        }

        $keyData = UserService::getUserApiKey((int) $user['id']);
        return $keyData ? UserService::applyEffectivePlanToApiKey($keyData) : null;
    }
}
