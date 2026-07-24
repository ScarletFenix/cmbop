<?php

namespace Tests\Feature;

use Tests\TestCase;

class OverlayStackFixTest extends TestCase
{
    public function test_app_shell_defines_overlay_stack_above_topbar(): void
    {
        $shell = file_get_contents(public_path('css/app-shell.css'));
        $assets = file_get_contents(public_path('assets/css/app-shell.css'));

        foreach ([$shell, $assets] as $css) {
            $this->assertStringContainsString('--shell-z-topbar: 1060', $css);
            $this->assertStringContainsString('--shell-z-modal: 1120', $css);
            $this->assertStringContainsString('--shell-z-modal-backdrop: 1110', $css);
            $this->assertStringContainsString('--shell-z-swal: 1130', $css);
            $this->assertStringContainsString('.modal-backdrop', $css);
            $this->assertStringContainsString('.chat-modal.modal', $css);
            $this->assertStringContainsString('--bs-modal-zindex: var(--shell-z-modal)', $css);
        }
    }

    public function test_modal_stack_helper_is_wired_in_role_layouts(): void
    {
        $this->assertFileExists(public_path('js/modal-stack.js'));
        $this->assertFileExists(public_path('assets/js/modal-stack.js'));

        $helper = file_get_contents(public_path('js/modal-stack.js'));
        $this->assertStringContainsString('show.bs.modal', $helper);
        $this->assertStringContainsString('document.body.appendChild', $helper);

        foreach ([
            resource_path('views/publisher/layouts/app.blade.php'),
            resource_path('views/advertiser/layouts/app.blade.php'),
            resource_path('views/admin/layouts/app.blade.php'),
        ] as $layout) {
            $html = file_get_contents($layout);
            $this->assertStringContainsString('modal-stack.js', $html);
        }
    }

    public function test_order_chat_reparents_modal_to_body(): void
    {
        $js = file_get_contents(public_path('js/order-chat.js'));
        $this->assertStringContainsString('document.body.appendChild(modalEl)', $js);
        $assets = file_get_contents(public_path('assets/js/order-chat.js'));
        $this->assertStringContainsString('document.body.appendChild(modalEl)', $assets);
    }

    public function test_cart_stays_below_modals_in_stack(): void
    {
        $cart = file_get_contents(public_path('css/cart.css'));
        $this->assertStringContainsString('--shell-z-cart', $cart);
        $this->assertStringContainsString('--shell-z-cart-backdrop', $cart);
    }
}
