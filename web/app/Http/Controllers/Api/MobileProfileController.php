<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Services\UserProfileUpdater;
use Illuminate\Http\JsonResponse;

class MobileProfileController
{
    /** Modifie seulement le profil du compte connecté, avec les validations du web. */
    public function update(ProfileUpdateRequest $request, UserProfileUpdater $profiles): JsonResponse
    {
        $user = $profiles->update($request->user(), $request->validated());

        return response()->json(['data' => MobileSessionController::profile($user)]);
    }
}
