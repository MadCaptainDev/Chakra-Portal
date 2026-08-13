<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Permission;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

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
        'manager_id',
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
     * Who signs this person's timesheet off.
     *
     * Nullable: the people at the top have no manager, and anyone left without
     * one falls back to the admins rather than becoming unapprovable.
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /** The people whose entries land in this person's queue. */
    public function reports(): HasMany
    {
        return $this->hasMany(User::class, 'manager_id');
    }

    public function managesAnyone(): bool
    {
        return $this->reports()->exists();
    }

    /** Can this person decide on that person's timesheet? */
    public function managesTimesheetOf(User $other): bool
    {
        return $this->isAdmin() || $other->manager_id === $this->id;
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(UserPermission::class);
    }

    /**
     * May this user do this thing?
     *
     * Admins are not consulted here -- Gate::before answers for them before
     * any of this runs, so an admin needs no rows at all. "manage" is the
     * module's own admin and satisfies every other ability within it.
     *
     * The relation is loaded once and reused: the sidebar asks this question
     * for every module on every page render.
     */
    public function hasPermission(string $module, string $ability): bool
    {
        if (! Permission::isGrantable($module, $ability)) {
            return false;
        }

        return $this->permissions
            ->contains(fn (UserPermission $granted) => $granted->module === $module
                && ($granted->ability === $ability || $granted->ability === Permission::ABILITY_MANAGE));
    }

    /** Does this user have any foothold in the module at all? */
    public function allows(string $module): bool
    {
        return $this->isAdmin()
            || $this->permissions->contains(fn (UserPermission $granted) => $granted->module === $module);
    }

    /**
     * Replace this user's permissions with the given matrix.
     *
     * Delete-then-insert rather than a diff: the matrix arrives complete from
     * the form, the row count is tiny, and one transaction is easier to reason
     * about than three sets of changes. Anything the registry does not
     * recognise is dropped rather than stored, so a hand-crafted form post
     * cannot invent a module.
     *
     * @param  array<string, array<int, string>>  $matrix  module => [ability, …]
     */
    public function syncPermissions(array $matrix): void
    {
        $rows = [];
        $seen = [];

        foreach ($matrix as $module => $abilities) {
            // De-duplicated before insert. A repeated value in the posted
            // array is otherwise a unique-constraint violation, which would
            // turn a harmless double-submit into a 500 on the user form.
            foreach (array_unique((array) $abilities) as $ability) {
                $key = $module.'.'.$ability;

                if (isset($seen[$key]) || ! Permission::isGrantable((string) $module, (string) $ability)) {
                    continue;
                }

                $seen[$key] = true;

                $rows[] = [
                    'user_id' => $this->id,
                    'module' => (string) $module,
                    'ability' => (string) $ability,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        DB::transaction(function () use ($rows) {
            $this->permissions()->delete();

            if ($rows !== []) {
                UserPermission::insert($rows);
            }
        });

        $this->unsetRelation('permissions');
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
