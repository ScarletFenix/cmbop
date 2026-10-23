<?php

namespace App\Console\Commands;

use App\Support\MarketingCssBundle;
use Illuminate\Console\Command;

class BuildMarketingCssBundleCommand extends Command
{
    protected $signature = 'css:bundle-marketing';

    protected $description = 'Concatenate and minify public-layout CSS into marketing-bundle.css';

    public function handle(): int
    {
        if (! class_exists(MarketingCssBundle::class)) {
            $this->error('MarketingCssBundle is missing.');

            return self::FAILURE;
        }

        $css = MarketingCssBundle::write();
        $bytes = strlen($css);
        $this->info('Wrote '.MarketingCssBundle::RELATIVE_PATH.' ('.$bytes.' bytes)');

        return self::SUCCESS;
    }
}
