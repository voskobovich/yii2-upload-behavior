<?php

namespace voskobovich\behaviors;

use Closure;
use Yii;
use yii\base\Behavior;
use yii\base\InvalidConfigException;
use yii\base\InvalidParamException;
use yii\db\BaseActiveRecord;
use yii\helpers\FileHelper;
use yii\web\UploadedFile;

/**
 * UploadBehavior automatically uploads file and fills the specified attribute
 * with a value of the name of the uploaded file.
 *
 * To use UploadBehavior, insert the following code to your ActiveRecord class:
 *
 * ```php
 * function behaviors()
 * {
 *     return [
 *         [
 *             'class' => \voskobovich\behaviors\UploadBehavior::className(),
 *             'attribute' => 'file',
 *             'path' => '@webroot/upload/{id}',
 *             'url' => '@web/upload/{id}',
 *         ],
 *     ];
 * }
 * ```
 *
 * @author Vitaly Voskobovich <vitaly@voskobovich.com>
 */
class UploadBehavior extends Behavior
{
    /**
     * @event Event an event that is triggered after a file is uploaded.
     */
    const EVENT_AFTER_UPLOAD = 'afterUpload';

    /**
     * @var string the attribute which holds the attachment.
     */
    public $attribute;
    /**
     * @var string the base path or path alias to the directory in which to save files.
     */
    public $path;
    /**
     * @var string the base URL or path alias for this file
     */
    public $url;
    /**
     * @var boolean|callable generate a new unique name for the file
     * set true or anonymous function takes the old filename and returns a new name.
     * @see self::generateFileName()
     */
    public $generateNewName = true;
    /**
     * @var boolean If `true` current attribute file will be deleted
     */
    public $unlinkOnSave = true;
    /**
     * @var boolean If `true` current attribute file will be deleted after model deletion.
     */
    public $unlinkOnDelete = true;

    /**
     * @var UploadedFile the uploaded file instance.
     */
    public $file;

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();

        if ($this->attribute === null) {
            throw new InvalidConfigException('The "attribute" property must be set.');
        }
        if ($this->path === null) {
            throw new InvalidConfigException('The "path" property must be set.');
        }
        if ($this->url === null) {
            throw new InvalidConfigException('The "url" property must be set.');
        }
    }

    /**
     * @inheritdoc
     */
    public function events()
    {
        return [
            BaseActiveRecord::EVENT_BEFORE_VALIDATE => 'beforeValidate',
            BaseActiveRecord::EVENT_BEFORE_INSERT => 'beforeSave',
            BaseActiveRecord::EVENT_BEFORE_UPDATE => 'beforeSave',
            BaseActiveRecord::EVENT_AFTER_INSERT => 'afterSave',
            BaseActiveRecord::EVENT_AFTER_UPDATE => 'afterSave',
            BaseActiveRecord::EVENT_BEFORE_DELETE => 'beforeDelete',
        ];
    }

    /**
     * This method is invoked before validation starts.
     */
    public function beforeValidate()
    {
        /** @var BaseActiveRecord $model */
        $model = $this->owner;

        $this->file = UploadedFile::getInstance($model, 'file');
        if ($this->file instanceof UploadedFile) {
            $this->file->name = $this->getFileName($this->file);
        }
    }

    /**
     * This method is called at the beginning of inserting or updating a record.
     */
    public function beforeSave()
    {
        /** @var BaseActiveRecord $model */
        $model = $this->owner;
        if ($this->file instanceof UploadedFile) {
            if (!$model->getIsNewRecord() && $this->unlinkOnSave === true) {
                $this->delete($this->attribute, true);
            }
            $model->setAttribute($this->attribute, $this->file->name);
        }
    }

    /**
     * This method is called at the end of inserting or updating a record.
     * @throws \yii\base\InvalidParamException
     */
    public function afterSave()
    {
        if ($this->file instanceof UploadedFile) {
            $path = $this->getUploadPath($this->attribute);
            if (!FileHelper::createDirectory(dirname($path))) {
                throw new InvalidParamException("Directory specified in 'path' attribute doesn't exist or cannot be created.");
            }
            $this->file->saveAs($path);
            $this->afterUpload();
        }
    }

    /**
     * This method is invoked before deleting a record.
     */
    public function beforeDelete()
    {
        $attribute = $this->attribute;
        if ($this->unlinkOnDelete && $attribute) {
            $this->delete($attribute);
        }
    }

    /**
     * Returns file path for the attribute.
     *
     * @param string $attribute
     * @param boolean $old
     *
     * @return string the file path.
     */
    public function getUploadPath($attribute, $old = false)
    {
        /** @var BaseActiveRecord $model */
        $model = $this->owner;
        $path = $this->resolvePath($this->path);
        $fileName = ($old === true) ? $model->getOldAttribute($attribute) : $model->$attribute;

        return $fileName ? Yii::getAlias($path . '/' . $fileName) : null;
    }

    /**
     * Returns file url for the attribute.
     *
     * @param string $attribute
     * @return string|null
     */
    public function getFileUrl($attribute)
    {
        /** @var BaseActiveRecord $model */
        $model = $this->owner;
        $url = $this->resolvePath($this->url);
        $fileName = $model->getOldAttribute($attribute);

        return $fileName ? Yii::getAlias($url . '/' . $fileName) : null;
    }

    /**
     * Checked has file in model
     *
     * @param $attribute
     * @return bool
     */
    public function hasFile($attribute)
    {
        /** @var BaseActiveRecord $model */
        $model = $this->owner;
        $fileName = $model->getOldAttribute($attribute);
        return !empty($fileName);
    }

    /**
     * Replaces all placeholders in path variable with corresponding values.
     * @param $path
     *
     * @return mixed
     */
    protected function resolvePath($path)
    {
        /** @var BaseActiveRecord $model */
        $model = $this->owner;
        return preg_replace_callback('/{([^}]+)}/', function ($matches) use ($model) {
            $name = $matches[1];
            $attribute = $model->getAttribute($name);
            if (is_string($attribute) || is_numeric($attribute)) {
                return $attribute;
            } else {
                return $matches[0];
            }
        }, $path);
    }

    /**
     * Deletes old file.
     * @param string $attribute
     * @param boolean $old
     */
    protected function delete($attribute, $old = false)
    {
        $path = $this->getUploadPath($attribute, $old);
        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * @param UploadedFile $file
     * @return string
     */
    protected function getFileName($file)
    {
        if ($this->generateNewName) {
            return $this->generateNewName instanceof Closure
                ? call_user_func($this->generateNewName, $file)
                : $this->generateFileName($file);
        } else {
            return $this->sanitize($file->name);
        }
    }

    /**
     * Replaces characters in strings that are illegal/unsafe for filename.
     *
     * #my*  unsaf<e>&file:name?".png
     *
     * @param string $filename the source filename to be "sanitized"
     * @return boolean string the sanitized filename
     */
    public static function sanitize($filename)
    {
        return str_replace([' ', '"', '\'', '&', '/', '\\', '?', '#'], '-', $filename);
    }

    /**
     * Generates random filename.
     * @param UploadedFile $file
     * @return string
     */
    protected function generateFileName($file)
    {
        return uniqid() . '.' . $file->extension;
    }

    /**
     * This method is invoked after uploading a file.
     * The default implementation raises the [[EVENT_AFTER_UPLOAD]] event.
     * You may override this method to do postprocessing after the file is uploaded.
     * Make sure you call the parent implementation so that the event is raised properly.
     */
    protected function afterUpload()
    {
        $this->owner->trigger(self::EVENT_AFTER_UPLOAD);
    }
}
