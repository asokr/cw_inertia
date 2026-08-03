<?php

$path = dirname(__DIR__).'/tests/Feature/Web/Subscriber/Wb/WbAbTestingTest.php';
$c = file_get_contents($path);
$c = str_replace('target_impressions', 'impressions_per_photo', $c);
file_put_contents($path, $c);
echo "done\n";
