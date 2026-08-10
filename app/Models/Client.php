<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'address',
        'email',
        'phone',
        'notion_venture',
        'industry_id',
    ];

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function contentItems(): HasMany
    {
        return $this->hasMany(ContentItem::class, 'venture', 'notion_venture');
    }

    /**
     * Published work for this client. Only the linked pieces -- a piece that
     * merely types the same name is not the same thing.
     */
    public function portfolioItems(): HasMany
    {
        return $this->hasMany(PortfolioItem::class);
    }

    /**
     * The client's sector, from the shared taxonomy.
     */
    public function industry(): BelongsTo
    {
        return $this->belongsTo(TaxonomyTerm::class, 'industry_id');
    }
}
