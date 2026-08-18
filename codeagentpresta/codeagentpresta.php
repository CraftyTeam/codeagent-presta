<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class CodeAgentPresta extends Module
{
    public function __construct()
    {
        $this->name = 'codeagentpresta';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Sergiu Rus';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName = $this->l('CodeAgent Presta');
        $this->description = $this->l('Development tools for CodeAgent through the official PrestaShop MCP Server.');
        $this->confirmUninstall = $this->l('Remove CodeAgent Presta development tools?');
    }

    public function isMcpCompliant()
    {
        return true;
    }

    public function install()
    {
        return parent::install()
            && Configuration::updateValue('CODEAGENT_PRESTA_ALLOW_WRITES', 1)
            && Configuration::updateValue('CODEAGENT_PRESTA_ALLOW_DATABASE_READ', 1)
            && Configuration::updateValue('CODEAGENT_PRESTA_ALLOW_MODULE_LIFECYCLE', 1);
    }

    public function uninstall()
    {
        Configuration::deleteByName('CODEAGENT_PRESTA_ALLOW_WRITES');
        Configuration::deleteByName('CODEAGENT_PRESTA_ALLOW_DATABASE_READ');
        Configuration::deleteByName('CODEAGENT_PRESTA_ALLOW_MODULE_LIFECYCLE');
        return parent::uninstall();
    }

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitCodeAgentPresta')) {
            Configuration::updateValue('CODEAGENT_PRESTA_ALLOW_WRITES', (int) Tools::getValue('CODEAGENT_PRESTA_ALLOW_WRITES'));
            Configuration::updateValue('CODEAGENT_PRESTA_ALLOW_DATABASE_READ', (int) Tools::getValue('CODEAGENT_PRESTA_ALLOW_DATABASE_READ'));
            Configuration::updateValue('CODEAGENT_PRESTA_ALLOW_MODULE_LIFECYCLE', (int) Tools::getValue('CODEAGENT_PRESTA_ALLOW_MODULE_LIFECYCLE'));
            $output .= $this->displayConfirmation($this->l('Settings saved.'));
        }

        $latest = $this->getLatestRelease();
        $updateHtml = '<p><strong>' . $this->l('Installed version:') . '</strong> ' . Tools::safeOutput($this->version) . '</p>';
        if (is_array($latest) && !empty($latest['version'])) {
            $latestVersion = (string) $latest['version'];
            $updateHtml .= '<p><strong>' . $this->l('Latest version:') . '</strong> ' . Tools::safeOutput($latestVersion) . '</p>';
            if (version_compare($latestVersion, $this->version, '>') && !empty($latest['download_url'])) {
                $updateHtml .= '<p><a class="btn btn-primary" href="' . Tools::safeOutput((string) $latest['download_url']) . '" target="_blank" rel="noopener noreferrer">' . $this->l('Download update') . '</a></p>';
            } else {
                $updateHtml .= '<p class="alert alert-success">' . $this->l('CodeAgent Presta is up to date.') . '</p>';
            }
        } else {
            $updateHtml .= '<p class="alert alert-info">' . $this->l('The GitHub update manifest could not be reached. This does not affect MCP tools.') . '</p>';
        }

        return $output . $this->renderForm() . '<div class="panel"><h3>' . $this->l('Updates') . '</h3>' . $updateHtml . '</div>';
    }

    private function renderForm()
    {
        $fieldsForm = [[
            'form' => [
                'legend' => ['title' => $this->l('CodeAgent Presta permissions'), 'icon' => 'icon-cogs'],
                'description' => $this->l('MCP authentication and user permissions remain controlled by PrestaShop MCP Server. These switches add a local safety boundary for development operations.'),
                'input' => [
                    ['type' => 'switch', 'label' => $this->l('Allow file writes'), 'name' => 'CODEAGENT_PRESTA_ALLOW_WRITES', 'is_bool' => true, 'values' => [['id' => 'writes_on', 'value' => 1, 'label' => $this->l('Enabled')], ['id' => 'writes_off', 'value' => 0, 'label' => $this->l('Disabled')]]],
                    ['type' => 'switch', 'label' => $this->l('Allow read-only database queries'), 'name' => 'CODEAGENT_PRESTA_ALLOW_DATABASE_READ', 'is_bool' => true, 'values' => [['id' => 'db_on', 'value' => 1, 'label' => $this->l('Enabled')], ['id' => 'db_off', 'value' => 0, 'label' => $this->l('Disabled')]]],
                    ['type' => 'switch', 'label' => $this->l('Allow module lifecycle operations'), 'name' => 'CODEAGENT_PRESTA_ALLOW_MODULE_LIFECYCLE', 'is_bool' => true, 'values' => [['id' => 'modules_on', 'value' => 1, 'label' => $this->l('Enabled')], ['id' => 'modules_off', 'value' => 0, 'label' => $this->l('Disabled')]]],
                ],
                'submit' => ['title' => $this->l('Save')],
            ],
        ]];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->title = $this->displayName;
        $helper->submit_action = 'submitCodeAgentPresta';
        $helper->fields_value = [
            'CODEAGENT_PRESTA_ALLOW_WRITES' => (int) Configuration::get('CODEAGENT_PRESTA_ALLOW_WRITES'),
            'CODEAGENT_PRESTA_ALLOW_DATABASE_READ' => (int) Configuration::get('CODEAGENT_PRESTA_ALLOW_DATABASE_READ'),
            'CODEAGENT_PRESTA_ALLOW_MODULE_LIFECYCLE' => (int) Configuration::get('CODEAGENT_PRESTA_ALLOW_MODULE_LIFECYCLE'),
        ];

        return $helper->generateForm($fieldsForm);
    }

    private function getLatestRelease()
    {
        $url = 'https://raw.githubusercontent.com/CraftyTeam/codeagent-presta/main/update.json';
        $context = stream_context_create(['http' => ['timeout' => 3, 'user_agent' => 'CodeAgent-Presta/' . $this->version], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $raw = @file_get_contents($url, false, $context);
        if (!is_string($raw) || $raw === '' || strlen($raw) > 16384) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }
}
