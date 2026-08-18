<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/CodeAgentPrestaSupport.php';

use PrestaShop\Module\PsMcpServer\Server\Attributes\PsMcpSchema;
use PrestaShop\Module\PsMcpServer\Server\Attributes\PsMcpTool;
use PrestaShop\Module\PsMcpServer\Server\Attributes\PsMcpToolAnnotations;
use PrestaShop\Module\PsMcpServer\Server\Exceptions\PsMcpToolCallException;

final class CodeAgentPrestaThemeTools
{
    #[PsMcpTool(name: 'codeagent_presta_theme_info', title: 'Inspect PrestaShop theme', description: 'Returns active or named theme metadata, theme.yml configuration and key design paths.', annotations: new PsMcpToolAnnotations(title: 'Inspect PrestaShop theme', readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: ['theme' => ['type' => 'string']], required: [])]
    public function themeInfo(string $theme = ''): array
    {
        $theme = $this->themeName($theme);
        $base = 'themes/' . $theme;
        $root = CodeAgentPrestaSupport::absolute($base);
        if (!is_dir($root)) {
            throw new PsMcpToolCallException('Theme directory was not found.', 1);
        }
        $config = $this->themeConfig($theme);
        return [
            'name' => $theme,
            'active' => hash_equals($this->activeTheme(), $theme),
            'path' => $base,
            'config_path' => $base . '/config/theme.yml',
            'config' => $config,
            'paths' => [
                'templates' => is_dir($root . '/templates') ? $base . '/templates' : null,
                'assets' => is_dir($root . '/assets') ? $base . '/assets' : null,
                'module_overrides' => is_dir($root . '/modules') ? $base . '/modules' : null,
                'plugins' => is_dir($root . '/plugins') ? $base . '/plugins' : null,
            ],
        ];
    }

    #[PsMcpTool(name: 'codeagent_presta_theme_overrides_list', title: 'List theme module overrides', description: 'Lists module template, CSS and JavaScript overrides present in the selected PrestaShop theme.', annotations: new PsMcpToolAnnotations(title: 'List theme module overrides', readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: ['theme' => ['type' => 'string'], 'module' => ['type' => 'string'], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500]], required: [])]
    public function themeOverridesList(string $theme = '', string $module = '', int $limit = 300): array
    {
        $theme = $this->themeName($theme);
        if ($module !== '' && !preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $module)) {
            throw new PsMcpToolCallException('Invalid module name.', 1);
        }
        $path = 'themes/' . $theme . '/modules' . ($module !== '' ? '/' . $module : '');
        $absolute = CodeAgentPrestaSupport::root() . '/' . $path;
        if (!is_dir($absolute)) {
            return ['theme' => $theme, 'module' => $module !== '' ? $module : null, 'items' => [], 'count' => 0, 'truncated' => false];
        }
        $listed = CodeAgentPrestaSupport::listFiles($path, true, max(1, min(500, $limit)));
        $items = array_values(array_filter($listed['items'], static fn(array $item): bool => ($item['type'] ?? '') === 'file'));
        return ['theme' => $theme, 'module' => $module !== '' ? $module : null, 'items' => $items, 'count' => count($items), 'truncated' => (bool) $listed['truncated']];
    }

    #[PsMcpTool(name: 'codeagent_presta_theme_template_resolve', title: 'Resolve PrestaShop template precedence', description: 'Resolves the effective theme or module template path using PrestaShop theme override precedence and returns all existing candidates.', annotations: new PsMcpToolAnnotations(title: 'Resolve PrestaShop template precedence', readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: ['template' => ['type' => 'string'], 'module' => ['type' => 'string'], 'theme' => ['type' => 'string']], required: ['template'])]
    public function themeTemplateResolve(string $template, string $module = '', string $theme = ''): array
    {
        $theme = $this->themeName($theme);
        $template = CodeAgentPrestaSupport::normalizeRelative($template);
        if ($template === '') {
            throw new PsMcpToolCallException('Template path is required.', 1);
        }
        $candidates = [];
        if ($module !== '') {
            if (!preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $module)) {
                throw new PsMcpToolCallException('Invalid module name.', 1);
            }
            $candidates[] = 'themes/' . $theme . '/modules/' . $module . '/' . $template;
            $parent = $this->parentTheme($theme);
            if ($parent !== '') {
                $candidates[] = 'themes/' . $parent . '/modules/' . $module . '/' . $template;
            }
            $candidates[] = 'modules/' . $module . '/' . $template;
        } else {
            $candidates[] = 'themes/' . $theme . '/' . $template;
            $parent = $this->parentTheme($theme);
            if ($parent !== '') {
                $candidates[] = 'themes/' . $parent . '/' . $template;
            }
        }
        $existing = [];
        foreach ($candidates as $candidate) {
            $absolute = CodeAgentPrestaSupport::root() . '/' . $candidate;
            if (is_file($absolute)) {
                $existing[] = ['path' => $candidate, 'sha256' => hash_file('sha256', $absolute), 'size' => filesize($absolute) ?: 0];
            }
        }
        return ['theme' => $theme, 'module' => $module !== '' ? $module : null, 'template' => $template, 'effective' => $existing[0]['path'] ?? null, 'candidates' => $candidates, 'existing' => $existing];
    }


    #[PsMcpTool(name: 'codeagent_presta_theme_hooks_map', title: 'Map hooks used by PrestaShop theme', description: 'Scans theme templates for Smarty hook calls and Twig renderHook calls, returning hook names and source locations.', annotations: new PsMcpToolAnnotations(title: 'Map hooks used by PrestaShop theme', readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: ['theme' => ['type' => 'string'], 'search' => ['type' => 'string'], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500]], required: [])]
    public function themeHooksMap(string $theme = '', string $search = '', int $limit = 300): array
    {
        $theme = $this->themeName($theme);
        $base = CodeAgentPrestaSupport::root() . '/themes/' . $theme;
        if (!is_dir($base)) {
            throw new PsMcpToolCallException('Theme directory was not found.', 1);
        }
        $limit = max(1, min(500, $limit));
        $results = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getSize() > 2097152) {
                continue;
            }
            $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
            if (!in_array($ext, ['tpl','twig'], true)) {
                continue;
            }
            $handle = @fopen($file->getPathname(), 'rb');
            if ($handle === false) {
                continue;
            }
            $lineNo = 0;
            while (($line = fgets($handle)) !== false) {
                $lineNo++;
                $names = [];
                if ($ext === 'tpl' && preg_match_all('/\{hook\s+[^}]*\bh\s*=\s*[\'\"]([^\'\"]+)[\'\"]/i', $line, $matches)) {
                    $names = array_merge($names, $matches[1]);
                }
                if ($ext === 'twig' && preg_match_all('/\brenderHook\s*\(\s*[\'\"]([^\'\"]+)[\'\"]/i', $line, $matches)) {
                    $names = array_merge($names, $matches[1]);
                }
                foreach (array_unique($names) as $name) {
                    if ($search !== '' && stripos($name, $search) === false) {
                        continue;
                    }
                    $relative = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen(CodeAgentPrestaSupport::root())), '/');
                    $results[] = ['hook' => $name, 'path' => $relative, 'line' => $lineNo];
                    if (count($results) >= $limit) {
                        fclose($handle);
                        return ['theme' => $theme, 'hooks' => $results, 'count' => count($results), 'truncated' => true];
                    }
                }
            }
            fclose($handle);
        }
        return ['theme' => $theme, 'hooks' => $results, 'count' => count($results), 'truncated' => false];
    }

    #[PsMcpTool(name: 'codeagent_presta_theme_override_create', title: 'Create PrestaShop theme module override', description: 'Copies a module template or asset into the active or selected theme using the official mirrored override structure.', annotations: new PsMcpToolAnnotations(title: 'Create PrestaShop theme module override', readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: ['module' => ['type' => 'string'], 'source_path' => ['type' => 'string'], 'theme' => ['type' => 'string'], 'destination_sha256' => ['type' => 'string']], required: ['module','source_path'])]
    public function themeOverrideCreate(string $module, string $source_path, string $theme = '', string $destination_sha256 = ''): array
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $module)) {
            throw new PsMcpToolCallException('Invalid module name.', 1);
        }
        $theme = $this->themeName($theme);
        $sourcePath = CodeAgentPrestaSupport::normalizeRelative($source_path);
        if ($sourcePath === '' || str_starts_with($sourcePath, 'modules/')) {
            throw new PsMcpToolCallException('source_path must be relative to the module directory.', 1);
        }
        $source = 'modules/' . $module . '/' . $sourcePath;
        $destination = 'themes/' . $theme . '/modules/' . $module . '/' . $sourcePath;
        $read = CodeAgentPrestaSupport::readFile($source, 0, 1048576);
        if (!empty($read['truncated'])) {
            throw new PsMcpToolCallException('Source file exceeds the 1 MB override limit.', 1);
        }
        $result = CodeAgentPrestaSupport::writeFile($destination, (string) $read['content'], $destination_sha256 !== '' ? $destination_sha256 : null);
        $result['source'] = $source;
        $result['theme'] = $theme;
        return $result;
    }

    private function activeTheme(): string
    {
        $context = Context::getContext();
        $name = $context->shop && $context->shop->theme ? (string) $context->shop->theme->getName() : '';
        if ($name === '' || !preg_match('/^[a-zA-Z0-9_.-]{1,128}$/D', $name)) {
            throw new PsMcpToolCallException('Active theme is unavailable.', 1);
        }
        return $name;
    }

    private function themeName(string $theme): string
    {
        $theme = trim($theme);
        if ($theme === '') {
            return $this->activeTheme();
        }
        if (!preg_match('/^[a-zA-Z0-9_.-]{1,128}$/D', $theme)) {
            throw new PsMcpToolCallException('Invalid theme name.', 1);
        }
        return $theme;
    }

    private function themeConfig(string $theme): array
    {
        $path = CodeAgentPrestaSupport::root() . '/themes/' . $theme . '/config/theme.yml';
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if (!is_string($raw) || strlen($raw) > 524288) {
            return [];
        }
        if (class_exists('Symfony\\Component\\Yaml\\Yaml')) {
            try {
                $parsed = \Symfony\Component\Yaml\Yaml::parse($raw);
                return is_array($parsed) ? $this->sanitizeConfig($parsed) : [];
            } catch (Throwable $e) {
                return ['parse_error' => true];
            }
        }
        return ['parser_available' => false, 'sha256' => hash('sha256', $raw)];
    }

    private function sanitizeConfig(array $value, int $depth = 0): array
    {
        if ($depth > 8) {
            return [];
        }
        $out = [];
        foreach ($value as $key => $item) {
            $keyString = (string) $key;
            if (preg_match('/(pass|password|secret|token|api.?key|private|salt|cookie|credential|auth)/i', $keyString)) {
                $out[$key] = '[REDACTED]';
                continue;
            }
            if (is_array($item)) {
                $out[$key] = $this->sanitizeConfig($item, $depth + 1);
            } elseif (is_scalar($item) || $item === null) {
                $text = is_string($item) && strlen($item) > 2000 ? substr($item, 0, 2000) : $item;
                $out[$key] = $text;
            }
        }
        return $out;
    }

    private function parentTheme(string $theme): string
    {
        $config = $this->themeConfig($theme);
        $parent = trim((string) ($config['parent'] ?? ''));
        return preg_match('/^[a-zA-Z0-9_.-]{1,128}$/D', $parent) ? $parent : '';
    }
}
