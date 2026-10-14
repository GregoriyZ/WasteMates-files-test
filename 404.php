<?php
// Serves the custom 404 page. The host replaces Apache's own error pages with
// a bare "404 Not Found", even with `ErrorDocument 404 /404.html` set, but it
// passes through error responses that come from PHP, so .htaccess routes
// missing URLs here instead.
http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__ . '/404.html');
