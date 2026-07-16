<?php
namespace Flyaction\ThinkRemoveWater\Services;

use Flyaction\ThinkRemoveWater\Core\Database;
use Flyaction\ThinkRemoveWater\Core\Security;
use PDO;

class UserService
{
    public static function register(string $email, string $password, string $nickname = ''): array
    {
        if (!SettingsService::getBool('allow_register', true)) {
            throw new \RuntimeException('当前未开放注册');
        }

        $config = require __DIR__ . '/../../config/app.php';
        $sec = $config['security'] ?? [];
        if (!Security::checkRegisterRateLimit(
            (int) ($sec['register_max_attempts'] ?? 3),
            (int) ($sec['register_window_minutes'] ?? 60)
        )) {
            throw new \RuntimeException('注册过于频繁，请稍后再试');
        }

        $email = Security::validateEmail($email);

        $pwdError = Security::validatePassword($password);
        if ($pwdError) {
            Security::logRegisterAttempt(false);
            throw new \RuntimeException($pwdError);
        }

        $nickname = Security::sanitizeNickname($nickname);
        if ($nickname === '') {
            $local = explode('@', $email)[0];
            $local = preg_replace('/[^\p{L}\p{N}\s_\-\.]/u', '', $local) ?? '';
            $nickname = mb_substr($local, 0, 32);
            if ($nickname === '') {
                $nickname = '用户' . substr(md5($email), 0, 6);
            }
        }

        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            Security::logRegisterAttempt(false);
            throw new \RuntimeException('该邮箱已注册');
        }

        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'INSERT INTO users (email, password, nickname, plan) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$email, password_hash($password, PASSWORD_DEFAULT), $nickname, 'regular']);
            $userId = (int) $db->lastInsertId();

            $apiKeyId = AuthService::createApiKeyForUser($userId, $nickname, $email, 'regular');
            $db->prepare('UPDATE users SET api_key_id = ? WHERE id = ?')->execute([$apiKeyId, $userId]);

            $db->commit();
            Security::logRegisterAttempt(true);
            return self::getById($userId);
        } catch (\Throwable $e) {
            $db->rollBack();
            Security::logRegisterAttempt(false);
            throw $e;
        }
    }

    public static function login(string $email, string $password, bool $remember = true): array
    {
        $email = Security::sanitizeEmail($email);

        $config = require __DIR__ . '/../../config/app.php';
        $sec = $config['security'] ?? [];
        if (!Security::checkLoginAttempts(
            'user',
            $email,
            (int) ($sec['login_max_attempts'] ?? 5),
            (int) ($sec['login_window_minutes'] ?? 15)
        )) {
            throw new \RuntimeException('登录尝试过多，请 15 分钟后再试');
        }

        if (strlen($password) > 128) {
            Security::logLoginAttempt('user', $email, false);
            throw new \RuntimeException('邮箱或密码错误');
        }

        $user = self::getByEmail($email);
        if (!$user || !(int) $user['status']) {
            Security::logLoginAttempt('user', $email, false);
            throw new \RuntimeException('邮箱或密码错误');
        }

        if (!password_verify($password, $user['password'])) {
            Security::logLoginAttempt('user', $email, false);
            throw new \RuntimeException('邮箱或密码错误');
        }

        Security::logLoginAttempt('user', $email, true);

        $db = Database::getInstance();
        $db->prepare('UPDATE users SET last_login = NOW(), last_login_ip = ? WHERE id = ?')
           ->execute([Security::clientIp(), $user['id']]);

        Security::startSession();
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];

        if ($remember) {
            RememberTokenService::issue('user', (int) $user['id']);
        } else {
            RememberTokenService::revokeCookie('user');
        }

        unset($user['password']);
        return $user;
    }

    public static function logout(): void
    {
        RememberTokenService::revokeCookie('user');
        Security::startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public static function getCurrentUser(): ?array
    {
        RememberTokenService::tryRestoreUser();
        Security::startSession();
        if (empty($_SESSION['user_id'])) {
            return null;
        }
        $userId = (int) $_SESSION['user_id'];
        self::syncExpiredPremium($userId);
        $user = self::getById($userId);
        if (!$user || !(int) $user['status']) {
            return null;
        }
        unset($user['password']);
        return $user;
    }

    /** 高级会员是否在有效期内（无到期日视为永久有效，兼容旧数据） */
    public static function isPremiumActive(array $user): bool
    {
        if (($user['plan'] ?? 'regular') !== 'premium') {
            return false;
        }
        if (empty($user['plan_expires_at'])) {
            return true;
        }
        return strtotime($user['plan_expires_at']) >= time();
    }

    public static function getEffectivePlan(array $user): string
    {
        if (($user['plan'] ?? 'regular') === 'premium' && !self::isPremiumActive($user)) {
            return 'regular';
        }
        return $user['plan'] ?? 'regular';
    }

    /** 到期自动降为普通用户 */
    public static function syncExpiredPremium(int $userId): void
    {
        $user = self::getById($userId);
        if (!$user || ($user['plan'] ?? 'regular') !== 'premium') {
            return;
        }
        if (self::isPremiumActive($user)) {
            return;
        }
        self::downgradeToRegular($userId);
    }

    public static function downgradeToRegular(int $userId): void
    {
        $user = self::getById($userId);
        if (!$user) {
            return;
        }

        $db = Database::getInstance();
        $db->prepare('UPDATE users SET plan = ?, plan_expires_at = NULL WHERE id = ?')
           ->execute(['regular', $userId]);

        if (!empty($user['api_key_id'])) {
            $limit = SettingsService::getDailyLimitForPlan('regular');
            $db->prepare('UPDATE api_keys SET plan = ?, daily_limit = ? WHERE id = ?')
               ->execute(['regular', $limit, $user['api_key_id']]);
        }
    }

    /** 开通或续费高级会员（按月） */
    public static function assignPremiumMonths(int $userId, int $months): void
    {
        $months = max(1, min(36, $months));
        $user = self::getById($userId);
        if (!$user) {
            throw new \RuntimeException('用户不存在');
        }

        $base = time();
        if (
            ($user['plan'] ?? 'regular') === 'premium'
            && !empty($user['plan_expires_at'])
            && strtotime($user['plan_expires_at']) > $base
        ) {
            $base = strtotime($user['plan_expires_at']);
        }

        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . $months . ' months', $base));
        $limit = SettingsService::getDailyLimitForPlan('premium');

        $db = Database::getInstance();
        $db->prepare('UPDATE users SET plan = ?, plan_expires_at = ? WHERE id = ?')
           ->execute(['premium', $expiresAt, $userId]);

        if (!empty($user['api_key_id'])) {
            $db->prepare('UPDATE api_keys SET plan = ?, daily_limit = ? WHERE id = ?')
               ->execute(['premium', $limit, $user['api_key_id']]);
        }
    }

    public static function applyEffectivePlanToApiKey(array $keyData): array
    {
        if (empty($keyData['user_id'])) {
            return $keyData;
        }

        $userId = (int) $keyData['user_id'];
        self::syncExpiredPremium($userId);
        $user = self::getById($userId);
        if (!$user) {
            return $keyData;
        }

        $plan = self::getEffectivePlan($user);
        $keyData['plan'] = $plan;
        $keyData['daily_limit'] = SettingsService::getDailyLimitForPlan($plan);
        return $keyData;
    }

    public static function getById(int $id): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public static function getByEmail(string $email): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([Security::sanitizeEmail($email)]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public static function getUserApiKey(int $userId): ?array
    {
        $user = self::getById($userId);
        if (!$user || empty($user['api_key_id'])) {
            return null;
        }
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM api_keys WHERE id = ? AND status = 1');
        $stmt->execute([$user['api_key_id']]);
        $key = $stmt->fetch();
        return $key ?: null;
    }

    public static function getUsageStats(int $userId): array
    {
        self::syncExpiredPremium($userId);
        $keyData = self::getUserApiKey($userId);
        $user = self::getById($userId);
        $plan = self::getEffectivePlan($user ?: []);
        $expiresAt = ($user['plan'] ?? 'regular') === 'premium' ? ($user['plan_expires_at'] ?? null) : null;
        $dailyLimit = SettingsService::getDailyLimitForPlan($plan);

        if (!$keyData) {
            return [
                'plan'            => $plan,
                'plan_label'      => SettingsService::planLabel($plan, $expiresAt),
                'plan_expires_at' => $expiresAt,
                'premium_active'  => $plan === 'premium',
                'daily_limit'     => $dailyLimit,
                'unlimited'       => SettingsService::isUnlimited($dailyLimit),
                'today_used'      => 0,
                'today_remain'    => $dailyLimit,
                'total_requests'  => 0,
            ];
        }

        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM request_logs WHERE api_key_id = ? AND created_at >= CURDATE() AND status = "success"'
        );
        $stmt->execute([$keyData['id']]);
        $todayUsed = (int) $stmt->fetchColumn();

        $unlimited = SettingsService::isUnlimited($dailyLimit);

        return [
            'plan'            => $plan,
            'plan_label'      => SettingsService::planLabel($plan, $expiresAt),
            'plan_expires_at' => $expiresAt,
            'premium_active'  => $plan === 'premium',
            'daily_limit'     => $dailyLimit,
            'unlimited'       => $unlimited,
            'today_used'      => $todayUsed,
            'today_remain'    => $unlimited ? null : max(0, $dailyLimit - $todayUsed),
            'total_requests'  => (int) $keyData['total_requests'],
            'api_key'         => $keyData['api_key'],
        ];
    }

    public static function listUsers(int $page = 1, int $perPage = 20): array
    {
        $db = Database::getInstance();
        $offset = max(0, ($page - 1) * $perPage);
        $total = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $stmt = $db->prepare(
            'SELECT u.id, u.email, u.nickname, u.plan, u.plan_expires_at, u.status, u.last_login, u.created_at,
                    ak.api_key, ak.total_requests, ak.daily_limit
             FROM users u
             LEFT JOIN api_keys ak ON u.api_key_id = ak.id
             ORDER BY u.id DESC LIMIT ? OFFSET ?'
        );
        $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        return ['total' => $total, 'items' => $stmt->fetchAll()];
    }

    public static function updateUser(int $userId, array $data): bool
    {
        $user = self::getById($userId);
        if (!$user) {
            return false;
        }

        $db = Database::getInstance();
        $fields = [];
        $values = [];

        if (isset($data['plan']) && in_array($data['plan'], ['regular', 'premium'], true)) {
            if ($data['plan'] === 'regular') {
                self::downgradeToRegular($userId);
                return true;
            }
            if (isset($data['premium_months'])) {
                self::assignPremiumMonths($userId, (int) $data['premium_months']);
                return true;
            }
        }

        if (isset($data['premium_months']) && (int) $data['premium_months'] > 0) {
            self::assignPremiumMonths($userId, (int) $data['premium_months']);
            if (!isset($data['status']) && !isset($data['nickname'])) {
                return true;
            }
        }

        if (isset($data['status'])) {
            $fields[] = 'status = ?';
            $values[] = (int) $data['status'];
            if (!empty($user['api_key_id'])) {
                $db->prepare('UPDATE api_keys SET status = ? WHERE id = ?')
                   ->execute([(int) $data['status'], $user['api_key_id']]);
            }
        }

        if (isset($data['nickname'])) {
            $fields[] = 'nickname = ?';
            $values[] = Security::sanitizeNickname((string) $data['nickname']);
        }

        if (empty($fields)) {
            return true;
        }

        $values[] = $userId;
        $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?';
        return $db->prepare($sql)->execute($values);
    }

    public static function changePassword(int $userId, string $oldPassword, string $newPassword): void
    {
        $user = self::getById($userId);
        if (!$user || !password_verify($oldPassword, $user['password'])) {
            throw new \RuntimeException('原密码错误');
        }
        $pwdError = Security::validatePassword($newPassword);
        if ($pwdError) {
            throw new \RuntimeException($pwdError);
        }
        $db = Database::getInstance();
        $db->prepare('UPDATE users SET password = ? WHERE id = ?')
           ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
        RememberTokenService::revokeAllForSubject('user', $userId);
        RememberTokenService::revokeCookie('user');
    }

    public static function regenerateApiKey(int $userId): string
    {
        $user = self::getById($userId);
        if (!$user) {
            throw new \RuntimeException('用户不存在');
        }

        $db = Database::getInstance();
        $newKey = AuthService::generateApiKey();
        $plan = self::getEffectivePlan($user);
        $limit = SettingsService::getDailyLimitForPlan($plan);

        if (!empty($user['api_key_id'])) {
            $db->prepare('UPDATE api_keys SET api_key = ?, daily_limit = ?, plan = ? WHERE id = ?')
               ->execute([$newKey, $limit, $plan, $user['api_key_id']]);
        } else {
            $keyId = AuthService::createApiKeyForUser($userId, $user['nickname'], $user['email'], $plan);
            $db->prepare('UPDATE api_keys SET api_key = ? WHERE id = ?')->execute([$newKey, $keyId]);
            $db->prepare('UPDATE users SET api_key_id = ? WHERE id = ?')->execute([$keyId, $userId]);
        }

        return $newKey;
    }

    public static function listRequestLogs(int $userId, int $page = 1, int $perPage = 20, ?string $status = null): array
    {
        $user = self::getById($userId);
        if (!$user) {
            return ['total' => 0, 'page' => $page, 'per_page' => $perPage, 'items' => []];
        }

        $db = Database::getInstance();
        $page = max(1, $page);
        $perPage = min(50, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $apiKeyId = (int) ($user['api_key_id'] ?? 0);
        $where = 'WHERE (rl.user_id = ?';
        $params = [$userId];

        if ($apiKeyId > 0) {
            $where .= ' OR rl.api_key_id = ?';
            $params[] = $apiKeyId;
        }
        $where .= ')';

        if ($status === 'success' || $status === 'failed') {
            $where .= ' AND rl.status = ?';
            $params[] = $status;
        }

        $countSql = "SELECT COUNT(*) FROM request_logs rl {$where}";
        $stmt = $db->prepare($countSql);
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $listSql = "SELECT rl.id, rl.platform, rl.source_url, rl.status, rl.error_message,
                           rl.response_time, rl.ip_address, rl.created_at
                    FROM request_logs rl
                    {$where}
                    ORDER BY rl.created_at DESC
                    LIMIT ? OFFSET ?";
        $stmt = $db->prepare($listSql);
        $i = 1;
        foreach ($params as $p) {
            $stmt->bindValue($i++, $p);
        }
        $stmt->bindValue($i++, $perPage, PDO::PARAM_INT);
        $stmt->bindValue($i, $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'id'            => (int) $row['id'],
                'platform'      => $row['platform'],
                'source_url'    => $row['source_url'],
                'status'        => $row['status'],
                'error_message' => $row['error_message'],
                'response_time' => $row['response_time'] !== null ? (int) $row['response_time'] : null,
                'ip_address'    => $row['ip_address'],
                'created_at'    => $row['created_at'],
            ];
        }

        return [
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
            'items'    => $items,
        ];
    }
}
