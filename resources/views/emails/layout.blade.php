<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject ?? 'Notification' }}</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            background-color: #f4f6f9;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            color: #1e293b;
            -webkit-font-smoothing: antialiased;
        }
        .wrapper {
            width: 100%;
            background-color: #f4f6f9;
            padding: 40px 15px;
            box-sizing: border-box;
        }
        .container {
            max-width: 580px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }
        .header {
            background-color: #090b10;
            padding: 28px 32px;
            text-align: center;
            border-bottom: 3px solid #7c3aed;
        }
        .brand-name {
            color: #ffffff;
            font-size: 20px;
            font-weight: 800;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin: 0;
        }
        .content {
            padding: 36px 32px;
        }
        .footer {
            background-color: #f8fafc;
            padding: 24px 32px;
            text-align: center;
            border-top: 1px solid #e2e8f0;
            font-size: 12px;
            color: #64748b;
            line-height: 1.6;
        }
        .button {
            display: inline-block;
            background-color: #059669;
            color: #ffffff !important;
            text-decoration: none;
            padding: 12px 28px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            margin-top: 18px;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            font-size: 13px;
        }
        .table th {
            text-align: left;
            padding: 10px 12px;
            background-color: #f1f5f9;
            color: #475569;
            font-weight: 600;
            border-bottom: 1px solid #e2e8f0;
        }
        .table td {
            padding: 12px;
            border-bottom: 1px solid #f1f5f9;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="container">
            <div class="header">
                <h1 class="brand-name">{{ \App\Models\Setting::get('store_name', 'INHALIQ') }}</h1>
            </div>
            <div class="content">
                @yield('content')
            </div>
            <div class="footer">
                <p style="margin: 0 0 6px 0;">This email was sent by <strong>{{ \App\Models\Setting::get('store_name', 'INHALIQ') }}</strong></p>
                <p style="margin: 0 0 6px 0;">{{ \App\Models\Setting::get('store_address', 'Dhaka, Bangladesh') }} | Hotline: {{ \App\Models\Setting::get('support_phone', '+880 1711-521098') }}</p>
                <p style="margin: 0; font-size: 11px; color: #94a3b8;">&copy; {{ date('Y') }} {{ \App\Models\Setting::get('store_name', 'INHALIQ') }}. All rights reserved.</p>
            </div>
        </div>
    </div>
</body>
</html>
