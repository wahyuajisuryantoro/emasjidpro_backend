<?php

namespace App\Helpers;

class GoogleCloudStorageHelper
{
    public static function getFileUrl(string $filePath): string
    {
        $bucket = config('filesystems.disks.gcs.bucket');
        $pathPrefix = config('filesystems.disks.gcs.path_prefix');
        $fullPath = $pathPrefix ? $pathPrefix . '/' . $filePath : $filePath;
        return "https://storage.googleapis.com/{$bucket}/{$fullPath}";
    }
    
    public static function getPublicUrl(string $filePath): string
    {
        $bucket = config('filesystems.disks.gcs.bucket');
        $pathPrefix = config('filesystems.disks.gcs.path_prefix');
        $fullPath = $pathPrefix ? $pathPrefix . '/' . $filePath : $filePath;
        return "https://storage.googleapis.com/{$bucket}/{$fullPath}";
    }
    
    public static function getSignedUrl(string $filePath, int $expirationMinutes = 60): string
    {
        try {
            $keyFilePath = base_path(config('filesystems.disks.gcs.key_file'));
            if (!file_exists($keyFilePath)) {
                \Log::warning("Google Cloud key file not found at: {$keyFilePath}");
                return self::getFileUrl($filePath);
            }

            $storageClient = new \Google\Cloud\Storage\StorageClient([
                'projectId' => config('filesystems.disks.gcs.project_id'),
                'keyFilePath' => $keyFilePath,
            ]);
            
            $bucket = $storageClient->bucket(config('filesystems.disks.gcs.bucket'));
            $object = $bucket->object($filePath);
            
            $signedUrl = $object->signedUrl(
                new \DateTime('+' . $expirationMinutes . ' minutes')
            );
            
            return $signedUrl;
        } catch (\Exception $e) {
            \Log::error('Failed to generate signed URL: ' . $e->getMessage());
            return self::getFileUrl($filePath);
        }
    }
}