<?php

namespace Tests\Feature;

use App\Support\TrustedProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxiesTest extends TestCase
{
    public function test_star_is_never_trusted(): void
    {
        config(['app.trusted_proxies' => '*']);

        $this->assertSame([], TrustedProxies::addresses());
    }

    public function test_cloudflare_alias_loads_edge_cidrs(): void
    {
        config(['app.trusted_proxies' => 'cloudflare']);

        $addresses = TrustedProxies::addresses();
        $this->assertNotEmpty($addresses);
        $this->assertContains('173.245.48.0/20', $addresses);
    }

    public function test_forwarded_for_does_not_change_request_ip_by_default(): void
    {
        Route::middleware('web')->get('/__proxy/ip', fn (Request $request) => response()->json([
            'ip' => $request->ip(),
        ]));

        $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])
            ->withHeaders(['X-Forwarded-For' => '198.51.100.7'])
            ->getJson('/__proxy/ip')
            ->assertOk()
            ->assertJsonPath('ip', '10.1.2.3');
    }
}
