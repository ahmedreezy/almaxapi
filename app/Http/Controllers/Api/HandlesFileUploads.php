<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Shared file upload/delete helpers used by controllers that handle public media.
 *
 * Security:
 * - MIME type verified via finfo (not just extension)
 * - UUID filenames (no user-controlled path components)
 * - Controller validation sets the per-use file size limit
 */
trait HandlesFileUploads
{
    protected function storeUpload(UploadedFile $file, string $folder): string
    {
        return $this->storeTypedUpload($file, $folder, [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
        ], 'image', 'File must be a valid JPEG, PNG, or WebP image.');
    }

    protected function storeTypedUpload(
        UploadedFile $file,
        string $folder,
        array $allowed,
        string $field,
        string $message
    ): string {
        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file->getRealPath());

        if (! array_key_exists($mimeType, $allowed)) {
            throw ValidationException::withMessages([
                $field => [$message],
            ]);
        }

        $filename = Str::uuid()->toString() . '.' . $allowed[$mimeType];
        Storage::disk('uploads')->put("{$folder}/{$filename}", file_get_contents($file->getRealPath()));

        return "/uploads/{$folder}/{$filename}";
    }

    protected function deleteUpload(?string $url): void
    {
        if (! $url) {
            return;
        }
        $relativePath = ltrim(str_replace('/uploads/', '', $url), '/');
        Storage::disk('uploads')->delete($relativePath);
    }
}
