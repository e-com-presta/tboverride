<?php

class ImageManager extends ImageManagerCore
{
    public static function resize(
        $srcFile,
        $dstFile,
        $dstWidth = null,
        $dstHeight = null,
        $fileType = 'jpg',
        $forceType = false,
        &$error = 0,
        &$tgtWidth = null,
        &$tgtHeight = null,
        $quality = 5,
        &$srcWidth = null,
        &$srcHeight = null
    ) {
        clearstatcache(true, $srcFile);
        if (!file_exists($srcFile) || !filesize($srcFile)) {
            return !($error = static::ERROR_FILE_NOT_EXIST);
        }
        list($tmpWidth, $tmpHeight, $type) = getimagesize($srcFile);
        $rotate = 0;
        if (function_exists('exif_read_data')) {
            if ($fileType != 'jpg') {
                $exif = [];
            } else {
                $exif = @exif_read_data($srcFile);
            }
            if ($exif && isset($exif['Orientation'])) {
                switch ($exif['Orientation']) {
                    case 3:
                        $srcWidth = $tmpWidth;
                        $srcHeight = $tmpHeight;
                        $rotate = 180;
                        break;
                    case 6:
                        $srcWidth = $tmpHeight;
                        $srcHeight = $tmpWidth;
                        $rotate = -90;
                        break;
                    case 8:
                        $srcWidth = $tmpHeight;
                        $srcHeight = $tmpWidth;
                        $rotate = 90;
                        break;
                    default:
                        $srcWidth = $tmpWidth;
                        $srcHeight = $tmpHeight;
                }
            } else {
                $srcWidth = $tmpWidth;
                $srcHeight = $tmpHeight;
            }
        } else {
            $srcWidth = $tmpWidth;
            $srcHeight = $tmpHeight;
        }
        if ($fileType !== 'webp' && (Configuration::get('PS_IMAGE_QUALITY') == 'png_all'
            || (Configuration::get('PS_IMAGE_QUALITY') == 'png' && $type == IMAGETYPE_PNG) && !$forceType)) {
            $fileType = 'png';
        }
        if (!$srcWidth) {
            return !($error = static::ERROR_FILE_WIDTH);
        }
        $srcWidth = (int) $srcWidth;
        $srcHeight = (int) $srcHeight;
        if (!$dstWidth) {
            $dstWidth = $srcWidth;
        }
        if (!$dstHeight) {
            $dstHeight = $srcHeight;
        }
        $widthDiff = $dstWidth / $srcWidth;
        $heightDiff = $dstHeight / $srcHeight;
        $psImageGenerationMethod = Configuration::get('PS_IMAGE_GENERATION_METHOD');
        if ($widthDiff > 1 && $heightDiff > 1) {
            $nextWidth = $srcWidth;
            $nextHeight = $srcHeight;
        } else {
            if ($psImageGenerationMethod == 2 || (!$psImageGenerationMethod && $widthDiff > $heightDiff)) {
                $nextHeight = (int) $dstHeight;
                $nextWidth = (int) round(($srcWidth * $nextHeight) / $srcHeight);
                $dstWidth = (int) (!$psImageGenerationMethod ? $dstWidth : $nextWidth);
            } else {
                $nextWidth = (int) $dstWidth;
                $nextHeight = (int) round($srcHeight * $dstWidth / $srcWidth);
                $dstHeight = (int) (!$psImageGenerationMethod ? $dstHeight : $nextHeight);
            }
        }
        if (!ImageManager::checkImageMemoryLimit($srcFile)) {
            return !($error = static::ERROR_MEMORY_LIMIT);
        }
        $dstWidth = (int) $dstWidth;
        $dstHeight = (int) $dstHeight;
        $tgtWidth = $dstWidth;
        $tgtHeight = $dstHeight;
        $destImage = imagecreatetruecolor($dstWidth, $dstHeight);
        if ($fileType == 'png' && $type == IMAGETYPE_PNG || $fileType === 'webp') {
            imagealphablending($destImage, false);
            imagesavealpha($destImage, true);
            $transparent = imagecolorallocatealpha($destImage, 255, 255, 255, 127);
            imagefilledrectangle($destImage, 0, 0, $dstWidth, $dstHeight, $transparent);
        } else {
            $white = imagecolorallocate($destImage, 255, 255, 255);
            imagefilledrectangle($destImage, 0, 0, $dstWidth, $dstHeight, $white);
        }
        $srcImage = ImageManager::create($type, $srcFile);
        if ($rotate) {
            $srcImage = imagerotate($srcImage, $rotate, 0);
        }
        if ($dstWidth >= $srcWidth && $dstHeight >= $srcHeight) {
            imagecopyresized($destImage, $srcImage, (int) (($dstWidth - $nextWidth) / 2), (int) (($dstHeight - $nextHeight) / 2), 0, 0, $nextWidth, $nextHeight, $srcWidth, $srcHeight);
        } else {
            imagecopyresampled($destImage, $srcImage, (int) (($dstWidth - $nextWidth) / 2), (int) (($dstHeight - $nextHeight) / 2), 0, 0, $nextWidth, $nextHeight, $srcWidth, $srcHeight);
        }
        $writeFile = ImageManager::write($fileType, $destImage, $dstFile);
        @imagedestroy($srcImage);
        file_put_contents(dirname($dstFile).DIRECTORY_SEPARATOR.'fileType', $fileType);
        return $writeFile;
    }

    public static function create($type, $filename)
    {
        switch ($type) {
            case IMAGETYPE_GIF:
                return imagecreatefromgif($filename);
            case IMAGETYPE_PNG:
                return imagecreatefrompng($filename);
            case IMAGETYPE_WEBP:
                return imagecreatefromwebp($filename);
            case IMAGETYPE_JPEG:
            default:
                return imagecreatefromjpeg($filename);
        }
    }

    public static function write($type, $resource, $filename)
    {
        static $psPngQuality = null;
        static $psJpegQuality = null;
        static $psWebpQuality = null;
        if ($psPngQuality === null) {
            $psPngQuality = Configuration::get('PS_PNG_QUALITY');
        }
        if ($psJpegQuality === null) {
            $psJpegQuality = Configuration::get('PS_JPEG_QUALITY');
        }
        if ($psWebpQuality === null) {
            $psWebpQuality = Configuration::get('TB_WEBP_QUALITY');
        }
        switch ($type) {
            case 'gif':
                $success = imagegif($resource, $filename);
                break;
            case 'png':
                $quality = ($psPngQuality === false ? 9 : $psPngQuality);
                $success = imagepng($resource, $filename, (int) $quality);
                break;
            case 'webp':
                $quality = ($psWebpQuality === false ? 80 : $psWebpQuality);
                $success = imagewebp($resource, $filename, (int) $quality);
                break;
            case 'jpg':
            case 'jpeg':
            default:
                $quality = ($psJpegQuality === false ? 90 : $psJpegQuality);
                imageinterlace($resource, 1);
                $success = imagejpeg($resource, $filename, (int) $quality);
                break;
        }
        imagedestroy($resource);
        @chmod($filename, 0664);
        return $success;
    }

    public static function validateUpload($file, $maxFileSize = 0, $types = null)
    {
        if ((int) $maxFileSize > 0 && $file['size'] > (int) $maxFileSize) {
            return sprintf(Tools::displayError('Image is too large (%1$d kB). Maximum allowed: %2$d kB'), $file['size'] / 1024, $maxFileSize / 1024);
        }
        if (!ImageManager::isRealImage($file['tmp_name'], $file['type']) || !ImageManager::isCorrectImageFileExt($file['name'], $types) || preg_match('/%00/', $file['name'])) {
            return Tools::displayError('Image format not recognized, allowed formats are: .gif, .jpg, .jpeg, .jpe, .png, .webp');
        }
        if ($file['error']) {
            return sprintf(Tools::displayError('Error while uploading image; please change your server\'s settings. (Error code: %s)'), $file['error']);
        }
        return false;
    }

    public static function isRealImage($filename, $fileMimeType = null, $mimeTypeList = null)
    {
        $mimeType = false;
        if (!$mimeTypeList) {
            $mimeTypeList = [
                'image/gif',
                'image/jpg',
                'image/jpeg',
                'image/pjpeg',
                'image/png',
                'image/x-png',
                'image/webp'
            ];
        }
        $mimeType = static::getMimeType($filename);
        if ($fileMimeType && (empty($mimeType) || $mimeType == 'regular file' || $mimeType == 'text/plain')) {
            $mimeType = $fileMimeType;
        }
        foreach ($mimeTypeList as $type) {
            if (strstr($mimeType, $type)) {
                return true;
            }
        }
        return false;
    }

    public static function isCorrectImageFileExt($filename, $authorizedExtensions = null)
    {
        if ($authorizedExtensions === null) {
            $authorizedExtensions = ['gif', 'jpg', 'jpeg', 'jpe', 'png', 'webp'];
        }
        $nameExplode = explode('.', $filename);
        if (count($nameExplode) >= 2) {
            $currentExtension = strtolower($nameExplode[count($nameExplode) - 1]);
            if (!in_array($currentExtension, $authorizedExtensions)) {
                return false;
            }
        } else {
            return false;
        }
        return true;
    }

    public static function cut($srcFile, $dstFile, $dstWidth = null, $dstHeight = null, $fileType = 'jpg', $dstX = 0, $dstY = 0)
    {
        if (!file_exists($srcFile)) {
            return false;
        }
        $srcInfo = getimagesize($srcFile);
        $src = [
            'width' => $srcInfo[0],
            'height' => $srcInfo[1],
            'ressource' => ImageManager::create($srcInfo[2], $srcFile),
        ];
        $dest = [];
        $dest['x'] = $dstX;
        $dest['y'] = $dstY;
        $dest['width'] = !is_null($dstWidth) ? $dstWidth : $src['width'];
        $dest['height'] = !is_null($dstHeight) ? $dstHeight : $src['height'];
        $dest['ressource'] = ImageManager::createWhiteImage($dest['width'], $dest['height']);
        $white = imagecolorallocate($dest['ressource'], 255, 255, 255);
        imagecopyresampled($dest['ressource'], $src['ressource'], 0, 0, $dest['x'], $dest['y'], $dest['width'], $dest['height'], $dest['width'], $dest['height']);
        imagecolortransparent($dest['ressource'], $white);
        $return = ImageManager::write($fileType, $dest['ressource'], $dstFile);
        @imagedestroy($src['ressource']);
        return $return;
    }

    public static function createWhiteImage($width, $height)
    {
        $image = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $white);
        return $image;
    }

    public static function getMimeTypeByExtension($fileName)
    {
        $types = [
            'image/gif' => ['gif'],
            'image/jpeg' => ['jpg', 'jpeg', 'jpe'],
            'image/png' => ['png'],
            'image/webp' => ['webp'],
        ];
        $extension = substr($fileName, strrpos($fileName, '.') + 1);
        $mimeType = null;
        foreach ($types as $mime => $exts) {
            if (in_array($extension, $exts)) {
                $mimeType = $mime;
                break;
            }
        }
        if ($mimeType === null) {
            $mimeType = 'image/jpeg';
        }
        return $mimeType;
    }

    public static function getMimeType(string $filename)
    {
        $mimeType = false;
        if (function_exists('getimagesize')) {
            $imageInfo = @getimagesize($filename);
            if ($imageInfo) {
                $mimeType = $imageInfo['mime'];
            }
        }
        if (!$mimeType && function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $filename);
            finfo_close($finfo);
        }
        if (!$mimeType && function_exists('mime_content_type')) {
            $mimeType = mime_content_type($filename);
        }
        if (!$mimeType && function_exists('exec')) {
            $mimeType = trim(exec('file -b --mime-type '.escapeshellarg($filename)));
            if (!$mimeType) {
                $mimeType = trim(exec('file --mime '.escapeshellarg($filename)));
            }
            if (!$mimeType) {
                $mimeType = trim(exec('file -bi '.escapeshellarg($filename)));
            }
        }
        return $mimeType;
    }
}
