<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the interaction-layer conventions:
 *  - no native alert()/confirm() outside the slb-confirm library
 *  - buttons use the design system rather than inline colours
 *  - one registration CTA label
 *  - auth validation errors are announced and tied to their input
 */
class UiConsistencyGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return string[]
     */
    private function frontendFiles(): array
    {
        $paths = [];
        foreach ([resource_path('views'), public_path('js'), public_path('assets/js')] as $root) {
            if (! is_dir($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }
                if (! preg_match('/\.(blade\.php|js)$/', $file->getFilename())) {
                    continue;
                }
                // slb-confirm.js is the one place allowed to fall back to native dialogs.
                if ($file->getFilename() === 'slb-confirm.js') {
                    continue;
                }
                $paths[] = $file->getPathname();
            }
        }

        return $paths;
    }

    public function test_no_native_alert_or_confirm_outside_the_confirm_library(): void
    {
        $offenders = [];

        foreach ($this->frontendFiles() as $path) {
            foreach (file($path) as $index => $line) {
                $trimmed = trim($line);

                // Skip comments — prose like "after confirm (…)" is not a call.
                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '{{--')) {
                    continue;
                }

                if (preg_match('/(?<![\w.$])(?:window\.)?(alert|confirm)\s*\(/', $line)) {
                    // slbAlert / slbConfirm / showAppToast are the sanctioned helpers.
                    if (preg_match('/slbAlert|slbConfirm|showAppToast/', $line)) {
                        continue;
                    }
                    $offenders[] = str_replace(base_path().'/', '', $path).':'.($index + 1).'  '.$trimmed;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Use slbAlert()/slbConfirm() instead of native dialogs:\n".implode("\n", $offenders)
        );
    }

    public function test_buttons_do_not_carry_inline_background_colours(): void
    {
        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));

        foreach ($iterator as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            foreach (file($file->getPathname()) as $index => $line) {
                // Only flag elements that opt into the button system and then override it.
                if (preg_match('/class="[^"]*\bbtn\b[^"]*"[^>]*style="[^"]*background/', $line)) {
                    $offenders[] = str_replace(resource_path('views').'/', '', $file->getPathname()).':'.($index + 1);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Buttons must use button-system classes, not inline colours:\n".implode("\n", $offenders)
        );
    }

    public function test_button_classes_used_in_markup_are_defined_in_css(): void
    {
        $css = '';
        foreach (glob(public_path('assets/css/*.css')) as $file) {
            $css .= file_get_contents($file);
        }

        // These previously appeared in markup with no rule anywhere, so they
        // rendered as unstyled Bootstrap buttons.
        foreach (['btn-outline-live', 'btn-outline-teal'] as $ghost) {
            $this->assertSame(
                0,
                (int) shell_exec(sprintf(
                    'grep -rl %s %s %s 2>/dev/null | wc -l',
                    escapeshellarg($ghost),
                    escapeshellarg(resource_path('views')),
                    escapeshellarg(public_path('js'))
                )),
                "{$ghost} is not defined in any stylesheet; use a real button-system class."
            );
        }

        $this->assertStringContainsString('.btn-live-url', $css);
    }

    public function test_registration_ctas_share_one_label(): void
    {
        $labels = [];

        foreach ([
            'components/cta.blade.php',
            'components/pricing.blade.php',
            'pages/about.blade.php',
            'pages/how-it-works.blade.php',
            'pages/marketplace.blade.php',
        ] as $view) {
            $markup = file_get_contents(resource_path('views/'.$view));
            if (preg_match("/url\('\/register'\).*?\n?.*?messages\.([a-z_]+)/s", $markup, $m)) {
                $labels[$view] = $m[1];
            }
        }

        $this->assertNotEmpty($labels);
        $this->assertSame(
            ['get_started'],
            array_values(array_unique($labels)),
            'Page-level registration CTAs must share one label: '.json_encode($labels)
        );
    }

    public function test_login_links_validation_messages_to_their_inputs(): void
    {
        $markup = file_get_contents(resource_path('views/auth/login.blade.php'));

        $this->assertStringContainsString('aria-describedby="emailError"', $markup);
        $this->assertStringContainsString('aria-describedby="passwordError"', $markup);
        $this->assertStringContainsString('id="emailError" role="alert"', $markup);
        $this->assertStringContainsString('id="passwordError" role="alert"', $markup);

        // The feedback divs used to render but never be populated.
        $this->assertStringContainsString('function showFieldErrors', $markup);
        $this->assertStringContainsString("setAttribute('aria-invalid', 'true')", $markup);
    }

    public function test_register_links_validation_messages_to_their_inputs(): void
    {
        $markup = file_get_contents(resource_path('views/auth/register.blade.php'));

        foreach (['nameError', 'emailError', 'passwordError', 'password_confirmationError'] as $field) {
            $this->assertStringContainsString('aria-describedby="'.$field.'"', $markup);
            $this->assertStringContainsString('id="'.$field.'" role="alert"', $markup);
        }
    }

    public function test_dynamic_auth_toasts_are_announced(): void
    {
        foreach (['login', 'register', 'forgot-password', 'reset-password'] as $page) {
            $markup = file_get_contents(resource_path('views/auth/'.$page.'.blade.php'));
            $this->assertStringContainsString(
                "setAttribute('role'",
                $markup,
                "{$page} builds toasts dynamically and must set an ARIA role"
            );
            $this->assertStringContainsString("setAttribute('aria-live'", $markup);
        }
    }
}
