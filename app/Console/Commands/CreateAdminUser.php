<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class CreateAdminUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:create-admin-user
                            {--name=System Administrator : The administrator name}
                            {--email=admin@library.com : The administrator email address}
                            {--password= : The administrator password}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create or update the system administrator account';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = trim((string) $this->option('name'));
        $email = strtolower(trim((string) $this->option('email')));
        $password = $this->option('password');

        if ($password === null) {
            $password = $this->secret('Password');
        }

        try {
            $credentials = validator(
                compact('name', 'email', 'password'),
                [
                    'name' => ['required', 'string', 'max:255'],
                    'email' => ['required', 'email', 'max:255'],
                    'password' => ['required', Password::min(8)],
                ],
            )->validate();
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        $attributes = [
            'name' => $credentials['name'],
            'password' => $credentials['password'],
            'email_verified_at' => now(),
        ];

        if (Schema::hasColumn('users', 'role')) {
            $attributes['role'] = 'admin';
        }

        if (Schema::hasColumn('users', 'status')) {
            $attributes['status'] = 'active';
        }

        $user = User::query()->firstOrNew(['email' => $credentials['email']]);
        $wasRecentlyCreated = ! $user->exists;

        $user->forceFill($attributes)->save();

        $this->info($wasRecentlyCreated
            ? 'Administrator account created successfully.'
            : 'Administrator account updated successfully.');

        return self::SUCCESS;
    }
}
