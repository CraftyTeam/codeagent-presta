<?php

declare(strict_types=1);

use PrestaShop\Module\PsMcpServer\Server\Exceptions\PsMcpToolCallException;

final class CodeAgentPrestaSupport
{
    private const MAX_READ_BYTES = 1048576;
    private const MAX_WRITE_BYTES = 1048576;
    private const MAX_RESULTS = 500;

    public static function root(): string
    {
        $root = realpath(_PS_ROOT_DIR_);
        if ($root === false) {
            throw new PsMcpToolCallException('PrestaShop root directory is unavailable.', 1);
        }
        return rtrim(str_replace('\\', '/', $root), '/');
    }

    public static function normalizeRelative(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('#/+#', '/', $path) ?: '';
        $path = ltrim($path, '/');
        if ($path === '' || $path === '.') {
            return '';
        }
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                throw new PsMcpToolCallException('Parent directory traversal is not allowed.', 1);
            }
            if (strpos($part, "\0") !== false) {
                throw new PsMcpToolCallException('Invalid path.', 1);
            }
            $parts[] = $part;
        }
        return implode('/', $parts);
    }

    public static function absolute(string $path, bool $mustExist = true): string
    {
        $relative = self::normalizeRelative($path);
        $root = self::root();
        $absolute = $relative === '' ? $root : $root . '/' . $relative;
        if ($mustExist) {
            $real = realpath($absolute);
            if ($real === false) {
                throw new PsMcpToolCallException('Path does not exist: ' . $relative, 1);
            }
            $real = str_replace('\\', '/', $real);
            if ($real !== $root && !str_starts_with($real, $root . '/')) {
                throw new PsMcpToolCallException('Path escapes the PrestaShop root.', 1);
            }
            return $real;
        }
        $parent = realpath(dirname($absolute));
        if ($parent === false) {
            throw new PsMcpToolCallException('Parent directory does not exist.', 1);
        }
        $parent = str_replace('\\', '/', $parent);
        if ($parent !== $root && !str_starts_with($parent, $root . '/')) {
            throw new PsMcpToolCallException('Path escapes the PrestaShop root.', 1);
        }
        return $parent . '/' . basename($absolute);
    }

    public static function assertReadablePath(string $relative): void
    {
        $relative = strtolower(self::normalizeRelative($relative));
        $blocked = [
            'app/config/parameters.php',
            'config/settings.inc.php',
            '.env',
            '.env.local',
            '.git/',
            'var/sessions/',
        ];
        foreach ($blocked as $item) {
            if ($relative === rtrim($item, '/') || str_starts_with($relative . '/', $item)) {
                throw new PsMcpToolCallException('Access to sensitive credentials or session storage is blocked.', 1);
            }
        }
    }

    public static function assertWritablePath(string $relative): void
    {
        if (!(bool) Configuration::get('CODEAGENT_PRESTA_ALLOW_WRITES')) {
            throw new PsMcpToolCallException('File writes are disabled in CodeAgent Presta settings.', 1);
        }
        $relative = self::normalizeRelative($relative);
        $allowed = ['modules/', 'themes/', 'override/', 'mails/', 'translations/'];
        foreach ($allowed as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                return;
            }
        }
        throw new PsMcpToolCallException('Writes are restricted to modules, themes, override, mails and translations.', 1);
    }

    public static function readFile(string $path, int $offset = 0, int $length = 262144): array
    {
        $relative = self::normalizeRelative($path);
        self::assertReadablePath($relative);
        $absolute = self::absolute($relative);
        if (!is_file($absolute) || !is_readable($absolute)) {
            throw new PsMcpToolCallException('File is not readable.', 1);
        }
        $size = filesize($absolute);
        if ($size === false) {
            throw new PsMcpToolCallException('Unable to read file metadata.', 1);
        }
        $offset = max(0, $offset);
        $length = max(1, min(self::MAX_READ_BYTES, $length));
        $handle = fopen($absolute, 'rb');
        if ($handle === false) {
            throw new PsMcpToolCallException('Unable to open file.', 1);
        }
        if ($offset > 0) {
            fseek($handle, $offset);
        }
        $content = fread($handle, $length);
        fclose($handle);
        if ($content === false) {
            throw new PsMcpToolCallException('Unable to read file.', 1);
        }
        return [
            'path' => $relative,
            'content' => $content,
            'offset' => $offset,
            'bytes' => strlen($content),
            'size' => $size,
            'truncated' => ($offset + strlen($content)) < $size,
            'sha256' => hash_file('sha256', $absolute),
        ];
    }

    public static function writeFile(string $path, string $content, ?string $expectedSha256 = null): array
    {
        $relative = self::normalizeRelative($path);
        self::assertWritablePath($relative);
        if (strlen($content) > self::MAX_WRITE_BYTES) {
            throw new PsMcpToolCallException('File content exceeds the 1 MB write limit.', 1);
        }
        $absolute = self::absolute($relative, file_exists(self::root() . '/' . $relative));
        if (file_exists($absolute) && $expectedSha256 !== null && $expectedSha256 !== '') {
            $current = hash_file('sha256', $absolute);
            if (!is_string($current) || !hash_equals(strtolower($expectedSha256), strtolower($current))) {
                throw new PsMcpToolCallException('File changed since it was read. Re-read it before writing.', 1);
            }
        }
        $directory = dirname($absolute);
        if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
            throw new PsMcpToolCallException('Unable to create destination directory.', 1);
        }
        $tmp = tempnam($directory, '.codeagent-');
        if ($tmp === false || file_put_contents($tmp, $content, LOCK_EX) === false) {
            if (is_string($tmp)) {
                @unlink($tmp);
            }
            throw new PsMcpToolCallException('Unable to write temporary file.', 1);
        }
        @chmod($tmp, 0644);
        if (!rename($tmp, $absolute)) {
            @unlink($tmp);
            throw new PsMcpToolCallException('Unable to replace destination file.', 1);
        }
        return ['path' => $relative, 'bytes' => strlen($content), 'sha256' => hash_file('sha256', $absolute)];
    }

    public static function listFiles(string $path, bool $recursive = false, int $limit = 200): array
    {
        $relative = self::normalizeRelative($path);
        self::assertReadablePath($relative);
        $absolute = self::absolute($relative);
        if (!is_dir($absolute)) {
            throw new PsMcpToolCallException('Path is not a directory.', 1);
        }
        $limit = max(1, min(self::MAX_RESULTS, $limit));
        $items = [];
        $iterator = $recursive
            ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST)
            : new IteratorIterator(new DirectoryIterator($absolute));
        foreach ($iterator as $file) {
            if ($file instanceof DirectoryIterator && $file->isDot()) {
                continue;
            }
            $full = str_replace('\\', '/', $file->getPathname());
            $rel = ltrim(substr($full, strlen(self::root())), '/');
            try {
                self::assertReadablePath($rel);
            } catch (Throwable $e) {
                continue;
            }
            $items[] = [
                'path' => $rel,
                'type' => $file->isDir() ? 'directory' : ($file->isLink() ? 'link' : 'file'),
                'size' => $file->isFile() ? $file->getSize() : null,
                'modified_at' => gmdate('c', $file->getMTime()),
            ];
            if (count($items) >= $limit) {
                break;
            }
        }
        return ['path' => $relative, 'items' => $items, 'count' => count($items), 'truncated' => count($items) >= $limit];
    }

    public static function clearCache(): array
    {
        if (!(bool) Configuration::get('CODEAGENT_PRESTA_ALLOW_WRITES')) {
            throw new PsMcpToolCallException('Writes are disabled in CodeAgent Presta settings.', 1);
        }
        $paths = [
            _PS_ROOT_DIR_ . '/var/cache/prod',
            _PS_ROOT_DIR_ . '/var/cache/dev',
            _PS_ROOT_DIR_ . '/cache/smarty/cache',
            _PS_ROOT_DIR_ . '/cache/smarty/compile',
        ];
        $removed = 0;
        foreach ($paths as $path) {
            if (!is_dir($path)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $item) {
                $ok = $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
                if ($ok) {
                    $removed++;
                }
            }
        }
        return ['cleared' => true, 'entries_removed' => $removed];
    }

    public static function dbPrefix(): string
    {
        return defined('_DB_PREFIX_') ? (string) _DB_PREFIX_ : '';
    }
}
