<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function threads(Request $request)
    {
        $userId = $request->user()->id;

        $messages = Message::where('sender_id', $userId)
            ->orWhere('recipient_id', $userId)
            ->orderBy('created_at', 'desc')
            ->get();

        $partnerIds = $messages
            ->map(fn ($msg) => $msg->sender_id == $userId ? $msg->recipient_id : $msg->sender_id)
            ->unique()
            ->filter()
            ->values()
            ->all();

        // One query for every conversation partner instead of one per thread.
        $partners = $partnerIds
            ? User::whereIn('id', $partnerIds)->get()->keyBy('id')
            : collect();

        $threadsMap = [];
        foreach ($messages as $msg) {
            $partnerId = $msg->sender_id == $userId ? $msg->recipient_id : $msg->sender_id;
            if (!isset($threadsMap[$partnerId]) && $partners->has($partnerId)) {
                $partner = $partners->get($partnerId);
                $threadsMap[$partnerId] = [
                    'partner_id' => $partner->id,
                    'partner_name' => $partner->name,
                    'partner_role' => $partner->role,
                    'lodge_name' => $msg->lodge_name,
                    'last_message' => $msg->text,
                    'unread' => $msg->recipient_id == $userId ? $msg->unread : false,
                    'time' => $msg->created_at->diffForHumans(),
                ];
            }
        }

        return response()->json(array_values($threadsMap));
    }
    
    public function index(Request $request, $partnerId)
    {
        $userId = $request->user()->id;

        $messages = Message::where(function($q) use ($userId, $partnerId) {
            $q->where('sender_id', $userId)->where('recipient_id', $partnerId);
        })->orWhere(function($q) use ($userId, $partnerId) {
            $q->where('sender_id', $partnerId)->where('recipient_id', $userId);
        })->orderBy('created_at', 'asc')->get();
        
        Message::where('sender_id', $partnerId)
            ->where('recipient_id', $userId)
            ->where('unread', true)
            ->update(['unread' => false]);
            
        return response()->json($messages);
    }
    
    public function store(Request $request)
    {
        $request->validate([
            'recipient_id' => 'required|exists:users,id',
            'lodge_name' => 'required|string',
            'text' => 'required|string',
        ]);
        
        $userId = $request->user()->id;
        
        $message = Message::create([
            'sender_id' => $userId,
            'recipient_id' => $request->recipient_id,
            'lodge_name' => $request->lodge_name,
            'text' => $request->text,
            'unread' => true,
        ]);
        
        // Removed: this inserted a canned "Habari! Thank you for messaging…"
        // row as if the host had written it. It persisted in the real
        // messages table, showed up in GET /messages/threads as genuine host
        // correspondence, and could not be told apart from a real reply.
        // Real replies arrive via the host's own session.

        return response()->json($message, 201);
    }
}
