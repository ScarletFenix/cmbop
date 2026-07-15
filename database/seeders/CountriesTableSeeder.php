<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Country;

class CountriesTableSeeder extends Seeder
{
    public function run()
    {
        // Europe-focused marketplace countries only
        $countries = [
            ['code' => 'at', 'name' => 'Austria', 'region' => 'Europe'],
            ['code' => 'by', 'name' => 'Belarus', 'region' => 'Europe'],
            ['code' => 'be', 'name' => 'Belgium', 'region' => 'Europe'],
            ['code' => 'bg', 'name' => 'Bulgaria', 'region' => 'Europe'],
            ['code' => 'hr', 'name' => 'Croatia', 'region' => 'Europe'],
            ['code' => 'cy', 'name' => 'Cyprus', 'region' => 'Europe'],
            ['code' => 'cz', 'name' => 'Czech Republic', 'region' => 'Europe'],
            ['code' => 'dk', 'name' => 'Denmark', 'region' => 'Europe'],
            ['code' => 'ee', 'name' => 'Estonia', 'region' => 'Europe'],
            ['code' => 'fi', 'name' => 'Finland', 'region' => 'Europe'],
            ['code' => 'fr', 'name' => 'France', 'region' => 'Europe'],
            ['code' => 'de', 'name' => 'Germany', 'region' => 'Europe'],
            ['code' => 'gr', 'name' => 'Greece', 'region' => 'Europe'],
            ['code' => 'hu', 'name' => 'Hungary', 'region' => 'Europe'],
            ['code' => 'ie', 'name' => 'Ireland', 'region' => 'Europe'],
            ['code' => 'it', 'name' => 'Italy', 'region' => 'Europe'],
            ['code' => 'lv', 'name' => 'Latvia', 'region' => 'Europe'],
            ['code' => 'lt', 'name' => 'Lithuania', 'region' => 'Europe'],
            ['code' => 'lu', 'name' => 'Luxembourg', 'region' => 'Europe'],
            ['code' => 'mt', 'name' => 'Malta', 'region' => 'Europe'],
            ['code' => 'nl', 'name' => 'Netherlands', 'region' => 'Europe'],
            ['code' => 'no', 'name' => 'Norway', 'region' => 'Europe'],
            ['code' => 'pl', 'name' => 'Poland', 'region' => 'Europe'],
            ['code' => 'pt', 'name' => 'Portugal', 'region' => 'Europe'],
            ['code' => 'ro', 'name' => 'Romania', 'region' => 'Europe'],
            ['code' => 'ru', 'name' => 'Russia', 'region' => 'Europe'],
            ['code' => 'sk', 'name' => 'Slovakia', 'region' => 'Europe'],
            ['code' => 'si', 'name' => 'Slovenia', 'region' => 'Europe'],
            ['code' => 'es', 'name' => 'Spain', 'region' => 'Europe'],
            ['code' => 'se', 'name' => 'Sweden', 'region' => 'Europe'],
            ['code' => 'ch', 'name' => 'Switzerland', 'region' => 'Europe'],
            ['code' => 'ua', 'name' => 'Ukraine', 'region' => 'Europe'],
            ['code' => 'uk', 'name' => 'United Kingdom', 'region' => 'Europe'],
            ['code' => 'is', 'name' => 'Iceland', 'region' => 'Europe'],
            ['code' => 'rs', 'name' => 'Serbia', 'region' => 'Europe'],
            ['code' => 'ba', 'name' => 'Bosnia and Herzegovina', 'region' => 'Europe'],
            ['code' => 'al', 'name' => 'Albania', 'region' => 'Europe'],
            ['code' => 'mk', 'name' => 'North Macedonia', 'region' => 'Europe'],
            ['code' => 'me', 'name' => 'Montenegro', 'region' => 'Europe'],
            ['code' => 'md', 'name' => 'Moldova', 'region' => 'Europe'],
            // Major North American markets
            ['code' => 'us', 'name' => 'United States', 'region' => 'North America'],
            ['code' => 'ca', 'name' => 'Canada', 'region' => 'North America'],
            ['code' => 'mx', 'name' => 'Mexico', 'region' => 'North America'],
        ];

        foreach ($countries as $country) {
            Country::updateOrCreate(
                ['code' => $country['code']],
                $country
            );
        }
    }
}
