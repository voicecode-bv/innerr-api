<?php

namespace App\Enums;

enum EmailVerificationResult
{
    case Verified;

    case AlreadyVerified;

    case InvalidCode;

    case NoActiveCode;
}
