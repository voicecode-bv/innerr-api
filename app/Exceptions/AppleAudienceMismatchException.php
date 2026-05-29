<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an Apple ID token's `aud` claim does not match this app's
 * configured client id. The Socialite Apple provider validates the token's
 * signature, issuer and expiry but not its audience, so a token minted for a
 * different Apple relying party would otherwise be accepted — enabling account
 * takeover. We enforce the audience ourselves.
 */
class AppleAudienceMismatchException extends RuntimeException {}
