<?php

namespace App\Services\Subscriptions\Exceptions;

use RuntimeException;

/**
 * Thrown when a client tries to verify an in-app purchase that the authoritative
 * store data (Apple `appAccountToken` / Google `obfuscatedExternalAccountId`)
 * does not attribute to the authenticated user. Prevents entitlement theft where
 * one account claims another account's subscription.
 */
class PurchaseOwnershipException extends RuntimeException {}
