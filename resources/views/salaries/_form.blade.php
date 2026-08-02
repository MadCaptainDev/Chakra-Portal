@php $employee = $employee ?? null; @endphp

<x-card class="p-4 sm:p-6">
    <form method="POST" action="{{ $employee ? route('salaries.update', $employee) : route('salaries.store') }}">
        @csrf
        @if ($employee)
            @method('PUT')
        @endif

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
            <div>
                <x-input-label for="emp_name" value="Name" />
                <x-text-input id="emp_name" name="name" type="text" class="mt-1 block w-full"
                              value="{{ old('name', $employee->name ?? '') }}" required />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="emp_role" value="Role" />
                <x-text-input id="emp_role" name="role" type="text" class="mt-1 block w-full"
                              value="{{ old('role', $employee->role ?? '') }}" placeholder="e.g. Editor" />
                <x-input-error :messages="$errors->get('role')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="emp_amount" value="Monthly Salary" />
                <x-text-input id="emp_amount" name="amount" type="number" step="0.01" min="0"
                              class="mt-1 block w-full" value="{{ old('amount', $employee->amount ?? '') }}" required />
                <x-input-error :messages="$errors->get('amount')" class="mt-2" />
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 mb-4">
            <div>
                <x-input-label for="emp_joined" value="Joined On" />
                <x-text-input id="emp_joined" name="joined_on" type="date" class="mt-1 block w-full"
                              value="{{ old('joined_on', $employee?->joined_on?->format('Y-m-d') ?? '') }}" />
                <x-input-error :messages="$errors->get('joined_on')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="emp_phone" value="Phone" />
                <x-text-input id="emp_phone" name="phone" type="text" class="mt-1 block w-full"
                              value="{{ old('phone', $employee->phone ?? '') }}" />
                <x-input-error :messages="$errors->get('phone')" class="mt-2" />
            </div>

            <div class="flex items-end">
                <label class="inline-flex items-center min-h-[44px] gap-2">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1"
                           @checked(old('is_active', $employee->is_active ?? true))
                           class="rounded border-gray-300 text-brand-500 focus:ring-brand-400">
                    <span class="text-sm text-gray-700">Currently working</span>
                </label>
            </div>

            <div class="flex items-end">
                <x-primary-button>{{ $employee ? 'Save Changes' : 'Add Employee' }}</x-primary-button>
            </div>
        </div>
    </form>
</x-card>
