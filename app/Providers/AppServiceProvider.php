<?php

namespace App\Providers;

use App\Listeners\HandleOrderBillingDocuments;
use App\Listeners\IssueDepositReceipt;
use App\Listeners\SendOrderLifecycleEmails;
use App\Listeners\SendTrustpilotReviewOnOrderCompleted;
use App\Models\Blog;
use App\Models\DepositRequest;
use App\Models\Order;
use App\Models\Project;
use App\Models\User;
use App\Services\EmailNotificationService;
use App\Support\MarketingOpsQueues;
use App\Support\OrderLifecycleMailSuppressor;
use App\Support\PublicStorageLink;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OrderLifecycleMailSuppressor::class);

        // Blade views call these helpers on every form page. Composer "files"
        // autoload is enough after dump-autoload, but a deploy that only
        // synced PHP without regenerating the classmap leaves My Sites (and
        // every other old_text() form) as Call to undefined function — and
        // catalog eye-reveal refresh as Call to undefined function safe_external_url()
        // / site_description_excerpt().
        foreach ([
            app_path('Helpers/LanguageHelper.php'),
            app_path('Helpers/FormHelper.php'),
            app_path('Helpers/UrlHelper.php'),
            app_path('Helpers/SiteDescriptionHelper.php'),
        ] as $helper) {
            if (is_file($helper)) {
                require_once $helper;
            }
        }
    }

    public function boot(): void
    {
        $this->assertConfiguredMediaPath();

        // App shells use Bootstrap, not Tailwind. Laravel's default Tailwind
        // pagination SVGs render as giant arrows when w-5/h-5/hidden utilities
        // are missing — switch to Bootstrap 5 views sitewide (catalog + admin).
        Paginator::useBootstrapFive();

        // Authenticated users hitting /login or /register go to their role dashboard.
        RedirectIfAuthenticated::redirectUsing(function () {
            $user = Auth::user();

            return $user ? $user->getDashboardRoute() : '/';
        });

        // Gap-fill: welcome + admin new-user (HTTP only — skips seeders/artisan)
        // afterCommit so signup transaction is never blocked by mail/SMTP.
        User::created(function (User $user) {
            if (app()->runningInConsole()) {
                return;
            }

            $userId = $user->id;

            $run = function () use ($userId) {
                try {
                    $fresh = User::find($userId);
                    if (! $fresh) {
                        return;
                    }
                    $emails = app(EmailNotificationService::class);
                    $emails->sendWelcome($fresh);
                    $emails->notifyAdminsNewUser($fresh);
                } catch (\Throwable $e) {
                    Log::warning('Post-registration email hooks failed', [
                        'user_id' => $userId,
                        'error' => $e->getMessage(),
                    ]);
                }
            };

            if (DB::transactionLevel() > 0) {
                DB::afterCommit($run);
            } else {
                $run();
            }
        });

        // Order lifecycle emails — listeners themselves defer to afterCommit
        Order::created(function (Order $order) {
            try {
                app(SendOrderLifecycleEmails::class)->created($order);
            } catch (\Throwable $e) {
                Log::warning('Order created notification hook failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

            try {
                app(HandleOrderBillingDocuments::class)->created($order);
            } catch (\Throwable $e) {
                Log::warning('Order created billing hook failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        Order::updated(function (Order $order) {
            try {
                app(SendOrderLifecycleEmails::class)->updated($order);
            } catch (\Throwable $e) {
                Log::warning('Order updated notification hook failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

            try {
                app(HandleOrderBillingDocuments::class)->updated($order);
            } catch (\Throwable $e) {
                Log::warning('Order updated billing hook failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

            try {
                app(SendTrustpilotReviewOnOrderCompleted::class)->handle($order);
            } catch (\Throwable $e) {
                Log::warning('Trustpilot review hook failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        // Wallet top-ups settle through several paths (admin approval, Stripe
        // webhooks, saved cards); hook the model so each one issues a receipt.
        DepositRequest::created(function (DepositRequest $deposit) {
            try {
                app(IssueDepositReceipt::class)->created($deposit);
            } catch (\Throwable $e) {
                Log::warning('Deposit created receipt hook failed', [
                    'deposit_request_id' => $deposit->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        DepositRequest::updated(function (DepositRequest $deposit) {
            try {
                app(IssueDepositReceipt::class)->updated($deposit);
            } catch (\Throwable $e) {
                Log::warning('Deposit updated receipt hook failed', [
                    'deposit_request_id' => $deposit->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        View::composer('*', function ($view) {
            try {
                if (auth()->check()) {
                    $projects = Project::where('user_id', auth()->id())
                        ->latest()
                        ->get();
                } else {
                    $projects = collect();
                }
            } catch (\Throwable $e) {
                Log::warning('sidebarProjects composer failed', ['error' => $e->getMessage()]);
                $projects = collect();
            }

            $view->with('sidebarProjects', $projects);
        });

        // Recent published posts for the public footer "Latest Updates" section
        View::composer('components.footer', function ($view) {
            $posts = collect();

            try {
                if (Schema::hasTable('blogs')) {
                    $posts = Blog::published()
                        ->orderByDesc('published_at')
                        ->limit(4)
                        ->get(['id', 'title', 'slug', 'published_at', 'created_at']);
                }
            } catch (\Throwable) {
                $posts = collect();
            }

            $view->with('footerRecentBlogs', $posts);
        });

        View::composer('marketing.layouts.app', function ($view) {
            $ready = 0;
            $bulk = 0;

            try {
                $ready = MarketingOpsQueues::sitesReadyForStaffCount();
                $bulk = MarketingOpsQueues::bulkWaitingOnMarketerCount();
            } catch (\Throwable $e) {
                Log::warning('Marketing sidebar queue badges failed', ['error' => $e->getMessage()]);
            }

            $view->with([
                'mktReadySiteCount' => $ready,
                'mktBulkWaitingCount' => $bulk,
            ]);
        });
    }

    /**
     * When MEDIA_PATH is set (Hostinger durable media), fail loudly if the
     * directory is missing or not writable so uploads do not silently die.
     * Also warn when public/storage does not resolve to MEDIA_PATH (blank
     * admin previews after a "successful" upload).
     * Unset MEDIA_PATH keeps the default storage/app/public (local/CI).
     */
    private function assertConfiguredMediaPath(): void
    {
        $configured = config('filesystems.media_path');
        if (! is_string($configured) || trim($configured) === '') {
            return;
        }

        $path = rtrim($configured, DIRECTORY_SEPARATOR);
        $ok = is_dir($path) && is_writable($path);
        if (! $ok) {
            $message = 'MEDIA_PATH is set to ['.$path.'] but that directory is missing or not writable. '
                .'Create it (and ownership for the PHP user) or clear MEDIA_PATH. See docs/hostinger-media.md.';

            Log::critical($message);

            throw new \RuntimeException($message);
        }

        if (app()->runningUnitTests()) {
            return;
        }

        // Best-effort: recreate public/storage → MEDIA_PATH when it drifted after a deploy.
        $ensure = PublicStorageLink::ensure();
        if (! $ensure['ok']) {
            Log::warning('public/storage does not point at MEDIA_PATH — site images may look blank after upload. '
                .'Run: php artisan media:ensure-link', [
                    'media_path' => $path,
                    'ensure' => $ensure,
                ]);
        }
    }
}
