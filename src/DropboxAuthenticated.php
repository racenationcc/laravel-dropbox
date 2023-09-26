<?php

namespace RaceNation\Dropbox;

use Closure;
use RaceNation\Dropbox\Facades\Dropbox;

class DropboxAuthenticated
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if (Dropbox::getTokenData() === null) {
            return Dropbox::connect();
        }

        return $next($request);
    }
}
