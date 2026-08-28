<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AccessibilityFeedback;

class AccessibilityFeedbackController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'comment'   => 'required|string|max:300',
            'page_url'  => 'nullable|string|max:255',
        ]);

        $feedback = AccessibilityFeedback::create([
            'comment'    => $validated['comment'],
            'page_url'   => $request->input('page_url', 'legal/accessibility.html'),
            'user_agent' => $request->userAgent(),
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Thank you! Your accessibility feedback has been submitted directly to our product engineering team.',
            'data'    => $feedback
        ], 201);
    }
}
