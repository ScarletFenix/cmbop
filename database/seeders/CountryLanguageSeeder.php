<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Country;
use App\Models\Language;

class CountryLanguageSeeder extends Seeder
{
    public function run()
    {
        // European country → language mappings only
        $mappings = [
            'at' => ['de'],
            'by' => ['ru', 'be'],
            'be' => ['nl', 'fr', 'de'],
            'bg' => ['bg'],
            'hr' => ['hr'],
            'cy' => ['el', 'tr'],
            'cz' => ['cs'],
            'dk' => ['da'],
            'ee' => ['et'],
            'fi' => ['fi', 'sv'],
            'fr' => ['fr'],
            'de' => ['de'],
            'gr' => ['el'],
            'hu' => ['hu'],
            'ie' => ['en', 'ga'],
            'it' => ['it'],
            'lv' => ['lv'],
            'lt' => ['lt'],
            'lu' => ['lb', 'fr', 'de'],
            'mt' => ['mt', 'en'],
            'nl' => ['nl'],
            'no' => ['no'],
            'pl' => ['pl'],
            'pt' => ['pt'],
            'ro' => ['ro'],
            'ru' => ['ru'],
            'sk' => ['sk'],
            'si' => ['sl'],
            'es' => ['es', 'ca', 'gl', 'eu'],
            'se' => ['sv'],
            'ch' => ['de', 'fr', 'it', 'rm'],
            'ua' => ['uk', 'ru'],
            'uk' => ['en', 'cy', 'gd'],
            'is' => ['en'],
            'rs' => ['en'],
            'ba' => ['hr', 'en'],
            'al' => ['en'],
            'mk' => ['en'],
            'me' => ['en'],
            'md' => ['ro', 'ru'],
            // Major North America
            'us' => ['en', 'es'],
            'ca' => ['en', 'fr'],
            'mx' => ['es'],
        ];

        foreach ($mappings as $countryCode => $languageCodes) {
            $country = Country::where('code', $countryCode)->first();
            if (!$country) {
                continue;
            }

            foreach ($languageCodes as $index => $langCode) {
                $language = Language::where('code', $langCode)->first();
                if (!$language) {
                    continue;
                }

                $country->languages()->syncWithoutDetaching([
                    $language->id => ['is_primary' => $index === 0],
                ]);
            }
        }
    }
}
