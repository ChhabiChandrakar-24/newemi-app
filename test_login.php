<?php 
$user = App\Models\User::where("email", "test@gmail.com")->first(); 
var_dump($user->is_platform_admin);
