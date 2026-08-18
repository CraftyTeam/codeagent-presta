<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/CodeAgentPrestaSupport.php';

use PrestaShop\Module\PsMcpServer\Server\Attributes\PsMcpSchema;
use PrestaShop\Module\PsMcpServer\Server\Attributes\PsMcpTool;
use PrestaShop\Module\PsMcpServer\Server\Attributes\PsMcpToolAnnotations;
use PrestaShop\Module\PsMcpServer\Server\Exceptions\PsMcpToolCallException;

final class CodeAgentPrestaHookTools
{
    #[PsMcpTool(name: 'codeagent_presta_hook_modules_list', title: 'List modules on PrestaShop hook', description: 'Lists modules attached to a hook for the current or selected shop, in rendering order.', annotations: new PsMcpToolAnnotations(title: 'List modules on PrestaShop hook', readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: ['hook' => ['type' => 'string'], 'shop_id' => ['type' => 'integer', 'minimum' => 1]], required: ['hook'])]
    public function hookModulesList(string $hook, int $shop_id = 0): array
    {
        $hookId = $this->hookId($hook, false);
        if ($hookId <= 0) {
            return ['hook' => $hook, 'shop_id' => $this->shopId($shop_id), 'modules' => [], 'count' => 0];
        }
        $shopId = $this->shopId($shop_id);
        $rows = Db::getInstance()->executeS('SELECT m.name,m.id_module,hm.position,m.active FROM `' . _DB_PREFIX_ . 'hook_module` hm INNER JOIN `' . _DB_PREFIX_ . 'module` m ON m.id_module=hm.id_module WHERE hm.id_hook=' . (int) $hookId . ' AND hm.id_shop=' . (int) $shopId . ' ORDER BY hm.position,m.name');
        return ['hook' => $hook, 'hook_id' => $hookId, 'shop_id' => $shopId, 'modules' => array_values((array) $rows), 'count' => count((array) $rows)];
    }

    #[PsMcpTool(name: 'codeagent_presta_hook_module_attach', title: 'Attach module to PrestaShop hook', description: 'Registers an installed module on a hook for one shop using PrestaShop Hook registration.', annotations: new PsMcpToolAnnotations(title: 'Attach module to PrestaShop hook', readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: ['module' => ['type' => 'string'], 'hook' => ['type' => 'string'], 'shop_id' => ['type' => 'integer', 'minimum' => 1]], required: ['module','hook'])]
    public function hookModuleAttach(string $module, string $hook, int $shop_id = 0): array
    {
        $this->assertWrites();
        $instance = $this->module($module);
        $shopId = $this->shopId($shop_id);
        if (method_exists('Hook', 'isModuleRegisteredOnHook') && Hook::isModuleRegisteredOnHook($instance, $hook, $shopId)) {
            return ['module' => $module, 'hook' => $hook, 'shop_id' => $shopId, 'attached' => true, 'changed' => false];
        }
        $ok = $instance->registerHook($hook, [$shopId]);
        if (!$ok) {
            throw new PsMcpToolCallException('Unable to attach module to hook.', 1);
        }
        return ['module' => $module, 'hook' => $hook, 'shop_id' => $shopId, 'attached' => true, 'changed' => true];
    }

    #[PsMcpTool(name: 'codeagent_presta_hook_module_detach', title: 'Detach module from PrestaShop hook', description: 'Unregisters a module from a hook for one shop. This can remove visible content or behavior.', annotations: new PsMcpToolAnnotations(title: 'Detach module from PrestaShop hook', readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: ['module' => ['type' => 'string'], 'hook' => ['type' => 'string'], 'shop_id' => ['type' => 'integer', 'minimum' => 1]], required: ['module','hook'])]
    public function hookModuleDetach(string $module, string $hook, int $shop_id = 0): array
    {
        $this->assertWrites();
        $instance = $this->module($module);
        $shopId = $this->shopId($shop_id);
        $hookId = $this->hookId($hook, false);
        if ($hookId <= 0) {
            return ['module' => $module, 'hook' => $hook, 'shop_id' => $shopId, 'attached' => false, 'changed' => false];
        }
        $registered = (bool) Db::getInstance()->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'hook_module` WHERE id_hook=' . (int) $hookId . ' AND id_module=' . (int) $instance->id . ' AND id_shop=' . (int) $shopId);
        if (!$registered) {
            return ['module' => $module, 'hook' => $hook, 'shop_id' => $shopId, 'attached' => false, 'changed' => false];
        }
        $ok = $instance->unregisterHook($hookId, [$shopId]);
        if (!$ok) {
            throw new PsMcpToolCallException('Unable to detach module from hook.', 1);
        }
        return ['module' => $module, 'hook' => $hook, 'shop_id' => $shopId, 'attached' => false, 'changed' => true];
    }

    #[PsMcpTool(name: 'codeagent_presta_hook_module_position', title: 'Set module position on PrestaShop hook', description: 'Moves an attached module to an exact 1-based position on a hook for one shop.', annotations: new PsMcpToolAnnotations(title: 'Set module position on PrestaShop hook', readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: ['module' => ['type' => 'string'], 'hook' => ['type' => 'string'], 'position' => ['type' => 'integer', 'minimum' => 1], 'shop_id' => ['type' => 'integer', 'minimum' => 1]], required: ['module','hook','position'])]
    public function hookModulePosition(string $module, string $hook, int $position, int $shop_id = 0): array
    {
        $this->assertWrites();
        $instance = $this->module($module);
        $hookId = $this->hookId($hook, false);
        if ($hookId <= 0) {
            throw new PsMcpToolCallException('Hook not found.', 1);
        }
        $shopId = $this->shopId($shop_id);
        $rows = Db::getInstance()->executeS('SELECT id_module FROM `' . _DB_PREFIX_ . 'hook_module` WHERE id_hook=' . (int) $hookId . ' AND id_shop=' . (int) $shopId . ' ORDER BY position,id_module');
        $ids = array_map(static fn(array $row): int => (int) $row['id_module'], (array) $rows);
        $current = array_search((int) $instance->id, $ids, true);
        if ($current === false) {
            throw new PsMcpToolCallException('Module is not attached to this hook for the selected shop.', 1);
        }
        array_splice($ids, (int) $current, 1);
        $target = max(0, min(count($ids), $position - 1));
        array_splice($ids, $target, 0, [(int) $instance->id]);
        $db = Db::getInstance();
        if (!$db->execute('START TRANSACTION')) {
            throw new PsMcpToolCallException('Unable to start database transaction.', 1);
        }
        try {
            foreach ($ids as $index => $idModule) {
                $ok = $db->update('hook_module', ['position' => $index + 1], 'id_hook=' . (int) $hookId . ' AND id_shop=' . (int) $shopId . ' AND id_module=' . (int) $idModule);
                if (!$ok) {
                    throw new RuntimeException('Position update failed.');
                }
            }
            $db->execute('COMMIT');
        } catch (Throwable $e) {
            $db->execute('ROLLBACK');
            throw new PsMcpToolCallException('Unable to reorder hook modules.', 1);
        }
        return ['module' => $module, 'hook' => $hook, 'shop_id' => $shopId, 'position' => $target + 1, 'count' => count($ids)];
    }

    private function assertWrites(): void
    {
        if (!(bool) Configuration::get('CODEAGENT_PRESTA_ALLOW_WRITES')) {
            throw new PsMcpToolCallException('Writes are disabled in CodeAgent Presta settings.', 1);
        }
    }

    private function module(string $name): Module
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $name)) {
            throw new PsMcpToolCallException('Invalid module name.', 1);
        }
        $module = Module::getInstanceByName($name);
        if (!$module || !Module::isInstalled($name)) {
            throw new PsMcpToolCallException('Installed module not found.', 1);
        }
        return $module;
    }

    private function hookId(string $name, bool $create): int
    {
        if (!preg_match('/^[a-zA-Z0-9_]{2,191}$/D', $name)) {
            throw new PsMcpToolCallException('Invalid hook name.', 1);
        }
        $id = (int) Hook::getIdByName($name, false);
        if ($id > 0 || !$create) {
            return $id;
        }
        return 0;
    }

    private function shopId(int $shopId): int
    {
        if ($shopId <= 0) {
            $context = Context::getContext();
            $shopId = $context->shop ? (int) $context->shop->id : 0;
        }
        $exists = $shopId > 0 && (bool) Db::getInstance()->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'shop` WHERE id_shop=' . (int) $shopId);
        if (!$exists) {
            throw new PsMcpToolCallException('Invalid shop id.', 1);
        }
        return $shopId;
    }
}
