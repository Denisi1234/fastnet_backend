@extends('emails.layout')

@php
    $guestName = $guestName ?? null;
    $propertyName = $propertyName ?? null;
    $propertyAddress = $propertyAddress ?? null;
    $bookingCode = $bookingCode ?? null;
    $checkIn = $checkIn ?? null;
    $checkOut = $checkOut ?? null;
    $totalFormatted = $totalFormatted ?? null;
    $receiptUrl = $receiptUrl ?? null;
    $bookingsUrl = $bookingsUrl ?? null;
@endphp


@section('title', 'Booking confirmed')

@section('content')
    <div style="display:inline-block;background:#ecfdf5;color:#059669;font-weight:700;font-size:12px;padding:4px 12px;border-radius:20px;border:1px solid #a7f3d0;margin-bottom:16px;">
        BOOKING CONFIRMED
    </div>

    <h1 style="margin:0 0 16px 0;font-size:20px;font-weight:700;color:#0f172a;">
        @if($guestName)
            Hello {{ $guestName }}, your stay is confirmed
        @else
            Your stay is confirmed
        @endif
    </h1>

    <p style="margin:0 0 20px 0;font-size:15px;color:#475569;">
        Thank you for booking with FastNetStays. Your reservation
        @if($propertyName) at <strong>{{ $propertyName }}</strong> @endif
        has been confirmed. Here are your booking details.
    </p>

    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px;margin:0 0 24px 0;">

        @if($bookingCode)
        <div style="display:flex;justify-content:space-between;gap:16px;margin-bottom:10px;font-size:14px;">
            <span style="color:#64748b;font-weight:600;">Booking reference</span>
            <span style="color:#006CE4;font-weight:700;text-align:right;">{{ $bookingCode }}</span>
        </div>
        @endif

        @if($propertyName)
        <div style="display:flex;justify-content:space-between;gap:16px;margin-bottom:10px;font-size:14px;">
            <span style="color:#64748b;font-weight:600;">Property</span>
            <span style="color:#0f172a;font-weight:700;text-align:right;">{{ $propertyName }}</span>
        </div>
        @endif

        @if($propertyAddress)
        <div style="display:flex;justify-content:space-between;gap:16px;margin-bottom:10px;font-size:14px;">
            <span style="color:#64748b;font-weight:600;">Location</span>
            <span style="color:#0f172a;font-weight:700;text-align:right;">{{ $propertyAddress }}</span>
        </div>
        @endif

        @if($checkIn)
        <div style="display:flex;justify-content:space-between;gap:16px;margin-bottom:10px;font-size:14px;">
            <span style="color:#64748b;font-weight:600;">Check-in</span>
            <span style="color:#0f172a;font-weight:700;text-align:right;">{{ $checkIn }}</span>
        </div>
        @endif

        @if($checkOut)
        <div style="display:flex;justify-content:space-between;gap:16px;font-size:14px;">
            <span style="color:#64748b;font-weight:600;">Check-out</span>
            <span style="color:#0f172a;font-weight:700;text-align:right;">{{ $checkOut }}</span>
        </div>
        @endif

        @if($totalFormatted)
        <div style="border-top:1px solid #e2e8f0;padding-top:10px;margin-top:10px;display:flex;justify-content:space-between;gap:16px;font-size:16px;">
            <span style="color:#0f172a;font-weight:600;">Total paid</span>
            <span style="color:#007fad;font-weight:700;font-size:18px;text-align:right;">{{ $totalFormatted }}</span>
        </div>
        @endif
    </div>

    {{-- NOTE: the previous body hardcoded a "Front Desk PIN: 3947" and fixed
         check-in (14:00-20:30) / check-out (08:00-11:00) windows. No column
         anywhere stores a PIN or these windows, so every guest received a
         fabricated door code. They are omitted until real data exists. --}}

    @if($receiptUrl)
    <div style="text-align:center;">
        <a href="{{ $receiptUrl }}" style="display:inline-block;background:#007fad;color:#ffffff;font-weight:700;text-decoration:none;padding:12px 24px;border-radius:6px;">Download your e-receipt</a>
    </div>
    @endif

    @if(! $bookingCode && ! $checkIn && ! $totalFormatted)
    <p style="margin:0;font-size:14px;color:#64748b;">
        Your booking reference and full itinerary are available in your
        <a href="{{ $bookingsUrl }}" style="color:#006CE4;">bookings page</a>.
    </p>
    @endif
@endsection
