<?php namespace Admsys\AzureBlobStorageOctobercms;

use System\Classes\PluginBase;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\Facades\Storage;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use Illuminate\Support\Facades\Log;

/**
 * Azure Blob Storage Plugin para October CMS
 */
class Plugin extends PluginBase
{
    /**
     * Configuración para cargar el plugin con alta prioridad
     */
    public $elevated = true;

    /**
     * Returns information about this plugin.
     *
     * @return array
     */
    public function pluginDetails()
    {
        return [
            'name'        => 'Azure Blob Storage for October CMS',
            'description' => 'Proporciona integración con Azure Blob Storage para October CMS',
            'author'      => 'Admsys',
            'icon'        => 'icon-cloud-upload'
        ];
    }

    /**
     * Register method, called when the plugin is first registered.
     *
     * @return void
     */
    public function register()
    {
        // Registrar modelos
        $this->registerModels();

        // Registrar inmediatamente el servicio principal
        $this->app->singleton('azure-blob-storage', function ($app) {
            $settings = \Admsys\AzureBlobStorageOctobercms\Models\Settings::instance();
            return new \Admsys\AzureBlobStorage\AzureBlobStorage([
                'account_name' => $settings->account_name,
                'account_key' => $settings->account_key,
                'container_name' => $settings->container_name,
                'endpoint' => $settings->endpoint,
                'url' => $settings->url,
                'default_visibility' => $settings->default_visibility,
            ]);
        });

        // Registrar la facade
        $alias = AliasLoader::getInstance();
        $alias->alias('AzureBlobStorage', 'Admsys\AzureBlobStorage\Facades\AzureBlobStorage');

        // Registrar el driver inmediatamente
        $this->registerAzureDriver();
    }

    /**
     * Registrar modelos para OctoberCMS
     */
    protected function registerModels()
    {
        // Registrar el modelo Settings para que OctoberCMS lo reconozca
        if (class_exists('\System\Classes\PluginManager')) {
            // En lugar de usar ClassLoader, asegúrate de que el archivo está incluido
            $settingsPath = __DIR__ . '/models/Settings.php';

            if (file_exists($settingsPath) && !class_exists('\Admsys\AzureBlobStorageOctobercms\Models\Settings')) {
                require_once $settingsPath;
            }

            // Log para depuración
            \Log::info('Azure Blob Storage: Modelo Settings cargado manualmente');
        }
    }

    /**
     * Boot method, called right before the request route.
     *
     * @return void
     */
    public function boot()
    {
        // Asegurarse de que el driver esté registrado
        $this->registerAzureDriver();
    }

    /**
     * Registrar el driver de Azure Blob Storage con estrategia de fallback
     */
    protected function registerAzureDriver()
    {
        try {
            Log::info('Azure Blob Storage: Intentando registrar el driver');

            // Determinar la versión de Flysystem disponible
            $flysystemVersion = $this->detectFlysystemVersion();
            Log::info('Azure Blob Storage: Versión de Flysystem detectada: ' . $flysystemVersion);

            // Registrar el driver adecuado según la versión
            switch ($flysystemVersion) {
                case 1:
                    $this->registerFlysystemV1Driver();
                    break;
                case 2:
                    $this->registerFlysystemV2Driver();
                    break;
                case 3:
                    $this->registerFlysystemV3Driver();
                    break;
                default:
                    Log::error('Azure Blob Storage: No se pudo detectar una versión compatible de Flysystem');
                    throw new \Exception('No se pudo detectar una versión compatible de Flysystem');
            }

            Log::info('Azure Blob Storage: Driver registrado correctamente para Flysystem v' . $flysystemVersion);
        } catch (\Exception $e) {
            Log::error('Azure Blob Storage: Error al registrar el driver: ' . $e->getMessage());
        }
    }
    /**
     * Detecta la versión de Flysystem disponible
     *
     * @return int Versión de Flysystem (1, 2, o 3)
     */
    protected function detectFlysystemVersion()
    {
        // Flysystem v3
        if (class_exists('\League\Flysystem\FilesystemOperator')) {
            return 3;
        }

        // Flysystem v2
        if (class_exists('\League\Flysystem\FilesystemAdapter')) {
            return 2;
        }

        // Flysystem v1
        if (class_exists('\League\Flysystem\AdapterInterface')) {
            return 1;
        }

        // No se pudo detectar
        return 0;
    }

    /**
     * Registra el driver compatible con Flysystem v1 (Laravel 6-7, October CMS v2)
     */
    protected function registerFlysystemV1Driver()
    {
        Storage::extend('azure', function ($app, $config) {
            $connectionString = $this->buildConnectionString($config);
            $client = BlobRestProxy::createBlobService($connectionString);

            // Usar el adaptador para Flysystem v1
            if (!class_exists('\Admsys\AzureBlobStorage\Adapters\AzureBlobStorageAdapterV1')) {
                require_once __DIR__ . '/adapters/AzureBlobStorageAdapterV1.php';
            }

            $adapter = new \Admsys\AzureBlobStorage\Adapters\AzureBlobStorageAdapterV1(
                $client,
                $config['container_name'],
                $config
            );

            return new \League\Flysystem\Filesystem($adapter, [
                'visibility' => $config['default_visibility'] ?? 'private',
            ]);
        });
    }

    /**
     * Registra el driver compatible con Flysystem v2 (Laravel 8-9, October CMS v3 temprano)
     */
    protected function registerFlysystemV2Driver()
    {
        Storage::extend('azure', function ($app, $config) {
            $connectionString = $this->buildConnectionString($config);
            $client = BlobRestProxy::createBlobService($connectionString);

            // Usar el adaptador para Flysystem v2
            if (!class_exists('\Admsys\AzureBlobStorage\Adapters\AzureBlobStorageAdapterV2')) {
                require_once __DIR__ . '/adapters/AzureBlobStorageAdapterV2.php';
            }

            $adapter = new \Admsys\AzureBlobStorage\Adapters\AzureBlobStorageAdapterV2(
                $client,
                $config['container_name'],
                $config
            );

            return new \Illuminate\Filesystem\FilesystemAdapter(
                new \League\Flysystem\Filesystem($adapter),
                $adapter,
                $config
            );
        });
    }

    /**
     * Registra el driver compatible con Flysystem v3 (Laravel 10+, October CMS v3 reciente)
     */
    protected function registerFlysystemV3Driver()
    {
        Storage::extend('azure', function ($app, $config) {
            $connectionString = $this->buildConnectionString($config);
            $client = BlobRestProxy::createBlobService($connectionString);

            // Usar el adaptador para Flysystem v3
            if (!class_exists('\Admsys\AzureBlobStorage\Adapters\AzureBlobStorageAdapterV3')) {
                require_once __DIR__ . '/adapters/AzureBlobStorageAdapterV3.php';
            }

            $adapter = new \Admsys\AzureBlobStorage\Adapters\AzureBlobStorageAdapterV3(
                $client,
                $config['container_name'],
                $config
            );

            return new \Illuminate\Filesystem\FilesystemAdapter(
                new \League\Flysystem\Filesystem($adapter),
                $adapter,
                $config
            );
        });
    }

    /**
     * Construye la cadena de conexión para Azure Blob Storage
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

    /**
     * Registers any back-end settings.
     *
     * @return array
     */
    public function registerSettings()
    {
        return [
            'settings' => [
                'label'       => 'Azure Blob Storage',
                'description' => 'Administra la configuración de Azure Blob Storage con OctoberCMS.',
                'category'    => 'Integraciones Personalizadas',
                'icon'        => 'icon-cloud',
                'class'       => 'Admsys\AzureBlobStorageOctobercms\Models\Settings',
                'order'       => 100,
                'keywords'    => 'azure blob storage cloud',
                'permissions' => ['admsys.azureblobstorageoctobercms.access_settings']
            ]
        ];
    }

    /**
     * Registers any back-end permissions.
     *
     * @return array
     */
    public function registerPermissions()
    {
        return [
            'admsys.azureblobstorageoctobercms.access_settings' => [
                'tab' => 'Azure Blob Storage',
                'label' => 'Administrar configuración de Azure Blob Storage'
            ],
        ];
    }
}