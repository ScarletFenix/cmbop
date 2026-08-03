<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Dialogs used to render in SweetAlert's factory purple with square corners
 * while every button in the app was a teal pill, because colour was set per call
 * site and ~170 of 199 calls never set it. Disabled primaries kept the brand
 * fill, and loading states were improvised three different ways.
 */
class DialogAndButtonStateTest extends TestCase
{
    private function dialogCss(): string
    {
        return file_get_contents(public_path('assets/css/dialog-system.css'));
    }

    private function buttonCss(): string
    {
        return file_get_contents(public_path('assets/css/button-system.css'));
    }

    /**
     * @return list<string>
     */
    private function layouts(): array
    {
        return [
            'advertiser/layouts/app.blade.php',
            'publisher/layouts/app.blade.php',
            'admin/layouts/app.blade.php',
            'marketing/layouts/app.blade.php',
            'layouts/app.blade.php',
        ];
    }

    /**
     * @return list<string>
     */
    private function scriptedFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'))
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }

        return array_merge($files, glob(public_path('js/*.js')));
    }

    public function test_every_layout_loads_the_dialog_theme(): void
    {
        foreach ($this->layouts() as $layout) {
            $this->assertStringContainsString(
                'assets/css/dialog-system.css',
                file_get_contents(resource_path('views/'.$layout)),
                "{$layout} must load the dialog theme."
            );
        }
    }

    public function test_no_call_site_sets_a_dialog_button_colour(): void
    {
        $offenders = [];

        foreach ($this->scriptedFiles() as $file) {
            $src = file_get_contents($file);

            // An inline style beats the stylesheet, so one of these anywhere
            // reintroduces the drift this replaced.
            if (preg_match('/(confirmButtonColor|cancelButtonColor|denyButtonColor)\s*:/', $src)) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders, "Dialog colour belongs in dialog-system.css, not in:\n".implode("\n", $offenders));
    }

    public function test_dialog_buttons_match_the_app_shape_and_palette(): void
    {
        $css = $this->dialogCss();

        $this->assertStringContainsString('--dialog-btn-radius: var(--radius-pill', $css);
        $this->assertStringContainsString('.swal2-styled.swal2-confirm', $css);
        $this->assertStringContainsString('var(--brand-primary', $css);
        // Cancel is an outline, so the dialog has one obvious primary action.
        $this->assertMatchesRegularExpression(
            '/\.swal2-styled\.swal2-cancel\s*\{[^}]*background-color:\s*transparent/s',
            $css
        );
    }

    public function test_destructive_dialogs_keep_a_danger_confirm(): void
    {
        $this->assertStringContainsString('.slb-swal-danger', $this->dialogCss());
        $this->assertStringContainsString(
            "confirmButton: danger ? 'slb-swal-danger' : ''",
            file_get_contents(public_path('js/slb-confirm.js'))
        );

        // Views that previously hard-coded a red now carry the intent as a class.
        $ordersJs = file_get_contents(resource_path('views/advertiser/orders.blade.php'));
        $this->assertStringContainsString('slb-swal-danger', $ordersJs);
    }

    public function test_dialog_theme_outranks_sweetalerts_own_stylesheet(): void
    {
        $css = $this->dialogCss();

        // SweetAlert injects its CSS at runtime, so it would win on order;
        // the extra specificity is what makes this stick without !important.
        $this->assertStringContainsString('.swal2-container .swal2-popup .swal2-styled', $css);
        $this->assertStringNotContainsString('!important', str_replace(
            'animation: none !important',
            '',
            $css
        ));
    }

    public function test_disabled_buttons_stop_looking_clickable(): void
    {
        $css = $this->buttonCss();

        // Disabled primary used to keep --brand-primary, so only Bootstrap's
        // 0.65 opacity separated it from an enabled button.
        $this->assertMatchesRegularExpression(
            '/\.btn:disabled,[^{]*\{[^}]*--bs-btn-disabled-bg:\s*#eef1f4/s',
            $css
        );
        $this->assertMatchesRegularExpression('/\.btn:disabled,[^{]*\{[^}]*cursor:\s*not-allowed/s', $css);

        $brand = file_get_contents(public_path('assets/css/brand-colors.css'));
        $this->assertStringNotContainsString('--bs-btn-disabled-bg: var(--brand-primary)', $brand);
    }

    public function test_there_is_one_loading_convention(): void
    {
        $css = $this->buttonCss();

        $this->assertStringContainsString('.btn.is-loading', $css);
        // The label stays in the layout so the button cannot change width.
        $this->assertMatchesRegularExpression('/\.btn\.is-loading\s*\{[^}]*color:\s*transparent/s', $css);
        $this->assertStringContainsString('slb-btn-spin', $css);
        // A loading button keeps its own fill, or the white spinner would sit on
        // the neutral disabled grey.
        $this->assertStringContainsString('.btn.is-loading:disabled', $css);
    }

    public function test_the_publisher_actions_use_the_loading_convention(): void
    {
        $tasks = file_get_contents(resource_path('views/publisher/tasks.blade.php'));

        $this->assertStringContainsString("addClass('is-loading')", $tasks);
        $this->assertStringContainsString("removeClass('is-loading')", $tasks);

        // Rewriting innerHTML threw the label away — the live-URL button came
        // back as "Submit URL" instead of "Submit Live URL".
        $this->assertStringNotContainsString("html('Submit URL')", $tasks);
        $this->assertStringNotContainsString('fa fa-spinner fa-spin"></i>\');', $tasks);
    }

    public function test_warning_is_no_longer_the_danger_red(): void
    {
        $brand = file_get_contents(public_path('assets/css/brand-colors.css'));

        preg_match('/--brand-warning:\s*(#[0-9a-fA-F]{6})/', $brand, $warning);
        preg_match('/--brand-danger:\s*(#[0-9a-fA-F]{6})/', $brand, $danger);

        $this->assertNotEmpty($warning);
        $this->assertNotEmpty($danger);
        $this->assertNotSame(
            strtolower($danger[1]),
            strtolower($warning[1]),
            'A caution must not look identical to a destructive action.'
        );
    }

    public function test_success_and_warning_buttons_use_the_brand(): void
    {
        $css = $this->buttonCss();

        // These were the last two variants still rendering Bootstrap's palette.
        $this->assertMatchesRegularExpression('/\.btn-success\s*\{[^}]*var\(--brand-success/s', $css);
        $this->assertMatchesRegularExpression('/\.btn-warning\s*\{[^}]*var\(--brand-warning/s', $css);
        $this->assertStringNotContainsString('#198754', $css);
    }

    public function test_the_unused_cta_aliases_are_gone(): void
    {
        foreach (['btn-cta-primary', 'btn-cta-danger'] as $alias) {
            $offenders = [];

            foreach (array_merge($this->scriptedFiles(), glob(public_path('assets/css/*.css'))) as $file) {
                $src = file_get_contents($file);
                // Allow the note explaining why they were removed.
                $src = preg_replace('/\/\*.*?\*\//s', '', $src);

                if (str_contains($src, $alias)) {
                    $offenders[] = basename($file);
                }
            }

            $this->assertSame([], $offenders, "{$alias} was defined but never used; found in:\n".implode("\n", $offenders));
        }
    }
}
