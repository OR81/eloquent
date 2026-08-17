<?php

namespace Or81\Eloquent;

use RuntimeException;

/**
 * Reads environment values, so a project can keep its credentials in a .env
 * file instead of in code.
 *
 * If vlucas/phpdotenv is installed it is used to load the file, exactly as an
 * application would:
 *
 *     Dotenv::createImmutable($directory)->safeLoad();
 *
 * Without that package a small built-in parser reads the same file, so nothing
 * has to be installed for this to work. Values already present in the real
 * environment ($_ENV, $_SERVER or getenv) always win over the file.
 */
class Env
{
    protected static bool $booted = false;

    /** Values read by the built-in parser, used when the key is not in the environment. */
    protected static array $values = [];

    /** Runtime overrides set through set(), which beat everything else. */
    protected static array $overrides = [];

    /** An explicit .env location set through useEnv(). */
    protected static ?string $path = null;

    protected static ?string $loadedFrom = null;

    /**
     * Point at a .env file, or at the directory holding one. Call before the
     * first query; it resets anything already read.
     */
    public static function use(string $path): void
    {
        if (! file_exists($path)) {
            throw new RuntimeException("No .env file at [{$path}].");
        }

        self::$path = is_dir($path) ? rtrim($path, '/\\') . DIRECTORY_SEPARATOR . '.env' : $path;

        self::reset();
    }

    public static function get(string $key, $default = null)
    {
        self::boot();

        if (array_key_exists($key, self::$overrides)) {
            return self::normalize(self::$overrides[$key]);
        }

        foreach ([$_ENV, $_SERVER] as $source) {
            if (array_key_exists($key, $source)) {
                return self::normalize($source[$key]);
            }
        }

        $value = getenv($key);

        if ($value !== false) {
            return self::normalize($value);
        }

        if (array_key_exists($key, self::$values)) {
            return self::normalize(self::$values[$key]);
        }

        return $default;
    }

    public static function has(string $key): bool
    {
        return self::get($key, '__missing__') !== '__missing__';
    }

    /**
     * Override a value at runtime. Pass null to drop the override and fall
     * back to the environment or the file again. Handy in tests.
     */
    public static function set(string $key, ?string $value): void
    {
        self::boot();

        if ($value === null) {
            unset(self::$overrides[$key]);

            return;
        }

        self::$overrides[$key] = $value;
    }

    /**
     * Everything the built-in parser read, for debugging.
     */
    public static function all(): array
    {
        self::boot();

        return self::$values;
    }

    /**
     * The file the values came from, or null when none was found.
     */
    public static function loadedFrom(): ?string
    {
        self::boot();

        return self::$loadedFrom;
    }

    /**
     * Forget everything, overrides included, so the next read starts over.
     */
    public static function reset(): void
    {
        self::$booted = false;
        self::$values = [];
        self::$overrides = [];
        self::$loadedFrom = null;
    }

    protected static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;

        $file = self::$path ?? self::discover();

        if ($file === null || ! is_file($file) || ! is_readable($file)) {
            return;
        }

        self::$loadedFrom = $file;

        // Hand over to phpdotenv when the project already has it.
        if (class_exists('\Dotenv\Dotenv') && method_exists('\Dotenv\Dotenv', 'createImmutable')) {
            \Dotenv\Dotenv::createImmutable(dirname($file), basename($file))->safeLoad();
        }

        self::$values = self::parse($file);
    }

    /**
     * Look in the working directory first, then walk up from this package,
     * which finds the project root when installed under vendor/.
     */
    protected static function discover(): ?string
    {
        $candidates = [getcwd()];

        $directory = dirname(__DIR__);

        for ($depth = 0; $depth < 6; $depth++) {
            $candidates[] = $directory;

            $parent = dirname($directory);

            if ($parent === $directory) {
                break;
            }

            $directory = $parent;
        }

        foreach ($candidates as $candidate) {
            if ($candidate === false || $candidate === '') {
                continue;
            }

            $file = rtrim($candidate, '/\\') . DIRECTORY_SEPARATOR . '.env';

            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * A deliberately small .env reader: KEY=value, quotes, # comments and an
     * optional `export` prefix. No variable interpolation.
     */
    protected static function parse(string $file): array
    {
        $values = [];

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return $values;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }

            if (strpos($line, 'export ') === 0) {
                $line = trim(substr($line, 7));
            }

            [$key, $value] = explode('=', $line, 2);

            $key = trim($key);

            if ($key === '' || ! preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $key)) {
                continue;
            }

            $values[$key] = self::unquote(trim($value));
        }

        return $values;
    }

    protected static function unquote(string $value): string
    {
        $length = strlen($value);

        if ($length >= 2) {
            $first = $value[0];

            if (($first === '"' || $first === "'") && $value[$length - 1] === $first) {
                $value = substr($value, 1, -1);

                return $first === '"' ? str_replace(['\\n', '\\"', '\\\\'], ["\n", '"', '\\'], $value) : $value;
            }
        }

        // An unquoted value ends at the first inline comment.
        $position = strpos($value, ' #');

        return $position === false ? $value : rtrim(substr($value, 0, $position));
    }

    /**
     * Turn the handful of magic words into real PHP values.
     */
    protected static function normalize($value)
    {
        if (! is_string($value)) {
            return $value;
        }

        switch (strtolower($value)) {
            case 'true':
            case '(true)':
                return true;
            case 'false':
            case '(false)':
                return false;
            case 'null':
            case '(null)':
                return null;
            case 'empty':
            case '(empty)':
                return '';
        }

        return $value;
    }
}
