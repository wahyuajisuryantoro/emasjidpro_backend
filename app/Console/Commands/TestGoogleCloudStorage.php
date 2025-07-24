<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TestGoogleCloudStorage extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:gcs';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test Google Cloud Storage connection';

    /**
     * Execute the console command.
     */
   public function handle()
    {
        try {
            $this->info('Testing Google Cloud Storage connection...');
            $disk = Storage::disk('gcs');
            $this->info('✓ GCS disk configured');
            $testContent = 'Test file created at ' . now();
            $testPath = 'test/' . Str::uuid() . '.txt';
            
            $uploaded = $disk->put($testPath, $testContent);
            
            if ($uploaded) {
                $this->info('✓ File uploaded successfully');
                if ($disk->exists($testPath)) {
                    $this->info('✓ File exists in bucket');
                    $bucket = config('filesystems.disks.gcs.bucket');
                    $pathPrefix = config('filesystems.disks.gcs.path_prefix');
                    $fullPath = $pathPrefix ? $pathPrefix . '/' . $testPath : $testPath;
                    $url = "https://storage.googleapis.com/{$bucket}/{$fullPath}";      
                    $this->info("✓ File URL: $url");
                    $disk->delete($testPath);
                    $this->info('✓ Test file deleted');
                    
                    $this->info('🎉 Google Cloud Storage is working perfectly!');
                } else {
                    $this->error('✗ File not found in bucket');
                }
            } else {
                $this->error('✗ Failed to upload file');
            }
            
        } catch (\Exception $e) {
            $this->error('✗ Error: ' . $e->getMessage());
            $this->info('Please check your configuration:');
            $this->info('- GOOGLE_CLOUD_PROJECT_ID');
            $this->info('- GOOGLE_CLOUD_KEY_FILE path');
            $this->info('- GOOGLE_CLOUD_STORAGE_BUCKET');
            $this->info('- Service Account permissions');
        }
    }
}