<?php

use App\Http\Controllers\Api\PaypalWebhookController;
use App\Http\Controllers\Api\StripeWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| These routes are loaded by the RouteServiceProvider within a group
| which is assigned the "api" middleware group. These routes are stateless.
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

/*
|--------------------------------------------------------------------------
| Stripe Webhook
|--------------------------------------------------------------------------
| Stripe will POST events here.
| No CSRF, no session, fully stateless (correct approach).
|
*/

Route::post('/stripe/webhook', [StripeWebhookController::class, 'handleWebhook']);

/*
|--------------------------------------------------------------------------
| PayPal Webhook
|--------------------------------------------------------------------------
| PayPal POSTs signed events here. Stateless, no CSRF.
|
*/
Route::post('/paypal/webhook', [PaypalWebhookController::class, 'handleWebhook']);
