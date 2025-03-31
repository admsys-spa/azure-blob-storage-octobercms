<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Azure Blob Storage Configuration
    |--------------------------------------------------------------------------
    |
    | Aquí puedes configurar tus credenciales para Azure Blob Storage.
    |
    */

    'account_name' => env('AZURE_STORAGE_ACCOUNT_NAME', ''),
    'account_key' => env('AZURE_STORAGE_ACCOUNT_KEY', ''),
    'sas_token' => env('AZURE_STORAGE_SAS_TOKEN', ''),  // Token SAS para autenticación alternativa
    'container_name' => env('AZURE_STORAGE_CONTAINER', ''),

    /*
    |--------------------------------------------------------------------------
    | Configuración adicional
    |--------------------------------------------------------------------------
    |
    | Configuraciones opcionales para personalizar el comportamiento.
    |
    */

    // Endpoint opcional personalizado (para usar con Azurite, emulador local, etc.)
    'endpoint' => env('AZURE_STORAGE_ENDPOINT', null),

    // URL base para archivos públicos (CDN o URL de la cuenta)
    'url' => env('AZURE_STORAGE_URL', null),

    // Configuración de visibilidad por defecto ('public' o 'private')
    'default_visibility' => env('AZURE_STORAGE_DEFAULT_VISIBILITY', 'private'),

    // Tiempo máximo de carga en segundos
    'max_upload_time' => env('AZURE_STORAGE_MAX_UPLOAD_TIME', 600),

    // Modo de autenticación ('key' para clave de cuenta o 'sas' para token SAS)
    'auth_mode' => env('AZURE_STORAGE_AUTH_MODE', 'key'),
];
