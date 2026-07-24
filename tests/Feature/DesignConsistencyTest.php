<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DesignConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    public function test_advertiser_layout_uses_app_shell_without_duplicate_sidebar_block(): void
    {
        $layout = file_get_contents(resource_path('views/advertiser/layouts/app.blade.php'));
        $this->assertStringContainsString('css/app-shell.css', $layout);
        $this->assertStringContainsString('css/cart.css', $layout);
        $this->assertStringContainsString('height="68"', $layout);
        $this->assertStringContainsString('height="64"', $layout);
        $this->assertStringContainsString('background:transparent', $layout);
        $this->assertStringNotContainsString('#sidebar {', $layout);
        $this->assertStringNotContainsString("font-family: 'Poppins'", $layout);
        $this->assertStringNotContainsString('transition: all 0.3s', $layout);

        $this->actingAs($this->advertiser())
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertSee('css/app-shell.css', false)
            ->assertSee('css/cart.css', false);
    }

    public function test_publisher_layout_uses_app_shell_without_duplicate_sidebar_block(): void
    {
        $layout = file_get_contents(resource_path('views/publisher/layouts/app.blade.php'));
        $this->assertStringContainsString('css/app-shell.css', $layout);
        $this->assertStringNotContainsString('#sidebar {', $layout);
        $this->assertStringNotContainsString("font-family: 'Poppins'", $layout);
    }

    public function test_checkout_payment_tiles_use_brand_tokens_not_raw_cyan(): void
    {
        $checkout = file_get_contents(resource_path('views/advertiser/checkout.blade.php'));
        $this->assertStringNotContainsString('border: 2px solid #5bc4c7', $checkout);
        $this->assertStringNotContainsString('color:#5bc4c7', $checkout);
        $this->assertStringContainsString('var(--brand-primary-tint', $checkout);
        $this->assertStringContainsString('var(--radius-lg', $checkout);
        $this->assertStringContainsString('payment-option-card', $checkout);
    }

    public function test_select_css_uses_border_and_focus_tokens(): void
    {
        $multi = file_get_contents(public_path('css/multi-select.css'));
        $single = file_get_contents(public_path('css/single-select.css'));

        $this->assertStringContainsString('var(--border-default', $multi);
        $this->assertStringContainsString('var(--focus-ring', $multi);
        $this->assertStringContainsString('min-height: 38px', $multi);

        $this->assertStringContainsString('var(--border-default', $single);
        $this->assertStringContainsString('var(--focus-ring', $single);
        $this->assertStringContainsString('min-height: 38px', $single);
    }

    public function test_dash_panel_uses_radius_lg_token(): void
    {
        $spacing = file_get_contents(public_path('css/spacing-system.css'));
        $this->assertStringContainsString('.dash-panel', $spacing);
        $this->assertStringContainsString('border-radius: var(--radius-lg', $spacing);
    }

    public function test_cart_drawer_is_wider_than_legacy_360(): void
    {
        $cart = file_get_contents(public_path('assets/css/cart.css'));
        $this->assertStringContainsString('width: min(420px, 94vw)', $cart);
        $this->assertStringContainsString('right: -420px', $cart);
        $this->assertStringNotContainsString('width: min(360px, 92vw)', $cart);
    }

    public function test_primary_wordmark_png_is_transparent(): void
    {
        $path = public_path('assets/img/logo1.png');
        $this->assertFileExists($path);
        $bytes = file_get_contents($path);
        // PNG signature + IHDR; then look for tRNS or color type 6 (RGBA) in IHDR.
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($bytes, 0, 8));
        $ihdr = substr($bytes, 8, 25); // length+IHDR+data start
        $this->assertStringContainsString('IHDR', $ihdr);
        // Color type is byte 25 of file (offset 16+8+4+4 = wait):
        // 8 sig + 4 len + 4 'IHDR' + 4 width + 4 height + 1 bitdepth + 1 color = offset 25
        $colorType = ord($bytes[25]);
        $this->assertSame(6, $colorType, 'logo1.png should be RGBA (color type 6) for transparency');
    }

    public function test_brand_tokens_use_mist_teal_pair(): void
    {
        $brand = file_get_contents(public_path('css/brand-colors.css'));
        $this->assertStringContainsString('--brand-primary: #185054', $brand);
        $this->assertStringContainsString('--brand-primary-soft: #3faeb2', $brand);
        $this->assertStringContainsString('--brand-primary-bg: #e6f5f5', $brand);
        $this->assertStringContainsString('--surface-2: #f7fafb', $brand);
        $this->assertStringContainsString('--brand-warning: #dc2626', $brand);
    }

    public function test_marketing_back_to_top_uses_brand_primary(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $this->assertStringContainsString('id="backToTop"', $layout);
        $this->assertStringContainsString('btn btn-primary', $layout);
        $this->assertStringNotContainsString('btn btn-danger rounded-circle', $layout);
    }

    public function test_publisher_websites_focus_uses_brand_ring_not_purple(): void
    {
        $html = file_get_contents(resource_path('views/publisher/websites.blade.php'));
        $this->assertStringNotContainsString('rgba(84, 105, 212', $html);
        $this->assertStringContainsString('var(--focus-ring', $html);
    }
}
