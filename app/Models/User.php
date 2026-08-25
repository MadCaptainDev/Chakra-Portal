<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Permission;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
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

    /**
     * Somebody the studio works for, not somebody who works at it. Reaches
     * their own invoices, their own published work and their own shoots, and
     * nothing else in the building.
     */
    public const ROLE_CLIENT = 'client';

    /**
     * The two roles the staff screens are about. A client is deliberately
     * absent: this array feeds the user form's admin toggle and the pickers
     * that ask "which of our people?", and a client is neither.
     */
    public const ROLES = [
        self::ROLE_ADMIN => 'Admin — full access',
        self::ROLE_EMPLOYEE => 'Employee — timesheet & profile',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    /*
     * client_id is fillable because an admin sets it when issuing a client's
     * login. Nothing a client can reach posts to a form that writes it -- their
     * own profile form does not offer it, and ProfileUpdateRequest does not
     * accept it, so this cannot be used to walk into another client's account.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'client_id',
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

    /**
     * Tested against the value, not as "everyone who is not an admin".
     *
     * It used to be the latter, which was true while there were two roles and
     * silently wrong the moment there were three: a client would have passed
     * every employee check in the app, including the two in
     * TimesheetAdminController that decide whose timesheet may be opened.
     */
    public function isEmployee(): bool
    {
        return $this->role === self::ROLE_EMPLOYEE;
    }

    /**
     * Does this person fill in a personal timesheet and to-dos?
     *
     * Employees always do. An admin who has been linked to a Salaries row does
     * too: they keep full admin access for the books, but day-to-day logging
     * uses /my/* the same as everyone else. A pure admin (no salary link)
     * does not appear on team timesheet / to-do boards.
     */
    public function logsWork(): bool
    {
        if ($this->isEmployee()) {
            return true;
        }

        if ($this->relationLoaded('employeeRecord')) {
            return $this->employeeRecord !== null;
        }

        return $this->employeeRecord()->exists();
    }

    /**
     * Everyone who should appear on timesheet and personal to-do team lists.
     *
     * @see logsWork()
     */
    public function scopeWhoLogWork(Builder $query): void
    {
        $query->where(function (Builder $inner) {
            $inner->where('role', self::ROLE_EMPLOYEE)
                ->orWhereHas('employeeRecord');
        });
    }

    public function isClient(): bool
    {
        return $this->role === self::ROLE_CLIENT;
    }

    /** The client this login speaks for. Null for everyone at the studio. */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * The studio's own people.
     *
     * Every "which of our people?" picker uses this -- assigning a to-do,
     * naming a manager, crewing a shoot, notifying about recurring invoices.
     * A client has a login but is not staff, and must never appear in one.
     */
    public function scopeStaff(Builder $query): void
    {
        $query->whereIn('role', [self::ROLE_ADMIN, self::ROLE_EMPLOYEE]);
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
     * Who signs this person's work off. More than one is normal.
     *
     * An editor answers to the producer on the job and to the studio lead on
     * the week, and whichever of them is reachable that day is the one who can
     * actually look at the work. Empty is fine: the people at the top have no
     * manager, and anyone left without one falls back to the admins rather than
     * becoming unapprovable.
     */
    public function managers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'manager_user', 'user_id', 'manager_id')
            ->orderBy('name');
    }

    /** The people whose work lands in this person's queue. */
    public function reports(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'manager_user', 'manager_id', 'user_id')
            ->orderBy('name');
    }

    public function managesAnyone(): bool
    {
        return $this->reports()->exists();
    }

    /**
     * Can this person decide on that person's work?
     *
     * Admins can, always -- somebody has to be able to cover for a manager who
     * is on a shoot.
     */
    public function manages(User $other): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        // Uses the loaded relation when the caller has already eager-loaded it,
        // which the team screens do once for a whole page of people.
        if ($other->relationLoaded('managers')) {
            return $other->managers->contains('id', $this->id);
        }

        return $other->managers()->whereKey($this->id)->exists();
    }

    /**
     * Kept because the timesheet screens read better for it, and because
     * "decide on the timesheet" is the specific thing most callers mean.
     */
    public function managesTimesheetOf(User $other): bool
    {
        return $this->manages($other);
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(UserPermission::class);
    }

    /** Keys an AI client uses to act as this person over the MCP endpoint. */
    public function mcpTokens(): HasMany
    {
        return $this->hasMany(McpToken::class);
    }

    /** Browsers that have agreed to receive push notifications. */
    public function pushTokens(): HasMany
    {
        return $this->hasMany(PushToken::class);
    }

    /**
     * Where FcmChannel sends a push -- every device this person has opted
     * in on. Empty for a client rather than throwing: clients do not get
     * push (see the pushTokens migration), and Notification::send() calling
     * this on one is a routing bug, not a reason to 500.
     */
    public function routeNotificationForFcm(): Collection
    {
        return $this->isClient() ? collect() : $this->pushTokens;
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
     * Every user who can see a module -- the query-level twin of allows(),
     * for "who do I notify about this" rather than "can this one person see
     * it". Staff only: a client has no module permissions and isClient()
     * excludes them explicitly rather than relying on that alone, since a
     * client with a stray permission row (there should never be one) must
     * still never be resolved as a recipient here.
     */
    public function scopeCanSee(Builder $query, string $module): void
    {
        $query->where('role', '!=', self::ROLE_CLIENT)
            ->where(function (Builder $q) use ($module) {
                $q->where('role', self::ROLE_ADMIN)
                    ->orWhereHas('permissions', fn (Builder $p) => $p->where('module', $module));
            });
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
        return match (true) {
            $this->isAdmin() => 'dashboard',
            $this->isClient() => 'client.dashboard',
            default => 'my.dashboard',
        };
    }

    /**
     * Public URL for the uploaded profile photo, when one exists.
     */
    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? asset($this->avatar_path) : null;
    }
}
