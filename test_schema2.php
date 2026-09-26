<?php echo json_encode(Illuminate\Support\Facades\DB::select("SHOW COLUMNS FROM companies LIKE 'status'"));
