<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceBrandLogoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    public function test_billing_logo_helpers_resolve_email_logo_asset(): void
    {
        config(['billing.company.logo_path' => 'assets/img/email-logo.png']);

        $path = billing_company_logo_path();
        $dataUri = billing_company_logo_data_uri();

        $this->assertNotNull($path);
        $this->assertFileExists($path);
        $this->assertNotNull($dataUri);
        $this->assertStringStartsWith('data:image/png;base64,', $dataUri);
    }

    public function test_pdf_invoice_html_embeds_company_logo(): void
    {
        $role = Role::where('name', 'advertiser')->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
            'name' => 'Invoice Buyer',
        ]);
        $user->roles()->attach($role->id);

        $invoice = Invoice::create([
            'user_id' => $user->id,
            'invoice_number' => 'INV-TEST-000001',
            'type' => Invoice::TYPE_TAX_INVOICE,
            'status' => Invoice::STATUS_PAID,
            'invoice_date' => now(),
            'customer_name' => $user->name,
            'customer_email' => $user->email,
            'currency' => 'EUR',
            'subtotal' => 100,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 100,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'order_number' => 'ORD-TEST',
            'line_items' => [
                ['description' => 'Guest post', 'quantity' => 1, 'unit_price' => 100, 'total' => 100],
            ],
            'billing_snapshot' => [],
        ]);

        $html = view('billing.pdf.invoice', [
            'invoice' => $invoice,
            'company' => config('billing.company'),
            'colors' => config('billing.colors'),
            'currencySymbol' => '€',
        ])->render();

        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString('SEOLinkBuildings', $html);
        $this->assertStringNotContainsString('topurl-logo', $html);
        $this->assertStringNotContainsString('TopURLZ', $html);
    }

    public function test_advertiser_web_invoice_uses_brand_logo_not_topurlz(): void
    {
        $blade = file_get_contents(resource_path('views/advertiser/invoice.blade.php'));

        $this->assertStringContainsString('billing_company_logo_data_uri', $blade);
        $this->assertStringContainsString('email-logo.png', $blade);
        $this->assertStringNotContainsString('topurl-logo.png', $blade);
        $this->assertStringNotContainsString('TopURLZ', $blade);
    }
}
