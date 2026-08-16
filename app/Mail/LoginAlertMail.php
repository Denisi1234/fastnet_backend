<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class LoginAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $time;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->time = now()->format('F j, Y, g:i a');
    }

    public function build()
    {
        return $this->subject('Security Alert: New sign-in to your FastNetStays account')
                    ->html($this->renderHtmlContent());
    }

    private function renderHtmlContent(): string
    {
        $fullName  = trim($this->user->name ?? '');
        $firstName = $fullName ? explode(' ', $fullName)[0] : 'Customer';
        if ($firstName === 'Customer' && !empty($this->user->email)) {
            $firstName = ucfirst(explode('@', $this->user->email)[0]);
        }
        $firstName = htmlspecialchars($firstName);
        $time = htmlspecialchars($this->time);

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
  body { margin: 0; padding: 0; background-color: #f4f7f6; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
  .container { max-width: 500px; margin: 40px auto; background-color: #ffffff; border-radius: 8px; border: 1px solid #e2e8f0; overflow: hidden; }
  .header { background-color: #002244; padding: 20px; text-align: center; }
  .header-brand { color: #ffffff; font-size: 20px; font-weight: 900; letter-spacing: -0.5px; }
  .header-brand span { color: #007fad; }
  .content { padding: 30px; color: #334155; line-height: 1.6; }
  .content h2 { color: #0f172a; font-size: 18px; margin-top: 0; }
  .alert-box { background-color: #f8fafc; border-left: 4px solid #007fad; padding: 15px; margin: 20px 0; font-size: 14px; }
  .footer { background-color: #f8fafc; padding: 20px; text-align: center; font-size: 12px; color: #64748b; border-top: 1px solid #e2e8f0; }
</style>
</head>
<body>
  <div class="container">
    <div class="header">
      <div class="header-brand">FASTNET<span>STAYS</span></div>
    </div>
    <div class="content">
      <h2>Hi {$firstName},</h2>
      <p>We noticed a new sign-in to your FastNetStays account.</p>
      
      <div class="alert-box">
        <strong>Account:</strong> {$this->user->email}<br>
        <strong>Time:</strong> {$time}
      </div>

      <p>If this was you, you don't need to do anything. If you didn't sign in recently, please secure your account immediately by changing your password.</p>
      
      <p>Thanks,<br>The FastNetStays Security Team</p>
    </div>
    <div class="footer">
      &copy; FastNetStays.com. All rights reserved.
    </div>
  </div>
</body>
</html>
HTML;
    }
}
