<?php

namespace App\Http\Controllers\My;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * An employee's own salary and raise history -- read only.
 *
 * The admin Salaries module (app/Http/Controllers/SalaryController.php)
 * covers everyone and is gated by the salaries.* module permission, which
 * most employees don't hold and shouldn't need just to see their own
 * numbers. This is the same data, scoped to the signed-in user the same way
 * every other /my/* screen scopes to them -- no module permission, no
 * editing, no other employee's row is even queryable from here.
 */
class SalaryController extends Controller
{
    public function index(Request $request): View
    {
        $employee = $request->user()->employeeRecord;

        return view('my.salary', [
            'employee' => $employee,
            'hikes' => $employee?->hikes()->with('createdBy')->get() ?? collect(),
        ]);
    }
}
