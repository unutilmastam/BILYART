<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class BranchRequest extends FormRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:150'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'timezone' => ['sometimes', 'timezone:all'],
            'reportTime' => ['sometimes', 'date_format:H:i'],
            'isActive' => ['sometimes', 'boolean'],
            'paymentMode' => ['sometimes', 'in:CASHIER,BILL_ACCEPTOR'],
        ];
    }

    /** @return array<string, mixed> column => value */
    public function columns(): array
    {
        $map = ['name' => 'name', 'address' => 'address', 'phone' => 'phone', 'timezone' => 'timezone', 'isActive' => 'is_active', 'paymentMode' => 'payment_mode'];
        $out = [];
        foreach ($map as $in => $col) {
            if ($this->has($in)) {
                $out[$col] = $in === 'isActive' ? $this->boolean($in) : $this->input($in);
            }
        }
        if ($this->has('reportTime')) {
            $out['report_time'] = $this->input('reportTime').':00';
        }

        return $out;
    }
}
