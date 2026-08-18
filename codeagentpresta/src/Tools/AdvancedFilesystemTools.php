<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/CodeAgentPrestaSupport.php';

use PrestaShop\Module\PsMcpServer\Server\Attributes\PsMcpSchema;
use PrestaShop\Module\PsMcpServer\Server\Attributes\PsMcpTool;
use PrestaShop\Module\PsMcpServer\Server\Attributes\PsMcpToolAnnotations;
use PrestaShop\Module\PsMcpServer\Server\Exceptions\PsMcpToolCallException;

final class CodeAgentPrestaAdvancedFilesystemTools
{
    #[PsMcpTool(name: 'codeagent_presta_file_stat', title: 'Inspect PrestaShop path metadata', description: 'Returns safe metadata for a file or directory under the PrestaShop root without reading its contents.', annotations: new PsMcpToolAnnotations(title: 'Inspect PrestaShop path metadata', readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: ['path' => ['type' => 'string']], required: ['path'])]
    public function fileStat(string $path): array
    {
        $relative = CodeAgentPrestaSupport::normalizeRelative($path);
        CodeAgentPrestaSupport::assertReadablePath($relative);
        $rawAbsolute = CodeAgentPrestaSupport::root() . '/' . $relative;
        if (is_link($rawAbsolute)) {
            return ['path' => $relative, 'type' => 'link', 'size' => null, 'modified_at' => gmdate('c', filemtime($rawAbsolute) ?: 0), 'permissions' => null, 'readable' => false, 'writable' => false, 'sha256' => null];
        }
        $absolute = CodeAgentPrestaSupport::absolute($relative);
        CodeAgentPrestaSupport::assertReadablePath(CodeAgentPrestaSupport::relativeFromAbsolute($absolute));
        $type = is_dir($absolute) ? 'directory' : (is_file($absolute) ? 'file' : 'other');
        return [
            'path' => $relative,
            'type' => $type,
            'size' => is_file($absolute) ? (filesize($absolute) ?: 0) : null,
            'modified_at' => gmdate('c', filemtime($absolute) ?: 0),
            'permissions' => substr(sprintf('%o', fileperms($absolute) ?: 0), -4),
            'readable' => is_readable($absolute),
            'writable' => is_writable($absolute),
            'sha256' => is_file($absolute) && !is_link($absolute) ? hash_file('sha256', $absolute) : null,
        ];
    }

    #[PsMcpTool(name: 'codeagent_presta_file_patch', title: 'Patch PrestaShop development file', description: 'Applies an exact bounded text replacement to an existing development file using SHA-256 optimistic concurrency.', annotations: new PsMcpToolAnnotations(title: 'Patch PrestaShop development file', readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: ['path' => ['type' => 'string'], 'search' => ['type' => 'string', 'minLength' => 1], 'replace' => ['type' => 'string'], 'expected_sha256' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64], 'replace_all' => ['type' => 'boolean']], required: ['path','search','replace','expected_sha256'])]
    public function filePatch(string $path, string $search, string $replace, string $expected_sha256, bool $replace_all = false): array
    {
        $relative = CodeAgentPrestaSupport::normalizeRelative($path);
        CodeAgentPrestaSupport::assertWritablePath($relative);
        $current = CodeAgentPrestaSupport::readFile($relative, 0, 1048576);
        if (!empty($current['truncated'])) {
            throw new PsMcpToolCallException('Files larger than 1 MB cannot be patched by this tool.', 1);
        }
        if (!hash_equals(strtolower($expected_sha256), strtolower((string) $current['sha256']))) {
            throw new PsMcpToolCallException('File changed since it was read. Re-read it before patching.', 1);
        }
        $content = (string) $current['content'];
        $count = substr_count($content, $search);
        if ($count === 0) {
            throw new PsMcpToolCallException('Search text was not found in the file.', 1);
        }
        if (!$replace_all && $count !== 1) {
            throw new PsMcpToolCallException('Search text is not unique. Refine the patch or set replace_all=true.', 1);
        }
        if ($replace_all) {
            $updated = str_replace($search, $replace, $content, $replacements);
        } else {
            $offset = strpos($content, $search);
            if ($offset === false) {
                throw new PsMcpToolCallException('Search text was not found in the file.', 1);
            }
            $updated = substr_replace($content, $replace, $offset, strlen($search));
            $replacements = 1;
        }
        $written = CodeAgentPrestaSupport::writeFile($relative, $updated, $expected_sha256);
        $written['replacements'] = $replacements;
        return $written;
    }

    #[PsMcpTool(name: 'codeagent_presta_file_copy', title: 'Copy PrestaShop development file', description: 'Copies one regular file between allowed development roots. Existing destinations require their SHA-256.', annotations: new PsMcpToolAnnotations(title: 'Copy PrestaShop development file', readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: ['source' => ['type' => 'string'], 'destination' => ['type' => 'string'], 'destination_sha256' => ['type' => 'string']], required: ['source','destination'])]
    public function fileCopy(string $source, string $destination, string $destination_sha256 = ''): array
    {
        $src = CodeAgentPrestaSupport::normalizeRelative($source);
        $dst = CodeAgentPrestaSupport::normalizeRelative($destination);
        CodeAgentPrestaSupport::assertReadablePath($src);
        CodeAgentPrestaSupport::assertWritablePath($dst);
        $read = CodeAgentPrestaSupport::readFile($src, 0, 1048576);
        if (!empty($read['truncated'])) {
            throw new PsMcpToolCallException('Source file exceeds the 1 MB copy limit.', 1);
        }
        $result = CodeAgentPrestaSupport::writeFile($dst, (string) $read['content'], $destination_sha256 !== '' ? $destination_sha256 : null);
        $result['source'] = $src;
        return $result;
    }

    #[PsMcpTool(name: 'codeagent_presta_file_move', title: 'Move PrestaShop development file', description: 'Moves a regular file between allowed development roots with source SHA-256 concurrency protection.', annotations: new PsMcpToolAnnotations(title: 'Move PrestaShop development file', readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false))]
    #[PsMcpSchema(properties: ['source' => ['type' => 'string'], 'destination' => ['type' => 'string'], 'source_sha256' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64], 'destination_sha256' => ['type' => 'string']], required: ['source','destination','source_sha256'])]
    public function fileMove(string $source, string $destination, string $source_sha256, string $destination_sha256 = ''): array
    {
        $src = CodeAgentPrestaSupport::normalizeRelative($source);
        $dst = CodeAgentPrestaSupport::normalizeRelative($destination);
        CodeAgentPrestaSupport::assertWritablePath($src);
        CodeAgentPrestaSupport::assertWritablePath($dst);
        $sourceRaw = CodeAgentPrestaSupport::root() . '/' . $src;
        if (is_link($sourceRaw)) {
            throw new PsMcpToolCallException('Moving symbolic links is blocked.', 1);
        }
        $sourceAbsolute = CodeAgentPrestaSupport::absolute($src);
        CodeAgentPrestaSupport::assertWritablePath(CodeAgentPrestaSupport::relativeFromAbsolute($sourceAbsolute));
        if (!is_file($sourceAbsolute)) {
            throw new PsMcpToolCallException('Source must be a regular file.', 1);
        }
        $current = hash_file('sha256', $sourceAbsolute);
        if (!is_string($current) || !hash_equals(strtolower($source_sha256), strtolower($current))) {
            throw new PsMcpToolCallException('Source file changed since it was read.', 1);
        }
        $destinationRootPath = CodeAgentPrestaSupport::root() . '/' . $dst;
        $destinationExists = file_exists($destinationRootPath);
        if (is_link($destinationRootPath)) {
            throw new PsMcpToolCallException('Moving over symbolic links is blocked.', 1);
        }
        $destinationAbsolute = CodeAgentPrestaSupport::absolute($dst, $destinationExists);
        CodeAgentPrestaSupport::assertWritablePath(CodeAgentPrestaSupport::relativeFromAbsolute($destinationAbsolute));
        if ($destinationExists) {
            if (!is_file($destinationAbsolute) || is_link($destinationAbsolute)) {
                throw new PsMcpToolCallException('Destination must be a regular file.', 1);
            }
            if ($destination_sha256 === '') {
                throw new PsMcpToolCallException('destination_sha256 is required when replacing an existing destination.', 1);
            }
            $destinationCurrent = hash_file('sha256', $destinationAbsolute);
            if (!is_string($destinationCurrent) || !hash_equals(strtolower($destination_sha256), strtolower($destinationCurrent))) {
                throw new PsMcpToolCallException('Destination file changed since it was read.', 1);
            }
        }
        $directory = dirname($destinationAbsolute);
        if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
            throw new PsMcpToolCallException('Unable to create destination directory.', 1);
        }
        if (!rename($sourceAbsolute, $destinationAbsolute)) {
            throw new PsMcpToolCallException('Unable to move file.', 1);
        }
        return ['moved' => true, 'source' => $src, 'destination' => $dst, 'sha256' => hash_file('sha256', $destinationAbsolute)];
    }

    #[PsMcpTool(name: 'codeagent_presta_directory_delete', title: 'Delete PrestaShop development directory', description: 'Deletes an empty directory under an allowed development root. Recursive deletion is intentionally not supported.', annotations: new PsMcpToolAnnotations(title: 'Delete PrestaShop development directory', readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false))]
    #[PsMcpSchema(properties: ['path' => ['type' => 'string']], required: ['path'])]
    public function directoryDelete(string $path): array
    {
        $relative = CodeAgentPrestaSupport::normalizeRelative($path);
        CodeAgentPrestaSupport::assertWritablePath($relative . '/placeholder');
        $rawAbsolute = CodeAgentPrestaSupport::root() . '/' . $relative;
        if (is_link($rawAbsolute)) {
            throw new PsMcpToolCallException('Deleting symbolic links is blocked.', 1);
        }
        $absolute = CodeAgentPrestaSupport::absolute($relative);
        CodeAgentPrestaSupport::assertWritablePath(CodeAgentPrestaSupport::relativeFromAbsolute($absolute) . '/placeholder');
        if (!is_dir($absolute)) {
            throw new PsMcpToolCallException('Path must be a regular directory.', 1);
        }
        $iterator = new FilesystemIterator($absolute, FilesystemIterator::SKIP_DOTS);
        if ($iterator->valid()) {
            throw new PsMcpToolCallException('Directory is not empty. Recursive deletion is intentionally blocked.', 1);
        }
        if (!rmdir($absolute)) {
            throw new PsMcpToolCallException('Unable to delete directory.', 1);
        }
        return ['deleted' => true, 'path' => $relative];
    }
}
