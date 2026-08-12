<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Campaign\CampaignStatusService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProjectController extends Controller
{
    public function index(CampaignStatusService $statusService)
    {
        $projects = Project::where('user_id', auth()->id())
            ->latest()
            ->get();

        $statusCounts = $statusService->countsForMany($projects);
        $activeCampaignId = session('active_campaign_id');

        return view('advertiser.campaigns', compact('projects', 'statusCounts', 'activeCampaignId'));
    }

    public function show(Project $project, CampaignStatusService $statusService)
    {
        if ($project->user_id !== auth()->id()) {
            abort(403);
        }

        $counts = $statusService->countsFor($project);

        $orders = $project->orders()
            ->with(['items' => function ($q) {
                $q->select(
                    'id',
                    'order_id',
                    'site_id',
                    'site_name',
                    'site_url',
                    'price',
                    'publisher_status',
                    'modification_requested',
                    'live_url'
                );
            }])
            ->latest()
            ->paginate(20);

        $activeCampaignId = session('active_campaign_id');

        return view('advertiser.campaigns.show', compact('project', 'counts', 'orders', 'activeCampaignId'));
    }

    public function activate(Project $project)
    {
        if ($project->user_id !== auth()->id()) {
            abort(403);
        }

        session(['active_campaign_id' => $project->id]);

        $redirect = request()->input('redirect', route('advertiser.catalog'));

        return redirect($redirect)
            ->with('success', 'Shopping for campaign: '.$project->project_name);
    }

    public function deactivate()
    {
        session()->forget('active_campaign_id');

        return back()->with('success', 'Campaign context cleared.');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'project_name' => [
                'required',
                'string',
                'max:255',
                'unique:projects,project_name,NULL,id,user_id,'.auth()->id(),
            ],
            'project_url' => [
                'required',
                'url',
                'max:255',
                'unique:projects,project_url,NULL,id,user_id,'.auth()->id(),
            ],
        ]);

        $project = Project::create([
            'user_id' => auth()->id(),
            'project_name' => $validated['project_name'],
            'project_url' => $validated['project_url'],
            'slug' => Str::slug($validated['project_name']),
        ]);

        if ($request->boolean('activate') || ! session('active_campaign_id')) {
            session(['active_campaign_id' => $project->id]);
        }

        if ($request->boolean('shop')) {
            return redirect()
                ->route('advertiser.catalog')
                ->with('success', 'Campaign created. Browse placements for '.$project->project_name.'.');
        }

        return back()->with('success', 'Campaign created successfully.');
    }

    public function update(Request $request, Project $project)
    {
        if ($project->user_id !== auth()->id()) {
            abort(403);
        }

        $validated = $request->validate([
            'project_name' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-zA-Z0-9\s\-]+$/',
                'unique:projects,project_name,'.$project->id.',id,user_id,'.auth()->id(),
            ],
            'project_url' => [
                'required',
                'url',
                'max:255',
                'unique:projects,project_url,'.$project->id.',id,user_id,'.auth()->id(),
            ],
        ]);

        $project->update([
            'project_name' => $validated['project_name'],
            'project_url' => $validated['project_url'],
            'slug' => Str::slug($validated['project_name']),
        ]);

        return back()->with('success', 'Campaign updated successfully.');
    }

    public function destroy(Project $project)
    {
        if ($project->user_id !== auth()->id()) {
            abort(403);
        }

        if ((int) session('active_campaign_id') === (int) $project->id) {
            session()->forget('active_campaign_id');
        }

        $project->delete();

        return redirect()
            ->route('advertiser.campaigns')
            ->with('success', 'Campaign deleted successfully.');
    }
}
