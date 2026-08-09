<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Source guards for Add Funds UX bugs that are easy to reintroduce in Blade JS.
 */
class AddFundsUiGuardTest extends TestCase
{
    private function addFundsView(): string
    {
        return (string) file_get_contents(resource_path('views/advertiser/add-funds.blade.php'));
    }

    public function test_custom_amount_does_not_alert_and_clear_while_typing_below_minimum(): void
    {
        $view = $this->addFundsView();

        // The old handler fired Swal + cleared the field on every keystroke when
        // parseFloat(value) < 10, so typing "100" died on the first "1".
        $this->assertStringContainsString("addEventListener('blur'", $view);
        $this->assertStringContainsString('// Partial / below-minimum while typing', $view);

        preg_match(
            "/customAmountInput\.addEventListener\('input',\s*function\s*\(\)\s*\{(.*?)\n\s*\}\);/s",
            $view,
            $inputHandler
        );
        $this->assertNotEmpty($inputHandler[1] ?? null, 'Expected a customAmount input listener.');

        $body = $inputHandler[1];
        $this->assertStringNotContainsString('Swal.fire', $body);
        $this->assertStringNotContainsString("this.value = ''", $body);
        $this->assertStringContainsString('amount >= 10', $body);
    }

    public function test_billing_modal_client_validation_requires_company_name(): void
    {
        $view = $this->addFundsView();

        $this->assertStringContainsString('formData.company_name', $view);
        $this->assertMatchesRegularExpression(
            "/!String\(formData\.company_name \|\| ''\)\.trim\(\)/",
            $view,
            'Client billing validation must require company_name like the server.'
        );
    }
}
