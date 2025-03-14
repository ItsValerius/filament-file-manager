<?php

namespace BostjanOb\FilamentFileManager\Model;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Sushi\Sushi;

class FileItem extends Model
{
    use Sushi;

    // Instead of a disk name, we store the built disk instance.
    protected static Filesystem $diskInstance;

    protected static string $path;

    protected array $schema = [
        'name' => 'string',
        'dateModified' => 'datetime',
        'size' => 'integer',
        'type' => 'string',
    ];

    /**
     * Accepts a disk configuration array and a path.
     * For example:
     * [
     *    'driver' => 'local',
     *    'root'   => storage_path('app/public'),
     * ]
     */
    public static function queryForDiskAndPath(FileSystem $disk, string $path = ''): Builder
    {
        // Build the disk dynamically using the provided configuration.
        static::$diskInstance = ($disk);
        static::$path = $path;

        return static::query();
    }

    public function isFolder(): bool
    {

        return $this->type === 'Folder'
            && static::$diskInstance->exists(($this->path));
    }

    public function isPreviousPath(): bool
    {
        return $this->name === '..';
    }

    public function delete(): bool
    {
        if ($this->isFolder()) {
            return static::$diskInstance->deleteDirectory($this->path);
        }

        return static::$diskInstance->delete($this->path);
    }

    public function canOpen(): bool
    {
        return $this->type !== 'Folder'
            && static::$diskInstance->exists($this->path)
            && static::$diskInstance->getVisibility($this->path) === FileSystem::VISIBILITY_PUBLIC;
    }

    public function getRows(): array
    {
        $backPath = [];
        if (self::$path) {
            $pathSegments = Str::of(self::$path)->explode('/');

            $backPath = [
                [
                    'name' => '..',
                    'dateModified' => null,
                    'size' => null,
                    'type' => 'Folder',
                    'path' => $pathSegments->count() > 1
                        ? $pathSegments->take($pathSegments->count() - 1)->join('/')
                        : '',
                ],
            ];
        }

        $storage = static::$diskInstance;

        return collect($backPath)->push(
            ...collect($storage->directories(static::$path))
                ->sort()
                ->map(fn (string $directory): array => [
                    'name' => Str::remove(self::$path.'/', $directory),
                    'dateModified' => $storage->lastModified($directory),
                    'size' => null,
                    'type' => 'Folder',
                    'path' => $directory,
                ]),
            ...collect($storage->files(static::$path))
                ->sort()
                ->map(fn (string $file): array => [
                    'name' => Str::remove(self::$path.'/', $file),
                    'dateModified' => $storage->lastModified($file),
                    'size' => $storage->size($file),
                    'type' => $storage->mimeType($file) ?: null,
                    'path' => $file,
                ])
        )->toArray();
    }
}
