<?php

return [

    'login' => [
        'invalid' => 'Invalid email or password.',
        'throttled' => 'Too many login attempts. Please try again later.',
        'unverified' => 'Your email is not verified.',
        'success' => 'Login successful!',
    ],

    'register' => [
        'throttled' => 'Too many registration attempts. Please try again later.',
        'validation' => 'Please fix the highlighted fields and try again.',
        'unavailable' => 'Registration is temporarily unavailable. Please contact support.',
        'failed' => 'Something went wrong. Please try again.',
    ],

    'password' => [
        'throttled' => 'Too many attempts. Please try again later.',
        'reset_throttled' => 'Too many attempts. Try again later.',
        'reset_sent' => 'If an account with this email exists, a password reset link has been sent.',
    ],

    'session' => [
        'expired' => 'Your session expired. Refresh the page and try again.',
    ],

    'generic' => [
        'retry' => 'Something went wrong. Please try again.',
        'unavailable' => 'This action is temporarily unavailable. Please try again later.',
    ],

    'payment' => [
        'webhook_unavailable' => 'Webhook not configured',
        'webhook_signature' => 'Invalid signature',
        'webhook_failed' => 'Payment could not be recorded. Please try again later.',
        'leftover_credit_failed' => 'The leftover card credit could not be applied. Try again or contact support.',
        'leftover_credit_applied' => 'This leftover was paid using the card credit already in your wallet.',
    ],

    'cron' => [
        'disabled' => 'Scheduler is not configured.',
        'forbidden' => 'Forbidden.',
    ],

];
