{{--
  Shared shell for every transactional FastNetStays email.

  Design tokens match the original inline templates: navy #002155 wordmark,
  yellow #febb02 accent, blue #006CE4, on an #f8fafc page with a white card.

  RULE for every template that uses this layout: never invent a value. If a
  field is unknown, omit the row entirely (@if on a real value). The previous
  inline bodies fell back to literals such as a 105,000 TSh total or a front
  desk PIN of 3947, which sent guests fabricated booking facts.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>@yield('title', 'FastNetStays')</title>
</head>
<body style="margin:0;padding:0;background-color:#f8fafc;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1e293b;-webkit-font-smoothing:antialiased;line-height:1.6;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f8fafc;padding:40px 16px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:560px;background-color:#ffffff;border:1px solid #e2e8f0;border-radius:12px;text-align:left;box-shadow:0 1px 3px rgba(0,0,0,.03);">

                {{-- Brand header --}}
                <tr>
                    <td style="padding:32px 36px 28px;border-bottom:1px solid #f1f5f9;">
                        <div style="font-size:22px;font-weight:800;letter-spacing:-.5px;line-height:1;">
                            <span style="color:#002155;">FASTNET</span><span style="color:#febb02;">STAYS</span><span style="color:#006CE4;font-size:17px;font-weight:700;">.com</span>
                        </div>
                        <div style="margin-top:6px;font-size:0;line-height:0;">
                            <span style="display:inline-block;width:6px;height:6px;border-radius:50%;background-color:#ef4444;margin-right:4px;"></span>
                            <span style="display:inline-block;width:6px;height:6px;border-radius:50%;background-color:#f97316;margin-right:4px;"></span>
                            <span style="display:inline-block;width:6px;height:6px;border-radius:50%;background-color:#febb02;margin-right:4px;"></span>
                            <span style="display:inline-block;width:6px;height:6px;border-radius:50%;background-color:#10b981;margin-right:4px;"></span>
                            <span style="display:inline-block;width:6px;height:6px;border-radius:50%;background-color:#006CE4;"></span>
                        </div>
                    </td>
                </tr>

                {{-- Body --}}
                <tr>
                    <td style="padding:32px 36px;">
                        @yield('content')
                    </td>
                </tr>

                {{-- Footer --}}
                <tr>
                    <td style="padding:24px 36px;border-top:1px solid #f1f5f9;">
                        <div style="font-size:14px;font-weight:700;color:#002155;margin-bottom:2px;">Fastnet Stays</div>
                        <div style="font-size:13px;color:#64748b;font-style:italic;">Your stay, simplified.</div>
                        <div style="margin-top:14px;font-size:12px;color:#94a3b8;">
                            &copy; {{ now()->year }} FastNetStays.com &middot;
                            <a href="mailto:support@fastnetstays.com" style="color:#64748b;">support@fastnetstays.com</a>
                        </div>
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>
</body>
</html>
