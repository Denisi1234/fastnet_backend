<?php

namespace App\Services;

use App\Models\User;
use App\Mail\WelcomeUserMail;
use Illuminate\Support\Facades\Log;

class ResendMailService
{
    // ── Cached hero image so we don't re-download per request ─────────────────
    private static ?array $cachedHero = null;

    public static function sendWelcomeEmail(User $user): bool
    {
        $apiKey      = env('RESEND_API_KEY', '');
        $primaryFrom = env('MAIL_FROM_ADDRESS', 'welcome@fastnetstays.com');
        $fromName    = env('MAIL_FROM_NAME', 'FastNetStays');
        $toEmail     = $user->email ?? null;

        if (!$toEmail || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        // ── 1. Build HTML from WelcomeUserMail ────────────────────────────────
        $mailable   = new WelcomeUserMail($user);
        $reflection = new \ReflectionMethod($mailable, 'renderHtmlContent');
        $reflection->setAccessible(true);
        $html = $reflection->invoke($mailable);

        $subject = 'Welcome to FastNetStays.com — Your account is ready';

        // ── 3. Send — try verified domain first, fallback to resend.dev ───────
        $response = self::post($apiKey, "{$fromName} <{$primaryFrom}>", $toEmail, $subject, $html);

        if (isset($response['statusCode']) && $response['statusCode'] === 403) {
            Log::warning("Domain {$primaryFrom} not yet verified — using fallback sender.");
            $response = self::post($apiKey, "{$fromName} <onboarding@resend.dev>", $toEmail, $subject, $html);
        }

        if (isset($response['id'])) {
            Log::info("Welcome email sent to {$toEmail}. Resend ID: {$response['id']}");
            return true;
        }

        Log::error("Resend error for {$toEmail}: " . json_encode($response));
        return false;
    }

    public static function sendLoginAlertEmail(User $user): bool
    {
        $apiKey      = env('RESEND_API_KEY', '');
        $primaryFrom = env('MAIL_FROM_ADDRESS', 'security@fastnetstays.com');
        $fromName    = env('MAIL_FROM_NAME', 'FastNetStays Security');
        $toEmail     = $user->email ?? null;

        if (!$toEmail || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $mailable   = new \App\Mail\LoginAlertMail($user);
        $reflection = new \ReflectionMethod($mailable, 'renderHtmlContent');
        $reflection->setAccessible(true);
        $html = $reflection->invoke($mailable);

        $subject = 'Security Alert: New sign-in to your FastNetStays account';

        $response = self::post($apiKey, "{$fromName} <{$primaryFrom}>", $toEmail, $subject, $html);

        if (isset($response['statusCode']) && $response['statusCode'] === 403) {
            $response = self::post($apiKey, "{$fromName} <onboarding@resend.dev>", $toEmail, $subject, $html);
        }

        if (isset($response['id'])) {
            Log::info("Login alert email sent to {$toEmail}. Resend ID: {$response['id']}");
            return true;
        }

        Log::error("Resend login alert error for {$toEmail}: " . json_encode($response));
        return false;
    }

    public static function sendOtpEmail(string $toEmail, string $otpCode, string $purpose = 'Password Reset'): bool
    {
        $apiKey      = env('RESEND_API_KEY', '');
        $primaryFrom = env('MAIL_FROM_ADDRESS', 'security@fastnetstays.com');
        $fromName    = env('MAIL_FROM_NAME', 'FastNetStays');

        if (!$toEmail || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $user = User::where('email', $toEmail)->first();
        $customerName = $user ? $user->name : 'there';

        $subject = "Your verification code: {$otpCode}";

        $html = '
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Your verification code</title>
        </head>
        <body style="margin: 0; padding: 0; background-color: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; color: #1e293b; -webkit-font-smoothing: antialiased; line-height: 1.6;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color: #f8fafc; padding: 40px 16px;">
                <tr>
                    <td align="center">
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width: 520px; background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 40px 36px; text-align: left; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
                            
                            <!-- Official FastNetStays Logo -->
                            <tr>
                                <td style="padding-bottom: 28px; border-bottom: 1px solid #f1f5f9;">
                                    <div style="font-size: 22px; font-weight: 800; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; letter-spacing: -0.5px; line-height: 1;">
                                        <span style="color: #002155;">FASTNET</span><span style="color: #febb02;">STAYS</span><span style="color: #006CE4; font-size: 17px; font-weight: 700;">.com</span>
                                    </div>
                                    <div style="margin-top: 6px; font-size: 0; line-height: 0;">
                                        <span style="display: inline-block; width: 6px; height: 6px; border-radius: 50%; background-color: #ef4444; margin-right: 4px;"></span>
                                        <span style="display: inline-block; width: 6px; height: 6px; border-radius: 50%; background-color: #f97316; margin-right: 4px;"></span>
                                        <span style="display: inline-block; width: 6px; height: 6px; border-radius: 50%; background-color: #febb02; margin-right: 4px;"></span>
                                        <span style="display: inline-block; width: 6px; height: 6px; border-radius: 50%; background-color: #10b981; margin-right: 4px;"></span>
                                        <span style="display: inline-block; width: 6px; height: 6px; border-radius: 50%; background-color: #006CE4;"></span>
                                    </div>
                                </td>
                            </tr>

                            <!-- Email Body Content -->
                            <tr>
                                <td style="padding-top: 28px;">
                                    <h1 style="margin: 0 0 20px 0; font-size: 20px; font-weight: 700; color: #0f172a;">Your verification code</h1>
                                    
                                    <p style="margin: 0 0 16px 0; font-size: 15px; color: #334155;">
                                        Hi ' . htmlspecialchars($customerName) . ',
                                    </p>

                                    <p style="margin: 0 0 24px 0; font-size: 15px; color: #334155;">
                                        We received a request to reset your Fastnet Stays account password.
                                    </p>

                                    <p style="margin: 0 0 12px 0; font-size: 14px; font-weight: 600; color: #64748b;">
                                        Your verification code is:
                                    </p>
                                </td>
                            </tr>

                            <!-- 6-digit Code Display -->
                            <tr>
                                <td align="center" style="padding: 4px 0 28px 0;">
                                    <div style="background-color: #f1f5f9; border-radius: 8px; padding: 16px 28px; display: inline-block; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 32px; font-weight: 800; letter-spacing: 8px; color: #002155;">
                                        ' . htmlspecialchars($otpCode) . '
                                    </div>
                                </td>
                            </tr>

                            <!-- Instructions & Security Notice -->
                            <tr>
                                <td>
                                    <p style="margin: 0 0 16px 0; font-size: 14px; color: #475569;">
                                        This code will expire in <strong>10 minutes</strong>. For your security, do not share this code with anyone.
                                    </p>

                                    <p style="margin: 0 0 32px 0; font-size: 14px; color: #64748b;">
                                        If you didn\'t request this code, you can safely ignore this email.
                                    </p>
                                </td>
                            </tr>

                            <!-- Signoff Footer -->
                            <tr>
                                <td style="padding-top: 24px; border-top: 1px solid #f1f5f9;">
                                    <div style="font-size: 14px; font-weight: 700; color: #002155; margin-bottom: 2px;">
                                        Fastnet Stays
                                    </div>
                                    <div style="font-size: 13px; color: #64748b; font-style: italic;">
                                        Your stay, simplified.
                                    </div>
                                </td>
                            </tr>

                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        ';

        $response = self::post($apiKey, "{$fromName} <{$primaryFrom}>", $toEmail, $subject, $html);

        if (isset($response['statusCode']) && $response['statusCode'] === 403) {
            $response = self::post($apiKey, "{$fromName} <onboarding@resend.dev>", $toEmail, $subject, $html);
        }

        if (isset($response['id'])) {
            Log::info("OTP code sent to {$toEmail}. Resend ID: {$response['id']}");
            return true;
        }

        Log::error("Resend OTP error for {$toEmail}: " . json_encode($response));
        return false;
    }

    public static function sendBookingConfirmation($guest, $booking, $property): bool
    {
        $apiKey      = env('RESEND_API_KEY', '');
        $primaryFrom = env('MAIL_FROM_ADDRESS', 'bookings@fastnetstays.com');
        $fromName    = env('MAIL_FROM_NAME', 'FastNetStays Reservations');
        $toEmail     = $guest->email ?? null;

        if (!$toEmail || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $propertyName = $property->name ?? 'FastNet Stay';
        $bookingCode = $booking->booking_code ?? 'BK' . $booking->id;
        $subject = "Booking Confirmed: {$propertyName} (#{$bookingCode})";

        $html = "
        <div style='font-family: sans-serif; max-width: 600px; margin: auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px;'>
            <h2 style='color: #006ce4;'>Your stay is confirmed!</h2>
            <p>Habari {$guest->name},</p>
            <p>Your booking at <strong>{$propertyName}</strong> is locked in.</p>
            <div style='background-color: #f8fafc; padding: 16px; border-radius: 6px; margin: 20px 0;'>
                <p><strong>Booking Reference:</strong> {$bookingCode}</p>
                <p><strong>Check-in:</strong> {$booking->check_in}</p>
                <p><strong>Check-out:</strong> {$booking->check_out}</p>
                <p><strong>Total Amount:</strong> TSh " . number_format($booking->total_price) . "</p>
            </div>
            <p>Thank you for booking with FastNet Stays!</p>
        </div>";

        $response = self::post($apiKey, "{$fromName} <{$primaryFrom}>", $toEmail, $subject, $html);
        return isset($response['id']);
    }

    // ── Download URL → base64 string ──────────────────────────────────────────
    private static function fetchImage(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$body || $code < 200 || $code >= 300) {
            Log::warning("Could not fetch email asset from {$url} (HTTP {$code})");
            return null;
        }

        return base64_encode($body);
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
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $key,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);

        return json_decode($raw, true) ?? [];
    }
}
