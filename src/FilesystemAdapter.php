<?php

namespace Admsys\AzureBlobStorage;

use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter as Adapter;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use MicrosoftAzure\Storage\Blob\Models\CreateBlockBlobOptions;
use MicrosoftAzure\Storage\Blob\Models\ListBlobsOptions;
use MicrosoftAzure\Storage\Common\Exceptions\ServiceException;

class FilesystemAdapter implements Adapter
{
    protected $client;

    protected $container;

    protected $config;

    public function __construct(BlobRestProxy $client, string $container, array $config = [])
    {
        $this->client = $client;
        $this->container = $container;
        $this->config = $config;
    }

    public function fileExists(string $path): bool
    {
        try {
            $this->client->getBlobProperties($this->container, $path);

            return true;
        } catch (ServiceException $exception) {
            if ($exception->getCode() === 404) {
                return false;
            }

            throw UnableToCheckExistence::forLocation($path, $exception);
        }
    }

    public function directoryExists(string $path): bool
    {
        try {
            $options = new ListBlobsOptions();
            $options->setPrefix(rtrim($path, '/').'/');
            $options->setMaxResults(1);

            $result = $this->client->listBlobs($this->container, $options);

            return count($result->getBlobs()) > 0;
        } catch (ServiceException $exception) {
            throw UnableToCheckExistence::forLocation($path, $exception);
        }
    }

    public function write(string $path, string $contents, Config $config): void
    {
        try {
            $options = $this->getOptionsFromConfig($config);

            $this->client->createBlockBlob(
                $this->container,
                $path,
                $contents,
                $options
            );
        } catch (ServiceException $exception) {
            throw UnableToWriteFile::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $contents = stream_get_contents($contents);

        if ($contents === false) {
            throw UnableToWriteFile::atLocation($path, 'Error reading stream');
        }

        $this->write($path, $contents, $config);
    }

    public function read(string $path): string
    {
        try {
            $blob = $this->client->getBlob($this->container, $path);

            return stream_get_contents($blob->getContentStream());
        } catch (ServiceException $exception) {
            throw UnableToReadFile::fromLocation($path, $exception->getMessage(), $exception);
        }
    }

    public function readStream(string $path)
    {
        try {
            $blob = $this->client->getBlob($this->container, $path);

            $stream = fopen('php://temp', 'w+');

            if ($stream === false) {
                throw UnableToReadFile::fromLocation($path, 'Unable to create temporary stream');
            }

            $content = stream_get_contents($blob->getContentStream());
            fwrite($stream, $content);
            rewind($stream);

            return $stream;
        } catch (ServiceException $exception) {
            throw UnableToReadFile::fromLocation($path, $exception->getMessage(), $exception);
        }
    }

    public function delete(string $path): void
    {
        try {
            $this->client->deleteBlob($this->container, $path);
        } catch (ServiceException $exception) {
            if ($exception->getCode() !== 404) {
                throw UnableToDeleteFile::atLocation($path, $exception->getMessage(), $exception);
            }
        }
    }

    public function deleteDirectory(string $path): void
    {
        $path = rtrim($path, '/').'/';

        try {
            $blobs = $this->listContents($path, true);

            foreach ($blobs as $blob) {
                $this->delete($blob->path());
            }

            // Eliminar el directorio "virtual"
            try {
                $this->client->deleteBlob($this->container, $path);
            } catch (ServiceException $exception) {
                if ($exception->getCode() !== 404) {
                    throw $exception;
                }
            }
        } catch (ServiceException $exception) {
            throw UnableToDeleteDirectory::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        try {
            $path = rtrim($path, '/').'/';
            $options = $this->getOptionsFromConfig($config);

            $this->client->createBlockBlob(
                $this->container,
                $path,
                '',
                $options
            );
        } catch (ServiceException $exception) {
            throw UnableToCreateDirectory::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    public function setVisibility(string $path, string $visibility): void
    {
        throw UnableToSetVisibility::atLocation($path, 'Azure Blob Storage does not support setting visibility');
    }

    public function visibility(string $path): FileAttributes
    {
        throw UnableToRetrieveMetadata::visibility($path, 'Azure Blob Storage does not support retrieving visibility');
    }

    public function mimeType(string $path): FileAttributes
    {
        try {
            $properties = $this->client->getBlobProperties($this->container, $path);

            return new FileAttributes(
                $path,
                null,
                null,
                null,
                $properties->getProperties()->getContentType()
            );
        } catch (ServiceException $exception) {
            throw UnableToRetrieveMetadata::mimeType($path, $exception->getMessage(), $exception);
        }
    }

    public function lastModified(string $path): FileAttributes
    {
        try {
            $properties = $this->client->getBlobProperties($this->container, $path);
            $lastModified = $properties->getProperties()->getLastModified()->getTimestamp();

            return new FileAttributes(
                $path,
                null,
                null,
                $lastModified
            );
        } catch (ServiceException $exception) {
            throw UnableToRetrieveMetadata::lastModified($path, $exception->getMessage(), $exception);
        }
    }

    public function fileSize(string $path): FileAttributes
    {
        try {
            $properties = $this->client->getBlobProperties($this->container, $path);
            $fileSize = $properties->getProperties()->getContentLength();

            return new FileAttributes(
                $path,
                $fileSize
            );
        } catch (ServiceException $exception) {
            throw UnableToRetrieveMetadata::fileSize($path, $exception->getMessage(), $exception);
        }
    }

    public function listContents(string $path = '', bool $deep = false): iterable
    {
        $path = rtrim($path, '/');
        if ($path !== '') {
            $path .= '/';
        }

        $options = new ListBlobsOptions();
        $options->setPrefix($path);

        try {
            $blobList = $this->client->listBlobs($this->container, $options);
            $blobs = $blobList->getBlobs();

            foreach ($blobs as $blob) {
                $blobPath = $blob->getName();
                $normalizedPath = $this->normalizePath($blobPath);

                // Saltar el directorio raíz si se incluye en los resultados
                if ($normalizedPath === $path) {
                    continue;
                }

                // Gestionar subdirectorios basados en el separador /
                if (! $deep) {
                    $baseDir = str_replace($path, '', $normalizedPath);
                    $subDir = explode('/', $baseDir, 2);

                    if (isset($subDir[1])) {
                        $dirPath = $path.$subDir[0].'/';
                        $isDirectory = true;

                        // Solo devolver directorios únicos
                        static $emittedDirectories = [];
                        if (isset($emittedDirectories[$dirPath])) {
                            continue;
                        }

                        $emittedDirectories[$dirPath] = true;

                        yield new FileAttributes(
                            $dirPath,
                            null,
                            null,
                            null,
                            null,
                            true
                        );

                        continue;
                    }
                }

                // Determinar si es un directorio o un archivo
                $isDirectory = substr($normalizedPath, -1) === '/';
                $contentLength = $blob->getProperties()->getContentLength();
                $lastModified = $blob->getProperties()->getLastModified()->getTimestamp();
                $mimeType = $blob->getProperties()->getContentType();

                yield new FileAttributes(
                    $normalizedPath,
                    $isDirectory ? null : $contentLength,
                    null,
                    $lastModified,
                    $mimeType,
                    $isDirectory
                );
            }
        } catch (ServiceException $exception) {
            // Manejar posibles errores, simplemente devuelve una lista vacía
            return;
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        try {
            $this->copy($source, $destination, $config);
            $this->delete($source);
        } catch (ServiceException $exception) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $exception);
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        try {
            $this->client->copyBlob(
                $this->container,
                $destination,
                $this->container,
                $source
            );
        } catch (ServiceException $exception) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $exception);
        }
    }

    /**
     * Normaliza la ruta del blob.
     */
    protected function normalizePath(string $path): string
    {
        return $path;
    }

    /**
     * Obtiene las opciones a partir de la configuración.
     */
    protected function getOptionsFromConfig(Config $config): CreateBlockBlobOptions
    {
        $options = new CreateBlockBlobOptions();

        if ($config->has('ContentType')) {
            $options->setContentType($config->get('ContentType'));
        }

        if ($config->has('CacheControl')) {
            $options->setCacheControl($config->get('CacheControl'));
        }

        if ($config->has('Metadata') && is_array($config->get('Metadata'))) {
            $options->setMetadata($config->get('Metadata'));
        }

        return $options;
    }
}
