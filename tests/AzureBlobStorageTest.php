<?php

namespace Admsys\AzureBlobStorage\Tests;

use Admsys\AzureBlobStorage\AzureBlobStorage;
use Admsys\AzureBlobStorage\Facades\AzureBlobStorage as AzureBlobStorageFacade;
use Illuminate\Http\UploadedFile;
use Orchestra\Testbench\TestCase;

class AzureBlobStorageTest extends TestCase
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
        // Configuración para pruebas
        $app['config']->set('azure-blob-storage.account_name', 'devstoreaccount1');
        $app['config']->set('azure-blob-storage.account_key', 'Eby8vdM02xNOcqFlqUwJPLlmEtlCDXJ1OUzFT50uSRZ6IFsuFq2UVErCz4I6tq/K1SZFPTOtr/KBHBeksoGMGw==');
        $app['config']->set('azure-blob-storage.container_name', 'test-container');
        $app['config']->set('azure-blob-storage.endpoint', 'http://127.0.0.1:10000/devstoreaccount1');
    }

    /** @test */
    public function it_can_be_instantiated()
    {
        $this->assertInstanceOf(
            AzureBlobStorage::class,
            $this->app->make('azure-blob-storage')
        );
    }

    /** @test */
    public function facade_works()
    {
        $this->assertInstanceOf(
            AzureBlobStorage::class,
            AzureBlobStorageFacade::getFacadeRoot()
        );
    }

    /**
     * @test
     *
     * @group integration
     */
    public function it_can_upload_file()
    {
        // Este test solo se ejecutará si se configura Azurite (emulador local)
        if (! $this->isAzuriteRunning()) {
            $this->markTestSkipped('Azurite no está en ejecución. Omitiendo prueba de integración.');
        }

        $tempFile = UploadedFile::fake()->create('document.pdf', 500);
        $result = AzureBlobStorageFacade::upload($tempFile, 'test-document.pdf');

        $this->assertNotFalse($result);
        $this->assertStringContainsString('test-document.pdf', $result);
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
