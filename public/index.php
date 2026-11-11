<?php
// One canonical, uncached entry for customer and staff surfaces.
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
readfile(__DIR__.'/index.html');
