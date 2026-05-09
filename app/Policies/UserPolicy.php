<?php

namespace App\Policies;

use App\Models\User;

/**
 * Authorization for the admin user-CRUD endpoints. Convention: methods
 * return true / false based on caller authz only. State preconditions
 * (e.g. "user owns active organisations" for delete) stay in the
 * controller / service so 422 vs 403 maps cleanly.
 *
 * Permission grants (set in RolesAndPermissionsSeeder):
 *   users.view    → system_manager + superadmin
 *   users.create  → system_manager + superadmin
 *   users.update  → superadmin only (tightened from prior commit)
 *   users.delete  → superadmin only
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('users.view');
    }

    public function view(User $user, User $target): bool
    {
        return $user->hasPermissionTo('users.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('users.create');
    }

    public function update(User $user, User $target): bool
    {
        return $user->hasPermissionTo('users.update');
    }

    /**
     * Self-delete OR superadmin-delete. The "self" path doesn't require
     * a permission — any authenticated user can delete their own account.
     */
    public function delete(User $user, User $target): bool
    {
        if ($user->id === $target->id) {
            return true;
        }
        return $user->hasPermissionTo('users.delete');
    }
}
