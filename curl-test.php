<?php

if (function_exists('curl_init')) {
    echo "cURL ENABLED";
} else {
    echo "cURL DISABLED";
}