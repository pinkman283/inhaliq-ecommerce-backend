@extends('emails.layout')

@section('content')
    <h2 style="margin: 0 0 12px 0; font-size: 20px; font-weight: 700; color: #0f172a;">
        {{ $purpose === 'forgot_password' ? 'Password Reset Verification' : 'Verify Your Email Address' }}
    </h2>
    <p style="margin: 0 0 24px 0; font-size: 14px; color: #475569; line-height: 1.5;">
        Please use the 6-digit verification code below to complete your request. This code will expire in <strong>10 minutes</strong>.
    </p>

    <div style="background-color: #f8fafc; border: 2px dashed #cbd5e1; border-radius: 8px; padding: 24px; text-align: center; margin: 24px 0;">
        <span style="font-size: 11px; text-transform: uppercase; font-weight: 700; letter-spacing: 1.5px; color: #64748b; display: block; margin-bottom: 8px;">
            One-Time Password (OTP)
        </span>
        <span style="font-family: 'SF Mono', Consolas, Monaco, monospace; font-size: 36px; font-weight: 800; letter-spacing: 8px; color: #059669; display: inline-block;">
            {{ $otp }}
        </span>
    </div>

    <div style="background-color: #fef2f2; border-left: 4px solid #ef4444; padding: 12px 16px; border-radius: 4px; margin-top: 24px;">
        <p style="margin: 0; font-size: 12px; color: #991b1b; line-height: 1.4;">
            <strong>Security Advisory:</strong> Never share this code with anyone. Our customer support representatives will never ask you for your verification code.
        </p>
    </div>

    <p style="margin: 24px 0 0 0; font-size: 12px; color: #94a3b8;">
        If you did not request this code, no action is needed. You can safely ignore this email.
    </p>
@endsection
