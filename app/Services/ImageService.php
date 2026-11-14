<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ApiException;

final class ImageService
{
    private const MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * @param array<string, mixed> $file
     * @return array{image_path: string, image_thumb_path: string}
     */
    public function store(array $file, string $folder = 'products'): array
    {
        $this->assertValidUpload($file);

        $tmp = (string) $file['tmp_name'];
        $maxBytes = ((int) config('app.upload_max_mb', 5)) * 1024 * 1024;

        if ((int) ($file['size'] ?? 0) > $maxBytes) {
            throw new ApiException(
                'UPLOAD_TOO_LARGE',
                'La imagen supera el tamaño máximo de ' . config('app.upload_max_mb', 5) . ' MB.',
                422,
                ['image' => 'La imagen es demasiado grande.'],
            );
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp);
        if (!is_string($mime) || !array_key_exists($mime, self::MIME_TYPES)) {
            throw new ApiException(
                'UPLOAD_INVALID_TYPE',
                'Formato no permitido. Usa JPG, PNG o WebP.',
                422,
                ['image' => 'Formato no permitido.'],
            );
        }

        $info = @getimagesize($tmp);
        if ($info === false || $info[0] < 10 || $info[1] < 10 || $info[0] > 6000 || $info[1] > 6000) {
            throw new ApiException(
                'UPLOAD_INVALID_IMAGE',
                'La imagen no es válida o tiene dimensiones no permitidas.',
                422,
                ['image' => 'Imagen no válida.'],
            );
        }

        $source = @imagecreatefromstring((string) file_get_contents($tmp));
        if ($source === false) {
            throw new ApiException('UPLOAD_INVALID_IMAGE', 'No se pudo procesar la imagen.', 422);
        }

        $folder = preg_match('/^[a-z0-9_-]+$/i', $folder) ? $folder : 'products';
        $directory = public_path('uploads/' . $folder);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            imagedestroy($source);
            throw new ApiException('UPLOAD_STORAGE_ERROR', 'No se pudo crear la carpeta de imágenes.', 500);
        }

        $extension = $this->preferredExtension();
        $name = bin2hex(random_bytes(16));
        $mainRelative = 'uploads/' . $folder . '/' . $name . '.' . $extension;
        $thumbRelative = 'uploads/' . $folder . '/' . $name . '-thumb.' . $extension;

        $mainPath = public_path($mainRelative);
        $thumbPath = public_path($thumbRelative);

        $main = $this->resize($source, 1600);
        $thumb = $this->resize($source, 600);
        imagedestroy($source);

        $ok = $this->write($main, $mainPath, $extension) && $this->write($thumb, $thumbPath, $extension);
        imagedestroy($main);
        imagedestroy($thumb);

        if (!$ok) {
            @unlink($mainPath);
            @unlink($thumbPath);
            throw new ApiException('UPLOAD_STORAGE_ERROR', 'No se pudo guardar la imagen.', 500);
        }

        return [
            'image_path' => $mainRelative,
            'image_thumb_path' => $thumbRelative,
        ];
    }

    public function delete(?string $relativePath): void
    {
        if ($relativePath === null || trim($relativePath) === '') {
            return;
        }

        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        if (!str_starts_with($relativePath, 'uploads/')) {
            return;
        }

        $uploadsRoot = realpath(public_path('uploads'));
        $target = realpath(public_path($relativePath));

        if ($uploadsRoot === false || $target === false) {
            return;
        }

        $uploadsRoot = rtrim(str_replace('\\', '/', $uploadsRoot), '/') . '/';
        $target = str_replace('\\', '/', $target);
        if (str_starts_with($target, $uploadsRoot) && is_file($target)) {
            @unlink($target);
        }
    }

    /** @param array<string, mixed> $file */
    private function assertValidUpload(array $file): void
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            $message = match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'La imagen es demasiado grande.',
                UPLOAD_ERR_NO_FILE => 'Selecciona una imagen.',
                default => 'No se pudo subir la imagen.',
            };
            throw new ApiException('UPLOAD_ERROR', $message, 422, ['image' => $message]);
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new ApiException('UPLOAD_ERROR', 'El archivo recibido no es válido.', 422);
        }
    }

    private function preferredExtension(): string
    {
        if (function_exists('imagewebp') && (imagetypes() & IMG_WEBP)) {
            return 'webp';
        }

        return 'jpg';
    }

    /** @return \GdImage */
    private function resize(\GdImage $source, int $maxWidth): \GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $ratio = min(1, $maxWidth / max(1, $width));
        $newWidth = max(1, (int) round($width * $ratio));
        $newHeight = max(1, (int) round($height * $ratio));

        $target = imagecreatetruecolor($newWidth, $newHeight);
        $white = imagecolorallocate($target, 255, 255, 255);
        imagefilledrectangle($target, 0, 0, $newWidth, $newHeight, $white);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imageinterlace($target, true);

        return $target;
    }

    private function write(\GdImage $image, string $path, string $extension): bool
    {
        $ok = $extension === 'webp'
            ? imagewebp($image, $path, 82)
            : imagejpeg($image, $path, 85);

        if ($ok) {
            @chmod($path, 0644);
        }

        return $ok;
    }
}
