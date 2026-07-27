<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceSellerDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    public function test_final_order_invoice_shows_partners_with_topurlz_seller_block(): void
    {
        $html = view('advertiser.invoice', [
            'invoiceType' => 'order',
            'referenceCode' => 'ORDTEST1',
            'amount' => 120,
            'billingName' => 'Buyer Name',
            'companyName' => 'Buyer Co',
            'country' => 'DE',
            'state' => '',
            'city' => 'Berlin',
            'address' => 'Street 1',
            'postalCode' => '10115',
            'vatNumber' => '',
            'userName' => 'Buyer Name',
            'userEmail' => 'buyer@example.com',
            'userId' => 1,
            'status' => 'completed',
            'paymentMethod' => 'wallet',
            'orderDate' => now(),
            'orderItems' => [
                [
                    'site_name' => 'Example Site',
                    'site_url' => 'https://example.de',
                    'price' => 120,
                    'sensitive_type' => null,
                ],
            ],
            'totalBaseAmount' => 120,
            'totalSensitiveAmount' => 0,
        ])->render();

        $this->assertStringContainsString('SEOLinkBuildings Partners with (Topurlz LTD)', $html);
        $this->assertStringContainsString('20 Wenlock Road, London, England, N1 7GU', $html);
        $this->assertStringContainsString('Registration No:', $html);
        $this->assertStringContainsString('16607074', $html);
        $this->assertStringContainsString('support@seolinkbuildings.com', $html);
        $this->assertStringContainsString('Not VAT registered', $html);
        $this->assertStringNotContainsString('Beneficiary:', $html);
        $this->assertStringNotContainsString('BE04905543949331', $html);
    }

    public function test_deposit_invoice_shows_partner_seller_and_bank_beneficiary(): void
    {
        $html = view('advertiser.invoice', [
            'invoiceType' => 'deposit',
            'referenceCode' => 'DEPTEST1',
            'amount' => 50,
            'billingName' => 'Buyer Name',
            'companyName' => '',
            'country' => 'DE',
            'state' => '',
            'city' => 'Berlin',
            'address' => 'Street 1',
            'postalCode' => '10115',
            'vatNumber' => '',
            'userName' => 'Buyer Name',
            'userEmail' => 'buyer@example.com',
            'userId' => 1,
            'status' => 'pending',
            'paymentMethod' => 'bank',
            'orderDate' => now(),
            'orderItems' => [],
            'totalBaseAmount' => 0,
            'totalSensitiveAmount' => 0,
            'deposit' => null,
            'canMarkPaid' => false,
            'userMarkedPaid' => false,
            'markPaidUrl' => null,
        ])->render();

        $this->assertStringContainsString('SEOLinkBuildings Partner', $html);
        $this->assertStringContainsString('Beneficiary:', $html);
        $this->assertStringContainsString('Topurlz Ltd', $html);
        $this->assertStringContainsString('TRWIBEB1XXX', $html);
        $this->assertStringContainsString('BE04905543949331', $html);
        $this->assertStringContainsString('+447445152374', $html);
        $this->assertStringContainsString('16607074', $html);
        $this->assertStringContainsString('Not VAT registered', $html);
        $this->assertStringNotContainsString('SEOLinkBuildings Partners with (Topurlz LTD)', $html);
    }

    public function test_pdf_tax_invoice_shows_registration_and_vat_note(): void
    {
        $role = Role::where('name', 'advertiser')->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        $invoice = Invoice::create([
            'user_id' => $user->id,
            'invoice_number' => 'INV-TEST-000002',
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
            'order_number' => 'ORD-TEST-2',
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

        $this->assertStringContainsString('SEOLinkBuildings Partners with (Topurlz LTD)', $html);
        $this->assertStringContainsString('Registration No: 16607074', $html);
        $this->assertStringContainsString('Not VAT registered', $html);
        $this->assertStringContainsString('20 Wenlock Road', $html);
    }
}
