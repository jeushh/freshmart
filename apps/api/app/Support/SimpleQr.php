<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Tiny QR helper around the vendored single-file MIT library in resources/php/qrcode.php
 * (Kazuhiko Arase's qrcode-generator). No Composer package needed.
 * Renders a scannable QR as an SVG data URI. Used by the FAKE QR driver only.
 */
final class SimpleQr
{
    /** Byte capacity per version (1-10) at error-correction level M. */
    private const CAPACITY_M = [1 => 14, 2 => 26, 3 => 42, 4 => 62, 5 => 84, 6 => 106, 7 => 122, 8 => 152, 9 => 180, 10 => 213];

    public static function svgDataUri(string $text, int $size = 260): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode(self::svg($text, $size));
    }

    public static function svg(string $text, int $size = 260): string
    {
        require_once dirname(__DIR__, 2).'/resources/php/qrcode.php';

        $version = null;
        foreach (self::CAPACITY_M as $v => $capacity) {
            if (strlen($text) <= $capacity) {
                $version = $v;
                break;
            }
        }
        if ($version === null) {
            throw new InvalidArgumentException('QR text is too long.');
        }

        $qr = new \QRCode;
        $qr->setTypeNumber($version);
        $qr->setErrorCorrectLevel(QR_ERROR_CORRECT_LEVEL_M);
        $qr->addData($text);
        $qr->make();

        $count = $qr->getModuleCount();
        $quiet = 4;
        $path = '';
        for ($row = 0; $row < $count; $row++) {
            for ($col = 0; $col < $count; $col++) {
                if ($qr->isDark($row, $col)) {
                    $path .= 'M'.($col + $quiet).','.($row + $quiet).'h1v1h-1z';
                }
            }
        }

        $box = $count + $quiet * 2;

        return '<svg xmlns="http://www.w3.org/2000/svg" width="'.$size.'" height="'.$size.'" viewBox="0 0 '.$box.' '.$box.'" shape-rendering="crispEdges">'
            .'<rect width="'.$box.'" height="'.$box.'" fill="#ffffff"/>'
            .'<path d="'.$path.'" fill="#000000"/>'
            .'</svg>';
    }
}
