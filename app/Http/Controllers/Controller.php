<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    // Enables $this->authorize(...) — used by DocumentController,
    // ApprovalController, and NotificationController against the Policies
    // registered in AppServiceProvider (Laravel 11's default controller
    // skeleton no longer wires this trait in by default).
    use AuthorizesRequests;
}
