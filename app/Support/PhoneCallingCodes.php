<?php

namespace App\Support;

class PhoneCallingCodes
{
    public const DEFAULT_DIAL = '49';

    /**
     * @return list<array{iso: string, dial: string, name: string}>
     */
    public static function all(): array
    {
        return [
            ['iso' => 'al', 'dial' => '355', 'name' => 'Albania'],
            ['iso' => 'dz', 'dial' => '213', 'name' => 'Algeria'],
            ['iso' => 'ar', 'dial' => '54', 'name' => 'Argentina'],
            ['iso' => 'au', 'dial' => '61', 'name' => 'Australia'],
            ['iso' => 'at', 'dial' => '43', 'name' => 'Austria'],
            ['iso' => 'bh', 'dial' => '973', 'name' => 'Bahrain'],
            ['iso' => 'be', 'dial' => '32', 'name' => 'Belgium'],
            ['iso' => 'ba', 'dial' => '387', 'name' => 'Bosnia'],
            ['iso' => 'br', 'dial' => '55', 'name' => 'Brazil'],
            ['iso' => 'bg', 'dial' => '359', 'name' => 'Bulgaria'],
            ['iso' => 'ca', 'dial' => '1', 'name' => 'Canada'],
            ['iso' => 'cl', 'dial' => '56', 'name' => 'Chile'],
            ['iso' => 'cn', 'dial' => '86', 'name' => 'China'],
            ['iso' => 'co', 'dial' => '57', 'name' => 'Colombia'],
            ['iso' => 'hr', 'dial' => '385', 'name' => 'Croatia'],
            ['iso' => 'cy', 'dial' => '357', 'name' => 'Cyprus'],
            ['iso' => 'cz', 'dial' => '420', 'name' => 'Czechia'],
            ['iso' => 'dk', 'dial' => '45', 'name' => 'Denmark'],
            ['iso' => 'eg', 'dial' => '20', 'name' => 'Egypt'],
            ['iso' => 'ee', 'dial' => '372', 'name' => 'Estonia'],
            ['iso' => 'fi', 'dial' => '358', 'name' => 'Finland'],
            ['iso' => 'fr', 'dial' => '33', 'name' => 'France'],
            ['iso' => 'de', 'dial' => '49', 'name' => 'Germany'],
            ['iso' => 'gr', 'dial' => '30', 'name' => 'Greece'],
            ['iso' => 'hk', 'dial' => '852', 'name' => 'Hong Kong'],
            ['iso' => 'hu', 'dial' => '36', 'name' => 'Hungary'],
            ['iso' => 'is', 'dial' => '354', 'name' => 'Iceland'],
            ['iso' => 'in', 'dial' => '91', 'name' => 'India'],
            ['iso' => 'id', 'dial' => '62', 'name' => 'Indonesia'],
            ['iso' => 'ie', 'dial' => '353', 'name' => 'Ireland'],
            ['iso' => 'il', 'dial' => '972', 'name' => 'Israel'],
            ['iso' => 'it', 'dial' => '39', 'name' => 'Italy'],
            ['iso' => 'jp', 'dial' => '81', 'name' => 'Japan'],
            ['iso' => 'kw', 'dial' => '965', 'name' => 'Kuwait'],
            ['iso' => 'lv', 'dial' => '371', 'name' => 'Latvia'],
            ['iso' => 'lt', 'dial' => '370', 'name' => 'Lithuania'],
            ['iso' => 'lu', 'dial' => '352', 'name' => 'Luxembourg'],
            ['iso' => 'my', 'dial' => '60', 'name' => 'Malaysia'],
            ['iso' => 'mt', 'dial' => '356', 'name' => 'Malta'],
            ['iso' => 'mx', 'dial' => '52', 'name' => 'Mexico'],
            ['iso' => 'md', 'dial' => '373', 'name' => 'Moldova'],
            ['iso' => 'me', 'dial' => '382', 'name' => 'Montenegro'],
            ['iso' => 'ma', 'dial' => '212', 'name' => 'Morocco'],
            ['iso' => 'nl', 'dial' => '31', 'name' => 'Netherlands'],
            ['iso' => 'nz', 'dial' => '64', 'name' => 'New Zealand'],
            ['iso' => 'ng', 'dial' => '234', 'name' => 'Nigeria'],
            ['iso' => 'mk', 'dial' => '389', 'name' => 'North Macedonia'],
            ['iso' => 'no', 'dial' => '47', 'name' => 'Norway'],
            ['iso' => 'om', 'dial' => '968', 'name' => 'Oman'],
            ['iso' => 'pk', 'dial' => '92', 'name' => 'Pakistan'],
            ['iso' => 'pe', 'dial' => '51', 'name' => 'Peru'],
            ['iso' => 'ph', 'dial' => '63', 'name' => 'Philippines'],
            ['iso' => 'pl', 'dial' => '48', 'name' => 'Poland'],
            ['iso' => 'pt', 'dial' => '351', 'name' => 'Portugal'],
            ['iso' => 'qa', 'dial' => '974', 'name' => 'Qatar'],
            ['iso' => 'ro', 'dial' => '40', 'name' => 'Romania'],
            ['iso' => 'sa', 'dial' => '966', 'name' => 'Saudi Arabia'],
            ['iso' => 'rs', 'dial' => '381', 'name' => 'Serbia'],
            ['iso' => 'sg', 'dial' => '65', 'name' => 'Singapore'],
            ['iso' => 'sk', 'dial' => '421', 'name' => 'Slovakia'],
            ['iso' => 'si', 'dial' => '386', 'name' => 'Slovenia'],
            ['iso' => 'za', 'dial' => '27', 'name' => 'South Africa'],
            ['iso' => 'kr', 'dial' => '82', 'name' => 'South Korea'],
            ['iso' => 'es', 'dial' => '34', 'name' => 'Spain'],
            ['iso' => 'se', 'dial' => '46', 'name' => 'Sweden'],
            ['iso' => 'ch', 'dial' => '41', 'name' => 'Switzerland'],
            ['iso' => 'tw', 'dial' => '886', 'name' => 'Taiwan'],
            ['iso' => 'th', 'dial' => '66', 'name' => 'Thailand'],
            ['iso' => 'tn', 'dial' => '216', 'name' => 'Tunisia'],
            ['iso' => 'tr', 'dial' => '90', 'name' => 'Turkey'],
            ['iso' => 'ua', 'dial' => '380', 'name' => 'Ukraine'],
            ['iso' => 'ae', 'dial' => '971', 'name' => 'United Arab Emirates'],
            ['iso' => 'gb', 'dial' => '44', 'name' => 'United Kingdom'],
            ['iso' => 'us', 'dial' => '1', 'name' => 'United States'],
            ['iso' => 'vn', 'dial' => '84', 'name' => 'Vietnam'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function dials(): array
    {
        return array_values(array_unique(array_map(fn (array $row) => $row['dial'], self::all())));
    }

    public static function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    public static function label(string $dial): string
    {
        $dial = self::digits($dial);
        foreach (self::all() as $row) {
            if ($row['dial'] === $dial) {
                return strtoupper($row['iso']).' +'.$row['dial'];
            }
        }

        return $dial !== '' ? '+'.$dial : '+'.self::DEFAULT_DIAL;
    }

    public static function combine(string $dial, string $number): string
    {
        $number = trim($number);
        if ($number === '') {
            return '';
        }

        if (str_starts_with($number, '+') || str_starts_with($number, '00')) {
            $digits = self::digits($number);

            return $digits !== '' ? '+'.$digits : '';
        }

        $local = ltrim(self::digits($number), '0');
        $code = self::digits($dial);
        if ($local === '') {
            return '';
        }
        if ($code !== '' && str_starts_with($local, $code)) {
            return '+'.$local;
        }

        return $code !== '' ? '+'.$code.$local : '+'.$local;
    }

    /**
     * @return array{dial: string, number: string}
     */
    public static function split(string $value): array
    {
        $digits = self::digits($value);
        if ($digits === '') {
            return ['dial' => self::DEFAULT_DIAL, 'number' => ''];
        }

        $dials = self::dials();
        usort($dials, fn (string $a, string $b) => strlen($b) <=> strlen($a));
        foreach ($dials as $dial) {
            if (str_starts_with($digits, $dial) && strlen($digits) > strlen($dial)) {
                return ['dial' => $dial, 'number' => substr($digits, strlen($dial))];
            }
        }

        return ['dial' => self::DEFAULT_DIAL, 'number' => $digits];
    }

    /**
     * @param  list<mixed>|mixed  $numbers
     * @param  list<mixed>|mixed  $codes
     * @return list<string>
     */
    public static function combineList(mixed $numbers, mixed $codes): array
    {
        $numbers = is_array($numbers) ? $numbers : [$numbers];
        $codes = is_array($codes) ? $codes : [$codes];
        $out = [];
        foreach ($numbers as $i => $number) {
            $combined = self::combine((string) ($codes[$i] ?? self::DEFAULT_DIAL), is_scalar($number) ? (string) $number : '');
            if ($combined !== '') {
                $out[] = $combined;
            }
        }

        return array_values(array_unique($out));
    }
}
