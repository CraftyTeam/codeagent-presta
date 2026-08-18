<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/CodeAgentPrestaSupport.php';

use PrestaShop\Module\PsMcpServer\Server\Attributes\PsMcpSchema;
use PrestaShop\Module\PsMcpServer\Server\Attributes\PsMcpTool;
use PrestaShop\Module\PsMcpServer\Server\Attributes\PsMcpToolAnnotations;
use PrestaShop\Module\PsMcpServer\Server\Exceptions\PsMcpToolCallException;

final class CodeAgentPrestaRuntimeTools
{
    #[PsMcpTool(name: 'codeagent_presta_cache_status', title: 'Inspect PrestaShop cache settings', description: 'Returns safe Smarty and front-office cache/CCC settings relevant to development and design work.', annotations: new PsMcpToolAnnotations(title: 'Inspect PrestaShop cache settings', readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: [], required: [])]
    public function cacheStatus(): array
    {
        return ['settings' => $this->performanceSettings(), 'cache_dirs' => $this->cacheDirectories()];
    }

    #[PsMcpTool(name: 'codeagent_presta_maintenance_mode_get', title: 'Get PrestaShop maintenance mode', description: 'Returns whether the current shop is enabled or in maintenance mode.', annotations: new PsMcpToolAnnotations(title: 'Get PrestaShop maintenance mode', readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: [], required: [])]
    public function maintenanceModeGet(): array
    {
        $enabled = (bool) Configuration::get('PS_SHOP_ENABLE');
        return ['shop_enabled' => $enabled, 'maintenance_mode' => !$enabled, 'maintenance_ip' => (string) Configuration::get('PS_MAINTENANCE_IP')];
    }

    #[PsMcpTool(name: 'codeagent_presta_maintenance_mode_set', title: 'Set PrestaShop maintenance mode', description: 'Enables or disables maintenance mode for the current shop. Enabling maintenance makes the storefront unavailable to ordinary visitors.', annotations: new PsMcpToolAnnotations(title: 'Set PrestaShop maintenance mode', readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: ['maintenance' => ['type' => 'boolean']], required: ['maintenance'])]
    public function maintenanceModeSet(bool $maintenance): array
    {
        $this->assertWrites();
        if (!Configuration::updateValue('PS_SHOP_ENABLE', $maintenance ? 0 : 1)) {
            throw new PsMcpToolCallException('Unable to update maintenance mode.', 1);
        }
        return $this->maintenanceModeGet();
    }

    #[PsMcpTool(name: 'codeagent_presta_debug_mode_get', title: 'Get PrestaShop debug mode', description: 'Reads the _PS_MODE_DEV_ setting from config/defines.inc.php and returns its SHA-256 for safe changes.', annotations: new PsMcpToolAnnotations(title: 'Get PrestaShop debug mode', readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: [], required: [])]
    public function debugModeGet(): array
    {
        $path = CodeAgentPrestaSupport::root() . '/config/defines.inc.php';
        if (!is_file($path) || !is_readable($path)) {
            throw new PsMcpToolCallException('config/defines.inc.php is not readable.', 1);
        }
        $content = file_get_contents($path);
        if (!is_string($content)) {
            throw new PsMcpToolCallException('Unable to read debug configuration.', 1);
        }
        if (!preg_match('/define\s*\(\s*[\'\"]_PS_MODE_DEV_[\'\"]\s*,\s*(true|false)\s*\)/i', $content, $match)) {
            throw new PsMcpToolCallException('_PS_MODE_DEV_ definition was not found.', 1);
        }
        return ['enabled' => strtolower($match[1]) === 'true', 'path' => 'config/defines.inc.php', 'sha256' => hash('sha256', $content)];
    }

    #[PsMcpTool(name: 'codeagent_presta_debug_mode_set', title: 'Set PrestaShop debug mode', description: 'Changes only _PS_MODE_DEV_ in config/defines.inc.php using SHA-256 optimistic concurrency.', annotations: new PsMcpToolAnnotations(title: 'Set PrestaShop debug mode', readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: ['enabled' => ['type' => 'boolean'], 'expected_sha256' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64]], required: ['enabled','expected_sha256'])]
    public function debugModeSet(bool $enabled, string $expected_sha256): array
    {
        $this->assertWrites();
        $path = CodeAgentPrestaSupport::root() . '/config/defines.inc.php';
        $content = file_get_contents($path);
        if (!is_string($content)) {
            throw new PsMcpToolCallException('Unable to read debug configuration.', 1);
        }
        $currentHash = hash('sha256', $content);
        if (!hash_equals(strtolower($expected_sha256), strtolower($currentHash))) {
            throw new PsMcpToolCallException('Debug configuration changed since it was read.', 1);
        }
        $replacement = "define('_PS_MODE_DEV_', " . ($enabled ? 'true' : 'false') . ')';
        $updated = preg_replace('/define\s*\(\s*[\'\"]_PS_MODE_DEV_[\'\"]\s*,\s*(?:true|false)\s*\)\s*;/i', $replacement, $content, 1, $count);
        if (!is_string($updated) || $count !== 1) {
            throw new PsMcpToolCallException('Unable to locate a unique _PS_MODE_DEV_ definition.', 1);
        }
        $this->atomicWrite($path, $updated);
        return ['enabled' => $enabled, 'path' => 'config/defines.inc.php', 'sha256' => hash('sha256', $updated)];
    }

    #[PsMcpTool(name: 'codeagent_presta_performance_settings_get', title: 'Get PrestaShop frontend development settings', description: 'Returns Smarty compilation/cache and CSS/JS/HTML optimization settings that affect theme development.', annotations: new PsMcpToolAnnotations(title: 'Get PrestaShop frontend development settings', readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: [], required: [])]
    public function performanceSettingsGet(): array
    {
        return ['settings' => $this->performanceSettings()];
    }

    #[PsMcpTool(name: 'codeagent_presta_performance_settings_set', title: 'Set PrestaShop frontend performance settings', description: 'Sets explicit Smarty cache/compile and CCC-related values. No production defaults are assumed.', annotations: new PsMcpToolAnnotations(title: 'Set PrestaShop frontend performance settings', readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: ['smarty_force_compile' => ['type' => 'boolean'], 'smarty_cache' => ['type' => 'boolean'], 'css_cache' => ['type' => 'boolean'], 'js_cache' => ['type' => 'boolean'], 'html_compression' => ['type' => 'boolean'], 'inline_js_compression' => ['type' => 'boolean']], required: ['smarty_force_compile','smarty_cache','css_cache','js_cache','html_compression','inline_js_compression'])]
    public function performanceSettingsSet(bool $smarty_force_compile, bool $smarty_cache, bool $css_cache, bool $js_cache, bool $html_compression, bool $inline_js_compression): array
    {
        $this->assertWrites();
        $values = [
            'PS_SMARTY_FORCE_COMPILE' => (int) $smarty_force_compile,
            'PS_SMARTY_CACHE' => (int) $smarty_cache,
            'PS_CSS_THEME_CACHE' => (int) $css_cache,
            'PS_JS_THEME_CACHE' => (int) $js_cache,
            'PS_HTML_THEME_COMPRESSION' => (int) $html_compression,
            'PS_JS_HTML_THEME_COMPRESSION' => (int) $inline_js_compression,
        ];
        foreach ($values as $key => $value) {
            if (!Configuration::updateValue($key, $value)) {
                throw new PsMcpToolCallException('Unable to update performance setting: ' . $key, 1);
            }
        }
        return ['settings' => $this->performanceSettings()];
    }

    #[PsMcpTool(name: 'codeagent_presta_logs_search', title: 'Search PrestaShop logs', description: 'Searches recent PrestaShop log files for literal or regex text and redacts common credential patterns.', annotations: new PsMcpToolAnnotations(title: 'Search PrestaShop logs', readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: ['query' => ['type' => 'string', 'minLength' => 1], 'regex' => ['type' => 'boolean'], 'case_sensitive' => ['type' => 'boolean'], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 300]], required: ['query'])]
    public function logsSearch(string $query, bool $regex = false, bool $case_sensitive = false, int $limit = 100): array
    {
        if ($query === '' || strlen($query) > 500) {
            throw new PsMcpToolCallException('Invalid log search query.', 1);
        }
        $limit = max(1, min(300, $limit));
        $files = [];
        foreach ([CodeAgentPrestaSupport::root() . '/var/logs', CodeAgentPrestaSupport::root() . '/var/log'] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (glob($dir . '/*') ?: [] as $path) {
                if (is_file($path) && !is_link($path) && (filesize($path) ?: 0) <= 52428800) {
                    $files[$path] = filemtime($path) ?: 0;
                }
            }
        }
        arsort($files);
        $results = [];
        foreach (array_slice(array_keys($files), 0, 10) as $path) {
            $handle = @fopen($path, 'rb');
            if ($handle === false) {
                continue;
            }
            $size = filesize($path) ?: 0;
            $read = min($size, 2097152);
            if ($read > 0 && $size > $read) {
                fseek($handle, -$read, SEEK_END);
                fgets($handle);
            }
            $lineNo = 0;
            while (($line = fgets($handle)) !== false) {
                $lineNo++;
                if ($regex) {
                    $flags = $case_sensitive ? 'u' : 'iu';
                    $subject = strlen($line) > 65536 ? substr($line, 0, 65536) : $line;
                    $matched = @preg_match('~(*LIMIT_MATCH=100000)(*LIMIT_RECURSION=10000)' . str_replace('~', '\\~', $query) . '~' . $flags, $subject) === 1;
                } else {
                    $matched = $case_sensitive ? str_contains($line, $query) : stripos($line, $query) !== false;
                }
                if ($matched) {
                    $results[] = ['file' => basename($path), 'tail_line' => $lineNo, 'text' => $this->redact(mb_substr(rtrim($line), 0, 2000))];
                    if (count($results) >= $limit) {
                        fclose($handle);
                        return ['results' => $results, 'count' => count($results), 'truncated' => true];
                    }
                }
            }
            fclose($handle);
        }
        return ['results' => $results, 'count' => count($results), 'truncated' => false];
    }

    private function performanceSettings(): array
    {
        $keys = ['PS_SMARTY_FORCE_COMPILE','PS_SMARTY_CACHE','PS_CSS_THEME_CACHE','PS_JS_THEME_CACHE','PS_HTML_THEME_COMPRESSION','PS_JS_HTML_THEME_COMPRESSION','PS_HTACCESS_CACHE_CONTROL'];
        $settings = [];
        foreach ($keys as $key) {
            $settings[$key] = Configuration::get($key);
        }
        return $settings;
    }

    private function cacheDirectories(): array
    {
        $result = [];
        foreach (['var/cache/prod','var/cache/dev','cache/smarty/cache','cache/smarty/compile'] as $relative) {
            $path = CodeAgentPrestaSupport::root() . '/' . $relative;
            $result[] = ['path' => $relative, 'exists' => is_dir($path), 'writable' => is_dir($path) ? is_writable($path) : false];
        }
        return $result;
    }

    private function assertWrites(): void
    {
        if (!(bool) Configuration::get('CODEAGENT_PRESTA_ALLOW_WRITES')) {
            throw new PsMcpToolCallException('Writes are disabled in CodeAgent Presta settings.', 1);
        }
    }

    private function atomicWrite(string $path, string $content): void
    {
        $dir = dirname($path);
        $tmp = tempnam($dir, '.codeagent-');
        if ($tmp === false || file_put_contents($tmp, $content, LOCK_EX) === false) {
            if (is_string($tmp)) {
                @unlink($tmp);
            }
            throw new PsMcpToolCallException('Unable to write temporary configuration file.', 1);
        }
        @chmod($tmp, fileperms($path) & 0777 ?: 0644);
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new PsMcpToolCallException('Unable to replace configuration file.', 1);
        }
    }

    private function redact(string $line): string
    {
        $patterns = [
            '/((?:password|passwd|pwd|secret|token|api[_-]?key|authorization|cookie|credential)\s*[=:]\s*)[^\s,;]+/i',
            '/(Bearer\s+)[A-Za-z0-9._~+\/-]+/i',
            '/([?&](?:token|key|secret|password)=)[^&\s]+/i',
        ];
        return (string) preg_replace($patterns, '$1[REDACTED]', $line);
    }
}
