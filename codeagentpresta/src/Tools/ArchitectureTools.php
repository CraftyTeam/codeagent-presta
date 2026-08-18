<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/CodeAgentPrestaSupport.php';

use PrestaShop\Module\PsMcpServer\Server\Attributes\PsMcpSchema;
use PrestaShop\Module\PsMcpServer\Server\Attributes\PsMcpTool;
use PrestaShop\Module\PsMcpServer\Server\Attributes\PsMcpToolAnnotations;
use PrestaShop\Module\PsMcpServer\Server\Exceptions\PsMcpToolCallException;

final class CodeAgentPrestaArchitectureTools
{
    #[PsMcpTool(name: 'codeagent_presta_overrides_list', title: 'List PrestaShop class and controller overrides', description: 'Lists shop-level and module-provided override files so compatibility risks can be inspected before changes.', annotations: new PsMcpToolAnnotations(title: 'List PrestaShop class and controller overrides', readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: ['module' => ['type' => 'string'], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500]], required: [])]
    public function overridesList(string $module = '', int $limit = 300): array
    {
        if ($module !== '' && !preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $module)) {
            throw new PsMcpToolCallException('Invalid module name.', 1);
        }
        $limit = max(1, min(500, $limit));
        $roots = ['override'];
        if ($module !== '') {
            $roots[] = 'modules/' . $module . '/override';
        } else {
            foreach (glob(CodeAgentPrestaSupport::root() . '/modules/*/override', GLOB_ONLYDIR) ?: [] as $path) {
                $roots[] = ltrim(substr(str_replace('\\', '/', $path), strlen(CodeAgentPrestaSupport::root())), '/');
                if (count($roots) >= 101) {
                    break;
                }
            }
        }
        $items = [];
        foreach ($roots as $root) {
            if (!is_dir(CodeAgentPrestaSupport::root() . '/' . $root)) {
                continue;
            }
            $remaining = $limit - count($items);
            if ($remaining <= 0) {
                break;
            }
            $listed = CodeAgentPrestaSupport::listFiles($root, true, $remaining);
            foreach ($listed['items'] as $item) {
                if (($item['type'] ?? '') === 'file') {
                    $items[] = $item;
                }
            }
        }
        return ['items' => $items, 'count' => count($items), 'truncated' => count($items) >= $limit];
    }

    #[PsMcpTool(name: 'codeagent_presta_routes_list', title: 'Inspect PrestaShop module routes', description: 'Discovers Symfony route configuration and legacy front-controller declarations in modules without executing them.', annotations: new PsMcpToolAnnotations(title: 'Inspect PrestaShop module routes', readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: ['module' => ['type' => 'string'], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200]], required: [])]
    public function routesList(string $module = '', int $limit = 100): array
    {
        return $this->configFiles('routes', $module, $limit);
    }

    #[PsMcpTool(name: 'codeagent_presta_services_list', title: 'Inspect PrestaShop module services', description: 'Discovers Symfony service configuration files in modules and parses YAML when the Symfony YAML component is available.', annotations: new PsMcpToolAnnotations(title: 'Inspect PrestaShop module services', readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: ['module' => ['type' => 'string'], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200]], required: [])]
    public function servicesList(string $module = '', int $limit = 100): array
    {
        return $this->configFiles('services', $module, $limit);
    }

    #[PsMcpTool(name: 'codeagent_presta_module_controllers_list', title: 'List PrestaShop module controllers', description: 'Lists legacy front/admin controllers and modern Symfony controller classes for one module.', annotations: new PsMcpToolAnnotations(title: 'List PrestaShop module controllers', readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: ['module' => ['type' => 'string']], required: ['module'])]
    public function moduleControllersList(string $module): array
    {
        $module = $this->moduleName($module);
        $bases = [
            'legacy_front' => 'modules/' . $module . '/controllers/front',
            'legacy_admin' => 'modules/' . $module . '/controllers/admin',
            'modern' => 'modules/' . $module . '/src/Controller',
        ];
        $groups = [];
        foreach ($bases as $type => $path) {
            if (!is_dir(CodeAgentPrestaSupport::root() . '/' . $path)) {
                $groups[$type] = [];
                continue;
            }
            $listed = CodeAgentPrestaSupport::listFiles($path, true, 300);
            $groups[$type] = array_values(array_filter($listed['items'], static fn(array $item): bool => ($item['type'] ?? '') === 'file' && str_ends_with(strtolower((string) $item['path']), '.php')));
        }
        return ['module' => $module, 'controllers' => $groups];
    }

    private function configFiles(string $kind, string $module, int $limit): array
    {
        $limit = max(1, min(200, $limit));
        $modules = [];
        if ($module !== '') {
            $modules[] = $this->moduleName($module);
        } else {
            foreach (glob(CodeAgentPrestaSupport::root() . '/modules/*', GLOB_ONLYDIR) ?: [] as $dir) {
                $name = basename($dir);
                if (preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $name)) {
                    $modules[] = $name;
                }
            }
            sort($modules, SORT_STRING);
        }
        $items = [];
        foreach ($modules as $name) {
            $patterns = $kind === 'routes'
                ? ['config/routes.yml','config/routes.yaml','config/routes/*.yml','config/routes/*.yaml']
                : ['config/services.yml','config/services.yaml','config/services/*.yml','config/services/*.yaml'];
            foreach ($patterns as $pattern) {
                foreach (glob(CodeAgentPrestaSupport::root() . '/modules/' . $name . '/' . $pattern) ?: [] as $file) {
                    if (!is_file($file) || filesize($file) > 524288) {
                        continue;
                    }
                    $raw = file_get_contents($file);
                    if (!is_string($raw)) {
                        continue;
                    }
                    $relative = ltrim(substr(str_replace('\\', '/', $file), strlen(CodeAgentPrestaSupport::root())), '/');
                    $entry = ['module' => $name, 'path' => $relative, 'sha256' => hash('sha256', $raw)];
                    if (class_exists('Symfony\\Component\\Yaml\\Yaml')) {
                        try {
                            $parsed = \Symfony\Component\Yaml\Yaml::parse($raw);
                            $entry['summary'] = $this->summarizeConfig($kind, is_array($parsed) ? $parsed : []);
                        } catch (Throwable $e) {
                            $entry['parse_error'] = true;
                        }
                    } else {
                        $entry['parser_available'] = false;
                    }
                    $items[] = $entry;
                    if (count($items) >= $limit) {
                        return ['kind' => $kind, 'items' => $items, 'count' => count($items), 'truncated' => true];
                    }
                }
            }
        }
        return ['kind' => $kind, 'items' => $items, 'count' => count($items), 'truncated' => false];
    }


    private function summarizeConfig(string $kind, array $config): array
    {
        if ($kind === 'services') {
            $services = is_array($config['services'] ?? null) ? array_keys($config['services']) : [];
            $imports = [];
            foreach ((array) ($config['imports'] ?? []) as $import) {
                if (is_array($import) && isset($import['resource']) && is_string($import['resource'])) {
                    $imports[] = $import['resource'];
                }
            }
            return ['service_ids' => array_slice(array_values($services), 0, 500), 'imports' => array_slice($imports, 0, 100), 'service_count' => count($services)];
        }
        $routes = [];
        foreach ($config as $name => $definition) {
            if (!is_string($name) || !is_array($definition)) {
                continue;
            }
            $entry = ['name' => $name];
            foreach (['path','controller','methods'] as $key) {
                if (isset($definition[$key]) && (is_string($definition[$key]) || is_array($definition[$key]))) {
                    $entry[$key] = $definition[$key];
                }
            }
            if (isset($definition['defaults']['_controller']) && is_string($definition['defaults']['_controller'])) {
                $entry['controller'] = $definition['defaults']['_controller'];
            }
            $routes[] = $entry;
            if (count($routes) >= 500) {
                break;
            }
        }
        return ['routes' => $routes, 'route_count' => count($routes)];
    }

    private function moduleName(string $module): string
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $module)) {
            throw new PsMcpToolCallException('Invalid module name.', 1);
        }
        if (!is_dir(CodeAgentPrestaSupport::root() . '/modules/' . $module)) {
            throw new PsMcpToolCallException('Module directory not found.', 1);
        }
        return $module;
    }
}
