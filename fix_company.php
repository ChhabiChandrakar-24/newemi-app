<?php
App\Models\Company::whereIn("id", [15, 16])->update(["status" => "active"]);
App\Models\User::whereIn("company_id", [15, 16])->update(["status" => "active"]);
echo "fixed";
