@csrf

<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div class="sm:col-span-2">
        <x-input-label for="title" value="Title" />
        <x-text-input id="title" name="title" type="text" class="mt-1" required autofocus
                      :value="old('title', $script->title)" placeholder="Tea montage reel" />
        <x-input-error :messages="$errors->get('title')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="client_id" value="Client" />
        <x-select id="client_id" name="client_id" class="mt-1">
            <option value="">No client yet</option>
            @foreach ($clients as $client)
                <option value="{{ $client->id }}" @selected(old('client_id', $script->client_id) == $client->id)>{{ $client->name }}</option>
            @endforeach
        </x-select>
        <x-input-error :messages="$errors->get('client_id')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="campaign" value="Campaign" />
        <x-text-input id="campaign" name="campaign" type="text" class="mt-1"
                      :value="old('campaign', $script->campaign)" placeholder="Festive 2026" />
        <x-input-error :messages="$errors->get('campaign')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="writer_id" value="Writer" />
        <x-select id="writer_id" name="writer_id" class="mt-1">
            <option value="">Unassigned</option>
            @foreach ($writers as $writer)
                <option value="{{ $writer->id }}" @selected(old('writer_id', $script->writer_id) == $writer->id)>{{ $writer->name }}</option>
            @endforeach
        </x-select>
        <x-input-error :messages="$errors->get('writer_id')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="editor_id" value="Editor" />
        <x-select id="editor_id" name="editor_id" class="mt-1">
            <option value="">Unassigned</option>
            @foreach ($writers as $writer)
                <option value="{{ $writer->id }}" @selected(old('editor_id', $script->editor_id) == $writer->id)>{{ $writer->name }}</option>
            @endforeach
        </x-select>
        <x-input-error :messages="$errors->get('editor_id')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="status" value="Status" />
        <x-select id="status" name="status" class="mt-1" required>
            @foreach ($statuses as $value => $label)
                <option value="{{ $value }}" @selected(old('status', $script->status ?? \App\Models\Script::STATUS_DRAFT) === $value)>{{ $label }}</option>
            @endforeach
        </x-select>
        <x-input-error :messages="$errors->get('status')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="priority" value="Priority" />
        <x-select id="priority" name="priority" class="mt-1" required>
            @foreach ($priorities as $value => $label)
                <option value="{{ $value }}" @selected(old('priority', $script->priority ?? \App\Models\Script::PRIORITY_NORMAL) === $value)>{{ $label }}</option>
            @endforeach
        </x-select>
        <x-input-error :messages="$errors->get('priority')" class="mt-2" />
    </div>

    @foreach ([
        ['script_type_id', 'Script type', $scriptTypes],
        ['platform_id', 'Platform', $platforms],
        ['language_id', 'Language', $languages],
    ] as [$field, $label, $options])
        <div>
            <x-input-label :for="$field" :value="$label" />
            <x-select :id="$field" :name="$field" class="mt-1">
                <option value="">Not set</option>
                @foreach ($options as $option)
                    <option value="{{ $option['value'] }}" @selected(old($field, $script->{$field}) == $option['value'])>{{ $option['label'] }}</option>
                @endforeach
            </x-select>
            <x-input-error :messages="$errors->get($field)" class="mt-2" />
        </div>
    @endforeach

    <div>
        <x-input-label for="target_seconds" value="Target duration (seconds)" />
        <x-text-input id="target_seconds" name="target_seconds" type="number" min="1" max="36000" class="mt-1"
                      :value="old('target_seconds', $script->target_seconds)" placeholder="30" />
        <x-input-error :messages="$errors->get('target_seconds')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="due_on" value="Deadline" />
        <x-text-input id="due_on" name="due_on" type="date" class="mt-1"
                      :value="old('due_on', $script->due_on?->format('Y-m-d'))" />
        <x-input-error :messages="$errors->get('due_on')" class="mt-2" />
    </div>
</div>
