<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeadCaptureController extends Controller
{
    public function capture(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lead_id' => 'nullable|integer',
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:50',
            'email' => 'nullable|string|email|max:255',
            'address' => 'nullable|string|max:1000',
            'city' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:50',
            'cart_items' => 'nullable|array',
            'total_amount' => 'nullable|numeric|min:0',
        ]);

        $user = $request->user('sanctum');
        $lead = null;

        // 1. Try to find by lead_id if supplied
        if (!empty($validated['lead_id'])) {
            $lead = Lead::where('id', $validated['lead_id'])
                ->where('status', '!=', 'converted')
                ->first();
        }

        // 2. Otherwise, look for a recent unconverted lead matching phone (within last 4 hours)
        if (!$lead) {
            $lead = Lead::where('phone', $validated['phone'])
                ->where('status', '!=', 'converted')
                ->where('created_at', '>=', Carbon::now()->subHours(4))
                ->latest()
                ->first();
        }

        $leadData = [
            'name' => $validated['name'],
            'phone' => $validated['phone'],
            'email' => $validated['email'] ?? ($lead ? $lead->email : null),
            'address' => $validated['address'] ?? ($lead ? $lead->address : null),
            'city' => $validated['city'] ?? ($lead ? $lead->city : null),
            'postal_code' => $validated['postal_code'] ?? ($lead ? $lead->postal_code : null),
            'cart_items' => $validated['cart_items'] ?? ($lead ? $lead->cart_items : []),
            'total_amount' => $validated['total_amount'] ?? ($lead ? $lead->total_amount : 0.00),
            'user_id' => $user ? $user->id : ($lead ? $lead->user_id : null),
            'source' => 'checkout_abandonment',
        ];

        if ($lead) {
            $lead->update($leadData);
        } else {
            $leadData['status'] = 'new';
            $lead = Lead::create($leadData);

            // Notify authorized staff & admins of new incoming lead
            \App\Services\AdminNotificationService::notifyNewLead($lead);
        }

        return response()->json([
            'message' => 'Checkout lead captured successfully',
            'lead_id' => $lead->id,
            'status' => $lead->status,
        ]);
    }
}
