<?php

final class SimpleZipWriter
{
    /** @var resource */
    private $handle;

    /** @var array<int, array<string, int|string>> */
    private array $entries = [];

    private bool $closed = false;

    public function __construct(string $path)
    {
        $handle = fopen($path, 'w+b');
        if ($handle === false) {
            throw new RuntimeException('Impossible de creer le fichier ZIP.');
        }
        $this->handle = $handle;
    }

    public function addFile(string $sourcePath, string $entryName): bool
    {
        if ($this->closed || !is_file($sourcePath) || !is_readable($sourcePath)) {
            return false;
        }

        $entryName = $this->normalizeEntryName($entryName);
        if ($entryName === '') {
            return false;
        }

        $size = filesize($sourcePath);
        if ($size === false || $size > 0xFFFFFFFF) {
            return false;
        }

        $crcHex = hash_file('crc32b', $sourcePath);
        if ($crcHex === false) {
            return false;
        }

        $source = fopen($sourcePath, 'rb');
        if ($source === false) {
            return false;
        }

        $offset = ftell($this->handle);
        if ($offset === false || $offset > 0xFFFFFFFF) {
            fclose($source);
            return false;
        }

        [$dosTime, $dosDate] = $this->dosDateTime((int)(filemtime($sourcePath) ?: time()));
        $flags = 0x0800;
        $crc = (int)hexdec($crcHex);
        $nameLength = strlen($entryName);

        $header = pack(
            'VvvvvvVVVvv',
            0x04034b50,
            20,
            $flags,
            0,
            $dosTime,
            $dosDate,
            $crc,
            $size,
            $size,
            $nameLength,
            0
        );

        if (!$this->writeAll($header . $entryName)) {
            fclose($source);
            return false;
        }

        $copied = stream_copy_to_stream($source, $this->handle);
        fclose($source);
        if ($copied === false || $copied !== $size) {
            return false;
        }

        $this->entries[] = [
            'name' => $entryName,
            'crc' => $crc,
            'size' => (int)$size,
            'time' => $dosTime,
            'date' => $dosDate,
            'flags' => $flags,
            'offset' => (int)$offset,
        ];

        return true;
    }

    public function close(): bool
    {
        if ($this->closed) {
            return true;
        }

        $centralOffset = ftell($this->handle);
        if ($centralOffset === false || $centralOffset > 0xFFFFFFFF || count($this->entries) > 0xFFFF) {
            fclose($this->handle);
            $this->closed = true;
            return false;
        }

        foreach ($this->entries as $entry) {
            $name = (string)$entry['name'];
            $header = pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014b50,
                20,
                20,
                (int)$entry['flags'],
                0,
                (int)$entry['time'],
                (int)$entry['date'],
                (int)$entry['crc'],
                (int)$entry['size'],
                (int)$entry['size'],
                strlen($name),
                0,
                0,
                0,
                0,
                0,
                (int)$entry['offset']
            );
            if (!$this->writeAll($header . $name)) {
                fclose($this->handle);
                $this->closed = true;
                return false;
            }
        }

        $centralEnd = ftell($this->handle);
        if ($centralEnd === false) {
            fclose($this->handle);
            $this->closed = true;
            return false;
        }

        $centralSize = $centralEnd - $centralOffset;
        $entryCount = count($this->entries);
        $footer = pack(
            'VvvvvVVv',
            0x06054b50,
            0,
            0,
            $entryCount,
            $entryCount,
            $centralSize,
            $centralOffset,
            0
        );
        $success = $this->writeAll($footer);
        fclose($this->handle);
        $this->closed = true;

        return $success;
    }

    private function normalizeEntryName(string $entryName): string
    {
        $entryName = str_replace('\\', '/', trim($entryName));
        $parts = [];
        foreach (explode('/', $entryName) as $part) {
            $part = trim(str_replace(["\0", "\r", "\n"], '', $part));
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }
        return implode('/', $parts);
    }

    /** @return array{0: int, 1: int} */
    private function dosDateTime(int $timestamp): array
    {
        $parts = getdate($timestamp);
        $year = max(1980, min(2107, (int)$parts['year']));
        $dosTime = ((int)$parts['hours'] << 11) | ((int)$parts['minutes'] << 5) | intdiv((int)$parts['seconds'], 2);
        $dosDate = (($year - 1980) << 9) | ((int)$parts['mon'] << 5) | (int)$parts['mday'];
        return [$dosTime, $dosDate];
    }

    private function writeAll(string $data): bool
    {
        $length = strlen($data);
        $written = 0;
        while ($written < $length) {
            $result = fwrite($this->handle, substr($data, $written));
            if ($result === false || $result === 0) {
                return false;
            }
            $written += $result;
        }
        return true;
    }
}
