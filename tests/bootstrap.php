<?php

/**
 * Archivo bootstrap para tests
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Configurar variables de entorno para pruebas
putenv('AZURE_STORAGE_ACCOUNT_NAME=devstoreaccount1');
putenv('AZURE_STORAGE_ACCOUNT_KEY=Eby8vdM02xNOcqFlqUwJPLlmEtlCDXJ1OUzFT50uSRZ6IFsuFq2UVErCz4I6tq/K1SZFPTOtr/KBHBeksoGMGw==');
putenv('AZURE_STORAGE_CONTAINER=test-container');
putenv('AZURE_STORAGE_ENDPOINT=http://127.0.0.1:10000/devstoreaccount1');

// Funciones de ayuda para tests
if (!function_exists('config')) {
    function config($key = null, $default = null)
    {
        // Implementación simple para pruebas que no usan el framework completo
        $configs = [
            'azure-blob-storage-config.account_name' => getenv('AZURE_STORAGE_ACCOUNT_NAME'),
            'azure-blob-storage-config.account_key' => getenv('AZURE_STORAGE_ACCOUNT_KEY'),
            'azure-blob-storage-config.container_name' => getenv('AZURE_STORAGE_CONTAINER'),
            'azure-blob-storage-config.endpoint' => getenv('AZURE_STORAGE_ENDPOINT'),
            'azure-blob-storage-config.default_visibility' => 'private',
        ];

        if (is_null($key)) {
            return $configs;
        }

        return $configs[$key] ?? $default;
    }
}

// Configurar timezone para evitar warnings
date_default_timezone_set('UTC');

// Mensaje informativo
echo "Ejecutando pruebas para AzureBlobStorage...\n";
echo "Para ejecutar las pruebas de integración, asegúrate de tener Azurite en ejecución en localhost:10000\n";
echo "Comando para iniciar Azurite: 'azurite --silent --location ./azurite --debug ./debug.log'\n\n";