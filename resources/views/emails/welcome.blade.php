@extends('emails.layout')

@php
    $userName       = $userName ?? null;
    $email          = $email ?? null;
    $searchUrl      = $searchUrl ?? null;
    $accountUrl     = $accountUrl ?? null;
    $preferencesUrl = $preferencesUrl ?? null;
    $privacyUrl     = $privacyUrl ?? null;

    $welcomeHeading = $userName
        ? "Welcome, {$userName}"
        : 'Welcome to FastNetStays';
@endphp

@section('title', 'Welcome to FastNetStays.com')

@section('content')
    <h1 style="margin:0 0 12px 0;font-size:22px;font-weight:700;color:#0f172a;">{{ $welcomeHeading }}</h1>

    <p style="margin:0 0 8px 0;font-size:15px;line-height:1.6;color:#334155;">
        Your account is ready. You can now search stays across Tanzania, save favourites
        and book instantly.
    </p>

    @if($email)
    <p style="margin:0 0 24px 0;font-size:14px;color:#64748b;">
        Signed up as <strong>{{ $email }}</strong>.
    </p>
    @endif

    {{-- Feature grid. Uses styled badges rather than inline SVG because several
         mail clients strip <svg>, which left the original cards blank. --}}
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"
           style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;margin:0 0 24px 0;">
        <tr>
            @foreach([
                ['&#128188;', 'Manage bookings easily'],
                ['&#127873;', 'Exclusive member deals'],
                ['&#128666;', '24/7 Customer Service'],
            ] as $feature)
            <td align="center" valign="top" width="33%" style="padding:20px 8px;">
                <div style="font-size:24px;line-height:1;margin-bottom:10px;">{!! $feature[0] !!}</div>
                <div style="font-size:12px;font-weight:700;color:#1e293b;line-height:1.4;">{{ $feature[1] }}</div>
            </td>
            @if(! $loop->last)
            <td style="border-right:1px solid #e2e8f0;"></td>
            @endif
            @endforeach
        </tr>
    </table>

    @if($searchUrl)
    <div style="text-align:center;margin-bottom:24px;">
        <a href="{{ $searchUrl }}" style="display:inline-block;background:#007fad;color:#ffffff;font-weight:700;text-decoration:none;padding:13px 32px;border-radius:6px;">
            Start searching
        </a>
    </div>
    @endif

    <div style="border-top:1px solid #f1f5f9;padding-top:16px;">
        <div style="font-size:13px;color:#64748b;margin-bottom:8px;">
            <a href="{{ $accountUrl }}" style="color:#006CE4;text-decoration:none;">My account</a>
            &nbsp;&middot;&nbsp;
            <a href="{{ $preferencesUrl }}" style="color:#006CE4;text-decoration:none;">Email preferences</a>
            &nbsp;&middot;&nbsp;
            <a href="{{ $privacyUrl }}" style="color:#006CE4;text-decoration:none;">Privacy policy</a>
        </div>
        <div style="font-size:12px;color:#94a3b8;">
            You're receiving this because you created an account at fastnetstays.com.
        </div>
    </div>
@endsection
