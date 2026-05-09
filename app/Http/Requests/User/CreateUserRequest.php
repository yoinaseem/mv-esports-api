<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Admin-side user creation. Mirrors the registration validation but
 * doesn't issue a token — the admin isn't logging in as the new user.
 */
class CreateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // UserPolicy handles caller authz.
    }

    public function rules(): array
    {
        return [
            'name'          => ['nullable', 'string', 'max:255'],
            'display_name'  => ['nullable', 'string', 'max:255'],
            'email'         => ['required', 'email', 'unique:users,email'],
            'password'      => ['required', 'string', 'min:8', 'confirmed'],
            'date_of_birth' => ['required', 'date', 'before:'.now()->subYears(13)->toDateString()],
            'country'       => ['nullable', 'string', 'size:2'],
        ];
    }
}
