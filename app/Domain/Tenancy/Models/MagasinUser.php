<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutMembreMagasin;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class MagasinUser extends Pivot
{
    protected $table = 'magasin_user';

    protected $casts = [
        'role' => RoleMagasin::class,
        'statut' => StatutMembreMagasin::class,
        'joined_at' => 'datetime',
    ];

    public function magasin(): BelongsTo
    {
        return $this->belongsTo(Magasin::class);
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
