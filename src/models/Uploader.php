<?php
namespace fruitstudios\assetup\models;

use fruitstudios\assetup\AssetUp;
use fruitstudios\assetup\helpers\AssetUpHelper;
use fruitstudios\assetup\assetbundles\assetup\AssetUpAssetBundle;

use Craft;
use craft\web\View;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\base\VolumeInterface;
use craft\base\Model;
use craft\models\VolumeFolder;
use craft\helpers\Json as JsonHelper;

class Uploader extends Model
{

    // Constants
    // =========================================================================

    const TARGET_FIELD = 'field';
    const TARGET_FOLDER = 'folder';

    // Private
    // =========================================================================

    private $_target;
    private $_field;
    private $_element;
    private $_folder;

    private $_defaultAllowedFileExtensions;
    private $_defaultMaxUploadFileSize;
    private $_defaultJavascriptVariables;

    private $_javascriptProperties = [
        'id',
        'name',
        'target',
        'preview',
        'transform',
        'limit',
        'maxSize',
        'allowedFileExtensions',
        'enableDropToUpload',
        'enableReorder',
        'enableRemove',
    ];

    // Public
    // =========================================================================

    // ID
    public $id;

    // Name - name | null
    public $name;

    // Assets - Asset[] | null
    public $assets;

    // Field - id | handle | Field | null
    public $field;

    // Element - id | Element | null
    public $element;

    // Volume - id | handle | null
    public $volume;

    // Folder - id | path
    public $folder;

    // Uploader Layout
    public $layout = 'grid'; // grid | compact-grid | list

    // Preview
    public $preview = 'file'; // file | background | img

    // Settings
    public $enableDropToUpload = true;
    public $enableReorder = true;
    public $enableRemove = true;

    // Css
    public $customClass;
    public $themeColour = '#000000';

    // Language
    public $selectText;
    public $dropText;

    // Asset
    public $transform = '';
    public $limit;
    public $maxSize;
    public $allowedFileExtensions = [];


    // Public Methods
    // =========================================================================

    public function __construct(array $attributes = [])
    {
        // Defualts
        $this->_defaultJavascriptVariables = [
            'csrfTokenName' => Craft::$app->getConfig()->getGeneral()->csrfTokenName,
            'csrfTokenValue' => Craft::$app->getRequest()->getCsrfToken(),
        ];
        $this->_defaultAllowedFileExtensions = Craft::$app->getConfig()->getGeneral()->allowedFileExtensions;
        $this->_defaultMaxUploadFileSize = Craft::$app->getConfig()->getGeneral()->maxUploadFileSize;


        // Settings
        $this->id = uniqid('assetup');
        $this->selectText = Craft::t('assetup', 'Select files');
        $this->dropText = Craft::t('assetup', 'drop files here');
        $this->maxSize = $this->_defaultMaxUploadFileSize;
        $this->allowedFileExtensions = $this->_defaultAllowedFileExtensions;

        $this->setAttributes($attributes, false);
    }

    public function render()
    {
        $this->validate();

        $view = Craft::$app->getView();
        $view->registerAssetBundle(AssetUpAssetBundle::class);
        $view->registerJs('new AssetUp('.$this->_getJavascriptVariables().');', View::POS_END);

        $view->registerCss($this->_getCustomCss());

        return AssetUpHelper::renderTemplate('assetup/uploader', [
            'uploader' => $this
        ]);
    }

    public function rules()
    {

        // TODO: Should target use this for validation: https://www.yiiframework.com/doc/guide/2.0/en/tutorial-core-validators#filter

        $rules = parent::rules();
        $rules[] = [['id'], 'required'];
        $rules[] = [['target'], 'required', 'message' => Craft::t('assetup', 'A valid target field, volume or folder must be defined.')];
        $rules[] = [['maxSize'], 'integer', 'max' => $this->_defaultMaxUploadFileSize, 'message' => Craft::t('assetup', 'Max file cant be greater than the global setting maxUploadFileSize')];
        return $rules;
    }

    public function beforeValidate()
    {
        if($this->_validFieldTarget())
        {
            $this->_setTarget(self::TARGET_FIELD);
        }
        elseif($this->_validFolderTarget())
        {
            $this->_setTarget(self::TARGET_FOLDER);
        }

        return parent::beforeValidate();
    }

    public function afterValidate()
    {
        $target = $this->getTarget();
        switch ($target['type'])
        {
            case self::TARGET_FIELD:

                $this->limit = (int) ($this->_field->limit ? $this->_field->limit : null);
                $this->allowedFileExtensions = AssetUpHelper::getAllowedFileExtensionsByFieldKinds($this->_field->allowedKinds);

                if(!$this->name)
                {
                    $this->name = 'fields['.$this->_field->handle.']';
                }

                $this->enableReorder = true;
                $this->enableRemove = true;

                break;

            case self::TARGET_FOLDER:
                $this->enableReorder = false;
                $this->enableRemove = false;
                break;
        }



        return parent::afterValidate();
    }

    public function getTarget()
    {
        return $this->_target;
    }

    // Private Methods
    // =========================================================================

    private function _setTarget($type)
    {
        $target = [ 'type' => $type ];
        switch ($type)
        {
            case self::TARGET_FIELD:
                $target['fieldId'] = $this->_field->id ?? null;
                $target['elementId'] = $this->_element->id ?? null;
                break;

            case self::TARGET_FOLDER:
                $target['folderId'] = $this->_folder->id ?? null;
                break;
        }
        $this->_target = $target;
    }

    private function _getJavascriptVariables(bool $encode = true)
    {
        $settings = $this->_defaultJavascriptVariables;
        foreach ($this->_javascriptProperties as $property)
        {
            $settings[$property] = $this->$property;
        }

        if(Craft::$app->getConfig()->getGeneral()->devMode)
        {
            $settings['debug'] = true;
        }

        return $encode ? JsonHelper::encode($settings) : $settings;
    }

    private function _getCustomCss()
    {
      $css = '
        .assetup--isLoading:after { border-color: '.$this->themeColour.'; }
        .assetup--label { background-color: '.$this->themeColour.'; }
        .assetup--btn { color: '.$this->themeColour.'; }
      ';

      return $css;
    }


    private function _validFieldTarget()
    {
        // Field set as target
        if($this->field)
        {
            // Ok so an element is required then
            if(is_null($this->element))
            {
                $this->addError('field', Craft::t('assetup', 'A valid element is required when a using a field as your asset target.'));
                return false;
            }

            // Element provided lets check it
            $element = $this->element instanceof ElementInterface ? $this->element : false;
            if(!$element)
            {
                $element = Craft::$app->getElements()->getElementById((int) $this->element);
            }

            // Element is a duffer
            if(!$element)
            {
                $this->addError('element', Craft::t('assetup', 'Could not locate your element.'));
                return false;
            }

            // Store the element
            $this->_element = $element;

            // Got an element lets check the field
            $field = $this->field instanceof FieldInterface ? $this->field : false;
            if(!$field)
            {
                $field = AssetUp::$plugin->service->getAssetFieldByHandleOrId($this->field);
            }

            // Field is a duffer
            if(!$field)
            {
                $this->addError('field', Craft::t('assetup', 'Could not locate your field.'));
                return false;
            }

            // Store the field
            $this->_field = $field;
            return true;
        }
        return false;
    }

    private function _validFolderTarget()
    {
        // Volume and / or folder set as target
        if($this->volume || $this->folder)
        {
            // Folder model supplied
            if($this->folder instanceof VolumeFolder)
            {
                $this->_folder = $this->folder;
                return true;
            }

            // Folder id supplied
            if(is_numeric($this->folder))
            {
                $folder = Craft::$app->getAssets()->getFolderById((int) $this->folder);

                // Folder is a duffer
                if(!$folder)
                {
                    $this->addError('folder', Craft::t('assetup', 'We cant locate any folder by the id supplied.'));
                    return false;
                }

                // We have a folder
                $this->_folder = $this->folder;
                return true;
            }


            // Get supplied volume
            $volume = $this->volume instanceof VolumeInterface ? $this->volume : false;
            if(!$volume)
            {
                $volume = AssetUp::$plugin->service->getVolumeByHandleOrId($this->volume);
            }

            // Volume is a duffer
            if(!$volume)
            {
                $this->addError('volume', Craft::t('assetup', 'We cant get a volume to work with.'));
                return false;

                // IDEA: Do we want to grab the first if nothing supplied
                // if(!$targetVolume)
                // {
                //     $targetVolume = AssetUp::$plugin->service->getFirstViewableVolume();
                // }
            }

            // We must have volume,
            if(is_string($this->folder))
            {
                // if the foldler is a path does it exist
                $folder = Craft::$app->getAssets()->ensureFolderByFullPathAndVolume($this->folder, $volume, false);
                if(!$folder)
                {
                    $this->addError('folder', Craft::t('assetup', 'We cant find the folder path in the volume supplied.'));
                    return false;
                }

                // We have a folder
                $this->_folder = $this->folder;
                return true;
            }
            else
            {
                // Get volume top folder id
                $folderId = Craft::$app->getVolumes()->ensureTopFolder($volume);
                $folder = Craft::$app->getAssets()->getFolderById($folderId);
                if(!$folder)
                {
                    $this->addError('folder', Craft::t('assetup', 'We cant get or create the top folder for the volume you supplied.'));
                    return false;
                }

                // We have a folder
                $this->_folder = $folder;
                return true;
            }
        }
        return false;

    }
}
