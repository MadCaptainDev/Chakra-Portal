<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

trait ManagesAvatars
{
    protected function applyAvatarUpload(Request $request, object $model, string $attribute = 'avatar_path'): void
    {
        if ($request->boolean('remove_avatar') && $model->{$attribute}) {
            $this->deleteAvatarFile($model->{$attribute});
            $model->{$attribute} = null;
        }

        if ($request->hasFile('avatar')) {
            $previous = $model->{$attribute};
            $path = $request->file('avatar')->store('avatars', 'public');
            $model->{$attribute} = 'storage/'.$path;
            $this->deleteAvatarFile($previous);
        }
    }

    protected function deleteAvatarFile(?string $avatarPath): void
    {
        if (! $avatarPath || ! str_starts_with($avatarPath, 'storage/')) {
            return;
        }

        Storage::disk('public')->delete(substr($avatarPath, strlen('storage/')));
    }
}
