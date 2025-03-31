<?php namespace Admsys\AzureBlobStorage\Adapters;

use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;
use League\Flysystem\Util;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use MicrosoftAzure\Storage\Blob\Models\CreateBlockBlobOptions;
use MicrosoftAzure\Storage\Blob\Models\ListBlobsOptions;
use MicrosoftAzure\Storage\Common\Exceptions\ServiceException;
use Illuminate\Support\Facades\Log;

class AzureBlobStorageAdapterV1 implements AdapterInterface
{
    /**
     * Cliente Azure Blob Storage.
     *
     * @var \MicrosoftAzure\Storage\Blob\BlobRestProxy
     */
    protected $client;

    /**
     * Nombre del contenedor.
     *
     * @var string
     */
    protected $container;

    /**
     * Configuración adicional.
     *
     * @var array
     */
    protected $config;

    /**
     * Constructor.
     *
     * @param BlobRestProxy $client
     * @param string $container
     * @param array $config
     */
    public function __construct(BlobRestProxy $client, string $container, array $config = [])
    {
        $this->client = $client;
        $this->container = $container;
        $this->config = $config;
    }

    /**
     * {@inheritdoc}
     */
    public function write($path, $contents, Config $config)
    {
        return $this->upload($path, $contents, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function writeStream($path, $resource, Config $config)
    {
        return $this->upload($path, stream_get_contents($resource), $config);
    }

    /**
     * {@inheritdoc}
     */
    public function update($path, $contents, Config $config)
    {
        return $this->upload($path, $contents, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function updateStream($path, $resource, Config $config)
    {
        return $this->upload($path, stream_get_contents($resource), $config);
    }

    /**
     * {@inheritdoc}
     */
    public function rename($path, $newpath)
    {
        if (!$this->copy($path, $newpath)) {
            return false;
        }

        return $this->delete($path);
    }

    /**
     * {@inheritdoc}
     */
    public function copy($path, $newpath)
    {
        try {
            $this->client->copyBlob(
                $this->container,
                $newpath,
                $this->container,
                $path
            );

            return true;
        } catch (ServiceException $e) {
            Log::error('Azure Blob Storage error al copiar: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function delete($path)
    {
        try {
            $this->client->deleteBlob($this->container, $path);
            return true;
        } catch (ServiceException $e) {
            if ($e->getCode() !== 404) {
                Log::error('Azure Blob Storage error al eliminar: ' . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function deleteDir($dirname)
    {
        $dirname = rtrim($dirname, '/') . '/';

        try {
            $blobs = $this->listContents($dirname, true);

            foreach ($blobs as $blob) {
                $this->delete($blob['path']);
            }

            // Eliminar el "directorio" virtual
            try {
                $this->client->deleteBlob($this->container, $dirname);
            } catch (ServiceException $e) {
                if ($e->getCode() !== 404) {
                    Log::error('Azure Blob Storage error al eliminar directorio: ' . $e->getMessage());
                }
            }

            return true;
        } catch (ServiceException $e) {
            Log::error('Azure Blob Storage error al eliminar directorio: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function createDir($dirname, Config $config)
    {
        $dirname = rtrim($dirname, '/') . '/';

        try {
            $options = new CreateBlockBlobOptions();

            if ($config->has('ContentType')) {
                $options->setContentType($config->get('ContentType'));
            } else {
                $options->setContentType('application/directory');
            }

            if ($config->has('metadata')) {
                $options->setMetadata($config->get('metadata'));
            }

            $this->client->createBlockBlob(
                $this->container,
                $dirname,
                '',
                $options
            );

            return ['path' => $dirname, 'type' => 'dir'];
        } catch (ServiceException $e) {
            Log::error('Azure Blob Storage error al crear directorio: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function has($path)
    {
        try {
            $this->client->getBlobProperties($this->container, $path);
            return true;
        } catch (ServiceException $e) {
            if ($e->getCode() === 404) {
                return false;
            }

            Log::error('Azure Blob Storage error al verificar existencia: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function read($path)
    {
        try {
            $blob = $this->client->getBlob($this->container, $path);
            $content = stream_get_contents($blob->getContentStream());

            return ['contents' => $content];
        } catch (ServiceException $e) {
            Log::error('Azure Blob Storage error al leer: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function readStream($path)
    {
        try {
            $blob = $this->client->getBlob($this->container, $path);
            $stream = fopen('php://temp', 'w+');

            if ($stream === false) {
                return false;
            }

            fwrite($stream, stream_get_contents($blob->getContentStream()));
            rewind($stream);

            return ['stream' => $stream];
        } catch (ServiceException $e) {
            Log::error('Azure Blob Storage error al leer stream: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function listContents($directory = '', $recursive = false)
    {
        $directory = rtrim($directory, '/');
        if ($directory !== '') {
            $directory .= '/';
        }

        $options = new ListBlobsOptions();
        $options->setPrefix($directory);

        if (!$recursive) {
            $options->setDelimiter('/');
        }

        try {
            $listResults = $this->client->listBlobs($this->container, $options);
            $blobs = $listResults->getBlobs();

            $contents = [];
            foreach ($blobs as $blob) {
                $name = $blob->getName();

                // Saltar el directorio actual
                if ($name === $directory) {
                    continue;
                }

                $contents[] = $this->normalizeBlobProperties($blob);
            }

            return $contents;
        } catch (ServiceException $e) {
            Log::error('Azure Blob Storage error al listar contenido: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getMetadata($path)
    {
        try {
            $properties = $this->client->getBlobProperties($this->container, $path);

            return $this->normalizeProperties($path, $properties->getProperties(), $properties->getMetadata());
        } catch (ServiceException $e) {
            Log::error('Azure Blob Storage error al obtener metadata: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getSize($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * {@inheritdoc}
     */
    public function getMimetype($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * {@inheritdoc}
     */
    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * {@inheritdoc}
     */
    public function getVisibility($path)
    {
        // Azure Blob Storage no tiene un concepto directo de visibilidad como S3
        // Podemos usar metadatos para simular esto
        $metadata = $this->getMetadata($path);

        if (isset($metadata['visibility'])) {
            return ['visibility' => $metadata['visibility']];
        }

        // Por defecto, asumimos que es privado
        return ['visibility' => 'private'];
    }

    /**
     * {@inheritdoc}
     */
    public function setVisibility($path, $visibility)
    {
        // Azure no admite cambiar la visibilidad directamente
        // Podríamos simular esto con metadatos, políticas de acceso o SAS tokens
        // Por ahora, simplemente devolvemos true
        return ['visibility' => $visibility];
    }

    /**
     * Sube un archivo a Azure Blob Storage.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config
     * @return array|false
     */
    protected function upload($path, $contents, Config $config)
    {
        try {
            $options = new CreateBlockBlobOptions();

            if ($config->has('ContentType')) {
                $options->setContentType($config->get('ContentType'));
            } else {
                $options->setContentType($this->guessMimeType($path, $contents));
            }

            if ($config->has('CacheControl')) {
                $options->setCacheControl($config->get('CacheControl'));
            }

            if ($config->has('metadata')) {
                $options->setMetadata($config->get('metadata'));
            }

            if ($config->has('visibility')) {
                $options->setMetadata(['visibility' => $config->get('visibility')]);
            }

            $this->client->createBlockBlob(
                $this->container,
                $path,
                $contents,
                $options
            );

            return $this->normalizeResponse($path, $contents);
        } catch (ServiceException $e) {
            Log::error('Azure Blob Storage error al subir: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Normaliza la respuesta de las operaciones.
     *
     * @param string $path
     * @param string $contents
     * @return array
     */
    protected function normalizeResponse($path, $contents = null)
    {
        $result = ['path' => $path];

        if (is_string($contents)) {
            $result['contents'] = $contents;
            $result['size'] = Util::contentSize($contents);
            $result['type'] = 'file';
            $result['mimetype'] = Util::guessMimeType($path, $contents);
        }

        return $result;
    }

    /**
     * Normaliza las propiedades de un blob.
     *
     * @param \MicrosoftAzure\Storage\Blob\Models\Blob $blob
     * @return array
     */
    protected function normalizeBlobProperties($blob)
    {
        $path = $blob->getName();
        $timestamp = $blob->getProperties()->getLastModified()->getTimestamp();
        $mimetype = $blob->getProperties()->getContentType();
        $size = $blob->getProperties()->getContentLength();

        $type = 'file';
        if (substr($path, -1) === '/') {
            $type = 'dir';
        }

        return [
            'path' => $path,
            'timestamp' => $timestamp,
            'size' => $size,
            'type' => $type,
            'mimetype' => $mimetype,
        ];
    }

    /**
     * Normaliza las propiedades y metadatos.
     *
     * @param string $path
     * @param \MicrosoftAzure\Storage\Blob\Models\BlobProperties $properties
     * @param array $metadata
     * @return array
     */
    protected function normalizeProperties($path, $properties, $metadata = [])
    {
        $result = [
            'path' => $path,
            'timestamp' => $properties->getLastModified()->getTimestamp(),
            'size' => $properties->getContentLength(),
            'mimetype' => $properties->getContentType(),
        ];

        if (substr($path, -1) === '/') {
            $result['type'] = 'dir';
        } else {
            $result['type'] = 'file';
        }

        if (!empty($metadata)) {
            $result['metadata'] = $metadata;

            if (isset($metadata['visibility'])) {
                $result['visibility'] = $metadata['visibility'];
            }
        }

        return $result;
    }

    /**
     * Intenta adivinar el tipo MIME del archivo.
     *
     * @param string $path
     * @param string $content
     * @return string
     */
    protected function guessMimeType($path, $content)
    {
        return Util::guessMimeType($path, $content);
    }
}