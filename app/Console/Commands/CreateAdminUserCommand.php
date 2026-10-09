<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateAdminUserCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:create-user 
                            {--email= : Email address of the user} 
                            {--name= : Name of the user} 
                            {--password= : Password for the user} 
                            {--role=admin : User role (admin, editor, researcher, user)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create or promote a user to administrator or scientific editor';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = $this->option('email') ?: $this->ask('ایمیل مدیر را وارد کنید');
        $name = $this->option('name') ?: $this->ask('نام کامل را وارد کنید');
        $role = $this->option('role') ?: $this->choice('نقش کاربری را انتخاب کنید', ['admin', 'editor', 'researcher', 'user'], 0);

        $validator = Validator::make(['email' => $email], [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            $this->error('فرمت ایمیل نامعتبر است.');
            return 1;
        }

        $user = User::where('email', $email)->first();

        if ($user) {
            if ($this->confirm("کاربری با ایمیل [{$email}] وجود دارد. آیا مایلید به نقش [{$role}] ارتقا یابد؟", true)) {
                $user->role = $role;
                if ($this->option('password')) {
                    $user->password = Hash::make($this->option('password'));
                } elseif ($this->confirm('آیا مایل به تغییر گذرواژه این کاربر هستید؟', false)) {
                    $newPass = $this->secret('گذرواژه جدید را وارد کنید');
                    $user->password = Hash::make($newPass);
                }
                $user->save();
                $this->info("نقش کاربر [{$user->name}] با موفقیت به [{$role}] به‌روزرسانی شد.");
                return 0;
            }
            return 0;
        }

        $password = $this->option('password') ?: $this->secret('گذرواژه را وارد کنید (حداقل ۸ نویسه)');

        if (strlen($password) < 8) {
            $this->error('طول گذرواژه باید حداقل ۸ نویسه باشد.');
            return 1;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => $role,
            'is_verified_scholar' => true,
        ]);

        $this->info("کاربر جدید [{$user->name}] با نقش [{$role}] و شناسه #{$user->id} با موفقیت ساخته شد.");
        return 0;
    }
}
