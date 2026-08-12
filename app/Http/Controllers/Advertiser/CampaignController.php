<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;

/**
 * Legacy entrypoints kept for old links; campaign hub lives on ProjectController.
 */
class CampaignController extends Controller
{
    public function websites(Project $project): RedirectResponse
    {
        if ($project->user_id !== auth()->id()) {
            abort(403);
        }

        return redirect()->route('advertiser.campaigns.show', $project);
    }
}
