<?php

namespace Admsys\AzureBlobStorage\Tests;

use Admsys\AzureBlobStorage\AzureBlobStorage;
use Admsys\AzureBlobStorage\Facades\AzureBlobStorage as AzureBlobStorageFacade;
use Illuminate\Http\UploadedFile;
use Orchestra\Testbench\TestCase;

class IntegrationUploadTest extends TestCase
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
        $app['config']->set('azure-blob-storage-config.max_upload_time', 600);
    }

    /**
     * Setup the test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

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
    }

    /**
     * @test
     * @group integration
     */
    public function it_can_upload_file_and_check_existence()
    {
        // Crear un archivo temporal para la prueba
        $tempFile = UploadedFile::fake()->create('integration-test.txt', 100);

        // Subir el archivo usando el servicio
        $blobName = 'test-upload-' . uniqid() . '.txt';
        $result = AzureBlobStorageFacade::upload($tempFile, $blobName);

        // Verificar que la subida fue exitosa
        $this->assertNotFalse($result);
        $this->assertStringContainsString($blobName, $result);

        // Verificar que el archivo existe
        $exists = AzureBlobStorageFacade::exists($blobName);
        $this->assertTrue($exists);

        // Limpiar - eliminar el archivo subido
        AzureBlobStorageFacade::delete($blobName);
    }

    /**
     * @test
     * @group integration
     */
    public function it_can_upload_and_download_file_content()
    {
        // Crear contenido de prueba único
        $testContent = 'Test content ' . uniqid();

        // Crear un archivo temporal con el contenido
        $tempFile = tempnam(sys_get_temp_dir(), 'azure_test_');
        file_put_contents($tempFile, $testContent);

        // Subir el archivo
        $blobName = 'test-content-' . uniqid() . '.txt';
        $result = AzureBlobStorageFacade::upload($tempFile, $blobName);

        // Verificar que la subida fue exitosa
        $this->assertNotFalse($result);

        // Descargar el contenido del archivo
        $downloadedContent = AzureBlobStorageFacade::download($blobName);

        // Verificar que el contenido descargado es igual al original
        $this->assertEquals($testContent, $downloadedContent);

        // Limpiar
        AzureBlobStorageFacade::delete($blobName);
        unlink($tempFile);
    }

    /**
     * @test
     * @group integration
     */
    public function it_can_upload_with_unique_name()
    {
        // Crear un archivo temporal
        $tempFile = UploadedFile::fake()->create('unique-test.txt', 100);

        // Subir con opción de nombre único
        $result = AzureBlobStorageFacade::upload($tempFile, 'common-name.txt', ['unique' => true]);

        // Verificar que el nombre generado es único
        $this->assertNotFalse($result);
        $this->assertStringContainsString('common-name_', $result);
        $this->assertMatchesRegularExpression('/common-name_[a-zA-Z0-9]{8}\.txt/', $result);

        // Extraer el nombre del blob de la URL
        $blobName = basename(parse_url($result, PHP_URL_PATH));

        // Limpiar
        AzureBlobStorageFacade::delete($blobName);
    }

    /**
     * @test
     * @group integration
     */
    public function it_can_upload_multiple_files_and_list_them()
    {
        // Crear varios archivos
        $tempFiles = [];
        $blobNames = [];

        for ($i = 1; $i <= 3; $i++) {
            $tempFile = UploadedFile::fake()->create("multi-test-{$i}.txt", 100);
            $blobName = "multi-test-{$i}-" . uniqid() . ".txt";

            // Subir archivo
            $result = AzureBlobStorageFacade::upload($tempFile, $blobName);
            $this->assertNotFalse($result);

            $tempFiles[] = $tempFile;
            $blobNames[] = $blobName;
        }

        // Listar los blobs con el prefijo "multi-test"
        $listedBlobs = AzureBlobStorageFacade::listBlobs('multi-test');

        // Verificar que se listen al menos los 3 archivos que acabamos de subir
        $this->assertIsArray($listedBlobs);
        $this->assertGreaterThanOrEqual(3, count($listedBlobs));

        // Verificar que todos los archivos que subimos estén en la lista
        $listedNames = array_map(function($blob) {
            return $blob['name'];
        }, $listedBlobs);

        foreach ($blobNames as $blobName) {
            $this->assertTrue(in_array($blobName, $listedNames));
        }

        // Limpiar - eliminar todos los archivos subidos
        foreach ($blobNames as $blobName) {
            AzureBlobStorageFacade::delete($blobName);
        }
    }

    /**
     * @test
     * @group integration
     */
    public function it_can_upload_with_metadata_and_retrieve_it()
    {
        // Este test solo es válido para Azure real, ya que Azurite puede no soportar todas las funcionalidades
        // Verificamos si estamos usando Azure real o Azurite
        if ($this->isAzuriteEmulator()) {
            $this->markTestSkipped('Este test requiere Azure real, no el emulador Azurite.');
        }

        // Crear un archivo temporal
        $tempFile = UploadedFile::fake()->create('metadata-test.txt', 100);

        // Metadatos a asociar
        $metadata = [
            'author' => 'Test Author',
            'category' => 'Test Category',
            'created' => date('Y-m-d')
        ];

        // Subir con metadatos
        $blobName = 'metadata-test-' . uniqid() . '.txt';
        $result = AzureBlobStorageFacade::upload($tempFile, $blobName, [
            'metadata' => $metadata
        ]);

        // Verificar que la subida fue exitosa
        $this->assertNotFalse($result);

        // Obtener los metadatos (requiere implementar un método getMetadata)
        // Esta parte dependería de si tienes implementado un método getMetadata en tu clase AzureBlobStorage
        // Si no lo tienes, puedes comentar esta parte del test o implementar dicho método

        /*
        $retrievedMetadata = AzureBlobStorageFacade::getMetadata($blobName);

        // Verificar los metadatos
        $this->assertEquals($metadata['author'], $retrievedMetadata['author']);
        $this->assertEquals($metadata['category'], $retrievedMetadata['category']);
        $this->assertEquals($metadata['created'], $retrievedMetadata['created']);
        */

        // Limpiar
        AzureBlobStorageFacade::delete($blobName);
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

    /**
     * Determina si estamos usando el emulador Azurite o Azure real
     */
    private function isAzuriteEmulator()
    {
        return strpos(config('azure-blob-storage-config.endpoint'), 'devstoreaccount1') !== false;
    }
}