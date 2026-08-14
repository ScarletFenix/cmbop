<?php

return [

    /*
    | Advertiser welcome credit granted on first qualifying signup.
    | Admin can disable this from Promotions Center without a deploy.
    */
    'amount' => 20.00,

    'enabled_default' => true,

    'cookie_name' => 'slb_welcome_claimed',

    /*
    | Minutes the browser cookie lasts after a successful claim.
    | 365 days — same browser cannot collect the bonus again after clearing nothing.
    */
    'cookie_minutes' => 525600,

];
