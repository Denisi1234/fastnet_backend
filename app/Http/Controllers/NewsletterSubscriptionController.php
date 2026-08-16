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
        ], [
            'email.unique' => 'This email is already subscribed!',
        ]);

        $subscription = NewsletterSubscription::create([
            'email' => $validated['email'],
        ]);

        // Send confirmation email via Resend Mail Service
        ResendMailService::sendSubscriptionEmail($subscription->email);

        return response()->json([
            'status' => 'success',
            'message' => 'Subscribed successfully! Thank you for joining our newsletter.'
        ], 201);
    }
}
