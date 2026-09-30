<?php

namespace App\Http\Requests\Admin;

use App\Domain\Branches\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class UserRequest extends FormRequest
{
    public function rules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:150'],
            'login' => [$creating ? 'required' : 'prohibited', 'string', 'min:3', 'max:64', 'regex:/^[A-Za-z0-9._+-]+$/', Rule::unique('users', 'login')],
            'password' => [$creating ? 'required' : 'sometimes', 'string', 'max:200', Password::min(10)->letters()->numbers()],
            'role' => [$creating ? 'required' : 'sometimes', Rule::in(['CLIENT_OWNER', 'CLIENT_MANAGER', 'CLIENT_OPERATOR'])],
            'isActive' => ['sometimes', 'boolean'],
            'branchIds' => ['sometimes', 'array', 'max:1000'],
            'branchIds.*' => ['string', 'size:26', 'distinct'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('login'))) {
            $this->merge(['login' => strtolower(trim($this->input('login')))]);
        }
    }

    /** @return list<int>|null null when any id is not a branch of this tenant */
    public function branchIds(): ?array
    {
        $publicIds = (array) $this->input('branchIds', []);
        $ids = Branch::query()->whereIn('public_id', $publicIds)->pluck('id')->map(fn ($v) => (int) $v)->all();

        return count($ids) === count($publicIds) ? $ids : null;
    }
}
