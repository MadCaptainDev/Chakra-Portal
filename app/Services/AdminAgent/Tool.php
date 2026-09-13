<?php

namespace App\Services\AdminAgent;

use App\Models\User;

/**
 * One thing the assistant is allowed to do.
 *
 * Every tool takes the admin who asked, not just the arguments. That is not
 * for convenience: it is the second half of the gate. The first half decided
 * this conversation belongs to an admin at all; passing the user down means a
 * tool can enforce its own rules, and a tool added later that must answer
 * differently for different people has somewhere to look.
 *
 * `run()` returns a string, and the string is read by the model, not by the
 * admin -- so it should say what it knows plainly and completely, including
 * "nothing matched", and leave the phrasing to the model.
 */
interface Tool
{
    public function name(): string;

    /** @return array<string, mixed> the tool definition sent to the model */
    public function definition(): array;

    /** @param array<string, mixed> $input */
    public function run(User $admin, array $input): string;
}
