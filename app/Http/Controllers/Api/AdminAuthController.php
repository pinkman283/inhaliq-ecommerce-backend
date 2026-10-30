<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\AdminInvitation;
use App\Services\MailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class AdminAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (!$user) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials do not match our records.'],
            ]);
        }

        // STRICT SECURITY BOUNDARY: Reject non-administrative accounts without leaking account existence
        if (!in_array($user->role, ['super_admin', 'admin', 'staff'])) {
            AuditLog::log(
                null,
                'admin.auth_rejected',
                'User',
                $user->id,
                "Unauthorized login attempt to admin console by non-admin user {$user->email} (Role: {$user->role})",
                ['email' => $user->email, 'role' => $user->role]
            );

            throw ValidationException::withMessages([
                'email' => ['The provided credentials do not match our records.'],
            ]);
        }

        if ($user->locked_until && $user->locked_until > now()) {
            return response()->json([
                'message' => 'Administrative account is temporarily locked due to multiple failed login attempts.',
                'locked' => true,
                'locked_until' => $user->locked_until,
            ], 403);
        }

        if (!Hash::check($validated['password'], $user->password)) {
            $user->increment('failed_login_attempts');
            
            if ($user->failed_login_attempts >= 5) {
                $user->update([
                    'locked_until' => now()->addMinutes(15), // 15 mins for admins
                ]);
                AuditLog::log(null, 'admin.locked_out', 'User', $user->id, "Admin account locked out after 5 failed attempts.");

                return response()->json([
                    'message' => 'Administrative account is temporarily locked due to multiple failed login attempts.',
                    'locked' => true,
                    'locked_until' => $user->locked_until,
                ], 403);
            }

            throw ValidationException::withMessages([
                'email' => ['The provided credentials do not match our records.'],
            ]);
        }

        // Reset attempts on successful login
        $user->update([
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ]);

        if ($user->isSuspended()) {
            AuditLog::log(
                null,
                'admin.suspended_login_blocked',
                'User',
                $user->id,
                "Suspended administrative account {$user->email} attempted to log in.",
                ['email' => $user->email, 'role' => $user->role, 'suspended_until' => $user->suspended_until]
            );

            $reasonText = $user->suspension_reason ? " Reason: {$user->suspension_reason}." : "";
            return response()->json([
                'message' => "This administrative account is suspended.{$reasonText} Contact executive management.",
            ], 403);
        }

        // Revoke older admin session tokens
        $user->tokens()->where('name', 'admin_token')->delete();

        // Generate high-privilege token
        $token = $user->createToken('admin_token', ['admin:access'])->plainTextToken;

        // Log successful administrator authentication in cryptographic audit trail
        AuditLog::log(
            $user,
            'admin.login_success',
            'User',
            $user->id,
            "Administrator {$user->name} ({$user->role}) signed in successfully to operations console.",
            ['email' => $user->email, 'role' => $user->role, 'ip' => $request->ip()]
        );

        return response()->json([
            'message' => 'Administrative authentication authorized.',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'permissions' => $user->permissions ?? [],
                'status' => $user->status,
                'avatar' => $user->avatar,
                'phone' => $user->phone,
                'admin_theme' => $user->admin_theme ?? 'dark',
            ],
        ]);
    }

    public function invite(Request $request): JsonResponse
    {
        // Only super admin can invite
        if (!$request->user() || !$request->user()->isSuperAdmin()) {
            return response()->json(['message' => 'Only Super Admins can invite new administrators.'], 403);
        }

        $validated = $request->validate([
            'email' => 'required|string|email|unique:users',
            'role' => 'required|string|in:admin,staff',
        ]);

        $token = Str::random(60);

        AdminInvitation::updateOrCreate(
            ['email' => $validated['email']],
            [
                'role' => $validated['role'],
                'token' => $token,
                'expires_at' => now()->addHours(24),
            ]
        );

        MailService::sendAdminInvitation($validated['email'], $token, $validated['role']);

        AuditLog::log($request->user(), 'admin.invited', 'User', null, "Super Admin invited {$validated['email']} as {$validated['role']}.");

        return response()->json([
            'message' => 'Invitation sent successfully.',
        ]);
    }

    public function activate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $invitation = AdminInvitation::where('token', $validated['token'])->first();

        if (!$invitation || $invitation->expires_at < now()) {
            return response()->json(['message' => 'Invitation token is invalid or expired.'], 400);
        }

        // Create the admin user
        $user = User::create([
            'name' => $validated['name'],
            'email' => $invitation->email,
            'password' => Hash::make($validated['password']),
            'role' => $invitation->role,
            'avatar' => 'https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?auto=format&fit=crop&w=200&q=80',
        ]);

        $invitation->delete();

        AuditLog::log($user, 'admin.activated', 'User', $user->id, "Administrator account activated.");

        return response()->json([
            'message' => 'Admin account activated successfully. You can now log in.',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'permissions' => $user->permissions ?? [],
                'status' => $user->status,
                'avatar' => $user->avatar,
                'phone' => $user->phone,
                'admin_theme' => $user->admin_theme ?? 'dark',
            ],
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $oldValues = $user->toArray();

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

        // Verify current password if admin is changing password
        $newPassword = $request->input('password') ?? $request->input('new_password');

        if (!empty($newPassword)) {
            $confirmPassword = $request->input('password_confirmation') ?? $request->input('confirm_password');

            if (empty($currentPassword)) {
                throw ValidationException::withMessages([
                    'current_password' => ['Please enter your current administrator password to set a new password.'],
                ]);
            }

            if (!Hash::check($currentPassword, $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => ['The current password you entered is incorrect.'],
                ]);
            }

            if (strlen($newPassword) < 8) {
                throw ValidationException::withMessages([
                    'password' => ['New administrator password must be at least 8 characters long.'],
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

        $user->fill($validated);
        $wasDirty = $user->isDirty();
        $user->save();

        if (!empty($newPassword)) {
            $user->tokens()->where('id', '!=', $user->currentAccessToken()?->id)->delete();
        }

        if ($wasDirty) {
            AuditLog::log(
                $user,
                'admin.profile_updated',
                'User',
                $user->id,
                "Administrator {$user->name} updated their profile settings & avatar.",
                $oldValues,
                $user->toArray()
            );
        }

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'permissions' => $user->permissions ?? [],
                'status' => $user->status,
                'avatar' => $user->avatar,
                'phone' => $user->phone,
                'admin_theme' => $user->admin_theme ?? 'dark',
            ],
        ]);
    }

    public function updateTheme(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'theme' => 'required|string|in:dark,light',
        ]);

        $user = $request->user();
        $user->admin_theme = $validated['theme'];
        $user->save();

        return response()->json([
            'message' => 'Admin theme updated successfully.',
            'theme' => $user->admin_theme,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user) {
            AuditLog::log(
                $user,
                'admin.logout',
                'User',
                $user->id,
                "Administrator {$user->name} logged out from Executive Portal."
            );
            $user->currentAccessToken()->delete();
        }

        return response()->json([
            'message' => 'Admin session terminated successfully',
        ]);
    }
}
