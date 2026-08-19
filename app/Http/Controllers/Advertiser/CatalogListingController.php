<?php

namespace App\Http\Controllers\Advertiser;

/**
 * Advertiser catalog listing HTTP surface: browse, live results,
 * bulk-deals rail, and search suggest.
 *
 * Cart, checkout (wallet / card / PayPal), and order actions stay on
 * CatalogController so listing changes do not ride the payment class.
 */
class CatalogListingController extends CatalogController {}
