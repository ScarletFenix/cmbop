<?php

return [

    /*
    | Dated "New" pills for just-shipped UI. After `until` (inclusive, app
    | timezone end of day) the badge renders nothing. Omit `until` to keep it
    | on until you delete the key. Set enabled => false for an instant hide.
    */
    'add_funds.paypal' => [
        'label' => 'New',
        'until' => '2026-09-30',
    ],

    'checkout.paypal' => [
        'label' => 'New',
        'until' => '2026-09-30',
    ],

];
