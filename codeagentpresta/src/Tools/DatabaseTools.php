<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/CodeAgentPrestaSupport.php';

use PrestaShop\Module\PsMcpServer\Server\Attributes\PsMcpSchema;
use PrestaShop\Module\PsMcpServer\Server\Attributes\PsMcpTool;
use PrestaShop\Module\PsMcpServer\Server\Attributes\PsMcpToolAnnotations;
use PrestaShop\Module\PsMcpServer\Server\Exceptions\PsMcpToolCallException;

final class CodeAgentPrestaDatabaseTools
{
    #[PsMcpTool(name: 'codeagent_presta_database_tables', title: 'List PrestaShop database tables', description: 'Lists tables in the current PrestaShop database with approximate row counts and storage engine.', annotations: new PsMcpToolAnnotations(title: 'List PrestaShop database tables', readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: ['search' => ['type' => 'string']], required: [])]
    public function databaseTables(string $search = ''): array
    {
        $this->assertDbRead();
        $dbName = (string) _DB_NAME_;
        $where = "TABLE_SCHEMA='" . pSQL($dbName) . "'";
        if ($search !== '') {
            $where .= " AND TABLE_NAME LIKE '%" . pSQL($search) . "%'";
        }
        $rows = Db::getInstance()->executeS('SELECT TABLE_NAME AS table_name,ENGINE AS engine,TABLE_ROWS AS approximate_rows,DATA_LENGTH AS data_bytes,INDEX_LENGTH AS index_bytes FROM information_schema.TABLES WHERE ' . $where . ' ORDER BY TABLE_NAME LIMIT 500');
        return ['tables' => array_values((array) $rows), 'count' => count((array) $rows)];
    }

    #[PsMcpTool(name: 'codeagent_presta_database_describe', title: 'Describe PrestaShop database table', description: 'Returns columns and indexes for one table in the current PrestaShop database.', annotations: new PsMcpToolAnnotations(title: 'Describe PrestaShop database table', readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: ['table' => ['type' => 'string']], required: ['table'])]
    public function databaseDescribe(string $table): array
    {
        $this->assertDbRead();
        if (!preg_match('/^[a-zA-Z0-9_]{1,128}$/D', $table)) {
            throw new PsMcpToolCallException('Invalid table name.', 1);
        }
        $columns = Db::getInstance()->executeS('SHOW FULL COLUMNS FROM `' . bqSQL($table) . '`');
        $indexes = Db::getInstance()->executeS('SHOW INDEX FROM `' . bqSQL($table) . '`');
        return ['table' => $table, 'columns' => array_values((array) $columns), 'indexes' => array_values((array) $indexes)];
    }

    #[PsMcpTool(name: 'codeagent_presta_database_query', title: 'Run read-only PrestaShop SQL', description: 'Executes one bounded SELECT, SHOW, DESCRIBE, DESC or EXPLAIN statement against the current shop database. Mutating SQL and multi-statements are rejected.', annotations: new PsMcpToolAnnotations(title: 'Run read-only PrestaShop SQL', readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: ['sql' => ['type' => 'string', 'minLength' => 1], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500]], required: ['sql'])]
    public function databaseQuery(string $sql, int $limit = 200): array
    {
        $this->assertDbRead();
        $sql = trim($sql);
        if ($sql === '' || strlen($sql) > 20000 || str_contains($sql, ';')) {
            throw new PsMcpToolCallException('Use one SQL statement without a semicolon.', 1);
        }
        if (!preg_match('/^(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $sql)) {
            throw new PsMcpToolCallException('Only read-only SQL statements are allowed.', 1);
        }
        if (preg_match('/\b(INTO\s+OUTFILE|INTO\s+DUMPFILE|FOR\s+UPDATE|LOCK\s+IN\s+SHARE\s+MODE|SLEEP\s*\(|BENCHMARK\s*\(|LOAD_FILE\s*\()/i', $sql)) {
            throw new PsMcpToolCallException('Unsafe SQL construct is blocked.', 1);
        }
        $limit = max(1, min(500, $limit));
        if (preg_match('/^SELECT\b/i', $sql)) {
            if (preg_match('/\bLIMIT\s+(?:(\d+)\s*,\s*)?(\d+)(?:\s+OFFSET\s+\d+)?\s*$/i', $sql, $limitMatch)) {
                $requested = (int) $limitMatch[2];
                if ($requested > $limit) {
                    throw new PsMcpToolCallException('SQL LIMIT exceeds the requested tool limit.', 1);
                }
            } else {
                $sql .= ' LIMIT ' . $limit;
            }
        }
        if (preg_match('/\b(mysql|information_schema|performance_schema|sys)\s*\./i', $sql)) {
            throw new PsMcpToolCallException('Queries outside the current PrestaShop database are blocked.', 1);
        }
        if (preg_match('/\b(GET_LOCK|RELEASE_LOCK|IS_FREE_LOCK|IS_USED_LOCK)\s*\(/i', $sql)) {
            throw new PsMcpToolCallException('Database locking functions are blocked.', 1);
        }
        $started = microtime(true);
        $rows = Db::getInstance()->executeS($sql);
        if ($rows === false) {
            throw new PsMcpToolCallException('Database query failed.', 1);
        }
        return ['rows' => array_slice(array_values((array) $rows), 0, $limit), 'count' => min(count((array) $rows), $limit), 'duration_ms' => (int) round((microtime(true) - $started) * 1000)];
    }

    #[PsMcpTool(name: 'codeagent_presta_configuration_get', title: 'Read safe PrestaShop configuration', description: 'Reads one configuration key but blocks names that look like secrets, passwords, tokens, API keys or cryptographic material.', annotations: new PsMcpToolAnnotations(title: 'Read safe PrestaShop configuration', readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: ['key' => ['type' => 'string', 'pattern' => '^[A-Z0-9_]+$']], required: ['key'])]
    public function configurationGet(string $key): array
    {
        if (!preg_match('/^[A-Z0-9_]{2,128}$/D', $key)) {
            throw new PsMcpToolCallException('Invalid configuration key.', 1);
        }
        if (preg_match('/(PASS|PASSWORD|SECRET|TOKEN|API.?KEY|PRIVATE|SALT|COOKIE|CREDENTIAL|AUTH)/i', $key)) {
            throw new PsMcpToolCallException('Sensitive configuration keys are blocked.', 1);
        }
        $value = Configuration::get($key);
        if (is_string($value) && strlen($value) > 4000) {
            $value = mb_substr($value, 0, 4000);
        }
        return ['key' => $key, 'value' => $value];
    }

    private function assertDbRead(): void
    {
        if (!(bool) Configuration::get('CODEAGENT_PRESTA_ALLOW_DATABASE_READ')) {
            throw new PsMcpToolCallException('Database reads are disabled in CodeAgent Presta settings.', 1);
        }
    }
}
