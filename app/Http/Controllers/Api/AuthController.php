<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\PendingRegistration;
use App\Models\EmailOtp;
use App\Services\MailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * Step 1: Request Registration OTP
     */
    public function registerRequest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                function ($attribute, $value, $fail) {
                    $u = User::where('email', strtolower(trim($value)))->first();
                    if ($u && $u->customer_type !== 'guest') {
                        $fail('The email has already been taken.');
                    }
                },
            ],
            'password' => 'required|string|min:6',
            'phone' => 'nullable|string|max:30',
        ]);

        // 1. Check if OTP Shield is disabled in store settings
        if (!\App\Models\Setting::isOtpShieldEnabled()) {
            $existing = User::where('email', $validated['email'])->first();
            if ($existing && $existing->customer_type === 'guest') {
                $existing->update([
                    'name' => $validated['name'],
                    'password' => Hash::make($validated['password']),
                    'phone' => $validated['phone'] ?? $existing->phone,
                    'customer_type' => 'registered',
                    'status' => 'active',
                ]);
                $user = $existing;
            } else {
                $user = User::create([
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'password' => Hash::make($validated['password']),
                    'role' => 'customer',
                    'customer_type' => 'registered',
                    'status' => 'active',
                    'phone' => $validated['phone'] ?? null,
                    'avatar' => 'https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?auto=format&fit=crop&w=200&q=80',
                ]);
            }

            $token = $user->createToken('auth_token', ['customer:access'])->plainTextToken;

            return response()->json([
                'message' => 'Account registered successfully.',
                'otp_required' => false,
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'phone' => $user->phone,
                    'avatar' => $user->avatar,
                ],
            ], 201);
        }

        // 2. Enforce 60-second cooldown between OTP requests
        $existing = PendingRegistration::where('email', $validated['email'])->first();
        if ($existing && $existing->updated_at && $existing->updated_at->addSeconds(60)->isFuture()) {
            $secondsLeft = now()->diffInSeconds($existing->updated_at->addSeconds(60), false);
            if ($secondsLeft > 0) {
                throw ValidationException::withMessages([
                    'email' => ["Please wait {$secondsLeft} second(s) before requesting another verification code."],
                ]);
            }
        }

        $otp = (string) random_int(100000, 999999);
        $otpHash = Hash::make($otp);

        PendingRegistration::updateOrCreate(
            ['email' => $validated['email']],
            [
                'name' => $validated['name'],
                'password_hash' => Hash::make($validated['password']),
                'phone' => $validated['phone'] ?? null,
                'otp_hash' => $otpHash,
                'attempts' => 0,
                'expires_at' => now()->addMinutes(10),
            ]
        );

        MailService::sendOtp($validated['email'], $otp, 'registration');

        return response()->json([
            'message' => 'OTP sent to your email address.',
            'otp_required' => true,
            'cooldown_seconds' => 60,
        ]);
    }

    /**
     * Step 2: Verify Registration OTP & Create User
     */
    public function registerVerify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|string|email|max:255',
            'otp' => 'required|string|size:6',
        ]);

        $user = \Illuminate\Support\Facades\DB::transaction(function () use ($validated) {
            $pending = PendingRegistration::where('email', $validated['email'])->lockForUpdate()->first();

            if (!$pending || $pending->expires_at < now()) {
                throw ValidationException::withMessages(['otp' => ['The OTP has expired or is invalid.']]);
            }

            if ($pending->attempts >= 5) {
                throw ValidationException::withMessages(['otp' => ['Too many failed attempts. Please request a new OTP.']]);
            }

            if (!Hash::check($validated['otp'], $pending->otp_hash)) {
                $pending->increment('attempts');
                throw ValidationException::withMessages(['otp' => ['The provided OTP is incorrect.']]);
            }

            // OTP is valid. If a guest user record already exists for this email, upgrade it to an active registered customer
            $existing = User::where('email', $pending->email)->first();
            if ($existing && $existing->customer_type === 'guest') {
                $existing->update([
                    'name' => $pending->name,
                    'password' => $pending->password_hash,
                    'phone' => $pending->phone ?: $existing->phone,
                    'customer_type' => 'registered',
                    'status' => 'active',
                ]);
                $createdOrUpgraded = $existing;
            } else {
                $createdOrUpgraded = User::create([
                    'name' => $pending->name,
                    'email' => $pending->email,
                    'password' => $pending->password_hash,
                    'role' => 'customer',
                    'customer_type' => 'registered',
                    'status' => 'active',
                    'phone' => $pending->phone,
                    'avatar' => 'https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?auto=format&fit=crop&w=200&q=80',
                ]);
            }

            $pending->delete(); // Consume OTP
            return $createdOrUpgraded;
        });

        $token = $user->createToken('auth_token', ['customer:access'])->plainTextToken;

        return response()->json([
            'message' => 'User registered successfully',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'phone' => $user->phone,
                'avatar' => $user->avatar,
            ],
        ], 201);
    }

    /**
     * Login with Brute-Force Protection
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|string',
            'password' => 'required|string',
            'remember' => 'nullable|boolean',
        ]);

        $loginInput = trim($validated['email']);
        $user = User::where('email', $loginInput)
            ->orWhere('phone', $loginInput)
            ->first();

        if (!$user) {
            throw ValidationException::withMessages([
                'email' => ['Invalid email or password.'],
            ]);
        }

        if ($user->locked_until && $user->locked_until > now()) {
            return response()->json([
                'message' => 'Account is temporarily locked due to multiple failed login attempts.',
                'locked' => true,
                'locked_until' => $user->locked_until,
            ], 403);
        }

        if (!Hash::check($validated['password'], $user->password)) {
            $user->increment('failed_login_attempts');
            
            if ($user->failed_login_attempts >= 5) {
                $user->update([
                    'locked_until' => now()->addMinutes(5),
                ]);
                return response()->json([
                    'message' => 'Account is temporarily locked due to multiple failed login attempts.',
                    'locked' => true,
                    'locked_until' => $user->locked_until,
                ], 403);
            }

            throw ValidationException::withMessages([
                'email' => ['Invalid email or password.'],
            ]);
        }

        // Reset attempts on successful login
        $user->update([
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ]);

        if ($user->isSuspended()) {
            $reasonText = $user->suspension_reason ? " Reason: {$user->suspension_reason}." : "";
            if ($user->suspended_until) {
                $untilFormatted = $user->suspended_until->format('M d, Y H:i T');
                return response()->json([
                    'message' => "Your customer account is temporarily suspended until {$untilFormatted}.{$reasonText}",
                    'suspended' => true,
                    'suspended_until' => $user->suspended_until,
                    'suspension_reason' => $user->suspension_reason,
                ], 403);
            }
            return response()->json([
                'message' => "Your customer account has been suspended indefinitely. Please contact support.{$reasonText}",
                'suspended' => true,
                'suspended_until' => null,
                'suspension_reason' => $user->suspension_reason,
            ], 403);
        }

        // Revoke previous customer tokens
        $user->tokens()->where('name', 'auth_token')->delete();

        $token = $user->createToken('auth_token', ['customer:access'])->plainTextToken;

        return response()->json([
            'message' => 'Logged in successfully',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'phone' => $user->phone,
                'avatar' => $user->avatar,
            ],
        ]);
    }

    /**
     * Forgot Password Request
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|string|email',
        ]);

        $user = User::where('email', $validated['email'])->first();

        // Always return generic response to prevent email harvesting
        if (!$user) {
            return response()->json([
                'message' => 'If an account exists, a password reset email has been sent.',
                'cooldown_seconds' => 60,
            ]);
        }

        // Enforce 60-second cooldown between reset OTP requests
        $existingOtp = EmailOtp::where('email', $validated['email'])
            ->where('purpose', 'forgot_password')
            ->first();

        if ($existingOtp && $existingOtp->updated_at && $existingOtp->updated_at->addSeconds(60)->isFuture()) {
            $secondsLeft = now()->diffInSeconds($existingOtp->updated_at->addSeconds(60), false);
            if ($secondsLeft > 0) {
                throw ValidationException::withMessages([
                    'email' => ["Please wait {$secondsLeft} second(s) before requesting another reset code."],
                ]);
            }
        }

        $otp = (string) random_int(100000, 999999);
        $otpHash = Hash::make($otp);

        EmailOtp::updateOrCreate(
            ['email' => $validated['email'], 'purpose' => 'forgot_password'],
            [
                'otp_hash' => $otpHash,
                'attempts' => 0,
                'expires_at' => now()->addMinutes(10),
            ]
        );

        MailService::sendOtp($validated['email'], $otp, 'forgot_password');

        return response()->json([
            'message' => 'If an account exists, a password reset email has been sent.',
            'cooldown_seconds' => 60,
        ]);
    }

    /**
     * Verify Forgot Password OTP
     */
    public function verifyResetOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|string|email',
            'otp' => 'required|string|size:6',
        ]);

        $otpRecord = EmailOtp::where('email', $validated['email'])
            ->where('purpose', 'forgot_password')
            ->first();

        if (!$otpRecord || $otpRecord->expires_at < now()) {
            throw ValidationException::withMessages(['otp' => ['The OTP has expired or is invalid.']]);
        }

        if ($otpRecord->attempts >= 5) {
            throw ValidationException::withMessages(['otp' => ['Too many failed attempts. Please request a new OTP.']]);
        }

        if (!Hash::check($validated['otp'], $otpRecord->otp_hash)) {
            $otpRecord->increment('attempts');
            throw ValidationException::withMessages(['otp' => ['The provided OTP is incorrect.']]);
        }

        // Generate temporary reset token
        $resetToken = Str::random(60);
        $otpRecord->update([
            'otp_hash' => Hash::make($resetToken), // Reuse field to store reset token
            'expires_at' => now()->addMinutes(30),
            'purpose' => 'reset_token',
        ]);

        return response()->json([
            'message' => 'OTP verified successfully.',
            'reset_token' => $resetToken,
        ]);
    }

    /**
     * Reset Password
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|string|email',
            'reset_token' => 'required|string',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $otpRecord = EmailOtp::where('email', $validated['email'])
            ->where('purpose', 'reset_token')
            ->first();

        if (!$otpRecord || $otpRecord->expires_at < now() || !Hash::check($validated['reset_token'], $otpRecord->otp_hash)) {
            throw ValidationException::withMessages(['reset_token' => ['The reset session is invalid or has expired.']]);
        }

        $user = User::where('email', $validated['email'])->first();
        if ($user) {
            $user->update([
                'password' => Hash::make($validated['password']),
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ]);
            
            // Revoke all sessions
            $user->tokens()->delete();
        }

        $otpRecord->delete(); // Consume reset token

        return response()->json([
            'message' => 'Password reset successfully. You can now log in.',
        ]);
    }

    public function profile(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load(['addresses', 'orders' => fn($q) => $q->latest()->with('items')]);

        return response()->json([
            'user' => $user,
            'total_orders' => (int) $user->orders()->count(),
            'total_spent' => (float) $user->orders()->where('payment_status', 'paid')->sum('total_amount'),
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        // Removed email from updateable fields to prevent email changes
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'phone' => 'nullable|string|max:30',
            'avatar' => 'nullable|string',
            'current_password' => 'nullable|string',
            'password' => 'nullable|string',
            'new_password' => 'nullable|string',
            'password_confirmation' => 'nullable|string',
            'confirm_password' => 'nullable|string',
        ]);

        $currentPassword = $request->input('current_password');

        // Verify current password if user is changing password
        $newPassword = $request->input('password') ?? $request->input('new_password');

        if (!empty($newPassword)) {
            $confirmPassword = $request->input('password_confirmation') ?? $request->input('confirm_password');

            if (empty($currentPassword)) {
                throw ValidationException::withMessages([
                    'current_password' => ['Please enter your current password to set a new password.'],
                ]);
            }

            if (!Hash::check($currentPassword, $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => ['The current password you entered is incorrect.'],
                ]);
            }

            if (strlen($newPassword) < 6) {
                throw ValidationException::withMessages([
                    'password' => ['New password must be at least 6 characters.'],
                ]);
            }

            if ($newPassword !== $confirmPassword) {
                throw ValidationException::withMessages([
                    'password_confirmation' => ['The new password confirmation does not match.'],
                ]);
            }

            $validated['password'] = Hash::make($newPassword);
        } else {
            unset($validated['password']);
        }

        unset($validated['current_password'], $validated['new_password'], $validated['password_confirmation'], $validated['confirm_password']);

        $user->update($validated);

        if (!empty($newPassword)) {
            $user->tokens()->where('id', '!=', $user->currentAccessToken()?->id)->delete();
        }

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'phone' => $user->phone,
                'avatar' => $user->avatar,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }
}
