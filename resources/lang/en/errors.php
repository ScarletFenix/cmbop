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
        'reset_success' => 'Password has been reset successfully.',
        'reset_invalid' => 'Invalid token or email.',
    ],

    'session' => [
        'expired' => 'Your session expired. Refresh the page and try again.',
    ],

    'generic' => [
        'retry' => 'Something went wrong. Please try again.',
        'unavailable' => 'This action is temporarily unavailable. Please try again later.',
    ],

    'oauth' => [
        'unavailable' => 'Google sign-in is not available. Please use email and password.',
        'temporary' => 'Google sign-in is temporarily unavailable. Please try again or use email and password.',
        'cancelled' => 'Google sign-in was cancelled. You can try again or use email and password.',
        'failed' => 'Google sign-in failed. Please try again or use email and password.',
        'no_email' => 'Google did not share an email address. Please use another sign-in method.',
    ],

    'payment' => [
        'webhook_unavailable' => 'Webhook not configured',
        'webhook_signature' => 'Invalid signature',
        'webhook_event' => 'This payment update could not be read.',
        'webhook_failed' => 'Payment could not be recorded. Please try again later.',
        'leftover_credit_failed' => 'The leftover card credit could not be applied. Try again or contact support.',
        'leftover_credit_applied' => 'This leftover was paid using the card credit already in your wallet.',
        'paypal_auth' => 'PayPal rejected these credentials. Use sandbox keys with sandbox mode, or live keys with live mode.',
        'paypal_unavailable' => 'PayPal is temporarily unavailable. Please try again or use another payment method.',
        'paypal_unreachable' => 'This server could not reach PayPal. Try again in a moment, or pay with wallet or card.',
        'paypal_rejected' => 'PayPal could not start this payment (:code). Try again or use wallet or card.',
        'paypal_restricted' => 'This PayPal business account cannot receive payments. Use wallet or card, or check the account in the PayPal dashboard.',
        'paypal_webhook_as_secret' => 'PAYPAL_SECRET is a webhook ID (WH-…). Paste the REST app Secret from the PayPal dashboard, not the webhook ID.',
        'paypal_return_url' => 'PayPal needs a public https:// return URL. Set APP_URL to your live domain, then run php artisan config:clear.',
        'paypal_duplicate' => 'This checkout was already sent to PayPal. Refresh the page and try again.',
        'paypal_not_completed' => 'PayPal payment was not completed.',
        'paypal_cancelled' => 'PayPal payment was cancelled.',
        'paypal_not_configured' => 'PayPal is not configured.',
        'paypal_not_configured_checkout' => 'PayPal is not configured. Please pay with wallet or card.',
        'paypal_not_configured_deposit' => 'PayPal is not configured. Please contact support.',
        'paypal_invalid_return' => 'Invalid PayPal return.',
        'paypal_wrong_account' => 'Payment does not belong to this account.',
        'paypal_not_order' => 'This payment is not an order checkout.',
        'paypal_not_deposit' => 'This payment is not a wallet top-up.',
        'paypal_reference_mismatch' => 'Payment reference mismatch.',
        'paypal_order_mismatch' => 'PayPal order does not match this checkout.',
        'order_failed' => 'We could not process your order. Please try again.',
        'stripe_not_configured' => 'Stripe is not configured. Please contact support.',
        'stripe_checkout_failed' => 'Failed to create checkout session. Please try again.',
        'stripe_saved_card_failed' => 'Saved card payment failed. Please try again or use a new card.',
        'stripe_verify_failed' => 'Unable to verify payment. Please contact support.',
        'stripe_verify_card_failed' => 'Unable to verify card payment. Please contact support.',
        'stripe_not_completed' => 'Card payment was not completed.',
        'stripe_session_unpaid' => 'Payment not completed.',
        'verification_failed' => 'Payment verification failed. Please try again.',
        'verification_failed_support' => 'Payment verification failed. Please contact support.',
        'verify_failed_support' => 'Failed to verify payment. Please contact support.',
        'invalid_callback' => 'Invalid payment callback.',
        'invalid_session' => 'Invalid payment session.',
        'wallet_failed' => 'Unable to process wallet payment. Please try again.',
        'wallet_insufficient' => 'Insufficient wallet balance for this order.',
        'listings_gone_after_pay' => 'Payment was received but the listing(s) are no longer available. Contact support with your payment reference.',
        'wallet_session_is_order' => 'This payment is not a wallet top-up. Order payments are confirmed on the order page.',
        'feature_stripe_failed' => 'Could not start card checkout. Please try again or use wallet balance.',
        'feature_invalid_session' => 'Invalid feature payment session.',
        'feature_not_completed' => 'Payment was not completed.',
        'feature_mismatch' => 'Payment session does not match this website.',
        'feature_verify_failed' => 'Could not verify payment. Contact support if you were charged.',
    ],

    'cron' => [
        'disabled' => 'Scheduler is not configured.',
        'forbidden' => 'Forbidden.',
    ],

];
