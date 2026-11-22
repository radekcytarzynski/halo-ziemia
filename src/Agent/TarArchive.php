<?php

declare(strict_types=1);

namespace App\Agent;

/**
 * Minimalny zapis archiwum tar (format ustar) – bez zależności od rozszerzenia phar
 * (na hostingu współdzielonym bywa zablokowane przez phar.readonly).
 */
final class TarArchive
{
    private const int BLOCK = 512;

    private string $data = '';

    public function addFile(string $name, string $contents, int $mode, int $mtime): void
    {
        if (\strlen($name) > 100) {
            throw new \InvalidArgumentException(\sprintf('Za długa ścieżka w archiwum: %s', $name));
        }

        $header = pack(
            'a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12',
            $name,
            \sprintf('%07o', $mode & 0o7777),
            \sprintf('%07o', 0),
            \sprintf('%07o', 0),
            \sprintf('%011o', \strlen($contents)),
            \sprintf('%011o', $mtime),
            str_repeat(' ', 8),
            '0',
            '',
            "ustar\0",
            '00',
            'root',
            'root',
            '',
            '',
            '',
            '',
        );

        $checksum = array_sum(array_map('ord', str_split($header)));
        $header = substr_replace($header, \sprintf("%06o\0 ", $checksum), 148, 8);

        $this->data .= $header.$contents.str_repeat("\0", (self::BLOCK - \strlen($contents) % self::BLOCK) % self::BLOCK);
    }

    public function toString(): string
    {
        return $this->data.str_repeat("\0", self::BLOCK * 2);
    }

    public function toGzip(): string
    {
        $gz = gzencode($this->toString(), 9);
        if (false === $gz) {
            throw new \RuntimeException('Nie udało się skompresować archiwum.');
        }

        return $gz;
    }
}
