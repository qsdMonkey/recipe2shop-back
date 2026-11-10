<?php

declare(strict_types=1);

namespace App\Prompt;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Lit les prompts versionnés dans api/prompts/<nom>.md (jamais de prompt en dur dans le PHP).
 */
final class PromptLoader
{
    /** @var array<string, string> */
    private array $cache = [];

    public function __construct(
        #[Autowire('%kernel.project_dir%/prompts')]
        private readonly string $directory,
    ) {
    }

    public function load(string $name): string
    {
        if (!preg_match('/^[a-z0-9_]+$/', $name)) {
            throw new \InvalidArgumentException(\sprintf('Invalid prompt name "%s".', $name));
        }

        if (!isset($this->cache[$name])) {
            $path = \sprintf('%s/%s.md', $this->directory, $name);
            if (!is_file($path)) {
                throw new \RuntimeException(\sprintf('Prompt file "%s" not found.', $path));
            }
            $this->cache[$name] = trim((string) file_get_contents($path));
        }

        return $this->cache[$name];
    }
}
