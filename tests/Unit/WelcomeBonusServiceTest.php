<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\WelcomeBonusClaim;
use App\Models\WelcomeBonusSetting;
use App\Services\Wallet\WelcomeBonusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class WelcomeBonusServiceTest extends TestCase
{
    use RefreshDatabase;

    private WelcomeBonusService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(WelcomeBonusService::class);
    }

    public function test_enabled_advertiser_from_new_ip_gets_configured_amount(): void
    {
        $this->assertTrue($this->service->isEnabled());
        $this->assertSame(20.0, $this->service->amountFor($this->request('1.2.3.4'), 'advertiser'));
    }

    public function test_disabled_flag_returns_zero(): void
    {
        $this->service->setEnabled(false);

        $this->assertFalse($this->service->isEnabled());
        $this->assertSame(0.0, $this->service->amountFor($this->request('1.2.3.4'), 'advertiser'));
    }

    public function test_publisher_role_returns_zero(): void
    {
        $this->assertSame(0.0, $this->service->amountFor($this->request('1.2.3.4'), 'publisher'));
    }

    public function test_ip_already_claimed_returns_zero(): void
    {
        $user = User::factory()->create();
        WelcomeBonusClaim::query()->create([
            'user_id' => $user->id,
            'ip_address' => '1.2.3.4',
            'source' => 'registration',
            'amount' => 20,
        ]);

        $this->assertSame(0.0, $this->service->amountFor($this->request('1.2.3.4'), 'advertiser'));
        $this->assertSame(20.0, $this->service->amountFor($this->request('9.9.9.9'), 'advertiser'));
    }

    public function test_claim_cookie_returns_zero(): void
    {
        $request = $this->request('8.8.8.8', [
            (string) config('welcome_bonus.cookie_name') => '1',
        ]);

        $this->assertSame(0.0, $this->service->amountFor($request, 'advertiser'));
    }

    public function test_record_claim_rejects_duplicate_ip(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $request = $this->request('10.0.0.1');

        $this->assertTrue($this->service->recordClaim($first, $request, 20.0, 'registration'));
        $this->assertFalse($this->service->recordClaim($second, $request, 20.0, 'registration'));
        $this->assertSame(1, WelcomeBonusClaim::query()->count());
    }

    public function test_oversized_ip_is_ignored_instead_of_breaking_signup(): void
    {
        $request = $this->request(str_repeat('1', 50));

        $this->assertNull($this->service->normalizedIp($request));
        $this->assertSame(20.0, $this->service->amountFor($request, 'advertiser'));

        $user = User::factory()->create();
        $this->assertTrue($this->service->recordClaim($user, $request, 20.0, 'registration'));
        $this->assertNull(WelcomeBonusClaim::query()->where('user_id', $user->id)->value('ip_address'));
    }

    public function test_settings_default_enabled_until_toggled(): void
    {
        $this->assertTrue(WelcomeBonusSetting::isEnabled());

        WelcomeBonusSetting::setEnabled(false, 99);
        $this->assertFalse(WelcomeBonusSetting::isEnabled());

        $stored = WelcomeBonusSetting::getValue('config', []);
        $this->assertFalse($stored['enabled']);
        $this->assertSame(99, $stored['updated_by']);
    }

    private function request(string $ip, array $cookies = []): Request
    {
        return Request::create('/register', 'POST', [], $cookies, [], [
            'REMOTE_ADDR' => $ip,
        ]);
    }
}
