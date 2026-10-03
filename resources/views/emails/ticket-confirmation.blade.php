@extends('emails.layout')

@php
    $ticketId = $ticketId ?? null;
    $issue = $issue ?? null;
    $status = $status ?? null;
    $userName = $userName ?? null;
    $portalUrl = $portalUrl ?? null;
@endphp


@section('title', 'Support request received')

@section('content')
    <div style="display:inline-block;background:#eff6ff;color:#0055d4;font-size:11px;font-weight:700;padding:4px 10px;border-radius:6px;text-transform:uppercase;border:1px solid #bfdbfe;margin-bottom:16px;">
        Support request received
    </div>

    <h1 style="margin:0 0 12px 0;font-size:18px;font-weight:700;color:#0f172a;">
        @if($userName)Hello {{ $userName }}, @endif
        we have your request
    </h1>

    <p style="margin:0 0 16px 0;font-size:14px;line-height:1.6;color:#475569;">
        Our support team has received your inquiry and is reviewing it now. We will reply by email and you can follow the conversation in your support chat.
    </p>

    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:20px;margin:0 0 24px 0;font-size:13.5px;">
        @if($ticketId)
        <div style="display:flex;justify-content:space-between;gap:16px;margin-bottom:8px;">
            <span style="color:#64748b;font-weight:500;">Ticket</span>
            <span style="color:#0f172a;font-weight:600;text-align:right;">#{{ $ticketId }}</span>
        </div>
        @endif
        @if($issue)
        <div style="display:flex;justify-content:space-between;gap:16px;margin-bottom:8px;">
            <span style="color:#64748b;font-weight:500;">Issue</span>
            <span style="color:#0f172a;font-weight:600;text-align:right;">{{ $issue }}</span>
        </div>
        @endif
        @if($status)
        <div style="display:flex;justify-content:space-between;gap:16px;">
            <span style="color:#64748b;font-weight:500;">Status</span>
            <span style="color:#0f172a;font-weight:600;text-align:right;">{{ $status }}</span>
        </div>
        @endif
    </div>

    @if($portalUrl)
    <div style="text-align:center;">
        <a href="{{ $portalUrl }}" style="display:inline-block;background:#0055d4;color:#ffffff;text-decoration:none;padding:12px 28px;border-radius:10px;font-weight:600;font-size:14px;">
            View your support chat
        </a>
    </div>
    @endif
@endsection
