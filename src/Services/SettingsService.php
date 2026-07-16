<?php
namespace Flyaction\ThinkRemoveWater\Services;

use Flyaction\ThinkRemoveWater\Core\Database;

class SettingsService
{
    private static array $cache = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        try {
            $db = Database::getInstance();
            $stmt = $db->prepare('SELECT value FROM settings WHERE key_name = ?');
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
            if ($value === false) {
                return $default;
            }
            self::$cache[$key] = $value;
            return $value;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    public static function getInt(string $key, int $default = 0): int
    {
        return (int) self::get($key, (string) $default);
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $val = self::get($key, $default ? '1' : '0');
        return in_array((string) $val, ['1', 'true', 'yes', 'on'], true);
    }

    public static function set(string $key, string $value, ?string $description = null): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO settings (key_name, value, description) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), description = COALESCE(VALUES(description), description)'
        );
        $result = $stmt->execute([$key, $value, $description]);
        self::$cache[$key] = $value;
        return $result;
    }

    public static function getAll(): array
    {
        try {
            $db = Database::getInstance();
            $rows = $db->query('SELECT key_name, value, description FROM settings ORDER BY key_name')->fetchAll();
            $map = [];
            foreach ($rows as $row) {
                $map[$row['key_name']] = $row['value'];
            }
            return $map;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function getDailyLimitForPlan(string $plan): int
    {
        if ($plan === 'premium') {
            return self::getInt('premium_daily_limit', 0);
        }
        return self::getInt('regular_daily_limit', 100);
    }

    public static function planLabel(string $plan, ?string $expiresAt = null): string
    {
        if ($plan === 'premium') {
            if ($expiresAt) {
                $date = date('Y-m-d', strtotime($expiresAt));
                return '高级会员（至 ' . $date . '）';
            }
            return '高级会员';
        }
        return '普通用户';
    }

    public static function isUnlimited(int $dailyLimit): bool
    {
        return $dailyLimit === 0;
    }
}
