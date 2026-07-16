<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\Wallet;
use App\Services\ActivityLogger;
use App\Services\SitePromotionService;
use Illuminate\Http\Request;

class SitePromotionController extends Controller
{
    public function __construct(private readonly SitePromotionService $promotions)
    {
    }

    public function feature(Request $request, int $id)
    {
        $site = Site::where('publisher_id', auth()->id())->findOrFail($id);
        $result = $this->promotions->featureWithWallet($site, auth()->user());

        if ($result['success'] ?? false) {
            ActivityLogger::log(
                'site.featured',
                auth()->user()->name.' featured "'.$site->site_name.'"',
                $site,
                ['days' => $this->promotions->featureDays(), 'price' => $this->promotions->featurePrice()],
                $site->site_name
            );
        }

        return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
    }

    public function walletSummary()
    {
        $roleId = Wallet::publisherRoleId();
        $balance = 0.0;
        if ($roleId) {
            $wallet = Wallet::where('user_id', auth()->id())->where('role_id', $roleId)->first();
            $balance = (float) ($wallet?->balance ?? 0);
        }

        return response()->json([
            'success' => true,
            'balance' => $balance,
            'feature_price' => $this->promotions->featurePrice(),
            'feature_days' => $this->promotions->featureDays(),
            'top_up_url' => route('advertiser.add-funds'),
            'balance_url' => route('publisher.balance'),
            'hint' => 'Pay from publisher earnings. Short on funds? Top up via Add Funds (payment methods), then transfer to your publisher wallet from Balance.',
        ]);
    }

    public function joinBulk(Request $request, int $id)
    {
        $site = Site::where('publisher_id', auth()->id())->findOrFail($id);
        $data = $request->validate([
            'percent' => 'required|numeric|min:'.config('site_promotions.bulk.min_percent', 10)
                .'|max:'.config('site_promotions.bulk.max_percent', 15),
        ]);

        $site = $this->promotions->joinBulkDiscount($site, (float) $data['percent']);

        return response()->json([
            'success' => true,
            'message' => 'Joined bulk discount program ('.rtrim(rtrim(number_format((float) $site->bulk_discount_percent, 2), '0'), '.').'% on 3–5 articles).',
            'site' => $site,
        ]);
    }

    public function leaveBulk(int $id)
    {
        $site = Site::where('publisher_id', auth()->id())->findOrFail($id);
        $site = $this->promotions->leaveBulkDiscount($site);

        return response()->json([
            'success' => true,
            'message' => 'Left the bulk discount program.',
            'site' => $site,
        ]);
    }

    public function setDiscount(Request $request, int $id)
    {
        $site = Site::where('publisher_id', auth()->id())->findOrFail($id);
        $data = $request->validate([
            'percent' => 'required|numeric|min:'.config('site_promotions.custom_discount.min_percent', 1)
                .'|max:'.config('site_promotions.custom_discount.max_percent', 70),
            'days' => 'required|integer|min:1|max:'.config('site_promotions.custom_discount.max_days', 90),
        ]);

        $site = $this->promotions->setCustomDiscount($site, (float) $data['percent'], (int) $data['days']);

        ActivityLogger::log(
            'site.discount_set',
            auth()->user()->name.' set a '.$data['percent'].'% discount on "'.$site->site_name.'" for '.$data['days'].' days',
            $site,
            $data,
            $site->site_name
        );

        return response()->json([
            'success' => true,
            'message' => 'Discount live for '.$data['days'].' day(s). You’ll get an email when it ends.',
            'site' => $site,
        ]);
    }

    public function clearDiscount(int $id)
    {
        $site = Site::where('publisher_id', auth()->id())->findOrFail($id);
        $site = $this->promotions->clearCustomDiscount($site);

        return response()->json([
            'success' => true,
            'message' => 'Custom discount removed.',
            'site' => $site,
        ]);
    }
}
