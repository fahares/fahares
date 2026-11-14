<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The page reports that a visitor has seen a notice or followed its link. Both are counted for the
 * admin's list; for a signed-in member a seen notice is also remembered, so it stays away on their
 * other devices. Nothing about a guest is stored.
 */
class AnnouncementEventController extends Controller
{
    public function store(Request $request, Announcement $announcement)
    {
        $event = $request->validate(['event' => 'required|in:view,click'])['event'];

        if ($announcement->isRunning()) {
            Announcement::whereKey($announcement->id)->increment($event === 'click' ? 'clicks_count' : 'views_count');
        }
        if ($request->user()) {
            DB::table('announcement_user')->insertOrIgnore([
                'announcement_id' => $announcement->id, 'user_id' => $request->user()->id, 'seen_at' => now(),
            ]);
            // The same notice in the member's own notifications is no longer new
            $request->user()->unreadNotifications()->where('data->announcement_id', $announcement->id)->update(['read_at' => now()]);
        }

        return response()->noContent();
    }
}
