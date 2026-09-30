<?php

namespace App\Http\Resources;

use App\Domain\Users\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
final class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'login' => $this->login,
            'role' => $this->role->value,
            'isActive' => $this->is_active,
            'lastLoginAt' => $this->last_login_at?->toIso8601ZuluString(),
        ];
    }
}
