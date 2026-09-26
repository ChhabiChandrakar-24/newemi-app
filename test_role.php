<?php $role = App\Models\Role::where("name", "admin")->first(); echo json_encode($role ? $role->permissions->pluck("name") : null);
