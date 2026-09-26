<?php $res = DB::select("SHOW COLUMNS FROM devices"); foreach($res as $r) { if(str_contains($r->Type, "enum")) print_r($r); }
