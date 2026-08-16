<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteRating;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SiteRatingController extends Controller
{
    public function index(Request $request)
    {
        $ratings = new LengthAwarePaginator([], 0, 30);
        $ratings->withPath($request->url())->appends($request->query());
        $sites = collect();

        try {
            if (Schema::hasTable('site_ratings')) {
                $query = SiteRating::query()
                    ->with(['site:'.implode(',', $this->siteRelationSelectColumns()), 'user:id,name,email'])
                    ->latest('id');

                $status = is_string($request->query('status')) ? $request->query('status') : '';
                if (in_array($status, [SiteRating::STATUS_APPROVED, SiteRating::STATUS_HIDDEN, SiteRating::STATUS_PENDING], true)) {
                    $query->where('status', $status);
                }
                if ($request->filled('site_id')) {
                    $query->where('site_id', (int) $request->site_id);
                }
                $q = is_string($request->query('q')) ? trim($request->query('q')) : '';
                if ($q !== '') {
                    $query->where(function ($inner) use ($q) {
                        $inner->where('comment', 'like', "%{$q}%")
                            ->orWhereHas('site', function ($s) use ($q) {
                                $s->where('site_name', 'like', "%{$q}%")
                                    ->orWhere('domain', 'like', "%{$q}%");
                            })
                            ->orWhereHas('user', function ($u) use ($q) {
                                $u->where('name', 'like', "%{$q}%")
                                    ->orWhere('email', 'like', "%{$q}%");
                            });
                    });
                }

                $ratings = $query->paginate(30)->withQueryString();
            }

            $sites = Site::query()->orderBy('site_name')->get(['id', 'site_name', 'domain']);
        } catch (\Throwable $e) {
            Log::warning('Admin site ratings index failed', [
                'error' => $e->getMessage(),
            ]);
            $ratings = new LengthAwarePaginator([], 0, 30);
            $ratings->withPath($request->url())->appends($request->query());
        }

        return view('admin.site-ratings', compact('ratings', 'sites'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'site_id' => 'required|exists:sites,id',
            'user_id' => 'nullable|exists:users,id',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:500',
            'status' => 'required|in:approved,hidden,pending',
        ]);

        $rating = SiteRating::create([
            'site_id' => (int) $data['site_id'],
            'user_id' => $data['user_id'] ?? auth()->id(),
            'rating' => (int) $data['rating'],
            'comment' => $data['comment'] ?? null,
            'status' => $data['status'],
            'is_admin' => true,
        ]);

        SiteRating::refreshSiteAggregate((int) $data['site_id']);

        $this->logRatingActivity(
            'site.rating_saved',
            (auth()->user()->name ?? 'Staff').' saved a rating for site #'.$data['site_id'],
            $rating->site,
            ['rating_id' => $rating->id, 'rating' => $rating->rating, 'status' => $rating->status],
            $rating->site?->site_name
        );

        return response()->json([
            'success' => true,
            'message' => 'Rating saved',
            'rating' => $rating->load(['site:id,site_name,domain', 'user:id,name,email']),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $rating = SiteRating::findOrFail($id);

        $data = $request->validate([
            'rating' => 'sometimes|integer|min:1|max:5',
            'comment' => 'nullable|string|max:500',
            'status' => 'sometimes|in:approved,hidden,pending',
        ]);

        $rating->fill($data);
        $rating->save();
        $changed = $rating->wasChanged();

        SiteRating::refreshSiteAggregate($rating->site_id);

        if ($changed) {
            $this->logRatingActivity(
                'site.rating_updated',
                (auth()->user()->name ?? 'Staff').' updated rating #'.$rating->id,
                $rating->site,
                $data,
                $rating->site?->site_name
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Rating updated',
            'rating' => $rating->fresh(['site:id,site_name,domain', 'user:id,name,email']),
        ]);
    }

    public function destroy(int $id)
    {
        $rating = SiteRating::findOrFail($id);
        $siteId = $rating->site_id;
        $siteName = $rating->site?->site_name;

        $rating->delete();
        SiteRating::refreshSiteAggregate($siteId);

        $this->logRatingActivity(
            'site.rating_deleted',
            (auth()->user()->name ?? 'Staff').' deleted a site rating',
            null,
            ['site_id' => $siteId, 'rating_id' => $id],
            $siteName
        );

        return response()->json([
            'success' => true,
            'message' => 'Rating deleted',
        ]);
    }

    /**
     * Activity log is best-effort — a missing table must not turn a saved
     * rating into a failed response (retry would insert another staff row).
     *
     * @param  array<string, mixed>  $properties
     */
    private function logRatingActivity(
        string $action,
        string $description,
        ?Site $subject,
        array $properties,
        ?string $subjectLabel
    ): void {
        ActivityLogger::tryLog($action, $description, $subject, $properties, $subjectLabel);
    }

    /**
     * @return list<string>
     */
    private function siteRelationSelectColumns(): array
    {
        $columns = ['id', 'site_name', 'domain', 'site_url'];
        foreach (['rating_avg', 'rating_count'] as $optional) {
            if (Site::hasSitesColumn($optional)) {
                $columns[] = $optional;
            }
        }

        return $columns;
    }
}
