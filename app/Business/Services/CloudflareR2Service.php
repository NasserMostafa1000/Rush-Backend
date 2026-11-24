<?php

namespace App\Business\Services;

use App\Business\Exceptions\ApiException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CloudflareR2Service
{
    /**
     * Upload image to Cloudflare R2 and return the relative object key stored in DB.
     */
    public function upload(UploadedFile $file, string $folder): string
    {
        $folder = trim($folder, '/');
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $relative = $folder.'/'.Str::ulid().'.'.$ext;
        $root = trim((string) config('services.r2.root', ''), '/');
        $objectKey = $root !== '' ? $root.'/'.$relative : $relative;

        if (config('services.r2.fake')) {
            return $objectKey;
        }

        $bucket = config('filesystems.disks.r2.bucket');
        $endpoint = config('filesystems.disks.r2.endpoint');
        $key = config('filesystems.disks.r2.key');
        $secret = config('filesystems.disks.r2.secret');

        if (! $bucket || ! $endpoint || ! $key || ! $secret) {
            throw ApiException::make('r2_not_configured', 'Cloudflare R2 is not configured.', 500);
        }

        $ok = Storage::disk('r2')->put($objectKey, file_get_contents($file->getRealPath()), [
            'visibility' => 'public',
            'ContentType' => $file->getMimeType() ?: 'application/octet-stream',
        ]);

        if (! $ok) {
            throw ApiException::make('upload_failed', 'Failed to upload image to R2.', 502);
        }

        return $objectKey;
    }

    public function url(?string $relativePath): ?string
    {
        if ($relativePath === null || $relativePath === '') {
            return null;
        }

        if (config('services.r2.fake')) {
            $base = rtrim((string) config('services.r2.public_url', 'https://r2.fake.local'), '/');

            return $base.'/'.$relativePath;
        }

        $publicUrl = config('services.r2.public_url') ?: config('filesystems.disks.r2.url');
        if ($publicUrl) {
            return rtrim((string) $publicUrl, '/').'/'.ltrim($relativePath, '/');
        }

        return Storage::disk('r2')->url($relativePath);
    }
}
