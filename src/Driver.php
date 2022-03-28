<?php declare(strict_types=1);

namespace Pilulka\ElfinderFlysystemDriver;

use elFinder;
use elFinderVolumeDriver;
use Intervention\Image\ImageManager;
use League\Flysystem\Filesystem;
use League\Flysystem\PathNormalizer;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\WhitespacePathNormalizer;
use Pilulka\ElfinderFlysystemDriver\Util\Util;

class Driver extends elFinderVolumeDriver
{
    protected $driverId = 'fls';

    protected Filesystem $fs;
    protected ?ImageManager $imageManager = null;
    protected Cache $localCache;
    private PathNormalizer $pathNormalizer;

    public function __construct()
    {
        $opts = [
            'filesystem' => null,
            'glideURL' => null,
            'glideKey' => null,
            'imageManager' => null,
            'cache' => 'session',   // 'session', 'memory' or false
            'fscache' => null,      // The Flysystem cache
            'checkSubfolders' => false, // Disable for performance
        ];

        $this->options = array_merge($this->options, $opts);

        $this->localCache = new Cache();
        $this->pathNormalizer = new WhitespacePathNormalizer();
    }

    public function mount(array $opts)
    {
        if (!isset($opts['path']) || $opts['path'] === '') {
            $opts['path'] = '/';
        }

        return parent::mount($opts);
    }

    protected function getIcon(): string
    {
        $parentUrl = defined('ELFINDER_IMG_PARENT_URL') ? (rtrim(ELFINDER_IMG_PARENT_URL, '/') . '/') : '';

        return $parentUrl . 'img/volume_icon_local.png';
    }

    protected function init(): bool
    {
        $this->fs = $this->options['filesystem'];

        $this->options['icon'] = $this->options['icon'] ?: (empty($this->options['rootCssClass']) ? $this->getIcon() : '');
        $this->root = $this->options['path'];

        $this->imageManager = new ImageManager();
        $this->options['useRemoteArchive'] = true;

        return true;
    }

    /**
     * @param string $path file path
     **/
    protected function _dirname($path): string
    {
        return Util::dirname($path) ?: '/';
    }

    /**
     * @param string $path path
     **/
    protected function _normpath($path): string
    {
        return $path;
    }

    protected function _dirExists(string $path): bool
    {
        $dir = $this->_dirname($path);
        $basename = basename($path);

        foreach ($this->listContents($dir, true) as $meta) {
            if ($meta && $meta['type'] !== 'file' && $meta['basename'] == $basename) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $path file path
     **/
    protected function _stat($path): array|false
    {
        $stat = [
            'size' => 0,
            'ts' => time(),
            'read' => true,
            'write' => true,
            'locked' => false,
            'hidden' => false,
            'mime' => 'directory',
        ];

        // If root, just return from above
        if ($this->root == $path) {
            $stat['name'] = $this->root;

            return $stat;
        }

        // If not exists, return empty
        if (!$this->fs->fileExists($path)) {
            // Check if the parent doesn't have this path
            if ($this->_dirExists($path)) {
                return $stat;
            }

            // Neither a file or directory exist, return empty
            return [];
        }

        $pathParts = pathinfo($path);

        $stat['path'] = $path;
        $stat['dirname'] = $pathParts['dirname'];
        $stat['basename'] = $pathParts['basename'];
        $stat['filename'] = $pathParts['filename'];

        if (!str_contains($path, '.')) {
            $stat['type'] = 'dir';

            return $stat;
        }

        $stat['type'] = 'file';

        try {
            $stat['ts'] = $this->fs->lastModified($path);
        } catch (UnableToRetrieveMetadata) {
            return [];
        }

        $stat['size'] = $this->fs->fileSize($path);
        $stat['mime'] = $this->fs->mimeType($path);
        $stat['hidden'] = $this->fs->visibility($path) !== 'public';
        $stat['mimetype'] = $this->fs->mimeType($path);
        $stat['timestamp'] = $this->fs->lastModified($path);

        return $stat;
    }

    /**
     * @param string $path dir path
     **/
    protected function _subdirs($path): bool
    {
        $contents = $this->listContents($path, true);

        $filter = static function ($item): bool {
            $type = $item['type'] ?? null;

            return $type !== null && $item['type'] === 'dir';
        };

        $contents = $contents->filter($filter);
        $dirs = $contents->toArray();

        return !empty($dirs);
    }

    /**
     * @param string $path file path
     * @param string $mime file mime type
     **/
    protected function _dimensions($path, $mime): string
    {
        $ret = false;
        if ($imgsize = $this->getImageSize($path, $mime)) {
            $ret = $imgsize['dimensions'];
        }

        return $ret;
    }

    /**
     * @param string $path dir path
     **/
    protected function _scandir($path): array
    {
        $paths = [];

        foreach ($this->listContents($path, false) as $object) {
            if ($object) {
                $paths[] = $object['path'];
            }
        }

        return $paths;
    }

    /**
     * @param string $path file path
     * @param string $mode
     **/
    protected function _fopen($path, $mode = 'rb'): mixed
    {
        return $this->fs->readStream($path);
    }

    /**
     * @param resource $fp file pointer
     * @param string $path file path
     **/
    protected function _fclose($fp, $path = ''): bool
    {
        return @fclose($fp);
    }

    /**
     * @param string $path parent dir path
     * @param string $name new directory name
     **/
    protected function _mkdir($path, $name): string|bool
    {
        $path = $this->_joinPath($path, $name);
        $this->fs->createDirectory($path);

        return $path;
    }

    /**
     * @param string $path parent dir path
     * @param string $name new file name
     **/
    protected function _mkfile($path, $name): string|bool
    {
        $path = $this->_joinPath($path, $name);
        $this->fs->write($path, '', []);

        return $path;
    }

    /**
     * @param string $source source file path
     * @param string $targetDir target directory path
     * @param string $name new file name
     **/
    protected function _copy($source, $targetDir, $name): string|bool
    {
        $path = $this->_joinPath($targetDir, $name);
        $this->fs->copy($source, $path, []);

        return $path;
    }

    /**
     * @param string $source source file path
     * @param string $targetDir target dir path
     * @param string $name file name
     **/
    protected function _move($source, $targetDir, $name): string|bool
    {
        $path = $this->_joinPath($targetDir, $name);
        $this->fs->move($source, $path, []);

        return $path;
    }

    /**
     * @param string $path file path
     **/
    protected function _unlink($path): bool
    {
        $this->fs->delete($path);

        return true;
    }

    /**
     * @param string $path dir path
     **/
    protected function _rmdir($path): bool
    {
        $this->fs->deleteDirectory($path);

        return true;
    }

    /**
     * @param resource $fp file pointer
     * @param string $dir target dir path
     * @param string $name file name
     * @param array $stat file stat (required by some virtual fs)
     **/
    protected function _save($fp, $dir, $name, $stat): bool|string
    {
        $path = $this->_joinPath($dir, $name);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $config = [];
        if (isset(self::$mimetypes[$ext])) {
            $config['mimetype'] = self::$mimetypes[$ext];
        }

        $config['visibility'] = 'public';
        if (isset($this->options['visibility'])) {
            $config['visibility'] = $this->options['visibility'];
        }

        $this->fs->writeStream($path, $fp, $config);

        return $path;
    }

    /**
     * @param string $path file path
     **/
    protected function _getContents($path): string|false
    {
        return $this->fs->read($path);
    }

    /**
     * @param string $path file path
     * @param string $content new file content
     **/
    protected function _filePutContents($path, $content): bool
    {
        $this->fs->write($path, $content, []);

        return true;
    }

    /**
     * @param string $path file path
     **/
    protected function _basename($path): string
    {
        return basename($path);
    }

    /**
     * @param string $dir
     * @param string $name
     **/
    protected function _joinPath($dir, $name): string
    {
        return Util::normalizePath($dir . $this->separator . $name);
    }

    /**
     * @param string $path file path
     **/
    protected function _relpath($path): string
    {
        return $path;
    }

    /**
     * @param string $path file path
     **/
    protected function _abspath($path): string
    {
        return $path;
    }

    /**
     * @param string $path file path
     **/
    protected function _path($path): string
    {
        return $this->rootName . $this->separator . $path;
    }

    /**
     * @param string $path path to check
     * @param string $parent parent path
     **/
    protected function _inpath($path, $parent): bool
    {
        return $path == $parent || str_starts_with($path, $parent . '/');
    }

    /**
     * @param string $source file to link to
     * @param string $targetDir folder to create link in
     * @param string $name symlink name
     **/
    protected function _symlink($source, $targetDir, $name): bool
    {
        return false;
    }

    /**
     * @param string $path file path
     * @param array $arc archiver options
     **/
    protected function _extract($path, $arc): bool
    {
        return false;
    }

    /**
     * @param string $dir target dir
     * @param array $files files names list
     * @param string $name archive name
     * @param array $arc archiver options
     **/
    protected function _archive($dir, $files, $name, $arc): string|bool
    {
        return false;
    }

    /**
     * Detect available archivers
     *
     * @return void
     **/
    protected function _checkArchivers(): void
    {
    }

    protected function _chmod($path, $mode): bool
    {
        return false;
    }

    /**
     * @param string $hash image file
     * @param int $width new width
     * @param int $height new height
     * @param int $x X start poistion for crop
     * @param int $y Y start poistion for crop
     * @param string $mode action how to mainpulate image
     * @param string $bg background color
     * @param int $degree rotete degree
     * @param int $jpgQuality JEPG quality (1-100)
     **/
    public function resize($hash, $width, $height, $x, $y, $mode = 'resize', $bg = '', $degree = 0, $jpgQuality = null): array|false
    {
        if ($this->commandDisabled('resize')) {
            return $this->setError(elFinder::ERROR_PERM_DENIED);
        }

        if (($file = $this->file($hash)) == false) {
            return $this->setError(elFinder::ERROR_FILE_NOT_FOUND);
        }

        if (!$file['write'] || !$file['read']) {
            return $this->setError(elFinder::ERROR_PERM_DENIED);
        }

        $path = $this->decode($hash);
        if (!$this->canResize($path, $file)) {
            return $this->setError(elFinder::ERROR_UNSUPPORT_TYPE);
        }

        if (!$image = $this->imageManager->make($this->_getContents($path))) {
            return false;
        }

        switch ($mode) {
            case 'propresize':
                $image->resize($width, $height, function ($constraint) {
                    $constraint->aspectRatio();
                });
                break;

            case 'crop':
                $image->crop($width, $height, $x, $y);
                break;

            case 'fitsquare':
                $image->fit($width, $height, null, 'center');
                break;

            case 'rotate':
                $image->rotate($degree);
                break;

            default:
                $image->resize($width, $height);
                break;
        }

        if ($jpgQuality && $image->mime() === 'image/jpeg') {
            $result = (string) $image->encode('jpg', $jpgQuality);
        } else {
            $result = (string) $image->encode();
        }
        if ($result && $this->_filePutContents($path, $result)) {
            $this->rmTmb($file);
            $this->clearstatcache();
            $stat = $this->stat($path);
            $stat['width'] = $image->width();
            $stat['height'] = $image->height();
            return $stat;
        }

        return false;
    }

    public function getImageSize($path, $mime = ''): array|false
    {
        $size = false;
        if ($mime === '' || strtolower(substr($mime, 0, 5)) === 'image') {
            if ($data = $this->_getContents($path)) {
                if ($size = @getimagesizefromstring($data)) {
                    $size['dimensions'] = $size[0] . 'x' . $size[1];
                }
            }
        }
        return $size;
    }

    /**
     * @param string $hash file hash
     * @param array $options options
     **/
    public function getContentUrl($hash, $options = []): string
    {
        if (!empty($options['onetime']) && $this->options['onetimeUrl']) {
            // use parent method to make onetime URL
            return parent::getContentUrl($hash, $options);
        }
        if (!empty($options['temporary'])) {
            // try make temporary file
            $url = parent::getContentUrl($hash, $options);
            if ($url) {
                return $url;
            }
        }
        if (($file = $this->file($hash)) == false || !isset($file['url']) || !$file['url'] || $file['url'] == 1) {
            if ($file && !empty($file['url']) && !empty($options['temporary'])) {
                return parent::getContentUrl($hash, $options);
            }

            return parent::getContentUrl($hash, $options);
        }
        return $file['url'];
    }

    public function listContents(string $location, bool $deep): iterable
    {
        $normalizePath = $this->pathNormalizer->normalizePath($location);

        if ($this->localCache->isComplete($normalizePath, $deep)) {
            return $this->localCache->listContents($normalizePath, $deep);
        }

        $result = $this->fs->listContents($normalizePath, $deep);
        $result = iterator_to_array($result);
        $this->localCache->storeContents($normalizePath, $result, $deep);

        return $this->localCache->listContents($normalizePath, $deep);
    }
}
