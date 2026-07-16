<?php
namespace Flyaction\ThinkRemoveWater\Services;

use PDO;
use PDOException;

class DatabaseMigrator
{
    /** @var array<string, string> */
    private const MIGRATIONS = [
        'base'     => 'schema.sql',
        'v2'       => 'migrate_v2.sql',
        'v3'       => 'migrate_v3.sql',
        'v4'       => 'migrate_v4.sql',
        'fix_plan' => 'fix_plan.sql',
    ];

    private PDO $pdo;
    private string $databaseDir;

    /** @var list<array{version:string,status:string,message:string}> */
    private array $log = [];

    public function __construct(?PDO $pdo = null)
    {
        $this->databaseDir = dirname(__DIR__, 2) . '/database';
        $this->pdo = $pdo ?? $this->connect();
        $this->ensureMigrationTable();
    }

    /** @return list<array{version:string,status:string,message:string}> */
    public function runPending(): array
    {
        $this->log = [];
        $applied = $this->appliedVersions();

        if (!$this->tableExists('settings')) {
            $this->applyFile('base', self::MIGRATIONS['base'], true);
            $this->markApplied(['base', 'v2', 'v3', 'v4']);
            $this->ensureDefaultAdmin();
            $this->ensureInstallLock();
            return $this->log;
        }

        foreach (['v2', 'v3', 'v4'] as $version) {
            if (in_array($version, $applied, true)) {
                continue;
            }
            if ($this->isMigrationAppliedPhysically($version)) {
                $this->markApplied([$version]);
                $this->log[] = [
                    'version' => $version,
                    'status'  => 'ok',
                    'message' => '数据库结构已存在，已标记为已升级',
                ];
                continue;
            }
            $this->applyFile($version, self::MIGRATIONS[$version], false);
            $this->markApplied([$version]);
        }

        if ($this->needsPlanFix() && !in_array('fix_plan', $applied, true)) {
            $this->applyFile('fix_plan', self::MIGRATIONS['fix_plan'], false);
            $this->markApplied(['fix_plan']);
        }

        $this->ensureDefaultAdmin();
        $this->ensureInstallLock();

        return $this->log;
    }

    /** @return string[] */
    public function appliedVersions(): array
    {
        $stmt = $this->pdo->query('SELECT version FROM schema_migrations ORDER BY version');
        return $stmt ? array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'version') : [];
    }

    public function isFreshDatabase(): bool
    {
        return !$this->tableExists('settings');
    }

    /** @return list<array{version:string,status:string,message:string}> */
    public function getLog(): array
    {
        return $this->log;
    }

    private function connect(): PDO
    {
        $config = require dirname(__DIR__, 2) . '/config/database.php';
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['dbname'],
            $config['charset']
        );

        try {
            return new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            throw new \RuntimeException('数据库连接失败：' . $e->getMessage());
        }
    }

    private function ensureMigrationTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS `schema_migrations` (
                `version` VARCHAR(64) NOT NULL PRIMARY KEY,
                `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    private function applyFile(string $version, string $filename, bool $required): void
    {
        $path = $this->databaseDir . '/' . $filename;
        if (!is_file($path)) {
            $this->log[] = [
                'version' => $version,
                'status'  => $required ? 'error' : 'skip',
                'message' => '文件不存在：' . $filename,
            ];
            if ($required) {
                throw new \RuntimeException('缺少数据库文件：' . $filename);
            }
            return;
        }

        $sql = $this->normalizeSql((string) file_get_contents($path));
        $statements = $this->splitSql($sql);
        $executed = 0;
        $skipped = 0;

        foreach ($statements as $statement) {
            $statement = $this->sanitizeStatement($statement);
            if ($statement === '') {
                continue;
            }
            try {
                $this->pdo->exec($statement);
                $executed++;
            } catch (PDOException $e) {
                if ($this->isIgnorableSqlError($e)) {
                    $skipped++;
                    continue;
                }
                $this->log[] = [
                    'version' => $version,
                    'status'  => 'error',
                    'message' => $e->getMessage(),
                ];
                throw new \RuntimeException("[{$version}] " . $e->getMessage());
            }
        }

        $this->log[] = [
            'version' => $version,
            'status'  => 'ok',
            'message' => sprintf('已执行 %s（%d 条语句，跳过 %d 条）', $filename, $executed, $skipped),
        ];
    }

    /** @param string[] $versions */
    private function markApplied(array $versions): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO schema_migrations (version) VALUES (?)'
        );
        foreach ($versions as $version) {
            $stmt->execute([$version]);
        }
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function needsPlanFix(): bool
    {
        if (!$this->tableExists('api_keys')) {
            return false;
        }
        $stmt = $this->pdo->query(
            "SELECT COLUMN_TYPE FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'api_keys' AND column_name = 'plan'"
        );
        $type = $stmt ? (string) $stmt->fetchColumn() : '';
        return $type !== '' && !str_contains(strtolower($type), 'enum(\'regular\',\'premium\')');
    }

    private function ensureDefaultAdmin(): void
    {
        if (!$this->tableExists('admins')) {
            return;
        }
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
        if ($count > 0) {
            return;
        }
        $hash = password_hash('admin123', PASSWORD_DEFAULT);
        $this->pdo->prepare('INSERT INTO admins (username, password, email) VALUES (?, ?, ?)')
            ->execute(['admin', $hash, 'admin@localhost']);
        $this->log[] = [
            'version' => 'admin',
            'status'  => 'ok',
            'message' => '已创建默认管理员 admin / admin123（请登录后立即修改密码）',
        ];
    }

    private function ensureInstallLock(): void
    {
        $lock = dirname(__DIR__, 2) . '/storage/installed.lock';
        $dir = dirname($lock);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (!is_file($lock)) {
            file_put_contents($lock, date('c') . "\n");
            $this->log[] = [
                'version' => 'lock',
                'status'  => 'ok',
                'message' => '已写入 storage/installed.lock，站点安装标记完成',
            ];
        }
    }

    private function normalizeSql(string $sql): string
    {
        return preg_replace('/^\xEF\xBB\xBF/', '', $sql) ?? $sql;
    }

    private function stripSqlComments(string $sql): string
    {
        $sql = $this->normalizeSql($sql);
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql) ?? $sql;
        // 整行注释（允许行首有 BOM、空白等不可见字符）
        $sql = preg_replace('/^\s*--[^\r\n]*/m', '', $sql) ?? $sql;
        $sql = preg_replace('/^\s*#[^\r\n]*/m', '', $sql) ?? $sql;
        return trim($sql);
    }

    private function sanitizeStatement(string $statement): string
    {
        $statement = $this->normalizeSql(trim($statement));
        if ($statement === '') {
            return '';
        }
        // 若仍有杂质，从首个 SQL 关键字处截断（避免中文注释残留）
        if (preg_match('/\b(CREATE|ALTER|INSERT|UPDATE|DELETE|DROP|SET|TRUNCATE|REPLACE)\b/i', $statement, $match, PREG_OFFSET_CAPTURE)) {
            return substr($statement, $match[0][1]);
        }
        return $statement;
    }

    /** @return string[] */
    private function splitSql(string $sql): array
    {
        $sql = $this->stripSqlComments($sql);
        if ($sql === '') {
            return [];
        }

        $parts = preg_split('/;\s*/', $sql) ?: [];
        $statements = [];
        foreach ($parts as $part) {
            $statement = trim($part);
            if ($statement !== '') {
                $statements[] = $statement . ';';
            }
        }

        return $statements;
    }

    private function isMigrationAppliedPhysically(string $version): bool
    {
        return match ($version) {
            'v2' => $this->tableExists('users')
                && $this->tableExists('login_attempts')
                && $this->columnExists('api_keys', 'user_id'),
            'v3' => $this->columnExists('users', 'plan_expires_at'),
            'v4' => $this->tableExists('remember_tokens'),
            default => false,
        };
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function isIgnorableSqlError(PDOException $e): bool
    {
        $msg = $e->getMessage();
        $ignorable = [
            'Duplicate column name',
            'Duplicate key name',
            'already exists',
            '1060', // Duplicate column
            '1061', // Duplicate key
            '1050', // Table exists
            '1091', // Can't DROP; check that column/key exists
        ];
        foreach ($ignorable as $needle) {
            if (str_contains($msg, $needle)) {
                return true;
            }
        }
        return false;
    }
}
