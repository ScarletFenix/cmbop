<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteAnnouncement;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AnnouncementController extends Controller
{
    public function index()
    {
        $announcements = SiteAnnouncement::query()
            ->latest('id')
            ->paginate(20);

        return view('admin.promotions.announcements.index', compact('announcements'));
    }

    public function create()
    {
        return view('admin.promotions.announcements.form', [
            'announcement' => new SiteAnnouncement([
                'type' => 'offer',
                'style' => 'promo',
                'audience' => 'all',
                'is_active' => true,
                'is_dismissible' => true,
                'priority' => 100,
            ]),
            'mode' => 'create',
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['created_by'] = auth()->id();

        SiteAnnouncement::create($data);

        return redirect()
            ->route('admin.promotions.announcements.index')
            ->with('success', 'Announcement created.');
    }

    public function edit(SiteAnnouncement $announcement)
    {
        return view('admin.promotions.announcements.form', [
            'announcement' => $announcement,
            'mode' => 'edit',
        ]);
    }

    public function update(Request $request, SiteAnnouncement $announcement)
    {
        $announcement->update($this->validated($request));

        return redirect()
            ->route('admin.promotions.announcements.index')
            ->with('success', 'Announcement updated.');
    }

    public function destroy(SiteAnnouncement $announcement)
    {
        $announcement->delete();

        return redirect()
            ->route('admin.promotions.announcements.index')
            ->with('success', 'Announcement deleted.');
    }

    public function toggle(SiteAnnouncement $announcement)
    {
        $announcement->update(['is_active' => !$announcement->is_active]);

        return back()->with('success', $announcement->is_active ? 'Announcement activated.' : 'Announcement paused.');
    }

    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'message' => ['required', 'string', 'max:2000'],
            'type' => ['required', Rule::in(array_keys(config('promotions.announcement_types', [])))],
            'style' => ['required', Rule::in(array_keys(config('promotions.announcement_styles', [])))],
            'audience' => ['required', Rule::in(array_keys(config('promotions.audiences', [])))],
            'cta_label' => ['nullable', 'string', 'max:80'],
            'cta_url' => ['nullable', 'url', 'max:500'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
            'is_dismissible' => ['sometimes', 'boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active');
        $data['is_dismissible'] = $request->boolean('is_dismissible');
        $data['priority'] = (int) ($data['priority'] ?? 100);

        return $data;
    }
}
