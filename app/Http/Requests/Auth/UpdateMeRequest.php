<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Self-edit of the authenticated user's own profile. Sparse — any subset
 * of fields. Email uniqueness skips the caller's own row. Password change
 * requires `current_password` (not just `password_confirmation`) — defends
 * against the stolen-token-rotates-password attack.
 */
class UpdateMeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route already requires auth:sanctum; any authenticated user
        // may edit their own profile.
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            'name'             => ['sometimes', 'nullable', 'string', 'max:255'],
            'display_name'     => ['sometimes', 'nullable', 'string', 'max:255'],
            'email'            => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($userId)],
            'date_of_birth'    => ['sometimes', 'date', 'before:'.now()->subYears(13)->toDateString()],
            'country'          => ['sometimes', 'nullable', 'string', 'size:2'],
            'password'         => ['sometimes', 'string', 'min:8', 'confirmed'],
            // current_password is required when (and only when) password is in
            // the payload. Laravel's `current_password` rule verifies the
            // submitted plaintext matches the auth user's stored hash.
            'current_password' => ['required_with:password', 'current_password'],
        ];
    }
}
