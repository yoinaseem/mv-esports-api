<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Superadmin-only edit of a user account. Sparse — any subset of fields.
 * Email uniqueness skips the row being edited. Password requires
 * confirmation if present. Age-gate enforced on date_of_birth changes.
 */
class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // UserPolicy handles caller authz.
    }

    public function rules(): array
    {
        $user = $this->route('user');
        $userId = is_object($user) ? $user->id : $user;

        return [
            'name'          => ['sometimes', 'nullable', 'string', 'max:255'],
            'display_name'  => ['sometimes', 'nullable', 'string', 'max:255'],
            'email'         => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($userId)],
            'password'      => ['sometimes', 'string', 'min:8', 'confirmed'],
            'date_of_birth' => ['sometimes', 'date', 'before:'.now()->subYears(13)->toDateString()],
            'country'       => ['sometimes', 'nullable', 'string', 'size:2'],
        ];
    }
}
