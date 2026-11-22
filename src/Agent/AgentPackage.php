<?php

declare(strict_types=1);

namespace App\Agent;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Paczka agenta (katalog agent/ bez testów) jako tar.gz z katalogiem halo-ziemia-agent/.
 */
final readonly class AgentPackage
{
    public const string ROOT = 'halo-ziemia-agent';
    public const string FILENAME = 'halo-ziemia-agent.tar.gz';

    public function __construct(
        #[Autowire('%kernel.project_dir%/agent')]
        private string $agentDir,
    ) {
    }

    /**
     * @return list<string> ścieżki względne plików wchodzących do paczki
     */
    public function files(): array
    {
        if (!is_dir($this->agentDir)) {
            throw new \RuntimeException(\sprintf('Brak katalogu agenta: %s', $this->agentDir));
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->agentDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            $relative = substr($file->getPathname(), \strlen($this->agentDir) + 1);
            if (str_starts_with($relative, 'tests/')) {
                continue;
            }
            $files[] = $relative;
        }
        sort($files);

        return $files;
    }

    public function build(): string
    {
        $tar = new TarArchive();
        foreach ($this->files() as $relative) {
            $path = $this->agentDir.'/'.$relative;
            $contents = file_get_contents($path);
            if (false === $contents) {
                throw new \RuntimeException(\sprintf('Nie można odczytać %s', $path));
            }
            $tar->addFile(self::ROOT.'/'.$relative, $contents, is_executable($path) ? 0o755 : 0o644, (int) filemtime($path));
        }

        return $tar->toGzip();
    }

    public function version(): ?string
    {
        $script = @file_get_contents($this->agentDir.'/halo-ziemia-agent');

        return false !== $script && 1 === preg_match('/^HZ_VERSION="([^"]+)"/m', $script, $m) ? $m[1] : null;
    }
}
