<?php

namespace Admsys\AzureBlobStorageOctobercms\Models;

use October\Rain\Database\Model;
use October\Rain\Database\Traits\Validation;
use System\Behaviors\SettingsModel;

/**
 * Settings Model
 */
class Settings extends Model
{

    /**
     * Implement behaviors
     */
    public $implement = [
        SettingsModel::class
    ];

    /**
     * The settings code
     */
    public $settingsCode = 'admsys_azure_blob_storage_settings';

    /**
     * Reference to field configuration
     */
    public $settingsFields = 'fields.yaml';

    /**
     * Implement the ValidationTrait
     */
    use Validation;

    /**
     * Validation rules
     */
    public $rules = [
        'account_name' => 'required',
        'container_name' => 'required',
    ];

    /**
     * Initialize the model
     */
    public function initSettingsData()
    {
        $this->account_name = env('AZURE_STORAGE_ACCOUNT_NAME', '');
        $this->account_key = env('AZURE_STORAGE_ACCOUNT_KEY', '');
        $this->sas_token = env('AZURE_STORAGE_SAS_TOKEN', '');
        $this->container_name = env('AZURE_STORAGE_CONTAINER', '');
        $this->endpoint = env('AZURE_STORAGE_ENDPOINT', '');
        $this->url = env('AZURE_STORAGE_URL', '');
        $this->default_visibility = env('AZURE_STORAGE_DEFAULT_VISIBILITY', 'private');
        $this->auth_mode = env('AZURE_STORAGE_AUTH_MODE', 'key');
    }
}