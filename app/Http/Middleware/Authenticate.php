<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as BaseAuthenticate;

class Authenticate extends BaseAuthenticate
{
    protected function authenticate($request, array $guards): void
    {
        parent::authenticate($request, $guards);

        $user = $this->auth->guard()->user();

        if (! $user->active) {
            $this->unauthenticated($request, $guards);
        }
    }
}
