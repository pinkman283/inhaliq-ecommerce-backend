@extends('emails.layout')

@section('content')
    <h2 style="margin: 0 0 12px 0; font-size: 20px; font-weight: 700; color: #0f172a;">
        Staff Invitation to Executive Portal
    </h2>
    <p style="margin: 0 0 20px 0; font-size: 14px; color: #475569; line-height: 1.5;">
        You have been invited to join the <strong>{{ \App\Models\Setting::get('store_name', 'INHALIQ') }}</strong> administration portal with the role of:
    </p>

    <div style="background-color: #f5f3ff; border: 1px solid #ddd6fe; border-radius: 8px; padding: 16px; margin: 16px 0; text-align: center;">
        <span style="font-size: 15px; font-weight: 700; color: #6d28d9; text-transform: capitalize;">
            {{ str_replace('_', ' ', $role) }}
        </span>
    </div>

    <p style="margin: 0 0 20px 0; font-size: 14px; color: #475569; line-height: 1.5;">
        Please click the button below to set your account password and activate your access:
    </p>

    <div style="text-align: center; margin: 28px 0;">
        <a href="{{ $activationUrl }}" class="button" style="background-color: #7c3aed;">
            Activate Staff Account &rarr;
        </a>
    </div>

    <p style="margin: 24px 0 6px 0; font-size: 12px; color: #64748b;">
        Or copy and paste this link into your browser:
    </p>
    <p style="margin: 0; font-size: 11px; color: #7c3aed; word-break: break-all; font-family: monospace;">
        {{ $activationUrl }}
    </p>

    <p style="margin: 24px 0 0 0; font-size: 12px; color: #94a3b8;">
        This invitation link is valid for <strong>24 hours</strong>. If you did not expect this invitation, please contact your systems administrator.
    </p>
@endsection
