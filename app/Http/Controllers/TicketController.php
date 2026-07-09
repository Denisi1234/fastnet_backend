<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    public function index(Request $request)
    {
        $userId = $request->user()->id;
        
        if ($request->user()->role === 'admin' || $request->user()->role === 'owner') {
            $tickets = Ticket::with('user')->orderBy('updated_at', 'desc')->get();
        } else {
            $tickets = Ticket::where('user_id', $userId)->orderBy('updated_at', 'desc')->get();
        }

        return response()->json($tickets);
    }

    public function store(Request $request)
    {
        $request->validate([
            'issue' => 'required|string',
            'description' => 'required|string',
            'initial_message' => 'required|string',
        ]);

        $user = $request->user();

        $messages = [
            [
                'sender' => $user->role === 'owner' ? 'Host' : 'User',
                'text' => $request->initial_message,
                'time' => 'Just now',
            ]
        ];

        $ticket = Ticket::create([
            'user_id' => $user->id,
            'issue' => $request->issue,
            'description' => $request->description,
            'status' => 'Open',
            'messages' => $messages,
        ]);

        return response()->json($ticket, 201);
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:Open,In Progress,Resolved',
        ]);

        $ticket = Ticket::findOrFail($id);
        $ticket->update(['status' => $request->status]);

        return response()->json($ticket);
    }

    public function sendMessage(Request $request, $id)
    {
        $request->validate([
            'text' => 'required|string',
        ]);

        $ticket = Ticket::findOrFail($id);
        $user = $request->user();

        $messages = $ticket->messages ?? [];
        $messages[] = [
            'sender' => $user->role === 'owner' ? 'Host' : 'User',
            'text' => $request->text,
            'time' => 'Just now',
        ];

        // Simulate auto-reply response on new messages
        if ($user->role !== 'owner' && $user->role !== 'admin' && $ticket->status !== 'Resolved') {
            $replyText = "Hello! A member of our Lodge Support Team has been assigned to your ticket and will look into this right away.";
            if (str_contains(strtolower($request->text), 'cancel')) {
                $replyText = "We are deeply sorry to hear about the cancellation. We are searching for nearby alternative lodges and checking refund eligibility.";
            } else if (str_contains(strtolower($request->text), 'refund') || str_contains(strtolower($request->text), 'fee')) {
                $replyText = "Your refund request has been logged. We are contacting the property host to verify details and process the adjustment.";
            }

            $messages[] = [
                'sender' => 'Agent',
                'text' => $replyText,
                'time' => 'Just now',
            ];
            $ticket->status = 'In Progress';
        }

        $ticket->update(['messages' => $messages]);

        return response()->json($ticket);
    }
}
