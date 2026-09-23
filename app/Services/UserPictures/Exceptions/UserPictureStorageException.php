<?php

declare(strict_types=1);

namespace Spora\Services\UserPictures\Exceptions;

use RuntimeException;

/**
 * Thrown by {@see \Spora\Services\UserPictures\UserPictureService} when
 * a write against the `<storage>/user-pictures/` on-disk store or the
 * `user_pictures` table fails (permissions, disk full, missing row
 * after insert, etc.). Surfaces as an HTTP 500 via the Kernel — there
 * is no useful client-side recovery, and the user can simply retry
 * the upload.
 */
final class UserPictureStorageException extends RuntimeException
{
    public static function onWrite(string $path): self
    {
        return new self("Failed to write user picture to {$path}");
    }

    public static function onRename(string $dst): self
    {
        return new self("Failed to move user picture into place at {$dst}");
    }

    public static function onMkdir(string $dir): self
    {
        return new self("Failed to create user-pictures directory: {$dir}");
    }
}
