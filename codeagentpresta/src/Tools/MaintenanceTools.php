<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/CodeAgentPrestaSupport.php';

use PrestaShop\Module\PsMcpServer\Server\Attributes\PsMcpSchema;
use PrestaShop\Module\PsMcpServer\Server\Attributes\PsMcpTool;
use PrestaShop\Module\PsMcpServer\Server\Attributes\PsMcpToolAnnotations;
use PrestaShop\Module\PsMcpServer\Server\Exceptions\PsMcpToolCallException;

final class CodeAgentPrestaMaintenanceTools
{
    #[PsMcpTool(name: 'codeagent_presta_cache_clear', title: 'Clear PrestaShop cache', description: 'Clears PrestaShop Symfony and Smarty cache directories. File writes must be enabled.', annotations: new PsMcpToolAnnotations(title: 'Clear PrestaShop cache', readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: [], required: [])]
    public function cacheClear(): array
    {
        return CodeAgentPrestaSupport::clearCache();
    }

    #[PsMcpTool(name: 'codeagent_presta_logs_read', title: 'Read recent PrestaShop logs', description: 'Reads the tail of recent text log files under var/logs or var/log without exposing session/credential files.', annotations: new PsMcpToolAnnotations(title: 'Read recent PrestaShop logs', readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: ['lines' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500], 'filename' => ['type' => 'string', 'description' => 'Optional exact log filename.']], required: [])]
    public function logsRead(int $lines = 100, string $filename = ''): array
    {
        $lines = max(1, min(500, $lines));
        $dirs = [CodeAgentPrestaSupport::root() . '/var/logs', CodeAgentPrestaSupport::root() . '/var/log'];
        $files = [];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (glob($dir . '/*') ?: [] as $path) {
                if (!is_file($path) || filesize($path) > 52428800) {
                    continue;
                }
                if ($filename !== '' && basename($path) !== $filename) {
                    continue;
                }
                $files[$path] = filemtime($path) ?: 0;
            }
        }
        arsort($files);
        $selected = array_slice(array_keys($files), 0, $filename !== '' ? 1 : 3);
        $out = [];
        foreach ($selected as $path) {
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                continue;
            }
            $buffer = '';
            $size = filesize($path) ?: 0;
            $read = min($size, 1048576);
            if ($read > 0) {
                fseek($handle, -$read, SEEK_END);
                $buffer = (string) fread($handle, $read);
            }
            fclose($handle);
            $chunks = preg_split('/\r?\n/', $buffer) ?: [];
            $chunks = array_map([$this, 'redactLogLine'], $chunks);
            $out[] = ['file' => basename($path), 'lines' => array_slice($chunks, -$lines)];
        }
        return ['logs' => $out, 'count' => count($out)];
    }

    #[PsMcpTool(name: 'codeagent_presta_module_enable', title: 'Enable PrestaShop module', description: 'Enables an already installed module. CodeAgent Presta cannot disable itself.', annotations: new PsMcpToolAnnotations(title: 'Enable PrestaShop module', readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: ['name' => ['type' => 'string']], required: ['name'])]
    public function moduleEnable(string $name): array
    {
        $module = $this->module($name);
        if (!Module::isInstalled($name)) {
            throw new PsMcpToolCallException('Module is not installed.', 1);
        }
        $ok = $module->enable(false);
        return ['name' => $name, 'enabled' => (bool) $ok, 'active' => Module::isEnabled($name)];
    }

    #[PsMcpTool(name: 'codeagent_presta_module_disable', title: 'Disable PrestaShop module', description: 'Disables an installed module. This is destructive to runtime behavior and cannot target CodeAgent Presta itself.', annotations: new PsMcpToolAnnotations(title: 'Disable PrestaShop module', readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: ['name' => ['type' => 'string']], required: ['name'])]
    public function moduleDisable(string $name): array
    {
        if ($name === 'codeagentpresta') {
            throw new PsMcpToolCallException('CodeAgent Presta cannot disable itself through MCP.', 1);
        }
        $module = $this->module($name);
        $ok = $module->disable(false);
        return ['name' => $name, 'disabled' => (bool) $ok, 'active' => Module::isEnabled($name)];
    }

    #[PsMcpTool(name: 'codeagent_presta_module_install', title: 'Install PrestaShop module', description: 'Installs a module that already exists under modules/. Does not download arbitrary packages.', annotations: new PsMcpToolAnnotations(title: 'Install PrestaShop module', readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: ['name' => ['type' => 'string']], required: ['name'])]
    public function moduleInstall(string $name): array
    {
        $module = $this->module($name);
        if (Module::isInstalled($name)) {
            return ['name' => $name, 'installed' => true, 'changed' => false];
        }
        $ok = $module->install();
        return ['name' => $name, 'installed' => (bool) $ok && Module::isInstalled($name), 'changed' => (bool) $ok];
    }

    #[PsMcpTool(name: 'codeagent_presta_module_uninstall', title: 'Uninstall PrestaShop module', description: 'Uninstalls a module and may remove its stored data. Cannot target CodeAgent Presta itself.', annotations: new PsMcpToolAnnotations(title: 'Uninstall PrestaShop module', readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: ['name' => ['type' => 'string']], required: ['name'])]
    public function moduleUninstall(string $name): array
    {
        if ($name === 'codeagentpresta') {
            throw new PsMcpToolCallException('CodeAgent Presta cannot uninstall itself through MCP.', 1);
        }
        $module = $this->module($name);
        if (!Module::isInstalled($name)) {
            return ['name' => $name, 'installed' => false, 'changed' => false];
        }
        $ok = $module->uninstall();
        return ['name' => $name, 'installed' => Module::isInstalled($name), 'changed' => (bool) $ok];
    }

    private function redactLogLine(string $line): string
    {
        $patterns = [
            '/((?:password|passwd|pwd|secret|token|api[_-]?key|authorization|cookie|credential)\s*[=:]\s*)[^\s,;]+/i',
            '/(Bearer\s+)[A-Za-z0-9._~+\/-]+/i',
            '/([?&](?:token|key|secret|password)=)[^&\s]+/i',
        ];
        return (string) preg_replace($patterns, '$1[REDACTED]', $line);
    }

    private function module(string $name): Module
    {
        if (!(bool) Configuration::get('CODEAGENT_PRESTA_ALLOW_MODULE_LIFECYCLE')) {
            throw new PsMcpToolCallException('Module lifecycle operations are disabled in CodeAgent Presta settings.', 1);
        }
        if (!preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $name)) {
            throw new PsMcpToolCallException('Invalid module name.', 1);
        }
        $module = Module::getInstanceByName($name);
        if (!$module) {
            throw new PsMcpToolCallException('Module not found on disk.', 1);
        }
        return $module;
    }
}
