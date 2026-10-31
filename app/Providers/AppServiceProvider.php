<?php

namespace App\Providers;

use App\Auth\Permissions;
use App\Helpers\SearchHelper;
use App\Suggestions\SuggestionReadings;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The access matrix's permissions answer as Gate abilities; any other ability falls through to its own gate or policy
        Gate::before(fn ($user, string $ability) => Permissions::exists($ability) ? $user->hasPermission($ability) : null);

        Blade::directive('highlight', function ($expression) {
            return "<?php echo \App\Helpers\SearchHelper::highlight({$expression}); ?>";
        });

        // Jalali dates in Tehran time with Persian digits (App\Support\Jalali)
        Blade::directive('jdate', fn ($expression) => "<?php echo e(\App\Support\Jalali::format({$expression})); ?>");
        Blade::directive('jago', fn ($expression) => "<?php echo e(\App\Support\Jalali::ago({$expression})); ?>");

        // The verification email in Persian, in the site's RTL mail layout
        VerifyEmail::toMailUsing(fn ($notifiable, string $url) => (new MailMessage)
            ->subject('تأیید ایمیل | فهارس')
            ->greeting('تأیید نشانی ایمیل')
            ->line("{$notifiable->name} گرامی، برای تأیید این نشانی ایمیل در فهارس روی دکمه‌ی زیر بزنید.")
            ->line('پس از تأیید، پیشنهادهایی که پیش‌تر بدون ورود و با همین ایمیل فرستاده‌اید به حساب شما وصل می‌شوند.')
            ->action('تأیید ایمیل', $url)
            ->line('این پیوند تا ۶۰ دقیقه معتبر است. اگر در فهارس حساب نساخته‌اید، این ایمیل را نادیده بگیرید.')
            ->salutation(\App\Models\SiteSetting::get('mail.salutation')));

        ResetPassword::toMailUsing(fn ($notifiable, string $token) => (new MailMessage)
            ->subject('بازیابی گذرواژه | فهارس')
            ->greeting('بازیابی گذرواژه')
            ->line("{$notifiable->name} گرامی، درخواست بازیابی گذرواژه‌ی حساب شما در فهارس ثبت شد.")
            ->action('تعیین گذرواژه‌ی تازه', route('password.reset', ['token' => $token, 'email' => $notifiable->getEmailForPasswordReset()]))
            ->line('این پیوند تا ۶۰ دقیقه معتبر است. اگر شما این درخواست را نداده‌اید، این ایمیل را نادیده بگیرید؛ گذرواژه‌ی شما تغییر نمی‌کند.')
            ->salutation(\App\Models\SiteSetting::get('mail.salutation')));

        // Record pages share the readings of their fields so the per-field badges and the readings list can show them
        $records = ['manuscripts.show' => 'manuscript', 'works.show' => 'work', 'people.show' => 'person', 'libraries.show' => 'library'];
        View::composer(array_keys($records), function ($view) use ($records) {
            $record = $view->getData()[$records[$view->name()]] ?? null;
            View::share('suggestionReadings', $record ? SuggestionReadings::for($record) : collect());
            // Published research notes, in approval order (see NoteTypes)
            View::share('researchNotes', $record ? \App\Models\FieldSuggestion::where('suggestable_type', $record::class)
                ->where('suggestable_id', $record->getKey())->where('kind', 'note')->where('status', 'approved')
                ->orderBy('reviewed_at')->orderBy('id')->get() : collect());
        });
    }
}

if (!function_exists('highlight_search')) {
    function highlight_search(?string $text, ?string $query): string
    {
        return \App\Helpers\SearchHelper::highlight($text, $query);
    }
}

