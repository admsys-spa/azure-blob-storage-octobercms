<?php

namespace Admsys\AzureBlobStorage;

use Illuminate\Filesystem\FilesystemAdapter as IlluminateFilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;

class AzureBlobStorageServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        // Intentar cargar config desde October CMS si está instalado
        if (class_exists('\System\Classes\PluginManager')) {
            // Estamos en October CMS
            $this->app->singleton('azure-blob-storage', function ($app) {
                // Asegúrate de que el namespace es correcto y que la clase tiene el trait Singleton
                $settings = \Admsys\AzureBlobStorageOctobercms\Models\Settings::instance();

                // Verificación adicional para depuración
                if (!$settings) {
                    \Log::error('No se pudo obtener la instancia de Settings para Azure Blob Storage');
                    // Usar configuración por defecto como fallback
                    return new AzureBlobStorage([
                        'account_name' => env('AZURE_STORAGE_ACCOUNT_NAME', ''),
                        'account_key' => env('AZURE_STORAGE_ACCOUNT_KEY', ''),
                        'sas_token' => env('AZURE_STORAGE_SAS_TOKEN', ''),
                        'container_name' => env('AZURE_STORAGE_CONTAINER', ''),
                        'endpoint' => env('AZURE_STORAGE_ENDPOINT', ''),
                        'url' => env('AZURE_STORAGE_URL', ''),
                        'default_visibility' => env('AZURE_STORAGE_DEFAULT_VISIBILITY', 'private'),
                        'auth_mode' => env('AZURE_STORAGE_AUTH_MODE', ''),
                    ]);
                }

                $config = [
                    'account_name' => $settings->account_name,
                    'account_key' => $settings->account_key,
                    'sas_token' => $settings->sas_token,
                    'container_name' => $settings->container_name,
                    'endpoint' => $settings->endpoint,
                    'url' => $settings->url,
                    'default_visibility' => $settings->default_visibility,
                    'auth_mode' => $settings->auth_mode,
                ];

                return new AzureBlobStorage($config);
            });
        } else {
            // Estamos en Laravel estándar
            $this->mergeConfigFrom(
                __DIR__ . '/../config/azure-blob-storage-config.php',
                'azure-blob-storage-config'
            );

            $this->app->singleton('azure-blob-storage', function ($app) {
                return new AzureBlobStorage(
                    $app['config']['azure-blob-storage-config']
                );
            });
        }
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        // Comprobar si estamos en Laravel estándar (no October CMS)
        if (!class_exists('\System\Classes\PluginManager')) {
            // Publicar configuración
            if ($this->app->runningInConsole()) {
                $this->publishes([
                    __DIR__ . '/../config/azure-blob-storage-config.php' => config_path('azure-blob-storage-config.php'),
                ], 'azure-blob-storage-config');
            }

            // Registrar el driver de storage personalizado para Laravel
            Storage::extend('azure', function ($app, $config) {
                $connectionString = $this->buildConnectionString($config);
                $client = BlobRestProxy::createBlobService($connectionString);

                $adapter = new FilesystemAdapter($client, $config['container_name'], $config);
                $filesystem = new Filesystem($adapter, $config);

                return new IlluminateFilesystemAdapter($filesystem, $adapter, $config);
            });
        }
    }

    /**
     * Construye la cadena de conexión para Azure Blob Storage.
     *
     * @param array $config
     * @return string
     */
    protected function buildConnectionString(array $config)
    {
        $connectionString = "DefaultEndpointsProtocol=https;";
        $connectionString .= "AccountName=" . $config['account_name'] . ";";

        // Verificar si estamos usando SAS Token o clave de cuenta
        if (isset($config['sas_token']) && !empty($config['sas_token'])) {
            // Eliminar el signo de interrogación inicial si existe
            $sasToken = $config['sas_token'];
            if (substr($sasToken, 0, 1) === '?') {
                $sasToken = substr($sasToken, 1);
            }

            $connectionString .= "SharedAccessSignature=" . $sasToken . ";";
        } else if (isset($config['account_key']) && !empty($config['account_key'])) {
            $connectionString .= "AccountKey=" . $config['account_key'] . ";";
        }

        if (!empty($config['endpoint'])) {
            $connectionString .= "BlobEndpoint=" . $config['endpoint'] . ";";
        }

        return $connectionString;
    }
}
