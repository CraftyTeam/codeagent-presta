# CodeAgent Presta

MCP development, maintenance and design tools for PrestaShop, discovered by the official PrestaShop MCP Server.

## Requirements

- PrestaShop 8.x or 9.x
- Official PrestaShop MCP Server (`ps_mcp_server`)
- Official PrestaShop MCP Tools (`ps_mcp_tools`) for commerce operations
- PHP 8.1+

## Installation

Upload `codeagentpresta.zip` from PrestaShop Back Office → Modules → Module Manager → Upload a module, then install **CodeAgent Presta**.

The module declares `isMcpCompliant()` and the official MCP server discovers the tools under `src/` automatically.

## Tool surface

CodeAgent Presta 1.1.0 exposes 47 development/design tools grouped around:

- filesystem inspection, bounded search, read, patch, write, copy, move and safe deletion;
- module inspection and lifecycle management;
- database schema inspection and bounded read-only SQL;
- hooks, module attachment/detachment and hook positioning per shop;
- theme metadata, template precedence, theme module overrides and hook maps;
- Symfony route/service discovery and controller/override inspection;
- PrestaShop environment, configuration, logs, cache, debug and maintenance diagnostics;
- explicit frontend performance settings for theme development.

Commerce operations such as products, orders and customers remain provided by the official `ps_mcp_tools` module and are not duplicated here.

## Security boundaries

- File writes are restricted to `modules/`, `themes/`, `override/`, `mails/`, and `translations/`.
- Symlink traversal is blocked for mutations.
- Credential, session, raw log and runtime-cache paths are blocked from generic file reads.
- Log tools redact common password/token/API-key patterns.
- Existing files require SHA-256 optimistic concurrency before replacement; patch/move operations use the same protection.
- File reads/writes and searches are bounded for CPU/RAM safety.
- SQL is read-only, bounded, single-statement and rejects unsafe constructs, locks and system-schema access.
- Module lifecycle operations are independently switchable.
- Destructive MCP tools are annotated so the CodeAgent gateway can require explicit confirmation.
- CodeAgent Presta cannot disable or uninstall itself through MCP.

## Updates

The module reads the GitHub-hosted `update.json` manifest. Release packages and SHA-256 checksums are published as GitHub Release assets.
