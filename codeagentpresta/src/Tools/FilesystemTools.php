<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/CodeAgentPrestaSupport.php';

use PrestaShop\Module\PsMcpServer\Server\Attributes\PsMcpSchema;
use PrestaShop\Module\PsMcpServer\Server\Attributes\PsMcpTool;
use PrestaShop\Module\PsMcpServer\Server\Attributes\PsMcpToolAnnotations;
use PrestaShop\Module\PsMcpServer\Server\Exceptions\PsMcpToolCallException;

final class CodeAgentPrestaFilesystemTools
{
    #[PsMcpTool(name: 'codeagent_presta_files_list', title: 'List PrestaShop files', description: 'Lists files and directories inside the PrestaShop root. Sensitive credential/session paths are excluded.', annotations: new PsMcpToolAnnotations(title: 'List PrestaShop files', readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: ['path' => ['type' => 'string', 'description' => 'Path relative to the PrestaShop root. Empty string means root.'], 'recursive' => ['type' => 'boolean', 'description' => 'Recursively list descendants.'], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500]], required: [])]
    public function filesList(string $path = '', bool $recursive = false, int $limit = 200): array
    {
        return CodeAgentPrestaSupport::listFiles($path, $recursive, $limit);
    }

    #[PsMcpTool(name: 'codeagent_presta_file_read', title: 'Read PrestaShop file', description: 'Reads a bounded byte range from a file under the PrestaShop root and returns its SHA-256 for optimistic concurrency.', annotations: new PsMcpToolAnnotations(title: 'Read PrestaShop file', readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: ['path' => ['type' => 'string'], 'offset' => ['type' => 'integer', 'minimum' => 0], 'length' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1048576]], required: ['path'])]
    public function fileRead(string $path, int $offset = 0, int $length = 262144): array
    {
        return CodeAgentPrestaSupport::readFile($path, $offset, $length);
    }

    #[PsMcpTool(name: 'codeagent_presta_file_search', title: 'Search PrestaShop files', description: 'Searches text files under a directory for a literal or regular-expression pattern. Results are bounded.', annotations: new PsMcpToolAnnotations(title: 'Search PrestaShop files', readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: ['path' => ['type' => 'string'], 'query' => ['type' => 'string', 'minLength' => 1], 'regex' => ['type' => 'boolean'], 'case_sensitive' => ['type' => 'boolean'], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200]], required: ['query'])]
    public function fileSearch(string $query, string $path = '', bool $regex = false, bool $case_sensitive = false, int $limit = 100): array
    {
        $relative = CodeAgentPrestaSupport::normalizeRelative($path);
        CodeAgentPrestaSupport::assertReadablePath($relative);
        $root = CodeAgentPrestaSupport::absolute($relative);
        if (!is_dir($root)) {
            throw new PsMcpToolCallException('Search path must be a directory.', 1);
        }
        if (strlen($query) > 500) {
            throw new PsMcpToolCallException('Search query is too long.', 1);
        }
        $limit = max(1, min(200, $limit));
        $results = [];
        $extensions = ['php','tpl','twig','js','css','scss','json','xml','yml','yaml','md','txt','sql','html','htm'];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getSize() > 2097152) {
                continue;
            }
            $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
            if (!in_array($ext, $extensions, true)) {
                continue;
            }
            $full = str_replace('\\', '/', $file->getPathname());
            $rel = ltrim(substr($full, strlen(CodeAgentPrestaSupport::root())), '/');
            try {
                CodeAgentPrestaSupport::assertReadablePath($rel);
            } catch (Throwable $e) {
                continue;
            }
            $handle = @fopen($full, 'rb');
            if ($handle === false) {
                continue;
            }
            $lineNo = 0;
            while (($line = fgets($handle)) !== false) {
                $lineNo++;
                $matched = false;
                if ($regex) {
                    $flags = $case_sensitive ? 'u' : 'iu';
                    $matched = @preg_match('~' . str_replace('~', '\\~', $query) . '~' . $flags, $line) === 1;
                } else {
                    $matched = $case_sensitive ? str_contains($line, $query) : stripos($line, $query) !== false;
                }
                if ($matched) {
                    $results[] = ['path' => $rel, 'line' => $lineNo, 'text' => mb_substr(rtrim($line), 0, 1000)];
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

    #[PsMcpTool(name: 'codeagent_presta_file_write', title: 'Write PrestaShop development file', description: 'Atomically creates or replaces a file in modules, themes, override, mails or translations. Use expected_sha256 when replacing an existing file.', annotations: new PsMcpToolAnnotations(title: 'Write PrestaShop development file', readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: ['path' => ['type' => 'string'], 'content' => ['type' => 'string'], 'expected_sha256' => ['type' => 'string', 'description' => 'Optional SHA-256 returned by file_read.']], required: ['path','content'])]
    public function fileWrite(string $path, string $content, string $expected_sha256 = ''): array
    {
        return CodeAgentPrestaSupport::writeFile($path, $content, $expected_sha256 !== '' ? $expected_sha256 : null);
    }

    #[PsMcpTool(name: 'codeagent_presta_file_delete', title: 'Delete PrestaShop development file', description: 'Deletes one file under the writable development roots. Directories and paths outside allowed roots are rejected.', annotations: new PsMcpToolAnnotations(title: 'Delete PrestaShop development file', readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: ['path' => ['type' => 'string'], 'expected_sha256' => ['type' => 'string']], required: ['path'])]
    public function fileDelete(string $path, string $expected_sha256 = ''): array
    {
        $relative = CodeAgentPrestaSupport::normalizeRelative($path);
        CodeAgentPrestaSupport::assertWritablePath($relative);
        $absolute = CodeAgentPrestaSupport::absolute($relative);
        if (!is_file($absolute) || is_link($absolute)) {
            throw new PsMcpToolCallException('Only regular files can be deleted by this tool.', 1);
        }
        if ($expected_sha256 !== '') {
            $current = hash_file('sha256', $absolute);
            if (!is_string($current) || !hash_equals(strtolower($expected_sha256), strtolower($current))) {
                throw new PsMcpToolCallException('File changed since it was read. Re-read it before deletion.', 1);
            }
        }
        if (!unlink($absolute)) {
            throw new PsMcpToolCallException('Unable to delete file.', 1);
        }
        return ['deleted' => true, 'path' => $relative];
    }

    #[PsMcpTool(name: 'codeagent_presta_directory_create', title: 'Create PrestaShop development directory', description: 'Creates a directory recursively inside an allowed development root. Existing parent directories are validated against the PrestaShop root.', annotations: new PsMcpToolAnnotations(title: 'Create PrestaShop development directory', readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: ['path' => ['type' => 'string']], required: ['path'])]
    public function directoryCreate(string $path): array
    {
        $relative = CodeAgentPrestaSupport::normalizeRelative($path);
        CodeAgentPrestaSupport::assertWritablePath($relative . '/placeholder');
        $root = CodeAgentPrestaSupport::root();
        $absolute = $root . '/' . $relative;
        if (is_dir($absolute)) {
            return ['created' => false, 'exists' => true, 'path' => $relative];
        }
        $probe = dirname($absolute);
        while (!is_dir($probe) && $probe !== $root && str_starts_with(str_replace('\\', '/', $probe), $root . '/')) {
            $probe = dirname($probe);
        }
        $realParent = realpath($probe);
        if ($realParent === false) {
            throw new PsMcpToolCallException('Unable to resolve destination parent.', 1);
        }
        $realParent = str_replace('\\', '/', $realParent);
        if ($realParent !== $root && !str_starts_with($realParent, $root . '/')) {
            throw new PsMcpToolCallException('Directory path escapes the PrestaShop root.', 1);
        }
        if (!mkdir($absolute, 0755, true) && !is_dir($absolute)) {
            throw new PsMcpToolCallException('Unable to create directory.', 1);
        }
        return ['created' => true, 'path' => $relative];
    }
}
