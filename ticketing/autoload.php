<?php
spl_autoload_register(function ($class) {
    $prefix = 'Ticketing\\';
    $baseDir = __DIR__ . '/';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $relative = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
    if (!file_exists($file)) {
        $parts = explode('\\', $relative);
        $parts = array_map(static fn(string $part): string => strtolower($part), $parts);
        $parts[count($parts)-1] = basename(str_replace('\\', '/', $relative));
        $file = $baseDir . implode('/', $parts) . '.php';
    }
    if (file_exists($file)) require $file;
});
