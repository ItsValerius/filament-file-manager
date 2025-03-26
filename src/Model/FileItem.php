<?php

namespace BostjanOb\FilamentFileManager\Model;

use Carbon\Carbon;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use League\Flysystem\StorageAttributes;
use Sushi\Sushi;

/**
 * A Sushi-powered Eloquent model that holds a transient "listing" of
 * files/folders from OneDrive (or any disk) for display in Filament.
 */
class FileItem extends Model
{
    use Sushi;

    /**
     * We store the actual Filesystem instance in a static property,
     * along with the path being browsed.
     */
    protected static Filesystem $diskInstance;

    protected static string $path;

    /**
     * Sushi requires a schema. Adjust as needed for your columns.
     */
    protected array $schema = [
        'name' => 'string',
        'dateModified' => 'datetime',
        'size' => 'integer',
        'type' => 'string',
    ];


    /**
     * Called to "initialize" the disk + path before you query().
     */
    public static function queryForDiskAndPath(Filesystem $disk, string $path = ''): Builder
    {
        static::$diskInstance = $disk;
        static::$path = $path;

        // Return the Sushi model's query builder:
        return static::query();
    }

    /**
     * Returns whether this row is a folder according to the "type" column.
     */
    public function isFolder(): bool
    {
        // If you stored "Folder" as the 'type' for directories:
        return $this->type === 'Folder';
    }

    /**
     * Checks if it's the special "go up one directory" entry.
     */
    public function isPreviousPath(): bool
    {
        return $this->name === '..';
    }

    /**
     * Delete this item (file or folder) on the remote disk.
     */
    public function delete(): bool
    {
        if ($this->isFolder() && ! $this->isPreviousPath()) {
            return static::$diskInstance->deleteDirectory($this->path);
        }

        return static::$diskInstance->delete($this->path);
    }

    /**
     * Whether the file can be "opened" directly (e.g. preview/download).
     * This is optional and depends on your own logic.
     */
    public function canOpen(): bool
    {
        if ($this->isFolder()) {
            return false;
        }

        // Example: check if the file actually exists and is public:
        if (! static::$diskInstance->exists($this->path)) {
            return false;
        }

        return static::$diskInstance->getVisibility($this->path)
            === Filesystem::VISIBILITY_PUBLIC;
    }

    /**
     * Sushi's method for retrieving the "in-memory" rows.
     *
     * Instead of enumerating directories/files individually,
     * we make ONE call to `listContents()` to get an array
     * of StorageAttributes (which already contain size, timestamps, etc.).
     */
    public function getRows(): array
    {
        $disk = static::$diskInstance;
        $path = static::$path;

        // If there's a path (not the root), create a "go up" entry:
        $backPath = [];
        if ($path) {
            $pathSegments = Str::of($path)->explode('/');
            $upOne = $pathSegments->count() > 1
                ? $pathSegments->take($pathSegments->count() - 1)->join('/')
                : '';

            $backPath[] = [
                'name' => '..',
                'dateModified' => null,
                'size' => null,
                'type' => 'Folder',
                'path' => $upOne,
            ];
        }

        // List all items in the current path with a single call:
        $items = $disk->listContents($path, /* $deep = */ false);

        // Convert the items into arrays for Sushi:
        $mapped = collect($items)
            ->sortBy(fn (StorageAttributes $attr) => basename($attr->path())) // Sort by name if you like
            ->map(function (StorageAttributes $attr) {
                $isFile = $attr->isFile();

                return [
                    'name' => basename($attr->path()),
                    'dateModified' => $attr->lastModified()
                        ? Carbon::createFromTimestamp($attr->lastModified())
                        : null,
                    'size' => $isFile ? $attr->fileSize() : null,
                    // You could store "Folder" or the actual mime-type for files:
                    'type' => $isFile
                        ? ($attr->mimeType() ?: 'file')
                        : 'Folder',
                    'path' => $attr->path(),
                ];
            })
            ->values()
            ->toArray();

        // Return the "go up" entry (if any) plus the real listing:
        return array_merge($backPath, $mapped);
    }
    public static function getPath(): string
    {
        return static::$path;
    }
}
