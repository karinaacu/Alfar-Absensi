<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AccountReady
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()) {
            abort_unless($request->user()->active, 403, 'Akun tidak aktif.');
            if ($request->user()->must_change_password && ! $request->is('password/change', 'logout')) {
                return redirect('/password/change');
            }
        }

        return $next($request);
    }
}
