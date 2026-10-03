<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Transactional email delivery via the Resend REST API.
 *
 * Bodies live in resources/views/emails/*.blade.php — this class only assembles
 * the data and hands it to the transport.
 *
 * Two rules this service enforces:
 *  1. Never send invented booking data. Every field is read from the record and
 *     templates omit rows they have no value for.
 *  2. Never lose a send silently. A missing API key, an unverified domain and a
 *     transport error are all logged with enough detail to diagnose.
 */
class ResendMailService
{
    /**
     * Role senders. Each is overridable via env, but deliberately NOT via
     * MAIL_FROM_ADDRESS: that variable held a placeholder (hello@example.com)
     * which overrode all five role senders and made every send fail.
     */
    private const SENDERS = [
        'welcome'  => ['MAIL_FROM_WELCOME',  'welcome@fastnetstays.com',  'FastNetStays'],
        'security' => ['MAIL_FROM_SECURITY', 'security@fastnetstays.com', 'FastNetStays Security'],
        'bookings' => ['MAIL_FROM_BOOKINGS', 'bookings@fastnetstays.com', 'FastNetStays Reservations'],
        'support'  => ['MAIL_FROM_SUPPORT',  'support@fastnetstays.com',  'FastNet Support'],
        'careers'  => ['MAIL_FROM_CAREERS',  'careers@fastnetstays.com',  'FastNetStays Careers'],
    ];

    /** Last-resort sender that works on any Resend account (no domain needed). */
    private const FALLBACK_SENDER = 'onboarding@resend.dev';

    private static function frontendBase(): string
    {
        $default = env('APP_ENV') === 'production'
            ? 'https://fastnetstays.com'
            : 'http://127.0.0.1:8765';

        return rtrim(env('FRONTEND_URL', $default), '/');
    }

    // ── Public API (signatures unchanged — existing callers keep working) ──────

    public static function sendWelcomeEmail(User $user): bool
    {
        return self::deliver('welcome', $user->email ?? null, 'emails.welcome', [
            'userName'       => $user->name,
            'email'          => $user->email,
            'searchUrl'      => self::frontendBase() . '/',
            'accountUrl'     => self::frontendBase() . '/settings',
            'preferencesUrl' => self::frontendBase() . '/notifications',
            'privacyUrl'     => self::frontendBase() . '/privacy-policy',
        ], 'Welcome to FastNetStays.com — Your account is ready', 'welcome email');
    }

    public static function sendLoginAlertEmail(User $user): bool
    {
        return self::deliver('security', $user->email ?? null, 'emails.login-alert', [
            'accountEmail' => $user->email,
            'signedInAt'   => now()->format('d M Y, H:i'),
        ], 'Security Alert: New sign-in to your FastNetStays account', 'login alert');
    }

    public static function sendOtpEmail(string $toEmail, string $otpCode, string $purpose = 'Password Reset'): bool
    {
        $user = User::where('email', $toEmail)->first();

        // Lower-case verb so it reads correctly mid-sentence: "to reset your password".
        $verb = strtolower($purpose);

        return self::deliver('security', $toEmail, 'emails.otp', [
            'otpCode' => $otpCode,
            // Null for a contact that has no account yet — the passwordless
            // sign-in flow proves the address before creating the user. The
            // template omits the greeting when this is null, so do not deref it.
            'customerName' => $user?->name,
            'purpose'      => $verb,
        ], "Your verification code: {$otpCode}", 'OTP');
    }

    public static function sendBookingConfirmation($guest, $booking, $property): bool
    {
        $bookingCode = $booking->booking_code ?? ('BK' . ($booking->id ?? ''));

        return self::deliver('bookings', $guest->email ?? null, 'emails.booking-confirmed', [
            'guestName'        => $guest->name ?? null,
            'propertyName'     => $property->name ?? null,
            'propertyAddress'  => $property->address ?? null,
            'bookingCode'      => $bookingCode,
            'checkIn'          => self::formatDate($booking->check_in ?? null),
            'checkOut'         => self::formatDate($booking->check_out ?? null),
            // Null, not a fabricated number: the old body defaulted to TSh 105,000.
            'totalFormatted'   => isset($booking->total_price)
                ? 'TSh ' . number_format((float) $booking->total_price)
                : null,
            'receiptUrl'       => self::frontendBase() . "/booking/e-receipt.html?code={$bookingCode}&action=download",
            'bookingsUrl'      => self::frontendBase() . '/my-booking',
        ], "Booking Confirmed: " . ($property->name ?? 'your stay') . " (#{$bookingCode})", 'booking confirmation');
    }

    public static function sendTicketConfirmationEmail($ticket, ?string $toEmail = null, ?string $userName = null): bool
    {
        $email = $toEmail ?: ($ticket->user->email ?? null);
        $issue = $ticket->issue ?? null;

        return self::deliver('support', $email, 'emails.ticket-confirmation', [
            'ticketId'  => $ticket->id,
            'issue'     => $issue,
            'status'    => $ticket->status ?? 'Open',
            'userName'  => $userName ?: ($ticket->user->name ?? null),
            'portalUrl' => self::frontendBase() . '/support/chat.html?ticket=' . $ticket->id,
        ], "Support Ticket #{$ticket->id} Created" . ($issue ? ": {$issue}" : ''), 'ticket confirmation');
    }

    public static function sendTicketResolvedEmail($ticket, ?string $toEmail = null, ?string $userName = null): bool
    {
        $email = $toEmail ?: ($ticket->user->email ?? null);
        $issue = $ticket->issue ?? null;

        return self::deliver('support', $email, 'emails.ticket-resolved', [
            'ticketId'  => $ticket->id,
            'issue'     => $issue,
            'status'    => $ticket->status ?? 'Resolved',
            'userName'  => $userName ?: ($ticket->user->name ?? null),
            'portalUrl' => self::frontendBase() . '/support/chat.html?ticket=' . $ticket->id,
        ], "[Resolved] Ticket #{$ticket->id}" . ($issue ? ": {$issue}" : ''), 'ticket resolved');
    }

    public static function sendSubscriptionEmail(string $toEmail, string $type = 'general'): bool
    {
        $careers = $type === 'careers';

        return self::deliver('careers', $toEmail, 'emails.subscription', [
            'subject'     => $careers
                ? 'You are subscribed to Fastnetstays.com Career & Job Alerts'
                : 'Welcome to FastNetStays Updates & News',
            'badge'       => $careers ? 'Career alerts' : 'Subscribed',
            'headline'    => $careers ? 'Career alerts are on' : 'Welcome to FastNetStays Updates',
            'description' => $careers
                ? 'We will email you when we open new host or partner roles in Tanzania.'
                : 'Occasional news, travel tips and member-only deals. No noise.',
            'bullets'     => $careers
                ? ['New host and partner roles', 'Listing opportunities across Tanzania']
                : ['Travel tips from our team', 'Member-only pricing', 'New destinations as they launch'],
            'portalUrl'   => self::frontendBase() . '/',
        ], $careers
                ? 'You are subscribed to Fastnetstays.com Career & Job Alerts'
                : 'Welcome to FastNetStays Updates & News',
            'subscription');
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private static function formatDate($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->format('d M Y');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Render a template and deliver it, falling back to a verified-on-any-account
     * sender if the role domain is not verified in Resend.
     */
    private static function deliver(
        string $role,
        ?string $to,
        string $view,
        array  $data,
        string $subject,
        string $label
    ): bool {
        if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            Log::warning("Resend: skipping {$label} — invalid or missing recipient address.");
            return false;
        }

        $key = (string) env('RESEND_API_KEY', '');
        if ($key === '') {
            Log::error("Resend: cannot send {$label} to {$to} — RESEND_API_KEY is not set.");
            return false;
        }

        try {
            $html = view($view, $data)->render();
        } catch (\Throwable $e) {
            Log::error("Resend: {$view} failed to render for {$to}: " . $e->getMessage());
            return false;
        }

        [$envKey, $defaultAddress, $defaultName] = self::SENDERS[$role];
        $address = (string) env($envKey, $defaultAddress);
        $name    = (string) env($envKey . '_NAME', $defaultName);

        $response = self::post($key, "{$name} <{$address}>", $to, $subject, $html);

        // 403 = sender domain not verified for this Resend account.
        if (($response['statusCode'] ?? null) === 403) {
            Log::warning("Resend: sender {$address} not verified for {$label} — retrying with fallback sender.");
            $response = self::post($key, "{$name} <" . self::FALLBACK_SENDER . '>', $to, $subject, $html);
        }

        if (isset($response['id'])) {
            Log::info("Resend: {$label} sent to {$to}. Id: {$response['id']}");
            return true;
        }

        Log::error("Resend: {$label} to {$to} failed. Response: " . json_encode($response));
        return false;
    }

    // ── POST to Resend REST API ───────────────────────────────────────────────
    private static function post(
        string $key,
        string $from,
        string $to,
        string $subject,
        string $html,
        array  $attachments = []
    ): array {
        $payload = [
            'from'    => $from,
            'to'      => [$to],
            'subject' => $subject,
            'html'    => $html,
        ];

        if (!empty($attachments)) {
            $payload['attachments'] = $attachments;
        }

        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            // TLS verification stays ON. This transport had it disabled, which
            // exposed the API key and message bodies to interception.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $key,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);
        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            Log::error("Resend: transport error (HTTP {$code}): {$err}");
            return ['statusCode' => $code ?: 0];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            Log::error("Resend: non-JSON response (HTTP {$code}): " . substr((string) $raw, 0, 300));
            return ['statusCode' => $code];
        }

        // Surface the real HTTP status so the 403 sender-fallback check works
        // even when Resend's error body omits statusCode.
        $decoded['statusCode'] = $decoded['statusCode'] ?? $code;

        return $decoded;
    }
}
