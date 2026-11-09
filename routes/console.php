<?php

use Illuminate\Support\Facades\Schedule;

// Löschlauf: abgelaufene Sendungen, liegengebliebene Entwürfe, alte Protokolle.
Schedule::command('portal:prune')->hourly()->withoutOverlapping();
