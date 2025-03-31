<?php

namespace Admsys\AzureBlobStorage\Tests;

use Admsys\AzureBlobStorage\Facades\AzureBlobStorage as AzureBlobStorageFacade;
use Orchestra\Testbench\TestCase;

class LargeFileUploadTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [
            'Admsys\AzureBlobStorage\AzureBlobStorageServiceProvider',
        ];
    }

    protected function getPackageAliases($app)
    {
        return [
            'AzureBlobStorage' => AzureBlobStorageFacade::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        // Configuración para Azurite (emulador local)
        $app['config']->set('azure-blob-storage-config.account_name', 'devstoreaccount1');
        $app['config']->set('azure-blob-storage-config.account_key', 'Eby8vdM02xNOcqFlqUwJPLlmEtlCDXJ1OUzFT50uSRZ6IFsuFq2UVErCz4I6tq/K1SZFPTOtr/KBHBeksoGMGw==');
        $app['config']->set('azure-blob-storage-config.container_name', 'test-container');
        $app['config']->set('azure-blob-storage-config.endpoint', 'http://127.0.0.1:10000/devstoreaccount1');

        // Establecer un timeout corto para la prueba
        $app['config']->set('azure-blob-storage-config.max_upload_time', 30);
    }

    /**
     * @test
     * @group integration
     * @group large-files
     */
    public function it_can_upload_large_file_with_custom_timeout()
    {
        // Omitir si Azurite no está en ejecución
        if (!$this->isAzuriteRunning()) {
            $this->markTestSkipped('Azurite no está en ejecución. Omitiendo prueba de integración.');
        }

        // Verificar si el contenedor existe, si no, crearlo
        $service = $this->app->make('azure-blob-storage');
        $client = $service->getClient();

        try {
            // Intentar crear el contenedor (ignoramos si ya existe)
            $client->createContainer('test-container');
        } catch (\Exception $e) {
            // Ignorar error si el contenedor ya existe
        }

        // Generar un archivo grande (10MB)
        $filePath = $this->generateLargeFile(10 * 1024 * 1024);

        // Establecer un timeout personalizado para esta operación específica
        // (esto asume que has implementado el método setMaxOperationTime)
        AzureBlobStorageFacade::setMaxOperationTime(120); // 2 minutos

        // Subir el archivo grande
        $blobName = 'large-file-test-' . uniqid() . '.dat';
        $startTime = microtime(true);
        $result = AzureBlobStorageFacade::upload($filePath, $blobName);
        $endTime = microtime(true);

        // Verificar que la subida fue exitosa
        $this->assertNotFalse($result);
        $this->assertStringContainsString($blobName, $result);

        // Verificar que el archivo existe
        $exists = AzureBlobStorageFacade::exists($blobName);
        $this->assertTrue($exists);

        // Log del tiempo que tomó la subida
        $uploadTime = $endTime - $startTime;
        echo "La subida del archivo grande tomó {$uploadTime} segundos.\n";

        // Limpiar
        AzureBlobStorageFacade::delete($blobName);
        unlink($filePath);
    }

    /**
     * Genera un archivo grande para pruebas
     *
     * @param int $size Tamaño en bytes
     * @return string Ruta al archivo generado
     */
    private function generateLargeFile(int $size): string
    {
        $filePath = tempnam(sys_get_temp_dir(), 'azure_large_test_');

        $fp = fopen($filePath, 'w');

        // Escribir en bloques de 1MB para no consumir mucha memoria
        $blockSize = 1024 * 1024;
        $block = str_repeat('0', $blockSize);

        $blocksToWrite = ceil($size / $blockSize);

        for ($i = 0; $i < $blocksToWrite; $i++) {
            fwrite($fp, $block);
        }

        fclose($fp);

        return $filePath;
    }

    /**
     * Verifica si Azurite está en ejecución
     */
    private function isAzuriteRunning()
    {
        // Intenta conectarse al endpoint de Azurite
        $connectionOk = @fsockopen('127.0.0.1', 10000);
        if ($connectionOk) {
            fclose($connectionOk);
            return true;
        }
        return false;
    }
}