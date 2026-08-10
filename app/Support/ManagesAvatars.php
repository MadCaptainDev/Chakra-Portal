<?php

namespace App\Support;

use Illuminate\Http\Request;

trait ManagesAvatars
{
    /**
     * Avatars go through PublicUpload into public/uploads/avatars, the same as
     * every other browser-reachable file here.
     *
     * They used to be written to the "public" disk and served as
     * "storage/avatars/x.jpg", which relies on the public/storage symlink.
     * The symlink exists on production but Apache will not follow it, so every
     * avatar came back 403 and no profile picture ever appeared. Nothing under
     * public/uploads depends on a symlink, so it just works.
     */
    protected function applyAvatarUpload(Request $request, object $model, string $attribute = 'avatar_path'): void
    {
        if ($request->boolean('remove_avatar') && $model->{$attribute}) {
            $this->deleteAvatarFile($model->{$attribute});
            $model->{$attribute} = null;
        }

        if ($request->hasFile('avatar')) {
            $previous = $model->{$attribute};
            $model->{$attribute} = PublicUpload::store($request->file('avatar'), 'avatars');
            $this->deleteAvatarFile($previous);
        }
    }

    protected function deleteAvatarFile(?string $avatarPath): void
    {
        PublicUpload::delete($avatarPath);
    }
}
