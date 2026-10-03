@extends('emails.layout')

@php
    $otpCode = $otpCode ?? null;
    $customerName = $customerName ?? null;
    $purpose = $purpose ?? null;
@endphp


@section('title', 'Your verification code')

@section('content')
    <h1 style="margin:0 0 20px 0;font-size:20px;font-weight:700;color:#0f172a;">Your verification code</h1>

    @if($customerName)
    <p style="margin:0 0 16px 0;font-size:15px;color:#334155;">Hi {{ $customerName }},</p>
    @endif

    <p style="margin:0 0 24px 0;font-size:15px;color:#334155;">
        We received a request to {{ $purpose }}. Use the code below to continue.
    </p>

    <p style="margin:0 0 12px 0;font-size:14px;font-weight:600;color:#64748b;">Your verification code is:</p>

    <div style="text-align:center;padding:4px 0 28px 0;">
        <div style="background-color:#f1f5f9;border-radius:8px;padding:16px 28px;display:inline-block;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:32px;font-weight:800;letter-spacing:8px;color:#002155;">
            {{ $otpCode }}
        </div>
    </div>

    <p style="margin:0 0 16px 0;font-size:14px;color:#475569;">
        This code expires in <strong>10 minutes</strong>. For your security, do not share it with anyone.
    </p>

    <p style="margin:0;font-size:14px;color:#64748b;">
        If you didn't request this code, you can safely ignore this email.
    </p>
@endsection
