<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    public function index()
    {
        $userId = (int) auth()->id();

        $projects = Project::where('user_id', $userId)
            ->latest()
            ->get();

        $orders = Order::query()
            ->where('user_id', $userId)
            ->with('items')
            ->get();

        $countsByHost = Project::stageCountsByHost($orders);

        foreach ($projects as $project) {
            $host = Project::hostFromUrl($project->project_url);
            $project->setAttribute(
                'stage_counts',
                $countsByHost[$host] ?? Project::emptyStageCounts()
            );
        }

        return view('advertiser.campaigns', compact('projects'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'project_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('projects', 'project_name')->where('user_id', auth()->id()),
            ],
            'project_url' => [
                'required',
                'url',
                'max:255',
                Rule::unique('projects', 'project_url')->where('user_id', auth()->id()),
            ],
        ]);

        Project::create([
            'user_id' => auth()->id(),
            'project_name' => $validated['project_name'],
            'project_url' => $validated['project_url'],
        ]);

        return back()->with('success', 'Project created successfully.');
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
                'regex:/^[a-zA-Z0-9\s\-]+$/', // clean names only
                Rule::unique('projects', 'project_name')
                    ->where('user_id', auth()->id())
                    ->ignore($project->id),
            ],
            'project_url' => [
                'required',
                'url',
                'max:255',
                Rule::unique('projects', 'project_url')
                    ->where('user_id', auth()->id())
                    ->ignore($project->id),
            ],
        ]);

        $project->update([
            'project_name' => $validated['project_name'],
            'project_url' => $validated['project_url'],
        ]);

        return back()->with('success', 'Project updated successfully.');
    }

    public function destroy(Project $project)
    {
        if ($project->user_id !== auth()->id()) {
            abort(403);
        }

        $project->delete();

        return back()->with('success', 'Project deleted successfully.');
    }
}
