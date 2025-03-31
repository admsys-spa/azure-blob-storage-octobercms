<?php

namespace Admsys\AzureBlobStorage;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use MicrosoftAzure\Storage\Blob\Models\CreateBlockBlobOptions;
use MicrosoftAzure\Storage\Blob\Models\ListBlobsOptions;
use MicrosoftAzure\Storage\Common\Exceptions\ServiceException;

class AzureBlobStorage
{
    /**
     * Cliente Azure Blob Storage.
     *
     * @var \MicrosoftAzure\Storage\Blob\BlobRestProxy
     */
    protected $blobClient;

    /**
     * Configuración del servicio.
     *
     * @var array
     */
    protected $config;

    /**
     * Constructor.
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->initClient();
    }

    /**
     * Inicializar el cliente de Azure Blob Storage.
     *
     * @return void
     */
    protected function initClient()
    {
        $connectionString = $this->getConnectionString();
        $this->blobClient = BlobRestProxy::createBlobService($connectionString);
    }

    /**
     * Obtener la cadena de conexión para Azure.
     *
     * @return string
     */
    protected function getConnectionString()
    {
        $connectionString = 'DefaultEndpointsProtocol=https;';
        $connectionString .= 'AccountName='.$this->config['account_name'].';';

        // Verificar si estamos usando SAS Token o clave de cuenta
        if (isset($this->config['sas_token']) && !empty($this->config['sas_token'])) {
            // Eliminar el signo de interrogación inicial si existe en el token
            $sasToken = $this->config['sas_token'];
            if (substr($sasToken, 0, 1) === '?') {
                $sasToken = substr($sasToken, 1);
            }

            $connectionString .= 'SharedAccessSignature='.$sasToken.';';
        } else if (isset($this->config['account_key']) && !empty($this->config['account_key'])) {
            // Usar la clave de cuenta tradicional
            $connectionString .= 'AccountKey='.$this->config['account_key'].';';
        } else {
            Log::warning('Azure Blob Storage: No se ha proporcionado ni clave de cuenta ni token SAS');
        }

        if (! empty($this->config['endpoint'])) {
            $connectionString .= 'BlobEndpoint='.$this->config['endpoint'].';';
        }

        return $connectionString;
    }

    /**
     * Subir un archivo a Azure Blob Storage.
     *
     * @param  \Illuminate\Http\UploadedFile|string  $file
     * @return string|bool
     */
    public function upload($file, string $blobName = null, array $options = [])
    {
        try {
            // Configurar timeout
            $maxUploadTime = $this->config['max_upload_time'] ?? 600; // Valor por defecto: 10 minutos
            $originalTimeout = ini_get('max_execution_time');

            // Establecer nuevo timeout si es posible
            if (ini_get('max_execution_time') != 0) { // No modificar si es ilimitado (0)
                @set_time_limit($maxUploadTime);
            }

            $content = $file instanceof UploadedFile ? file_get_contents($file->getRealPath()) : file_get_contents($file);
            $contentType = $file instanceof UploadedFile ? $file->getMimeType() : mime_content_type($file);

            if (empty($blobName)) {
                $blobName = $file instanceof UploadedFile
                    ? $file->getClientOriginalName()
                    : basename($file);
            }

            // Asegurar que el nombre del blob sea único
            if (isset($options['unique']) && $options['unique']) {
                $blobName = $this->getUniqueBlobName($blobName);
            }

            $createBlobOptions = new CreateBlockBlobOptions();
            $createBlobOptions->setContentType($contentType);

            // Configurar cachés
            if (isset($options['cacheControl'])) {
                $createBlobOptions->setCacheControl($options['cacheControl']);
            }

            // Configurar metadatos
            if (isset($options['metadata']) && is_array($options['metadata'])) {
                $createBlobOptions->setMetadata($options['metadata']);
            }

            // Configurar visibilidad
            $visibility = $options['visibility'] ?? $this->config['default_visibility'];
            if ($visibility === 'public') {
                $createBlobOptions->setBlobPublicAccess('container');
            }

            // Subir a Azure
            $this->blobClient->createBlockBlob(
                $this->config['container_name'],
                $blobName,
                $content,
                $createBlobOptions
            );

            // Restaurar el timeout original
            if ($originalTimeout != 0 && ini_get('max_execution_time') != 0) {
                @set_time_limit($originalTimeout);
            }

            return $this->getUrl($blobName);
        } catch (ServiceException $e) {
            Log::error('Azure Blob Storage error: ' . $e->getMessage());

            // Restaurar el timeout original en caso de error
            if (isset($originalTimeout) && $originalTimeout != 0 && ini_get('max_execution_time') != 0) {
                @set_time_limit($originalTimeout);
            }

            return false;
        } catch (\Exception $e) {
            Log::error('Error al subir archivo a Azure: ' . $e->getMessage());

            // Restaurar el timeout original en caso de error
            if (isset($originalTimeout) && $originalTimeout != 0 && ini_get('max_execution_time') != 0) {
                @set_time_limit($originalTimeout);
            }

            return false;
        }
    }

    /**
     * Generar un nombre único para el blob.
     *
     * @return string
     */
    protected function getUniqueBlobName(string $originalName)
    {
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $baseName = pathinfo($originalName, PATHINFO_FILENAME);

        return $baseName . '_' . Str::random(8) . '.' . $extension;
    }

    /**
     * Obtener la URL del blob.
     *
     * @return string
     */
    public function getUrl(string $blobName)
    {
        // Si hay una URL base configurada, usarla
        if (! empty($this->config['url'])) {
            return rtrim($this->config['url'], '/') . '/' . $blobName;
        }

        // De lo contrario, construir la URL de Azure
        return sprintf(
            'https://%s.blob.core.windows.net/%s/%s',
            $this->config['account_name'],
            $this->config['container_name'],
            $blobName
        );
    }

    /**
     * Eliminar un blob.
     *
     * @return bool
     */
    public function delete(string $blobName)
    {
        try {
            $this->blobClient->deleteBlob(
                $this->config['container_name'],
                $blobName
            );

            return true;
        } catch (ServiceException $e) {
            Log::error('Azure Blob Storage error al eliminar: ' . $e->getMessage());

            return false;
        } catch (\Exception $e) {
            Log::error('Error general al eliminar blob: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Verificar si un blob existe.
     *
     * @return bool
     */
    public function exists(string $blobName)
    {
        try {
            $this->blobClient->getBlobProperties(
                $this->config['container_name'],
                $blobName
            );

            return true;
        } catch (ServiceException $e) {
            if ($e->getCode() === 404) {
                return false;
            }

            Log::error('Azure Blob Storage error al verificar blob: ' . $e->getMessage());

            return false;
        } catch (\Exception $e) {
            Log::error('Error general al verificar blob: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Listar blobs en el contenedor.
     *
     * @return array|bool
     */
    public function listBlobs(string $prefix = null, int $maxResults = 1000)
    {
        try {
            // Configurar timeout solo si se van a listar muchos blobs
            if ($maxResults > 1000) {
                $maxUploadTime = $this->config['max_upload_time'] ?? 600;
                $originalTimeout = ini_get('max_execution_time');

                if (ini_get('max_execution_time') != 0) {
                    @set_time_limit($maxUploadTime);
                }
            }

            $options = new ListBlobsOptions();

            if ($prefix) {
                $options->setPrefix($prefix);
            }

            $options->setMaxResults($maxResults);

            $result = $this->blobClient->listBlobs($this->config['container_name'], $options);
            $blobs = $result->getBlobs();

            $files = [];
            foreach ($blobs as $blob) {
                $files[] = [
                    'name' => $blob->getName(),
                    'url' => $this->getUrl($blob->getName()),
                    'size' => $blob->getProperties()->getContentLength(),
                    'lastModified' => $blob->getProperties()->getLastModified()->format('Y-m-d H:i:s'),
                    'contentType' => $blob->getProperties()->getContentType(),
                ];
            }

            // Restaurar el timeout original si fue modificado
            if (isset($originalTimeout) && $originalTimeout != 0 && ini_get('max_execution_time') != 0) {
                @set_time_limit($originalTimeout);
            }

            return $files;
        } catch (ServiceException $e) {
            Log::error('Azure Blob Storage error al listar blobs: ' . $e->getMessage());

            // Restaurar el timeout original en caso de error
            if (isset($originalTimeout) && $originalTimeout != 0 && ini_get('max_execution_time') != 0) {
                @set_time_limit($originalTimeout);
            }

            return false;
        } catch (\Exception $e) {
            Log::error('Error general al listar blobs: ' . $e->getMessage());

            // Restaurar el timeout original en caso de error
            if (isset($originalTimeout) && $originalTimeout != 0 && ini_get('max_execution_time') != 0) {
                @set_time_limit($originalTimeout);
            }

            return false;
        }
    }
    /**
     * Descargar un blob.
     *
     * @return string|bool Contenido del blob o false si falla
     */
    public function download(string $blobName)
    {
        try {
            // Configurar timeout
            $maxUploadTime = $this->config['max_upload_time'] ?? 600; // Usar la misma config
            $originalTimeout = ini_get('max_execution_time');

            // Establecer nuevo timeout si es posible
            if (ini_get('max_execution_time') != 0) {
                @set_time_limit($maxUploadTime);
            }

            $result = $this->blobClient->getBlob(
                $this->config['container_name'],
                $blobName
            );

            $content = stream_get_contents($result->getContentStream());

            // Restaurar el timeout original
            if ($originalTimeout != 0 && ini_get('max_execution_time') != 0) {
                @set_time_limit($originalTimeout);
            }

            return $content;
        } catch (ServiceException $e) {
            Log::error('Azure Blob Storage error al descargar blob: ' . $e->getMessage());

            // Restaurar el timeout original en caso de error
            if (isset($originalTimeout) && $originalTimeout != 0 && ini_get('max_execution_time') != 0) {
                @set_time_limit($originalTimeout);
            }

            return false;
        } catch (\Exception $e) {
            Log::error('Error general al descargar blob: ' . $e->getMessage());

            // Restaurar el timeout original en caso de error
            if (isset($originalTimeout) && $originalTimeout != 0 && ini_get('max_execution_time') != 0) {
                @set_time_limit($originalTimeout);
            }

            return false;
        }
    }

    /**
     * Obtener el cliente blob directamente para operaciones avanzadas.
     *
     * @return \MicrosoftAzure\Storage\Blob\BlobRestProxy
     */
    public function getClient()
    {
        return $this->blobClient;
    }

    /**
     * Establece el tiempo máximo para operaciones en Azure Blob Storage.
     *
     * @param int $seconds Tiempo máximo en segundos (0 para ilimitado)
     * @return $this
     */
    public function setMaxOperationTime(int $seconds)
    {
        $this->config['max_upload_time'] = $seconds;
        return $this;
    }

    /**
     * Obtiene el tiempo máximo configurado para operaciones.
     *
     * @return int
     */
    public function getMaxOperationTime()
    {
        return $this->config['max_upload_time'] ?? 600;
    }
}
