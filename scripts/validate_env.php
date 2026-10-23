#!/usr/bin/env php
<?php

declare(strict_types=1);

final class DeploymentEnvironmentValidator
{
    /**
     * Validate a Laravel environment file and repair secrets that were
     * accidentally split by horizontal whitespace while being pasted.
     */
    public static function run(array $arguments): int
    {
        $path = $arguments[1] ?? '.env';

        if (! is_file($path) || ! is_readable($path)) {
            self::error("Environment file is missing or unreadable: {$path}");

            return 1;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            self::error("Unable to read environment file: {$path}");

            return 1;
        }

        [$repairedContents, $repairs] = self::repairSpacedHexSecrets($contents);

        if ($repairs !== []) {
            if (! self::replaceWithBackup($path, $repairedContents)) {
                return 1;
            }

            foreach ($repairs as $repair) {
                printf(
                    "[env] Repaired whitespace inside hexadecimal secret %s on line %d.\n",
                    $repair['name'],
                    $repair['line'],
                );
            }

            echo "[env] Original environment file saved as {$path}.backup.\n";
            $contents = $repairedContents;
        }

        $invalidEntries = self::findUnquotedWhitespace($contents);
        if ($invalidEntries !== []) {
            foreach ($invalidEntries as $entry) {
                self::error(sprintf(
                    'Invalid unquoted whitespace in %s on line %d. Remove it or quote the complete value.',
                    $entry['name'],
                    $entry['line'],
                ));
            }

            return 1;
        }

        $autoload = dirname(__DIR__).'/vendor/autoload.php';
        if (! is_file($autoload)) {
            self::error('Composer dependencies are unavailable; cannot validate .env syntax.');

            return 1;
        }

        require_once $autoload;

        try {
            Dotenv\Dotenv::parse($contents);
        } catch (Throwable) {
            self::error('The environment file still has invalid dotenv syntax. Check quotes and reserved characters; secret values are intentionally hidden.');

            return 1;
        }

        echo "[env] Environment syntax is valid.\n";

        return 0;
    }

    /**
     * @return array{string, list<array{name: string, line: int}>}
     */
    public static function repairSpacedHexSecrets(string $contents): array
    {
        $lines = explode("\n", $contents);
        $repairs = [];

        foreach ($lines as $index => $line) {
            $carriageReturn = str_ends_with($line, "\r") ? "\r" : '';
            $lineWithoutEnding = rtrim($line, "\r");

            if (! preg_match(
                '/^(\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_.]*)\s*=\s*)([0-9A-Fa-f]+(?:[ \t]+[0-9A-Fa-f]+)+)(\s*)$/',
                $lineWithoutEnding,
                $matches,
            )) {
                continue;
            }

            $compactValue = preg_replace('/[ \t]+/', '', $matches[3]);
            if ($compactValue === null || strlen($compactValue) !== 64) {
                continue;
            }

            $lines[$index] = $matches[1].$compactValue.$matches[4].$carriageReturn;
            $repairs[] = ['name' => $matches[2], 'line' => $index + 1];
        }

        return [implode("\n", $lines), $repairs];
    }

    /**
     * @return list<array{name: string, line: int}>
     */
    public static function findUnquotedWhitespace(string $contents): array
    {
        $invalidEntries = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $index => $line) {
            if (! preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_.]*)\s*=\s*(.*)$/', $line, $matches)) {
                continue;
            }

            $value = trim($matches[2]);
            if ($value === '' || $value[0] === "'" || $value[0] === '"') {
                continue;
            }

            $valueWithoutComment = preg_replace('/\s+#.*$/', '', $value);
            if ($valueWithoutComment !== null && preg_match('/\s/', $valueWithoutComment)) {
                $invalidEntries[] = ['name' => $matches[1], 'line' => $index + 1];
            }
        }

        return $invalidEntries;
    }

    private static function replaceWithBackup(string $path, string $contents): bool
    {
        $backupPath = $path.'.backup';
        if (! copy($path, $backupPath)) {
            self::error("Unable to back up {$path} before repairing it.");

            return false;
        }

        $permissions = fileperms($path);
        if ($permissions !== false) {
            chmod($backupPath, $permissions & 0777);
        }

        $temporaryPath = tempnam(dirname($path), '.env.deploy.');
        if ($temporaryPath === false) {
            self::error("Unable to create a temporary environment file beside {$path}.");

            return false;
        }

        if (file_put_contents($temporaryPath, $contents, LOCK_EX) === false) {
            @unlink($temporaryPath);
            self::error("Unable to write the repaired environment file: {$path}");

            return false;
        }

        if ($permissions !== false) {
            chmod($temporaryPath, $permissions & 0777);
        }

        if (! rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            self::error("Unable to replace the environment file: {$path}");

            return false;
        }

        return true;
    }

    private static function error(string $message): void
    {
        fwrite(STDERR, "[error] {$message}\n");
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(DeploymentEnvironmentValidator::run($argv));
}
