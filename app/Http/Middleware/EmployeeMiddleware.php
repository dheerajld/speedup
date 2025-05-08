<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EmployeeMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->user() || !$request->user()->isEmployee()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Employee access required.'
            ], 403);
        }

        return $next($request);
    }
}
