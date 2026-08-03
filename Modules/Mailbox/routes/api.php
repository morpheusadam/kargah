<?php

/*
| Kargah has no HTTP API.
|
| nwidart scaffolds an apiResource and a placeholder controller into every new
| module. Those routes went out behind auth:sanctum pointing at controllers
| whose index/create/show/edit render views that were never written, so an
| authenticated request got a 500 — thirty dead endpoints across six modules.
|
| Removed rather than left lying: dead surface area is worse than missing
| surface area, because it is undocumented, untested, and the first thing
| anyone poking at the application finds. tests/Feature/NoDeadEndpointsTest.php
| is what stops `module:make` quietly putting them back.
|
| When Kargah does grow an API, write the routes and the controllers together.
*/
