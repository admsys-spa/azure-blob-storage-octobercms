<?php

namespace Admsys\AzureBlobStorage\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string|bool upload($file, ?string $blobName = null, array $options = [])
 * @method static string getUrl(string $blobName)
 * @method static bool delete(string $blobName)
 * @method static bool exists(string $blobName)
 * @method static array|bool listBlobs(?string $prefix = null, int $maxResults = 1000)
 * @method static string|bool download(string $blobName)
 * @method static \MicrosoftAzure\Storage\Blob\BlobRestProxy getClient()
 * @method static \Admsys\AzureBlobStorage\AzureBlobStorage setMaxOperationTime(int $seconds)
 * @method static int getMaxOperationTime()
 *
 * @see \Admsys\AzureBlobStorage\AzureBlobStorage
 */
class AzureBlobStorage extends Facade
{
    /**
     * Obtener el nombre del componente registrado.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'azure-blob-storage';
    }
}