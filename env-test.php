<?php

echo '<pre>';

echo "SUPABASE_URL: ";
echo getenv('SUPABASE_URL') ? 'SET' : 'NOT SET';

echo "\nSUPABASE_SECRET_KEY: ";
echo getenv('SUPABASE_SECRET_KEY') ? 'SET' : 'NOT SET';

echo "\nSUPABASE_BUCKET: ";
echo getenv('SUPABASE_BUCKET') ? 'SET' : 'NOT SET';

echo "\n</pre>";