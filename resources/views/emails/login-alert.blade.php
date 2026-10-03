@extends('emails.layout')

@php
    $accountEmail = $accountEmail ?? null;
    $signedInAt = $signedInAt ?? null;
@endphp


@section('title', 'New sign-in')

@section('content')
    <div style="display:inline-block;background:#fff1f1;color:#da1e28;font-weight:700;font-size:12px;padding:4px 12px;border-radius:20px;border:1px solid #fecaca;margin-bottom:16px;">
        SECURITY ALERT
    </div>

    <h1 style="margin:0 0 16px 0;font-size:20px;font-weight:700;color:#0f172a;">New sign-in to your account</h1>

    <p style="margin:0 0 20px 0;font-size:15px;color:#475569;">
        Your FastNetStays account was just signed in. If this was you, no action is needed.
    </p>

    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px;margin:0 0 24px 0;">
        @if($accountEmail)
        <div style="display:flex;justify-content:space-between;gap:16px;margin-bottom:10px;font-size:14px;">
            <span style="color:#64748b;font-weight:600;">Account</span>
            <span style="color:#0f172a;font-weight:700;text-align:right;">{{ $accountEmail }}</span>
        </div>
        @endif
        @if($signedInAt)
        <div style="display:flex;justify-content:space-between;gap:16px;font-size:14px;">
            <span style="color:#64748b;font-weight:600;">Signed in at</span>
            <span style="color:#0f172a;font-weight:700;text-align:right;">{{ $signedInAt }}</span>
        </div>
        @endif
    </div>

    <p style="margin:0;font-size:14px;color:#475569;">
        If this wasn't you, reset your password immediately and contact us at
        <a href="mailto:security@fastnetstays.com" style="color:#006CE4;">security@fastnetstays.com</a>.
    </p>
@endsection
