<?php

declare(strict_types=1);

namespace Formation\Repositories;

class FormationRepository
{
    private string $projectRoot;
    private string $docsRoot;

    public function __construct(string $projectRoot)
    {
        $this->projectRoot = rtrim($projectRoot, '/\\');
        $this->docsRoot = $this->projectRoot . '/docs/formation';
    }

    public function projectRoot(): string
    {
        return $this->projectRoot;
    }

    public function docsRoot(): string
    {
        return $this->docsRoot;
    }

    public function writeDocument(string $path, string $content): bool
    {
        $resolved = realpath($path);
        $docsRoot = realpath($this->docsRoot);

        if ($resolved === false || $docsRoot === false) {
            return false;
        }

        $resolved = str_replace('\\', '/', $resolved);
        $docsRoot = rtrim(str_replace('\\', '/', $docsRoot), '/') . '/';
        if (strpos($resolved, $docsRoot) !== 0 || !is_writable($path)) {
            return false;
        }

        return file_put_contents($path, rtrim($content) . "\n") !== false;
    }
}
