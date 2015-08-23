<?php
/**
 * Created by PhpStorm.
 * User: Nicolas
 * Date: 6/02/15
 * Time: 19:20
 */

namespace flydreamers\db;


use yii\base\Behavior;
use yii\base\Exception;
use yii\db\ActiveRecord;

/**
 * Class TranslateBehavior
 * Allows to save and edit translations of active records
 *
 * @property ActiveRecord $owner
 */
class TranslateBehavior extends Behavior
{

    /**
     * @see Behavior::events()
     */
    public function events()
    {
        return [
            ActiveRecord::EVENT_AFTER_FIND => 'initTranslations', //Initialize the translations
            ActiveRecord::EVENT_AFTER_INSERT => 'saveTranslation', //Save the changes
            ActiveRecord::EVENT_AFTER_UPDATE => 'saveTranslation', //Save the changes
            ActiveRecord::EVENT_AFTER_DELETE => 'deleteTranslations', //Delete translations
        ];
    }

    /**
     * The attributes that need translation
     * @var array
     */
    public $translationAttributes = [];

    /**
     * Enabled translation attributes
     * @var array
     */
    public $translationLanguages = ['es', 'en'];

    /**
     * Holds current language descriptions, based on language codes
     * @var array
     */
    public $langDescription = [
        'es' => 'Español',
        'en' => 'English'
    ];

    /**
     * The relation that get all related items
     * @var string
     */
    public $translationRelation = 'translations';

    /**
     * Current language
     * @var string
     */
    public $_currentLanguage;

    /**
     * The language field in the related table
     * @var string
     */
    public $languageField = 'language';

    /**
     * Holds all translations models
     * @var array
     */
    private $_translated;

    /**
     * Checks if the current $attribute of the instance is in the list of available languages for the model
     * @param string $attribute the attribute name to validate
     * @param [] $params extra parameters for the function. Not used.
     */
    public function checkLanguage($attribute, $params)
    {
        if (!in_array($this->$attribute, $this->translationLanguages)) {
            $this->owner->addError($attribute, \Yii::t('app', 'The language {lang} is not in the list of available languages.',
                ['lang' => $this->$attribute]));
        }
    }

    /**
     * Sets the current language for the element
     * @param null|string $lang the language to set attributes in. if null, current application language will be considered
     */
    public function setCurrentLanguage($lang = null)
    {
        $this->_currentLanguage = $lang === null ? substr(\Yii::$app->language, 0, 2) : $lang;
        $this->setAttributesInLanguage($this->_currentLanguage);
    }

    /**
     * @inheritdoc
     */
    public function initTranslations()
    {
        if (!isset($this->_translated)) {
            $translation = null;
            /** @var \yii\db\ActiveQuery $relation */
            $relation = $this->owner->getRelation($this->translationRelation);
            /** @var ActiveRecord $class */
            $class = $relation->modelClass;
            foreach($this->translationLanguages as $lang){
                if ($this->owner->getPrimarykey()) {
                    $translation = $class::findOne(
                        [$this->languageField => $lang, key($relation->link) => $this->owner->getPrimarykey()]
                    );

                    if ($translation != null) {
                        $this->_translated[$lang] = $translation;
                    }
                }
            }
        }

        if (!isset($this->_currentLanguage)) {
            $this->setCurrentLanguage();
        }
    }

    /**
     * Returns the current language description
     * @return string
     */
    public function getCurrentLangDescription()
    {
        return $this->getLanguageDescription($this->_currentLanguage);
    }

    /**
     * Returns the current language description for the parameter language code
     * @param string $lang the language code
     * @return string the language description
     * @throws \yii\base\Exception if the language description is not set in static::$langDescription
     */
    public function getLanguageDescription($lang)
    {
        if (isset($this->langDescription[$lang])) {
            return $this->langDescription[$lang];
        }
        throw new Exception(\Yii::t('app', 'Language description for {lc} not set.', ['lc' => $lang]));
    }

    /**
     * Returns all possible languages for the instance
     * @return array
     */
    public function getAvailableLanguages()
    {
        return $this->translationLanguages;
    }

    /**
     * Sets the current instance attributes in the language specified
     * @param null|string $lang the language to set attributes in. if null, current application language will be considered
     * @throws Exception if the language is not valid for the current model
     */
    public function setAttributesInLanguage($lang = null)
    {
        if ($lang === null) {
            $lang = substr(\Yii::$app->language, 0, 2);
        }

        if (!in_array($lang, $this->translationLanguages)) {
            throw new Exception(\Yii::t('app', 'Invalid language {lang}.', ['lang' => $lang]));
        }

        $this->initTranslations();
        foreach ($this->translationAttributes as $attribute) {
            $this->owner->$attribute = null;
            if (isset($this->_translated[$lang][$attribute])) {
                $this->owner->$attribute = $this->_translated[$lang][$attribute];
            }
        }
    }

    /**
     * Saves current attributes as the language of the class
     * @return bool
     */
    public function saveTranslation()
    {
        if ($this->_currentLanguage === null) {
            $this->_currentLanguage = substr(\Yii::$app->language, 0, 2);
        }

        $translation = null;
        $relation = $this->owner->getRelation($this->translationRelation);
        /** @var ActiveRecord $class */
        $class = $relation->modelClass;
        if ($this->owner->getPrimarykey()) {
            $translation = $class::findOne(
                [$this->languageField => $this->_currentLanguage, key($relation->link) => $this->owner->getPrimarykey()]
            );
        }
        if ($translation === null) {
            $translation = new $class;
            $translation->{key($relation->link)} = $this->owner->getPrimaryKey();
            $translation->{$this->languageField} = $this->_currentLanguage;
        }

        foreach($this->translationAttributes as $attribute){
            $translation->$attribute = $this->owner->$attribute;
        }

        return $translation->save();
    }

    /**
     * Deletes related translations
     */
    public function deleteTranslations()
    {
        $relation = $this->owner->getRelation($this->translationRelation);
        /** @var ActiveRecord $class */
        $class = $relation->modelClass;
        $class::deleteAll(key($relation->link).' = :id', [':id'=>$this->owner->getPrimaryKey()]);
    }

    /**
     * Returns the item language status. Can have four possible outcomes
     *      false: the language code is not valid for the model
     *      'complete': all language attributes are set
     *      'incomplete': some language attributes are set
     *      'not-set': none of the language attributes are set
     * @param string $lang the language to check the attributes for
     * @param bool $icon whether to return the output as an icon or as text
     * @return false|string
     * @throws Exception
     */
    public function getLanguageStatus($lang, $icon = false)
    {
        if ($icon) {
            throw new Exception('IMPLEMENT');
        }
        if (in_array($lang, $this->getAvailableLanguages())) {
            $oldLang = $this->_currentLanguage;
            $this->setCurrentLanguage($lang);
            $check = 0;
            foreach ($this->translationAttributes as $attribute) {
                if (!isset($this->owner->$attribute) || strlen($this->owner->$attribute) == 0)
                    continue;
                $check++;
            }

            $this->setCurrentLanguage($oldLang);

            if ($check == 0) {
                return 'not-set';
            }

            if ($check == count($this->translationAttributes)) {
                return 'complete';
            }

            return 'incomplete';
        }
        return false;
    }

} 
