<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\NewsletterSubscription;
use App\Services\ResendMailService;

class NewsletterSubscriptionController extends Controller
{
    public function subscribe(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email|unique:newsletter_subscriptions,email',
            'type'  => 'nullable|string|in:general,careers',
        ], [
            'email.unique' => 'This email is already subscribed for updates!',
        ]);

        $subscription = NewsletterSubscription::create([
            'email' => $validated['email'],
        ]);

        $type = $request->input('type', 'general');

        // Send real confirmation email via Resend Mail Service
        ResendMailService::sendSubscriptionEmail($subscription->email, $type);

        $msg = ($type === 'careers')
            ? 'Subscribed successfully! You will receive an alert as soon as career opportunities launch.'
            : 'Subscribed successfully! Thank you for joining our updates.';

        return response()->json([
            'status' => 'success',
            'message' => $msg
        ], 201);
    }
}
