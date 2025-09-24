<?php

namespace WaterlooBae\UwAdfs\Console\Commands;

use Illuminate\Console\Command;
use WaterlooBae\UwAdfs\Services\AdfsService;

class RefreshMetadataCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'uw-adfs:refresh-metadata 
                            {--environment= : Specify environment (production or development)}
                            {--clear-cache : Clear cached metadata}';

    /**
     * The console command description.
     */
    protected $description = 'Refresh SAML metadata from UW ADFS servers';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $environment = $this->option('environment') ?: config('uw-adfs.environment');
        $clearCache = $this->option('clear-cache');

        if ($clearCache) {
            $this->info('Clearing metadata cache...');
            
            $productionUrl = config('uw-adfs.idp.production.metadata_url');
            $developmentUrl = config('uw-adfs.idp.development.metadata_url');
            
            if ($productionUrl) {
                cache()->forget('uw_adfs_metadata_' . md5($productionUrl));
            }
            if ($developmentUrl) {
                cache()->forget('uw_adfs_metadata_' . md5($developmentUrl));
            }
            
            $this->info('Metadata cache cleared.');
        }

        $this->info("Refreshing metadata for environment: {$environment}");

        try {
            // Create a new service instance to test metadata fetching
            $config = config('uw-adfs');
            $adfsService = new AdfsService($config);

            // This will trigger metadata fetching and caching
            $metadata = $adfsService->getMetadata();
            
            $this->info('✅ Metadata refreshed successfully!');
            $this->info('SAML metadata is now cached and ready for use.');
            
            // Show metadata info
            $idpConfig = $config['idp'][$environment];
            if (isset($idpConfig['metadata_url'])) {
                $this->info("Source URL: {$idpConfig['metadata_url']}");
            }
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error('❌ Failed to refresh metadata: ' . $e->getMessage());
            
            // Check if fallback is available
            $idpConfig = config("uw-adfs.idp.{$environment}");
            if (isset($idpConfig['metadata_file']) && file_exists($idpConfig['metadata_file'])) {
                $this->warn("Fallback available: {$idpConfig['metadata_file']}");
            }
            
            return Command::FAILURE;
        }
    }
}