@extends('emails.layout')

@section('content')
    <h2 style="margin: 0 0 8px 0; font-size: 20px; font-weight: 700; color: #0f172a;">
        Your Package Is On Its Way! 📦
    </h2>
    <p style="margin: 0 0 20px 0; font-size: 14px; color: #475569; line-height: 1.5;">
        Hi {{ $order->customer_name }}, parcel for order <strong>#{{ $order->order_number }}</strong> has been dispatched with our delivery partner.
    </p>

    <!-- Tracking Details Card -->
    <div style="background-color: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 20px; margin: 20px 0; font-size: 13px;">
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="color: #166534; padding: 6px 0;">Courier Partner:</td>
                <td style="font-weight: 700; color: #14532d; text-align: right; text-transform: capitalize;">{{ $shipment->provider ?? 'Steadfast Courier' }}</td>
            </tr>
            @if(!empty($shipment->consignment_id))
            <tr>
                <td style="color: #166534; padding: 6px 0;">Consignment ID:</td>
                <td style="font-family: monospace; font-weight: 700; color: #14532d; text-align: right;">{{ $shipment->consignment_id }}</td>
            </tr>
            @endif
            @if(!empty($shipment->tracking_code))
            <tr>
                <td style="color: #166534; padding: 6px 0;">Tracking Code:</td>
                <td style="font-family: monospace; font-weight: 700; color: #14532d; text-align: right;">{{ $shipment->tracking_code }}</td>
            </tr>
            @endif
            @if($shipment->cod_amount > 0)
            <tr style="border-top: 1px dashed #86efac;">
                <td style="color: #166534; padding: 10px 0 0 0; font-weight: 700;">Collectable COD Amount:</td>
                <td style="font-size: 16px; font-weight: 800; color: #15803d; text-align: right; padding: 10px 0 0 0;">৳{{ number_format($shipment->cod_amount, 2) }}</td>
            </tr>
            @endif
        </table>
    </div>

    @if(!empty($shipment->tracking_code))
    <div style="text-align: center; margin: 28px 0;">
        <a href="https://steadfast.com.bd/t/{{ $shipment->tracking_code }}" class="button" style="background-color: #059669;">
            Track Your Parcel Online &rarr;
        </a>
    </div>
    @endif

    <div style="background-color: #fefce8; border-left: 4px solid #eab308; padding: 12px 16px; border-radius: 4px; margin-top: 24px; font-size: 12px; color: #713f12; line-height: 1.5;">
        <strong>Delivery Reminder:</strong> Please keep your phone reachable. The delivery agent will call you before arrival. If your order is Cash on Delivery (COD), please keep the exact cash amount ready.
    </div>
@endsection
