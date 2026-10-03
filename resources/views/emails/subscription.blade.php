@extends('emails.layout')

@php
    $subject = $subject ?? null;
    $badge = $badge ?? null;
    $headline = $headline ?? null;
    $description = $description ?? null;
    $bullets = $bullets ?? null;
    $portalUrl = $portalUrl ?? null;
@endphp


@section('title', $subject)

@section('content')
    <div style="display:inline-block;background:#eff6ff;color:#0055d4;font-size:11px;font-weight:700;padding:4px 10px;border-radius:6px;text-transform:uppercase;border:1px solid #bfdbfe;margin-bottom:16px;">
        {{ $badge }}
    </div>

    <h1 style="margin:0 0 12px 0;font-size:18px;font-weight:700;color:#0f172a;">{{ $headline }}</h1>

    <p style="margin:0 0 16px 0;font-size:14px;line-height:1.6;color:#475569;">{{ $description }}</p>

    @if($bullets)
    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:20px;margin:0 0 24px 0;">
        @foreach($bullets as $bullet)
        <div style="display:flex;gap:10px;margin-bottom:8px;font-size:14px;color:#334155;">
            <span style="color:#24a148;font-weight:700;">&#10003;</span>
            <span>{{ $bullet }}</span>
        </div>
        @endforeach
    </div>
    @endif

    @if($portalUrl)
    <div style="text-align:center;">
        <a href="{{ $portalUrl }}" style="display:inline-block;background:#0055d4;color:#ffffff;text-decoration:none;padding:12px 28px;border-radius:10px;font-weight:600;font-size:14px;">
            Start exploring
        </a>
    </div>
    @endif

    <p style="margin:24px 0 0 0;font-size:12px;color:#94a3b8;">
        You are receiving this because you subscribed at fastnetstays.com.
        You can change your communication preferences at any time from your account.
    </p>
@endsection
