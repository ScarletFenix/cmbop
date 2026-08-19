<?php

namespace Tests\Feature;

use App\Models\AdBanner;
use App\Models\Role;
use App\Models\User;
use App\Services\SiteEnrichment\ImageOptimizationService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesBlogUploads;
use Tests\TestCase;

class AdBannerImageValidationTest extends TestCase
{
    use CreatesBlogUploads;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        $role = Role::where('name', 'admin')->firstOrFail();
        $this->admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $this->admin->roles()->attach($role->id);
    }

    public function test_svg_upload_is_rejected(): void
    {
        $svg = UploadedFile::fake()->create('ad.svg', 20, 'image/svg+xml');

        $this->actingAs($this->admin)
            ->from(route('admin.promotions.banners.create'))
            ->post(route('admin.promotions.banners.store'), [
                'name' => 'SVG ad',
                'size_key' => 'custom',
                'width' => 300,
                'height' => 250,
                'placement' => 'header',
                'audience' => 'all',
                'image' => $svg,
                'is_active' => 1,
            ])
            ->assertRedirect(route('admin.promotions.banners.create'))
            ->assertSessionHasErrors('image');
    }

    public function test_tiny_image_on_leaderboard_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.promotions.banners.create'))
            ->post(route('admin.promotions.banners.store'), [
                'name' => 'Tiny',
                'size_key' => 'leaderboard',
                'placement' => 'header',
                'audience' => 'all',
                'image' => $this->png(10, 10),
                'is_active' => 1,
            ])
            ->assertRedirect(route('admin.promotions.banners.create'))
            ->assertSessionHasErrors('image');
    }

    public function test_matching_leaderboard_image_is_accepted(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)
            ->post(route('admin.promotions.banners.store'), [
                'name' => 'Fit',
                'size_key' => 'leaderboard',
                'placement' => 'header',
                'audience' => 'all',
                'image' => $this->png(728, 90),
                'is_active' => 1,
            ])
            ->assertRedirect(route('admin.promotions.banners.index'));

        $banner = AdBanner::query()->where('name', 'Fit')->first();
        $this->assertNotNull($banner);
        $this->assertSame(728, (int) $banner->width);
        $this->assertSame(90, (int) $banner->height);
        $this->assertNotEmpty($banner->image_path);
        Storage::disk('public')->assertExists((string) $banner->image_path);
        if (ImageOptimizationService::canEncodeWebp()) {
            $this->assertStringEndsWith('.webp', (string) $banner->image_path);
            $this->assertStringStartsWith('RIFF', Storage::disk('public')->get((string) $banner->image_path));
        }
    }

    public function test_custom_banner_jpeg_is_converted_to_webp_when_encoder_exists(): void
    {
        if (! ImageOptimizationService::canEncodeWebp()) {
            $this->markTestSkipped('No WebP encoder (GD, Imagick, or cwebp)');
        }

        Storage::fake('public');

        $this->actingAs($this->admin)
            ->post(route('admin.promotions.banners.store'), [
                'name' => 'WebP banner',
                'size_key' => 'custom',
                'width' => 300,
                'height' => 250,
                'placement' => 'header',
                'audience' => 'all',
                'image' => $this->fakeBlogUpload('promo.jpg', 300, 250),
                'is_active' => 1,
            ])
            ->assertRedirect(route('admin.promotions.banners.index'));

        $banner = AdBanner::query()->where('name', 'WebP banner')->first();
        $this->assertNotNull($banner);
        $this->assertNotEmpty($banner->image_path);
        $this->assertStringStartsWith('banners/', (string) $banner->image_path);
        $this->assertStringEndsWith('.webp', (string) $banner->image_path);
        Storage::disk('public')->assertExists((string) $banner->image_path);
        $this->assertStringStartsWith('RIFF', Storage::disk('public')->get((string) $banner->image_path));
    }

    private function png(int $width, int $height): UploadedFile
    {
        $png = $this->validPngBytes($width, $height);
        $info = @getimagesizefromstring($png);
        $this->assertIsArray($info);
        $this->assertSame($width, (int) $info[0]);
        $this->assertSame($height, (int) $info[1]);

        return UploadedFile::fake()->createWithContent('banner.png', $png);
    }
}
