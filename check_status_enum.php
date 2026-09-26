<?php $res = DB::select("SHOW COLUMNS FROM companies WHERE Field = 'status'"); var_dump($res);
