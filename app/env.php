<?php
/**
 * Reads the .env file in the project root, so passwords live outside the code.
 *
 * Format: one KEY=value per line. Blank lines and lines starting with # are
 * ignored. Wrap a value in single or double quotes if it has spaces, a # or
 * leading/trailing spaces in it. A real environment variable of the same name
 * (set in Apache or the shell) wins over the file.
 */
declare(strict_types=1);

if (!function_exists('env')) {
    /** @return array<string,string> */
    function env_file_values(?string $file = null): array
    {
        static $values = null;
        if ($values !== null) {
            return $values;
        }
        $values = [];
        $file = $file ?? dirname(__DIR__) . '/.env';
        if (!is_file($file) || !is_readable($file)) {
            return $values;
        }

        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (strncmp($line, 'export ', 7) === 0) {
                $line = ltrim(substr($line, 7));
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($line, 0, $eq));
            $val = trim(substr($line, $eq + 1));
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }

            $q = $val[0] ?? '';
            if (($q === '"' || $q === "'") && ($end = strrpos($val, $q)) > 0) {
                $val = substr($val, 1, $end - 1);
                if ($q === '"') {
                    $val = strtr($val, ['\\n' => "\n", '\\"' => '"', '\\\\' => '\\']);
                }
            } else {
                // Unquoted: a " #" starts a trailing comment.
                $val = rtrim((string)preg_replace('/\s+#.*$/', '', $val));
            }
            $values[$key] = $val;
        }
        return $values;
    }

    /**
     * A setting from the environment or .env, or $default when it is not set.
     * "true"/"false" come back as booleans; everything else as a string.
     */
    function env(string $key, mixed $default = null): mixed
    {
        $real = getenv($key);
        $val = $real !== false ? $real : (env_file_values()[$key] ?? null);
        if ($val === null) {
            return $default;
        }
        return match (strtolower($val)) {
            'true'  => true,
            'false' => false,
            default => $val,
        };
    }
}
