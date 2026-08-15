<?php

use App\Http\Controllers\McpController;
use Illuminate\Support\Facades\Route;

/*
 * The MCP endpoint.
 *
 * Registered outside routes/web.php on purpose, so it never picks up the web
 * middleware group: no session is started, no CSRF token is expected, and no
 * cookie this endpoint sees can turn into a browser login. A token is the only
 * way in, and it buys exactly one request.
 *
 * One route, one verb. The specification also describes GET (for a server that
 * pushes over SSE) and DELETE (for ending a session); this server streams
 * nothing and keeps no session, so both would be lies. A client that tries them
 * gets a 405, which is the honest answer.
 */
Route::post('/mcp', McpController::class)->name('mcp');
