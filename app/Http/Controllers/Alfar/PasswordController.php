<?php

namespace App\Http\Controllers\Alfar;

use App\Http\Controllers\Controller;
use App\Services\Access;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class PasswordController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('alfar/ChangePassword');
    }

    public function update(Request $r): RedirectResponse
    {
        $r->validate(['current_password' => 'required|current_password', 'password' => ['required', 'confirmed', 'different:current_password', Password::min(12)->letters()->numbers()]]);
        $r->user()->forceFill(['password' => $r->password, 'must_change_password' => false])->save();
        $r->session()->regenerate();
        Access::audit($r->user()->id, 'password.change', 'user:'.$r->user()->id);

        return redirect('/dashboard')->with('success', 'Password berhasil diganti.');
    }
}
