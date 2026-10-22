<?php

namespace App\Models;

use Database\Factories\MenuItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MenuItem extends Model
{
    /** @use HasFactory<MenuItemFactory> */
    use HasFactory;

    protected $fillable = [
        'menu_category_id',
        'name',
        'description',
        'price',
        'is_available',
        'sort_order',
    ];

    /** @return array{price: 'decimal:2', is_available: 'boolean', sort_order: 'integer'} */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_available' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<MenuCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(
            MenuCategory::class,
            'menu_category_id'
        );
    }

    /** @return HasMany<PreOrderItem, $this> */
    public function preOrderItems(): HasMany
    {
        return $this->hasMany(PreOrderItem::class);
    }
}
