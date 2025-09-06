<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Channel;

class ChannelOrderController extends Controller
{
    public function reorder(Request $request)
    {
        $channelIds = $request->input('ids', []);
        if (empty($channelIds) || !is_array($channelIds)) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => 'No channels provided for sorting.'], 422);
            }
            return back()->with('error', 'No channels provided for sorting.');
        }

        foreach ($channelIds as $index => $id) {
            Channel::where('id', $id)->update(['sort' => $index]);
        }

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => 'Channels order updated.']);
        }
        return back()->with('success', 'Channels order updated.');
    }
}

