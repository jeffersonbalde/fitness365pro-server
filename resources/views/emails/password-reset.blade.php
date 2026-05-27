<!doctype html>
<html>
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Reset Password</title>
  </head>
  <body style="margin:0;padding:0;background:#f6f7fb;font-family:Arial,Helvetica,sans-serif;">
    <div style="max-width:600px;margin:0 auto;padding:24px;">
      <div style="background:#ffffff;border-radius:12px;padding:24px;border:1px solid #e6e8f0;">
        <h2 style="margin:0 0 12px 0;color:#0f172a;font-size:20px;">Reset your password</h2>
        <p style="margin:0 0 16px 0;color:#334155;font-size:14px;line-height:1.6;">
          Click the button below to reset your Fitness 365 Pro password. This link expires in
          <strong>{{ $minutes }}</strong> minutes.
        </p>

        <div style="margin:18px 0;text-align:center;">
          <a href="{{ $resetUrl }}" style="display:inline-block;padding:12px 24px;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600;font-size:14px;">
            Reset Password
          </a>
        </div>

        <p style="margin:16px 0 0 0;color:#64748b;font-size:12px;line-height:1.6;">
          If the button doesn't work, copy and paste this link into your browser:
        </p>
        <p style="margin:8px 0 0 0;color:#2563eb;font-size:12px;word-break:break-all;">
          {{ $resetUrl }}
        </p>

        <p style="margin:18px 0 0 0;color:#64748b;font-size:12px;line-height:1.6;">
          If you didn't request a password reset, you can safely ignore this email.
        </p>
      </div>
      <p style="margin:12px 0 0 0;text-align:center;color:#94a3b8;font-size:12px;">
        Fitness 365 Pro
      </p>
    </div>
  </body>
</html>

