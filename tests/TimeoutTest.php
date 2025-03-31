<?php

namespace Admsys\AzureBlobStorage\Tests;

use Admsys\AzureBlobStorage\AzureBlobStorage;
use Mockery;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use PHPUnit\Framework\TestCase;

class TimeoutTest extends TestCase
{
    protected $blobClientMock;
    protected $azureBlobStorage;
    protected $config;
    protected $originalTimeout;

    protected function setUp(): void
    {
        parent::setUp();

        // Guardar el timeout original de PHP
        $this->originalTimeout = ini_get('max_execution_time');

        $this->blobClientMock = Mockery::mock(BlobRestProxy::class);

        $this->config = [
            'account_name' => 'testaccount',
            'account_key' => 'testkey',
            'container_name' => 'test-container',
            'endpoint' => null,
            'url' => null,
            'default_visibility' => 'private',
            'max_upload_time' => 120, // 2 minutos
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

            // Método para acceder al valor de max_upload_time
            public function getMaxUploadTime()
            {
                return $this->config['max_upload_time'] ?? 600;
            }

            // Método para probar el comportamiento de timeout
            public function testTimeout($callback)
            {
                $originalTimeout = ini_get('max_execution_time');

                // Establecer nuevo timeout si es posible
                if (ini_get('max_execution_time') != 0) {
                    @set_time_limit($this->config['max_upload_time']);
                }

                try {
                    $result = $callback();

                    // Restaurar el timeout original
                    if ($originalTimeout != 0 && ini_get('max_execution_time') != 0) {
                        @set_time_limit($originalTimeout);
                    }

                    return $result;
                } catch (\Exception $e) {
                    // Restaurar el timeout original en caso de error
                    if ($originalTimeout != 0 && ini_get('max_execution_time') != 0) {
                        @set_time_limit($originalTimeout);
                    }

                    throw $e;
                }
            }
        };
    }

    protected function tearDown(): void
    {
        // Restaurar el timeout original
        if ($this->originalTimeout != 0) {
            @set_time_limit($this->originalTimeout);
        }

        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_can_set_max_operation_time()
    {
        // Verificar el valor predeterminado
        $this->assertEquals(120, $this->azureBlobStorage->getMaxUploadTime());

        // Cambiar el valor mediante el método setMaxOperationTime
        $this->azureBlobStorage->setMaxOperationTime(300);

        // Verificar que el valor se actualizó
        $this->assertEquals(300, $this->azureBlobStorage->getMaxUploadTime());
    }

    /** @test */
    public function it_sets_and_restores_timeout_correctly()
    {
        // Saltamos la prueba si no podemos manipular el timeout
        if (!function_exists('set_time_limit')) {
            $this->markTestSkipped('La función set_time_limit no está disponible en este entorno.');
        }

        // Establecer un timeout inicial para la prueba
        $initialTimeout = 30;
        @set_time_limit($initialTimeout);

        // Verificar que podemos obtener el timeout actual
        $currentTimeout = ini_get('max_execution_time');
        if ($currentTimeout != $initialTimeout) {
            $this->markTestSkipped('No se puede modificar el timeout en este entorno.');
        }

        // Ejecutar una operación que debería cambiar el timeout y luego restaurarlo
        $result = $this->azureBlobStorage->testTimeout(function() {
            // Verificar que el timeout se cambió durante la ejecución
            $timeoutDuringExecution = ini_get('max_execution_time');
            $this->assertEquals(120, $timeoutDuringExecution);
            return true;
        });

        // Verificar que el timeout se restauró correctamente
        $this->assertEquals($initialTimeout, ini_get('max_execution_time'));
        $this->assertTrue($result);
    }

    /** @test */
    public function it_restores_timeout_even_if_exception_occurs()
    {
        // Saltamos la prueba si no podemos manipular el timeout
        if (!function_exists('set_time_limit')) {
            $this->markTestSkipped('La función set_time_limit no está disponible en este entorno.');
        }

        // Establecer un timeout inicial para la prueba
        $initialTimeout = 30;
        @set_time_limit($initialTimeout);

        // Verificar que podemos obtener el timeout actual
        $currentTimeout = ini_get('max_execution_time');
        if ($currentTimeout != $initialTimeout) {
            $this->markTestSkipped('No se puede modificar el timeout en este entorno.');
        }

        // Ejecutar una operación que lanza una excepción
        try {
            $this->azureBlobStorage->testTimeout(function() {
                throw new \Exception('Error de prueba');
            });

            $this->fail('Se esperaba una excepción pero no se lanzó ninguna');
        } catch (\Exception $e) {
            // Verificar que el mensaje de error es el esperado
            $this->assertEquals('Error de prueba', $e->getMessage());
        }

        // Verificar que el timeout se restauró correctamente a pesar de la excepción
        $this->assertEquals($initialTimeout, ini_get('max_execution_time'));
    }

    /** @test */
    public function it_does_not_modify_timeout_if_unlimited()
    {
        // Saltamos la prueba si no podemos manipular el timeout
        if (!function_exists('set_time_limit')) {
            $this->markTestSkipped('La función set_time_limit no está disponible en este entorno.');
        }

        // Establecer un timeout ilimitado (0)
        @set_time_limit(0);

        // Verificar que podemos obtener el timeout actual
        $currentTimeout = ini_get('max_execution_time');
        if ($currentTimeout != 0) {
            $this->markTestSkipped('No se puede establecer un timeout ilimitado en este entorno.');
        }

        // Ejecutar una operación que no debería cambiar el timeout ilimitado
        $this->azureBlobStorage->testTimeout(function() {
            // Verificar que el timeout sigue siendo ilimitado durante la ejecución
            $this->assertEquals(0, ini_get('max_execution_time'));
            return true;
        });

        // Verificar que el timeout sigue siendo ilimitado después de la ejecución
        $this->assertEquals(0, ini_get('max_execution_time'));
    }
}