<?php
namespace verbb\formie\integrations\emailmarketing;

use verbb\formie\base\Integration;
use verbb\formie\base\EmailMarketing;
use verbb\formie\elements\Form;
use verbb\formie\elements\Submission;
use verbb\formie\errors\IntegrationException;
use verbb\formie\events\SendIntegrationPayloadEvent;
use verbb\formie\models\IntegrationCollection;
use verbb\formie\models\IntegrationField;
use verbb\formie\models\IntegrationFormSettings;

use Craft;
use craft\helpers\ArrayHelper;
use craft\helpers\Json;
use craft\web\View;

class IContact extends EmailMarketing
{
    // Properties
    // =========================================================================

    public $appId;
    public $password;
    public $username;
    public $accountId;
    public $clientFolderId;


    // Public Methods
    // =========================================================================

    /**
     * @inheritDoc
     */
    public static function displayName(): string
    {
        return Craft::t('formie', 'iContact');
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return Craft::t('formie', 'Sign up users to your iContact lists to grow your audience for campaigns.');
    }

    /**
     * @inheritDoc
     */
    public function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['appId', 'password', 'username', 'accountId', 'clientFolderId'], 'required'];

        return $rules;
    }

    /**
     * @inheritDoc
     */
    public function fetchFormSettings()
    {
        $settings = [];

        try {
            $response = $this->request('GET', 'lists');
            $lists = $response['lists'] ?? [];

            // While we're at it, fetch the fields for the list
            $response = $this->request('GET', 'customfields');
            $fields = $response['customfields'] ?? [];

            foreach ($lists as $list) {
                $listFields = array_merge([
                    new IntegrationField([
                        'handle' => 'email',
                        'name' => Craft::t('formie', 'Email'),
                        'required' => true,
                    ]),
                    new IntegrationField([
                        'handle' => 'prefix',
                        'name' => Craft::t('formie', 'Prefix'),
                    ]),
                    new IntegrationField([
                        'handle' => 'firstName',
                        'name' => Craft::t('formie', 'First Name'),
                    ]),
                    new IntegrationField([
                        'handle' => 'lastName',
                        'name' => Craft::t('formie', 'Last Name'),
                    ]),
                    new IntegrationField([
                        'handle' => 'suffix',
                        'name' => Craft::t('formie', 'Suffix'),
                    ]),
                    new IntegrationField([
                        'handle' => 'street',
                        'name' => Craft::t('formie', 'Street'),
                    ]),
                    new IntegrationField([
                        'handle' => 'street2',
                        'name' => Craft::t('formie', 'Street 2'),
                    ]),
                    new IntegrationField([
                        'handle' => 'city',
                        'name' => Craft::t('formie', 'City'),
                    ]),
                    new IntegrationField([
                        'handle' => 'state',
                        'name' => Craft::t('formie', 'State'),
                    ]),
                    new IntegrationField([
                        'handle' => 'postalCode',
                        'name' => Craft::t('formie', 'Postal Code'),
                    ]),
                    new IntegrationField([
                        'handle' => 'phone',
                        'name' => Craft::t('formie', 'Phone'),
                    ]),
                    new IntegrationField([
                        'handle' => 'fax',
                        'name' => Craft::t('formie', 'Fax'),
                    ]),
                    new IntegrationField([
                        'handle' => 'business',
                        'name' => Craft::t('formie', 'Business Phone'),
                    ]),
                    new IntegrationField([
                        'handle' => 'status',
                        'name' => Craft::t('formie', 'Status'),
                    ]),
                ], $this->_getCustomFields($fields));

                $settings['lists'][] = new IntegrationCollection([
                    'id' => $list['listId'],
                    'name' => $list['name'],
                    'fields' => $listFields,
                ]);
            }
        } catch (\Throwable $e) {
            Integration::error($this, Craft::t('formie', 'API error: “{message}” {file}:{line}', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]), true);
        }

        return new IntegrationFormSettings($settings);
    }

    /**
     * @inheritDoc
     */
    public function sendPayload(Submission $submission): bool
    {
        try {
            $fieldValues = $this->getFieldMappingValues($submission, $this->fieldMapping);

            $validStatuses = ['normal', 'bounced', 'donotcontact', 'pending', 'invitable', 'deleted'];

            // Setup defaults for status
            $fieldValues['status'] = $fieldValues['status'] ?? 'normal';

            if (!in_array($fieldValues['status'], $validStatuses)) {
                $fieldValues['status'] = 'normal';
            }

            $payload = [
                'contact' => $fieldValues,
            ];

            $response = $this->deliverPayload($submission, 'contacts', $payload);

            if ($response === false) {
                return true;
            }

            $contactId = $response['contacts'][0]['contactId'] ?? '';

            if (!$contactId) {
                Integration::error($this, Craft::t('formie', 'API error: “{response}”', [
                    'response' => Json::encode($response),
                ]), true);

                return false;
            }

            // Add them to the list
            $payload = [
                'subscription' => [
                    'contactId' => $contactId,
                    'listId' => $this->listId,
                    'status' => $fieldValues['status'],
                ],
            ];

            $response = $this->deliverPayload($submission, 'subscriptions', $payload);

            if ($response === false) {
                return true;
            }

            $failed = $response['failed'] ?? [];

            if ($failed) {
                Integration::error($this, Craft::t('formie', 'API error: “{response}”', [
                    'response' => Json::encode($response),
                ]), true);

                return false;
            }
        } catch (\Throwable $e) {
            Integration::error($this, Craft::t('formie', 'API error: “{message}” {file}:{line}', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]), true);

            return false;
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function fetchConnection(): bool
    {
        try {
            $response = $this->request('GET', 'lists');
        } catch (\Throwable $e) {
            Integration::error($this, Craft::t('formie', 'API error: “{message}” {file}:{line}', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]), true);

            return false;
        }

        return true;
    }


    // Protected Methods
    // =========================================================================

    /**
     * @inheritDoc
     */
    protected function getClient()
    {
        if ($this->_client) {
            return $this->_client;
        }

        $accountId = Craft::parseEnv($this->accountId);
        $clientFolderId = Craft::parseEnv($this->clientFolderId);

        return $this->_client = Craft::createGuzzleClient([
            'base_uri' => "https://app.icontact.com/icp/a/{$accountId}/c/{$clientFolderId}/",
            'headers' => [
                'Accept' => 'application/json',
                'Content-type' => 'application/json',
                'Api-Version' => '2.2',
                'API-AppId' => Craft::parseEnv($this->appId),
                'API-Username' => Craft::parseEnv($this->username),
                'API-Password' => Craft::parseEnv($this->password),
            ],
        ]);
    }


    // Private Methods
    // =========================================================================

    /**
     * @inheritDoc
     */
    private function _convertFieldType($fieldType)
    {
        $fieldTypes = [
            'checkbox' => IntegrationField::TYPE_ARRAY,
            'number' => IntegrationField::TYPE_NUMBER,
            'date' => IntegrationField::TYPE_DATE,
        ];

        return $fieldTypes[$fieldType] ?? IntegrationField::TYPE_STRING;
    }

    /**
     * @inheritDoc
     */
    private function _getCustomFields($fields, $excludeNames = [])
    {
        $customFields = [];

        foreach ($fields as $key => $field) {
            // Exclude any names
            if (in_array($field['publicName'], $excludeNames)) {
                 continue;
            }

            $customFields[] = new IntegrationField([
                'handle' => $field['customFieldId'] ,
                'name' => $field['publicName'],
                'type' => $this->_convertFieldType($field['fieldType']),
            ]);
        }

        return $customFields;
    }
}