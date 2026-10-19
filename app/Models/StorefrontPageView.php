<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StorefrontPageView extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'storefront_page_views';

    protected $fillable = [
        'session_id',
        'visitor_id',
        'url_path',
        'page_title',
        'page_type',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function session()
    {
        return $this->belongsTo(TrafficSession::class, 'session_id', 'session_id');
    }
}
