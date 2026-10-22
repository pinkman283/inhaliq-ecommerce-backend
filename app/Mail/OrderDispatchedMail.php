<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\Shipment;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderDispatchedMail extends Mailable
{
    use Queueable, SerializesModels;

    public Order $order;
    public Shipment $shipment;

    public function __construct(Order $order, Shipment $shipment)
    {
        $this->order = $order;
        $this->shipment = $shipment;
    }

    public function envelope(): Envelope
    {
        $storeName = Setting::get('store_name', 'INHALIQ');
        return new Envelope(
            subject: "Your Order #{$this->order->order_number} Has Been Shipped - {$storeName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.order_dispatched',
            with: [
                'order' => $this->order,
                'shipment' => $this->shipment,
            ],
        );
    }
}
