<?php
namespace Flyaction\ThinkRemoveWater\Services;

use Flyaction\ThinkRemoveWater\Core\Database;
use Flyaction\ThinkRemoveWater\Core\Security;

class RememberTokenService
{
    private const TTL_SECONDS = 2592000; // 30 天
    private const MAX_TOKENS_PER_SUBJECT = 5;

    public static function ttlDays(): int
    {
        return (int) (self::TTL_SECONDS / 86400);
    }

    public static function cookieName(string $type): string
    {
        return $type === 'admin' ? 'wm_admin_rt' : 'wm_user_rt';
    }

    public static function issue(string $type, int $subjectId): void
    {
        if (!in_array($type, ['user', 'admin'], true) || $subjectId <= 0) {
            return;
        }

        self::purgeExpired();
        self::trimSubjectTokens($type, $subjectId);

        $raw = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        $expiresAt = date('Y-m-d H:i:s', time() + self::TTL_SECONDS);

        $db = Database::getInstance();
        $db->prepare(
            'INSERT INTO remember_tokens (type, subject_id, token_hash, expires_at, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $type,
            $subjectId,
            $hash,
            $expiresAt,
            Security::clientIp(),
            substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);

        self::setCookie($type, $raw);
    }

    public static function tryRestoreUser(): void
    {
        Security::startSession();
        if (!empty($_SESSION['user_id'])) {
            return;
        }

        $subjectId = self::resolveFromCookie('user');
        if (!$subjectId) {
            return;
        }

        $user = UserService::getById($subjectId);
        if (!$user || !(int) $user['status']) {
            self::revokeCookie('user');
            return;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = $subjectId;
    }

    public static function tryRestoreAdmin(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if (!empty($_SESSION['admin_id'])) {
            return;
        }

        $subjectId = self::resolveFromCookie('admin');
        if (!$subjectId) {
            return;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT id, username FROM admins WHERE id = ?');
        $stmt->execute([$subjectId]);
        $admin = $stmt->fetch();
        if (!$admin) {
            self::revokeCookie('admin');
            return;
        }

        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int) $admin['id'];
        $_SESSION['admin_name'] = (string) $admin['username'];
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
    }

    public static function revokeCookie(string $type): void
    {
        $raw = self::readCookie($type);
        if ($raw) {
            self::revokeRaw($type, $raw);
        }
        self::clearCookie($type);
    }

    public static function revokeAllForSubject(string $type, int $subjectId): void
    {
        if ($subjectId <= 0) {
            return;
        }
        $db = Database::getInstance();
        $db->prepare('DELETE FROM remember_tokens WHERE type = ? AND subject_id = ?')
           ->execute([$type, $subjectId]);
    }

    private static function resolveFromCookie(string $type): ?int
    {
        $raw = self::readCookie($type);
        if (!$raw) {
            return null;
        }

        $raw = preg_replace('/[^a-f0-9]/', '', strtolower($raw)) ?? '';
        if (strlen($raw) !== 64) {
            self::clearCookie($type);
            return null;
        }

        $hash = hash('sha256', $raw);
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT id, subject_id, expires_at FROM remember_tokens WHERE type = ? AND token_hash = ? LIMIT 1'
        );
        $stmt->execute([$type, $hash]);
        $row = $stmt->fetch();

        if (!$row || strtotime((string) $row['expires_at']) < time()) {
            if ($row) {
                $db->prepare('DELETE FROM remember_tokens WHERE id = ?')->execute([(int) $row['id']]);
            }
            self::clearCookie($type);
            return null;
        }

        $db->prepare('UPDATE remember_tokens SET last_used_at = NOW() WHERE id = ?')
           ->execute([(int) $row['id']]);

        return (int) $row['subject_id'];
    }

    private static function revokeRaw(string $type, string $raw): void
    {
        $raw = preg_replace('/[^a-f0-9]/', '', strtolower($raw)) ?? '';
        if (strlen($raw) !== 64) {
            return;
        }
        $hash = hash('sha256', $raw);
        $db = Database::getInstance();
        $db->prepare('DELETE FROM remember_tokens WHERE type = ? AND token_hash = ?')
           ->execute([$type, $hash]);
    }

    private static function trimSubjectTokens(string $type, int $subjectId): void
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT id FROM remember_tokens WHERE type = ? AND subject_id = ? ORDER BY id DESC'
        );
        $stmt->execute([$type, $subjectId]);
        $ids = array_map('intval', array_column($stmt->fetchAll(), 'id'));
        if (count($ids) < self::MAX_TOKENS_PER_SUBJECT) {
            return;
        }
        $remove = array_slice($ids, self::MAX_TOKENS_PER_SUBJECT - 1);
        if (empty($remove)) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($remove), '?'));
        $db->prepare("DELETE FROM remember_tokens WHERE id IN ({$placeholders})")->execute($remove);
    }

    private static function purgeExpired(): void
    {
        if (random_int(1, 100) > 5) {
            return;
        }
        $db = Database::getInstance();
        $db->exec('DELETE FROM remember_tokens WHERE expires_at < NOW()');
    }

    private static function readCookie(string $type): ?string
    {
        $name = self::cookieName($type);
        $value = $_COOKIE[$name] ?? '';
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function setCookie(string $type, string $raw): void
    {
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        setcookie(self::cookieName($type), $raw, [
            'expires'  => time() + self::TTL_SECONDS,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::cookieName($type)] = $raw;
    }

    private static function clearCookie(string $type): void
    {
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        setcookie(self::cookieName($type), '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[self::cookieName($type)]);
    }
}
