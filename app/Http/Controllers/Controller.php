<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * AuthorizesRequests is what gives every controller `$this->authorize()`.
 * Laravel 11 dropped it from the generated base class; the moment a policy
 * exists — LeadPolicy — it has to come back, or the check is a fatal error
 * rather than a 403.
 */
abstract class Controller
{
    use AuthorizesRequests;
}
