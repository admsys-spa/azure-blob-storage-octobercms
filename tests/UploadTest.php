<?php

namespace Admsys\AzureBlobStorage\Tests;

use Admsys\AzureBlobStorage\AzureBlobStorage;
use Illuminate\Http\UploadedFile;
use Mockery;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use MicrosoftAzure\Storage\Blob\Models\CreateBlockBlobOptions;
use MicrosoftAzure\Storage\Blob\Models\GetBlobResult;
use PHPUnit\Framework\TestCase;

class UploadTest extends TestCase
{
    protected $blobClientMock;
    protected $azureBlobStorage;
    protected $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->blobClientMock = Mockery::mock(BlobRestProxy::class);

        $this->config = [
            'account_name' => 'testaccount',
            'account_key' => 'testkey',
            'container_name' => 'test-container',
            'endpoint' => null,
            'url' => null,
            'default_visibility' => 'private',
            'max_upload_time' => 600,
        ];

        // Crear clase con cliente mock
        $this->azureBlobStorage = new class($this->config, $this->blobClientMock) extends AzureBlobStorage {
            protected $mockedClient;

            public function __construct(array $config, $mockedClient)
            {
                $this->config = $config;
                $this->mockedClient = $mockedClient;
            }

            protected function initClient()
            {
                // No inicializar el cliente real, usamos el mock
                $this->blobClient = $this->mockedClient;
            }
        };
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_can_upload_file_from_uploaded_file()
    {
        // Crear un archivo temporal
        $tempFile = UploadedFile::fake()->create('document.pdf', 500);
        $fileContent = file_get_contents($tempFile->getRealPath());
        
        // Configurar expectativas del mock
        $this->blobClientMock->shouldReceive('createBlockBlob')
            ->once()
            ->with(
                'test-container',
                'document.pdf',
                $fileContent,
                Mockery::type(CreateBlockBlobOptions::class)
            );

        // Ejecutar el método upload
        $result = $this->azureBlobStorage->upload($tempFile);

        // Verificar que el resultado es correcto
        $this->assertIsString($result);
        $this->assertStringContainsString('document.pdf', $result);
    }

    /** @test */
    public function it_can_upload_file_from_path()
    {
        // Crear un archivo temporal
        $tempFilePath = tempnam(sys_get_temp_dir(), 'azuretest_');
        file_put_contents($tempFilePath, 'Test file content');
        
        // Configurar expectativas del mock
        $this->blobClientMock->shouldReceive('createBlockBlob')
            ->once()
            ->with(
                'test-container',
                basename($tempFilePath),
                'Test file content',
                Mockery::type(CreateBlockBlobOptions::class)
            );

        // Ejecutar el método upload
        $result = $this->azureBlobStorage->upload($tempFilePath);

        // Verificar que el resultado es correcto
        $this->assertIsString($result);
        $this->assertStringContainsString(basename($tempFilePath), $result);
        
        // Limpiar
        unlink($tempFilePath);
    }

    /** @test */
    public function it_can_upload_with_custom_blob_name()
    {
        // Crear un archivo temporal
        $tempFile = UploadedFile::fake()->create('document.pdf', 500);
        $fileContent = file_get_contents($tempFile->getRealPath());
        
        // Configurar expectativas del mock
        $this->blobClientMock->shouldReceive('createBlockBlob')
            ->once()
            ->with(
                'test-container',
                'custom-name.pdf',
                $fileContent,
                Mockery::type(CreateBlockBlobOptions::class)
            );

        // Ejecutar el método upload con nombre personalizado
        $result = $this->azureBlobStorage->upload($tempFile, 'custom-name.pdf');

        // Verificar que el resultado es correcto
        $this->assertIsString($result);
        $this->assertStringContainsString('custom-name.pdf', $result);
    }

    /** @test */
    public function it_can_upload_with_unique_name_option()
    {
        // Crear un archivo temporal
        $tempFile = UploadedFile::fake()->create('document.pdf', 500);
        $fileContent = file_get_contents($tempFile->getRealPath());
        
        // Configurar expectativas del mock - no podemos verificar el nombre exacto ya que será aleatorio
        $this->blobClientMock->shouldReceive('createBlockBlob')
            ->once()
            ->with(
                'test-container',
                Mockery::type('string'), // Nombre aleatorio
                $fileContent,
                Mockery::type(CreateBlockBlobOptions::class)
            );

        // Ejecutar el método upload con opción unique
        $result = $this->azureBlobStorage->upload($tempFile, null, ['unique' => true]);

        // Verificar que el resultado es correcto
        $this->assertIsString($result);
        $this->assertStringContainsString('document', $result);
        $this->assertMatchesRegularExpression('/document_[a-zA-Z0-9]{8}\.pdf/', $result);
    }

    /** @test */
    public function it_returns_false_on_upload_error()
    {
        // Crear un archivo temporal
        $tempFile = UploadedFile::fake()->create('document.pdf', 500);
        
        // Configurar el mock para lanzar una excepción
        $this->blobClientMock->shouldReceive('createBlockBlob')
            ->once()
            ->andThrow(new \MicrosoftAzure\Storage\Common\Exceptions\ServiceException('Error al subir archivo'));

        // Ejecutar el método upload
        $result = $this->azureBlobStorage->upload($tempFile);

        // Verificar que el resultado es false
        $this->assertFalse($result);
    }

    /** @test */
    public function it_can_upload_with_metadata()
    {
        // Crear un archivo temporal
        $tempFile = UploadedFile::fake()->create('document.pdf', 500);
        $fileContent = file_get_contents($tempFile->getRealPath());
        
        // Configurar expectativas del mock
        $this->blobClientMock->shouldReceive('createBlockBlob')
            ->once()
            ->withArgs(function ($container, $blobName, $content, $options) {
                // Verificar que los metadatos se pasaron correctamente
                $metadata = $options->getMetadata();
                return $container === 'test-container' && 
                       $blobName === 'document.pdf' && 
                       $metadata['author'] === 'Test Author' &&
                       $metadata['category'] === 'Test Category';
            });

        // Ejecutar el método upload con metadatos
        $result = $this->azureBlobStorage->upload($tempFile, null, [
            'metadata' => [
                'author' => 'Test Author',
                'category' => 'Test Category'
            ]
        ]);

        // Verificar que el resultado es correcto
        $this->assertIsString($result);
    }

    /** @test */
    public function it_can_upload_with_public_visibility()
    {
        // Crear un archivo temporal
        $tempFile = UploadedFile::fake()->create('document.pdf', 500);
        
        // Configurar expectativas del mock
        $this->blobClientMock->shouldReceive('createBlockBlob')
            ->once()
            ->withArgs(function ($container, $blobName, $content, $options) {
                // Verificar que se estableció la visibilidad pública
                return $options->getBlobPublicAccess() === 'container';
            });

        // Ejecutar el método upload con visibilidad pública
        $result = $this->azureBlobStorage->upload($tempFile, null, [
            'visibility' => 'public'
        ]);

        // Verificar que el resultado es correcto
        $this->assertIsString($result);
    }

    /** @test */
    public function it_can_upload_with_cache_control()
    {
        // Crear un archivo temporal
        $tempFile = UploadedFile::fake()->create('document.pdf', 500);
        
        // Configurar expectativas del mock
        $this->blobClientMock->shouldReceive('createBlockBlob')
            ->once()
            ->withArgs(function ($container, $blobName, $content, $options) {
                // Verificar que se estableció el Cache-Control
                return $options->getCacheControl() === 'public, max-age=31536000';
            });

        // Ejecutar el método upload con Cache-Control
        $result = $this->azureBlobStorage->upload($tempFile, null, [
            'cacheControl' => 'public, max-age=31536000'
        ]);

        // Verificar que el resultado es correcto
        $this->assertIsString($result);
    }
}