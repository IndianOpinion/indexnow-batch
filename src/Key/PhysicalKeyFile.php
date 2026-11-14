<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch\Key;

use IndianOpinion\IndexNowBatch\Options;

/**
 * Optional physical key file for web servers that answer `.txt` themselves (rule 8).
 *
 * The plugin records each file it writes in a marker option and never
 * overwrites or removes a file it did not write.
 */
final class PhysicalKeyFile
{
    public const WRITTEN = 'written';
    public const UNCHANGED = 'unchanged';
    public const PRESENT_UNMANAGED = 'present_unmanaged';

    /**
     * @param string $directory Directory that serves the home URL.
     */
    public function __construct(private string $directory)
    {
        $this->directory = rtrim($directory, '/\\');
    }

    public function pathFor(string $key): string
    {
        return $this->directory . '/' . $key . '.txt';
    }

    /**
     * @return array{path: string, key: string}|null
     */
    public function marker(): ?array
    {
        $value = get_option(Options::KEY_FILE, null);
        if (!is_array($value) || !isset($value['path'], $value['key'])) {
            return null;
        }

        return ['path' => (string) $value['path'], 'key' => (string) $value['key']];
    }

    /**
     * Writes the key file for $key.
     *
     * @throws \RuntimeException When the file belongs to someone else or cannot be written.
     */
    public function write(string $key): string
    {
        if (!KeyManager::isValid($key)) {
            throw new \RuntimeException('Refusing to write an invalid key.');
        }
        $path = $this->pathFor($key);
        $marker = $this->marker();
        $ours = $marker !== null && $marker['path'] === $path;

        if (is_file($path)) {
            $content = $this->read($path);
            if (!$ours) {
                if ($content === $key) {
                    return self::PRESENT_UNMANAGED;
                }
                throw new \RuntimeException('A file the plugin did not write already exists at ' . $path . '; not overwriting it.');
            }
            if ($content === $key) {
                return self::UNCHANGED;
            }
        }
        if (!is_dir($this->directory) || !is_writable($this->directory)) {
            throw new \RuntimeException('Directory is not writable: ' . $this->directory);
        }
        if (file_put_contents($path, $key, LOCK_EX) !== strlen($key)) {
            throw new \RuntimeException('Could not write ' . $path);
        }
        update_option(Options::KEY_FILE, ['path' => $path, 'key' => $key], false);

        return self::WRITTEN;
    }

    /**
     * Removes the file the marker names, when it still holds the marked key.
     *
     * @return bool Whether a file was removed.
     */
    public function remove(): bool
    {
        $marker = $this->marker();
        if ($marker === null) {
            return false;
        }
        $removed = false;
        if (is_file($marker['path']) && $this->read($marker['path']) === $marker['key']) {
            $removed = unlink($marker['path']);
        }
        delete_option(Options::KEY_FILE);

        return $removed;
    }

    /**
     * After a rotation: writes the new file and removes the old one, only
     * when the plugin manages a file.
     */
    public function rotate(string $newKey): bool
    {
        if ($this->marker() === null) {
            return false;
        }
        $this->remove();
        $this->write($newKey);

        return true;
    }

    private function read(string $path): ?string
    {
        if (!is_readable($path)) {
            return null;
        }
        $content = file_get_contents($path);

        return $content === false ? null : trim($content);
    }
}
