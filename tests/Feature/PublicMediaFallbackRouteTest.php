<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicMediaFallbackRouteTest extends TestCase
{
    public function test_media_route_streams_site_image_from_public_disk(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('sites/fallback-cover.webp', 'fake-webp-body');

        $this->get('/media/sites/fallback-cover.webp')
            ->assertOk()
            ->assertHeader('Cache-Control');
    }

    public function test_media_route_rejects_path_traversal_and_private_prefixes(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('sites/ok.webp', 'x');
        Storage::disk('public')->put('private/secret.txt', 'nope');

        $this->get('/media/../sites/ok.webp')->assertNotFound();
        $this->get('/media/private/secret.txt')->assertNotFound();
        $this->get('/media/sites/missing.webp')->assertNotFound();
    }
}
