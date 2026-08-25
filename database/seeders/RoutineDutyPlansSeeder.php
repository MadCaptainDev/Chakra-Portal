<?php

namespace Database\Seeders;

use App\Models\Routine;
use App\Models\RoutineField;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Real studio duty templates only — no Instagram handles, no permitted users.
 * Admin toggles Client Instagram / Venture account IDs and staff afterwards.
 *
 * Safe to re-run: matches by title and refreshes schedule / checkpoints / fields.
 */
class RoutineDutyPlansSeeder extends Seeder
{
    public function run(): void
    {
        $adminId = User::query()->where('role', User::ROLE_ADMIN)->value('id')
            ?? User::query()->value('id');

        foreach ($this->plans() as $plan) {
            $routine = Routine::query()->updateOrCreate(
                ['title' => $plan['title']],
                [
                    'description' => $plan['description'],
                    'schedule_type' => $plan['schedule_type'],
                    'schedule_interval' => $plan['schedule_interval'] ?? null,
                    'day_of_month' => null,
                    'completion_mode' => Routine::MODE_SHARED,
                    'subject_type' => $plan['subject_type'],
                    'is_active' => true,
                    'catch_up_days' => $plan['catch_up_days'] ?? 31,
                    'starts_on' => today()->toDateString(),
                    'created_by' => $adminId,
                ],
            );

            $routine->users()->sync([]);
            $routine->subjects()->delete();
            $routine->checkpoints()->delete();
            $routine->fields()->delete();

            $checkpointIdsByName = [];
            foreach ($plan['checkpoints'] as $i => $name) {
                $cp = $routine->checkpoints()->create([
                    'name' => $name,
                    'sort_order' => $i,
                ]);
                $checkpointIdsByName[mb_strtolower($name)] = $cp->id;
            }

            foreach ($plan['fields'] as $i => $field) {
                $cpName = isset($field['checkpoint']) ? mb_strtolower($field['checkpoint']) : '';
                $routine->fields()->create([
                    'checkpoint_id' => $cpName !== '' ? ($checkpointIdsByName[$cpName] ?? null) : null,
                    'label' => $field['label'],
                    'key' => $field['key'],
                    'type' => $field['type'],
                    'default_value' => $field['default'] ?? null,
                    'is_required' => (bool) ($field['required'] ?? false),
                    'sort_order' => $i,
                ]);
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function plans(): array
    {
        return [
            [
                'title' => 'Venture Direct Messages and Comments',
                'description' => 'Daily check of selected Client Instagram / Venture accounts. Messages and Comments are verified separately; first permitted person to complete wins.',
                'schedule_type' => Routine::SCHEDULE_DAILY,
                'subject_type' => Routine::SUBJECT_ACCOUNTS,
                'catch_up_days' => 7,
                'checkpoints' => ['Messages', 'Comments'],
                'fields' => [
                    [
                        'label' => 'Replied to Messages',
                        'key' => 'replied_to_messages',
                        'type' => RoutineField::TYPE_NUMBER,
                        'default' => '0',
                        'checkpoint' => 'Messages',
                    ],
                    [
                        'label' => 'Replied to Comments',
                        'key' => 'replied_to_comments',
                        'type' => RoutineField::TYPE_NUMBER,
                        'default' => '0',
                        'checkpoint' => 'Comments',
                    ],
                ],
            ],
            [
                'title' => 'Move final output to hard disk',
                'description' => 'End-of-day handoff of final output to the named hard disk. Simple done click; optional disk name.',
                'schedule_type' => Routine::SCHEDULE_DAILY,
                'subject_type' => null,
                'catch_up_days' => 7,
                'checkpoints' => [],
                'fields' => [
                    [
                        'label' => 'Hard disk name',
                        'key' => 'disk_name',
                        'type' => RoutineField::TYPE_TEXT,
                        'default' => '',
                    ],
                ],
            ],
            [
                'title' => 'Verify training',
                'description' => 'Confirm that someone was trained by someone else. Due every 10 days.',
                'schedule_type' => Routine::SCHEDULE_EVERY_N_DAYS,
                'schedule_interval' => 10,
                'subject_type' => null,
                'catch_up_days' => 20,
                'checkpoints' => [],
                'fields' => [
                    [
                        'label' => 'Trainee',
                        'key' => 'trainee',
                        'type' => RoutineField::TYPE_TEXT,
                        'default' => '',
                    ],
                    [
                        'label' => 'Verified by',
                        'key' => 'verified_by',
                        'type' => RoutineField::TYPE_TEXT,
                        'default' => '',
                    ],
                ],
            ],
            [
                'title' => 'Clean the office',
                'description' => 'Office cleaning duty. Due every 2 days.',
                'schedule_type' => Routine::SCHEDULE_EVERY_N_DAYS,
                'schedule_interval' => 2,
                'subject_type' => null,
                'catch_up_days' => 7,
                'checkpoints' => [],
                'fields' => [],
            ],
        ];
    }
}
