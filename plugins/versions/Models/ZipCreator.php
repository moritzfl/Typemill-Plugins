<?php

namespace Plugins\versions\Models;

final class ZipCreator
{
    private ?\ZipArchive $zipArchive = null;

    private ?StoredZipBuilder $storedBuilder = null;

    private ?string $zipPath = null;

    public static function open(): ?self
    {
        $creator = new self();

        if (class_exists(\ZipArchive::class)) {
            $tempPath = tempnam(sys_get_temp_dir(), 'tm_zip_');
            if ($tempPath === false) {
                return null;
            }

            $zipPath = $tempPath . '.zip';
            unlink($tempPath);

            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                return null;
            }

            $creator->zipArchive = $zip;
            $creator->zipPath = $zipPath;

            return $creator;
        }

        $creator->storedBuilder = new StoredZipBuilder();

        return $creator;
    }

    public function addFromString(string $name, string $content): bool
    {
        if ($this->zipArchive !== null) {
            return $this->zipArchive->addFromString($name, $content);
        }

        return $this->storedBuilder?->addFromString($name, $content) ?? false;
    }

    public function addFile(string $name, string $filePath): bool
    {
        if ($this->zipArchive !== null) {
            return $this->zipArchive->addFile($filePath, $name);
        }

        return $this->storedBuilder?->addFile($name, $filePath) ?? false;
    }

    public function finish(): ?string
    {
        if ($this->zipArchive !== null) {
            $this->zipArchive->close();
            $this->zipArchive = null;

            if ($this->zipPath === null || !is_file($this->zipPath)) {
                return null;
            }

            $content = file_get_contents($this->zipPath);
            $this->removeTempFile();

            return $content === false ? null : $content;
        }

        return $this->storedBuilder?->build();
    }

    public function __destruct()
    {
        if ($this->zipArchive !== null) {
            $this->zipArchive->close();
            $this->zipArchive = null;
        }

        $this->removeTempFile();
    }

    private function removeTempFile(): void
    {
        if ($this->zipPath !== null && is_file($this->zipPath)) {
            unlink($this->zipPath);
        }

        $this->zipPath = null;
    }
}
