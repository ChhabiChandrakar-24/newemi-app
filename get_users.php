<?php echo json_encode(App\Models\User::orderBy("id", "desc")->take(5)->get(["id", "email", "company_id", "is_platform_admin", "status"]));
