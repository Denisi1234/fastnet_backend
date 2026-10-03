<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\User;
use App\Services\ResendMailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TicketController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $userId = $user ? $user->id : null;
        
        if ($user && ($user->role === 'admin' || $user->role === 'owner')) {
            $tickets = Ticket::with('user')->orderBy('updated_at', 'desc')->get();
        } else if ($userId) {
            $tickets = Ticket::where('user_id', $userId)
                ->orderBy('updated_at', 'desc')
                ->get();
        } else {
            $tickets = Ticket::orderBy('updated_at', 'desc')->get();
        }

        return response()->json($tickets);
    }

    public function show(Request $request, $id)
    {
        $ticket = Ticket::with('user')->find($id);

        if (!$ticket) {
            return response()->json(['message' => 'Support ticket not found'], 404);
        }

        return response()->json($ticket);
    }

    public function store(Request $request)
    {
        $request->validate([
            'issue' => 'required|string',
            'description' => 'required|string',
            'initial_message' => 'nullable|string',
            'email' => 'nullable|email',
            'name' => 'nullable|string',
        ]);

        $user = $request->user();

        // Previously fell back to user id 1, filing every guest's support
        // ticket against whichever account was first in the table.
        $userId = $user ? $user->id : null;

        $guestEmail = $request->email ?? ($user ? $user->email : null);
        $guestName = $request->name ?? ($user ? $user->name : 'Traveler');

        // If email wasn't sent directly, try extracting from description (e.g. Contact: John (john@example.com))
        if (!$guestEmail && preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $request->description, $matches)) {
            $guestEmail = $matches[0];
        }

        $initialText = $request->initial_message ?? $request->description;

        $messages = [
            [
                'sender' => ($user && $user->role === 'owner') ? 'Host' : 'User',
                'name' => $guestName,
                'text' => $initialText,
                'time' => date('h:i A'),
            ]
        ];

        $ticket = Ticket::create([
            'user_id' => $userId,
            'issue' => $request->issue,
            'description' => $request->description,
            'status' => 'Open',
            'messages' => $messages,
        ]);

        // Send Email Confirmation to the User
        if ($guestEmail) {
            try {
                ResendMailService::sendTicketConfirmationEmail($ticket, $guestEmail, $guestName);
            } catch (\Exception $e) {
                Log::error("Failed to send ticket confirmation email: " . $e->getMessage());
            }
        }

        return response()->json($ticket, 201);
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:Open,In Progress,Resolved',
        ]);

        $ticket = Ticket::with('user')->findOrFail($id);
        $oldStatus = $ticket->status;
        $newStatus = $request->status;

        $ticket->update(['status' => $newStatus]);

        // If status changed to Resolved, send Resolved Email notification to User
        if ($newStatus === 'Resolved' && $oldStatus !== 'Resolved') {
            $userEmail = $ticket->user ? $ticket->user->email : null;
            $userName = $ticket->user ? $ticket->user->name : 'Traveler';

            if (!$userEmail && preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $ticket->description, $matches)) {
                $userEmail = $matches[0];
            }

            if ($userEmail) {
                try {
                    ResendMailService::sendTicketResolvedEmail($ticket, $userEmail, $userName);
                } catch (\Exception $e) {
                    Log::error("Failed to send ticket resolved email: " . $e->getMessage());
                }
            }
        }

        return response()->json($ticket);
    }

    public function sendMessage(Request $request, $id)
    {
        $request->validate([
            'text' => 'nullable|string',
            'message' => 'nullable|string',
        ]);

        $text = $request->text ?? $request->message;
        if (empty($text)) {
            return response()->json(['message' => 'Message text is required'], 422);
        }

        $ticket = Ticket::with('user')->findOrFail($id);
        $user = $request->user();
        $isStaff = $user && ($user->role === 'admin' || $user->role === 'owner');

        $messages = $ticket->messages ?? [];

        if ($isStaff) {
            $senderLabel = 'Agent';
            $senderName = $user->name ?? 'Support Specialist';
            $ticket->status = 'In Progress';
        } else {
            $senderLabel = 'User';
            $senderName = $user ? $user->name : 'Traveler';
        }

        $messages[] = [
            'sender' => $senderLabel,
            'name' => $senderName,
            'text' => $text,
            'time' => date('h:i A'),
        ];

        $ticket->messages = $messages;
        $ticket->updated_at = now();
        $ticket->save();

        return response()->json($ticket);
    }
}
