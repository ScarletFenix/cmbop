<?php

namespace App\Console\Commands;

use App\Services\PaypalCheckoutService;
use App\Support\UserMessages;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Probe PayPal OAuth without printing secrets. 401 is almost always
 * sandbox keys against live (or the reverse).
 */
class PaypalStatusCommand extends Command
{
    protected $signature = 'paypal:status';

    protected $description = 'Show PayPal mode/host and whether Client ID + Secret authenticate';

    public function handle(PaypalCheckoutService $paypal): int
    {
        $snap = $paypal->connectionSnapshot();

        $this->newLine();
        $this->line(sprintf('  Mode        %s', $snap['mode']));
        $this->line(sprintf('  Host        %s', $snap['host']));
        if ($snap['forced_live']) {
            $this->warn('  PAYPAL_MODE was sandbox; production is using live. Set PAYPAL_MODE=live or PAYPAL_ALLOW_SANDBOX=true.');
        }
        $this->line(sprintf(
            '  Client ID   %s',
            $snap['client_id_set'] ? $snap['client_id_hint'] : 'missing'
        ));
        $this->line(sprintf(
            '  Secret      %s',
            $snap['secret_set'] ? 'set ('.$snap['secret_length'].' chars)' : 'missing'
        ));
        $this->line(sprintf(
            '  Webhook ID  %s',
            $snap['webhook_id_set'] ? 'set' : 'missing (webhooks will 503)'
        ));
        if ($snap['webhook_id_set'] && ! $snap['webhook_id_ok']) {
            $this->warn('  PAYPAL_WEBHOOK_ID should start with WH- (Dashboard → Webhooks). This looks like a merchant/app id.');
        }
        $this->line(sprintf(
            '  Config cache %s',
            file_exists($this->laravel->getCachedConfigPath())
                ? 'PRESENT — .env edits do nothing until php artisan config:clear'
                : 'none'
        ));

        if (! $snap['configured']) {
            $this->newLine();
            $this->error('PayPal is not configured. Set PAYPAL_CLIENT_ID and PAYPAL_SECRET, then config:clear.');

            return self::FAILURE;
        }

        if ($paypal->secretLooksLikeWebhookId()) {
            $this->newLine();
            $this->error(UserMessages::get('payment.paypal_webhook_as_secret'));

            return self::FAILURE;
        }

        try {
            $paypal->accessToken(allowHostFallback: false);
        } catch (RuntimeException $e) {
            try {
                $paypal->accessToken(allowHostFallback: true);
                if ($paypal->baseUrl() !== $snap['host']) {
                    $this->newLine();
                    $this->error('OAuth failed on '.$snap['host'].'.');
                    $this->line('  These keys work on '.$paypal->baseUrl().'.');
                    $this->line('  Set PAYPAL_MODE='.($paypal->mode() === 'live' ? 'sandbox' : 'live').' then php artisan config:clear.');

                    return self::FAILURE;
                }

                $this->newLine();
                $this->info('OAuth: ok');

                return self::SUCCESS;
            } catch (RuntimeException) {
                // Both hosts rejected the keys or were unreachable.
            }

            $this->newLine();
            $this->error($e->getMessage());
            if ($e->getMessage() === UserMessages::get('payment.paypal_auth')) {
                $this->line('  Sandbox keys only work with PAYPAL_MODE=sandbox (developer.paypal.com Sandbox tab).');
                $this->line('  Live keys only work with PAYPAL_MODE=live (Live tab).');
                $this->line('  Copy Client ID + Secret from the same app; do not paste the webhook ID as the secret.');
            }

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('OAuth: ok');

        return self::SUCCESS;
    }
}
