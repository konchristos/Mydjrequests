<?php
file_put_contents(__DIR__ . '/ping.log', date('c')." ping\n", FILE_APPEND);
echo "OK";