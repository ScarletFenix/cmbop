<?php

namespace App\Http\Controllers;

use App\Services\Marketing\CatalogTeaserService;

class MarketingPageController extends Controller
{
    public function about()
    {
        return view('pages.about');
    }

    public function faq()
    {
        return view('pages.faq');
    }

    public function pricing()
    {
        return view('pages.pricing');
    }

    public function marketplace(CatalogTeaserService $teasers)
    {
        return view('pages.marketplace', [
            'teasers' => $teasers->teasers(8),
        ]);
    }

    public function howItWorks()
    {
        return view('pages.how-it-works');
    }

    public function becomePublisher()
    {
        return view('pages.become-a-publisher');
    }

    public function whyChooseUs()
    {
        return view('pages.why-choose-us');
    }

    public function cookiePolicy()
    {
        return view('pages.cookie-policy');
    }

    public function refundPolicy()
    {
        return view('pages.refund-policy');
    }
}
