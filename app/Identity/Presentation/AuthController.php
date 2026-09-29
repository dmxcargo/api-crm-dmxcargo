<?php

namespace App\Identity\Presentation;

use App\Identity\Application\Authenticate;
use App\Identity\Infrastructure\EloquentAccounts;
use App\Shared\Presentation\ApiRequest;
use Illuminate\Http\Request;

final class AuthController
{
    public function login(ApiRequest $request, Authenticate $auth)
    {
        $d = $request->validated();

        return response()->json(['data' => $auth->login($d['login'], $d['password'], $d['deviceName'])]);
    }

    public function me(Request $request, EloquentAccounts $accounts)
    {
        return response()->json(['data' => $accounts->find($request->user()->id)->publicData()]);
    }

    public function logout(Request $request, Authenticate $auth)
    {
        $auth->logout($request->user()->id, (string) $request->user()->currentAccessToken()->id);

        return response()->json(['message' => 'Anda berhasil keluar.']);
    }

    public function revokeAll(Request $request, Authenticate $auth)
    {
        $auth->logout($request->user()->id, null, true);

        return response()->json(['message' => 'Seluruh sesi Anda telah dicabut.']);
    }
}
