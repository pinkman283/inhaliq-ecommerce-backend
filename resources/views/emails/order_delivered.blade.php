@extends('emails.layout')

@section('content')
    <div style="text-align: center; margin-bottom: 24px;">
        <div style="width: 56px; height: 56px; background-color: #ecfdf5; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 12px;">
            <span style="font-size: 28px;">🎉</span>
        </div>
        <h2 style="margin: 0 0 8px 0; font-size: 20px; font-weight: 700; color: #0f172a;">
            Your Order Has Been Delivered!
        </h2>
        <p style="margin: 0; font-size: 14px; color: #475569;">
            Hi {{ $order->customer_name }}, order <strong>#{{ $order->order_number }}</strong> was successfully delivered to your address.
        </p>
    </div>

    <div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; margin: 24px 0; text-align: center;">
        <h3 style="margin: 0 0 8px 0; font-size: 15px; color: #0f172a;">How was your experience?</h3>
        <p style="margin: 0 0 16px 0; font-size: 13px; color: #64748b; line-height: 1.5;">
            We hope you love your purchase! Your feedback helps us improve and helps other customers find the right products.
        </p>
        <a href="{{ env('FRONTEND_URL', 'http://localhost:3000') }}/account" class="button" style="background-color: #0f172a;">
            View Order & Leave Review &rarr;
        </a>
    </div>

    <p style="margin: 20px 0 0 0; font-size: 12px; color: #94a3b8; text-align: center;">
        Thank you for choosing {{ \App\Models\Setting::get('store_name', 'INHALIQ') }}! If you have any questions or concerns regarding your parcel, please contact our support hotline.
    </p>
@endsection
