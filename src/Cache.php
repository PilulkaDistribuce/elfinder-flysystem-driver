<?php declare(strict_types=1);

namespace Pilulka\ElfinderFlysystemDriver;

use League\Flysystem\DirectoryListing;
use Pilulka\ElfinderFlysystemDriver\Util\Util;

class Cache
{
    protected array $cache = [];
    protected array $complete = [];

    public function storeContents(string $directory, array $contents, bool $recursive = false): void
    {
        $directories = [$directory];

        foreach ($contents as $object) {
            $this->updateObject($object['path'], Util::pathinfo($object['path']));
            $object = $this->cache[$object['path']];

            if ($recursive && $this->pathIsInDirectory($directory, $object['path'])) {
                $directories[] = $object['dirname'];
            }
        }

        foreach (array_unique($directories) as $directory) {
            $this->setComplete($directory, $recursive);
        }
    }

    public function updateObject(string $path, array $object): void
    {
        if (!$this->fileExists($path)) {
            $this->cache[$path] = Util::pathinfo($path);
        }

        $this->cache[$path] = array_merge($this->cache[$path], $object);

        $this->ensureParentDirectories($path);
    }

    public function setComplete($dirname, $recursive): void
    {
        $this->complete[$dirname] = $recursive ? 'recursive' : true;
    }

    protected function pathIsInDirectory($directory, $path)
    {
        return $directory === '' || str_starts_with($path, $directory . '/');
    }

    public function ensureParentDirectories($path): void
    {
        $object = $this->cache[$path];

        while ($object['dirname'] !== '' && !isset($this->cache[$object['dirname']])) {
            $object = Util::pathinfo($object['dirname']);
            $object['type'] = 'dir';
            $this->cache[$object['path']] = $object;
        }
    }

    public function fileExists(string $location): bool
    {
        if ($location !== false && array_key_exists($location, $this->cache)) {
            return $this->cache[$location] !== false;
        }

        return $this->isComplete(Util::dirname($location), false);

    }

    public function isComplete($dirname, $recursive): bool
    {
        if (!array_key_exists($dirname, $this->complete)) {
            return false;
        }

        if ($recursive && $this->complete[$dirname] !== 'recursive') {
            return false;
        }

        return true;
    }

    public function listContents(string $location, bool $deep = false): DirectoryListing
    {
        $result = [];

        foreach ($this->cache as $object) {
            if ($object === false) {
                continue;
            }

            if ($object['dirname'] === $location) {
                $result[] = $object;
            } elseif ($deep && $this->pathIsInDirectory($location, $object['path'])) {
                $result[] = $object;
            }
        }

        return new DirectoryListing($result);
    }
}
