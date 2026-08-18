<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;

/**
 * Subida de imágenes del menú (productos, categorías, logo).
 *
 * Las imágenes van al disco 'public', que se sirve por /storage gracias al
 * enlace de `php artisan storage:link`.
 */
class ImageService
{
    private const DISK      = 'public';
    private const MAX_WIDTH = 1200;
    private const QUALITY   = 82;

    /**
     * Normaliza y guarda la imagen. Devuelve la URL pública.
     *
     * Se reencoda siempre a JPEG: unifica formatos, recorta el peso y evita
     * guardar lo que suba el cliente tal cual.
     */
    public function store(UploadedFile $file, string $folder): string
    {
        $image = ImageManager::gd()->read($file->getPathname());

        // scaleDown no agranda: una imagen pequeña se deja como está.
        $image->scaleDown(width: self::MAX_WIDTH);

        $path = trim($folder, '/') . '/' . Str::uuid() . '.jpg';

        Storage::disk(self::DISK)->put($path, (string) $image->toJpeg(self::QUALITY));

        return Storage::disk(self::DISK)->url($path);
    }

    /**
     * Borra el archivo asociado a una URL previamente devuelta por store().
     *
     * Ignora en silencio las URLs externas o ya inexistentes: el objetivo es no
     * dejar huérfanos, no fallar si el archivo ya no está.
     */
    public function delete(?string $url): void
    {
        $path = $this->pathFromUrl($url);

        if ($path && Storage::disk(self::DISK)->exists($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    private function pathFromUrl(?string $url): ?string
    {
        if (!$url) {
            return null;
        }

        $marker = '/storage/';
        $pos    = strpos($url, $marker);

        return $pos === false
            ? null
            : substr($url, $pos + strlen($marker));
    }
}
