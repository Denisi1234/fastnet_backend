<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WelcomeUserMail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function build()
    {
        return $this->subject('Welcome to FastNetStays.com — Your account is ready')
                    ->html($this->renderHtmlContent());
    }

    // ── Inline SVG icons — zero network requests, 100% email client support ──
    private static function suitcaseSvg(): string
    {
        return '<svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="#007fad" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg">
          <rect x="2" y="7" width="20" height="14" rx="2"/>
          <path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/>
          <line x1="12" y1="12" x2="12" y2="16"/>
          <line x1="10" y1="14" x2="14" y2="14"/>
        </svg>';
    }

    private static function tagSvg(): string
    {
        return '<svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="#007fad" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg">
          <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/>
          <line x1="7" y1="7" x2="7.01" y2="7"/>
          <circle cx="10" cy="10" r="1.5" fill="#007fad" stroke="none"/>
        </svg>';
    }

    private static function headsetSvg(): string
    {
        return '<svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="#007fad" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg">
          <path d="M3 18v-6a9 9 0 0 1 18 0v6"/>
          <path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3z"/>
          <path d="M3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"/>
        </svg>';
    }

    private function renderHtmlContent(): string
    {
        $fullName  = trim($this->user->name ?? '');
        $firstName = $fullName ? explode(' ', $fullName)[0] : 'there';
        if ($firstName === 'there' && !empty($this->user->email)) {
            $firstName = ucfirst(explode('@', $this->user->email)[0]);
        $heroUrl = config('app.url') . '/images/welcome-hero.jpg';
        }
        $firstName   = htmlspecialchars($firstName);
        $currentYear = date('Y');

        $suitcaseSvg  = self::suitcaseSvg();
        $tagSvg       = self::tagSvg();
        $headsetSvg   = self::headsetSvg();

        return <<<HTML
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>Welcome to FastNetStays.com</title>
<!--[if mso]>
<noscript><xml><o:OfficeDocumentSettings xmlns:o="urn:schemas-microsoft-com:office:office">
<o:PixelsPerInch>96</o:PixelsPerInch>
</o:OfficeDocumentSettings></xml></noscript>
<![endif]-->
<style type="text/css">
  body, table, td, a { -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%; }
  table, td { mso-table-lspace:0pt; mso-table-rspace:0pt; }
  img { -ms-interpolation-mode:bicubic; border:0; line-height:100%; outline:none; text-decoration:none; }
  body { margin:0 !important; padding:0 !important; background-color:#dde3ea !important; }
  /* Force light mode */
  :root { color-scheme: light only; }
  @media (prefers-color-scheme: dark) {
    body { background-color:#dde3ea !important; }
    .email-card { background-color:#ffffff !important; }
    .body-bg { background-color:#ffffff !important; }
    .main-text { color:#334155 !important; }
    .head-text { color:#001f3f !important; }
    .feat-label { color:#1e293b !important; }
  }
</style>
</head>
<body style="margin:0;padding:0;background-color:#dde3ea;">

<!-- ╔══════════════ OUTER WRAPPER ══════════════╗ -->
<table border="0" cellpadding="0" cellspacing="0" width="100%" bgcolor="#dde3ea"
       style="background-color:#dde3ea;min-width:100%;">
<tr><td align="center" valign="top" style="padding:28px 8px 36px 8px;">

<!-- ╔══════════════ EMAIL CARD 600px ══════════════╗ -->
<table class="email-card" border="0" cellpadding="0" cellspacing="0"
       style="background-color:#ffffff;border-radius:8px;overflow:hidden;
              box-shadow:0 4px 20px rgba(0,0,0,0.10);max-width:600px;width:100%;">

  <!-- ════════════ 1. LOGO BAR ════════════ -->
  <tr>
    <td class="body-bg" bgcolor="#ffffff"
        style="background-color:#ffffff;padding:22px 28px 18px 28px;border-bottom:1px solid #f0f4f8;">
      <span style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;
                   font-size:22px;font-weight:900;letter-spacing:-0.5px;line-height:1;mso-line-height-rule:exactly;">
        <span style="color:#007fad;">FASTNET</span><span style="color:#001f3f;">STAYS</span><span
          style="color:#007fad;font-size:15px;font-weight:700;">.com</span>
      </span>
    </td>
  </tr>

  <!-- ════════════ 2. HERO IMAGE BLOCK (Bulletproof Background) ════════════ -->
  <tr>
    <td background="{$heroUrl}" 
        bgcolor="#005580" 
        width="600" 
        height="280" 
        valign="bottom" 
        style="background-position: center; background-size: cover; background-repeat: no-repeat;">
      <!--[if gte mso 9]>
      <v:rect xmlns:v="urn:schemas-microsoft-com:vml" fill="true" stroke="false" style="width:600px;height:280px;">
        <v:fill type="frame" src="{$heroUrl}" color="#005580" />
        <v:textbox inset="0,0,0,0">
      <![endif]-->
      
      <table border="0" cellpadding="0" cellspacing="0" width="100%">
        <tr>
          <td height="180">
            <!-- Spacer to push text to the bottom -->
          </td>
        </tr>
        <tr>
          <td style="padding:22px 28px 28px; background:linear-gradient(to top,rgba(0,28,58,0.9) 0%,rgba(0,28,58,0.2) 80%,transparent 100%);">
            <span style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;
                         font-size:34px;font-weight:900;color:#ffffff;
                         line-height:1.15;display:block;letter-spacing:-0.5px;
                         mso-line-height-rule:exactly; text-shadow: 0px 2px 4px rgba(0,0,0,0.5);">
              Welcome to<br>FastNetStays.com
            </span>
          </td>
        </tr>
      </table>

      <!--[if gte mso 9]>
        </v:textbox>
      </v:rect>
      <![endif]-->
    </td>
  </tr>

  <!-- ════════════ 3. GREETING + BODY ════════════ -->
  <tr>
    <td class="body-bg" bgcolor="#ffffff"
        style="background-color:#ffffff;padding:36px 36px 20px 36px;">
      <table border="0" cellpadding="0" cellspacing="0" width="100%">
        <tr>
          <td align="center" style="padding-bottom:14px;">
            <h1 class="head-text"
                style="margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;
                       font-size:22px;font-weight:800;color:#001f3f;letter-spacing:-0.3px;
                       mso-line-height-rule:exactly;">
              Hi {$firstName}, we're glad you're here!
            </h1>
          </td>
        </tr>
        <tr>
          <td align="left" style="padding-bottom:28px;">
            <p class="main-text"
               style="margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;
                      font-size:15px;line-height:1.7;color:#475569;">
              You're just a few clicks away from discovering incredible places to stay across Tanzania.
              From cozy lakeside lodges to luxury beach resorts, find the perfect stay for any trip, any budget.
            </p>
          </td>
        </tr>
        <!-- CTA Button -->
        <tr>
          <td align="center" style="padding-bottom:8px;">
            <!--[if mso]>
            <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word"
              href="https://fastnetstays.com" style="height:52px;v-text-anchor:middle;width:320px;" arcsize="6%"
              stroke="f" fillcolor="#007fad">
              <w:anchorlock/>
              <center style="color:#ffffff;font-family:Arial,sans-serif;font-size:16px;font-weight:bold;">Start Searching</center>
            </v:roundrect>
            <![endif]-->
            <!--[if !mso]><!-->
            <a href="https://fastnetstays.com" target="_blank"
               style="background-color:#007fad;border-radius:6px;color:#ffffff;display:inline-block;
                      font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;
                      font-size:16px;font-weight:800;line-height:52px;text-align:center;
                      text-decoration:none;width:300px;max-width:90%;
                      -webkit-text-size-adjust:none;mso-hide:all;">
              Start Searching
            </a>
            <!--<![endif]-->
          </td>
        </tr>
      </table>
    </td>
  </tr>

  <!-- ════════════ 4. THREE FEATURE COLUMNS ════════════ -->
  <tr>
    <td class="body-bg" bgcolor="#ffffff"
        style="background-color:#ffffff;padding:8px 28px 32px 28px;">

      <!-- Divider -->
      <table border="0" cellpadding="0" cellspacing="0" width="100%">
        <tr><td style="border-top:1px solid #e2e8f0;padding-top:24px;font-size:0;">&nbsp;</td></tr>
      </table>

      <!-- Feature 3-column grid — fixed-width cells prevent stacking -->
      <table border="0" cellpadding="0" cellspacing="0" width="100%"
             style="table-layout:fixed;">
        <tr>

          <!-- Col 1: Bookings -->
          <td align="center" valign="top" width="33%"
              style="padding:0 8px 0 0;border-right:1px solid #e2e8f0;">
            <table border="0" cellpadding="0" cellspacing="0" width="100%">
              <tr>
                <td align="center" style="padding-bottom:10px;line-height:0;">
                  {$suitcaseSvg}
                </td>
              </tr>
              <tr>
                <td align="center">
                  <span class="feat-label"
                        style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;
                               font-size:12px;font-weight:700;color:#1e293b;line-height:1.4;display:block;">
                    Manage bookings<br>easily
                  </span>
                </td>
              </tr>
            </table>
          </td>

          <!-- Col 2: Deals -->
          <td align="center" valign="top" width="34%"
              style="padding:0 8px;border-right:1px solid #e2e8f0;">
            <table border="0" cellpadding="0" cellspacing="0" width="100%">
              <tr>
                <td align="center" style="padding-bottom:10px;line-height:0;">
                  {$tagSvg}
                </td>
              </tr>
              <tr>
                <td align="center">
                  <span class="feat-label"
                        style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;
                               font-size:12px;font-weight:700;color:#1e293b;line-height:1.4;display:block;">
                    Exclusive member<br>deals
                  </span>
                </td>
              </tr>
            </table>
          </td>

          <!-- Col 3: Support -->
          <td align="center" valign="top" width="33%"
              style="padding:0 0 0 8px;">
            <table border="0" cellpadding="0" cellspacing="0" width="100%">
              <tr>
                <td align="center" style="padding-bottom:10px;line-height:0;">
                  {$headsetSvg}
                </td>
              </tr>
              <tr>
                <td align="center">
                  <span class="feat-label"
                        style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;
                               font-size:12px;font-weight:700;color:#1e293b;line-height:1.4;display:block;">
                    24/7 Customer<br>Service
                  </span>
                </td>
              </tr>
            </table>
          </td>

        </tr>
      </table>
    </td>
  </tr>

  <!-- ════════════ 5. DARK NAVY FOOTER ════════════ -->
  <tr>
    <td bgcolor="#001f3f"
        style="background-color:#001f3f;padding:32px 28px 28px;border-radius:0 0 8px 8px;">
      <table border="0" cellpadding="0" cellspacing="0" width="100%">

        <!-- Brand -->
        <tr>
          <td style="padding-bottom:14px;">
            <span style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;
                         font-size:20px;font-weight:900;letter-spacing:-0.4px;">
              <span style="color:#ffffff;">FASTNET</span><span style="color:#38bdf8;">STAYS</span><span
                style="color:rgba(255,255,255,0.5);font-size:14px;font-weight:700;">.com</span>
            </span>
          </td>
        </tr>

        <!-- Nav links -->
        <tr>
          <td style="padding-bottom:22px;">
            <span style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;
                         font-size:13px;line-height:1.6;">
              <a href="https://fastnetstays.com/my-booking"
                 style="color:#ffffff;text-decoration:none;font-weight:600;">My Account</a>
              <span style="color:rgba(255,255,255,0.25);margin:0 9px;">|</span>
              <a href="https://fastnetstays.com/privacy"
                 style="color:#ffffff;text-decoration:none;font-weight:600;">Privacy Policy</a>
              <span style="color:rgba(255,255,255,0.25);margin:0 9px;">|</span>
              <a href="#" style="color:rgba(255,255,255,0.45);text-decoration:none;">Unsubscribe</a>
            </span>
          </td>
        </tr>

        <!-- App store badges — pure HTML, no images -->
        <tr>
          <td style="padding-bottom:24px;">
            <table border="0" cellpadding="0" cellspacing="0">
              <tr>
                <!-- App Store -->
                <td style="padding-right:10px;">
                  <a href="#" style="display:inline-block;background:#000000;border-radius:8px;
                                     padding:9px 16px;text-decoration:none;border:1px solid #3a3a3a;">
                    <table border="0" cellpadding="0" cellspacing="0">
                      <tr>
                        <td style="vertical-align:middle;padding-right:8px;">
                          <span style="font-size:24px;color:#ffffff;font-family:Arial;line-height:1;display:block;">&#63743;</span>
                        </td>
                        <td style="vertical-align:middle;">
                          <div style="font-family:Arial,sans-serif;color:rgba(255,255,255,0.6);font-size:9px;line-height:1.3;">Download on the</div>
                          <div style="font-family:Arial,sans-serif;color:#ffffff;font-size:15px;font-weight:700;line-height:1.2;">App Store</div>
                        </td>
                      </tr>
                    </table>
                  </a>
                </td>
                <!-- Google Play -->
                <td>
                  <a href="#" style="display:inline-block;background:#000000;border-radius:8px;
                                     padding:9px 16px;text-decoration:none;border:1px solid #3a3a3a;">
                    <table border="0" cellpadding="0" cellspacing="0">
                      <tr>
                        <td style="vertical-align:middle;padding-right:8px;">
                          <span style="font-size:20px;color:#34a853;font-family:Arial;line-height:1;display:block;">&#9654;</span>
                        </td>
                        <td style="vertical-align:middle;">
                          <div style="font-family:Arial,sans-serif;color:rgba(255,255,255,0.6);font-size:9px;line-height:1.3;">GET IT ON</div>
                          <div style="font-family:Arial,sans-serif;color:#ffffff;font-size:15px;font-weight:700;line-height:1.2;">Google Play</div>
                        </td>
                      </tr>
                    </table>
                  </a>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- Copyright -->
        <tr>
          <td>
            <span style="font-family:Arial,sans-serif;font-size:12px;
                         color:rgba(255,255,255,0.32);line-height:1.6;">
              &copy; {$currentYear} FastNetStays.com. All rights reserved.<br>
              Tanzania's hotel &amp; lodge booking platform.
            </span>
          </td>
        </tr>

      </table>
    </td>
  </tr>

</table>
<!-- /EMAIL CARD -->

</td></tr>
</table>
<!-- /OUTER WRAPPER -->

</body>
</html>
HTML;
    }
}
