<?php

namespace App\Domain\Branches\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/** Holiday / closed date in branch-local calendar. */
class BranchClosedDay extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $fillable = ['branch_id', 'date', 'reason'];

    protected function casts(): array
    {
        return ['date' => 'immutable_date'];
    }
}
