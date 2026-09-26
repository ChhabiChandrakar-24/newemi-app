<?php $user = App\Models\User::find(273); $user->load("roles.permissions"); echo json_encode(["roles" => $user->roles->pluck("name"), "perms" => $user->roles->flatMap->permissions->pluck("name")]);
