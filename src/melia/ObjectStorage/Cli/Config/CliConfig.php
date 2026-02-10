<?php

declare(strict_types=1);

namespace melia\ObjectStorage\Cli\Config;

use RuntimeException;

final class CliConfig
{
    private const FILE_NAME = 'object-storage-cli.ini';
    private const KEY_SHARD_DEPTH = 'shard_depth';

    public function getShardDepthForDir(string $dir): ?int
    {
        $data = $this->read();

        $section = $this->dirToSection($dir);
        if (!isset($data[$section]) || !is_array($data[$section])) {
            return null;
        }

        $value = $data[$section][self::KEY_SHARD_DEPTH] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int)$value;
        }

        return null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function read(): array
    {
        $path = $this->getConfigPath();
        if (!is_file($path)) {
            return [];
        }

        $ini = @parse_ini_file($path, true, INI_SCANNER_TYPED);
        if (!is_array($ini)) {
            return [];
        }

        // Ensure only sectioned structure (array of arrays)
        foreach ($ini as $section => $values) {
            if (!is_array($values)) {
                unset($ini[$section]);
            }
        }

        /** @var array<string, array<string, mixed>> $ini */
        return $ini;
    }

    /**
     * @return string absolute path to the central CLI config file
     */
    public function getConfigPath(): string
    {
        // Windows: %APPDATA%\object-storage\object-storage-cli.ini
        $appData = getenv('APPDATA');
        if (is_string($appData) && $appData !== '') {
            return rtrim($appData, DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR . 'object-storage'
                . DIRECTORY_SEPARATOR . self::FILE_NAME;
        }

        // Fallback (Linux/macOS): ~/.config/object-storage/object-storage-cli.ini
        $home = getenv('HOME');
        if (is_string($home) && $home !== '') {
            return rtrim($home, DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR . '.config'
                . DIRECTORY_SEPARATOR . 'object-storage'
                . DIRECTORY_SEPARATOR . self::FILE_NAME;
        }

        // Last resort: temp directory
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'object-storage'
            . DIRECTORY_SEPARATOR . self::FILE_NAME;
    }

    private function dirToSection(string $dir): string
    {
        return $this->sanitizeSectionName($this->normalizeDirKey($dir));
    }

    private function sanitizeSectionName(string $section): string
    {
        // Keep section readable (actual path), but avoid breaking INI syntax.
        // Brackets are not allowed inside section headers.
        return str_replace(['[', ']'], '_', $section);
    }

    private function normalizeDirKey(string $dir): string
    {
        $dir = trim($dir);
        if ($dir === '') {
            return $dir;
        }

        $real = realpath($dir);
        $dir = $real !== false ? $real : $dir;

        $dir = rtrim($dir, "/\\");
        $dir = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $dir);

        // Windows is typically case-insensitive
        if (DIRECTORY_SEPARATOR === '\\') {
            $dir = strtolower($dir);
        }

        return $dir;
    }

    public function setShardDepthForDir(string $dir, int $depth): void
    {
        $data = $this->read();

        $section = $this->dirToSection($dir);
        if (!isset($data[$section]) || !is_array($data[$section])) {
            $data[$section] = [];
        }

        $data[$section][self::KEY_SHARD_DEPTH] = $depth;

        $this->write($data);
    }

    /**
     * @param array<string, array<string, mixed>> $data
     */
    private function write(array $data): void
    {
        $path = $this->getConfigPath();
        $dir = dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create config directory: ' . $dir);
        }

        // Build INI content
        $out = '';
        foreach ($data as $section => $values) {
            if (!is_array($values) || $values === []) {
                continue;
            }

            $out .= '[' . $this->sanitizeSectionName((string)$section) . ']' . PHP_EOL;

            foreach ($values as $key => $value) {
                $out .= $this->formatIniLine((string)$key, $value) . PHP_EOL;
            }

            $out .= PHP_EOL;
        }

        if (file_put_contents($path, $out, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write CLI config file: ' . $path);
        }
    }

    private function formatIniLine(string $key, mixed $value): string
    {
        if (is_bool($value)) {
            return $key . ' = ' . ($value ? 'true' : 'false');
        }

        if (is_int($value) || is_float($value)) {
            return $key . ' = ' . $value;
        }

        if ($value === null) {
            // INI has no true null; write empty string
            return $key . ' = ""';
        }

        // string (escape quotes)
        $s = (string)$value;
        $s = str_replace('"', '\"', $s);

        return $key . ' = "' . $s . '"';
    }

    public function unsetShardDepthForDir(string $dir): void
    {
        $data = $this->read();

        $section = $this->dirToSection($dir);
        if (!isset($data[$section]) || !is_array($data[$section])) {
            $this->write($data);
            return;
        }

        unset($data[$section][self::KEY_SHARD_DEPTH]);

        // If section is empty, remove it entirely (keeps file tidy)
        if ($data[$section] === []) {
            unset($data[$section]);
        }

        $this->write($data);
    }
}