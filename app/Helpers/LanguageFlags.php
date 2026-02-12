<?php

namespace App\Helpers;

/**
 * Helper class to map ISO 639 language codes to country flag SVG URLs.
 * Uses Flag CDN (https://flagcdn.com/) for SVG flags.
 */
class LanguageFlags
{
    /**
     * Map ISO 639-2/3 language codes to ISO 3166-1 alpha-2 country codes.
     * This is a best-effort mapping as languages don't always map 1:1 to countries.
     */
    protected static array $languageToCountry = [
        // Major languages
        'eng' => 'gb',
        'en' => 'gb',
        'fra' => 'fr',
        'fre' => 'fr',
        'fr' => 'fr',
        'deu' => 'de',
        'ger' => 'de',
        'de' => 'de',
        'spa' => 'es',
        'es' => 'es',
        'ita' => 'it',
        'it' => 'it',
        'por' => 'pt',
        'pt' => 'pt',
        'rus' => 'ru',
        'ru' => 'ru',
        'jpn' => 'jp',
        'ja' => 'jp',
        'kor' => 'kr',
        'ko' => 'kr',
        'zho' => 'cn',
        'chi' => 'cn',
        'zh' => 'cn',
        'cmn' => 'cn',
        'ara' => 'sa',
        'ar' => 'sa',
        'hin' => 'in',
        'hi' => 'in',
        'ben' => 'bd',
        'bn' => 'bd',
        'urd' => 'pk',
        'ur' => 'pk',
        'tur' => 'tr',
        'tr' => 'tr',
        'pol' => 'pl',
        'pl' => 'pl',
        'nld' => 'nl',
        'dut' => 'nl',
        'nl' => 'nl',
        'swe' => 'se',
        'sv' => 'se',
        'nor' => 'no',
        'no' => 'no',
        'nob' => 'no',
        'nno' => 'no',
        'dan' => 'dk',
        'da' => 'dk',
        'fin' => 'fi',
        'fi' => 'fi',
        'ell' => 'gr',
        'gre' => 'gr',
        'el' => 'gr',
        'ces' => 'cz',
        'cze' => 'cz',
        'cs' => 'cz',
        'slk' => 'sk',
        'slo' => 'sk',
        'sk' => 'sk',
        'hun' => 'hu',
        'hu' => 'hu',
        'ron' => 'ro',
        'rum' => 'ro',
        'ro' => 'ro',
        'bul' => 'bg',
        'bg' => 'bg',
        'ukr' => 'ua',
        'uk' => 'ua',
        'hrv' => 'hr',
        'hr' => 'hr',
        'srp' => 'rs',
        'sr' => 'rs',
        'slv' => 'si',
        'sl' => 'si',
        'heb' => 'il',
        'he' => 'il',
        'iw' => 'il',
        'tha' => 'th',
        'th' => 'th',
        'vie' => 'vn',
        'vi' => 'vn',
        'ind' => 'id',
        'id' => 'id',
        'may' => 'my',
        'msa' => 'my',
        'ms' => 'my',
        'fil' => 'ph',
        'tgl' => 'ph',
        'tl' => 'ph',
        'tam' => 'in',
        'ta' => 'in',
        'tel' => 'in',
        'te' => 'in',
        'mal' => 'in',
        'ml' => 'in',
        'kan' => 'in',
        'kn' => 'in',
        'mar' => 'in',
        'mr' => 'in',
        'guj' => 'in',
        'gu' => 'in',
        'pan' => 'in',
        'pa' => 'in',
        'cat' => 'es',
        'ca' => 'es',
        'eus' => 'es',
        'baq' => 'es',
        'eu' => 'es',
        'glg' => 'es',
        'gl' => 'es',
        'lat' => 'va',
        'la' => 'va',
        'est' => 'ee',
        'et' => 'ee',
        'lav' => 'lv',
        'lv' => 'lv',
        'lit' => 'lt',
        'lt' => 'lt',
        'fas' => 'ir',
        'per' => 'ir',
        'fa' => 'ir',
        'swa' => 'ke',
        'sw' => 'ke',
        'afr' => 'za',
        'af' => 'za',
        'zul' => 'za',
        'zu' => 'za',
        'xho' => 'za',
        'xh' => 'za',
        'nep' => 'np',
        'ne' => 'np',
        'sin' => 'lk',
        'si' => 'lk',
        'mya' => 'mm',
        'bur' => 'mm',
        'my' => 'mm',
        'khm' => 'kh',
        'km' => 'kh',
        'lao' => 'la',
        'lo' => 'la',
        'amh' => 'et',
        'am' => 'et',
        'kat' => 'ge',
        'geo' => 'ge',
        'ka' => 'ge',
        'hye' => 'am',
        'arm' => 'am',
        'hy' => 'am',
        'aze' => 'az',
        'az' => 'az',
        'uzb' => 'uz',
        'uz' => 'uz',
        'kaz' => 'kz',
        'kk' => 'kz',
        'bel' => 'by',
        'be' => 'by',
        'mkd' => 'mk',
        'mac' => 'mk',
        'mk' => 'mk',
        'sqi' => 'al',
        'alb' => 'al',
        'sq' => 'al',
        'bos' => 'ba',
        'bs' => 'ba',
        'isl' => 'is',
        'ice' => 'is',
        'is' => 'is',
        'mlt' => 'mt',
        'mt' => 'mt',
        'ltz' => 'lu',
        'lb' => 'lu',
        'cym' => 'gb',
        'wel' => 'gb',
        'cy' => 'gb',
        'gle' => 'ie',
        'ga' => 'ie',
        'gla' => 'gb',
        'gd' => 'gb',
        'yid' => 'il',
        'yi' => 'il',
        'mon' => 'mn',
        'mn' => 'mn',
        'tib' => 'cn',
        'bod' => 'cn',
        'bo' => 'cn',
        'uig' => 'cn',
        'ug' => 'cn',

        // Portuguese variations
        'pt-br' => 'br',
        'pt-pt' => 'pt',

        // Chinese variations
        'zh-hans' => 'cn',
        'zh-hant' => 'tw',
        'zh-cn' => 'cn',
        'zh-tw' => 'tw',
        'zh-hk' => 'hk',
        'yue' => 'hk',
        'can' => 'hk',

        // English variations
        'en-us' => 'us',
        'en-gb' => 'gb',
        'en-au' => 'au',
        'en-ca' => 'ca',
        'en-nz' => 'nz',
        'en-ie' => 'ie',
        'en-za' => 'za',

        // Spanish variations
        'es-mx' => 'mx',
        'es-ar' => 'ar',
        'es-co' => 'co',
        'es-cl' => 'cl',
        'es-pe' => 'pe',
        'es-ve' => 've',
        'es-419' => 'mx',

        // French variations
        'fr-ca' => 'ca',
        'fr-be' => 'be',
        'fr-ch' => 'ch',

        // German variations
        'de-at' => 'at',
        'de-ch' => 'ch',

        // Dutch variations
        'nl-be' => 'be',
    ];

    /**
     * Get the flag SVG URL for a language code.
     *
     * @param  string  $languageCode  ISO 639-2/3 language code
     * @param  int  $width  Width of the flag image (default 24)
     * @return string|null URL to flag SVG or null if not found
     */
    public static function getFlagUrl(string $languageCode, int $width = 24): ?string
    {
        $languageCode = strtolower(trim($languageCode));

        // Skip undefined or unknown
        if (in_array($languageCode, ['und', 'undetermined', 'unknown', 'mul', 'zxx', 'mis', 'qaa'])) {
            return null;
        }

        $countryCode = self::$languageToCountry[$languageCode] ?? null;

        if (! $countryCode) {
            return null;
        }

        // Use Flag CDN for SVG flags
        return "https://flagcdn.com/w{$width}/{$countryCode}.png";
    }

    /**
     * Get the country code for a language code.
     *
     * @param  string  $languageCode  ISO 639-2/3 language code
     * @return string|null ISO 3166-1 alpha-2 country code or null if not found
     */
    public static function getCountryCode(string $languageCode): ?string
    {
        $languageCode = strtolower(trim($languageCode));

        return self::$languageToCountry[$languageCode] ?? null;
    }

    /**
     * Get the human-readable language name for a language code.
     *
     * @param  string  $languageCode  ISO 639-2/3 language code
     * @return string Human-readable language name
     */
    public static function getLanguageName(string $languageCode): string
    {
        $names = [
            'eng' => 'English',
            'en' => 'English',
            'fra' => 'French',
            'fre' => 'French',
            'fr' => 'French',
            'deu' => 'German',
            'ger' => 'German',
            'de' => 'German',
            'spa' => 'Spanish',
            'es' => 'Spanish',
            'ita' => 'Italian',
            'it' => 'Italian',
            'por' => 'Portuguese',
            'pt' => 'Portuguese',
            'rus' => 'Russian',
            'ru' => 'Russian',
            'jpn' => 'Japanese',
            'ja' => 'Japanese',
            'kor' => 'Korean',
            'ko' => 'Korean',
            'zho' => 'Chinese',
            'chi' => 'Chinese',
            'zh' => 'Chinese',
            'cmn' => 'Mandarin',
            'ara' => 'Arabic',
            'ar' => 'Arabic',
            'hin' => 'Hindi',
            'hi' => 'Hindi',
            'ben' => 'Bengali',
            'bn' => 'Bengali',
            'urd' => 'Urdu',
            'ur' => 'Urdu',
            'tur' => 'Turkish',
            'tr' => 'Turkish',
            'pol' => 'Polish',
            'pl' => 'Polish',
            'nld' => 'Dutch',
            'dut' => 'Dutch',
            'nl' => 'Dutch',
            'swe' => 'Swedish',
            'sv' => 'Swedish',
            'nor' => 'Norwegian',
            'no' => 'Norwegian',
            'nob' => 'Norwegian',
            'nno' => 'Norwegian',
            'dan' => 'Danish',
            'da' => 'Danish',
            'fin' => 'Finnish',
            'fi' => 'Finnish',
            'ell' => 'Greek',
            'gre' => 'Greek',
            'el' => 'Greek',
            'ces' => 'Czech',
            'cze' => 'Czech',
            'cs' => 'Czech',
            'slk' => 'Slovak',
            'slo' => 'Slovak',
            'sk' => 'Slovak',
            'hun' => 'Hungarian',
            'hu' => 'Hungarian',
            'ron' => 'Romanian',
            'rum' => 'Romanian',
            'ro' => 'Romanian',
            'bul' => 'Bulgarian',
            'bg' => 'Bulgarian',
            'ukr' => 'Ukrainian',
            'uk' => 'Ukrainian',
            'hrv' => 'Croatian',
            'hr' => 'Croatian',
            'srp' => 'Serbian',
            'sr' => 'Serbian',
            'slv' => 'Slovenian',
            'sl' => 'Slovenian',
            'heb' => 'Hebrew',
            'he' => 'Hebrew',
            'iw' => 'Hebrew',
            'tha' => 'Thai',
            'th' => 'Thai',
            'vie' => 'Vietnamese',
            'vi' => 'Vietnamese',
            'ind' => 'Indonesian',
            'id' => 'Indonesian',
            'may' => 'Malay',
            'msa' => 'Malay',
            'ms' => 'Malay',
            'fil' => 'Filipino',
            'tgl' => 'Filipino',
            'tl' => 'Filipino',
            'tam' => 'Tamil',
            'ta' => 'Tamil',
            'tel' => 'Telugu',
            'te' => 'Telugu',
            'und' => 'Undefined',
            'mul' => 'Multiple',
            'zxx' => 'No linguistic content',
            'mis' => 'Miscellaneous',
            'yue' => 'Cantonese',
            'can' => 'Cantonese',
        ];

        $languageCode = strtolower(trim($languageCode));

        return $names[$languageCode] ?? strtoupper($languageCode);
    }

    /**
     * Generate HTML for displaying language flags.
     *
     * @param  array|string|null  $languages  Array or JSON string of ISO 639-2/3 language codes
     * @param  int  $flagWidth  Width of each flag (default 20)
     * @return string HTML string with flag images
     */
    public static function renderFlags(array|string|null $languages, int $flagWidth = 20): string
    {
        // Handle string input (JSON encoded or single language code)
        if (is_string($languages)) {
            $decoded = json_decode($languages, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $languages = $decoded;
            } else {
                // Single language code as string
                $languages = [$languages];
            }
        }

        if (empty($languages)) {
            return '<span class="text-gray-400">-</span>';
        }

        $flags = [];
        foreach ($languages as $lang) {
            $url = self::getFlagUrl($lang, $flagWidth);
            $name = self::getLanguageName($lang);

            if ($url) {
                $flags[] = sprintf(
                    '<img src="%s" alt="%s" title="%s (%s)" style="width: %dpx; display: inline-block; margin-right: 4px; border-radius: 2px; box-shadow: 0 1px 2px rgba(0,0,0,0.1);">',
                    htmlspecialchars($url),
                    htmlspecialchars($name),
                    htmlspecialchars($name),
                    strtoupper($lang),
                    $flagWidth
                );
            } else {
                // Fallback to text badge if no flag found
                $flags[] = sprintf(
                    '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300" title="%s">%s</span>',
                    htmlspecialchars($name),
                    strtoupper($lang)
                );
            }
        }

        return implode('', $flags);
    }
}
