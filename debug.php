<?php
// This endpoint no longer writes a log file to the web root.
// It previously wrote request details (including raw GPS coordinates
// from phone check-ins) to a publicly-readable debug.txt, which was
// a privacy/security risk. It now just acknowledges the request.

http_response_code(200);
echo 'OK';
