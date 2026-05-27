<!doctype html>
<html>
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Verification Code</title>
  </head>
  <body style="margin:0;padding:0;background:#f6f7fb;font-family:Arial,Helvetica,sans-serif;">
    <div style="max-width:600px;margin:0 auto;padding:24px;">
      <div style="background:#ffffff;border-radius:12px;padding:24px;border:1px solid #e6e8f0;">
        <h2 style="margin:0 0 12px 0;color:#0f172a;font-size:20px;">Verify your email</h2>
        <p style="margin:0 0 16px 0;color:#334155;font-size:14px;line-height:1.6;">
          Use the verification code below to complete your Fitness 365 Pro signup. This code expires in
          <strong>{{ $minutes }}</strong> minutes.
        </p>

        <div style="margin:18px 0;padding:16px;border-radius:10px;background:#f1f5f9;border:1px solid #e2e8f0;text-align:center;">
          <div style="font-size:28px;letter-spacing:8px;font-weight:700;color:#062156;">
            {{ $otp }}
          </div>
        </div>

        <p style="margin:0;color:#64748b;font-size:12px;line-height:1.6;">
          If you didn’t request this, you can safely ignore this email.
        </p>
      </div>
      <p style="margin:12px 0 0 0;text-align:center;color:#94a3b8;font-size:12px;">
        Fitness 365 Pro
      </p>
    </div>
  </body>
</html>


