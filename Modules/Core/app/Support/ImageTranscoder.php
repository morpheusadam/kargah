<?php

namespace Modules\Core\Support;

/**
 * Turns a picture one consumer refuses into the JPEG almost every consumer takes.
 *
 * Lives in Core, not in the module that first needed it (Social), because both
 * ends of the same problem need it: `Modules\Social\Services\Publishers\MediaItem`
 * re-encodes bytes it is about to hand a network directly (Facebook Page reads
 * `contents()`), and `Modules\Data\Services\AttachmentService` re-encodes bytes
 * on their way out of the signed file-share route (Instagram and Threads only
 * ever get a URL — see `AttachmentService::publicUrl()`'s `$as` parameter). One
 * copy in the shared kernel both already depend on beats two copies drifting
 * apart, or Data depending on Social to reach it.
 *
 * The motivating case: Instagram's image container is JPEG and nothing else
 * (`Modules\Social\Support\Networks::all()`, the `instagram` entry), and it
 * refuses a PNG with an error that names neither the file nor the reason. Every
 * other network in that catalogue that takes images at all takes JPEG too, so
 * re-encoding to JPEG is the one conversion that is never wasted.
 *
 * **GD, not a package.** Nothing in the root `composer.json` pulls in an image
 * library — Laravel's own `illuminate/image` is an optional dev suggestion,
 * unused here — and this needs exactly one operation: decode, flatten,
 * re-encode. The `gd` extension most PHP installs ship with already does that
 * in four calls; a dependency would buy nothing this does not already have.
 *
 * **A decode failure is not an error here.** `toJpeg()` returns null for
 * anything GD cannot read — a truncated upload, a mime that lied about its
 * bytes — and both callers fall back to their ordinary "this is not usable"
 * path, which already reports it correctly.
 */
final class ImageTranscoder
{
    /**
     * Source mimes worth asking GD to decode.
     *
     * Not every `image/*` mime belongs here: SVG is vectors GD cannot
     * rasterize and TIFF/HEIC are not reliably built into a stock GD, so both
     * are left to the caller's original mime check rather than failing a
     * `false` decode silently. This list is exactly the raster formats a
     * social upload realistically arrives in and GD reads on a default build.
     */
    private const DECODABLE = ['image/png', 'image/gif', 'image/webp', 'image/bmp'];

    /** Quality passed to `imagejpeg()` — high enough that re-encoding is not the visible reason a post looks worse. */
    private const JPEG_QUALITY = 87;

    public static function canConvert(string $mime): bool
    {
        return extension_loaded('gd') && in_array($mime, self::DECODABLE, true);
    }

    /**
     * Re-encode as JPEG, or null if GD could not decode the source.
     *
     * Transparency is flattened onto white rather than carried through as
     * black or noise — a PNG icon with a transparent background is the exact
     * case this exists for, and white is the flattening a person would choose
     * by hand.
     */
    public static function toJpeg(string $bytes, string $mime): ?string
    {
        if (! self::canConvert($mime)) {
            return null;
        }

        $source = @imagecreatefromstring($bytes);

        if ($source === false) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);

        $flattened = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($flattened, 255, 255, 255);
        imagefill($flattened, 0, 0, $white);
        imagealphablending($flattened, true);
        imagecopy($flattened, $source, 0, 0, 0, 0, $width, $height);
        imagedestroy($source);

        ob_start();
        $wrote = imagejpeg($flattened, null, self::JPEG_QUALITY);
        $jpeg = ob_get_clean();
        imagedestroy($flattened);

        return $wrote && is_string($jpeg) && $jpeg !== '' ? $jpeg : null;
    }
}
