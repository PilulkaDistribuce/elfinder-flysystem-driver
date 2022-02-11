<?php declare(strict_types=1);

namespace Pilulka\ElfinderFlysystemDriver\Util;

use League\Flysystem\CorruptedPathDetected;
use LogicException;

class Util
{
    public static function pathinfo(string $path): array
    {
        $pathInfo = compact('path');
        $dirname = dirname($path);

        if ($dirname !== '') {
            $pathInfo['dirname'] = static::normalizeDirname($dirname);
        }

        $pathInfo['basename'] = static::basename($path);

        $pathInfo += pathinfo($pathInfo['basename']);

        return $pathInfo + ['dirname' => ''];
    }

    public static function normalizeDirname(string $dirname): string
    {
        return $dirname === '.' ? '' : $dirname;
    }

    public static function dirname(string $path): string
    {
        return static::normalizeDirname(dirname($path));
    }

    public static function map(array $object, array $map): array
    {
        $result = [];

        foreach ($map as $from => $to) {
            if (!isset($object[$from])) {
                continue;
            }

            $result[$to] = $object[$from];
        }

        return $result;
    }

    public static function normalizePath(string $path): string
    {
        return static::normalizeRelativePath($path);
    }

    public static function normalizeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = static::removeFunkyWhiteSpace($path);
        $parts = [];

        foreach (explode('/', $path) as $part) {
            switch ($part) {
                case '':
                case '.':
                    break;

                case '..':
                    if (empty($parts)) {
                        throw new LogicException(
                            'Path is outside of the defined root, path: [' . $path . ']'
                        );
                    }
                    array_pop($parts);
                    break;

                default:
                    $parts[] = $part;
                    break;
            }
        }

        return implode('/', $parts);
    }

    protected static function removeFunkyWhiteSpace(string $path): string
    {
        if (preg_match('#\p{C}+#u', $path)) {
            throw CorruptedPathDetected::forPath($path);
        }

        return $path;
    }

    private static function basename(string $path): string
    {
        $separators = DIRECTORY_SEPARATOR === '/' ? '/' : '\/';

        $path = rtrim($path, $separators);

        $basename = preg_replace('#.*?([^' . preg_quote($separators, '#') . ']+$)#', '$1', $path);

        if (DIRECTORY_SEPARATOR === '/') {
            return $basename;
        }

        while (preg_match('#^[a-zA-Z]{1}:[^\\\/]#', $basename)) {
            $basename = substr($basename, 2);
        }

        if (preg_match('#^[a-zA-Z]{1}:$#', $basename)) {
            $basename = rtrim($basename, ':');
        }

        return $basename;
    }
}
