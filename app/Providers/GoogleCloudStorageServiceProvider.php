<?php

namespace App\Providers;

use Exception;
use League\Flysystem\Filesystem;
use Google\Cloud\Storage\StorageClient;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter;

class GoogleCloudStorageServiceProvider extends ServiceProvider
{

    public function register(): void
    {
        //
    }


    public function boot(): void
    {
        Storage::extend('gcs', function ($app, $config) {
            $keyFilePath = base_path($config['key_file']);
            if (!file_exists($keyFilePath)) {
                throw new Exception("Google Cloud key file not found at: {$keyFilePath}");
            }

            $storageClient = new StorageClient([
                'projectId' => $config['project_id'],
                'keyFilePath' => $keyFilePath,
            ]);

            $bucket = $storageClient->bucket($config['bucket']);
            $pathPrefix = $config['path_prefix'] ?? '';

            $adapter = new GoogleCloudStorageAdapter($bucket, $pathPrefix);
            $filesystem = new Filesystem($adapter);

            return new FilesystemAdapter($filesystem, $adapter, $config);
        });
    }
}
