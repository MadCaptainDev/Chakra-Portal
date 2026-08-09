<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Access level. Not to be confused with Expense::$role, which is an
     * employee's job title ("Editor") and grants nothing.
     */
    public const ROLE_ADMIN = 'admin';

    public const ROLE_EMPLOYEE = 'employee';

    public const ROLES = [
        self::ROLE_ADMIN => 'Admin — full access',
        self::ROLE_EMPLOYEE => 'Employee — timesheet & profile',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'avatar_path',
        'bio',
        'phone',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isEmployee(): bool
    {
        return ! $this->isAdmin();
    }

    /**
     * The Salaries record for this person, if their login has been linked to
     * one. Admins normally have none.
     */
    public function employeeRecord(): HasOne
    {
        return $this->hasOne(Expense::class)->where('type', Expense::TYPE_SALARY);
    }

    public function timesheetEntries(): HasMany
    {
        return $this->hasMany(TimesheetEntry::class);
    }

    public function points(): HasMany
    {
        return $this->hasMany(EmployeePoint::class);
    }

    /**
     * Where this user belongs after signing in.
     */
    public function homeRoute(): string
    {
        return $this->isAdmin() ? 'dashboard' : 'my.dashboard';
    }

    /**
     * Public URL for the uploaded profile photo, when one exists.
     */
    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? asset($this->avatar_path) : null;
    }
}
