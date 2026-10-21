<?php
/**
 * Strips location and camera metadata from review photos before they are
 * stored. Review uploads are public files, and phone photos carry the GPS
 * position they were taken at.
 *
 * Works on the bytes, with no image library and no re-encoding: JPEG
 * APP1/APP3–APP15 and comment segments, PNG text and eXIf chunks, and WebP
 * EXIF and XMP chunks are removed. A JPEG keeps its colour profile and its
 * orientation (rewritten as a one-tag EXIF block), so portrait photos still
 * display upright. GIF carries no EXIF; videos are not changed.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Product_Reviews_Image_Privacy
{
    /**
     * Rewrite the file in place. False when it could not be read, parsed or
     * written; the caller then skips the upload rather than publishing a
     * photo that may still carry its location.
     */
    public static function strip_file(string $path, string $mime): bool
    {
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return true;
        }

        $bytes = @file_get_contents($path);
        if (!is_string($bytes) || $bytes === '') {
            return false;
        }

        $clean = self::strip_bytes($bytes, $mime);
        if ($clean === null) {
            return false;
        }

        if ($clean === $bytes) {
            return true;
        }

        return false !== @file_put_contents($path, $clean);
    }

    /**
     * @return string|null The cleaned bytes, or null when the data is not a
     *                     well-formed file of that type.
     */
    public static function strip_bytes(string $bytes, string $mime): ?string
    {
        switch ($mime) {
            case 'image/jpeg':
                return self::strip_jpeg($bytes);
            case 'image/png':
                return self::strip_png($bytes);
            case 'image/webp':
                return self::strip_webp($bytes);
        }

        return $bytes;
    }

    private static function strip_jpeg(string $b): ?string
    {
        $len = strlen($b);
        if ($len < 4 || "\xFF\xD8" !== substr($b, 0, 2)) {
            return null;
        }

        $out         = "\xFF\xD8";
        $pos         = 2;
        $orientation = 0;

        while ($pos < $len) {
            if ("\xFF" !== $b[$pos]) {
                return null;
            }

            // Any number of 0xFF fill bytes may precede a marker.
            $m = $pos + 1;
            while ($m < $len && "\xFF" === $b[$m]) {
                $m++;
            }
            if ($m >= $len) {
                return null;
            }

            $marker = ord($b[$m]);

            // Start of scan or end of image: what follows is image data.
            if (0xDA === $marker || 0xD9 === $marker) {
                $out .= "\xFF" . substr($b, $m);

                return self::insert_orientation($out, $orientation);
            }

            // Markers without a length field.
            if (0x01 === $marker || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $out .= "\xFF" . chr($marker);
                $pos  = $m + 1;
                continue;
            }

            if ($m + 2 >= $len) {
                return null;
            }

            $seg_len = (ord($b[$m + 1]) << 8) | ord($b[$m + 2]);
            if ($seg_len < 2 || $m + 1 + $seg_len > $len) {
                return null;
            }

            $payload = substr($b, $m + 3, $seg_len - 2);

            if (0xE1 === $marker) {
                // APP1: EXIF (GPS, camera, date) or XMP. Only the orientation
                // survives.
                if (0 === $orientation && 0 === strncmp($payload, "Exif\x00\x00", 6)) {
                    $orientation = self::exif_orientation(substr($payload, 6));
                }
                $keep = false;
            } elseif (0xE2 === $marker) {
                // APP2: keep the ICC colour profile, drop anything else (MPF).
                $keep = 0 === strncmp($payload, "ICC_PROFILE\x00", 12);
            } elseif ($marker >= 0xE3 && $marker <= 0xEF) {
                // APP3–APP15 (IPTC, Photoshop, maker data), except APP14:
                // Adobe's colour transform is needed to decode CMYK files.
                $keep = 0xEE === $marker;
            } else {
                // COM (0xFE) is free text.
                $keep = 0xFE !== $marker;
            }

            if ($keep) {
                $out .= "\xFF" . substr($b, $m, $seg_len + 1);
            }

            $pos = $m + 1 + $seg_len;
        }

        return null;
    }

    /**
     * Orientation tag (0x0112) from a TIFF block, or 0.
     */
    private static function exif_orientation(string $tiff): int
    {
        $len = strlen($tiff);
        if ($len < 8) {
            return 0;
        }

        $order = substr($tiff, 0, 2);
        if ('II' === $order) {
            $u16 = 'v';
            $u32 = 'V';
        } elseif ('MM' === $order) {
            $u16 = 'n';
            $u32 = 'N';
        } else {
            return 0;
        }

        if (42 !== unpack($u16, substr($tiff, 2, 2))[1]) {
            return 0;
        }

        $ifd = (int) unpack($u32, substr($tiff, 4, 4))[1];
        if ($ifd < 8 || $ifd + 2 > $len) {
            return 0;
        }

        $count = (int) unpack($u16, substr($tiff, $ifd, 2))[1];
        for ($i = 0; $i < $count; $i++) {
            $entry = $ifd + 2 + ($i * 12);
            if ($entry + 12 > $len) {
                return 0;
            }

            if (0x0112 !== unpack($u16, substr($tiff, $entry, 2))[1]) {
                continue;
            }

            // Type 3 is SHORT; the value sits in the first two bytes of the
            // value field.
            if (3 !== unpack($u16, substr($tiff, $entry + 2, 2))[1]) {
                return 0;
            }

            $value = (int) unpack($u16, substr($tiff, $entry + 8, 2))[1];

            return ($value >= 1 && $value <= 8) ? $value : 0;
        }

        return 0;
    }

    /**
     * Put a one-tag EXIF block back for a rotated or mirrored photo, after
     * the JFIF header when there is one. Orientation 1 (upright) needs none.
     */
    private static function insert_orientation(string $jpeg, int $orientation): string
    {
        if ($orientation < 2) {
            return $jpeg;
        }

        $tiff = "MM\x00\x2A\x00\x00\x00\x08"                                   // big-endian, IFD0 at offset 8
            . "\x00\x01"                                                       // one entry
            . "\x01\x12\x00\x03\x00\x00\x00\x01" . pack('n', $orientation) . "\x00\x00"
            . "\x00\x00\x00\x00";                                              // no next IFD
        $payload = "Exif\x00\x00" . $tiff;
        $segment = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;

        $at = 2;
        if ("\xFF\xE0" === substr($jpeg, 2, 2) && strlen($jpeg) >= 6) {
            $at = 4 + ((ord($jpeg[4]) << 8) | ord($jpeg[5]));
        }

        return substr($jpeg, 0, $at) . $segment . substr($jpeg, $at);
    }

    private static function strip_png(string $b): ?string
    {
        $signature = "\x89PNG\r\n\x1A\n";
        if (0 !== strncmp($b, $signature, 8)) {
            return null;
        }

        $drop = ['eXIf', 'tEXt', 'zTXt', 'iTXt', 'tIME'];
        $out  = $signature;
        $pos  = 8;
        $len  = strlen($b);

        while ($pos + 12 <= $len) {
            $data_len = (int) unpack('N', substr($b, $pos, 4))[1];
            $type     = substr($b, $pos + 4, 4);
            $size     = 12 + $data_len;
            if ($data_len < 0 || $pos + $size > $len) {
                return null;
            }

            if (!in_array($type, $drop, true)) {
                $out .= substr($b, $pos, $size);
            }

            $pos += $size;

            // Anything after IEND is not part of the image.
            if ('IEND' === $type) {
                return $out;
            }
        }

        return null;
    }

    private static function strip_webp(string $b): ?string
    {
        $len = strlen($b);
        if ($len < 12 || 'RIFF' !== substr($b, 0, 4) || 'WEBP' !== substr($b, 8, 4)) {
            return null;
        }

        $body    = '';
        $pos     = 12;
        $vp8x_at = -1;

        while ($pos + 8 <= $len) {
            $fourcc   = substr($b, $pos, 4);
            $data_len = (int) unpack('V', substr($b, $pos + 4, 4))[1];
            if ($data_len < 0 || $pos + 8 + $data_len > $len) {
                return null;
            }

            // Chunks are padded to an even length.
            $size = 8 + $data_len + ($data_len & 1);

            if ('EXIF' !== $fourcc && 'XMP ' !== $fourcc) {
                if ('VP8X' === $fourcc && $data_len >= 1 && $vp8x_at < 0) {
                    $vp8x_at = strlen($body);
                }
                $body .= substr($b, $pos, min($size, $len - $pos));
            }

            $pos += $size;
        }

        if ($body === '') {
            return null;
        }

        // The extended header announces EXIF (0x08) and XMP (0x04); clear
        // both flags now that the chunks are gone.
        if ($vp8x_at >= 0) {
            $body[$vp8x_at + 8] = chr(ord($body[$vp8x_at + 8]) & ~0x0C);
        }

        return 'RIFF' . pack('V', strlen($body) + 4) . 'WEBP' . $body;
    }
}
