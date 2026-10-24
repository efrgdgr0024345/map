<?php
declare(strict_types=1);

namespace App;

/** Route colours from the official Perth GTFS, with accessible label colours. */
final class NetworkStyle
{
    /**
     * Prefer current feed colours. These named fallbacks reflect the official
     * routes.txt downloaded on 2026-10-24; see docs/network-colours.md.
     *
     * @return array{color:string,textColor:string,colorSource:string,lineName:string}
     */
    public static function forRoute(string $name, int $type, ?string $feedColor = null, ?string $feedTextColor = null): array
    {
        $name = trim($name);
        $normalized = strtolower(preg_replace('/[\s\-\x{2010}-\x{2015}]+/u', ' ', $name) ?? $name);
        $isTrain = in_array($type, [0, 1, 2], true) || ($type >= 100 && $type < 200);
        $isFerry = $type === 4 || ($type >= 1000 && $type < 1100);
        $known = null;

        if ($isTrain) {
            // Joondalup is a legacy name for the service now named Yanchep.
            $rail = [
                '/\bairport\b/' => ['Airport Line', '#4FC7B5'],
                '/\bthornlie(?:\s*cockburn)?\b/' => ['Thornlie-Cockburn Line', '#AB72C2'],
                '/\barmadale\b/' => ['Armadale Line', '#F2A900'],
                '/\bellenbrook\b/' => ['Ellenbrook Line', '#D32737'],
                '/\bfremantle\b/' => ['Fremantle Line', '#002F6C'],
                '/\bmidland\b/' => ['Midland Line', '#910048'],
                '/\bmandurah\b/' => ['Mandurah Line', '#CB6015'],
                '/\b(?:yanchep|joondalup)\b/' => ['Yanchep Line', '#949300'],
                '/\bstadium\s+special\b/' => ['Stadium Special', '#009645'],
                '/\baustralind\b/' => ['Australind', '#CF4520'],
                '/\bprospector\b/' => ['Prospector', '#CF4520'],
                '/\bmerredin\s*link\b/' => ['MerredinLink', '#CF4520'],
                '/\bavon\s*link\b/' => ['AvonLink', '#CF4520'],
            ];
            foreach ($rail as $pattern => $style) {
                if (preg_match($pattern, $normalized)) { $known = $style; break; }
            }
        } elseif (!$isFerry) {
            $cats = [
                'blue' => ['Blue CAT', '#002F6C'],
                'red' => ['Red CAT', '#9D2235'],
                'yellow' => ['Yellow CAT', '#F1B434'],
                'green' => ['Green CAT', '#509E2F'],
                'purple' => ['Purple CAT', '#65428A'],
                'surf' => ['Surf CAT', '#009645'],
            ];
            foreach ($cats as $word => $style) {
                if (preg_match('/\b' . $word . '\s*cat\b/', $normalized)) { $known = $style; break; }
            }
        }

        $feed = self::hex($feedColor);
        // Unknown rail colours are an application fallback, not an official line.
        $color = $feed ?? ($known[1] ?? ($isTrain ? '#007EA8' : '#009645'));
        $lineName = $known[0] ?? ($name !== '' ? $name : ($isTrain ? 'Train' : ($isFerry ? 'Ferry' : 'Bus')));

        return [
            'color' => $color,
            'textColor' => self::foreground($color, self::hex($feedTextColor)),
            'colorSource' => $feed !== null ? 'feed' : ($known !== null ? 'network' : 'mode'),
            'lineName' => $lineName,
        ];
    }

    /** GTFS uses six hexadecimal digits; optional # also accepts normalized app values. */
    private static function hex(?string $value): ?string
    {
        if ($value === null || !preg_match('/^#?([a-fA-F0-9]{6})$/D', $value, $match)) { return null; }
        return '#' . strtoupper($match[1]);
    }

    private static function foreground(string $background, ?string $preferred): string
    {
        $luminance = self::luminance($background);
        $blackContrast = ($luminance + 0.05) / 0.05;
        $whiteContrast = 1.05 / ($luminance + 0.05);
        // Keep a provider's black/white label only when normal-size text is readable.
        if ($preferred === '#000000' && $blackContrast >= 4.5) { return $preferred; }
        if ($preferred === '#FFFFFF' && $whiteContrast >= 4.5) { return $preferred; }
        return $blackContrast >= $whiteContrast ? '#000000' : '#FFFFFF';
    }

    private static function luminance(string $color): float
    {
        $channels = [];
        foreach ([1, 3, 5] as $offset) {
            $channel = hexdec(substr($color, $offset, 2)) / 255;
            $channels[] = $channel <= 0.04045 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
        }
        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
