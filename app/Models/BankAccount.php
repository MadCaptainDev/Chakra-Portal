<?php

namespace App\Models;

use App\Support\Banks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An account EMIs are paid through: the bank (and its logo) and whose
 * account the month's money has to be deposited into.
 */
class BankAccount extends Model
{
    protected $fillable = ['bank', 'holder_name', 'last4', 'nickname', 'notes'];

    public function emis(): HasMany
    {
        return $this->hasMany(Expense::class)->where('type', Expense::TYPE_EMI);
    }

    /** Paid to a person directly (GPay, cash) rather than into a bank account. */
    public function isPerson(): bool
    {
        return ! Banks::exists($this->bank);
    }

    public function bankName(): ?string
    {
        return Banks::name($this->bank);
    }

    public function logoUrl(): ?string
    {
        return Banks::logo($this->bank);
    }

    /** "AN" for Annamalai -- the avatar when there is no bank logo. */
    public function initials(): string
    {
        $words = preg_split('/\s+/', trim((string) ($this->holder_name ?: $this->nickname ?: '?')));

        return mb_strtoupper(mb_substr($words[0], 0, 1).(isset($words[1]) ? mb_substr($words[1], 0, 1) : mb_substr($words[0], 1, 1)));
    }

    /** "Annamalai · Federal Bank ••1234", "Axis Bank", "Thatha (paid directly)". */
    public function label(): string
    {
        if ($this->isPerson()) {
            return trim(($this->nickname ?: $this->holder_name ?: 'Unnamed').' (paid directly)');
        }

        $bank = $this->bankName().($this->last4 ? ' ••'.$this->last4 : '');

        return $this->holder_name ? $this->holder_name.' · '.$bank : $bank;
    }

    /** What goes in expenses.payee, which older screens and tools still read. */
    public function payeeText(): string
    {
        if ($this->isPerson()) {
            return (string) ($this->holder_name ?: $this->nickname);
        }

        return $this->holder_name ? $this->bankName().' - '.$this->holder_name : (string) $this->bankName();
    }

    /** For the account pickers, most used first. */
    public static function forPicker()
    {
        return static::query()->withCount('emis')->get()
            ->sortBy([['emis_count', 'desc'], [fn ($a) => $a->label(), 'asc']])->values();
    }
}
