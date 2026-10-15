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

        $quoted = [];
        $quoteIds = $messages->pluck('reply_to_id')->filter()->unique()->values()->all();
        if (!empty($quoteIds)) {
            $quoted = Message::whereIn('id', $quoteIds)->with('sender:id,name')->get()->keyBy('id');
        }
        
        Message::where('sender_id', $partnerId)
            ->where('recipient_id', $userId)
            ->where('unread', true)
            ->update(['unread' => false]);

        return response()->json($messages->map(function ($m) use ($quoted) {
            $row = $m->toArray();
            if ($m->reply_to_id && isset($quoted[$m->reply_to_id])) {
                $q = $quoted[$m->reply_to_id];
                $row['reply_to'] = [
                    'id' => $q->id,
                    'text' => $q->text,
                    'sender_name' => $q->sender->name ?? 'Guest',
                ];
            }
            return $row;
        })->values()->all());
    }
    
    public function store(Request $request)
    {
        $request->validate([
            'recipient_id' => 'required|exists:users,id',
            'lodge_name' => 'required|string',
            'text' => 'required|string|max:2000',
            'reply_to_id' => 'nullable|integer|exists:messages,id',
        ]);
        
        $userId = $request->user()->id;

        if ((int)$request->recipient_id === (int)$userId) {
            return response()->json(['message' => 'You cannot message yourself.'], 422);
        }

        // A quote must point at a message inside this same conversation —
        // never at a stranger's row.
        $replyToId = $request->reply_to_id ? (int)$request->reply_to_id : null;
        $quoted = null;
        if ($replyToId) {
            $quoted = Message::find($replyToId);
            $inThread = $quoted && (
                ($quoted->sender_id == $userId && $quoted->recipient_id == $request->recipient_id) ||
                ($quoted->sender_id == $request->recipient_id && $quoted->recipient_id == $userId)
            );
            if (!$inThread) {
                return response()->json(['message' => 'Quoted message not found in this conversation.'], 422);
            }
        }
        
        $message = Message::create([
            'sender_id' => $userId,
            'recipient_id' => $request->recipient_id,
            'lodge_name' => $request->lodge_name,
            'text' => $request->text,
            'reply_to_id' => $replyToId,
            'unread' => true,
        ]);
        
        // Removed: this inserted a canned "Habari! Thank you for messaging…"
        // row as if the host had written it. It persisted in the real
        // messages table, showed up in GET /messages/threads as genuine host
        // correspondence, and could not be told apart from a real reply.
        // Real replies arrive via the host's own session.

        $out = $message->toArray();
        if (!empty($quoted)) {
            $out['reply_to'] = [
                'id' => $quoted->id,
                'text' => $quoted->text,
                'sender_name' => $quoted->sender->name ?? 'Guest',
            ];
        }

        return response()->json($out, 201);
    }
}
