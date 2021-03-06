<?php
namespace kr0lik\recource;

use Yii;
use yii\base\Model;
use yii\web\UploadedFile;
use yii\helpers\FileHelper;

class ResourceStrategy
{
    protected $model;
    protected $attribute;
    protected $relativeResourceFolder;
    protected $relativeTempFolder;

    private $hashLength = 6;
    private $oldResource;

    public function __construct(Model $model, string $attribute, string $relativeResourceFolder, string $relativeTempFolder)
    {
        $this->model = $model;
        $this->attribute = $attribute;
        $this->relativeResourceFolder = $relativeResourceFolder;
        $this->relativeTempFolder = $relativeTempFolder;
    }

    public function generateHash(): string
    {
        return md5(time() . uniqid());
    }

    public function getPathFromHash(string $hash): string
    {
        $hash = str_replace(DIRECTORY_SEPARATOR, '', $hash);
        $hash = substr($hash, 0, $this->hashLength);
        return DIRECTORY_SEPARATOR . join(DIRECTORY_SEPARATOR, str_split($hash, 2)) . DIRECTORY_SEPARATOR;
    }

    protected function getStorePath(): string
    {
        return DIRECTORY_SEPARATOR . trim($this->relativeResourceFolder, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    protected function getFolderPath(string $hash, bool $absolute = false): string
    {
        return ($absolute ? Yii::getAlias('@webroot') : '') . $this->getStorePath() . ltrim($this->getPathFromHash($hash), DIRECTORY_SEPARATOR);
    }

    protected function getTempPath(bool $absolute = false): string
    {
        return ($absolute ? Yii::getAlias('@webroot') : '') . DIRECTORY_SEPARATOR . trim($this->relativeTempFolder, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    protected function getResourceTempPath(bool $absolute = false): string
    {
        $resourceName = $this->getResourceName();

        return  $this->getTempPath($absolute) . trim(str_replace($this->getTempPath(), '', $resourceName), DIRECTORY_SEPARATOR);
    }

    protected function getResourceFolderPath(bool $absolute = false): string
    {
        $resourceName = $this->getResourceName();

        return  $this->getFolderPath($resourceName, $absolute) . $resourceName;
    }

    private function getResourceName(): string
    {
        if ($this->model->{$this->attribute} instanceof UploadedFile) {
            $this->uploadResource();
        }

        return $this->model->{$this->attribute};
    }

    private function isTemp(): bool
    {
        $resourceName = $this->getResourceName();

        return strpos($resourceName, trim($this->getTempPath(), DIRECTORY_SEPARATOR)) !== false;
    }

    public function getResourcePath(bool $absolute = false): string
    {
        if ($this->isTemp()) {
            return $this->getResourceTempPath($absolute);
        } else {
            return $this->getResourceFolderPath($absolute);
        }
    }

    public function uploadResource(): ?bool
    {
        $file = $this->model->{$this->attribute};

        if ($file instanceof UploadedFile) {
            $extension = $file->getExtension();
            $newFileName = $this->generateHash() . '.' . $extension;

            $tempFolder = $this->getTempPath(true);
                         
            if (! is_dir($tempFolder)) {
                FileHelper::createDirectory($tempFolder);
            }
            
            if ($file->saveAs($tempFolder . $newFileName)) {
                chmod($tempFolder . $newFileName, 0775);
                $this->model->{$this->attribute} = $this->getTempPath() . $newFileName;
                return true;
            } else {
                $this->model->addError($this->attribute, 'Cant save new file');
                return false;
            }
        } elseif ($this->isTemp()) {
            if (! file_exists($this->getResourcePath(true))) {
                $this->model->addError($this->attribute, 'No temp file');
                return false;
            }
        }

        return null;
    }

    public function saveResource(): ?bool
    {
        if ($this->uploadResource() !== false && $this->isTemp()) {
            $extension = pathinfo($this->getResourcePath(), PATHINFO_EXTENSION);
            $newFileName = $this->generateHash() . '.' . $extension;

            $newDir = $this->getFolderPath($newFileName, true);
            if (! is_dir($newDir)) {
                FileHelper::createDirectory($newDir);
            }

            if (file_exists($newDir . $newFileName)) {
                $existsFiles = glob($newDir . pathinfo($newFileName, PATHINFO_FILENAME) . '_*.' . $extension);
                $newFileName = pathinfo($newFileName, PATHINFO_FILENAME) . '_' . count($existsFiles) . '.' . $extension;
            }

            $newFilePath = $newDir . $newFileName;

            if (copy($this->getResourcePath(true), $newFilePath)) {
                $this->oldResource = $this->model->{$this->attribute};
                $this->model->{$this->attribute} = $newFileName;
                return true;
            } else {
                $this->model->addError($this->attribute, 'Cant move file from temp');
                return false;
            }
        }

        return null;
    }

    public function deleteResource(): ?bool
    {
        return $this->moveResourceToTemp($this->getResourceName());
    }

    public function deleteOldResource(): ?bool
    {
        $status = null;
        if ($this->oldResource) {
            if ($status = $this->moveResourceToTemp($this->oldResource)) $this->oldResource = null;
        }
        
        $model = $this->model;
        $oldAttributes = $model->getOldAttributes();
        $oldFile = isset($oldAttributes[$this->attribute]) && $oldAttributes[$this->attribute] ? $oldAttributes[$this->attribute] : null;

        if ($oldFile && $oldFile != $model->{$this->attribute}) {
            $status2 = $this->moveResourceToTemp($oldFile);
            
            if ($status !== false && $status2 !== null) $status = $status2;
        }

        return $status;
    }

    private function moveResourceToTemp(string $oldResourceName): ?bool
    {
        $status = null;
        
        $tempFolder = $this->getTempPath(true);
                         
        if (! is_dir($tempFolder)) {
            FileHelper::createDirectory($tempFolder);
        }

        $oldFilePath = $this->getFolderPath($oldResourceName, true) . $oldResourceName;
        if ($oldResourceName && file_exists($oldFilePath)) { 
            $newTempPath = $tempFolder . $oldResourceName;

            if (file_exists($newTempPath)) unlink($newTempPath);
            $status = @rename($oldFilePath, $newTempPath);
        }

        // Form images with filter in name
        $filteredImages = glob($this->getFolderPath($oldResourceName, true) . pathinfo($oldResourceName, PATHINFO_FILENAME) . '.*.' . pathinfo($oldResourceName, PATHINFO_EXTENSION));
        foreach ($filteredImages as $image) {
            $newTempPath = $tempFolder . $image;

            if (file_exists($newTempPath)) unlink($newTempPath);
            @rename($image, $newTempPath);
        }

        return $status;
    }
}
