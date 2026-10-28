<?php

use Illuminate\Support\Facades\Schedule;

// Remove Sanctum tokens that expired more than a day ago.
Schedule::command('sanctum:prune-expired --hours=24')->daily();
