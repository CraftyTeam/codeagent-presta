# CodeAgent Presta

Development MCP tools for PrestaShop, designed to be discovered by the official PrestaShop MCP Server.

## Requirements

- PrestaShop 8.x or 9.x
- Official PrestaShop MCP Server (`ps_mcp_server`)
- Official PrestaShop MCP Tools (`ps_mcp_tools`) for commerce tools
- PHP 8.1+

## Installation

Upload `codeagentpresta.zip` from PrestaShop Back Office → Modules → Module Manager → Upload a module, then install **CodeAgent Presta**.

The module declares `isMcpCompliant()` and the official MCP server discovers all tools under `src/` automatically.

## Development tools

- `codeagent_presta_environment`
- `codeagent_presta_files_list`
- `codeagent_presta_file_read`
- `codeagent_presta_file_search`
- `codeagent_presta_file_write`
- `codeagent_presta_file_delete`
- `codeagent_presta_directory_create`
- `codeagent_presta_modules_list`
- `codeagent_presta_module_info`
- `codeagent_presta_hooks_list`
- `codeagent_presta_themes_list`
- `codeagent_presta_database_tables`
- `codeagent_presta_database_describe`
- `codeagent_presta_database_query`
- `codeagent_presta_configuration_get`
- `codeagent_presta_cache_clear`
- `codeagent_presta_logs_read`
- `codeagent_presta_module_enable`
- `codeagent_presta_module_disable`
- `codeagent_presta_module_install`
- `codeagent_presta_module_uninstall`

## Security boundaries

- File writes are restricted to `modules/`, `themes/`, `override/`, `mails/`, and `translations/`.
- Credential/config/session paths are blocked from file reads.
- SQL is read-only and rejects multi-statements and unsafe constructs.
- Module lifecycle operations are independently switchable.
- Destructive tools are annotated as destructive so the CodeAgent gateway requires explicit confirmation.
- CodeAgent Presta cannot disable or uninstall itself through MCP.

## Updates

The module checks the GitHub-hosted `update.json` manifest. Release packages and checksums are published as GitHub Release assets.
