<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TrafficSession extends Model
{
    use HasFactory;

    protected $table = 'traffic_sessions';

    protected $fillable = [
        'session_id',
        'visitor_id',
        'ip_address',
        'user_agent',
        'device_type',
        'channel',
        'referrer_source',
        'landing_page',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'domain',
    ];

    public function pageViews()
    {
        return $this->hasMany(StorefrontPageView::class, 'session_id', 'session_id');
    }
}
