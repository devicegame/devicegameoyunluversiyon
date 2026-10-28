<?php
/**
 * Ürün posterleri için prosedürel SVG "key-art".
 * Dış görsel bağımlılığı yok; renkler ve slug'dan üretilir.
 */

if (!function_exists('dg_rand')) {
    /** Slug'dan tohumlanan basit üreteç: her ürün hep aynı sanatı alır. */
    function dg_rand(string $seed): callable
    {
        $state = crc32($seed) ?: 1;
        return function (float $min, float $max, int $precision = 0) use (&$state) {
            $state = ($state * 1103515245 + 12345) & 0x7fffffff;
            $value = $min + ($state / 0x7fffffff) * ($max - $min);
            return round($value, $precision);
        };
    }
}

if (!function_exists('poster_svg')) {
    /**
     * Poster arka planını SVG olarak döndürür.
     * $variant: aynı sayfada tekrar eden kullanımlarda id çakışmasını önler.
     */
    function poster_svg(array $product, string $variant = ''): string
    {
        $slug = $product['slug'];
        $uid = 'dg' . substr(md5($slug . $variant), 0, 7);
        $c1 = $product['c1'];
        $c2 = $product['c2'];
        $accent = $product['accent'];

        $r = dg_rand($slug . '|art');
        $b1x = $r(10, 90);  $b1y = $r(2, 34);   $b1r = $r(120, 190);
        $b2x = $r(55, 98);  $b2y = $r(45, 92);  $b2r = $r(90, 150);
        $b3x = $r(0, 35);   $b3y = $r(60, 96);  $b3r = $r(80, 140);
        $rot = $r(-28, 28);
        $rings = (int)$r(2, 4);
        $ringX = $r(48, 78);
        $ringY = $r(16, 34);

        $svg  = '<svg class="poster-art" viewBox="0 0 400 460" preserveAspectRatio="xMidYMid slice" aria-hidden="true" focusable="false">';
        $svg .= '<defs>';
        $svg .= '<linearGradient id="' . $uid . 'bg" x1="12%" y1="0%" x2="88%" y2="100%">'
              . '<stop offset="0%" stop-color="' . e($c1) . '"/>'
              . '<stop offset="52%" stop-color="' . e($c1) . '" stop-opacity=".72"/>'
              . '<stop offset="100%" stop-color="#04100c"/>'
              . '</linearGradient>';
        $svg .= '<radialGradient id="' . $uid . 'glow" cx="50%" cy="50%" r="50%">'
              . '<stop offset="0%" stop-color="' . e($c2) . '" stop-opacity=".85"/>'
              . '<stop offset="65%" stop-color="' . e($c2) . '" stop-opacity=".22"/>'
              . '<stop offset="100%" stop-color="' . e($c2) . '" stop-opacity="0"/>'
              . '</radialGradient>';
        $svg .= '<radialGradient id="' . $uid . 'vign" cx="50%" cy="96%" r="72%">'
              . '<stop offset="0%" stop-color="#020a07" stop-opacity=".85"/>'
              . '<stop offset="100%" stop-color="#020a07" stop-opacity="0"/>'
              . '</radialGradient>';
        $svg .= '<radialGradient id="' . $uid . 'blob1" cx="50%" cy="50%" r="50%">'
              . '<stop offset="0%" stop-color="' . e($c2) . '" stop-opacity=".55"/>'
              . '<stop offset="60%" stop-color="' . e($c2) . '" stop-opacity=".18"/>'
              . '<stop offset="100%" stop-color="' . e($c2) . '" stop-opacity="0"/>'
              . '</radialGradient>';
        $svg .= '<radialGradient id="' . $uid . 'blob2" cx="50%" cy="50%" r="50%">'
              . '<stop offset="0%" stop-color="' . e($accent) . '" stop-opacity=".3"/>'
              . '<stop offset="100%" stop-color="' . e($accent) . '" stop-opacity="0"/>'
              . '</radialGradient>';
        $svg .= '</defs>';

        // Zemin + atmosfer
        $svg .= '<rect width="400" height="460" fill="url(#' . $uid . 'bg)"/>';
        $svg .= '<ellipse cx="' . $b1x . '%" cy="' . $b1y . '%" rx="' . $b1r . '" ry="' . (int)($b1r * 0.82) . '" fill="url(#' . $uid . 'glow)"/>';

        // Yumuşak renk kütleleri
        $svg .= '<ellipse cx="' . $b2x . '%" cy="' . $b2y . '%" rx="' . (int)($b2r * 1.5) . '" ry="' . (int)($b2r * 1.35) . '" fill="url(#' . $uid . 'blob1)" opacity=".55" transform="rotate(' . $rot . ' 200 230)"/>';
        $svg .= '<ellipse cx="' . $b3x . '%" cy="' . $b3y . '%" rx="' . (int)($b3r * 1.6) . '" ry="' . (int)($b3r * 1.2) . '" fill="url(#' . $uid . 'blob2)" opacity=".5" transform="rotate(' . (-$rot) . ' 200 230)"/>';

        // Yörünge halkaları
        $svg .= '<g fill="none" stroke="' . e($accent) . '" stroke-width="1">';
        for ($i = 0; $i < $rings; $i++) {
            $ry = 60 + $i * 46;
            $opacity = 0.22 - $i * 0.05;
            $svg .= '<ellipse cx="' . $ringX . '%" cy="' . $ringY . '%" rx="' . (int)($ry * 1.55) . '" ry="' . $ry . '"'
                  . ' opacity="' . max(0.06, $opacity) . '"'
                  . ' transform="rotate(' . (-18 + $i * 7) . ' 300 100)"/>';
        }
        $svg .= '</g>';

        // İnce ışık çizgileri
        $svg .= '<g stroke="' . e($accent) . '" stroke-width="1" opacity=".14">';
        for ($i = 0; $i < 7; $i++) {
            $x = 30 + $i * 58;
            $svg .= '<line x1="' . $x . '" y1="460" x2="' . ($x + 130) . '" y2="120"/>';
        }
        $svg .= '</g>';

        // Alt vinyet
        $svg .= '<rect width="400" height="460" fill="url(#' . $uid . 'vign)"/>';
        $svg .= '</svg>';

        return $svg;
    }
}
