<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/CodeAgentPrestaSupport.php';

use PrestaShop\Module\PsMcpServer\Server\Attributes\PsMcpSchema;
use PrestaShop\Module\PsMcpServer\Server\Attributes\PsMcpTool;
use PrestaShop\Module\PsMcpServer\Server\Attributes\PsMcpToolAnnotations;
use PrestaShop\Module\PsMcpServer\Server\Exceptions\PsMcpToolCallException;

final class CodeAgentPrestaPlatformTools
{
    #[PsMcpTool(name: 'codeagent_presta_environment', title: 'Inspect PrestaShop environment', description: 'Returns safe runtime, shop, PHP, theme and database-prefix information without exposing credentials.', annotations: new PsMcpToolAnnotations(title: 'Inspect PrestaShop environment', readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: [], required: [])]
    public function environment(): array
    {
        $context = Context::getContext();
        $theme = $context->shop && $context->shop->theme ? $context->shop->theme->getName() : null;
        return [
            'prestashop_version' => _PS_VERSION_,
            'php_version' => PHP_VERSION,
            'shop_id' => $context->shop ? (int) $context->shop->id : null,
            'shop_name' => (string) Configuration::get('PS_SHOP_NAME'),
            'shop_domain' => $context->shop ? (string) $context->shop->domain : null,
            'theme' => $theme,
            'default_language_id' => (int) Configuration::get('PS_LANG_DEFAULT'),
            'default_currency_id' => (int) Configuration::get('PS_CURRENCY_DEFAULT'),
            'multistore' => (bool) Shop::isFeatureActive(),
            'debug_mode' => defined('_PS_MODE_DEV_') ? (bool) _PS_MODE_DEV_ : false,
            'db_prefix' => CodeAgentPrestaSupport::dbPrefix(),
            'root' => CodeAgentPrestaSupport::root(),
            'module_version' => Module::getInstanceByName('codeagentpresta')?->version,
        ];
    }

    #[PsMcpTool(name: 'codeagent_presta_modules_list', title: 'List PrestaShop modules', description: 'Lists installed or available modules with version, author and active state.', annotations: new PsMcpToolAnnotations(title: 'List PrestaShop modules', readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: ['active_only' => ['type' => 'boolean']], required: [])]
    public function modulesList(bool $active_only = false): array
    {
        $modules = [];
        foreach (Module::getModulesOnDisk(true) as $row) {
            $name = (string) ($row->name ?? '');
            if ($name === '') {
                continue;
            }
            $instance = Module::getInstanceByName($name);
            $active = $instance ? (bool) Module::isEnabled($name) : false;
            if ($active_only && !$active) {
                continue;
            }
            $modules[] = [
                'name' => $name,
                'display_name' => $instance ? (string) $instance->displayName : (string) ($row->displayName ?? $name),
                'version' => $instance ? (string) $instance->version : (string) ($row->version ?? ''),
                'author' => $instance ? (string) $instance->author : (string) ($row->author ?? ''),
                'active' => $active,
                'installed' => (bool) Module::isInstalled($name),
            ];
        }
        usort($modules, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return ['modules' => array_slice($modules, 0, 1000), 'count' => count($modules), 'truncated' => count($modules) > 1000];
    }

    #[PsMcpTool(name: 'codeagent_presta_module_info', title: 'Inspect PrestaShop module', description: 'Returns module metadata, path, installed/active state and registered hooks.', annotations: new PsMcpToolAnnotations(title: 'Inspect PrestaShop module', readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: ['name' => ['type' => 'string', 'pattern' => '^[a-zA-Z0-9_-]+$']], required: ['name'])]
    public function moduleInfo(string $name): array
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $name)) {
            throw new PsMcpToolCallException('Invalid module name.', 1);
        }
        $module = Module::getInstanceByName($name);
        if (!$module) {
            throw new PsMcpToolCallException('Module not found.', 1);
        }
        $hooks = [];
        if ((int) $module->id > 0) {
            $rows = Db::getInstance()->executeS('SELECT h.name FROM `' . _DB_PREFIX_ . 'hook_module` hm INNER JOIN `' . _DB_PREFIX_ . 'hook` h ON h.id_hook=hm.id_hook WHERE hm.id_module=' . (int) $module->id . ' ORDER BY h.name');
            foreach ((array) $rows as $row) {
                $hooks[] = (string) $row['name'];
            }
        }
        return [
            'name' => $module->name,
            'display_name' => $module->displayName,
            'description' => $module->description,
            'version' => $module->version,
            'author' => $module->author,
            'installed' => Module::isInstalled($name),
            'active' => Module::isEnabled($name),
            'path' => 'modules/' . $module->name,
            'hooks' => $hooks,
            'mcp_compliant' => method_exists($module, 'isMcpCompliant') ? (bool) $module->isMcpCompliant() : false,
        ];
    }

    #[PsMcpTool(name: 'codeagent_presta_hooks_list', title: 'List PrestaShop hooks', description: 'Lists registered hooks and optionally filters names/descriptions.', annotations: new PsMcpToolAnnotations(title: 'List PrestaShop hooks', readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: ['search' => ['type' => 'string'], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500]], required: [])]
    public function hooksList(string $search = '', int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $where = '';
        if ($search !== '') {
            $safe = pSQL($search);
            $where = " WHERE name LIKE '%{$safe}%' OR title LIKE '%{$safe}%' OR description LIKE '%{$safe}%'";
        }
        $rows = Db::getInstance()->executeS('SELECT id_hook,name,title,description,position FROM `' . _DB_PREFIX_ . 'hook`' . $where . ' ORDER BY name LIMIT ' . $limit);
        return ['hooks' => array_values((array) $rows), 'count' => count((array) $rows), 'truncated' => count((array) $rows) >= $limit];
    }

    #[PsMcpTool(name: 'codeagent_presta_themes_list', title: 'List PrestaShop themes', description: 'Lists themes found in the shop and identifies the active theme.', annotations: new PsMcpToolAnnotations(title: 'List PrestaShop themes', readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: [], required: [])]
    public function themesList(): array
    {
        $active = Context::getContext()->shop && Context::getContext()->shop->theme ? Context::getContext()->shop->theme->getName() : '';
        $items = [];
        $dir = CodeAgentPrestaSupport::root() . '/themes';
        foreach (new DirectoryIterator($dir) as $entry) {
            if ($entry->isDot() || !$entry->isDir()) {
                continue;
            }
            $name = $entry->getFilename();
            $items[] = ['name' => $name, 'active' => hash_equals((string) $active, $name), 'path' => 'themes/' . $name];
        }
        usort($items, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return ['themes' => $items, 'count' => count($items), 'active' => $active];
    }
}
