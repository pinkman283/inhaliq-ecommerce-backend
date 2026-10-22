<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderDeliveredMail extends Mailable
{
    use Queueable, SerializesModels;

    public Order $order;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    public function envelope(): Envelope
    {
        $storeName = Setting::get('store_name', 'INHALIQ');
        return new Envelope(
            subject: "Your Order #{$this->order->order_number} Has Been Delivered - {$storeName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.order_delivered',
            with: [
                'order' => $this->order,
            ],
        );
    }
}
