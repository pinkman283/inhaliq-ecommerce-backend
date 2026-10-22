@extends('emails.layout')

@section('content')
    <h2 style="margin: 0 0 8px 0; font-size: 20px; font-weight: 700; color: #0f172a;">
        Thank You For Your Order!
    </h2>
    <p style="margin: 0 0 20px 0; font-size: 14px; color: #475569; line-height: 1.5;">
        Hi {{ $order->customer_name }}, your order <strong>#{{ $order->order_number }}</strong> has been confirmed and is being prepared for dispatch.
    </p>

    <!-- Order Metadata Summary Card -->
    <div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin-bottom: 24px; font-size: 13px;">
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="color: #64748b; padding: 4px 0;">Order Number:</td>
                <td style="font-weight: 700; color: #0f172a; text-align: right; padding: 4px 0; font-family: monospace;">{{ $order->order_number }}</td>
            </tr>
            <tr>
                <td style="color: #64748b; padding: 4px 0;">Payment Method:</td>
                <td style="font-weight: 600; color: #0f172a; text-align: right; padding: 4px 0; text-transform: capitalize;">{{ str_replace('_', ' ', $order->payment_method) }}</td>
            </tr>
            <tr>
                <td style="color: #64748b; padding: 4px 0;">Shipping Method:</td>
                <td style="font-weight: 600; color: #0f172a; text-align: right; padding: 4px 0;">{{ $order->shipping_method ?? 'Standard Delivery' }}</td>
            </tr>
        </table>
    </div>

    <!-- Items Table -->
    <h3 style="margin: 0 0 10px 0; font-size: 14px; font-weight: 700; color: #1e293b;">Order Items</h3>
    <table class="table">
        <thead>
            <tr>
                <th>Item</th>
                <th style="text-align: center;">Qty</th>
                <th style="text-align: right;">Price</th>
            </tr>
        </thead>
        <tbody>
            @foreach($items as $item)
                <tr>
                    <td>
                        <strong style="color: #0f172a;">{{ $item->product_name }}</strong>
                        @if(!empty($item->variant_name))
                            <br><span style="font-size: 11px; color: #64748b;">Variant: {{ $item->variant_name }}</span>
                        @endif
                    </td>
                    <td style="text-align: center; color: #475569;">{{ $item->quantity }}</td>
                    <td style="text-align: right; font-weight: 600; color: #0f172a;">৳{{ number_format($item->total_price, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <!-- Totals Breakdown -->
    <div style="border-top: 1px solid #e2e8f0; padding-top: 12px; margin-bottom: 24px;">
        <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
            <tr>
                <td style="padding: 4px 0; color: #64748b;">Subtotal:</td>
                <td style="padding: 4px 0; text-align: right; font-weight: 600; color: #0f172a;">৳{{ number_format($order->subtotal, 2) }}</td>
            </tr>
            <tr>
                <td style="padding: 4px 0; color: #64748b;">Shipping Fee:</td>
                <td style="padding: 4px 0; text-align: right; font-weight: 600; color: #0f172a;">৳{{ number_format($order->shipping_amount, 2) }}</td>
            </tr>
            @if($order->discount_amount > 0)
                <tr>
                    <td style="padding: 4px 0; color: #059669;">Promo Discount:</td>
                    <td style="padding: 4px 0; text-align: right; font-weight: 600; color: #059669;">-৳{{ number_format($order->discount_amount, 2) }}</td>
                </tr>
            @endif
            @if($order->tax_amount > 0)
                <tr>
                    <td style="padding: 4px 0; color: #64748b;">VAT / Tax:</td>
                    <td style="padding: 4px 0; text-align: right; font-weight: 600; color: #0f172a;">৳{{ number_format($order->tax_amount, 2) }}</td>
                </tr>
            @endif
            <tr style="border-top: 2px solid #0f172a;">
                <td style="padding: 10px 0 0 0; font-size: 15px; font-weight: 800; color: #0f172a;">Grand Total:</td>
                <td style="padding: 10px 0 0 0; font-size: 16px; font-weight: 800; color: #059669; text-align: right;">৳{{ number_format($order->total_amount, 2) }}</td>
            </tr>
        </table>
    </div>

    <!-- Shipping Address Card -->
    @if(!empty($order->shipping_address))
        <div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin-top: 20px; font-size: 12px; color: #475569;">
            <strong style="color: #0f172a; display: block; margin-bottom: 6px; font-size: 13px;">Delivery Address</strong>
            {{ $order->shipping_address['address_line1'] ?? '' }}
            @if(!empty($order->shipping_address['city']))
                , {{ $order->shipping_address['city'] }}
            @endif
            @if(!empty($order->customer_phone))
                <br>Recipient Phone: {{ $order->customer_phone }}
            @endif
        </div>
    @endif
@endsection
