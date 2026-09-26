<?php echo json_encode(Spatie\Permission\Models\Permission::where("name", "like", "users.%")->pluck("name"));
