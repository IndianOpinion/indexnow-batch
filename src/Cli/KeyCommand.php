<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch\Cli;

use IndianOpinion\IndexNowBatch\Key\KeyVerifier;
use IndianOpinion\IndexNowBatch\Key\PhysicalKeyFile;
use IndianOpinion\IndexNowBatch\Plugin;

/**
 * Shows, verifies and rotates the key, and manages the physical key file.
 */
final class KeyCommand
{
    public function __construct(private Plugin $plugin)
    {
    }

    private function usableKey(): string
    {
        $error = $this->plugin->keys()->configError();
        if ($error !== null) {
            \WP_CLI::error($error);
        }

        return $this->plugin->keys()->key();
    }

    /**
     * Prints the full key.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function show(array $args, array $assoc): void
    {
        \WP_CLI::line($this->usableKey());
    }

    /**
     * Fetches the key file from the site and prints the result.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function verify(array $args, array $assoc): void
    {
        $key = $this->usableKey();
        $url = $this->plugin->rules()->keyLocation($key);
        $verifier = $this->plugin->verifier();
        $result = $verifier->check($key, $url);
        $verifier->store($key, $url, $result['state'], $result['detail']);
        $message = sprintf('%s: %s%s', $url, $result['state'], $result['detail'] !== '' ? ' (' . $result['detail'] . ')' : '');
        if ($result['state'] === KeyVerifier::UNVERIFIED) {
            \WP_CLI::error($message);
        }
        if ($result['state'] === KeyVerifier::UNKNOWN) {
            \WP_CLI::warning($message . '. The site could not fetch itself; sends will proceed.');

            return;
        }
        \WP_CLI::success($message);
    }

    /**
     * Generates a new key. The old key file stops being served at once.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Do not ask for confirmation.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function rotate(array $args, array $assoc): void
    {
        \WP_CLI::confirm('Rotate the IndexNow key?', $assoc);
        try {
            $key = $this->plugin->rotateKey();
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());

            return;
        }
        \WP_CLI::success('New key ' . \IndianOpinion\IndexNowBatch\Key\KeyManager::mask($key) . ' in place. Verification cleared.');
    }

    /**
     * Writes the key file into the directory that serves the home URL.
     *
     * For web servers that answer .txt requests themselves.
     *
     * @subcommand write-file
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function write_file(array $args, array $assoc): void
    {
        $key = $this->usableKey();
        $file = $this->plugin->physicalKeyFile();
        try {
            $result = $file->write($key);
        } catch (\RuntimeException $e) {
            \WP_CLI::error($e->getMessage());

            return;
        }
        $path = $file->pathFor($key);
        if ($result === PhysicalKeyFile::PRESENT_UNMANAGED) {
            \WP_CLI::success($path . ' already holds the key; it was not written by the plugin and will not be managed.');

            return;
        }
        \WP_CLI::success(($result === PhysicalKeyFile::WRITTEN ? 'Wrote ' : 'Already current: ') . $path);
    }

    /**
     * Removes the key file the plugin wrote.
     *
     * @subcommand remove-file
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function remove_file(array $args, array $assoc): void
    {
        $file = $this->plugin->physicalKeyFile();
        if ($file->marker() === null) {
            \WP_CLI::warning('The plugin has not written a key file; nothing to remove.');

            return;
        }
        $removed = $file->remove();
        \WP_CLI::success($removed ? 'Key file removed.' : 'Marker cleared; the file was missing or changed and was left alone.');
    }
}
