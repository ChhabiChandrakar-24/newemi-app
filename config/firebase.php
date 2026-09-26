<?php

return ['enabled' => (bool) env('FIREBASE_ENABLED', false), 'project_id' => env('FIREBASE_PROJECT_ID'), 'credentials' => env('FIREBASE_CREDENTIALS')];
