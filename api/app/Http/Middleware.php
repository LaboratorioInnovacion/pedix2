<?php

declare(strict_types=1);

namespace VO\Http;

interface Middleware
{
    public function handle(Request $request, callable $next): JsonResponse;
}
