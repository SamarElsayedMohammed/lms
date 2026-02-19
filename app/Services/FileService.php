<?php

namespace App\Services;

use App\Models\Setting;
use Exception;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;
use RuntimeException;

class FileService
{
    /**
     * @param $requestFile
     * @param $folder
     * @return string
     */
    public static function compressAndUpload($requestFile, $folder)
    {
        $file_name = uniqid('', true) . time() . '.' . $requestFile->getClientOriginalExtension();
        if (in_array($requestFile->getClientOriginalExtension(), ['jpg', 'jpeg', 'png'])) {
            // Check the Extension should be jpg or png and do compression
            $image = Image::make($requestFile)->encode(null, 60);
            Storage::disk('public')->put($folder . '/' . $file_name, $image);
        } else {
            // Else assign file as it is
            $file = $requestFile;
            $file->storeAs($folder, $file_name, 'public');
        }
        return $folder . '/' . $file_name;
    }

    /**
     * @param $requestFile
     * @param $folder
     * @return string
     */
    public static function upload($requestFile, $folder)
    {
        $file_name = uniqid('', true) . time() . '.' . $requestFile->getClientOriginalExtension();
        $requestFile->storeAs($folder, $file_name, 'public');
        return $folder . '/' . $file_name;
    }

    /**
     * @param $requestFile
     * @param $folder
     * @param $deleteRawOriginalImage
     * @return string
     */
    public static function replace($requestFile, $folder, $deleteRawOriginalImage)
    {
        self::delete($deleteRawOriginalImage);
        return self::upload($requestFile, $folder);
    }

    /**
     * @param $path
     * @return bool
     */
    public static function checkFileExists($path)
    {
        if (empty($path)) {
            return false;
        }

        // If path starts with /storage/, remove it for Storage::fileExists check
        $storagePath = str_starts_with((string) $path, '/storage/') ? substr((string) $path, 9) : $path;

        // Check in public disk for web-accessible files
        return Storage::disk('public')->exists($storagePath);
    }

    /**
     * @param $path
     * @return string
     */
    public static function getFilePath($path)
    {
        return Storage::path($path);
    }

    /**
     * @param $requestFile
     * @param $folder
     * @param $deleteRawOriginalImage
     * @return string
     */
    public static function compressAndReplace($requestFile, $folder, $deleteRawOriginalImage)
    {
        if (!empty($deleteRawOriginalImage)) {
            self::delete($deleteRawOriginalImage);
        }
        return self::compressAndUpload($requestFile, $folder);
    }

    /**
     * @param $requestFile
     * @param $code
     * @return string
     */
    public static function uploadLanguageFile($requestFile, $code)
    {
        $filename = $code . '.' . $requestFile->getClientOriginalExtension();
        if (file_exists(base_path('resources/lang/') . $filename)) {
            File::delete(base_path('resources/lang/') . $filename);
        }
        $requestFile->move(base_path('resources/lang/'), $filename);
        return $filename;
    }

    /**
     * @param $file
     * @return bool
     */
    public static function deleteLanguageFile($file)
    {
        if (file_exists(base_path('resources/lang/') . $file)) {
            return File::delete(base_path('resources/lang/') . $file);
        }
        return true;
    }

    /**
     * @param $image = rawOriginalPath
     * @return bool
     */
    public static function delete($image)
    {
        if (!empty($image)) {
            // Normalize path for checking (remove /storage/ prefix if present)
            $storagePath = str_starts_with((string) $image, '/storage/') ? substr((string) $image, 9) : $image;

            if (Storage::disk('public')->exists($storagePath)) {
                return Storage::disk('public')->delete($storagePath);
            }
        }

        //Image does not exist in server so feel free to upload new image
        return true;
    }

    /**
     * @param $path
     * @return string
     */
    public static function getFileUrl($path)
    {
        if (empty($path)) {
            return null;
        }

        // If path is already a full URL, return as is
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        // If path already starts with /storage/, just wrap it with url() helper
        if (str_starts_with((string) $path, '/storage/')) {
            return url($path);
        }

        // Return full URL - for public disk, path should be relative to storage/app/public
        // Storage::url() will generate /storage/path format
        return url('/storage/' . $path);
    }

    /**
     * @throws Exception
     */
    public static function compressAndUploadWithWatermark($requestFile, $folder)
    {
        $file_name = uniqid('', true) . time() . '.' . $requestFile->getClientOriginalExtension();

        try {
            if (in_array($requestFile->getClientOriginalExtension(), ['jpg', 'jpeg', 'png'])) {
                $watermarkPath = Setting::where('name', 'watermark_image')->value('value');

                $fullWatermarkPath = storage_path('app/public/' . $watermarkPath);
                $watermark = null;

                $imagePath = $requestFile->getPathname();
                if (!file_exists($imagePath) || !is_readable($imagePath)) {
                    throw new RuntimeException('Uploaded image file is not readable at path: ' . $imagePath);
                }
                $image = Image::make($imagePath)->encode(null, 60);
                $imageWidth = $image->width();
                $imageHeight = $image->height();

                if (!empty($watermarkPath) && file_exists($fullWatermarkPath)) {
                    $watermark = Image::make($fullWatermarkPath)
                        ->resize($imageWidth, $imageHeight, static function ($constraint): void {
                            $constraint->aspectRatio(); // Preserve aspect ratio
                        })
                        ->opacity(10);
                }

                if ($watermark) {
                    $image->insert($watermark, 'center');
                }

                Storage::disk('public')->put($folder . '/' . $file_name, (string) $image->encode());
            } else {
                // Else assign file as it is
                $file = $requestFile;
                $file->storeAs($folder, $file_name, 'public');
            }
            return $folder . '/' . $file_name;
        } catch (Exception $e) {
            throw new RuntimeException($e);

            //            $file = $requestFile;
            //            return  $file->storeAs($folder, $file_name, 'public');
        }
    }

    public static function compressAndReplaceWithWatermark($requestFile, $folder, $deleteRawOriginalImage = null)
    {
        if (!empty($deleteRawOriginalImage)) {
            self::delete($deleteRawOriginalImage);
        }

        $file_name = uniqid('', true) . time() . '.' . $requestFile->getClientOriginalExtension();

        try {
            if (in_array($requestFile->getClientOriginalExtension(), ['jpg', 'jpeg', 'png'])) {
                $watermarkPath = Setting::where('name', 'watermark_image')->value('value');
                $fullWatermarkPath = storage_path('app/public/' . $watermarkPath);
                $watermark = null;
                $imagePath = $requestFile->getPathname();
                if (!file_exists($imagePath) || !is_readable($imagePath)) {
                    throw new RuntimeException('Uploaded image file is not readable at path: ' . $imagePath);
                }
                $image = Image::make($imagePath)->encode(null, 60);
                $imageWidth = $image->width();
                $imageHeight = $image->height();

                if (!empty($watermarkPath) && file_exists($fullWatermarkPath)) {
                    $watermark = Image::make($fullWatermarkPath)
                        ->resize($imageWidth, $imageHeight, static function ($constraint): void {
                            $constraint->aspectRatio(); // Preserve aspect ratio
                        })
                        ->opacity(10);
                }

                if ($watermark) {
                    $image->insert($watermark, 'center');
                }

                Storage::disk('public')->put($folder . '/' . $file_name, (string) $image->encode());
            } else {
                $file = $requestFile;
                $file->storeAs($folder, $file_name, 'public');
            }

            return $folder . '/' . $file_name;
        } catch (Exception $e) {
            throw new RuntimeException($e);
        }
    }

    public static function replaceAndUpload($requestFile, $folder, $deleteRawOriginalImage = null)
    {
        if (!empty($deleteRawOriginalImage)) {
            self::delete($deleteRawOriginalImage);
        }
        return self::upload($requestFile, $folder);
    }

    /** Private File Upload Functions*/

    /**
     * @param $requestFile
     * @param $folder
     * @return string
     */
    private static function uploadPrivateFile($requestFile, $folder)
    {
        $fileName = uniqid('', true) . time() . '.' . $requestFile->getClientOriginalExtension();
        $requestFile->storeAs($folder, $fileName, 'private');
        return $folder . '/' . $fileName;
    }

    /**
     * @param $path
     * @return bool
     */
    private static function deletePrivateFile($path)
    {
        if (!empty($path) && self::checkPrivateFileExists($path)) {
            return Storage::disk('private')->delete($path);
        }
        return true;
    }

    /**
     * @param $requestFile
     * @param $folder
     * @param $path
     * @return string
     */
    public static function removeAndUploadPrivateFile($requestFile, $folder, $path)
    {
        self::deletePrivateFile($path);
        return self::uploadPrivateFile($requestFile, $folder);
    }

    /**
     * @param $path
     * @return string
     */
    public static function getPrivateFilePath($path)
    {
        return Storage::disk('private')->path($path);
    }

    /**
     * @param $path
     * @return bool
     */
    public static function checkPrivateFileExists($path)
    {
        return Storage::disk('private')->exists($path);
    }

    /** End of Private File Upload Functions*/
}
