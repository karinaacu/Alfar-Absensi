<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class BootstrapAdmin extends Command
{
    protected $signature = 'alfar:admin {--generate : Generate a temporary password and display it once}';

    protected $description = 'Create the initial superadmin without embedding a password in source control';

    public function handle(): int
    {
        if (User::where('email', 'superadmin@alfar.id')->exists()) {
            $this->error('Akun sudah ada; password tidak diubah.');

            return self::FAILURE;
        }
        $password = $this->option('generate') ? Str::password(24) : $this->secret('Password sementara (minimal 12 karakter, huruf dan angka)');
        $v = Validator::make(['password' => $password], ['password' => ['required', Password::min(12)->letters()->numbers()]]);
        if ($v->fails()) {
            $this->error('Password belum memenuhi persyaratan.');

            return self::FAILURE;
        }
        $user = User::create(['name' => 'Superadmin Alfar', 'email' => 'superadmin@alfar.id', 'password' => $password]);
        $user->forceFill(['is_superadmin' => true, 'must_change_password' => true, 'email_verified_at' => now()])->save();
        if ($this->option('generate')) {
            $this->line('Password sementara (simpan aman): '.$password);
        }
        $this->info('superadmin@alfar.id dibuat. Wajib mengganti password saat login pertama.');

        return self::SUCCESS;
    }
}
